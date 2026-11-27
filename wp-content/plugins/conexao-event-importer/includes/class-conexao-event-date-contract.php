<?php
/**
 * Date-only write contract for `_event_date` and `_event_end_date` (Stage 4).
 *
 * ## Why this exists
 *
 * `_event_date` and `_event_end_date` are contractually date-only `Y-m-d`
 * values: the event runtime's recurrence evaluator validates exactly that
 * shape, and the data model registers the meta as type `date`. Stage 3.5
 * found 20 CI-fixture rows carrying a full `Y-m-d H:i:s` datetime because the
 * fixture seeder wrote one directly, bypassing the importer normalizer.
 *
 * The importer's own `normalize_date()` can only emit `Y-m-d`, so the engine
 * write path is safe by construction. This class is the DEFENSIVE boundary
 * guard: it proves that invariant at the single point where the engine
 * persists the two date fields, so a future writer (a new normalizer, a
 * hand-built payload, a regression) can never silently persist a
 * datetime-shaped value. It is a write-path guard, not a read-time migration:
 * already-stored values are never rewritten here.
 *
 * ## Semantics
 *
 * `date_only( $value )` deterministically returns the date-only `Y-m-d`
 * portion of a valid datetime-shaped input (the leading date segment), or an
 * empty string when the value is malformed or ambiguous. A valid `Y-m-d`
 * value passes through unchanged. The caller decides what an empty return
 * means for the write (the engine treats it as "no value to persist").
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Date-only write contract for the event date meta fields.
 */
class Conexao_Event_Date_Contract {

	const DATE_ONLY_PATTERN = '/^(\d{4}-\d{2}-\d{2})$/';

	/**
	 * The leading date segment of an ISO-like datetime, if present.
	 *
	 * Matches `Y-m-d` followed by a time component (`T` or space separator),
	 * e.g. `2026-11-13 00:00:00` or `2026-11-13T00:00:00`.
	 */
	const DATETIME_LEADING_DATE_PATTERN = '/^(\d{4}-\d{2}-\d{2})[T ]\d{2}:\d{2}/';

	/**
	 * Reduce a value to a date-only `Y-m-d` string.
	 *
	 * - A value already in `Y-m-d` form is returned unchanged.
	 * - A valid datetime-shaped value (`Y-m-d` + `HH:MM` separated by `T` or a
	 *   space) is deterministically reduced to its leading `Y-m-d` segment.
	 * - Anything else (empty, malformed, ambiguous) returns an empty string.
	 *
	 * The reduction is a pure string operation on the leading date segment; it
	 * performs no timezone conversion, so it follows the codebase's
	 * timezone-less local-date semantics for `_event_date`.
	 *
	 * @param mixed $value Raw value.
	 * @return string `Y-m-d` or empty string.
	 */
	public static function date_only( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( self::DATE_ONLY_PATTERN, $value, $m ) ) {
			return self::is_real_date( $m[1] ) ? $m[1] : '';
		}

		if ( preg_match( self::DATETIME_LEADING_DATE_PATTERN, $value, $m ) ) {
			return self::is_real_date( $m[1] ) ? $m[1] : '';
		}

		return '';
	}

	/**
	 * Whether a `Y-m-d` string is a real calendar date.
	 *
	 * @param string $date Date string.
	 * @return bool
	 */
	private static function is_real_date( $date ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * Whether a value is a valid date-only `Y-m-d` calendar date.
	 *
	 * @param mixed $value Value to check.
	 * @return bool
	 */
	public static function is_date_only( $value ) {
		$value = (string) $value;
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}
}
