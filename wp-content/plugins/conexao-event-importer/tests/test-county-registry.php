<?php
/**
 * Automated tests for the multi-county registry and source parameterization.
 *
 * Usage: docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/test-county-registry.php
 *
 * These tests verify the county registry, source config generation,
 * handler routing, and the refactored provider implementations.
 * No live HTTP requests. No Event records created.
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
// Test 1: County Registry
// ---------------------------------------------------------------------------
test_section( 'County Registry' );

$counties = Conexao_County_Registry::get_counties();
test_assert( 26 === count( $counties ), 'Registry contains 26 counties' );

$slugs = Conexao_County_Registry::get_slugs();
test_assert( 26 === count( $slugs ), '26 county slugs returned' );

// Verify all 26 expected counties exist.
$expected = array( 'carlow','cavan','clare','cork','donegal','dublin','galway','kerry','kildare','kilkenny','laois','leitrim','limerick','longford','louth','mayo','meath','monaghan','offaly','roscommon','sligo','tipperary','waterford','westmeath','wexford','wicklow' );
foreach ( $expected as $slug ) {
	test_assert( isset( $counties[ $slug ] ), "County '{$slug}' exists in registry" );
}

// Verify ROI jurisdiction.
foreach ( $counties as $slug => $data ) {
	test_assert( 'ROI' === $data['jurisdiction'], "{$slug}: jurisdiction is ROI" );
}

// Verify each county has required fields.
foreach ( $counties as $slug => $data ) {
	test_assert( ! empty( $data['name'] ), "{$slug}: has display name" );
	test_assert( ! empty( $data['eb_slug'] ), "{$slug}: has EB slug" );
	test_assert( ! empty( $data['eb_region_labels'] ) && is_array( $data['eb_region_labels'] ), "{$slug}: has EB region labels" );
	test_assert( ! empty( $data['hw_where'] ) && is_array( $data['hw_where'] ), "{$slug}: has HW where[] values" );
}

// Verify Cork has multiple region labels.
test_assert( count( $counties['cork']['eb_region_labels'] ) === 2, 'Cork has 2 region labels' );
test_assert( in_array( 'Cork', $counties['cork']['eb_region_labels'], true ), 'Cork includes "Cork"' );
test_assert( in_array( 'Cork City', $counties['cork']['eb_region_labels'], true ), 'Cork includes "Cork City"' );

// Verify Galway has multiple region labels.
test_assert( count( $counties['galway']['eb_region_labels'] ) === 2, 'Galway has 2 region labels' );

// Verify Dublin has 4 HW where[] values.
test_assert( count( $counties['dublin']['hw_where'] ) === 4, 'Dublin has 4 HW where[] values' );

// Verify Cork has correct HW where[].
test_assert( $counties['cork']['hw_where'] === array( 'cork-county' ), 'Cork HW where[] is [cork-county]' );

// Verify Galway has correct HW where[].
test_assert( count( $counties['galway']['hw_where'] ) === 2, 'Galway has 2 HW where[] values' );

// ---------------------------------------------------------------------------
// Test 2: Eventbrite Source Config Generation
// ---------------------------------------------------------------------------
test_section( 'Eventbrite Source Config Generation' );

$eb_sources = Conexao_County_Registry::get_all_eventbrite_sources();
test_assert( 26 === count( $eb_sources ), '26 Eventbrite source configs generated' );

// Verify key format.
test_assert( isset( $eb_sources['eventbrite_laois'] ), 'eventbrite_laois key exists' );
test_assert( isset( $eb_sources['eventbrite_cork'] ), 'eventbrite_cork key exists' );
test_assert( isset( $eb_sources['eventbrite_dublin'] ), 'eventbrite_dublin key exists' );

// Verify all are inactive.
foreach ( $eb_sources as $id => $source ) {
	test_assert( 'inactive' === $source['status'], "{$id}: status is inactive" );
	test_assert( 'eventbrite' === $source['type'], "{$id}: type is eventbrite" );
	test_assert( ! empty( $source['url'] ), "{$id}: has URL" );
	test_assert( ! empty( $source['county'] ), "{$id}: has county" );
	test_assert( ! empty( $source['region_labels'] ), "{$id}: has region_labels" );
}

// Verify Laois URL.
test_assert( strpos( $eb_sources['eventbrite_laois']['url'], 'ireland--laois' ) !== false, 'Laois EB URL contains ireland--laois' );

// Verify Cork URL.
test_assert( strpos( $eb_sources['eventbrite_cork']['url'], 'ireland--cork' ) !== false, 'Cork EB URL contains ireland--cork' );

// ---------------------------------------------------------------------------
// Test 3: Heritage Week Source Config Generation
// ---------------------------------------------------------------------------
test_section( 'Heritage Week Source Config Generation' );

$hw_sources = Conexao_County_Registry::get_all_heritage_week_sources();
test_assert( 26 === count( $hw_sources ), '26 Heritage Week source configs generated' );

// Verify key format.
test_assert( isset( $hw_sources['heritage_week_laois'] ), 'heritage_week_laois key exists' );
test_assert( isset( $hw_sources['heritage_week_cork'] ), 'heritage_week_cork key exists' );
test_assert( isset( $hw_sources['heritage_week_dublin'] ), 'heritage_week_dublin key exists' );

// Verify all are inactive.
foreach ( $hw_sources as $id => $source ) {
	test_assert( 'inactive' === $source['status'], "{$id}: status is inactive" );
	test_assert( 'heritage_week' === $source['type'], "{$id}: type is heritage_week" );
	test_assert( ! empty( $source['url'] ), "{$id}: has URL" );
	test_assert( ! empty( $source['county'] ), "{$id}: has county" );
	test_assert( ! empty( $source['hw_where'] ) && is_array( $source['hw_where'] ), "{$id}: has hw_where array" );
}

// Verify Dublin has 4 hw_where values.
test_assert( count( $hw_sources['heritage_week_dublin']['hw_where'] ) === 4, 'heritage_week_dublin has 4 hw_where values' );

// ---------------------------------------------------------------------------
// Test 4: Eventbrite Source ID (config-driven)
// ---------------------------------------------------------------------------
test_section( 'Eventbrite Source ID' );

// Config-driven ID.
$laois_eb = Conexao_County_Registry::get_eventbrite_source( 'laois' );
$source = new Conexao_Source_Eventbrite( $laois_eb );
test_assert( 'eventbrite_laois' === $source->get_id(), 'eventbrite_laois: get_id() returns config ID' );

$cork_eb = Conexao_County_Registry::get_eventbrite_source( 'cork' );
$source = new Conexao_Source_Eventbrite( $cork_eb );
test_assert( 'eventbrite_cork' === $source->get_id(), 'eventbrite_cork: get_id() returns config ID' );

$dublin_eb = Conexao_County_Registry::get_eventbrite_source( 'dublin' );
$source = new Conexao_Source_Eventbrite( $dublin_eb );
test_assert( 'eventbrite_dublin' === $source->get_id(), 'eventbrite_dublin: get_id() returns config ID' );

// Legacy fallback (empty config).
$source = new Conexao_Source_Eventbrite( array() );
test_assert( 'eventbrite' === $source->get_id(), 'Legacy fallback: get_id() returns eventbrite' );

// ---------------------------------------------------------------------------
// Test 5: Eventbrite Accepted Region Labels
// ---------------------------------------------------------------------------
test_section( 'Eventbrite Region Labels' );

$reflect = new ReflectionClass( 'Conexao_Source_Eventbrite' );
$method = $reflect->getMethod( 'get_region_labels' );
$method->setAccessible( true );

// Laois accepts 'Laois'.
$source = new Conexao_Source_Eventbrite( $laois_eb );
$labels = $method->invoke( $source );
test_assert( $labels === array( 'Laois' ), 'Laois region labels = [Laois]' );

// Cork accepts 'Cork' and 'Cork City'.
$source = new Conexao_Source_Eventbrite( $cork_eb );
$labels = $method->invoke( $source );
test_assert( in_array( 'Cork', $labels, true ), 'Cork region labels include "Cork"' );
test_assert( in_array( 'Cork City', $labels, true ), 'Cork region labels include "Cork City"' );

// ---------------------------------------------------------------------------
// Test 6: Eventbrite County Assignment
// ---------------------------------------------------------------------------
test_section( 'Eventbrite County Assignment' );

$method = $reflect->getMethod( 'get_county' );
$method->setAccessible( true );

$source = new Conexao_Source_Eventbrite( $laois_eb );
test_assert( 'Laois' === $method->invoke( $source ), 'Laois county = "Laois"' );

$source = new Conexao_Source_Eventbrite( $cork_eb );
test_assert( 'Cork' === $method->invoke( $source ), 'Cork county = "Cork"' );

$source = new Conexao_Source_Eventbrite( $dublin_eb );
test_assert( 'Dublin' === $method->invoke( $source ), 'Dublin county = "Dublin"' );

// ---------------------------------------------------------------------------
// Test 7: Eventbrite Dead API Path Removal
// ---------------------------------------------------------------------------
test_section( 'Eventbrite Dead API Path Removal' );
	test_assert( ! method_exists( 'Conexao_Source_Eventbrite', 'fetch_via_api' ), 'fetch_via_api() removed' );
test_assert( ! method_exists( 'Conexao_Source_Eventbrite', 'use_api' ), 'use_api() removed' );
test_assert( ! defined( 'Conexao_Source_Eventbrite::API_SEARCH_URL' ), 'API_SEARCH_URL constant removed' );

// ---------------------------------------------------------------------------
// Test 8: Eventbrite Normalizer — primary_venue
// ---------------------------------------------------------------------------
test_section( 'Eventbrite Normalizer — primary_venue' );

$normalizer = new Conexao_Eventbrite_Normalizer();

// Test primary_venue is read.
$raw = array(
	'id' => 'test123',
	'name' => 'Test Event',
	'url' => 'https://example.com/event',
	'summary' => 'Short desc',
	'primary_venue' => array(
		'name' => 'Test Venue',
		'address' => array(
			'address_1' => '123 Main St',
			'city' => 'Cork',
			'region' => 'Cork',
			'postal_code' => 'T12 AB12',
		),
	),
	'locations' => array(
		array( 'type' => 'region', 'name' => 'Cork' ),
		array( 'type' => 'locality', 'name' => 'Cork' ),
	),
	'source' => 'eventbrite_cork',
	'county' => 'Cork',
	'start_date' => '2026-12-01',
	'start_time' => '14:00',
	'end_date' => '2026-12-01',
	'end_time' => '16:00',
	'is_online_event' => false,
	'is_cancelled' => false,
);

$normalized = $normalizer->normalize( $raw );
test_assert( 'Test Venue' === $normalized['venue'], 'primary_venue name: venue populated' );
test_assert( '123 Main St, Cork, Cork, T12 AB12' === $normalized['address'], 'primary_venue address: full address populated' );
test_assert( 'Cork' === $normalized['town'], 'primary_venue city: town populated' );
test_assert( 'eventbrite_cork' === $normalized['source'], 'source from raw event' );
test_assert( 'Cork' === $normalized['county'], 'county from raw event' );

// Test legacy venue fallback.
$raw_legacy = array(
	'id' => 'test456',
	'name' => 'Legacy Event',
	'url' => 'https://example.com/legacy',
	'venue' => array(
		'name' => 'Old Venue',
		'address' => array(
			'address_1' => '456 Old St',
			'city' => 'Laois',
		),
	),
	'source' => 'eventbrite',
	'county' => 'Laois',
	'start_date' => '2026-12-01',
	'start_time' => '10:00',
	'end_date' => '2026-12-01',
	'end_time' => '12:00',
	'is_online_event' => false,
	'is_cancelled' => false,
);

$normalized_legacy = $normalizer->normalize( $raw_legacy );
test_assert( 'Old Venue' === $normalized_legacy['venue'], 'Legacy venue name: venue populated' );
test_assert( '456 Old St, Laois' === $normalized_legacy['address'], 'Legacy venue address: populated' );

// Test description prefers summary.
test_assert( 'Short desc' === $normalized['description'], 'Description prefers summary' );

// ---------------------------------------------------------------------------
// Test 9: Eventbrite Unknown Region Rejection
// ---------------------------------------------------------------------------
test_section( 'Eventbrite Unknown Region Rejection' );

$method = $reflect->getMethod( 'is_county_event' );
$method->setAccessible( true );

// Laois source rejects an event with region 'Cork'.
$source = new Conexao_Source_Eventbrite( $laois_eb );
$result = $method->invoke( $source, array(
	'locations' => array( array( 'type' => 'region', 'name' => 'Cork' ) ),
) );
test_assert( ! $result['accepted'], 'Laois rejects Cork region' );

// Laois source accepts an event with region 'Laois'.
$result = $method->invoke( $source, array(
	'locations' => array( array( 'type' => 'region', 'name' => 'Laois' ) ),
) );
test_assert( $result['accepted'], 'Laois accepts Laois region' );

// ---------------------------------------------------------------------------
// Test 10: Heritage Week Source ID (config-driven)
// ---------------------------------------------------------------------------
test_section( 'Heritage Week Source ID' );

$laois_hw = Conexao_County_Registry::get_heritage_week_source( 'laois' );
$source = new Conexao_Source_Heritage_Week( $laois_hw );
test_assert( 'heritage_week_laois' === $source->get_id(), 'heritage_week_laois: get_id() returns config ID' );

$cork_hw = Conexao_County_Registry::get_heritage_week_source( 'cork' );
$source = new Conexao_Source_Heritage_Week( $cork_hw );
test_assert( 'heritage_week_cork' === $source->get_id(), 'heritage_week_cork: get_id() returns config ID' );

// Legacy fallback.
$source = new Conexao_Source_Heritage_Week( array() );
test_assert( 'heritage_week' === $source->get_id(), 'Legacy fallback: get_id() returns heritage_week' );

// ---------------------------------------------------------------------------
// Test 11: Heritage Week Multiple where[]
// ---------------------------------------------------------------------------
test_section( 'Heritage Week Multiple where[]' );

$reflect_hw = new ReflectionClass( 'Conexao_Source_Heritage_Week' );
$method = $reflect_hw->getMethod( 'get_hw_where' );
$method->setAccessible( true );

$source = new Conexao_Source_Heritage_Week( $laois_hw );
$hw_where = $method->invoke( $source );
test_assert( $hw_where === array( 'laois' ), 'Laois HW where[] = [laois]' );

$dublin_hw = Conexao_County_Registry::get_heritage_week_source( 'dublin' );
$source = new Conexao_Source_Heritage_Week( $dublin_hw );
$hw_where = $method->invoke( $source );
test_assert( count( $hw_where ) === 4, 'Dublin HW where[] has 4 values' );

// ---------------------------------------------------------------------------
// Test 12: Handler Routing
// ---------------------------------------------------------------------------
test_section( 'Handler Routing' );

$plugin = Conexao_Event_Importer::instance();
$engine_reflect = new ReflectionClass( $plugin->importer );
$method = $engine_reflect->getMethod( 'get_source_handler' );
$method->setAccessible( true );

// eventbrite type routes to Conexao_Source_Eventbrite.
$handler = $method->invoke( $plugin->importer, array( 'id' => 'eventbrite_cork', 'type' => 'eventbrite', 'url' => 'test' ) );
test_assert( $handler instanceof Conexao_Source_Eventbrite, 'eventbrite type routes to Conexao_Source_Eventbrite' );

// heritage_week type routes to Conexao_Source_Heritage_Week.
$handler = $method->invoke( $plugin->importer, array( 'id' => 'heritage_week_cork', 'type' => 'heritage_week', 'url' => 'test' ) );
test_assert( $handler instanceof Conexao_Source_Heritage_Week, 'heritage_week type routes to Conexao_Source_Heritage_Week' );

// heritage_week_laois (type-based routing).
$handler = $method->invoke( $plugin->importer, array( 'id' => 'heritage_week_laois', 'type' => 'heritage_week', 'url' => 'test' ) );
test_assert( $handler instanceof Conexao_Source_Heritage_Week, 'heritage_week_laois routes to Conexao_Source_Heritage_Week' );

// Legacy: heritage_week ID still routes to Conexao_Source_Heritage_Week.
$handler = $method->invoke( $plugin->importer, array( 'id' => 'heritage_week', 'type' => 'website', 'url' => 'test' ) );
test_assert( $handler instanceof Conexao_Source_Heritage_Week, 'Legacy heritage_week ID routes to Conexao_Source_Heritage_Week' );

// ---------------------------------------------------------------------------
// Test 13: Source Seeding Idempotency
// ---------------------------------------------------------------------------
test_section( 'Source Seeding Idempotency' );

$sources_mgr = new Conexao_Event_Sources();

// First seeding.
$result1 = $sources_mgr->seed_county_sources();
test_assert( 52 === $result1['inserted'], 'First seed: 52 sources inserted' );
test_assert( 0 === $result1['skipped'], 'First seed: 0 skipped' );

// Second seeding (should skip all).
$result2 = $sources_mgr->seed_county_sources();
test_assert( 0 === $result2['inserted'], 'Second seed: 0 inserted (idempotent)' );
test_assert( 52 === $result2['skipped'], 'Second seed: 52 skipped (idempotent)' );

// Clean up test sources.
$sources = $sources_mgr->get_all();
foreach ( $result1['ids'] as $id ) {
	unset( $sources[ $id ] );
}
update_option( Conexao_Event_Sources::OPTION_KEY, $sources, false );

// ---------------------------------------------------------------------------
// Test 14: Independent Source Logging
// ---------------------------------------------------------------------------
test_section( 'Independent Source Logging' );

// Verify the client accepts source ID.
$client = new Conexao_Eventbrite_Client( 'eventbrite_cork' );
$client_reflect = new ReflectionClass( $client );
$prop = $client_reflect->getProperty( 'source_id' );
$prop->setAccessible( true );
test_assert( 'eventbrite_cork' === $prop->getValue( $client ), 'Client source_id = eventbrite_cork' );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n========================================\n";
echo "Test Results: {$passed} passed, {$failed} failed\n";
echo "========================================\n";

exit( $failed > 0 ? 1 : 0 );
