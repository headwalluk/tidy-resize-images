<?php
/**
 * Upload-time integration: hook the WordPress upload pipeline so new
 * attachments are processed at arrival.
 *
 * @package Tidy_Resize_Images
 */

namespace Tidy_Resize_Images;

defined( 'ABSPATH' ) || die();

/**
 * Upload Handler.
 *
 * Two integration points:
 *
 * - `big_image_size_threshold` — adjust WordPress's built-in scaled-rotation
 *   limit so it honours our `max_edge` setting when ours is lower than
 *   WP's default (2560).
 * - `wp_generate_attachment_metadata` — after WP saves the upload and
 *   builds intermediate sizes, run our processor: back up the original,
 *   resize / convert / recompress as required, replace the source file,
 *   regenerate intermediate sizes for the new file, and mark the
 *   attachment as processed.
 *
 * **Scope note (per project workflow priority):** upload-time processing
 * is a lower-priority safety net. The headline workflow is the bulk
 * processor (M7) running interactively or via a scheduled cron. This
 * class implements the happy path cleanly but does not gold-plate —
 * no per-context overrides, no upload-time dry-run UI, no progress
 * surface.
 *
 * @since 0.1.0
 */
class Upload_Handler {

	/**
	 * Register the upload-pipeline hooks.
	 *
	 * Called from `Plugin::run()` outside the is_admin() branch — uploads
	 * can originate from front-end forms (e.g. WooCommerce product images
	 * uploaded via REST or other plugins) so we must register globally.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'big_image_size_threshold', array( $this, 'filter_big_image_threshold' ), 10, 1 );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'process_after_metadata' ), 10, 3 );
	}

	/**
	 * Lower WordPress's `big_image_size_threshold` to our `max_edge` when
	 * ours is the more restrictive value.
	 *
	 * Returning a smaller value tells WP to scale-rotate the original at
	 * our limit during `wp_create_image_subsizes()`, which is cheaper
	 * than us re-encoding a full-resolution intermediate later.
	 *
	 * @since 0.1.0
	 *
	 * @param int $threshold Default WP threshold (2560).
	 *
	 * @return int
	 */
	public function filter_big_image_threshold( $threshold ): int {
		$threshold = is_int( $threshold ) ? $threshold : (int) $threshold;
		$rules     = Image_Processor::from_settings();
		$max_edge  = (int) ( $rules['max_edge'] ?? $threshold );

		return min( $threshold, $max_edge );
	}

	/**
	 * Run our processing pipeline after WP has generated metadata for an
	 * uploaded attachment.
	 *
	 * Implementation lands in M5.2 — for M5.1 this is a stub that
	 * passes the metadata through unchanged so the filter is in place
	 * and can be wired without M5.2 being merged.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $metadata      Generated attachment metadata.
	 * @param int                  $attachment_id Attachment post ID.
	 * @param string               $context       Either 'create' or 'update'.
	 *
	 * @return array<string, mixed>
	 */
	public function process_after_metadata( $metadata, $attachment_id, $context = '' ): array {
		unset( $attachment_id, $context );

		return is_array( $metadata ) ? $metadata : array();
	}
}
