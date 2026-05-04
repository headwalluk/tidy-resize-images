<?php
/**
 * Decides what to do with an image and (in M2.4) carries out the transform.
 *
 * @package Tidy_Resize_Images
 */

namespace Tidy_Resize_Images;

defined( 'ABSPATH' ) || die();

/**
 * Image processor.
 *
 * Two responsibilities, separated for testability:
 *
 * - `plan( $path, $rules )` — read the source, run the decision tree, return
 *   a Plan describing what *would* happen. No filesystem mutation. Cheap to
 *   call repeatedly.
 * - `execute( $plan, $path, $tmp_path )` — carry out the transform, write
 *   to a temp path, return a Result. The on-disk swap (replacing the source
 *   with the temp file) and the DB rewrites belong to Trash_Manager (M4)
 *   and Search_Replace (M6).
 *
 * The decision step flows through the `tri_format_decision` filter so a
 * future Expert mode (or any third-party plugin) can override the default
 * Simple/Auto rules without subclassing.
 *
 * @since 0.1.0
 */
class Image_Processor {

	/**
	 * Capability detector — gates AVIF/HEIC behaviour.
	 *
	 * @var Capabilities
	 */
	private Capabilities $caps;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Capabilities|null $caps Optional capability detector (injected for testability).
	 */
	public function __construct( ?Capabilities $caps = null ) {
		$this->caps = $caps ?? new Capabilities();
	}

	/**
	 * Compute a settings hash over the subset of $rules that affects
	 * encoded output bytes.
	 *
	 * Used by Skip_Memo to decide whether a previously-recorded
	 * "result-larger-than-source" memo is still valid: when the operator
	 * changes any of these knobs, the memo is automatically invalidated
	 * because the hash no longer matches.
	 *
	 * Hashed inputs (per the format-decision tree in the project tracker):
	 *   - lossy_target, lossy_quality
	 *   - alpha_target, alpha_quality
	 *   - jpeg_quality
	 *   - strip_exif
	 *
	 * Excluded: max_edge (the result-larger rule only fires when no
	 * dimension change occurred, so max_edge can never have differed
	 * between memo and current run for the memo to apply); excluded_mimes
	 * (decision-stage concern, not encoding-stage); dry_run flag (no
	 * effect on output bytes).
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $rules Ruleset.
	 *
	 * @return string sha1 hex string.
	 */
	public static function settings_hash( array $rules ): string {
		$relevant = array(
			'lossy_target'  => (string) ( $rules['lossy_target'] ?? '' ),
			'lossy_quality' => (int) ( $rules['lossy_quality'] ?? 0 ),
			'alpha_target'  => (string) ( $rules['alpha_target'] ?? '' ),
			'alpha_quality' => (int) ( $rules['alpha_quality'] ?? 0 ),
			'jpeg_quality'  => (int) ( $rules['jpeg_quality'] ?? 0 ),
			'strip_exif'    => (bool) ( $rules['strip_exif'] ?? false ),
		);

		return sha1( (string) wp_json_encode( $relevant ) );
	}

	/**
	 * Compile the default ruleset from plugin constants.
	 *
	 * M3 will add a `from_settings()` factory that reads from wp_options;
	 * for now everything comes from the DEF_ constants in constants.php.
	 *
	 * Returned shape:
	 *   array(
	 *     'max_edge'       => int,           // px; longest edge cap
	 *     'lossy_target'   => string,        // MIME, e.g. 'image/webp'
	 *     'lossy_quality'  => int,           // 1-100
	 *     'alpha_target'   => string,        // MIME, e.g. 'image/webp'
	 *     'alpha_quality'  => int,           // 1-100
	 *     'jpeg_quality'   => int,           // 1-100, used when source is JPEG
	 *     'strip_exif'     => bool,
	 *     'excluded_mimes' => array<string>, // MIMEs we never touch
	 *   )
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	/**
	 * Build a ruleset from the current wp_options-stored settings.
	 *
	 * Reads from a `Settings` instance (defaulting to the orchestrator's
	 * shared instance). Returns the same shape as `default_rules()` —
	 * the `Image_Processor` doesn't care whether rules came from the
	 * constants (testing, fresh install) or the operator's saved
	 * settings.
	 *
	 * Note: `max_bytes`, `dry_run`, and `backup_originals` are
	 * intentionally NOT included. Those are scanner / wrapper-layer
	 * concerns (which attachments are candidates, whether to mutate the
	 * filesystem, whether to back up the original) — not Image_Processor
	 * concerns.
	 *
	 * @since 0.1.0
	 *
	 * @param Settings|null $settings Optional Settings instance for
	 *                                testability. Defaults to the
	 *                                orchestrator's shared instance.
	 *
	 * @return array<string, mixed> Ruleset, same shape as default_rules().
	 */
	public static function from_settings( ?Settings $settings = null ): array {
		if ( is_null( $settings ) ) {
			$settings = get_plugin()->get_settings();
		}

		return array(
			'max_edge'       => (int) $settings->get( OPT_LIMITS_MAX_EDGE ),
			'lossy_target'   => (string) $settings->get( OPT_FORMAT_LOSSY_TARGET ),
			'lossy_quality'  => (int) $settings->get( OPT_FORMAT_LOSSY_QUALITY ),
			'alpha_target'   => (string) $settings->get( OPT_FORMAT_ALPHA_TARGET ),
			'alpha_quality'  => (int) $settings->get( OPT_FORMAT_ALPHA_QUALITY ),
			'jpeg_quality'   => (int) $settings->get( OPT_FORMAT_JPEG_QUALITY ),
			'strip_exif'     => (bool) $settings->get( OPT_BEHAVIOUR_STRIP_EXIF ),
			'excluded_mimes' => (array) $settings->get( OPT_BEHAVIOUR_EXCLUDED_MIMES ),
		);
	}

	/**
	 * Compile the default ruleset from plugin constants.
	 *
	 * Used as a fallback when no Settings instance is available (e.g. in
	 * unit tests or fresh-install code paths). For production callers
	 * during a normal request, use `from_settings()` instead so the
	 * operator's saved values are honoured.
	 *
	 * Returned shape:
	 *   array(
	 *     'max_edge'       => int,
	 *     'lossy_target'   => string,  // MIME
	 *     'lossy_quality'  => int,     // 1-100
	 *     'alpha_target'   => string,  // MIME
	 *     'alpha_quality'  => int,
	 *     'jpeg_quality'   => int,
	 *     'strip_exif'     => bool,
	 *     'excluded_mimes' => array<string>,
	 *   )
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public static function default_rules(): array {
		return array(
			'max_edge'       => DEF_MAX_EDGE,
			'lossy_target'   => DEF_LOSSY_TARGET,
			'lossy_quality'  => DEF_LOSSY_QUALITY,
			'alpha_target'   => DEF_ALPHA_TARGET,
			'alpha_quality'  => DEF_ALPHA_QUALITY,
			'jpeg_quality'   => DEF_JPEG_QUALITY,
			'strip_exif'     => true,
			'excluded_mimes' => DEF_EXCLUDED_MIMES,
		);
	}

	/**
	 * Plan what to do with an image — no filesystem mutation.
	 *
	 * Steps:
	 *   1. Probe source metadata via Image_Library.
	 *   2. Skip if the source is unreadable or its MIME is on the
	 *      excluded list.
	 *   3. Otherwise compute the default decision via the decision tree.
	 *   4. Pass the decision through the `tri_format_decision` filter so
	 *      external code can override.
	 *
	 * Returned Plan shape:
	 *   array(
	 *     'action'      => 'convert' | 'recompress' | 'resize_only' | 'skip',
	 *     'target_mime' => string,   // empty when action === 'skip'
	 *     'quality'     => int,      // 0 when action === 'skip'
	 *     'max_edge'    => int|null, // null = no resize required
	 *     'strip_exif'  => bool,
	 *     'reason'      => string,   // for logging / dry-run report
	 *     'source_meta' => array,    // mime, dims, bytes, has_alpha, is_animated
	 *   )
	 *
	 * @since 0.1.0
	 *
	 * @param string               $source_path Absolute path to the source image.
	 * @param array<string, mixed> $rules       Ruleset (see default_rules() for shape).
	 *
	 * @return array<string, mixed> Plan.
	 */
	public function plan( string $source_path, array $rules ): array {
		$excluded = isset( $rules['excluded_mimes'] ) && is_array( $rules['excluded_mimes'] )
			? $rules['excluded_mimes']
			: array();

		// Early MIME check — catches vector formats (SVG) and other
		// excluded MIMEs before we attempt a raster decode that would
		// fail for non-raster formats. We don't rely on wp_check_filetype
		// alone because WordPress excludes SVG from its allowed-MIMEs
		// list by default.
		$ext_mime        = $this->detect_mime( $source_path );
		$excluded_by_ext = '' !== $ext_mime && in_array( $ext_mime, $excluded, true );

		$source_meta = array();
		$decision    = array();

		if ( $excluded_by_ext ) {
			$source_meta = array(
				'mime'        => $ext_mime,
				'width'       => 0,
				'height'      => 0,
				'bytes'       => 0,
				'has_alpha'   => false,
				'is_animated' => false,
			);
			$decision    = $this->skip_plan( 'excluded_mime' );
		} else {
			$lib         = new Image_Library( $source_path, $this->caps );
			$source_meta = $lib->get_meta();
			$lib->close();

			if ( empty( $source_meta ) ) {
				$decision = $this->skip_plan( 'source_unreadable' );
			} elseif ( in_array( $source_meta['mime'], $excluded, true ) ) {
				$decision = $this->skip_plan( 'excluded_mime' );
			} else {
				$decision = $this->default_decision( $source_meta, $rules );
			}
		}

		$decision['source_meta'] = $source_meta;

		/**
		 * Filter the format decision for an image.
		 *
		 * Allows external code to override the Simple/Auto decision tree
		 * (for example a future Expert mode mapping matrix). The returned
		 * array must conform to the same Plan shape — see plan()'s docblock.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $decision    Default decision.
		 * @param string               $source_path Absolute path to source.
		 * @param array<string, mixed> $source_meta Source meta (mime, dims, bytes, has_alpha, is_animated).
		 * @param array<string, mixed> $rules       Ruleset in effect.
		 */
		$decision = apply_filters( 'tri_format_decision', $decision, $source_path, $source_meta, $rules );

		return $decision;
	}

	/**
	 * Compute the default decision for a source — the Simple/Auto branch table.
	 *
	 * Branch table:
	 *   image/png    → has_alpha ? alpha-target : lossy-target  → convert (or recompress if same MIME)
	 *   image/jpeg   → lossy-target (convert if different, else recompress at jpeg_quality)
	 *   image/webp   → lossy-target (convert if different, else recompress at lossy_quality)
	 *   image/heic   → convert to lossy-target (capability-gated; skip if no Imagick HEIC)
	 *   image/gif    → animated ? skip : convert to lossy-target
	 *   image/svg+xml→ skip (vector format; out of our scope)
	 *
	 * After branch selection, max-edge resize is applied if the source's
	 * longest edge exceeds rules.max_edge. AVIF target is downgraded to
	 * WebP if the host can't write AVIF.
	 *
	 * For lossy sources (JPEG, WebP) where the chosen target differs
	 * from the source MIME, the orchestrator
	 * (`Attachment_Processor::process()`) treats the resulting plan as a
	 * convert-with-fallback: if the converted file ends up larger than
	 * the source, it retries with `recompress_plan()` to recompress in
	 * the source format before declaring the result discarded. That
	 * gives most JPEGs a chance at WebP savings while preserving the
	 * "always at least try in-place recompression" promise.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $source_meta From Image_Library::get_meta().
	 * @param array<string, mixed> $rules       Ruleset.
	 *
	 * @return array<string, mixed> Plan (without source_meta — plan() adds that).
	 */
	public function default_decision( array $source_meta, array $rules ): array {
		$mime        = (string) ( $source_meta['mime'] ?? '' );
		$action      = 'skip';
		$target_mime = '';
		$quality     = 0;
		$reason      = 'unknown_mime';

		switch ( $mime ) {
			case MIME_PNG:
				if ( ! empty( $source_meta['has_alpha'] ) ) {
					$target_mime = (string) $rules['alpha_target'];
					$quality     = (int) $rules['alpha_quality'];
					$reason      = 'png_alpha_to_alpha_target';
				} else {
					$target_mime = (string) $rules['lossy_target'];
					$quality     = (int) $rules['lossy_quality'];
					$reason      = 'png_opaque_to_lossy_target';
				}
				$action = ( $target_mime === $mime ) ? 'recompress' : 'convert';
				break;

			case MIME_JPEG:
				$target_mime = (string) $rules['lossy_target'];

				if ( MIME_JPEG === $target_mime ) {
					// Operator chose JPEG as their lossy target — straight
					// in-place recompression at jpeg_quality.
					$quality = (int) $rules['jpeg_quality'];
					$action  = 'recompress';
					$reason  = 'jpeg_recompress';
				} else {
					// Convert to the operator's preferred lossy format. If
					// the result ends up larger than the source, the
					// orchestrator falls back to a JPEG recompression.
					$quality = (int) $rules['lossy_quality'];
					$action  = 'convert';
					$reason  = 'jpeg_to_lossy_target';
				}
				break;

			case MIME_WEBP:
				$target_mime = (string) $rules['lossy_target'];

				if ( MIME_WEBP === $target_mime ) {
					$quality = (int) $rules['lossy_quality'];
					$action  = 'recompress';
					$reason  = 'webp_recompress';
				} else {
					$quality = (int) $rules['lossy_quality'];
					$action  = 'convert';
					$reason  = 'webp_to_lossy_target';
				}
				break;

			case MIME_HEIC:
				if ( $this->caps->imagick_supports( MIME_HEIC ) ) {
					$target_mime = (string) $rules['lossy_target'];
					$quality     = (int) $rules['lossy_quality'];
					$action      = 'convert';
					$reason      = 'heic_to_lossy_target';
				} else {
					$reason = 'heic_no_backend_support';
				}
				break;

			case MIME_GIF:
				if ( ! empty( $source_meta['is_animated'] ) ) {
					$reason = 'gif_animated';
				} else {
					$target_mime = (string) $rules['lossy_target'];
					$quality     = (int) $rules['lossy_quality'];
					$action      = 'convert';
					$reason      = 'gif_static_to_lossy_target';
				}
				break;

			case MIME_SVG:
				$reason = 'svg_excluded';
				break;

			default:
				$reason = 'unknown_mime';
		}

		// Capability fallback: if the target is AVIF but the host can't write it,
		// downgrade to WebP. Quality knob stays as-is — operator can tune later.
		if ( 'skip' !== $action && MIME_AVIF === $target_mime && ! $this->caps->supports( MIME_AVIF ) ) {
			$target_mime = MIME_WEBP;
			$reason     .= '_avif_unsupported_fallback_to_webp';
		}

		// Decide whether resize is required.
		$max_edge = null;

		if ( 'skip' !== $action ) {
			$longest = max( (int) ( $source_meta['width'] ?? 0 ), (int) ( $source_meta['height'] ?? 0 ) );

			if ( $longest > (int) $rules['max_edge'] ) {
				$max_edge = (int) $rules['max_edge'];

				// If the *only* change is a resize (target MIME == source MIME and
				// we'd have just been recompressing at the same quality), label
				// the action accordingly so loggers can be precise. We still
				// re-encode at the configured quality though — the resize itself
				// requires a re-encode, so quality always applies.
				if ( $target_mime === $mime && 'recompress' === $action ) {
					// Keep $action as 'recompress' since we are still re-encoding.
					$reason .= '_with_resize';
				}
			}
		}

		return array(
			'action'      => $action,
			'target_mime' => $target_mime,
			'quality'     => $quality,
			'max_edge'    => $max_edge,
			'strip_exif'  => (bool) ( $rules['strip_exif'] ?? true ),
			'reason'      => $reason,
		);
	}

	/**
	 * Build a fallback Plan that recompresses an image in its source
	 * format, for use when the primary `convert` plan produced a result
	 * larger than the source.
	 *
	 * Only applicable when the source MIME is one we can both read and
	 * write — currently JPEG and WebP. Returns null otherwise (PNG / GIF
	 * sources where falling back to a lossless re-encode would almost
	 * always be larger than the source anyway, and HEIC sources, where
	 * we have no encoder).
	 *
	 * The returned plan inherits max_edge, strip_exif, and source_meta
	 * from the original plan but switches target_mime to the source
	 * MIME and picks the source-format quality knob (jpeg_quality for
	 * JPEG sources, lossy_quality for WebP sources).
	 *
	 * @since 0.4.0
	 *
	 * @param array<string, mixed> $plan  Original plan (must be a
	 *                                    `convert` plan whose primary
	 *                                    execute already returned
	 *                                    `committed=false`).
	 * @param array<string, mixed> $rules Ruleset.
	 *
	 * @return array<string, mixed>|null Fallback plan or null when no
	 *                                   fallback is applicable.
	 */
	public function recompress_plan( array $plan, array $rules ): ?array {
		// Only the convert path has a meaningful "recompress in source
		// format" fallback — recompress plans already are the fallback.
		if ( 'convert' !== ( $plan['action'] ?? '' ) ) {
			return null;
		}

		$source_mime = (string) ( $plan['source_meta']['mime'] ?? '' );
		$quality     = 0;
		$supported   = true;

		switch ( $source_mime ) {
			case MIME_JPEG:
				$quality = (int) ( $rules['jpeg_quality'] ?? 0 );
				break;
			case MIME_WEBP:
				$quality = (int) ( $rules['lossy_quality'] ?? 0 );
				break;
			default:
				$supported = false;
		}

		if ( ! $supported ) {
			return null;
		}

		return array(
			'action'      => 'recompress',
			'target_mime' => $source_mime,
			'quality'     => $quality,
			'max_edge'    => $plan['max_edge'] ?? null,
			'strip_exif'  => (bool) ( $plan['strip_exif'] ?? true ),
			'reason'      => 'recompress_fallback',
			'source_meta' => isset( $plan['source_meta'] ) && is_array( $plan['source_meta'] )
				? $plan['source_meta']
				: array(),
		);
	}

	/**
	 * Carry out the transform described by a Plan and write the output
	 * to a temp path.
	 *
	 * Implements the "result-larger-than-source" rule from the format
	 * decision tree: if the encoded output is at least as large as the
	 * source AND no dimension change occurred, the output is discarded
	 * and the Result is marked `committed=false` with reason
	 * `result_larger_than_source`. Callers (M2.5) translate that into the
	 * `_tri_conversion_skipped` memoisation marker.
	 *
	 * Returned Result shape:
	 *   array(
	 *     'success'         => bool,    // false only on hard failure (encode error etc.)
	 *     'committed'       => bool,    // true if we kept the output, false if discarded
	 *     'output_path'     => string,  // temp file path; '' if not committed
	 *     'output_meta'     => array,   // mime/dims/bytes of output; empty if not committed
	 *     'reason'          => string,  // 'committed' | 'result_larger_than_source' | plan reason | error reason
	 *     'savings_bytes'   => int,     // source - output (negative if output grew)
	 *     'savings_percent' => float,   // 0.0 if not committed
	 *     'error'           => string,  // empty on success
	 *   )
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $plan        Plan from plan().
	 * @param string               $source_path Absolute path to the source image.
	 * @param string|null          $tmp_path    Optional temp output path; auto-generated if null.
	 *
	 * @return array<string, mixed> Result.
	 */
	public function execute( array $plan, string $source_path, ?string $tmp_path = null ): array {
		$result = $this->empty_result();

		// Guard clause: skip plans short-circuit before any I/O.
		if ( 'skip' === ( $plan['action'] ?? 'skip' ) ) {
			$result['success'] = true;
			$result['reason']  = $plan['reason'] ?? 'skipped';
			return $result;
		}

		$source_meta  = isset( $plan['source_meta'] ) && is_array( $plan['source_meta'] ) ? $plan['source_meta'] : array();
		$source_bytes = (int) ( $source_meta['bytes'] ?? 0 );
		$target_mime  = (string) ( $plan['target_mime'] ?? '' );
		$quality      = (int) ( $plan['quality'] ?? 0 );
		$strip_exif   = (bool) ( $plan['strip_exif'] ?? false );
		$max_edge     = $plan['max_edge'] ?? null;

		if ( is_null( $tmp_path ) ) {
			$tmp_path = $this->generate_tmp_path( $target_mime );
		}

		$lib     = new Image_Library( $source_path, $this->caps );
		$proceed = true;

		if ( is_int( $max_edge ) && ! $lib->resize( $max_edge ) ) {
			$err              = $lib->get_last_error();
			$result['error']  = ! is_null( $err ) ? $err->get_error_message() : __( 'Resize failed.', 'tidy-resize-images' );
			$result['reason'] = 'resize_failed';
			$proceed          = false;
		}

		$written = '';

		if ( $proceed ) {
			$written = $lib->encode( $target_mime, $quality, $strip_exif, $tmp_path );

			if ( '' === $written ) {
				$err              = $lib->get_last_error();
				$result['error']  = ! is_null( $err ) ? $err->get_error_message() : __( 'Encode failed.', 'tidy-resize-images' );
				$result['reason'] = 'encode_failed';
				$proceed          = false;
			}
		}

		$lib->close();

		if ( $proceed ) {
			$output_filesize = filesize( $written );
			$output_bytes    = is_int( $output_filesize ) ? $output_filesize : 0;
			$no_dim_change   = is_null( $max_edge );

			if ( $output_bytes >= $source_bytes && $no_dim_change ) {
				wp_delete_file( $written );
				$result['success']       = true;
				$result['committed']     = false;
				$result['reason']        = 'result_larger_than_source';
				$result['savings_bytes'] = $source_bytes - $output_bytes;
			} else {
				$output_lib  = new Image_Library( $written, $this->caps );
				$output_meta = $output_lib->get_meta();
				$output_lib->close();

				$result['success']         = true;
				$result['committed']       = true;
				$result['output_path']     = $written;
				$result['output_meta']     = $output_meta;
				$result['reason']          = 'committed';
				$result['savings_bytes']   = $source_bytes - $output_bytes;
				$result['savings_percent'] = $source_bytes > 0
					? round( ( $source_bytes - $output_bytes ) / $source_bytes * 100, 1 )
					: 0.0;
			}
		}

		return $result;
	}

	/**
	 * Build an empty Result with default zero values.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	private function empty_result(): array {
		return array(
			'success'         => false,
			'committed'       => false,
			'output_path'     => '',
			'output_meta'     => array(),
			'reason'          => '',
			'savings_bytes'   => 0,
			'savings_percent' => 0.0,
			'error'           => '',
		);
	}

	/**
	 * Detect a file's MIME type, with fallback for formats WordPress
	 * blocks from its allowed-uploads list (notably SVG).
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Absolute path to the file.
	 *
	 * @return string MIME type, or empty string if unknown.
	 */
	private function detect_mime( string $path ): string {
		$filetype = wp_check_filetype( $path );
		$mime     = (string) ( $filetype['type'] ?? '' );

		if ( '' === $mime && function_exists( 'mime_content_type' ) ) {
			$sniffed = mime_content_type( $path );

			if ( false !== $sniffed ) {
				$mime = (string) $sniffed;
			}
		}

		return $mime;
	}

	/**
	 * Generate a unique temp output path with the appropriate extension
	 * for the target MIME.
	 *
	 * Does not actually create the file on disk — the path is reserved
	 * by uniqueness of the UUID. Image_Library::encode() creates the
	 * file when it writes.
	 *
	 * @since 0.1.0
	 *
	 * @param string $target_mime e.g. 'image/webp'.
	 *
	 * @return string Absolute path under the WP temp directory.
	 */
	private function generate_tmp_path( string $target_mime ): string {
		$ext_map = array(
			MIME_JPEG => 'jpg',
			MIME_PNG  => 'png',
			MIME_WEBP => 'webp',
			MIME_AVIF => 'avif',
			MIME_GIF  => 'gif',
		);

		$ext = $ext_map[ $target_mime ] ?? 'tmp';

		return get_temp_dir() . 'tri_' . wp_generate_uuid4() . '.' . $ext;
	}

	/**
	 * Build a skip-plan with the given reason.
	 *
	 * @since 0.1.0
	 *
	 * @param string $reason Short machine-readable reason for logging.
	 *
	 * @return array<string, mixed>
	 */
	private function skip_plan( string $reason ): array {
		return array(
			'action'      => 'skip',
			'target_mime' => '',
			'quality'     => 0,
			'max_edge'    => null,
			'strip_exif'  => false,
			'reason'      => $reason,
		);
	}
}
