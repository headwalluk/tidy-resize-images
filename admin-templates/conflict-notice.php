<?php
/**
 * Admin notice listing active competing image-optimization plugins.
 *
 * Expects $conflicts in scope: array<string, string> plugin_path => display_name.
 * Code-first per house style.
 *
 * @package Tidy_Resize_Images
 */

namespace Tidy_Resize_Images;

defined( 'ABSPATH' ) || die();

if ( empty( $conflicts ) || ! is_array( $conflicts ) ) {
	return;
}

$tri_names_html = '';
foreach ( $conflicts as $tri_conflict_name ) {
	$tri_names_html .= sprintf( '<li><strong>%s</strong></li>', esc_html( $tri_conflict_name ) );
}

printf(
	'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p><ul style="list-style:disc;padding-left:1.5em;margin:.5em 0;">%3$s</ul><p>%4$s</p></div>',
	esc_html__( 'Tidy Resize Images:', 'tidy-resize-images' ),
	esc_html__( 'detected the following image-optimization plugins active alongside this one. Running multiple image processors can cause unexpected behaviour. We will not deactivate them — your call.', 'tidy-resize-images' ),
	wp_kses_post( $tri_names_html ),
	esc_html__( 'Visit Plugins to deactivate any that you no longer need.', 'tidy-resize-images' )
);
