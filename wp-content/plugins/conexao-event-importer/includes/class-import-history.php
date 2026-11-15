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

	/**
	 * Maximum number of history rows retained.
	 *
	 * Stage 3 (P13) replaced the blind 100-row slice with group-aware
	 * retention. 100 could not hold one logical Run All: the registry holds 154
	 * sources on 2026-11-15, so a single Run All produces one parent row plus
	 * one row per attempted source — 155 rows — and the beginning of the run
	 * (the parent, the first sources) was evicted before the run finished.
	 *
	 * Measured on the live store: 927 bytes per row, so 1,200 rows is ≈1.1 MB in
	 * one non-autoloaded option row, read only by the admin history screen. The
	 * number is not the fix — `retain()` is; the number is the bound it enforces.
	 *
	 * @var int
	 */
	const MAX_ENTRIES = 1200;

	/**
	 * Maximum number of completed logical groups retained.
	 *
	 * The second of the two bounds, whichever binds first with MAX_ENTRIES:
	 *
	 *   * 50 standalone source runs (one row each) are retained in full — the
	 *     same order of magnitude as the old 100-row cap, so no operator loses
	 *     history they used to have;
	 *   * a full Run All is one group of ~155 rows, so 1,200 rows binds first and
	 *     about seven complete Run Alls are retained.
	 *
	 * Active runs are exempt from both bounds.
	 *
	 * @var int
	 */
	const MAX_GROUPS = 50;

	/**
	 * Statuses that mean a logical run has not finished yet.
	 *
	 * @var string[]
	 */
	const ACTIVE_STATUSES = array( 'queued', 'running' );


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

		// Stage 3 (P12): the machine-readable cause of an incomplete
		// enumeration travels with the row, so Import History and the source
		// health view explain WHY a run stopped instead of only that it did.
		$incomplete_reason = '';
		if ( ! empty( $stats['incomplete_reason'] ) ) {
			$incomplete_reason = (string) $stats['incomplete_reason'];
		} elseif ( ! empty( $extra['incomplete_reason'] ) ) {
			$incomplete_reason = (string) $extra['incomplete_reason'];
		}
		if ( '' !== trim( $incomplete_reason ) ) {
			$entry['incomplete_reason'] = sanitize_key( $incomplete_reason );
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
		$history = self::retain( $history );

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
	 * The logical-run key one history row belongs to.
	 *
	 * Uses Stage 1's identity model — never a new one:
	 *
	 *   1. `parent_run_group_id` — the group a child row belongs to;
	 *   2. `run_id` — for the parent row itself, and for a standalone source run
	 *      (`run_source()` writes its own run ID as the parent group, so both
	 *      agree);
	 *   3. `legacy` — a row written before Stage 1, which carries no run
	 *      identity at all.
	 *
	 * The returned key is prefixed so it can never collide with a bare run ID.
	 *
	 * @param array $entry One history row.
	 * @return string Group key.
	 */
	public static function entry_group_key( array $entry ) {
		$group = isset( $entry['parent_run_group_id'] ) ? trim( (string) $entry['parent_run_group_id'] ) : '';

		if ( '' === $group ) {
			$group = isset( $entry['run_id'] ) ? trim( (string) $entry['run_id'] ) : '';
		}

		return '' === $group ? 'legacy' : 'group:' . $group;
	}

	/**
	 * Whether a logical run has reached a terminal state.
	 *
	 * A group is terminal only when NO row in it still claims an unfinished
	 * status. This is what protects an in-flight Run All: the parent is written
	 * with status `running` before the first source starts, and only the final
	 * `record_parent()` call makes it terminal.
	 *
	 * A group whose parent row is missing (a standalone source run, or a run
	 * whose parent was never written) is terminal when its own rows are — which
	 * is how 9 of the 100 rows on the live store behave.
	 *
	 * @param array $rows Rows of one group.
	 * @return bool
	 */
	public static function is_group_terminal( array $rows ) {
		foreach ( $rows as $row ) {
			$status = strtolower( trim( (string) ( isset( $row['status'] ) ? $row['status'] : '' ) ) );
			if ( in_array( $status, self::ACTIVE_STATUSES, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Group IDs that are still live and must never be evicted.
	 *
	 * @return string[] Bare group IDs (no prefix), for the log store's benefit.
	 */
	public static function get_active_group_ids() {
		$groups = array();

		foreach ( self::get_all() as $entry ) {
			if ( is_array( $entry ) ) {
				$groups[ self::entry_group_key( $entry ) ][] = $entry;
			}
		}

		$active = array();
		foreach ( $groups as $key => $rows ) {
			if ( 'legacy' === $key || self::is_group_terminal( $rows ) ) {
				continue;
			}
			$active[] = substr( (string) $key, strlen( 'group:' ) );
		}

		return $active;
	}

	/**
	 * Apply the retention bound to a history array.
	 *
	 * ## Policy (Stage 3 / P13)
	 *
	 * * **Bound.** `MAX_ENTRIES` rows OR `MAX_GROUPS` completed logical groups,
	 *   whichever binds first.
	 * * **Unit.** A whole logical group. The parent row and every child row of a
	 *   run are removed together or not at all, so no orphan parent and no
	 *   orphan child is left behind, and the beginning of a run is never lost
	 *   while its own later rows survive.
	 * * **Order.** Oldest completed group first, by the newest row it contains.
	 * * **Protection.** An ACTIVE group is never evicted, at any pressure.
	 * * **Legacy rows.** Rows with no run identity are evicted individually,
	 *   oldest first, and only after every eligible group has gone — they cannot
	 *   be reconstructed as a run, and they are never given an invented ID.
	 * * **Capacity pressure.** If the protected groups alone exceed either
	 *   bound, nothing protected is discarded and `get_retention_state()`
	 *   reports the overage.
	 *
	 * Idempotent: applied to an array that already satisfies the policy it
	 * returns an equal array.
	 *
	 * @param array $history Rows, newest first.
	 * @return array Retained rows, newest first.
	 */
	public static function retain( array $history ) {
		$total  = count( $history );
		$groups = array();

		foreach ( $history as $index => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$key = self::entry_group_key( $entry );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'rows'     => array(),
					'terminal' => true,
					'newest'   => 0,
					'oldest_ix' => 0,
				);
			}
			$groups[ $key ]['rows'][] = $index;
			$status                   = strtolower( trim( (string) ( isset( $entry['status'] ) ? $entry['status'] : '' ) ) );
			if ( in_array( $status, self::ACTIVE_STATUSES, true ) ) {
				$groups[ $key ]['terminal'] = false;
			}
			$stamp = isset( $entry['time'] ) ? (int) strtotime( (string) $entry['time'] ) : 0;
			$groups[ $key ]['newest'] = max( (int) $groups[ $key ]['newest'], $stamp );
			// The store is newest-first, so a HIGHER row index is OLDER. This is
			// the deterministic tie-break when several rows share a timestamp
			// (a run records its parent and children within the same second):
			// without it, "oldest group first" would collapse into an arbitrary
			// order and could evict the run that was just written.
			$groups[ $key ]['oldest_ix'] = max( (int) $groups[ $key ]['oldest_ix'], (int) $index );
		}

		$completed      = array();
		$legacy_indexes = array();
		foreach ( $groups as $key => $info ) {
			if ( 'legacy' === $key ) {
				$legacy_indexes = $info['rows'];
				continue;
			}
			if ( ! empty( $info['terminal'] ) ) {
				$completed[ $key ] = $info;
			}
		}

		if ( $total <= self::MAX_ENTRIES && count( $completed ) <= self::MAX_GROUPS ) {
			return $history;
		}

		uasort(
			$completed,
			static function ( $a, $b ) {
				if ( (int) $a['newest'] !== (int) $b['newest'] ) {
					return (int) $a['newest'] <=> (int) $b['newest'];
				}
				// Equal timestamps: the larger row index is the older run.
				return (int) $b['oldest_ix'] <=> (int) $a['oldest_ix'];
			}
		);

		$dropped = array();
		$rows    = $total;
		$kept    = count( $completed );
		$keys    = array_keys( $completed );
		$newest  = count( $keys ) - 1;

		foreach ( $keys as $position => $key ) {
			if ( $rows <= self::MAX_ENTRIES && $kept <= self::MAX_GROUPS ) {
				break;
			}
			// Never evict the newest completed group to make room for itself: a
			// single run larger than the bound stays readable and its overage is
			// reported as pressure.
			if ( $position === $newest ) {
				break;
			}
			foreach ( $completed[ $key ]['rows'] as $index ) {
				$dropped[ $index ] = true;
			}
			$rows -= count( $completed[ $key ]['rows'] );
			--$kept;
		}

		if ( $rows > self::MAX_ENTRIES && ! empty( $legacy_indexes ) ) {
			$over = $rows - self::MAX_ENTRIES;
			foreach ( array_slice( $legacy_indexes, max( 0, count( $legacy_indexes ) - $over ) ) as $index ) {
				$dropped[ $index ] = true;
			}
		}

		$retained = array();
		foreach ( $history as $index => $entry ) {
			if ( isset( $dropped[ $index ] ) ) {
				continue;
			}
			$retained[] = $entry;
		}

		return $retained;
	}

	/**
	 * The measurable state of the history retention bound.
	 *
	 * Derived on every call from the stored contents — no new option, and no
	 * cached state that can go stale.
	 *
	 * @return array{max_entries:int,max_groups:int,total:int,groups:int,completed_groups:int,active_groups:int,active_entries:int,legacy_entries:int,pressure:bool,over_by:int}
	 */
	public static function get_retention_state() {
		$history = self::get_all();
		$groups  = array();
		$legacy  = 0;

		foreach ( $history as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$key = self::entry_group_key( $entry );
			if ( 'legacy' === $key ) {
				++$legacy;
				continue;
			}
			$groups[ $key ][] = $entry;
		}

		$active_groups  = 0;
		$active_entries = 0;
		$completed      = 0;
		foreach ( $groups as $rows ) {
			if ( self::is_group_terminal( $rows ) ) {
				++$completed;
				continue;
			}
			++$active_groups;
			$active_entries += count( $rows );
		}

		$total_count = count( $history );

		// Pressure means a bound could not be met: the protected groups alone
		// exceed it, or retention had to keep more than the bound (a single run
		// larger than either bound is retained whole and reported here).
		$over = max(
			0,
			$total_count - self::MAX_ENTRIES,
			$active_entries - self::MAX_ENTRIES,
			$active_groups - self::MAX_GROUPS
		);

		return array(
			'max_entries'      => self::MAX_ENTRIES,
			'max_groups'       => self::MAX_GROUPS,
			'total'            => count( $history ),
			'groups'           => count( $groups ),
			'completed_groups' => $completed,
			'active_groups'    => $active_groups,
			'active_entries'   => $active_entries,
			'legacy_entries'   => $legacy,
			'pressure'         => ( $over > 0 ),
			'over_by'          => $over,
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
