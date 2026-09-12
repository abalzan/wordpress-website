<?php
/**
 * Diagnose perpetual updates: compare stored meta vs incoming normalized data.
 */
require '/var/www/html/wp-load.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$sm      = new Conexao_Event_Sources();
$cfg     = $sm->get( 'eventbrite_laois' );
$handler = new Conexao_Source_Eventbrite( $cfg );
$raws    = $handler->fetch_events();
echo 'raw: ' . count( $raws ) . "\n";

$location   = new Conexao_Event_Location();
$normalizer = new Conexao_Event_Normalizer( $location );
$dedup      = new Conexao_Event_Deduplicator();

$engine   = Conexao_Event_Importer::instance()->importer;
$compared = 0;

foreach ( $raws as $raw ) {
	$raw['source'] = ! empty( $raw['source'] ) ? $raw['source'] : $cfg['id'];
	$normalized    = $normalizer->normalize( $raw );
	$date_check = Conexao_Event_Date_Filter::evaluate( $normalized );
	if ( Conexao_Event_Date_Filter::IMPORT !== $date_check['status'] ) {
		continue;
	}
	$existing_id = $dedup->find( $normalized );
	if ( ! $existing_id ) {
		continue;
	}

	$title     = get_the_title( $existing_id );
	$existing  = array(
		'title'       => $title,
		'start_date'  => get_post_meta( $existing_id, '_event_date', true ),
		'start_time'  => get_post_meta( $existing_id, '_event_start_time', true ),
		'end_date'    => get_post_meta( $existing_id, '_event_end_date', true ),
		'end_time'    => get_post_meta( $existing_id, '_event_end_time', true ),
		'source_url'  => get_post_meta( $existing_id, '_event_url', true ),
		'venue'       => get_post_meta( $existing_id, '_event_venue', true ),
		'organizer'   => get_post_meta( $existing_id, '_event_organizer', true ),
		'banner'      => get_post_meta( $existing_id, '_event_banner', true ),
		'address'     => get_post_meta( $existing_id, '_event_address', true ),
		'town'        => get_post_meta( $existing_id, '_event_town', true ),
		'ticket_url'  => get_post_meta( $existing_id, '_event_ticket_url', true ),
		'description' => get_post( $existing_id )->post_content,
	);
	$resolved  = Conexao_Event_Address::resolve_stored( $normalized['address'], $existing['address'] );
	$diffs     = array();
	$checkmap  = array(
		'title'      => $normalized['title'],
		'start_date' => $normalized['start_date'],
		'start_time' => $normalized['start_time'],
		'end_date'   => $normalized['end_date'],
		'end_time'   => $normalized['end_time'],
		'source_url' => $normalized['source_url'],
		'venue'      => $normalized['venue'],
		'organizer'  => $normalized['organizer'],
		'banner'     => $normalized['banner'],
		'address'    => $resolved,
		'town'       => $normalized['town'],
		'ticket_url' => $normalized['ticket_url'] ?? '',
	);
	foreach ( $checkmap as $field => $incoming ) {
		$stored = isset( $existing[ $field ] ) ? (string) $existing[ $field ] : '';
		if ( $stored !== (string) $incoming ) {
			$diffs[] = $field . ': [' . $stored . '] -> [' . $incoming . ']';
		}
	}
	// Description (excerpt-trimmed both sides like upsert does).
	if ( $existing['description'] !== $normalized['description'] ) {
		$diffs[] = 'description: differs (' . strlen( $existing['description'] ) . ' vs ' . strlen( $normalized['description'] ) . ' chars)';
	}

	if ( ! empty( $diffs ) && $compared < 4 ) {
		echo "\n" . $title . "\n";
		foreach ( $diffs as $d ) {
			echo '  ' . $d . "\n";
		}
		$compared++;
	}
}
echo "\ncompared sample: $compared\n";
