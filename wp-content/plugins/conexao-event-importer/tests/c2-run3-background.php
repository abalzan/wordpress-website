<?php
/**
 * Stage C2 (remediation) - canonical idempotency second pass, six pilot sources.
 * Runs under WP-CLI so the Event Runtime gate is bypassed for importer queries.
 * One source at a time with rate-limit gaps; writes a verbose log.
 *
 * Usage: php c2-run3-background.php <log-file>  (run via: php wp-cli.phar eval-file ...)
 */
set_time_limit( 0 );
if ( ! defined( 'WPINC' ) ) { require '/var/www/html/wp-load.php'; }
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$log = isset( $args[0] ) ? $args[0] : '/tmp/c2-run3.log';
$fh  = fopen( $log, 'w' );
$p   = Conexao_Event_Importer::instance();
$sources = array( 'eventbrite_laois', 'eventbrite_cork', 'eventbrite_dublin', 'heritage_week_laois', 'heritage_week_cork', 'heritage_week_dublin' );
$gap = 45;

$w = function ( $line ) use ( $fh ) { fwrite( $fh, $line . "\n" ); fflush( $fh ); };

$w( '=== C2 REMEDIATION SECOND PASS START ' . current_time( 'c' ) . ' (WP_CLI=' . ( defined( 'WP_CLI' ) && WP_CLI ? 'yes' : 'no' ) . ') ===' );

foreach ( $sources as $sid ) {
	$s = microtime( true );
	try {
		$r = $p->importer->run_source( $sid, false );
		if ( ! is_array( $r ) ) { $r = array(); }
	} catch ( \Throwable $e ) {
		$w( $sid . ' FATAL: ' . $e->getMessage() );
		$r = array();
	}
	$w( $sid . ': found=' . ( $r['found'] ?? 0 )
		. ' created=' . ( $r['created'] ?? 0 )
		. ' updated=' . ( $r['updated'] ?? 0 )
		. ' unchanged=' . ( $r['unchanged'] ?? 0 )
		. ' dup=' . ( $r['duplicates'] ?? 0 )
		. ' skipped=' . ( $r['skipped'] ?? 0 )
		. ' past=' . ( $r['skipped_past'] ?? 0 )
		. ' invalid=' . ( $r['skipped_invalid_date'] ?? 0 )
		. ' failed=' . ( $r['failed'] ?? 0 )
		. ' status=' . ( $r['status'] ?? '?' )
		. ' elapsed=' . round( microtime( true ) - $s, 1 ) . 's' );
	if ( ! empty( $r['event_results'] ) ) {
		foreach ( $r['event_results'] as $e ) {
			$o = $e['outcome'] ?? '';
			if ( 'created' === $o || 'updated' === $o || 'duplicate' === $o ) {
				$w( '  ' . strtoupper( $o ) . ': ' . ( $e['title'] ?? '' ) . ' sid=' . ( $e['source_id'] ?? ( $e['extra']['source_id'] ?? '?' ) ) . ' id=' . ( $e['post_id'] ?? '?' ) );
			}
		}
	}
	foreach ( (array) ( $r['fatal_errors'] ?? array() ) as $fe ) {
		$w( '  FATAL: ' . ( $fe['message'] ?? '' ) );
	}
	if ( $sid !== end( $sources ) ) {
		$w( '  (sleeping ' . $gap . 's)' );
		sleep( $gap );
	}
}
$w( '=== C2 REMEDIATION SECOND PASS END ' . current_time( 'c' ) . ' ===' );
fclose( $fh );
echo "done\n";
