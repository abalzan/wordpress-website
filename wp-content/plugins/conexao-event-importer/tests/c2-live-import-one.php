<?php
/**
 * Stage C2 — Single-source live import runner (for HW Dublin rate-limit retry).
 *
 * Usage: php c2-live-import-one.php <source_id>
 */
set_time_limit( 0 );
require '/var/www/html/wp-load.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$sid = isset( $argv[1] ) ? $argv[1] : '';
if ( '' === $sid ) {
	echo "usage: php c2-live-import-one.php <source_id>\n";
	exit( 1 );
}

$p = Conexao_Event_Importer::instance();
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
	if ( ! empty( $r['fatal_errors'] ) ) {
		foreach ( $r['fatal_errors'] as $fe ) {
			echo '  FATAL: ' . ( $fe['message'] ?? '' ) . "\n";
		}
	}
} catch ( \Throwable $e ) {
	echo $sid . ' FATAL: ' . $e->getMessage() . "\n";
}
