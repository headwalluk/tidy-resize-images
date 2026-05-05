<?php
/**
 * `wp tidy-images trash` — list and purge backup records.
 *
 * @package Tidy_Resize_Images
 */

namespace Tidy_Resize_Images;

defined( 'ABSPATH' ) || die();

/**
 * `wp tidy-images trash` — list and purge backup records.
 *
 * @since 0.5.0
 */
class CLI_Trash {

	/**
	 * List attachments that currently have a trash backup record.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Max records to return.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--offset=<offset>]
	 * : Pagination offset.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 *   - ids
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp tidy-images trash list
	 *     wp tidy-images trash list --format=ids
	 *     wp tidy-images trash list --limit=200 --format=json
	 *
	 * @subcommand list
	 * @when after_wp_load
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args.
	 *
	 * @return void
	 */
	public function list_( array $args, array $assoc_args ): void {
		unset( $args );

		$limit  = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 50;
		$offset = isset( $assoc_args['offset'] ) ? max( 0, (int) $assoc_args['offset'] ) : 0;
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';

		if ( 'count' === $format ) {
			\WP_CLI::log( (string) Trash_Manager::count_trashed() );
			return;
		}

		$ids = Trash_Manager::list_trashed( $limit, $offset );

		if ( 'ids' === $format ) {
			\WP_CLI::log( implode( ' ', array_map( 'strval', $ids ) ) );
			return;
		}

		$rows = array();

		foreach ( $ids as $id ) {
			$backup = Trash_Manager::get_backup( $id );

			if ( is_null( $backup ) ) {
				continue;
			}

			$rows[] = array(
				'id'               => $id,
				'title'            => (string) get_the_title( $id ),
				'orig_basename'    => (string) ( $backup['orig_basename'] ?? '' ),
				'mime'             => (string) ( $backup['mime'] ?? '' ),
				'bytes'            => (int) ( $backup['bytes'] ?? 0 ),
				'trashed_at'       => (string) ( $backup['trashed_at'] ?? '' ),
				'filename_changed' => ! empty( $backup['filename_changed'] ) ? 'yes' : 'no',
			);
		}

		\WP_CLI\Utils\format_items(
			$format,
			$rows,
			array( 'id', 'title', 'orig_basename', 'mime', 'bytes', 'trashed_at', 'filename_changed' )
		);
	}

	/**
	 * Purge one (or all) trash backup records.
	 *
	 * Single-id purges run unprompted — destructive ops are deliberate by
	 * design here. `--all` requires `--yes` to avoid accidental nukes.
	 *
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : Attachment ID to purge.
	 *
	 * [--all]
	 * : Purge every trash backup record.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt for --all.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tidy-images trash purge 1234
	 *     wp tidy-images trash purge --all --yes
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Associative args.
	 *
	 * @return void
	 */
	public function purge( array $args, array $assoc_args ): void {
		$all = ! empty( $assoc_args['all'] );
		$id  = isset( $args[0] ) ? (int) $args[0] : 0;

		if ( $all && $id > 0 ) {
			\WP_CLI::error( 'Pass either an attachment ID or --all, not both.' );
		}

		if ( ! $all && $id <= 0 ) {
			\WP_CLI::error( 'Provide an attachment ID, or use --all.' );
		}

		if ( $all ) {
			\WP_CLI::confirm( 'Purge every trash backup record?', $assoc_args );
			$this->purge_all();
			return;
		}

		if ( is_null( Trash_Manager::get_backup( $id ) ) ) {
			\WP_CLI::error( sprintf( 'Attachment %d has no backup record.', $id ) );
		}

		Trash_Manager::purge( $id );
		\WP_CLI::success( sprintf( 'Purged backup for attachment %d.', $id ) );
	}

	/**
	 * Purge every trash backup record by paging through list_trashed().
	 *
	 * Uses a fresh listing on each iteration because purging removes the
	 * meta row each list_trashed() query is filtering on.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	private function purge_all(): void {
		$batch  = 100;
		$purged = 0;

		while ( true ) {
			$ids = Trash_Manager::list_trashed( $batch, 0 );

			if ( empty( $ids ) ) {
				break;
			}

			foreach ( $ids as $id ) {
				Trash_Manager::purge( $id );
				++$purged;
			}
		}

		\WP_CLI::success( sprintf( 'Purged %d backup record(s).', $purged ) );
	}
}
