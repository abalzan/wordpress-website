<?php
/**
 * Event recurrence model + evaluator (internal, runtime).
 *
 * Internal recurrence layer for the `event` post type. Step 1 scope: data
 * model + evaluator only. Nothing here is wired to the admin UI, the event
 * archive queries, the frontend templates, or the importer.
 *
 * Meta keys:
 *   _event_recurrence       '' (one-time) or 'weekly'
 *   _event_recurrence_days  CSV of ISO weekdays: 1 (Mon) ... 7 (Sun)
 *   _event_recurrence_start Y-m-d, optional; falls back to _event_date
 *   _event_recurrence_end   Y-m-d, optional; blank/invalid = open-ended
 *
 * All comparisons run on local calendar dates in the WordPress-configured
 * timezone (wp_timezone()) via DateTimeImmutable - no UTC timestamps, no
 * bare strtotime(), no date()+time().
 *
 * @package Conexao_Event_Runtime
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Event_Recurrence {

	const TYPE_WEEKLY = 'weekly';

	/**
	 * Whether the event occurs on the given calendar date.
	 *
	 * Events without recurrence metadata (or with an unsupported recurrence
	 * value) keep the legacy one-time comparison: the stored `_event_date`
	 * must equal the requested local calendar date.
	 *
	 * @param int               $post_id Event post ID.
	 * @param DateTimeImmutable $date    Requested instant; evaluated on its
	 *                                   calendar date in the site timezone.
	 * @return bool
	 */
	public static function occurs_on_date( int $post_id, DateTimeImmutable $date ): bool {
		$local      = $date->setTimezone( wp_timezone() );
		$local_date = $local->format( 'Y-m-d' );

		$recurrence = self::recurrence_type( $post_id );
		if ( '' === $recurrence || self::TYPE_WEEKLY !== $recurrence ) {
			return self::one_time_occurs_on_date( $post_id, $local_date );
		}

		$days = self::recurrence_days( $post_id );

		// No valid selected weekdays: defensively treat as a one-time event.
		if ( empty( $days ) ) {
			return self::one_time_occurs_on_date( $post_id, $local_date );
		}

		$start = self::recurrence_start( $post_id );
		if ( null === $start ) {
			return false;
		}

		$end = self::recurrence_end( $post_id );

		if ( $local_date < $start ) {
			return false;
		}
		if ( null !== $end && $local_date > $end ) {
			return false;
		}

		return in_array( (int) $local->format( 'N' ), $days, true );
	}

	/**
	 * Next occurrence on/after the given date, or null.
	 *
	 * One-time events: returns `_event_date` when it is on/after the given
	 * date, otherwise null.
	 *
	 * Weekly events: scans the next 7 calendar days (starting at the given
	 * date, which is included like the one-time "on/after" rule), respecting
	 * the recurrence start/end. A weekly series has at most 7 days between
	 * occurrences, so this window always contains the next occurrence while
	 * the series is active.
	 *
	 * @param int     $post_id Event post ID.
	 * @param DateTimeImmutable $from Starting instant; evaluated on its
	 *                                calendar date in the site timezone.
	 * @return DateTimeImmutable|null Occurrence (midnight, site timezone) or
	 *                                null when the series has ended.
	 */
	public static function next_occurrence( int $post_id, DateTimeImmutable $from ): ?DateTimeImmutable {
		$from_date = $from->setTimezone( wp_timezone() )->format( 'Y-m-d' );

		$recurrence = self::recurrence_type( $post_id );
		if ( '' === $recurrence || self::TYPE_WEEKLY !== $recurrence ) {
			return self::one_time_next_occurrence( $post_id, $from_date );
		}

		$days = self::recurrence_days( $post_id );
		if ( empty( $days ) ) {
			return self::one_time_next_occurrence( $post_id, $from_date );
		}

		$start = self::recurrence_start( $post_id );
		if ( null === $start ) {
			return null;
		}

		$end = self::recurrence_end( $post_id );

		$cursor = new DateTimeImmutable( $from_date, wp_timezone() );

		for ( $i = 0; $i < 7; $i++ ) {
			$cursor_date = $cursor->format( 'Y-m-d' );

			if ( $cursor_date < $start ) {
				$cursor = $cursor->modify( '+1 day' );
				continue;
			}

			if ( null !== $end && $cursor_date > $end ) {
				return null;
			}

			if ( in_array( (int) $cursor->format( 'N' ), $days, true ) ) {
				return $cursor;
			}

			$cursor = $cursor->modify( '+1 day' );
		}

		return null;
	}
	/**
	 * Normalized recurrence type for an event (trimmed string).
	 *
	 * Public so theme/template code can check recurrence state without
	 * duplicating the read logic. Returns '' for one-time or unknown types.
	 *
	 * @param int $post_id Event post ID.
	 * @return string
	 */
	public static function recurrence_type( $post_id ) {
		$value = get_post_meta( $post_id, '_event_recurrence', true );
		if ( ! is_string( $value ) ) {
			return '';
		}
		return trim( $value );
	}

	/**
	 * Parse the recurrence weekdays into a list of ISO weekday numbers
	 * (1 = Monday ... 7 = Sunday). Invalid/empty CSV entries are discarded.
	 *
	 * Public so theme/template code can build presentation labels without
	 * re-implementing the CSV parsing.
	 *
	 * @param int $post_id Event post ID.
	 * @return int[]
	 */
	public static function recurrence_days( $post_id ) {
		$value = get_post_meta( $post_id, '_event_recurrence_days', true );
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return array();
		}

		$days = array();
		foreach ( preg_split( '/\s*,\s*/', trim( $value ) ) as $token ) {
			if ( preg_match( '/^[1-7]$/', $token ) ) {
				$days[ (int) $token ] = true;
			}
		}
		return array_keys( $days );
	}

	/**
	 * Recurrence start as a validated Y-m-d string, or null when invalid.
	 *
	 * Falls back to `_event_date` when `_event_recurrence_start` is blank.
	 * A present-but-invalid start is treated as invalid (null) per the
	 * defensive-data design.
	 *
	 * @param int $post_id Event post ID.
	 * @return string|null
	 */
	private static function recurrence_start( $post_id ) {
		$value = get_post_meta( $post_id, '_event_recurrence_start', true );
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			$value = get_post_meta( $post_id, '_event_date', true );
		}
		if ( ! is_string( $value ) ) {
			$value = '';
		}
		$value = trim( $value );

		if ( ! self::is_valid_date( $value ) ) {
			return null;
		}
		return $value;
	}

	/**
	 * Recurrence end as a validated Y-m-d string, or null when blank/invalid
	 * (blank = open-ended; invalid is defensively treated as open-ended).
	 *
	 * Public so theme/template code can display the series end date without
	 * re-implementing the validation.
	 *
	 * @param int $post_id Event post ID.
	 * @return string|null
	 */
	public static function recurrence_end( $post_id ) {
		$value = get_post_meta( $post_id, '_event_recurrence_end', true );
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}
		$value = trim( $value );

		if ( ! self::is_valid_date( $value ) ) {
			return null;
		}
		return $value;
	}

	/**
	 * One-time date comparison on the local calendar date.
	 *
	 * When `_event_end_date` is absent or invalid, this keeps the legacy
	 * exact-match behavior (active only on `_event_date`). When a valid end
	 * date is present, the event is active for every calendar day in the
	 * inclusive [start, end] range — so a multi-day event stays visible on
	 * each day it is running, not only on day 1.
	 *
	 * @param int    $post_id    Event post ID.
	 * @param string $local_date Local date in Y-m-d format.
	 * @return bool
	 */
	private static function one_time_occurs_on_date( $post_id, $local_date ) {
		$event_date = (string) get_post_meta( $post_id, '_event_date', true );
		$end_date   = get_post_meta( $post_id, '_event_end_date', true );
		if ( ! is_string( $end_date ) || ! self::is_valid_date( $end_date ) ) {
			return $event_date === $local_date;
		}
		return $event_date <= $local_date && $local_date <= $end_date;
	}

	/**
	 * One-time next-occurrence logic.
	 *
	 * No end date (legacy one-day): returns `_event_date` when it is on/after
	 * `$from_date`, otherwise null.
	 *
	 * With a valid end date (multi-day): returns null only when the event has
	 * already ended before `$from_date`; returns `$from_date` itself when the
	 * event is currently active (so it sorts among today's events); returns
	 * `_event_date` when the event has not started yet.
	 *
	 * @param int    $post_id   Event post ID.
	 * @param string $from_date Local start date in Y-m-d format.
	 * @return DateTimeImmutable|null
	 */
	private static function one_time_next_occurrence( $post_id, $from_date ) {
		$event_date = (string) get_post_meta( $post_id, '_event_date', true );
		if ( ! self::is_valid_date( $event_date ) ) {
			return null;
		}
		$end_date = get_post_meta( $post_id, '_event_end_date', true );
		if ( is_string( $end_date ) && self::is_valid_date( $end_date ) ) {
			if ( $end_date < $from_date ) {
				return null;
			}
			if ( $event_date > $from_date ) {
				return new DateTimeImmutable( $event_date, wp_timezone() );
			}
			return new DateTimeImmutable( $from_date, wp_timezone() );
		}
		if ( $event_date < $from_date ) {
			return null;
		}
		return new DateTimeImmutable( $event_date, wp_timezone() );
	}

	/**
	 * Whether a string is a real calendar date in Y-m-d format.
	 *
	 * @param mixed $value Value to check.
	 * @return bool
	 */
	private static function is_valid_date( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}
}
