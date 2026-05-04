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
 *   builds intermediate sizes, hand off to `Attachment_Processor::process()`
 *   for the backup → execute → swap → regenerate pipeline.
 *
 * **Scope note (per project workflow priority):** upload-time processing
 * is a lower-priority safety net. The headline workflow is the bulk
 * processor (M7) running interactively or via a scheduled cron. This
 * class implements the happy path cleanly but does not gold-plate —
 * no per-context overrides, no upload-time dry-run UI.
 *
 * Re-entry guard: when our processor regenerates intermediate sizes via
 * `wp_create_image_subsizes()` it re-fires this filter. We detect the
 * recursion via `Attachment_Processor::is_running()` and bail out so
 * we don't try to re-process the just-swapped file.
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
	 * Pre-condition checks live in `should_process()`; the actual work is
	 * delegated to `Attachment_Processor::process()` and we map its
	 * Result back to the metadata shape this filter expects.
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
			$processor      = new Attachment_Processor();
			$process_result = $processor->process( (int) $attachment_id, false, $result );
			$post_swap_meta = $process_result['output_meta'] ?? array();

			if ( 'committed' === ( $process_result['action'] ?? '' ) && is_array( $post_swap_meta ) && ! empty( $post_swap_meta ) ) {
				$result = $post_swap_meta;
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

		// Re-entry guard: Attachment_Processor calls wp_create_image_subsizes
		// during its commit, which re-fires this filter. Skip when we are
		// already inside that call — otherwise we would try to re-process the
		// just-swapped file.
		if ( Attachment_Processor::is_running() ) {
			return false;
		}

		if ( $attachment_id <= 0 ) {
			return false;
		}

		$settings = get_plugin()->get_settings();

		// Dry-run setting suppresses upload-time processing entirely — operator
		// who's previewing should not have new uploads silently processed.
		// Bulk runs honour dry-run via the Result, but uploads have no UI to
		// surface a planned action, so we just no-op.
		if ( (bool) $settings->get( OPT_BEHAVIOUR_DRY_RUN ) ) {
			return false;
		}

		return true;
	}
}
