<?php
/**
 * Settings — Limits tab.
 *
 * Two fields: max longest edge (px) and max file size (bytes). Both are
 * thresholds used by the bulk scanner (M7) to decide which attachments
 * are candidates for processing — they do NOT cause an attachment to be
 * skipped during processing if it happens to be smaller than the limits.
 *
 * Code-first per house style.
 *
 * @package Tidy_Resize_Images
 */

namespace Tidy_Resize_Images;

defined( 'ABSPATH' ) || die();

$tri_settings  = get_plugin()->get_settings();
$tri_max_edge  = (int) $tri_settings->get( OPT_LIMITS_MAX_EDGE );
$tri_max_bytes = (int) $tri_settings->get( OPT_LIMITS_MAX_BYTES );

printf(
	'<h2>%s</h2><p class="tri-help">%s</p>',
	esc_html__( 'Limits', 'tidy-resize-images' ),
	esc_html__( 'Set the dimensional and file-size thresholds at which images become candidates for processing. These thresholds drive the bulk scanner — they are not enforced as caps on otherwise-good images.', 'tidy-resize-images' )
);

echo '<table class="form-table" role="presentation"><tbody>';

// Max longest edge.
printf(
	'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input name="%1$s" id="%1$s" type="number" min="%3$d" max="%4$d" step="1" value="%5$d" class="small-text" /> %6$s<p class="description">%7$s</p></td></tr>',
	esc_attr( OPT_LIMITS_MAX_EDGE ),
	esc_html__( 'Max longest edge', 'tidy-resize-images' ),
	esc_attr( (string) MIN_EDGE ),
	esc_attr( (string) MAX_EDGE ),
	esc_attr( (string) $tri_max_edge ),
	esc_html__( 'pixels', 'tidy-resize-images' ),
	esc_html(
		sprintf(
			/* translators: 1: min, 2: max */
			__( 'Images whose longest side exceeds this value are proportionally resized. Range: %1$d – %2$d px.', 'tidy-resize-images' ),
			MIN_EDGE,
			MAX_EDGE
		)
	)
);

// Max file size — stored and edited in bytes; helper line shows
// human-readable equivalent so the operator can sanity-check.
printf(
	'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input name="%1$s" id="%1$s" type="number" min="%3$d" max="%4$d" step="1024" value="%5$d" class="regular-text" /> %6$s<p class="description">%7$s</p></td></tr>',
	esc_attr( OPT_LIMITS_MAX_BYTES ),
	esc_html__( 'Max file size', 'tidy-resize-images' ),
	esc_attr( (string) MIN_BYTES ),
	esc_attr( (string) MAX_BYTES ),
	esc_attr( (string) $tri_max_bytes ),
	esc_html__( 'bytes', 'tidy-resize-images' ),
	esc_html(
		sprintf(
			/* translators: 1: human-readable current value, 2: human-readable min, 3: human-readable max */
			__( 'Images larger than this become candidates for re-encoding. Current value: %1$s. Range: %2$s – %3$s.', 'tidy-resize-images' ),
			size_format( $tri_max_bytes ),
			size_format( MIN_BYTES ),
			size_format( MAX_BYTES )
		)
	)
);

echo '</tbody></table>';
