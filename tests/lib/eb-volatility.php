<?php
/**
 * G11 — Eventbrite source volatility measurement library.
 *
 * ## What this is
 *
 * A PURE, deterministic comparison library for Eventbrite observation pairs.
 * It contains no HTTP, no WordPress dependency and no importer logic: given
 * two captured observations it answers, repeatably:
 *
 *   - which events are new, changed, unchanged, or removed;
 *   - which events MOVED between pages;
 *   - which events CROSSED the checkpoint/resume boundary;
 *   - whether an observation is complete enough for a removal to be meaningful;
 *   - what the change rate is, without ever dividing by an invalid denominator.
 *
 * The whole point of this file is that the measurement cannot quietly lie.
 * Three failure modes are structurally prevented rather than merely documented:
 *
 *   1. **Absence is never inferred from an incomplete walk.** `removed` is
 *      only populated when BOTH sides of the comparison are complete. A 429,
 *      a WAF/405, a timeout or an exhausted budget makes an observation
 *      incomplete, and an incomplete side can only ever produce
 *      `removals_valid => false`. This mirrors the importer's own rule that
 *      `mark_missing_events()` is unreachable without `fetch_complete`.
 *
 *   2. **Page number is never an identity.** Identity is the stable
 *      Eventbrite event ID, always. Page membership is compared as a SET of
 *      IDs, so a page whose contents shuffled between observations is still
 *      reported as movement, never as stability and never as removals.
 *
 *   3. **Rate limiting is never counted as volatility.** 429/WAF/timeout
 *      produce a `failure_class` and an incomplete flag. They never enter the
 *      removed set and never move a change rate.
 *
 * ## Where it lives and why
 *
 * `tests/lib/` — the same shared location as `assertions.php` and
 * `option-isolation.php`. It is deliberately NOT under `wp-content/`: this is
 * measurement instrumentation for an observation stage, and G11 is
 * measurement-first. Nothing here ships to production, is reachable from the
 * importer, or can change runtime behaviour.
 *
 * ## Identity limitation (G11 §9)
 *
 * The primary identity is the native Eventbrite event ID, the same
 * `_event_source_id` the importer already stores. `eb_vol_normalise_observation()`
 * DROPS any record without one rather than inventing a substitute, so a
 * degraded capture can never masquerade as a complete event set.
 *
 * @package Conexao_BR_Test_Harness
 */

if ( ! defined( 'CONEXAO_TESTS_ROOT' ) ) {
	fwrite( STDERR, "conexao: tests/lib/eb-volatility.php loaded outside tests/bootstrap.php\n" );
	exit( 1 );
}

/**
 * Failure classes that make an observation INCOMPLETE.
 *
 * Every one of these is an access/transport condition, never an upstream
 * content change. An observation that hit any of them did not prove the
 * listing was smaller — it proved we could not see all of it.
 *
 * @var array<string,string>
 */
$GLOBALS['eb_vol_incomplete_classes'] = array(
	'rate_limited'       => 'rate_limited',
	'waf'                => 'waf',
	'timeout'            => 'timeout',
	'network'            => 'network',
	'budget_exhausted'   => 'budget_exhausted',
	'http_error'         => 'http_error',
	'parse_failed'       => 'parse_failed',
	'not_walked'         => 'not_walked',
	'upstream_truncated' => 'upstream_truncated',
);

/**
 * Classify an HTTP status (or transport error) into a failure class.
 *
 * G11 §16/§17. A 429 is a rate-limit observation and a 405 is a WAF
 * observation; neither is volatility, and neither is worked around.
 *
 * @param int    $http_status HTTP status, or 0 for a transport failure.
 * @param string $reason      Optional explicit reason from the transport.
 * @return string Failure class ('' when the response was a clean 200).
 */
function eb_vol_classify_failure( $http_status, $reason = '' ) {
	$status = (int) $http_status;
	$reason = (string) $reason;

	if ( 429 === $status ) {
		return 'rate_limited';
	}

	if ( 405 === $status || 403 === $status ) {
		return 'waf';
	}

	if ( 0 === $status ) {
		if ( false !== strpos( $reason, 'budget' ) ) {
			return 'budget_exhausted';
		}
		if ( false !== strpos( $reason, 'timeout' ) ) {
			return 'timeout';
		}
		return 'network';
	}

	if ( '' !== $reason && isset( $GLOBALS['eb_vol_incomplete_classes'][ $reason ] ) ) {
		return $reason;
	}

	if ( $status >= 400 ) {
		return 'http_error';
	}

	return '';
}

/**
 * Whether an HTTP status indicates an upstream access control response.
 *
 * G11 §17. Kept separate from `eb_vol_classify_failure()` so a test can assert
 * the WAF path specifically instead of inferring it from a generic failure.
 *
 * @param int $http_status HTTP status.
 * @return bool
 */
function eb_vol_is_waf( $http_status ) {
	return 'waf' === eb_vol_classify_failure( $http_status );
}

/**
 * Whether an HTTP status indicates rate limiting.
 *
 * @param int $http_status HTTP status.
 * @return bool
 */
function eb_vol_is_rate_limited( $http_status ) {
	return 'rate_limited' === eb_vol_classify_failure( $http_status );
}

/**
 * Normalise one captured observation into the single canonical shape.
 *
 * The harness writes raw page records; everything downstream consumes this
 * normalised shape, so the comparison logic has exactly one input contract.
 *
 * Expected input:
 *
 *     array(
 *         'source'             => string,
 *         'observation_id'     => string,
 *         'timestamp'          => string,
 *         'complete'           => bool,
 *         'failure_class'      => string,   // '' when clean.
 *         'pages_walked'       => int,
 *         'declared_pages'     => int,
 *         'declared_objects'   => int,
 *         'requests'           => int,
 *         'events'             => array<int,array{event_id,page,position,field_hash}>,
 *         'truncated_upstream' => bool,
 *     )
 *
 * @param array $raw Captured observation.
 * @return array Normalised observation.
 */
function eb_vol_normalise_observation( array $raw ) {
	$events = array();

	foreach ( ( isset( $raw['events'] ) && is_array( $raw['events'] ) ? $raw['events'] : array() ) as $event ) {
		if ( ! is_array( $event ) ) {
			continue;
		}

		$event_id = isset( $event['event_id'] ) ? (string) $event['event_id'] : '';
		if ( '' === $event_id ) {
			// No stable identity: drop it rather than inventing one, so a
			// degraded capture can never look like a complete event set.
			continue;
		}

		$events[] = array(
			'event_id'   => $event_id,
			'page'       => isset( $event['page'] ) ? (int) $event['page'] : 0,
			'position'   => isset( $event['position'] ) ? (int) $event['position'] : 0,
			'field_hash' => isset( $event['field_hash'] ) ? (string) $event['field_hash'] : '',
		);
	}

	$failure_class = isset( $raw['failure_class'] ) ? (string) $raw['failure_class'] : '';
	$complete      = ! empty( $raw['complete'] );

	// An upstream-truncated listing is structurally incomplete even when every
	// requested page was fetched. G8 established that Eventbrite pins
	// page_count while declaring a larger object_count, so a walk that reached
	// its declared end has still not seen everything. Treating that as
	// complete would manufacture removals out of an upstream cap.
	if ( ! empty( $raw['truncated_upstream'] ) ) {
		$complete      = false;
		$failure_class = 'upstream_truncated';
	}

	// Any recorded failure class forces incompleteness. Deliberately
	// one-directional: a caller that wrongly sets complete=true on a failed
	// walk is corrected here, because that is the exact defect this stage
	// exists to prevent.
	if ( '' !== $failure_class ) {
		$complete = false;
	}

	return array(
		'source'             => isset( $raw['source'] ) ? (string) $raw['source'] : '',
		'observation_id'     => isset( $raw['observation_id'] ) ? (string) $raw['observation_id'] : '',
		'timestamp'          => isset( $raw['timestamp'] ) ? (string) $raw['timestamp'] : '',
		'complete'           => $complete,
		'failure_class'      => $failure_class,
		'pages_walked'       => isset( $raw['pages_walked'] ) ? (int) $raw['pages_walked'] : 0,
		'declared_pages'     => isset( $raw['declared_pages'] ) ? (int) $raw['declared_pages'] : 0,
		'declared_objects'   => isset( $raw['declared_objects'] ) ? (int) $raw['declared_objects'] : 0,
		'requests'           => isset( $raw['requests'] ) ? (int) $raw['requests'] : 0,
		'truncated_upstream' => ! empty( $raw['truncated_upstream'] ),
		'events'             => $events,
	);
}

/**
 * Index an observation's events by stable ID.
 *
 * @param array $observation Normalised observation.
 * @return array<string,array{page:int,position:int,field_hash:string}>
 */
function eb_vol_index_by_id( array $observation ) {
	$index = array();

	foreach ( $observation['events'] as $event ) {
		// First occurrence wins so a duplicate ID resolves deterministically
		// instead of depending on page ordering.
		if ( ! isset( $index[ $event['event_id'] ] ) ) {
			$index[ $event['event_id'] ] = array(
				'page'       => $event['page'],
				'position'   => $event['position'],
				'field_hash' => $event['field_hash'],
			);
		}
	}

	return $index;
}

/**
 * The unique ID set of an observation.
 *
 * @param array $observation Normalised observation.
 * @return string[]
 */
function eb_vol_ids( array $observation ) {
	return array_keys( eb_vol_index_by_id( $observation ) );
}

/**
 * Group an observation's events into page => ordered list of event IDs.
 *
 * The ordering within a page is the position Eventbrite served, which is what
 * makes page ORDER comparable between observations (G11 §8 "reordered").
 *
 * @param array $observation Normalised observation.
 * @return array<int,string[]> Page number => ordered event IDs.
 */
function eb_vol_pages( array $observation ) {
	$grouped = array();

	foreach ( $observation['events'] as $event ) {
		$page = (int) $event['page'];
		if ( $page <= 0 ) {
			continue;
		}
		$grouped[ $page ][] = $event;
	}

	$ordered = array();
	foreach ( $grouped as $page => $events ) {
		usort(
			$events,
			static function ( $a, $b ) {
				if ( $a['position'] === $b['position'] ) {
					return strcmp( $a['event_id'], $b['event_id'] );
				}
				return $a['position'] < $b['position'] ? -1 : 1;
			}
		);

		$ids = array();
		foreach ( $events as $event ) {
			$ids[] = $event['event_id'];
		}
		$ordered[ (int) $page ] = $ids;
	}

	ksort( $ordered );

	return $ordered;
}
/**
 * Whether an observation carries a usable, comparable event set.
 *
 * G11 §9: identity is the native Eventbrite ID. An observation with no events
 * at all cannot be compared by ID, so it is reported as not comparable — an
 * empty capture must never be read as "everything was removed".
 *
 * @param array $observation Normalised observation.
 * @return bool
 */
function eb_vol_identity_available( array $observation ) {
	return ! empty( $observation['events'] );
}

/**
 * Detect event IDs that appear more than once in ONE observation.
 *
 * G11 §15. Duplicates here measure the request/coverage cost of walking
 * overlapping regions; they are NOT an importer defect, because the importer
 * already deduplicates by native ID.
 *
 * @param array $observation Normalised observation.
 * @return array{members:array<string,int>,pages:array<string,int[]>,count:int}
 */
function eb_vol_duplicates( array $observation ) {
	$members = array();
	$pages   = array();

	foreach ( $observation['events'] as $event ) {
		$id = $event['event_id'];
		if ( ! isset( $members[ $id ] ) ) {
			$members[ $id ] = 0;
			$pages[ $id ]   = array();
		}
		++$members[ $id ];
		$pages[ $id ][] = (int) $event['page'];
	}

	$duplicated = array();
	foreach ( $members as $id => $count ) {
		if ( $count > 1 ) {
			$duplicated[ $id ] = $count;
		}
	}

	$occurrences = 0;
	foreach ( $duplicated as $count ) {
		$occurrences += $count;
	}

	return array(
		'members' => $duplicated,
		'pages'   => $pages,
		'count'   => $occurrences,
	);
}

/**
 * Group page movements by the checkpoint boundary they crossed.
 *
 * G11 §10. This is THE measurement the stage exists for: how much data can
 * realistically cross the resume boundary between two runs?
 *
 * Boundary semantics follow G10's resume rule exactly. A resumed walk
 * re-walks `OVERLAP_PAGES` already-confirmed pages below the checkpoint and
 * then continues from `last_page + 1`. With `boundary` = N (the previous run's
 * last confirmed page):
 *
 *   - `downward` — moved from page <= N to page > N. The event was confirmed
 *     before and now sits beyond the resume point. This is the risky
 *     direction: with no overlap at all, an event pushed from page N to N+1
 *     by a single new top-of-listing insertion is exactly the displacement
 *     the overlap exists to re-validate.
 *   - `upward` — moved from page > N to page <= N. The event entered the
 *     region the previous run had already covered.
 *
 * Keying by direction lets the report count each side explicitly instead of
 * collapsing them into one number.
 *
 * @param array $movement Event ID => array{from:int,to:int,delta:int}.
 * @param int   $boundary Checkpoint page from the previous run.
 * @return array{upward:array<string,array>,downward:array<string,array>,total:int}
 */
function eb_vol_boundary_crossing( array $movement, $boundary ) {
	$boundary = (int) $boundary;

	$upward   = array();
	$downward = array();

	if ( $boundary > 0 ) {
		foreach ( $movement as $id => $move ) {
			$from = (int) $move['from'];
			$to   = (int) $move['to'];

			if ( $from <= $boundary && $to > $boundary ) {
				$downward[ $id ] = $move;
				continue;
			}

			if ( $from > $boundary && $to <= $boundary ) {
				$upward[ $id ] = $move;
			}
		}
	}

	return array(
		'upward'   => $upward,
		'downward' => $downward,
		'total'    => count( $upward ) + count( $downward ),
	);
}
/**
 * Compare two consecutive observations of one source.
 *
 * This is the load-bearing measurement. Its contract:
 *
 *   - `new` / `changed` / `unchanged` are always computed from the stable
 *     Eventbrite ID sets, never from titles, page numbers or positions.
 *   - `removed` is populated ONLY when both observations are complete.
 *     Otherwise it is empty AND `removals_valid` is false, so a caller cannot
 *     mistake "we could not see them" for "they are gone".
 *   - `page_movement` maps an event ID to its old and new page whenever the
 *     page changed while the event itself persisted.
 *   - `boundary_crossing` is derived from that movement against an explicit
 *     checkpoint page, never from "page N looks the same".
 *
 * @param array $previous Normalised previous observation.
 * @param array $current  Normalised current observation.
 * @param int   $boundary Checkpoint/resume boundary page (G10's last_page).
 * @return array
 */
function eb_vol_compare( array $previous, array $current, $boundary = 0 ) {
	$prev_index = eb_vol_index_by_id( $previous );
	$curr_index = eb_vol_index_by_id( $current );

	$prev_ids = array_keys( $prev_index );
	$curr_ids = array_keys( $curr_index );

	$new       = array();
	$removed   = array();
	$changed   = array();
	$unchanged = array();
	$movement  = array();

	foreach ( $curr_ids as $id ) {
		if ( ! isset( $prev_index[ $id ] ) ) {
			$new[] = $id;
		}
	}

	foreach ( $prev_ids as $id ) {
		if ( ! isset( $curr_index[ $id ] ) ) {
			$removed[] = $id;
			continue;
		}

		$prev_event = $prev_index[ $id ];
		$curr_event = $curr_index[ $id ];

		if ( $prev_event['field_hash'] !== $curr_event['field_hash'] ) {
			$changed[] = $id;
		} else {
			$unchanged[] = $id;
		}

		if ( (int) $prev_event['page'] !== (int) $curr_event['page'] ) {
			$movement[ $id ] = array(
				'from'  => (int) $prev_event['page'],
				'to'    => (int) $curr_event['page'],
				'delta' => (int) $curr_event['page'] - (int) $prev_event['page'],
			);
		}
	}

	$removals_valid = (bool) ( $previous['complete'] && $current['complete'] );

	if ( ! $removals_valid ) {
		// Discard the removals entirely: they are an artefact of what we could
		// NOT read, not of what the upstream did.
		$removed = array();
	}

	$intersection = 0;
	foreach ( $curr_ids as $id ) {
		if ( isset( $prev_index[ $id ] ) ) {
			++$intersection;
		}
	}

	// Computed once so the report's boundary table and this summary can never
	// disagree.
	$crossing = eb_vol_boundary_crossing( $movement, (int) $boundary );

	return array(
		'previous_count'     => count( $prev_ids ),
		'current_count'      => count( $curr_ids ),
		'intersection'       => $intersection,
		'new'                => $new,
		'changed'            => $changed,
		'unchanged'          => $unchanged,
		'removed'            => $removed,
		'removals_valid'     => $removals_valid,
		'page_movement'      => $movement,
		'moved_count'        => count( $movement ),
		'boundary_crossing'  => $crossing,
		'boundary_crossed'   => $crossing['total'],
		'duplicates'         => eb_vol_duplicates( $current ),
		'previous_complete'  => (bool) $previous['complete'],
		'current_complete'   => (bool) $current['complete'],
		'previous_failure'   => (string) $previous['failure_class'],
		'current_failure'    => (string) $current['failure_class'],
	);
}

/**
 * Compute the change rate for a comparison, or refuse to.
 *
 * G11 §13:
 *
 *     change rate = (new + changed + valid removals) / previous unique set
 *
 * A rate is returned ONLY when the denominator is valid: both observations
 * complete and the previous set non-empty. Every other case returns null so
 * the caller renders "unmeasured", never a misleading "0%".
 *
 * @param array $comparison Result of `eb_vol_compare()`.
 * @return float|null Change rate in [0,1], or null when not measurable.
 */
function eb_vol_change_rate( array $comparison ) {
	if ( empty( $comparison['removals_valid'] ) ) {
		return null;
	}

	$previous_count = isset( $comparison['previous_count'] ) ? (int) $comparison['previous_count'] : 0;
	if ( $previous_count <= 0 ) {
		return null;
	}

	$affected = (int) count( $comparison['new'] )
		+ (int) count( $comparison['changed'] )
		+ (int) count( $comparison['removed'] );

	return $affected / $previous_count;
}
/**
 * Which pages changed membership between two observations.
 *
 * G11 §8 "page-set changes". Each page is compared as a SET of IDs, so a page
 * that merely reordered is distinguished from one whose contents changed.
 *
 * @param array $previous Normalised previous observation.
 * @param array $current  Normalised current observation.
 * @return array<int,array{added:int,removed:int,reordered:bool,old_count:int,new_count:int}>
 */
function eb_vol_page_set_changes( array $previous, array $current ) {
	$prev_pages = eb_vol_pages( $previous );
	$curr_pages = eb_vol_pages( $current );

	$all_pages = array_unique( array_merge( array_keys( $prev_pages ), array_keys( $curr_pages ) ) );
	sort( $all_pages );

	$changes = array();

	foreach ( $all_pages as $page ) {
		$prev_ids = isset( $prev_pages[ $page ] ) ? $prev_pages[ $page ] : array();
		$curr_ids = isset( $curr_pages[ $page ] ) ? $curr_pages[ $page ] : array();

		$added   = count( array_diff( $curr_ids, $prev_ids ) );
		$removed = count( array_diff( $prev_ids, $curr_ids ) );

		$changes[ (int) $page ] = array(
			'added'     => $added,
			'removed'   => $removed,
			'reordered' => ( 0 === $added && 0 === $removed && $prev_ids !== $curr_ids ),
			'old_count' => count( $prev_ids ),
			'new_count' => count( $curr_ids ),
		);
	}

	return $changes;
}

/**
 * The lowest overlap that would have covered every observed movement.
 *
 * G11 §11. A RECOMMENDATION INPUT, never a decision: this function changes no
 * constant. It only tells a later implementation stage what the measured
 * movement would imply.
 *
 * @param array $comparison Result of `eb_vol_compare()`.
 * @return int Recommended overlap in pages (0 when nothing moved).
 */
function eb_vol_recommended_overlap( array $comparison ) {
	$movement = isset( $comparison['page_movement'] ) ? $comparison['page_movement'] : array();

	$worst = 0;
	foreach ( $movement as $move ) {
		$span = abs( (int) $move['delta'] );
		if ( $span > $worst ) {
			$worst = $span;
		}
	}

	return $worst;
}

/**
 * Classify a source's volatility from its measured comparisons.
 *
 * G11 §23. Deliberately evidence-driven and deliberately conservative:
 *
 *   - `unknown`   when there are fewer than $min_comparisons COMPLETE
 *     comparisons — an incomplete comparison cannot establish a change rate,
 *     so it must never contribute to a high/moderate/low verdict;
 *   - `low`       when the mean change rate is below 5 %;
 *   - `moderate`  below 25 %;
 *   - `high`      at or above 25 %.
 *
 * @param array $comparisons    List of `eb_vol_compare()` results.
 * @param int   $min_comparisons Complete comparisons required to conclude.
 * @return string One of high|moderate|low|unknown.
 */
function eb_vol_classify_volatility( array $comparisons, $min_comparisons = 2 ) {
	$rates = array();

	foreach ( $comparisons as $comparison ) {
		$rate = eb_vol_change_rate( $comparison );
		if ( null !== $rate ) {
			$rates[] = (float) $rate;
		}
	}

	if ( count( $rates ) < (int) $min_comparisons ) {
		return 'unknown';
	}

	$mean = array_sum( $rates ) / count( $rates );

	if ( $mean >= 0.25 ) {
		return 'high';
	}
	if ( $mean >= 0.05 ) {
		return 'moderate';
	}

	return 'low';
}
/**
 * Decide whether the current `OVERLAP_PAGES` setting is justified.
 *
 * G11 §11. Classifies as one of:
 *
 *   - `unmeasured`   — not enough complete comparisons to say anything;
 *   - `insufficient` — observed movement exceeded the current overlap;
 *   - `excessive`    — the overlap re-walked pages that provably never moved,
 *                      repeatedly, across complete observations;
 *   - `sufficient`   — movement stayed within the current overlap.
 *
 * The function NEVER changes a constant. It only classifies, so a later
 * implementation stage can act on a real number instead of an intuition.
 *
 * @param array $comparisons     List of `eb_vol_compare()` results.
 * @param int   $current_overlap The production constant under test.
 * @param int   $min_complete    Complete comparisons required to conclude.
 * @return array{verdict:string,max_movement:int,complete_comparisons:int,changed_comparisons:int,rationale:string}
 */
function eb_vol_classify_overlap( array $comparisons, $current_overlap, $min_complete = 2 ) {
	$current_overlap = (int) $current_overlap;

	$complete    = 0;
	$max_move    = 0;
	$with_change = 0;

	foreach ( $comparisons as $comparison ) {
		if ( empty( $comparison['removals_valid'] ) ) {
			continue;
		}

		++$complete;

		$moved = false;
		foreach ( ( isset( $comparison['page_movement'] ) ? $comparison['page_movement'] : array() ) as $move ) {
			$span     = abs( (int) $move['delta'] );
			$max_move = max( $max_move, $span );
			if ( $span > 0 ) {
				$moved = true;
			}
		}

		if ( $moved ) {
			++$with_change;
		}
	}

	$base = array(
		'max_movement'         => $max_move,
		'complete_comparisons' => $complete,
		'changed_comparisons'  => $with_change,
	);

	if ( $complete < (int) $min_complete ) {
		return array_merge(
			$base,
			array(
				'verdict'   => 'unmeasured',
				'rationale' => sprintf(
					'Only %d complete comparison(s); %d are required before any overlap conclusion is defensible.',
					$complete,
					$min_complete
				),
			)
		);
	}

	if ( $max_move > $current_overlap ) {
		return array_merge(
			$base,
			array(
				'verdict'   => 'insufficient',
				'rationale' => sprintf(
					'Observed page movement of %d page(s) exceeds the %d-page overlap.',
					$max_move,
					$current_overlap
				),
			)
		);
	}

	if ( 0 === $with_change ) {
		return array_merge(
			$base,
			array(
				'verdict'   => 'excessive',
				'rationale' => sprintf(
					'%d complete comparison(s) observed zero page movement; the %d-page overlap re-walks pages that never changed.',
					$complete,
					$current_overlap
				),
			)
		);
	}

	return array_merge(
		$base,
		array(
			'verdict'   => 'sufficient',
			'rationale' => sprintf(
				'Maximum observed page movement was %d page(s), within the %d-page overlap, across %d complete comparison(s).',
				$max_move,
				$current_overlap,
				$complete
			),
		)
	);
}

/**
 * Build the per-source volatility summary for one observation pair.
 *
 * This is the exact row shape the report renders (§13/§14): present, new,
 * changed, unchanged, valid removals, requests, failures, completeness.
 *
 * The source-level measures are deliberately named so they cannot be confused
 * with WordPress import mutations. Nothing here writes, creates or updates a
 * post: "new" means "present in this listing and absent from the previous
 * observation", NOT "a post was created".
 *
 * @param array $previous Normalised previous observation.
 * @param array $current  Normalised current observation.
 * @param int   $boundary Checkpoint boundary page.
 * @return array
 */
function eb_vol_source_summary( array $previous, array $current, $boundary = 0 ) {
	$comparison = eb_vol_compare( $previous, $current, $boundary );
	$crossing   = $comparison['boundary_crossing'];

	return array(
		'source'               => $current['source'],
		'previous_observation' => $previous['observation_id'],
		'current_observation'  => $current['observation_id'],
		'timestamp'            => $current['timestamp'],
		'present'              => (int) $comparison['current_count'],
		'previous_present'     => (int) $comparison['previous_count'],
		'new'                  => count( $comparison['new'] ),
		'changed'              => count( $comparison['changed'] ),
		'unchanged'            => count( $comparison['unchanged'] ),
		'removed'              => count( $comparison['removed'] ),
		'removals_valid'       => (bool) $comparison['removals_valid'],
		'intersection'         => (int) $comparison['intersection'],
		'page_movement_count'  => (int) $comparison['moved_count'],
		'boundary_upward'      => count( $crossing['upward'] ),
		'boundary_downward'    => count( $crossing['downward'] ),
		'boundary_crossed'     => (int) $comparison['boundary_crossed'],
		'duplicate_ids'        => (int) $comparison['duplicates']['count'],
		'complete'             => (bool) $current['complete'],
		'failure_class'        => (string) $current['failure_class'],
		'pages_walked'         => (int) $current['pages_walked'],
		'declared_pages'       => (int) $current['declared_pages'],
		'declared_objects'     => (int) $current['declared_objects'],
		'requests'             => (int) $current['requests'],
		'change_rate'          => eb_vol_change_rate( $comparison ),
		'recommended_overlap'  => eb_vol_recommended_overlap( $comparison ),
		'comparison'           => $comparison,
	);
}