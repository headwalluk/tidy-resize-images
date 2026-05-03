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
