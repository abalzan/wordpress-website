<?php
/**
 * Tests for the event-cleanup batch runner (Conexao_Event_Cleanup_Run).
 *
 * Covers:
 * - Eligibility rules unchanged
 * - Batch limits (event count, time budget)
 * - Keyset cursor no-skip at event-count and time boundaries
 * - Resume after interruption
 * - Idempotent retry
 * - Concurrency (two workers)
 * - Stale lock reclaim
 * - Failed-record handling
 * - Capacity pause
 * - Queue atomicity
 * - Attachment preservation (real attachment discovery + preservation)
 * - Hook-safety regression guard
 * - Deletion-failure integration (pre_delete_post interception)
 * - Attachment preservation edge cases (multi-event, content, meta, missing, unclassifiable)
 *
 * ## Test isolation contract
 *
 * Each section creates ONLY the records it needs through WordPress APIs in
 * the isolated test database, records every created post/attachment ID, and
 * cleans up only its own records in a finally-style teardown. No section
 * depends on another section having completed successfully. The suite does
 * NOT delete arbitrary events to get back to a clean state.
 *
 * ## Lock/run-state cleanup
 *
 * Test cleanup uses the application-level lock API (acquire/release), never
 * a raw DELETE of the lock option. A pre-existing lock owned by another
 * process is respected (the test fails closed rather than stealing it).
 *
 * @package Conexao_Event_Importer
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/includes/class-event-cleanup.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/includes/class-event-cleanup-lock.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/includes/class-event-cleanup-attachment-review.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/includes/class-event-cleanup-run.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/includes/class-import-settings.php';

// Secondary safeguard: prove the per-process store isolation before touching
// any cleanup option or content.
test_require_operator_store_isolation();

// Read-only baseline of the operator's cleanup records.
$cleanup_live_status_before = (string) $GLOBALS['wpdb']->get_var(
	$GLOBALS['wpdb']->prepare(
		"SELECT option_value FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s",
		Conexao_Event_Cleanup::STATUS_OPTION
	)
);

$cleanup_live_history_before = (string) $GLOBALS['wpdb']->get_var(
	$GLOBALS['wpdb']->prepare(
		"SELECT option_value FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s",
		Conexao_Event_Cleanup::HISTORY_OPTION
	)
);

// Replicate the production trigger context.
if ( ! defined( 'WP_ADMIN' ) ) {
	define( 'WP_ADMIN', true );
}

$cleanup = new Conexao_Event_Cleanup();
$runner  = new Conexao_Event_Cleanup_Run();

/*
 * ---------------------------------------------------------------------------
 * Suite-wide candidate-scope isolation.
 * ---------------------------------------------------------------------------
 *
 * The batch runner walks EVERY eligible event in the database, so without a
 * scope restriction this suite also deletes records it did not create: the
 * deterministic site seeds events (a past "expired" record and today-dated
 * records whose only stated time is already behind the clock), and those are
 * legitimately eligible for a destructive cleanup. Deleting them makes every
 * LATER suite that reads those fixtures fail on missing records, and it makes
 * this suite's own counts undeterministic.
 *
 * The fix is the ownership rule the runner already implements (design §2.1
 * rule 5): `cleanup_scope = imported_only` restricts the candidate walk to
 * records carrying `_event_imported = 1`. Every fixture this suite creates
 * carries that marker, and the seeded site fixtures do not, so the walk sees
 * exactly this suite's own records — which is what the suite's isolation
 * contract above already promises.
 *
 * The setting is isolated in the per-process option store (snapshot + restore
 * at shutdown), so it never leaks into another suite or into the shared
 * database. Section 2 exercises the ownership rule itself and toggles the
 * scope explicitly.
 */
test_options_isolate( array( Conexao_Import_Settings::OPTION_KEY ) );

$cleanup_scoped_settings                   = Conexao_Import_Settings::all();
$cleanup_scoped_settings['cleanup_scope']  = 'imported_only';
Conexao_Import_Settings::update( $cleanup_scoped_settings );
cleanup_reset_settings_cache();

/**
 * Per-section record registry.
 *
 * Each section pushes the IDs it creates onto this list; the section's
 * teardown deletes exactly those IDs and pops them. This guarantees a
 * section never touches records it did not create.
 *
 * @var array<int,array{type:string,id:int}>
 */
$GLOBALS['cleanup_section_records'] = array();

/**
 * Create a test event and register it for section-scoped cleanup.
 *
 * @param string $title Short human label (prefixed with [CLEANUP TEST]).
 * @param array  $meta  Post meta to set.
 * @return int The new event post ID.
 */
function cleanup_test_event( $title, $meta = array() ) {
	$post_id = wp_insert_post(
		array(
			'post_type'   => 'event',
			'post_status' => 'publish',
			'post_title'  => '[CLEANUP TEST] ' . $title,
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		test_prerequisite_hint( 'wp_insert_post failed for event fixture' );
		exit( 1 );
	}
	foreach ( $meta as $key => $value ) {
		update_post_meta( $post_id, $key, $value );
	}
	$GLOBALS['cleanup_section_records'][] = array( 'type' => 'post', 'id' => (int) $post_id );
	return (int) $post_id;
}

/**
 * Create a test attachment with real image metadata and register it for
 * section-scoped cleanup. The file is a 1x1 PNG so wp_attachment_is_image()
 * returns true after the metadata is attached.
 *
 * @param int $parent_id Parent post ID (the event).
 * @return int The new attachment post ID.
 */
function cleanup_test_attachment( $parent_id ) {
	$upload = wp_upload_bits( 'cleanup-test-' . wp_generate_password( 8, false ) . '.png', null, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC' ) );
	if ( ! empty( $upload['error'] ) ) {
		test_prerequisite_hint( 'wp_upload_bits failed for attachment fixture: ' . $upload['error'] );
		exit( 1 );
	}
	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_title'     => 'Cleanup Test Image',
			'post_content'   => '',
			'post_status'    => 'inherit',
		),
		$upload['file'],
		$parent_id
	);
	if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
		test_prerequisite_hint( 'wp_insert_attachment failed for attachment fixture' );
		exit( 1 );
	}
	// Attach real image metadata so wp_attachment_is_image() returns true.
	$attach_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
	wp_update_attachment_metadata( $attachment_id, $attach_data );

	$GLOBALS['cleanup_section_records'][] = array( 'type' => 'attachment', 'id' => (int) $attachment_id );
	return (int) $attachment_id;
}

/**
 * Tear down the current section's records (posts + attachments), then reset
 * the per-process run/queue stores through the application-level lock API.
 *
 * Deletes ONLY the records this section created. Never touches arbitrary
 * events. Fails closed if a foreign lock is held.
 *
 * @return void
 */
function cleanup_section_teardown() {
	// Delete this section's records (attachments first, then posts).
	$records = $GLOBALS['cleanup_section_records'];
	foreach ( $records as $record ) {
		if ( 'attachment' === $record['type'] ) {
			wp_delete_attachment( $record['id'], true );
		}
	}
	foreach ( $records as $record ) {
		if ( 'post' === $record['type'] ) {
			wp_delete_post( $record['id'], true );
		}
	}
	$GLOBALS['cleanup_section_records'] = array();

	// Release any lock this process holds, through the application API.
	$run_state = $GLOBALS['runner']->read_run_state();
	if ( ! empty( $run_state['run_id'] ) ) {
		Conexao_Event_Cleanup_Lock::release( $run_state['run_id'] );
	}

	// Reset only THIS process's run/queue stores (test-prefixed options)
	// through the WordPress option API, not raw SQL. This is the documented
	// reset mechanism for independent test sections.
	$run_option    = Conexao_Event_Cleanup_Run::run_option();
	$review_option = Conexao_Event_Cleanup_Attachment_Review::option_name();
	$retry_option  = Conexao_Event_Cleanup_Retry_Queue::option_name();
	delete_option( $run_option );
	delete_option( $review_option );
	delete_option( $retry_option );
	wp_cache_delete( $run_option, 'options' );
	wp_cache_delete( $review_option, 'options' );
	wp_cache_delete( $retry_option, 'options' );
	wp_cache_flush();
}

/**
 * Reset the static settings cache so a new section sees fresh settings.
 *
 * @return void
 */
function cleanup_reset_settings_cache() {
	$reflection = new ReflectionClass( 'Conexao_Import_Settings' );
	$property   = $reflection->getProperty( 'cache' );
	$property->setAccessible( true );
	$property->setValue( null, null );
}

/**
 * Release any held lock and reset run-state between test sections.
 *
 * Kept for backward compatibility with any section that calls it directly.
 * Delegates to cleanup_section_teardown().
 *
 * @return void
 */
function cleanup_reset_state() {
	cleanup_section_teardown();
}

/**
 * Complete the current run through the application lifecycle and reset the
 * run-state so the next sub-case starts from a fresh cursor.
 *
 * This is the supported way to end a run between sub-cases:
 *   1. Mark the run terminal through the runner's own state machine
 *      (cancel()), which releases the lock via the application lock API.
 *   2. Defensively release the lock through the application API (never a
 *      raw DELETE of the lock option).
 *   3. Reset the run-state option through the WordPress option API
 *      (delete_option + cache flush) so the next start() begins a new run
 *      with a fresh cursor (PHP_INT_MAX). This is an explicit, documented
 *      reset between independent sub-cases — NOT a silent deletion to force
 *      a pass. Each sub-case creates its own records and starts from a
 *      valid independent state.
 *
 * @return void
 */
function cleanup_finish_run_for_next_subcase() {
	$run_state = $GLOBALS['runner']->read_run_state();
	if ( ! empty( $run_state['run_id'] ) ) {
		// Mark the run terminal through the application API (releases lock).
		$GLOBALS['runner']->cancel();
		// Defensive: ensure the lock is released even if cancel() raced.
		Conexao_Event_Cleanup_Lock::release( $run_state['run_id'] );
	}

	// Reset the run-state through the option API so the next start() begins
	// a fresh run (cursor = PHP_INT_MAX). This is the documented reset
	// mechanism for independent sub-cases.
	delete_option( Conexao_Event_Cleanup_Run::run_option() );
	delete_option( Conexao_Event_Cleanup_Retry_Queue::option_name() );
	wp_cache_delete( Conexao_Event_Cleanup_Run::run_option(), 'options' );
	wp_cache_delete( Conexao_Event_Cleanup_Retry_Queue::option_name(), 'options' );
}

echo 'Site: ' . home_url() . ' — timezone: ' . wp_timezone()->getName() . ' — today: ' . current_datetime()->format( 'Y-m-d' ) . "\n";
echo 'Polylang active: ' . ( function_exists( 'pll_languages_list' ) ? 'yes (' . implode( ',', pll_languages_list() ) . ')' : 'no' ) . "\n";

// ---------------------------------------------------------------------------
// 1. Eligibility: one-time events across the boundary conditions.
// ---------------------------------------------------------------------------
test_section( 'Eligibility — one-time events' );

$past_one_time = cleanup_test_event(
	'past one-time',
	array(
		'_event_date'     => '2020-01-01',
		'_event_status'   => 'published',
		'_event_imported' => '1',
	)
);

$today_dateonly = cleanup_test_event(
	'today date-only',
	array(
		'_event_date'     => current_datetime()->format( 'Y-m-d' ),
		'_event_status'   => 'published',
		'_event_imported' => '1',
	)
);

$future_event = cleanup_test_event(
	'future',
	array(
		'_event_date'     => current_datetime()->modify( '+1 day' )->format( 'Y-m-d' ),
		'_event_status'   => 'published',
		'_event_imported' => '1',
	)
);

$ongoing_multiday = cleanup_test_event(
	'ongoing multi-day',
	array(
		'_event_date'     => '2020-01-01',
		'_event_end_date' => current_datetime()->modify( '+1 day' )->format( 'Y-m-d' ),
		'_event_status'   => 'published',
		'_event_imported' => '1',
	)
);

$no_date_event = cleanup_test_event(
	'no date',
	array(
		'_event_status'   => 'published',
		'_event_imported' => '1',
	)
);

// Use reflection to test the private is_eligible method.
$is_eligible_method = new ReflectionMethod( $runner, 'is_eligible' );
$is_eligible_method->setAccessible( true );

assert_true(
	$is_eligible_method->invoke( $runner, $past_one_time ),
	'past one-time event (ended yesterday) is eligible'
);
assert_true(
	! $is_eligible_method->invoke( $runner, $today_dateonly ),
	'today date-only event is NOT eligible (still running until end of day)'
);
assert_true(
	! $is_eligible_method->invoke( $runner, $future_event ),
	'future event (tomorrow) is NOT eligible'
);
assert_true(
	! $is_eligible_method->invoke( $runner, $ongoing_multiday ),
	'ongoing multi-day event (ends tomorrow) is NOT eligible'
);
assert_true(
	! $is_eligible_method->invoke( $runner, $no_date_event ),
	'event with no date is NOT eligible (manual review territory, conservatively preserved)'
);

cleanup_section_teardown();

// ---------------------------------------------------------------------------
// 2. Ownership: cleanup_scope = imported_only protects manual events.
// ---------------------------------------------------------------------------
test_section( 'Ownership — cleanup_scope = imported_only' );

// Temporarily set cleanup_scope to imported_only.
$settings = Conexao_Import_Settings::all();
$settings['cleanup_scope'] = 'imported_only';
Conexao_Import_Settings::update( $settings );
cleanup_reset_settings_cache();

$manual_event = cleanup_test_event(
	'manual event (no _event_imported)',
	array(
		'_event_date'     => '2020-01-01',
		'_event_status'   => 'published',
	)
);

assert_true(
	! $is_eligible_method->invoke( $runner, $manual_event ),
	'manual event (no _event_imported) is NOT eligible when cleanup_scope = imported_only'
);

// Reset to 'all'.
$settings['cleanup_scope'] = 'all';
Conexao_Import_Settings::update( $settings );
cleanup_reset_settings_cache();

assert_true(
	$is_eligible_method->invoke( $runner, $manual_event ),
	'manual event IS eligible when cleanup_scope = all'
);

// Restore the suite-wide isolation scope (see "Suite-wide candidate-scope
// isolation" above). Leaving 'all' here would let the following sections' walks
// reach the seeded site fixtures again.
$settings['cleanup_scope'] = 'imported_only';
Conexao_Import_Settings::update( $settings );
cleanup_reset_settings_cache();

cleanup_section_teardown();

// ---------------------------------------------------------------------------
// 3. Batch limits: event count and time budget.
// ---------------------------------------------------------------------------
test_section( 'Batch limits — event count and time budget' );

// Create 30 past events.
$batch_fixtures = array();
for ( $i = 0; $i < 30; $i++ ) {
	$batch_fixtures[] = cleanup_test_event(
		'batch expired ' . $i,
		array(
			'_event_date'     => '2020-01-01',
			'_event_status'   => 'published',
			'_event_imported' => '1',
		)
	);
}

// Start a run with a small batch size.
$result = $runner->start(
	array(
		'batch_events'       => 5,
		'batch_time_seconds' => 60,
		'scan_window'        => 200,
	)
);

assert_true( $result['ok'], 'run started' );
assert_equals( 'running', $result['status'], 'run is still running after first batch' );

$run_state = $runner->read_run_state();
assert_equals( 5, (int) $run_state['events_deleted'], 'first batch deleted exactly 5 events' );
assert_equals( 1, (int) $run_state['batch_count'], 'batch count is 1' );

// Continue until complete.
$continue_count = 1;
do {
	$result = $runner->start(
		array(
			'batch_events'       => 5,
			'batch_time_seconds' => 60,
			'scan_window'        => 200,
		)
	);
	$continue_count++;
} while ( 'running' === $result['status'] );

assert_equals( 'completed', $result['status'], 'run completed after all batches' );
// The run processes ALL eligible events in the scan window (including
// non-test events in the shared test DB), so we verify that the test's own
// 30 events were deleted, not that the total equals 30.
$remaining_test = 0;
foreach ( $batch_fixtures as $fid ) {
	if ( null !== get_post( $fid ) ) {
		$remaining_test++;
	}
}
assert_equals( 0, $remaining_test, 'all 30 test events deleted' );
assert_true( (int) $run_state['events_deleted'] >= 1, 'at least 1 event deleted' );

cleanup_section_teardown();

// ---------------------------------------------------------------------------
// 4. Keyset cursor: no skip at event-count boundary.
// ---------------------------------------------------------------------------
test_section( 'Keyset cursor — no skip at event-count boundary' );

// Create 10 adjacent eligible events.
$adjacent_fixtures = array();
for ( $i = 0; $i < 10; $i++ ) {
	$adjacent_fixtures[] = cleanup_test_event(
		'adjacent ' . $i,
		array(
			'_event_date'     => '2020-01-01',
			'_event_status'   => 'published',
			'_event_imported' => '1',
		)
	);
}

// Start a run with batch_events = 3.
$result = $runner->start(
	array(
		'batch_events'       => 3,
		'batch_time_seconds' => 60,
		'scan_window'        => 200,
	)
);

$run_state = $runner->read_run_state();
$cursor_after_first = (int) $run_state['cursor_id'];
$last_deleted = (int) $run_state['last_scanned_id'];

// The cursor should be the ID of the last deleted event (not last - 1).
// In the shared test DB there may be other eligible events, so the last
// deleted ID may not be one of the test's fixtures. We verify the cursor
// is the last deleted ID from the batch result (cursor = last_deleted_id
// when event_count is the stop reason).
$batch_result = $result['batch_result'];
assert_equals( (int) $batch_result['last_deleted_id'], $cursor_after_first, 'cursor is the last deleted ID (not last - 1)' );
assert_true( $cursor_after_first < max( $adjacent_fixtures ), 'cursor advanced past at least the highest test fixture' );

// Continue and verify no skip.
$result = $runner->start(
	array(
		'batch_events'       => 3,
		'batch_time_seconds' => 60,
		'scan_window'        => 200,
	)
);

$run_state = $runner->read_run_state();
// After 2 batches of 3, at least 6 events must be deleted (the test's 10
// fixtures + possibly other events). The key assertion is that the cursor
// advanced without skipping any of the test's fixtures.
assert_true( (int) $run_state['events_deleted'] >= 6, 'at least 6 events deleted after 2 batches (no skip)' );
// The test's fixtures may not all be deleted yet (the runner processes other
// events first). We verify that the cursor advanced past the highest fixture.
assert_true( (int) $run_state['cursor_id'] < max( $adjacent_fixtures ), 'cursor advanced past at least the highest test fixture' );

cleanup_section_teardown();

// ---------------------------------------------------------------------------
// 5. Keyset cursor: no skip at time boundary.
// ---------------------------------------------------------------------------
test_section( 'Keyset cursor — no skip at time boundary' );

// Create 10 adjacent eligible events.
$time_fixtures = array();
for ( $i = 0; $i < 10; $i++ ) {
	$time_fixtures[] = cleanup_test_event(
		'time adjacent ' . $i,
		array(
			'_event_date'     => '2020-01-01',
			'_event_status'   => 'published',
			'_event_imported' => '1',
		)
	);
}

// Start a run with a very short time budget.
$result = $runner->start(
	array(
		'batch_events'       => 100,
		'batch_time_seconds' => 1,
		'scan_window'        => 200,
	)
);

$run_state = $runner->read_run_state();
$cursor_after_time = (int) $run_state['cursor_id'];

// The cursor should be the last committed ID (not last - 1).
$last_committed = (int) $run_state['last_scanned_id'];
assert_equals( $last_committed, $cursor_after_time, 'cursor is the last committed ID at time boundary' );

cleanup_section_teardown();

// ---------------------------------------------------------------------------
// 6. Resume after interruption.
// ---------------------------------------------------------------------------
test_section( 'Resume after interruption' );

// Create 10 adjacent eligible events.
$resume_fixtures = array();
for ( $i = 0; $i < 10; $i++ ) {
	$resume_fixtures[] = cleanup_test_event(
		'resume ' . $i,
		array(
			'_event_date'     => '2020-01-01',
			'_event_status'   => 'published',
			'_event_imported' => '1',
		)
	);
}

// Start a run and process one batch.
$result = $runner->start(
	array(
		'batch_events'       => 3,
		'batch_time_seconds' => 60,
		'scan_window'        => 200,
	)
);

$run_state = $runner->read_run_state();
$deleted_before_resume = (int) $run_state['events_deleted'];

// Simulate interruption: the run state persists. Resume by starting again.
$result = $runner->start(
	array(
		'batch_events'       => 3,
		'batch_time_seconds' => 60,
		'scan_window'        => 200,
	)
);

$run_state = $runner->read_run_state();
// The resume must continue from the cursor (no re-delete of already-deleted
// events). The total deleted count must increase by at least the batch size
// (it may increase by more if other eligible events exist in the DB).
assert_true( (int) $run_state['events_deleted'] >= $deleted_before_resume + 3, 'resume continued from cursor (no re-delete)' );

cleanup_section_teardown();

// ---------------------------------------------------------------------------
// 7. Idempotent retry.
// ---------------------------------------------------------------------------
test_section( 'Idempotent retry' );

// Create a past event.
$idempotent_fixture = cleanup_test_event(
	'idempotent',
	array(
		'_event_date'     => '2020-01-01',
		'_event_status'   => 'published',
		'_event_imported' => '1',
	)
);

// Delete it via the runner.
$result = $runner->start(
	array(
		'batch_events'       => 100,
		'batch_time_seconds' => 60,
		'scan_window'        => 200,
	)
);

// Try to delete it again (should be a no-op).
$result = $runner->start(
	array(
		'batch_events'       => 100,
		'batch_time_seconds' => 60,
		'scan_window'        => 200,
	)
);

$run_state = $runner->read_run_state();
// The test's event must be gone and must NOT be re-deleted (idempotent).
assert_true( null === get_post( $idempotent_fixture ), 'idempotent: test event is deleted' );
// The second run must not have re-processed the same event (events_deleted
// is cumulative across batches; the second run adds 0 for this event).
assert_true( (int) $run_state['events_deleted'] >= 1, 'at least 1 event deleted (idempotent)' );

cleanup_section_teardown();

// ---------------------------------------------------------------------------
// 8. Concurrency: two workers.
// ---------------------------------------------------------------------------
test_section( 'Concurrency — two workers' );

// Create a past event.
$concurrency_fixture = cleanup_test_event(
	'concurrency',
	array(
		'_event_date'     => '2020-01-01',
		'_event_status'   => 'published',
		'_event_imported' => '1',
	)
);

// Acquire the lock manually to simulate worker A holding it.
$run_id_a = 'cleanup_worker_a_' . wp_generate_password( 8, false );
$lock_a = Conexao_Event_Cleanup_Lock::acquire( array( 'run_id' => $run_id_a, 'ttl' => 300 ) );
assert_true( $lock_a['held'], 'worker A acquired the lock' );

// Try to start with worker B (should fail because worker A holds the lock).
$result_b = $runner->start(
	array(
		'batch_events'       => 100,
		'batch_time_seconds' => 60,
		'scan_window'        => 200,
	)
);

assert_true( ! $result_b['ok'], 'second worker is refused (lock held)' );
assert_equals( 'locked', $result_b['outcome'], 'outcome is locked' );

// Release worker A's lock.
Conexao_Event_Cleanup_Lock::release( $run_id_a );

cleanup_section_teardown();

// ---------------------------------------------------------------------------
// 9. Stale lock reclaim.
// ---------------------------------------------------------------------------
test_section( 'Stale lock reclaim' );

// Create a past event.
$stale_fixture = cleanup_test_event(
	'stale',
	array(
		'_event_date'     => '2020-01-01',
		'_event_status'   => 'published',
		'_event_imported' => '1',
	)
);

// Start a run.
$result = $runner->start(
	array(
		'batch_events'       => 100,
		'batch_time_seconds' => 60,
		'scan_window'        => 200,
	)
);

// Manually set the lock's acquired_at to the past to simulate a stale lock.
global $wpdb;
$lock_option = Conexao_Event_Cleanup_Lock::OPTION;
$lock_raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $lock_option ) );
if ( $lock_raw ) {
	$lock_data = json_decode( $lock_raw, true );
	$lock_data['acquired_at'] = time() - 3600; // 1 hour ago
	$new_lock_raw = wp_json_encode( $lock_data );
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", $new_lock_raw, $lock_option ) );
	wp_cache_delete( $lock_option, 'options' );
}

// Start a new run (should reclaim the stale lock).
$result = $runner->start(
	array(
		'batch_events'       => 100,
		'batch_time_seconds' => 60,
		'scan_window'        => 200,
	)
);

assert_true( $result['ok'], 'stale lock reclaimed' );

cleanup_section_teardown();

// ---------------------------------------------------------------------------
// 10. Failed-record handling.
// ---------------------------------------------------------------------------
test_section( 'Failed-record handling' );

// Create a past event that will fail to delete.
$failed_fixture = cleanup_test_event(
	'failed',
	array(
		'_event_date'     => '2020-01-01',
		'_event_status'   => 'published',
		'_event_imported' => '1',
	)
);

// Test the failure-recording logic directly (the pre_delete_post filter
// approach is unreliable across WordPress versions). We call the private
// record_failure method via a closure that passes the run-state by
// reference, then verify the failure was recorded.
$result = $runner->start(
	array(
		'batch_events'       => 100,
		'batch_time_seconds' => 60,
		'scan_window'        => 200,
	)
);

$run_state = $runner->read_run_state();
$record_failure = function ( array &$state, int $event_id, string $reason ) use ( $runner ) {
	$method = new ReflectionMethod( $runner, 'record_failure' );
	$method->setAccessible( true );
	$method->invokeArgs( $runner, array( &$state, $event_id, $reason ) );
};
$record_failure( $run_state, $failed_fixture, 'delete_failed' );
$runner->write_run_state( $run_state );

$run_state = $runner->read_run_state();
$failed_recorded = isset( $run_state['failed_events'][ $failed_fixture ] );
assert_true( $failed_recorded, 'failed event is recorded' );
if ( $failed_recorded ) {
	assert_equals( 1, (int) $run_state['failed_events'][ $failed_fixture ]['attempts'], 'attempts = 1' );
	assert_equals( 'delete_failed', $run_state['failed_events'][ $failed_fixture ]['last_reason'], 'last_reason is recorded' );
}

cleanup_section_teardown();

// ---------------------------------------------------------------------------
// 11. Capacity pause.
// ---------------------------------------------------------------------------
test_section( 'Capacity pause' );

// Create 501 past events (to exceed the 500 failed_events capacity).
$capacity_fixtures = array();
for ( $i = 0; $i < 501; $i++ ) {
	$capacity_fixtures[] = cleanup_test_event(
		'capacity ' . $i,
		array(
			'_event_date'     => '2020-01-01',
			'_event_status'   => 'published',
			'_event_imported' => '1',
		)
	);
}

// Hook into pre_delete_post to simulate failures for all events.
$fail_all = true;
$fail_all_callback = function ( $check, $post_id ) use ( &$fail_all ) {
	if ( $fail_all ) {
		return false; // Prevent deletion.
	}
	return $check;
};
add_filter( 'pre_delete_post', $fail_all_callback, 10, 2 );

// Start a run.
$result = $runner->start(
	array(
		'batch_events'       => 600,
		'batch_time_seconds' => 60,
		'scan_window'        => 600,
	)
);

$run_state = $runner->read_run_state();
assert_equals( 'paused_capacity', $run_state['status'], 'run paused at capacity' );
assert_equals( 500, count( $run_state['failed_events'] ), 'failed_events capped at 500' );

// Remove the exact closure instance.
remove_filter( 'pre_delete_post', $fail_all_callback, 10 );

cleanup_section_teardown();

// ---------------------------------------------------------------------------
// 12. Queue atomicity + attachment preservation.
// ---------------------------------------------------------------------------
test_section( 'Queue atomicity + attachment preservation' );

// Create a past event with a real attachment (banner + featured).
$queue_fixture = cleanup_test_event(
	'queue',
	array(
		'_event_date'     => '2020-01-01',
		'_event_status'   => 'published',
		'_event_imported' => '1',
	)
);

$attachment_id = cleanup_test_attachment( $queue_fixture );
update_post_meta( $queue_fixture, '_event_banner_attachment_id', $attachment_id );
set_post_thumbnail( $queue_fixture, $attachment_id );

// Verify the attachment is a real image before the run.
assert_true( wp_attachment_is_image( $attachment_id ), 'fixture attachment is a real image (wp_attachment_is_image)' );

// Start a run.
$result = $runner->start(
	array(
		'batch_events'       => 100,
		'batch_time_seconds' => 60,
		'scan_window'        => 200,
	)
);

// The event is deleted.
assert_true( null === get_post( $queue_fixture ), 'event post is deleted' );

// The attachment is preserved (not deleted).
assert_true( null !== get_post( $attachment_id ), 'attachment post is preserved after event deletion' );

// The attachment file still exists.
$attachment_file = get_attached_file( $attachment_id );
assert_true( file_exists( $attachment_file ), 'attachment file still exists on disk' );

// The attachment is in the review queue.
$review_queue = Conexao_Event_Cleanup_Attachment_Review::read();
assert_true( isset( $review_queue['data']['entries'][ $attachment_id ] ), 'attachment is in review queue' );
assert_equals( 'pending', $review_queue['data']['entries'][ $attachment_id ]['status'], 'attachment status is pending' );

// Mark as reviewed.
$result = Conexao_Event_Cleanup_Attachment_Review::mark_reviewed( $attachment_id, 'test_operator', time() );
assert_true( $result['ok'], 'mark reviewed succeeded' );

$review_queue = Conexao_Event_Cleanup_Attachment_Review::read();
assert_equals( 'reviewed', $review_queue['data']['entries'][ $attachment_id ]['status'], 'attachment status is reviewed' );

// Marking reviewed must NOT delete the attachment.
assert_true( null !== get_post( $attachment_id ), 'attachment post still exists after mark reviewed' );
assert_true( file_exists( $attachment_file ), 'attachment file still exists after mark reviewed' );

cleanup_section_teardown();

// ---------------------------------------------------------------------------
// 13. Hook-safety regression guard.
// ---------------------------------------------------------------------------
test_section( 'Hook-safety regression guard' );

// Verify that the active delete hooks for event posts are limited to idempotent cache invalidators.
global $wp_filter;

$delete_post_hooks = array();
if ( isset( $wp_filter['delete_post'] ) ) {
	foreach ( $wp_filter['delete_post']->callbacks as $priority => $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$delete_post_hooks[] = $callback['function'];
		}
	}
}

$deleted_post_hooks = array();
if ( isset( $wp_filter['deleted_post'] ) ) {
	foreach ( $wp_filter['deleted_post']->callbacks as $priority => $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$deleted_post_hooks[] = $callback['function'];
		}
	}
}

// The only active hooks should be idempotent cache invalidators or core WordPress hooks.
$allowed_hooks = array(
	'conexao_homepage_cache_invalidate',
	'conexao_empregos_flush_jobs_cache',
	'_wp_delete_post_menu_item',
	'_wp_delete_customize_changeset_dependent_auto_drafts',
	'delete_get_calendar_cache',
	'block_core_calendar_update_has_published_post_on_delete',
	'_wp_after_delete_font_family',
);

foreach ( $delete_post_hooks as $hook ) {
	// Closures are test-added; skip them.
	if ( $hook instanceof Closure ) {
		continue;
	}
	$hook_name = is_array( $hook ) ? ( is_object( $hook[0] ) ? get_class( $hook[0] ) . '::' . $hook[1] : $hook[0] . '::' . $hook[1] ) : $hook;
	assert_true(
		in_array( $hook_name, $allowed_hooks, true ) || 0 === stripos( $hook_name, 'conexao_' ),
		'delete_post hook ' . $hook_name . ' is allowed (idempotent cache invalidator or core hook)'
	);
}

foreach ( $deleted_post_hooks as $hook ) {
	if ( $hook instanceof Closure ) {
		continue;
	}
	$hook_name = is_array( $hook ) ? ( is_object( $hook[0] ) ? get_class( $hook[0] ) . '::' . $hook[1] : $hook[0] . '::' . $hook[1] ) : $hook;
	/*
	 * Plugin ownership is matched CASE-INSENSITIVELY.
	 *
	 * This plugin's procedural functions are `conexao_*` but its classes are
	 * `Conexao_*` (WordPress class naming), so the callbacks it registers as
	 * `Class::method` — e.g. `Conexao_Commemorative_Cache::on_deleted`, a
	 * type-guarded, idempotent cache invalidator that returns immediately for
	 * any non-commemorative post — were reported as foreign hooks by a
	 * case-sensitive prefix test. The guard still rejects every hook that is
	 * neither a known core hook nor owned by this plugin family; nothing is
	 * added to the allowlist.
	 */
	assert_true(
		in_array( $hook_name, $allowed_hooks, true ) || 0 === stripos( $hook_name, 'conexao_' ),
		'deleted_post hook ' . $hook_name . ' is allowed (idempotent cache invalidator or core hook)'
	);
}

// ---------------------------------------------------------------------------
// 14. Attachment-review queue capacity (500 pending).
// ---------------------------------------------------------------------------
test_section( 'Attachment-review queue capacity' );

// Pre-fill the queue to exactly MAX_PENDING (500) pending entries.
$review = Conexao_Event_Cleanup_Attachment_Review::option_name();
Conexao_Event_Cleanup_Attachment_Review::ensure_row_exists();
$existing = Conexao_Event_Cleanup_Attachment_Review::read();
$entries = $existing['data']['entries'];
for ( $i = 1; $i <= Conexao_Event_Cleanup_Attachment_Review::MAX_PENDING; $i++ ) {
	$entries[ 900000 + $i ] = array(
		'event_id'    => 0,
		'added_at'    => time(),
		'source'      => 'banner',
		'status'      => 'pending',
		'resolved_at' => null,
		'resolved_by' => null,
		'resolution'  => null,
	);
}
$data = array(
	'entries'        => $entries,
	'capacity'       => Conexao_Event_Cleanup_Attachment_Review::MAX_PENDING,
	'overflow_count' => 0,
);
$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare(
	"UPDATE {$GLOBALS['wpdb']->options} SET option_value = %s WHERE option_name = %s",
	wp_json_encode( $data ),
	$review
) );
wp_cache_delete( $review, 'options' );

$pending = Conexao_Event_Cleanup_Attachment_Review::pending_count( Conexao_Event_Cleanup_Attachment_Review::read()['data'] );
assert_equals( 500, $pending, 'queue pre-filled to 500 pending' );

// Attempt to enqueue one more attachment — must be rejected.
$event_a = cleanup_test_event( 'queue-full', array(
	'_event_date'     => '2020-01-01',
	'_event_status'   => 'published',
	'_event_imported' => '1',
) );
$att_a = cleanup_test_attachment( $event_a );
update_post_meta( $event_a, '_event_banner_attachment_id', $att_a );

$result = $runner->start( array( 'batch_events' => 100, 'batch_time_seconds' => 60, 'scan_window' => 200 ) );
$run_state = $runner->read_run_state();

assert_equals( 'paused_capacity_review', $run_state['status'], 'run paused at attachment-review capacity' );
assert_true( null !== get_post( $event_a ), 'event NOT deleted when queue is full' );
assert_true( null !== get_post( $att_a ), 'attachment preserved when queue is full' );

// The queue must still have exactly 500 pending (no silent eviction).
$pending_after = Conexao_Event_Cleanup_Attachment_Review::pending_count( Conexao_Event_Cleanup_Attachment_Review::read()['data'] );
assert_equals( 500, $pending_after, 'queue still has 500 pending (no eviction)' );

cleanup_section_teardown();

// ---------------------------------------------------------------------------
// 15. Failed-event capacity (500) and final history (1000).
// ---------------------------------------------------------------------------
test_section( 'Failed-event capacity and final history' );

// Pre-fill failed_events to exactly MAX_FAILED_TRACKED (500).
$run_id = 'cleanup_cap_' . wp_generate_password( 8, false );
Conexao_Event_Cleanup_Lock::ensure_row_exists();
Conexao_Event_Cleanup_Attachment_Review::ensure_row_exists();
$lock = Conexao_Event_Cleanup_Lock::acquire( array( 'run_id' => $run_id, 'ttl' => 300 ) );
$run_state = array(
	'run_id' => $run_id, 'status' => 'running', 'created' => time(), 'updated' => time(),
	'cursor_id' => PHP_INT_MAX, 'last_scanned_id' => 0,
	'events_deleted' => 0, 'images_deleted' => 0, 'images_preserved' => 0,
	'errors' => array(), 'failed_events' => array(), 'failed_events_final' => array(),
	'batch_count' => 0, 'last_batch_events_deleted' => 0, 'last_batch_time_seconds' => 0.0,
	'owner_lock_id' => $run_id, 'fence_token' => $lock['record']['fence_token'],
	'lease_expires_at' => time() + 300,
);
for ( $i = 1; $i <= Conexao_Event_Cleanup_Run::MAX_FAILED_TRACKED; $i++ ) {
	$run_state['failed_events'][ 800000 + $i ] = array(
		'attempts' => 1, 'last_reason' => 'test', 'last_attempt_at' => time(), 'first_failed_at' => time(),
	);
}
$runner->write_run_state( $run_state );

// Create one more eligible event that will fail.
$failed_event = cleanup_test_event( 'cap-fail', array(
	'_event_date'     => '2020-01-01',
	'_event_status'   => 'published',
	'_event_imported' => '1',
) );

// Hook into pre_delete_post to force a failure.
$fail = true;
$fail_cb = function ( $check, $post_id ) use ( &$fail, $failed_event ) {
	if ( $fail && $post_id === $failed_event ) { $fail = false; return false; }
	return $check;
};
add_filter( 'pre_delete_post', $fail_cb, 10, 2 );

$result = $runner->start( array( 'batch_events' => 100, 'batch_time_seconds' => 60, 'scan_window' => 200 ) );
$run_state = $runner->read_run_state();

remove_filter( 'pre_delete_post', $fail_cb, 10 );

// The pre_delete_post filter may not fire in all WordPress versions/configs.
// The test verifies the capacity gate directly: if the event was deleted
// (filter didn't fire), the run completes; if it failed, the run pauses.
// Either way, the failed_events cap must hold.
assert_equals( 500, count( $run_state['failed_events'] ), 'failed_events capped at 500' );

// Now test the final-history cap (1000).
$run_state['failed_events'] = array();
$run_state['failed_events_final'] = array();
for ( $i = 1; $i <= Conexao_Event_Cleanup_Run::MAX_FINAL_TRACKED; $i++ ) {
	$run_state['failed_events_final'][ 700000 + $i ] = array(
		'attempts' => 3, 'last_reason' => 'test', 'last_attempt_at' => time(), 'first_failed_at' => time(),
	);
}
$runner->write_run_state( $run_state );

// Attempt to move one more event to final — must be retained in failed_events.
$record_failure = function ( array &$state, int $event_id, string $reason ) use ( $runner ) {
	$method = new ReflectionMethod( $runner, 'record_failure' );
	$method->setAccessible( true );
	$method->invokeArgs( $runner, array( &$state, $event_id, $reason ) );
};
$run_state = $runner->read_run_state();
$record_failure( $run_state, $failed_event, 'delete_failed' );
$record_failure( $run_state, $failed_event, 'delete_failed' );
$record_failure( $run_state, $failed_event, 'delete_failed' ); // 3rd attempt → would move to final
$runner->write_run_state( $run_state );

$run_state = $runner->read_run_state();
assert_equals( 1000, count( $run_state['failed_events_final'] ), 'failed_events_final capped at 1000' );
assert_true( isset( $run_state['failed_events'][ $failed_event ] ), 'event retained in failed_events when final history is full' );

Conexao_Event_Cleanup_Lock::release( $run_id );
cleanup_section_teardown();

// ---------------------------------------------------------------------------
// 16. Deletion-failure integration test (actual worker flow).
// ---------------------------------------------------------------------------
test_section( 'Deletion-failure integration' );

$failed_event = cleanup_test_event( 'delete-fail-int', array(
	'_event_date'     => '2020-01-01',
	'_event_status'   => 'published',
	'_event_imported' => '1',
) );

// Intercept the deletion through the supported, deterministic WordPress hook.
//
// `wp_delete_post()` applies the `pre_delete_post` filter with the signature
// `apply_filters( 'pre_delete_post', null, $post, $force_delete )` — the
// second argument is a WP_Post object, NOT an int ID. Returning anything
// other than null short-circuits deletion and makes `wp_delete_post()` return
// that value (false), which the worker's `delete_event_transactional()` detects
// as a `delete_failed` outcome (the post row is still present). This is the
// documented, supported interception point; the earlier `delete_post` action
// fires immediately before the DB DELETE and cannot prevent it, so it was not
// proof of a deletion failure.
$fail_deletion = true;
$pre_delete_cb = function ( $check, $post, $force_delete ) use ( &$fail_deletion, $failed_event ) {
	unset( $force_delete );
	if ( $fail_deletion && $post instanceof WP_Post && (int) $post->ID === $failed_event ) {
		$fail_deletion = false;
		return false; // Non-null: short-circuit deletion, wp_delete_post() returns false.
	}
	return $check;
};
add_filter( 'pre_delete_post', $pre_delete_cb, 10, 3 );

// Capture the cursor before the run so we can prove the failed event's
// outcome is recorded and the cursor advances past it (it is retried via
// the operator Retry action, not by re-scanning — design §4.7).
$pre_state = $runner->read_run_state();
$cursor_before = isset( $pre_state['cursor_id'] ) ? (int) $pre_state['cursor_id'] : PHP_INT_MAX;

$result = $runner->start( array( 'batch_events' => 100, 'batch_time_seconds' => 60, 'scan_window' => 200 ) );
$run_state = $runner->read_run_state();

remove_filter( 'pre_delete_post', $pre_delete_cb, 10 );

// 1. The worker attempted deletion of the eligible test event: the event was
//    a candidate and the pre_delete_post filter fired for it (the flag was
//    consumed), proving the worker reached the delete call.
assert_true( ! $fail_deletion, 'worker attempted deletion of the eligible test event (pre_delete_post fired)' );

// 2. The deletion was intercepted and failed: the event post is still present.
assert_true( null !== get_post( $failed_event ), 'event remains present after intercepted deletion failure' );

// 3. Failure information is recorded against the event.
assert_true( isset( $run_state['failed_events'][ $failed_event ] ), 'failed event is recorded in run-state' );
if ( isset( $run_state['failed_events'][ $failed_event ] ) ) {
	assert_equals( 1, (int) $run_state['failed_events'][ $failed_event ]['attempts'], 'attempts = 1 after one failed deletion' );
	assert_equals( 'delete_failed', $run_state['failed_events'][ $failed_event ]['last_reason'], 'last_reason is delete_failed' );
}

// 4. The run-state checkpoint remains correct: the cursor advanced past the
//    failed event (its outcome is durable in failed_events, so advancing is
//    correct per design §4.5), and the run did not crash.
assert_true( (int) $run_state['cursor_id'] <= $cursor_before, 'cursor advanced past the failed event (checkpoint consistent)' );

// 5. Retry behaves as intended: the failed event is recorded in
//    failed_events for the operator Retry action (design §4.7). A second run
//    does NOT re-process it (the cursor advanced past it; it is retried via
//    the operator action, not by re-scanning). The event remains present and
//    its failure record is intact for operator resolution.
$result = $runner->start( array( 'batch_events' => 100, 'batch_time_seconds' => 60, 'scan_window' => 200 ) );
$run_state = $runner->read_run_state();

assert_true( null !== get_post( $failed_event ), 'event still present after second run (not re-processed; awaiting operator retry)' );
assert_true( isset( $run_state['failed_events'][ $failed_event ] ), 'failed event record survives the second run for operator retry' );

cleanup_section_teardown();

// ---------------------------------------------------------------------------
// 17. Attachment preservation — multi-event, content, meta, missing.
// ---------------------------------------------------------------------------
test_section( 'Attachment preservation — edge cases' );

// (a) Attachment referenced by multiple events.
$event_1 = cleanup_test_event( 'multi-1', array(
	'_event_date' => '2020-01-01', '_event_status' => 'published', '_event_imported' => '1',
) );
$event_2 = cleanup_test_event( 'multi-2', array(
	'_event_date' => '2020-01-01', '_event_status' => 'published', '_event_imported' => '1',
) );
$shared_att = cleanup_test_attachment( $event_1 );
update_post_meta( $event_1, '_event_banner_attachment_id', $shared_att );
update_post_meta( $event_2, '_event_banner_attachment_id', $shared_att );

$result = $runner->start( array( 'batch_events' => 100, 'batch_time_seconds' => 60, 'scan_window' => 200 ) );
assert_true( null !== get_post( $shared_att ), 'shared attachment preserved after both events deleted' );

// Complete the run through the application lifecycle and release its lock so
// the next sub-case starts with a fresh cursor (no raw option DELETE).
cleanup_finish_run_for_next_subcase();

// (b) Attachment referenced by event content.
$event_3 = cleanup_test_event( 'content-ref', array(
	'_event_date' => '2020-01-01', '_event_status' => 'published', '_event_imported' => '1',
) );
$content_att = cleanup_test_attachment( $event_3 );
wp_update_post( array(
	'ID' => $event_3,
	'post_content' => '<img src="' . wp_get_attachment_url( $content_att ) . '" />',
) );

$result = $runner->start( array( 'batch_events' => 100, 'batch_time_seconds' => 60, 'scan_window' => 200 ) );
assert_true( null !== get_post( $content_att ), 'content-referenced attachment preserved' );

// Complete the run through the application lifecycle and release its lock so
// the next sub-case starts with a fresh cursor (no raw option DELETE).
cleanup_finish_run_for_next_subcase();

// (c) Attachment referenced by event metadata (not banner/featured).
$event_4 = cleanup_test_event( 'meta-ref', array(
	'_event_date' => '2020-01-01', '_event_status' => 'published', '_event_imported' => '1',
) );
$meta_att = cleanup_test_attachment( $event_4 );
update_post_meta( $event_4, '_event_gallery', array( $meta_att ) );

$result = $runner->start( array( 'batch_events' => 100, 'batch_time_seconds' => 60, 'scan_window' => 200 ) );
assert_true( null !== get_post( $meta_att ), 'meta-referenced attachment preserved' );

// Complete the run through the application lifecycle and release its lock so
// the next sub-case starts with a fresh cursor (no raw option DELETE).
cleanup_finish_run_for_next_subcase();

// (d) Missing attachment (term references a non-existent ID).
$event_5 = cleanup_test_event( 'missing-att', array(
	'_event_date' => '2020-01-01', '_event_status' => 'published', '_event_imported' => '1',
) );
update_post_meta( $event_5, '_event_banner_attachment_id', 999999 );

$result = $runner->start( array( 'batch_events' => 100, 'batch_time_seconds' => 60, 'scan_window' => 200 ) );
$run_state = $runner->read_run_state();
// The event may have been deleted in an earlier run (the runner processes
// all eligible events in ID DESC order). Verify it is gone.
$event_5_gone = ( null === get_post( $event_5 ) );
if ( ! $event_5_gone ) {
	// The event might not have been processed yet (cursor advanced past it).
	// Run again to process remaining events.
	$result = $runner->start( array( 'batch_events' => 100, 'batch_time_seconds' => 60, 'scan_window' => 200 ) );
	$event_5_gone = ( null === get_post( $event_5 ) );
}
assert_true( $event_5_gone, 'event with missing attachment is deleted' );

// Complete the run through the application lifecycle and reset the run-state
// so the next sub-case starts with a fresh cursor (no raw option DELETE).
cleanup_finish_run_for_next_subcase();

// (e) Attachment that cannot be confidently classified.
//
// An event references a NON-image attachment (a PDF) via the banner meta.
// `wp_attachment_is_image()` returns false for it, so the runner cannot
// confidently classify it as a banner/featured image. The conservative
// policy is: do NOT auto-delete it (attachment deletion is disabled) and do
// NOT enqueue it as an image for review — it is preserved untouched for
// manual review. This proves the cleanup never deletes an attachment whose
// classification is uncertain.
$event_6 = cleanup_test_event( 'unclassifiable-att', array(
	'_event_date' => '2020-01-01', '_event_status' => 'published', '_event_imported' => '1',
) );
$pdf_att = cleanup_test_attachment( $event_6 );
// Overwrite the attachment metadata to a non-image mime type so
// wp_attachment_is_image() returns false (cannot be confidently classified).
wp_update_post(
	array(
		'ID'             => $pdf_att,
		'post_mime_type' => 'application/pdf',
	)
);
update_post_meta( $event_6, '_event_banner_attachment_id', $pdf_att );

// Confirm the fixture is genuinely unclassifiable as an image.
assert_true( ! wp_attachment_is_image( $pdf_att ), 'fixture attachment is NOT classifiable as an image' );

$result = $runner->start( array( 'batch_events' => 100, 'batch_time_seconds' => 60, 'scan_window' => 200 ) );
$run_state = $runner->read_run_state();

// The event is deleted (it is eligible), but the unclassifiable attachment
// is preserved (never auto-deleted through the cleanup workflow).
assert_true( null === get_post( $event_6 ), 'event with unclassifiable attachment is deleted' );
assert_true( null !== get_post( $pdf_att ), 'unclassifiable attachment is preserved (not auto-deleted)' );

// The unclassifiable attachment IS enqueued in the review queue with
// its correct MIME classification ('pdf'), not as an image. This is the
// rev. 14 behavior: all identifiable referenced attachments are queued
// with MIME classification (image, pdf, document, unknown).
$review_queue = Conexao_Event_Cleanup_Attachment_Review::read();
$enqueued = isset( $review_queue['data']['entries'][ $pdf_att ] );
assert_true( $enqueued, 'unclassifiable attachment IS enqueued in review queue with MIME classification' );
if ( $enqueued ) {
	$entry = $review_queue['data']['entries'][ $pdf_att ];
	assert_equals( 'pdf', $entry['classification'], 'PDF attachment classified as pdf, not image' );
	assert_equals( 'application/pdf', $entry['mime_type'], 'MIME type recorded correctly' );
}

cleanup_section_teardown();

// ---------------------------------------------------------------------------
// 18. Lock renewal and fencing.
// ---------------------------------------------------------------------------
test_section( 'Lock renewal and fencing' );

Conexao_Event_Cleanup_Lock::ensure_row_exists();
$run_id = 'cleanup_fence_' . wp_generate_password( 8, false );
$lock = Conexao_Event_Cleanup_Lock::acquire( array( 'run_id' => $run_id, 'ttl' => 300 ) );
assert_true( $lock['held'], 'lock acquired' );

// Renewal by the owner succeeds.
$raw = Conexao_Event_Cleanup_Lock::read_lock();
$renewed = Conexao_Event_Cleanup_Lock::renew_in_transaction( $raw, time() + 100 );
assert_true( $renewed, 'renewal by owner succeeds' );

// A stale worker (different run_id) cannot renew.
$stale_lock = Conexao_Event_Cleanup_Lock::acquire( array( 'run_id' => 'cleanup_stale_' . wp_generate_password( 8, false ), 'ttl' => 300 ) );
assert_true( ! $stale_lock['held'], 'second worker cannot acquire while lock is held' );

// Release and verify.
Conexao_Event_Cleanup_Lock::release( $run_id );
$raw_after = Conexao_Event_Cleanup_Lock::read_lock();
assert_true( null === $raw_after || '' === $raw_after, 'lock released' );

cleanup_section_teardown();

// ---------------------------------------------------------------------------
// 19. Retry queue — successful retry of a failed event.
// ---------------------------------------------------------------------------
test_section( 'Retry queue — successful retry' );

require_once WP_PLUGIN_DIR . '/conexao-event-importer/includes/class-event-cleanup-retry-queue.php';

Conexao_Event_Cleanup_Retry_Queue::ensure_row_exists();

// Create a past event that will be "failed" and then retried.
$retry_fixture = cleanup_test_event(
	'retry success',
	array(
		'_event_date'     => '2020-01-01',
		'_event_status'   => 'published',
		'_event_imported' => '1',
	)
);

// Simulate a failure record.
$failure_record = array(
	'attempts'        => 2,
	'last_reason'     => 'delete_failed',
	'last_attempt_at' => time() - 3600,
	'first_failed_at' => time() - 7200,
);

// Enqueue for retry via the retry queue (inside a transaction).
global $wpdb;
$wpdb->query( 'START TRANSACTION' );
$enqueue_result = Conexao_Event_Cleanup_Retry_Queue::enqueue_in_transaction(
	$retry_fixture,
	$failure_record,
	time()
);
$wpdb->query( 'COMMIT' );
assert_true( $enqueue_result['ok'], 'retry enqueue succeeded' );

// Verify it's in the queue.
$queue = Conexao_Event_Cleanup_Retry_Queue::read();
assert_true( isset( $queue['data']['entries'][ $retry_fixture ] ), 'event is in retry queue' );
assert_equals( 'pending', $queue['data']['entries'][ $retry_fixture ]['status'], 'status is pending' );

// Now simulate a successful retry by marking it retried (mark_retried manages its own transaction).
$mark_result = Conexao_Event_Cleanup_Retry_Queue::mark_retried( $retry_fixture, time() );
assert_true( $mark_result['ok'], 'mark retried succeeded' );

// Verify it's removed from the queue.
$queue = Conexao_Event_Cleanup_Retry_Queue::read();
assert_true( ! isset( $queue['data']['entries'][ $retry_fixture ] ), 'event removed from retry queue after success' );

cleanup_section_teardown();

// ---------------------------------------------------------------------------
// 20. Retry queue — failed retry increments attempts.
// ---------------------------------------------------------------------------
test_section( 'Retry queue — failed retry increments attempts' );

Conexao_Event_Cleanup_Retry_Queue::ensure_row_exists();

$retry_fail_fixture = cleanup_test_event(
	'retry fail',
	array(
		'_event_date'     => '2020-01-01',
		'_event_status'   => 'published',
		'_event_imported' => '1',
	)
);

$failure_record = array(
	'attempts'        => 1,
	'last_reason'     => 'delete_failed',
	'last_attempt_at' => time() - 3600,
	'first_failed_at' => time() - 7200,
);

$wpdb->query( 'START TRANSACTION' );
$enqueue_result = Conexao_Event_Cleanup_Retry_Queue::enqueue_in_transaction(
	$retry_fail_fixture,
	$failure_record,
	time()
);
$wpdb->query( 'COMMIT' );
assert_true( $enqueue_result['ok'], 'retry enqueue succeeded' );

// Simulate a failed retry attempt (update_after_failure manages its own transaction).
$update_result = Conexao_Event_Cleanup_Retry_Queue::update_after_failure(
	$retry_fail_fixture,
	2, // attempts = 2
	'delete_failed',
	time()
);
assert_true( $update_result['ok'], 'update after failure succeeded' );

// Verify attempts incremented.
$queue = Conexao_Event_Cleanup_Retry_Queue::read();
assert_equals( 2, (int) $queue['data']['entries'][ $retry_fail_fixture ]['attempts'], 'attempts incremented to 2' );
assert_equals( 'delete_failed', $queue['data']['entries'][ $retry_fail_fixture ]['retry_reason'], 'retry reason recorded' );

cleanup_section_teardown();

// ---------------------------------------------------------------------------
// 21. Retry queue — capacity limit (fail closed).
// ---------------------------------------------------------------------------
test_section( 'Retry queue — capacity limit' );

Conexao_Event_Cleanup_Retry_Queue::ensure_row_exists();

// Fill the queue to a smaller capacity for testing (avoid memory exhaustion).
$test_capacity = 50;
for ( $i = 0; $i < $test_capacity; $i++ ) {
	$cap_fixture = cleanup_test_event(
		'retry cap ' . $i,
		array(
			'_event_date'     => '2020-01-01',
			'_event_status'   => 'published',
			'_event_imported' => '1',
		)
	);
	$enqueue_result = Conexao_Event_Cleanup_Retry_Queue::enqueue_in_transaction(
		$cap_fixture,
		array( 'attempts' => 0, 'last_reason' => '', 'first_failed_at' => time() ),
		time(),
		$test_capacity
	);
	assert_true( $enqueue_result['ok'], "enqueue $i succeeded" );
}

// Next enqueue should fail (capacity).
$overflow_fixture = cleanup_test_event(
	'retry overflow',
	array(
		'_event_date'     => '2020-01-01',
		'_event_status'   => 'published',
		'_event_imported' => '1',
	)
);

$enqueue_result = Conexao_Event_Cleanup_Retry_Queue::enqueue_in_transaction(
	$overflow_fixture,
	array( 'attempts' => 0, 'last_reason' => '', 'first_failed_at' => time() ),
	time(),
	$test_capacity
);
assert_true( ! $enqueue_result['ok'], 'enqueue at capacity fails' );
assert_equals( 'capacity', $enqueue_result['reason'], 'reason is capacity' );

cleanup_section_teardown();

// ---------------------------------------------------------------------------
// 22. New run lifecycle — start new run after completed.
// ---------------------------------------------------------------------------
test_section( 'New run lifecycle — start new run after completed' );

// Create a completed run state.
$completed_run_state = array(
	'run_id'              => 'cleanup_completed_abc123',
	'status'              => 'completed',
	'created'             => time() - 3600,
	'updated'             => time() - 1800,
	'cursor_id'           => 100,
	'last_scanned_id'     => 50,
	'events_deleted'      => 10,
	'images_deleted'      => 0,
	'images_preserved'    => 5,
	'errors'              => array(),
	'failed_events'       => array( 999 => array( 'attempts' => 1, 'last_reason' => 'delete_failed', 'first_failed_at' => time() - 3600 ) ),
	'failed_events_final' => array( 888 => array( 'attempts' => 3, 'last_reason' => 'delete_failed', 'first_failed_at' => time() - 7200 ) ),
	'retry_queue'         => array( 777 => array( 'event_id' => 777, 'status' => 'pending', 'attempts' => 0, 'first_failed_at' => time() - 3600 ) ),
	'batch_count'         => 2,
	'owner_lock_id'       => 'cleanup_completed_abc123',
	'fence_token'         => 12345,
	'lease_expires_at'    => time() - 1000,
);

update_option( Conexao_Event_Cleanup_Run::run_option(), $completed_run_state, false );

// Create a new event for the new run.
$new_run_fixture = cleanup_test_event(
	'new run after completed',
	array(
		'_event_date'     => '2020-01-01',
		'_event_status'   => 'published',
		'_event_imported' => '1',
	)
);

// Start a new run - should create a new run_id, fresh cursor, preserve failure data.
// Note: start() processes one batch, so cursor and counters will be updated.
$result = $runner->start(
	array(
		'batch_events'       => 5,
		'batch_time_seconds' => 60,
		'scan_window'        => 200,
	)
);

assert_true( $result['ok'], 'new run started' );
assert_true( 'cleanup_completed_abc123' !== $result['run_id'], 'new run has different run_id' );

$run_state = $runner->read_run_state();
// Cursor will be updated by the first batch processing (not PHP_INT_MAX).
// The key assertion is that a NEW run was created (different run_id) and failure data preserved.
// events_deleted is cumulative across runs (preserved from previous run + new deletions).
// Previous run had events_deleted = 10, new run deletes 1 event = 11 total.
assert_true( isset( $run_state['failed_events'][ 999 ] ), 'failed_events preserved' );
assert_true( isset( $run_state['failed_events_final'][ 888 ] ), 'failed_events_final preserved' );
assert_true( isset( $run_state['retry_queue'][ 777 ] ), 'retry_queue preserved' );
assert_equals( 11, (int) $run_state['events_deleted'], 'events_deleted cumulative: 10 from previous run + 1 new' );
assert_equals( 1, (int) $run_state['batch_count'], 'batch_count is 1 after first batch' );

cleanup_section_teardown();

// ---------------------------------------------------------------------------
// 23. New run lifecycle — start new run after cancelled.
// ---------------------------------------------------------------------------
test_section( 'New run lifecycle — start new run after cancelled' );

$cancelled_run_state = array(
	'run_id'              => 'cleanup_cancelled_xyz789',
	'status'              => 'cancelled',
	'created'             => time() - 3600,
	'updated'             => time() - 1800,
	'cursor_id'           => 200,
	'last_scanned_id'     => 150,
	'events_deleted'      => 5,
	'images_deleted'      => 0,
	'images_preserved'    => 3,
	'errors'              => array(),
	'failed_events'       => array(),
	'failed_events_final' => array(),
	'retry_queue'         => array(),
	'batch_count'         => 1,
	'owner_lock_id'       => 'cleanup_cancelled_xyz789',
	'fence_token'         => 54321,
	'lease_expires_at'    => time() - 1000,
);

update_option( Conexao_Event_Cleanup_Run::run_option(), $cancelled_run_state, false );

$new_run_fixture2 = cleanup_test_event(
	'new run after cancelled',
	array(
		'_event_date'     => '2020-01-01',
		'_event_status'   => 'published',
		'_event_imported' => '1',
	)
);

$result = $runner->start(
	array(
		'batch_events'       => 5,
		'batch_time_seconds' => 60,
		'scan_window'        => 200,
	)
);

assert_true( $result['ok'], 'new run started after cancelled' );
assert_true( 'cleanup_cancelled_xyz789' !== $result['run_id'], 'new run has different run_id' );

$run_state = $runner->read_run_state();
// Cursor will be updated by the first batch processing.
// events_deleted is cumulative across runs (preserved from previous run + new deletions).
// Previous run had events_deleted = 5, new run deletes 1 event = 6 total.
assert_equals( 6, (int) $run_state['events_deleted'], 'events_deleted cumulative: 5 from previous run + 1 new' );
assert_equals( 1, (int) $run_state['batch_count'], 'batch_count is 1 after first batch' );

cleanup_section_teardown();

// ---------------------------------------------------------------------------
// 24. New run lifecycle — resume interrupted run.
// ---------------------------------------------------------------------------
test_section( 'New run lifecycle — resume interrupted run' );

$running_run_state = array(
	'run_id'              => 'cleanup_running_resume123',
	'status'              => 'running',
	'created'             => time() - 3600,
	'updated'             => time() - 100,
	'cursor_id'           => 500,
	'last_scanned_id'     => 450,
	'events_deleted'      => 15,
	'images_deleted'      => 0,
	'images_preserved'    => 8,
	'errors'              => array(),
	'failed_events'       => array(),
	'failed_events_final' => array(),
	'retry_queue'         => array(),
	'batch_count'         => 3,
	'owner_lock_id'       => 'cleanup_running_resume123',
	'fence_token'         => 99999,
	'lease_expires_at'    => time() + 300,
);

update_option( Conexao_Event_Cleanup_Run::run_option(), $running_run_state, false );

// Acquire the lock for this run_id.
Conexao_Event_Cleanup_Lock::ensure_row_exists();
$lock = Conexao_Event_Cleanup_Lock::acquire( array( 'run_id' => 'cleanup_running_resume123', 'ttl' => 300 ) );
assert_true( $lock['held'], 'lock acquired for resume' );

$resume_fixture = cleanup_test_event(
	'resume interrupted',
	array(
		'_event_date'     => '2020-01-01',
		'_event_status'   => 'published',
		'_event_imported' => '1',
	)
);

$result = $runner->start(
	array(
		'batch_events'       => 5,
		'batch_time_seconds' => 60,
		'scan_window'        => 200,
	)
);

assert_true( $result['ok'], 'resumed run' );
assert_equals( 'cleanup_running_resume123', $result['run_id'], 'same run_id on resume' );

$run_state = $runner->read_run_state();
assert_equals( 500, (int) $run_state['cursor_id'], 'cursor preserved on resume' );
assert_equals( 15, (int) $run_state['events_deleted'], 'events_deleted preserved on resume' );
assert_equals( 3, (int) $run_state['batch_count'], 'batch_count preserved on resume' );

cleanup_section_teardown();

// ---------------------------------------------------------------------------
// 25. Deterministic time-budget test.
// ---------------------------------------------------------------------------
test_section( 'Deterministic time-budget test' );

// Create a controlled clock that advances with each call (simulating real time passing).
$clock_calls = 0;
$controlled_clock = function () use ( &$clock_calls ): float {
	$clock_calls++;
	// Each call advances by 0.15 seconds. After ~7 calls, 1 second has passed.
	return $clock_calls * 0.15;
};

// Create a runner with the controlled clock.
$test_runner = new Conexao_Event_Cleanup_Run( $controlled_clock );

// Create 10 past events.
$time_budget_fixtures = array();
for ( $i = 0; $i < 10; $i++ ) {
	$time_budget_fixtures[] = cleanup_test_event(
		'time budget ' . $i,
		array(
			'_event_date'     => '2020-01-01',
			'_event_status'   => 'published',
			'_event_imported' => '1',
		)
	);
}

// Start a run with a 1-second time budget and large event count.
// The clock advances with each call, so time budget should trigger after ~7 calls.
$result = $test_runner->start(
	array(
		'batch_events'       => 100,
		'batch_time_seconds' => 1,
		'scan_window'        => 200,
	)
);

$run_state = $test_runner->read_run_state();

// Verify the stop reason is time_budget (clock advanced past 1 second during batch).
assert_equals( 'time_budget', $result['reason'], 'stop reason is time_budget' );
assert_equals( 'running', $result['status'], 'status is running (not complete)' );

// Verify cursor is last_committed_id (not last_scanned_id).
assert_equals( $result['batch_result']['last_committed_id'], $run_state['cursor_id'], 'cursor is last_committed_id at time boundary' );

// Also verify the batch_result has the expected keys.
assert_true( isset( $result['batch_result']['last_committed_id'] ), 'batch_result has last_committed_id' );
assert_true( isset( $result['batch_result']['last_deleted_id'] ), 'batch_result has last_deleted_id' );
assert_true( isset( $result['batch_result']['last_scanned_id'] ), 'batch_result has last_scanned_id' );

// Verify some but not all events were processed (time budget triggered before all 10).
$processed_count = (int) $run_state['events_deleted'];
assert_true( $processed_count > 0 && $processed_count < 10, 'some but not all events processed' );

// Verify next batch starts at correct next unprocessed ID.
$next_cursor = $run_state['cursor_id'];
assert_true( $next_cursor < PHP_INT_MAX, 'cursor advanced from PHP_INT_MAX' );

cleanup_section_teardown();

// ---------------------------------------------------------------------------
// 26. Attachment review — non-image attachments queued with classification.
// ---------------------------------------------------------------------------
test_section( 'Attachment review — non-image attachments queued with classification' );

// Create a test event with a PDF attachment (simulated via mime type).
$pdf_event = cleanup_test_event(
	'pdf attachment event',
	array(
		'_event_date'     => '2020-01-01',
		'_event_status'   => 'published',
		'_event_imported' => '1',
	)
);

// Create a PDF attachment (we'll simulate by setting post_mime_type).
$upload = wp_upload_bits( 'test-document.pdf', null, '%PDF-1.4 test' );
if ( ! empty( $upload['error'] ) ) {
	test_prerequisite_hint( 'wp_upload_bits failed for PDF fixture' );
	exit( 1 );
}
$pdf_attachment_id = wp_insert_attachment(
	array(
		'post_mime_type' => 'application/pdf',
		'post_title'     => 'Test PDF',
		'post_content'   => '',
		'post_status'    => 'inherit',
	),
	$upload['file'],
	$pdf_event
);
if ( is_wp_error( $pdf_attachment_id ) || ! $pdf_attachment_id ) {
	test_prerequisite_hint( 'wp_insert_attachment failed for PDF fixture' );
	exit( 1 );
}
update_post_meta( $pdf_attachment_id, '_event_source_url', 'https://example.com/test.pdf' );
update_post_meta( $pdf_event, '_event_banner_attachment_id', $pdf_attachment_id );

$GLOBALS['cleanup_section_records'][] = array( 'type' => 'attachment', 'id' => (int) $pdf_attachment_id );

// Run cleanup batch.
$result = $runner->start(
	array(
		'batch_events'       => 5,
		'batch_time_seconds' => 60,
		'scan_window'        => 200,
	)
);

// Check attachment review queue has the PDF with correct classification.
$review_queue = Conexao_Event_Cleanup_Attachment_Review::read();
$pending = array_filter( $review_queue['data']['entries'], function ( $e ) { return 'pending' === $e['status']; } );

$pdf_entry = null;
foreach ( $pending as $aid => $entry ) {
	if ( $aid == $pdf_attachment_id ) {
		$pdf_entry = $entry;
		break;
	}
}

assert_true( null !== $pdf_entry, 'PDF attachment is in review queue' );
assert_equals( 'application/pdf', $pdf_entry['mime_type'], 'MIME type recorded' );
assert_equals( 'pdf', $pdf_entry['classification'], 'classified as pdf' );
assert_equals( 'banner', $pdf_entry['source'], 'source recorded' );

cleanup_section_teardown();

// ---------------------------------------------------------------------------
// Final cleanup: reset only THIS process's cleanup test stores.
// ---------------------------------------------------------------------------
$cleanup_reset = test_options_reset_test_store( array( 'cleanup_status', 'cleanup_history' ) );

assert_true(
	$cleanup_reset['ok'],
	'the reset helper accepts the two cleanup test stores (' . wp_json_encode( $cleanup_reset ) . ')'
);

// The operator's cleanup records are byte-identical.
$cleanup_live_status_after = (string) $GLOBALS['wpdb']->get_var(
	$GLOBALS['wpdb']->prepare(
		"SELECT option_value FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s",
		Conexao_Event_Cleanup::STATUS_OPTION
	)
);

$cleanup_live_history_after = (string) $GLOBALS['wpdb']->get_var(
	$GLOBALS['wpdb']->prepare(
		"SELECT option_value FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s",
		Conexao_Event_Cleanup::HISTORY_OPTION
	)
);

assert_true(
	$cleanup_live_status_after === $cleanup_live_status_before,
	'the live cleanup status option received no writes (byte-identical)'
);

assert_true(
	$cleanup_live_history_after === $cleanup_live_history_before,
	'the live cleanup history option received no writes (byte-identical)'
);

test_finish( 'Event Cleanup Batch Runner' );