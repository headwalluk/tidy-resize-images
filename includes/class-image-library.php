<?php
/**
 * Thin wrapper around WP_Image_Editor with raw GD/Imagick reach-through.
 *
 * @package Tidy_Resize_Images
 */

namespace Tidy_Resize_Images;

defined( 'ABSPATH' ) || die();

// This class reads raw bytes from arbitrary image files to inspect format
// headers (PNG IHDR, WebP VP8X, GIF blocks). WP_Filesystem is not appropriate
// for byte-level reads of user-uploaded media. fopen/fread/fclose and
// file_get_contents failures are checked explicitly with strict comparisons.
// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged

/**
 * Image library wrapper.
 *
 * Defaults to WordPress's WP_Image_Editor abstraction for read/resize/encode
 * — that buys us its backend selection logic (Imagick > GD) and consistent
 * error handling. We reach into raw Imagick (or file headers) for things
 * WP_Image_Editor does not expose:
 *
 *   - Alpha-channel detection (needed before deciding lossy vs alpha target)
 *   - Animated-image detection (we never touch animated GIFs)
 *   - EXIF stripping with predictable behaviour across backends
 *
 * Usage:
 *
 *   $lib  = new Image_Library( $path );
 *   $meta = $lib->get_meta();             // mime / dims / bytes / has_alpha / is_animated
 *   $lib->resize( 2560 );                 // proportional, no-op if already smaller
 *   $tmp  = $lib->encode( 'image/webp', 80, true, $tmp_path );
 *   $lib->close();
 *
 * @since 0.1.0
 */
class Image_Library {

	/**
	 * Path to the source image on disk.
	 *
	 * @var string
	 */
	private string $source_path;

	/**
	 * Lazy-loaded WP_Image_Editor instance (Imagick or GD backend).
	 *
	 * Stored as object|null; null means not yet loaded. A WP_Error encountered
	 * during instantiation is recorded in $last_error.
	 *
	 * @var object|null
	 */
	private ?object $editor = null;

	/**
	 * Memoised metadata (populated by get_meta()).
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $meta = null;

	/**
	 * Last error encountered. Cleared at the start of each public call.
	 *
	 * @var \WP_Error|null
	 */
	private ?\WP_Error $last_error = null;

	/**
	 * Capability detector (injected for testability).
	 *
	 * @var Capabilities
	 */
	private Capabilities $caps;

	/**
	 * Constructor.
	 *
	 * Does NOT load the image — loading is deferred until the first call
	 * that needs it. This lets get_meta() do cheap header inspection
	 * without paying the full decode cost.
	 *
	 * @since 0.1.0
	 *
	 * @param string            $source_path Absolute path to the source image.
	 * @param Capabilities|null $caps        Optional capability detector.
	 */
	public function __construct( string $source_path, ?Capabilities $caps = null ) {
		$this->source_path = $source_path;
		$this->caps        = $caps ?? new Capabilities();
	}

	/**
	 * Get the most recent error, if any.
	 *
	 * @since 0.1.0
	 *
	 * @return \WP_Error|null
	 */
	public function get_last_error(): ?\WP_Error {
		return $this->last_error;
	}

	/**
	 * Probe metadata without performing a full decode.
	 *
	 * Returned shape:
	 *   array(
	 *     'mime'        => string,  // e.g. 'image/png'
	 *     'width'       => int,
	 *     'height'      => int,
	 *     'bytes'       => int,
	 *     'has_alpha'   => bool,
	 *     'is_animated' => bool,
	 *   )
	 *
	 * Returns an empty array on failure; check get_last_error().
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_meta(): array {
		$this->last_error = null;

		if ( is_null( $this->meta ) ) {
			if ( ! is_readable( $this->source_path ) ) {
				$this->last_error = new \WP_Error(
					'tri_unreadable_source',
					sprintf(
						/* translators: %s: file path */
						__( 'Source image is not readable: %s', 'tidy-resize-images' ),
						$this->source_path
					)
				);
				$this->meta = array();
			} else {
				$size = wp_getimagesize( $this->source_path );

				if ( false === $size ) {
					$this->last_error = new \WP_Error(
						'tri_invalid_image',
						sprintf(
							/* translators: %s: file path */
							__( 'File is not a recognised image: %s', 'tidy-resize-images' ),
							$this->source_path
						)
					);
					$this->meta = array();
				} else {
					$mime  = $size['mime'] ?? '';
					$bytes = filesize( $this->source_path );

					$this->meta = array(
						'mime'        => $mime,
						'width'       => (int) ( $size[0] ?? 0 ),
						'height'      => (int) ( $size[1] ?? 0 ),
						'bytes'       => is_int( $bytes ) ? $bytes : 0,
						'has_alpha'   => $this->detect_alpha( $mime ),
						'is_animated' => $this->detect_animated( $mime ),
					);
				}
			}
		}

		return $this->meta;
	}

	/**
	 * Resize the image so its longest edge does not exceed $max_edge.
	 *
	 * Proportional. No-op (returns true) if the image is already within
	 * the limit. The transform is held in memory and persisted only when
	 * encode() is called.
	 *
	 * @since 0.1.0
	 *
	 * @param int $max_edge Maximum longest-edge dimension in pixels.
	 *
	 * @return bool True on success (including no-op), false on failure.
	 */
	public function resize( int $max_edge ): bool {
		$this->last_error = null;
		$success          = false;

		$meta   = $this->get_meta();
		$editor = $this->get_editor();

		if ( ! empty( $meta ) && ! is_null( $editor ) ) {
			$longest = max( $meta['width'], $meta['height'] );

			if ( $longest <= $max_edge ) {
				$success = true;
			} else {
				$target_w = $meta['width'];
				$target_h = $meta['height'];

				if ( $meta['width'] >= $meta['height'] ) {
					$target_w = $max_edge;
					$target_h = 0; // 0 = preserve aspect ratio.
				} else {
					$target_w = 0;
					$target_h = $max_edge;
				}

				$result = $editor->resize( $target_w, $target_h, false );

				if ( is_wp_error( $result ) ) {
					$this->last_error = $result;
				} else {
					$success = true;
				}
			}
		}

		return $success;
	}

	/**
	 * Resize to exact target dimensions, hard-cropping from centre.
	 *
	 * Used by Image_Processor::execute_derivative() when regenerating
	 * orphan thumbnail sizes — old metadata records dimensions but not
	 * crop offset, so we mirror WP's own regenerate behaviour and crop
	 * from centre.
	 *
	 * Returns true on success (including no-op when already at the target
	 * dimensions), false on failure (`get_last_error()` for details).
	 *
	 * @since 0.5.0
	 *
	 * @param int $width  Target width in px.
	 * @param int $height Target height in px.
	 *
	 * @return bool
	 */
	public function resize_to_dims( int $width, int $height ): bool {
		$this->last_error = null;
		$success          = false;

		$meta   = $this->get_meta();
		$editor = $this->get_editor();

		if ( ! empty( $meta ) && ! is_null( $editor ) ) {
			if ( $meta['width'] === $width && $meta['height'] === $height ) {
				$success = true;
			} else {
				$result = $editor->resize( $width, $height, true );

				if ( is_wp_error( $result ) ) {
					$this->last_error = $result;
				} else {
					$success = true;
				}
			}
		}

		return $success;
	}

	/**
	 * Encode the (possibly resized) image to a target MIME at a given quality.
	 *
	 * Writes to $tmp_path. Returns the path on success, empty string on
	 * failure (check get_last_error()). When $strip_exif is true and
	 * Imagick is the backend, EXIF/XMP/IPTC metadata is removed from the
	 * encoded file in a second pass.
	 *
	 * @since 0.1.0
	 *
	 * @param string $target_mime e.g. 'image/webp'.
	 * @param int    $quality     1-100 (encoder-specific interpretation).
	 * @param bool   $strip_exif  Whether to strip EXIF/XMP metadata after encode.
	 * @param string $tmp_path    Absolute destination path.
	 *
	 * @return string Path written, or empty string on failure.
	 */
	public function encode( string $target_mime, int $quality, bool $strip_exif, string $tmp_path ): string {
		$this->last_error = null;
		$written          = '';

		$editor = $this->get_editor();

		if ( ! is_null( $editor ) ) {
			$editor->set_quality( $quality );

			$result = $editor->save( $tmp_path, $target_mime );

			if ( is_wp_error( $result ) ) {
				$this->last_error = $result;
			} elseif ( ! is_array( $result ) || empty( $result['path'] ) ) {
				$this->last_error = new \WP_Error(
					'tri_encode_failed',
					__( 'Image editor returned no path.', 'tidy-resize-images' )
				);
			} else {
				$written = (string) $result['path'];

				if ( $strip_exif && ! $this->strip_metadata_from_file( $written, $target_mime ) ) {
					// Strip failed but encode succeeded — keep the file, surface the warning.
					$this->last_error = new \WP_Error(
						'tri_strip_exif_failed',
						__( 'Encoded successfully but EXIF strip failed.', 'tidy-resize-images' ),
						array( 'path' => $written )
					);
				}
			}
		}

		return $written;
	}

	/**
	 * Release any held image resources.
	 *
	 * Safe to call multiple times.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function close(): void {
		$this->editor = null;
		$this->meta   = null;
	}

	/**
	 * Get (or lazily instantiate) the WP_Image_Editor for the source file.
	 *
	 * Records any error in $last_error and returns null on failure.
	 *
	 * @since 0.1.0
	 *
	 * @return object|null WP_Image_Editor subclass instance, or null on failure.
	 */
	private function get_editor(): ?object {
		if ( is_null( $this->editor ) ) {
			$candidate = wp_get_image_editor( $this->source_path );

			if ( is_wp_error( $candidate ) ) {
				$this->last_error = $candidate;
			} else {
				$this->editor = $candidate;
			}
		}

		return $this->editor;
	}

	/**
	 * Detect whether the source image carries a meaningful alpha channel.
	 *
	 * Strategy:
	 * - JPEG and (most) GIF: never has alpha → false.
	 * - PNG: read the IHDR colour-type byte (4 = greyscale+alpha, 6 = RGB+alpha).
	 * - WebP: read the VP8X header alpha bit.
	 * - Anything else: defer to Imagick if available; otherwise false.
	 *
	 * File-header parsing is intentionally cheap — we don't pay the full
	 * decode cost just to answer this question.
	 *
	 * @since 0.1.0
	 *
	 * @param string $mime MIME type of the source.
	 *
	 * @return bool
	 */
	private function detect_alpha( string $mime ): bool {
		$result = false;

		switch ( $mime ) {
			case MIME_JPEG:
				$result = false;
				break;
			case MIME_PNG:
				$result = $this->png_has_alpha( $this->source_path );
				break;
			case MIME_WEBP:
				$result = $this->webp_has_alpha( $this->source_path );
				break;
			case MIME_AVIF:
			case MIME_HEIC:
				$result = $this->imagick_has_alpha( $this->source_path );
				break;
			case MIME_GIF:
				// Static GIFs may have 1-bit transparency; treat as alpha for safety.
				$result = $this->gif_has_transparency( $this->source_path );
				break;
			default:
				$result = false;
		}

		return $result;
	}

	/**
	 * Detect whether the source is a multi-frame image (animated GIF / WebP).
	 *
	 * @since 0.1.0
	 *
	 * @param string $mime MIME type of the source.
	 *
	 * @return bool
	 */
	private function detect_animated( string $mime ): bool {
		$result = false;

		switch ( $mime ) {
			case MIME_GIF:
				$result = $this->gif_is_animated( $this->source_path );
				break;
			case MIME_WEBP:
				$result = $this->webp_is_animated( $this->source_path );
				break;
			default:
				$result = false;
		}

		return $result;
	}

	/**
	 * Check the PNG IHDR chunk for an alpha-bearing colour type.
	 *
	 * IHDR layout: signature(8) + length(4) + 'IHDR'(4) + width(4) + height(4)
	 *            + bit_depth(1) + colour_type(1).
	 *
	 * Colour types 4 and 6 carry an alpha channel.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Path to the PNG file.
	 *
	 * @return bool
	 */
	private function png_has_alpha( string $path ): bool {
		$result = false;
		$handle = @fopen( $path, 'rb' );

		if ( false !== $handle ) {
			$header = fread( $handle, 26 );
			fclose( $handle );

			if ( false !== $header && strlen( $header ) >= 26 ) {
				$colour_type = ord( $header[25] );
				$result      = ( 4 === $colour_type || 6 === $colour_type );
			}
		}

		return $result;
	}

	/**
	 * Check the WebP VP8X header for the alpha bit.
	 *
	 * Returns false for VP8 (lossy without alpha) and VP8L (lossless;
	 * always carries alpha but small files).
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Path to the WebP file.
	 *
	 * @return bool
	 */
	private function webp_has_alpha( string $path ): bool {
		$result = false;
		$handle = @fopen( $path, 'rb' );

		if ( false !== $handle ) {
			$header = fread( $handle, 30 );
			fclose( $handle );

			if ( false !== $header && strlen( $header ) >= 30 ) {
				$chunk = substr( $header, 12, 4 );

				if ( 'VP8X' === $chunk ) {
					$flags  = ord( $header[20] );
					$result = (bool) ( $flags & 0x10 );
				} elseif ( 'VP8L' === $chunk ) {
					$result = true;
				}
			}
		}

		return $result;
	}

	/**
	 * Check whether an animated GIF has more than one frame.
	 *
	 * Counts image descriptor blocks (0x2C) up to a small cap. Cheap.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Path to the GIF file.
	 *
	 * @return bool
	 */
	private function gif_is_animated( string $path ): bool {
		$result   = false;
		$contents = @file_get_contents( $path );

		if ( false !== $contents ) {
			$frames = 0;
			$length = strlen( $contents );

			for ( $i = 0; $i < $length - 1 && $frames < 2; $i++ ) {
				if ( 0x21 === ord( $contents[ $i ] ) && 0xF9 === ord( $contents[ $i + 1 ] ) ) {
					++$frames;
				}
			}

			$result = $frames >= 2;
		}

		return $result;
	}

	/**
	 * Check whether a static GIF has transparency declared.
	 *
	 * Reads the Logical Screen Descriptor packed-fields byte for the
	 * Global Colour Table flag, then inspects subsequent Graphic Control
	 * Extension blocks for the transparency flag.
	 *
	 * Approximate — false positives are acceptable since the format
	 * decision tree treats GIFs cautiously regardless.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Path to the GIF file.
	 *
	 * @return bool
	 */
	private function gif_has_transparency( string $path ): bool {
		$result   = false;
		$contents = @file_get_contents( $path );

		if ( false !== $contents ) {
			$length = strlen( $contents );

			for ( $i = 0; $i < $length - 3 && ! $result; $i++ ) {
				if ( 0x21 === ord( $contents[ $i ] )
					&& 0xF9 === ord( $contents[ $i + 1 ] )
					&& 0x04 === ord( $contents[ $i + 2 ] )
				) {
					$packed = ord( $contents[ $i + 3 ] );
					$result = (bool) ( $packed & 0x01 );
				}
			}
		}

		return $result;
	}

	/**
	 * Check whether a WebP file is animated (VP8X header anim bit).
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Path to the WebP file.
	 *
	 * @return bool
	 */
	private function webp_is_animated( string $path ): bool {
		$result = false;
		$handle = @fopen( $path, 'rb' );

		if ( false !== $handle ) {
			$header = fread( $handle, 30 );
			fclose( $handle );

			if ( false !== $header && strlen( $header ) >= 30 ) {
				$chunk = substr( $header, 12, 4 );

				if ( 'VP8X' === $chunk ) {
					$flags  = ord( $header[20] );
					$result = (bool) ( $flags & 0x02 );
				}
			}
		}

		return $result;
	}

	/**
	 * Use Imagick to detect alpha when format-specific parsers don't fit
	 * (currently AVIF and HEIC).
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Path to the image file.
	 *
	 * @return bool
	 */
	private function imagick_has_alpha( string $path ): bool {
		$result = false;

		if ( $this->caps->has_imagick() ) {
			try {
				$im = new \Imagick();
				$im->pingImage( $path );
				$result = (bool) $im->getImageAlphaChannel();
				$im->clear();
			} catch ( \ImagickException $e ) {
				$result = false;
			}
		}

		return $result;
	}

	/**
	 * Strip EXIF / XMP / IPTC metadata from a saved file in place.
	 *
	 * Imagick path: open, stripImage(), write back, clear. Imagick is the only
	 * backend where this is needed — GD does not preserve metadata across
	 * encode, so a GD-saved file is already stripped.
	 *
	 * SVG (and any other vector format that might appear) is excluded —
	 * stripping XML metadata is a different problem and out of scope for v1.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Path to the file to strip.
	 * @param string $mime MIME type of the file.
	 *
	 * @return bool True on success (including no-op when GD is the backend).
	 */
	private function strip_metadata_from_file( string $path, string $mime ): bool {
		$success = true;

		if ( MIME_SVG !== $mime && $this->caps->has_imagick() && $this->caps->imagick_supports( $mime ) ) {
			try {
				$im = new \Imagick( $path );
				$im->stripImage();
				$im->writeImage( $path );
				$im->clear();
			} catch ( \ImagickException $e ) {
				$success = false;
			}
		}

		return $success;
	}
}
