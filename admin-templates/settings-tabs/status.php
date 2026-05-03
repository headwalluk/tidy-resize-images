<?php
/**
 * Settings — Status tab.
 *
 * Read-only view of:
 *  - Detected GD/Imagick capabilities and per-MIME support
 *  - Counts of attachments by tracking-meta presence (processed,
 *    protected, has-backup, conversion-skipped)
 *  - Current settings hash (used to invalidate failed-conversion memos)
 *
 * Counts will all be zero until M5 (upload handler) and M7 (bulk
 * processor) start writing the tracking meta. An explanatory note
 * tells the operator that's expected.
 *
 * Code-first per house style.
 *
 * @package Tidy_Resize_Images
 */

namespace Tidy_Resize_Images;

defined( 'ABSPATH' ) || die();

$tri_settings = get_plugin()->get_settings();
$tri_caps     = new Capabilities();
$tri_summary  = $tri_caps->get_summary();
$tri_rules    = Image_Processor::from_settings( $tri_settings );
$tri_hash     = Image_Processor::settings_hash( $tri_rules );

/**
 * Count attachments with the given tracking-meta presence.
 *
 * @param string $meta_key Meta key to check.
 * @param bool   $truthy   When true, meta value must also be truthy
 *                         (used for the protected flag).
 *
 * @return int
 */
$tri_count_with_meta = static function ( string $meta_key, bool $truthy = false ): int {
	$args = array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => 1,
		'no_found_rows'  => false,
		'fields'         => 'ids',
		'meta_query'     => array(  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			array(
				'key'     => $meta_key,
				'compare' => 'EXISTS',
			),
		),
	);

	if ( $truthy ) {
		$args['meta_query'] = array(  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			array(
				'key'     => $meta_key,
				'value'   => array( '', '0', 'false' ),
				'compare' => 'NOT IN',
			),
		);
	}

	$query = new \WP_Query( $args );

	return (int) $query->found_posts;
};

$tri_total_attachments     = (int) array_sum( (array) wp_count_posts( 'attachment' ) );
$tri_count_processed       = $tri_count_with_meta( META_PROCESSED_AT );
$tri_count_protected       = $tri_count_with_meta( META_PROTECTED, true );
$tri_count_with_backup     = $tri_count_with_meta( META_BACKUP );
$tri_count_skipped         = $tri_count_with_meta( META_CONVERSION_SKIPPED );
$tri_no_processing_yet_msg = ( 0 === $tri_count_processed && 0 === $tri_count_with_backup && 0 === $tri_count_skipped );

printf(
	'<h2>%s</h2><p class="tri-help">%s</p>',
	esc_html__( 'Status', 'tidy-resize-images' ),
	esc_html__( 'Read-only summary of capabilities, counts, and runtime debug values.', 'tidy-resize-images' )
);

if ( $tri_no_processing_yet_msg ) {
	printf(
		'<div class="notice notice-info inline"><p>%s</p></div>',
		esc_html__( 'No processing has run on this site yet. Counts below will populate once the upload handler (M5) or bulk processor (M7) start touching attachments.', 'tidy-resize-images' )
	);
}

// --- Backend capabilities -------------------------------------------------.
printf(
	'<h3>%s</h3><p>%s &nbsp; %s</p>',
	esc_html__( 'Backends', 'tidy-resize-images' ),
	sprintf(
		'GD: <span class="tri-cap-%s">%s</span>',
		esc_attr( $tri_summary['gd'] ? 'yes' : 'no' ),
		esc_html( $tri_summary['gd'] ? 'available' : 'not available' )
	),
	sprintf(
		'Imagick: <span class="tri-cap-%s">%s</span>',
		esc_attr( $tri_summary['imagick'] ? 'yes' : 'no' ),
		esc_html( $tri_summary['imagick'] ? 'available' : 'not available' )
	)
);

$tri_caps_rows = '';
foreach ( $tri_summary['formats'] as $tri_mime => $tri_per_backend ) {
	$tri_caps_rows .= sprintf(
		'<tr><td><code>%1$s</code></td><td><span class="tri-cap-%2$s">%3$s</span></td><td><span class="tri-cap-%4$s">%5$s</span></td></tr>',
		esc_html( $tri_mime ),
		esc_attr( $tri_per_backend['gd'] ? 'yes' : 'no' ),
		esc_html( $tri_per_backend['gd'] ? '✓' : '—' ),
		esc_attr( $tri_per_backend['imagick'] ? 'yes' : 'no' ),
		esc_html( $tri_per_backend['imagick'] ? '✓' : '—' )
	);
}

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $tri_caps_rows is built from per-piece esc_*() calls above.
printf(
	'<table class="tri-cap-table"><thead><tr><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>%s</tbody></table>',
	esc_html__( 'MIME type', 'tidy-resize-images' ),
	esc_html__( 'GD', 'tidy-resize-images' ),
	esc_html__( 'Imagick', 'tidy-resize-images' ),
	$tri_caps_rows
);
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

// --- Counts ---------------------------------------------------------------.
printf(
	'<h3 style="margin-top:30px;">%s</h3>',
	esc_html__( 'Counts', 'tidy-resize-images' )
);

$tri_count_rows = sprintf(
	'<tr><td>%1$s</td><td><strong>%2$d</strong></td></tr>'
	. '<tr><td>%3$s</td><td><strong>%4$d</strong></td></tr>'
	. '<tr><td>%5$s</td><td><strong>%6$d</strong></td></tr>'
	. '<tr><td>%7$s</td><td><strong>%8$d</strong></td></tr>'
	. '<tr><td>%9$s</td><td><strong>%10$d</strong></td></tr>',
	esc_html__( 'Total attachments', 'tidy-resize-images' ),
	$tri_total_attachments,
	esc_html__( 'Processed by Tidy Images', 'tidy-resize-images' ),
	$tri_count_processed,
	esc_html__( 'Protected (do not touch)', 'tidy-resize-images' ),
	$tri_count_protected,
	esc_html__( 'Has trash backup', 'tidy-resize-images' ),
	$tri_count_with_backup,
	esc_html__( 'Conversion skipped (result-larger memo)', 'tidy-resize-images' ),
	$tri_count_skipped
);

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $tri_count_rows is built from per-piece esc_*() calls above.
printf(
	'<table class="tri-cap-table"><tbody>%s</tbody></table>',
	$tri_count_rows
);
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

// --- Debug ---------------------------------------------------------------.
printf(
	'<h3 style="margin-top:30px;">%s</h3><p><strong>%s</strong> <code>%s</code></p><p class="tri-help">%s</p>',
	esc_html__( 'Debug', 'tidy-resize-images' ),
	esc_html__( 'Settings hash:', 'tidy-resize-images' ),
	esc_html( $tri_hash ),
	esc_html__( 'Failed-conversion memoisation markers are invalidated automatically when this hash changes. Useful for confirming that a settings change has actually altered the encoding-affecting subset of options.', 'tidy-resize-images' )
);
