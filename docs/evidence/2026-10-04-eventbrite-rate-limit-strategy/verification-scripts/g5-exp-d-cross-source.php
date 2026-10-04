<?php
/**
 * G5 Experiment D — sequential cross-source test (Cork -> Dublin, then the
 * reverse order), LOCAL ONLY and always a dry run.
 *
 * Measures whether the SECOND Eventbrite source inherits the rate-limit
 * condition from the FIRST, which is the G5E question. Uses the real engine
 * so the run-scoped state machine is exercised exactly as in production.
 */
require_once '/var/www/html/tests/bootstrap.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$order = ( $argv[1] ?? 'cork,dublin' );
$order = explode( ',', $order );

$trace = array();
add_filter(
	'pre_http_request',
	function ( $preempt, $args, $url ) use ( &$trace ) {
		$trace[] = array( microtime( true ), $url );
		return $preempt;
	},
	1,
	3
);
add_filter(
	'http_api_debug',
	function ( $response, $args, $url ) use ( &$trace ) {
		$code    = 0;
		$headers = array();
		if ( is_array( $response ) ) {
			$code    = isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
			$headers = isset( $response['headers'] ) && is_array( $response['headers'] ) ? $response['headers'] : array();
		}
		$ra = '';
		foreach ( $headers as $n => $v ) {
			if ( 0 === strcasecmp( (string) $n, 'retry-after' ) ) {
				$ra = is_array( $v ) ? implode( ',', $v ) : (string) $v;
			}
		}
		$trace[] = array( microtime( true ), $url, $code, $ra );
		return $response;
	},
	10,
	3
);

$plugin = Conexao_Event_Importer::instance();

echo "=== EXPERIMENT D: sequential cross-source, order = " . implode( ' -> ', $order ) . " ===\n";

foreach ( $order as $source_id ) {
	$before = count( $trace );
	$start  = microtime( true );
	$result = $plugin->importer->run_source( $source_id, true );
	$spent  = microtime( true ) - $start;

	$mine = array_slice( $trace, $before );
	$mine = array_values( array_filter( $mine, static function ( $r ) { return false !== strpos( $r[1], 'eventbrite' ); } ) );

	$statuses = array();
	foreach ( $mine as $r ) {
		$c = isset( $r[2] ) ? $r[2] : 'pending';
		$statuses[ $c ] = isset( $statuses[ $c ] ) ? $statuses[ $c ] + 1 : 1;
	}

	printf(
		"\n--- %s ---\nduration=%.1fs outbound_eventbrite_requests=%d status_distribution=%s\n",
		$source_id,
		$spent,
		count( $mine ),
		json_encode( $statuses )
	);
	printf( "status=%s complete=%s found=%d\n", $result['status'], ! empty( $result['complete'] ) ? 'true' : 'false', $result['found'] );
	printf( "run_rate_limited=%s tripped_by=%s skipped=%s\n",
		Conexao_Eventbrite_Pacing::is_rate_limited() ? 'yes' : 'no',
		Conexao_Eventbrite_Pacing::get_rate_limited_source(),
		json_encode( Conexao_Eventbrite_Pacing::get_skipped_sources() ) );

	$hist = Conexao_Import_History::get_for_source( $source_id, 1 );
	if ( $hist ) {
		printf( "history: status=%s found=%d complete=%s\n  message=%s\n",
			$hist[0]['status'], $hist[0]['found'],
			isset( $hist[0]['complete'] ) ? ( $hist[0]['complete'] ? 'true' : 'false' ) : 'n/a',
			$hist[0]['message'] );
	}
	foreach ( Conexao_Import_Log::get_for_source( $source_id, 5 ) as $e ) {
		printf( "  log[%s] %s\n", $e['level'], substr( $e['message'], 0, 120 ) );
	}
}
