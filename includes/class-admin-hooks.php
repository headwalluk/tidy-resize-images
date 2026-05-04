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
 * - Register the top-level admin menu entry plus the Settings and Trash
 *   submenus, routing render callbacks to the appropriate templates.
 * - Render admin notices, including the conflict notice when competing
 *   image-optimization plugins are active alongside this one.
 * - Enqueue the plugin's admin CSS / JS, scoped to our pages so we
 *   don't bleed assets onto unrelated admin screens.
 * - Handle admin-post.php form submissions for the Trash page (restore
 *   and purge actions). Each handler verifies capability, attachment
 *   ID, and nonce before delegating to Trash_Manager.
 *
 * @since 0.1.0
 */
class Admin_Hooks {

	/**
	 * Submenu slug for the Trash admin page.
	 */
	private const TRASH_MENU_SLUG = ADMIN_MENU_SLUG . '-trash';

	/**
	 * Submenu slug for the Bulk admin page.
	 */
	private const BULK_MENU_SLUG = ADMIN_MENU_SLUG . '-bulk';

	/**
	 * Hook suffixes for our admin pages, captured from add_menu_page /
	 * add_submenu_page return values during register_menu().
	 *
	 * We can't predict these from the menu slug alone — WordPress uses
	 * `sanitize_title( $menu_title )` (not the menu slug) as the parent
	 * prefix in submenu hook suffixes. For example, our parent menu_title
	 * "Tidy Images" sanitises to `tidy-images`, so the Bulk submenu hook
	 * is `tidy-images_page_tidy-resize-images-bulk` rather than the more
	 * intuitive `tidy-resize-images_page_tidy-resize-images-bulk`.
	 *
	 * Capturing the returned values is robust against any renaming of
	 * menu titles in the future.
	 *
	 * @var array<string, string> Keys: 'settings' | 'bulk' | 'trash'.
	 */
	private array $hook_suffixes = array(
		'settings' => '',
		'bulk'     => '',
		'trash'    => '',
	);

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

		// First submenu reuses the parent slug to override WordPress's
		// auto-created duplicate entry — gives us a meaningful "Settings"
		// label instead of repeating the parent's "Tidy Images".
		$this->hook_suffixes['settings'] = (string) add_submenu_page(
			ADMIN_MENU_SLUG,
			__( 'Tidy Resize Images — Settings', 'tidy-resize-images' ),
			__( 'Settings', 'tidy-resize-images' ),
			ADMIN_CAPABILITY,
			ADMIN_MENU_SLUG,
			array( $this, 'render_settings_page' )
		);

		$this->hook_suffixes['bulk'] = (string) add_submenu_page(
			ADMIN_MENU_SLUG,
			__( 'Tidy Images — Bulk', 'tidy-resize-images' ),
			__( 'Bulk', 'tidy-resize-images' ),
			ADMIN_CAPABILITY,
			self::BULK_MENU_SLUG,
			array( $this, 'render_bulk_page' )
		);

		$this->hook_suffixes['trash'] = (string) add_submenu_page(
			ADMIN_MENU_SLUG,
			__( 'Tidy Images — Trash', 'tidy-resize-images' ),
			__( 'Trash', 'tidy-resize-images' ),
			ADMIN_CAPABILITY,
			self::TRASH_MENU_SLUG,
			array( $this, 'render_trash_page' )
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
	 * Render the Trash page by including its template.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render_trash_page(): void {
		require TRI_PLUGIN_DIR . 'admin-templates/trash-page.php';
	}

	/**
	 * Render the Bulk page by including its template.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render_bulk_page(): void {
		require TRI_PLUGIN_DIR . 'admin-templates/bulk-page.php';
	}

	/**
	 * Handle the `tri_trash_restore` admin-post action.
	 *
	 * Verifies capability, attachment ID, and per-attachment nonce; then
	 * calls Trash_Manager::restore() and redirects back to the Trash
	 * page with a success/failure flash message.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function handle_trash_restore(): void {
		$attachment_id = $this->verify_trash_action_request();
		$ok            = Trash_Manager::restore( $attachment_id );

		$this->redirect_to_trash_page( $ok ? 'restored' : 'restore_failed' );
	}

	/**
	 * Handle the `tri_trash_restore_protect` admin-post action.
	 *
	 * Same restore plumbing as `handle_trash_restore`, plus sets the
	 * `_tri_protected` flag so the restored attachment is exempt from
	 * subsequent bulk runs. Operator intent is "I want this file back AND
	 * I want Tidy to leave it alone."
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function handle_trash_restore_protect(): void {
		$attachment_id = $this->verify_trash_action_request();
		$ok            = Trash_Manager::restore( $attachment_id );

		if ( $ok ) {
			update_post_meta( $attachment_id, META_PROTECTED, '1' );
		}

		$this->redirect_to_trash_page( $ok ? 'restored_protected' : 'restore_failed' );
	}

	/**
	 * Handle the `tri_trash_purge` admin-post action.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function handle_trash_purge(): void {
		$attachment_id = $this->verify_trash_action_request();
		$ok            = Trash_Manager::purge( $attachment_id );

		$this->redirect_to_trash_page( $ok ? 'purged' : 'purge_failed' );
	}

	/**
	 * AJAX: return the candidate count for a bulk run.
	 *
	 * Used by the bulk admin page to show the upfront total.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function ajax_bulk_count(): void {
		check_ajax_referer( 'tri_bulk_action', 'nonce' );

		if ( ! current_user_can( ADMIN_CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'tidy-resize-images' ) ), 403 );
		}

		$bp = new Bulk_Processor();

		wp_send_json_success( array( 'count' => $bp->count_candidates() ) );
	}

	/**
	 * AJAX: process one batch and return the Result.
	 *
	 * The JS driver calls this repeatedly until `done=true`.
	 *
	 * Inputs:
	 *   - cursor   (int) Largest ID already processed (start with 0).
	 *   - limit    (int) Batch size; clamped 1..50, default 5.
	 *   - dry_run  (bool/'1') When set, plan without mutating.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function ajax_bulk_step(): void {
		check_ajax_referer( 'tri_bulk_action', 'nonce' );

		if ( ! current_user_can( ADMIN_CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'tidy-resize-images' ) ), 403 );
		}

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- check_ajax_referer above.
		$cursor  = isset( $_POST['cursor'] ) ? absint( wp_unslash( $_POST['cursor'] ) ) : 0;
		$limit   = isset( $_POST['limit'] ) ? max( 1, min( 50, absint( wp_unslash( $_POST['limit'] ) ) ) ) : 5;
		$dry_run = ! empty( $_POST['dry_run'] );
        // phpcs:enable WordPress.Security.NonceVerification.Missing

		$bp     = new Bulk_Processor();
		$result = $bp->run_batch( $cursor, $limit, $dry_run );

		wp_send_json_success( $result );
	}

	/**
	 * Build a plugin asset URL that respects the current request's scheme.
	 *
	 * `plugins_url()` derives the scheme from `siteurl` / WP_CONTENT_URL.
	 * When WordPress is behind an SSL-terminating proxy that doesn't set
	 * the HTTPS server var, PHP sees `http://` even though the browser is
	 * on `https://` — and asset URLs come back as `http://`, which the
	 * browser then blocks as mixed content. We force the URL to match the
	 * current request scheme as a defensive measure.
	 *
	 * @since 0.1.0
	 *
	 * @param string $relative Relative path from the plugin root (e.g. 'assets/admin/tri-admin.js').
	 *
	 * @return string Absolute URL.
	 */
	private function asset_url( string $relative ): string {
		$url = plugins_url( $relative, TRI_PLUGIN_FILE );

		if ( is_ssl() ) {
			$url = set_url_scheme( $url, 'https' );
		}

		return $url;
	}

	/**
	 * Validate a Trash-page action request and return the attachment ID.
	 *
	 * Aborts with `wp_die()` on any failure (missing capability, missing
	 * attachment ID, bad nonce). On success returns the verified
	 * attachment ID.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	private function verify_trash_action_request(): int {
		if ( ! current_user_can( ADMIN_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'tidy-resize-images' ), 403 );
		}

		$attachment_id = isset( $_GET['attachment_id'] ) ? absint( $_GET['attachment_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified below.

		if ( 0 === $attachment_id ) {
			wp_die( esc_html__( 'Missing attachment ID.', 'tidy-resize-images' ), 400 );
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'tri_trash_action_' . $attachment_id ) ) {
			wp_die( esc_html__( 'Security check failed. Refresh the Trash page and try again.', 'tidy-resize-images' ), 403 );
		}

		return $attachment_id;
	}

	/**
	 * Redirect back to the Trash admin page with a notice query param.
	 *
	 * @since 0.1.0
	 *
	 * @param string $notice Notice slug consumed by the Trash template
	 *                       (e.g. 'restored', 'purge_failed').
	 *
	 * @return void
	 */
	private function redirect_to_trash_page( string $notice ): void {
		$url = add_query_arg( 'tri_notice', $notice, admin_url( 'admin.php?page=' . self::TRASH_MENU_SLUG ) );

		wp_safe_redirect( $url );
		exit();
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
		$is_plugin_page = '' !== $hook_suffix && in_array( $hook_suffix, $this->hook_suffixes, true );

		if ( $is_plugin_page ) {
			wp_enqueue_style( 'tri-admin', $this->asset_url( 'assets/admin/tri-admin.css' ), array(), TRI_PLUGIN_VERSION );

			wp_enqueue_script( 'tri-admin', $this->asset_url( 'assets/admin/tri-admin.js' ), array(), TRI_PLUGIN_VERSION, true );

			// The bulk page needs the AJAX nonce + endpoint URL.
			if ( $this->hook_suffixes['bulk'] === $hook_suffix ) {
				wp_localize_script(
					'tri-admin',
					'triBulk',
					array(
						'ajaxUrl' => admin_url( 'admin-ajax.php' ),
						'nonce'   => wp_create_nonce( 'tri_bulk_action' ),
						'i18n'    => array(
							'starting'     => __( 'Starting…', 'tidy-resize-images' ),
							'processing'   => __( 'Processing…', 'tidy-resize-images' ),
							'done'         => __( 'Done.', 'tidy-resize-images' ),
							'stopped'      => __( 'Stopped.', 'tidy-resize-images' ),
							'errored'      => __( 'Error.', 'tidy-resize-images' ),
							'noCandidates' => __( 'No attachments need processing.', 'tidy-resize-images' ),
							'confirmLive'  => __(
								'Run a LIVE bulk processing pass? Originals will be backed up to Trash unless you have disabled backups in Settings → Behaviour.',
								'tidy-resize-images'
							),
						),
					)
				);
			}
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
