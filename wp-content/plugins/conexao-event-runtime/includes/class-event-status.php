<?php
/**
 * Event status management (production runtime).
 *
 * Owns the `_event_status` semantics shared by the public visibility gate,
 * the admin status UI (Conexão Admin UX consumes this class via
 * class_exists()) and the local import tooling (expiry marking, cleanup).
 *
 * @package Conexao_Event_Runtime
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Event_Status {

	const DRAFT            = 'draft';
	const PUBLISHED        = 'published';
	const SOURCE_NOT_FOUND = 'source_not_found';
	const EXPIRED          = 'expired';
	const REJECTED         = 'rejected';

	/**
	 * Get all supported statuses with labels.
	 *
	 * @return array
	 */
	public static function get_statuses() {
		return array(
			self::DRAFT            => __( 'Draft', 'conexao-event-runtime' ),
			self::PUBLISHED        => __( 'Published', 'conexao-event-runtime' ),
			self::SOURCE_NOT_FOUND => __( 'Source Not Found', 'conexao-event-runtime' ),
			self::EXPIRED          => __( 'Expired', 'conexao-event-runtime' ),
			self::REJECTED         => __( 'Rejected', 'conexao-event-runtime' ),
		);
	}

	/**
	 * Persist the event status on a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $status  Status key.
	 */
	public static function set_status( $post_id, $status ) {
		$statuses = self::get_statuses();
		if ( ! isset( $statuses[ $status ] ) ) {
			return;
		}
		update_post_meta( $post_id, '_event_status', $status );
	}

	/**
	 * Get the current status of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_status( $post_id ) {
		$status = get_post_meta( $post_id, '_event_status', true );
		if ( empty( $status ) ) {
			return self::PUBLISHED; // Legacy events without a status are live.
		}
		return $status;
	}

	/**
	 * Automatically mark past events as expired.
	 *
	 * Handles two cases:
	 *  1. Legacy events without any status whose date has passed.
	 *  2. PUBLISHED events whose end date/time has passed — these previously
	 *     stayed publicly visible until the weekly cleanup deleted them.
	 *
	 * Triggered by the local import tooling after each import run (no cron —
	 * importing and cleanup are manual, local-only operations), so historical
	 * data is preserved but expired events stop appearing on public pages
	 * immediately after they end.
	 */
	public static function mark_expired_events() {
		$expired = 0;

		// 1. Legacy events with no status at all.
		$legacy_query = new WP_Query(
			array(
				'post_type'      => 'event',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => '_event_date',
						'value'   => current_time( 'Y-m-d' ),
						'compare' => '<',
						'type'    => 'DATE',
					),
					array(
						'key'     => '_event_status',
						'compare' => 'NOT EXISTS',
					),
				),
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		foreach ( $legacy_query->posts as $post_id ) {
			update_post_meta( $post_id, '_event_status', self::EXPIRED );
			$expired++;
		}

		// 2. Published events whose end date/time has passed.
		$published_query = new WP_Query(
			array(
				'post_type'      => 'event',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => '_event_status',
						'value'   => self::PUBLISHED,
					),
					array(
						'key'     => '_event_date',
						'value'   => current_time( 'Y-m-d' ),
						'compare' => '<=',
						'type'    => 'DATE',
					),
				),
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$now_ts = time();

		foreach ( $published_query->posts as $post_id ) {
			$end_timestamp = self::get_event_end_timestamp( $post_id );

			if ( $end_timestamp && $end_timestamp < $now_ts ) {
				update_post_meta( $post_id, '_event_status', self::EXPIRED );
				$expired++;
			}
		}

		return $expired;
	}

	/**
	 * Get the end timestamp for an event in the site timezone.
	 *
	 * Uses _event_end_date + _event_end_time when available. Falls back to
	 * _event_date + _event_start_time (or _event_time) when no end date exists.
	 *
	 * Shared by the expiry check and the weekly cleanup so both use identical
	 * "has this event ended?" logic.
	 *
	 * @param int $post_id Event post ID.
	 * @return int|null Unix timestamp or null when the date is invalid/missing.
	 */
	public static function get_event_end_timestamp( $post_id ) {
		$end_date = get_post_meta( $post_id, '_event_end_date', true );
		$end_time = get_post_meta( $post_id, '_event_end_time', true );

		// Fall back to the event date when no end date is set.
		if ( empty( $end_date ) ) {
			$end_date = get_post_meta( $post_id, '_event_date', true );
			$end_time = get_post_meta( $post_id, '_event_start_time', true );

			// Fall back to the combined _event_time field if start time is empty.
			if ( empty( $end_time ) ) {
				$event_time = get_post_meta( $post_id, '_event_time', true );
				if ( $event_time ) {
					// _event_time may be "10:00 — 12:00"; use the first part.
					$parts = explode( '—', $event_time );
					$end_time = trim( $parts[0] );
				}
			}
		}

		if ( empty( $end_date ) ) {
			return null;
		}

		// Validate the date format (Y-m-d).
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date ) ) {
			return null;
		}

		$timezone = wp_timezone();

		try {
			$date = new DateTime( $end_date, $timezone );

			if ( ! empty( $end_time ) && preg_match( '/^(\d{1,2}):(\d{2})$/', $end_time, $m ) ) {
				$date->setTime( (int) $m[1], (int) $m[2] );
			} else {
				// No valid time: treat the event as ending at the end of the day.
				$date->setTime( 23, 59, 59 );
			}

			return $date->getTimestamp();
		} catch ( Exception $e ) {
			return null;
		}
	}
}
