<?php
/**
 * Full local run_all DRY verification (G1/G2 regression against the baseline).
 * Local only. Never contacts production.
 */
require_once '/var/www/html/tests/bootstrap.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$trace = array();
add_filter( 'pre_http_request', function ( $p, $a, $u ) use ( &$trace ) { $trace[] = $u; return $p; }, 1, 3 );

$plugin = Conexao_Event_Importer::instance();
$before = count( Conexao_Import_History::get_all() );
$log_before = count( Conexao_Import_Log::get_all() );

$started = microtime( true );
$combined = $plugin->importer->run_all( array( 'dry_run' => true ) );
$elapsed = microtime( true ) - $started;

$hosts = array(); $paths = array();
foreach ( $trace as $u ) { $h = parse_url( $u, PHP_URL_HOST ); if ( $h ) { $hosts[$h] = isset($hosts[$h])?$hosts[$h]+1:1; } $paths[$u] = true; }

$per_source = array();
foreach ( (array) $combined['sources'] as $sid => $r ) {
	$st = isset($r['status']) ? $r['status'] : '?';
	$per_source[$st] = isset($per_source[$st]) ? $per_source[$st] + 1 : 1;
}

echo "\n=== FULL RUN_ALL (dry) ===\n";
printf( "ELAPSED_SECONDS=%.1f\n", $elapsed );
printf( "SOURCES=%d\n", count( (array) $combined['sources'] ) );
printf( "COMBINED=%s\n", wp_json_encode( array_intersect_key( $combined, array_flip( array( 'found','created','updated','unchanged','skipped','skipped_past','skipped_invalid_date','failed','errors','status' ) ) ) ) );
printf( "PER_SOURCE_STATUS=%s\n", wp_json_encode( $per_source ) );
printf( "FATAL_ERROR_COUNT=%d\n", count( (array) $combined['fatal_errors'] ) );
printf( "TOTAL_OUTBOUND_REQUESTS=%d\n", count( $trace ) );
printf( "UNIQUE_TARGETS=%d\n", count( $paths ) );
printf( "DUPLICATE_REQUESTS=%d\n", count( $trace ) - count( $paths ) );
printf( "BY_HOST=%s\n", wp_json_encode( $hosts ) );
printf( "HISTORY_ROWS_ADDED=%d\n", count( Conexao_Import_History::get_all() ) - $before );
printf( "LOG_ENTRIES_ADDED=%d\n", count( Conexao_Import_Log::get_all() ) - $log_before );
file_put_contents( '/var/www/html/tests/tmp/full-run-trace.json', wp_json_encode( $trace ) );

$h = Conexao_Import_History::get_all();
$status = array(); $false_empty = 0; $fatal = 0; $incomplete = 0;
foreach ( $h as $e ) {
	$s = isset($e['status']) ? $e['status'] : '?';
	$status[$s] = isset($status[$s]) ? $status[$s]+1 : 1;
	if ( 'success' === $s && 0 === (int) $e['found'] ) { $false_empty++; }
	if ( ! empty($e['fatal_errors']) ) { $fatal++; }
	if ( isset($e['complete']) && false === $e['complete'] ) { $incomplete++; }
}
printf( "HISTORY_AFTER=%s\n", wp_json_encode( array( 'rows'=>count($h), 'status'=>$status, 'found0_and_success'=>$false_empty, 'with_fatal_errors'=>$fatal, 'marked_incomplete'=>$incomplete ) ) );
