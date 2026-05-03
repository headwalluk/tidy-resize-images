<?php
/**
 * Settings — Behaviour tab.
 *
 * Operational toggles that apply across all processing paths: dry-run,
 * EXIF stripping, originals backup, trash retention, and the always-skip
 * MIME list.
 *
 * The "DB search-replace scope" controls (mentioned in the M3 tracker
 * entry) land in M6 alongside the search-replace implementation — the
 * UI without the underlying code would be a foot-gun.
 *
 * Code-first per house style.
 *
 * @package Tidy_Resize_Images
 */

namespace Tidy_Resize_Images;

defined( 'ABSPATH' ) || die();

$tri_settings       = get_plugin()->get_settings();
$tri_dry_run        = (bool) $tri_settings->get( OPT_BEHAVIOUR_DRY_RUN );
$tri_strip_exif     = (bool) $tri_settings->get( OPT_BEHAVIOUR_STRIP_EXIF );
$tri_backup         = (bool) $tri_settings->get( OPT_BEHAVIOUR_BACKUP_ORIGINALS );
$tri_retention_days = (int) $tri_settings->get( OPT_BEHAVIOUR_TRASH_RETENTION_DAYS );
$tri_excluded_mimes = (array) $tri_settings->get( OPT_BEHAVIOUR_EXCLUDED_MIMES );

/**
 * Friendly display label for a MIME type used in the excluded-MIMEs list.
 *
 * @param string $mime MIME type.
 *
 * @return string
 */
$tri_mime_friendly = static function ( string $mime ): string {
	$labels = array(
		MIME_JPEG => 'JPEG',
		MIME_PNG  => 'PNG',
		MIME_WEBP => 'WebP',
		MIME_AVIF => 'AVIF',
		MIME_GIF  => 'GIF',
		MIME_HEIC => 'HEIC',
		MIME_SVG  => 'SVG',
	);

	return $labels[ $mime ] ?? $mime;
};

printf(
	'<h2>%s</h2><p class="tri-help">%s</p>',
	esc_html__( 'Behaviour', 'tidy-resize-images' ),
	esc_html__( 'Operational toggles that apply to every processing path (upload-time, bulk, WP-CLI).', 'tidy-resize-images' )
);

echo '<table class="form-table" role="presentation"><tbody>';

// --- Dry-run --------------------------------------------------------------.
printf(
	'<tr><th scope="row">%1$s</th><td><label><input name="%2$s" id="%2$s" type="checkbox" value="1"%3$s /> %4$s</label><p class="description">%5$s</p></td></tr>',
	esc_html__( 'Dry-run mode', 'tidy-resize-images' ),
	esc_attr( OPT_BEHAVIOUR_DRY_RUN ),
	checked( $tri_dry_run, true, false ),
	esc_html__( 'Plan but do not modify anything', 'tidy-resize-images' ),
	esc_html__( 'When enabled, every code path produces a report of what it would do without writing files or updating the database. Useful for previewing a bulk run before committing.', 'tidy-resize-images' )
);

// --- Strip EXIF -----------------------------------------------------------.
printf(
	'<tr><th scope="row">%1$s</th><td><label><input name="%2$s" id="%2$s" type="checkbox" value="1"%3$s /> %4$s</label><p class="description">%5$s</p></td></tr>',
	esc_html__( 'Strip EXIF metadata', 'tidy-resize-images' ),
	esc_attr( OPT_BEHAVIOUR_STRIP_EXIF ),
	checked( $tri_strip_exif, true, false ),
	esc_html__( 'Remove EXIF / XMP / IPTC on encode', 'tidy-resize-images' ),
	esc_html__( 'Drops camera metadata (timestamps, GPS, device info) and authoring metadata from re-encoded images. Recommended for public sites — modest size savings and removes accidental information disclosure.', 'tidy-resize-images' )
);

// --- Backup originals -----------------------------------------------------.
printf(
	'<tr><th scope="row">%1$s</th><td><label><input name="%2$s" id="%2$s" type="checkbox" value="1"%3$s /> %4$s</label><p class="description">%5$s</p></td></tr>',
	esc_html__( 'Backup originals to Trash', 'tidy-resize-images' ),
	esc_attr( OPT_BEHAVIOUR_BACKUP_ORIGINALS ),
	checked( $tri_backup, true, false ),
	esc_html__( 'Keep a restorable copy of every modified original', 'tidy-resize-images' ),
	esc_html__( 'Originals are moved to wp-content/uploads/tri-trash/ and can be restored from the Trash admin page or via WP-CLI. Highly recommended — disabling this means modifications are irreversible.', 'tidy-resize-images' )
);

// --- Trash retention ------------------------------------------------------.
printf(
	'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input name="%1$s" id="%1$s" type="number" min="%3$d" max="%4$d" step="1" value="%5$d" class="small-text" /> %6$s<p class="description">%7$s</p></td></tr>',
	esc_attr( OPT_BEHAVIOUR_TRASH_RETENTION_DAYS ),
	esc_html__( 'Trash retention', 'tidy-resize-images' ),
	esc_attr( (string) MIN_TRASH_RETENTION_DAYS ),
	esc_attr( (string) MAX_TRASH_RETENTION_DAYS ),
	esc_attr( (string) $tri_retention_days ),
	esc_html__( 'days', 'tidy-resize-images' ),
	esc_html__( 'Trashed originals are auto-purged after this many days. Set to 0 to disable auto-purge (purge manually only). The cron auto-purge lands in M10.', 'tidy-resize-images' )
);

// --- Excluded MIMEs -------------------------------------------------------.
$tri_mime_checkboxes = '';

foreach ( Settings::known_image_mimes() as $tri_mime ) {
	$tri_is_excluded      = in_array( $tri_mime, $tri_excluded_mimes, true );
	$tri_mime_checkboxes .= sprintf(
		'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%1$s[]" value="%2$s"%3$s /> <code>%2$s</code> &nbsp; %4$s</label>',
		esc_attr( OPT_BEHAVIOUR_EXCLUDED_MIMES ),
		esc_attr( $tri_mime ),
		checked( $tri_is_excluded, true, false ),
		esc_html( call_user_func( $tri_mime_friendly, $tri_mime ) )
	);
}

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $tri_mime_checkboxes is built from per-piece esc_*() calls above.
printf(
	'<tr><th scope="row">%1$s</th><td><fieldset>%2$s</fieldset><p class="description">%3$s</p></td></tr>',
	esc_html__( 'Always skip these MIME types', 'tidy-resize-images' ),
	$tri_mime_checkboxes,
	esc_html__( 'The image processor will refuse to touch sources of these MIME types regardless of other settings. Defaults: SVG and GIF (vector and animated formats are out of v1 scope).', 'tidy-resize-images' )
);
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

echo '</tbody></table>';
