<?php
/**
 * Tests for the Phase 3C leisure related-events helper.
 *
 * Verifies `conexao_leisure_related_events()` against the Phase 3C design:
 *
 *  - COUNTY (`conexao_county`) is the ONLY relationship used — never
 *    category, keywords, title similarity, free text or geographic distance.
 *  - The helper intersects the SHARED cached upcoming-event list
 *    (`Conexao_Event_Query::upcoming_events()` via `conexao_event_upcoming_ids()`)
 *    — no second event-query system, no per-page transient, no per-event
 *    queries.
 *  - Runtime occurrence ordering is preserved (next occurrence ascending;
 *    recurring events use their existing next-occurrence logic; multi-day
 *    events use their active-date logic) and each event appears exactly once.
 *  - Maximum 2–3 event cards (helper caps at 3).
 *  - External (redirecting) leisure records never surface events.
 *  - Destinations without a county, or whose county has no upcoming events,
 *    return an empty array (the page then renders nothing).
 *
 * The script creates temporary posts/terms and deletes them at the end; it
 * writes no other data and runs in the current plugin/theme configuration.
 *
 * Requirements: conexao-data-model (leisure CPT + conexao_county taxonomy),
 * conexao-event-runtime (Conexao_Event_Query) and the active theme.
 *
 * Usage (from the project root):
 *   docker compose exec wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-leisure-related-events.php
 */

// --- Bootstrap WordPress (plugins + theme option). ---

// Load the active theme so the helpers under test are defined.
// The active theme is not auto-loaded by wp-load.php in a CLI context.
// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$theme_functions = get_template_directory() . '/functions.php';
if ( file_exists( $theme_functions ) ) {
	require_once $theme_functions;
}

$passed        = 0;
$failed        = 0;
$created_posts = array();
$created_terms = array();
$GLOBALS['ph3c_cohort'] = array();

/**
 * The term ID of an EXISTING, real county.
 *
 * `conexao_county` is a closed list of the Republic of Ireland's 26 counties
 * (`Conexao_Data_Model_Counties` rejects every other term at pre_insert_term),
 * so a fixture may not invent "Teste Alfa (PH3C)". The county is the site's own
 * shared term and is deliberately NOT registered for cleanup: this suite must
 * never delete one.
 *
 * @param string $slug Canonical county slug.
 * @return int Term ID, or 0 when that county term does not exist.
 */
function leisure_test_county( $slug ) {
	if ( ! taxonomy_exists( 'conexao_county' ) ) {
		return 0;
	}

	$existing = term_exists( $slug, 'conexao_county' );
	if ( ! $existing || is_wp_error( $existing ) ) {
		return 0;
	}

	$term = get_term( (int) ( is_array( $existing ) ? $existing['term_id'] : $existing ), 'conexao_county' );

	return ( $term instanceof WP_Term ) ? (int) $term->term_id : 0;
}

/**
 * `conexao_leisure_related_events()` restricted to THIS SUITE'S cohort.
 *
 * The real counties are shared with the site's own content, so "at most three
 * events, these three, in this order" is only a population-independent claim
 * inside the fixture cohort. The product query is untouched: the restriction is
 * a `pre_get_posts` filter at priority 999 that intersects its `post__in` (the
 * runtime's upcoming-event list) with the suite's own event IDs — the same
 * technique `test-stage41-rest-language.php` documents for its REST membership
 * assertions. Every assertion below still proves the real county scoping, the
 * 3-event cap, the ordering and the gates: the cohort contains a cross-county
 * event, a past event and a hidden event that must all stay out.
 *
 * @param int $leisure_id Leisure record.
 * @return array Related event posts.
 */
function leisure_test_related( $leisure_id ) {
	$cohort = array_values( array_map( 'intval', array_filter( (array) $GLOBALS['ph3c_cohort'] ) ) );

	$restrict = static function ( $query ) use ( $cohort ) {
		$scheduled = array_map( 'intval', (array) $query->get( 'post__in' ) );

		$query->set(
			'post__in',
			empty( $scheduled ) ? $cohort : array_values( array_intersect( $scheduled, $cohort ) )
		);
	};

	add_action( 'pre_get_posts', $restrict, 999 );
	$related = conexao_leisure_related_events( $leisure_id );
	remove_action( 'pre_get_posts', $restrict, 999 );

	return $related;
}

function leisure_test_create_leisure( $title, $county_term_id = 0, $internal = true, $extra_meta = array() ) {
	global $created_posts;
	$post_id = wp_insert_post(
		array(
			'post_type'   => 'leisure',
			'post_title'  => '[PH3C TEST] ' . $title,
			'post_status' => 'publish',
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		exit( 1 );
	}

	$meta = $extra_meta;
	if ( $internal ) {
		// Phase 3B flag: keeps the record internal even with display links set.
		$meta['_leisure_internal_page'] = '1';
	} else {
		// No flag + an official website => classifies as EXTERNAL (redirects away).
		$meta['_leisure_official_website'] = 'https://example.invalid/test';
		$meta['_leisure_town']             = 'Test Town';
	}
	if ( $county_term_id ) {
		wp_set_object_terms( $post_id, array( (int) $county_term_id ), 'conexao_county' );
	}
	foreach ( $meta as $key => $value ) {
		update_post_meta( $post_id, $key, $value );
	}
	$created_posts[] = $post_id;
	return $post_id;
}

function leisure_test_create_event( $title, $county_term_id, $meta = array() ) {
	global $created_posts;
	$post_id = wp_insert_post(
		array(
			'post_type'   => 'event',
			'post_title'  => '[PH3C TEST] ' . $title,
			'post_status' => 'publish',
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		exit( 1 );
	}
	if ( ! isset( $meta['_event_status'] ) ) {
		$meta['_event_status'] = 'published';
	}
	foreach ( $meta as $key => $value ) {
		update_post_meta( $post_id, $key, $value );
	}
	if ( $county_term_id ) {
		wp_set_object_terms( $post_id, array( (int) $county_term_id ), 'conexao_county' );
	}
	$created_posts[] = $post_id;

	// Every fixture event joins the cohort the assertions are scoped to.
	$GLOBALS['ph3c_cohort'][] = (int) $post_id;

	return $post_id;
}

/** Y-m-d N days from today (site-local). */
function ph3c_day_offset( $days ) {
	return Conexao_Event_Query::today()->modify( ( $days >= 0 ? '+' : '' ) . $days . ' day' )->format( 'Y-m-d' );
}

/** ISO weekday number (1=Mon..7=Sun) N days from today. */
function ph3c_weekday_offset( $days ) {
	return (int) Conexao_Event_Query::today()->modify( ( $days >= 0 ? '+' : '' ) . $days . ' day' )->format( 'N' );
}

echo 'Site: ' . home_url() . ' — timezone: ' . wp_timezone()->getName() . ' — today: ' . ph3c_day_offset( 0 ) . "\n";

if ( ! function_exists( 'conexao_leisure_related_events' ) ) {
	exit( 1 );
}
if ( ! class_exists( 'Conexao_Event_Query' ) ) {
	exit( 1 );
}

// Start from a clean cache window so saved test events are all visible.
Conexao_Event_Query::flush_cache();

// Counties: A has events; ZETA has none; OMEGA used for cross-county events.
$county_a     = leisure_test_county( 'laois' );
$county_zeta  = leisure_test_county( 'leitrim' );
$county_omega = leisure_test_county( 'cavan' );

// ---------------------------------------------------------------------------
// 1. Same-county upcoming events, runtime order, max 3, no duplicates
// ---------------------------------------------------------------------------
test_section( 'Same-County Upcoming Events' );

$ev_today  = leisure_test_create_event( 'Alfa hoje', $county_a, array( '_event_date' => ph3c_day_offset( 0 ) ) );
$ev_plus2  = leisure_test_create_event( 'Alfa +2', $county_a, array( '_event_date' => ph3c_day_offset( 2 ) ) );
$ev_plus5  = leisure_test_create_event( 'Alfa +5', $county_a, array( '_event_date' => ph3c_day_offset( 5 ) ) );
$ev_plus10 = leisure_test_create_event( 'Alfa +10', $county_a, array( '_event_date' => ph3c_day_offset( 10 ) ) );
// Cross-county upcoming event (must never leak into Alfa results).
$ev_omega1 = leisure_test_create_event( 'Omega +1', $county_omega, array( '_event_date' => ph3c_day_offset( 1 ) ) );
// Past event in Alfa (not in the upcoming set).
$ev_past = leisure_test_create_event( 'Alfa passado', $county_a, array( '_event_date' => ph3c_day_offset( -2 ) ) );
// Gated hidden event in Alfa (not in the upcoming set).
$ev_hidden = leisure_test_create_event( 'Alfa oculto', $county_a, array( '_event_date' => ph3c_day_offset( 3 ), '_event_status' => 'expired' ) );

$leisure_a = leisure_test_create_leisure( 'Lazer Alfa', $county_a );
$related   = leisure_test_related( $leisure_a );

$related_ids = wp_list_pluck( $related, 'ID' );

assert_true( count( $related ) === 3, 'returns at most 3 events (got ' . count( $related ) . ')' );
assert_true( in_array( $ev_today, $related_ids, true ), 'includes the same-county event dated today' );
assert_true( in_array( $ev_plus2, $related_ids, true ), 'includes the same-county event on +2' );
assert_true( in_array( $ev_plus5, $related_ids, true ), 'includes the same-county event on +5' );
assert_true( ! in_array( $ev_plus10, $related_ids, true ), 'caps at 3 (excludes the 4th same-county event)' );
assert_true( ! in_array( $ev_omega1, $related_ids, true ), 'excludes cross-county events' );
assert_true( ! in_array( $ev_past, $related_ids, true ), 'excludes past events (not in the upcoming set)' );
assert_true( ! in_array( $ev_hidden, $related_ids, true ), 'excludes non-published (_event_status) events' );
assert_true( count( $related_ids ) === count( array_unique( $related_ids ) ), 'no duplicate events' );

// Ordering: must equal the runtime's authoritative occurrence order, narrowed
// to the same-county subset — never re-sorted arbitrarily.
$runtime_order = array_values( array_intersect( conexao_event_upcoming_ids(), $related_ids ) );
assert_true( $related_ids === $runtime_order, 'order preserves the event runtime occurrence ordering' );
// ---------------------------------------------------------------------------
// 2. Recurring events (existing next-occurrence logic)
// ---------------------------------------------------------------------------
test_section( 'Recurring Events' );

// A weekly series in Alfa with its next occurrence on the weekday 4 days out
// will appear AND be capped/uniqued exactly once per post.
$weekly_recur = leisure_test_create_event( 'Alfa semanal', $county_a, array(
	'_event_date'             => ph3c_day_offset( 0 ),
	'_event_recurrence'       => 'weekly',
	'_event_recurrence_days'  => (string) ph3c_weekday_offset( 4 ),
	'_event_recurrence_start' => ph3c_day_offset( 0 ),
	'_event_recurrence_end'   => ph3c_day_offset( 60 ),
) );

$related_recur = leisure_test_related( $leisure_a );
$recur_ids     = wp_list_pluck( $related_recur, 'ID' );
$count_recur   = count( array_keys( $recur_ids, $weekly_recur, true ) );

assert_true( in_array( $weekly_recur, $recur_ids, true ), 'includes the recurring event (next-occurrence logic)' );
assert_true( count( $recur_ids ) === count( array_unique( $recur_ids ) ), 'recurring event set stays duplicate-free' );

// The card display date for the recurring event must be its next occurrence
// (same runtime helper the event-card component uses).
if ( function_exists( 'conexao_event_display_date' ) ) {
	$expected_next = conexao_event_display_date( $weekly_recur );
	assert_true( $expected_next === ph3c_day_offset( 4 ), 'recurring event next occurrence resolves to +4' );
	assert_true( '' !== conexao_event_recurrence_label( $weekly_recur ), 'recurring event has a recurrence label for the card' );
}

// ---------------------------------------------------------------------------
// 3. Multi-day event active today
// ---------------------------------------------------------------------------
test_section( 'Multi-Day Events' );

// Started yesterday, ends tomorrow — active today (active-date logic).
$multiday = leisure_test_create_event( 'Alfa multi-dia', $county_a, array(
	'_event_date'     => ph3c_day_offset( -1 ),
	'_event_end_date' => ph3c_day_offset( 1 ),
) );

$related_multi = leisure_test_related( $leisure_a );
$multi_ids     = wp_list_pluck( $related_multi, 'ID' );

assert_true( in_array( $multiday, $multi_ids, true ), 'includes the multi-day event active today' );

// ---------------------------------------------------------------------------
// 4. No county / county without events => nothing rendered
// ---------------------------------------------------------------------------
test_section( 'No-Events States' );

$leisure_no_county = leisure_test_create_leisure( 'Lazer sem condado', 0 );
assert_true( array() === leisure_test_related( $leisure_no_county ), 'destination without a county returns empty' );

$leisure_zeta = leisure_test_create_leisure( 'Lazer Zeta', $county_zeta );
assert_true( array() === leisure_test_related( $leisure_zeta ), 'county with no upcoming events returns empty' );

// ---------------------------------------------------------------------------
// 5. External destinations never surface events
// ---------------------------------------------------------------------------
test_section( 'External Destinations' );

$leisure_external = leisure_test_create_leisure( 'Lazer externo', $county_a, false );
assert_true( array() === leisure_test_related( $leisure_external ), 'external (redirecting) record returns empty' );
if ( function_exists( 'conexao_leisure_external_url' ) ) {
	assert_true( (bool) conexao_leisure_external_url( $leisure_external ), 'test record classifies as external (setup sanity)' );
}

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------
foreach ( $created_posts as $post_id ) {
	wp_delete_post( $post_id, true );
}
foreach ( $created_terms as $term_id ) {
	wp_delete_term( $term_id, 'conexao_county' );
}
Conexao_Event_Query::flush_cache();

test_finish();
