<?php
/**
 * Main plugin orchestrator.
 *
 * @package Tidy_Resize_Images
 */

namespace Tidy_Resize_Images;

defined( 'ABSPATH' ) || die();

/**
 * Plugin orchestrator.
 *
 * Owns the plugin lifecycle: lazy-instantiates collaborators and registers
 * WordPress hooks. Add new top-level features by adding a new collaborator
 * accessor (get_*) and wiring its hooks in run().
 *
 * @since 0.1.0
 */
class Plugin {

	/**
	 * Lazy-loaded admin hooks collaborator.
	 *
	 * Owns admin menu registration and admin_notices rendering.
	 *
	 * @var Admin_Hooks|null
	 */
	private ?Admin_Hooks $admin_hooks = null;

	/**
	 * Lazy-loaded settings collaborator.
	 *
	 * Per the project's settings-API pattern, this must be instantiated
	 * before admin_init fires so register_setting() calls land in time.
	 * `run()` resolves it eagerly inside the is_admin() branch.
	 *
	 * @var Settings|null
	 */
	private ?Settings $settings = null;

	/**
	 * Register all WordPress hooks.
	 *
	 * Front-end runs only the textdomain loader. Admin-only collaborators
	 * are wired inside the is_admin() branch to keep front-end overhead
	 * minimal.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function run(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		if ( is_admin() ) {
			$settings    = $this->get_settings();
			$admin_hooks = $this->get_admin_hooks();

			add_action( 'admin_init', array( $settings, 'register' ) );
			add_action( 'admin_menu', array( $admin_hooks, 'register_menu' ) );
			add_action( 'admin_notices', array( $admin_hooks, 'render_notices' ) );
			add_action( 'admin_enqueue_scripts', array( $admin_hooks, 'enqueue_assets' ) );
		}
	}

	/**
	 * Load the plugin's translations.
	 *
	 * The text-domain literal is intentionally not abstracted to a constant.
	 * WordPress's i18n tooling (and the WordPress.WP.I18n PHPCS sniff) both
	 * expect the literal string to appear at the call site.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'tidy-resize-images', false, TRI_PLUGIN_BASENAME . '/languages' );
	}

	/**
	 * Get (and lazily instantiate) the Admin_Hooks collaborator.
	 *
	 * @since 0.1.0
	 *
	 * @return Admin_Hooks
	 */
	public function get_admin_hooks(): Admin_Hooks {
		if ( is_null( $this->admin_hooks ) ) {
			$this->admin_hooks = new Admin_Hooks();
		}

		return $this->admin_hooks;
	}

	/**
	 * Get (and lazily instantiate) the Settings collaborator.
	 *
	 * @since 0.1.0
	 *
	 * @return Settings
	 */
	public function get_settings(): Settings {
		if ( is_null( $this->settings ) ) {
			$this->settings = new Settings();
		}

		return $this->settings;
	}
}
