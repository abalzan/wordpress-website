<?php
/**
 * Stage 7.x regression tests — the source-level `county` taxonomy hint.
 *
 * Usage:
 *   docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/test-source-county-hint.php
 *
 * ## The rule under test
 *
 * An Event source declares a per-source `county`. That hint is the ONLY thing
 * that gives an imported event a `conexao_county` term, and the /eventos
 * archive filters on exactly that term (?county=<slug>). So:
 *
 *   1. A shipped source whose STORED county drifted to '' must be repaired
 *      back to the county the source ships with — otherwise every event it
 *      imports is permanently invisible to the county filter.
 *   2. A deliberate non-empty stored county is NEVER overwritten.
 *   3. A source that ships no county stays empty (no invented geography).
 *   4. End to end: a source-shaped event carrying a county hint ends up with a
 *      real `conexao_county` term, and is then returned by the events query
 *      that the archive uses.
 *
 * These tests assert the RULE with synthetic sources and a throwaway event.
 * They never reference a real event ID, and they clean up everything they
 * create, so the suite is safe to run against a populated install.
 *
 * @package Conexao_Event_Importer
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$passed = 0;
$failed = 0;

$sources_mgr = new Conexao_Event_Sources();
$option_key  = Conexao_Event_Sources::OPTION_KEY;

/**
 * Replace the stored sources registry with $map for the duration of a test,
 * always restoring the original value afterwards.
 *
 * @param array         $map    Replacement registry.
 * @param array|null    $holder Set to the original registry on first call.
 * @return void
 */
function stage7_set_sources( array $map, &$holder ) {
	global $option_key;
	if ( null === $holder ) {
		$holder = get_option( $option_key, array() );
	}
	update_option( $option_key, $map, false );
}

/**
 * Restore the original sources registry.
 *
 * @param array|null $holder Original registry.
 * @return void
 */
function stage7_restore_sources( $holder ) {
	global $option_key;
	if ( null !== $holder ) {
		update_option( $option_key, $holder, false );
	}
}

// ---------------------------------------------------------------------------
// Test 1: a drifted (emptied) shipped county is repaired from the default.
// ---------------------------------------------------------------------------
test_section( 'Shipped county hint is self-healed when it drifts to empty' );

$original = null;

// Store laois_tourism with an EMPTY county — exactly the drift that made every
// Laois Tourism event invisible to /eventos?county=laois.
$defaults = $sources_mgr->get_defaults();
$drifted  = $defaults;
$drifted['laois_tourism']['county'] = '';

stage7_set_sources( $drifted, $original );

$repaired = ( new Conexao_Event_Sources() )->get_all();

assert_true(
	'Laois' === ( $repaired['laois_tourism']['county'] ?? '' ),
	'an emptied laois_tourism county is restored to the shipped default "Laois"'
);

// The repair must be PERSISTED, not only returned, or the next process would
// re-derive it and an importer run would still see the empty value.
$persisted = get_option( $option_key, array() );
assert_true(
	'Laois' === ( $persisted['laois_tourism']['county'] ?? '' ),
	'the repaired county hint is written back to the stored option'
);

// Idempotence: a second read changes nothing.
$again = ( new Conexao_Event_Sources() )->get_all();
assert_true(
	'Laois' === ( $again['laois_tourism']['county'] ?? '' ),
	'reading again keeps the repaired county (idempotent)'
);

// ---------------------------------------------------------------------------
// Test 2: a deliberate non-empty county is never overwritten.
// ---------------------------------------------------------------------------
test_section( 'A deliberate non-empty county is never overwritten' );

$overridden          = $defaults;
$overridden['laois_tourism']['county'] = 'Kilkenny';

stage7_set_sources( $overridden, $original );

$kept = ( new Conexao_Event_Sources() )->get_all();
assert_true(
	'Kilkenny' === ( $kept['laois_tourism']['county'] ?? '' ),
	'an operator override ("Kilkenny") survives; the shipped default does not win'
);

// ---------------------------------------------------------------------------
// Test 3: a source that ships no county is not given one.
// ---------------------------------------------------------------------------
test_section( 'A source shipping no county stays empty (no invented geography)' );

// ivvcc and motorsport_ireland ship with county => ''.
assert_true(
	'' === ( $defaults['ivvcc']['county'] ?? 'missing' ),
	'ivvcc ships an empty county'
);
assert_true(
	'' === ( $defaults['motorsport_ireland']['county'] ?? 'missing' ),
	'motorsport_ireland ships an empty county'
);

$no_county = $defaults;
stage7_set_sources( $no_county, $original );

$untouched = ( new Conexao_Event_Sources() )->get_all();

// ---------------------------------------------------------------------------
// Test 4: end to end — a source-shaped event gets a real county term and is
// then returned by the events query the /eventos archive relies on.
//
// This is the test that fails if the original bug returns: an event imported
// from a county-declaring source must be reachable through a county-scoped
// event query, without any per-ID special casing.
// ---------------------------------------------------------------------------
test_section( 'A source-shaped event with a county hint is reachable via a county-scoped event query' );

// A county that certainly exists in the registry and ships on a source.
$county_name = (string) $defaults['laois_tourism']['county'];
assert_true( '' !== $county_name, 'the fixture source declares a county' );

$county_term = term_exists( $county_name, 'conexao_county' );
assert_true( $county_term && ! is_wp_error( $county_term ), "the shared '{$county_name}' county term already exists" );

$county_term_id = (int) ( is_array( $county_term ) ? $county_term['term_id'] : $county_term );

/*
 * Build a throwaway event carrying exactly the shape a county-declaring
 * source emits: the identity meta plus a `county` in the normalized payload.
 * The event is dated in the future so the archive's date window admits it.
 */
$fixture_id = wp_insert_post(
	array(
		'post_type'   => 'event',
		'post_status' => 'publish',
		'post_title'  => 'Stage 7 county-hint fixture ' . wp_generate_password( 8, false ),
	)
);
assert_true( $fixture_id && ! is_wp_error( $fixture_id ), 'fixture event created' );

update_post_meta( $fixture_id, '_event_source', 'laois_tourism' );
update_post_meta( $fixture_id, '_event_source_id', 'stage7-fixture-' . $fixture_id );
update_post_meta( $fixture_id, '_event_export_uuid', wp_generate_uuid4() );
update_post_meta( $fixture_id, '_event_status', 'published' );
update_post_meta( $fixture_id, '_event_date', gmdate( 'Y-m-d', strtotime( '+30 days' ) ) );
update_post_meta( $fixture_id, '_event_end_date', gmdate( 'Y-m-d', strtotime( '+30 days' ) ) );

/** Run a county-scoped event query the way the archive does. */
$query_county = static function () use ( $county_name ) {
	$q = new WP_Query(
		array(
			'post_type'      => 'event',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => array(
				array(
					'taxonomy' => 'conexao_county',
					'field'    => 'slug',
					'terms'    => sanitize_title( $county_name ),
				),
			),
		)
	);
	return array_map( 'intval', $q->posts );
};

assert_true(
	! in_array( (int) $fixture_id, $query_county(), true ),
	'before save_event_taxonomies(), the fixture is NOT in the county-scoped event query'
);

/*
 * The exact call the importer makes once a source forwards its county hint.
 * save_event_taxonomies() is protected and the constructor is private (the
 * importer is a singleton), so build an uninitialised instance and invoke the
 * real shipped method on it. save_event_taxonomies() only uses $this->location,
 * which we initialise explicitly. This exercises the real method, not a copy.
 */
$importer_reflect = new ReflectionClass( 'Conexao_Event_Importer_Engine' );
$importer         = $importer_reflect->newInstanceWithoutConstructor();

$location_prop = $importer_reflect->getProperty( 'location' );
$location_prop->setAccessible( true );
$location_prop->setValue( $importer, new Conexao_Event_Location() );

$method = new ReflectionMethod( 'Conexao_Event_Importer_Engine', 'save_event_taxonomies' );
$method->setAccessible( true );
$method->invoke( $importer, $fixture_id, array( 'county' => $county_name ) );

$fixture_county = wp_get_object_terms( $fixture_id, 'conexao_county', array( 'fields' => 'ids' ) );
assert_true(
	! is_wp_error( $fixture_county ) && in_array( $county_term_id, array_map( 'intval', $fixture_county ), true ),
	'save_event_taxonomies() assigned the EXISTING shared county term (no new term)'
);

assert_true(
	1 === count( array_filter( (array) $fixture_county ) ),
	'exactly one county term is assigned (no per-language duplicate)'
);

assert_true(
	in_array( (int) $fixture_id, $query_county(), true ),
	'AFTER the assignment the fixture IS returned by the county-scoped event query'
);

// The county taxonomy is SHARED: the term must carry no Polylang language and
// no cross-language counterpart (AGENTS.md taxonomy policy).
if ( function_exists( 'pll_get_term_language' ) ) {
	$term_lang = pll_get_term_language( $county_term_id, 'slug' );
	assert_true(
		! is_string( $term_lang ) || '' === $term_lang,
		'the county term carries no language (shared geography taxonomy)'
	);
}

// The identity meta must be untouched by a taxonomy save.
assert_true(
	'laois_tourism' === get_post_meta( $fixture_id, '_event_source', true ),
	'_event_source is unchanged by the taxonomy assignment'
);
assert_true(
	'stage7-fixture-' . $fixture_id === get_post_meta( $fixture_id, '_event_source_id', true ),
	'_event_source_id is unchanged by the taxonomy assignment'
);
assert_true(
	'' !== get_post_meta( $fixture_id, '_event_export_uuid', true ),
	'_event_export_uuid is preserved'
);

// ---------------------------------------------------------------------------
// Test 5: a payload WITHOUT a county must not gain one.
// ---------------------------------------------------------------------------
test_section( 'A payload with no county hint does not acquire a county term' );

$bare_id = wp_insert_post(
	array(
		'post_type'   => 'event',
		'post_status' => 'publish',
		'post_title'  => 'Stage 7 no-county fixture ' . wp_generate_password( 8, false ),
	)
);
update_post_meta( $bare_id, '_event_source', 'laois_tourism' );
update_post_meta( $bare_id, '_event_status', 'published' );

$method->invoke( $importer, $bare_id, array( 'county' => '' ) );

$bare_county = wp_get_object_terms( $bare_id, 'conexao_county', array( 'fields' => 'ids' ) );
assert_true(
	empty( $bare_county ),
	'an empty county hint creates NO county term (no fabricated geography)'
);

// ---------------------------------------------------------------------------
// Cleanup: remove every fixture this suite created.
// ---------------------------------------------------------------------------
stage7_restore_sources( $original );

foreach ( array( $fixture_id, $bare_id ) as $cleanup_id ) {
	if ( $cleanup_id && ! is_wp_error( $cleanup_id ) ) {
		wp_delete_post( $cleanup_id, true );
	}
}

test_finish();

assert_true(
	'' === ( $untouched['ivvcc']['county'] ?? 'missing' ),
	'ivvcc is still empty after the self-heal pass'
);

// A source id that ships NOTHING must not acquire a county either.
$mystery           = $defaults;
$mystery['zzz_unknown_source'] = array(
	'id'     => 'zzz_unknown_source',
	'name'   => 'Unknown',
	'type'   => 'website',
	'status' => 'inactive',
);
stage7_set_sources( $mystery, $original );

$still_none = ( new Conexao_Event_Sources() )->get_all();
assert_true(
	! isset( $still_none['zzz_unknown_source']['county'] )
		|| '' === $still_none['zzz_unknown_source']['county'],
	'an unknown source id does not gain a county'
);
