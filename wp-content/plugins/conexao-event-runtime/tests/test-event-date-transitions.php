<?php
/**
 * Regression test — event date transitions, cache rollover and timezone
 * boundaries (Stage 23).
 *
 * WHY THIS SUITE EXISTS
 * ---------------------
 * `/eventos/` lists events through `Conexao_Event_Query::upcoming_events()`: one
 * SQL candidate query, exact PHP evaluation in the site timezone, and a
 * transient cache keyed by the site-local calendar date. This suite pins the
 * whole contract down with a CONTROLLED clock (`upcoming_events( $from )` /
 * `upcoming_event_ids( $from )`) so no assertion depends on a real-world event
 * date or on sleeping until midnight:
 *
 *   T1  an event whose start and end dates are today is today's event and is
 *       gone the next day, WITHOUT the record being modified;
 *   T2  an event that ended yesterday is excluded today, even when a
 *       previously populated cache entry exists;
 *   T3  tomorrow's event is classified as tomorrow today and as today tomorrow;
 *   T4  an ongoing multi-day event stays visible on every day it runs (and
 *       still disappears the day after its end date);
 *   T5  the cache is keyed by the site-local date, its lifetime ends at local
 *       midnight, a previous day's entry can never be served to a later day,
 *       and saving/deleting an event refreshes the list;
 *   T6  the cached and cache-bypassed paths agree for the same fixtures;
 *   T7  date classification uses the site timezone, never UTC (including a
 *       boundary case that differs between the two, and the DST change);
 *   T8  existing behaviour is intact: upcoming events stay visible, ordering
 *       and uniqueness hold, the taxonomy filter and pagination still narrow
 *       the same ID list, and weekly series keep working.
 *
 * The suite creates its own temporary events (and one taxonomy term) and
 * deletes them again; it writes no other data.
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

test_title( 'Event date transitions, cache rollover and timezone boundaries (Stage 23)' );

test_require(
	class_exists( 'Conexao_Event_Query' ) && class_exists( 'Conexao_Event_Recurrence' ),
	'activate-conexao-event-runtime',
	'the event runtime query/evaluator classes are loaded',
	'docker compose exec wordpress wp --allow-root plugin activate conexao-data-model conexao-event-runtime'
);

/** Fixture post IDs created by this suite, deleted at the end. */
$dt_created = array();

/** Taxonomy term IDs created by this suite, deleted at the end. */
$dt_terms = array();

/**
 * Site-local Y-m-d N days from today.
 *
 * @param int $offset Signed day offset.
 * @return string
 */
function dt_day( $offset ) {
	return Conexao_Event_Query::today()->modify( ( $offset >= 0 ? '+' : '' ) . $offset . ' day' )->format( 'Y-m-d' );
}

/**
 * ISO weekday number (1 = Mon … 7 = Sun) N days from today.
 *
 * @param int $offset Signed day offset.
 * @return int
 */
function dt_weekday( $offset ) {
	return (int) Conexao_Event_Query::today()->modify( ( $offset >= 0 ? '+' : '' ) . $offset . ' day' )->format( 'N' );
}

/**
 * Create a temporary published event record.
 *
 * @param string $title Fixture title.
 * @param array  $meta  Meta key => value.
 * @return int Post ID.
 */
function dt_create_event( $title, array $meta ) {
	global $dt_created;

	$post_id = wp_insert_post(
		array(
			'post_type'   => 'event',
			'post_title'  => '[STAGE23 DATE] ' . $title,
			'post_status' => 'publish',
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		return 0;
	}

	foreach ( $meta as $key => $value ) {
		update_post_meta( $post_id, $key, $value );
	}

	$dt_created[] = (int) $post_id;

	return (int) $post_id;
}

/**
 * Ordered ID list for a simulated "today" (uncached evaluation).
 *
 * @param int $offset Day offset used as today.
 * @return int[]
 */
function dt_ids( $offset ) {
	return Conexao_Event_Query::upcoming_event_ids(
		new DateTimeImmutable( dt_day( $offset ), wp_timezone() )
	);
}

/**
 * ID => next occurrence map for a simulated "today" (uncached evaluation).
 *
 * @param int $offset Day offset used as today.
 * @return array<int,string>
 */
function dt_map( $offset ) {
	return Conexao_Event_Query::upcoming_events(
		new DateTimeImmutable( dt_day( $offset ), wp_timezone() )
	);
}

/**
 * Whether an event is classified as occurring on a simulated day.
 *
 * @param int $event_id Event post ID.
 * @param int $offset   Day offset used as "today".
 * @return bool
 */
function dt_is_upcoming_on( $event_id, $offset ) {
	return in_array( (int) $event_id, dt_ids( $offset ), true );
}

/**
 * The transient key the runtime uses for a local date and language.
 *
 * @param string $ymd      Local date (Ymd).
 * @param string $language Language slug.
 * @return string
 */
function dt_cache_key( $ymd, $language ) {
	return 'conexao_event_upcoming_' . $ymd . ( '' !== $language ? '_' . $language : '' );
}

/**
 * Current language slug used in cache keys ('' when Polylang is inactive).
 *
 * @return string
 */
function dt_language_slug() {
	return function_exists( 'pll_current_language' ) ? (string) pll_current_language( 'slug' ) : '';
}
echo 'Site timezone: ' . wp_timezone()->getName() . ' — today: ' . dt_day( 0 ) . ' (' . dt_day( -1 ) . ' … ' . dt_day( 2 ) . ")\n";

Conexao_Event_Query::flush_cache();

// ---------------------------------------------------------------------------
// T1 — an event whose start and end dates are today
// ---------------------------------------------------------------------------
test_section( 'T1: today → yesterday without touching the record' );

$dt_today = dt_create_event(
	'starts and ends today',
	array(
		'_event_date'     => dt_day( 0 ),
		'_event_end_date' => dt_day( 0 ),
	)
);

assert_true( dt_is_upcoming_on( $dt_today, 0 ), 'T1: the event is in today\'s results' );
assert_true( dt_is_upcoming_on( $dt_today, -1 ), 'T1: the event was already upcoming the day before (archive semantics)' );
assert_true( ! dt_is_upcoming_on( $dt_today, 1 ), 'T1: the SAME unmodified record is gone the next day' );
assert_true( ! dt_is_upcoming_on( $dt_today, 2 ), 'T1: and stays gone' );
assert_true( Conexao_Event_Recurrence::occurs_on_date( $dt_today, new DateTimeImmutable( dt_day( 0 ), wp_timezone() ) ), 'T1: the card is classified as "today" today' );
assert_true( ! Conexao_Event_Recurrence::occurs_on_date( $dt_today, new DateTimeImmutable( dt_day( 1 ), wp_timezone() ) ), 'T1: and NOT as "today" the next day' );

// ---------------------------------------------------------------------------
// T2 — an event that ended yesterday, with a cache entry in play
// ---------------------------------------------------------------------------
test_section( 'T2: expired events are excluded, even with a cache entry' );

$dt_expired = dt_create_event(
	'ended yesterday',
	array(
		'_event_date'     => dt_day( -3 ),
		'_event_end_date' => dt_day( -1 ),
	)
);

assert_true( ! dt_is_upcoming_on( $dt_expired, 0 ), 'T2: the event is not in today\'s results' );
assert_true( ! dt_is_upcoming_on( $dt_expired, 1 ), 'T2: it is not in tomorrow\'s results either' );
assert_true( dt_is_upcoming_on( $dt_expired, -1 ), 'T2: it WAS in the results on its own last day (the boundary is the day, not the clock)' );
assert_true( ! Conexao_Event_Recurrence::occurs_on_date( $dt_expired, new DateTimeImmutable( dt_day( 0 ), wp_timezone() ) ), 'T2: it is not classified as "today"' );

$dt_today_key = dt_cache_key( Conexao_Event_Query::today()->format( 'Ymd' ), dt_language_slug() );
$dt_prev_key  = dt_cache_key( Conexao_Event_Query::today()->modify( '-1 day' )->format( 'Ymd' ), dt_language_slug() );

Conexao_Event_Query::flush_cache();
set_transient( $dt_prev_key, array( (int) $dt_expired => dt_day( -1 ) ), DAY_IN_SECONDS );
delete_transient( $dt_today_key );

$dt_cached_after_stale = Conexao_Event_Query::upcoming_event_ids();

assert_true( ! in_array( (int) $dt_expired, $dt_cached_after_stale, true ), 'T2/T5: a previous day\'s cache entry is never served to today' );
assert_true( in_array( (int) $dt_today, $dt_cached_after_stale, true ), 'T2: today\'s cached read is rebuilt from the live records' );
delete_transient( $dt_prev_key );

// ---------------------------------------------------------------------------
// T3 — tomorrow becomes today
// ---------------------------------------------------------------------------
test_section( 'T3: tomorrow becomes today' );

$dt_tomorrow = dt_create_event( 'tomorrow only', array( '_event_date' => dt_day( 1 ) ) );

$dt_map_today    = dt_map( 0 );
$dt_map_tomorrow = dt_map( 1 );

assert_equals( dt_day( 1 ), isset( $dt_map_today[ $dt_tomorrow ] ) ? $dt_map_today[ $dt_tomorrow ] : null, 'T3: today the event is classified as tomorrow\'s event' );
assert_equals( dt_day( 1 ), isset( $dt_map_tomorrow[ $dt_tomorrow ] ) ? $dt_map_tomorrow[ $dt_tomorrow ] : null, 'T3: the next day the same record is classified as TODAY\'s event' );
assert_true( ! isset( dt_map( 2 )[ $dt_tomorrow ] ), 'T3: two days later it is no longer an upcoming event' );

// ---------------------------------------------------------------------------
// T4 — an ongoing multi-day event must stay visible
// ---------------------------------------------------------------------------
test_section( 'T4: ongoing multi-day events remain visible' );

$dt_ongoing = dt_create_event(
	'started yesterday, ends tomorrow',
	array(
		'_event_date'     => dt_day( -1 ),
		'_event_end_date' => dt_day( 1 ),
	)
);

assert_true( dt_is_upcoming_on( $dt_ongoing, -1 ), 'T4: visible on its first day' );
assert_true( dt_is_upcoming_on( $dt_ongoing, 0 ), 'T4: visible today (it is still running)' );
assert_true( dt_is_upcoming_on( $dt_ongoing, 1 ), 'T4: visible on its last day' );
assert_true( ! dt_is_upcoming_on( $dt_ongoing, 2 ), 'T4: gone the day after its end date' );
assert_equals( dt_day( 0 ), isset( dt_map( 0 )[ $dt_ongoing ] ) ? dt_map( 0 )[ $dt_ongoing ] : null, 'T4: an in-progress event sorts among today\'s events' );
assert_true( Conexao_Event_Recurrence::occurs_on_date( $dt_ongoing, new DateTimeImmutable( dt_day( 0 ), wp_timezone() ) ), 'T4: and carries the "today" classification while it runs' );


// ---------------------------------------------------------------------------
// T5 — cache key, midnight lifetime and invalidation
// ---------------------------------------------------------------------------
test_section( 'T5: date-keyed cache, midnight lifetime, invalidation' );

Conexao_Event_Query::flush_cache();

$dt_cached_ids   = Conexao_Event_Query::upcoming_event_ids();
$dt_tomorrow_key = dt_cache_key( Conexao_Event_Query::today()->modify( '+1 day' )->format( 'Ymd' ), dt_language_slug() );

assert_true( in_array( $dt_today, $dt_cached_ids, true ), 'T5: the cached list contains today\'s event' );
assert_true( is_array( get_transient( $dt_today_key ) ), 'T5: the ordered list is cached under the site-local date key' );
assert_true( 0 === strpos( $dt_today_key, 'conexao_event_upcoming_' . Conexao_Event_Query::today()->format( 'Ymd' ) ), 'T5: the key carries the site-local calendar date, so a later day can never read it' );
assert_true( $dt_tomorrow_key !== $dt_today_key, 'T5: tomorrow\'s key differs from today\'s — the day rollover rebuilds the set' );

$dt_timeout             = (int) get_option( '_transient_timeout_' . $dt_today_key, 0 );
$dt_seconds_to_midnight = Conexao_Event_Query::today()->modify( '+1 day' )->getTimestamp() - time();

assert_true( $dt_timeout > time(), 'T5: the cache entry is still valid when it is written' );
assert_true( $dt_timeout - time() <= DAY_IN_SECONDS, 'T5: the lifetime never exceeds one day' );
assert_true( abs( ( $dt_timeout - time() ) - $dt_seconds_to_midnight ) <= 5, 'T5: the lifetime ends at the next local midnight' );

$dt_probe = dt_create_event( 'cache invalidation probe', array( '_event_date' => dt_day( 0 ) ) );
assert_true( in_array( $dt_probe, Conexao_Event_Query::upcoming_event_ids(), true ), 'T5: saving an event refreshes the list immediately' );

wp_delete_post( $dt_probe, true );
assert_true( ! in_array( $dt_probe, Conexao_Event_Query::upcoming_event_ids(), true ), 'T5: deleting an event removes it from the list immediately' );

// ---------------------------------------------------------------------------
// T6 — cached and cache-bypassed paths agree
// ---------------------------------------------------------------------------
test_section( 'T6: cached vs cache-bypassed comparison' );

Conexao_Event_Query::flush_cache();
$dt_from_cache = Conexao_Event_Query::upcoming_event_ids();
$dt_from_live  = Conexao_Event_Query::upcoming_event_ids( Conexao_Event_Query::today() );

assert_true( $dt_from_cache === $dt_from_live, 'T6: the cached ID list equals the cache-bypassed evaluation for the same day' );
assert_true( Conexao_Event_Query::upcoming_events() === Conexao_Event_Query::upcoming_events( Conexao_Event_Query::today() ), 'T6: the same holds for the ID => next-occurrence map' );

// ---------------------------------------------------------------------------
// T7 — the site timezone, a UTC boundary and the DST change
// ---------------------------------------------------------------------------
test_section( 'T7: timezone boundaries' );

assert_true( '00:00:00' === Conexao_Event_Query::today()->format( 'H:i:s' ), 'T7: "today" is local midnight' );
assert_true( wp_timezone()->getName() === Conexao_Event_Query::today()->getTimezone()->getName(), 'T7: "today" is expressed in the WordPress site timezone' );

/*
 * The date layer must read date-only values as SITE-LOCAL calendar dates, never
 * as UTC timestamps. Europe/Dublin is the documented site configuration (CI
 * sets it and bootstrap-ci-fixtures.php asserts it), so this block pins it
 * explicitly — which makes the UTC-versus-local boundary testable in ANY
 * environment — and restores the inherited value immediately afterwards (also
 * from a shutdown handler, so an early exit cannot leave the site changed).
 */
$dt_previous_timezone = (string) get_option( 'timezone_string' );
$dt_previous_offset   = get_option( 'gmt_offset' );

register_shutdown_function(
	function () use ( $dt_previous_timezone, $dt_previous_offset ) {
		update_option( 'timezone_string', $dt_previous_timezone );
		update_option( 'gmt_offset', $dt_previous_offset );
	}
);

update_option( 'timezone_string', 'Europe/Dublin' );
update_option( 'gmt_offset', '' );

assert_true( 'Europe/Dublin' === wp_timezone()->getName(), 'T7: the documented site timezone is in force for this block' );

// Europe/Dublin is UTC+1 in summer, so 2026-06-30T23:30Z is already 2026-07-01 locally.
$dt_summer = dt_create_event( 'summer UTC boundary', array( '_event_date' => '2026-07-01' ) );
$dt_mixed  = ( new DateTimeImmutable( '2026-07-01 00:30:00', wp_timezone() ) )->setTimezone( new DateTimeZone( 'UTC' ) );

assert_true( '2026-06-30' === $dt_mixed->format( 'Y-m-d' ), 'T7: the boundary instant really is a different UTC calendar date', 'utc=' . $dt_mixed->format( 'c' ) );
assert_true( Conexao_Event_Recurrence::occurs_on_date( $dt_summer, $dt_mixed ), 'T7: a UTC instant whose LOCAL date is 2026-07-01 is that event\'s day' );
assert_true( ! Conexao_Event_Recurrence::occurs_on_date( $dt_summer, new DateTimeImmutable( '2026-06-30 12:00:00', new DateTimeZone( 'UTC' ) ) ), 'T7: a UTC instant whose local date is 2026-06-30 is NOT (UTC and local dates are never mixed)' );

// A weekly series is matched on the LOCAL weekday, not on the UTC one.
$dt_summer_weekly = dt_create_event(
	'summer UTC weekly',
	array(
		'_event_date'             => '2026-07-01',
		'_event_recurrence'       => 'weekly',
		'_event_recurrence_days'  => '3',
		'_event_recurrence_start' => '2026-07-01',
	)
);
assert_true( Conexao_Event_Recurrence::occurs_on_date( $dt_summer_weekly, $dt_mixed ), 'T7: the weekly weekday match uses the local date (Wednesday, not the UTC Tuesday)' );
assert_true( ! Conexao_Event_Recurrence::occurs_on_date( $dt_summer_weekly, new DateTimeImmutable( '2026-06-30 12:00:00', new DateTimeZone( 'UTC' ) ) ), 'T7: and does not fire on the UTC weekday' );

// The local calendar day must survive the DST change (2026-10-25, 02:00 -> 01:00).
$dt_dst = dt_create_event( 'DST boundary', array( '_event_date' => '2026-10-25' ) );
assert_true( Conexao_Event_Recurrence::occurs_on_date( $dt_dst, new DateTimeImmutable( '2026-10-25 00:30:00', wp_timezone() ) ), 'T7: a date-only event matches its own local day across the DST change' );
assert_true( ! Conexao_Event_Recurrence::occurs_on_date( $dt_dst, new DateTimeImmutable( '2026-10-24 23:30:00', wp_timezone() ) ), 'T7: and not the previous local day' );
assert_true( '2026-10-25' === ( new DateTimeImmutable( '2026-10-24', wp_timezone() ) )->modify( '+1 day' )->format( 'Y-m-d' ), 'T7: the local calendar day after 24 Oct 2026 is 25 Oct' );

update_option( 'timezone_string', $dt_previous_timezone );
update_option( 'gmt_offset', $dt_previous_offset );
assert_true( $dt_previous_timezone === (string) get_option( 'timezone_string' ), 'T7: the site timezone was restored' );

// ---------------------------------------------------------------------------
// T8 — existing behaviour is intact
// ---------------------------------------------------------------------------
test_section( 'T8: existing behaviour intact' );

$dt_future = dt_create_event( 'future', array( '_event_date' => dt_day( 10 ) ) );
assert_true( dt_is_upcoming_on( $dt_future, 0 ), 'T8: a future event is still visible (the archive is an upcoming list)' );

$dt_weekly = dt_create_event(
	'weekly series',
	array(
		'_event_date'             => dt_day( 0 ),
		'_event_recurrence'       => 'weekly',
		'_event_recurrence_days'  => (string) dt_weekday( 0 ),
		'_event_recurrence_start' => dt_day( 0 ),
	)
);
$dt_map_now = dt_map( 0 );
assert_true( isset( $dt_map_now[ $dt_weekly ] ) && dt_day( 0 ) === $dt_map_now[ $dt_weekly ], 'T8: an active weekly series is still listed with its next occurrence' );

$dt_values = array_values( dt_map( 0 ) );
$dt_sorted = $dt_values;
sort( $dt_sorted );
assert_true( $dt_values === $dt_sorted, 'T8: the list is still ordered by next occurrence ascending' );

$dt_ids_now = dt_ids( 0 );
assert_true( count( $dt_ids_now ) === count( array_unique( $dt_ids_now ) ), 'T8: each event still appears exactly once' );

// The archive's own shape: a curated ID list fed to WP_Query via post__in.
$dt_term    = wp_insert_term( '[STAGE23 DATE] category', 'conexao_category' );
$dt_term_id = is_wp_error( $dt_term ) ? 0 : (int) $dt_term['term_id'];
if ( $dt_term_id > 0 ) {
	$dt_terms[] = $dt_term_id;
	wp_set_object_terms( $dt_today, array( $dt_term_id ), 'conexao_category' );
}
assert_true( $dt_term_id > 0, 'T8: the fixture category term was created' );

$dt_filtered = new WP_Query(
	array(
		'post_type'              => 'event',
		'post_status'            => 'publish',
		'post__in'               => $dt_ids_now,
		'orderby'                => 'post__in',
		'order'                  => 'ASC',
		'posts_per_page'         => -1,
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
		'tax_query'              => array(
			array(
				'taxonomy' => 'conexao_category',
				'field'    => 'term_id',
				'terms'    => $dt_term_id,
			),
		),
	)
);
assert_true( wp_list_pluck( $dt_filtered->posts, 'ID' ) === array( $dt_today ), 'T8: the taxonomy filter still narrows the curated ID list to the matching event' );

if ( count( $dt_ids_now ) >= 2 ) {
	$dt_page_two = new WP_Query(
		array(
			'post_type'      => 'event',
			'post_status'    => 'publish',
			'post__in'       => $dt_ids_now,
			'orderby'        => 'post__in',
			'order'          => 'ASC',
			'posts_per_page' => 1,
			'paged'          => 2,
		)
	);
	assert_true( wp_list_pluck( $dt_page_two->posts, 'ID' ) === array( (int) $dt_ids_now[1] ), 'T8: pagination over the curated ID list still returns the same order (page 2 = the second event)' );
} else {
	assert_fail( 'T8: at least two upcoming events are needed to assert pagination order' );
}

// ---------------------------------------------------------------------------
// Cleanup — the suite removes exactly what it created.
// ---------------------------------------------------------------------------
test_section( 'Cleanup' );

foreach ( $dt_created as $dt_post_id ) {
	if ( get_post( $dt_post_id ) ) {
		wp_delete_post( (int) $dt_post_id, true );
	}
}
foreach ( $dt_terms as $dt_term_id ) {
	wp_delete_term( (int) $dt_term_id, 'conexao_category' );
}
Conexao_Event_Query::flush_cache();

$dt_leftovers = 0;
foreach ( $dt_created as $dt_post_id ) {
	if ( get_post( $dt_post_id ) ) {
		$dt_leftovers++;
	}
}
assert_equals( 0, $dt_leftovers, 'every fixture event created by this suite is gone after cleanup' );
assert_true( count( $dt_created ) >= 10, 'the suite created its full fixture set', 'created=' . count( $dt_created ) );


test_finish( 'event date transitions' );

