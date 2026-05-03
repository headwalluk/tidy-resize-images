<?php
/**
 * Smoke test for Search_Replace.
 *
 * Covers:
 *  - Raw URL replacement in post_content
 *  - JSON-escaped URL replacement in post_content
 *  - Serialised array postmeta (top-level + nested)
 *  - JSON-encoded postmeta (Elementor-style)
 *  - Our own _tri_* meta keys are NOT touched
 *  - Dry-run produces zero mutations
 *  - rewrite_attachment_rename() derives all sub-size pairs
 *
 * Cleans up after itself.
 *
 *     wp eval-file wp-content/plugins/tidy-resize-images/dev-notes/smoke-tests/search-replace.php
 *
 * @package Tidy_Resize_Images
 */

defined( 'ABSPATH' ) || die();

WP_CLI::log( '' );
WP_CLI::log( '=== Search_Replace smoke test ===' );
WP_CLI::log( '' );

$old_url = 'https://devx.headwall.tech/wp-content/uploads/2026/05/sr-smoke-test.png';
$new_url = 'https://devx.headwall.tech/wp-content/uploads/2026/05/sr-smoke-test.webp';

// --- Fixture: post containing both URL forms + serialised meta ----------.

$content = sprintf(
	'<img src="%1$s" /> ' . PHP_EOL . 'JSON: %2$s',
	$old_url,
	wp_json_encode( array( 'image' => $old_url ) )
);

$post_id = wp_insert_post(
	array(
		'post_title'   => 'SR smoke test',
		'post_content' => $content,
		'post_status'  => 'publish',
	)
);

update_post_meta(
	$post_id,
	'sr_smoke_layout',
	array(
		'type'   => 'image',
		'url'    => $old_url,
		'nested' => array( 'fallback' => $old_url ),
	)
);

update_post_meta( $post_id, '_test_elementor_data', wp_json_encode( array( array( 'image_url' => $old_url ) ) ) );

// Our own meta — must NOT be touched.
update_post_meta( $post_id, '_tri_backup', array( 'orig_path' => $old_url ) );

WP_CLI::log( sprintf( 'Created test post %d with raw URL, JSON URL, serialised meta, and _tri_backup', $post_id ) );

// --- Dry-run -------------------------------------------------------------.

$sr     = new \Tidy_Resize_Images\Search_Replace();
$report = $sr->rewrite( $old_url, $new_url, array(), true );

WP_CLI::log( '' );
WP_CLI::log( '--- Dry-run ---' );
WP_CLI::log( sprintf( 'posts:    rows_changed=%d', $report['tables']['posts']['rows_changed'] ) );
WP_CLI::log( sprintf( 'postmeta: rows_changed=%d', $report['tables']['postmeta']['rows_changed'] ) );

if ( str_contains( get_post( $post_id )->post_content, $new_url ) ) {
	WP_CLI::error( 'Dry-run mutated post_content (it should not have).' );
}

// --- Live run ------------------------------------------------------------.

$report = $sr->rewrite( $old_url, $new_url, array(), false );

WP_CLI::log( '' );
WP_CLI::log( '--- Live run ---' );
WP_CLI::log( sprintf( 'posts:    rows_changed=%d', $report['tables']['posts']['rows_changed'] ) );
WP_CLI::log( sprintf( 'postmeta: rows_changed=%d', $report['tables']['postmeta']['rows_changed'] ) );

$updated_post = get_post( $post_id );
$layout       = get_post_meta( $post_id, 'sr_smoke_layout', true );
$elementor    = json_decode( get_post_meta( $post_id, '_test_elementor_data', true ), true );
$tri_backup   = get_post_meta( $post_id, '_tri_backup', true );

$assertions = array(
	'raw URL replaced in post'           => ! str_contains( $updated_post->post_content, $old_url ),
	'JSON-escaped URL replaced in post'  => ! str_contains( $updated_post->post_content, str_replace( '/', '\\/', $old_url ) ),
	'new URL present in post'            => str_contains( $updated_post->post_content, $new_url ),
	'serialised top-level URL replaced'  => $layout['url'] === $new_url,
	'serialised nested URL replaced'     => $layout['nested']['fallback'] === $new_url,
	'JSON meta URL replaced'             => $elementor[0]['image_url'] === $new_url,
	'_tri_backup meta NOT touched'       => $tri_backup['orig_path'] === $old_url,
);

WP_CLI::log( '' );
foreach ( $assertions as $label => $pass ) {
	WP_CLI::log( sprintf( '%s %s', $pass ? '[ok]' : '[FAIL]', $label ) );
}

if ( in_array( false, $assertions, true ) ) {
	WP_CLI::error( 'One or more assertions failed.' );
}

// --- rewrite_attachment_rename -------------------------------------------.

WP_CLI::log( '' );
WP_CLI::log( '--- rewrite_attachment_rename ---' );

$old_meta = array(
	'file'  => '2026/05/sr-rename.png',
	'sizes' => array(
		'thumbnail' => array( 'file' => 'sr-rename-150x150.png' ),
		'medium'    => array( 'file' => 'sr-rename-300x225.png' ),
	),
);
$new_meta = array(
	'file'  => '2026/05/sr-rename.webp',
	'sizes' => array(
		'thumbnail' => array( 'file' => 'sr-rename-150x150.webp' ),
		'medium'    => array( 'file' => 'sr-rename-300x225.webp' ),
	),
);

$report = $sr->rewrite_attachment_rename( 999, $old_meta, $new_meta, array(), true );
WP_CLI::log( sprintf( 'pairs derived: %d (expect 3: full + 2 sub-sizes)', $report['pairs_processed'] ) );

if ( 3 !== $report['pairs_processed'] ) {
	WP_CLI::error( 'Expected 3 rename pairs, got ' . $report['pairs_processed'] );
}

// --- Cleanup -------------------------------------------------------------.

wp_delete_post( $post_id, true );

WP_CLI::log( '' );
WP_CLI::success( 'Search_Replace smoke test complete.' );
