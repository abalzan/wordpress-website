<?php
/**
 * Focused runtime verification for G1/G2 (LOCAL ONLY, dry run).
 * Captures per-source stats, HTTP request trace and pagination termination.
 */
require_once '/var/www/html/tests/bootstrap.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$targets = array_slice( $argv, 1 );
if ( empty( $targets ) ) { fwrite( STDERR, "usage: focused-run.php <source_id>...\n" ); exit( 2 ); }

$trace = array();
$filter = function ( $preempt, $args, $url ) use ( &$trace ) {
	$trace[] = $url;
	return $preempt;
};
add_filter( 'pre_http_request', $filter, 1, 3 );

$plugin = Conexao_Event_Importer::instance();

foreach ( $targets as $source_id ) {
	$trace = array();
	$started = microtime( true );
	$result = $plugin->importer->run_source( $source_id, true );
	$elapsed = microtime( true ) - $started;

	$hosts = array(); $statuses = array();
	foreach ( $trace as $u ) {
		$h = parse_url( $u, PHP_URL_HOST );
		if ( $h ) { $hosts[ $h ] = isset( $hosts[ $h ] ) ? $hosts[ $h ] + 1 : 1; }
	}
	$unique = array_unique( $trace );

	echo "\n=== SOURCE {$source_id} ===\n";
	printf( "duration_seconds=%.1f\n", $elapsed );
	printf( "status=%s\n", $result['status'] );
	printf( "found=%d created=%d updated=%d unchanged=%d skipped=%d errors=%d failed=%d\n",
		$result['found'], $result['created'], $result['updated'], $result['unchanged'],
		$result['skipped'], $result['errors'], $result['failed'] );
	printf( "complete=%s\n", array_key_exists( 'complete', $result ) ? ( $result['complete'] ? 'true' : 'false' ) : 'n/a' );
	printf( "fatal_errors=%d\n", count( $result['fatal_errors'] ) );
	foreach ( $result['fatal_errors'] as $fe ) { echo "  fatal: {$fe['message']} | {$fe['technical']}\n"; }
	printf( "http_requests=%d unique_urls=%d duplicates=%d\n", count( $trace ), count( $unique ), count( $trace ) - count( $unique ) );
	printf( "by_host=%s\n", json_encode( $hosts ) );
	$note = $result['message'] ?? '';
	printf( "summary=%s\n", $note );

	$hist = Conexao_Import_History::get_for_source( $source_id, 1 );
	if ( ! empty( $hist ) ) {
		printf( "history_row: time=%s status=%s found=%d complete=%s run_id=%s\n",
			$hist[0]['time'], $hist[0]['status'], $hist[0]['found'],
			isset( $hist[0]['complete'] ) ? ( $hist[0]['complete'] ? 'true' : 'false' ) : 'n/a',
			isset( $hist[0]['run_id'] ) ? $hist[0]['run_id'] : '' );
		printf( "history_message=%s\n", $hist[0]['message'] );
	}

	// pagination trace for heritage-week
	if ( false !== strpos( $source_id, 'heritage' ) ) {
		// A listing page is /event-listings or /event-listings/pN; a DETAIL
		// page is /event-listings/<slug>.
		$listing = array();
		$details = 0;
		foreach ( $trace as $u ) {
			$path = (string) parse_url( $u, PHP_URL_PATH );
			if ( false !== strpos( $u, 'heritageweek.ie/event-listings' ) ) {
				if ( preg_match( '#/event-listings(/p\d+)?/?$#', $path ) ) {
					$listing[] = $u;
				} else {
					$details++;
				}
			}
		}
		printf( "detail_pages=%d\n", $details );
		printf( "listing_pages_fetched=%d unique_listing_pages=%d\n", count( $listing ), count( array_unique( $listing ) ) );
		$page_seq = array();
		foreach ( $listing as $u ) {
			$page_seq[] = preg_match( '#/p(\d+)/?$#', (string) parse_url( $u, PHP_URL_PATH ), $m ) ? 'p' . $m[1] : 'p1';
		}
		printf( "page_order=%s\n", implode( ' -> ', $page_seq ) );
		$revisits = count( $page_seq ) - count( array_unique( $page_seq ) );
		printf( "page_revisits=%d\n", $revisits );
		printf( "termination=%s\n", $result['complete'] ? 'no forward link left (complete enumeration)' : 'INCOMPLETE' );
	}

	$entries = Conexao_Import_Log::get_for_source( $source_id, 6 );
	foreach ( $entries as $e ) {
		printf( "  log[%s] run_id=%s :: %s\n", $e['level'], '' === $e['run_id'] ? '(EMPTY)' : $e['run_id'], substr( $e['message'], 0, 110 ) );
	}
}
