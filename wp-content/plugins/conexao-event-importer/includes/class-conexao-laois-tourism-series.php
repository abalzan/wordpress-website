<?php
/**
 * Laois Tourism ICS series adapter.
 *
 * The Laois Tourism feed (https://laoistourism.ie/events/?ical=1, produced by
 * "Eventive"/ECP, PRODID "-//Laois Tourism - ECPv6.18.0//NONSGML v1.0//EN")
 * publishes NO RRULE anywhere in its VEVENTs — measured across the whole live
 * feed: 30 VEVENTs, 0 RRULE, 0 EXDATE, 0 RDATE, 0 X- recurrence properties.
 * A multi-week class or course is instead encoded as a SINGLE VEVENT whose
 *
 *     DTSTART = first occurrence
 *     DTEND   = end of the LAST occurrence (not start + duration)
 *
 * Read literally, that pair is indistinguishable from one continuously
 * running event spanning the whole window, which is why "Chair Yoga At
 * Portlaoise Library" (29 Sep - 20 Oct 2026) used to be shown on every day in
 * between instead of on its four weekly occurrence dates.
 *
 * WHY THIS RULE IS SAFE (measured, not assumed)
 * ----------------------------------------------
 * Across all 30 VEVENTs of the live feed, the multi-week timed events are
 * exactly the five weekly programmes, and each one satisfies ALL of:
 *
 *   1. it is a TIMED event (VALUE=DATE all-day events are excluded outright);
 *   2. weekday(DTSTART.date) === weekday(DTEND.date)  (same weekday);
 *   3. the day span is an exact positive multiple of 7;
 *   4. the leftover time-of-day delta is strictly positive and < 1 day,
 *      i.e. DTEND's clock time is a plausible END time for one single session
 *      that starts at DTSTART's clock time (the session duration).
 *
 * A genuine multi-day event fails these tests: the fixture's "Imposter Art
 * Exhibition" is all-day (excluded by 1), and a real timed multi-day event
 * would not land on the same weekday with a clean whole-week span plus a
 * one-session time delta. The rule is therefore a structural fingerprint, not
 * a title heuristic, and it reproduces the occurrence counts the source itself
 * states in the DESCRIPTION ("four-week" Chair Yoga -> 4, "6-week" Bootcamp ->
 * 6, "seven-week" workshop -> 7).
 *
 * SCOPE
 * -----
 * This class is deliberately the ONLY place that knows about this convention.
 * It is wired in exclusively for the `laois_tourism` source id (see
 * Conexao_Source_ICalendar::resolve_series_adapter()), so standard ICS
 * DTSTART/DTEND semantics are left untouched for every other feed.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Laois Tourism collapsed weekly-series detector.
 *
 * @see Conexao_Laois_Tourism_Series (file header) for the measured basis of
 *      the convention this class recognises.
 *
 * @package Conexao_Event_Importer
 */
class Conexao_Laois_Tourism_Series {

	/** Source id this adapter is allowed to act on. */
	const SOURCE_ID = 'laois_tourism';

	/**
	 * Longest series (in occurrences) this adapter will materialise.
	 *
	 * A defensive ceiling: a malformed feed must never be able to expand into
	 * an unbounded number of occurrence dates. 200 weekly occurrences is ~3.8
	 * years, far beyond anything the source publishes.
	 */
	const MAX_OCCURRENCES = 200;

	/**
	 * Whether this adapter may process events from the given source.
	 *
	 * @param string $source_id Source slug.
	 * @return bool
	 */
	public static function handles_source( $source_id ) {
		return self::SOURCE_ID === (string) $source_id;
	}

	/**
	 * Detect a collapsed weekly series and return its recurrence description.
	 *
	 * @param array $event Parsed event array (the ICS source's output), using
	 *                     the already-parsed start_date/start_time/end_date/
	 *                     end_time plus the raw `all_day` flag.
	 * @return array{type:string,interval:int,days:array<int>,start:string,end:string,count:int,source_rule:string,occurrences:array<int,string>}|null
	 *         Null when the event is NOT a collapsed weekly series (the caller
	 *         then keeps the literal DTSTART/DTEND reading).
	 */
	public static function detect( array $event ) {
		// (1) All-day events never take part in this convention.
		if ( ! empty( $event['all_day'] ) ) {
			return null;
		}

		$start = Conexao_ICS_Recurrence::normalize_date(
			isset( $event['start_date'] ) ? (string) $event['start_date'] : ''
		);
		$end   = Conexao_ICS_Recurrence::normalize_date(
			isset( $event['end_date'] ) ? (string) $event['end_date'] : ''
		);

		if ( null === $start || null === $end ) {
			return null;
		}

		// The convention is about TIMED occurrences, so both clock times must
		// be present and parseable.
		$start_seconds = self::seconds_of_day( isset( $event['start_time'] ) ? $event['start_time'] : '' );
		$end_seconds   = self::seconds_of_day( isset( $event['end_time'] ) ? $event['end_time'] : '' );
		if ( null === $start_seconds || null === $end_seconds ) {
			return null;
		}

		// (2) Same weekday on both ends.
		$start_weekday = Conexao_ICS_Recurrence::weekday_of( $start );
		$end_weekday   = Conexao_ICS_Recurrence::weekday_of( $end );
		if ( $start_weekday !== $end_weekday ) {
			return null;
		}

		// (3) Exact positive whole-week day span. Computed on calendar dates
		// (not timestamps), so a DST transition inside the window cannot shift
		// the span by an hour and break the test.
		$day_span = self::calendar_days_between( $start, $end );
		if ( $day_span <= 0 || 0 !== $day_span % 7 ) {
			return null;
		}

		// (4) Positive, less-than-one-day time delta == one session duration.
		$remainder_seconds = $end_seconds - $start_seconds;
		if ( $remainder_seconds <= 0 || $remainder_seconds >= DAY_IN_SECONDS ) {
			return null;
		}

		$occurrences = self::occurrence_dates( $start, $day_span );
		if ( empty( $occurrences ) || count( $occurrences ) > self::MAX_OCCURRENCES ) {
			return null;
		}

		return array(
			'type'        => 'weekly',
			'interval'    => 1,
			'days'        => array( $start_weekday ),
			'start'       => $start,
			'end'         => end( $occurrences ),
			'count'       => count( $occurrences ),
			'source_rule' => 'laois-collapsed-weekly',
			'occurrences' => $occurrences,
		);
	}

	/**
	 * Materialise the weekly occurrence dates for a detected series.
	 *
	 * Pure date arithmetic in UTC: these are calendar values, never instants,
	 * so the result is unaffected by DST in any timezone.
	 *
	 * @param string $start    Series start date (Y-m-d).
	 * @param int    $day_span Whole-week day span between the first and the
	 *                         last occurrence date.
	 * @return string[] Occurrence dates (Y-m-d), ascending.
	 */
	private static function occurrence_dates( $start, $day_span ) {
		$weeks       = intdiv( $day_span, 7 );
		$first       = strtotime( $start . ' 00:00:00 UTC' );
		$occurrences = array();

		if ( false === $first ) {
			return $occurrences;
		}

		for ( $i = 0; $i <= $weeks; $i++ ) {
			$occurrences[] = gmdate( 'Y-m-d', $first + ( $i * 7 * DAY_IN_SECONDS ) );
		}

		return $occurrences;
	}

	/**
	 * Whole calendar days between two Y-m-d dates (end - start).
	 *
	 * @param string $start Y-m-d.
	 * @param string $end   Y-m-d.
	 * @return int
	 */
	private static function calendar_days_between( $start, $end ) {
		$from = strtotime( $start . ' 00:00:00 UTC' );
		$to   = strtotime( $end . ' 00:00:00 UTC' );

		if ( false === $from || false === $to ) {
			return 0;
		}

		return (int) round( ( $to - $from ) / DAY_IN_SECONDS );
	}

	/**
	 * Seconds since midnight for an H:i / H:i:s clock time.
	 *
	 * @param string $time Clock time.
	 * @return int|null Null when the value is not a valid clock time.
	 */
	private static function seconds_of_day( $time ) {
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim( (string) $time ), $m ) ) {
			return null;
		}

		$hours   = (int) $m[1];
		$minutes = (int) $m[2];
		$seconds = isset( $m[3] ) ? (int) $m[3] : 0;

		if ( $hours > 23 || $minutes > 59 || $seconds > 59 ) {
			return null;
		}

		return ( $hours * 3600 ) + ( $minutes * 60 ) + $seconds;
	}
}
