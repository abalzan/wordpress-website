<?php
/**
 * Verify the imported events in the database.
 */

$wp_load = dirname( __DIR__ ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

global $wpdb;

$table = $wpdb->posts;
$rows  = $wpdb->get_results( "SELECT ID, post_title, post_status FROM {$table} WHERE post_type = 'event'" );

echo 'All events in DB: ' . count( $rows ) . PHP_EOL;

foreach ( $rows as $r ) {
	$status = get_post_meta( $r->ID, '_event_status', true );
	$status = $status ? $status : '(none)';
	$src    = get_post_meta( $r->ID, '_event_source', true );
	$src    = $src ? $src : 'manual';
	$date   = get_post_meta( $r->ID, '_event_date', true );
	$url    = get_post_meta( $r->ID, '_event_url', true );
	$import = get_post_meta( $r->ID, '_event_imported', true );

	echo '- ID=' . $r->ID . ' | post_status=' . $r->post_status . ' | src=' . $src . ' | event_status=' . $status . ' | imported=' . ( $import ? 'yes' : 'no' ) . ' | ' . $r->post_title . ' | date=' . $date . PHP_EOL;
	if ( $url ) {
		echo '    URL: ' . $url . PHP_EOL;
	}
}