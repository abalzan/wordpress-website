<?php
/**
 * ICS recurrence rule (RRULE) reader.
 *
 * A small, dependency-free reader for the subset of RFC 5545 RRULE that maps
 * cleanly onto the event data model that already exists in this repository
 * (`_event_recurrence = 'weekly'` + `_event_recurrence_days` + a bounded
 * `_event_recurrence_start` / `_event_recurrence_end` window).
 *
 * It is deliberately NOT a general recurrence expander: it returns the
 * recurrence DESCRIPTION (weekday set + bounded window), never a materialised
 * list of dates. The single evaluator in `Conexao_Event_Recurrence`
 * (conexao-event-runtime) remains the one place that answers "does this event
 * occur on date X", so no second recurrence engine is introduced here.
 *
 * Scope:
 *  - FREQ=WEEKLY (the only frequency the data model expresses).
 *  - INTERVAL (default 1; only INTERVAL=1 maps to "weekly on these weekdays",
 *    because a 2-week cadence is not representable without a new field).
 *  - COUNT (number of occurrences) and UNTIL (inclusive end date).
 *  - BYDAY (e.g. "MO,WE") — the canonical weekly expansion.
 *
 * Anything outside that subset (FREQ=DAILY/MONTHLY/YEARLY, INTERVAL>1,
 * ordinal BYDAY tokens such as "2MO") returns null. Null means "not
 * representable" and the caller must leave the event one-time rather than
 * guess — silent degradation is safe, because the stored `_event_date` /
 * `_event_end_date` still describe a valid single event.
 *
 * No UTC conversion is performed anywhere in this class: an ICS value is read
 * as the calendar date a calendar application displays, so TZID-bearing local
 * wall-clock values never shift across a day boundary.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reader for the representable subset of the RFC 5545 RRULE grammar.
 *
 * @see Conexao_ICS_Recurrence (file header) for the supported subset and the
 *      deliberate refusal policy for everything outside it.
 *
 * @package Conexao_Event_Importer
 */
class Conexao_ICS_Recurrence {

	/** Supported RRULE frequency. */
	const FREQ_WEEKLY = 'WEEKLY';

	/**
	 * BYDAY token => ISO weekday number (1 = Monday ... 7 = Sunday).
	 *
	 * Only bare weekday tokens are supported. RFC 5545 also allows an ordinal
	 * prefix ("2MO" = second Monday), meaningless in a weekly rule, so those
	 * are rejected rather than silently truncated to "MO".
	 *
	 * @var array<string,int>
	 */
	private static $weekday_tokens = array(
		'MO' => 1,
		'TU' => 2,
		'WE' => 3,
		'TH' => 4,
		'FR' => 5,
		'SA' => 6,
		'SU' => 7,
	);

	/**
	 * Parse an RRULE value into a recurrence description.
	 *
	 * @param string $rrule     Raw RRULE value, with or without the "RRULE:"
	 *                          property name prefix.
	 * @param string $start_date DTSTART calendar date in Y-m-d form.
	 * @param string $end_date   DTEND calendar date in Y-m-d form, used as the
	 *                           series end when the rule carries neither COUNT
	 *                           nor UNTIL.
	 * @return array{interval:int,days:int[],end:string,count:int,end_source:string}|null
	 *         Null when the rule is not representable in the event data model.
	 */
	public static function parse( $rrule, $start_date, $end_date = '' ) {
		$rrule = self::strip_property_name( (string) $rrule );

		if ( '' === $rrule ) {
			return null;
		}

		$parts = array();
		foreach ( explode( ';', $rrule ) as $pair ) {
			if ( false === strpos( $pair, '=' ) ) {
				continue;
			}
			list( $key, $value )                 = explode( '=', $pair, 2 );
			$parts[ strtoupper( trim( $key ) ) ] = strtoupper( trim( $value ) );
		}

		if ( ( isset( $parts['FREQ'] ) ? $parts['FREQ'] : '' ) !== self::FREQ_WEEKLY ) {
			return null;
		}

		// A multi-week cadence (INTERVAL>1) is not expressible: the data model
		// stores "weekly on these weekdays". Refuse rather than approximate.
		$interval = isset( $parts['INTERVAL'] ) ? (int) $parts['INTERVAL'] : 1;
		if ( 1 !== $interval ) {
			return null;
		}

		$days = self::parse_byday( isset( $parts['BYDAY'] ) ? $parts['BYDAY'] : '' );
		if ( null === $days ) {
			return null;
		}

		$start = self::normalize_date( $start_date );
		if ( null === $start ) {
			return null;
		}

		// No BYDAY: RFC 5545 defaults to the weekday of DTSTART.
		if ( empty( $days ) ) {
			$weekday = self::weekday_of( $start );
			if ( null === $weekday ) {
				return null;
			}
			$days = array( $weekday );
		}
		// UNTIL is inclusive per RFC 5545 3.3.10. It may be a DATE or a
		// DATE-TIME; only the calendar-date part is used, so a DTSTART/DTEND
		// pair never shifts across a day boundary through timezone conversion.
		if ( isset( $parts['UNTIL'] ) ) {
			$until = self::normalize_date( $parts['UNTIL'] );
			if ( null === $until ) {
				return null;
			}
			$end        = $until;
			$end_source = 'UNTIL';
			$count      = 0;
		} elseif ( isset( $parts['COUNT'] ) && preg_match( '/^\d+$/', $parts['COUNT'] ) && (int) $parts['COUNT'] > 0 ) {
			// COUNT gives a count, not a date. The occurrence count is carried
			// through and the caller (which owns the date model) derives the
			// last occurrence date from it.
			$count      = (int) $parts['COUNT'];
			$end_source = 'COUNT';
			$end        = self::normalize_date( $end_date );
			if ( null === $end ) {
				$end = $start;
			}
		} else {
			$count      = 0;
			$end_source = 'DTEND';
			$end        = self::normalize_date( $end_date );
			if ( null === $end ) {
				$end = $start;
			}
		}

		if ( $end < $start ) {
			$end = $start;
		}

		sort( $days );

		return array(
			'interval'   => $interval,
			'days'       => $days,
			'end'        => $end,
			'count'      => $count,
			'end_source' => $end_source,
		);
	}

	/**
	 * Parse a BYDAY value into ISO weekday numbers.
	 *
	 * @param string $byday Comma-separated BYDAY tokens (may be empty).
	 * @return int[]|null Sorted unique ISO weekdays, or null when a token is
	 *                    not representable (e.g. an ordinal "2MO").
	 */
	public static function parse_byday( $byday ) {
		$byday = trim( (string) $byday );
		if ( '' === $byday ) {
			return array();
		}

		$days = array();
		foreach ( explode( ',', $byday ) as $token ) {
			$token = strtoupper( trim( $token ) );
			if ( ! isset( self::$weekday_tokens[ $token ] ) ) {
				return null;
			}
			$days[ self::$weekday_tokens[ $token ] ] = true;
		}

		$days = array_keys( $days );
		sort( $days );

		return $days;
	}

	/**
	 * ISO weekday number (1 = Monday ... 7 = Sunday) for a date value.
	 *
	 * Evaluated on the calendar date alone (anchored to UTC purely as a
	 * timezone-free weekday calculation, never as a stored instant), so the
	 * result matches the source's own calendar.
	 *
	 * @param string $date Y-m-d (or ICS compact) date.
	 * @return int|null Null when the date is invalid.
	 */
	public static function weekday_of( $date ) {
		$date = self::normalize_date( $date );
		if ( null === $date ) {
			return null;
		}
		return (int) gmdate( 'N', strtotime( $date . ' 00:00:00 UTC' ) );
	}

	/**
	 * Validate and normalize a date value to Y-m-d.
	 *
	 * Accepts Y-m-d and the ICS compact forms YYYYMMDD and YYYYMMDDTHHMMSS(Z),
	 * returning only the calendar-date part.
	 *
	 * @param string $value Raw value.
	 * @return string|null Y-m-d, or null when invalid.
	 */
	public static function normalize_date( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}

		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $value, $m ) ) {
			$candidate = $m[1] . '-' . $m[2] . '-' . $m[3];
		} elseif ( preg_match( '/^(\d{4})(\d{2})(\d{2})/', $value, $m ) ) {
			$candidate = $m[1] . '-' . $m[2] . '-' . $m[3];
		} else {
			return null;
		}

		return self::is_valid_date( $candidate ) ? $candidate : null;
	}

	/**
	 * Materialise the occurrence dates of a weekly rule.
	 *
	 * Every date on or after `$start` whose ISO weekday is in `$days`, up to and
	 * including `$end`. Pure calendar arithmetic anchored to UTC, so the result
	 * is a set of DATES and never drifts across a day boundary because of a
	 * timezone conversion or a DST transition.
	 *
	 * This is a report/verification helper only: the stored model keeps the
	 * window + weekdays, and `Conexao_Event_Recurrence` evaluates them.
	 *
	 * @param string   $start Series start date (Y-m-d).
	 * @param int[]    $days  ISO weekdays (1 = Monday ... 7 = Sunday).
	 * @param string   $end   Inclusive series end date (Y-m-d).
	 * @param int|null $count Optional hard cap on the number of dates.
	 * @return string[] Occurrence dates (Y-m-d), ascending.
	 */
	public static function occurrence_dates( $start, array $days, $end, $count = null ) {
		$start = self::normalize_date( $start );
		$end   = self::normalize_date( $end );

		if ( null === $start || null === $end || empty( $days ) ) {
			return array();
		}

		$days = array_map( 'intval', $days );
		$from = strtotime( $start . ' 00:00:00 UTC' );
		$to   = strtotime( $end . ' 00:00:00 UTC' );

		if ( false === $from || false === $to || $to < $from ) {
			return array();
		}

		$wanted    = array_fill_keys( $days, true );
		$dates     = array();
		$span_days = (int) round( ( $to - $from ) / DAY_IN_SECONDS );

		// Bounded by the window length; a malformed day list can never make
		// this unbounded because the cursor always advances.
		for ( $offset = 0; $offset <= $span_days; $offset++ ) {
			$cursor = gmdate( 'Y-m-d', $from + ( $offset * DAY_IN_SECONDS ) );
			$iso    = (int) gmdate( 'N', $from + ( $offset * DAY_IN_SECONDS ) );

			if ( isset( $wanted[ $iso ] ) ) {
				$dates[] = $cursor;
				if ( null !== $count && count( $dates ) >= (int) $count ) {
					break;
				}
			}
		}

		return $dates;
	}

	/**
	 * Date of the Nth occurrence of a weekly rule counted from a start date.
	 *
	 * Used for COUNT-bounded rules, which express a number of occurrences
	 * rather than an end date.
	 *
	 * @param string $start Series start date (Y-m-d).
	 * @param int[]  $days  ISO weekdays.
	 * @param int    $count Number of occurrences (>= 1).
	 * @return string Y-m-d, or an empty string when it cannot be derived.
	 */
	public static function last_occurrence_date( $start, array $days, $count ) {
		$count = (int) $count;

		if ( $count < 1 || empty( $days ) ) {
			return '';
		}

		$start = self::normalize_date( $start );
		if ( null === $start ) {
			return '';
		}

		// Walk forward at most 7 weeks per occurrence (every weekday set hits
		// at least once per 7-day cycle), which is a hard upper bound.
		$from  = strtotime( $start . ' 00:00:00 UTC' );
		$found = 0;

		for ( $offset = 0; $offset <= 7 * $count; $offset++ ) {
			$iso = (int) gmdate( 'N', $from + ( $offset * DAY_IN_SECONDS ) );

			if ( in_array( $iso, array_map( 'intval', $days ), true ) ) {
				++$found;
				if ( $found === $count ) {
					return gmdate( 'Y-m-d', $from + ( $offset * DAY_IN_SECONDS ) );
				}
			}
		}

		return '';
	}

	/**
	 * Whether a Y-m-d string is a real calendar date.
	 *
	 * @param string $value Y-m-d string.
	 * @return bool
	 */
	private static function is_valid_date( $value ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * Strip an optional "RRULE:" / "RRULE;VALUE=..." property prefix.
	 *
	 * @param string $rrule Raw value.
	 * @return string
	 */
	private static function strip_property_name( $rrule ) {
		if ( preg_match( '/^RRULE[^:]*:(.*)$/i', $rrule, $m ) ) {
			return trim( $m[1] );
		}
		return trim( $rrule );
	}
}
