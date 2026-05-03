<?php
/**
 * Settings registration, storage, and read access.
 *
 * @package Tidy_Resize_Images
 */

namespace Tidy_Resize_Images;

defined( 'ABSPATH' ) || die();

/**
 * Settings.
 *
 * Owns the wp_options storage layer for the plugin: registers each option
 * with the WordPress Settings API (so the standard options.php submission
 * flow handles nonces and persistence), defines per-option sanitisation
 * callbacks, and provides a typed read API with deterministic defaults.
 *
 * Storage strategy: one wp_options row per setting (not a single bundled
 * array). More verbose, but compatible with the Settings API and easier
 * to inspect via `wp option get`.
 *
 * Per-context overrides (admin / front-end / bulk uploads) are out of
 * scope for v1 — stored as flat scalars now, planned to migrate to
 * per-context arrays in a later milestone if the need arises.
 *
 * @since 0.1.0
 */
class Settings {

	/**
	 * The Settings API option group used for register_setting() calls and
	 * referenced by settings_fields() in form templates.
	 */
	private const OPTION_GROUP = 'tri_options';

	/**
	 * Register every setting with the WordPress Settings API.
	 *
	 * Hooked to admin_init by Plugin::run(). Must run before any
	 * options.php submission is processed (admin_init is the standard
	 * place).
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		// Limits.
		register_setting(
			self::OPTION_GROUP,
			OPT_LIMITS_MAX_EDGE,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_max_edge' ),
				'default'           => DEF_MAX_EDGE,
			)
		);
		register_setting(
			self::OPTION_GROUP,
			OPT_LIMITS_MAX_BYTES,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_max_bytes' ),
				'default'           => DEF_MAX_BYTES,
			)
		);

		// Format - Simple/Auto.
		register_setting(
			self::OPTION_GROUP,
			OPT_FORMAT_LOSSY_TARGET,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_lossy_target' ),
				'default'           => DEF_LOSSY_TARGET,
			)
		);
		register_setting(
			self::OPTION_GROUP,
			OPT_FORMAT_LOSSY_QUALITY,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_quality' ),
				'default'           => DEF_LOSSY_QUALITY,
			)
		);
		register_setting(
			self::OPTION_GROUP,
			OPT_FORMAT_ALPHA_TARGET,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_alpha_target' ),
				'default'           => DEF_ALPHA_TARGET,
			)
		);
		register_setting(
			self::OPTION_GROUP,
			OPT_FORMAT_ALPHA_QUALITY,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_quality' ),
				'default'           => DEF_ALPHA_QUALITY,
			)
		);
		register_setting(
			self::OPTION_GROUP,
			OPT_FORMAT_JPEG_QUALITY,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_quality' ),
				'default'           => DEF_JPEG_QUALITY,
			)
		);

		// Behaviour.
		register_setting(
			self::OPTION_GROUP,
			OPT_BEHAVIOUR_DRY_RUN,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_bool' ),
				'default'           => false,
			)
		);
		register_setting(
			self::OPTION_GROUP,
			OPT_BEHAVIOUR_STRIP_EXIF,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_bool' ),
				'default'           => true,
			)
		);
		register_setting(
			self::OPTION_GROUP,
			OPT_BEHAVIOUR_BACKUP_ORIGINALS,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_bool' ),
				'default'           => true,
			)
		);
		register_setting(
			self::OPTION_GROUP,
			OPT_BEHAVIOUR_TRASH_RETENTION_DAYS,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_trash_retention_days' ),
				'default'           => DEF_TRASH_RETENTION_DAYS,
			)
		);
		register_setting(
			self::OPTION_GROUP,
			OPT_BEHAVIOUR_EXCLUDED_MIMES,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_mime_array' ),
				'default'           => DEF_EXCLUDED_MIMES,
			)
		);
		register_setting(
			self::OPTION_GROUP,
			OPT_BEHAVIOUR_SR_POSTS,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_bool' ),
				'default'           => true,
			)
		);
		register_setting(
			self::OPTION_GROUP,
			OPT_BEHAVIOUR_SR_POSTMETA,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_bool' ),
				'default'           => true,
			)
		);
	}

	/**
	 * Build the search-replace `$scope` array from the current settings.
	 *
	 * Used by callers (Trash_Manager, Bulk_Processor) when invoking
	 * `Search_Replace::rewrite()` so the operator's scope toggles are
	 * honoured uniformly across every code path.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, bool>
	 */
	public function sr_scope(): array {
		return array(
			'posts'    => (bool) $this->get( OPT_BEHAVIOUR_SR_POSTS ),
			'postmeta' => (bool) $this->get( OPT_BEHAVIOUR_SR_POSTMETA ),
		);
	}

	/**
	 * Get the current value of a setting, falling back to the registered
	 * default when no value is stored.
	 *
	 * Returns the raw type from get_option() — caller must cast if needed.
	 * Use the typed helpers (get_int / get_bool / get_string / get_array)
	 * when you need a guaranteed type.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key Option name (use the OPT_ constants).
	 *
	 * @return mixed
	 */
	public function get( string $key ) {
		$defaults = self::all_defaults();
		$default  = $defaults[ $key ] ?? null;

		return get_option( $key, $default );
	}

	/**
	 * Get the current value of every registered setting as a flat array.
	 *
	 * Keyed by OPT_ constant value (the option name in wp_options), so
	 * `$settings->all()[ OPT_LIMITS_MAX_EDGE ]` is the lookup pattern.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$result = array();

		foreach ( self::all_defaults() as $key => $default ) {
			$result[ $key ] = get_option( $key, $default );
		}

		return $result;
	}

	/**
	 * The full map of OPT_ name => default value, computed from the
	 * DEF_ constants in constants.php.
	 *
	 * Used both to seed get_option() defaults and to enumerate every
	 * setting for all() and for option-group migrations later on.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public static function all_defaults(): array {
		return array(
			OPT_LIMITS_MAX_EDGE                => DEF_MAX_EDGE,
			OPT_LIMITS_MAX_BYTES               => DEF_MAX_BYTES,
			OPT_FORMAT_LOSSY_TARGET            => DEF_LOSSY_TARGET,
			OPT_FORMAT_LOSSY_QUALITY           => DEF_LOSSY_QUALITY,
			OPT_FORMAT_ALPHA_TARGET            => DEF_ALPHA_TARGET,
			OPT_FORMAT_ALPHA_QUALITY           => DEF_ALPHA_QUALITY,
			OPT_FORMAT_JPEG_QUALITY            => DEF_JPEG_QUALITY,
			OPT_BEHAVIOUR_DRY_RUN              => false,
			OPT_BEHAVIOUR_STRIP_EXIF           => true,
			OPT_BEHAVIOUR_BACKUP_ORIGINALS     => true,
			OPT_BEHAVIOUR_TRASH_RETENTION_DAYS => DEF_TRASH_RETENTION_DAYS,
			OPT_BEHAVIOUR_EXCLUDED_MIMES       => DEF_EXCLUDED_MIMES,
			OPT_BEHAVIOUR_SR_POSTS             => true,
			OPT_BEHAVIOUR_SR_POSTMETA          => true,
		);
	}

	/**
	 * MIMEs that may be selected as the lossy (non-alpha) target.
	 *
	 * Operators set this for PNG-without-alpha, JPEG (recompress in place
	 * applies separately), WebP recompression, HEIC, and static GIF.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string>
	 */
	public static function lossy_target_mimes(): array {
		return array( MIME_WEBP, MIME_AVIF );
	}

	/**
	 * MIMEs that may be selected as the alpha-preserving target.
	 *
	 * Includes MIME_PNG so an operator can choose to keep PNG alpha
	 * sources untouched even when the lossy target is something else.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string>
	 */
	public static function alpha_target_mimes(): array {
		return array( MIME_WEBP, MIME_AVIF, MIME_PNG );
	}

	/**
	 * MIMEs that may appear in the excluded-MIME list.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string>
	 */
	public static function known_image_mimes(): array {
		return array( MIME_JPEG, MIME_PNG, MIME_WEBP, MIME_AVIF, MIME_GIF, MIME_HEIC, MIME_SVG );
	}

	/**
	 * Sanitise the max-edge setting by clamping to the allowed range.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Raw value from the form.
	 *
	 * @return int
	 */
	public function sanitize_max_edge( $value ): int {
		$int = (int) $value;

		return max( MIN_EDGE, min( MAX_EDGE, $int ) );
	}

	/**
	 * Sanitise the max-bytes setting.
	 *
	 * The form expresses this as KB (operator-friendly); we store the
	 * raw byte count. This sanitiser receives bytes — the form template
	 * is responsible for the KB-to-bytes conversion at submission time.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Raw value from the form (in bytes).
	 *
	 * @return int
	 */
	public function sanitize_max_bytes( $value ): int {
		$int = (int) $value;

		return max( MIN_BYTES, min( MAX_BYTES, $int ) );
	}

	/**
	 * Sanitise a quality value (1-100).
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Raw value from the form.
	 *
	 * @return int
	 */
	public function sanitize_quality( $value ): int {
		$int = (int) $value;

		return max( MIN_QUALITY, min( MAX_QUALITY, $int ) );
	}

	/**
	 * Sanitise the lossy target MIME — must be one of the allowed values.
	 *
	 * Falls back to DEF_LOSSY_TARGET on invalid input rather than
	 * surfacing an error, since invalid input here means a tampered form
	 * (the dropdown only offers valid choices).
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Raw value from the form.
	 *
	 * @return string
	 */
	public function sanitize_lossy_target( $value ): string {
		$mime = is_string( $value ) ? $value : '';

		return in_array( $mime, self::lossy_target_mimes(), true ) ? $mime : DEF_LOSSY_TARGET;
	}

	/**
	 * Sanitise the alpha-preserving target MIME.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Raw value from the form.
	 *
	 * @return string
	 */
	public function sanitize_alpha_target( $value ): string {
		$mime = is_string( $value ) ? $value : '';

		return in_array( $mime, self::alpha_target_mimes(), true ) ? $mime : DEF_ALPHA_TARGET;
	}

	/**
	 * Sanitise a boolean setting using the WordPress filter idiom that
	 * accepts '1', 'on', 'yes', 'true', etc.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Raw value from the form.
	 *
	 * @return bool
	 */
	public function sanitize_bool( $value ): bool {
		return (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Sanitise the trash retention days setting.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Raw value from the form.
	 *
	 * @return int
	 */
	public function sanitize_trash_retention_days( $value ): int {
		$int = (int) $value;

		return max( MIN_TRASH_RETENTION_DAYS, min( MAX_TRASH_RETENTION_DAYS, $int ) );
	}

	/**
	 * Sanitise the excluded-MIMEs array — drop anything that is not on
	 * our known-image list.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Raw value from the form.
	 *
	 * @return array<string>
	 */
	public function sanitize_mime_array( $value ): array {
		$known = self::known_image_mimes();
		$input = is_array( $value ) ? $value : array();
		$out   = array();

		foreach ( $input as $mime ) {
			if ( is_string( $mime ) && in_array( $mime, $known, true ) ) {
				$out[] = $mime;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * The Settings API option group name — exposed so form templates can
	 * pass it to settings_fields().
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function option_group(): string {
		return self::OPTION_GROUP;
	}
}
