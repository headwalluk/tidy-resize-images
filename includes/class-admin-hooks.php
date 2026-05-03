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
 * - Enqueue the plugin's admin CSS / JS, scoped to the settings page so
 *   we don't bleed assets onto unrelated admin screens.
 *
 * @since 0.1.0
 */
class Admin_Hooks {

	/**
	 * Hook suffix WordPress uses for our top-level admin page.
	 *
	 * Format is `toplevel_page_<menu-slug>`.
	 *
	 * @var string
	 */
	private const SETTINGS_HOOK_SUFFIX = 'toplevel_page_' . ADMIN_MENU_SLUG;

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
	 * Enqueue the plugin's admin CSS and JS.
	 *
	 * Scoped to our settings page only — `$hook_suffix` matches the
	 * fully-qualified hook name WordPress passes to `admin_enqueue_scripts`.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 *
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( self::SETTINGS_HOOK_SUFFIX === $hook_suffix ) {
			wp_enqueue_style(
				'tri-admin',
				plugins_url( 'assets/admin/tri-admin.css', TRI_PLUGIN_FILE ),
				array(),
				TRI_PLUGIN_VERSION
			);

			wp_enqueue_script(
				'tri-admin',
				plugins_url( 'assets/admin/tri-admin.js', TRI_PLUGIN_FILE ),
				array(),
				TRI_PLUGIN_VERSION,
				true
			);
		}
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
