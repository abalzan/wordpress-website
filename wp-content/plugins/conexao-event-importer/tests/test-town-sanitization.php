<?php
/**
 * Tests for the Eventos city/town filter normalization.
 *
 * Verifies:
 *  - Conexao_Event_Location::sanitize_town() strips Eircode fragments
 *  - Conexao_Event_Location::sanitize_town() rejects standalone Eircodes
 *  - Conexao_Event_Location::ensure_town() rejects sanitized-empty input
 *  - Conexao_Event_Normalizer::normalize() sanitizes the town field
 *  - No new Eircode-contaminated town terms are created
 *
 * Usage (from project root):
 *   docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/test-town-sanitization.php
 */


// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$passed = 0;
$failed = 0;

test_section( 'sanitize_town() — Eircode stripping' );

$loc = new Conexao_Event_Location();

// Town + Eircode variants.
$result = $loc->sanitize_town( 'Ballinamore N41 E8H0' );
assert_true( 'Ballinamore' === $result, "Ballinamore N41 E8H0 → Ballinamore (got: '{$result}')" );

$result = $loc->sanitize_town( 'Ballinamore N41E8HO' );
assert_true( 'Ballinamore' === $result, "Ballinamore N41E8HO → Ballinamore (got: '{$result}')" );

$result = $loc->sanitize_town( 'Oranmore H91 72H3' );
assert_true( 'Oranmore' === $result, "Oranmore H91 72H3 → Oranmore (got: '{$result}')" );

$result = $loc->sanitize_town( 'Oranmore H91 72H3,' );
assert_true( 'Oranmore' === $result, "Oranmore H91 72H3, → Oranmore (got: '{$result}')" );

$result = $loc->sanitize_town( 'H91 72H3 Oranmore' );
assert_true( 'Oranmore' === $result, "H91 72H3 Oranmore → Oranmore (got: '{$result}')" );

$result = $loc->sanitize_town( 'Ballinamore' );
assert_true( 'Ballinamore' === $result, "Ballinamore → Ballinamore (clean name preserved)" );

$result = $loc->sanitize_town( 'Dublin' );
assert_true( 'Dublin' === $result, "Dublin → Dublin (clean name preserved)" );

$result = $loc->sanitize_town( '  Ballinamore N41 E8H0  ' );
assert_true( 'Ballinamore' === $result, "whitespace handled: padded input → Ballinamore" );

test_section( 'sanitize_town() — standalone Eircode rejection' );

$result = $loc->sanitize_town( 'A92 DF7X.' );
assert_true( '' === $result, "A92 DF7X. → '' (standalone Eircode rejected)" );

$result = $loc->sanitize_town( 'W23 FNP4,' );
assert_true( '' === $result, "W23 FNP4, → '' (standalone Eircode rejected)" );

$result = $loc->sanitize_town( 'N41 E8H0' );
assert_true( '' === $result, "N41 E8H0 → '' (standalone Eircode rejected)" );

$result = $loc->sanitize_town( 'H91 72H3' );
assert_true( '' === $result, "H91 72H3 → '' (standalone Eircode rejected)" );

$result = $loc->sanitize_town( '' );
assert_true( '' === $result, "empty string → ''" );

$result = $loc->sanitize_town( '   ' );
assert_true( '' === $result, "whitespace only → ''" );

test_section( 'ensure_town() — defense in depth' );

$term_id = $loc->ensure_town( 'A92 DF7X.' );
assert_true( 0 === $term_id, "ensure_town('A92 DF7X.') returns 0 (term not created)" );

$term_id = $loc->ensure_town( 'W23 FNP4,' );
assert_true( 0 === $term_id, "ensure_town('W23 FNP4,') returns 0 (term not created)" );

$term_id = $loc->ensure_town( 'Ballinamore N41 E8H0' );
$ballinamore_id = (int) term_exists( 'Ballinamore', 'conexao_town' )['term_id'];
assert_true( $term_id === $ballinamore_id, "ensure_town('Ballinamore N41 E8H0') resolves to Ballinamore term_id={$term_id}" );

$term_id = $loc->ensure_town( 'Dublin' );
assert_true( $term_id > 0, "ensure_town('Dublin') creates/resolves a term" );

test_section( 'Normalizer integration — town sanitization' );

$normalizer = new Conexao_Event_Normalizer( $loc );

$base_raw = array(
	'title'       => 'Test Event',
	'url'         => 'https://example.com/e/test',
	'start_date'  => '2026-12-30',
	'start_time'  => '10:00',
	'end_date'    => '2026-12-30',
	'end_time'    => '12:00',
	'location'    => '',
	'description' => 'Test event.',
	'image'       => '',
	'source_id'   => 'test-1',
	'organizer'   => 'Test',
	'price'       => 'Free',
	'category'    => 'Test',
	'county'      => 'Cork',
	'venue'       => 'Test Venue',
		'address'     => '123 Main St',
);

// Town with Eircode gets stripped.
$raw = $base_raw;
$raw['town'] = 'Ballinamore N41 E8H0';
$normalized = $normalizer->normalize( $raw );
assert_true( 'Ballinamore' === $normalized['town'], "normalizer: 'Ballinamore N41 E8H0' → 'Ballinamore' (got: '{$normalized['town']}')" );

// Standalone Eircode as town → empty.
$raw = $base_raw;
$raw['town'] = 'A92 DF7X.';
$normalized = $normalizer->normalize( $raw );
assert_true( '' === $normalized['town'], "normalizer: 'A92 DF7X.' → '' (got: '{$normalized['town']}')" );

$raw = $base_raw;
$raw['town'] = 'W23 FNP4,';
$normalized = $normalizer->normalize( $raw );
assert_true( '' === $normalized['town'], "normalizer: 'W23 FNP4,' → '' (got: '{$normalized['town']}')" );

// Clean town preserved.
$raw = $base_raw;
$raw['town'] = 'Dublin';
$normalized = $normalizer->normalize( $raw );
assert_true( 'Dublin' === $normalized['town'], "normalizer: 'Dublin' → 'Dublin' (preserved)" );

// Address metadata untouched when Eircode in town.
$raw = $base_raw;
$raw['town'] = 'Oranmore H91 72H3';
$raw['address'] = '123 Main St, Oranmore, H91 72H3';
$normalized = $normalizer->normalize( $raw );
assert_true( 'Oranmore' === $normalized['town'], "normalizer: town sanitized to 'Oranmore' (got: '{$normalized['town']}')" );
assert_true( '123 Main St, Oranmore, H91 72H3' === $normalized['address'], "normalizer: address metadata preserved (got: '{$normalized['address']}')" );

test_section( 'is_county_only_value() — a county is not a town' );

// Counties with NO town of the same name are rejected as a town value.
assert_true( $loc->is_county_only_value( 'Laois' ), "Laois is a county, not a town" );
assert_true( $loc->is_county_only_value( 'laois' ), "match is case-insensitive" );
assert_true( $loc->is_county_only_value( '  Laois  ' ), "surrounding whitespace tolerated" );
assert_true( $loc->is_county_only_value( 'Clare' ), "Clare is a county, not a town" );
assert_true( $loc->is_county_only_value( 'Kerry' ), "Kerry is a county, not a town" );
assert_true( $loc->is_county_only_value( 'Meath' ), "Meath is a county, not a town" );
assert_true( $loc->is_county_only_value( 'Offaly' ), "Offaly is a county, not a town" );

// County markers around the name are recognised.
assert_true( $loc->is_county_only_value( 'Co. Laois' ), "'Co. Laois' recognised as the county" );
assert_true( $loc->is_county_only_value( 'Co Laois' ), "'Co Laois' recognised as the county" );
assert_true( $loc->is_county_only_value( 'County Laois' ), "'County Laois' recognised as the county" );
assert_true( $loc->is_county_only_value( 'Laois, Ireland' ), "'Laois, Ireland' recognised as the county" );
assert_true( $loc->is_county_only_value( 'Laois Ireland' ), "'Laois Ireland' recognised as the county" );

// A county name that IS also a real town must NOT be rejected. This is the
// guard against over-correction: Cavan, Wicklow, Kildare, Carlow, Longford,
// Monaghan and Donegal are genuine towns, as are the cities Cork, Dublin,
// Galway, Kilkenny, Sligo, Waterford and Wexford.
foreach ( array( 'Cork', 'Dublin', 'Galway', 'Kilkenny', 'Sligo', 'Waterford', 'Wexford',
	'Cavan', 'Wicklow', 'Kildare', 'Limerick', 'Carlow', 'Longford', 'Monaghan', 'Donegal', 'Leitrim' ) as $real_town ) {
	assert_true( ! $loc->is_county_only_value( $real_town ), "'{$real_town}' is a real town and must be kept" );
}

// Towns that merely start with "co" must survive the county-marker strip.
assert_true( ! $loc->is_county_only_value( 'Cobh' ), "'Cobh' is a town starting with 'Co'" );
assert_true( ! $loc->is_county_only_value( 'Cong' ), "'Cong' is a town starting with 'Co'" );
assert_true( ! $loc->is_county_only_value( 'Newtown' ), "'Newtown' is a town, not a county" );
assert_true( ! $loc->is_county_only_value( '' ), "empty value is not a county" );

test_section( 'sanitize_town() — county names are rejected' );

assert_true( '' === $loc->sanitize_town( 'Laois' ), "Laois → '' (county, not a town)" );
assert_true( '' === $loc->sanitize_town( 'Co. Laois' ), "'Co. Laois' → ''" );
assert_true( '' === $loc->sanitize_town( 'Laois, Ireland' ), "'Laois, Ireland' → ''" );

// Genuine towns that share a county's name are preserved untouched.
foreach ( array( 'Cork', 'Dublin', 'Galway', 'Cavan', 'Wicklow', 'Kildare' ) as $real_town ) {
	assert_true( $real_town === $loc->sanitize_town( $real_town ), "'{$real_town}' preserved as a town" );
}

test_section( 'ensure_town() — a county never creates a town term' );

$laois_town_before = term_exists( 'Laois', 'conexao_town' );
$term_id = $loc->ensure_town( 'Laois' );
assert_true( 0 === $term_id, "ensure_town('Laois') returns 0 — no term is created" );

$laois_town_after = term_exists( 'Laois', 'conexao_town' );
assert_true(
	(bool) $laois_town_before === (bool) $laois_town_after,
	'ensure_town(\'Laois\') creates no new conexao_town term (term state unchanged)'
);

test_section( 'Normalizer integration — county is kept, town is not invented' );

$normalizer = new Conexao_Event_Normalizer( $loc );

// The exact shape a Laois-scoped source emits: county hint "Laois" and an
// address whose locality resolves no further than the county.
$laois_raw = array(
	'title'       => 'Happy Hiking - Hill Skills Day',
	'url'         => 'https://example.com/e/laois-1',
	'start_date'  => '2026-12-30',
	'start_time'  => '10:00',
	'end_date'    => '2026-12-30',
	'end_time'    => '12:00',
	'description' => 'Test event.',
	'image'       => '',
	'source'      => 'laois_tourism',
	'source_id'   => 'stage-p-1',
	'location'    => 'Gorteenameale Eco Trail, Laois, Laois',
	'venue'       => 'Gorteenameale',
	'county'      => 'Laois',
	'town'        => 'Laois',
	'address'     => 'Gorteenameale Eco Trail, Laois, Laois',
);

$normalized = $normalizer->normalize( $laois_raw );
assert_true( 'Laois' === $normalized['county'], "normalizer: county 'Laois' is retained (got: '{$normalized['county']}')" );
assert_true( '' === $normalized['town'], "normalizer: town is empty, not 'Laois' (got: '{$normalized['town']}')" );
assert_true( 'Gorteenameale' === $normalized['venue'], "normalizer: venue preserved (got: '{$normalized['venue']}')" );
assert_true( 'Gorteenameale' === $normalized['event_location'], "normalizer: _event_location still shows the venue (got: '{$normalized['event_location']}')" );
assert_true( empty( $normalized['validation_errors'] ), 'normalizer: county-only event is NOT rejected as unlocated' );

// A real town supplied by the source still imports.
$portlaoise_raw              = $laois_raw;
$portlaoise_raw['source_id'] = 'stage-p-2';
$portlaoise_raw['title']     = 'Lasta at Dunamaise Arts Centre';
$portlaoise_raw['town']      = 'Portlaoise';
$normalized = $normalizer->normalize( $portlaoise_raw );
assert_true( 'Laois' === $normalized['county'], "normalizer: county 'Laois' retained with a real town" );
assert_true( 'Portlaoise' === $normalized['town'], "normalizer: real town 'Portlaoise' still imported (got: '{$normalized['town']}')" );

test_section( 'Database state verification' );

$terms = get_terms( array(
	'taxonomy'   => 'conexao_town',
	'hide_empty' => false,
	'fields'     => 'names',
) );
$bad_count = 0;
foreach ( (array) $terms as $term_name ) {
	if ( preg_match( '/[A-Z]\d{2}\s?[A-Z0-9]{4}/i', $term_name )
		|| preg_match( '/^[A-Z]\d{2}\s/', $term_name )
		|| preg_match( '/^co\.?\s+/i', $term_name )
		|| preg_match( '/^co\.[A-Z]/', $term_name )
		|| preg_match( '/,\s*ireland\s*$/i', $term_name )
		|| preg_match( '/\s+ireland\s*$/i', $term_name )
	) {
		$bad_count++;
	}
}
assert_true( 0 === $bad_count, "no Eircode-contaminated or non-town terms in conexao_town" );

// Verify specific clean terms exist.
$ballinamore = term_exists( 'Ballinamore', 'conexao_town' );
assert_true( $ballinamore, "clean term 'Ballinamore' exists" );
$oranmore = term_exists( 'Oranmore', 'conexao_town' );
assert_true( $oranmore, "clean term 'Oranmore' exists (was contaminated)" );
$galway = term_exists( 'Galway', 'conexao_town' );
assert_true( $galway, "clean term 'Galway' exists (was 'Galway, Ireland')" );

// Verify legitimate Co-towns still exist.
$cork = term_exists( 'Cork', 'conexao_town' );
assert_true( $cork, "legitimate town 'Cork' still exists" );
$cobh = term_exists( 'Cobh', 'conexao_town' );
assert_true( $cobh, "legitimate town 'Cobh' still exists" );
$corofin = term_exists( 'Corofin', 'conexao_town' );
assert_true( $corofin, "legitimate town 'Corofin' still exists" );

// Verify standalone Eircode terms removed.
$a92 = term_exists( 'A92 DF7X.', 'conexao_town' );
assert_true( ! $a92, "standalone Eircode 'A92 DF7X.' removed" );
$w23 = term_exists( 'W23 FNP4,', 'conexao_town' );
assert_true( ! $w23, "standalone Eircode 'W23 FNP4,' removed" );

test_finish();
