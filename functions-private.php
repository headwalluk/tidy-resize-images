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
