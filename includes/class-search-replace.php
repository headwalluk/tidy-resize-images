<?php
/**
 * DB Search & Replace: rewrite URL references when an attachment file is renamed.
 *
 * @package Tidy_Resize_Images
 */

namespace Tidy_Resize_Images;

defined( 'ABSPATH' ) || die();

/**
 * Search & Replace.
 *
 * Updates references to a renamed attachment URL across `wp_posts.post_content`
 * and `wp_postmeta.meta_value`. Two URL forms are handled at every string leaf:
 *
 *   - Raw:           https://site.tld/wp-content/uploads/.../logo.png
 *   - JSON-escaped:  https:\/\/site.tld\/wp-content\/uploads\/...\/logo.png
 *
 * The escaped form covers values stored inside JSON-encoded blobs — Elementor
 * `_elementor_data`, Gutenberg block JSON, ACF flexible-content fields, etc.
 *
 * Postmeta values are unserialised, recursively walked, and reserialised to
 * preserve PHP serialisation semantics. Plain (non-serialised) string meta
 * values are str_replaced directly. Objects are not descended into for v1 —
 * they are rare in practice and walking private/protected properties is
 * risky.
 *
 * **Out of scope for v1:**
 * - `wp_options` rewrites
 * - Multisite tables (`wp_*_posts`, `wp_*_postmeta`)
 * - Our own `_tri_*` meta keys are skipped to avoid mutating backup state
 *
 * Always supports a `dry_run` mode that produces the same Report shape with
 * `dry_run=true` and no actual DB writes.
 *
 * @since 0.1.0
 */
class Search_Replace {

	/**
	 * Maximum sample rows per table included in the dry-run Report.
	 */
	private const SAMPLE_LIMIT = 10;

	/**
	 * Rewrite every `$old_url` reference to `$new_url` in scope.
	 *
	 * Report shape:
	 *   array(
	 *     'success' => bool,
	 *     'dry_run' => bool,
	 *     'old_url' => string,
	 *     'new_url' => string,
	 *     'tables'  => array(
	 *       'posts'    => array(
	 *         'rows_examined' => int,
	 *         'rows_changed'  => int,
	 *         'samples'       => array<array{row_id:int, col:string, occurrences:int}>,
	 *       ),
	 *       'postmeta' => array(
	 *         'rows_examined' => int,
	 *         'rows_changed'  => int,
	 *         'samples'       => array<array{row_id:int, post_id:int, meta_key:string, occurrences:int}>,
	 *       ),
	 *     ),
	 *   )
	 *
	 * @since 0.1.0
	 *
	 * @param string              $old_url Source URL to find.
	 * @param string              $new_url Replacement URL.
	 * @param array<string, bool> $scope   { 'posts' => bool, 'postmeta' => bool }.
	 *                                     Both default true if unset.
	 * @param bool                $dry_run When true, no DB writes; the Report
	 *                                     still shows what *would* change.
	 *
	 * @return array<string, mixed> Report.
	 */
	public function rewrite( string $old_url, string $new_url, array $scope = array(), bool $dry_run = false ): array {
		$report = array(
			'success' => true,
			'dry_run' => $dry_run,
			'old_url' => $old_url,
			'new_url' => $new_url,
			'tables'  => array(
				'posts'    => array(
					'rows_examined' => 0,
					'rows_changed'  => 0,
					'samples'       => array(),
				),
				'postmeta' => array(
					'rows_examined' => 0,
					'rows_changed'  => 0,
					'samples'       => array(),
				),
			),
		);

		if ( '' === $old_url || $old_url === $new_url ) {
			return $report;
		}

		$do_posts    = ! isset( $scope['posts'] ) || (bool) $scope['posts'];
		$do_postmeta = ! isset( $scope['postmeta'] ) || (bool) $scope['postmeta'];

		if ( $do_posts ) {
			$report['tables']['posts'] = $this->rewrite_posts( $old_url, $new_url, $dry_run );
		}

		if ( $do_postmeta ) {
			$report['tables']['postmeta'] = $this->rewrite_postmeta( $old_url, $new_url, $dry_run );
		}

		return $report;
	}

	/**
	 * Rewrite all URL references for an attachment whose files have been
	 * renamed (e.g. PNG → WebP conversion).
	 *
	 * Derives every `(old_url, new_url)` rename pair by comparing the
	 * before/after `_wp_attachment_metadata` arrays — full-size + every
	 * intermediate size + the WP-core `original_image` (when present) —
	 * then calls `rewrite()` for each pair, accumulating a single Report.
	 *
	 * Intended for the M5 / M7 / M8 commit step: when the processor
	 * changes a file's MIME, the caller passes the original metadata
	 * (captured before the swap) and the new metadata (from
	 * `wp_create_image_subsizes()` on the converted file).
	 *
	 * @since 0.1.0
	 *
	 * @param int                  $attachment_id Attachment post ID.
	 * @param array<string, mixed> $old_meta      Pre-swap metadata.
	 * @param array<string, mixed> $new_meta      Post-swap metadata.
	 * @param array<string, bool>  $scope         { 'posts' => bool, 'postmeta' => bool }.
	 * @param bool                 $dry_run       No DB writes if true.
	 *
	 * @return array<string, mixed> Report with `attachment_id` and
	 *                              `pairs_processed` added.
	 */
	public function rewrite_attachment_rename(
		int $attachment_id,
		array $old_meta,
		array $new_meta,
		array $scope = array(),
		bool $dry_run = false
	): array {
		$pairs    = $this->derive_rename_pairs( $old_meta, $new_meta );
		$combined = $this->blank_report( $dry_run );

		$combined['attachment_id']   = $attachment_id;
		$combined['pairs_processed'] = count( $pairs );
		$combined['pairs']           = $pairs;

		foreach ( $pairs as $pair ) {
			$report   = $this->rewrite( $pair['old_url'], $pair['new_url'], $scope, $dry_run );
			$combined = $this->merge_report( $combined, $report );
		}

		return $combined;
	}

	/**
	 * Derive every `(old_url, new_url)` rename pair from before/after
	 * `_wp_attachment_metadata` arrays.
	 *
	 * Yields pairs for the full-size file, every intermediate size whose
	 * basename has changed, and the WP-core `original_image` (when
	 * present and renamed).
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $old_meta Pre-swap metadata.
	 * @param array<string, mixed> $new_meta Post-swap metadata.
	 *
	 * @return array<int, array{old_url: string, new_url: string}>
	 */
	private function derive_rename_pairs( array $old_meta, array $new_meta ): array {
		$upload   = wp_upload_dir();
		$base_url = trailingslashit( (string) ( $upload['baseurl'] ?? '' ) );

		$old_file = (string) ( $old_meta['file'] ?? '' );
		$new_file = (string) ( $new_meta['file'] ?? '' );

		$pairs = array();

		// Full-size.
		if ( '' !== $old_file && '' !== $new_file && $old_file !== $new_file ) {
			$pairs[] = array(
				'old_url' => $base_url . $old_file,
				'new_url' => $base_url . $new_file,
			);
		}

		// Intermediate sizes live in the same dir as the full-size file.
		$old_dir_url = '' !== $old_file ? $base_url . trailingslashit( dirname( $old_file ) ) : '';
		$new_dir_url = '' !== $new_file ? $base_url . trailingslashit( dirname( $new_file ) ) : '';

		$old_sizes = isset( $old_meta['sizes'] ) && is_array( $old_meta['sizes'] ) ? $old_meta['sizes'] : array();
		$new_sizes = isset( $new_meta['sizes'] ) && is_array( $new_meta['sizes'] ) ? $new_meta['sizes'] : array();

		foreach ( $old_sizes as $size_key => $old_size ) {
			$old_basename = (string) ( $old_size['file'] ?? '' );
			$new_basename = (string) ( $new_sizes[ $size_key ]['file'] ?? '' );

			if ( '' !== $old_basename && '' !== $new_basename && $old_basename !== $new_basename ) {
				$pairs[] = array(
					'old_url' => $old_dir_url . $old_basename,
					'new_url' => $new_dir_url . $new_basename,
				);
			}
		}

		// WP's `original_image` (kept by big_image_size_threshold scaling).
		$old_orig = (string) ( $old_meta['original_image'] ?? '' );
		$new_orig = (string) ( $new_meta['original_image'] ?? '' );

		if ( '' !== $old_orig && '' !== $new_orig && $old_orig !== $new_orig ) {
			$pairs[] = array(
				'old_url' => $old_dir_url . $old_orig,
				'new_url' => $new_dir_url . $new_orig,
			);
		}

		return $pairs;
	}

	/**
	 * Build an empty Report with default zero values.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $dry_run Whether the upcoming run is a dry-run.
	 *
	 * @return array<string, mixed>
	 */
	private function blank_report( bool $dry_run ): array {
		return array(
			'success' => true,
			'dry_run' => $dry_run,
			'old_url' => '',
			'new_url' => '',
			'tables'  => array(
				'posts'    => array(
					'rows_examined' => 0,
					'rows_changed'  => 0,
					'samples'       => array(),
				),
				'postmeta' => array(
					'rows_examined' => 0,
					'rows_changed'  => 0,
					'samples'       => array(),
				),
			),
		);
	}

	/**
	 * Merge a per-pair Report into a running accumulator.
	 *
	 * Sums rows_examined and rows_changed; appends samples up to
	 * SAMPLE_LIMIT total per table; carries any failure flag.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $accumulator Running total.
	 * @param array<string, mixed> $latest      One pair's Report.
	 *
	 * @return array<string, mixed>
	 */
	private function merge_report( array $accumulator, array $latest ): array {
		foreach ( array( 'posts', 'postmeta' ) as $table ) {
			$accumulator['tables'][ $table ]['rows_examined'] += (int) ( $latest['tables'][ $table ]['rows_examined'] ?? 0 );
			$accumulator['tables'][ $table ]['rows_changed']  += (int) ( $latest['tables'][ $table ]['rows_changed'] ?? 0 );

			$room = self::SAMPLE_LIMIT - count( $accumulator['tables'][ $table ]['samples'] );

			if ( $room > 0 ) {
				$accumulator['tables'][ $table ]['samples'] = array_merge(
					$accumulator['tables'][ $table ]['samples'],
					array_slice( (array) ( $latest['tables'][ $table ]['samples'] ?? array() ), 0, $room )
				);
			}
		}

		if ( empty( $latest['success'] ) ) {
			$accumulator['success'] = false;
		}

		return $accumulator;
	}

	/**
	 * Rewrite occurrences in `wp_posts.post_content`.
	 *
	 * @since 0.1.0
	 *
	 * @param string $old_url Source URL.
	 * @param string $new_url Replacement URL.
	 * @param bool   $dry_run No DB writes if true.
	 *
	 * @return array<string, mixed> Per-table report fragment.
	 */
	private function rewrite_posts( string $old_url, string $new_url, bool $dry_run ): array {
		global $wpdb;

		$report = array(
			'rows_examined' => 0,
			'rows_changed'  => 0,
			'samples'       => array(),
		);

		$like_raw  = '%' . $wpdb->esc_like( $old_url ) . '%';
		$like_json = '%' . $wpdb->esc_like( $this->json_escape( $old_url ) ) . '%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Search-replace inherently scans across all matching rows; cache invalidation handled by clean_post_cache below.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_content FROM {$wpdb->posts} WHERE post_content LIKE %s OR post_content LIKE %s",
				$like_raw,
				$like_json
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$report['rows_examined'] = count( (array) $rows );

		foreach ( (array) $rows as $row ) {
			$new_content = $this->rewrite_string( (string) $row->post_content, $old_url, $new_url );

			if ( $new_content !== $row->post_content ) {
				++$report['rows_changed'];

				if ( count( $report['samples'] ) < self::SAMPLE_LIMIT ) {
					$report['samples'][] = array(
						'row_id'      => (int) $row->ID,
						'col'         => 'post_content',
						'occurrences' => $this->count_occurrences( (string) $row->post_content, $old_url ),
					);
				}

				if ( ! $dry_run ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct UPDATE of post_content; clean_post_cache below invalidates.
					$wpdb->update(
						$wpdb->posts,
						array( 'post_content' => $new_content ),
						array( 'ID' => (int) $row->ID )
					);
					clean_post_cache( (int) $row->ID );
				}
			}
		}

		return $report;
	}

	/**
	 * Rewrite occurrences in `wp_postmeta.meta_value`, serialized-data-aware.
	 *
	 * @since 0.1.0
	 *
	 * @param string $old_url Source URL.
	 * @param string $new_url Replacement URL.
	 * @param bool   $dry_run No DB writes if true.
	 *
	 * @return array<string, mixed> Per-table report fragment.
	 */
	private function rewrite_postmeta( string $old_url, string $new_url, bool $dry_run ): array {
		global $wpdb;

		$report = array(
			'rows_examined' => 0,
			'rows_changed'  => 0,
			'samples'       => array(),
		);

		$like_raw  = '%' . $wpdb->esc_like( $old_url ) . '%';
		$like_json = '%' . $wpdb->esc_like( $this->json_escape( $old_url ) ) . '%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Search-replace inherently scans all matching rows by meta_value; cache invalidation via clean_post_cache below.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_value LIKE %s OR meta_value LIKE %s",
				$like_raw,
				$like_json
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value

		foreach ( (array) $rows as $row ) {
			// Skip our own meta — mutating META_BACKUP etc. would corrupt
			// the trash record.
			if ( str_starts_with( (string) $row->meta_key, '_tri_' ) ) {
				continue;
			}

			++$report['rows_examined'];

			list( $new_value, $changed_count ) = $this->rewrite_value( $row->meta_value, $old_url, $new_url );

			if ( $changed_count > 0 ) {
				++$report['rows_changed'];

				if ( count( $report['samples'] ) < self::SAMPLE_LIMIT ) {
					$report['samples'][] = array(
						'row_id'      => (int) $row->meta_id,
						'post_id'     => (int) $row->post_id,
						'meta_key'    => (string) $row->meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- sample-report field name, not a query input.
						'occurrences' => $changed_count,
					);
				}

				if ( ! $dry_run ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct UPDATE of meta_value; clean_post_cache invalidates.
					$wpdb->update(
						$wpdb->postmeta,
						array( 'meta_value' => $new_value ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- $wpdb->update column array, not a meta_query input.
						array( 'meta_id' => (int) $row->meta_id )
					);
					clean_post_cache( (int) $row->post_id );
				}
			}
		}

		return $report;
	}

	/**
	 * Rewrite a meta value (which may be a serialised array, a serialised
	 * scalar, or a plain string).
	 *
	 * Returns `[ $new_value, $occurrences ]`.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed  $raw     Raw meta value as stored in the DB.
	 * @param string $old_url Source URL.
	 * @param string $new_url Replacement URL.
	 *
	 * @return array{0: mixed, 1: int}
	 */
	private function rewrite_value( $raw, string $old_url, string $new_url ): array {
		if ( ! is_string( $raw ) ) {
			return array( $raw, 0 );
		}

		if ( is_serialized( $raw ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- We MUST handle PHP-serialised meta to support the WordPress storage convention. allowed_classes=false neutralises the object-injection risk.
			$data                             = @unserialize( $raw, array( 'allowed_classes' => false ) );
			list( $new_data, $changed_count ) = $this->walk_data( $data, $old_url, $new_url );

			if ( $changed_count > 0 ) {
				return array( maybe_serialize( $new_data ), $changed_count );
			}

			return array( $raw, 0 );
		}

		$new_string = $this->rewrite_string( $raw, $old_url, $new_url );

		if ( $new_string !== $raw ) {
			return array( $new_string, $this->count_occurrences( $raw, $old_url ) );
		}

		return array( $raw, 0 );
	}

	/**
	 * Recursively walk a deserialized data structure and rewrite string leaves.
	 *
	 * Returns `[ $new_data, $changed_count ]`. Objects are returned unchanged
	 * — descending into private/protected properties is risky and the use case
	 * is rare.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed  $data    The (possibly nested) data.
	 * @param string $old_url Source URL.
	 * @param string $new_url Replacement URL.
	 *
	 * @return array{0: mixed, 1: int}
	 */
	private function walk_data( $data, string $old_url, string $new_url ): array {
		$total_changed = 0;
		$result        = $data;

		if ( is_array( $data ) ) {
			$result = array();

			foreach ( $data as $key => $value ) {
				list( $new_value, $sub_changed ) = $this->walk_data( $value, $old_url, $new_url );
				$result[ $key ]                  = $new_value;
				$total_changed                  += $sub_changed;
			}
		} elseif ( is_string( $data ) ) {
			$new_string = $this->rewrite_string( $data, $old_url, $new_url );

			if ( $new_string !== $data ) {
				$total_changed = $this->count_occurrences( $data, $old_url );
				$result        = $new_string;
			}
		}

		return array( $result, $total_changed );
	}

	/**
	 * Apply both raw and JSON-escaped str_replace to a string.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value   The string to rewrite.
	 * @param string $old_url Source URL.
	 * @param string $new_url Replacement URL.
	 *
	 * @return string
	 */
	private function rewrite_string( string $value, string $old_url, string $new_url ): string {
		$value = str_replace( $old_url, $new_url, $value );

		$old_json = $this->json_escape( $old_url );
		$new_json = $this->json_escape( $new_url );

		// Only apply the escaped substitution if the JSON form differs from
		// the raw form (i.e. URL contains forward slashes — virtually always).
		if ( $old_json !== $old_url ) {
			$value = str_replace( $old_json, $new_json, $value );
		}

		return $value;
	}

	/**
	 * Convert forward slashes to JSON-escaped backslash-slash.
	 *
	 * Example: `https://site.tld/path` → `https:\/\/site.tld\/path`.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url Source URL.
	 *
	 * @return string
	 */
	private function json_escape( string $url ): string {
		return str_replace( '/', '\\/', $url );
	}

	/**
	 * Count occurrences of `$old_url` (raw + JSON-escaped) in a string.
	 *
	 * @since 0.1.0
	 *
	 * @param string $haystack The string to search.
	 * @param string $old_url  The URL to count.
	 *
	 * @return int
	 */
	private function count_occurrences( string $haystack, string $old_url ): int {
		$raw_count  = substr_count( $haystack, $old_url );
		$json_form  = $this->json_escape( $old_url );
		$json_count = ( $json_form !== $old_url ) ? substr_count( $haystack, $json_form ) : 0;

		return $raw_count + $json_count;
	}
}
