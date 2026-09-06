<?php
/**
 * Automated tests for event importer address support.
 *
 * Usage: docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/test-event-address.php
 *
 * These tests use saved HTML fixtures and pure class logic and do NOT make
 * live requests to external sources.
 *
 * @package Conexao_Event_Importer
 */

$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

// Ensure plugin classes are loaded.
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$fixtures_dir = __DIR__ . '/fixtures';
$address_fixtures_dir = $fixtures_dir . '/address';

$passed = 0;
$failed = 0;

function test_assert( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		echo "  PASS: {$message}\n";
	} else {
		$failed++;
		echo "  FAIL: {$message}\n";
	}
}

function test_section( $title ) {
	echo "\n=== {$title} ===\n";
}

// ---------------------------------------------------------------------------
// 1. Address normalization
// ---------------------------------------------------------------------------
test_section( 'Address Normalization' );

test_assert(
	'Jones Road, Dublin 3, D03 P0K7' === Conexao_Event_Address::normalize( "  Jones Road,\n\t Dublin  3,  D03 P0K7 \n" ),
	'Whitespace and line breaks collapse to a single-line address'
);

test_assert(
	'Church Street, Portlaoise' === Conexao_Event_Address::normalize( 'Church &omicron; Street&#44; Portlaoise' === '' ? '' : 'Church&nbsp;Street,   Portlaoise' ),
	'HTML entities are decoded (nbsp)'
);

test_assert(
	'Main Street, Portlaoise, R32 XW63' === Conexao_Event_Address::normalize( 'Main Street,, Portlaoise, , , R32 XW63' ),
	'Duplicate comma separators collapse'
);

test_assert(
	'Unit 5, Shopping Centre, Portlaoise' === Conexao_Event_Address::normalize( "<p>Unit 5</p><br>Shopping Centre<br/>Portlaoise" ),
	'Unit/apartment info and <br>/<p> markup preserved as separators, not removed'
);

test_assert(
	'&Aacute;tha Cliath' === Conexao_Event_Address::normalize( '&Aacute;tha Cliath' ) || 'Átha Cliath' === Conexao_Event_Address::normalize( '&Aacute;tha Cliath' ),
	'Entities decoded without destroying non-ASCII text'
);

test_assert(
	'' === Conexao_Event_Address::normalize( '   ' ) && '' === Conexao_Event_Address::normalize( '' ),
	'Empty/whitespace-only values normalize to empty'
);

test_assert(
	', ,, Main Street, ' === '' || 'Main Street' === Conexao_Event_Address::normalize( ', ,, Main Street, ' ),
	'Leading/trailing stray separators are trimmed'
);

// ---------------------------------------------------------------------------
// 2. Plausibility / no-guess validation
// ---------------------------------------------------------------------------
test_section( 'Plausibility / No-Guess Validation' );

test_assert( ! Conexao_Event_Address::is_plausible( 'Dublin' ), '"Dublin" alone is NOT an address' );
test_assert( ! Conexao_Event_Address::is_plausible( 'Croke Park' ), '"Croke Park" alone is NOT an address' );
test_assert( ! Conexao_Event_Address::is_plausible( 'Portlaoise' ), 'A bare town name is NOT an address' );
test_assert( Conexao_Event_Address::is_plausible( 'Jones Road, Dublin 3, D03 P0K7' ), 'Full supplied street address is plausible' );
test_assert( Conexao_Event_Address::is_plausible( 'Stradbally Hall, Stradbally, Co. Laois' ), 'Venue + town + county marker is plausible' );
test_assert( Conexao_Event_Address::is_plausible( 'Main Street, Portlaoise' ), 'Multi-part street address is plausible' );
test_assert( ! Conexao_Event_Address::is_plausible( 'https://example.com/venue' ), 'URLs are rejected' );
test_assert( ! Conexao_Event_Address::is_plausible( 'javascript:alert(1)' ), 'javascript: URLs are rejected' );
test_assert( ! Conexao_Event_Address::is_plausible( 'box@example.com' ), 'Email addresses are rejected' );
test_assert( ! Conexao_Event_Address::is_plausible( '<script>alert(1)</script>' ), 'Markup is rejected' );
test_assert( ! Conexao_Event_Address::is_plausible( '12345' ), 'Numbers without letters are rejected' );
test_assert( ! Conexao_Event_Address::is_plausible( '' ), 'Empty is not plausible' );
test_assert( ! Conexao_Event_Address::is_plausible( str_repeat( 'A very long address fragment ', 20 ) ), 'Over-long values are rejected' );
test_assert( Conexao_Event_Address::is_plausible( 'Apt 3, Block B, Harbour View, Dún Laoghaire, Co. Dublin' ), 'Apartment/unit detail preserved in plausible multi-part address' );
test_assert( Conexao_Event_Address::is_plausible( 'R32 XW63' ), 'A bare Eircode is plausible (source-supplied postal code)' );

// ---------------------------------------------------------------------------
// 3. JSON-LD extraction (fixtures A, C, D, E, G, H)
// ---------------------------------------------------------------------------
test_section( 'JSON-LD Extraction' );

$jsonld = new Conexao_Event_Jsonld_Location();

// Fixture A: full structured PostalAddress.
$extracted = $jsonld->extract( (string) file_get_contents( $address_fixtures_dir . '/jsonld-full-structured.html' ) );
test_assert( 'Stradbally Hall' === $extracted['venue'], 'A: venue name extracted from Place' );
test_assert(
	'Stradbally, Co. Laois, R32 XW63, IE' === $extracted['address'],
	'A: address composed from supplied streetAddress/locality/region/postalCode/country (got: ' . $extracted['address'] . ')'
);

// Fixture C: JSON-LD text address.
$extracted = $jsonld->extract( (string) file_get_contents( $address_fixtures_dir . '/jsonld-text-address.html' ) );
test_assert( 'Croke Park' === $extracted['venue'], 'C: venue from Place.name' );
test_assert( 'Jones Road, Dublin 3, D03 P0K7' === $extracted['address'], 'C: supplied text address stored verbatim (normalized)' );

// Fixture D: venue only (no street/postal component).
$extracted = $jsonld->extract( (string) file_get_contents( $address_fixtures_dir . '/jsonld-venue-only.html' ) );
test_assert( 'Emo Court' === $extracted['venue'], 'D: venue extracted' );
test_assert( '' === $extracted['address'], 'D: locality/region alone is NOT promoted to an address (no guess)' );

// Fixture E: town/city only.
$extracted = $jsonld->extract( (string) file_get_contents( $address_fixtures_dir . '/jsonld-town-only.html' ) );
test_assert( '' === $extracted['venue'], 'E: text location "Dublin" is not used as a venue' );
test_assert( '' === $extracted['address'], 'E: "Dublin" alone is NOT converted into an address' );

// Fixture G: malformed location data.
$extracted = $jsonld->extract( (string) file_get_contents( $address_fixtures_dir . '/jsonld-malformed.html' ) );
test_assert( '' === $extracted['venue'] && '' === $extracted['address'], 'G: malformed JSON-LD and numeric location are rejected without guessing' );

// Fixture H: multiple possible addresses (organizer HQ + ticket office).
$extracted = $jsonld->extract( (string) file_get_contents( $address_fixtures_dir . '/jsonld-multiple-addresses.html' ) );
test_assert( 'Stradbally Hall' === $extracted['venue'], 'H: event venue selected' );
test_assert(
	'' !== $extracted['address'] && false === strpos( $extracted['address'], 'Head Office Road' ) && false === strpos( $extracted['address'], 'Sale Street' ),
	'H: organizer/contact addresses rejected; Event.location address used (got: ' . $extracted['address'] . ')'
);

// Empty input.
$extracted = $jsonld->extract( '' );
test_assert( '' === $extracted['venue'] && '' === $extracted['address'], 'Empty HTML yields empty result' );
$extracted = $jsonld->extract( '<html><body>No JSON-LD here</body></html>' );
test_assert( '' === $extracted['venue'] && '' === $extracted['address'], 'HTML without JSON-LD yields empty result' );

// ---------------------------------------------------------------------------
// 4. Location-string parsing (no-guess at string level)
// ---------------------------------------------------------------------------
test_section( 'Location String Parsing' );

$location = new Conexao_Event_Location();

$parsed = $location->normalize( 'Dublin' );
test_assert( '' === $parsed['address'], 'String "Dublin" yields no address (no guess, no ", Ireland" appended)' );
test_assert( 'Dublin' === $parsed['venue'], 'String "Dublin" stays a venue label' );

$parsed = $location->normalize( 'Croke Park' );
test_assert( '' === $parsed['address'] && 'Croke Park' === $parsed['venue'], 'Venue-only string: address empty, venue kept' );

$parsed = $location->normalize( 'Jones Road, Dublin 3, D03 P0K7' );
test_assert( '' !== $parsed['address'], 'Supplied street address with Eircode is kept' );
test_assert(
	false === strpos( (string) $parsed['address'], 'Ireland' ),
	'No country suffix is invented'
);

$parsed = $location->normalize( 'Stradbally Hall, Stradbally, Co. Laois, R32 XW63' );
test_assert( '' !== $parsed['address'], 'Labelled venue/address block with Eircode yields an address' );
test_assert( 'Laois' === $parsed['county'], 'County still detected' );
test_assert( 'Stradbally' === $parsed['town'], 'Town still detected' );

$parsed = $location->normalize( '' );
test_assert( '' === $parsed['address'] && '' === $parsed['venue'] && '' === $parsed['county'], 'Missing location: all parts empty' );

// ---------------------------------------------------------------------------
// 5. Normalizer overlay (structured source fields win; B: venue + address HTML)
// ---------------------------------------------------------------------------
test_section( 'Normalizer Structured Overlay' );

$normalizer = new Conexao_Event_Normalizer( new Conexao_Event_Location() );

// Structured raw event (as produced by the Eventbrite API/scrape path).
$normalized = $normalizer->normalize( array(
	'title'      => 'Structured Event',
	'url'        => 'https://example.com/e/1',
	'start_date' => '2026-08-01',
	'end_date'   => '2026-08-02',
	'location'   => 'Portlaoise Town Centre, Portlaoise',
	'venue'      => 'Portlaoise Town Centre',
	'town'       => 'Portlaoise',
	'address'    => 'Main Street, Portlaoise, Laois, R32 E7A2',
	'source_id'  => 'ev-1',
	'source'     => 'eventbrite',
) );
test_assert( 'Main Street, Portlaoise, Laois, R32 E7A2' === $normalized['address'], 'Structured source address stored (B: venue + address)' );
test_assert( 'Portlaoise Town Centre' === $normalized['venue'], 'Structured venue wins over string-derived venue' );
test_assert( 'Portlaoise' === $normalized['town'], 'Structured town used' );
test_assert( 'found' === $normalized['address_status'], 'address_status = found for structured address' );

// Venue only (no structured address, no street-like location string).
$normalized = $normalizer->normalize( array(
	'title'      => 'Venue Only Event',
	'url'        => 'https://example.com/e/2',
	'start_date' => '2026-08-01',
	'location'   => 'Dublin Convention Centre',
	'venue'      => 'Dublin Convention Centre',
	'source_id'  => 'ev-2',
	'source'     => 'eventbrite',
	'county'     => 'Laois',
) );
test_assert( '' === $normalized['address'], 'Venue-only event: address empty (never invented)' );
test_assert( 'Dublin Convention Centre' === $normalized['venue'], 'Venue-only event: venue preserved' );
test_assert( 'missing' === $normalized['address_status'], 'address_status = missing' );

// Malformed structured address (rejected, not guessed).
$normalized = $normalizer->normalize( array(
	'title'      => 'Malformed Address Event',
	'url'        => 'https://example.com/e/3',
	'start_date' => '2026-08-01',
	'location'   => 'Portlaoise',
	'address'    => '<script>alert(1)</script>',
	'source_id'  => 'ev-3',
	'source'     => 'eventbrite',
	'county'     => 'Laois',
) );
test_assert( '' === $normalized['address'], 'Malformed/script address is NOT stored' );
test_assert( 'rejected' === $normalized['address_status'], 'address_status = rejected for malformed data' );

// Multi-day + recurring events: address extraction identical, dates untouched.
$multi_day_raw = array(
	'title'      => 'Multi Day Festival',
	'url'        => 'https://example.com/e/4',
	'start_date' => '2026-08-28',
	'start_time' => '12:00',
	'end_date'   => '2026-08-30',
	'end_time'   => '23:00',
	'location'   => 'Stradbally Hall, Stradbally, Co. Laois',
	'address'    => 'Stradbally Hall, Stradbally, Co. Laois, R32 XW63',
	'venue'      => 'Stradbally Hall',
	'town'       => 'Stradbally',
	'source_id'  => 'ev-4',
	'source'     => 'eventbrite',
	'county'     => 'Laois',
	'recurrence' => '',
);
$normalized_multi = $normalizer->normalize( $multi_day_raw );
test_assert( 'R32 XW63' === substr( (string) $normalized_multi['address'], -8 ), 'Multi-day event: address extracted identically' );
test_assert( '2026-08-28' === $normalized_multi['start_date'] && '2026-08-30' === $normalized_multi['end_date'], 'Multi-day event: start/end dates untouched' );

$normalized_recurring = $normalizer->normalize( array_merge( $multi_day_raw, array(
	'source_id'   => 'ev-5',
	'recurrence'  => 'weekly',
	'recurrence_days' => '1,3',
	'location'    => 'Main Street, Portlaoise, Laois, R32 E7A2',
	'address'     => '',
	'venue'       => '',
	'town'        => '',
) ) );
test_assert( 'Main Street, Portlaoise, Laois, R32 E7A2' === $normalized_recurring['address'], 'Recurring event: address extracted identically from the location string' );

// ---------------------------------------------------------------------------
// 6. Map URL behavior (deterministic, no APIs, no geocoding)
// ---------------------------------------------------------------------------
test_section( 'Map URL Behavior' );

test_assert(
	'Jones Road, Dublin 3, D03 P0K7' === Conexao_Event_Address::map_query( 'Jones Road, Dublin 3, D03 P0K7', 'Croke Park', 'Croke Park' ),
	'Map query priority 1: full address wins'
);
test_assert(
	'Croke Park, Jones Road, Dublin 3' === Conexao_Event_Address::map_query( '', 'Croke Park', 'Jones Road, Dublin 3' ),
	'Map query priority 2: venue + existing location'
);
test_assert(
	'Portlaoise, Laois' === Conexao_Event_Address::map_query( '', '', 'Portlaoise, Laois' ),
	'Map query priority 3: existing location fallback'
);
test_assert( '' === Conexao_Event_Address::map_query( '', '', '' ), 'No location data: no map query' );

$map_url = Conexao_Event_Address::map_url( 'Jones Road, Dublin 3, D03 P0K7' );
test_assert(
	0 === strpos( $map_url, 'https://www.google.com/maps/search/?api=1&query=' ),
	'Map URL is a plain Google Maps search URL (no API key, no geocoding)'
);
test_assert(
	false === strpos( $map_url, ' ' ) && false === strpos( $map_url, '"' ),
	'Map URL query is properly URL-encoded'
);
test_assert( '' === Conexao_Event_Address::map_url( '' ), 'Empty query: no map URL' );

// ---------------------------------------------------------------------------
// 7. Idempotent re-import / changed address / preservation
// ---------------------------------------------------------------------------
test_section( 'Idempotency and Address Preservation' );

test_assert(
	'New Address 12, Portlaoise' === Conexao_Event_Address::resolve_stored( 'New Address 12, Portlaoise', 'Old Address 9, Portlaoise' ),
	'Source address wins over stored address (importer-owned on update)'
);
test_assert(
	'Old Address 9, Portlaoise' === Conexao_Event_Address::resolve_stored( '', 'Old Address 9, Portlaoise' ),
	'Existing trusted address preserved when the source supplies none (manual data not destroyed)'
);
test_assert(
	'' === Conexao_Event_Address::resolve_stored( '', '' ),
	'Both absent: stays empty'
);
test_assert(
	'Same Address, Portlaoise' === Conexao_Event_Address::resolve_stored( 'Same Address, Portlaoise', 'Same Address, Portlaoise' ),
	'Unchanged source address resolves to the identical value (no unnecessary rewrite)'
);

// ---------------------------------------------------------------------------
// 8. REST exposure (existing event meta conventions)
// ---------------------------------------------------------------------------
test_section( 'REST Exposure' );

if ( ! class_exists( 'Conexao_Event_Runtime' ) ) {
	require_once WP_PLUGIN_DIR . '/conexao-event-runtime/conexao-event-runtime.php';
}
// Idempotent; guarantees registration even if init hasn't fired in this process.
Conexao_Event_Runtime::instance()->register_meta();

$registered_address = array();
$registered_map     = array();
foreach ( get_registered_meta_keys( 'post', 'event' ) as $meta_key => $meta_args ) {
	if ( '_event_address' === $meta_key ) {
		$registered_address = $meta_args;
	}
	if ( '_event_map_url' === $meta_key ) {
		$registered_map = $meta_args;
	}
}
test_assert( is_array( $registered_address ) && ! empty( $registered_address['show_in_rest'] ), '_event_address is registered with show_in_rest = true' );
test_assert( is_array( $registered_address ) && 'string' === $registered_address['type'], '_event_address registered as string type' );
test_assert( is_array( $registered_map ) && ! empty( $registered_map['show_in_rest'] ), '_event_map_url is registered with show_in_rest = true' );
test_assert( is_array( $registered_map ) && 'string' === $registered_map['type'], '_event_map_url registered as string type' );

// ---------------------------------------------------------------------------
// 9. Eventbrite fixture end-to-end (venue + address in normal HTML)
// ---------------------------------------------------------------------------
test_section( 'Eventbrite Fixture End-to-End' );

$fixture_html = (string) file_get_contents( $fixtures_dir . '/eventbrite-page-1.html' );
$eb_parser    = new Conexao_Eventbrite_Parser();
$eb_norm      = new Conexao_Eventbrite_Normalizer();
$main_norm    = new Conexao_Event_Normalizer( new Conexao_Event_Location() );

try {
	$parsed_page = $eb_parser->parse( $fixture_html );
	$raw_events  = $parsed_page['events'];

	$eb_event      = null;
	$eb_venue_only = null;
	foreach ( $raw_events as $candidate ) {
		if ( '1989764061872' === $candidate['id'] ) {
			$eb_event = $candidate;
		}
		if ( '1989764061875' === $candidate['id'] ) {
			$eb_venue_only = $candidate;
		}
	}

	if ( $eb_event ) {
		$eb_raw = $eb_norm->normalize( $eb_event );
		$final  = $main_norm->normalize( $eb_raw );
		test_assert( false !== strpos( (string) $final['address'], 'Main Street' ), 'Eventbrite HTML: structured venue.address flows into the final address' );
		test_assert( 'Portlaoise Town Centre' === $final['venue'], 'Eventbrite HTML: venue name preserved' );
		test_assert( 'found' === $final['address_status'], 'Eventbrite HTML: address_status = found' );
	} else {
		test_assert( false, 'Eventbrite fixture: full event not found' );
	}

	if ( $eb_venue_only ) {
		$eb_raw2 = $eb_norm->normalize( $eb_venue_only );
		$final2  = $main_norm->normalize( $eb_raw2 );
		test_assert( '' === $final2['address'], 'Eventbrite HTML: venue-only event keeps address empty' );
		test_assert( 'Dublin Convention Centre' === $final2['venue'], 'Eventbrite HTML: venue-only event keeps venue' );
	} else {
		test_assert( false, 'Eventbrite fixture: venue-only event not found' );
	}
} catch ( Exception $e ) {
	test_assert( false, 'Eventbrite fixture parse failed: ' . $e->getMessage() );
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n==============================\n";
echo "RESULTS: {$passed} passed, {$failed} failed\n";
echo "==============================\n";
exit( $failed > 0 ? 1 : 0 );
