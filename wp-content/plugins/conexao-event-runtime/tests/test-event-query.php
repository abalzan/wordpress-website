<?php
/**
 * Tests for the public event query/candidate helper (Step 3).
 *
 * Verifies Conexao_Event_Query against the Step 3 design:
 *
 *  - One-time events keep the legacy behavior: dated today or later are in
 *    the ordered list (their next occurrence is the stored _event_date);
 *    past one-time events are not.
 *  - Weekly recurrence: active series enter the list with an occurrence in
 *    the local [today, today+7] window; series not yet started (beyond the
 *    window) and ended series do not; open-ended series do; weekday
 *    selection is evaluated exactly in PHP, not in SQL.
 *  - The public _event_status gate is preserved (expired events hidden).
 *  - The list is sorted by next occurrence and contains each event once
 *    (no per-occurrence duplicates — no occurrence posts are created).
 *  - The ordered list feeds a WP_Query via post__in + orderby => post__in
 *    (the pattern used by the archive and the secondary surfaces).
 *
 * The script creates temporary event posts and deletes them at the end;
 * it writes no other data and runs in the current plugin configuration.
 *
 * Usage (from the project root):
 *   docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-runtime/tests/test-event-query.php
 */

$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

$passed        = 0;
$failed        = 0;
$test_post_ids = array();

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

function create_test_event( $title, $meta = array() ) {
	global $test_post_ids;
	$post_id = wp_insert_post(
		array(
			'post_type'   => 'event',
			'post_title'  => '[RECQ TEST] ' . $title,
			'post_status' => 'publish',
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		echo "  FATAL: could not create test event: {$post_id->get_error_message()}\n";
		exit( 1 );
	}
	foreach ( $meta as $key => $value ) {
		update_post_meta( $post_id, $key, $value );
	}
	$test_post_ids[] = $post_id;
	return $post_id;
}

/** Y-m-d N days from today (site-local). */
function query_day_offset( $days ) {
	return Conexao_Event_Query::today()->modify( ( $days >= 0 ? '+' : '' ) . $days . ' day' )->format( 'Y-m-d' );
}

/** ISO weekday number (1=Mon..7=Sun) N days from today. */
function query_weekday_offset( $days ) {
	return (int) Conexao_Event_Query::today()->modify( ( $days >= 0 ? '+' : '' ) . $days . ' day' )->format( 'N' );
}

echo "Running event query helper tests...\n";
echo 'Site: ' . home_url() . ' — timezone: ' . wp_timezone()->getName() . ' — today: ' . query_day_offset( 0 ) . "\n";

// Start from a clean cache window so saved test events are all visible.
Conexao_Event_Query::flush_cache();

// ---------------------------------------------------------------------------
// 1. One-time events (legacy behavior preserved)
// ---------------------------------------------------------------------------
test_section( 'One-Time Events' );

$past_one_time       = create_test_event( 'One-time past', array( '_event_date' => query_day_offset( -3 ) ) );
$today_one_time      = create_test_event( 'One-time today', array( '_event_date' => query_day_offset( 0 ) ) );
$future_one_time     = create_test_event( 'One-time future', array( '_event_date' => query_day_offset( 10 ) ) );
$plain_one_time      = create_test_event( 'One-time no extra meta' ); // No recurrence meta at all.
update_post_meta( $plain_one_time, '_event_date', query_day_offset( 5 ) );
$far_future_one_time = create_test_event( 'One-time far future', array( '_event_date' => query_day_offset( 200 ) ) );

$ids = Conexao_Event_Query::upcoming_event_ids();

test_assert( in_array( $today_one_time, $ids, true ), 'one-time today is in the list' );
test_assert( in_array( $future_one_time, $ids, true ), 'one-time future is in the list' );
test_assert( in_array( $plain_one_time, $ids, true ), 'event with no recurrence metadata is in the list' );
test_assert( in_array( $far_future_one_time, $ids, true ), 'one-time beyond the 7-day window is still in the list (archive semantics)' );
test_assert( ! in_array( $past_one_time, $ids, true ), 'one-time past is NOT in the list' );

test_assert( Conexao_Event_Query::next_occurrence_date( $today_one_time ) === query_day_offset( 0 ), 'one-time today: next occurrence = stored date' );
test_assert( Conexao_Event_Query::next_occurrence_date( $future_one_time ) === query_day_offset( 10 ), 'one-time future: next occurrence = stored date' );
test_assert( null === Conexao_Event_Query::next_occurrence_date( $past_one_time ), 'one-time past: no next occurrence' );

// ---------------------------------------------------------------------------
// 2. Weekly recurrence in the query list
// ---------------------------------------------------------------------------
test_section( 'Weekly Recurrence' );

// 3. Weekly series active today (selected weekday = today's weekday).
$weekly_active = create_test_event( 'Weekly active today', array(
	'_event_date'             => query_day_offset( 0 ),
	'_event_recurrence'       => 'weekly',
	'_event_recurrence_days'  => (string) query_weekday_offset( 0 ),
	'_event_recurrence_start' => query_day_offset( 0 ),
	'_event_recurrence_end'   => query_day_offset( 30 ),
) );

// 4. Weekly series whose range has not started (start beyond the window).
$weekly_not_started = create_test_event( 'Weekly not started', array(
	'_event_date'             => query_day_offset( 30 ),
	'_event_recurrence'       => 'weekly',
	'_event_recurrence_days'  => (string) query_weekday_offset( 30 ),
	'_event_recurrence_start' => query_day_offset( 30 ),
	'_event_recurrence_end'   => query_day_offset( 90 ),
) );

// 5. Weekly series whose range has ended.
$weekly_ended = create_test_event( 'Weekly ended', array(
	'_event_date'             => query_day_offset( -30 ),
	'_event_recurrence'       => 'weekly',
	'_event_recurrence_days'  => (string) query_weekday_offset( 0 ),
	'_event_recurrence_start' => query_day_offset( -30 ),
	'_event_recurrence_end'   => query_day_offset( -1 ),
) );

// 6. Twice-weekly series (today + today+2).
$weekly_twice = create_test_event( 'Weekly twice a week', array(
	'_event_date'             => query_day_offset( 0 ),
	'_event_recurrence'       => 'weekly',
	'_event_recurrence_days'  => query_weekday_offset( 0 ) . ',' . query_weekday_offset( 2 ),
	'_event_recurrence_start' => query_day_offset( 0 ),
) );

// 7. Open-ended weekly series started long ago.
$weekly_open = create_test_event( 'Weekly open-ended', array(
	'_event_date'             => query_day_offset( -60 ),
	'_event_recurrence'       => 'weekly',
	'_event_recurrence_days'  => (string) query_weekday_offset( 0 ),
	'_event_recurrence_start' => query_day_offset( -60 ),
) );

// 8. Weekly series whose selected weekday does not match its start date.
$weekly_mismatch = create_test_event( 'Weekly weekday mismatch', array(
	'_event_date'             => query_day_offset( -1 ),
	'_event_recurrence'       => 'weekly',
	'_event_recurrence_days'  => (string) query_weekday_offset( 0 ),
	'_event_recurrence_start' => query_day_offset( -1 ),
) );

$ids = Conexao_Event_Query::upcoming_event_ids();

test_assert( in_array( $weekly_active, $ids, true ), 'weekly active today is in the list' );
test_assert( ! in_array( $weekly_not_started, $ids, true ), 'weekly not started (beyond window) is NOT in the list' );
test_assert( ! in_array( $weekly_ended, $ids, true ), 'weekly ended is NOT in the list' );
test_assert( in_array( $weekly_twice, $ids, true ), 'twice-weekly active series is in the list' );
test_assert( in_array( $weekly_open, $ids, true ), 'open-ended weekly series is in the list' );
test_assert( in_array( $weekly_mismatch, $ids, true ), 'weekly series with start/weekday mismatch is in the list' );
test_assert( count( array_keys( $ids, $weekly_active, true ) ) === 1, 'weekly event appears exactly once (no per-occurrence results)' );

test_assert( Conexao_Event_Query::next_occurrence_date( $weekly_active ) === query_day_offset( 0 ), 'weekly active today: next occurrence is today' );
test_assert( Conexao_Event_Query::next_occurrence_date( $weekly_twice ) === query_day_offset( 0 ), 'twice-weekly: next occurrence is today' );
test_assert( Conexao_Event_Query::next_occurrence_date( $weekly_mismatch ) === query_day_offset( 0 ), 'weekday mismatch: weekday drives the schedule, not the start date' );
test_assert( null === Conexao_Event_Query::next_occurrence_date( $weekly_ended ), 'weekly ended: no next occurrence' );

// ---------------------------------------------------------------------------
// 3. Status gate (published / legacy no-status)
// ---------------------------------------------------------------------------
test_section( 'Status Gate' );

$expired_weekly = create_test_event( 'Weekly expired', array(
	'_event_date'             => query_day_offset( -60 ),
	'_event_recurrence'       => 'weekly',
	'_event_recurrence_days'  => (string) query_weekday_offset( 0 ),
	'_event_recurrence_start' => query_day_offset( -60 ),
	'_event_status'           => 'expired',
) );

$draft_weekly = create_test_event( 'Weekly draft status', array(
	'_event_date'             => query_day_offset( 0 ),
	'_event_recurrence'       => 'weekly',
	'_event_recurrence_days'  => (string) query_weekday_offset( 0 ),
	'_event_recurrence_start' => query_day_offset( 0 ),
	'_event_status'           => 'draft',
) );

$ids = Conexao_Event_Query::upcoming_event_ids();

test_assert( ! in_array( $expired_weekly, $ids, true ), 'expired recurring event is NOT in the list' );
test_assert( ! in_array( $draft_weekly, $ids, true ), 'draft recurring event is NOT in the list' );

// ---------------------------------------------------------------------------
// 4. Sorting: next occurrence ascending
// ---------------------------------------------------------------------------
test_section( 'Sorting' );

$events = Conexao_Event_Query::upcoming_events();
$dates  = array_values( $events );
$sorted = $dates;
sort( $sorted );
test_assert( $dates === $sorted, 'list is sorted by next occurrence ascending' );
test_assert( count( $events ) === count( array_unique( array_keys( $events ) ) ), 'list keys are unique event IDs' );
test_assert( $events[ $today_one_time ] === query_day_offset( 0 ), 'today occurrences sort first' );
test_assert( reset( $dates ) === query_day_offset( 0 ), 'first entry is today' );

// ---------------------------------------------------------------------------
// 5. post__in + orderby post__in integration (archive pattern)
// ---------------------------------------------------------------------------
test_section( 'WP_Query Integration' );

$ordered_ids = array_keys( $events );
$q           = new WP_Query( array(
	'post_type'      => 'event',
	'post_status'    => 'publish',
	'post__in'       => $ordered_ids,
	'orderby'        => 'post__in',
	'order'          => 'ASC',
	'posts_per_page' => count( $ordered_ids ),
	'no_found_rows'  => true,
) );
$q_ids = wp_list_pluck( $q->posts, 'ID' );
test_assert( $q_ids === array_map( 'intval', $ordered_ids ), 'WP_Query with post__in + orderby post__in preserves the ordered list' );

// Taxonomy filters keep narrowing the same ID list (archive ?categoria pattern).
$term_result = wp_insert_term( 'RECQ Test Category', 'conexao_category' );
$term_id     = is_wp_error( $term_result ) ? null : $term_result['term_id'];
if ( $term_id ) {
	wp_set_object_terms( $today_one_time, array( $term_id ), 'conexao_category' );
	$filtered = new WP_Query( array(
		'post_type'      => 'event',
		'post_status'    => 'publish',
		'post__in'       => $ordered_ids,
		'orderby'        => 'post__in',
		'order'          => 'ASC',
		'tax_query'      => array(
			array(
				'taxonomy' => 'conexao_category',
				'field'    => 'term_id',
				'terms'    => $term_id,
			),
		),
		'no_found_rows'  => true,
	) );
	test_assert( wp_list_pluck( $filtered->posts, 'ID' ) === array( $today_one_time ), 'tax_query narrows the post__in list (filters keep working)' );
	wp_delete_term( $term_id, 'conexao_category' );
} else {
	test_assert( false, 'could not create test category term' );
}

// Empty list stays deterministic ("no upcoming events", not "all events").
$empty_q = new WP_Query( array(
	'post_type'     => 'event',
	'post_status'   => 'publish',
	'post__in'      => array( 0 ),
	'orderby'       => 'post__in',
	'no_found_rows' => true,
) );
test_assert( 0 === $empty_q->post_count, 'empty ID list yields zero results (no filter leak)' );

// ---------------------------------------------------------------------------
// 6. Cache behavior (date-keyed transient)
// ---------------------------------------------------------------------------
test_section( 'Cache' );

$today_key = 'conexao_event_upcoming_' . Conexao_Event_Query::today()->format( 'Ymd' );
$cached    = get_transient( $today_key );
test_assert( is_array( $cached ) && array_keys( $cached ) === $ordered_ids, 'date-keyed transient stores the ordered ID map' );
test_assert( Conexao_Event_Query::upcoming_event_ids() === array_map( 'intval', $ordered_ids ), 'cached read matches fresh evaluation' );

Conexao_Event_Query::flush_cache();
test_assert( false === get_transient( $today_key ), 'flush_cache() deletes the date-keyed transient' );

// Uncached evaluation with an explicit $from produces the same map shape.
$from_events = Conexao_Event_Query::upcoming_events( Conexao_Event_Query::today() );
test_assert( array_keys( $from_events ) === $ordered_ids, 'explicit $from evaluation matches the cached list' );

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------
foreach ( $test_post_ids as $test_post_id ) {
	wp_delete_post( $test_post_id, true );
}
Conexao_Event_Query::flush_cache();

echo "\n=== Results ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
