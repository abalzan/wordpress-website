<?php
/**
 * Stage C2 — Targeted is_unchanged diagnosis for specific post IDs.
 *
 * Re-fetches the source live, normalizes, finds the raw record whose
 * source_id matches the stored _event_source_id, and prints every field
 * the engine's is_unchanged() compares: stored vs incoming.
 *
 * Usage: php c2-diagnose-one.php <post_id> <source_id>
 */
set_time_limit( 0 );
require '/var/www/html/wp-load.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$pid = (int) ( $argv[1] ?? 0 );
$sid = $argv[2] ?? '';
if ( ! $pid || '' === $sid ) {
	echo "usage: php c2-diagnose-one.php <post_id> <source_slug>\n";
	exit( 1 );
}

$sm      = new Conexao_Event_Sources();
$cfg     = $sm->get( $sid );
$handler = new Conexao_Source_Eventbrite( $cfg );
$raws    = $handler->fetch_events();
echo 'raw: ' . count( $raws ) . "\n";

$stored_sid = get_post_meta( $pid, '_event_source_id', true );
$raw = null;
foreach ( $raws as $r ) {
	if ( (string) ( $r['id'] ?? '' ) === (string) $stored_sid ) {
		$raw = $r;
		break;
	}
}
if ( ! $raw ) {
	echo "raw record not found for source_id=$stored_sid\n";
	exit( 1 );
}

$raw['source'] = $cfg['id'];
$n = ( new Conexao_Eventbrite_Normalizer() )->normalize( $raw );

$existing = array(
	'title'   => get_the_title( $pid ),
	'date'    => (string) get_post_meta( $pid, '_event_date', true ),
	'time'    => (string) get_post_meta( $pid, '_event_start_time', true ),
	'url'     => (string) get_post_meta( $pid, '_event_url', true ),
	'venue'   => (string) get_post_meta( $pid, '_event_venue', true ),
	'org'     => (string) get_post_meta( $pid, '_event_organizer', true ),
	'banner'  => (string) get_post_meta( $pid, '_event_banner', true ),
	'address' => (string) get_post_meta( $pid, '_event_address', true ),
);
$resolved = Conexao_Event_Address::resolve_stored( $n['address'], $existing['address'] );

$incoming = array(
	'title'   => $n['title'],
	'date'    => $n['start_date'],
	'time'    => $n['start_time'],
	'url'     => $n['source_url'],
	'venue'   => $n['venue'],
	'org'     => $n['organizer'],
	'banner'  => $n['banner'],
	'address' => $resolved,
);

$is_unchanged = true;
foreach ( $existing as $k => $v ) {
	$eq = ( $v === $incoming[ $k ] );
	if ( ! $eq ) {
		$is_unchanged = false;
	}
	echo str_pad( $k, 10 ) . ' stored=[' . $v . "]\n"
		. str_pad( '', 10 ) . ' incoming=[' . $incoming[ $k ] . '] ' . ( $eq ? 'EQUAL' : '** DIFF **' ) . "\n";
}
echo 'is_unchanged=' . ( $is_unchanged ? 'TRUE' : 'FALSE' ) . "\n";
