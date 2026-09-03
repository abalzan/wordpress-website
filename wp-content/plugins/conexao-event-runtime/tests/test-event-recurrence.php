<?php
/**
 * Tests for the internal event recurrence model + evaluator.
 *
 * Verifies Conexao_Event_Recurrence::occurs_on_date() and
 * Conexao_Event_Recurrence::next_occurrence() against the Step 1
 * recurrence design:
 *
 *  - Legacy one-time events keep the exact `_event_date` comparison.
 *  - Weekly recurrence: selected weekdays, inclusive start/end boundaries,
 *    open-ended and invalid-date defensive handling.
 *  - All evaluation happens on local calendar dates in the WordPress
 *    timezone (including DST transitions and UTC instants that map to a
 *    different calendar day in the site zone).
 *
 * The script creates temporary event posts and deletes them at the end;
 * it writes no other data and runs in the current plugin configuration.
 *
 * Usage (from the project root):
 *   docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-runtime/tests/test-event-recurrence.php
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
			'post_title'  => '[TEST] ' . $title,
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

/** Local midnight DateTimeImmutable for a Y-m-d string. */
function recurrence_date( $ymd ) {
	return new DateTimeImmutable( $ymd, wp_timezone() );
}

/** Y-m-d of a DateTimeImmutable in the site timezone. */
function recurrence_ymd( $dt ) {
	return $dt->setTimezone( wp_timezone() )->format( 'Y-m-d' );
}

echo "Running event recurrence runtime tests...\n";
echo 'Site: ' . home_url() . ' — timezone: ' . wp_timezone()->getName() . "\n";

// ---------------------------------------------------------------------------
// 1. Meta registration + class availability
// ---------------------------------------------------------------------------
test_section( 'Meta Registration' );

test_assert( class_exists( 'Conexao_Event_Recurrence' ), 'Conexao_Event_Recurrence class is loaded' );

$registered = get_registered_meta_keys( 'post', 'event' );
foreach ( array( '_event_recurrence', '_event_recurrence_days', '_event_recurrence_start', '_event_recurrence_end' ) as $key ) {
	test_assert( isset( $registered[ $key ] ), "{$key} meta is registered" );
}
test_assert( 'string' === $registered['_event_recurrence']['type'], '_event_recurrence type is string' );
test_assert( 'string' === $registered['_event_recurrence_days']['type'], '_event_recurrence_days type is string' );
test_assert( 'string' === $registered['_event_recurrence_start']['type'], '_event_recurrence_start type is string' );
test_assert( 'string' === $registered['_event_recurrence_end']['type'], '_event_recurrence_end type is string' );
test_assert( ! empty( $registered['_event_recurrence']['show_in_rest'] ), '_event_recurrence is REST-visible' );
test_assert( ! empty( $registered['_event_recurrence_days']['show_in_rest'] ), '_event_recurrence_days is REST-visible' );
test_assert( ! empty( $registered['_event_recurrence_start']['show_in_rest'] ), '_event_recurrence_start is REST-visible' );
test_assert( ! empty( $registered['_event_recurrence_end']['show_in_rest'] ), '_event_recurrence_end is REST-visible' );

// ---------------------------------------------------------------------------
// 2. One-time events (legacy behavior preserved)
// ---------------------------------------------------------------------------
test_section( 'One-Time Events' );

$one_time = create_test_event( 'One-time', array( '_event_date' => '2026-03-04' ) );
test_assert( Conexao_Event_Recurrence::occurs_on_date( $one_time, recurrence_date( '2026-03-04' ) ), 'one-time: occurs on its stored date' );
test_assert( ! Conexao_Event_Recurrence::occurs_on_date( $one_time, recurrence_date( '2026-03-05' ) ), 'one-time: not the day after' );
test_assert( ! Conexao_Event_Recurrence::occurs_on_date( $one_time, recurrence_date( '2026-03-03' ) ), 'one-time: not the day before' );

// Unsupported recurrence value must not silently become recurring.
$unsupported = create_test_event( 'Unsupported recurrence', array(
	'_event_recurrence'      => 'monthly',
	'_event_recurrence_days' => '3',
	'_event_date'            => '2026-03-04',
) );
test_assert( Conexao_Event_Recurrence::occurs_on_date( $unsupported, recurrence_date( '2026-03-04' ) ), 'unsupported type: behaves as one-time on its date' );
test_assert( ! Conexao_Event_Recurrence::occurs_on_date( $unsupported, recurrence_date( '2026-03-11' ) ), 'unsupported type: does not recur weekly' );

// ---------------------------------------------------------------------------
// 3. Weekly recurrence — occurs_on_date(): weekday selection
// ---------------------------------------------------------------------------
test_section( 'Weekly Recurrence: Weekday Selection' );

// Weekly Wednesday, using _event_date as the recurrence-start fallback.
$wed = create_test_event( 'Weekly Wed', array(
	'_event_recurrence'      => 'weekly',
	'_event_recurrence_days' => '3',
	'_event_date'            => '2026-03-04',
) );
test_assert( Conexao_Event_Recurrence::occurs_on_date( $wed, recurrence_date( '2026-03-04' ) ), 'weekly Wednesday: occurs on 2026-03-04 (start via _event_date)' );
test_assert( Conexao_Event_Recurrence::occurs_on_date( $wed, recurrence_date( '2026-03-11' ) ), 'weekly Wednesday: occurs on 2026-03-11' );
test_assert( ! Conexao_Event_Recurrence::occurs_on_date( $wed, recurrence_date( '2026-03-03' ) ), 'weekly Wednesday: Tuesday does not occur' );
test_assert( ! Conexao_Event_Recurrence::occurs_on_date( $wed, recurrence_date( '2026-03-09' ) ), 'weekly Wednesday: Monday does not occur' );

// Monday + Wednesday (explicit start).
$mon_wed = create_test_event( 'Mon+Wed', array(
	'_event_recurrence'       => 'weekly',
	'_event_recurrence_days'  => '1,3',
	'_event_recurrence_start' => '2026-03-02',
) );
test_assert( Conexao_Event_Recurrence::occurs_on_date( $mon_wed, recurrence_date( '2026-03-02' ) ), 'Mon+Wed: Monday occurs' );
test_assert( Conexao_Event_Recurrence::occurs_on_date( $mon_wed, recurrence_date( '2026-03-04' ) ), 'Mon+Wed: Wednesday occurs' );
test_assert( Conexao_Event_Recurrence::occurs_on_date( $mon_wed, recurrence_date( '2026-03-09' ) ), 'Mon+Wed: next Monday occurs' );
test_assert( ! Conexao_Event_Recurrence::occurs_on_date( $mon_wed, recurrence_date( '2026-03-03' ) ), 'Mon+Wed: Tuesday does not occur' );

// Sunday weekday (7) is parseable.
$sunday = create_test_event( 'Weekly Sun', array(
	'_event_recurrence'       => 'weekly',
	'_event_recurrence_days'  => '7',
	'_event_recurrence_start' => '2026-01-04',
) );
test_assert( Conexao_Event_Recurrence::occurs_on_date( $sunday, recurrence_date( '2026-01-04' ) ), 'weekly Sunday: ISO day 7 occurs' );

// ---------------------------------------------------------------------------
// 4. Weekly recurrence — start/end boundaries
// ---------------------------------------------------------------------------
test_section( 'Weekly Recurrence: Start/End Boundaries' );

// Recurrence starts on the selected weekday.
$start_on = create_test_event( 'Starts on selected day', array(
	'_event_recurrence'       => 'weekly',
	'_event_recurrence_days'  => '3',
	'_event_recurrence_start' => '2026-03-04', // Wednesday
) );
test_assert( Conexao_Event_Recurrence::occurs_on_date( $start_on, recurrence_date( '2026-03-04' ) ), 'start on selected weekday: occurs on start day' );
test_assert( Conexao_Event_Recurrence::occurs_on_date( $start_on, recurrence_date( '2026-03-11' ) ), 'start on selected weekday: occurs one week later' );
test_assert( ! Conexao_Event_Recurrence::occurs_on_date( $start_on, recurrence_date( '2026-03-03' ) ), 'start on selected weekday: no occurrence before start' );

// Recurrence starts before the selected weekday.
$start_before = create_test_event( 'Starts before selected day', array(
	'_event_recurrence'       => 'weekly',
	'_event_recurrence_days'  => '3',
	'_event_recurrence_start' => '2026-03-02', // Monday, before Wednesday
) );
test_assert( Conexao_Event_Recurrence::occurs_on_date( $start_before, recurrence_date( '2026-03-04' ) ), 'start before selected weekday: first Wednesday occurs' );
test_assert( ! Conexao_Event_Recurrence::occurs_on_date( $start_before, recurrence_date( '2026-03-02' ) ), 'start before selected weekday: Monday (unselected) does not occur' );

// Recurrence ends on the selected weekday (inclusive).
$end_on = create_test_event( 'Ends on selected day', array(
	'_event_recurrence'       => 'weekly',
	'_event_recurrence_days'  => '1',
	'_event_recurrence_start' => '2026-03-02',
	'_event_recurrence_end'   => '2026-03-09', // Monday
) );
test_assert( Conexao_Event_Recurrence::occurs_on_date( $end_on, recurrence_date( '2026-03-09' ) ), 'end on selected weekday: last occurrence included' );
test_assert( ! Conexao_Event_Recurrence::occurs_on_date( $end_on, recurrence_date( '2026-03-16' ) ), 'end on selected weekday: no occurrence after end' );

// Recurrence ends before the next selected weekday.
$end_before = create_test_event( 'Ends before next selected day', array(
	'_event_recurrence'       => 'weekly',
	'_event_recurrence_days'  => '1',
	'_event_recurrence_start' => '2026-03-02',
	'_event_recurrence_end'   => '2026-03-11', // Wednesday, before next Monday
) );
test_assert( Conexao_Event_Recurrence::occurs_on_date( $end_before, recurrence_date( '2026-03-09' ) ), 'end before next selected weekday: last Monday still occurs' );
test_assert( ! Conexao_Event_Recurrence::occurs_on_date( $end_before, recurrence_date( '2026-03-16' ) ), 'end before next selected weekday: no occurrence after end' );

// Start/end boundaries are inclusive.
$bounds = create_test_event( 'Inclusive boundaries', array(
	'_event_recurrence'       => 'weekly',
	'_event_recurrence_days'  => '1',
	'_event_recurrence_start' => '2026-03-02',
	'_event_recurrence_end'   => '2026-03-09',
) );
test_assert( Conexao_Event_Recurrence::occurs_on_date( $bounds, recurrence_date( '2026-03-02' ) ), 'boundaries: start day is inclusive' );
test_assert( Conexao_Event_Recurrence::occurs_on_date( $bounds, recurrence_date( '2026-03-09' ) ), 'boundaries: end day is inclusive' );
test_assert( ! Conexao_Event_Recurrence::occurs_on_date( $bounds, recurrence_date( '2026-03-01' ) ), 'boundaries: day before start does not occur' );

// Open-ended recurrence (no _event_recurrence_end).
$open = create_test_event( 'Open-ended', array(
	'_event_recurrence'       => 'weekly',
	'_event_recurrence_days'  => '1',
	'_event_recurrence_start' => '2026-03-02',
) );
test_assert( Conexao_Event_Recurrence::occurs_on_date( $open, recurrence_date( '2026-12-28' ) ), 'open-ended: still occurs months later' );

// ---------------------------------------------------------------------------
// 5. Weekly recurrence — defensive data handling
// ---------------------------------------------------------------------------
test_section( 'Weekly Recurrence: Defensive Data Handling' );

// Invalid recurrence start -> false (no silent _event_date fallback when an
// explicit but invalid start is present).
$bad_start = create_test_event( 'Invalid start', array(
	'_event_recurrence'       => 'weekly',
	'_event_recurrence_days'  => '1',
	'_event_recurrence_start' => 'not-a-date',
	'_event_date'             => '2026-03-02',
) );
test_assert( ! Conexao_Event_Recurrence::occurs_on_date( $bad_start, recurrence_date( '2026-03-02' ) ), 'invalid start: occurs_on_date is false' );

// Invalid recurrence end -> treated as open-ended.
$bad_end = create_test_event( 'Invalid end', array(
	'_event_recurrence'       => 'weekly',
	'_event_recurrence_days'  => '1',
	'_event_recurrence_start' => '2026-03-02',
	'_event_recurrence_end'   => '2026-99-99',
) );
test_assert( Conexao_Event_Recurrence::occurs_on_date( $bad_end, recurrence_date( '2027-01-04' ) ), 'invalid end: treated as open-ended' );

// Missing recurrence days -> defensive one-time fallback.
$no_days = create_test_event( 'Missing days', array(
	'_event_recurrence' => 'weekly',
	'_event_date'       => '2026-03-04',
) );
test_assert( Conexao_Event_Recurrence::occurs_on_date( $no_days, recurrence_date( '2026-03-04' ) ), 'missing days: falls back to one-time on _event_date' );
test_assert( ! Conexao_Event_Recurrence::occurs_on_date( $no_days, recurrence_date( '2026-03-11' ) ), 'missing days: does not recur weekly' );

// Malformed weekday CSV: invalid tokens discarded, valid ones kept.
$bad_csv = create_test_event( 'Malformed CSV', array(
	'_event_recurrence'       => 'weekly',
	'_event_recurrence_days'  => ' 1 , x , 3 , 9 ',
	'_event_recurrence_start' => '2026-03-02',
) );
test_assert( Conexao_Event_Recurrence::occurs_on_date( $bad_csv, recurrence_date( '2026-03-02' ) ), 'malformed CSV: valid Monday kept' );
test_assert( Conexao_Event_Recurrence::occurs_on_date( $bad_csv, recurrence_date( '2026-03-04' ) ), 'malformed CSV: valid Wednesday kept' );
test_assert( ! Conexao_Event_Recurrence::occurs_on_date( $bad_csv, recurrence_date( '2026-03-03' ) ), 'malformed CSV: invalid entries discarded' );

// ---------------------------------------------------------------------------
// 6. Calendar math — leap year, DST, timezone
// ---------------------------------------------------------------------------
test_section( 'Calendar Math: Leap Year, DST, Timezone' );

// Leap year 2028: 2028-02-29 is a Tuesday.
$leap = create_test_event( 'Leap year', array(
	'_event_recurrence'       => 'weekly',
	'_event_recurrence_days'  => '2',
	'_event_recurrence_start' => '2028-02-29',
) );
test_assert( Conexao_Event_Recurrence::occurs_on_date( $leap, recurrence_date( '2028-02-29' ) ), 'leap year: 2028-02-29 occurrence' );
test_assert( Conexao_Event_Recurrence::occurs_on_date( $leap, recurrence_date( '2028-03-07' ) ), 'leap year: following Tuesday' );
test_assert( ! Conexao_Event_Recurrence::occurs_on_date( $leap, recurrence_date( '2028-02-28' ) ), 'leap year: 2028-02-28 (Monday) does not occur' );

// Spring-forward DST: weekly Wednesday crossing 2026-03-29 (Europe/Dublin).
$dst = create_test_event( 'DST spring', array(
	'_event_recurrence'       => 'weekly',
	'_event_recurrence_days'  => '3',
	'_event_recurrence_start' => '2026-03-25',
) );
test_assert( Conexao_Event_Recurrence::occurs_on_date( $dst, recurrence_date( '2026-03-25' ) ), 'DST spring: before transition occurs' );
test_assert( Conexao_Event_Recurrence::occurs_on_date( $dst, recurrence_date( '2026-04-01' ) ), 'DST spring: first Wednesday after transition occurs' );
test_assert( ! Conexao_Event_Recurrence::occurs_on_date( $dst, recurrence_date( '2026-03-28' ) ), 'DST spring: Saturday does not occur' );

$dst_next = Conexao_Event_Recurrence::next_occurrence( $dst, recurrence_date( '2026-03-28' ) );
test_assert( $dst_next && '2026-04-01' === recurrence_ymd( $dst_next ), 'DST spring: next occurrence after transition is 2026-04-01' );

// Fall-back DST: weekly Tuesday crossing 2026-10-25 (Europe/Dublin).
$dst_fall = create_test_event( 'DST fall', array(
	'_event_recurrence'       => 'weekly',
	'_event_recurrence_days'  => '2',
	'_event_recurrence_start' => '2026-10-20',
) );
$dst_fall_next = Conexao_Event_Recurrence::next_occurrence( $dst_fall, recurrence_date( '2026-10-26' ) );
test_assert( $dst_fall_next && '2026-10-27' === recurrence_ymd( $dst_fall_next ), 'DST fall: next Tuesday after transition is 2026-10-27' );

// Timezone-sensitive Wednesday: the evaluator must use the LOCAL calendar
// date in the site timezone, not the UTC calendar date.
$tz = create_test_event( 'Timezone Wednesday', array(
	'_event_recurrence'       => 'weekly',
	'_event_recurrence_days'  => '3',
	'_event_recurrence_start' => '2026-06-03',
) );
$utc_wed_late = new DateTimeImmutable( '2026-06-03 23:30:00', new DateTimeZone( 'UTC' ) ); // = 2026-06-04 00:30 IST.
$utc_tue_late = new DateTimeImmutable( '2026-06-02 23:30:00', new DateTimeZone( 'UTC' ) ); // = 2026-06-03 00:30 IST.
test_assert( Conexao_Event_Recurrence::occurs_on_date( $tz, recurrence_date( '2026-06-03' ) ), 'timezone: Wednesday in site timezone occurs' );
test_assert( ! Conexao_Event_Recurrence::occurs_on_date( $tz, $utc_wed_late ), 'timezone: UTC Wed 23:30 maps to Thu in Dublin -> no occurrence' );
test_assert( Conexao_Event_Recurrence::occurs_on_date( $tz, $utc_tue_late ), 'timezone: UTC Tue 23:30 maps to Wed in Dublin -> occurs' );
// ---------------------------------------------------------------------------
// 7. next_occurrence()
// ---------------------------------------------------------------------------
test_section( 'next_occurrence()' );

// One-time: on/after the from date.
$nt_one = create_test_event( 'One-time next', array( '_event_date' => '2026-05-15' ) );
$n1 = Conexao_Event_Recurrence::next_occurrence( $nt_one, recurrence_date( '2026-05-10' ) );
test_assert( $n1 && '2026-05-15' === recurrence_ymd( $n1 ), 'one-time: next occurrence after 2026-05-10' );
$n2 = Conexao_Event_Recurrence::next_occurrence( $nt_one, recurrence_date( '2026-05-15' ) );
test_assert( $n2 && '2026-05-15' === recurrence_ymd( $n2 ), 'one-time: from equals the event date (on/after)' );
test_assert( null === Conexao_Event_Recurrence::next_occurrence( $nt_one, recurrence_date( '2026-05-16' ) ), 'one-time: null after the event date' );

// Weekly Monday+Wednesday.
$nt_weekly = create_test_event( 'Weekly next', array(
	'_event_recurrence'       => 'weekly',
	'_event_recurrence_days'  => '1,3',
	'_event_recurrence_start' => '2026-03-02',
) );
$n3 = Conexao_Event_Recurrence::next_occurrence( $nt_weekly, recurrence_date( '2026-03-05' ) );
test_assert( $n3 && '2026-03-09' === recurrence_ymd( $n3 ), 'weekly: from Thursday -> next Monday' );
$n4 = Conexao_Event_Recurrence::next_occurrence( $nt_weekly, recurrence_date( '2026-03-04' ) );
test_assert( $n4 && '2026-03-04' === recurrence_ymd( $n4 ), 'weekly: from an occurrence day returns it' );
$n5 = Conexao_Event_Recurrence::next_occurrence( $nt_weekly, recurrence_date( '2026-02-25' ) );
test_assert( $n5 && '2026-03-02' === recurrence_ymd( $n5 ), 'weekly: series start within the scan window' );

// After the series has ended.
$nt_ended = create_test_event( 'Weekly ended', array(
	'_event_recurrence'       => 'weekly',
	'_event_recurrence_days'  => '1',
	'_event_recurrence_start' => '2026-03-02',
	'_event_recurrence_end'   => '2026-03-09',
) );
$n6 = Conexao_Event_Recurrence::next_occurrence( $nt_ended, recurrence_date( '2026-03-09' ) );
test_assert( $n6 && '2026-03-09' === recurrence_ymd( $n6 ), 'ended series: end day itself still returns (inclusive)' );
test_assert( null === Conexao_Event_Recurrence::next_occurrence( $nt_ended, recurrence_date( '2026-03-10' ) ), 'ended series: null the day after the end' );

// Invalid recurrence start -> null in next_occurrence as well.
test_assert( null === Conexao_Event_Recurrence::next_occurrence( $bad_start, recurrence_date( '2026-03-02' ) ), 'invalid start: next_occurrence is null' );

// ---------------------------------------------------------------------------
// 8. current_datetime() compatibility (site-timezone now)
// ---------------------------------------------------------------------------
test_section( 'current_datetime() Compatibility' );

$now_dt   = current_datetime();
$today    = $now_dt->format( 'Y-m-d' );
$nt_today = create_test_event( 'One-time today', array( '_event_date' => $today ) );
test_assert( Conexao_Event_Recurrence::occurs_on_date( $nt_today, $now_dt ), 'current_datetime(): one-time occurs on today' );
$nt_now = Conexao_Event_Recurrence::next_occurrence( $nt_today, $now_dt );
test_assert( $nt_now && $today === recurrence_ymd( $nt_now ), 'current_datetime(): next occurrence is today' );

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------
test_section( 'Cleanup' );

foreach ( $test_post_ids as $pid ) {
	wp_delete_post( $pid, true );
}
echo 'Deleted ' . count( $test_post_ids ) . " temporary test events.\n";

echo "\n==============================\n";
echo "PASSED: {$passed}  FAILED: {$failed}\n";
echo "==============================\n";
exit( $failed > 0 ? 1 : 0 );