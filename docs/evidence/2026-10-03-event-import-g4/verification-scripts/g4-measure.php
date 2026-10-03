<?php
/**
 * G4 measurement harness (LOCAL ONLY, never contacts production).
 *
 * Captures the metrics §2 of the G4 brief requires, for a focused set of
 * sources or for a full dry run: duration, outbound requests, unique
 * requests, duplicate requests, HTTP status distribution, 429 count,
 * per-provider request counts, events found/created/updated, failed sources,
 * incomplete sources, retry attempts, and — once G4A/G4B/G4C land — response
 * cache hits/misses and outbound-budget exhaustion.
 *
 * Usage:
 *   php g4-measure.php focused <source_id> [<source_id> ...]
 *   php g4-measure.php full
 *
 * The HTTP trace uses the same `pre_http_request` + `http_response` pair the
 * G1-G3 evidence used, so the numbers are comparable across stages.
 *
 * @package Conexao_Event_Importer
 */

require_once '/var/www/html/tests/bootstrap.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$mode    = isset( $argv[1] ) ? $argv[1] : '';
$targets = array_slice( $argv, 2 );

if ( 'focused' !== $mode && 'full' !== $mode ) {
	fwrite( STDERR, "usage: g4-measure.php focused <source_id>... | full\n" );
	exit( 2 );
}
if ( 'focused' === $mode && empty( $targets ) ) {
	fwrite( STDERR, "usage: g4-measure.php focused <source_id>...\n" );
	exit( 2 );
}

$GLOBALS['g4_trace']   = array();
$GLOBALS['g4_status'] = array();

add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		$GLOBALS['g4_trace'][] = array(
			'method' => isset( $args['method'] ) ? strtoupper( (string) $args['method'] ) : 'GET',
			'url'    => (string) $url,
			'host'   => (string) wp_parse_url( (string) $url, PHP_URL_HOST ),
		);
		return $preempt;
	},
	1,
	3
);

add_filter(
	'http_response',
	static function ( $response, $parsed_args, $url ) {
		$code       = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$retry_after = '';

		if ( ! is_wp_error( $response ) ) {
			$headers = wp_remote_retrieve_headers( $response );
			if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
				$all = $headers->getAll();
			} elseif ( is_array( $headers ) ) {
				$all = $headers;
			} else {
				$all = array();
			}
			foreach ( $all as $name => $value ) {
				if ( 'retry-after' === strtolower( (string) $name ) ) {
					$retry_after = (string) $value;
					break;
				}
			}
		}

		$GLOBALS['g4_status'][] = array(
			'url'         => (string) $url,
			'code'        => $code,
			'retry_after' => $retry_after,
		);

		return $response;
	},
	999,
	3
);

/**
 * Reset the per-target counters.
 *
 * @return void
 */
function g4_reset() {
	$GLOBALS['g4_trace']   = array();
	$GLOBALS['g4_status'] = array();
}

/**
 * Count the importer's own "retrying" diagnostic entries for a source.
 *
 * @param string $source_id Source slug.
 * @return int Retry attempts recorded in the import log.
 */
function g4_count_retries( $source_id ) {
	$entries = Conexao_Import_Log::get_for_source( $source_id, 500 );
	$retries = 0;
	foreach ( $entries as $e ) {
		if ( false !== strpos( $e['message'], 'Retrying with a short delay' ) ) {
			++$retries;
		}
	}
	return $retries;
}

/**
 * Summarise the trace collected so far.
 *
 * @param string $label Human label for the measurement.
 * @return array Summary array.
 */
function g4_summarise( $label ) {
	$trace  = $GLOBALS['g4_trace'];
	$status = $GLOBALS['g4_status'];

	$unique    = array();
	$by_host   = array();
	$by_method = array();
	foreach ( $trace as $t ) {
		$unique[ $t['method'] . ' ' . $t['url'] ] = true;
		$host = '' !== $t['host'] ? $t['host'] : '(local)';
		$by_host[ $host ] = isset( $by_host[ $host ] ) ? $by_host[ $host ] + 1 : 1;
		$by_method[ $t['method'] ] = isset( $by_method[ $t['method'] ] ) ? $by_method[ $t['method'] ] + 1 : 1;
	}

	$codes            = array();
	$retry_after_seen = 0;
	foreach ( $status as $s ) {
		$key           = 0 === $s['code'] ? 'transport_error' : (string) $s['code'];
		$codes[ $key ] = isset( $codes[ $key ] ) ? $codes[ $key ] + 1 : 1;
		if ( '' !== $s['retry_after'] ) {
			++$retry_after_seen;
		}
	}

	$summary = array(
		'label'                  => $label,
		'outbound_requests'      => count( $trace ),
		'unique_requests'        => count( $unique ),
		'duplicate_requests'     => count( $trace ) - count( $unique ),
		'by_host'                => $by_host,
		'by_method'              => $by_method,
		'status_distribution'    => $codes,
		'count_429'              => isset( $codes['429'] ) ? $codes['429'] : 0,
		'count_502'              => isset( $codes['502'] ) ? $codes['502'] : 0,
		'count_504'              => isset( $codes['504'] ) ? $codes['504'] : 0,
		'transport_errors'       => isset( $codes['transport_error'] ) ? $codes['transport_error'] : 0,
		'responses_with_retry_after' => $retry_after_seen,
	);

	echo "\n=== {$label} ===\n";
	printf( "outbound_requests=%d unique_requests=%d duplicate_requests=%d\n",
		$summary['outbound_requests'], $summary['unique_requests'], $summary['duplicate_requests'] );
	printf( "status_distribution=%s\n", wp_json_encode( $summary['status_distribution'] ) );
	printf( "count_429=%d count_502=%d count_504=%d transport_errors=%d\n",
		$summary['count_429'], $summary['count_502'], $summary['count_504'], $summary['transport_errors'] );
	printf( "responses_with_retry_after=%d\n", $summary['responses_with_retry_after'] );
	printf( "by_host=%s\n", wp_json_encode( $summary['by_host'] ) );
	printf( "by_method=%s\n", wp_json_encode( $summary['by_method'] ) );

	if ( class_exists( 'Conexao_Request_Budget' ) ) {
		printf( "request_budget=%s\n", wp_json_encode( Conexao_Request_Budget::get_stats() ) );
	}
	if ( class_exists( 'Conexao_Request_Reuse' ) ) {
		printf( "response_reuse=%s\n", wp_json_encode( Conexao_Request_Reuse::get_stats() ) );
	}

	return $summary;
}
/**
 * Annotate a summary with provider-specific pagination facts (§13).
 *
 * @param string $source_id Source slug.
 * @param array  $result    Stats array returned by run_source().
 * @param array  $summary   Summary (by reference).
 * @return void
 */
function g4_annotate_source( $source_id, array $result, array &$summary ) {
	$listing = array();
	$details = 0;
	$pages   = array();

	foreach ( $GLOBALS['g4_trace'] as $t ) {
		$path = (string) wp_parse_url( $t['url'], PHP_URL_PATH );

		if ( false !== strpos( $t['url'], 'heritageweek.ie/event-listings' ) ) {
			if ( preg_match( '#/event-listings(/p\d+)?/?$#', $path ) ) {
				$listing[] = $t['url'];
			} else {
				++$details;
			}
		}

		if ( false !== strpos( $t['url'], 'eventbrite' ) ) {
			$pages[] = preg_match( '#[?&]page=(\d+)#', $t['url'], $m ) ? (int) $m[1] : 1;
		}
	}

	if ( false !== strpos( $source_id, 'heritage' ) ) {
		$page_seq = array();
		foreach ( $listing as $u ) {
			$page_seq[] = preg_match( '#/p(\d+)/?$#', (string) wp_parse_url( $u, PHP_URL_PATH ), $m ) ? 'p' . $m[1] : 'p1';
		}
		printf( "pages_visited=%d unique_listing_urls=%d detail_pages=%d\n",
			count( $listing ), count( array_unique( $listing ) ), $details );
		printf( "page_order=%s\n", implode( ' -> ', $page_seq ) );
		printf( "page_revisits=%d\n", count( $page_seq ) - count( array_unique( $page_seq ) ) );
		printf( "termination=%s\n", ! empty( $result['complete'] ) ? 'complete enumeration' : 'INCOMPLETE' );

		$summary['heritage_pages_visited']     = count( $listing );
		$summary['heritage_unique_listing']    = count( array_unique( $listing ) );
		$summary['heritage_detail_pages']      = $details;
		$summary['heritage_page_revisits']     = count( $page_seq ) - count( array_unique( $page_seq ) );
	}

	if ( false !== strpos( $source_id, 'eventbrite' ) ) {
		printf( "pages_attempted=%d unique_pages=%d\n", count( $pages ), count( array_unique( $pages ) ) );
		$summary['eventbrite_pages_attempted'] = count( $pages );
		$summary['eventbrite_unique_pages']    = count( array_unique( $pages ) );
	}
}

$plugin     = Conexao_Event_Importer::instance();
$summaries   = array();
$started_all = microtime( true );

if ( 'focused' === $mode ) {
	foreach ( $targets as $source_id ) {
		g4_reset();
		$started = microtime( true );
		$result  = $plugin->importer->run_source( $source_id, true );
		$elapsed = microtime( true ) - $started;

		printf( "\n--- SOURCE %s ---\n", $source_id );
		printf( "duration_seconds=%.1f\n", $elapsed );
		printf( "status=%s\n", $result['status'] );
		printf( "found=%d created=%d updated=%d unchanged=%d skipped=%d errors=%d failed=%d\n",
			$result['found'], $result['created'], $result['updated'], $result['unchanged'],
			$result['skipped'], $result['errors'], $result['failed'] );
		printf( "complete=%s\n", array_key_exists( 'complete', $result ) ? ( $result['complete'] ? 'true' : 'false' ) : 'n/a' );
		printf( "fatal_errors=%d\n", count( $result['fatal_errors'] ) );
		foreach ( $result['fatal_errors'] as $fe ) {
			printf( "  fatal: %s | %s\n", $fe['message'], $fe['technical'] );
		}
		printf( "retry_attempts_logged=%d\n", g4_count_retries( $source_id ) );

		$hist = Conexao_Import_History::get_for_source( $source_id, 1 );
		if ( ! empty( $hist ) ) {
			printf( "history_row: time=%s status=%s found=%d complete=%s run_id=%s\n",
				$hist[0]['time'], $hist[0]['status'], $hist[0]['found'],
				isset( $hist[0]['complete'] ) ? ( $hist[0]['complete'] ? 'true' : 'false' ) : 'n/a',
				isset( $hist[0]['run_id'] ) ? $hist[0]['run_id'] : '' );
			printf( "history_message=%s\n", $hist[0]['message'] );
		}

		$summary = g4_summarise( 'SOURCE ' . $source_id );
		g4_annotate_source( $source_id, $result, $summary );

		$summary['source_id']      = $source_id;
		$summary['duration']       = round( $elapsed, 1 );
		$summary['status']         = $result['status'];
		$summary['found']          = (int) $result['found'];
		$summary['created']        = (int) $result['created'];
		$summary['updated']        = (int) $result['updated'];
		$summary['complete']       = array_key_exists( 'complete', $result ) ? (bool) $result['complete'] : null;
		$summary['retry_attempts'] = g4_count_retries( $source_id );
		$summaries[]               = $summary;

		// Persist the raw per-request trace so the duplicate requests can be
		// classified afterwards: a duplicate that follows a FAILURE on the same
		// URL is a retry (which must never be cached), whereas a duplicate that
		// follows a SUCCESS is genuine reuse waste.
		file_put_contents(
			'/var/www/html/tests/tmp/g4-trace-' . $source_id . '.json',
			wp_json_encode(
				array(
					'trace'  => $GLOBALS['g4_trace'],
					'status' => $GLOBALS['g4_status'],
				),
				JSON_PRETTY_PRINT
			)
		);

		$entries = Conexao_Import_Log::get_for_source( $source_id, 8 );
		foreach ( $entries as $e ) {
			printf( "  log[%s] run_id=%s :: %s\n", $e['level'], '' === $e['run_id'] ? '(EMPTY)' : $e['run_id'], substr( $e['message'], 0, 120 ) );
		}
	}
} else {
	g4_reset();

	$started  = microtime( true );
	$combined = $plugin->importer->run_all( array( 'dry_run' => true ) );
	$elapsed  = microtime( true ) - $started;

	$per_source = array();
	$incomplete = 0;
	$failed     = 0;
	foreach ( (array) $combined['sources'] as $sid => $r ) {
		$st = isset( $r['status'] ) ? $r['status'] : '?';
		$per_source[ $st ] = isset( $per_source[ $st ] ) ? $per_source[ $st ] + 1 : 1;
		if ( 'failed' === $st ) {
			++$failed;
		}
		if ( array_key_exists( 'complete', $r ) && false === $r['complete'] ) {
			++$incomplete;
		}
	}

	echo "\n=== FULL RUN_ALL (dry) ===\n";
	printf( "elapsed_seconds=%.1f\n", $elapsed );
	printf( "sources=%d\n", count( (array) $combined['sources'] ) );
	printf( "combined_status=%s\n", $combined['status'] );
	printf( "found=%d created=%d updated=%d unchanged=%d skipped=%d skipped_past=%d skipped_invalid_date=%d errors=%d failed=%d\n",
		$combined['found'], $combined['created'], $combined['updated'], $combined['unchanged'],
		$combined['skipped'], $combined['skipped_past'], $combined['skipped_invalid_date'],
		$combined['errors'], $combined['failed'] );
	printf( "per_source_status=%s\n", wp_json_encode( $per_source ) );
	printf( "failed_sources=%d incomplete_sources=%d\n", $failed, $incomplete );
	printf( "fatal_error_count=%d\n", count( (array) $combined['fatal_errors'] ) );

	$summary = g4_summarise( 'FULL RUN' );

	$summary['elapsed']            = round( $elapsed, 1 );
	$summary['found']              = (int) $combined['found'];
	$summary['created']            = (int) $combined['created'];
	$summary['updated']            = (int) $combined['updated'];
	$summary['per_source']         = $per_source;
	$summary['failed_sources']     = $failed;
	$summary['incomplete_sources'] = $incomplete;

	$h = Conexao_Import_History::get_all();
	$status = array();
	$false_empty = 0;
	$fatal = 0;
	$hist_incomplete = 0;
	foreach ( $h as $e ) {
		$s = isset( $e['status'] ) ? $e['status'] : '?';
		$status[ $s ] = isset( $status[ $s ] ) ? $status[ $s ] + 1 : 1;
		if ( 'success' === $s && 0 === (int) $e['found'] ) {
			++$false_empty;
		}
		if ( ! empty( $e['fatal_errors'] ) ) {
			++$fatal;
		}
		if ( isset( $e['complete'] ) && false === $e['complete'] ) {
			++$hist_incomplete;
		}
	}

	printf( "history_after=%s\n", wp_json_encode( array(
		'rows'               => count( $h ),
		'status'             => $status,
		'found0_and_success' => $false_empty,
		'with_fatal_errors'  => $fatal,
		'marked_incomplete'  => $hist_incomplete,
	) ) );

	$summary['history_found0_and_success'] = $false_empty;
	$summary['history_rows']              = count( $h );
	$summaries[]                          = $summary;
}

printf( "\nTOTAL_MEASUREMENT_SECONDS=%.1f\n", microtime( true ) - $started_all );

$out = '/var/www/html/tests/tmp/g4-measure-' . ( 'full' === $mode ? 'full' : 'focused' ) . '.json';
file_put_contents( $out, wp_json_encode( $summaries, JSON_PRETTY_PRINT ) );
printf( "SUMMARY_WRITTEN=%s\n", $out );