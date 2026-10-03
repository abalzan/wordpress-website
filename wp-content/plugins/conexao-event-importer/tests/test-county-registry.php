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


// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$passed = 0;
$failed = 0;

// ---------------------------------------------------------------------------
// Test 1: County Registry
// ---------------------------------------------------------------------------
test_section( 'County Registry' );

$counties = Conexao_County_Registry::get_counties();
assert_true( 26 === count( $counties ), 'Registry contains 26 counties' );

$slugs = Conexao_County_Registry::get_slugs();
assert_true( 26 === count( $slugs ), '26 county slugs returned' );

// Verify all 26 expected counties exist.
$expected = array( 'carlow','cavan','clare','cork','donegal','dublin','galway','kerry','kildare','kilkenny','laois','leitrim','limerick','longford','louth','mayo','meath','monaghan','offaly','roscommon','sligo','tipperary','waterford','westmeath','wexford','wicklow' );
foreach ( $expected as $slug ) {
	assert_true( isset( $counties[ $slug ] ), "County '{$slug}' exists in registry" );
}

// Verify ROI jurisdiction.
foreach ( $counties as $slug => $data ) {
	assert_true( 'ROI' === $data['jurisdiction'], "{$slug}: jurisdiction is ROI" );
}

// Verify each county has required fields.
foreach ( $counties as $slug => $data ) {
	assert_true( ! empty( $data['name'] ), "{$slug}: has display name" );
	assert_true( ! empty( $data['eb_slug'] ), "{$slug}: has EB slug" );
	assert_true( ! empty( $data['eb_region_labels'] ) && is_array( $data['eb_region_labels'] ), "{$slug}: has EB region labels" );
	assert_true( ! empty( $data['hw_where'] ) && is_array( $data['hw_where'] ), "{$slug}: has HW where[] values" );
}

// Verify Cork has multiple region labels.
assert_true( count( $counties['cork']['eb_region_labels'] ) === 2, 'Cork has 2 region labels' );
assert_true( in_array( 'Cork', $counties['cork']['eb_region_labels'], true ), 'Cork includes "Cork"' );
assert_true( in_array( 'Cork City', $counties['cork']['eb_region_labels'], true ), 'Cork includes "Cork City"' );

// Verify Galway has multiple region labels.
assert_true( count( $counties['galway']['eb_region_labels'] ) === 2, 'Galway has 2 region labels' );

// Verify Dublin has 4 HW where[] values.
assert_true( count( $counties['dublin']['hw_where'] ) === 4, 'Dublin has 4 HW where[] values' );

// Verify Cork has correct HW where[].
assert_true( $counties['cork']['hw_where'] === array( 'cork-county' ), 'Cork HW where[] is [cork-county]' );

// Verify Galway has correct HW where[].
assert_true( count( $counties['galway']['hw_where'] ) === 2, 'Galway has 2 HW where[] values' );

// ---------------------------------------------------------------------------
// Test 2: Eventbrite Source Config Generation
// ---------------------------------------------------------------------------
test_section( 'Eventbrite Source Config Generation' );

$eb_sources = Conexao_County_Registry::get_all_eventbrite_sources();
assert_true( 26 === count( $eb_sources ), '26 Eventbrite source configs generated' );

// Verify key format.
assert_true( isset( $eb_sources['eventbrite_laois'] ), 'eventbrite_laois key exists' );
assert_true( isset( $eb_sources['eventbrite_cork'] ), 'eventbrite_cork key exists' );
assert_true( isset( $eb_sources['eventbrite_dublin'] ), 'eventbrite_dublin key exists' );

// Verify all are inactive.
foreach ( $eb_sources as $id => $source ) {
	assert_true( 'inactive' === $source['status'], "{$id}: status is inactive" );
	assert_true( 'eventbrite' === $source['type'], "{$id}: type is eventbrite" );
	assert_true( ! empty( $source['url'] ), "{$id}: has URL" );
	assert_true( ! empty( $source['county'] ), "{$id}: has county" );
	assert_true( ! empty( $source['region_labels'] ), "{$id}: has region_labels" );
}

// Verify Laois URL.
assert_true( strpos( $eb_sources['eventbrite_laois']['url'], 'ireland--laois' ) !== false, 'Laois EB URL contains ireland--laois' );

// Verify Cork URL.
assert_true( strpos( $eb_sources['eventbrite_cork']['url'], 'ireland--cork' ) !== false, 'Cork EB URL contains ireland--cork' );

// ---------------------------------------------------------------------------
// Test 3: Heritage Week Source Config Generation
// ---------------------------------------------------------------------------
test_section( 'Heritage Week Source Config Generation' );

$hw_sources = Conexao_County_Registry::get_all_heritage_week_sources();
assert_true( 26 === count( $hw_sources ), '26 Heritage Week source configs generated' );

// Verify key format.
assert_true( isset( $hw_sources['heritage_week_laois'] ), 'heritage_week_laois key exists' );
assert_true( isset( $hw_sources['heritage_week_cork'] ), 'heritage_week_cork key exists' );
assert_true( isset( $hw_sources['heritage_week_dublin'] ), 'heritage_week_dublin key exists' );

// Verify all are inactive.
foreach ( $hw_sources as $id => $source ) {
	assert_true( 'inactive' === $source['status'], "{$id}: status is inactive" );
	assert_true( 'heritage_week' === $source['type'], "{$id}: type is heritage_week" );
	assert_true( ! empty( $source['url'] ), "{$id}: has URL" );
	assert_true( ! empty( $source['county'] ), "{$id}: has county" );
	assert_true( ! empty( $source['hw_where'] ) && is_array( $source['hw_where'] ), "{$id}: has hw_where array" );
}

// Verify Dublin has 4 hw_where values.
assert_true( count( $hw_sources['heritage_week_dublin']['hw_where'] ) === 4, 'heritage_week_dublin has 4 hw_where values' );

// ---------------------------------------------------------------------------
// Test 4: Eventbrite Source ID (config-driven)
// ---------------------------------------------------------------------------
test_section( 'Eventbrite Source ID' );

// Config-driven ID.
$laois_eb = Conexao_County_Registry::get_eventbrite_source( 'laois' );
$source = new Conexao_Source_Eventbrite( $laois_eb );
assert_true( 'eventbrite_laois' === $source->get_id(), 'eventbrite_laois: get_id() returns config ID' );

$cork_eb = Conexao_County_Registry::get_eventbrite_source( 'cork' );
$source = new Conexao_Source_Eventbrite( $cork_eb );
assert_true( 'eventbrite_cork' === $source->get_id(), 'eventbrite_cork: get_id() returns config ID' );

$dublin_eb = Conexao_County_Registry::get_eventbrite_source( 'dublin' );
$source = new Conexao_Source_Eventbrite( $dublin_eb );
assert_true( 'eventbrite_dublin' === $source->get_id(), 'eventbrite_dublin: get_id() returns config ID' );

// Legacy fallback (empty config).
$source = new Conexao_Source_Eventbrite( array() );
assert_true( 'eventbrite' === $source->get_id(), 'Legacy fallback: get_id() returns eventbrite' );

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
assert_true( $labels === array( 'Laois' ), 'Laois region labels = [Laois]' );

// Cork accepts 'Cork' and 'Cork City'.
$source = new Conexao_Source_Eventbrite( $cork_eb );
$labels = $method->invoke( $source );
assert_true( in_array( 'Cork', $labels, true ), 'Cork region labels include "Cork"' );
assert_true( in_array( 'Cork City', $labels, true ), 'Cork region labels include "Cork City"' );

// ---------------------------------------------------------------------------
// Test 6: Eventbrite County Assignment
// ---------------------------------------------------------------------------
test_section( 'Eventbrite County Assignment' );

$method = $reflect->getMethod( 'get_county' );
$method->setAccessible( true );

$source = new Conexao_Source_Eventbrite( $laois_eb );
assert_true( 'Laois' === $method->invoke( $source ), 'Laois county = "Laois"' );

$source = new Conexao_Source_Eventbrite( $cork_eb );
assert_true( 'Cork' === $method->invoke( $source ), 'Cork county = "Cork"' );

$source = new Conexao_Source_Eventbrite( $dublin_eb );
assert_true( 'Dublin' === $method->invoke( $source ), 'Dublin county = "Dublin"' );

// ---------------------------------------------------------------------------
// Test 7: Eventbrite Dead API Path Removal
// ---------------------------------------------------------------------------
test_section( 'Eventbrite Dead API Path Removal' );
	assert_true( ! method_exists( 'Conexao_Source_Eventbrite', 'fetch_via_api' ), 'fetch_via_api() removed' );
assert_true( ! method_exists( 'Conexao_Source_Eventbrite', 'use_api' ), 'use_api() removed' );
assert_true( ! defined( 'Conexao_Source_Eventbrite::API_SEARCH_URL' ), 'API_SEARCH_URL constant removed' );

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
assert_true( 'Test Venue' === $normalized['venue'], 'primary_venue name: venue populated' );
assert_true( '123 Main St, Cork, Cork, T12 AB12' === $normalized['address'], 'primary_venue address: full address populated' );
assert_true( 'Cork' === $normalized['town'], 'primary_venue city: town populated' );
assert_true( 'eventbrite_cork' === $normalized['source'], 'source from raw event' );
assert_true( 'Cork' === $normalized['county'], 'county from raw event' );

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
assert_true( 'Old Venue' === $normalized_legacy['venue'], 'Legacy venue name: venue populated' );
assert_true( '456 Old St, Laois' === $normalized_legacy['address'], 'Legacy venue address: populated' );

// Test description prefers summary.
assert_true( 'Short desc' === $normalized['description'], 'Description prefers summary' );

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
assert_true( ! $result['accepted'], 'Laois rejects Cork region' );

// Laois source accepts an event with region 'Laois'.
$result = $method->invoke( $source, array(
	'locations' => array( array( 'type' => 'region', 'name' => 'Laois' ) ),
) );
assert_true( $result['accepted'], 'Laois accepts Laois region' );

// ---------------------------------------------------------------------------
// Test 10: Heritage Week Source ID (config-driven)
// ---------------------------------------------------------------------------
test_section( 'Heritage Week Source ID' );

$laois_hw = Conexao_County_Registry::get_heritage_week_source( 'laois' );
$source = new Conexao_Source_Heritage_Week( $laois_hw );
assert_true( 'heritage_week_laois' === $source->get_id(), 'heritage_week_laois: get_id() returns config ID' );

$cork_hw = Conexao_County_Registry::get_heritage_week_source( 'cork' );
$source = new Conexao_Source_Heritage_Week( $cork_hw );
assert_true( 'heritage_week_cork' === $source->get_id(), 'heritage_week_cork: get_id() returns config ID' );

// Legacy fallback.
$source = new Conexao_Source_Heritage_Week( array() );
assert_true( 'heritage_week' === $source->get_id(), 'Legacy fallback: get_id() returns heritage_week' );

// ---------------------------------------------------------------------------
// Test 11: Heritage Week Multiple where[]
// ---------------------------------------------------------------------------
test_section( 'Heritage Week Multiple where[]' );

$reflect_hw = new ReflectionClass( 'Conexao_Source_Heritage_Week' );
$method = $reflect_hw->getMethod( 'get_hw_where' );
$method->setAccessible( true );

$source = new Conexao_Source_Heritage_Week( $laois_hw );
$hw_where = $method->invoke( $source );
assert_true( $hw_where === array( 'laois' ), 'Laois HW where[] = [laois]' );

$dublin_hw = Conexao_County_Registry::get_heritage_week_source( 'dublin' );
$source = new Conexao_Source_Heritage_Week( $dublin_hw );
$hw_where = $method->invoke( $source );
assert_true( count( $hw_where ) === 4, 'Dublin HW where[] has 4 values' );

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
assert_true( $handler instanceof Conexao_Source_Eventbrite, 'eventbrite type routes to Conexao_Source_Eventbrite' );

// heritage_week type routes to Conexao_Source_Heritage_Week.
$handler = $method->invoke( $plugin->importer, array( 'id' => 'heritage_week_cork', 'type' => 'heritage_week', 'url' => 'test' ) );
assert_true( $handler instanceof Conexao_Source_Heritage_Week, 'heritage_week type routes to Conexao_Source_Heritage_Week' );

// heritage_week_laois (type-based routing).
$handler = $method->invoke( $plugin->importer, array( 'id' => 'heritage_week_laois', 'type' => 'heritage_week', 'url' => 'test' ) );
assert_true( $handler instanceof Conexao_Source_Heritage_Week, 'heritage_week_laois routes to Conexao_Source_Heritage_Week' );

// Legacy: heritage_week ID still routes to Conexao_Source_Heritage_Week.
$handler = $method->invoke( $plugin->importer, array( 'id' => 'heritage_week', 'type' => 'website', 'url' => 'test' ) );
assert_true( $handler instanceof Conexao_Source_Heritage_Week, 'Legacy heritage_week ID routes to Conexao_Source_Heritage_Week' );

// ---------------------------------------------------------------------------
// Test 13: Registry Completeness and Seeding Idempotency
// ---------------------------------------------------------------------------
test_section( 'Registry Completeness and Seeding Idempotency' );

$sources_mgr = new Conexao_Event_Sources();
$option_key  = Conexao_Event_Sources::OPTION_KEY;

// Snapshot whatever the environment had, so this test restores it exactly.
$snapshot = get_option( $option_key, array() );

// --- 13a: SELF-HEAL FROM A SIX-SOURCE (or empty) ENVIRONMENT ---------------
//
// This is the regression this suite guards. The county registry used to be
// reachable ONLY through the manual `seed_county_sources()` operator step, so
// any environment that was not interactively activated reported just the six
// legacy defaults and silently lost the whole 26-county coverage. get_all()
// now self-heals, so a plain read MUST restore the complete registry.

$only_legacy = array();
foreach ( $sources_mgr->get_defaults() as $id => $source ) {
	$only_legacy[ $id ] = $source;
}
update_option( $option_key, $only_legacy, false );

// A plain read must repair it -- no operator step, no explicit seeding.
$repaired = $sources_mgr->get_all();

assert_true(
	52 === count( $sources_mgr->get_county_source_ids() ),
	'Self-heal: a 6-source environment is completed to 52 county sources by get_all() alone'
);
assert_true(
	58 === count( $repaired ),
	'Self-heal: registry holds 58 sources (6 legacy defaults + 52 county)'
);

// Every one of the 26 counties is represented by BOTH providers.
$missing_eb = array();
$missing_hw = array();
foreach ( Conexao_County_Registry::get_slugs() as $slug ) {
	if ( ! isset( $repaired[ 'eventbrite_' . $slug ] ) ) {
		$missing_eb[] = $slug;
	}
	if ( ! isset( $repaired[ 'heritage_week_' . $slug ] ) ) {
		$missing_hw[] = $slug;
	}
}
assert_true( array() === $missing_eb, 'All 26 counties have an eventbrite_<county> source' );
assert_true( array() === $missing_hw, 'All 26 counties have a heritage_week_<county> source' );

// The six legacy defaults must SURVIVE the repair untouched.
$legacy_ids      = array(
	'laois_tourism',
	'heritage_week',
	'eventbrite',
	'ivvcc',
	'motorsport_ireland',
	'mondello_park',
);
$legacy_defaults = $sources_mgr->get_defaults();
$legacy_intact   = true;
foreach ( $legacy_ids as $id ) {
	if ( ! isset( $repaired[ $id ] ) || $repaired[ $id ] !== $legacy_defaults[ $id ] ) {
		$legacy_intact = false;
	}
}
assert_true( $legacy_intact, 'The six legacy default sources survive the repair unmodified' );

// Restored county sources ship INACTIVE: repairing the registry must never
// start an import, fetch a provider or write an Event post.
$county_active = 0;
foreach ( Conexao_County_Registry::get_all_county_sources() as $id => $source ) {
	if ( isset( $repaired[ $id ] ) && 'active' === $repaired[ $id ]['status'] ) {
		$county_active++;
	}
}
assert_true( 0 === $county_active, 'No restored county source is active (repair cannot trigger an import)' );

// A restored county source must match the registry EXACTLY -- the repair
// copies the authoritative config, it does not rebuild it from scratch.
$exact_match = true;
foreach ( Conexao_County_Registry::get_all_county_sources() as $id => $registry_source ) {
	if ( ! isset( $repaired[ $id ] ) ) {
		$exact_match = false;
		break;
	}
	foreach ( array( 'id', 'name', 'url', 'type', 'status', 'county', 'region_labels', 'hw_where', 'category' ) as $field ) {
		if ( ( $registry_source[ $field ] ?? null ) !== ( $repaired[ $id ][ $field ] ?? null ) ) {
			$exact_match = false;
			break 2;
		}
	}
}
assert_true( $exact_match, 'Every restored county source matches Conexao_County_Registry exactly' );

// --- 13b: EXPLICIT SEEDING IS STILL IDEMPOTENT ---------------------------
//
// With the registry already complete, the manual operator step is a no-op.
$result2 = $sources_mgr->seed_county_sources();
assert_true( 0 === $result2['inserted'], 'Explicit seed after self-heal: 0 inserted (idempotent)' );
assert_true( 52 === $result2['skipped'], 'Explicit seed after self-heal: 52 skipped (idempotent)' );

// --- 13c: NO DUPLICATE REGISTRATIONS --------------------------------------
//
// A source id is the array key, so a duplicate registration is structurally
// impossible; assert the observable invariant anyway, because it is what a
// duplicate import would actually break.
$all_ids    = array_keys( $sources_mgr->get_all() );
$unique_ids = array_unique( $all_ids );
assert_true(
	count( $all_ids ) === count( $unique_ids ),
	'Every source id is registered exactly once (no duplicate registrations)'
);

// --- 13d: RETIRED SOURCES ARE NOT RESTORED -------------------------------
//
// laois_council / leo_laois / local_enterprise_office_laois were deliberately
// retired (commit 042259d). The self-heal must never resurrect them.
$retired_present = array();
foreach ( array( 'laois_council', 'leo_laois', 'local_enterprise_office_laois' ) as $retired ) {
	if ( isset( $repaired[ $retired ] ) ) {
		$retired_present[] = $retired;
	}
}
assert_true( array() === $retired_present, 'Intentionally retired sources are NOT restored by the self-heal' );

// --- 13e: AN OPERATOR EDIT IS NEVER OVERWRITTEN ---------------------------
//
// The repair only INSERTS missing ids. A source the operator activated, or
// re-pointed, must survive untouched.
update_option( $option_key, $only_legacy, false );
$sources_mgr->get_all(); // re-heal
$custom = $sources_mgr->get_all();
$custom['eventbrite_cork']['status'] = 'active';
$custom['eventbrite_cork']['url']    = 'https://example.invalid/operator-override';
update_option( $option_key, $custom, false );
$after_override = $sources_mgr->get_all();
assert_true(
	'active' === $after_override['eventbrite_cork']['status']
		&& 'https://example.invalid/operator-override' === $after_override['eventbrite_cork']['url'],
	'An operator override of a county source is never overwritten by the self-heal'
);

// --- 13f: AN EMPTY OPTION SELF-HEALS TO THE FULL REGISTRY ----------------
delete_option( $option_key );
$from_empty = $sources_mgr->get_all();
assert_true( 58 === count( $from_empty ), 'An empty/absent option self-heals to the full 58-source registry' );

// Restore the exact environment state this test found.
if ( is_array( $snapshot ) && array() !== $snapshot ) {
	update_option( $option_key, $snapshot, false );
} else {
	delete_option( $option_key );
}

// ---------------------------------------------------------------------------
// Test 14: Independent Source Logging
// ---------------------------------------------------------------------------
test_section( 'Independent Source Logging' );

// Verify the client accepts source ID.
$client = new Conexao_Eventbrite_Client( 'eventbrite_cork' );
$client_reflect = new ReflectionClass( $client );
$prop = $client_reflect->getProperty( 'source_id' );
$prop->setAccessible( true );
assert_true( 'eventbrite_cork' === $prop->getValue( $client ), 'Client source_id = eventbrite_cork' );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

test_finish();
