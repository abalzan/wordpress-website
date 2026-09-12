<?php
/**
 * Stage C2 — Clean idempotency verification run (post-fix).
 *
 * Runs the five remaining pilot sources sequentially with rate-limit gaps.
 */
set_time_limit( 0 );
require '/var/www/html/wp-load.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$p       = Conexao_Event_Importer::instance();
$sources = array( 'eventbrite_cork', 'eventbrite_dublin', 'heritage_week_laois', 'heritage_week_cork', 'heritage_week_dublin' );
$gap     = 45;

function c2_one_report( $sid, $r, $elapsed ) {
	echo $sid . ': found=' . ( $r['found'] ?? 0 )
		. ' created=' . ( $r['created'] ?? 0 )
		. ' updated=' . ( $r['updated'] ?? 0 )
		. ' unchanged=' . ( $r['unchanged'] ?? 0 )
		. ' dup=' . ( $r['duplicates'] ?? 0 )
		. ' past=' . ( $r['skipped_past'] ?? 0 )
		. ' failed=' . ( $r['failed'] ?? 0 )
		. ' status=' . ( $r['status'] ?? '?' )
		. ' elapsed=' . round( $elapsed, 1 ) . "s\n";
	if ( ! empty( $r['fatal_errors'] ) ) {
		foreach ( $r['fatal_errors'] as $fe ) { echo '  FATAL: ' . ( $fe['message'] ?? '' ) . "\n"; }
	}
	flush();
}

echo '=== C2 CLEAN VERIFICATION RUN START ' . current_time( 'c' ) . " ===\n";
flush();
foreach ( $sources as $sid ) {
	$s = microtime( true );
	try {
		$r = $p->importer->run_source( $sid, false );
		c2_one_report( $sid, is_array( $r ) ? $r : array(), microtime( true ) - $s );
	} catch ( \Throwable $e ) {
		echo $sid . ' FATAL: ' . $e->getMessage() . "\n";
		flush();
	}
	if ( $sid !== end( $sources ) ) {
		echo "  (sleeping {$gap}s)\n";
		flush();
		sleep( $gap );
	}
}
echo '=== C2 CLEAN VERIFICATION RUN END ' . current_time( 'c' ) . " ===\n";
