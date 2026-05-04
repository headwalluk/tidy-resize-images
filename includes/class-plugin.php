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
	 * Lazy-loaded upload handler collaborator.
	 *
	 * Registers globally (not is_admin()-gated) — uploads can come from
	 * the front-end too.
	 *
	 * @var Upload_Handler|null
	 */
	private ?Upload_Handler $upload_handler = null;

	/**
	 * Lazy-loaded media-library hooks collaborator.
	 *
	 * Owns the upload.php list-table column and (in M8b) the row actions
	 * + AJAX endpoints for protect/unprotect/optimize/restore.
	 *
	 * @var Media_Library_Hooks|null
	 */
	private ?Media_Library_Hooks $media_library_hooks = null;

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

		// Upload handler must register globally — uploads can originate from
		// front-end forms (REST endpoints, plugin upload widgets, etc.).
		$this->get_upload_handler()->register_hooks();

		// Cron callback registered globally — cron events can fire in any
		// context, not just admin requests.
		add_action( TRI_BULK_CRON_HOOK, __NAMESPACE__ . '\\run_bulk_cron' );

		if ( is_admin() ) {
			$settings      = $this->get_settings();
			$admin_hooks   = $this->get_admin_hooks();
			$media_library = $this->get_media_library_hooks();

			add_action( 'admin_init', array( $settings, 'register' ) );
			add_action( 'admin_menu', array( $admin_hooks, 'register_menu' ) );
			add_action( 'admin_notices', array( $admin_hooks, 'render_notices' ) );
			add_action( 'admin_enqueue_scripts', array( $admin_hooks, 'enqueue_assets' ) );
			add_action( 'admin_post_tri_trash_restore', array( $admin_hooks, 'handle_trash_restore' ) );
			add_action( 'admin_post_tri_trash_restore_protect', array( $admin_hooks, 'handle_trash_restore_protect' ) );
			add_action( 'admin_post_tri_trash_purge', array( $admin_hooks, 'handle_trash_purge' ) );
			add_action( 'wp_ajax_tri_bulk_count', array( $admin_hooks, 'ajax_bulk_count' ) );
			add_action( 'wp_ajax_tri_bulk_step', array( $admin_hooks, 'ajax_bulk_step' ) );

			add_filter( 'manage_upload_columns', array( $media_library, 'register_columns' ) );
			add_action( 'manage_media_custom_column', array( $media_library, 'render_column' ), 10, 2 );
			add_filter( 'media_row_actions', array( $media_library, 'register_row_actions' ), 10, 2 );
			add_action( 'admin_enqueue_scripts', array( $media_library, 'enqueue_assets' ) );
			add_action( 'wp_ajax_tri_set_protected', array( $media_library, 'ajax_set_protected' ) );
			add_action( 'wp_ajax_tri_optimize_now', array( $media_library, 'ajax_optimize_now' ) );
			add_action( 'wp_ajax_tri_restore_original', array( $media_library, 'ajax_restore_original' ) );
			add_action( 'add_meta_boxes_attachment', array( $media_library, 'register_meta_box' ) );
			add_action( 'edit_attachment', array( $media_library, 'save_meta_box' ) );
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

	/**
	 * Get (and lazily instantiate) the Upload_Handler collaborator.
	 *
	 * @since 0.1.0
	 *
	 * @return Upload_Handler
	 */
	public function get_upload_handler(): Upload_Handler {
		if ( is_null( $this->upload_handler ) ) {
			$this->upload_handler = new Upload_Handler();
		}

		return $this->upload_handler;
	}

	/**
	 * Get (and lazily instantiate) the Media_Library_Hooks collaborator.
	 *
	 * @since 0.2.0
	 *
	 * @return Media_Library_Hooks
	 */
	public function get_media_library_hooks(): Media_Library_Hooks {
		if ( is_null( $this->media_library_hooks ) ) {
			$this->media_library_hooks = new Media_Library_Hooks();
		}

		return $this->media_library_hooks;
	}
}
