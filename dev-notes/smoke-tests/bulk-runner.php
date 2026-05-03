<?php
/**
 * Smoke test for Bulk_Processor.
 *
 * Creates two synthetic attachments (a large PNG and a small JPEG), runs
 * count_candidates, then runs a dry batch followed by a live batch.
 * Verifies the per-batch Result and cleans up.
 *
 *     wp eval-file wp-content/plugins/tidy-resize-images/dev-notes/smoke-tests/bulk-runner.php
 *
 * @package Tidy_Resize_Images
 */

defined( 'ABSPATH' ) || die();

require_once ABSPATH . 'wp-admin/includes/image.php';

WP_CLI::log( '' );
WP_CLI::log( '=== Bulk_Processor smoke test ===' );
WP_CLI::log( '' );

// --- Fixtures ------------------------------------------------------------.

$upload = wp_upload_dir();
$ids    = array();

// Big PNG with alpha — should convert to WebP.
$png_path = $upload['path'] . '/tri-bulk-smoke-big-' . time() . '.png';
$gd       = imagecreatetruecolor( 3200, 2400 );
imagesavealpha( $gd, true );
imagefill( $gd, 0, 0, imagecolorallocatealpha( $gd, 80, 160, 200, 64 ) );
for ( $i = 0; $i < 4000; $i++ ) {
	imagesetpixel( $gd, mt_rand( 0, 3199 ), mt_rand( 0, 2399 ), imagecolorallocate( $gd, mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 0, 255 ) ) );
}
imagepng( $gd, $png_path );
imagedestroy( $gd );
$ids[]    = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Bulk PNG', 'post_status' => 'inherit' ), $png_path );
$png_size = filesize( $png_path );

// Smaller JPEG — should recompress.
$jpeg_path = $upload['path'] . '/tri-bulk-smoke-jpg-' . time() . '.jpg';
$gd        = imagecreatetruecolor( 1500, 1000 );
imagefill( $gd, 0, 0, imagecolorallocate( $gd, 200, 50, 80 ) );
for ( $i = 0; $i < 1000; $i++ ) {
	imagesetpixel( $gd, mt_rand( 0, 1499 ), mt_rand( 0, 999 ), imagecolorallocate( $gd, mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 0, 255 ) ) );
}
imagejpeg( $gd, $jpeg_path, 95 );
imagedestroy( $gd );
$ids[]     = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => 'Bulk JPEG', 'post_status' => 'inherit' ), $jpeg_path );
$jpeg_size = filesize( $jpeg_path );

WP_CLI::log( sprintf( 'Created PNG fixture %d at %d bytes', $ids[0], $png_size ) );
WP_CLI::log( sprintf( 'Created JPEG fixture %d at %d bytes', $ids[1], $jpeg_size ) );

$bp = new \Tidy_Resize_Images\Bulk_Processor();

// --- Count + dry-run -----------------------------------------------------.

$total = $bp->count_candidates();
WP_CLI::log( '' );
WP_CLI::log( sprintf( 'count_candidates: %d (test fixtures included)', $total ) );

$dry_cursor = max( 0, $ids[0] - 1 );

WP_CLI::log( '' );
WP_CLI::log( '--- Dry-run batch (cursor right before our first fixture) ---' );
$dry = $bp->run_batch( $dry_cursor, 5, true );
WP_CLI::log( sprintf( 'examined=%d changed=%d skipped=%d errored=%d done=%s last_cursor=%d',
	$dry['attachments_examined'], $dry['attachments_changed'], $dry['attachments_skipped'], $dry['attachments_errored'],
	$dry['done'] ? 'yes' : 'no', $dry['last_cursor']
) );
foreach ( $dry['log'] as $entry ) {
	WP_CLI::log( sprintf( '  #%d %s -> %s (%s)', $entry['id'], $entry['title'], $entry['action'], $entry['reason'] ) );
}

// --- Live batch ----------------------------------------------------------.

WP_CLI::log( '' );
WP_CLI::log( '--- Live batch ---' );
$live = $bp->run_batch( $dry_cursor, 5, false );
WP_CLI::log( sprintf( 'examined=%d changed=%d skipped=%d errored=%d bytes_saved=%d done=%s',
	$live['attachments_examined'], $live['attachments_changed'], $live['attachments_skipped'], $live['attachments_errored'],
	$live['bytes_saved'], $live['done'] ? 'yes' : 'no'
) );
foreach ( $live['log'] as $entry ) {
	$savings = $entry['savings_bytes'] ?? 0;
	WP_CLI::log( sprintf( '  #%d %s -> %s (%s) saved=%d', $entry['id'], $entry['title'], $entry['action'], $entry['reason'], $savings ) );
}

// --- Verify state --------------------------------------------------------.

WP_CLI::log( '' );
WP_CLI::log( '--- Verify post-state ---' );
foreach ( $ids as $id ) {
	$attached = get_attached_file( $id );
	$mime     = get_post_mime_type( $id );
	$processed = get_post_meta( $id, \Tidy_Resize_Images\META_PROCESSED_AT, true );
	WP_CLI::log( sprintf( '  #%d: %s (%s) processed_at=%s', $id, basename( (string) $attached ), $mime, $processed ?: 'not set' ) );
}

// --- Re-run scan: fixtures should now be excluded by _tri_processed_at ---.

WP_CLI::log( '' );
$total_after = $bp->count_candidates();
WP_CLI::log( sprintf( 'count_candidates after live run: %d (should be %d less)', $total_after, $total ) );

// --- Cleanup -------------------------------------------------------------.

foreach ( $ids as $id ) {
	\Tidy_Resize_Images\Trash_Manager::purge( $id );
	wp_delete_attachment( $id, true );
}

WP_CLI::log( '' );
WP_CLI::success( 'Bulk_Processor smoke test complete.' );
