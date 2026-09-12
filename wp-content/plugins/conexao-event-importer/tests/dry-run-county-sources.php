<?php
/**
 * Dry-run import for all 52 county source registrations.
 *
 * Runs: wp conexao-events import --source=<source-key> --dry-run
 * for every configured county source.
 *
 * NO Event records are created or modified. NO production writes.
 *
 * Usage: docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/dry-run-county-sources.php
 */

$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

if ( php_sapi_name() !== 'cli' ) {
	echo "This script must be run from the command line.\n";
	exit( 1 );
}

echo "============================================================\n";
echo "County Source Dry-Run Imports\n";
echo "Date: " . date( 'Y-m-d H:i:s' ) . "\n";
echo "============================================================\n";

// Seed county sources first.
$sources_mgr = new Conexao_Event_Sources();
$sources_mgr->seed_county_sources();

$county_sources = Conexao_County_Registry::get_all_county_sources();
$plugin = Conexao_Event_Importer::instance();
$results = array();

foreach ( $county_sources as $id => $source ) {
	echo "\n--- {$id} ---\n";

	$start = microtime( true );

	try {
		$result = $plugin->importer->run_source( $id, true ); // dry-run = true
		$elapsed = round( microtime( true ) - $start, 2 );

		$status = isset( $result['status'] ) ? $result['status'] : 'unknown';
		$found = isset( $result['found'] ) ? (int) $result['found'] : 0;
		$parsed = isset( $result['parsed'] ) ? (int) $result['parsed'] : 0;
		$eligible = isset( $result['eligible'] ) ? (int) $result['eligible'] : 0;
		$created = isset( $result['created'] ) ? (int) $result['created'] : 0;
		$updated = isset( $result['updated'] ) ? (int) $result['updated'] : 0;
		$unchanged = isset( $result['unchanged'] ) ? (int) $result['unchanged'] : 0;
		$duplicates = isset( $result['duplicates'] ) ? (int) $result['duplicates'] : 0;
		$skipped = isset( $result['skipped'] ) ? (int) $result['skipped'] : 0;
		$failed = isset( $result['failed'] ) ? (int) $result['failed'] : 0;

		echo "  found={$found} parsed={$parsed} eligible={$eligible}\n";
		echo "  created={$created} updated={$updated} unchanged={$unchanged}\n";
		echo "  duplicates={$duplicates} skipped={$skipped} failed={$failed}\n";
		echo "  status={$status} elapsed={$elapsed}s\n";

		$results[ $id ] = array_merge( $result, array( 'elapsed' => $elapsed ) );

	} catch ( Exception $e ) {
		echo "  FATAL: " . $e->getMessage() . "\n";
		$results[ $id ] = array( 'status' => 'FATAL', 'error' => $e->getMessage() );
	}
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n============================================================\n";
echo "Dry-Run Summary\n";
echo "============================================================\n";

$total_found = 0;
$total_failed = 0;
$fatal_count = 0;

foreach ( $results as $id => $r ) {
	$total_found += isset( $r['found'] ) ? (int) $r['found'] : 0;
	$total_failed += isset( $r['failed'] ) ? (int) $r['failed'] : 0;
	if ( isset( $r['status'] ) && 'FATAL' === $r['status'] ) {
		$fatal_count++;
	}
}

echo "Total sources: " . count( $results ) . "\n";
echo "Total events found: {$total_found}\n";
echo "Total failures: {$total_failed}\n";
echo "Fatal errors: {$fatal_count}\n";
echo "Event records created: 0 (dry-run)\n";
echo "Event records modified: 0 (dry-run)\n";

// Save results.
$report_file = __DIR__ . '/dry-run-results.json';
file_put_contents( $report_file, wp_json_encode( $results, JSON_PRETTY_PRINT ) );
echo "\nResults saved to: {$report_file}\n";
