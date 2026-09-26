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


// Ensure plugin classes are loaded.
// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$fixtures_dir = __DIR__ . '/fixtures';
$address_fixtures_dir = $fixtures_dir . '/address';

$passed = 0;
$failed = 0;

// ---------------------------------------------------------------------------
// 1. Address normalization
// ---------------------------------------------------------------------------
test_section( 'Address Normalization' );

assert_true(
	'Jones Road, Dublin 3, D03 P0K7' === Conexao_Event_Address::normalize( "  Jones Road,\n\t Dublin  3,  D03 P0K7 \n" ),
	'Whitespace and line breaks collapse to a single-line address'
);

assert_true(
	'Church Street, Portlaoise' === Conexao_Event_Address::normalize( 'Church &omicron; Street&#44; Portlaoise' === '' ? '' : 'Church&nbsp;Street,   Portlaoise' ),
	'HTML entities are decoded (nbsp)'
);

assert_true(
	'Main Street, Portlaoise, R32 XW63' === Conexao_Event_Address::normalize( 'Main Street,, Portlaoise, , , R32 XW63' ),
	'Duplicate comma separators collapse'
);

assert_true(
	'Unit 5, Shopping Centre, Portlaoise' === Conexao_Event_Address::normalize( "<p>Unit 5</p><br>Shopping Centre<br/>Portlaoise" ),
	'Unit/apartment info and <br>/<p> markup preserved as separators, not removed'
);

assert_true(
	'&Aacute;tha Cliath' === Conexao_Event_Address::normalize( '&Aacute;tha Cliath' ) || 'Átha Cliath' === Conexao_Event_Address::normalize( '&Aacute;tha Cliath' ),
	'Entities decoded without destroying non-ASCII text'
);

assert_true(
	'' === Conexao_Event_Address::normalize( '   ' ) && '' === Conexao_Event_Address::normalize( '' ),
	'Empty/whitespace-only values normalize to empty'
);

assert_true(
	', ,, Main Street, ' === '' || 'Main Street' === Conexao_Event_Address::normalize( ', ,, Main Street, ' ),
	'Leading/trailing stray separators are trimmed'
);

// ---------------------------------------------------------------------------
// 2. Plausibility / no-guess validation
// ---------------------------------------------------------------------------
test_section( 'Plausibility / No-Guess Validation' );

assert_true( ! Conexao_Event_Address::is_plausible( 'Dublin' ), '"Dublin" alone is NOT an address' );
assert_true( ! Conexao_Event_Address::is_plausible( 'Croke Park' ), '"Croke Park" alone is NOT an address' );
assert_true( ! Conexao_Event_Address::is_plausible( 'Portlaoise' ), 'A bare town name is NOT an address' );
assert_true( Conexao_Event_Address::is_plausible( 'Jones Road, Dublin 3, D03 P0K7' ), 'Full supplied street address is plausible' );
assert_true( Conexao_Event_Address::is_plausible( 'Stradbally Hall, Stradbally, Co. Laois' ), 'Venue + town + county marker is plausible' );
assert_true( Conexao_Event_Address::is_plausible( 'Main Street, Portlaoise' ), 'Multi-part street address is plausible' );
assert_true( ! Conexao_Event_Address::is_plausible( 'https://example.com/venue' ), 'URLs are rejected' );
assert_true( ! Conexao_Event_Address::is_plausible( 'javascript:alert(1)' ), 'javascript: URLs are rejected' );
assert_true( ! Conexao_Event_Address::is_plausible( 'box@example.com' ), 'Email addresses are rejected' );
assert_true( ! Conexao_Event_Address::is_plausible( '<script>alert(1)</script>' ), 'Markup is rejected' );
assert_true( ! Conexao_Event_Address::is_plausible( '12345' ), 'Numbers without letters are rejected' );
assert_true( ! Conexao_Event_Address::is_plausible( '' ), 'Empty is not plausible' );
assert_true( ! Conexao_Event_Address::is_plausible( str_repeat( 'A very long address fragment ', 20 ) ), 'Over-long values are rejected' );
assert_true( Conexao_Event_Address::is_plausible( 'Apt 3, Block B, Harbour View, Dún Laoghaire, Co. Dublin' ), 'Apartment/unit detail preserved in plausible multi-part address' );
assert_true( Conexao_Event_Address::is_plausible( 'R32 XW63' ), 'A bare Eircode is plausible (source-supplied postal code)' );

// ---------------------------------------------------------------------------
// 3. JSON-LD extraction (fixtures A, C, D, E, G, H)
// ---------------------------------------------------------------------------
test_section( 'JSON-LD Extraction' );

$jsonld = new Conexao_Event_Jsonld_Location();

// Fixture A: full structured PostalAddress.
$extracted = $jsonld->extract( (string) file_get_contents( $address_fixtures_dir . '/jsonld-full-structured.html' ) );
assert_true( 'Stradbally Hall' === $extracted['venue'], 'A: venue name extracted from Place' );
assert_true(
	'Stradbally, Co. Laois, R32 XW63, IE' === $extracted['address'],
	'A: address composed from supplied streetAddress/locality/region/postalCode/country (got: ' . $extracted['address'] . ')'
);

// Fixture C: JSON-LD text address.
$extracted = $jsonld->extract( (string) file_get_contents( $address_fixtures_dir . '/jsonld-text-address.html' ) );
assert_true( 'Croke Park' === $extracted['venue'], 'C: venue from Place.name' );
assert_true( 'Jones Road, Dublin 3, D03 P0K7' === $extracted['address'], 'C: supplied text address stored verbatim (normalized)' );

// Fixture D: venue only (no street/postal component).
$extracted = $jsonld->extract( (string) file_get_contents( $address_fixtures_dir . '/jsonld-venue-only.html' ) );
assert_true( 'Emo Court' === $extracted['venue'], 'D: venue extracted' );
assert_true( '' === $extracted['address'], 'D: locality/region alone is NOT promoted to an address (no guess)' );

// Fixture E: town/city only.
$extracted = $jsonld->extract( (string) file_get_contents( $address_fixtures_dir . '/jsonld-town-only.html' ) );
assert_true( '' === $extracted['venue'], 'E: text location "Dublin" is not used as a venue' );
assert_true( '' === $extracted['address'], 'E: "Dublin" alone is NOT converted into an address' );

// Fixture G: malformed location data.
$extracted = $jsonld->extract( (string) file_get_contents( $address_fixtures_dir . '/jsonld-malformed.html' ) );
assert_true( '' === $extracted['venue'] && '' === $extracted['address'], 'G: malformed JSON-LD and numeric location are rejected without guessing' );

// Fixture H: multiple possible addresses (organizer HQ + ticket office).
$extracted = $jsonld->extract( (string) file_get_contents( $address_fixtures_dir . '/jsonld-multiple-addresses.html' ) );
assert_true( 'Stradbally Hall' === $extracted['venue'], 'H: event venue selected' );
assert_true(
	'' !== $extracted['address'] && false === strpos( $extracted['address'], 'Head Office Road' ) && false === strpos( $extracted['address'], 'Sale Street' ),
	'H: organizer/contact addresses rejected; Event.location address used (got: ' . $extracted['address'] . ')'
);

// Empty input.
$extracted = $jsonld->extract( '' );
assert_true( '' === $extracted['venue'] && '' === $extracted['address'], 'Empty HTML yields empty result' );
$extracted = $jsonld->extract( '<html><body>No JSON-LD here</body></html>' );
assert_true( '' === $extracted['venue'] && '' === $extracted['address'], 'HTML without JSON-LD yields empty result' );

// ---------------------------------------------------------------------------
// 4. Location-string parsing (no-guess at string level)
// ---------------------------------------------------------------------------
test_section( 'Location String Parsing' );

$location = new Conexao_Event_Location();

$parsed = $location->normalize( 'Dublin' );
assert_true( '' === $parsed['address'], 'String "Dublin" yields no address (no guess, no ", Ireland" appended)' );
assert_true( 'Dublin' === $parsed['venue'], 'String "Dublin" stays a venue label' );

$parsed = $location->normalize( 'Croke Park' );
assert_true( '' === $parsed['address'] && 'Croke Park' === $parsed['venue'], 'Venue-only string: address empty, venue kept' );

$parsed = $location->normalize( 'Jones Road, Dublin 3, D03 P0K7' );
assert_true( '' !== $parsed['address'], 'Supplied street address with Eircode is kept' );
assert_true(
	false === strpos( (string) $parsed['address'], 'Ireland' ),
	'No country suffix is invented'
);

$parsed = $location->normalize( 'Stradbally Hall, Stradbally, Co. Laois, R32 XW63' );
assert_true( '' !== $parsed['address'], 'Labelled venue/address block with Eircode yields an address' );
assert_true( 'Laois' === $parsed['county'], 'County still detected' );
assert_true( 'Stradbally' === $parsed['town'], 'Town still detected' );

$parsed = $location->normalize( '' );
assert_true( '' === $parsed['address'] && '' === $parsed['venue'] && '' === $parsed['county'], 'Missing location: all parts empty' );

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
assert_true( 'Main Street, Portlaoise, Laois, R32 E7A2' === $normalized['address'], 'Structured source address stored (B: venue + address)' );
assert_true( 'Portlaoise Town Centre' === $normalized['venue'], 'Structured venue wins over string-derived venue' );
assert_true( 'Portlaoise' === $normalized['town'], 'Structured town used' );
assert_true( 'found' === $normalized['address_status'], 'address_status = found for structured address' );

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
assert_true( '' === $normalized['address'], 'Venue-only event: address empty (never invented)' );
assert_true( 'Dublin Convention Centre' === $normalized['venue'], 'Venue-only event: venue preserved' );
assert_true( 'missing' === $normalized['address_status'], 'address_status = missing' );

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
assert_true( '' === $normalized['address'], 'Malformed/script address is NOT stored' );
assert_true( 'rejected' === $normalized['address_status'], 'address_status = rejected for malformed data' );

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
assert_true( 'R32 XW63' === substr( (string) $normalized_multi['address'], -8 ), 'Multi-day event: address extracted identically' );
assert_true( '2026-08-28' === $normalized_multi['start_date'] && '2026-08-30' === $normalized_multi['end_date'], 'Multi-day event: start/end dates untouched' );

$normalized_recurring = $normalizer->normalize( array_merge( $multi_day_raw, array(
	'source_id'   => 'ev-5',
	'recurrence'  => 'weekly',
	'recurrence_days' => '1,3',
	'location'    => 'Main Street, Portlaoise, Laois, R32 E7A2',
	'address'     => '',
	'venue'       => '',
	'town'        => '',
) ) );
assert_true( 'Main Street, Portlaoise, Laois, R32 E7A2' === $normalized_recurring['address'], 'Recurring event: address extracted identically from the location string' );

// ---------------------------------------------------------------------------
// 6. Map URL behavior (deterministic, no APIs, no geocoding)
// ---------------------------------------------------------------------------
test_section( 'Map URL Behavior' );

assert_true(
	'Jones Road, Dublin 3, D03 P0K7' === Conexao_Event_Address::map_query( 'Jones Road, Dublin 3, D03 P0K7', 'Croke Park', 'Croke Park' ),
	'Map query priority 1: full address wins'
);
assert_true(
	'Croke Park, Jones Road, Dublin 3' === Conexao_Event_Address::map_query( '', 'Croke Park', 'Jones Road, Dublin 3' ),
	'Map query priority 2: venue + existing location'
);
assert_true(
	'Portlaoise, Laois' === Conexao_Event_Address::map_query( '', '', 'Portlaoise, Laois' ),
	'Map query priority 3: existing location fallback'
);
assert_true( '' === Conexao_Event_Address::map_query( '', '', '' ), 'No location data: no map query' );

$map_url = Conexao_Event_Address::map_url( 'Jones Road, Dublin 3, D03 P0K7' );
assert_true(
	0 === strpos( $map_url, 'https://www.google.com/maps/search/?api=1&query=' ),
	'Map URL is a plain Google Maps search URL (no API key, no geocoding)'
);
assert_true(
	false === strpos( $map_url, ' ' ) && false === strpos( $map_url, '"' ),
	'Map URL query is properly URL-encoded'
);
assert_true( '' === Conexao_Event_Address::map_url( '' ), 'Empty query: no map URL' );

// ---------------------------------------------------------------------------
// 7. Idempotent re-import / changed address / preservation
// ---------------------------------------------------------------------------
test_section( 'Idempotency and Address Preservation' );

assert_true(
	'New Address 12, Portlaoise' === Conexao_Event_Address::resolve_stored( 'New Address 12, Portlaoise', 'Old Address 9, Portlaoise' ),
	'Source address wins over stored address (importer-owned on update)'
);
assert_true(
	'Old Address 9, Portlaoise' === Conexao_Event_Address::resolve_stored( '', 'Old Address 9, Portlaoise' ),
	'Existing trusted address preserved when the source supplies none (manual data not destroyed)'
);
assert_true(
	'' === Conexao_Event_Address::resolve_stored( '', '' ),
	'Both absent: stays empty'
);
assert_true(
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
assert_true( is_array( $registered_address ) && ! empty( $registered_address['show_in_rest'] ), '_event_address is registered with show_in_rest = true' );
assert_true( is_array( $registered_address ) && 'string' === $registered_address['type'], '_event_address registered as string type' );
assert_true( is_array( $registered_map ) && ! empty( $registered_map['show_in_rest'] ), '_event_map_url is registered with show_in_rest = true' );
assert_true( is_array( $registered_map ) && 'string' === $registered_map['type'], '_event_map_url registered as string type' );

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
		assert_true( false !== strpos( (string) $final['address'], 'Main Street' ), 'Eventbrite HTML: structured venue.address flows into the final address' );
		assert_true( 'Portlaoise Town Centre' === $final['venue'], 'Eventbrite HTML: venue name preserved' );
		assert_true( 'found' === $final['address_status'], 'Eventbrite HTML: address_status = found' );
	} else {
		assert_true( false, 'Eventbrite fixture: full event not found' );
	}

	if ( $eb_venue_only ) {
		$eb_raw2 = $eb_norm->normalize( $eb_venue_only );
		$final2  = $main_norm->normalize( $eb_raw2 );
		assert_true( '' === $final2['address'], 'Eventbrite HTML: venue-only event keeps address empty' );
		assert_true( 'Dublin Convention Centre' === $final2['venue'], 'Eventbrite HTML: venue-only event keeps venue' );
	} else {
		assert_true( false, 'Eventbrite fixture: venue-only event not found' );
	}
} catch ( Exception $e ) {
	assert_true( false, 'Eventbrite fixture parse failed: ' . $e->getMessage() );
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

test_finish();
