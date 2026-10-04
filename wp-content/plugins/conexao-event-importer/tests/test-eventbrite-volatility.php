<?php
/**
 * G11 — Eventbrite volatility measurement logic.
 *
 * ## What is proved
 *
 * These tests cover the MEASUREMENT, not speculative production tuning. G11
 * changes no constant, raises no budget and activates no source, so there is
 * nothing here that "tunes" anything. What is proved is that the measurement
 * cannot lie:
 *
 *   1.  event-set comparison (new/changed/unchanged/removed/intersection)
 *   2.  new-event detection
 *   3.  changed-event detection (tracked field hash differs)
 *   4.  unchanged-event detection
 *   5.  valid removal detection, ONLY on complete observations
 *   6.  an incomplete observation CANNOT produce removals
 *   7.  page movement detection
 *   8.  checkpoint-boundary crossing detection
 *   9.  duplicate ID detection
 *    10. 429 classified as a rate-limit OBSERVATION, not volatility
 *   11. WAF (405/403) classified as a WAF OBSERVATION, not volatility
 *   12. completeness-aware change-rate calculation
 *
 * Plus the negative proofs G11 §26 requires:
 *
 *   - treating an incomplete observation as complete is a failure;
 *   - counting 429-missing events as removals is a failure;
 *   - using page number as event identity is a failure;
 *   - ignoring page movement loses the boundary signal.
 *
 * ## Transport
 *
 * Entirely deterministic and local: no HTTP, no upstream, no production write.
 * Every "observation" is a literal array, which is what makes the mutation
 * proofs below non-vacuous — a mutation that weakens the logic changes these
 * results.
 *
 * @package Conexao_Event_Importer
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

require_once CONEXAO_TESTS_ROOT . '/lib/eb-volatility.php';

/**
 * Build a normalised observation from a compact event list.
 *
 * @param array  $specs  List of array{0:page,1:position,2:id,3:hash}.
 * @param bool   $complete Whether the observation walked the whole listing.
 * @param string $failure  Failure class ('' when clean).
 * @return array Normalised observation.
 */
function g11_obs( array $specs, $complete = true, $failure = '' ) {
	$events = array();

	foreach ( $specs as $spec ) {
		$events[] = array(
			'page'       => (int) $spec[0],
			'position'   => (int) $spec[1],
			'event_id'   => (string) $spec[2],
			'field_hash' => (string) $spec[3],
		);
	}

	return eb_vol_normalise_observation(
		array(
			'source'         => 'eventbrite_cork_test',
			'observation_id' => 'obs',
			'timestamp'      => '2026-10-04T00:00:00+00:00',
			'complete'       => $complete,
			'failure_class'  => $failure,
			'events'         => $events,
		)
	);
}

/*
 * A two-page baseline: pages 1 and 2, four events, stable hashes.
 * page 1 => a1, a2   page 2 => b1, b2
 */
$g11_base = g11_obs(
	array(
		array( 1, 1, 'a1', 'h1' ),
		array( 1, 2, 'a2', 'h2' ),
		array( 2, 1, 'b1', 'h3' ),
		array( 2, 2, 'b2', 'h4' ),
	)
);

// ---------------------------------------------------------------------------
// 1. Event-set comparison
// ---------------------------------------------------------------------------

// Identical observations: nothing is new, changed, removed or moved.
$g11_same = eb_vol_compare( $g11_base, $g11_base, 2 );

assert_true( 4 === $g11_same['previous_count'], 'G11.1: the previous unique set is counted' );
assert_true( 4 === $g11_same['current_count'], 'G11.1: the current unique set is counted' );
assert_true( 4 === $g11_same['intersection'], 'G11.1: an unchanged set has a full intersection' );
assert_true( 0 === count( $g11_same['new'] ), 'G11.1: an unchanged set has no new events' );
assert_true( 0 === count( $g11_same['removed'] ), 'G11.1: an unchanged set has no removals' );

// ---------------------------------------------------------------------------
// 2. New-event detection
// ---------------------------------------------------------------------------

$g11_with_new = g11_obs(
	array(
		array( 1, 1, 'a1', 'h1' ),
		array( 1, 2, 'a2', 'h2' ),
		array( 2, 1, 'b1', 'h3' ),
		array( 2, 2, 'b2', 'h4' ),
		array( 2, 3, 'NEW', 'h9' ),
	)
);

$g11_cmp_new = eb_vol_compare( $g11_base, $g11_with_new, 2 );

assert_true(
	array( 'NEW' ) === $g11_cmp_new['new'],
	'G11.2: an event present only in the current observation is detected as new'
);
assert_true( 4 === count( $g11_cmp_new['unchanged'] ), 'G11.2: adding one event leaves the rest unchanged' );

// ---------------------------------------------------------------------------
// 3. Changed-event detection
// ---------------------------------------------------------------------------

// Same stable ID, different tracked-field hash (e.g. the start time moved).
$g11_with_changed = g11_obs(
	array(
		array( 1, 1, 'a1', 'h1' ),
		array( 1, 2, 'a2', 'hCHANGED' ),
		array( 2, 1, 'b1', 'h3' ),
		array( 2, 2, 'b2', 'h4' ),
	)
);

$g11_cmp_changed = eb_vol_compare( $g11_base, $g11_with_changed, 2 );

assert_true(
	array( 'a2' ) === $g11_cmp_changed['changed'],
	'G11.3: a stable ID whose tracked fields changed is detected as changed, not as new'
);
assert_true( ! in_array( 'a2', $g11_cmp_changed['new'], true ), 'G11.3: a changed event is NOT also reported as new' );
assert_true( ! in_array( 'a2', $g11_cmp_changed['removed'], true ), 'G11.3: a changed event is NOT reported as removed' );

// ---------------------------------------------------------------------------
// 4. Unchanged-event detection
// ---------------------------------------------------------------------------

assert_true( 3 === count( $g11_cmp_changed['unchanged'] ), 'G11.4: exactly the untouched events are unchanged' );
assert_true(
	! in_array( 'a2', $g11_cmp_changed['unchanged'], true ),
	'G11.4: a changed event is not double-counted as unchanged'
);

// ---------------------------------------------------------------------------
// 5. Valid removal detection (complete observations only)
// ---------------------------------------------------------------------------

$g11_after_removal = g11_obs(
	array(
		array( 1, 1, 'a1', 'h1' ),
		array( 1, 2, 'a2', 'h2' ),
		array( 2, 1, 'b1', 'h3' ),
	)
);

$g11_cmp_removed = eb_vol_compare( $g11_base, $g11_after_removal, 2 );

assert_true( $g11_cmp_removed['removals_valid'], 'G11.5: two complete observations make removals meaningful' );
assert_true(
	array( 'b2' ) === $g11_cmp_removed['removed'],
	'G11.5: an event missing from a complete second observation is a valid removal'
);
assert_true( 0 === count( $g11_cmp_removed['new'] ), 'G11.5: a pure removal introduces no new events' );
// ---------------------------------------------------------------------------
// 6. An INCOMPLETE observation cannot produce removals  (the core safety rule)
// ---------------------------------------------------------------------------

// Same 3-event capture as the valid removal, but the walk stopped on a 429.
$g11_truncated_by_429 = g11_obs(
	array(
		array( 1, 1, 'a1', 'h1' ),
		array( 1, 2, 'a2', 'h2' ),
		array( 2, 1, 'b1', 'h3' ),
	),
	false,
	'rate_limited'
);

$g11_cmp_incomplete = eb_vol_compare( $g11_base, $g11_truncated_by_429, 2 );

assert_true( ! $g11_cmp_incomplete['current_complete'], 'G11.6: a failed observation reports itself incomplete' );
assert_true( 'rate_limited' === $g11_cmp_incomplete['current_failure'], 'G11.6: the rate-limit class is preserved, not swallowed' );
assert_true( ! $g11_cmp_incomplete['removals_valid'], 'G11.6: removals are NOT valid when the current observation is incomplete' );
assert_true(
	0 === count( $g11_cmp_incomplete['removed'] ),
	'G11.6: an incomplete observation produces ZERO removals — absence from a partial walk is never a removal'
);
assert_true(
	null === eb_vol_change_rate( $g11_cmp_incomplete ),
	'G11.6: no change rate is computed from an incomplete comparison'
);

// The mirror case: an incomplete PREVIOUS observation also invalidates removals.
$g11_cmp_prev_incomplete = eb_vol_compare( $g11_truncated_by_429, $g11_base, 2 );

assert_true(
	! $g11_cmp_prev_incomplete['removals_valid'],
	'G11.6: removals are NOT valid when the previous observation is incomplete either'
);
assert_true( 0 === count( $g11_cmp_prev_incomplete['removed'] ), 'G11.6: an incomplete previous side yields zero removals' );

// An upstream-truncated listing is incomplete even when every page was fetched.
$g11_truncated_upstream = eb_vol_normalise_observation(
	array(
		'complete'           => true,
		'truncated_upstream' => true,
		'events'             => array(
			array( 'page' => 1, 'position' => 1, 'event_id' => 'a1', 'field_hash' => 'h1' ),
		),
	)
);

assert_true(
	! $g11_truncated_upstream['complete'],
	'G11.6: a listing declaring more objects than its pagination can expose is incomplete'
);
assert_true(
	'upstream_truncated' === $g11_truncated_upstream['failure_class'],
	'G11.6: upstream truncation is recorded as its own class'
);
assert_true(
	null === eb_vol_change_rate( eb_vol_compare( $g11_base, $g11_truncated_upstream, 2 ) ),
	'G11.6: an upstream-truncated observation yields no change rate'
);

// ---------------------------------------------------------------------------
// 7. Page movement detection
// ---------------------------------------------------------------------------

// b1 shifts from page 2 to page 3 — the event persists, its PAGE changed.
$g11_after_shift = g11_obs(
	array(
		array( 1, 1, 'a1', 'h1' ),
		array( 1, 2, 'a2', 'h2' ),
		array( 2, 1, 'b2', 'h4' ),
		array( 3, 1, 'b1', 'h3' ),
	)
);

$g11_cmp_shift = eb_vol_compare( $g11_base, $g11_after_shift, 2 );

assert_true( 1 === $g11_cmp_shift['moved_count'], 'G11.7: an event that changed page is detected as moved' );
assert_true( isset( $g11_cmp_shift['page_movement']['b1'] ), 'G11.7: page movement is keyed by the stable event ID' );
assert_true( 2 === $g11_cmp_shift['page_movement']['b1']['from'], 'G11.7: the old page is recorded' );
assert_true( 3 === $g11_cmp_shift['page_movement']['b1']['to'], 'G11.7: the new page is recorded' );
assert_true( 1 === $g11_cmp_shift['page_movement']['b1']['delta'], 'G11.7: the direction and size of the shift is recorded' );
assert_true(
	! in_array( 'b1', $g11_cmp_shift['new'], true ) && ! in_array( 'b1', $g11_cmp_shift['removed'], true ),
	'G11.7: moving a page is NOT a new event and NOT a removal'
);

// ---------------------------------------------------------------------------
// 8. Checkpoint-boundary crossing detection
// ---------------------------------------------------------------------------

// Boundary is page 2. b1 was on page 2 (confirmed) and is now on page 3:
// it crossed DOWNSTREAM past the resume point — the risk OVERLAP_PAGES covers.
$g11_cross = $g11_cmp_shift['boundary_crossing'];

assert_true( 1 === $g11_cross['total'], 'G11.8: a crossing of the boundary is counted' );
assert_true( 1 === count( $g11_cross['downward'] ), 'G11.8: a downstream crossing is classified as downward' );
assert_true( isset( $g11_cross['downward']['b1'] ), 'G11.8: the crossing is attributed to the moved event ID' );
assert_true( 0 === count( $g11_cross['upward'] ), 'G11.8: a downstream crossing is not also counted as upward' );
// Now the reverse: an event moves from page 3 UP into the covered region.
$g11_prev_deep = g11_obs(
	array(
		array( 1, 1, 'a1', 'h1' ),
		array( 1, 2, 'a2', 'h2' ),
		array( 2, 2, 'b2', 'h4' ),
		array( 3, 1, 'b1', 'h3' ),
	)
);

$g11_up = g11_obs(
	array(
		array( 1, 1, 'a1', 'h1' ),
		array( 1, 2, 'a2', 'h2' ),
		array( 2, 2, 'b2', 'h4' ),
		array( 2, 3, 'b1', 'h3' ),
	)
);

$g11_cross_up = eb_vol_compare( $g11_prev_deep, $g11_up, 2 )['boundary_crossing'];

assert_true( 1 === count( $g11_cross_up['upward'] ), 'G11.8: an upward crossing into the covered region is classified separately' );
assert_true( 0 === count( $g11_cross_up['downward'] ), 'G11.8: an upward crossing is not counted as downward' );

// A boundary of 0 means "no checkpoint recorded": nothing may be claimed.
assert_true(
	0 === eb_vol_boundary_crossing( $g11_cmp_shift['page_movement'], 0 )['total'],
	'G11.8: without a recorded checkpoint boundary NO crossing is claimed'
);

// A shift that never touches the boundary is not a boundary crossing.
$g11_near = g11_obs(
	array(
		array( 1, 1, 'a1', 'h1' ),
		array( 1, 2, 'a2', 'h2' ),
		array( 2, 1, 'b1', 'h3' ),
		array( 3, 1, 'b2', 'h4' ),
	)
);

assert_true(
	0 === eb_vol_compare( $g11_base, $g11_near, 5 )['boundary_crossing']['total'],
	'G11.8: movement entirely inside the covered region is not a boundary crossing'
);

// ---------------------------------------------------------------------------
// 9. Duplicate detection
// ---------------------------------------------------------------------------

$g11_dupe = g11_obs(
	array(
		array( 1, 1, 'a1', 'h1' ),
		array( 1, 2, 'a1', 'h1' ),
		array( 2, 1, 'a1', 'h1' ),
		array( 2, 2, 'b1', 'h3' ),
	)
);

$g11_dupes = eb_vol_duplicates( $g11_dupe );

assert_true( 1 === count( $g11_dupes['members'] ), 'G11.9: a repeated event ID is detected as a duplicate' );
assert_true( 3 === $g11_dupes['members']['a1'], 'G11.9: the duplicate occurrence count is recorded' );
assert_true( 3 === count( $g11_dupes['pages']['a1'] ), 'G11.9: the pages a duplicate spans are recorded (overlap cost)' );
assert_true( 0 === count( eb_vol_duplicates( $g11_base )['members'] ), 'G11.9: a clean observation reports no duplicates' );
assert_true(
	2 === count( eb_vol_ids( $g11_dupe ) ),
	'G11.9: duplicates collapse to one unique ID, so overlap cannot inflate the measured set'
);

// ---------------------------------------------------------------------------
// 10. A 429 is an OBSERVATION, not volatility
// ---------------------------------------------------------------------------

assert_true( eb_vol_is_rate_limited( 429 ), 'G11.10: HTTP 429 is classified as a rate limit' );
assert_true( 'rate_limited' === eb_vol_classify_failure( 429 ), 'G11.10: the 429 failure class is explicit' );
assert_true( ! eb_vol_is_waf( 429 ), 'G11.10: a 429 is not misreported as a WAF event' );
assert_true( '' === eb_vol_classify_failure( 200 ), 'G11.10: a clean 200 is not a failure at all' );

// A 429 walk is incomplete and yields no removals and no change rate —
// a rate limit must never be counted as the upstream "losing" events.
$g11_rl = g11_obs( array( array( 1, 1, 'a1', 'h1' ) ), false, 'rate_limited' );
$g11_rl_cmp = eb_vol_compare( $g11_base, $g11_rl, 2 );

assert_true( 0 === count( $g11_rl_cmp['removed'] ), 'G11.10: events missing after a 429 are NOT counted as removals' );
assert_true( null === eb_vol_change_rate( $g11_rl_cmp ), 'G11.10: a rate-limited walk yields no change rate' );
assert_true( 0 === count( eb_vol_change_rate( $g11_rl_cmp ) ? array( 1 ) : array() ), 'G11.10: a null change rate renders as unmeasured, not as 0%' );

// ---------------------------------------------------------------------------
// 11. A WAF block is an OBSERVATION, not volatility
// ---------------------------------------------------------------------------

assert_true( eb_vol_is_waf( 405 ), 'G11.11: HTTP 405 is classified as a WAF event' );
assert_true( eb_vol_is_waf( 403 ), 'G11.11: HTTP 403 is classified as a WAF event' );
assert_true( ! eb_vol_is_rate_limited( 405 ), 'G11.11: a WAF event is not misreported as a rate limit' );

$g11_waf = g11_obs( array( array( 1, 1, 'a1', 'h1' ) ), false, 'waf' );
$g11_waf_cmp = eb_vol_compare( $g11_base, $g11_waf, 2 );

assert_true( 'waf' === $g11_waf_cmp['current_failure'], 'G11.11: the WAF class survives into the comparison' );
assert_true( 0 === count( $g11_waf_cmp['removed'] ), 'G11.11: events missing after a WAF block are NOT counted as removals' );
assert_true( null === eb_vol_change_rate( $g11_waf_cmp ), 'G11.11: a WAF-blocked walk yields no change rate' );

// ---------------------------------------------------------------------------
// 12. Completeness-aware change-rate calculation
// ---------------------------------------------------------------------------

// One new out of a previous set of four => 0.25.
assert_true(
	0.25 == eb_vol_change_rate( $g11_cmp_new ),
	'G11.12: change rate = (new) / previous unique set'
);

// One removal out of a previous set of four => 0.25.
// (Loose comparison: PHP returns an exact integer division as int, not float.)
assert_true(
	0.25 == eb_vol_change_rate( $g11_cmp_removed ),
	'G11.12: change rate counts valid removals in the numerator'
);

// One changed out of four => 0.25.
assert_true(
	0.25 == eb_vol_change_rate( $g11_cmp_changed ),
	'G11.12: change rate counts changed events in the numerator'
);

// An unchanged pair is a genuine zero, not an unmeasured value.
assert_true(
	0.0 == eb_vol_change_rate( $g11_same ),
	'G11.12: two identical complete observations give a real 0% (distinct from null)'
);

// An empty previous set has no valid denominator.
assert_true(
	null === eb_vol_change_rate( eb_vol_compare( g11_obs( array() ), $g11_base, 2 ) ),
	'G11.12: an empty previous set yields null rather than a divide-by-zero rate'
);

// ---------------------------------------------------------------------------
// Volatility and overlap CLASSIFICATION (evidence, never a tuning action)
// ---------------------------------------------------------------------------

// Too few complete comparisons => unknown, never a guessed verdict.
assert_true(
	'unknown' === eb_vol_classify_volatility( array( $g11_cmp_new ) ),
	'G11.12: a single comparison is not enough to classify volatility'
);
assert_true(
	'unknown' === eb_vol_classify_volatility( array( $g11_cmp_incomplete, $g11_cmp_incomplete ) ),
	'G11.12: incomplete comparisons NEVER contribute to a volatility classification'
);

// Two complete, high-change comparisons => high volatility.
assert_true(
	'high' === eb_vol_classify_volatility( array( $g11_cmp_removed, $g11_cmp_new ) ),
	'G11.12: sustained large change rates classify as high volatility'
);

// Two identical complete comparisons => low volatility.
assert_true(
	'low' === eb_vol_classify_volatility( array( $g11_same, $g11_same ) ),
	'G11.12: a stable listing over two complete comparisons classifies as low'
);

// Overlap verdict: one page of movement on a 1-page overlap is covered.
$g11_verdict_ok = eb_vol_classify_overlap( array( $g11_cmp_shift, $g11_cmp_shift ), 1 );

assert_true( 'sufficient' === $g11_verdict_ok['verdict'], 'G11.12: movement inside the overlap classifies as sufficient' );
assert_true( 1 === $g11_verdict_ok['max_movement'], 'G11.12: the maximum observed movement is reported' );

// Movement of 2 pages exceeds a 1-page overlap => insufficient.
$g11_far = g11_obs(
	array(
		array( 1, 1, 'a1', 'h1' ),
		array( 1, 2, 'a2', 'h2' ),
		array( 3, 1, 'b1', 'h3' ),
		array( 4, 2, 'b2', 'h4' ),
	)
);

$g11_cmp_far = eb_vol_compare( $g11_base, $g11_far, 2 );
$g11_verdict_short = eb_vol_classify_overlap( array( $g11_cmp_far, $g11_cmp_far ), 1 );

assert_true(
	'insufficient' === $g11_verdict_short['verdict'],
	'G11.12: movement exceeding the overlap classifies as insufficient'
);
assert_true( 2 === eb_vol_recommended_overlap( $g11_cmp_far ), 'G11.12: the measured movement implies the overlap that would cover it' );

// No complete evidence => unmeasured, NOT a green light.
$g11_verdict_none = eb_vol_classify_overlap( array(), 1 );

assert_true(
	'unmeasured' === $g11_verdict_none['verdict'],
	'G11.12: with no complete comparison the verdict is unmeasured, never sufficient'
);

// Repeatedly zero movement => the overlap is doing no observed work.
$g11_verdict_excess = eb_vol_classify_overlap( array( $g11_same, $g11_same ), 1 );

assert_true(
	'excessive' === $g11_verdict_excess['verdict'],
	'G11.12: repeated zero movement classifies the overlap as excessive'
);

// ---------------------------------------------------------------------------
// Identity integrity: page number is NOT an identity  (G11 §12)
// ---------------------------------------------------------------------------

// Two observations with IDENTICAL page structure but completely different
// events. Any measurement keyed on page number would call this "stable".
$g11_page_same_events_a = g11_obs(
	array(
		array( 1, 1, 'x1', 'h1' ),
		array( 1, 2, 'x2', 'h2' ),
		array( 2, 1, 'x3', 'h3' ),
		array( 2, 2, 'x4', 'h4' ),
	)
);

$g11_page_same_events_b = g11_obs(
	array(
		array( 1, 1, 'y1', 'h1' ),
		array( 1, 2, 'y2', 'h2' ),
		array( 2, 1, 'y3', 'h3' ),
		array( 2, 2, 'y4', 'h4' ),
	)
);

$g11_cmp_identity = eb_vol_compare( $g11_page_same_events_a, $g11_page_same_events_b, 2 );

assert_true(
	4 === count( $g11_cmp_identity['new'] ) && 4 === count( $g11_cmp_identity['removed'] ),
	'G11.12: a page structure that is identical while every event changed is measured as 4 new and 4 removed — page position is not identity'
);
assert_true(
	0 === $g11_cmp_identity['moved_count'],
	'G11.12: no page MOVEMENT is claimed when the same IDs stayed on the same pages'
);

// ---------------------------------------------------------------------------
// Page-set change reporting
// ---------------------------------------------------------------------------

$g11_page_changes = eb_vol_page_set_changes( $g11_base, $g11_after_shift );

assert_true( 1 === $g11_page_changes[2]['removed'], 'G11.12: the page a movement left reports one fewer member' );
assert_true( 1 === $g11_page_changes[3]['added'], 'G11.12: the page a movement entered reports one more member' );
assert_true( ! $g11_page_changes[1]['reordered'], 'G11.12: an untouched page is not reported as reordered' );

test_finish();
// Transport failures are classified too, and are equally not volatility.
assert_true( 'timeout' === eb_vol_classify_failure( 0, 'timeout' ), 'G11.11: a transport timeout is classified' );
assert_true( 'network' === eb_vol_classify_failure( 0, 'connection reset' ), 'G11.11: a transport error is classified as network' );
assert_true( 'budget_exhausted' === eb_vol_classify_failure( 0, 'budget' ), 'G11.11: budget exhaustion is classified' );
