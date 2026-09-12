<?php
/**
 * Stage C1 — Heritage Week live imports (one source at a time).
 */
set_time_limit( 0 );
require '/var/www/html/wp-load.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$p       = Conexao_Event_Importer::instance();
$sources = array( 'heritage_week_laois', 'heritage_week_cork', 'heritage_week_dublin' );

foreach ( $sources as $sid ) {
	$s = microtime( true );
	try {
		$r = $p->importer->run_source( $sid, false );
		echo $sid . ': found=' . ( $r['found'] ?? 0 )
			. ' created=' . ( $r['created'] ?? 0 )
			. ' updated=' . ( $r['updated'] ?? 0 )
			. ' unchanged=' . ( $r['unchanged'] ?? 0 )
			. ' dup=' . ( $r['duplicates'] ?? 0 )
			. ' skipped=' . ( $r['skipped'] ?? 0 )
			. ' past=' . ( $r['skipped_past'] ?? 0 )
			. ' invalid=' . ( $r['skipped_invalid_date'] ?? 0 )
			. ' failed=' . ( $r['failed'] ?? 0 )
			. ' status=' . ( $r['status'] ?? '?' )
			. ' elapsed=' . round( microtime( true ) - $s, 1 ) . "s\n";

		if ( ! empty( $r['event_results'] ) ) {
			$shown = 0;
			foreach ( $r['event_results'] as $e ) {
				if ( 'skipped' === $e['outcome'] && $shown < 3 ) {
					echo '  SKIP: ' . $e['title'] . ' — ' . $e['message'] . "\n";
					$shown++;
				}
			}
		}
		if ( ! empty( $r['fatal_errors'] ) ) {
			foreach ( $r['fatal_errors'] as $fe ) {
				echo '  FATAL: ' . ( $fe['message'] ?? '' ) . "\n";
			}
		}
	} catch ( \Throwable $e ) {
		echo $sid . ' FATAL: ' . $e->getMessage() . "\n";
	}
	flush();
}
echo "DONE\n";
