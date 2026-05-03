<?php
/**
 * Settings page — tabbed shell.
 *
 * Each tab loads a partial from `admin-templates/settings-tabs/<slug>.php`.
 * If the partial doesn't exist yet (work-in-progress milestones), a
 * "coming soon" placeholder is shown instead.
 *
 * Single form spanning all tabs: every tab's fields are saved by one
 * "Save Changes" button at the bottom. Form action is `options.php` —
 * the WordPress Settings API endpoint, with nonce/option_page handling
 * supplied by `settings_fields()`.
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

// --- Build nav -------------------------------------------------------------.

$tri_nav_html = '';
$tri_is_first = true;

foreach ( $tri_tabs as $tri_slug => $tri_label ) {
	$tri_nav_html .= sprintf(
		'<a href="#%1$s" class="nav-tab%2$s" data-tab="%1$s">%3$s</a>',
		esc_attr( $tri_slug ),
		$tri_is_first ? ' nav-tab-active' : '',
		esc_html( $tri_label )
	);

	$tri_is_first = false;
}

// --- Build panels (load per-tab partials, fall back to placeholder) -------.

$tri_panels_html = '';
$tri_is_first    = true;

foreach ( $tri_tabs as $tri_slug => $tri_label ) {
	$tri_template = TRI_PLUGIN_DIR . 'admin-templates/settings-tabs/' . $tri_slug . '.php';

	ob_start();

	if ( file_exists( $tri_template ) ) {
		require $tri_template;
	} else {
		printf(
			'<h2>%s</h2><p class="tri-help">%s</p>',
			esc_html( $tri_label ),
			esc_html__( 'This tab is being built — content lands in a subsequent milestone.', 'tidy-resize-images' )
		);
	}

	$tri_panel_content = ob_get_clean();

	$tri_panels_html .= sprintf(
		'<div id="%1$s-panel" class="tri-tab-panel%2$s"%3$s>%4$s</div>',
		esc_attr( $tri_slug ),
		$tri_is_first ? ' active' : '',
		$tri_is_first ? '' : ' style="display:none;"',
		$tri_panel_content
	);

	$tri_is_first = false;
}

// --- Settings API form ----------------------------------------------------.

ob_start();
settings_fields( Settings::option_group() );
$tri_settings_fields_html = ob_get_clean();

ob_start();
submit_button( __( 'Save Changes', 'tidy-resize-images' ) );
$tri_submit_html = ob_get_clean();

// $tri_nav_html, $tri_panels_html, $tri_settings_fields_html, $tri_submit_html
// are all assembled from per-piece esc_*() / WP API output (settings_fields,
// submit_button) and partial templates that handle their own escaping.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
printf(
	'<div class="wrap tri-settings"><h1>%1$s</h1><nav class="nav-tab-wrapper wp-clearfix">%2$s</nav><form action="%3$s" method="post">%4$s<div class="tri-tab-content">%5$s</div>%6$s</form></div>',
	esc_html( get_admin_page_title() ),
	$tri_nav_html,
	esc_url( admin_url( 'options.php' ) ),
	$tri_settings_fields_html,
	$tri_panels_html,
	$tri_submit_html
);
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
