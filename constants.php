<?php
/**
 * Plugin-wide constants.
 *
 * @package Tidy_Resize_Images
 */

namespace Tidy_Resize_Images;

defined( 'ABSPATH' ) || die();

// --- Admin ------------------------------------------------------------------.

const ADMIN_MENU_SLUG  = 'tidy-resize-images';
const ADMIN_CAPABILITY = 'manage_options';

// --- Option keys (wp_options) - prefix with OPT_ ----------------------------.

// Limits.
const OPT_LIMITS_MAX_EDGE  = 'tri_limits_max_edge';
const OPT_LIMITS_MAX_BYTES = 'tri_limits_max_bytes';

// Format (Simple/Auto mode).
const OPT_FORMAT_LOSSY_TARGET  = 'tri_format_lossy_target';
const OPT_FORMAT_LOSSY_QUALITY = 'tri_format_lossy_quality';
const OPT_FORMAT_ALPHA_TARGET  = 'tri_format_alpha_target';
const OPT_FORMAT_ALPHA_QUALITY = 'tri_format_alpha_quality';
const OPT_FORMAT_JPEG_QUALITY  = 'tri_format_jpeg_quality';

// Behaviour.
const OPT_BEHAVIOUR_DRY_RUN              = 'tri_behaviour_dry_run';
const OPT_BEHAVIOUR_STRIP_EXIF           = 'tri_behaviour_strip_exif';
const OPT_BEHAVIOUR_BACKUP_ORIGINALS     = 'tri_behaviour_backup_originals';
const OPT_BEHAVIOUR_TRASH_RETENTION_DAYS = 'tri_behaviour_trash_retention_days';
const OPT_BEHAVIOUR_EXCLUDED_MIMES       = 'tri_behaviour_excluded_mimes';

// Internal state.
const OPT_DB_VERSION = 'tri_db_version';

// --- Post meta keys (per-attachment) - prefix with META_ --------------------.

const META_PROTECTED          = '_tri_protected';
const META_PROCESSED_AT       = '_tri_processed_at';
const META_PROCESSING_LOG     = '_tri_processing_log';
const META_BACKUP             = '_tri_backup';
const META_CONVERSION_SKIPPED = '_tri_conversion_skipped';

// --- Default values - prefix with DEF_ --------------------------------------.

const DEF_MAX_EDGE             = 2560;
const DEF_MAX_BYTES            = 524288; // 512 KB.
const DEF_LOSSY_TARGET         = 'image/webp';
const DEF_LOSSY_QUALITY        = 80;
const DEF_ALPHA_TARGET         = 'image/webp';
const DEF_ALPHA_QUALITY        = 85;
const DEF_JPEG_QUALITY         = 82;
const DEF_TRASH_RETENTION_DAYS = 30;

// --- Setting input ranges - prefix with MIN_/MAX_ ---------------------------.
//
// Used by sanitisation callbacks to clamp operator input to sensible bounds.

const MIN_EDGE                 = 100;
const MAX_EDGE                 = 10000;
const MIN_BYTES                = 1024;             // 1 KB.
const MAX_BYTES                = 10 * 1024 * 1024; // 10 MB.
const MIN_QUALITY              = 1;
const MAX_QUALITY              = 100;
const MIN_TRASH_RETENTION_DAYS = 0;                // 0 = never auto-purge.
const MAX_TRASH_RETENTION_DAYS = 365;

// --- MIME types -------------------------------------------------------------.

const MIME_JPEG = 'image/jpeg';
const MIME_PNG  = 'image/png';
const MIME_WEBP = 'image/webp';
const MIME_AVIF = 'image/avif';
const MIME_GIF  = 'image/gif';
const MIME_HEIC = 'image/heic';
const MIME_SVG  = 'image/svg+xml';

const DEF_EXCLUDED_MIMES = array( MIME_SVG, MIME_GIF );

// --- Conflict detection -----------------------------------------------------.
//
// Plugins that overlap with our functionality. We never deactivate them —
// we surface an admin notice and let the operator decide.

const CONFLICTING_PLUGINS = array(
	'imsanity/imsanity.php'                         => 'Imsanity',
	'ewww-image-optimizer/ewww-image-optimizer.php' => 'EWWW Image Optimizer',
	'shortpixel-image-optimiser/wp-shortpixel.php'  => 'ShortPixel Image Optimizer',
	'wp-smushit/wp-smush.php'                       => 'Smush',
	'optimole-wp/optimole-wp.php'                   => 'Optimole',
	'resmushit-image-optimizer/resmushit.php'       => 'reSmush.it',
	'tiny-compress-images/tiny-compress-images.php' => 'TinyPNG (Compress JPEG & PNG)',
	'webp-converter-for-media/webp-converter-for-media.php' => 'Converter for Media',
);
