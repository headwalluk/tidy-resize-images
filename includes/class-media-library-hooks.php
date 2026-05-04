<?php
/**
 * Media Library list-mode hooks: custom column, row actions, AJAX handlers.
 *
 * @package Tidy_Resize_Images
 */

namespace Tidy_Resize_Images;

defined( 'ABSPATH' ) || die();

/**
 * Media Library Hooks.
 *
 * Surfaces this plugin's per-attachment state inside the standard
 * `upload.php` list view: a "Tidy" column with up-to-four state icons,
 * plus row actions for protecting / unprotecting attachments. Grid mode
 * is intentionally unsupported in v1 — see the M8 grid-mode note in the
 * project tracker.
 *
 * State icons rendered in the column:
 *
 *   - lock     (protected)              from META_PROTECTED
 *   - yes-alt  (processed)              from META_PROCESSED_AT
 *   - backup   (original is in trash)   from META_BACKUP
 *   - warning  (conversion skipped)     from META_CONVERSION_SKIPPED
 *
 * Each icon carries a `title` attribute (tooltip on hover) and
 * `aria-label` for accessibility.
 *
 * @since 0.2.0
 */
class Media_Library_Hooks {

	/**
	 * Insert the "Tidy" column into the Media Library list-table just
	 * before the date column (or appended if no date column is present).
	 *
	 * Hook: `manage_upload_columns`.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $columns Existing columns.
	 *
	 * @return array<string, string>
	 */
	public function register_columns( array $columns ): array {
		$new_columns = array();
		$inserted    = false;

		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key && ! $inserted ) {
				$new_columns['tri_tidy'] = __( 'Tidy', 'tidy-resize-images' );
				$inserted                = true;
			}

			$new_columns[ $key ] = $label;
		}

		if ( ! $inserted ) {
			$new_columns['tri_tidy'] = __( 'Tidy', 'tidy-resize-images' );
		}

		return $new_columns;
	}

	/**
	 * Render the "Tidy" column for a row.
	 *
	 * Hook: `manage_media_custom_column`.
	 *
	 * @since 0.2.0
	 *
	 * @param string $column  Column key being rendered.
	 * @param int    $post_id Attachment post ID.
	 *
	 * @return void
	 */
	public function render_column( $column, $post_id ): void {
		if ( 'tri_tidy' !== $column ) {
			return;
		}

		echo $this->column_html_for( (int) $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- column_html_for() returns pre-escaped fragments.
	}

	/**
	 * Build the column-cell HTML for a single attachment.
	 *
	 * Public so the AJAX handler can return the same HTML after toggling
	 * state — keeps the rendering logic in one place.
	 *
	 * @since 0.2.0
	 *
	 * @param int $post_id Attachment post ID.
	 *
	 * @return string
	 */
	public function column_html_for( int $post_id ): string {
		$icons = $this->state_icons( $post_id );

		if ( '' === $icons ) {
			// Render a plain em-dash for visual parity with WP's other
			// "no-state" columns (Author etc). Avoid `&nbsp;` — the dash is
			// more discoverable as "this row has no Tidy state".
			return '<span class="tri-tidy-empty" aria-hidden="true">—</span>';
		}

		return $icons;
	}

	/**
	 * Build the icon block for a single attachment, or '' if no state is set.
	 *
	 * Order is fixed (protected → processed → backup → skipped) so the
	 * column reads consistently across rows.
	 *
	 * @since 0.2.0
	 *
	 * @param int $post_id Attachment post ID.
	 *
	 * @return string Pre-escaped HTML fragment, or empty string.
	 */
	private function state_icons( int $post_id ): string {
		$out = '';

		if ( ! empty( get_post_meta( $post_id, META_PROTECTED, true ) ) ) {
			$out .= $this->flag_icon(
				'lock',
				__( 'Protected — Tidy will never modify this file.', 'tidy-resize-images' )
			);
		}

		$processed_at = (string) get_post_meta( $post_id, META_PROCESSED_AT, true );

		if ( '' !== $processed_at ) {
			$out .= $this->flag_icon(
				'yes-alt',
				/* translators: %s: human-readable timestamp like "2026-05-04 18:32:11 BST" */
				sprintf( __( 'Processed by Tidy on %s.', 'tidy-resize-images' ), $processed_at )
			);
		}

		if ( ! is_null( Trash_Manager::get_backup( $post_id ) ) ) {
			$out .= $this->flag_icon(
				'backup',
				__( 'Original is in the Tidy trash and can be restored.', 'tidy-resize-images' )
			);
		}

		$skip = Skip_Memo::get( $post_id );

		if ( ! is_null( $skip ) ) {
			$reason = (string) ( $skip['reason'] ?? '' );
			$target = (string) ( $skip['attempted_target'] ?? '' );

			if ( '' !== $target ) {
				$tooltip = sprintf(
					/* translators: 1: target MIME e.g. image/webp, 2: machine reason */
					__( 'Tidy tried to convert this to %1$s but the result was no smaller (%2$s). It will be skipped on future runs unless settings change.', 'tidy-resize-images' ),
					$target,
					$reason
				);
			} else {
				$tooltip = __( 'Tidy will skip this attachment on future runs (a previous attempt produced no benefit).', 'tidy-resize-images' );
			}

			$out .= $this->flag_icon( 'warning', $tooltip );
		}

		return $out;
	}

	/**
	 * Add Protect / Unprotect to the Media Library row actions.
	 *
	 * Hook: `media_row_actions`. Only image attachments get the action;
	 * non-images (PDFs, audio, etc.) are not in our scope. Capability is
	 * checked here so non-admins don't see a UI affordance they can't
	 * use; the AJAX handler re-checks before mutating.
	 *
	 * The link carries `data-attachment-id` so the JS handler doesn't have
	 * to reconstruct the row's attachment ID from DOM context. The label
	 * reflects current state — "Protect" when not protected, "Unprotect"
	 * otherwise.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $actions Existing row actions.
	 * @param mixed                 $post    WP_Post for the row's attachment.
	 *
	 * @return array<string, string>
	 */
	public function register_row_actions( $actions, $post ): array {
		$actions = is_array( $actions ) ? $actions : array();

		if ( ! is_a( $post, '\WP_Post' ) || 'attachment' !== $post->post_type ) {
			return $actions;
		}

		if ( ! current_user_can( ADMIN_CAPABILITY ) ) {
			return $actions;
		}

		if ( 0 !== strpos( (string) $post->post_mime_type, 'image/' ) ) {
			return $actions;
		}

		$is_protected = ! empty( get_post_meta( $post->ID, META_PROTECTED, true ) );
		$label        = $is_protected
			? __( 'Unprotect', 'tidy-resize-images' )
			: __( 'Protect', 'tidy-resize-images' );

		$actions['tri_protect'] = sprintf(
			'<a href="#" class="tri-row-action-protect" data-attachment-id="%1$d">%2$s</a>',
			(int) $post->ID,
			esc_html( $label )
		);

		return $actions;
	}

	/**
	 * AJAX: toggle the `_tri_protected` meta on an attachment.
	 *
	 * Returns the new state plus the freshly-rendered Tidy column HTML so
	 * the JS handler can swap both the row-action label and the column
	 * cell without a full page refresh.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function ajax_set_protected(): void {
		check_ajax_referer( 'tri_media_library', 'nonce' );

		if ( ! current_user_can( ADMIN_CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'tidy-resize-images' ) ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_ajax_referer above.
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $post_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Missing attachment ID.', 'tidy-resize-images' ) ), 400 );
		}

		$post = get_post( $post_id );

		if ( is_null( $post ) || 'attachment' !== $post->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Attachment not found.', 'tidy-resize-images' ) ), 404 );
		}

		$currently_protected = ! empty( get_post_meta( $post_id, META_PROTECTED, true ) );
		$new_state           = ! $currently_protected;

		if ( $new_state ) {
			update_post_meta( $post_id, META_PROTECTED, '1' );
		} else {
			delete_post_meta( $post_id, META_PROTECTED );
		}

		wp_send_json_success(
			array(
				'protected'   => $new_state,
				'label'       => $new_state
					? __( 'Unprotect', 'tidy-resize-images' )
					: __( 'Protect', 'tidy-resize-images' ),
				'column_html' => $this->column_html_for( $post_id ),
			)
		);
	}

	/**
	 * Enqueue the Media Library JS / CSS on `upload.php`.
	 *
	 * Scoped tightly — we don't bleed onto the rest of the admin. The
	 * `dashicons` style is declared as a dependency so the column icons
	 * are guaranteed-loaded even on customised admins that disable the
	 * default font.
	 *
	 * @since 0.2.0
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 *
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ): void {
		if ( 'upload.php' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'tri-media-library',
			$this->asset_url( 'assets/admin/tri-media-library.css' ),
			array( 'dashicons' ),
			TRI_PLUGIN_VERSION
		);

		wp_enqueue_script(
			'tri-media-library',
			$this->asset_url( 'assets/admin/tri-media-library.js' ),
			array(),
			TRI_PLUGIN_VERSION,
			true
		);

		wp_localize_script(
			'tri-media-library',
			'triMediaLibrary',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'tri_media_library' ),
				'i18n'    => array(
					'failed' => __( 'Tidy could not update protection state. Please refresh the page and try again.', 'tidy-resize-images' ),
				),
			)
		);
	}

	/**
	 * Build a plugin asset URL that respects the current request's scheme.
	 *
	 * Mirrors the helper in Admin_Hooks — we keep both copies because the
	 * surfaces are otherwise unrelated and a one-line shared helper isn't
	 * worth a cross-class dependency.
	 *
	 * @since 0.2.0
	 *
	 * @param string $relative Relative path from the plugin root.
	 *
	 * @return string
	 */
	private function asset_url( string $relative ): string {
		$url = plugins_url( $relative, TRI_PLUGIN_FILE );

		if ( is_ssl() ) {
			$url = set_url_scheme( $url, 'https' );
		}

		return $url;
	}

	/**
	 * Render a single dashicon as a tooltipped flag inside the Tidy column.
	 *
	 * Wrapped in a span so the column can flow icons inline. CSS styling
	 * is intentionally minimal — relies on WordPress's built-in
	 * `.dashicons` rules so we don't have to enqueue anything just for
	 * this column to render.
	 *
	 * @since 0.2.0
	 *
	 * @param string $dashicon Dashicon name (e.g. 'lock', 'yes-alt').
	 * @param string $tooltip  Translated tooltip text.
	 *
	 * @return string Pre-escaped HTML fragment.
	 */
	private function flag_icon( string $dashicon, string $tooltip ): string {
		return sprintf(
			'<span class="tri-tidy-flag dashicons dashicons-%1$s" title="%2$s" aria-label="%2$s"></span>',
			esc_attr( $dashicon ),
			esc_attr( $tooltip )
		);
	}
}
