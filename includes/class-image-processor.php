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
 * - `execute( $plan, $path, $tmp_path )` — actually transform the image,
 *   write to a temp path, return a Result. Lands in M2.4.
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
		$lib         = new Image_Library( $source_path, $this->caps );
		$source_meta = $lib->get_meta();
		$lib->close();

		$decision = array();

		if ( empty( $source_meta ) ) {
			$decision = $this->skip_plan( 'source_unreadable' );
		} else {
			$excluded = isset( $rules['excluded_mimes'] ) && is_array( $rules['excluded_mimes'] )
				? $rules['excluded_mimes']
				: array();

			if ( in_array( $source_meta['mime'], $excluded, true ) ) {
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
	 *   image/jpeg   → recompress in place at jpeg_quality
	 *   image/webp   → recompress in place at lossy_quality
	 *   image/heic   → convert to lossy-target (capability-gated; skip if no Imagick HEIC)
	 *   image/gif    → animated ? skip : convert to lossy-target
	 *   image/svg+xml→ skip (vector format; out of our scope)
	 *
	 * After branch selection, max-edge resize is applied if the source's
	 * longest edge exceeds rules.max_edge. AVIF target is downgraded to
	 * WebP if the host can't write AVIF.
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
				$target_mime = MIME_JPEG;
				$quality     = (int) $rules['jpeg_quality'];
				$action      = 'recompress';
				$reason      = 'jpeg_recompress';
				break;

			case MIME_WEBP:
				$target_mime = MIME_WEBP;
				$quality     = (int) $rules['lossy_quality'];
				$action      = 'recompress';
				$reason      = 'webp_recompress';
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
