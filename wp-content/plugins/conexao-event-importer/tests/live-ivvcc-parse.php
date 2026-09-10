<?php
/**
 * LIVE read-only IVVCC parse validation (Stage B, section 46).
 *
 * Usage: docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/live-ivvcc-parse.php
 *
 * READ-ONLY: fetches the public IVVCC calendar + detail pages, parses and
 * normalizes them, and prints a summary. Nothing is written to WordPress:
 * no posts, no taxonomies, no images, no options.
 *
 * @package Conexao_Event_Importer
 */

$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$source = new Conexao_Source_Ivvcc( array(
	'url'         => 'https://www.ivvcc.ie/upcoming-events-calendar/',
	'crawl_delay' => 10,
) );

// Fetch the calendar once for discovery counting (read-only HTTP).
$ref  = new ReflectionMethod( 'Conexao_Source_Ivvcc', 'fetch_html' );
$ref->setAccessible( true );
$html = $ref->invoke( $source, 'https://www.ivvcc.ie/upcoming-events-calendar/' );

$cards = Conexao_Source_Ivvcc::parse_calendar_events( $html );
$ids   = array();
foreach ( $cards as $card ) {
	$ids[ $card['source_id'] ] = true;
}

$events = $source->fetch_events();

echo "IVVCC LIVE PARSE (read-only)\n";
echo 'calendar cards (raw):        ' . count( $cards ) . "\n";
echo 'unique EventON IDs:          ' . count( $ids ) . "\n";
echo 'parsed/normalized events:    ' . count( $events ) . "\n";
echo 'skipped (TBA/date not firm): ' . ( count( $ids ) - count( $events ) ) . "\n\n";

foreach ( $events as $event ) {
	printf(
		"IVVCC %s — %s — %s%s %s%s — %s — %s\n",
		$event['source_id'],
		$event['title'],
		$event['start_date'],
		$event['end_date'] ? ' -> ' . $event['end_date'] : '',
		$event['start_time'],
		$event['end_time'] ? '-' . $event['end_time'] : '',
		$event['location'],
		$event['url']
	);
}

echo "\nNo WordPress data was written.\n";
