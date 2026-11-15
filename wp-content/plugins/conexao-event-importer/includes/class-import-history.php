<?php
/**
 * Import history storage and retrieval.
 *
 * Stores structured records of completed import runs, including per-event
 * failure details so administrators can see which events failed and why.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Import_History {

	const OPTION_KEY = 'conexao_event_import_history';

	/**
	 * Prefix of the per-process test store.
	 *
	 * Only used when the shared test harness is loaded (`CONEXAO_TESTS_ROOT`)
	 * and has established a per-process suffix. Never equal to `OPTION_KEY`.
	 *
	 * @var string
	 */
	const TEST_OPTION_PREFIX = 'conexao_event_import_history__test__';

	/**
	 * Resolve the option name this store reads from and writes to.
	 *
	 * Production (no test harness loaded) resolves `OPTION_KEY` exactly as
	 * before; a test process resolves its own per-process row. A harness marker
	 * without a valid suffix THROWS rather than falling back to the operator's
	 * row.
	 *
	 * @throws RuntimeException When the harness is loaded without a valid suffix.
	 * @return string Option name.
	 */
	public static function option_key() {
		if ( ! defined( 'CONEXAO_TESTS_ROOT' ) && ! defined( 'CONEXAO_TEST_OPTION_SUFFIX' ) ) {
			return self::OPTION_KEY;
		}

		$suffix = self::test_option_suffix();

		if ( '' === $suffix ) {
			throw new RuntimeException( 'conexao: operator-store isolation was not established for the import history store.' );
		}

		return self::TEST_OPTION_PREFIX . $suffix;
	}

	/**
	 * Validated per-process test suffix, or '' when absent/malformed.
	 *
	 * @return string
	 */
	protected static function test_option_suffix() {
		if ( ! defined( 'CONEXAO_TEST_OPTION_SUFFIX' ) ) {
			return '';
		}

		$suffix = strtolower( (string) CONEXAO_TEST_OPTION_SUFFIX );

		return preg_match( '/^p[1-9][0-9]{0,9}_[a-f0-9]{16}$/', $suffix ) ? $suffix : '';
	}

	/** @var int Maximum number of history entries retained. */
	const MAX_ENTRIES = 100;

	/**
	 * Record a completed import run.
	 *
	 * @param string $source_id  Source slug.
	 * @param array  $stats      Stats: found, new, updated, unchanged, duplicates, skipped, skipped_past, skipped_invalid_date, errors (failed).
	 * @param string $status     Legacy or lifecycle status.
	 * @param string $message    Optional summary message.
	 * @param array  $extra      Optional extra data: failed_events, fatal_errors, run_id, event_results.
	 * @return array The recorded history entry.
	 */
	public static function record( $source_id, $stats, $status = 'success', $message = '', $extra = array() ) {
		$history = self::get_all();

		$entry = array(
			'time'                 => current_time( 'mysql' ),
			'source'               => sanitize_key( (string) $source_id ),
			'found'                => isset( $stats['found'] ) ? (int) $stats['found'] : 0,
			'new'                  => isset( $stats['new'] ) ? (int) $stats['new'] : 0,
			'updated'              => isset( $stats['updated'] ) ? (int) $stats['updated'] : 0,
			'unchanged'            => isset( $stats['unchanged'] ) ? (int) $stats['unchanged'] : 0,
			'duplicates'           => isset( $stats['duplicates'] ) ? (int) $stats['duplicates'] : 0,
			'skipped'              => isset( $stats['skipped'] ) ? (int) $stats['skipped'] : 0,
			'skipped_past'         => isset( $stats['skipped_past'] ) ? (int) $stats['skipped_past'] : 0,
			'skipped_invalid_date' => isset( $stats['skipped_invalid_date'] ) ? (int) $stats['skipped_invalid_date'] : 0,
			'errors'               => isset( $stats['errors'] ) ? (int) $stats['errors'] : 0,
			'status'               => self::normalize_status( $status ),
			'message'              => sanitize_text_field( (string) $message ),
		);
		if ( isset( $stats['source_skipped'] ) ) {
			$entry['source_skipped'] = (bool) $stats['source_skipped'];
		}
		if ( ! empty( $stats['source_skip_reason'] ) ) {
			$entry['source_skip_reason'] = sanitize_text_field( (string) $stats['source_skip_reason'] );
		}
		$rate_limit_state = ! empty( $stats['rate_limit_state'] ) && is_array( $stats['rate_limit_state'] )
			? $stats['rate_limit_state']
			: ( isset( $extra['rate_limit_state'] ) && is_array( $extra['rate_limit_state'] ) ? $extra['rate_limit_state'] : array() );
		if ( empty( $rate_limit_state ) && class_exists( 'Conexao_Eventbrite_Pacing' ) ) {
			$live_rate_limit_state = Conexao_Eventbrite_Pacing::get_run_state();
			if ( ! empty( $live_rate_limit_state['active'] ) ) {
				$rate_limit_state = $live_rate_limit_state;
			}
		}
		if ( ! empty( $rate_limit_state ) ) {
			$entry['rate_limit_state'] = array(
				'active'                  => ! empty( $rate_limit_state['active'] ),
				'rate_limited'            => ! empty( $rate_limit_state['rate_limited'] ),
				'first_source'            => isset( $rate_limit_state['first_source'] ) ? sanitize_key( (string) $rate_limit_state['first_source'] ) : '',
				'retry_at'                => isset( $rate_limit_state['retry_at'] ) ? (int) $rate_limit_state['retry_at'] : 0,
				'retry_after_kind'        => isset( $rate_limit_state['retry_after_kind'] ) ? sanitize_key( (string) $rate_limit_state['retry_after_kind'] ) : '',
				'retry_after_unsupported' => ! empty( $rate_limit_state['retry_after_unsupported'] ),
				'skipped_sources'         => isset( $rate_limit_state['skipped_sources'] ) && is_array( $rate_limit_state['skipped_sources'] ) ? array_values( array_map( 'sanitize_key', $rate_limit_state['skipped_sources'] ) ) : array(),
			);
		}

		// Include optional extra data (bounded to avoid huge options rows).
		if ( ! empty( $extra['run_id'] ) ) {
			$entry['run_id'] = sanitize_key( (string) $extra['run_id'] );
		}
		$source_run_id = ! empty( $extra['source_run_id'] )
			? (string) $extra['source_run_id']
			: (string) apply_filters( 'conexao_event_importer_current_source_run_id', '' );
		$parent_run_group_id = ! empty( $extra['parent_run_group_id'] )
			? (string) $extra['parent_run_group_id']
			: (string) ( ! empty( $entry['run_id'] ) ? $entry['run_id'] : apply_filters( 'conexao_event_importer_current_run_id', '' ) );
		if ( '' !== $source_run_id && 'parent' !== (string) ( isset( $extra['record_type'] ) ? $extra['record_type'] : '' ) ) {
			$entry['source_run_id'] = sanitize_key( $source_run_id );
		}
		if ( '' !== $parent_run_group_id ) {
			$entry['parent_run_group_id'] = sanitize_key( $parent_run_group_id );
		}
		$entry['record_type'] = ! empty( $extra['record_type'] )
			? sanitize_key( (string) $extra['record_type'] )
			: ( 'all' === sanitize_key( (string) $source_id ) ? 'parent' : 'child' );
		if ( ! empty( $extra['fatal_errors'] ) && is_array( $extra['fatal_errors'] ) ) {
			$entry['fatal_errors'] = array_slice( $extra['fatal_errors'], 0, 20 );
		}
		if ( ! empty( $extra['failed_events'] ) && is_array( $extra['failed_events'] ) ) {
			$entry['failed_events'] = array_slice( $extra['failed_events'], 0, 100 );
		}
		if ( isset( $extra['failed_sources'] ) && is_array( $extra['failed_sources'] ) ) {
			$entry['failed_sources'] = array_values( array_map( 'sanitize_key', $extra['failed_sources'] ) );
			$entry['total_failed_source_count'] = isset( $extra['total_failed_source_count'] )
				? (int) $extra['total_failed_source_count']
				: count( $entry['failed_sources'] );
		}
		foreach ( array( 'planned_source_count', 'source_skipped_count', 'event_skipped_count', 'aggregate_error_count', 'total_failed_source_count' ) as $count_key ) {
			if ( isset( $extra[ $count_key ] ) ) {
				$entry[ $count_key ] = max( 0, (int) $extra[ $count_key ] );
			}
		}
		if ( isset( $extra['child_run_ids'] ) && is_array( $extra['child_run_ids'] ) ) {
			$entry['child_run_ids'] = array_values( array_filter( array_map( 'sanitize_key', $extra['child_run_ids'] ) ) );
		}
		// Whether the source listing was fully enumerated. False means the
		// run saw only part of the source and therefore did NOT mark absent
		// events as source_not_found.
		if ( isset( $extra['complete'] ) ) {
			$entry['complete'] = (bool) $extra['complete'];
		}

		// G9 §26/§29: cooldown state is part of the run record, so Import
		// History stays the ONE place an operator looks for "why was this not
		// retried / when may I retry". Bounded to a small fixed shape — this is
		// per run, not per request.
		if ( ! empty( $extra['cooldown'] ) && is_array( $extra['cooldown'] ) ) {
			$entry['cooldown'] = array(
				'cooling_down'      => ! empty( $extra['cooldown']['cooling_down'] ),
				'seconds_remaining' => isset( $extra['cooldown']['seconds_remaining'] ) ? (int) $extra['cooldown']['seconds_remaining'] : 0,
				'next_eligible'     => isset( $extra['cooldown']['next_eligible'] ) ? sanitize_text_field( (string) $extra['cooldown']['next_eligible'] ) : '',
				'last_reason'       => isset( $extra['cooldown']['last_reason'] ) ? sanitize_key( (string) $extra['cooldown']['last_reason'] ) : '',
				'consecutive'       => isset( $extra['cooldown']['consecutive'] ) ? (int) $extra['cooldown']['consecutive'] : 0,
			);
		}

		// G10 §27: enumeration progress travels with the run record so Import
		// History stays the single place an operator checks "how far did this
		// get, and can it resume". This is progress state only — the operator
		// UI must never read `checkpoint.complete` as source completeness.
		if ( isset( $extra['checkpoint'] ) && is_array( $extra['checkpoint'] ) ) {
			$checkpoint = $extra['checkpoint'];

			$entry['checkpoint'] = array(
				'has_checkpoint' => ! empty( $checkpoint['has_checkpoint'] ),
				'last_page'      => isset( $checkpoint['last_page'] ) ? (int) $checkpoint['last_page'] : 0,
				'page_count'     => isset( $checkpoint['page_count'] ) ? (int) $checkpoint['page_count'] : 0,
				'complete'       => ! empty( $checkpoint['complete'] ),
				'last_failure'   => isset( $checkpoint['last_failure'] ) ? sanitize_key( (string) $checkpoint['last_failure'] ) : '',
				'updated_at'     => isset( $checkpoint['updated_at'] ) ? sanitize_text_field( (string) $checkpoint['updated_at'] ) : '',
			);
		}

		array_unshift( $history, $entry );
		$history = array_slice( $history, 0, self::MAX_ENTRIES );

		update_option( self::option_key(), $history, false );

		return $entry;
	}

	/**
	 * Insert or replace the one parent record for a logical Run All.
	 *
	 * Child attempts remain separate history rows; replacing the parent makes
	 * final aggregation idempotent when a continuation or worker reconciles it.
	 *
	 * @param string $run_group_id Parent run-group identifier.
	 * @param array  $stats        Aggregate stats.
	 * @param string $status       Lifecycle status.
	 * @param string $message      Operator-facing summary.
	 * @param array  $extra        Parent metadata.
	 * @return array Parent history entry.
	 */
	public static function record_parent( $run_group_id, $stats, $status, $message = '', $extra = array() ) {
		$extra['run_id']             = (string) $run_group_id;
		$extra['parent_run_group_id'] = (string) $run_group_id;
		$extra['record_type']        = 'parent';
		$entry = self::record( 'all', $stats, $status, $message, $extra );
		$history = self::get_all();
		$removed = false;

		foreach ( $history as $index => $existing ) {
			if ( 0 === $index ) {
				continue;
			}
			if ( 'parent' === (string) ( isset( $existing['record_type'] ) ? $existing['record_type'] : '' )
				&& sanitize_key( (string) $run_group_id ) === (string) ( isset( $existing['parent_run_group_id'] ) ? $existing['parent_run_group_id'] : '' ) ) {
				unset( $history[ $index ] );
				$removed = true;
			}
		}

		if ( $removed ) {
			$history = array_values( $history );
			update_option( self::option_key(), $history, false );
		}

		return $entry;
	}

	/**
	 * Get all history rows belonging to one parent run group.
	 *
	 * @param string $run_group_id Parent identifier.
	 * @param int    $limit        Maximum rows.
	 * @return array
	 */
	public static function get_for_parent( $run_group_id, $limit = 100 ) {
		$entries = array();
		$group   = sanitize_key( (string) $run_group_id );
		foreach ( self::get_all() as $entry ) {
			if ( $group === (string) ( isset( $entry['parent_run_group_id'] ) ? $entry['parent_run_group_id'] : '' ) ) {
				$entries[] = $entry;
			}
			if ( count( $entries ) >= (int) $limit ) {
				break;
			}
		}
		return $entries;
	}

	/**
	 * Recalculate a parent from the latest child row for each source.
	 *
	 * This is used after a continuation child finishes. It deliberately reads
	 * persisted child rows, so a repeated worker callback cannot double-count the
	 * same source attempt.
	 *
	 * @param string $run_group_id Parent identifier.
	 * @return array|null Updated parent, or null when no parent exists.
	 */
	public static function refresh_parent( $run_group_id ) {
		$rows = self::get_for_parent( $run_group_id, self::MAX_ENTRIES );
		$parent = null;
		$children = array();
		foreach ( $rows as $row ) {
			if ( 'parent' === (string) ( isset( $row['record_type'] ) ? $row['record_type'] : '' ) ) {
				if ( null === $parent ) {
					$parent = $row;
				}
				continue;
			}
			$source = isset( $row['source'] ) ? (string) $row['source'] : '';
			if ( '' !== $source && ! isset( $children[ $source ] ) ) {
				$children[ $source ] = $row;
			}
		}
		if ( null === $parent ) {
			return null;
		}

		$stats = array( 'found' => 0, 'new' => 0, 'updated' => 0, 'unchanged' => 0, 'duplicates' => 0, 'skipped' => 0, 'skipped_past' => 0, 'skipped_invalid_date' => 0, 'errors' => 0 );
		$failed_sources = array();
		$source_skipped = 0;
		foreach ( $children as $source => $child ) {
			foreach ( array_keys( $stats ) as $key ) {
				$stats[ $key ] += isset( $child[ $key ] ) ? (int) $child[ $key ] : 0;
			}
			if ( 'failed' === (string) ( isset( $child['status'] ) ? $child['status'] : '' ) ) {
				$failed_sources[] = $source;
			}
			if ( ! empty( $child['source_skipped'] ) ) {
				++$source_skipped;
			}
		}

		$status = 'completed';
		foreach ( $children as $child ) {
			$child_status = isset( $child['status'] ) ? (string) $child['status'] : '';
			if ( 'failed' === $child_status ) {
				$status = 'failed';
				break;
			}
			if ( 'rate_limited' === $child_status || 'rate_limited' === $status ) {
				$status = 'rate_limited';
			} elseif ( 'partial' === $child_status && 'completed' === $status ) {
				$status = 'partial';
			} elseif ( in_array( $child_status, array( 'warning', 'completed_with_errors' ), true ) && 'completed' === $status ) {
				$status = 'completed_with_errors';
			}
		}

		$stats['failed_sources'] = $failed_sources;
		$stats['total_failed_source_count'] = count( $failed_sources );
		$stats['source_skipped_count'] = $source_skipped;
		$stats['event_skipped_count'] = (int) $stats['skipped'];
		$stats['aggregate_error_count'] = (int) $stats['errors'];
		return self::record_parent(
			$run_group_id,
			$stats,
			$status,
			(string) $parent['message'],
			array(
				'planned_source_count' => count( $children ),
				'failed_sources'       => $failed_sources,
				'source_skipped_count' => $source_skipped,
				'event_skipped_count'  => (int) $stats['skipped'],
				'aggregate_error_count' => (int) $stats['errors'],
			)
		);
	}

	/**
	 * Get the full import history (newest first).
	 *
	 * @return array
	 */
	public static function get_all() {
		$history = get_option( self::option_key(), array() );
		return is_array( $history ) ? $history : array();
	}

	/**
	 * Get history for a specific source.
	 *
	 * @param string $source_id Source slug.
	 * @param int    $limit     Max entries.
	 * @return array
	 */
	public static function get_for_source( $source_id, $limit = 20 ) {
		$entries = array();
		foreach ( self::get_all() as $entry ) {
			if ( isset( $entry['source'] ) && $entry['source'] === $source_id ) {
				$entries[] = $entry;
			}
			if ( count( $entries ) >= $limit ) {
				break;
			}
		}
		return $entries;
	}

	/**
	 * Get the last run for a source.
	 *
	 * @param string $source_id Source slug.
	 * @return array|null
	 */
	public static function get_last( $source_id ) {
		foreach ( self::get_all() as $entry ) {
			if ( isset( $entry['source'] ) && $entry['source'] === $source_id ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Get the timestamp of the last successful import for a source.
	 *
	 * @param string $source_id Source slug.
	 * @return string MySQL datetime or empty string.
	 */
	public static function get_last_success_time( $source_id ) {
		foreach ( self::get_all() as $entry ) {
			if ( isset( $entry['source'] ) && $entry['source'] === $source_id && in_array( $entry['status'], array( 'success', 'completed', 'completed_with_errors' ), true ) ) {
				return $entry['time'];
			}
		}
		return '';
	}

	/**
	 * Clear the history.
	 */
	public static function clear() {
		update_option( self::option_key(), array(), false );
	}

	/**
	 * Normalize a status string.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	protected static function normalize_status( $status ) {
		$status = strtolower( trim( (string) $status ) );
		$valid  = array( 'queued', 'running', 'completed', 'completed_with_errors', 'partial', 'rate_limited', 'failed', 'success', 'warning', 'error' );
		return in_array( $status, $valid, true ) ? $status : 'success';
	}
}
