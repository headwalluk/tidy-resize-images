<?php
/**
 * Trash admin page.
 *
 * Lists attachments that currently have a `_tri_backup` record, with
 * per-row Restore and Purge actions. Action POSTs go to admin-post.php
 * and are handled by the M4.4 handlers.
 *
 * Code-first per house style.
 *
 * @package Tidy_Resize_Images
 */

namespace Tidy_Resize_Images;

defined( 'ABSPATH' ) || die();

if ( ! current_user_can( ADMIN_CAPABILITY ) ) {
	wp_die( esc_html__( 'You do not have permission to view this page.', 'tidy-resize-images' ) );
}

$tri_per_page = 20;
$tri_paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination param.
$tri_offset   = ( $tri_paged - 1 ) * $tri_per_page;
$tri_total    = Trash_Manager::count_trashed();
$tri_ids      = Trash_Manager::list_trashed( $tri_per_page, $tri_offset );

// --- Page heading ---------------------------------------------------------.

printf(
	'<div class="wrap tri-trash"><h1>%s</h1>',
	esc_html__( 'Tidy Images — Trash', 'tidy-resize-images' )
);

// --- Result-of-last-action notice (set by the admin-post handlers) -------.

$tri_notice = isset( $_GET['tri_notice'] ) ? sanitize_key( wp_unslash( $_GET['tri_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- one-shot read-only flash.

if ( '' !== $tri_notice ) {
	$tri_notice_text = '';
	$tri_notice_type = 'success';

	switch ( $tri_notice ) {
		case 'restored':
			$tri_notice_text = __( 'Original restored. Tidy will treat this image as a fresh candidate on the next bulk run.', 'tidy-resize-images' );
			break;
		case 'restored_protected':
			$tri_notice_text = __( 'Original restored and marked do-not-touch. Tidy will skip this image on future runs.', 'tidy-resize-images' );
			break;
		case 'restore_failed':
			$tri_notice_text = __( 'Restore failed — see the error log.', 'tidy-resize-images' );
			$tri_notice_type = 'error';
			break;
		case 'purged':
			$tri_notice_text = __( 'Backup purged.', 'tidy-resize-images' );
			break;
		case 'purge_failed':
			$tri_notice_text = __( 'Purge failed — see the error log.', 'tidy-resize-images' );
			$tri_notice_type = 'error';
			break;
	}

	if ( '' !== $tri_notice_text ) {
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $tri_notice_type ),
			esc_html( $tri_notice_text )
		);
	}
}

// --- Empty state ---------------------------------------------------------.

if ( 0 === $tri_total ) {
	printf(
		'<div class="notice notice-info inline"><p>%s</p></div></div>',
		esc_html__( 'No trashed images. Originals will appear here when the bulk processor or upload handler modifies an image (those land in M5/M7).', 'tidy-resize-images' )
	);
	return;
}

// --- Table of trashed attachments ----------------------------------------.

$tri_rows_html = '';

foreach ( $tri_ids as $tri_id ) {
	$tri_backup = Trash_Manager::get_backup( $tri_id );

	if ( is_null( $tri_backup ) ) {
		continue;
	}

	$tri_post = get_post( $tri_id );

	if ( is_null( $tri_post ) ) {
		continue;
	}

	$tri_current_path  = (string) get_attached_file( $tri_id );
	$tri_current_size  = ( '' !== $tri_current_path && file_exists( $tri_current_path ) ) ? filesize( $tri_current_path ) : 0;
	$tri_current_bytes = is_int( $tri_current_size ) ? $tri_current_size : 0;
	$tri_current_meta  = wp_get_attachment_metadata( $tri_id );
	$tri_current_w     = isset( $tri_current_meta['width'] ) ? (int) $tri_current_meta['width'] : 0;
	$tri_current_h     = isset( $tri_current_meta['height'] ) ? (int) $tri_current_meta['height'] : 0;
	$tri_current_mime  = (string) get_post_mime_type( $tri_id );

	$tri_orig_bytes = (int) ( $tri_backup['bytes'] ?? 0 );
	$tri_orig_w     = (int) ( $tri_backup['width'] ?? 0 );
	$tri_orig_h     = (int) ( $tri_backup['height'] ?? 0 );
	$tri_orig_mime  = (string) ( $tri_backup['mime'] ?? '' );
	$tri_trashed_at = (string) ( $tri_backup['trashed_at'] ?? '' );

	$tri_savings_bytes = $tri_orig_bytes - $tri_current_bytes;
	$tri_savings_pct   = $tri_orig_bytes > 0 ? round( ( $tri_savings_bytes / $tri_orig_bytes ) * 100, 1 ) : 0.0;

	$tri_filename_changed = ! empty( $tri_backup['filename_changed'] );
	$tri_warning_html     = '';

	if ( $tri_filename_changed ) {
		$tri_warning_html = sprintf(
			'<p class="tri-help" style="color:#d63638;margin-top:4px;"><strong>%s</strong> %s</p>',
			esc_html__( 'Filename changed.', 'tidy-resize-images' ),
			esc_html__( 'The processor renamed this file during conversion. Restoring will put the original file back, but page content and metadata may still reference the new filename until the search-replace pass runs (M6).', 'tidy-resize-images' )
		);
	}

	$tri_thumb = wp_get_attachment_image( $tri_id, array( 60, 60 ), true );

	$tri_restore_url         = wp_nonce_url(
		admin_url( 'admin-post.php?action=tri_trash_restore&attachment_id=' . $tri_id ),
		'tri_trash_action_' . $tri_id
	);
	$tri_restore_protect_url = wp_nonce_url(
		admin_url( 'admin-post.php?action=tri_trash_restore_protect&attachment_id=' . $tri_id ),
		'tri_trash_action_' . $tri_id
	);
	$tri_purge_url           = wp_nonce_url(
		admin_url( 'admin-post.php?action=tri_trash_purge&attachment_id=' . $tri_id ),
		'tri_trash_action_' . $tri_id
	);
	$tri_edit_url            = (string) get_edit_post_link( $tri_id );

	$tri_rows_html .= sprintf(
		'<tr>'
		. '<td>%1$s</td>'
		. '<td><a href="%2$s">%3$s</a><br /><span class="tri-help">ID %4$d</span>%5$s</td>'
		. '<td><code>%6$s</code><br />%7$d × %8$d<br />%9$s</td>'
		. '<td><code>%10$s</code><br />%11$d × %12$d<br />%13$s</td>'
		. '<td>%14$s<br /><span class="tri-help">%15$s</span></td>'
		. '<td>%16$s</td>'
		. '<td>'
		. '<a href="%17$s" class="button" title="%18$s">%19$s</a> '
		. '<a href="%20$s" class="button" title="%21$s">%22$s</a> '
		. '<a href="%23$s" class="button" onclick="return confirm(\'%24$s\');">%25$s</a>'
		. '</td>'
		. '</tr>',
		$tri_thumb,                                                                             // 1: pre-escaped.
		esc_url( $tri_edit_url ),                                                               // 2.
		esc_html( get_the_title( $tri_id ) ),                                                   // 3.
		(int) $tri_id,                                                                          // 4.
		$tri_warning_html,                                                                      // 5: pre-escaped.
		esc_html( $tri_orig_mime ),                                                             // 6.
		$tri_orig_w,                                                                            // 7.
		$tri_orig_h,                                                                            // 8.
		esc_html( size_format( $tri_orig_bytes ) ),                                             // 9.
		esc_html( $tri_current_mime ),                                                          // 10.
		$tri_current_w,                                                                         // 11.
		$tri_current_h,                                                                         // 12.
		esc_html( size_format( $tri_current_bytes ) ),                                          // 13.
		esc_html( size_format( max( 0, $tri_savings_bytes ) ) ),                                // 14.
		esc_html(
			sprintf(
				/* translators: %s: signed percentage like "40.6%" or "-3.2%" */
				__( '(%s%%)', 'tidy-resize-images' ),
				number_format( $tri_savings_pct, 1 )
			)
		),                                                                                      // 15.
		esc_html( $tri_trashed_at ),                                                            // 16.
		esc_url( $tri_restore_url ),                                                            // 17.
		esc_attr__( 'Put the original file back. Tidy will treat this attachment as a fresh candidate on the next bulk run.', 'tidy-resize-images' ), // 18.
		esc_html__( 'Restore', 'tidy-resize-images' ),                                          // 19.
		esc_url( $tri_restore_protect_url ),                                                    // 20.
		esc_attr__( 'Put the original file back AND mark this attachment do-not-touch so Tidy never modifies it again.', 'tidy-resize-images' ), // 21.
		esc_html__( 'Restore & protect', 'tidy-resize-images' ),                                // 22.
		esc_url( $tri_purge_url ),                                                              // 23.
		esc_js( __( 'Permanently delete this backup? Cannot be undone.', 'tidy-resize-images' ) ), // 24.
		esc_html__( 'Purge', 'tidy-resize-images' )                                             // 25.
	);
}

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $tri_rows_html assembled from per-piece esc_*() calls above.
printf(
	'<table class="wp-list-table widefat striped" style="margin-top:12px;"><thead><tr>'
	. '<th style="width:80px;">%1$s</th>'
	. '<th>%2$s</th>'
	. '<th>%3$s</th>'
	. '<th>%4$s</th>'
	. '<th>%5$s</th>'
	. '<th>%6$s</th>'
	. '<th>%7$s</th>'
	. '</tr></thead><tbody>%8$s</tbody></table>',
	esc_html__( 'Thumbnail', 'tidy-resize-images' ),
	esc_html__( 'Attachment', 'tidy-resize-images' ),
	esc_html__( 'Original', 'tidy-resize-images' ),
	esc_html__( 'Current', 'tidy-resize-images' ),
	esc_html__( 'Saved', 'tidy-resize-images' ),
	esc_html__( 'Trashed at', 'tidy-resize-images' ),
	esc_html__( 'Actions', 'tidy-resize-images' ),
	$tri_rows_html
);
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

// --- Pagination -----------------------------------------------------------.

$tri_total_pages = (int) ceil( $tri_total / $tri_per_page );

if ( $tri_total_pages > 1 ) {
	$tri_pagination_html = paginate_links(
		array(
			'base'      => add_query_arg( 'paged', '%#%' ),
			'format'    => '',
			'current'   => $tri_paged,
			'total'     => $tri_total_pages,
			'prev_text' => '&laquo;',
			'next_text' => '&raquo;',
		)
	);

	if ( ! empty( $tri_pagination_html ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links output is WP-escaped.
		printf( '<div class="tablenav"><div class="tablenav-pages">%s</div></div>', $tri_pagination_html );
	}
}

echo '</div>';
