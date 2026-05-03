<?php
/**
 * Settings page shell.
 *
 * Code-first per house style — no inline HTML mixed with PHP snippets.
 * Real tabs and form fields land in M3.
 *
 * @package Tidy_Resize_Images
 */

namespace Tidy_Resize_Images;

defined( 'ABSPATH' ) || die();

printf(
	'<div class="wrap"><h1>%s</h1><p>%s</p><p><em>%s</em></p></div>',
	esc_html__( 'Tidy Resize Images', 'tidy-resize-images' ),
	esc_html__( 'Plugin scaffolded. Settings tabs, bulk processor, and Media Library integration arrive in subsequent milestones.', 'tidy-resize-images' ),
	esc_html(
		sprintf(
			/* translators: %s: plugin version */
			__( 'Version %s', 'tidy-resize-images' ),
			TRI_PLUGIN_VERSION
		)
	)
);
