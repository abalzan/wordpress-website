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

$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$passed = 0;
$failed = 0;

function ts_assert( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		echo "  PASS: {$message}\n";
	} else {
		$failed++;
		echo "  FAIL: {$message}\n";
	}
}

function ts_section( $title ) {
	echo "\n=== {$title} ===\n";
}

ts_section( 'sanitize_town() — Eircode stripping' );

$loc = new Conexao_Event_Location();

// Town + Eircode variants.
$result = $loc->sanitize_town( 'Ballinamore N41 E8H0' );
ts_assert( 'Ballinamore' === $result, "Ballinamore N41 E8H0 → Ballinamore (got: '{$result}')" );

$result = $loc->sanitize_town( 'Ballinamore N41E8HO' );
ts_assert( 'Ballinamore' === $result, "Ballinamore N41E8HO → Ballinamore (got: '{$result}')" );

$result = $loc->sanitize_town( 'Oranmore H91 72H3' );
ts_assert( 'Oranmore' === $result, "Oranmore H91 72H3 → Oranmore (got: '{$result}')" );

$result = $loc->sanitize_town( 'Oranmore H91 72H3,' );
ts_assert( 'Oranmore' === $result, "Oranmore H91 72H3, → Oranmore (got: '{$result}')" );

$result = $loc->sanitize_town( 'H91 72H3 Oranmore' );
ts_assert( 'Oranmore' === $result, "H91 72H3 Oranmore → Oranmore (got: '{$result}')" );

$result = $loc->sanitize_town( 'Ballinamore' );
ts_assert( 'Ballinamore' === $result, "Ballinamore → Ballinamore (clean name preserved)" );

$result = $loc->sanitize_town( 'Dublin' );
ts_assert( 'Dublin' === $result, "Dublin → Dublin (clean name preserved)" );

$result = $loc->sanitize_town( '  Ballinamore N41 E8H0  ' );
ts_assert( 'Ballinamore' === $result, "whitespace handled: padded input → Ballinamore" );

ts_section( 'sanitize_town() — standalone Eircode rejection' );

$result = $loc->sanitize_town( 'A92 DF7X.' );
ts_assert( '' === $result, "A92 DF7X. → '' (standalone Eircode rejected)" );

$result = $loc->sanitize_town( 'W23 FNP4,' );
ts_assert( '' === $result, "W23 FNP4, → '' (standalone Eircode rejected)" );

$result = $loc->sanitize_town( 'N41 E8H0' );
ts_assert( '' === $result, "N41 E8H0 → '' (standalone Eircode rejected)" );

$result = $loc->sanitize_town( 'H91 72H3' );
ts_assert( '' === $result, "H91 72H3 → '' (standalone Eircode rejected)" );

$result = $loc->sanitize_town( '' );
ts_assert( '' === $result, "empty string → ''" );

$result = $loc->sanitize_town( '   ' );
ts_assert( '' === $result, "whitespace only → ''" );

ts_section( 'ensure_town() — defense in depth' );

$term_id = $loc->ensure_town( 'A92 DF7X.' );
ts_assert( 0 === $term_id, "ensure_town('A92 DF7X.') returns 0 (term not created)" );

$term_id = $loc->ensure_town( 'W23 FNP4,' );
ts_assert( 0 === $term_id, "ensure_town('W23 FNP4,') returns 0 (term not created)" );

$term_id = $loc->ensure_town( 'Ballinamore N41 E8H0' );
$ballinamore_id = (int) term_exists( 'Ballinamore', 'conexao_town' )['term_id'];
ts_assert( $term_id === $ballinamore_id, "ensure_town('Ballinamore N41 E8H0') resolves to Ballinamore term_id={$term_id}" );

$term_id = $loc->ensure_town( 'Dublin' );
ts_assert( $term_id > 0, "ensure_town('Dublin') creates/resolves a term" );

ts_section( 'Normalizer integration — town sanitization' );

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
ts_assert( 'Ballinamore' === $normalized['town'], "normalizer: 'Ballinamore N41 E8H0' → 'Ballinamore' (got: '{$normalized['town']}')" );

// Standalone Eircode as town → empty.
$raw = $base_raw;
$raw['town'] = 'A92 DF7X.';
$normalized = $normalizer->normalize( $raw );
ts_assert( '' === $normalized['town'], "normalizer: 'A92 DF7X.' → '' (got: '{$normalized['town']}')" );

$raw = $base_raw;
$raw['town'] = 'W23 FNP4,';
$normalized = $normalizer->normalize( $raw );
ts_assert( '' === $normalized['town'], "normalizer: 'W23 FNP4,' → '' (got: '{$normalized['town']}')" );

// Clean town preserved.
$raw = $base_raw;
$raw['town'] = 'Dublin';
$normalized = $normalizer->normalize( $raw );
ts_assert( 'Dublin' === $normalized['town'], "normalizer: 'Dublin' → 'Dublin' (preserved)" );

// Address metadata untouched when Eircode in town.
$raw = $base_raw;
$raw['town'] = 'Oranmore H91 72H3';
$raw['address'] = '123 Main St, Oranmore, H91 72H3';
$normalized = $normalizer->normalize( $raw );
ts_assert( 'Oranmore' === $normalized['town'], "normalizer: town sanitized to 'Oranmore' (got: '{$normalized['town']}')" );
ts_assert( '123 Main St, Oranmore, H91 72H3' === $normalized['address'], "normalizer: address metadata preserved (got: '{$normalized['address']}')" );

ts_section( 'Database state verification' );

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
		echo "  WARN: contaminated term found: \"{$term_name}\"\n";
	}
}
ts_assert( 0 === $bad_count, "no Eircode-contaminated or non-town terms in conexao_town" );

// Verify specific clean terms exist.
$ballinamore = term_exists( 'Ballinamore', 'conexao_town' );
ts_assert( $ballinamore, "clean term 'Ballinamore' exists" );
$oranmore = term_exists( 'Oranmore', 'conexao_town' );
ts_assert( $oranmore, "clean term 'Oranmore' exists (was contaminated)" );
$galway = term_exists( 'Galway', 'conexao_town' );
ts_assert( $galway, "clean term 'Galway' exists (was 'Galway, Ireland')" );

// Verify legitimate Co-towns still exist.
$cork = term_exists( 'Cork', 'conexao_town' );
ts_assert( $cork, "legitimate town 'Cork' still exists" );
$cobh = term_exists( 'Cobh', 'conexao_town' );
ts_assert( $cobh, "legitimate town 'Cobh' still exists" );
$corofin = term_exists( 'Corofin', 'conexao_town' );
ts_assert( $corofin, "legitimate town 'Corofin' still exists" );

// Verify standalone Eircode terms removed.
$a92 = term_exists( 'A92 DF7X.', 'conexao_town' );
ts_assert( ! $a92, "standalone Eircode 'A92 DF7X.' removed" );
$w23 = term_exists( 'W23 FNP4,', 'conexao_town' );
ts_assert( ! $w23, "standalone Eircode 'W23 FNP4,' removed" );

echo "\n========================================\n";
echo "Test Results: {$passed} passed, {$failed} failed\n";
echo "========================================\n";

exit( $failed > 0 ? 1 : 0 );