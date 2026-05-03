<?php
/**
 * Runtime capability detection for image processing.
 *
 * @package Tidy_Resize_Images
 */

namespace Tidy_Resize_Images;

defined( 'ABSPATH' ) || die();

/**
 * Detects what the current PHP install can actually do with images.
 *
 * Used to:
 * - Decide whether AVIF / WebP / HEIC targets are viable on this host
 *   (the operator may select an unavailable target in settings; we fall
 *   back gracefully and surface a notice).
 * - Power the future Status tab and `wp tidy-images caps` command.
 *
 * All checks are pure (no side effects) and cheap to call. Results are
 * memoised on the instance so repeated calls cost nothing.
 *
 * @since 0.1.0
 */
class Capabilities {

	/**
	 * Memoised summary, populated lazily by get_summary().
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $summary = null;

	/**
	 * Whether the GD extension is loaded.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function has_gd(): bool {
		return extension_loaded( 'gd' );
	}

	/**
	 * Whether the Imagick extension is loaded.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function has_imagick(): bool {
		return extension_loaded( 'imagick' ) && class_exists( '\\Imagick' );
	}

	/**
	 * Whether GD can read AND write the given MIME type.
	 *
	 * @since 0.1.0
	 *
	 * @param string $mime e.g. 'image/webp'.
	 *
	 * @return bool
	 */
	public function gd_supports( string $mime ): bool {
		$supported = false;

		if ( $this->has_gd() ) {
			switch ( $mime ) {
				case MIME_JPEG:
					$supported = function_exists( 'imagecreatefromjpeg' ) && function_exists( 'imagejpeg' );
					break;
				case MIME_PNG:
					$supported = function_exists( 'imagecreatefrompng' ) && function_exists( 'imagepng' );
					break;
				case MIME_WEBP:
					$supported = function_exists( 'imagecreatefromwebp' ) && function_exists( 'imagewebp' );
					break;
				case MIME_AVIF:
					$supported = function_exists( 'imagecreatefromavif' ) && function_exists( 'imageavif' );
					break;
				case MIME_GIF:
					$supported = function_exists( 'imagecreatefromgif' ) && function_exists( 'imagegif' );
					break;
				default:
					$supported = false;
			}
		}

		return $supported;
	}

	/**
	 * Whether Imagick can read AND write the given MIME type.
	 *
	 * Imagick reports formats by short name (WEBP, AVIF, HEIC, JPEG, PNG, GIF).
	 *
	 * @since 0.1.0
	 *
	 * @param string $mime e.g. 'image/heic'.
	 *
	 * @return bool
	 */
	public function imagick_supports( string $mime ): bool {
		$supported = false;

		if ( $this->has_imagick() ) {
			$short = $this->mime_to_imagick_format( $mime );

			if ( null !== $short ) {
				$formats   = ( new \Imagick() )->queryFormats( $short );
				$supported = in_array( $short, $formats, true );
			}
		}

		return $supported;
	}

	/**
	 * Whether *any* available backend can handle the given MIME type.
	 *
	 * @since 0.1.0
	 *
	 * @param string $mime e.g. 'image/webp'.
	 *
	 * @return bool
	 */
	public function supports( string $mime ): bool {
		return $this->gd_supports( $mime ) || $this->imagick_supports( $mime );
	}

	/**
	 * Get a flat summary of detected capabilities.
	 *
	 * Returned shape:
	 *   array(
	 *     'gd'      => bool,
	 *     'imagick' => bool,
	 *     'formats' => array(
	 *       'image/jpeg' => array( 'gd' => bool, 'imagick' => bool ),
	 *       ...
	 *     ),
	 *   )
	 *
	 * Memoised on the instance.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_summary(): array {
		if ( is_null( $this->summary ) ) {
			$mimes = array(
				MIME_JPEG,
				MIME_PNG,
				MIME_WEBP,
				MIME_AVIF,
				MIME_GIF,
				MIME_HEIC,
			);

			$formats = array();
			foreach ( $mimes as $mime ) {
				$formats[ $mime ] = array(
					'gd'      => $this->gd_supports( $mime ),
					'imagick' => $this->imagick_supports( $mime ),
				);
			}

			$this->summary = array(
				'gd'      => $this->has_gd(),
				'imagick' => $this->has_imagick(),
				'formats' => $formats,
			);
		}

		return $this->summary;
	}

	/**
	 * Map an image MIME type to Imagick's short format name.
	 *
	 * Returns null for MIME types Imagick does not natively recognise
	 * (e.g. SVG, which Imagick can support but unreliably across builds).
	 *
	 * @since 0.1.0
	 *
	 * @param string $mime e.g. 'image/jpeg'.
	 *
	 * @return string|null Imagick format name (uppercase), or null if unknown.
	 */
	private function mime_to_imagick_format( string $mime ): ?string {
		$map = array(
			MIME_JPEG => 'JPEG',
			MIME_PNG  => 'PNG',
			MIME_WEBP => 'WEBP',
			MIME_AVIF => 'AVIF',
			MIME_GIF  => 'GIF',
			MIME_HEIC => 'HEIC',
		);

		return $map[ $mime ] ?? null;
	}
}
