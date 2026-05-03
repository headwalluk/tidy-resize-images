<?php
/**
 * Smoke test for the image processor pipeline.
 *
 * Exercises plan() and execute() against three synthetic images covering
 * the main branches of the format-decision tree. Run it with:
 *
 *     wp eval-file wp-content/plugins/tidy-resize-images/dev-notes/smoke-tests/processor-roundtrip.php
 *
 * Not run as part of any automated suite — this is a hands-on tool for
 * iterating on processor changes without touching the Media Library.
 *
 * @package Tidy_Resize_Images
 */

defined( 'ABSPATH' ) || die();

$proc  = new \Tidy_Resize_Images\Image_Processor();
$rules = \Tidy_Resize_Images\Image_Processor::default_rules();

$cases = array();

// Case 1: PNG with alpha, oversized.
$png_alpha = tempnam( sys_get_temp_dir(), 'tri_smoke_' ) . '.png';
$gd        = imagecreatetruecolor( 4000, 3000 );
imagesavealpha( $gd, true );
imagefill( $gd, 0, 0, imagecolorallocatealpha( $gd, 100, 200, 50, 64 ) );
for ( $i = 0; $i < 5000; $i++ ) {
	imagesetpixel( $gd, mt_rand( 0, 3999 ), mt_rand( 0, 2999 ), imagecolorallocate( $gd, mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 0, 255 ) ) );
}
imagepng( $gd, $png_alpha );
imagedestroy( $gd );
$cases['Large PNG with alpha'] = $png_alpha;

// Case 2: Tiny JPEG (already lossy).
$jpeg = tempnam( sys_get_temp_dir(), 'tri_smoke_' ) . '.jpg';
$gd   = imagecreatetruecolor( 100, 100 );
imagefill( $gd, 0, 0, imagecolorallocate( $gd, 50, 100, 150 ) );
imagejpeg( $gd, $jpeg, 50 );
imagedestroy( $gd );
$cases['Tiny JPEG'] = $jpeg;

// Case 3: SVG (excluded MIME).
$svg = tempnam( sys_get_temp_dir(), 'tri_smoke_' ) . '.svg';
file_put_contents( $svg, '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><circle cx="50" cy="50" r="40"/></svg>' );
$cases['SVG'] = $svg;

WP_CLI::log( '' );
WP_CLI::log( '=== Image_Processor smoke test ===' );
WP_CLI::log( '' );
WP_CLI::log( sprintf( 'Settings hash: %s', \Tidy_Resize_Images\Image_Processor::settings_hash( $rules ) ) );
WP_CLI::log( '' );

foreach ( $cases as $label => $path ) {
	$plan   = $proc->plan( $path, $rules );
	$result = $proc->execute( $plan, $path );

	WP_CLI::log( sprintf( '--- %s ---', $label ) );
	WP_CLI::log( sprintf( 'source : %s (%d bytes)', $plan['source_meta']['mime'] ?? '?', filesize( $path ) ) );
	WP_CLI::log(
		sprintf(
			'plan   : action=%s target=%s q=%d max_edge=%s reason=%s',
			$plan['action'],
			$plan['target_mime'],
			$plan['quality'],
			is_null( $plan['max_edge'] ) ? 'null' : (string) $plan['max_edge'],
			$plan['reason']
		)
	);
	WP_CLI::log(
		sprintf(
			'result : success=%s committed=%s reason=%s savings=%dB (%s%%)',
			$result['success'] ? 'y' : 'n',
			$result['committed'] ? 'y' : 'n',
			$result['reason'],
			$result['savings_bytes'],
			$result['savings_percent']
		)
	);

	if ( $result['committed'] && file_exists( $result['output_path'] ) ) {
		wp_delete_file( $result['output_path'] );
	}

	WP_CLI::log( '' );
}

unlink( $png_alpha );
unlink( $jpeg );
unlink( $svg );

WP_CLI::success( 'Smoke test complete.' );
