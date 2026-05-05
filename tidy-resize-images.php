<?php
/**
 * Plugin Name:       Tidy Resize Images
 * Plugin URI:        https://github.com/headwalluk/tidy-resize-images
 * Description:       Keep the WordPress Media Library lean. Resize oversized uploads, convert unsuitable formats, and recompress bloated files — with originals safely backed up to a Trash directory, dry-run preview, and full WP-CLI control.
 * Version:           0.4.1
 * Requires at least: 6.2
 * Requires PHP:      8.3
 * Author:            Paul Faulkner
 * Author URI:        https://headwall-hosting.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tidy-resize-images
 * Domain Path:       /languages
 *
 * @package Tidy_Resize_Images
 */

/*
 * Entry-point file stays in the root namespace so that the plugin path
 * constants below are accessible without qualification from any caller
 * (themes, mu-plugins, drop-ins). All implementation classes live under
 * the Tidy_Resize_Images namespace inside includes/.
 */

defined( 'ABSPATH' ) || die();

define( 'TRI_PLUGIN_FILE', __FILE__ );
define( 'TRI_PLUGIN_VERSION', '0.4.1' );
define( 'TRI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TRI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TRI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once TRI_PLUGIN_DIR . 'constants.php';
require_once TRI_PLUGIN_DIR . 'functions-private.php';
require_once TRI_PLUGIN_DIR . 'includes/class-plugin.php';
require_once TRI_PLUGIN_DIR . 'includes/class-admin-hooks.php';
require_once TRI_PLUGIN_DIR . 'includes/class-settings.php';
require_once TRI_PLUGIN_DIR . 'includes/class-capabilities.php';
require_once TRI_PLUGIN_DIR . 'includes/class-image-library.php';
require_once TRI_PLUGIN_DIR . 'includes/class-image-processor.php';
require_once TRI_PLUGIN_DIR . 'includes/class-skip-memo.php';
require_once TRI_PLUGIN_DIR . 'includes/class-trash-manager.php';
require_once TRI_PLUGIN_DIR . 'includes/class-search-replace.php';
require_once TRI_PLUGIN_DIR . 'includes/class-attachment-processor.php';
require_once TRI_PLUGIN_DIR . 'includes/class-upload-handler.php';
require_once TRI_PLUGIN_DIR . 'includes/class-bulk-processor.php';
require_once TRI_PLUGIN_DIR . 'includes/class-media-library-hooks.php';
require_once TRI_PLUGIN_DIR . 'includes/class-cli.php';
require_once TRI_PLUGIN_DIR . 'includes/class-cli-trash.php';
require_once TRI_PLUGIN_DIR . 'includes/class-cli-settings.php';

/**
 * Bootstrap the plugin: instantiate the orchestrator and register hooks.
 *
 * Stored on a global so other code can reach it via tri_get_plugin()
 * without re-instantiating.
 *
 * @since 0.1.0
 *
 * @return void
 */
function tri_plugin_run(): void {
	global $tri_plugin_instance;

	$tri_plugin_instance = new \Tidy_Resize_Images\Plugin();
	$tri_plugin_instance->run();
}
tri_plugin_run();

/**
 * Activation hook: schedule the daily bulk-processor cron.
 *
 * Idempotent — only schedules if not already scheduled. Initial run is
 * delayed by an hour so activation doesn't immediately spike CPU.
 *
 * @since 0.1.0
 *
 * @return void
 */
function tri_plugin_activate(): void {
	if ( ! wp_next_scheduled( \Tidy_Resize_Images\TRI_BULK_CRON_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', \Tidy_Resize_Images\TRI_BULK_CRON_HOOK );
	}
}

/**
 * Deactivation hook: clear the scheduled cron event.
 *
 * @since 0.1.0
 *
 * @return void
 */
function tri_plugin_deactivate(): void {
	wp_clear_scheduled_hook( \Tidy_Resize_Images\TRI_BULK_CRON_HOOK );
}

register_activation_hook( __FILE__, 'tri_plugin_activate' );
register_deactivation_hook( __FILE__, 'tri_plugin_deactivate' );
