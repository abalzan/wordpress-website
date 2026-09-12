<?php
/**
 * Stage C1 Pilot Dry-Run — all 6 pilot sources.
 *
 * Runs each source independently with set_time_limit(0) to avoid
 * the docker exec 30s default timeout on large-county pagination.
 *
 * Usage: docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/dry-run-c1-pilot.php
 */

set_time_limit( 0 );

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

$pilots = array(
	'eventbrite_laois',
	'eventbrite_cork',
	'eventbrite_dublin',
	'heritage_week_laois',
	'heritage_week_cork',
	'heritage_week_dublin',
);

$plugin   = Conexao_Event_Importer::instance();
$results  = array();

foreach ( $pilots as $id ) {
	echo "\n=== $id (dry-run) ===\n";
	$start = microtime( true );

	try {
		$result    = $plugin->importer->run_source( $id, true );
		$elapsed   = round( microtime( true ) - $start, 1 );
		$results[] = array_merge( $result, array( 'elapsed' => $elapsed, 'source' => $id ) );

		echo '  found=' . ( $result['found'] ?? 0 ) . "\n";
		echo '  created=' . ( $result['created'] ?? 0 ) . ' updated=' . ( $result['updated'] ?? 0 ) . ' unchanged=' . ( $result['unchanged'] ?? 0 ) . "\n";
		echo '  duplicates=' . ( $result['duplicates'] ?? 0 ) . ' skipped=' . ( $result['skipped'] ?? 0 ) . ' failed=' . ( $result['failed'] ?? 0 ) . "\n";
		echo '  status=' . ( $result['status'] ?? '?' ) . " elapsed={$elapsed}s\n";

		if ( ! empty( $result['fatal_errors'] ) ) {
			foreach ( $result['fatal_errors'] as $e ) {
				echo '  FATAL: ' . ( $e['message'] ?? '' ) . "\n";
			}
		}
	} catch ( Exception $e ) {
		echo '  FATAL: ' . $e->getMessage() . "\n";
		$results[] = array( 'source' => $id, 'status' => 'FATAL', 'error' => $e->getMessage() );
	}
}

echo "\n=== SUMMARY ===\n";
foreach ( $results as $r ) {
	echo sprintf(
		"  %-24s found=%-5d created=%-5d updated=%-5s unchanged=%-5s dup=%-5s skip=%-5s fail=%-5s status=%s\n",
		$r['source'],
		$r['found'] ?? 0,
		$r['created'] ?? 0,
		$r['updated'] ?? 0,
		$r['unchanged'] ?? 0,
		$r['duplicates'] ?? 0,
		$r['skipped'] ?? 0,
		$r['failed'] ?? 0,
		$r['status'] ?? '?'
	);
}
echo "\nEvent records created: 0 (dry-run)\n";
echo "Event records modified: 0 (dry-run)\n";
