<?php
/**
 * Per-attachment "we already tried, don't retry" memoisation.
 *
 * @package Tidy_Resize_Images
 */

namespace Tidy_Resize_Images;

defined( 'ABSPATH' ) || die();

/**
 * Skip memo.
 *
 * When Image_Processor::execute() discards a re-encoded result because it
 * grew larger than the source (and no dimension change occurred), we record
 * a skip memo on the attachment. Subsequent processing runs consult the
 * memo and short-circuit before re-doing the work.
 *
 * The memo is invalidated automatically when the operator changes any
 * setting that affects output bytes — comparison is by settings hash, not
 * by timestamp, so memos are deterministic with respect to configuration.
 *
 * Memo shape (stored as a serialised array under META_CONVERSION_SKIPPED):
 *
 *   array(
 *     'reason'           => 'result_larger_than_source',
 *     'attempted_target' => 'image/webp',
 *     'attempted_at'     => 'Y-m-d H:i:s T',
 *     'settings_hash'    => sha1(...),
 *   )
 *
 * Static rather than injected — there is no instance state to carry.
 *
 * @since 0.1.0
 */
class Skip_Memo {

	/**
	 * Get the memo for an attachment, or null if none.
	 *
	 * @since 0.1.0
	 *
	 * @param int $attachment_id Attachment post ID.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( int $attachment_id ): ?array {
		$raw = get_post_meta( $attachment_id, META_CONVERSION_SKIPPED, true );

		return is_array( $raw ) ? $raw : null;
	}

	/**
	 * Whether we should skip processing this attachment under the current
	 * settings hash.
	 *
	 * Returns true only when a memo exists AND its recorded settings hash
	 * matches the current one — any settings change invalidates and we
	 * re-attempt.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $current_hash  Settings hash for the current ruleset.
	 *
	 * @return bool
	 */
	public static function should_skip( int $attachment_id, string $current_hash ): bool {
		$memo = self::get( $attachment_id );

		return ! is_null( $memo )
			&& isset( $memo['settings_hash'] )
			&& $memo['settings_hash'] === $current_hash;
	}

	/**
	 * Record a skip after a `result_larger_than_source` discard.
	 *
	 * Overwrites any existing memo (which is correct — newer attempt
	 * with newer settings supersedes the old marker).
	 *
	 * @since 0.1.0
	 *
	 * @param int    $attachment_id    Attachment post ID.
	 * @param string $attempted_target MIME we tried to convert to (e.g. 'image/webp').
	 * @param string $current_hash     Settings hash for the ruleset that was in effect.
	 *
	 * @return void
	 */
	public static function record( int $attachment_id, string $attempted_target, string $current_hash ): void {
		update_post_meta(
			$attachment_id,
			META_CONVERSION_SKIPPED,
			array(
				'reason'           => 'result_larger_than_source',
				'attempted_target' => $attempted_target,
				'attempted_at'     => self::now_formatted(),
				'settings_hash'    => $current_hash,
			)
		);
	}

	/**
	 * Clear any recorded memo for an attachment.
	 *
	 * Settings-change invalidation is automatic via hash comparison, so
	 * this is rarely needed. Useful for explicit "reset and re-process"
	 * operator actions.
	 *
	 * @since 0.1.0
	 *
	 * @param int $attachment_id Attachment post ID.
	 *
	 * @return void
	 */
	public static function clear( int $attachment_id ): void {
		delete_post_meta( $attachment_id, META_CONVERSION_SKIPPED );
	}

	/**
	 * Current time as a human-readable string with timezone.
	 *
	 * Per house style we store dates as readable strings, not Unix
	 * timestamps — easier to debug and self-documenting in the database.
	 *
	 * @since 0.1.0
	 *
	 * @return string e.g. '2026-05-03 14:32:11 UTC'.
	 */
	private static function now_formatted(): string {
		$now = new \DateTime( 'now', wp_timezone() );

		return $now->format( 'Y-m-d H:i:s T' );
	}
}
