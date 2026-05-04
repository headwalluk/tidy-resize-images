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
	 * Add Tidy entries to the upload.php bulk-actions dropdown.
	 *
	 * Hook: `bulk_actions-upload`. Two actions only:
	 *
	 *   - tri_protect   — set _tri_protected on every selected image
	 *   - tri_unprotect — clear _tri_protected on every selected image
	 *
	 * Bulk Restore and bulk Optimize are intentionally absent. Restore is
	 * destructive (loses the optimised version); doing 50 in one click
	 * is a foot-gun, so the row action stays single-shot. Bulk Optimize
	 * is covered by the dedicated Bulk page (Tidy Images → Bulk) which
	 * has progress, abort, and cron — better tooling for the workflow.
	 *
	 * @since 0.3.0
	 *
	 * @param array<string, string> $actions Existing bulk-action labels keyed by slug.
	 *
	 * @return array<string, string>
	 */
	public function register_bulk_actions( array $actions ): array {
		if ( ! current_user_can( ADMIN_CAPABILITY ) ) {
			return $actions;
		}

		$actions['tri_protect']   = __( 'Tidy: Protect', 'tidy-resize-images' );
		$actions['tri_unprotect'] = __( 'Tidy: Unprotect', 'tidy-resize-images' );

		return $actions;
	}

	/**
	 * Apply a Tidy bulk action to the selected attachment IDs.
	 *
	 * Hook: `handle_bulk_actions-upload`. WP passes us a redirect URL,
	 * the action slug, and the array of selected post IDs. We mutate
	 * meta for image attachments only and append `tri_bulk` and
	 * `tri_count` to the redirect URL so `render_bulk_notices` can
	 * surface the result.
	 *
	 * Non-image attachments and posts the operator can't edit are
	 * silently skipped.
	 *
	 * @since 0.3.0
	 *
	 * @param string     $sendback Redirect URL to append to.
	 * @param string     $action   Bulk action slug.
	 * @param array<int> $ids      Selected post IDs.
	 *
	 * @return string Redirect URL.
	 */
	public function handle_bulk_actions( $sendback, $action, $ids ): string {
		$sendback = (string) $sendback;
		$action   = (string) $action;
		$ids      = array_map( 'intval', (array) $ids );

		if ( 'tri_protect' !== $action && 'tri_unprotect' !== $action ) {
			return $sendback;
		}

		if ( ! current_user_can( ADMIN_CAPABILITY ) ) {
			return $sendback;
		}

		$changed = 0;

		foreach ( $ids as $id ) {
			if ( $id <= 0 ) {
				continue;
			}

			$post = get_post( $id );

			if ( is_null( $post ) || 'attachment' !== $post->post_type ) {
				continue;
			}

			if ( 0 !== strpos( (string) $post->post_mime_type, 'image/' ) ) {
				continue;
			}

			if ( 'tri_protect' === $action ) {
				update_post_meta( $id, META_PROTECTED, '1' );
			} else {
				delete_post_meta( $id, META_PROTECTED );
			}

			++$changed;
		}

		return add_query_arg(
			array(
				'tri_bulk'  => $action,
				'tri_count' => $changed,
			),
			$sendback
		);
	}

	/**
	 * Render the success notice after a Tidy bulk action ran.
	 *
	 * Hook: `admin_notices`, scoped to the upload screen. Reads the
	 * `tri_bulk` and `tri_count` query args set by `handle_bulk_actions`.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function render_bulk_notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( is_null( $screen ) || 'upload' !== $screen->id ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only flash from our own redirect.
		if ( ! isset( $_GET['tri_bulk'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_GET['tri_bulk'] ) );
		$count  = isset( $_GET['tri_count'] ) ? (int) $_GET['tri_count'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$message = '';

		switch ( $action ) {
			case 'tri_protect':
				$message = sprintf(
					/* translators: %d: number of attachments protected */
					_n(
						'%d attachment marked as protected by Tidy.',
						'%d attachments marked as protected by Tidy.',
						$count,
						'tidy-resize-images'
					),
					$count
				);
				break;
			case 'tri_unprotect':
				$message = sprintf(
					/* translators: %d: number of attachments unprotected */
					_n(
						'%d attachment unprotected.',
						'%d attachments unprotected.',
						$count,
						'tidy-resize-images'
					),
					$count
				);
				break;
		}

		if ( '' === $message ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( $message )
		);
	}

	/**
	 * Register the "Tidy" meta box on the attachment edit screen.
	 *
	 * Hook: `add_meta_boxes_attachment`. Renders a compact panel in the
	 * sidebar containing the protection toggle and a preview of the last
	 * five processing-log entries.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function register_meta_box(): void {
		add_meta_box(
			'tri_attachment',
			__( 'Tidy Resize Images', 'tidy-resize-images' ),
			array( $this, 'render_meta_box' ),
			'attachment',
			'side',
			'default'
		);
	}

	/**
	 * Render the attachment-edit meta box.
	 *
	 * Two pieces:
	 *   - Protection toggle (checkbox; saved by `save_meta_box`).
	 *   - Up to five most-recent entries from `_tri_processing_log`,
	 *     formatted human-readably.
	 *
	 * @since 0.3.0
	 *
	 * @param mixed $post WP_Post for the attachment being edited.
	 *
	 * @return void
	 */
	public function render_meta_box( $post ): void {
		if ( ! is_a( $post, '\WP_Post' ) ) {
			return;
		}

		$is_protected = ! empty( get_post_meta( $post->ID, META_PROTECTED, true ) );

		wp_nonce_field( 'tri_attachment_meta_' . $post->ID, 'tri_attachment_nonce' );

		printf(
			'<p><label><input type="checkbox" name="tri_protected" value="1"%1$s /> %2$s</label></p>',
			checked( $is_protected, true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns ' checked="checked"' or ''.
			esc_html__( 'Protected — Tidy will never modify this file.', 'tidy-resize-images' )
		);

		$log_raw = get_post_meta( $post->ID, META_PROCESSING_LOG, true );
		$log     = is_array( $log_raw ) ? $log_raw : array();

		printf(
			'<h4 style="margin-top:1em;">%s</h4>',
			esc_html__( 'Recent activity', 'tidy-resize-images' )
		);

		if ( empty( $log ) ) {
			printf(
				'<p class="tri-help" style="color:#666;">%s</p>',
				esc_html__( 'No Tidy activity yet for this attachment.', 'tidy-resize-images' )
			);
		} else {
			echo '<ul class="tri-processing-log" style="margin-top:0;font-size:12px;">';

			foreach ( $log as $entry ) {
				if ( is_array( $entry ) ) {
					echo $this->format_log_entry( $entry ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- format_log_entry() returns pre-escaped HTML.
				}
			}

			echo '</ul>';
		}
	}

	/**
	 * Add a "Protected" checkbox to the Media Library grid-mode edit form.
	 *
	 * Hook: `attachment_fields_to_edit`. The filter is the only path WP
	 * offers for surfacing custom fields in the modal that opens from the
	 * grid view — meta boxes (used by `register_meta_box`) only appear on
	 * the classic edit screen at `post.php?post=N&action=edit`. Operators
	 * who never switch the Media Library to list mode reach this surface
	 * to toggle Tidy protection.
	 *
	 * @since 0.3.0
	 *
	 * @param array<string, mixed> $form_fields Existing fields, keyed by
	 *                                          field slug.
	 * @param mixed                $post        WP_Post for the attachment.
	 *
	 * @return array<string, mixed>
	 */
	public function add_grid_mode_field( $form_fields, $post ): array {
		$form_fields = is_array( $form_fields ) ? $form_fields : array();

		if ( ! is_a( $post, '\WP_Post' ) || 'attachment' !== $post->post_type ) {
			return $form_fields;
		}

		if ( 0 !== strpos( (string) $post->post_mime_type, 'image/' ) ) {
			return $form_fields;
		}

		if ( ! current_user_can( ADMIN_CAPABILITY ) ) {
			return $form_fields;
		}

		$is_protected = ! empty( get_post_meta( $post->ID, META_PROTECTED, true ) );

		$form_fields['tri_protected'] = array(
			'label' => __( 'Tidy: Protect', 'tidy-resize-images' ),
			'input' => 'html',
			'html'  => sprintf(
				// Hidden marker tells the save handler this form rendered our
				// field — needed to distinguish "operator unticked the box"
				// (tri_protected absent on submit) from "the filter fired
				// without our field rendering" (don't touch meta).
				'<input type="hidden" name="attachments[%1$d][tri_protected_present]" value="1" />'
				. '<label><input type="checkbox" name="attachments[%1$d][tri_protected]" id="attachments-%1$d-tri_protected" value="1"%2$s /> %3$s</label>',
				(int) $post->ID,
				checked( $is_protected, true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns ' checked="checked"' or empty.
				esc_html__( 'Tidy will never modify this file.', 'tidy-resize-images' )
			),
			'helps' => __( 'Marks the attachment as do-not-touch. Bulk runs and the upload handler will skip it.', 'tidy-resize-images' ),
		);

		return $form_fields;
	}

	/**
	 * Persist the grid-mode protection checkbox to attachment meta.
	 *
	 * Hook: `attachment_fields_to_save`. WP passes us the existing $post
	 * (which we return unmodified — we only need the side-effect of
	 * writing META_PROTECTED) and the form's $attachment payload.
	 *
	 * @since 0.3.0
	 *
	 * @param mixed                $post       Post array (with ID).
	 * @param array<string, mixed> $attachment Form fields submitted.
	 *
	 * @return mixed
	 */
	public function save_grid_mode_field( $post, $attachment ): array {
		$post_arr = is_array( $post ) ? $post : array();

		if ( ! isset( $post_arr['ID'] ) ) {
			return $post_arr;
		}

		if ( ! current_user_can( ADMIN_CAPABILITY ) ) {
			return $post_arr;
		}

		// The field only renders for image attachments — but the save
		// filter fires for every attachment edit, so re-check.
		$post_object = get_post( (int) $post_arr['ID'] );

		if ( is_null( $post_object ) || 0 !== strpos( (string) $post_object->post_mime_type, 'image/' ) ) {
			return $post_arr;
		}

		// HTML convention: an unchecked checkbox submits nothing. So we can't
		// distinguish "form rendered, operator unticked" from "form did not
		// render at all" by the checkbox alone. The hidden `tri_protected_present`
		// marker (added by add_grid_mode_field) flags the former — if it's
		// absent we bail, treating this as a non-Tidy save context.
		if ( empty( $attachment['tri_protected_present'] ) ) {
			return $post_arr;
		}

		$protected = ! empty( $attachment['tri_protected'] );

		if ( $protected ) {
			update_post_meta( (int) $post_arr['ID'], META_PROTECTED, '1' );
		} else {
			delete_post_meta( (int) $post_arr['ID'], META_PROTECTED );
		}

		return $post_arr;
	}

	/**
	 * Save the attachment edit-screen meta-box fields.
	 *
	 * Hook: `edit_attachment`. Verifies our nonce + capability before
	 * mutating the protection meta. Other plugins' fields are unaffected
	 * by this handler.
	 *
	 * @since 0.3.0
	 *
	 * @param int $attachment_id Attachment post ID.
	 *
	 * @return void
	 */
	public function save_meta_box( $attachment_id ): void {
		$attachment_id = (int) $attachment_id;

		if ( $attachment_id <= 0 ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified just below.
		$nonce = isset( $_POST['tri_attachment_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['tri_attachment_nonce'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'tri_attachment_meta_' . $attachment_id ) ) {
			return;
		}

		if ( ! current_user_can( ADMIN_CAPABILITY ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$protected = ! empty( $_POST['tri_protected'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $protected ) {
			update_post_meta( $attachment_id, META_PROTECTED, '1' );
		} else {
			delete_post_meta( $attachment_id, META_PROTECTED );
		}
	}

	/**
	 * Format a single processing-log entry as an `<li>` for the meta box.
	 *
	 * Action labels are translated; reasons stay as machine strings to
	 * keep the surface compact (and because the operator-relevant
	 * machine reasons — `result_larger_than_source`, `excluded_mime`,
	 * `gif_animated` — read fine without translation).
	 *
	 * @since 0.3.0
	 *
	 * @param array<string, mixed> $entry Log entry.
	 *
	 * @return string Pre-escaped HTML fragment.
	 */
	private function format_log_entry( array $entry ): string {
		$at      = (string) ( $entry['at'] ?? '' );
		$action  = (string) ( $entry['action'] ?? '' );
		$reason  = (string) ( $entry['reason'] ?? '' );
		$source  = (string) ( $entry['source_mime'] ?? '' );
		$target  = (string) ( $entry['target_mime'] ?? '' );
		$bytes   = (int) ( $entry['savings_bytes'] ?? 0 );
		$percent = (float) ( $entry['savings_percent'] ?? 0 );

		$action_label = $action;

		switch ( $action ) {
			case 'committed':
				$action_label = __( 'Optimised', 'tidy-resize-images' );
				break;
			case 'discarded':
				$action_label = __( 'Discarded', 'tidy-resize-images' );
				break;
			case 'skipped':
				$action_label = __( 'Skipped', 'tidy-resize-images' );
				break;
			case 'errored':
				$action_label = __( 'Error', 'tidy-resize-images' );
				break;
			case 'planned':
				$action_label = __( 'Planned (dry-run)', 'tidy-resize-images' );
				break;
		}

		$detail_parts = array();

		if ( '' !== $source && '' !== $target && $source !== $target ) {
			$detail_parts[] = sprintf( '%s → %s', $source, $target );
		} elseif ( '' !== $target ) {
			$detail_parts[] = $target;
		}

		if ( $bytes > 0 ) {
			$detail_parts[] = sprintf(
				/* translators: 1: human-readable bytes saved, 2: percentage */
				__( 'saved %1$s (%2$s%%)', 'tidy-resize-images' ),
				size_format( $bytes ),
				number_format( $percent, 1 )
			);
		} elseif ( '' !== $reason && 'committed' !== $reason ) {
			$detail_parts[] = $reason;
		}

		$detail = ! empty( $detail_parts ) ? implode( ', ', $detail_parts ) : '';

		return sprintf(
			'<li style="margin-bottom:0.6em;border-left:3px solid %1$s;padding-left:6px;">'
			. '<strong>%2$s</strong>'
			. '<br /><span style="color:#555;">%3$s</span>'
			. ( '' !== $detail ? '<br /><span style="color:#666;font-style:italic;">%4$s</span>' : '%4$s' )
			. '</li>',
			esc_attr( $this->log_entry_colour( $action ) ),
			esc_html( $action_label ),
			esc_html( $at ),
			esc_html( $detail )
		);
	}

	/**
	 * Pick a left-border colour for a log entry based on its action.
	 *
	 * @since 0.3.0
	 *
	 * @param string $action Log entry action.
	 *
	 * @return string CSS colour value.
	 */
	private function log_entry_colour( string $action ): string {
		$colour = '#999';

		switch ( $action ) {
			case 'committed':
				$colour = '#46b450';
				break;
			case 'discarded':
				$colour = '#dba617';
				break;
			case 'errored':
				$colour = '#d63638';
				break;
			case 'planned':
				$colour = '#2271b1';
				break;
		}

		return $colour;
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
