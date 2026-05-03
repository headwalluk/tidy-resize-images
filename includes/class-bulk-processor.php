<?php
/**
 * Bulk processor: scan and process the existing Media Library in batches.
 *
 * @package Tidy_Resize_Images
 */

namespace Tidy_Resize_Images;

defined( 'ABSPATH' ) || die();

/**
 * Bulk Processor.
 *
 * The headline workflow for this plugin. Single shared `run_batch()`
 * method consumed by:
 *
 *   - The interactive admin AJAX runner (M7.2/3/4)
 *   - The scheduled daily cron (M7.5)
 *   - The `wp tidy-images process` WP-CLI command (M9)
 *
 * Cursor-based pagination via `ID > $cursor` keeps batches memory-bounded
 * and resumable. Each batch returns a Result with totals, the new cursor,
 * a `done` flag, and a per-attachment log.
 *
 * Scan filters (in SQL):
 *   - post_type = 'attachment', post_status = 'inherit'
 *   - post_mime_type LIKE 'image/%'
 *   - NO `_tri_protected` meta (or empty/'0')
 *   - NO `_tri_processed_at` meta
 *   - ID > cursor
 *
 * Skip-memo (`_tri_conversion_skipped`) checks happen in PHP per-attachment
 * because the settings_hash lives inside a serialised meta value and
 * cannot be filtered cheaply via SQL.
 *
 * Per-attachment processing duplicates the commit logic in
 * `Upload_Handler::commit_mutation()` for v1. A shared
 * `Attachment_Processor` helper extraction is queued for M8 when the
 * Media Library "Optimize Now" action becomes the third caller.
 *
 * @since 0.1.0
 */
class Bulk_Processor {

	/**
	 * Count attachments that match the scan criteria (would be processed).
	 *
	 * Used by the admin UI to show the upfront total before a run starts.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function count_candidates(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery -- Bulk-scan COUNT inherently scans posts + meta; cache invalidation not relevant for a count.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				WHERE p.post_type = 'attachment'
				  AND p.post_status = 'inherit'
				  AND p.post_mime_type LIKE %s
				  AND NOT EXISTS (
					  SELECT 1 FROM {$wpdb->postmeta} m1
					  WHERE m1.post_id = p.ID
						AND m1.meta_key = %s
						AND m1.meta_value NOT IN ( '', '0' )
				  )
				  AND NOT EXISTS (
					  SELECT 1 FROM {$wpdb->postmeta} m2
					  WHERE m2.post_id = p.ID
						AND m2.meta_key = %s
				  )",
				'image/%',
				META_PROTECTED,
				META_PROCESSED_AT
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery

		return $count;
	}

	/**
	 * Process up to `$limit` attachments with `ID > $cursor`.
	 *
	 * Returns a Result with totals, the cursor for the next batch, and a
	 * per-attachment log.
	 *
	 * Result shape:
	 *   array(
	 *     'attachments_examined' => int,  // how many we LOOKED at this batch
	 *     'attachments_changed'  => int,  // committed
	 *     'attachments_skipped'  => int,  // skip-memo / planned-skip / discarded
	 *     'attachments_errored'  => int,  // execute failed
	 *     'bytes_saved'          => int,  // sum of savings (committed only)
	 *     'last_cursor'          => int,  // largest ID processed; pass to next call
	 *     'done'                 => bool, // batch returned fewer rows than $limit
	 *     'log'                  => array<array{
	 *                                  id: int,
	 *                                  title: string,
	 *                                  action: 'committed'|'discarded'|'skipped'|'errored'|'planned',
	 *                                  reason: string,
	 *                                  savings_bytes: int,
	 *                                  savings_percent?: float,
	 *                                  target_mime?: string,
	 *                                  quality?: int,
	 *                                  error?: string,
	 *                                }>,
	 *   )
	 *
	 * @since 0.1.0
	 *
	 * @param int  $cursor  Largest ID already processed; this batch starts at ID > $cursor.
	 * @param int  $limit   Max attachments to process this call.
	 * @param bool $dry_run When true, plan without mutating.
	 *
	 * @return array<string, mixed>
	 */
	public function run_batch( int $cursor, int $limit, bool $dry_run ): array {
		$result = $this->empty_result();

		$settings       = get_plugin()->get_settings();
		$rules          = Image_Processor::from_settings( $settings );
		$hash           = Image_Processor::settings_hash( $rules );
		$backup_enabled = (bool) $settings->get( OPT_BEHAVIOUR_BACKUP_ORIGINALS );
		$sr_scope       = $settings->sr_scope();

		$ids = $this->find_candidates( $cursor, $limit );

		if ( empty( $ids ) ) {
			$result['done'] = true;
			return $result;
		}

		$result['attachments_examined'] = count( $ids );

		foreach ( $ids as $id ) {
			$log_entry             = $this->process_one( $id, $rules, $hash, $dry_run, $backup_enabled, $sr_scope );
			$result['log'][]       = $log_entry;
			$result['last_cursor'] = $id;

			switch ( $log_entry['action'] ) {
				case 'committed':
					++$result['attachments_changed'];
					$result['bytes_saved'] += (int) ( $log_entry['savings_bytes'] ?? 0 );
					break;
				case 'errored':
					++$result['attachments_errored'];
					break;
				case 'skipped':
				case 'discarded':
				case 'planned':
				default:
					++$result['attachments_skipped'];
					break;
			}
		}

		$result['done'] = count( $ids ) < $limit;

		return $result;
	}

	/**
	 * Find candidate attachment IDs.
	 *
	 * @since 0.1.0
	 *
	 * @param int $cursor Largest ID already processed.
	 * @param int $limit  Max IDs to return.
	 *
	 * @return array<int>
	 */
	private function find_candidates( int $cursor, int $limit ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery -- Bulk scan inherently scans posts + meta; not cacheable.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				WHERE p.post_type = 'attachment'
				  AND p.post_status = 'inherit'
				  AND p.post_mime_type LIKE %s
				  AND p.ID > %d
				  AND NOT EXISTS (
					  SELECT 1 FROM {$wpdb->postmeta} m1
					  WHERE m1.post_id = p.ID
						AND m1.meta_key = %s
						AND m1.meta_value NOT IN ( '', '0' )
				  )
				  AND NOT EXISTS (
					  SELECT 1 FROM {$wpdb->postmeta} m2
					  WHERE m2.post_id = p.ID
						AND m2.meta_key = %s
				  )
				ORDER BY p.ID ASC
				LIMIT %d",
				'image/%',
				$cursor,
				META_PROTECTED,
				META_PROCESSED_AT,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Process a single attachment and return its log entry.
	 *
	 * Top-level orchestrator. Cheap pre-condition checks short-circuit
	 * with guard clauses; the meaty mutation flow is in `commit_one()`.
	 *
	 * @since 0.1.0
	 *
	 * @param int                  $id              Attachment post ID.
	 * @param array<string, mixed> $rules           Ruleset from Image_Processor::from_settings().
	 * @param string               $hash            Settings hash for skip-memo comparison.
	 * @param bool                 $dry_run         Plan without mutating.
	 * @param bool                 $backup_enabled  Operator's backup_originals setting.
	 * @param array<string, bool>  $sr_scope        Search-replace scope.
	 *
	 * @return array<string, mixed> Log entry.
	 */
	private function process_one( int $id, array $rules, string $hash, bool $dry_run, bool $backup_enabled, array $sr_scope ): array {
		$log = $this->log_template( $id );

		if ( Skip_Memo::should_skip( $id, $hash ) ) {
			$log['reason'] = 'skip_memo_match';
			return $log;
		}

		$current_file = (string) get_attached_file( $id );

		if ( '' === $current_file || ! file_exists( $current_file ) ) {
			$log['action'] = 'errored';
			$log['reason'] = 'source_unreadable';
			return $log;
		}

		$processor = new Image_Processor();
		$plan      = $processor->plan( $current_file, $rules );

		if ( 'skip' === ( $plan['action'] ?? 'skip' ) ) {
			$log['reason'] = (string) ( $plan['reason'] ?? 'planned_skip' );
			return $log;
		}

		if ( $dry_run ) {
			$log['action']      = 'planned';
			$log['reason']      = (string) $plan['reason'];
			$log['target_mime'] = (string) $plan['target_mime'];
			$log['quality']     = (int) $plan['quality'];
			return $log;
		}

		return $this->commit_one( $id, $current_file, $plan, $hash, $backup_enabled, $sr_scope, $log );
	}

	/**
	 * Carry out the mutation for one attachment: backup → execute → swap →
	 * regenerate intermediates → search-replace → mark processed.
	 *
	 * Extracted from `process_one()` so the orchestrator's guard clauses
	 * stay readable and this method's flow control can use a `$proceed`
	 * flag to keep SESE for the meaty branches.
	 *
	 * @since 0.1.0
	 *
	 * @param int                  $id             Attachment post ID.
	 * @param string               $current_file   Current attached file path.
	 * @param array<string, mixed> $plan           Plan from Image_Processor.
	 * @param string               $hash           Settings hash for memo recording.
	 * @param bool                 $backup_enabled Operator's backup setting.
	 * @param array<string, bool>  $sr_scope       Search-replace scope.
	 * @param array<string, mixed> $log            Pre-populated log template.
	 *
	 * @return array<string, mixed>
	 */
	private function commit_one( int $id, string $current_file, array $plan, string $hash, bool $backup_enabled, array $sr_scope, array $log ): array {
		if ( $backup_enabled && ! Trash_Manager::backup( $id ) ) {
			$log['action'] = 'errored';
			$log['reason'] = 'backup_failed';
			return $log;
		}

		$pre_swap_meta = wp_get_attachment_metadata( $id );
		$processor     = new Image_Processor();
		$exec          = $processor->execute( $plan, $current_file );

		if ( ! $exec['success'] ) {
			if ( $backup_enabled ) {
				Trash_Manager::purge( $id );
			}
			$log['action'] = 'errored';
			$log['reason'] = 'execute_failed';
			$log['error']  = (string) ( $exec['error'] ?? '' );
			return $log;
		}

		if ( ! $exec['committed'] ) {
			Skip_Memo::record( $id, (string) ( $plan['target_mime'] ?? '' ), $hash );
			if ( $backup_enabled ) {
				Trash_Manager::purge( $id );
			}
			$log['action'] = 'discarded';
			$log['reason'] = 'result_larger_than_source';
			return $log;
		}

		$output_path = (string) $exec['output_path'];
		$output_mime = (string) ( $exec['output_meta']['mime'] ?? $plan['target_mime'] ?? '' );
		$source_mime = (string) ( $plan['source_meta']['mime'] ?? '' );
		$final_path  = $this->compute_final_path( $current_file, $output_mime );

		$this->delete_intermediates( $current_file, is_array( $pre_swap_meta ) ? $pre_swap_meta : array() );

		if ( $current_file !== $final_path && file_exists( $current_file ) ) {
			wp_delete_file( $current_file );
		}

		if ( $output_path !== $final_path ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- Both paths under uploads/; rename failure handled below.
			@rename( $output_path, $final_path );
		}

		if ( ! file_exists( $final_path ) ) {
			$log['action'] = 'errored';
			$log['reason'] = 'swap_failed';
			return $log;
		}

		update_attached_file( $id, $final_path );

		if ( $output_mime !== $source_mime ) {
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

		$new_meta = wp_create_image_subsizes( $final_path, $id );

		if ( ! is_array( $new_meta ) ) {
			$new_meta = array();
		}

		// Search-replace if the filename changed.
		if ( $output_mime !== $source_mime && is_array( $pre_swap_meta ) && ! empty( $new_meta ) ) {
			$sr = new Search_Replace();
			$sr->rewrite_attachment_rename( $id, $pre_swap_meta, $new_meta, $sr_scope, false );
		}

		update_post_meta( $id, META_PROCESSED_AT, $this->now_formatted() );

		$log['action']          = 'committed';
		$log['reason']          = 'committed';
		$log['savings_bytes']   = (int) ( $exec['savings_bytes'] ?? 0 );
		$log['savings_percent'] = (float) ( $exec['savings_percent'] ?? 0.0 );

		return $log;
	}

	/**
	 * Build a default log-entry template.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id Attachment post ID.
	 *
	 * @return array<string, mixed>
	 */
	private function log_template( int $id ): array {
		$title = (string) get_the_title( $id );

		if ( '' === $title ) {
			/* translators: %d: attachment ID */
			$title = sprintf( __( 'Attachment %d', 'tidy-resize-images' ), $id );
		}

		return array(
			'id'            => $id,
			'title'         => $title,
			'action'        => 'skipped',
			'reason'        => '',
			'savings_bytes' => 0,
		);
	}

	/**
	 * Build an empty Result with default zeros.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	private function empty_result(): array {
		return array(
			'attachments_examined' => 0,
			'attachments_changed'  => 0,
			'attachments_skipped'  => 0,
			'attachments_errored'  => 0,
			'bytes_saved'          => 0,
			'last_cursor'          => 0,
			'done'                 => false,
			'log'                  => array(),
		);
	}

	/**
	 * Compute the destination path for the processed file.
	 *
	 * Same directory as the source; extension swapped to match the target
	 * MIME. If MIME hasn't changed, returns the source path (overwrite).
	 *
	 * Duplicated from Upload_Handler::compute_final_path() — extraction
	 * to a shared helper is queued for M8.
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

		if ( ( 'jpg' === $current_e || 'jpeg' === $current_e ) && 'jpg' === $target_ext ) {
			return $source_path;
		}

		return $pathinfo['dirname'] . '/' . $pathinfo['filename'] . '.' . $target_ext;
	}

	/**
	 * Delete intermediate-size files referenced by the given metadata.
	 *
	 * Duplicated from Upload_Handler::delete_intermediates() — shared
	 * helper extraction queued for M8.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $source_path   Source file path (provides
	 *                                            the intermediates' directory).
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
