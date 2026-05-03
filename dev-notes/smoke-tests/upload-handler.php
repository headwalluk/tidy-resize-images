<?php
/**
 * Smoke test for Upload_Handler — exercise the full
 * `wp_generate_attachment_metadata` pipeline end-to-end.
 *
 * Creates a synthetic 4000×3000 PNG with alpha + noise, inserts it as an
 * attachment, triggers metadata generation (which fires our filter),
 * and reports the post-processing state. Then runs the restore round-trip
 * to verify no re-processing loop. Cleans up after itself.
 *
 *     wp eval-file wp-content/plugins/tidy-resize-images/dev-notes/smoke-tests/upload-handler.php
 *
 * @package Tidy_Resize_Images
 */

defined( 'ABSPATH' ) || die();

require_once ABSPATH . 'wp-admin/includes/image.php';

WP_CLI::log( '' );
WP_CLI::log( '=== Upload_Handler smoke test ===' );
WP_CLI::log( '' );

// --- Fixture: 4000×3000 PNG with alpha and pixel noise -------------------.

$upload    = wp_upload_dir();
$src_path  = $upload['path'] . '/tri-upload-smoke-' . time() . '.png';
$gd        = imagecreatetruecolor( 4000, 3000 );
imagesavealpha( $gd, true );
imagefill( $gd, 0, 0, imagecolorallocatealpha( $gd, 100, 200, 50, 64 ) );

for ( $i = 0; $i < 5000; $i++ ) {
	imagesetpixel(
		$gd,
		mt_rand( 0, 3999 ),
		mt_rand( 0, 2999 ),
		imagecolorallocate( $gd, mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 0, 255 ) )
	);
}

imagepng( $gd, $src_path );
imagedestroy( $gd );

$orig_size = filesize( $src_path );
WP_CLI::log( sprintf( 'Source: %s (%d bytes)', basename( $src_path ), $orig_size ) );

// --- Insert + trigger pipeline -------------------------------------------.

$attachment_id = wp_insert_attachment(
	array(
		'post_mime_type' => 'image/png',
		'post_title'     => 'Upload smoke test',
		'post_status'    => 'inherit',
	),
	$src_path
);

WP_CLI::log( sprintf( 'Created attachment %d', $attachment_id ) );

$metadata = wp_generate_attachment_metadata( $attachment_id, $src_path );
wp_update_attachment_metadata( $attachment_id, $metadata );

// --- Inspect post-processing state ---------------------------------------.

$attached     = get_attached_file( $attachment_id );
$current_meta = wp_get_attachment_metadata( $attachment_id );
$backup       = \Tidy_Resize_Images\Trash_Manager::get_backup( $attachment_id );

WP_CLI::log( '' );
WP_CLI::log( '--- After upload-time processing ---' );
WP_CLI::log( sprintf( 'attached file:        %s', basename( $attached ) ) );
WP_CLI::log( sprintf( 'attached file size:   %d bytes', filesize( $attached ) ) );
WP_CLI::log( sprintf( 'attached file MIME:   %s', get_post_mime_type( $attachment_id ) ) );
WP_CLI::log( sprintf( 'intermediates count:  %d', count( $current_meta['sizes'] ?? array() ) ) );
WP_CLI::log( sprintf( 'processed_at meta:    %s', get_post_meta( $attachment_id, \Tidy_Resize_Images\META_PROCESSED_AT, true ) ) );

if ( ! is_null( $backup ) ) {
	WP_CLI::log( sprintf( 'backup present:       yes (filename_changed=%s)', $backup['filename_changed'] ? 'true' : 'false' ) );
} else {
	WP_CLI::log( 'backup present:       no' );
}

$savings_bytes = $orig_size - filesize( $attached );
$savings_pct   = round( $savings_bytes / $orig_size * 100, 1 );
WP_CLI::log( sprintf( 'savings:              %d bytes (%s%%)', $savings_bytes, $savings_pct ) );

// --- Restore round-trip --------------------------------------------------.

WP_CLI::log( '' );
WP_CLI::log( '--- Restore round-trip ---' );

$ok = \Tidy_Resize_Images\Trash_Manager::restore( $attachment_id );
WP_CLI::log( sprintf( 'restore() returned:   %s', $ok ? 'true' : 'false' ) );

$restored = get_attached_file( $attachment_id );
WP_CLI::log( sprintf( 'restored file:        %s (%d bytes)', basename( $restored ), filesize( $restored ) ) );
WP_CLI::log( sprintf( 'restored MIME:        %s', get_post_mime_type( $attachment_id ) ) );

if ( ! str_ends_with( $restored, '.png' ) ) {
	WP_CLI::error( 'Restored file is not a .png — re-processing loop may have triggered.' );
}

// --- Cleanup -------------------------------------------------------------.

wp_delete_attachment( $attachment_id, true );
WP_CLI::log( '' );
WP_CLI::success( 'Upload_Handler smoke test complete.' );
