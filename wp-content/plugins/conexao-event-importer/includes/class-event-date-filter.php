<?php
/**
 * Past-event date filter for the import pipeline.
 *
 * Evaluates a normalized event's scheduling fields (start_date, start_time,
 * end_date, end_time — the same values stored as _event_date,
 * _event_start_time, _event_end_date, _event_end_time) against the site's
 * current date/time so the importer never creates or updates events that
 * have already ended.
 *
 * Comparison rules:
 *  - Multi-day events use the end date/time as the cutoff when available,
 *    so an event running 20–27 Aug still imports on 25 Aug.
 *  - Events with only a start date/time are imported while that moment is
 *    current or future, and skipped once it has passed. When no time is
 *    provided the event is treated as running all day (end-of-day cutoff),
 *    matching Conexao_Event_Status expiry logic.
 *  - Missing or unparseable dates never count as future events; they are
 *    reported as invalid so the importer can skip them safely.
 *
 * All comparisons are timezone-aware and use the WordPress-configured
 * timezone via current_datetime()/wp_timezone() — never raw string compares.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Event_Date_Filter {

	const IMPORT  = 'import';
	const PAST    = 'past';
	const INVALID = 'invalid';

	/**
	 * Evaluate a normalized event against the current site date/time.
	 *
	 * @param array $event Normalized event array (expects the start_date,
	 *                     start_time, end_date and end_time keys).
	 * @return array{status: string, cutoff_label: string} `status` is one of
	 *         `Self::IMPORT`, `Self::PAST` or `Self::INVALID`. `cutoff_label`
	 *         is the human-readable cutoff instant used for the comparison,
	 *         formatted 'Y-m-d H:i'; empty when invalid.
	 */
	public static function evaluate( $event ) {
		$now = current_datetime();

		$start_date = isset( $event['start_date'] ) ? trim( (string) $event['start_date'] ) : '';
		$start_time = isset( $event['start_time'] ) ? trim( (string) $event['start_time'] ) : '';
		$end_date   = isset( $event['end_date'] ) ? trim( (string) $event['end_date'] ) : '';
		$end_time   = isset( $event['end_time'] ) ? trim( (string) $event['end_time'] ) : '';

		// The start date is required and must be a real calendar date.
		if ( ! self::is_valid_date( $start_date ) ) {
			return array(
				'status'       => self::INVALID,
				'cutoff_label' => '',
			);
		}

		// Multi-day events: the end date is the relevant cutoff when it is
		// present and valid. Otherwise fall back to the start date/time.
		$use_end     = self::is_valid_date( $end_date );
		$cutoff_date = $use_end ? $end_date : $start_date;
		$cutoff_time = '';

		if ( $use_end && self::is_valid_time( $end_time ) ) {
			$cutoff_time = $end_time;
		} elseif ( ! $use_end && self::is_valid_time( $start_time ) ) {
			$cutoff_time = $start_time;
		}

		try {
			$cutoff = new DateTimeImmutable(
				$cutoff_date . ( '' !== $cutoff_time ? ' ' . $cutoff_time : '' ),
				wp_timezone()
			);

			if ( '' === $cutoff_time ) {
				// No usable time: treat the event as running all day, so it
				// only counts as ended once its final day is over. This
				// mirrors Conexao_Event_Status::get_event_end_timestamp().
				$cutoff = $cutoff->setTime( 23, 59, 59 );
			}
		} catch ( Exception $e ) {
			return array(
				'status'       => self::INVALID,
				'cutoff_label' => '',
			);
		}

		$label = $cutoff->format( 'Y-m-d H:i' );

		if ( $cutoff < $now ) {
			return array(
				'status'       => self::PAST,
				'cutoff_label' => $label,
			);
		}

		return array(
			'status'       => self::IMPORT,
			'cutoff_label' => $label,
		);
	}

	/**
	 * Whether a value is a valid Y-m-d calendar date.
	 *
	 * Rejects malformed strings and impossible dates such as 2026-02-30.
	 *
	 * @param string $value Value to check.
	 * @return bool
	 */
	protected static function is_valid_date( $value ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $value, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * Whether a value is a valid H:i (or H:i:s) time.
	 *
	 * @param string $value Value to check.
	 * @return bool
	 */
	protected static function is_valid_time( $value ) {
		return (bool) preg_match( '/^\d{1,2}:\d{2}(:\d{2})?$/', (string) $value );
	}
}