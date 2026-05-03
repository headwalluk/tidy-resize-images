<?php
/**
 * Smoke test for the Trash_Manager backup → restore → purge cycle.
 *
 * Creates a synthetic 200×200 PNG attachment, exercises every public
 * Trash_Manager method, and reports each step. Cleans up after itself.
 *
 *     wp eval-file wp-content/plugins/tidy-resize-images/dev-notes/smoke-tests/trash-roundtrip.php
 *
 * @package Tidy_Resize_Images
 */

defined( 'ABSPATH' ) || die();

require_once ABSPATH . 'wp-admin/includes/image.php';

WP_CLI::log( '' );
WP_CLI::log( '=== Trash_Manager smoke test ===' );
WP_CLI::log( '' );

// --- Fixture: a synthetic 200x200 PNG attachment -------------------------.

$upload_dir = wp_upload_dir();
$path       = $upload_dir['path'] . '/tri-trash-smoke-' . time() . '.png';

$gd = imagecreatetruecolor( 200, 200 );
imagefill( $gd, 0, 0, imagecolorallocate( $gd, 100, 50, 200 ) );
imagepng( $gd, $path );
imagedestroy( $gd );

$attachment_id = wp_insert_attachment(
	array(
		'post_mime_type' => 'image/png',
		'post_title'     => 'Trash smoke test',
		'post_status'    => 'inherit',
	),
	$path
);
wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $path ) );

$orig_size = filesize( $path );
WP_CLI::log( sprintf( 'Created attachment %d (%s, %d bytes)', $attachment_id, $path, $orig_size ) );

// --- Backup ---------------------------------------------------------------.

WP_CLI::log( '' );
WP_CLI::log( '--- backup ---' );

$ok = \Tidy_Resize_Images\Trash_Manager::backup( $attachment_id );
WP_CLI::log( sprintf( 'backup() returned: %s', $ok ? 'true' : 'false' ) );

$backup = \Tidy_Resize_Images\Trash_Manager::get_backup( $attachment_id );

if ( is_null( $backup ) ) {
	WP_CLI::error( 'No _tri_backup meta written.' );
}

WP_CLI::log( sprintf( '  trash path: %s', $backup['path'] ) );
WP_CLI::log( sprintf( '  trash file exists: %s', file_exists( $backup['path'] ) ? 'yes' : 'no' ) );
WP_CLI::log( sprintf( '  trashed_at: %s', $backup['trashed_at'] ) );

// --- Idempotency ----------------------------------------------------------.

WP_CLI::log( '' );
WP_CLI::log( '--- backup (idempotent) ---' );
$ok = \Tidy_Resize_Images\Trash_Manager::backup( $attachment_id );
WP_CLI::log( sprintf( 'backup() called again returned: %s', $ok ? 'true' : 'false' ) );

// --- Restore --------------------------------------------------------------.

WP_CLI::log( '' );
WP_CLI::log( '--- restore ---' );
file_put_contents( $path, 'MODIFIED' );
WP_CLI::log( sprintf( 'Modified original file. Size now: %d', filesize( $path ) ) );

$ok = \Tidy_Resize_Images\Trash_Manager::restore( $attachment_id );
WP_CLI::log( sprintf( 'restore() returned: %s', $ok ? 'true' : 'false' ) );

$current_size = filesize( get_attached_file( $attachment_id ) );
WP_CLI::log( sprintf( '  current file size: %d (expect %d)', $current_size, $orig_size ) );
WP_CLI::log( sprintf( '  meta cleared: %s', is_null( \Tidy_Resize_Images\Trash_Manager::get_backup( $attachment_id ) ) ? 'yes' : 'no' ) );
WP_CLI::log( sprintf( '  trash file removed: %s', ! file_exists( $backup['path'] ) ? 'yes' : 'no' ) );

if ( $current_size !== $orig_size ) {
	WP_CLI::error( sprintf( 'Restored size %d does not match original %d', $current_size, $orig_size ) );
}

// --- Purge ----------------------------------------------------------------.

WP_CLI::log( '' );
WP_CLI::log( '--- purge ---' );

\Tidy_Resize_Images\Trash_Manager::backup( $attachment_id );
$backup = \Tidy_Resize_Images\Trash_Manager::get_backup( $attachment_id );
WP_CLI::log( sprintf( 'Re-backed up to: %s', $backup['path'] ) );

$ok = \Tidy_Resize_Images\Trash_Manager::purge( $attachment_id );
WP_CLI::log( sprintf( 'purge() returned: %s', $ok ? 'true' : 'false' ) );
WP_CLI::log( sprintf( '  meta cleared: %s', is_null( \Tidy_Resize_Images\Trash_Manager::get_backup( $attachment_id ) ) ? 'yes' : 'no' ) );
WP_CLI::log( sprintf( '  trash file deleted: %s', ! file_exists( $backup['path'] ) ? 'yes' : 'no' ) );

// --- Cleanup --------------------------------------------------------------.

wp_delete_attachment( $attachment_id, true );
WP_CLI::log( '' );
WP_CLI::success( 'Trash_Manager smoke test complete.' );
