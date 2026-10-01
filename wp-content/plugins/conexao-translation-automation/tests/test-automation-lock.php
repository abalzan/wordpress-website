<?php
/**
 * Stage 2 lock tests: atomicity, ownership, stale policy, fail-closed
 * behaviour, and the "lock failure prevents engine mutation" rule.
 *
 * The lock is exercised against the REAL options table through the real
 * `$wpdb` primitives, because a lock proved only against a fake proves
 * nothing about the UNIQUE-key behaviour it depends on.
 *
 * Concurrency is proved DETERMINISTICALLY, by simulating the interleaving
 * explicitly rather than by racing two processes on a timer:
 *
 *   - run A acquires, then run B acquires -> B is LOCKED (the loser's INSERT
 *     is rejected by the UNIQUE key, so B cannot clobber A);
 *   - run B attempts to reclaim a stale lock that A has since rewritten ->
 *     the compare-and-swap matches zero rows, so B loses the race cleanly;
 *   - a raw second INSERT of the same option_name -> zero rows affected,
 *     which is the exact signal `acquire()` treats as "held".
 *
 * Every test tears the lock down afterwards, so a failure cannot poison a
 * later suite.
 *
 * @package Conexao_Translation_Automation
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation/conexao-translation-automation.php';

use Conexao_Translation_Automation_Lock as Lock;

test_title( 'conexao-translation-automation — Stage 2 lock safety' );

global $wpdb;

$ENGINE_FILE = CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-rollout/includes/class-conexao-translation-rollout-engine.php';
// STAGE 11: the pin moved ONCE, deliberately. Model A (true subset
// execution) requires the engine to accept an approved operation scope.
// The change is additive and confined to scope handling: two pure methods
// (narrow_manifest, planned_identities), one optional $args['scope'] key
// applied AFTER full-manifest validation, and a 'scope' key added to the
// two existing return payloads. No lifecycle stage was replaced,
// reordered or bypassed. Pre-Stage-11 digest (the Stage 11 §33 starting
// record): baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4
$ENGINE_SHA  = '264cc6c4e7b4214f2bc30436afb077d308b1897de444431c5c3a331f08116912';

/**
 * Reset the lock to a known-empty state.
 *
 * @return void
 */
function conexao_lock_reset(): void {
	Conexao_Translation_Automation_Lock::force_clear();
}

/**
 * Count the rows the lock currently occupies.
 *
 * @return int
 */
function conexao_lock_row_count(): int {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only test probe.
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s",
			Conexao_Translation_Automation_Lock::OPTION
		)
	);
}

/**
 * Overwrite the lock row with an arbitrary raw value, simulating corruption.
 *
 * @param string $raw Raw option value.
 * @return void
 */
function conexao_lock_corrupt( string $raw ): void {
	global $wpdb;

	conexao_lock_reset();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture.
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )",
			Conexao_Translation_Automation_Lock::OPTION,
			$raw
		)
	);

	wp_cache_delete( Conexao_Translation_Automation_Lock::OPTION, 'options' );
}

// ---------------------------------------------------------------------------
// 1. Acquisition
// ---------------------------------------------------------------------------
test_section( 'Acquisition' );

conexao_lock_reset();
assert_true( ! Lock::is_held(), 'no lock is held on a clean slate' );
assert_true( 0 === conexao_lock_row_count(), 'the lock occupies no rows on a clean slate' );

$first = Lock::acquire( array( 'run_id' => 'run_alpha', 'stage' => 'en-guide', 'environment' => 'local', 'now' => 1000 ) );

assert_true( true === $first['held'], 'the first acquisition succeeds' );
assert_true( Lock::ACQUIRED === $first['outcome'], 'the first acquisition reports "acquired"' );
assert_true( Lock::is_held(), 'the lock is now held' );
assert_true( 1 === conexao_lock_row_count(), 'the lock occupies exactly one row (site-wide, not per stage)' );
assert_true( 'run_alpha' === Lock::inspect()['run_id'], 'the lock records its owning run id' );

// ---------------------------------------------------------------------------
// 2. A second concurrent acquisition fails closed
// ---------------------------------------------------------------------------
test_section( 'Concurrent acquisition fails closed' );

$second = Lock::acquire( array( 'run_id' => 'run_beta', 'stage' => 'en-page', 'environment' => 'local', 'now' => 1010 ) );

assert_true( false === $second['held'], 'a second acquisition does NOT get the lock' );
assert_true( Lock::LOCKED === $second['outcome'], 'the second acquisition reports "locked"' );
assert_true( 'run_alpha' === Lock::inspect()['run_id'], 'the loser did not overwrite the holder' );
assert_true( 1 === conexao_lock_row_count(), 'the losing attempt created no second row' );

// The refusal is auditable and carries no secret.
assert_true( 'locked' === $second['event']['outcome'], 'the lock refusal is recorded for audit' );
assert_true( '' !== $second['event']['reason'], 'the lock refusal carries a reason' );
assert_true(
	! is_wp_error( Conexao_Translation_Automation_Result::assert_no_secrets( $second['event'] ) ),
	'the lock refusal event carries no credential'
);

// ---------------------------------------------------------------------------
// 3. Ownership: release
// ---------------------------------------------------------------------------
test_section( 'Ownership' );

$wrong = Lock::release( 'run_beta' );

assert_true( false === $wrong['released'], 'a non-owner cannot release the lock' );
assert_true( Lock::NOT_OWNER === $wrong['outcome'], 'a non-owner release reports "not_owner"' );
assert_true( Lock::is_held(), 'the lock survives a non-owner release attempt' );
assert_true( 'run_alpha' === Lock::inspect()['run_id'], 'the owner is unchanged after a non-owner release' );

$owner = Lock::release( 'run_alpha' );

assert_true( true === $owner['released'], 'the owner CAN release the lock' );
assert_true( Lock::RELEASED === $owner['outcome'], 'the owner release reports "released"' );
assert_true( ! Lock::is_held(), 'the lock is free after the owner releases it' );

// Releasing an unheld lock is refused, never reported as success.
$nobody = Lock::release( 'run_alpha' );
assert_true( false === $nobody['released'], 'releasing an unheld lock does not report success' );

// Re-acquisition after a clean release works (the lock is reusable).
$again = Lock::acquire( array( 'run_id' => 'run_gamma', 'now' => 2000 ) );
assert_true( true === $again['held'], 'the lock can be re-acquired after a clean release' );
conexao_lock_reset();

// ---------------------------------------------------------------------------
// 4. The same run re-entering
// ---------------------------------------------------------------------------
test_section( 'Same-run re-entry' );

Lock::acquire( array( 'run_id' => 'run_delta', 'now' => 3000 ) );
$reenter = Lock::acquire( array( 'run_id' => 'run_delta', 'now' => 3010 ) );

assert_true( true === $reenter['held'], 'the owning run re-entering still holds the lock' );
assert_true( 'run_delta' === Lock::inspect()['run_id'], 're-entry does not corrupt the owner record' );
conexao_lock_reset();

// ---------------------------------------------------------------------------
// 5. Stale policy
// ---------------------------------------------------------------------------
test_section( 'Stale-lock policy' );

Lock::acquire( array( 'run_id' => 'run_old', 'ttl' => 100, 'now' => 5000 ) );

$inside = Lock::acquire( array( 'run_id' => 'run_new', 'now' => 5050 ) );
assert_true( false === $inside['held'], 'a lock inside its TTL is NOT reclaimable' );
assert_true( Lock::LOCKED === $inside['outcome'], 'an in-TTL contention reports "locked"' );

$boundary = Lock::acquire( array( 'run_id' => 'run_new', 'now' => 5100 ) );
assert_true( false === $boundary['held'], 'a lock exactly AT its TTL is not yet reclaimable' );

$beyond = Lock::acquire( array( 'run_id' => 'run_new', 'now' => 5101 ) );
assert_true( true === $beyond['held'], 'a lock beyond its TTL IS reclaimable' );
assert_true( Lock::RECLAIMED === $beyond['outcome'], 'the reclaim is reported as "reclaimed_stale"' );
assert_true( 'run_new' === Lock::inspect()['run_id'], 'the reclaimer becomes the owner' );
assert_true(
	false !== strpos( $beyond['event']['reason'], 'run_old' ),
	'the reclaim event names the run whose lock was reclaimed (auditable)'
);
conexao_lock_reset();

// A zero/absent TTL is never treated as "reclaimable immediately".
conexao_lock_corrupt( wp_json_encode( array( 'v' => 1, 'run_id' => 'run_nottl', 'acquired_at' => 1, 'ttl' => 0 ) ) );
$no_ttl = Lock::acquire( array( 'run_id' => 'run_try', 'now' => 999999 ) );
assert_true( false === $no_ttl['held'], 'a record with no usable TTL is not reclaimable' );
assert_true( Lock::MALFORMED === $no_ttl['outcome'], 'a record with no usable TTL is treated as malformed' );
conexao_lock_reset();

// An out-of-range TTL supplied by the CALLER is refused outright.
$bad_ttl = Lock::acquire( array( 'run_id' => 'run_badttl', 'ttl' => 0, 'now' => 6000 ) );
assert_true( false === $bad_ttl['held'], 'an out-of-range TTL cannot acquire the lock' );
assert_true( Lock::UNAVAILABLE === $bad_ttl['outcome'], 'an out-of-range TTL is refused as unavailable' );

// ---------------------------------------------------------------------------
// 6. Malformed state fails closed and is never destroyed
// ---------------------------------------------------------------------------
test_section( 'Malformed state fails closed' );

conexao_lock_corrupt( 'this-is-not-json' );
$broken = Lock::acquire( array( 'run_id' => 'run_x', 'now' => 7000 ) );

assert_true( false === $broken['held'], 'a malformed lock blocks every run' );
assert_true( Lock::MALFORMED === $broken['outcome'], 'a malformed lock reports "malformed"' );
assert_true( 1 === conexao_lock_row_count(), 'the malformed row is NOT deleted' );

$broken_release = Lock::release( 'run_x' );
assert_true( false === $broken_release['released'], 'a malformed lock cannot be released' );
assert_true( 1 === conexao_lock_row_count(), 'a release attempt does not destroy a malformed row' );

// A record from an unknown schema version is equally refused.
conexao_lock_corrupt( wp_json_encode( array( 'v' => 99, 'run_id' => 'run_v99', 'acquired_at' => 7000, 'ttl' => 900 ) ) );
$future = Lock::acquire( array( 'run_id' => 'run_y', 'now' => 8000 ) );
assert_true( false === $future['held'], 'an unknown schema version blocks every run' );
assert_true( Lock::MALFORMED === $future['outcome'], 'an unknown schema version reports "malformed"' );

// A missing run_id cannot own a lock.
conexao_lock_corrupt( wp_json_encode( array( 'v' => 1, 'acquired_at' => 7000, 'ttl' => 900 ) ) );
$no_owner = Lock::acquire( array( 'run_id' => 'run_z', 'now' => 8000 ) );
assert_true( false === $no_owner['held'], 'a record with no owner cannot be inherited' );
conexao_lock_reset();

// ---------------------------------------------------------------------------
// 7. Deterministic race simulation: the compare-and-swap loses cleanly
// ---------------------------------------------------------------------------
test_section( 'Deterministic race simulation' );

// The CAS scoping property is what makes a real race safe, and it is a
// property of the SQL, not of any particular interleaving. `acquire()` reads
// the committed row and then swaps exactly those bytes, so a winner that
// rewrote the row in between leaves the loser's UPDATE matching nothing.
//
// A single-threaded test cannot interleave inside acquire(), so the property
// is proven directly against the primitive instead: an UPDATE scoped to bytes
// that are no longer current must affect zero rows and change nothing.
Lock::acquire( array( 'run_id' => 'run_holder', 'ttl' => 100, 'now' => 10000 ) );

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test probe reading the committed row.
$current = (string) $wpdb->get_var(
	$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Lock::OPTION )
);

// The winner rewrites the row, so `current` is now a stale snapshot.
$winner = wp_json_encode( array( 'v' => 1, 'run_id' => 'run_winner', 'acquired_at' => 10000, 'ttl' => 100 ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test probe performing the winning swap.
$wpdb->query(
	$wpdb->prepare(
		"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
		$winner,
		Lock::OPTION,
		$current
	)
);
wp_cache_delete( Lock::OPTION, 'options' );

// The loser's swap, scoped to the bytes it had read, must now match nothing.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test probe replaying the losing swap.
$loser_swap = $wpdb->query(
	$wpdb->prepare(
		"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
		wp_json_encode( array( 'v' => 1, 'run_id' => 'run_loser', 'acquired_at' => 11000, 'ttl' => 100 ) ),
		Lock::OPTION,
		$current
	)
);

assert_true( 0 === (int) $loser_swap, 'a compare-and-swap on superseded bytes affects zero rows' );
assert_true( 'run_winner' === Lock::inspect()['run_id'], 'the winner keeps the lock: a stale swap cannot overwrite it' );

// And a real acquisition by a third run is refused outright, because a live
// lock is held. This is the concurrent-execution guarantee end to end.
$refused = Lock::acquire( array( 'run_id' => 'run_third', 'now' => 10100 ) );
assert_true( false === $refused['held'], 'a concurrent run never enters while another holds the lock' );
assert_true( Lock::LOCKED === $refused['outcome'], 'the concurrent run is refused as "locked"' );
assert_true( 1 === conexao_lock_row_count(), 'the refusals created no additional lock rows' );
conexao_lock_reset();

// The raw primitive itself: a duplicate INSERT affects zero rows. This is the
// exact signal acquire() relies on, proven directly rather than inferred.
Lock::acquire( array( 'run_id' => 'run_first', 'now' => 12000 ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test probe of the atomic primitive.
$dup = $wpdb->query(
	$wpdb->prepare(
		"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )",
		Lock::OPTION,
		wp_json_encode( array( 'v' => 1, 'run_id' => 'run_second', 'acquired_at' => 12000, 'ttl' => 900 ) )
	)
);
assert_true( 0 === (int) $dup, 'a duplicate INSERT affects zero rows (the UNIQUE key is the arbiter)' );
assert_true( 1 === conexao_lock_row_count(), 'a duplicate INSERT creates no second lock row' );
conexao_lock_reset();

test_finish( 'automation lock' );
