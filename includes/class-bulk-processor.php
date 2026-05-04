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
 * Per-attachment processing delegates to `Attachment_Processor::process()`
 * — same pipeline used by Upload_Handler and the M8 "Optimize Now" row
 * action — and we map its Result onto the log-row shape consumed by the
 * Bulk page's JS driver.
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
	 *     'attachments_examined' => int,
	 *     'attachments_changed'  => int,
	 *     'attachments_skipped'  => int,
	 *     'attachments_errored'  => int,
	 *     'bytes_saved'          => int,
	 *     'last_cursor'          => int,
	 *     'done'                 => bool,
	 *     'log'                  => array<array{
	 *                                  id: int, title: string, action: string,
	 *                                  reason: string, savings_bytes: int,
	 *                                  savings_percent?: float, target_mime?: string,
	 *                                  quality?: int, error?: string,
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
		$ids    = $this->find_candidates( $cursor, $limit );

		if ( empty( $ids ) ) {
			$result['done'] = true;
			return $result;
		}

		$result['attachments_examined'] = count( $ids );

		$processor = new Attachment_Processor();

		foreach ( $ids as $id ) {
			$process_result        = $processor->process( $id, $dry_run );
			$log_entry             = $this->log_entry_from_result( $id, $process_result );
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
	 * Map an Attachment_Processor Result onto the log-row shape consumed
	 * by the Bulk page's JS driver.
	 *
	 * The shape predates Attachment_Processor (was authored against the
	 * inline pipeline this class once owned). Keeping the wire format
	 * unchanged means we don't have to touch the JS or the AJAX handler.
	 *
	 * @since 0.2.0
	 *
	 * @param int                  $id     Attachment post ID.
	 * @param array<string, mixed> $result Result from Attachment_Processor::process().
	 *
	 * @return array<string, mixed>
	 */
	private function log_entry_from_result( int $id, array $result ): array {
		$title = (string) get_the_title( $id );

		if ( '' === $title ) {
			/* translators: %d: attachment ID */
			$title = sprintf( __( 'Attachment %d', 'tidy-resize-images' ), $id );
		}

		return array(
			'id'              => $id,
			'title'           => $title,
			'action'          => (string) $result['action'],
			'reason'          => (string) $result['reason'],
			'savings_bytes'   => (int) ( $result['savings_bytes'] ?? 0 ),
			'savings_percent' => (float) ( $result['savings_percent'] ?? 0.0 ),
			'target_mime'     => (string) ( $result['target_mime'] ?? '' ),
			'quality'         => (int) ( $result['quality'] ?? 0 ),
			'error'           => (string) ( $result['error'] ?? '' ),
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
}
