<?php
/**
 * Attachment Processor: shared end-to-end commit pipeline used by
 * Upload_Handler, Bulk_Processor, and the M8 "Optimize Now" row action.
 *
 * @package Tidy_Resize_Images
 */

namespace Tidy_Resize_Images;

defined( 'ABSPATH' ) || die();

/**
 * Attachment Processor.
 *
 * Single shared `process()` entry point owns the destructive flow:
 *
 *   1. Guard checks (protected, source readable, skip-memo)
 *   2. Plan via Image_Processor
 *   3. Dry-run short-circuit (returns Result with action='planned')
 *   4. Backup original to trash (if enabled)
 *   5. Execute transformation to a temp file
 *   6. Discard if result was larger than source (records skip-memo)
 *   7. Swap temp file into place, delete stale intermediates
 *   8. Regenerate WP intermediate sizes for the new file
 *   9. Search-replace DB references when filename changed
 *  10. Append `_tri_processed_at` + `_tri_processing_log` meta
 *
 * Callers may pass an optional `$orig_metadata` so Upload_Handler can hand
 * in the metadata WP just generated (which is in flight inside the
 * `wp_generate_attachment_metadata` filter and not yet saved to postmeta).
 * Bulk_Processor and "Optimize Now" pass null and we fetch via
 * `wp_get_attachment_metadata()`.
 *
 * Returns a single Result shape regardless of outcome — callers map to
 * whatever surface they need (filter return, log row, AJAX response).
 *
 * Search-replace runs unconditionally on filename changes. For a fresh
 * upload there are no DB references yet so the queries are no-ops; the
 * cost buys a uniform contract for third-party hooks.
 *
 * Re-entry guard: `wp_create_image_subsizes()` fires the
 * `wp_generate_attachment_metadata` filter that Upload_Handler hooks. We
 * set a per-request static flag while regenerating so the upload handler
 * can detect and skip self-induced recursion.
 *
 * @since 0.2.0
 */
class Attachment_Processor {

	/**
	 * Maximum entries kept in `_tri_processing_log` (most-recent-first).
	 */
	private const LOG_LIMIT = 5;

	/**
	 * Re-entry guard, set true while we are mid-flight inside
	 * `wp_create_image_subsizes()`. Per-request; concurrent requests have
	 * independent flags.
	 *
	 * @var bool
	 */
	private static bool $is_running = false;

	/**
	 * Public guard accessor — Upload_Handler::should_process() consults this
	 * to avoid double-processing the just-swapped file.
	 *
	 * @since 0.2.0
	 *
	 * @return bool
	 */
	public static function is_running(): bool {
		return self::$is_running;
	}

	/**
	 * Process an attachment end-to-end and return a single Result.
	 *
	 * Result shape:
	 *
	 *   array(
	 *     'id'              => int,
	 *     'action'          => 'committed' | 'discarded' | 'skipped' | 'errored' | 'planned',
	 *     'reason'          => string,
	 *     'source_mime'     => string,
	 *     'target_mime'     => string,
	 *     'quality'         => int,
	 *     'savings_bytes'   => int,
	 *     'savings_percent' => float,
	 *     'output_meta'     => array,    // post-swap WP metadata; empty unless action='committed'
	 *     'error'           => string,
	 *   )
	 *
	 * @since 0.2.0
	 *
	 * @param int                       $id            Attachment post ID.
	 * @param bool                      $dry_run       Plan without mutating.
	 * @param array<string, mixed>|null $orig_metadata Optional pre-swap
	 *                                                 metadata. If null the
	 *                                                 method fetches via
	 *                                                 wp_get_attachment_metadata().
	 *
	 * @return array<string, mixed>
	 */
	public function process( int $id, bool $dry_run, ?array $orig_metadata = null ): array {
		$result = $this->empty_result( $id );

		// Protected — operator marked do-not-touch.
		if ( ! empty( get_post_meta( $id, META_PROTECTED, true ) ) ) {
			$result['action'] = 'skipped';
			$result['reason'] = 'protected';
			return $result;
		}

		$current_file = (string) get_attached_file( $id );

		if ( '' === $current_file || ! file_exists( $current_file ) ) {
			$result['action'] = 'errored';
			$result['reason'] = 'source_unreadable';
			return $result;
		}

		$settings       = get_plugin()->get_settings();
		$rules          = Image_Processor::from_settings( $settings );
		$hash           = Image_Processor::settings_hash( $rules );
		$backup_enabled = (bool) $settings->get( OPT_BEHAVIOUR_BACKUP_ORIGINALS );

		// Skip-memo: previous attempt already discarded under these settings.
		if ( Skip_Memo::should_skip( $id, $hash ) ) {
			$result['action'] = 'skipped';
			$result['reason'] = 'skip_memo_match';
			return $result;
		}

		$processor   = new Image_Processor();
		$plan        = $processor->plan( $current_file, $rules );
		$source_meta = isset( $plan['source_meta'] ) && is_array( $plan['source_meta'] ) ? $plan['source_meta'] : array();

		$result['source_mime'] = (string) ( $source_meta['mime'] ?? '' );
		$result['target_mime'] = (string) ( $plan['target_mime'] ?? '' );
		$result['quality']     = (int) ( $plan['quality'] ?? 0 );
		$result['reason']      = (string) ( $plan['reason'] ?? '' );

		if ( 'skip' === ( $plan['action'] ?? 'skip' ) ) {
			$result['action'] = 'skipped';
			$this->record_log( $id, $result );
			return $result;
		}

		if ( $dry_run ) {
			// Planned-but-not-mutated. No log entry — dry-run is a preview,
			// not a real event in the attachment's history.
			$result['action'] = 'planned';
			return $result;
		}

		// Mutation phase from here on.
		if ( $backup_enabled && ! Trash_Manager::backup( $id ) ) {
			$result['action'] = 'errored';
			$result['reason'] = 'backup_failed';
			$this->record_log( $id, $result );
			return $result;
		}

		$exec = $processor->execute( $plan, $current_file );

		if ( ! $exec['success'] ) {
			if ( $backup_enabled ) {
				Trash_Manager::purge( $id );
			}
			$result['action'] = 'errored';
			$result['reason'] = 'execute_failed';
			$result['error']  = (string) ( $exec['error'] ?? '' );
			$this->record_log( $id, $result );
			return $result;
		}

		if ( ! $exec['committed'] ) {
			// Primary `convert` produced a result no smaller than the source.
			// Before declaring this a discard, give the source-format
			// recompression a shot — it usually wins for JPEGs that don't
			// compress well as WebP (already-low-quality, line art, etc.).
			$fallback = $this->try_recompress_fallback( $processor, $plan, $rules, $current_file );

			if ( ! is_null( $fallback ) ) {
				$exec                  = $fallback['exec'];
				$plan                  = $fallback['plan'];
				$result['target_mime'] = (string) $plan['target_mime'];
				$result['quality']     = (int) $plan['quality'];
				// (We don't propagate $plan['reason'] here — commit() will
				// overwrite result['reason'] to 'committed' before
				// record_log() runs. Operators can identify a fallback
				// commit from the log by source_mime == target_mime.)
			}
		}

		if ( ! $exec['committed'] ) {
			// Either no fallback was applicable (PNG/HEIC source, or the
			// primary was already a recompress) or the fallback also produced
			// a larger result. Record the memo and clean up.
			Skip_Memo::record( $id, (string) ( $plan['target_mime'] ?? '' ), $hash );

			if ( $backup_enabled ) {
				Trash_Manager::purge( $id );
			}

			$result['action']        = 'discarded';
			$result['reason']        = 'result_larger_than_source';
			$result['savings_bytes'] = (int) ( $exec['savings_bytes'] ?? 0 );
			$this->record_log( $id, $result );
			return $result;
		}

		// Take a metadata snapshot if the caller didn't pass one. Upload_Handler
		// passes the in-flight metadata directly because it isn't saved to
		// postmeta yet at the point the filter fires.
		if ( is_null( $orig_metadata ) ) {
			$fetched       = wp_get_attachment_metadata( $id );
			$orig_metadata = is_array( $fetched ) ? $fetched : array();
		}

		return $this->commit( $id, $current_file, $exec, $plan, $orig_metadata, $backup_enabled, $settings, $result );
	}

	/**
	 * Carry out the file swap, regenerate intermediates, run search-replace,
	 * and record processing meta. Extracted so the planning phase in
	 * `process()` stays linear.
	 *
	 * @since 0.2.0
	 *
	 * @param int                  $id             Attachment post ID.
	 * @param string               $current_file   Path of the source file.
	 * @param array<string, mixed> $exec           Image_Processor::execute() result.
	 * @param array<string, mixed> $plan           Image_Processor::plan() result.
	 * @param array<string, mixed> $orig_metadata  Pre-swap metadata.
	 * @param bool                 $backup_enabled Operator's backup setting.
	 * @param Settings             $settings       Settings instance for SR scope lookup.
	 * @param array<string, mixed> $result         Pre-populated Result.
	 *
	 * @return array<string, mixed>
	 */
	private function commit( int $id, string $current_file, array $exec, array $plan, array $orig_metadata, bool $backup_enabled, Settings $settings, array $result ): array {
		$output_path      = (string) $exec['output_path'];
		$output_mime      = (string) ( $exec['output_meta']['mime'] ?? $plan['target_mime'] ?? '' );
		$source_mime      = (string) ( $plan['source_meta']['mime'] ?? '' );
		$final_path       = compute_final_path( $current_file, $output_mime );
		$filename_changed = ( $output_mime !== $source_mime );

		// On the rename branch, snapshot every old derivative file into
		// trash before they're wiped. This lets `Trash_Manager::restore()`
		// put them back on disk and lets us write the old metadata back
		// directly — preserving theme-registered orphan size entries that
		// WP would otherwise drop on regeneration.
		if ( $filename_changed && $backup_enabled ) {
			Trash_Manager::backup_derivatives( $id, $orig_metadata );
		}

		// Stale intermediates — derived from the old format / dimensions.
		delete_intermediate_files( $current_file, $orig_metadata );

		if ( $current_file !== $final_path && file_exists( $current_file ) ) {
			wp_delete_file( $current_file );
		}

		if ( $output_path !== $final_path ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- Both paths under wp-content/uploads/; rename failure is handled by the file_exists check below.
			@rename( $output_path, $final_path );
		}

		if ( ! file_exists( $final_path ) ) {
			$result['action'] = 'errored';
			$result['reason'] = 'swap_failed';
			$this->record_log( $id, $result );
			return $result;
		}

		update_attached_file( $id, $final_path );

		if ( $filename_changed ) {
			wp_update_post(
				array(
					'ID'             => $id,
					'post_mime_type' => $output_mime,
				)
			);

			if ( $backup_enabled ) {
				$backup = Trash_Manager::get_backup( $id );

				if ( ! is_null( $backup ) ) {
					$backup['filename_changed'] = true;
					update_post_meta( $id, META_BACKUP, $backup );
				}
			}
		}

		if ( ! function_exists( 'wp_create_image_subsizes' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// Re-entry guard around the regenerate call. Upload_Handler's
		// wp_generate_attachment_metadata listener checks is_running() and
		// bails to avoid re-processing the just-swapped file.
		self::$is_running = true;
		$new_meta         = wp_create_image_subsizes( $final_path, $id );
		self::$is_running = false;

		if ( ! is_array( $new_meta ) ) {
			$new_meta = array();
		}

		// Gap-fill: regenerate any size that lived in the old metadata but
		// is no longer registered with WP, so theme-registered orphan
		// derivatives don't 404 after a format conversion.
		$derivatives_renamed = 0;

		if ( $filename_changed && ! empty( $orig_metadata['sizes'] ) && ! empty( $new_meta ) ) {
			$gap_result = $this->fill_orphan_derivatives( $final_path, $output_mime, $plan, $orig_metadata, $new_meta );
			$new_meta   = $gap_result['new_meta'];

			$derivatives_renamed = $gap_result['count'];

			if ( $gap_result['count'] > 0 ) {
				wp_update_attachment_metadata( $id, $new_meta );
			}
		}

		// Search-replace runs unconditionally when the filename changed —
		// no-op for fresh uploads (no DB references yet); essential for
		// existing attachments with content references.
		if ( $filename_changed && ! empty( $orig_metadata ) && ! empty( $new_meta ) ) {
			$sr = new Search_Replace();
			$sr->rewrite_attachment_rename( $id, $orig_metadata, $new_meta, $settings->sr_scope(), false );
		}

		update_post_meta( $id, META_PROCESSED_AT, now_formatted() );

		$result['action']              = 'committed';
		$result['reason']              = 'committed';
		$result['savings_bytes']       = (int) ( $exec['savings_bytes'] ?? 0 );
		$result['savings_percent']     = (float) ( $exec['savings_percent'] ?? 0.0 );
		$result['output_meta']         = $new_meta;
		$result['derivatives_renamed'] = $derivatives_renamed;

		$this->record_log( $id, $result );

		return $result;
	}

	/**
	 * Regenerate orphan derivative sizes that wp_create_image_subsizes
	 * dropped because the size key is no longer registered.
	 *
	 * Iterates the OLD metadata snapshot (not the currently-registered
	 * size list) — that's the entire point: themes that registered odd
	 * sizes (`696x461`, `534x462`) and have since deregistered them
	 * still have content references to those filenames. Without this
	 * step those references would 404 after a format conversion.
	 *
	 * For each old size_key not present in the new metadata:
	 *   1. Compute the expected new basename (old basename with new ext).
	 *   2. Regenerate the file at the old recorded width × height via
	 *      `Image_Processor::execute_derivative()`. Hard-cropped from
	 *      centre; old metadata has no crop offset.
	 *   3. Move the temp output into the uploads directory.
	 *   4. Inject a new entry into `$new_meta['sizes'][$size_key]` so
	 *      `Search_Replace::rewrite_attachment_rename()` pairs the URL
	 *      rewrite correctly.
	 *
	 * @since 0.5.0
	 *
	 * @param string               $final_path    Absolute path to the renamed parent.
	 * @param string               $output_mime   Target MIME (e.g. 'image/webp').
	 * @param array<string, mixed> $plan          Plan from Image_Processor (for quality + strip_exif).
	 * @param array<string, mixed> $orig_metadata Pre-rename metadata snapshot.
	 * @param array<string, mixed> $new_meta      Post-rename metadata from wp_create_image_subsizes.
	 *
	 * @return array{new_meta: array<string, mixed>, count: int}
	 */
	private function fill_orphan_derivatives( string $final_path, string $output_mime, array $plan, array $orig_metadata, array $new_meta ): array {
		$base_dir   = trailingslashit( dirname( $final_path ) );
		$new_ext    = mime_to_extension( $output_mime );
		$count      = 0;
		$processor  = new Image_Processor();
		$strip_exif = (bool) ( $plan['strip_exif'] ?? false );
		$quality    = (int) ( $plan['quality'] ?? 0 );

		$new_sizes = isset( $new_meta['sizes'] ) && is_array( $new_meta['sizes'] ) ? $new_meta['sizes'] : array();

		foreach ( $orig_metadata['sizes'] as $size_key => $old_size ) {
			if ( isset( $new_sizes[ $size_key ] ) ) {
				continue;
			}

			$old_basename = (string) ( $old_size['file'] ?? '' );
			$width        = (int) ( $old_size['width'] ?? 0 );
			$height       = (int) ( $old_size['height'] ?? 0 );

			if ( '' === $old_basename || $width <= 0 || $height <= 0 ) {
				continue;
			}

			$new_basename = swap_extension( $old_basename, $new_ext );
			$new_path     = $base_dir . $new_basename;

			$spec = array(
				'width'       => $width,
				'height'      => $height,
				'target_mime' => $output_mime,
				'quality'     => $quality,
				'strip_exif'  => $strip_exif,
			);

			$exec = $processor->execute_derivative( $spec, $final_path );

			if ( ! $exec['success'] ) {
				continue;
			}

			$tmp_output = (string) $exec['output_path'];

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- Both paths under wp-content/uploads/; rename failure is handled by the file_exists check below.
			if ( ! @rename( $tmp_output, $new_path ) || ! file_exists( $new_path ) ) {
				if ( file_exists( $tmp_output ) ) {
					wp_delete_file( $tmp_output );
				}

				continue;
			}

			$bytes_raw = filesize( $new_path );

			$new_meta['sizes'][ $size_key ] = array(
				'file'      => $new_basename,
				'width'     => $width,
				'height'    => $height,
				'mime-type' => $output_mime,
				'filesize'  => is_int( $bytes_raw ) ? $bytes_raw : 0,
			);

			++$count;
		}

		return array(
			'new_meta' => $new_meta,
			'count'    => $count,
		);
	}

	/**
	 * Attempt a source-format recompress fallback after a primary
	 * `convert` plan was discarded for being larger than the source.
	 *
	 * Returns an array `{ exec, plan }` on a successful fallback (caller
	 * uses these as the canonical exec/plan from here on), or null when
	 * either no fallback is applicable (`recompress_plan()` returned
	 * null) or the fallback itself failed / also produced a larger
	 * result.
	 *
	 * @since 0.4.0
	 *
	 * @param Image_Processor      $processor    Shared processor instance.
	 * @param array<string, mixed> $plan         Original (primary) plan.
	 * @param array<string, mixed> $rules        Ruleset.
	 * @param string               $current_file Source file path.
	 *
	 * @return array{exec: array<string, mixed>, plan: array<string, mixed>}|null
	 */
	private function try_recompress_fallback( Image_Processor $processor, array $plan, array $rules, string $current_file ): ?array {
		$fallback_plan = $processor->recompress_plan( $plan, $rules );

		if ( is_null( $fallback_plan ) ) {
			return null;
		}

		$fallback_exec = $processor->execute( $fallback_plan, $current_file );

		if ( ! $fallback_exec['success'] || ! $fallback_exec['committed'] ) {
			// Defensive cleanup — Image_Processor::execute already deletes
			// its own temp on the larger-than-source path, so this only
			// fires for unusual error states.
			$tmp = (string) ( $fallback_exec['output_path'] ?? '' );

			if ( '' !== $tmp && file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}

			return null;
		}

		return array(
			'exec' => $fallback_exec,
			'plan' => $fallback_plan,
		);
	}

	/**
	 * Append a Result summary to `_tri_processing_log` (newest first).
	 *
	 * Capped at LOG_LIMIT entries to bound postmeta size. Dry-run callers
	 * skip this method — a planned action is a preview, not a real event.
	 *
	 * @since 0.2.0
	 *
	 * @param int                  $id     Attachment post ID.
	 * @param array<string, mixed> $result Result whose action/reason/savings
	 *                                     are being recorded.
	 *
	 * @return void
	 */
	private function record_log( int $id, array $result ): void {
		$entry = array(
			'at'                  => now_formatted(),
			'action'              => (string) $result['action'],
			'reason'              => (string) $result['reason'],
			'source_mime'         => (string) $result['source_mime'],
			'target_mime'         => (string) $result['target_mime'],
			'savings_bytes'       => (int) $result['savings_bytes'],
			'savings_percent'     => (float) $result['savings_percent'],
			'derivatives_renamed' => (int) ( $result['derivatives_renamed'] ?? 0 ),
		);

		$existing = get_post_meta( $id, META_PROCESSING_LOG, true );
		$log      = is_array( $existing ) ? $existing : array();

		array_unshift( $log, $entry );

		if ( count( $log ) > self::LOG_LIMIT ) {
			$log = array_slice( $log, 0, self::LOG_LIMIT );
		}

		update_post_meta( $id, META_PROCESSING_LOG, $log );
	}

	/**
	 * Build an empty Result with default zero values.
	 *
	 * @since 0.2.0
	 *
	 * @param int $id Attachment post ID.
	 *
	 * @return array<string, mixed>
	 */
	private function empty_result( int $id ): array {
		return array(
			'id'                  => $id,
			'action'              => 'skipped',
			'reason'              => '',
			'source_mime'         => '',
			'target_mime'         => '',
			'quality'             => 0,
			'savings_bytes'       => 0,
			'savings_percent'     => 0.0,
			'derivatives_renamed' => 0,
			'output_meta'         => array(),
			'error'               => '',
		);
	}
}
