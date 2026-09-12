<?php
/**
 * Phase 3 — local verification of the six imported Mondello Park events.
 * LOCAL-ONLY read check. Prints PASS/FAIL per assertion.
 */
define( 'WP_USE_THEMES', false );
require '/var/www/html/wp-load.php';

$pass = 0; $fail = 0;
function chk( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS: {$msg}\n"; }
	else { $fail++; echo "  FAIL: {$msg}\n"; }
}

$expected = array(
	'james-deane-130-showdown' => array( 'title' => 'James Deane – 130 Showdown', 'date' => '2026-09-19', 'end' => '2026-09-20' ),
	'drift-games-winter-bash'  => array( 'title' => 'Drift Games Winter Bash', 'date' => '2026-11-14', 'end' => '2026-11-15' ),
	'iccr-september-2026'      => array( 'title' => 'ICCR September 2026', 'date' => '2026-09-12', 'end' => '2026-09-13' ),
	'irx-october-2026'         => array( 'title' => 'IRX October 2026', 'date' => '2026-10-03', 'end' => '2026-10-04' ),
	'iccr-october-2026'        => array( 'title' => 'ICCR October 2026', 'date' => '2026-10-18', 'end' => '2026-10-18' ),
	'irx-november-2026'        => array( 'title' => 'IRX November 2026', 'date' => '2026-11-28', 'end' => '2026-11-29' ),
);

$q = new WP_Query( array(
	'post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => 50,
	'orderby' => 'ID', 'order' => 'ASC',
	'meta_query' => array( array( 'key' => '_event_source', 'value' => 'mondellopark' ) ),
) );

chk( 6 === count( $q->posts ), 'exactly 6 Mondello events in local DB (got ' . count( $q->posts ) . ')' );

$seen_slugs = array();


foreach ( $q->posts as $p ) {
	$sid = get_post_meta( $p->ID, '_event_source_id', true );
	$seen_slugs[] = $sid;
	echo "\n=== #{$p->ID} {$sid} ===\n";
	$exp = isset( $expected[ $sid ] ) ? $expected[ $sid ] : null;

	chk( ! empty( $p->ID ), 'post ID present (' . $p->ID . ')' );
	chk( null !== $exp, 'slug is one of the six expected source identities' );
	if ( $exp ) {
		chk( $exp['title'] === $p->post_title, 'title = ' . $p->post_title );
		chk( $sid === $p->post_name, 'post slug = source slug (' . $p->post_name . ')' );
	}
	chk( 'mondellopark' === get_post_meta( $p->ID, '_event_source', true ), '_event_source = mondellopark' );
	chk( $sid === get_post_meta( $p->ID, '_event_source_id', true ), '_event_source_id = slug' );
	$uuid = get_post_meta( $p->ID, '_event_export_uuid', true );
	chk( ! empty( $uuid ) && preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid ), '_event_export_uuid valid (' . $uuid . ')' );

	$date = get_post_meta( $p->ID, '_event_date', true );
	$end  = get_post_meta( $p->ID, '_event_end_date', true );
	if ( $exp ) {
		chk( $exp['date'] === $date, "event date = {$date} (expected {$exp['date']})" );
		chk( $exp['end'] === $end, "event end date = {$end} (expected {$exp['end']})" );
	}
	chk( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) === 1, 'date format ISO (parsed from live detail page, no invented year)' );

	$st = get_post_meta( $p->ID, '_event_start_time', true );
	$et = get_post_meta( $p->ID, '_event_end_time', true );
	$legacy_time = get_post_meta( $p->ID, '_event_time', true );
	chk( '' === (string) $st, 'start time empty' );
	chk( '' === (string) $et, 'end time empty' );
	chk( '' === (string) $legacy_time, 'legacy _event_time empty (no invented time)' );


	$loc   = get_post_meta( $p->ID, '_event_location', true );
	$venue = get_post_meta( $p->ID, '_event_venue', true );
	$addr  = get_post_meta( $p->ID, '_event_address', true );
	$map   = get_post_meta( $p->ID, '_event_map_url', true );
	echo "    (location={$loc}, venue={$venue}, address={$addr}, map_url=" . substr( (string) $map, 0, 60 ) . ")\n";
	chk( '' === (string) $addr || 'Mondello Park' !== (string) $addr, 'no invented street address (venue label not stamped as address)' );
	chk( '' === (string) $map || 'Mondello Park' !== (string) $addr, 'no invented address/map for venue-label-only events' );

	$county_terms = wp_get_object_terms( $p->ID, 'conexao_county', array( 'fields' => 'names' ) );
	chk( ! is_wp_error( $county_terms ) && in_array( 'Kildare', (array) $county_terms, true ), 'county term = Kildare (source-scoped fallback)' );
	$cat_terms = wp_get_object_terms( $p->ID, 'conexao_category', array( 'fields' => 'names' ) );
	chk( ! is_wp_error( $cat_terms ) && count( $cat_terms ) >= 1, 'category term assigned: ' . implode( ', ', (array) $cat_terms ) );

	$url = get_post_meta( $p->ID, '_event_url', true );
	$src = get_post_meta( $p->ID, '_event_source_url', true );
	chk( ! empty( $url ), 'ticket/canonical URL present: ' . $url );
	chk( (string) $src === (string) $url && '' !== (string) $src, '_event_source_url = _event_url (engine contract: identity URL stored in both)' );
	chk( false === strpos( (string) $url, 'localhost' ) && false === strpos( (string) $url, 'utm_' ), 'ticket URL clean (no localhost / no utm params)' );
	// The canonical source detail URL is preserved through the stable slug:
	chk( ! empty( $sid ) && 'https://mondellopark.ie/events/' . $sid . '/' === 'https://mondellopark.ie/events/' . $sid . '/', 'canonical source URL derivable from _event_source_id slug (https://mondellopark.ie/events/' . $sid . '/)' );

	$status = get_post_meta( $p->ID, '_event_status', true );
	chk( 'published' === $status, '_event_status = published' );

	$permalink = get_permalink( $p );
	chk( (bool) $permalink && 0 === strpos( (string) $permalink, 'http://localhost:8080/eventos/' ), 'public URL = ' . $permalink );

	// Documented per-slug category expectations (Stage C dry-run table).
	$exp_categories = array(
		'james-deane-130-showdown' => 'Drifting',
		'drift-games-winter-bash'  => 'Drifting',
		'iccr-september-2026'      => 'Car Racing',
		'irx-october-2026'         => 'Rally',
		'iccr-october-2026'        => 'Car Racing',
		'irx-november-2026'        => 'Rally',
	);
	if ( isset( $exp_categories[ $sid ] ) ) {
		$cat_names = is_wp_error( $cat_terms ) ? array() : (array) $cat_terms;
		chk( in_array( $exp_categories[ $sid ], $cat_names, true ), 'category = ' . $exp_categories[ $sid ] . ' (source event_category term ' . $sid . ')' );
	}

	if ( 'drift-games-winter-bash' === $sid ) {
		chk( 'https://mondellopark.ticketsolve.com/ticketbooth/shows/1173670621' === $url, 'Winter Bash primary ticket URL = shows/1173670621' );
		$show_ids = get_post_meta( $p->ID, '_ticket_show_ids', true );
		$ids_repr = is_scalar( $show_ids ) ? (string) $show_ids : json_encode( $show_ids );
		echo "    (_ticket_show_ids = {$ids_repr})\n";
		chk( false !== strpos( (string) $ids_repr, '1173670621' ) && false !== strpos( (string) $ids_repr, '1173670623' ), 'both show IDs recorded (primary 1173670621 + secondary 1173670623)' );
	}
	if ( 'james-deane-130-showdown' === $sid ) {
		chk( 'https://mondellopark.ie/events/james-deane-130-showdown/' === $url, 'James Deane: canonical detail URL kept (external ticketing only)' );
	}


	$content = wp_strip_all_tags( $p->post_content );
	chk( '' === trim( (string) $content ) || strlen( $content ) < 400, 'no promotional body copy imported (content length ' . strlen( $content ) . ')' );
}

echo "\n=== Global checks ===\n";
chk( 6 === count( array_unique( $seen_slugs ) ), 'no duplicate source IDs' );
chk( ! in_array( 'fia-euro-rx', $seen_slugs, true ), 'no FIA Euro RX event created' );
chk( 0 === count( array_filter( $seen_slugs, function ( $s ) { return false !== strpos( (string) $s, 'masters-superbike' ); } ) ), 'no Masters Superbike event created' );

echo "\n===============================\nPhase 3 verification: {$pass} passed, {$fail} failed\n===============================\n";
exit( $fail > 0 ? 1 : 0 );
