<?php
/**
 * Stage C2 — First pilot production export (scoped, local file only).
 *
 * Uses the existing Conexao_Event_Export mechanism (format 1.1.0) with the
 * new optional scope args. Writes the JSON to wp-content/uploads/ so the
 * payload audit (and the human reviewer) can inspect it. Nothing is sent
 * anywhere — production remains untouched.
 *
 * Usage: php c2-export.php [output-file]
 */
set_time_limit( 0 );
ini_set( 'memory_limit', '1024M' );
require '/var/www/html/wp-load.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$pilot_sources = array(
	'eventbrite_laois',
	'eventbrite_cork',
	'eventbrite_dublin',
	'heritage_week_laois',
	'heritage_week_cork',
	'heritage_week_dublin',
);

$out = empty( $argv[1] ) ? '/var/www/html/wp-content/uploads/c2-pilot-export.json' : $argv[1];

$export = new Conexao_Event_Export();
$payload = $export->build_export( array( 'sources' => $pilot_sources ) );

$json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
file_put_contents( $out, $json );

// Summary.
$manifest = $payload['manifest'];
$events   = $payload['events'];

$by_source     = array();
$by_county     = array();
$with_image    = 0;
$with_uuid     = 0;
$past          = 0;
$today         = current_time( 'Y-m-d' );

foreach ( $events as $e ) {
	$src = isset( $e['meta']['_event_source'] ) ? $e['meta']['_event_source'] : '(none)';
	$by_source[ $src ] = ( $by_source[ $src ] ?? 0 ) + 1;
	$county = isset( $e['taxonomies']['conexao_county'] ) ? implode( ',', $e['taxonomies']['conexao_county'] ) : '';
	$by_county[ $county ] = ( $by_county[ $county ] ?? 0 ) + 1;
	if ( ! empty( $e['featured_image']['data_base64'] ) ) {
		$with_image++;
	}
	if ( ! empty( $e['uuid'] ) ) {
		$with_uuid++;
	}
	$d = isset( $e['meta']['_event_date'] ) ? $e['meta']['_event_date'] : '';
	if ( '' !== $d && $d < $today ) {
		$past++;
	}
}

echo "=== C2 PILOT EXPORT WRITTEN ===\n";
echo 'file=' . $out . "\n";
echo 'bytes=' . strlen( $json ) . "\n";
echo 'format=' . $manifest['format'] . ' version=' . $manifest['version'] . "\n";
echo 'event_count=' . $manifest['event_count'] . "\n";
echo 'sources filter=' . implode( ',', $manifest['filters']['sources'] ?? array() ) . "\n";
echo "by_source:\n";
foreach ( $by_source as $s => $n ) {
	echo "  $s: $n\n";
}
echo "by_county:\n";
foreach ( $by_county as $s => $n ) {
	echo "  '$s': $n\n";
}
echo "with_embedded_image=$with_image with_uuid=$with_uuid past_events_included=$past\n";
