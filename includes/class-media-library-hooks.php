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
 * plus three row actions (Protect/Unprotect, Optimize Now, Restore
 * Original). Grid mode is intentionally unsupported in v1 — see the M8
 * grid-mode note in the project tracker.
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
 * Row actions surface only when applicable:
 *
 *   - Protect / Unprotect    — always on image attachments.
 *   - Optimize Now           — hidden on protected attachments (per the
 *                              M8a kickoff: protection is "hands off,
 *                              including manual"; operator must
 *                              Unprotect first).
 *   - Restore Original       — only when META_BACKUP exists.
 *
 * AJAX endpoints are nonce-checked against `tri_media_library` and
 * capability-gated to ADMIN_CAPABILITY. Each returns a fresh
 * `column_html` so the JS handler can update the row's Tidy cell
 * without a full page reload.
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
	 * Public so the AJAX handlers can return the same HTML after a state
	 * change — keeps the rendering logic in one place.
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
	 * Add the Tidy row actions to image attachments in the Media Library.
	 *
	 * Hook: `media_row_actions`. Only image attachments get actions;
	 * non-images (PDFs, audio, etc.) are not in our scope. Capability is
	 * checked here so non-admins don't see UI affordances they can't use;
	 * AJAX handlers re-check before mutating.
	 *
	 * Three actions appear (conditionally):
	 *   - Optimize Now — only when not protected
	 *   - Protect / Unprotect — always (label reflects current state)
	 *   - Restore Original — only when a backup exists
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
		$has_backup   = ! is_null( Trash_Manager::get_backup( $post->ID ) );

		// Optimize Now — hidden when the operator has marked the attachment
		// protected. Protection is "hands off, including manual." Operators
		// who want to re-process must Unprotect first.
		if ( ! $is_protected ) {
			$actions['tri_optimize'] = $this->row_action_link(
				$post->ID,
				'optimize',
				__( 'Optimize Now', 'tidy-resize-images' )
			);
		}

		$protect_label          = $is_protected
			? __( 'Unprotect', 'tidy-resize-images' )
			: __( 'Protect', 'tidy-resize-images' );
		$actions['tri_protect'] = $this->row_action_link( $post->ID, 'protect', $protect_label );

		// Restore Original — surfaced only when there's actually a backup
		// to restore. The dedicated Trash page also offers "Restore &
		// protect"; here we keep the row UX simple — operator can click
		// Restore Original then Protect, or use the Trash page if they
		// want the combined action.
		if ( $has_backup ) {
			$actions['tri_restore'] = $this->row_action_link(
				$post->ID,
				'restore',
				__( 'Restore Original', 'tidy-resize-images' )
			);
		}

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
		$post_id             = $this->verify_ajax_request();
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
	 * AJAX: run Attachment_Processor against a single attachment.
	 *
	 * Always live (per the M8a kickoff Q5: a single deliberate click on
	 * "Optimize Now" ignores the global dry-run setting). The protected
	 * check is duplicated here as defense in depth — the row-actions
	 * filter hides the link, but a determined caller could still POST
	 * the AJAX action.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function ajax_optimize_now(): void {
		$post_id = $this->verify_ajax_request();

		if ( ! empty( get_post_meta( $post_id, META_PROTECTED, true ) ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'This attachment is protected. Unprotect it first to optimise.', 'tidy-resize-images' ),
				),
				409
			);
		}

		$processor = new Attachment_Processor();
		$result    = $processor->process( $post_id, false );

		wp_send_json_success(
			array(
				'attachment_action' => (string) $result['action'],
				'reason'            => (string) $result['reason'],
				'savings_bytes'     => (int) $result['savings_bytes'],
				'savings_percent'   => (float) $result['savings_percent'],
				'column_html'       => $this->column_html_for( $post_id ),
			)
		);
	}

	/**
	 * AJAX: restore an attachment's original from the trash.
	 *
	 * Delegates to `Trash_Manager::restore()` which (since v0.3.0) also
	 * clears `_tri_processed_at` and `_tri_conversion_skipped` so the
	 * restored attachment is eligible for subsequent bulk runs.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function ajax_restore_original(): void {
		$post_id = $this->verify_ajax_request();

		if ( is_null( Trash_Manager::get_backup( $post_id ) ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No backup is available for this attachment.', 'tidy-resize-images' ),
				),
				409
			);
		}

		$ok = Trash_Manager::restore( $post_id );

		if ( ! $ok ) {
			wp_send_json_error(
				array(
					'message' => __( 'Restore failed. The original file may be missing from the trash directory.', 'tidy-resize-images' ),
				),
				500
			);
		}

		wp_send_json_success(
			array(
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
					'failed'      => __( 'Tidy could not complete that action. Please refresh the page and try again.', 'tidy-resize-images' ),
					'optimizeNow' => __( 'Optimize Now', 'tidy-resize-images' ),
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
	 * Verify nonce + capability + post ID for a row-action AJAX request.
	 *
	 * On any validation failure, sends a JSON error response and dies via
	 * `wp_send_json_error()`. On success returns the validated post ID.
	 *
	 * @since 0.3.0
	 *
	 * @return int
	 */
	private function verify_ajax_request(): int {
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

		return $post_id;
	}

	/**
	 * Build a row-action `<a>` element for the given action type.
	 *
	 * Class is generic (`tri-row-action`) so the JS click delegate matches
	 * all three; `data-tri-action` carries the action type which the JS
	 * maps to a wp_ajax action name.
	 *
	 * @since 0.3.0
	 *
	 * @param int    $post_id     Attachment post ID.
	 * @param string $action_type One of: 'protect', 'optimize', 'restore'.
	 * @param string $label       Translated link text.
	 *
	 * @return string Pre-escaped HTML.
	 */
	private function row_action_link( int $post_id, string $action_type, string $label ): string {
		return sprintf(
			'<a href="#" class="tri-row-action" data-tri-action="%1$s" data-attachment-id="%2$d">%3$s</a>',
			esc_attr( $action_type ),
			$post_id,
			esc_html( $label )
		);
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
