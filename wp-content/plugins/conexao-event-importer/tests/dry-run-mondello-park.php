<?php
/**
 * Mondello Park full read-only dry run.
 *
 * Temp-activates the local mondello_park source config (status=active),
 * runs a dry-run import, reports the result, then restores the original
 * inactive status. No WordPress writes occur (dry_run=true).
 *
 * Usage: docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/dry-run-mondello-park.php
 */

$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

// Conexao_Event_Importer is final and the engine is protected; reach the
// importer through the plugin's internal property path used by other tests.
$plugin   = Conexao_Event_Importer::instance();
$importer = $plugin->importer;
$sources  = new Conexao_Event_Sources();

echo "=== Mondello Park DRY-RUN ===" . PHP_EOL;
echo "Timestamp: " . date( 'Y-m-d H:i:s T' ) . PHP_EOL;

// Remember original status.
$orig = $sources->get( 'mondello_park' );
$orig_status = isset( $orig['status'] ) ? $orig['status'] : 'inactive';

// Activate locally for this dry run.
$orig['status'] = 'active';
$sources->save( $orig );

// Run dry-run.
$result = $importer->run_source( 'mondello_park', true );

// Restore original status.
$orig['status'] = $orig_status;
$sources->save( $orig );

echo PHP_EOL . "--- Result ---" . PHP_EOL;
if ( ! is_array( $result ) ) {
	echo "No result returned." . PHP_EOL;
	exit( 1 );
}

$counts = isset( $result['counts'] ) ? $result['counts'] : $result;
$found         = isset( $counts['found'] ) ? (int) $counts['found'] : 0;
$created       = isset( $counts['created'] ) ? (int) $counts['created'] : 0;
$updated       = isset( $counts['updated'] ) ? (int) $counts['updated'] : 0;
$unchanged     = isset( $counts['unchanged'] ) ? (int) $counts['unchanged'] : 0;
$duplicates    = isset( $counts['duplicates'] ) ? (int) $counts['duplicates'] : 0;
$skipped       = isset( $counts['skipped'] ) ? (int) $counts['skipped'] : 0;
$skipped_past  = isset( $counts['skipped_past'] ) ? (int) $counts['skipped_past'] : 0;
$skipped_invalid = isset( $counts['skipped_invalid_date'] ) ? (int) $counts['skipped_invalid_date'] : 0;
$failed        = isset( $counts['failed'] ) ? (int) $counts['failed'] : 0;
$status        = isset( $result['status'] ) ? $result['status'] : 'unknown';

echo sprintf(
	"found=%d created=%d updated=%d unchanged=%d duplicates=%d skipped=%d (past=%d, invalid_date=%d) failed=%d status=%s\n",
	$found, $created, $updated, $unchanged, $duplicates, $skipped, $skipped_past, $skipped_invalid, $failed, $status
);

if ( ! empty( $result['fatal_errors'] ) && is_array( $result['fatal_errors'] ) ) {
	echo "Fatal errors:" . PHP_EOL;
	foreach ( $result['fatal_errors'] as $err ) {
		$msg = isset( $err['message'] ) ? $err['message'] : '';
		echo "  - {$msg}" . PHP_EOL;
	}
}

if ( ! empty( $result['event_results'] ) && is_array( $result['event_results'] ) ) {
	echo PHP_EOL . "--- Per-event ---" . PHP_EOL;
	foreach ( $result['event_results'] as $evt ) {
		$title  = isset( $evt['title'] ) ? $evt['title'] : '-';
		$outcome = isset( $evt['outcome'] ) ? $evt['outcome'] : '-';
		$msg     = isset( $evt['message'] ) ? $evt['message'] : '';
		echo sprintf( "  %s [%s] %s" . PHP_EOL, $title, $outcome, $msg );
	}
}

echo PHP_EOL . "Dry-run complete. No writes performed." . PHP_EOL;

exit( 0 );
