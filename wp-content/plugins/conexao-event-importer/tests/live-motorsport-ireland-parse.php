<?php
/**
 * LIVE read-only Motorsport Ireland parse validation (Stage B).
 *
 * Usage: docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/live-motorsport-ireland-parse.php
 *
 * READ-ONLY: fetches the public master calendar + a bounded number of detail
 * pages (3, 2 s apart), parses and normalizes them, and prints representative
 * samples. Nothing is written to WordPress: no posts, no taxonomies, no
 * images, no options.
 */

$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$source = new Conexao_Source_Motorsport_Ireland( array(
	'url'             => 'https://www.motorsportireland.com/events',
	'max_detail_pages' => 3,
	'crawl_delay'      => 2,
) );

$ref  = new ReflectionMethod( 'Conexao_Source_Motorsport_Ireland', 'fetch_html' );
$ref->setAccessible( true );
$html = $ref->invoke( $source, 'https://www.motorsportireland.com/events' );

$cards = Conexao_Source_Motorsport_Ireland::parse_listing_cards( $html );

$uids = array();
foreach ( $cards as $card ) {
	$uids[ $card['source_id'] ] = true;
}

$events = $source->fetch_events();

$location   = new Conexao_Event_Location();
$normalizer = new Conexao_Event_Normalizer( $location );

echo "MOTORSPORT IRELAND LIVE PARSE (read-only)\n";
echo 'listing cards (raw):      ' . count( $cards ) . "\n";
echo 'unique hex UIDs:          ' . count( $uids ) . "\n";
echo 'parsed/normalized events: ' . count( $events ) . "\n";
echo 'with club attribution:    ' . count( array_filter( $events, function ( $e ) { return '' !== $e['organizer']; } ) ) . "\n";
echo 'multi-day flagged:        ' . count( array_filter( $events, function ( $e ) { return ! empty( $e['_is_multiday'] ); } ) ) . "\n";
echo 'cancelled (dropped):      ' . count( array_filter( $cards, function ( $e ) { return ! empty( $e['_skip'] ); } ) ) . "\n\n";

echo "Representative normalized samples:\n";
$shown_multiday = false;
foreach ( $events as $i => $event ) {
	$normalized = $normalizer->normalize( $event );
	$is_multiday = '' !== $normalized['end_date'];
	if ( $i < 4 || ( $is_multiday && ! $shown_multiday ) ) {
		$shown_multiday = $shown_multiday || $is_multiday;
		printf(
			"  UID %s | %s | %s %s%s | club=%s | cat=%s | venue=%s | loc-optional=%s\n",
			$normalized['source_id'],
			$normalized['title'],
			$normalized['start_date'],
			$normalized['start_time'],
			$is_multiday ? ' -> ' . $normalized['end_date'] . ' ' . $normalized['end_time'] : '',
			$normalized['organizer'] ? $normalized['organizer'] : '(none)',
			$normalized['category'] ? $normalized['category'] : '(none)',
			$normalized['venue'] ? $normalized['venue'] : '(empty)',
			! empty( $event['location_optional'] ) ? 'yes' : 'no'
		);
	}
}

echo "\nNo WordPress data was written.\n";
