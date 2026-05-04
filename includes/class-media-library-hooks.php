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

		$post_id = (int) $post_id;
		$icons   = $this->state_icons( $post_id );

		if ( '' === $icons ) {
			// Render a plain em-dash for visual parity with WP's other
			// "no-state" columns (Author etc). Avoid `&nbsp;` — the dash is
			// more discoverable as "this row has no Tidy state".
			echo '<span class="tri-tidy-empty" aria-hidden="true">—</span>';
			return;
		}

		echo $icons; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- state_icons() escapes each fragment.
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
