<?php
/**
 * Stage C2 — Idempotency re-run: the same six pilot live imports, second pass.
 *
 * Runs sequentially, one source at a time, with a fixed gap between sources
 * to respect provider rate limiting (C1 §4.3). Logs full per-source results.
 *
 * Usage: php c2-live-import-pilot.php
 */
set_time_limit( 0 );
require '/var/www/html/wp-load.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$p       = Conexao_Event_Importer::instance();
$sources = array(
	'eventbrite_laois',
	'eventbrite_cork',
	'eventbrite_dublin',
	'heritage_week_laois',
	'heritage_week_cork',
	'heritage_week_dublin',
);
$gap     = 45; // seconds between sources (rate-limit courtesy).

function c2_report( $sid, $r, $elapsed ) {
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
		. ' elapsed=' . round( $elapsed, 1 ) . "s\n";

	if ( ! empty( $r['event_results'] ) ) {
		$shown = 0;
		foreach ( $r['event_results'] as $e ) {
			if ( 'skipped' === $e['outcome'] && $shown < 3 ) {
				echo '  SKIP: ' . $e['title'] . ' — ' . ( $e['message'] ?? '' ) . "\n";
				$shown++;
			}
			if ( 'updated' === $e['outcome'] ) {
				echo '  UPDATED: ' . $e['title'] . ' (id=' . ( $e['post_id'] ?? '?' ) . ')\n';
			}
			if ( 'created' === $e['outcome'] ) {
				echo '  CREATED: ' . $e['title'] . ' (id=' . ( $e['post_id'] ?? '?' ) . ')\n';
			}
		}
	}
	if ( ! empty( $r['fatal_errors'] ) ) {
		foreach ( $r['fatal_errors'] as $fe ) {
			echo '  FATAL: ' . ( $fe['message'] ?? '' ) . "\n";
		}
	}
	flush();
}

echo '=== C2 SECOND-RUN (idempotency) START ' . current_time( 'c' ) . " ===\n";
flush();

foreach ( $sources as $sid ) {
	$s = microtime( true );
	try {
		$r = $p->importer->run_source( $sid, false );
		c2_report( $sid, is_array( $r ) ? $r : array(), microtime( true ) - $s );
	} catch ( \Throwable $e ) {
		echo $sid . ' FATAL: ' . $e->getMessage() . "\n";
		flush();
	}
	if ( $sid !== end( $sources ) ) {
		echo "  (sleeping {$gap}s before next source)\n";
		flush();
		sleep( $gap );
	}
}
echo '=== C2 SECOND-RUN END ' . current_time( 'c' ) . " ===\n";
