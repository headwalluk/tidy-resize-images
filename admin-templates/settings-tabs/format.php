<?php
/**
 * Settings — Format tab.
 *
 * Simple/Auto mode controls (v1). Expert mode is a placeholder.
 *
 * AVIF target options are runtime-gated: if the host's GD/Imagick build
 * cannot write AVIF, the option is rendered with `disabled` and an
 * explanatory tooltip, so the operator sees the option exists but
 * understands why it is unavailable.
 *
 * Code-first per house style.
 *
 * @package Tidy_Resize_Images
 */

namespace Tidy_Resize_Images;

defined( 'ABSPATH' ) || die();

$tri_settings       = get_plugin()->get_settings();
$tri_caps           = new Capabilities();
$tri_lossy_target   = (string) $tri_settings->get( OPT_FORMAT_LOSSY_TARGET );
$tri_lossy_quality  = (int) $tri_settings->get( OPT_FORMAT_LOSSY_QUALITY );
$tri_alpha_target   = (string) $tri_settings->get( OPT_FORMAT_ALPHA_TARGET );
$tri_alpha_quality  = (int) $tri_settings->get( OPT_FORMAT_ALPHA_QUALITY );
$tri_jpeg_quality   = (int) $tri_settings->get( OPT_FORMAT_JPEG_QUALITY );
$tri_avif_supported = $tri_caps->supports( MIME_AVIF );

/**
 * Human-readable label for a target MIME shown in dropdowns.
 *
 * Inline because no other template needs this mapping yet.
 *
 * @param string $mime         MIME type.
 * @param bool   $is_keep_self True if this is a "keep source format" choice.
 *
 * @return string
 */
$tri_mime_label = static function ( string $mime, bool $is_keep_self = false ): string {
	$labels = array(
		MIME_WEBP => 'WebP',
		MIME_AVIF => 'AVIF',
		MIME_PNG  => 'PNG (keep — do not convert)',
	);
	$label  = $labels[ $mime ] ?? $mime;

	return $is_keep_self ? $label : $label;
};

/**
 * Build a <select> dropdown for a target-MIME setting.
 *
 * @param string        $option_key   wp_options key.
 * @param array<string> $allowed      Allowed MIME values.
 * @param string        $current      Current selected value.
 * @param Capabilities  $caps         Runtime capability detector.
 * @param callable      $label_fn     Function returning a human label for a MIME.
 *
 * @return string HTML for the <select> element.
 */
$tri_render_target_select = static function ( string $option_key, array $allowed, string $current, Capabilities $caps, callable $label_fn ): string {
	$options = '';

	foreach ( $allowed as $mime ) {
		// PNG (keep) and WebP are universally supported in WP-bundled image
		// libraries on supported PHP versions; AVIF is the only target that
		// genuinely needs a capability check.
		$is_supported = ( MIME_AVIF === $mime ) ? $caps->supports( MIME_AVIF ) : true;
		$disabled     = $is_supported ? '' : ' disabled';
		$selected     = ( $mime === $current ) ? ' selected' : '';
		$label        = call_user_func( $label_fn, $mime );

		if ( ! $is_supported ) {
			$label .= ' — ' . __( 'not supported on this server', 'tidy-resize-images' );
		}

		$options .= sprintf(
			'<option value="%1$s"%2$s%3$s>%4$s</option>',
			esc_attr( $mime ),
			$selected,
			$disabled,
			esc_html( $label )
		);
	}

	return sprintf(
		'<select name="%1$s" id="%1$s">%2$s</select>',
		esc_attr( $option_key ),
		$options
	);
};

printf(
	'<h2>%s</h2><p class="tri-help">%s</p>',
	esc_html__( 'Format', 'tidy-resize-images' ),
	esc_html__( 'Choose the target format for converted and recompressed images. Simple/Auto mode is the only mode in v1 — Expert mode (a from→to mapping matrix) is planned.', 'tidy-resize-images' )
);

echo '<table class="form-table" role="presentation"><tbody>';

// --- Lossy target ---------------------------------------------------------.
$tri_lossy_select = $tri_render_target_select(
	OPT_FORMAT_LOSSY_TARGET,
	Settings::lossy_target_mimes(),
	$tri_lossy_target,
	$tri_caps,
	$tri_mime_label
);

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $tri_lossy_select is built from per-piece esc_*() calls above.
printf(
	'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td>%3$s<p class="description">%4$s</p></td></tr>',
	esc_attr( OPT_FORMAT_LOSSY_TARGET ),
	esc_html__( 'Lossy target format', 'tidy-resize-images' ),
	$tri_lossy_select,
	esc_html__( 'Used when converting PNG-without-alpha, HEIC, and static GIF. JPEG and WebP sources are always recompressed in place.', 'tidy-resize-images' )
);
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

printf(
	'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input name="%1$s" id="%1$s" type="number" min="%3$d" max="%4$d" step="1" value="%5$d" class="small-text" /><p class="description">%6$s</p></td></tr>',
	esc_attr( OPT_FORMAT_LOSSY_QUALITY ),
	esc_html__( 'Lossy quality', 'tidy-resize-images' ),
	esc_attr( (string) MIN_QUALITY ),
	esc_attr( (string) MAX_QUALITY ),
	esc_attr( (string) $tri_lossy_quality ),
	esc_html__( 'Encoder quality 1–100. Lower = smaller file, more visible artefacts.', 'tidy-resize-images' )
);

// --- Alpha-preserving target ---------------------------------------------.
$tri_alpha_select = $tri_render_target_select(
	OPT_FORMAT_ALPHA_TARGET,
	Settings::alpha_target_mimes(),
	$tri_alpha_target,
	$tri_caps,
	$tri_mime_label
);

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $tri_alpha_select is built from per-piece esc_*() calls above.
printf(
	'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td>%3$s<p class="description">%4$s</p></td></tr>',
	esc_attr( OPT_FORMAT_ALPHA_TARGET ),
	esc_html__( 'Alpha-preserving target format', 'tidy-resize-images' ),
	$tri_alpha_select,
	esc_html__( 'Used when the source has a meaningful alpha channel (PNG, AVIF, HEIC). Selecting "PNG (keep)" leaves alpha sources untouched.', 'tidy-resize-images' )
);
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

printf(
	'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input name="%1$s" id="%1$s" type="number" min="%3$d" max="%4$d" step="1" value="%5$d" class="small-text" /><p class="description">%6$s</p></td></tr>',
	esc_attr( OPT_FORMAT_ALPHA_QUALITY ),
	esc_html__( 'Alpha-preserving quality', 'tidy-resize-images' ),
	esc_attr( (string) MIN_QUALITY ),
	esc_attr( (string) MAX_QUALITY ),
	esc_attr( (string) $tri_alpha_quality ),
	esc_html__( 'Encoder quality 1–100. Higher than lossy quality is recommended — alpha-bearing images are usually graphics where artefacts are more visible.', 'tidy-resize-images' )
);

// --- JPEG recompression --------------------------------------------------.
printf(
	'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input name="%1$s" id="%1$s" type="number" min="%3$d" max="%4$d" step="1" value="%5$d" class="small-text" /><p class="description">%6$s</p></td></tr>',
	esc_attr( OPT_FORMAT_JPEG_QUALITY ),
	esc_html__( 'JPEG recompression quality', 'tidy-resize-images' ),
	esc_attr( (string) MIN_QUALITY ),
	esc_attr( (string) MAX_QUALITY ),
	esc_attr( (string) $tri_jpeg_quality ),
	esc_html__( 'Quality used when recompressing JPEG sources in place (no format change).', 'tidy-resize-images' )
);

echo '</tbody></table>';

// --- Expert mode placeholder ---------------------------------------------.
printf(
	'<h3 style="margin-top:30px;">%s</h3><div class="tri-coming-soon"><p><strong>%s</strong> %s</p></div>',
	esc_html__( 'Expert mode', 'tidy-resize-images' ),
	esc_html__( 'Coming soon.', 'tidy-resize-images' ),
	esc_html__( 'Expert mode will replace the Simple/Auto decision tree with a from→to mapping matrix, so you can configure per-source-MIME outcomes (e.g. "JPEG → AVIF for sources over 2 MB, otherwise recompress JPEG in place"). Simple/Auto mode covers most use cases; Expert mode is for power users who want full control.', 'tidy-resize-images' )
);
