<?php
/**
 * Settings page — tabbed shell.
 *
 * Tab content panels are populated by the M3.4-7 commits. The navigation
 * uses URL-hash routing (#limits / #format / #behaviour / #status) so the
 * active tab persists across reloads and is shareable via deep-link.
 *
 * Code-first per house style.
 *
 * @package Tidy_Resize_Images
 */

namespace Tidy_Resize_Images;

defined( 'ABSPATH' ) || die();

$tri_tabs = array(
	'limits'    => __( 'Limits', 'tidy-resize-images' ),
	'format'    => __( 'Format', 'tidy-resize-images' ),
	'behaviour' => __( 'Behaviour', 'tidy-resize-images' ),
	'status'    => __( 'Status', 'tidy-resize-images' ),
);

$tri_nav_html    = '';
$tri_panels_html = '';
$tri_is_first    = true;

foreach ( $tri_tabs as $tri_slug => $tri_label ) {
	$tri_nav_html .= sprintf(
		'<a href="#%1$s" class="nav-tab%2$s" data-tab="%1$s">%3$s</a>',
		esc_attr( $tri_slug ),
		$tri_is_first ? ' nav-tab-active' : '',
		esc_html( $tri_label )
	);

	$tri_panels_html .= sprintf(
		'<div id="%1$s-panel" class="tri-tab-panel%2$s"%3$s><h2>%4$s</h2><p class="tri-help">%5$s</p></div>',
		esc_attr( $tri_slug ),
		$tri_is_first ? ' active' : '',
		$tri_is_first ? '' : ' style="display:none;"',
		esc_html( $tri_label ),
		esc_html__( 'Form fields land in subsequent milestones.', 'tidy-resize-images' )
	);

	$tri_is_first = false;
}

// $tri_nav_html and $tri_panels_html are assembled from per-piece esc_html() and
// esc_attr() calls above; the surrounding markup is static.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
printf(
	'<div class="wrap tri-settings"><h1>%1$s</h1><nav class="nav-tab-wrapper wp-clearfix">%2$s</nav><div class="tri-tab-content">%3$s</div></div>',
	esc_html( get_admin_page_title() ),
	$tri_nav_html,
	$tri_panels_html
);
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
