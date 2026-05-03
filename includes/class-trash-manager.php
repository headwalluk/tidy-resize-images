<?php
/**
 * Trash Manager: original-file backup, restore, and purge.
 *
 * @package Tidy_Resize_Images
 */

namespace Tidy_Resize_Images;

defined( 'ABSPATH' ) || die();

/**
 * Trash Manager.
 *
 * When the image processor (M2) decides to mutate an attachment, the
 * caller (upload handler in M5, bulk processor in M7) must first call
 * `Trash_Manager::backup()` to preserve the original file. Restoration
 * later reverses the file-level changes.
 *
 * Trash directory layout:
 *
 *   wp-content/uploads/tri-trash/{year}/{month}/{attachment_id}-{timestamp}-{basename}
 *
 * The {attachment_id}-{timestamp} prefix ensures uniqueness without
 * hashing — same attachment can be backed up again later (after a
 * `purge()`) without filename collision.
 *
 * Per-attachment meta key `_tri_backup` carries the restore record.
 *
 * Caveat: the file-level restore implemented here puts the original
 * file back in place and rebuilds attachment metadata. If the
 * processor renamed the file during conversion (e.g. logo.png →
 * logo.webp), the DB content (post_content, postmeta) may still hold
 * URLs pointing to the renamed file. The DB-side rewriting belongs to
 * Search_Replace (M6). Trash_Manager surfaces this via the
 * `filename_changed` flag in the backup record so the Trash admin
 * page (M4.3) can warn before restoring.
 *
 * @since 0.1.0
 */
class Trash_Manager {

	/**
	 * Sub-directory name under wp-content/uploads/ for trashed originals.
	 */
	private const TRASH_DIRNAME = 'tri-trash';

	/**
	 * Back up an attachment's current file to the trash directory.
	 *
	 * Idempotent: if a backup already exists for this attachment, the
	 * call is a no-op (returns true). Use `purge()` first if you want
	 * to overwrite the existing backup.
	 *
	 * @since 0.1.0
	 *
	 * @param int $attachment_id Attachment post ID.
	 *
	 * @return bool True on success (including idempotent no-op), false on hard failure.
	 */
	public static function backup( int $attachment_id ): bool {
		$success = false;

		if ( ! is_null( self::get_backup( $attachment_id ) ) ) {
			// Already backed up — idempotent success.
			$success = true;
		} else {
			$current_file = (string) get_attached_file( $attachment_id );

			if ( '' !== $current_file && file_exists( $current_file ) ) {
				$basename   = basename( $current_file );
				$trash_path = self::trash_path( $attachment_id, $basename );
				$trash_dir  = dirname( $trash_path );

				wp_mkdir_p( $trash_dir );

				if ( @copy( $current_file, $trash_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- copy() failure surfaces via the false-return; we record the error path below.
					$size      = @getimagesize( $current_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- nullable result; non-image attachments would simply produce zeros.
					$bytes_raw = filesize( $current_file );
					$bytes     = is_int( $bytes_raw ) ? $bytes_raw : 0;

					update_post_meta(
						$attachment_id,
						META_BACKUP,
						array(
							'path'             => $trash_path,
							'orig_path'        => $current_file,
							'orig_basename'    => $basename,
							'mime'             => is_array( $size ) ? ( $size['mime'] ?? '' ) : '',
							'bytes'            => $bytes,
							'width'            => is_array( $size ) ? (int) ( $size[0] ?? 0 ) : 0,
							'height'           => is_array( $size ) ? (int) ( $size[1] ?? 0 ) : 0,
							'trashed_at'       => self::now_formatted(),
							'filename_changed' => false,
						)
					);

					$success = true;
				}
			}
		}

		return $success;
	}

	/**
	 * Restore an attachment to its backed-up state.
	 *
	 * Steps:
	 * 1. Read the backup meta — return false if none.
	 * 2. Verify the backup file exists on disk.
	 * 3. Delete the current file plus any intermediate sizes.
	 * 4. Move the backup file back to its original path.
	 * 5. Update `_wp_attached_file` and rebuild `_wp_attachment_metadata`
	 *    (which also regenerates intermediate sizes for the original
	 *    format).
	 * 6. Clear the `_tri_backup` meta.
	 *
	 * Caveat: DB content references (post_content, postmeta) are NOT
	 * rewritten here — that's Search_Replace's job (M6).
	 *
	 * @since 0.1.0
	 *
	 * @param int $attachment_id Attachment post ID.
	 *
	 * @return bool True on success.
	 */
	public static function restore( int $attachment_id ): bool {
		$success = false;
		$backup  = self::get_backup( $attachment_id );

		if ( ! is_null( $backup ) ) {
			$trash_path       = (string) ( $backup['path'] ?? '' );
			$orig_path        = (string) ( $backup['orig_path'] ?? '' );
			$filename_changed = ! empty( $backup['filename_changed'] );

			if ( '' !== $trash_path && '' !== $orig_path && file_exists( $trash_path ) ) {
				// Capture metadata BEFORE we delete the converted file —
				// needed by Search_Replace below to compute the reverse
				// rename pairs (current converted URLs → restored URLs).
				$pre_restore_meta = $filename_changed ? wp_get_attachment_metadata( $attachment_id ) : null;

				self::delete_current_image_files( $attachment_id );

				wp_mkdir_p( dirname( $orig_path ) );

				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- Both files are inside wp-content/uploads/; WP_Filesystem is for plugin-managed files requiring credentialed access. rename() failure handled via the false branch below.
				if ( @rename( $trash_path, $orig_path ) ) {
					update_attached_file( $attachment_id, $orig_path );

					if ( ! function_exists( 'wp_create_image_subsizes' ) ) {
						require_once ABSPATH . 'wp-admin/includes/image.php';
					}

					// Use wp_create_image_subsizes (not wp_generate_attachment_metadata)
					// so the wp_generate_attachment_metadata filter does not fire.
					// Otherwise Upload_Handler's filter would treat the just-restored
					// file as a fresh upload and immediately re-process it.
					$metadata = wp_create_image_subsizes( $orig_path, $attachment_id );

					if ( is_array( $metadata ) ) {
						wp_update_attachment_metadata( $attachment_id, $metadata );
					}

					if ( ! empty( $backup['mime'] ) ) {
						wp_update_post(
							array(
								'ID'             => $attachment_id,
								'post_mime_type' => (string) $backup['mime'],
							)
						);
					}

					// Reverse the processor's URL rewrites when the file was renamed.
					// "Old" here is the converted state we just deleted, "New" is the
					// restored state — Search_Replace::rewrite_attachment_rename
					// derives the right pairs from the metadata diff.
					if ( $filename_changed && is_array( $pre_restore_meta ) && is_array( $metadata ) ) {
						$scope = get_plugin()->get_settings()->sr_scope();
						$sr    = new Search_Replace();
						$sr->rewrite_attachment_rename( $attachment_id, $pre_restore_meta, $metadata, $scope, false );
					}

					delete_post_meta( $attachment_id, META_BACKUP );
					$success = true;
				}
			}
		}

		return $success;
	}

	/**
	 * Purge an attachment's backup — delete the trash file and clear meta.
	 *
	 * Returns true even if there was nothing to purge (no backup recorded
	 * or file already missing) — purge is idempotent.
	 *
	 * @since 0.1.0
	 *
	 * @param int $attachment_id Attachment post ID.
	 *
	 * @return bool
	 */
	public static function purge( int $attachment_id ): bool {
		$backup = self::get_backup( $attachment_id );

		if ( ! is_null( $backup ) ) {
			$trash_path = (string) ( $backup['path'] ?? '' );

			if ( '' !== $trash_path && file_exists( $trash_path ) ) {
				wp_delete_file( $trash_path );
			}

			delete_post_meta( $attachment_id, META_BACKUP );
		}

		return true;
	}

	/**
	 * Get the backup record for an attachment.
	 *
	 * @since 0.1.0
	 *
	 * @param int $attachment_id Attachment post ID.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_backup( int $attachment_id ): ?array {
		$raw = get_post_meta( $attachment_id, META_BACKUP, true );

		return is_array( $raw ) && ! empty( $raw ) ? $raw : null;
	}

	/**
	 * List attachment IDs that currently have a trash backup.
	 *
	 * Sorted by attachment post date descending — newest uploads first.
	 * Sorting by trash time is not done here because `trashed_at` lives
	 * inside a serialised meta array, which can't be sorted cheaply via
	 * MySQL. If chronological-by-trash sorting becomes important later,
	 * add a scalar `_tri_backup_trashed_at` meta and sort on that.
	 *
	 * @since 0.1.0
	 *
	 * @param int $limit  Max attachments to return (positive int).
	 * @param int $offset Offset for pagination (zero or positive).
	 *
	 * @return array<int> Attachment IDs.
	 */
	public static function list_trashed( int $limit = 20, int $offset = 0 ): array {
		$query = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => max( 1, $limit ),
				'offset'         => max( 0, $offset ),
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'meta_query'     => array(  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => META_BACKUP,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		return array_map( 'intval', (array) $query->posts );
	}

	/**
	 * Count attachments that currently have a trash backup.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public static function count_trashed(): int {
		$query = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_query'     => array(  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => META_BACKUP,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Compute the trash path for a given attachment + original basename.
	 *
	 * Uses GMT for the year/month bucket so timezone changes don't
	 * fragment the trash directory layout. The {id}-{timestamp} prefix
	 * makes the path unique across re-backups of the same attachment.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $basename      Original file basename.
	 *
	 * @return string Absolute path under the trash directory.
	 */
	private static function trash_path( int $attachment_id, string $basename ): string {
		$upload_dir = wp_upload_dir();
		$now        = time();
		$year       = gmdate( 'Y', $now );
		$month      = gmdate( 'm', $now );

		return trailingslashit( $upload_dir['basedir'] )
			. self::TRASH_DIRNAME . '/' . $year . '/' . $month . '/'
			. $attachment_id . '-' . $now . '-' . $basename;
	}

	/**
	 * Delete the current attachment file and all of its intermediate sizes.
	 *
	 * Reads `_wp_attachment_metadata` to find the sub-size filenames; also
	 * deletes the WP-core `original_image` (the unscaled rotation kept by
	 * `big_image_size_threshold`) when present.
	 *
	 * Safe to call on attachments that have no current file or no metadata
	 * — operations on missing files are silently skipped.
	 *
	 * @since 0.1.0
	 *
	 * @param int $attachment_id Attachment post ID.
	 *
	 * @return void
	 */
	private static function delete_current_image_files( int $attachment_id ): void {
		$current_file = (string) get_attached_file( $attachment_id );
		$metadata     = wp_get_attachment_metadata( $attachment_id );

		if ( '' !== $current_file && file_exists( $current_file ) ) {
			wp_delete_file( $current_file );

			$base_dir = trailingslashit( dirname( $current_file ) );

			if ( is_array( $metadata ) ) {
				if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
					foreach ( $metadata['sizes'] as $size ) {
						if ( ! empty( $size['file'] ) ) {
							$size_file = $base_dir . $size['file'];

							if ( file_exists( $size_file ) ) {
								wp_delete_file( $size_file );
							}
						}
					}
				}

				if ( ! empty( $metadata['original_image'] ) ) {
					$orig_image = $base_dir . $metadata['original_image'];

					if ( file_exists( $orig_image ) ) {
						wp_delete_file( $orig_image );
					}
				}
			}
		}
	}

	/**
	 * Current time as a human-readable string with timezone, per house style.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	private static function now_formatted(): string {
		$now = new \DateTime( 'now', wp_timezone() );

		return $now->format( 'Y-m-d H:i:s T' );
	}
}
