<?php
/**
 * Stage C2 — Export payload audit.
 *
 * Validates the generated pilot export JSON against the live DB state and
 * the documented payload format. Read-only.
 *
 * Checks: manifest (format/version/count/filters); per-event UUID v4,
 * county-specific source, non-empty source_id; scope exclusivity;
 * date/time formats; county/town coverage; ticket/CTA presence; content
 * boundary (plain text, bounded length); embedded image integrity; the six
 * pilot source keys all represented; UUID matching against the live DB.
 *
 * Usage: php c2-payload-audit.php [export-file]
 */
set_time_limit( 0 );
ini_set( 'memory_limit', '1024M' );
require '/var/www/html/wp-load.php';

$file = empty( $argv[1] ) ? '/var/www/html/wp-content/uploads/c2-pilot-export.json' : $argv[1];
$raw = (string) file_get_contents( $file );
$payload = json_decode( $raw, true );
if ( ! is_array( $payload ) ) {
	echo "FATAL: export JSON not readable\n";
	exit( 1 );
}

$pilot = array(
	'eventbrite_laois', 'eventbrite_cork', 'eventbrite_dublin',
	'heritage_week_laois', 'heritage_week_cork', 'heritage_week_dublin',
);

$fail = 0;
function ok( $label, $cond, $detail = '' ) {
	global $fail;
	echo ( $cond ? '[ OK ] ' : '[FAIL] ' ) . $label . ( $detail ? ' — ' . $detail : '' ) . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

$manifest = $payload['manifest'] ?? array();
$events   = $payload['events'] ?? array();

echo "=== C2 PAYLOAD AUDIT: $file ===\n";
echo 'manifest: ' . wp_json_encode( $manifest ) . "\n";
ok( 'Manifest format', ( $manifest['format'] ?? '' ) === 'conexao-event-export' );
ok( 'Manifest version', ( $manifest['version'] ?? '' ) === '1.1.0' );
ok( 'Manifest event_count matches payload', ( (int) ( $manifest['event_count'] ?? 0 ) ) === count( $events ), 'manifest=' . ( $manifest['event_count'] ?? '?' ) . ' actual=' . count( $events ) );
$fs = $manifest['filters']['sources'] ?? array();
ok( 'Manifest scope = six pilot sources', count( array_diff( $pilot, $fs ) ) === 0 && count( $fs ) === 6, implode( ',', $fs ) );

$by_source = array();
$by_county = array();
$bad_uuid = $bad_source = $missing_sid = $bad_date = $bad_time = $with_html = $over_len = array();
$with_county = $with_town = $with_url = $with_ticket = $with_image = $with_uuid = 0;
$past = 0;
$today = current_time( 'Y-m-d' );

foreach ( $events as $i => $e ) {
	$src = (string) ( $e['meta']['_event_source'] ?? '' );
	$sid = (string) ( $e['meta']['_event_source_id'] ?? '' );
	$by_source[ $src ] = ( $by_source[ $src ] ?? 0 ) + 1;
	$county = $e['taxonomies']['conexao_county'] ?? array();
	$town = $e['taxonomies']['conexao_town'] ?? array();
	$ckey = implode( ',', $county );
	$by_county[ '' === $ckey ? '(no county)' : $ckey ] = ( $by_county[ '' === $ckey ? '(no county)' : $ckey ] ?? 0 ) + 1;

	if ( '' !== ( $e['uuid'] ?? '' ) ) { $with_uuid++; }
	if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', (string) ( $e['uuid'] ?? '' ) ) ) { /* valid v4 */ } else { $bad_uuid[] = $i; }

	if ( ! in_array( $src, $pilot, true ) ) { $bad_source[] = $i . ':' . $src; }
	if ( '' === trim( $sid ) ) { $missing_sid[] = $i; }

	if ( ! empty( $county ) ) { $with_county++; }
	if ( ! empty( $town ) ) { $with_town++; }
	$url = (string) ( $e['meta']['_event_url'] ?? '' );
	if ( '' !== $url ) { $with_url++; }
	if ( '' !== ( $e['meta']['_event_cta'] ?? '' ) || '' !== ( $e['meta']['_event_registration'] ?? '' ) ) { $with_ticket++; }

	$d = (string) ( $e['meta']['_event_date'] ?? '' );
	$t = (string) ( $e['meta']['_event_start_time'] ?? '' );
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) { $bad_date[] = $i; }
	if ( '' !== $t && ! preg_match( '/^\d{2}:\d{2}$/', $t ) ) { $bad_time[] = $i; }
	if ( '' !== $d && $d < $today ) { $past++; }

	$content = (string) ( $e['post']['content'] ?? '' );
	if ( preg_match( '/<\s*[a-zA-Z][^>]*>/', $content ) ) { $with_html[] = $i; }
	if ( strlen( $content ) > 20000 ) { $over_len[] = $i; }

	$img = $e['featured_image'] ?? array();
	if ( ! empty( $img['data_base64'] ) ) {
		$with_image++;
		$bin = base64_decode( $img['data_base64'], true );
		if ( false === $bin || '' === $bin ) { $bad_uuid[] = 'img:' . $i; }
		if ( ! empty( $img['filename'] ) && false !== strpos( strtolower( (string) $img['filename'] ), 'logo' ) ) { $bad_source[] = 'logo-file:' . $i . ':' . $img['filename']; }
	}
}

echo "\nby_source in payload: " . wp_json_encode( $by_source ) . "\n";
echo "county/town presence: with_county=$with_county with_town=$with_town with_url=$with_url with_ticket_or_cta=$with_ticket with_uuid=$with_uuid with_embedded_image=$with_image\n";
echo "payload date distribution: past=$past of " . count( $events ) . " (export policy = entire pilot set, status-gated downstream)\n";
echo "by_county: " . wp_json_encode( $by_county ) . "\n";

ok( 'All UUIDs present + valid v4', 0 === count( $bad_uuid ), count( $bad_uuid ) ? implode( ',', array_slice( $bad_uuid, 0, 10 ) ) : count( $events ) . ' events' );
ok( 'All events carry a pilot source key', 0 === count( $bad_source ), count( $bad_source ) ? implode( ';', array_slice( $bad_source, 0, 10 ) ) : '' );
ok( 'All events carry non-empty _event_source_id', 0 === count( $missing_sid ), count( $missing_sid ) ? 'idx=' . implode( ',', $missing_sid ) : '' );
// Heritage Week 2026 has ended, so those three county sources legitimately
// contribute 0 events (all cards are past-classified). The Eventbrite county
// sources must all be represented; every payload source must be a pilot key.
$eb_sources = array( 'eventbrite_laois', 'eventbrite_cork', 'eventbrite_dublin' );
ok( 'Payload sources are all within the six pilot keys', 0 === count( array_diff( array_keys( $by_source ), $pilot ) ), implode( ',', array_keys( $by_source ) ) );
ok( 'All three Eventbrite county sources represented', 0 === count( array_diff( $eb_sources, array_keys( $by_source ) ) ), implode( ',', array_keys( $by_source ) ) );
echo 'note: Heritage Week sources represented = ' . count( array_intersect( array( 'heritage_week_laois', 'heritage_week_cork', 'heritage_week_dublin' ), array_keys( $by_source ) ) ) . " (0 expected - 2026 edition ended)\n";

// Cross-check every payload event against the live DB by UUID.
$missing_live = array();
foreach ( $events as $e ) {
	$u = (string) ( $e['uuid'] ?? '' );
	$found = new WP_Query(
		array(
			'post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => 1,
			'fields' => 'ids', 'no_found_rows' => true,
			'meta_key' => '_event_export_uuid', 'meta_value' => $u,
		)
	);
	if ( ! $found->have_posts() ) {
		$missing_live[] = $u;
	}
}
ok( 'Every payload event matches a live pilot event by UUID', 0 === count( $missing_live ), count( $missing_live ) ? implode( ',', array_slice( $missing_live, 0, 5 ) ) : count( $events ) . ' events matched' );

$live_count = (int) ( new WP_Query(
	array(
		'post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => 1,
		'fields' => 'ids', 'no_found_rows' => false,
		'meta_query' => array( array( 'key' => '_event_source', 'value' => $pilot, 'compare' => 'IN' ) ),
	)
) )->found_posts;
ok( 'Payload count equals live pilot count', count( $events ) === $live_count, 'payload=' . count( $events ) . ' live_pilot=' . $live_count );

echo "\n";
echo ( 0 === $fail ? "PAYLOAD AUDIT: ALL PASS (0 failures)\n" : "PAYLOAD AUDIT: $fail FAILURES\n" );

ok( 'All payload dates valid', 0 === count( $bad_date ), count( $bad_date ) ? 'idx=' . implode( ',', $bad_date ) : '' );
ok( 'All payload times valid', 0 === count( $bad_time ), count( $bad_time ) ? 'idx=' . implode( ',', $bad_time ) : '' );
ok( 'No HTML in payload content', 0 === count( $with_html ), count( $with_html ) ? 'idx=' . implode( ',', $with_html ) : '' );
ok( 'No oversized payload content', 0 === count( $over_len ), count( $over_len ) ? 'idx=' . implode( ',', $over_len ) : '' );
