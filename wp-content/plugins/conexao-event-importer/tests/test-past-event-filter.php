<?php
/**
 * Automated tests for the past-event import filter.
 *
 * Tests Conexao_Event_Date_Filter evaluation rules (future/current/past/
 * invalid), multi-day cutoffs, missing-time fallbacks, invalid-date safety,
 * and the new Conexao_Import_Result skip counters.
 *
 * Usage: docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/test-past-event-filter.php
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

/**
 * Build a Y-m-d / H:i pair relative to now using the site timezone.
 *
 * @param string $modifier strtotime-style modifier, e.g. '+10 days'.
 * @return array{0:string,1:string} [date, time]
 */
function rel_datetime( $modifier ) {
	$dt = current_datetime()->modify( $modifier );
	return array( $dt->format( 'Y-m-d' ), $dt->format( 'H:i' ) );
}

echo "Running past-event filter tests...\n";
echo 'Site timezone: ' . wp_timezone()->getName() . "\n";
echo 'Now: ' . current_datetime()->format( 'Y-m-d H:i:s' ) . "\n";

// ---------------------------------------------------------------------------
// Test 1: Future events are imported
// ---------------------------------------------------------------------------
test_section( 'Future Events' );

list( $future_date, $future_time ) = rel_datetime( '+10 days' );
$check = Conexao_Event_Date_Filter::evaluate( array(
	'start_date' => $future_date,
	'start_time' => $future_time,
	'end_date'   => '',
	'end_time'   => '',
) );
test_assert( Conexao_Event_Date_Filter::IMPORT === $check['status'], 'Event starting in 10 days imports' );

list( $tomorrow_date, $tomorrow_time ) = rel_datetime( '+1 day' );
$check = Conexao_Event_Date_Filter::evaluate( array(
	'start_date' => $tomorrow_date,
	'start_time' => '',
	'end_date'   => '',
	'end_time'   => '',
) );
test_assert( Conexao_Event_Date_Filter::IMPORT === $check['status'], 'Tomorrow date-only event imports' );

// ---------------------------------------------------------------------------
// Test 2: Events happening today
// ---------------------------------------------------------------------------
test_section( 'Events Happening Today' );

$today = current_datetime()->format( 'Y-m-d' );
$check = Conexao_Event_Date_Filter::evaluate( array(
	'start_date' => $today,
	'start_time' => '',
	'end_date'   => '',
	'end_time'   => '',
) );
test_assert( Conexao_Event_Date_Filter::IMPORT === $check['status'], 'Today date-only event imports (runs until end of day)' );

list( $later_date, $later_time ) = rel_datetime( '+2 hours' );
$check = Conexao_Event_Date_Filter::evaluate( array(
	'start_date' => $later_date,
	'start_time' => $later_time,
	'end_date'   => '',
	'end_time'   => '',
) );
test_assert( Conexao_Event_Date_Filter::IMPORT === $check['status'], 'Today event starting in 2 hours imports' );

list( $earlier_date, $earlier_time ) = rel_datetime( '-2 hours' );
$check = Conexao_Event_Date_Filter::evaluate( array(
	'start_date' => $earlier_date,
	'start_time' => $earlier_time,
	'end_date'   => '',
	'end_time'   => '',
) );
test_assert( Conexao_Event_Date_Filter::PAST === $check['status'], 'Today event whose only start time already passed is skipped' );

// ---------------------------------------------------------------------------
// Test 3: Multi-day events use the end date as cutoff
// ---------------------------------------------------------------------------
test_section( 'Multi-Day Events' );

list( $past_start_date, $past_start_time ) = rel_datetime( '-5 days' );
list( $future_end_date, $future_end_time ) = rel_datetime( '+2 days' );
$check = Conexao_Event_Date_Filter::evaluate( array(
	'start_date' => $past_start_date,
	'start_time' => $past_start_time,
	'end_date'   => $future_end_date,
	'end_time'   => $future_end_time,
) );
test_assert( Conexao_Event_Date_Filter::IMPORT === $check['status'], 'Multi-day event in progress (ends in 2 days) imports' );

// Started days ago, ended yesterday.
list( $old_start_date, $old_start_time ) = rel_datetime( '-10 days' );
list( $yesterday_end_date, $yesterday_end_time ) = rel_datetime( '-1 day' );
$check = Conexao_Event_Date_Filter::evaluate( array(
	'start_date' => $old_start_date,
	'start_time' => $old_start_time,
	'end_date'   => $yesterday_end_date,
	'end_time'   => $yesterday_end_time,
) );
test_assert( Conexao_Event_Date_Filter::PAST === $check['status'], 'Multi-day event that ended yesterday is skipped' );

// Ended earlier today.
list( $ended_today_date, $ended_today_time ) = rel_datetime( '-1 hour' );
$check = Conexao_Event_Date_Filter::evaluate( array(
	'start_date' => rel_datetime( '-3 days' )[0],
	'start_time' => '09:00',
	'end_date'   => $ended_today_date,
	'end_time'   => $ended_today_time,
) );
test_assert( Conexao_Event_Date_Filter::PAST === $check['status'], 'Multi-day event that ended an hour ago today is skipped' );

// ---------------------------------------------------------------------------
// Test 4: Past events are never imported
// ---------------------------------------------------------------------------
test_section( 'Past Events' );

list( $last_week_date, $last_week_time ) = rel_datetime( '-7 days' );
$check = Conexao_Event_Date_Filter::evaluate( array(
	'start_date' => $last_week_date,
	'start_time' => $last_week_time,
	'end_date'   => '',
	'end_time'   => '',
) );
test_assert( Conexao_Event_Date_Filter::PAST === $check['status'], 'Event from last week is skipped' );

$check = Conexao_Event_Date_Filter::evaluate( array(
	'start_date' => $last_week_date,
	'start_time' => '',
	'end_date'   => '',
	'end_time'   => '',
) );
test_assert( Conexao_Event_Date_Filter::PAST === $check['status'], 'Date-only event from last week is skipped' );

// ---------------------------------------------------------------------------
// Test 5: Invalid/missing dates are safely reported
// ---------------------------------------------------------------------------
test_section( 'Invalid Dates' );

$check = Conexao_Event_Date_Filter::evaluate( array(
	'start_date' => '',
	'start_time' => '',
	'end_date'   => '',
	'end_time'   => '',
) );
test_assert( Conexao_Event_Date_Filter::INVALID === $check['status'], 'Missing start date is invalid' );

$check = Conexao_Event_Date_Filter::evaluate( array(
	'start_date' => 'not-a-date',
	'start_time' => '',
	'end_date'   => '',
	'end_time'   => '',
) );
test_assert( Conexao_Event_Date_Filter::INVALID === $check['status'], 'Unparseable start date is invalid' );

$check = Conexao_Event_Date_Filter::evaluate( array(
	'start_date' => '2026-02-30', // Impossible calendar date.
	'start_time' => '',
	'end_date'   => '',
	'end_time'   => '',
) );
test_assert( Conexao_Event_Date_Filter::INVALID === $check['status'], 'Impossible calendar date (Feb 30) is invalid' );

$check = Conexao_Event_Date_Filter::evaluate( array() );
test_assert( Conexao_Event_Date_Filter::INVALID === $check['status'], 'Empty event data is invalid (never treated as future)' );

// A valid start with a garbage end date falls back to the start date.
list( $tomorrow_date2, ) = rel_datetime( '+1 day' );
$check = Conexao_Event_Date_Filter::evaluate( array(
	'start_date' => $tomorrow_date2,
	'start_time' => '',
	'end_date'   => 'garbage-end-date',
	'end_time'   => '',
) );
test_assert( Conexao_Event_Date_Filter::IMPORT === $check['status'], 'Garbage end date falls back to valid start date' );

// ---------------------------------------------------------------------------
// Test 6: Timezone-aware comparison (site timezone, not UTC assumption)
// ---------------------------------------------------------------------------
test_section( 'Timezone Handling' );

// The filter must build its cutoff in the site timezone. Verify the cutoff
// label matches a site-timezone construction of the same fields.
$tz_name = wp_timezone()->getName();
test_assert( ! empty( $tz_name ), 'Site timezone is configured (' . $tz_name . ')' );

list( $in_3_days, $at_noon ) = rel_datetime( '+3 days' );
$check = Conexao_Event_Date_Filter::evaluate( array(
	'start_date' => $in_3_days,
	'start_time' => $at_noon,
	'end_date'   => '',
	'end_time'   => '',
) );
test_assert( Conexao_Event_Date_Filter::IMPORT === $check['status'], 'Cutoff evaluated against site-timezone now' );
test_assert( $in_3_days . ' ' . $at_noon === $check['cutoff_label'], 'Cutoff label reflects the event date/time' );

// ---------------------------------------------------------------------------
// Test 7: Integration with the normalizer output shape
// ---------------------------------------------------------------------------
test_section( 'Normalizer Integration' );

$location = new Conexao_Event_Location();
$normalizer = new Conexao_Event_Normalizer( $location );

list( $norm_past_date, $norm_past_time ) = rel_datetime( '-4 days' );
$normalized = $normalizer->normalize( array(
	'title'      => 'Old Event',
	'url'        => 'https://example.com/old',
	'source_id'  => 'old-1',
	'source'     => 'test',
	'start_date' => $norm_past_date . ' 10:00:00',
	'location'   => 'Portlaoise, Laois',
) );
$check = Conexao_Event_Date_Filter::evaluate( $normalized );
test_assert( Conexao_Event_Date_Filter::PAST === $check['status'], 'Normalized past event evaluates as past' );

list( $norm_future_date, ) = rel_datetime( '+30 days' );
$normalized = $normalizer->normalize( array(
	'title'      => 'New Event',
	'url'        => 'https://example.com/new',
	'source_id'  => 'new-1',
	'source'     => 'test',
	'start_date' => $norm_future_date,
	'location'   => 'Portlaoise, Laois',
) );
$check = Conexao_Event_Date_Filter::evaluate( $normalized );
test_assert( Conexao_Event_Date_Filter::IMPORT === $check['status'], 'Normalized future event evaluates as importable' );

// ---------------------------------------------------------------------------
// Test 8: Import result counters
// ---------------------------------------------------------------------------
test_section( 'Import Result Skip Counters' );

$result = new Conexao_Import_Result( 'test_source', 'Test Source' );
$result->set_found( 4 );
$result->add_created( 'Kept Event', 1 );
$result->add_skipped_past( 'Old Event', 'Skipped: the event has already ended (2026-08-20 18:00).' );
$result->add_skipped_invalid_date( 'Broken Event', 'Skipped: the event date could not be evaluated.' );

$counts = $result->get_counts();
test_assert( 1 === $counts['created'], 'Created count unaffected by skips' );
test_assert( 1 === $counts['skipped_past'], 'skipped_past counter increments' );
test_assert( 1 === $counts['skipped_invalid_date'], 'skipped_invalid_date counter increments' );
test_assert( 2 === $counts['skipped'], 'Generic skipped counter includes past + invalid-date skips' );

$stats = $result->get_stats();
test_assert( isset( $stats['skipped_past'] ) && 1 === $stats['skipped_past'], 'get_stats exposes skipped_past' );
test_assert( isset( $stats['skipped_invalid_date'] ) && 1 === $stats['skipped_invalid_date'], 'get_stats exposes skipped_invalid_date' );

$skipped_events = $result->get_skipped_events();
test_assert( 2 === count( $skipped_events ), 'Both date-skips appear in get_skipped_events()' );
test_assert( 'Skipped: the event has already ended (2026-08-20 18:00).' === $skipped_events[0]['message'], 'Past-skip reason recorded verbatim' );
test_assert( 'warning' === $result->get_status(), 'Skips upgrade status to warning' );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n=== Results ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );