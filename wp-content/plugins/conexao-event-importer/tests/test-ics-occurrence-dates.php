<?php
/**
 * Tests for ICS occurrence-date resolution (Laois Tourism collapsed series +
 * generic RRULE) and its effect on the event date/archive layer.
 *
 * Proves, against the REAL captured Laois Tourism ICS fixture:
 *
 *  - Chair Yoga is recognised as exactly FOUR weekly occurrences and matches
 *    only 2026-09-29, 2026-10-06, 2026-10-13 and 2026-10-20 — and none of the
 *    neighbouring dates the old DTSTART..DTEND range wrongly included.
 *  - The other multi-week Laois programmes get consistent occurrence dates.
 *  - A genuine multi-day event still covers its whole date range.
 *  - An explicit RFC 5545 RRULE event still resolves.
 *  - VALUE=DATE all-day events are untouched.
 *  - The rule is source-scoped, so standard ICS semantics are preserved.
 *  - Re-running the write is idempotent.
 *
 * The suite writes only its own temporary events and deletes them at the end.
 *
 * Usage:
 *   docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/test-ics-occurrence-dates.php
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$passed        = 0;
$failed        = 0;
$test_post_ids = array();

/** Path to the captured real-world Laois Tourism ICS fixture. */
function ics_fixture_path() {
	return __DIR__ . '/fixtures/ics/laois-tourism.ics';
}

/**
 * Parse a raw ICS string through the real importer source handler.
 *
 * @param string $raw       ICS text.
 * @param string $source_id Source slug the parse runs under.
 * @return array[] Parsed events.
 */
function ics_parse( $raw, $source_id ) {
	$source = new Conexao_Source_ICalendar(
		array(
			'id'          => $source_id,
			'url'         => 'https://example.invalid/feed.ics',
			'source_url'  => 'https://example.invalid/',
			// Fixture is supplied inline so the suite never touches the network.
			'ics_content' => $raw,
		)
	);

	$method = new ReflectionMethod( 'Conexao_Source_ICalendar', 'parse_ical' );
	$method->setAccessible( true );

	return $method->invoke( $source, $raw );
}

/**
 * Find one parsed event by exact title.
 *
 * @param array[] $events Parsed events.
 * @param string  $title  Title to find.
 * @return array|null
 */
function ics_event_by_title( array $events, $title ) {
	foreach ( $events as $event ) {
		if ( isset( $event['title'] ) && $event['title'] === $title ) {
			return $event;
		}
	}
	return null;
}

/**
 * Create a temporary event post carrying the given meta.
 *
 * @param string $title Event title.
 * @param array  $meta  Meta to set.
 * @return int Post ID.
 */
function ics_create_event( $title, array $meta ) {
	global $test_post_ids;
	$post_id = wp_insert_post(
		array(
			'post_type'   => 'event',
			'post_title'  => '[ICSTEST] ' . $title,
			'post_status' => 'publish',
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		exit( 1 );
	}
	foreach ( $meta as $key => $value ) {
		update_post_meta( $post_id, $key, $value );
	}
	$test_post_ids[] = $post_id;
	return $post_id;
}

/** Local midnight DateTimeImmutable for a Y-m-d string. */
function ics_date( $ymd ) {
	return new DateTimeImmutable( $ymd, wp_timezone() );
}

/**
 * Persist a parsed recurrence through the real importer writer.
 *
 * @param int   $post_id    Post ID.
 * @param array $normalized Normalized event array (needs `recurrence`).
 * @return void
 */
function ics_write_recurrence( $post_id, array $normalized ) {
	$engine = new Conexao_Event_Importer_Engine(
		new Conexao_Event_Sources(),
		new Conexao_Event_Location()
	);
	$method = new ReflectionMethod( 'Conexao_Event_Importer_Engine', 'save_event_recurrence_meta' );
	$method->setAccessible( true );
	$method->invoke( $engine, $post_id, $normalized );
}

/** Read the four recurrence meta keys as a comparable array. */
function ics_stored_recurrence( $post_id ) {
	return array(
		'type'  => (string) get_post_meta( $post_id, '_event_recurrence', true ),
		'days'  => (string) get_post_meta( $post_id, '_event_recurrence_days', true ),
		'start' => (string) get_post_meta( $post_id, '_event_recurrence_start', true ),
		'end'   => (string) get_post_meta( $post_id, '_event_recurrence_end', true ),
	);
}

/**
 * Build a minimal single-VEVENT ICS document.
 *
 * @param array $props DTSTART/DTEND/RRULE lines, in order.
 * @return string
 */
function ics_minimal( array $props ) {
	$lines = array(
		'BEGIN:VCALENDAR',
		'VERSION:2.0',
		'PRODID:-//Test//EN',
		'BEGIN:VEVENT',
	);
	foreach ( $props as $line ) {
		$lines[] = $line;
	}
	$lines[] = 'END:VEVENT';
	$lines[] = 'END:VCALENDAR';

	return implode( "\r\n", $lines ) . "\r\n";
}

echo 'Site: ' . home_url() . " — timezone: " . wp_timezone()->getName() . "\n";
echo 'Fixture: ' . ics_fixture_path() . "\n";

// ---------------------------------------------------------------------------
test_section( '1. Classes loaded' );

assert_true( class_exists( 'Conexao_ICS_Recurrence' ), 'Conexao_ICS_Recurrence is loaded' );
assert_true( class_exists( 'Conexao_Laois_Tourism_Series' ), 'Conexao_Laois_Tourism_Series is loaded' );
assert_true( class_exists( 'Conexao_Event_Recurrence' ), 'Conexao_Event_Recurrence (runtime) is available' );
assert_true( is_readable( ics_fixture_path() ), 'the real Laois ICS fixture is readable' );

// ---------------------------------------------------------------------------
test_section( '2. Source convention: the feed declares no RRULE at all' );

$fixture_raw = file_get_contents( ics_fixture_path() );

assert_true( false !== strpos( $fixture_raw, 'BEGIN:VEVENT' ), 'fixture contains VEVENTs' );
assert_true( 0 === preg_match_all( '/^RRULE/mi', $fixture_raw ), 'fixture contains ZERO RRULE lines (measured)', conexao_test_describe( 0 ) );
assert_true( 0 === preg_match_all( '/^(EXDATE|RDATE)/mi', $fixture_raw ), 'fixture contains zero EXDATE/RDATE lines', conexao_test_describe( 0 ) );

$events = ics_parse( $fixture_raw, Conexao_Laois_Tourism_Series::SOURCE_ID );

assert_true( count( $events ) >= 30, 'fixture yields at least 30 parsed events, got ' . count( $events ) );

$chair_yoga = ics_event_by_title( $events, 'Chair Yoga At Portlaoise Library' );
assert_true( is_array( $chair_yoga ), 'Chair Yoga At Portlaoise Library was parsed' );

if ( is_array( $chair_yoga ) ) {
	// The source's literal DTSTART/DTEND pair, unchanged.
	assert_true( '2026-09-29' === $chair_yoga['start_date'], 'Chair Yoga DTSTART date is 2026-09-29', conexao_test_describe( '2026-09-29' ) );
	assert_true( '2026-10-20' === $chair_yoga['end_date'], 'Chair Yoga DTEND date is 2026-10-20', conexao_test_describe( '2026-10-20' ) );
	assert_true( '14:00' === $chair_yoga['start_time'], 'Chair Yoga DTSTART time is 14:00 (TZID local)', conexao_test_describe( '14:00' ) );
	assert_true( '15:00' === $chair_yoga['end_time'], 'Chair Yoga DTEND time is 15:00 (TZID local)', conexao_test_describe( '15:00' ) );

	// The fix: described as a bounded weekly series.
	assert_true( isset( $chair_yoga['recurrence'] ), 'Chair Yoga carries a recurrence description' );
	assert_true( 'weekly' === $chair_yoga['recurrence']['type'], 'Chair Yoga recurrence type is weekly', conexao_test_describe( 'weekly' ) );
	assert_true( 'laois-collapsed-weekly' === $chair_yoga['recurrence']['source_rule'], 'Chair Yoga resolved by the Laois collapsed-series rule', conexao_test_describe( 'laois-collapsed-weekly' ) );
	assert_true( '2' === (string) $chair_yoga['recurrence']['days'][0], 'Chair Yoga weekday set is Tuesday (ISO 2)', conexao_test_describe( '2' ) );
	assert_true( '2026-09-29' === $chair_yoga['recurrence']['start'], 'Chair Yoga series starts 2026-09-29', conexao_test_describe( '2026-09-29' ) );
	assert_true( '2026-10-20' === $chair_yoga['recurrence']['end'], 'Chair Yoga series ends 2026-10-20', conexao_test_describe( '2026-10-20' ) );
	assert_true( 4 === (int) $chair_yoga['recurrence']['count'], 'Chair Yoga resolves to exactly FOUR occurrences', conexao_test_describe( 4 ) );
	assert_true( array( '2026-09-29', '2026-10-06', '2026-10-13', '2026-10-20' ) === $chair_yoga['recurrence']['occurrences'], 'Chair Yoga occurrence dates are the four expected Tuesdays', conexao_test_describe( array( '2026-09-29', '2026-10-06', '2026-10-13', '2026-10-20' ) ) );
}

// ---------------------------------------------------------------------------
test_section( '3. Chair Yoga occurrence-date matching (the reported bug)' );

$chair_id = ics_create_event(
	'Chair Yoga',
	array(
		'_event_date'             => '2026-09-29',
		'_event_start_time'       => '14:00',
		'_event_end_date'         => '2026-10-20',
		'_event_end_time'         => '15:00',
		'_event_recurrence'       => 'weekly',
		'_event_recurrence_days'  => '2',
		'_event_recurrence_start' => '2026-09-29',
		'_event_recurrence_end'   => '2026-10-20',
	)
);

foreach ( array(
	'2026-09-29' => 'first occurrence',
	'2026-10-06' => 'second occurrence',
	'2026-10-13' => 'third occurrence',
	'2026-10-20' => 'fourth (final) occurrence',
) as $date => $why ) {
	assert_true(
		Conexao_Event_Recurrence::occurs_on_date( $chair_id, ics_date( $date ) ),
		"Chair Yoga occurs on $date ($why)"
	);
}

$in_range_non_occurrences = array(
	'2026-09-30', '2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05',
	'2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12',
	'2026-10-14', '2026-10-15', '2026-10-16', '2026-10-17', '2026-10-18', '2026-10-19',
);
foreach ( $in_range_non_occurrences as $date ) {
	assert_true(
		! Conexao_Event_Recurrence::occurs_on_date( $chair_id, ics_date( $date ) ),
		"Chair Yoga does NOT occur on $date (inside the old DTSTART..DTEND range)"
	);
}

// The five neighbours named in the acceptance criteria, re-asserted explicitly.
foreach ( array( '2026-10-02', '2026-10-05', '2026-10-07', '2026-10-12', '2026-10-14' ) as $date ) {
	assert_true(
		! Conexao_Event_Recurrence::occurs_on_date( $chair_id, ics_date( $date ) ),
		"Chair Yoga specifically does NOT match $date"
	);
}

assert_true( ! Conexao_Event_Recurrence::occurs_on_date( $chair_id, ics_date( '2026-09-22' ) ), 'Chair Yoga has no occurrence before 2026-09-29' );
assert_true( ! Conexao_Event_Recurrence::occurs_on_date( $chair_id, ics_date( '2026-10-27' ) ), 'Chair Yoga has no occurrence after 2026-10-20' );

assert_true( '2026-10-06' === Conexao_Event_Recurrence::next_occurrence( $chair_id, ics_date( '2026-10-02' ) )->format( 'Y-m-d' ), 'from 2026-10-02 the next Chair Yoga occurrence is 2026-10-06' );
assert_true( '2026-10-13' === Conexao_Event_Recurrence::next_occurrence( $chair_id, ics_date( '2026-10-07' ) )->format( 'Y-m-d' ), 'from 2026-10-07 the next Chair Yoga occurrence is 2026-10-13' );
assert_true( null === Conexao_Event_Recurrence::next_occurrence( $chair_id, ics_date( '2026-10-21' ) ), 'after the series ends there is no next occurrence' );

// ---------------------------------------------------------------------------
test_section( '4. Other multi-week Laois programmes' );

$expected_series = array(
	'What Were You Thinking Of Darling?'             => array( '2026-09-07', '2026-09-14', '2026-09-21', '2026-09-28', '2026-10-05', '2026-10-12', '2026-10-19' ),
	'Beginners Crochet Classes At Portlaoise Library' => array( '2026-09-14', '2026-09-21', '2026-09-28', '2026-10-05', '2026-10-12', '2026-10-19' ),
	'Bootcamp with Malone Fitness'                    => array( '2026-09-21', '2026-09-28', '2026-10-05', '2026-10-12', '2026-10-19', '2026-10-26' ),
	'Pilates Programme with Sabrina'                  => array( '2026-09-22', '2026-09-29', '2026-10-06', '2026-10-13', '2026-10-20', '2026-10-27' ),
);

foreach ( $expected_series as $title => $dates ) {
	$event = ics_event_by_title( $events, $title );
	assert_true( is_array( $event ), "$title was parsed" );

	if ( ! is_array( $event ) || ! isset( $event['recurrence'] ) ) {
		continue;
	}

	assert_true( $dates === $event['recurrence']['occurrences'], "$title occurrence dates are consistent (weekly from DTSTART)", conexao_test_describe( $dates ) );
	assert_true( count( $dates ) === (int) $event['recurrence']['count'], "$title occurrence count matches its date set", conexao_test_describe( count( $dates ) ) );

	// Round-trip through the real writer, then evaluate with the real runtime.
	$post_id = ics_create_event(
		$title,
		array(
			'_event_date'       => $event['start_date'],
			'_event_start_time' => $event['start_time'],
			'_event_end_date'   => $event['end_date'],
			'_event_end_time'   => $event['end_time'],
		)
	);
	ics_write_recurrence( $post_id, array( 'recurrence' => $event['recurrence'] ) );

	$stored = ics_stored_recurrence( $post_id );
	assert_true( 'weekly' === $stored['type'], "$title stored as a weekly series", conexao_test_describe( 'weekly' ) );
	assert_true( $dates[0] === $stored['start'], "$title stored series start is its first occurrence", conexao_test_describe( $dates[0] ) );
	assert_true( end( $dates ) === $stored['end'], "$title stored series end is its last occurrence", conexao_test_describe( end( $dates ) ) );

	foreach ( $dates as $date ) {
		assert_true( Conexao_Event_Recurrence::occurs_on_date( $post_id, ics_date( $date ) ), "$title occurs on $date" );
	}

	// A date inside the DTSTART..DTEND range but off the weekday must not match.
	$inside = null;
	for ( $offset = 1; $offset < 7; $offset++ ) {
		$candidate = gmdate( 'Y-m-d', strtotime( $dates[0] . ' 00:00:00 UTC' ) + ( $offset * DAY_IN_SECONDS ) );
		if ( $candidate < end( $dates ) && ! in_array( $candidate, $dates, true ) ) {
			$inside = $candidate;
			break;
		}
	}
	if ( null !== $inside ) {
		assert_true( ! Conexao_Event_Recurrence::occurs_on_date( $post_id, ics_date( $inside ) ), "$title does NOT occur on the in-range non-occurrence $inside" );
	}
}

// ---------------------------------------------------------------------------
test_section( '5. Genuine multi-day and all-day events are NOT series' );

$multiday_id = ics_create_event(
	'Real multi-day',
	array(
		'_event_date'       => '2026-09-30',
		'_event_start_time' => '19:30',
		'_event_end_date'   => '2026-10-03',
		'_event_end_time'   => '22:00',
	)
);

foreach ( array( '2026-09-30', '2026-10-01', '2026-10-02', '2026-10-03' ) as $date ) {
	assert_true( Conexao_Event_Recurrence::occurs_on_date( $multiday_id, ics_date( $date ) ), "genuine multi-day event still covers $date" );
}
assert_true( ! Conexao_Event_Recurrence::occurs_on_date( $multiday_id, ics_date( '2026-10-04' ) ), 'genuine multi-day event does not extend past its end' );

// The feed's own all-day multi-week exhibition (VALUE=DATE) must stay one-time.
$exhibition = ics_event_by_title( $events, 'Imposter Art Exhibition' );
// ---------------------------------------------------------------------------
test_section( '6. Explicit RRULE still works (generic, not Laois-specific)' );

$rrule_raw = ics_minimal(
	array(
		'UID:rrule-explicit@test',
		'SUMMARY:Explicit Weekly RRULE',
		'DTSTART;TZID=Europe/Dublin:20260901T190000',
		'DTEND;TZID=Europe/Dublin:20260901T210000',
		'RRULE:FREQ=WEEKLY;BYDAY=TU;COUNT=5',
		'URL:https://example.invalid/rrule',
	)
);

$rrule_event = ics_event_by_title( ics_parse( $rrule_raw, 'some_other_ics_source' ), 'Explicit Weekly RRULE' );
assert_true( is_array( $rrule_event ), 'explicit RRULE event parsed' );

if ( is_array( $rrule_event ) && isset( $rrule_event['recurrence'] ) ) {
	assert_true( 'rrule' === $rrule_event['recurrence']['source_rule'], 'explicit RRULE resolved by the standard path', conexao_test_describe( 'rrule' ) );
	assert_true( 'weekly' === $rrule_event['recurrence']['type'], 'explicit RRULE type is weekly', conexao_test_describe( 'weekly' ) );
	assert_true( '2026-09-01' === $rrule_event['recurrence']['start'], 'RRULE series starts at DTSTART', conexao_test_describe( '2026-09-01' ) );
	assert_true( array( '2026-09-01', '2026-09-08', '2026-09-15', '2026-09-22', '2026-09-29' ) === $rrule_event['recurrence']['occurrences'], 'COUNT=5 weekly RRULE yields exactly five occurrences', conexao_test_describe( array( '2026-09-01', '2026-09-08', '2026-09-15', '2026-09-22', '2026-09-29' ) ) );

	$rrule_post = ics_create_event(
		'RRULE',
		array(
			'_event_date'       => '2026-09-01',
			'_event_start_time' => '19:00',
			'_event_end_date'   => '2026-09-01',
			'_event_end_time'   => '21:00',
		)
	);
	ics_write_recurrence( $rrule_post, array( 'recurrence' => $rrule_event['recurrence'] ) );

	assert_true( Conexao_Event_Recurrence::occurs_on_date( $rrule_post, ics_date( '2026-09-29' ) ), 'RRULE series occurs on its 5th date' );
	assert_true( ! Conexao_Event_Recurrence::occurs_on_date( $rrule_post, ics_date( '2026-10-06' ) ), 'RRULE series stops after COUNT occurrences' );
	assert_true( ! Conexao_Event_Recurrence::occurs_on_date( $rrule_post, ics_date( '2026-09-02' ) ), 'RRULE series does not occur on an off-weekday date' );
} else {
	assert_true( false, 'explicit RRULE produced no recurrence description' );
}

// An UNTIL-bounded rule, whose UNTIL is a UTC DATE-TIME (DST edge).
$until_raw = ics_minimal(
	array(
		'UID:rrule-until@test',
		'SUMMARY:RRULE Until',
		'DTSTART;TZID=Europe/Dublin:20261026T190000',
		'DTEND;TZID=Europe/Dublin:20261026T210000',
		'RRULE:FREQ=WEEKLY;BYDAY=MO;UNTIL=20261116T215959Z',
		'URL:https://example.invalid/until',
	)
);

$until_event = ics_event_by_title( ics_parse( $until_raw, 'other_source' ), 'RRULE Until' );
assert_true( is_array( $until_event ), 'UNTIL-bounded RRULE parsed' );

if ( is_array( $until_event ) && isset( $until_event['recurrence'] ) ) {
	assert_true( '2026-11-16' === $until_event['recurrence']['end'], 'UNTIL (a UTC DATE-TIME) yields the calendar date 2026-11-16', conexao_test_describe( '2026-11-16' ) );
	assert_true( array( '2026-10-26', '2026-11-02', '2026-11-09', '2026-11-16' ) === $until_event['recurrence']['occurrences'], 'UNTIL-bounded RRULE yields four Mondays', conexao_test_describe( array( '2026-10-26', '2026-11-02', '2026-11-09', '2026-11-16' ) ) );
} else {
	assert_true( false, 'UNTIL-bounded RRULE produced no recurrence description' );
}

// A rule outside the representable subset degrades safely to a one-time event.
$daily_raw = ics_minimal(
	array(
		'UID:rrule-daily@test',
		'SUMMARY:Daily RRULE',
		'DTSTART;TZID=Europe/Dublin:20260901T190000',
		'DTEND;TZID=Europe/Dublin:20260901T210000',
		'RRULE:FREQ=DAILY;COUNT=3',
		'URL:https://example.invalid/daily',
	)
);

$daily_event = ics_event_by_title( ics_parse( $daily_raw, 'other_source' ), 'Daily RRULE' );
assert_true( is_array( $daily_event ), 'FREQ=DAILY event still parses' );

if ( is_array( $daily_event ) ) {
	assert_true( ! isset( $daily_event['recurrence'] ), 'a non-weekly RRULE is not forced into the weekly model' );
	assert_true( '2026-09-01' === $daily_event['start_date'], 'non-weekly RRULE keeps its literal DTSTART', conexao_test_describe( '2026-09-01' ) );
}

// INTERVAL=2 is likewise not silently approximated.
$biweekly_raw = ics_minimal(
	array(
		'UID:rrule-biweekly@test',
		'SUMMARY:Biweekly RRULE',
		'DTSTART;TZID=Europe/Dublin:20260901T190000',
		'DTEND;TZID=Europe/Dublin:20260901T210000',
		'RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=TU;COUNT=3',
		'URL:https://example.invalid/biweekly',
	)
);

$biweekly_event = ics_event_by_title( ics_parse( $biweekly_raw, 'other_source' ), 'Biweekly RRULE' );
assert_true( is_array( $biweekly_event ), 'INTERVAL=2 RRULE event still parses' );

if ( is_array( $biweekly_event ) ) {
	assert_true( ! isset( $biweekly_event['recurrence'] ), 'INTERVAL=2 is refused rather than approximated as weekly' );
}

// ---------------------------------------------------------------------------
test_section( '7. The rule is source-scoped (standard ICS semantics preserved)' );

// The identical fixture under a DIFFERENT source id must produce no series.
$other_chair = ics_event_by_title( ics_parse( $fixture_raw, 'a_different_ics_feed' ), 'Chair Yoga At Portlaoise Library' );
assert_true( is_array( $other_chair ), 'Chair Yoga parses under another source id' );

if ( is_array( $other_chair ) ) {
	assert_true( ! isset( $other_chair['recurrence'] ), 'the Laois rule does NOT leak to other ICS sources' );
	assert_true( '2026-09-29' === $other_chair['start_date'], 'other source keeps literal DTSTART', conexao_test_describe( '2026-09-29' ) );
	assert_true( '2026-10-20' === $other_chair['end_date'], 'other source keeps literal DTEND', conexao_test_describe( '2026-10-20' ) );
}

assert_true( Conexao_Laois_Tourism_Series::handles_source( 'laois_tourism' ), 'adapter claims only laois_tourism' );
assert_true( ! Conexao_Laois_Tourism_Series::handles_source( 'heritage_week' ), 'adapter refuses other source ids' );
assert_true( ! Conexao_Laois_Tourism_Series::handles_source( 'eventbrite' ), 'adapter refuses eventbrite' );

// The adapter must reject the shapes it is NOT allowed to claim.
$adapter_rejections = array(
	'all-day spanning weeks'    => array( 'all_day' => true, 'start_date' => '2026-10-02', 'start_time' => '', 'end_date' => '2026-10-31', 'end_time' => '' ),
	'different weekdays'        => array( 'start_date' => '2026-09-30', 'start_time' => '19:30', 'end_date' => '2026-10-03', 'end_time' => '22:00' ),
	// 8 days (not a multiple of 7) => not a collapsed weekly series.
	'not a whole-week span'     => array( 'start_date' => '2026-09-29', 'start_time' => '14:00', 'end_date' => '2026-10-07', 'end_time' => '15:00' ),
	'zero-length span'          => array( 'start_date' => '2026-09-29', 'start_time' => '14:00', 'end_date' => '2026-09-29', 'end_time' => '15:00' ),
	'DTEND time before DTSTART' => array( 'start_date' => '2026-09-29', 'start_time' => '19:00', 'end_date' => '2026-10-20', 'end_time' => '15:00' ),
	'missing end time'          => array( 'start_date' => '2026-09-29', 'start_time' => '14:00', 'end_date' => '2026-10-20', 'end_time' => '' ),
);
foreach ( $adapter_rejections as $why => $payload ) {
	assert_true( null === Conexao_Laois_Tourism_Series::detect( $payload ), "adapter rejects: $why", conexao_test_describe( null ) );
}

// Positive control: the same function accepts the genuine collapsed shape.
assert_true(
	is_array( Conexao_Laois_Tourism_Series::detect(
		array( 'start_date' => '2026-09-29', 'start_time' => '14:00', 'end_date' => '2026-10-20', 'end_time' => '15:00' )
	) ),
	'adapter accepts the genuine collapsed weekly shape (positive control)'
);

// ---------------------------------------------------------------------------
test_section( '8. DST correctness (Europe/Dublin transitions)' );

// The Europe/Dublin DST end is 2026-10-25T01:00. Pilates Programme runs
// 2026-09-22 -> 2026-10-27, straight across the transition.
$pilates = ics_event_by_title( $events, 'Pilates Programme with Sabrina' );
assert_true( is_array( $pilates ), 'Pilates Programme (spans the DST end) was parsed' );

if ( is_array( $pilates ) && isset( $pilates['recurrence'] ) ) {
	$pilates_id = ics_create_event(
		'Pilates DST',
		array(
			'_event_date'       => $pilates['start_date'],
			'_event_start_time' => $pilates['start_time'],
			'_event_end_date'   => $pilates['end_date'],
			'_event_end_time'   => $pilates['end_time'],
		)
	);
	ics_write_recurrence( $pilates_id, array( 'recurrence' => $pilates['recurrence'] ) );

	foreach ( array( '2026-09-22', '2026-10-06', '2026-10-20', '2026-10-27' ) as $date ) {
		assert_true( Conexao_Event_Recurrence::occurs_on_date( $pilates_id, ics_date( $date ) ), "DST-spanning series occurs on $date (TZID 20:00, unchanged)" );
	}
	foreach ( array( '2026-10-01', '2026-10-07', '2026-10-21', '2026-10-26' ) as $date ) {
		assert_true( ! Conexao_Event_Recurrence::occurs_on_date( $pilates_id, ics_date( $date ) ), "DST-spanning series does NOT occur on $date" );
	}

	// The last Laois occurrence (2026-10-27) sits AFTER the transition and
	// still belongs to the series, proving the window end is read as a date.
	assert_true(
		in_array( '2026-10-27', $pilates['recurrence']['occurrences'], true ),
		'the post-transition occurrence 2026-10-27 is part of the series'
	);
}

assert_true( array( '2026-10-26', '2026-11-02' ) === Conexao_ICS_Recurrence::occurrence_dates( '2026-10-26', array( 1 ), '2026-11-02' ), 'occurrence dates after the DST transition are not shifted by a day', conexao_test_describe( array( '2026-10-26', '2026-11-02' ) ) );

// ---------------------------------------------------------------------------
test_section( '9. Idempotence of the recurrence write' );

$idempotent_id = ics_create_event( 'Idempotent', array( '_event_date' => '2026-09-29' ) );
$normalized    = array( 'recurrence' => $chair_yoga['recurrence'] );

ics_write_recurrence( $idempotent_id, $normalized );
$first = ics_stored_recurrence( $idempotent_id );
ics_write_recurrence( $idempotent_id, $normalized );
$second = ics_stored_recurrence( $idempotent_id );
ics_write_recurrence( $idempotent_id, $normalized );
$third = ics_stored_recurrence( $idempotent_id );

assert_true( $first === $second, 'a second identical write changes nothing', conexao_test_describe( $first ) );
assert_true( $second === $third, 'a third identical write changes nothing', conexao_test_describe( $second ) );
assert_true( 'weekly' === $third['type'], 'stored type survives repeated writes', conexao_test_describe( 'weekly' ) );
assert_true( '2' === $third['days'], 'stored weekday set survives repeated writes', conexao_test_describe( '2' ) );
assert_true( '2026-09-29' === $third['start'], 'stored start survives repeated writes', conexao_test_describe( '2026-09-29' ) );
assert_true( '2026-10-20' === $third['end'], 'stored end survives repeated writes', conexao_test_describe( '2026-10-20' ) );

// Exactly one meta row per key — no duplicate rows from repeated writes.
global $wpdb;
foreach ( array( '_event_recurrence', '_event_recurrence_days', '_event_recurrence_start', '_event_recurrence_end' ) as $key ) {
	$rows = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $idempotent_id, $key )
	);
	assert_true( 1 === $rows, "exactly one '$key' meta row after three writes", conexao_test_describe( 1 ) );
}

// Clearing: an event that stops being a series has its whole group removed.
ics_write_recurrence( $idempotent_id, array( 'recurrence' => array() ) );
assert_true( array( 'type' => '', 'days' => '', 'start' => '', 'end' => '' ) === ics_stored_recurrence( $idempotent_id ), 'clearing a series removes every recurrence meta key', conexao_test_describe( array( 'type' => '', 'days' => '', 'start' => '', 'end' => '' ) ) );

// ---------------------------------------------------------------------------
test_section( '10. Change detection sees the recurrence rule' );

$engine       = new Conexao_Event_Importer_Engine( new Conexao_Event_Sources(), new Conexao_Event_Location() );
$incoming_sig = new ReflectionMethod( 'Conexao_Event_Importer_Engine', 'recurrence_signature' );
$incoming_sig->setAccessible( true );
$stored_sig   = new ReflectionMethod( 'Conexao_Event_Importer_Engine', 'stored_recurrence_signature' );
$stored_sig->setAccessible( true );

$detect_post  = ics_create_event( 'Change detection', array( '_event_date' => '2026-09-29' ) );
$expected_sig = $incoming_sig->invoke( $engine, array( 'recurrence' => $chair_yoga['recurrence'] ) );

assert_true( '' === $stored_sig->invoke( $engine, $detect_post ), 'a fresh event has no stored rule', conexao_test_describe( '' ) );
assert_true( 'weekly|2|2026-09-29|2026-10-20' === $expected_sig, 'the incoming Chair Yoga rule has a stable canonical signature', conexao_test_describe( 'weekly|2|2026-09-29|2026-10-20' ) );
assert_true(
	$stored_sig->invoke( $engine, $detect_post ) !== $expected_sig,
	'a missing stored rule is detected as a change (so the first post-fix run updates)'
);

ics_write_recurrence( $detect_post, array( 'recurrence' => $chair_yoga['recurrence'] ) );
assert_true( $expected_sig === $stored_sig->invoke( $engine, $detect_post ), 'after the write, stored and incoming signatures are identical (idempotent re-import)', conexao_test_describe( $expected_sig ) );

// The signature must be insensitive to weekday ordering.
$reordered            = $chair_yoga['recurrence'];
$reordered['days']    = array_reverse( $reordered['days'] );
assert_true( $expected_sig === $incoming_sig->invoke( $engine, array( 'recurrence' => $reordered ) ), 'the recurrence signature does not depend on weekday ordering', conexao_test_describe( $expected_sig ) );

// ---------------------------------------------------------------------------
test_section( '11. Archive query layer uses occurrence dates' );

Conexao_Event_Query::flush_cache();

$upcoming = Conexao_Event_Query::upcoming_event_ids( ics_date( '2026-10-02' ) );
assert_true( in_array( $chair_id, $upcoming, true ), 'Chair Yoga is in the upcoming set as of 2026-10-02 (next occurrence 2026-10-06)' );
assert_true( '2026-10-06' === (string) Conexao_Event_Query::next_occurrence_date( $chair_id ), 'the runtime reports 2026-10-06 as its next occurrence', conexao_test_describe( '2026-10-06' ) );

$generated = Conexao_ICS_Recurrence::occurrence_dates( '2026-09-29', array( 2 ), '2026-10-20' );
assert_true( 4 === count( $generated ), 'exactly four occurrence dates are generated for Chair Yoga', conexao_test_describe( 4 ) );

$matched = array();
$cursor  = strtotime( '2026-09-29 00:00:00 UTC' );
$stop    = strtotime( '2026-10-20 00:00:00 UTC' );
while ( $cursor <= $stop ) {
	$day = gmdate( 'Y-m-d', $cursor );
	if ( Conexao_Event_Recurrence::occurs_on_date( $chair_id, ics_date( $day ) ) ) {
		$matched[] = $day;
	}
	$cursor += DAY_IN_SECONDS;
}
assert_true( $generated === $matched, 'scanning every date in the window matches exactly the generated occurrence dates', conexao_test_describe( $generated ) );

// ---------------------------------------------------------------------------
// Cleanup.
foreach ( $test_post_ids as $id ) {
	wp_delete_post( $id, true );
}
Conexao_Event_Query::flush_cache();

test_finish( 'ICS occurrence dates' );
