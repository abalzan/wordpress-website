<?php
/**
 * Automated tests for the Conexao_Import_Log write-amplification optimization.
 *
 * Verifies that run-level buffering is transparent to callers: entry format,
 * ordering, cap (2000), 90-day retention, sensitive-key filtering, and
 * no-fail semantics are unchanged — while the number of option writes per
 * import run drops from one-per-entry to one (plus rare threshold flushes).
 *
 * Usage: docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/test-import-log.php
 */


// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$passed = 0;
$failed = 0;

// Count successful update_option writes for the log option.
$GLOBALS['log_writes'] = 0;
add_action(
	'updated_option',
	function ( $option ) {
		if ( Conexao_Import_Log::OPTION_KEY === $option ) {
			$GLOBALS['log_writes']++;
		}
	},
	10,
	1
);

function log_writes() {
	return $GLOBALS['log_writes'];
}

function reset_log_writes() {
	$GLOBALS['log_writes'] = 0;
}


// ---------------------------------------------------------------------------
// Test 1: Single log entry (outside a managed run — legacy immediate write).
// ---------------------------------------------------------------------------
test_section( 'Single log entry' );

Conexao_Import_Log::clear();
reset_log_writes();

Conexao_Import_Log::add( 'eventbrite', 'info', 'Single entry test', array( 'run_id' => 'run-single' ) );

$all = Conexao_Import_Log::get_all();
assert_true( 1 === count( $all ), 'Single entry is persisted immediately' );
assert_true( 'Single entry test' === $all[0]['message'], 'Entry message stored' );
assert_true( 'eventbrite' === $all[0]['source'], 'Entry source stored' );
assert_true( 'run-single' === $all[0]['run_id'], 'Entry run_id stored' );
assert_true( 'info' === $all[0]['level'], 'Entry level stored' );
assert_true( 1 === log_writes(), 'Single entry causes exactly one option write' );

// Empty messages are never logged (unchanged semantics).
Conexao_Import_Log::add( 'eventbrite', 'info', '   ' );
assert_true( 1 === count( Conexao_Import_Log::get_all() ), 'Empty message is ignored' );

// ---------------------------------------------------------------------------
// Test 2: Buffered run — 150 entries, multiple sources, one bounded write.
// ---------------------------------------------------------------------------
test_section( 'Buffered run: 150 entries, multiple sources' );

Conexao_Import_Log::clear();
reset_log_writes();

$run_id = 'buffered-' . uniqid();
Conexao_Import_Log::begin_run();
for ( $i = 1; $i <= 150; $i++ ) {
	$source = ( 0 === $i % 3 ) ? 'heritage-week' : ( ( 0 === $i % 2 ) ? 'laois-tourism' : 'eventbrite' );
	$level  = ( 0 === $i % 25 ) ? 'error' : 'info';
	Conexao_Import_Log::add( $source, $level, sprintf( 'entry %03d', $i ), array( 'run_id' => $run_id ) );
}
assert_true( 0 === count( Conexao_Import_Log::get_all() ), 'Buffered entries are not yet persisted mid-run' );

Conexao_Import_Log::end_run();

$all = Conexao_Import_Log::get_all();
assert_true( 150 === count( $all ), 'All 150 entries persisted after end_run()' );
assert_true( 'entry 150' === $all[0]['message'], 'Ordering preserved: newest entry first' );
assert_true( 'entry 001' === $all[149]['message'], 'Ordering preserved: oldest entry last' );
assert_true( 'heritage-week' === $all[0]['source'] && 'laois-tourism' === $all[2]['source'] && 'eventbrite' === $all[1]['source'], 'Multiple sources stored correctly' );
assert_true( 1 === log_writes(), '150-entry run causes exactly ONE option write (was 150)' );

$run_entries = Conexao_Import_Log::get_for_run( $run_id, 1000 );
assert_true( 150 === count( $run_entries ), 'get_for_run() returns all buffered entries' );
$run_ids = Conexao_Import_Log::get_run_ids( 5 );
assert_true( in_array( $run_id, $run_ids, true ), 'get_run_ids() sees the buffered run' );

// ---------------------------------------------------------------------------
// Test 3: Nested runs (run_all wrapping run_source) flush only once.
// ---------------------------------------------------------------------------
test_section( 'Nested runs flush once' );

Conexao_Import_Log::clear();
reset_log_writes();

Conexao_Import_Log::begin_run(); // outermost (run_all)
Conexao_Import_Log::add( 'eventbrite', 'info', 'outer start', array( 'run_id' => 'nested' ) );
Conexao_Import_Log::begin_run(); // inner (run_source)
Conexao_Import_Log::add( 'eventbrite', 'info', 'inner entry', array( 'run_id' => 'nested' ) );
Conexao_Import_Log::end_run(); // inner end — no flush yet
assert_true( 0 === count( Conexao_Import_Log::get_all() ), 'Inner end_run() does not flush' );
Conexao_Import_Log::add( 'laois-tourism', 'warning', 'after inner end', array( 'run_id' => 'nested' ) );
Conexao_Import_Log::end_run(); // outermost end — flushes

$all = Conexao_Import_Log::get_all();
assert_true( 3 === count( $all ), 'All nested entries persisted' );
assert_true( 'after inner end' === $all[0]['message'], 'Nested ordering preserved' );
assert_true( 1 === log_writes(), 'Nested run causes exactly ONE option write' );

// ---------------------------------------------------------------------------
// Test 4: Threshold flush keeps memory bounded and ordering intact (300 entries).
// ---------------------------------------------------------------------------
test_section( 'Threshold flush: 300 entries' );

Conexao_Import_Log::clear();
reset_log_writes();

Conexao_Import_Log::begin_run();
for ( $i = 1; $i <= 300; $i++ ) {
	Conexao_Import_Log::add( 'eventbrite', 'debug', sprintf( 'bulk %03d', $i ), array( 'run_id' => 'bulk' ) );
}
Conexao_Import_Log::end_run();

$all = Conexao_Import_Log::get_all();
assert_true( 300 === count( $all ), 'All 300 entries persisted' );
assert_true( 'bulk 300' === $all[0]['message'] && 'bulk 001' === $all[299]['message'], 'Ordering preserved across threshold flushes' );
assert_true( log_writes() <= 2, '300-entry run causes at most 2 writes (threshold + final): got ' . log_writes() );

// ---------------------------------------------------------------------------
// Test 5: Entries logged outside a managed run are never lost.
// ---------------------------------------------------------------------------
test_section( 'Ad-hoc entries outside a run' );

reset_log_writes();
Conexao_Import_Log::add( 'cleanup', 'info', 'ad-hoc entry A', array() );
Conexao_Import_Log::add( 'cleanup', 'warning', 'ad-hoc entry B', array() );

$all = Conexao_Import_Log::get_all();
assert_true( 'ad-hoc entry B' === $all[0]['message'], 'Ad-hoc entry visible immediately' );
assert_true( 2 === log_writes(), 'Ad-hoc entries write immediately (legacy behavior)' );

// ---------------------------------------------------------------------------
// Test 6: Failed source entries + severity/normalization preserved.
// ---------------------------------------------------------------------------
test_section( 'Failed source and severity' );

Conexao_Import_Log::clear();
Conexao_Import_Log::begin_run();
Conexao_Import_Log::add( 'eventbrite', 'info', 'Import started for Eventbrite.', array( 'run_id' => 'fail-run' ) );
Conexao_Import_Log::add( 'eventbrite', 'error', 'Failed to fetch events from Eventbrite. HTTP status: 403.', array(
	'run_id'      => 'fail-run',
	'http_status' => 403,
) );
Conexao_Import_Log::add( 'laois-tourism', 'warning', 'bogus level check', array( 'run_id' => 'fail-run' ) );
Conexao_Import_Log::add( 'laois-tourism', 'info', 'Import finished: created=3 updated=1.', array( 'run_id' => 'fail-run' ) );
Conexao_Import_Log::end_run();

$all = Conexao_Import_Log::get_all();
assert_true( 'error' === $all[2]['level'], 'Error severity preserved' );
assert_true( 403 === $all[2]['http_status'], 'HTTP status preserved' );
assert_true( 'warning' === $all[1]['level'], 'Warning severity preserved' );
assert_true(
	'Failed to fetch events from Eventbrite. HTTP status: 403.' === Conexao_Import_Log::get_last_error( 'eventbrite' ),
	'get_last_error() finds the failed-source error'
);

// Unknown level normalizes to info (unchanged semantics).
Conexao_Import_Log::add( 'eventbrite', 'bogus-level', 'normalization check', array() );
assert_true( 'info' === Conexao_Import_Log::get_all()[0]['level'], 'Unknown level normalizes to info' );

// ---------------------------------------------------------------------------
// Test 7: Sensitive-key filtering still applied before persistence.
// ---------------------------------------------------------------------------
test_section( 'Sensitive data filtering' );

Conexao_Import_Log::clear();
Conexao_Import_Log::add( 'eventbrite', 'error', 'Request failed', array(
	'token'         => 'SECRET-TOKEN',
	'password'      => 'hunter2',
	'api_key'       => 'KEY123',
	'authorization' => 'Bearer abc',
	'client_secret' => 'shhh',
	'url'           => 'https://example.com/feed',
	'attempt'       => 2,
	'run_id'        => 'sens-run',
) );

$entry      = Conexao_Import_Log::get_all()[0];
$serialized = wp_json_encode( $entry );
assert_true( false === strpos( $serialized, 'SECRET-TOKEN' ), 'token stripped' );
assert_true( false === strpos( $serialized, 'hunter2' ), 'password stripped' );
assert_true( false === strpos( $serialized, 'KEY123' ), 'api_key stripped' );
assert_true( false === strpos( $serialized, 'Bearer abc' ), 'authorization stripped' );
assert_true( false === strpos( $serialized, 'shhh' ), 'client_secret stripped' );
assert_true( 'https://example.com/feed' === $entry['context']['url'], 'Safe context key (url) preserved' );
assert_true( '2' === $entry['context']['attempt'], 'Safe context key (attempt) preserved' );

// Buffered entries are filtered too (filtering happens in add(), pre-buffer).
Conexao_Import_Log::begin_run();
Conexao_Import_Log::add( 'eventbrite', 'info', 'buffered secret test', array( 'api_key' => 'BUFFERED-KEY' ) );
Conexao_Import_Log::end_run();
$entry = Conexao_Import_Log::get_all()[0];
assert_true( false === strpos( wp_json_encode( $entry ), 'BUFFERED-KEY' ), 'Sensitive key stripped from buffered entries' );

// ---------------------------------------------------------------------------
// Test 8: 2000-entry cap is respected; newest entries kept.
// ---------------------------------------------------------------------------
test_section( '2000-entry cap' );

Conexao_Import_Log::clear();
Conexao_Import_Log::begin_run();
for ( $i = 1; $i <= 2050; $i++ ) {
	Conexao_Import_Log::add( 'eventbrite', 'info', sprintf( 'cap %04d', $i ), array( 'run_id' => 'cap-run' ) );
}
Conexao_Import_Log::end_run();

$all = Conexao_Import_Log::get_all();
assert_true( 2000 === count( $all ), 'Cap respected: exactly 2000 entries kept (got ' . count( $all ) . ')' );
assert_true( 'cap 2050' === $all[0]['message'], 'Newest entry kept' );
assert_true( 'cap 0051' === $all[1999]['message'], 'Oldest surviving entry is #51 (2050 - 2000 + 1)' );

// ---------------------------------------------------------------------------
// Test 9: 90-day retention (also covers buffered entries).
// ---------------------------------------------------------------------------
test_section( '90-day retention' );

Conexao_Import_Log::clear();

$old_time   = gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS );
$fresh_time = current_time( 'mysql' );
$seed       = array(
	array(
		'time' => $old_time, 'source' => 'eventbrite', 'level' => 'info', 'message' => 'ancient entry',
		'run_id' => '', 'event_id' => '', 'event_title' => '', 'http_status' => 0, 'context' => array(),
	),
	array(
		'time' => $fresh_time, 'source' => 'eventbrite', 'level' => 'info', 'message' => 'fresh entry',
		'run_id' => '', 'event_id' => '', 'event_title' => '', 'http_status' => 0, 'context' => array(),
	),
);
update_option( Conexao_Import_Log::OPTION_KEY, $seed, false );

Conexao_Import_Log::begin_run();
Conexao_Import_Log::add( 'eventbrite', 'info', 'buffered before prune', array() );
$removed = Conexao_Import_Log::prune_older_than_90_days();
Conexao_Import_Log::end_run();

$messages = wp_list_pluck( Conexao_Import_Log::get_all(), 'message' );
assert_true( 1 === $removed, 'Exactly one ancient entry removed' );
assert_true( 2 === count( $messages ), 'Fresh + buffered entries survive retention' );
assert_true( ! in_array( 'ancient entry', $messages, true ), 'Ancient entry pruned' );
assert_true( in_array( 'fresh entry', $messages, true ), 'Fresh entry retained' );
assert_true( in_array( 'buffered before prune', $messages, true ), 'Buffered entry flushed and retained by prune' );

// ---------------------------------------------------------------------------
// Test 10: Concurrent-run merge — a flush never overwrites persisted entries
// from another run (simulated by injecting entries between flushes).
// ---------------------------------------------------------------------------
test_section( 'Concurrent run merge' );

Conexao_Import_Log::clear();
Conexao_Import_Log::begin_run(); // Run A starts buffering.
Conexao_Import_Log::add( 'eventbrite', 'info', 'run A entry 1', array( 'run_id' => 'run-a' ) );

// Simulate a concurrent process (Run B) flushing its own entries into the
// option while Run A is still buffering. Newest first, as stored.
$run_b = array(
	array(
		'time' => current_time( 'mysql' ), 'source' => 'laois-tourism', 'level' => 'info',
		'message' => 'run B entry 2', 'run_id' => 'run-b', 'event_id' => '', 'event_title' => '',
		'http_status' => 0, 'context' => array(),
	),
	array(
		'time' => current_time( 'mysql' ), 'source' => 'laois-tourism', 'level' => 'info',
		'message' => 'run B entry 1', 'run_id' => 'run-b', 'event_id' => '', 'event_title' => '',
		'http_status' => 0, 'context' => array(),
	),
);
update_option( Conexao_Import_Log::OPTION_KEY, array_merge( $run_b, Conexao_Import_Log::get_all() ), false );

Conexao_Import_Log::end_run(); // Run A flushes (re-reads and prepends).

$messages = wp_list_pluck( Conexao_Import_Log::get_all(), 'message' );
assert_true( in_array( 'run A entry 1', $messages, true ), 'Run A entry persisted' );
assert_true( in_array( 'run B entry 1', $messages, true ), 'Concurrent run B entry 1 NOT overwritten' );
assert_true( in_array( 'run B entry 2', $messages, true ), 'Concurrent run B entry 2 NOT overwritten' );
assert_true( 'run A entry 1' === $messages[0], 'Newest-first ordering across concurrent runs' );

// ---------------------------------------------------------------------------
// Test 11: clear() wipes persisted and buffered entries.
// ---------------------------------------------------------------------------
test_section( 'Clear log' );

Conexao_Import_Log::clear();
Conexao_Import_Log::begin_run();
Conexao_Import_Log::add( 'eventbrite', 'info', 'will be cleared', array() );
Conexao_Import_Log::clear(); // Clear mid-run: buffered entry dropped too.
Conexao_Import_Log::end_run();
assert_true( 0 === count( Conexao_Import_Log::get_all() ), 'clear() wipes persisted and buffered entries' );

// ---------------------------------------------------------------------------
// Test 12: Write-amplification measurement (before vs after).
// ---------------------------------------------------------------------------
test_section( 'Write amplification: before vs after' );

Conexao_Import_Log::clear();
reset_log_writes();
for ( $i = 0; $i < 200; $i++ ) {
	Conexao_Import_Log::add( 'eventbrite', 'info', "before-write {$i}", array() );
}
$before = log_writes();

Conexao_Import_Log::clear();
reset_log_writes();
Conexao_Import_Log::begin_run();
for ( $i = 0; $i < 200; $i++ ) {
	Conexao_Import_Log::add( 'eventbrite', 'info', "after-write {$i}", array( 'run_id' => 'measured' ) );
}
Conexao_Import_Log::end_run();
$after     = log_writes();
$persisted = count( Conexao_Import_Log::get_for_run( 'measured', 1000 ) );

echo sprintf(
	"  INFO: 200 log entries -> BEFORE (per-entry writes): %d option writes | AFTER (buffered run): %d option writes (%d entries persisted)\n",
	$before,
	$after,
	$persisted
);
assert_true( 200 === $before, 'Before: 200 unbuffered entries caused 200 option writes' );
assert_true( 1 === $after, 'After: 200 buffered entries caused 1 option write' );
assert_true( 200 === $persisted, 'After: all 200 entries persisted correctly' );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

Conexao_Import_Log::clear();

test_finish();
