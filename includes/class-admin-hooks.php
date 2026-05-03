<?php
/**
 * Admin hooks: menu registration, admin notices, and (later) asset enqueueing.
 *
 * @package Tidy_Resize_Images
 */

namespace Tidy_Resize_Images;

defined( 'ABSPATH' ) || die();

/**
 * Registers admin-side surfaces.
 *
 * Responsibilities:
 * - Register the top-level admin menu entry and route its render callback
 *   to the appropriate template.
 * - Render admin notices, including the conflict notice when competing
 *   image-optimization plugins are active alongside this one.
 *
 * Asset enqueueing is deferred until M3 when the settings page acquires
 * its own JS/CSS.
 *
 * @since 0.1.0
 */
class Admin_Hooks {

	/**
	 * Register the top-level admin menu entry.
	 *
	 * Hooked to admin_menu by Plugin::run().
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'Tidy Resize Images', 'tidy-resize-images' ),
			__( 'Tidy Images', 'tidy-resize-images' ),
			ADMIN_CAPABILITY,
			ADMIN_MENU_SLUG,
			array( $this, 'render_settings_page' ),
			'dashicons-format-image',
			80
		);
	}

	/**
	 * Render the settings page by including its template.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		require TRI_PLUGIN_DIR . 'admin-templates/settings-page.php';
	}

	/**
	 * Render admin notices for this plugin.
	 *
	 * Currently renders only the conflict notice (when competing image
	 * plugins are active). Hooked to admin_notices by Plugin::run().
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render_notices(): void {
		$conflicts = get_active_conflicts();

		if ( ! empty( $conflicts ) ) {
			require TRI_PLUGIN_DIR . 'admin-templates/conflict-notice.php';
		}
	}
}
