<?php
/**
 * G5 baseline / experiment harness (LOCAL ONLY — always a dry run).
 *
 * Captures, for each target source: every outbound request with its wall-clock
 * offset and HTTP status, inter-request intervals, pages/requests before the
 * first 429, every Retry-After header seen, request-budget usage, the run
 * outcome and the Import History row.
 *
 * Usage (inside the WordPress container):
 *   php /var/www/html/tests/tmp/g5-probe.php <source_id> [...]
 *   G5_PROBE_OUT=<path>   write the raw JSON trace here
 */

require_once '/var/www/html/tests/bootstrap.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$targets = array_slice( $argv, 1 );
if ( empty( $targets ) ) {
	fwrite( STDERR, "usage: g5-probe.php <source_id>...\n" );
	exit( 2 );
}

$trace = array();

$on_request = function ( $preempt, $args, $url ) use ( &$trace ) {
	$trace[] = array(
		'phase' => 'request',
		'at'    => microtime( true ),
		'url'   => $url,
	);
	return $preempt;
};

$on_response = function ( $response, $parsed_args, $url ) use ( &$trace ) {
	$code  = 0;
	$retry = '';
	if ( is_array( $response ) ) {
		$code = isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
		if ( isset( $response['headers'] ) && is_array( $response['headers'] ) ) {
			foreach ( $response['headers'] as $name => $value ) {
				if ( 0 === strcasecmp( (string) $name, 'retry-after' ) ) {
					$retry = (string) ( is_array( $value ) ? implode( ',', $value ) : $value );
				}
			}
		}
	}
	$trace[] = array(
		'phase'       => 'response',
		'at'          => microtime( true ),
		'url'         => $url,
		'code'        => $code,
		'retry_after' => $retry,
	);
	return $response;
};

add_filter( 'pre_http_request', $on_request, 1, 3 );
add_filter( 'http_api_debug', $on_response, 10, 3 );

$plugin  = Conexao_Event_Importer::instance();
$report  = array();

foreach ( $targets as $source_id ) {
	$trace  = array();
	$start  = microtime( true );
	$result = $plugin->importer->run_source( $source_id, true );
	$end    = microtime( true );

	// Pair each request with its response.
	$rows       = array();
	$open       = array();
	$statuses   = array();
	$retry_seen = array();
	foreach ( $trace as $item ) {
		if ( 'request' === $item['phase'] ) {
			$open[] = $item;
			continue;
		}
		$req      = array_shift( $open );
		$rows[]   = array(
			'url'         => $item['url'],
			'offset'      => round( $req ? $req['at'] - $start : 0, 3 ),
			'latency'     => round( $item['at'] - ( $req ? $req['at'] : $item['at'] ), 3 ),
			'code'        => $item['code'],
			'retry_after' => $item['retry_after'],
		);
		$statuses[ $item['code'] ] = isset( $statuses[ $item['code'] ] ) ? $statuses[ $item['code'] ] + 1 : 1;
		if ( '' !== $item['retry_after'] ) {
			$retry_seen[] = $item['retry_after'];
		}
	}

	$eb_rows = array();
	foreach ( $rows as $row ) {
		if ( false !== strpos( $row['url'], 'eventbrite' ) ) {
			$eb_rows[] = $row;
		}
	}

	$first_429 = null;
	$intervals = array();
	$prev_at   = null;
	foreach ( $eb_rows as $i => $row ) {
		if ( null !== $prev_at ) {
			$intervals[] = round( $row['offset'] - $prev_at, 3 );
		}
		$prev_at = $row['offset'];
		if ( 429 === $row['code'] && null === $first_429 ) {
			$first_429 = array(
				'request_number' => $i + 1,
				'elapsed'        => $row['offset'],
			);
		}
	}

	$median = null;
	if ( $intervals ) {
		$sorted = $intervals;
		sort( $sorted );
		$c      = count( $sorted );
		$median = $c % 2 ? $sorted[ ( $c - 1 ) / 2 ] : ( $sorted[ $c / 2 - 1 ] + $sorted[ $c / 2 ] ) / 2;
	}
$budget = Conexao_Request_Budget::get_stats();
	$reuse  = Conexao_Request_Reuse::get_stats();
	$hist   = Conexao_Import_History::get_for_source( $source_id, 1 );

	$report[ $source_id ] = array(
		'duration_seconds'            => round( $end - $start, 1 ),
		'status'                      => $result['status'],
		'found'                       => $result['found'],
		'created'                     => $result['created'],
		'updated'                     => $result['updated'],
		'skipped'                     => $result['skipped'],
		'errors'                      => $result['errors'],
		'failed'                      => $result['failed'],
		'complete'                    => isset( $result['complete'] ) ? (bool) $result['complete'] : null,
		'fatal_errors'                => $result['fatal_errors'],
		'outbound_requests'           => count( $rows ),
		'eventbrite_requests'         => count( $eb_rows ),
		'status_distribution'         => $statuses,
		'retry_after_values'          => $retry_seen,
		'responses_with_retry_after'  => count( $retry_seen ),
		'first_429'                   => $first_429,
		'inter_request_intervals'     => $intervals,
		'inter_request_min'           => $intervals ? min( $intervals ) : null,
		'inter_request_median'        => $median,
		'inter_request_max'           => $intervals ? max( $intervals ) : null,
		'budget'                      => $budget,
		'reuse'                       => $reuse,
		'history'                     => $hist ? $hist[0] : null,
		'rows'                        => $eb_rows,
	);

	// ---- human-readable summary ------------------------------------------
	echo "\n=== SOURCE {$source_id} ===\n";
	printf( "duration_seconds=%.1f\n", $report[ $source_id ]['duration_seconds'] );
	printf( "status=%s complete=%s\n", $result['status'], null === $report[ $source_id ]['complete'] ? 'n/a' : ( $report[ $source_id ]['complete'] ? 'true' : 'false' ) );
	printf( "found=%d created=%d updated=%d skipped=%d errors=%d failed=%d\n",
		$result['found'], $result['created'], $result['updated'], $result['skipped'], $result['errors'], $result['failed'] );
	printf( "outbound_requests=%d eventbrite_requests=%d\n", count( $rows ), count( $eb_rows ) );
	printf( "status_distribution=%s\n", json_encode( $statuses ) );
	printf( "responses_with_retry_after=%d %s\n", count( $retry_seen ), json_encode( $retry_seen ) );
	printf( "first_429=%s\n", null === $first_429 ? 'none' : json_encode( $first_429 ) );
	printf( "inter_request_min=%s median=%s max=%s\n",
		json_encode( $report[ $source_id ]['inter_request_min'] ),
		json_encode( $median ),
		json_encode( $report[ $source_id ]['inter_request_max'] ) );
	printf( "budget=%s\n", json_encode( $budget ) );
	printf( "reuse=%s\n", json_encode( $reuse ) );
	foreach ( $result['fatal_errors'] as $fe ) {
		printf( "  fatal: %s\n", $fe['message'] );
	}
	if ( $hist ) {
		printf( "history_row: time=%s status=%s found=%d complete=%s run_id=%s\n",
			$hist[0]['time'], $hist[0]['status'], $hist[0]['found'],
			isset( $hist[0]['complete'] ) ? ( $hist[0]['complete'] ? 'true' : 'false' ) : 'n/a',
			isset( $hist[0]['run_id'] ) ? $hist[0]['run_id'] : '' );
		printf( "history_message=%s\n", $hist[0]['message'] );
	}
	foreach ( Conexao_Import_Log::get_for_source( $source_id, 14 ) as $e ) {
		printf( "  log[%s] :: %s\n", $e['level'], substr( $e['message'], 0, 130 ) );
	}
}

$out = getenv( 'G5_PROBE_OUT' );
if ( $out ) {
	file_put_contents( $out, json_encode( $report, JSON_PRETTY_PRINT ) );
	echo "\ntrace_written={$out}\n";
}
