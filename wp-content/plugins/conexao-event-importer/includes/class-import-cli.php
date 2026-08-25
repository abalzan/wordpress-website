<?php
/**
 * WP-CLI commands for the event importer.
 *
 * Commands (namespace: conexao-events):
 *
 *   wp conexao-events import [--source=<id>] [--dry-run]
 *   wp conexao-events cleanup [--dry-run]
 *   wp conexao-events status
 *
 * Import execution is local-only and on-demand. There is no cron scheduling,
 * no REST trigger, and no auto-disable of sources.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Only register when WP-CLI is actually running.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * Manage the Conexão event importer.
	 */
	class Conexao_Import_CLI {

		/**
		 * Run event imports (manual / local-only).
		 *
		 * ## OPTIONS
		 *
		 * [--source=<id>]
		 * : Import only this source slug.
		 *
		 * [--all]
		 * : Import every active source (default).
		 *
		 * [--dry-run]
		 * : Fetch and classify events without writing anything.
		 *
		 * ## EXAMPLES
		 *
		 *     wp conexao-events import
		 *     wp conexao-events import --source=eventbrite
		 *     wp conexao-events import --dry-run
		 *
		 * @subcommand import
		 *
		 * @param array $args       Positional args.
		 * @param array $assoc_args Associative args.
		 */
		public function import( $args, $assoc_args ) {
			$plugin   = Conexao_Event_Importer::instance();
			$importer = $plugin->importer;

			$dry_run = ! empty( $assoc_args['dry-run'] );

			// Single source mode.
			if ( ! empty( $assoc_args['source'] ) ) {
				$source_id = sanitize_key( $assoc_args['source'] );
				WP_CLI::log( sprintf( 'Importing source: %s%s', $source_id, $dry_run ? ' (dry-run)' : '' ) );

				$result = $importer->run_source( $source_id, $dry_run );
				$this->report_source_result( $source_id, $result, $dry_run );

				if ( isset( $result['status'] ) && 'failed' === $result['status'] ) {
					WP_CLI::error( 'Import failed for source: ' . $source_id );
				}
				WP_CLI::success( 'Done.' );
				return;
			}

			// All active sources.
			$sources_manager = new Conexao_Event_Sources();
			$active          = $sources_manager->get_active();

			if ( empty( $active ) ) {
				WP_CLI::warning( 'No active event sources configured.' );
				return;
			}

			WP_CLI::log( sprintf( 'Importing %d source(s)%s…', count( $active ), $dry_run ? ' (dry-run)' : '' ) );

			$had_failure = false;
			foreach ( $active as $source ) {
				$result = $importer->run_source( $source['id'], $dry_run );
				$this->report_source_result( $source['id'], $result, $dry_run );

				if ( isset( $result['status'] ) && 'failed' === $result['status'] ) {
					$had_failure = true;
				}
			}

			if ( $had_failure ) {
				WP_CLI::error( 'Import finished with failures (see log above).' );
			}
			WP_CLI::success( 'Import finished.' );
		}

		/**
		 * Run the cleanup (delete past events + orphaned images).
		 *
		 * ## OPTIONS
		 *
		 * [--dry-run]
		 * : Report what would be deleted without deleting anything.
		 *
		 * ## EXAMPLES
		 *
		 *     wp conexao-events cleanup
		 *     wp conexao-events cleanup --dry-run
		 *
		 * @subcommand cleanup
		 *
		 * @param array $args       Positional args.
		 * @param array $assoc_args Associative args.
		 */
		public function cleanup( $args, $assoc_args ) {
			$plugin  = Conexao_Event_Importer::instance();
			$cleanup = $plugin->cleanup;

			if ( ! empty( $assoc_args['dry-run'] ) ) {
				$reflection = new ReflectionMethod( $cleanup, 'find_past_events' );
				$past_ids   = $reflection->invoke( $cleanup );

				WP_CLI::log( sprintf( 'Dry-run: %d past event(s) would be deleted (plus their orphaned images).', count( $past_ids ) ) );
				foreach ( array_slice( $past_ids, 0, 20 ) as $post_id ) {
					WP_CLI::log( sprintf( '  - #%d %s', $post_id, get_the_title( $post_id ) ) );
				}
				if ( count( $past_ids ) > 20 ) {
					WP_CLI::log( sprintf( '  …and %d more.', count( $past_ids ) - 20 ) );
				}
				WP_CLI::success( 'Dry-run complete. Nothing was deleted.' );
				return;
			}

			$result = $cleanup->run_cleanup();

			WP_CLI::log( sprintf(
				'Cleanup: %d found, %d deleted, %d images deleted, %d preserved, %d errors.',
				(int) $result['events_found'],
				(int) $result['events_deleted'],
				(int) $result['images_deleted'],
				(int) $result['images_preserved'],
				count( $result['errors'] )
			) );

			if ( ! empty( $result['errors'] ) ) {
				foreach ( array_slice( $result['errors'], 0, 10 ) as $error ) {
					WP_CLI::warning( $error );
				}
			}

			WP_CLI::success( 'Cleanup finished.' );
		}

		/**
		 * Show importer status: sources, health, and last import times.
		 *
		 * ## EXAMPLES
		 *
		 *     wp conexao-events status
		 *
		 * @subcommand status
		 *
		 * @param array $args       Positional args.
		 * @param array $assoc_args Associative args.
		 */
		public function status( $args, $assoc_args ) {
			WP_CLI::log( 'Event Importer Status (manual, local-only):' );

			$rows = array();
			foreach ( ( new Conexao_Event_Sources() )->get_all() as $source ) {
				$health = Conexao_Source_Health::get( $source['id'] );

				$rows[] = array(
					'id'          => $source['id'],
					'name'        => isset( $source['name'] ) ? $source['name'] : $source['id'],
					'status'      => isset( $source['status'] ) ? $source['status'] : '',
					'last_import' => isset( $source['last_import'] ) && $source['last_import'] ? $source['last_import'] : '—',
					'fails'       => (string) $health['consecutive_failures'],
				);
			}

			WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'name', 'status', 'last_import', 'fails' ) );
		}

		/**
		 * Print a one-line summary for a single-source result.
		 *
		 * @param string $source_id Source slug.
		 * @param array  $result    Result stats.
		 * @param bool   $dry_run   Whether this was a dry run.
		 */
		protected function report_source_result( $source_id, $result, $dry_run ) {
			if ( ! is_array( $result ) ) {
				WP_CLI::warning( sprintf( '  %s: no result returned.', $source_id ) );
				return;
			}

			$prefix = $dry_run ? '[dry-run] ' : '';

			WP_CLI::log( sprintf(
				'  %s%s: found=%d created=%d updated=%d unchanged=%d duplicates=%d skipped=%d review=%d failed=%d status=%s',
				$prefix,
				$source_id,
				(int) ( isset( $result['found'] ) ? $result['found'] : 0 ),
				(int) ( isset( $result['created'] ) ? $result['created'] : 0 ),
				(int) ( isset( $result['updated'] ) ? $result['updated'] : 0 ),
				(int) ( isset( $result['unchanged'] ) ? $result['unchanged'] : 0 ),
				(int) ( isset( $result['duplicates'] ) ? $result['duplicates'] : 0 ),
				(int) ( isset( $result['skipped'] ) ? $result['skipped'] : 0 ),
				(int) ( isset( $result['needs_review'] ) ? $result['needs_review'] : 0 ),
				(int) ( isset( $result['failed'] ) ? $result['failed'] : 0 ),
				isset( $result['status'] ) ? $result['status'] : 'unknown'
			) );

			if ( ! empty( $result['fatal_errors'] ) && is_array( $result['fatal_errors'] ) ) {
				foreach ( $result['fatal_errors'] as $error ) {
					WP_CLI::warning( '    ' . ( isset( $error['message'] ) ? $error['message'] : '' ) );
				}
			}
		}
	}

	WP_CLI::add_command( 'conexao-events', 'Conexao_Import_CLI' );
}