<?php
/**
 * `wp tidy-images settings` — read and write plugin settings.
 *
 * @package Tidy_Resize_Images
 */

namespace Tidy_Resize_Images;

defined( 'ABSPATH' ) || die();

/**
 * `wp tidy-images settings` — read and write plugin settings.
 *
 * Settings keys are accepted in their short form (e.g. `max_edge`) or as
 * the full wp_options name (e.g. `tri_limits_max_edge`). All writes route
 * through the existing Settings sanitisers so values stay consistent
 * with what the admin UI would produce.
 *
 * @since 0.5.0
 */
class CLI_Settings {

	/**
	 * Get one or all plugin settings.
	 *
	 * ## OPTIONS
	 *
	 * [<key>]
	 * : Setting key (short form like `max_edge`, or full like
	 * `tri_limits_max_edge`). Omit to list every setting.
	 *
	 * [--format=<format>]
	 * : Output format when listing every setting.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp tidy-images settings get
	 *     wp tidy-images settings get max_edge
	 *     wp tidy-images settings get --format=json
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Associative args.
	 *
	 * @return void
	 */
	public function get( array $args, array $assoc_args ): void {
		if ( ! empty( $args[0] ) ) {
			$short  = self::resolve_key( (string) $args[0] );
			$value  = get_plugin()->get_settings()->get( $short['opt'] );
			$render = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );

			\WP_CLI::log( false === $render ? '' : (string) $render );
			return;
		}

		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		$rows   = array();

		foreach ( self::short_to_opt_map() as $short => $opt ) {
			$value = get_plugin()->get_settings()->get( $opt );

			$rows[] = array(
				'key'   => $short,
				'opt'   => $opt,
				'value' => is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ),
			);
		}

		\WP_CLI\Utils\format_items( $format, $rows, array( 'key', 'opt', 'value' ) );
	}

	/**
	 * Set a plugin setting.
	 *
	 * Routes through the existing Settings sanitiser for the chosen option,
	 * so the value is clamped / coerced exactly as the admin UI would do
	 * before storage.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Setting key (short form like `max_edge`, or full like
	 * `tri_limits_max_edge`).
	 *
	 * <value>
	 * : New value. For arrays (e.g. excluded_mimes) pass a comma-separated
	 * list (e.g. `image/svg+xml,image/gif`).
	 *
	 * ## EXAMPLES
	 *
	 *     wp tidy-images settings set max_edge 2560
	 *     wp tidy-images settings set lossy_target image/webp
	 *     wp tidy-images settings set strip_exif true
	 *     wp tidy-images settings set excluded_mimes image/svg+xml,image/gif
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Associative args (unused).
	 *
	 * @return void
	 */
	public function set( array $args, array $assoc_args ): void {
		unset( $assoc_args );

		if ( count( $args ) < 2 ) {
			\WP_CLI::error( 'Usage: wp tidy-images settings set <key> <value>' );
		}

		$short    = self::resolve_key( (string) $args[0] );
		$raw      = (string) $args[1];
		$settings = get_plugin()->get_settings();
		$prepared = self::prepare_value( $short['short'], $raw );
		$method   = $short['sanitizer'];
		$clean    = $settings->{$method}( $prepared );

		update_option( $short['opt'], $clean );

		$render = is_scalar( $clean ) ? (string) $clean : (string) wp_json_encode( $clean );

		\WP_CLI::success( sprintf( '%s = %s', $short['short'], $render ) );
	}

	/**
	 * Resolve a CLI-supplied key (short or full) to the OPT_ name and the
	 * Settings sanitiser method for the option.
	 *
	 * Unknown keys terminate the command with an error listing the
	 * accepted short names.
	 *
	 * @since 0.5.0
	 *
	 * @param string $key Short or full key.
	 *
	 * @return array{short: string, opt: string, sanitizer: string}
	 */
	private static function resolve_key( string $key ): array {
		$map      = self::short_to_opt_map();
		$full_map = array_flip( $map );

		if ( isset( $map[ $key ] ) ) {
			$short = $key;
			$opt   = $map[ $key ];
		} elseif ( isset( $full_map[ $key ] ) ) {
			$short = $full_map[ $key ];
			$opt   = $key;
		} else {
			\WP_CLI::error(
				sprintf(
					"Unknown settings key '%s'. Known keys: %s",
					$key,
					implode( ', ', array_keys( $map ) )
				)
			);
		}

		$sanitizers = self::sanitizer_map();
		$method     = $sanitizers[ $short ] ?? '';

		if ( '' === $method ) {
			\WP_CLI::error( sprintf( "No sanitiser registered for key '%s'.", $short ) );
		}

		return array(
			'short'     => $short,
			'opt'       => $opt,
			'sanitizer' => $method,
		);
	}

	/**
	 * Prepare a raw CLI string into the shape the chosen sanitiser expects.
	 *
	 * Most sanitisers accept strings directly; the array case
	 * (excluded_mimes) needs a comma-split before sanitisation.
	 *
	 * @since 0.5.0
	 *
	 * @param string $short Short key (already validated).
	 * @param string $raw   Raw value from the CLI.
	 *
	 * @return mixed
	 */
	private static function prepare_value( string $short, string $raw ) {
		if ( 'excluded_mimes' === $short ) {
			$parts = array_map( 'trim', explode( ',', $raw ) );
			return array_values( array_filter( $parts, static fn( $v ): bool => '' !== $v ) );
		}

		return $raw;
	}

	/**
	 * Short-name → OPT_ constant value map.
	 *
	 * The CLI accepts short names by default; the full OPT_ values are
	 * still tolerated so power users can paste the wp_options key directly.
	 *
	 * Keep this in sync with Settings::all_defaults() — every key there
	 * should appear here.
	 *
	 * @since 0.5.0
	 *
	 * @return array<string, string>
	 */
	private static function short_to_opt_map(): array {
		return array(
			'max_edge'             => OPT_LIMITS_MAX_EDGE,
			'max_bytes'            => OPT_LIMITS_MAX_BYTES,
			'lossy_target'         => OPT_FORMAT_LOSSY_TARGET,
			'lossy_quality'        => OPT_FORMAT_LOSSY_QUALITY,
			'alpha_target'         => OPT_FORMAT_ALPHA_TARGET,
			'alpha_quality'        => OPT_FORMAT_ALPHA_QUALITY,
			'jpeg_quality'         => OPT_FORMAT_JPEG_QUALITY,
			'dry_run'              => OPT_BEHAVIOUR_DRY_RUN,
			'strip_exif'           => OPT_BEHAVIOUR_STRIP_EXIF,
			'backup_originals'     => OPT_BEHAVIOUR_BACKUP_ORIGINALS,
			'trash_retention_days' => OPT_BEHAVIOUR_TRASH_RETENTION_DAYS,
			'excluded_mimes'       => OPT_BEHAVIOUR_EXCLUDED_MIMES,
			'sr_posts'             => OPT_BEHAVIOUR_SR_POSTS,
			'sr_postmeta'          => OPT_BEHAVIOUR_SR_POSTMETA,
		);
	}

	/**
	 * Short-name → Settings sanitiser method map.
	 *
	 * Hardcoded rather than discovered via reflection so phpcs and IDEs
	 * can lint the relationship and catch typos at review time.
	 *
	 * @since 0.5.0
	 *
	 * @return array<string, string>
	 */
	private static function sanitizer_map(): array {
		return array(
			'max_edge'             => 'sanitize_max_edge',
			'max_bytes'            => 'sanitize_max_bytes',
			'lossy_target'         => 'sanitize_lossy_target',
			'lossy_quality'        => 'sanitize_quality',
			'alpha_target'         => 'sanitize_alpha_target',
			'alpha_quality'        => 'sanitize_quality',
			'jpeg_quality'         => 'sanitize_quality',
			'dry_run'              => 'sanitize_bool',
			'strip_exif'           => 'sanitize_bool',
			'backup_originals'     => 'sanitize_bool',
			'trash_retention_days' => 'sanitize_trash_retention_days',
			'excluded_mimes'       => 'sanitize_mime_array',
			'sr_posts'             => 'sanitize_bool',
			'sr_postmeta'          => 'sanitize_bool',
		);
	}
}
