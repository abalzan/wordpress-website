<?php
/**
 * Mondello Park local import (real write, LOCAL DB only).
 *
 * Temp-activates the local mondello_park source (status=active), runs a REAL
 * import into the local WordPress DB (creates 6 upcoming events + downloads
 * images into the local Media Library), then restores the original inactive
 * status. This is step 1 of the documented local → export → production
 * pipeline. No production contact happens here.
 *
 * Usage: cat scripts/mp-local-import.php | docker compose exec -T wordpress php
 */

$wp_load = '/var/www/html/wp-load.php';
require $wp_load;
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$plugin   = Conexao_Event_Importer::instance();
$importer = $plugin->importer;
$sources  = new Conexao_Event_Sources();

echo "=== Mondello Park LOCAL IMPORT ===" . PHP_EOL;
echo "Timestamp: " . gmdate( 'Y-m-d H:i:s' ) . " UTC" . PHP_EOL;

// Baseline: local event count before.
$before = wp_count_posts( 'event' );
echo "Local events before: publish={$before->publish} total={$before->publish}" . PHP_EOL;

$orig       = $sources->get( 'mondello_park' );
$orig_status = isset( $orig['status'] ) ? $orig['status'] : 'inactive';
echo "Source status before: {$orig_status}" . PHP_EOL;

if ( 'inactive' !== $orig_status ) {
	echo "UNEXPECTED: source is not inactive — aborting." . PHP_EOL;
	exit( 1 );
}

// Activate locally for this run.
$orig['status'] = 'active';
$sources->save( $orig );

$result = $importer->run_source( 'mondello_park', false );

// Always restore the original status.
$orig['status'] = $orig_status;
$sources->save( $orig );
echo "Source status restored: {$orig_status}" . PHP_EOL;

echo PHP_EOL . "--- Result ---" . PHP_EOL;
if ( ! is_array( $result ) ) {
	echo "No result returned." . PHP_EOL;
	exit( 1 );
}

$counts = isset( $result['counts'] ) ? $result['counts'] : $result;
foreach ( array( 'found', 'created', 'updated', 'unchanged', 'duplicates', 'skipped', 'skipped_past', 'skipped_invalid_date', 'failed' ) as $k ) {
	if ( isset( $counts[ $k ] ) ) {
		echo $k . '=' . $counts[ $k ] . PHP_EOL;
	}
}
echo 'status=' . ( isset( $result['status'] ) ? $result['status'] : '?' ) . PHP_EOL;

$after = wp_count_posts( 'event' );
echo "Local events after: publish={$after->publish}" . PHP_EOL;

// List created Mondello events.
$q = new WP_Query( array(
	'post_type'      => 'event',
	'post_status'    => 'any',
	'posts_per_page' => 20,
	'meta_query'     => array(
		array( 'key' => '_event_source', 'value' => 'mondellopark' ),
	),
	'orderby'        => 'ID',
	'order'          => 'ASC',
) );
echo PHP_EOL . "Mondello events in local DB:" . PHP_EOL;
foreach ( $q->posts as $p ) {
	echo sprintf(
		"  #%d | %s | %s | date=%s end=%s | url=%s | status=%s" . PHP_EOL,
		$p->ID,
		$p->post_name,
		$p->post_title,
		get_post_meta( $p->ID, '_event_date', true ),
		get_post_meta( $p->ID, '_event_end_date', true ),
		get_post_meta( $p->ID, '_event_url', true ),
		get_post_meta( $p->ID, '_event_status', true )
	);
}
