<?php
/**
 * Upload-time integration: hook the WordPress upload pipeline so new
 * attachments are processed at arrival.
 *
 * @package Tidy_Resize_Images
 */

namespace Tidy_Resize_Images;

defined( 'ABSPATH' ) || die();

/**
 * Upload Handler.
 *
 * Two integration points:
 *
 * - `big_image_size_threshold` — adjust WordPress's built-in scaled-rotation
 *   limit so it honours our `max_edge` setting when ours is lower than
 *   WP's default (2560).
 * - `wp_generate_attachment_metadata` — after WP saves the upload and
 *   builds intermediate sizes, run our processor: back up the original,
 *   resize / convert / recompress as required, replace the source file,
 *   regenerate intermediate sizes for the new file, and mark the
 *   attachment as processed.
 *
 * **Scope note (per project workflow priority):** upload-time processing
 * is a lower-priority safety net. The headline workflow is the bulk
 * processor (M7) running interactively or via a scheduled cron. This
 * class implements the happy path cleanly but does not gold-plate —
 * no per-context overrides, no upload-time dry-run UI, no progress
 * surface.
 *
 * @since 0.1.0
 */
class Upload_Handler {

	/**
	 * Register the upload-pipeline hooks.
	 *
	 * Called from `Plugin::run()` outside the is_admin() branch — uploads
	 * can originate from front-end forms (e.g. WooCommerce product images
	 * uploaded via REST or other plugins) so we must register globally.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'big_image_size_threshold', array( $this, 'filter_big_image_threshold' ), 10, 1 );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'process_after_metadata' ), 10, 3 );
	}

	/**
	 * Lower WordPress's `big_image_size_threshold` to our `max_edge` when
	 * ours is the more restrictive value.
	 *
	 * Returning a smaller value tells WP to scale-rotate the original at
	 * our limit during `wp_create_image_subsizes()`, which is cheaper
	 * than us re-encoding a full-resolution intermediate later.
	 *
	 * @since 0.1.0
	 *
	 * @param int $threshold Default WP threshold (2560).
	 *
	 * @return int
	 */
	public function filter_big_image_threshold( $threshold ): int {
		$threshold = is_int( $threshold ) ? $threshold : (int) $threshold;
		$rules     = Image_Processor::from_settings();
		$max_edge  = (int) ( $rules['max_edge'] ?? $threshold );

		return min( $threshold, $max_edge );
	}

	/**
	 * Process an uploaded attachment after WP has generated its metadata.
	 *
	 * Top-level orchestrator. Pre-condition checks live in
	 * `should_process()`; the actual work lives in `run_pipeline()`.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed  $metadata      Generated attachment metadata.
	 * @param mixed  $attachment_id Attachment post ID.
	 * @param string $context       Either 'create' or 'update'.
	 *
	 * @return array<string, mixed>
	 */
	public function process_after_metadata( $metadata, $attachment_id, $context = '' ): array {
		$result = is_array( $metadata ) ? $metadata : array();

		if ( $this->should_process( (int) $attachment_id, (string) $context ) ) {
			$new_metadata = $this->run_pipeline( $result, (int) $attachment_id );

			if ( is_array( $new_metadata ) ) {
				$result = $new_metadata;
			}
		}

		return $result;
	}

	/**
	 * Decide whether to process this attachment at all.
	 *
	 * All cheap pre-condition checks live here. Returning false means
	 * the metadata flows through unchanged — no backup, no mutation.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $context       Filter context.
	 *
	 * @return bool
	 */
	private function should_process( int $attachment_id, string $context ): bool {
		// We only act on metadata generated for newly-created attachments.
		// 'update' fires when our own pipeline (or another plugin) regenerates
		// metadata for an existing attachment — re-processing then would loop.
		if ( 'create' !== $context ) {
			return false;
		}

		if ( $attachment_id <= 0 ) {
			return false;
		}

		// Honour the protected flag set by the operator on the attachment.
		$protected = get_post_meta( $attachment_id, META_PROTECTED, true );

		if ( ! empty( $protected ) ) {
			return false;
		}

		$settings = get_plugin()->get_settings();

		// Dry-run setting suppresses upload-time processing too — operator
		// who's previewing should not have new uploads silently processed.
		if ( (bool) $settings->get( OPT_BEHAVIOUR_DRY_RUN ) ) {
			return false;
		}

		// Skip-memo: previous attempt with the current settings hash
		// already yielded a result larger than source.
		$rules = Image_Processor::from_settings( $settings );
		$hash  = Image_Processor::settings_hash( $rules );

		if ( Skip_Memo::should_skip( $attachment_id, $hash ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Carry out the backup → execute → swap → regenerate-intermediates
	 * pipeline. Returns the new metadata (or the original on no-mutation).
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $metadata      Original metadata from WP.
	 * @param int                  $attachment_id Attachment post ID.
	 *
	 * @return array<string, mixed>
	 */
	private function run_pipeline( array $metadata, int $attachment_id ): array {
		$result = $metadata;

		$current_file = (string) get_attached_file( $attachment_id );

		if ( '' === $current_file || ! file_exists( $current_file ) ) {
			return $result;
		}

		$settings       = get_plugin()->get_settings();
		$rules          = Image_Processor::from_settings( $settings );
		$hash           = Image_Processor::settings_hash( $rules );
		$backup_enabled = (bool) $settings->get( OPT_BEHAVIOUR_BACKUP_ORIGINALS );
		$processor      = new Image_Processor();
		$plan           = $processor->plan( $current_file, $rules );

		if ( 'skip' === ( $plan['action'] ?? 'skip' ) ) {
			return $result;
		}

		// Backup before any mutation. If backup was requested but failed,
		// abort — we never want to mutate without a safety net.
		if ( $backup_enabled && ! Trash_Manager::backup( $attachment_id ) ) {
			return $result;
		}

		$exec = $processor->execute( $plan, $current_file );

		if ( ! $exec['success'] ) {
			if ( $backup_enabled ) {
				Trash_Manager::purge( $attachment_id );
			}
			return $result;
		}

		if ( ! $exec['committed'] ) {
			// Larger-than-source: record memo so we don't try again under the
			// same settings, and discard the unused backup.
			Skip_Memo::record( $attachment_id, (string) ( $plan['target_mime'] ?? '' ), $hash );

			if ( $backup_enabled ) {
				Trash_Manager::purge( $attachment_id );
			}

			return $result;
		}

		// Mutation phase.
		$result = $this->commit_mutation(
			$attachment_id,
			$current_file,
			$exec,
			$plan,
			$metadata,
			$backup_enabled
		);

		return $result;
	}

	/**
	 * Move the executed temp file into place, update WP attachment state,
	 * regenerate intermediate sizes, and mark the attachment as processed.
	 *
	 * Extracted from `run_pipeline()` to keep that method's control flow
	 * legible.
	 *
	 * @since 0.1.0
	 *
	 * @param int                  $attachment_id  Attachment post ID.
	 * @param string               $current_file   Current attached file path.
	 * @param array<string, mixed> $exec           Image_Processor::execute() result.
	 * @param array<string, mixed> $plan           The Plan produced by Image_Processor::plan().
	 * @param array<string, mixed> $orig_metadata  WP-generated metadata about to be replaced.
	 * @param bool                 $backup_enabled Whether the operator has originals-backup enabled.
	 *
	 * @return array<string, mixed> New metadata for WP.
	 */
	private function commit_mutation(
		int $attachment_id,
		string $current_file,
		array $exec,
		array $plan,
		array $orig_metadata,
		bool $backup_enabled
	): array {
		$output_path = (string) $exec['output_path'];
		$output_mime = (string) ( $exec['output_meta']['mime'] ?? $plan['target_mime'] ?? '' );
		$source_mime = (string) ( $plan['source_meta']['mime'] ?? '' );
		$final_path  = $this->compute_final_path( $current_file, $output_mime );

		// Delete WP's just-built intermediate sizes — they were derived from
		// the old source file and are now stale (wrong format and/or
		// dimensions).
		$this->delete_intermediates( $current_file, $orig_metadata );

		// Delete the old source file if the filename is changing.
		if ( $current_file !== $final_path && file_exists( $current_file ) ) {
			wp_delete_file( $current_file );
		}

		// Move temp output into place.
		if ( $output_path !== $final_path ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- Both paths are under wp-content/uploads/; WP_Filesystem is for credentialed access. Failure handled by the file_exists check below.
			@rename( $output_path, $final_path );
		}

		if ( ! file_exists( $final_path ) ) {
			// Move failed and we already deleted the old file — escape with
			// the metadata WP gave us; the operator's site is in a
			// recoverable but degraded state. The trash backup is the
			// safety net.
			return $orig_metadata;
		}

		update_attached_file( $attachment_id, $final_path );

		if ( $output_mime !== $source_mime ) {
			wp_update_post(
				array(
					'ID'             => $attachment_id,
					'post_mime_type' => $output_mime,
				)
			);

			// Mark the backup record so the Trash admin page can warn that
			// DB references may be stale until M6 search-replace runs.
			if ( $backup_enabled ) {
				$backup = Trash_Manager::get_backup( $attachment_id );

				if ( ! is_null( $backup ) ) {
					$backup['filename_changed'] = true;
					update_post_meta( $attachment_id, META_BACKUP, $backup );
				}
			}
		}

		if ( ! function_exists( 'wp_create_image_subsizes' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// Avoid recursion: unhook ourselves while regenerating intermediate
		// sizes (which fires wp_generate_attachment_metadata internally).
		remove_filter( 'wp_generate_attachment_metadata', array( $this, 'process_after_metadata' ), 10 );
		$new_metadata = wp_create_image_subsizes( $final_path, $attachment_id );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'process_after_metadata' ), 10, 3 );

		if ( ! is_array( $new_metadata ) ) {
			$new_metadata = $orig_metadata;
		}

		update_post_meta( $attachment_id, META_PROCESSED_AT, $this->now_formatted() );

		return $new_metadata;
	}

	/**
	 * Compute the destination path for the processed file.
	 *
	 * Same directory as the source; extension swapped to match the target
	 * MIME. If MIME hasn't changed, returns the source path (overwrite).
	 *
	 * @since 0.1.0
	 *
	 * @param string $source_path Current attached-file path.
	 * @param string $target_mime Target MIME type.
	 *
	 * @return string
	 */
	private function compute_final_path( string $source_path, string $target_mime ): string {
		$ext_map = array(
			MIME_JPEG => 'jpg',
			MIME_PNG  => 'png',
			MIME_WEBP => 'webp',
			MIME_AVIF => 'avif',
			MIME_GIF  => 'gif',
		);

		$target_ext = $ext_map[ $target_mime ] ?? '';

		if ( '' === $target_ext ) {
			return $source_path;
		}

		$pathinfo  = pathinfo( $source_path );
		$current_e = strtolower( $pathinfo['extension'] ?? '' );

		if ( $current_e === $target_ext ) {
			return $source_path;
		}

		// Treat .jpg/.jpeg as equivalent so we don't churn extensions.
		if ( ( 'jpg' === $current_e || 'jpeg' === $current_e ) && 'jpg' === $target_ext ) {
			return $source_path;
		}

		return $pathinfo['dirname'] . '/' . $pathinfo['filename'] . '.' . $target_ext;
	}

	/**
	 * Delete WP-generated intermediate-size files referenced by the
	 * original metadata.
	 *
	 * Used after the source file changes — those intermediates are stale.
	 * Also cleans the WP-core `original_image` (the unscaled rotation
	 * kept by `big_image_size_threshold`) when present.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $source_path   Current source file path
	 *                                            (provides the intermediates' directory).
	 * @param array<string, mixed> $orig_metadata WP-generated metadata.
	 *
	 * @return void
	 */
	private function delete_intermediates( string $source_path, array $orig_metadata ): void {
		$base_dir = trailingslashit( dirname( $source_path ) );

		if ( ! empty( $orig_metadata['sizes'] ) && is_array( $orig_metadata['sizes'] ) ) {
			foreach ( $orig_metadata['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$intermediate = $base_dir . $size['file'];

					if ( file_exists( $intermediate ) ) {
						wp_delete_file( $intermediate );
					}
				}
			}
		}

		if ( ! empty( $orig_metadata['original_image'] ) ) {
			$original_image = $base_dir . $orig_metadata['original_image'];

			if ( file_exists( $original_image ) ) {
				wp_delete_file( $original_image );
			}
		}
	}

	/**
	 * Current time as a human-readable string with timezone, per house style.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	private function now_formatted(): string {
		$now = new \DateTime( 'now', wp_timezone() );

		return $now->format( 'Y-m-d H:i:s T' );
	}
}
