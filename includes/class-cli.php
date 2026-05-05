<?php
/**
 * WP-CLI commands.
 *
 * Wraps the M2-M8 service surface so the operator can drive the plugin
 * from the command line. Three command namespaces are registered:
 *
 *   wp tidy-images <subcommand>           — top-level: caps, scan,
 *                                           process, protect, unprotect,
 *                                           restore.
 *   wp tidy-images trash <subcommand>     — list, purge.
 *   wp tidy-images settings <subcommand>  — get, set.
 *
 * @package Tidy_Resize_Images
 */

namespace Tidy_Resize_Images;

defined( 'ABSPATH' ) || die();

/**
 * Top-level `wp tidy-images` command.
 *
 * @since 0.5.0
 */
class CLI {

	/**
	 * Register every CLI command namespace with WP-CLI.
	 *
	 * Called from Plugin::run() inside a `defined( 'WP_CLI' )` guard so the
	 * registration cost is zero on web requests.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public static function register(): void {
		\WP_CLI::add_command( 'tidy-images', __CLASS__ );
		\WP_CLI::add_command( 'tidy-images trash', __NAMESPACE__ . '\\CLI_Trash' );
		\WP_CLI::add_command( 'tidy-images settings', __NAMESPACE__ . '\\CLI_Settings' );
	}

	/**
	 * Show detected GD / Imagick capabilities for image processing.
	 *
	 * ## OPTIONS
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
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp tidy-images caps
	 *     wp tidy-images caps --format=json
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args.
	 *
	 * @return void
	 */
	public function caps( array $args, array $assoc_args ): void {
		unset( $args );

		$caps    = new Capabilities();
		$summary = $caps->get_summary();
		$format  = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';

		$rows = array(
			array(
				'mime'    => '(extension)',
				'gd'      => $summary['gd'] ? 'yes' : 'no',
				'imagick' => $summary['imagick'] ? 'yes' : 'no',
				'any'     => ( $summary['gd'] || $summary['imagick'] ) ? 'yes' : 'no',
			),
		);

		foreach ( $summary['formats'] as $mime => $support ) {
			$rows[] = array(
				'mime'    => (string) $mime,
				'gd'      => $support['gd'] ? 'yes' : 'no',
				'imagick' => $support['imagick'] ? 'yes' : 'no',
				'any'     => ( $support['gd'] || $support['imagick'] ) ? 'yes' : 'no',
			);
		}

		\WP_CLI\Utils\format_items( $format, $rows, array( 'mime', 'gd', 'imagick', 'any' ) );
	}

	/**
	 * Scan the Media Library for attachments that match the bulk-processor
	 * criteria (image, not protected, not yet processed).
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Maximum number of candidates to list.
	 * ---
	 * default: 10
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
	 *     wp tidy-images scan
	 *     wp tidy-images scan --limit=50 --format=json
	 *     wp tidy-images scan --format=count
	 *     wp tidy-images scan --format=ids
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args.
	 *
	 * @return void
	 */
	public function scan( array $args, array $assoc_args ): void {
		unset( $args );

		$limit  = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 10;
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		$bp     = new Bulk_Processor();
		$total  = $bp->count_candidates();

		if ( 'count' === $format ) {
			\WP_CLI::log( (string) $total );
			return;
		}

		$ids = self::candidate_ids( $limit );

		if ( 'ids' === $format ) {
			\WP_CLI::log( implode( ' ', array_map( 'strval', $ids ) ) );
			return;
		}

		\WP_CLI::log( sprintf( 'Total candidates: %d (showing first %d)', $total, count( $ids ) ) );

		$rows = array();

		foreach ( $ids as $id ) {
			$file  = (string) get_attached_file( $id );
			$bytes = file_exists( $file ) ? (int) filesize( $file ) : 0;

			$rows[] = array(
				'id'    => $id,
				'title' => (string) get_the_title( $id ),
				'mime'  => (string) get_post_mime_type( $id ),
				'bytes' => $bytes,
				'file'  => '' !== $file ? basename( $file ) : '',
			);
		}

		\WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'title', 'mime', 'bytes', 'file' ) );
	}

	/**
	 * Mark one or more attachments as protected (do-not-touch).
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : One or more attachment IDs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tidy-images protect 1234
	 *     wp tidy-images protect 1234 1235 1236
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Associative args (unused).
	 *
	 * @return void
	 */
	public function protect( array $args, array $assoc_args ): void {
		unset( $assoc_args );
		$this->set_protection( $args, true );
	}

	/**
	 * Clear the protected (do-not-touch) flag from one or more attachments.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : One or more attachment IDs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tidy-images unprotect 1234
	 *     wp tidy-images unprotect 1234 1235 1236
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Associative args (unused).
	 *
	 * @return void
	 */
	public function unprotect( array $args, array $assoc_args ): void {
		unset( $assoc_args );
		$this->set_protection( $args, false );
	}

	/**
	 * Process one or more attachments — or `--all` candidates from the scan.
	 *
	 * Mutating by default. Use `--dry-run` for a preview, or omit with the
	 * site's dry-run setting turned on to inherit it.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : One or more attachment IDs.
	 *
	 * [--all]
	 * : Process every candidate from the bulk scan, batched.
	 *
	 * [--dry-run]
	 * : Plan the work without mutating files or DB.
	 *
	 * [--no-dry-run]
	 * : Force mutation even if the site setting has dry-run enabled.
	 *
	 * [--limit=<limit>]
	 * : Total cap on attachments processed in this --all run.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--batch-size=<batch>]
	 * : Per-batch size when looping in --all mode.
	 * ---
	 * default: 20
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp tidy-images process 1234
	 *     wp tidy-images process 1234 1235 --dry-run
	 *     wp tidy-images process --all --limit=100
	 *     wp tidy-images process --all --dry-run
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Associative args.
	 *
	 * @return void
	 */
	public function process( array $args, array $assoc_args ): void {
		$all     = ! empty( $assoc_args['all'] );
		$dry_run = self::resolve_dry_run( $assoc_args );

		if ( $all && ! empty( $args ) ) {
			\WP_CLI::error( 'Pass either explicit IDs or --all, not both.' );
		}

		if ( ! $all && empty( $args ) ) {
			\WP_CLI::error( 'Provide one or more attachment IDs, or use --all.' );
		}

		if ( $all ) {
			$this->process_all( $assoc_args, $dry_run );
		} else {
			$this->process_ids( array_map( 'intval', $args ), $dry_run );
		}
	}

	/**
	 * Restore an attachment to the file it was at before the plugin
	 * processed it. Reverses URL rewrites for renamed-format conversions.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Attachment ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tidy-images restore 1234
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Associative args (unused).
	 *
	 * @return void
	 */
	public function restore( array $args, array $assoc_args ): void {
		unset( $assoc_args );

		$id = isset( $args[0] ) ? (int) $args[0] : 0;

		if ( $id <= 0 ) {
			\WP_CLI::error( 'Provide a positive attachment ID.' );
		}

		if ( 'attachment' !== get_post_type( $id ) ) {
			\WP_CLI::error( sprintf( 'Post %d is not an attachment.', $id ) );
		}

		if ( is_null( Trash_Manager::get_backup( $id ) ) ) {
			\WP_CLI::error( sprintf( 'Attachment %d has no backup record.', $id ) );
		}

		if ( Trash_Manager::restore( $id ) ) {
			\WP_CLI::success( sprintf( 'Restored attachment %d.', $id ) );
		} else {
			\WP_CLI::error( sprintf( 'Restore failed for attachment %d.', $id ) );
		}
	}

	/**
	 * Apply or clear the protected flag on a list of attachment IDs.
	 *
	 * Logs per-id outcomes; non-attachment posts produce a warning and are
	 * skipped rather than aborting the batch.
	 *
	 * @since 0.5.0
	 *
	 * @param array<int, string> $ids       Raw positional args from WP-CLI.
	 * @param bool               $apply Target state.
	 *
	 * @return void
	 */
	private function set_protection( array $ids, bool $apply ): void {
		if ( empty( $ids ) ) {
			\WP_CLI::error( 'Provide one or more attachment IDs.' );
		}

		$verb = $apply ? 'Protected' : 'Unprotected';

		foreach ( $ids as $raw ) {
			$id = (int) $raw;

			if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
				\WP_CLI::warning( sprintf( 'Skipping %s — not an attachment.', $raw ) );
				continue;
			}

			if ( $apply ) {
				update_post_meta( $id, META_PROTECTED, '1' );
			} else {
				delete_post_meta( $id, META_PROTECTED );
			}

			\WP_CLI::log( sprintf( '%s #%d (%s)', $verb, $id, get_the_title( $id ) ) );
		}

		\WP_CLI::success( sprintf( '%s %d attachment(s).', $verb, count( $ids ) ) );
	}

	/**
	 * Process explicit attachment IDs through Attachment_Processor.
	 *
	 * Per-ID success/skip/failure is reported; the run continues across all
	 * IDs even when individual entries error.
	 *
	 * @since 0.5.0
	 *
	 * @param array<int> $ids     Attachment IDs.
	 * @param bool       $dry_run Plan-only flag.
	 *
	 * @return void
	 */
	private function process_ids( array $ids, bool $dry_run ): void {
		$processor = new Attachment_Processor();
		$totals    = array(
			'committed' => 0,
			'discarded' => 0,
			'skipped'   => 0,
			'errored'   => 0,
			'planned'   => 0,
		);
		$saved     = 0;

		foreach ( $ids as $id ) {
			if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
				\WP_CLI::warning( sprintf( 'Skipping %d — not an attachment.', $id ) );
				continue;
			}

			$result = $processor->process( $id, $dry_run );
			$action = (string) $result['action'];

			if ( isset( $totals[ $action ] ) ) {
				++$totals[ $action ];
			}

			$saved += (int) ( $result['savings_bytes'] ?? 0 );

			\WP_CLI::log(
				sprintf(
					'#%d %s -> %s (%s) saved=%d',
					$id,
					get_the_title( $id ),
					$action,
					(string) $result['reason'],
					(int) ( $result['savings_bytes'] ?? 0 )
				)
			);

			if ( ! empty( $result['error'] ) ) {
				\WP_CLI::log( sprintf( '    error: %s', (string) $result['error'] ) );
			}
		}

		\WP_CLI::success(
			sprintf(
				'Processed %d ID(s): committed=%d discarded=%d skipped=%d errored=%d planned=%d bytes_saved=%d%s',
				count( $ids ),
				$totals['committed'],
				$totals['discarded'],
				$totals['skipped'],
				$totals['errored'],
				$totals['planned'],
				$saved,
				$dry_run ? ' (dry-run)' : ''
			)
		);
	}

	/**
	 * Loop Bulk_Processor::run_batch until exhausted (or --limit reached).
	 *
	 * Cursor pagination matches the admin AJAX runner and the cron callback
	 * so resuming behaviour is identical across the three callers.
	 *
	 * @since 0.5.0
	 *
	 * @param array<string, string> $assoc_args Associative args.
	 * @param bool                  $dry_run    Plan-only flag.
	 *
	 * @return void
	 */
	private function process_all( array $assoc_args, bool $dry_run ): void {
		$total_cap  = isset( $assoc_args['limit'] ) ? max( 0, (int) $assoc_args['limit'] ) : 0;
		$batch_size = isset( $assoc_args['batch-size'] ) ? max( 1, (int) $assoc_args['batch-size'] ) : DEF_CRON_BATCH_SIZE;

		$bp        = new Bulk_Processor();
		$cursor    = 0;
		$processed = 0;
		$totals    = array(
			'examined' => 0,
			'changed'  => 0,
			'skipped'  => 0,
			'errored'  => 0,
		);
		$saved     = 0;

		\WP_CLI::log(
			sprintf(
				'Bulk-processing candidates (batch=%d, total cap=%s)%s',
				$batch_size,
				$total_cap > 0 ? (string) $total_cap : 'no limit',
				$dry_run ? ' [dry-run]' : ''
			)
		);

		while ( true ) {
			$this_batch = $batch_size;

			if ( $total_cap > 0 ) {
				$remaining  = $total_cap - $processed;
				$this_batch = min( $batch_size, $remaining );
			}

			if ( $this_batch <= 0 ) {
				break;
			}

			$result = $bp->run_batch( $cursor, $this_batch, $dry_run );

			$totals['examined'] += (int) $result['attachments_examined'];
			$totals['changed']  += (int) $result['attachments_changed'];
			$totals['skipped']  += (int) $result['attachments_skipped'];
			$totals['errored']  += (int) $result['attachments_errored'];
			$saved              += (int) $result['bytes_saved'];
			$processed          += (int) $result['attachments_examined'];

			foreach ( $result['log'] as $entry ) {
				\WP_CLI::log(
					sprintf(
						'  #%d %s -> %s (%s) saved=%d',
						(int) $entry['id'],
						(string) $entry['title'],
						(string) $entry['action'],
						(string) $entry['reason'],
						(int) ( $entry['savings_bytes'] ?? 0 )
					)
				);
			}

			if ( ! empty( $result['done'] ) || 0 === (int) $result['attachments_examined'] ) {
				break;
			}

			$cursor = (int) $result['last_cursor'];
		}

		\WP_CLI::success(
			sprintf(
				'Bulk done: examined=%d changed=%d skipped=%d errored=%d bytes_saved=%d%s',
				$totals['examined'],
				$totals['changed'],
				$totals['skipped'],
				$totals['errored'],
				$saved,
				$dry_run ? ' (dry-run)' : ''
			)
		);
	}

	/**
	 * Determine the dry-run flag for this invocation.
	 *
	 * Precedence: --dry-run on > --no-dry-run > site setting.
	 *
	 * @since 0.5.0
	 *
	 * @param array<string, string> $assoc_args Associative args.
	 *
	 * @return bool
	 */
	private static function resolve_dry_run( array $assoc_args ): bool {
		if ( isset( $assoc_args['dry-run'] ) ) {
			return true;
		}

		if ( isset( $assoc_args['no-dry-run'] ) ) {
			return false;
		}

		return (bool) get_plugin()->get_settings()->get( OPT_BEHAVIOUR_DRY_RUN );
	}

	/**
	 * Find candidate attachment IDs without going through Bulk_Processor's
	 * private finder.
	 *
	 * Mirrors the Bulk_Processor scan filters so `wp tidy-images scan`
	 * surfaces exactly what `process --all` would touch.
	 *
	 * @since 0.5.0
	 *
	 * @param int $limit Max IDs.
	 *
	 * @return array<int>
	 */
	private static function candidate_ids( int $limit ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery -- Mirror of Bulk_Processor::find_candidates(); not cacheable.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				WHERE p.post_type = 'attachment'
				  AND p.post_status = 'inherit'
				  AND p.post_mime_type LIKE %s
				  AND NOT EXISTS (
					  SELECT 1 FROM {$wpdb->postmeta} m1
					  WHERE m1.post_id = p.ID
						AND m1.meta_key = %s
						AND m1.meta_value NOT IN ( '', '0' )
				  )
				  AND NOT EXISTS (
					  SELECT 1 FROM {$wpdb->postmeta} m2
					  WHERE m2.post_id = p.ID
						AND m2.meta_key = %s
				  )
				ORDER BY p.ID ASC
				LIMIT %d",
				'image/%',
				META_PROTECTED,
				META_PROCESSED_AT,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery

		return array_map( 'intval', (array) $ids );
	}
}
