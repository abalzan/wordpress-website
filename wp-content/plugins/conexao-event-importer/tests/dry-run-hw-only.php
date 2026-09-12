<?php
set_time_limit( 120 );
require '/var/www/html/wp-load.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';
$p = Conexao_Event_Importer::instance();
$sources = array( 'heritage_week_laois', 'heritage_week_cork', 'heritage_week_dublin');
foreach ( $sources as $sid ) {
	$s      = microtime( true );
	$r      = $p->importer->run_source( $sid, true );
	$elapsed = round( microtime( true ) - $s, 1 );
	echo $sid . ': found=' . ( $r['found'] ?? 0 ) . ' created=' . ( $r['created'] ?? 0 ) . ' updated=' . ( $r['updated'] ?? 0 ) . ' failed=' . ( $r['failed'] ?? 0 ) . ' skipped=' . ( $r['skipped'] ?? 0 ) . ' status=' . ( $r['status'] ?? '?' ) . ' elapsed=' . $elapsed . "s\n";
	if ( ! empty( $r['event_results'] ) ) {
		echo "  First 3:\n";
		foreach ( array_slice( $r['event_results'], 0, 3 ) as $e ) {
			echo '    ' . $e['title'] . ' -> ' . $e['outcome'] . "\n";
		}
	}
	if ( ! empty( $r['fatal_errors'] ) ) {
		foreach ( $r['fatal_errors'] as $fe ) {
			echo '  FATAL: ' . $fe['message'] . "\n";
		}
	}
}
