<?php
/**
 * Internal helper functions for the plugin.
 *
 * Namespaced to Tidy_Resize_Images so symbols don't leak to the global
 * scope. Use tri_* aliases (in the entry-point file) only when a caller
 * cannot reach into our namespace.
 *
 * @package Tidy_Resize_Images
 */

namespace Tidy_Resize_Images;

defined( 'ABSPATH' ) || die();

/**
 * Get the global plugin instance.
 *
 * @since 0.1.0
 *
 * @return Plugin
 */
function get_plugin(): Plugin {
	global $tri_plugin_instance;
	return $tri_plugin_instance;
}

/**
 * Current time as a human-readable string with timezone, per house style.
 *
 * Used by collaborators that record `_tri_processed_at` and similar
 * datetime fields. Storing readable strings (not Unix timestamps) makes
 * postmeta self-documenting when read directly via SQL or wp-cli.
 *
 * @since 0.2.0
 *
 * @return string e.g. '2026-05-04 18:32:11 BST'.
 */
function now_formatted(): string {
	$now = new \DateTime( 'now', wp_timezone() );

	return $now->format( 'Y-m-d H:i:s T' );
}

/**
 * Compute the destination path for a processed image file.
 *
 * Same directory as the source; extension swapped to match the target
 * MIME. If MIME hasn't changed, returns the source path (overwrite). The
 * `.jpg`/`.jpeg` ambiguity is collapsed to `.jpg` so JPEG → JPEG
 * recompression doesn't churn the filename.
 *
 * @since 0.2.0
 *
 * @param string $source_path Current attached-file path.
 * @param string $target_mime Target MIME type.
 *
 * @return string
 */
function compute_final_path( string $source_path, string $target_mime ): string {
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
 * Delete WP-generated intermediate-size files referenced by a metadata
 * array, plus the WP-core `original_image` (the unscaled rotation kept
 * by `big_image_size_threshold`) when present.
 *
 * Used before regenerating intermediates after a source-file swap — the
 * old sub-sizes were derived from the previous source (potentially wrong
 * format and/or dimensions) and are now stale.
 *
 * @since 0.2.0
 *
 * @param string               $source_path Current source file path
 *                                          (provides the intermediates' directory).
 * @param array<string, mixed> $metadata    WP-generated metadata.
 *
 * @return void
 */
function delete_intermediate_files( string $source_path, array $metadata ): void {
	$base_dir = trailingslashit( dirname( $source_path ) );

	if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
		foreach ( $metadata['sizes'] as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$intermediate = $base_dir . $size['file'];

				if ( file_exists( $intermediate ) ) {
					wp_delete_file( $intermediate );
				}
			}
		}
	}

	if ( ! empty( $metadata['original_image'] ) ) {
		$original_image = $base_dir . $metadata['original_image'];

		if ( file_exists( $original_image ) ) {
			wp_delete_file( $original_image );
		}
	}
}

/**
 * Cron callback: process a small batch of attachments.
 *
 * Called daily by WordPress's cron system (registered in the entry-point
 * file's activation hook). Bounded by DEF_CRON_BATCH_SIZE so a single
 * tick can't run away with server resources — large libraries process
 * incrementally over many days.
 *
 * Honours the operator's dry-run setting: if dry-run is on, the cron
 * runs without mutating. The expectation is that operators turn off
 * dry-run when they're ready for the cron to do real work.
 *
 * @since 0.1.0
 *
 * @return void
 */
function run_bulk_cron(): void {
	$settings = get_plugin()->get_settings();
	$dry_run  = (bool) $settings->get( OPT_BEHAVIOUR_DRY_RUN );

	$bp = new Bulk_Processor();
	$bp->run_batch( 0, DEF_CRON_BATCH_SIZE, $dry_run );
}

/**
 * Get the subset of CONFLICTING_PLUGINS that are currently active.
 *
 * Result is memoised on a global for the duration of the request, since
 * the active-plugin list is stable within a single page load.
 *
 * @since 0.1.0
 *
 * @return array<string, string> plugin_path => display_name
 */
function get_active_conflicts(): array {
	global $tri_active_conflicting_plugins;

	if ( is_null( $tri_active_conflicting_plugins ) ) {
		$tri_active_conflicting_plugins = array();

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( CONFLICTING_PLUGINS as $plugin_path => $plugin_name ) {
			if ( is_plugin_active( $plugin_path ) ) {
				$tri_active_conflicting_plugins[ $plugin_path ] = $plugin_name;
			}
		}
	}

	return $tri_active_conflicting_plugins;
}
