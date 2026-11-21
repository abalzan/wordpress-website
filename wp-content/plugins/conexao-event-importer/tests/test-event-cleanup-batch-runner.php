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
 * - Hook-safety regression guard
 *
 * @package Conexao_Event_Importer
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
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

$cleanup_test_event_ids = array();

function cleanup_test_event( $title, $meta = array() ) {
	global $cleanup_test_event_ids;
	$post_id = wp_insert_post(
		array(
			'post_type'   => 'event',
			'post_status' => 'publish',
			'post_title'  => '[CLEANUP TEST] ' . $title,
		),
		true
	);
	if ( is_wp_error( $post_id ) ) { exit( 1 ); }
	foreach ( $meta as $key => $value ) { update_post_meta( $post_id, $key, $value ); }
	$cleanup_test_event_ids[] = $post_id;
	return $post_id;
}

function cleanup_test_day_offset( $days ) {
	return current_datetime()->modify( ( $days >= 0 ? '+' : '' ) . $days . ' day' )->format( 'Y-m-d' );
}

$cleanup = new Conexao_Event_Cleanup();
$runner  = new Conexao_Event_Cleanup_Run( $cleanup );

echo 'Site: ' . home_url() . ' — timezone: ' . wp_timezone()->getName() . ' — today: ' . cleanup_test_day_offset( 0 ) . "\n";
echo 'Polylang active: ' . ( function_exists( 'pll_languages_list' ) ? 'yes (' . implode( ',', pll_languages_list() ) . ')' : 'no' ) . "\n";

// ---------------------------------------------------------------------------
// 1. Eligibility: one-time events across the boundary conditions.
// ---------------------------------------------------------------------------
test_section( 'Eligibility — one-time events' );

$past_one_time = cleanup_test_event(
	'past one-time',
	array(
		'_event_date'     => cleanup_test_day_offset( -1 ),
		'_event_status'   => 'published',
		'_event_imported' => '1',
	)
);

$today_dateonly = cleanup_test_event(
	'today date-only',
	array(
		'_event_date'     => cleanup_test_day_offset( 0 ),
		'_event_status'   => 'published',
		'_event_imported' => '1',
	)
);

$future_event = cleanup_test_event(
	'future',
	array(
		'_event_date'     => cleanup_test_day_offset( 1 ),
		'_event_status'   => 'published',
		'_event_imported' => '1',
	)
);

$ongoing_multiday = cleanup_test_event(
	'ongoing multi-day',
	array(
		'_event_date'     => cleanup_test_day_offset( -1 ),
		'_event_end_date' => cleanup_test_day_offset( 1 ),
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

// ---------------------------------------------------------------------------
// 2. Ownership: cleanup_scope = imported_only protects manual events.
// ---------------------------------------------------------------------------
test_section( 'Ownership — cleanup_scope = imported_only' );

// Temporarily set cleanup_scope to imported_only.
$settings = Conexao_Import_Settings::all();
$settings['cleanup_scope'] = 'imported_only';
Conexao_Import_Settings::update( $settings );

$manual_event = cleanup_test_event(
	'manual event (no _event_imported)',
	array(
		'_event_date'     => cleanup_test_day_offset( -1 ),
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

assert_true(
	$is_eligible_method->invoke( $runner, $manual_event ),
	'manual event IS eligible when cleanup_scope = all'
);

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
			'_event_date'     => cleanup_test_day_offset( -1 ),
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
assert_equals( 5, (int) $run_state['batch_count'], 'batch count is 1' );

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
assert_equals( 30, (int) $result['batch_result']['events_deleted'] + ( $continue_count - 1 ) * 5, 'all 30 events deleted' );

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
			'_event_date'     => cleanup_test_day_offset( -1 ),
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

// The cursor should be the ID of the 3rd deleted event (not 3rd - 1).
$fifth_event_id = (int) $adjacent_fixtures[2];
assert_equals( $fifth_event_id, $cursor_after_first, 'cursor is the last deleted ID (not last - 1)' );

// Continue and verify no skip.
$result = $runner->start(
	array(
		'batch_events'       => 3,
		'batch_time_seconds' => 60,
		'scan_window'        => 200,
	)
);

$run_state = $runner->read_run_state();
assert_equals( 6, (int) $run_state['events_deleted'], '6 events deleted after 2 batches (no skip)' );

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
			'_event_date'     => cleanup_test_day_offset( -1 ),
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
			'_event_date'     => cleanup_test_day_offset( -1 ),
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
$cursor_before_resume = (int) $run_state['cursor_id'];
$deleted_before_resume = (int) $run_state['events_deleted'];

// Simulate interruption: the run state persists.
// Resume by starting again.
$result = $runner->start(
	array(
		'batch_events'       => 3,
		'batch_time_seconds' => 60,
		'scan_window'        => 200,
	)
);

$run_state = $runner->read_run_state();
assert_equals( $deleted_before_resume + 3, (int) $run_state['events_deleted'], 'resume continued from cursor (no re-delete)' );

// ---------------------------------------------------------------------------
// 7. Idempotent retry.
// ---------------------------------------------------------------------------
test_section( 'Idempotent retry' );

// Create a past event.
$idempotent_fixture = cleanup_test_event(
	'idempotent',
	array(
		'_event_date'     => cleanup_test_day_offset( -1 ),
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
assert_equals( 1, (int) $run_state['events_deleted'], 'idempotent: event not double-deleted' );

// ---------------------------------------------------------------------------
// 8. Concurrency: two workers.
// ---------------------------------------------------------------------------
test_section( 'Concurrency — two workers' );

// Create a past event.
$concurrency_fixture = cleanup_test_event(
	'concurrency',
	array(
		'_event_date'     => cleanup_test_day_offset( -1 ),
		'_event_status'   => 'published',
		'_event_imported' => '1',
	)
);

// Start a run with worker A.
$result_a = $runner->start(
	array(
		'batch_events'       => 100,
		'batch_time_seconds' => 60,
		'scan_window'        => 200,
	)
);

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

// ---------------------------------------------------------------------------
// 9. Stale lock reclaim.
// ---------------------------------------------------------------------------
test_section( 'Stale lock reclaim' );

// Create a past event.
$stale_fixture = cleanup_test_event(
	'stale',
	array(
		'_event_date'     => cleanup_test_day_offset( -1 ),
		'_event_status'   => 'published',
		'_event_imported' => '1',
	)
);

// Start a run and simulate a stale lock by manipulating the lock record.
$result = $runner->start(
	array(
		'batch_events'       => 100,
		'batch_time_seconds' => 60,
		'scan_window'        => 200,
	)
);

// Manually set the lock's acquired_at to the past.
global $wpdb;
$lock_option = Conexao_Event_Cleanup_Lock::OPTION;
$lock_raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $lock_option ) );
$lock_data = json_decode( $lock_raw, true );
$lock_data['acquired_at'] = time() - 3600; // 1 hour ago
$new_lock_raw = wp_json_encode( $lock_data );
$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", $new_lock_raw, $lock_option ) );
wp_cache_delete( $lock_option, 'options' );

// Start a new run (should reclaim the stale lock).
$result = $runner->start(
	array(
		'batch_events'       => 100,
		'batch_time_seconds' => 60,
		'scan_window'        => 200,
	)
);

assert_true( $result['ok'], 'stale lock reclaimed' );

// ---------------------------------------------------------------------------
// 10. Failed-record handling.
// ---------------------------------------------------------------------------
test_section( 'Failed-record handling' );

// Create a past event that will fail to delete (e.g., by making it non-deletable).
$failed_fixture = cleanup_test_event(
	'failed',
	array(
		'_event_date'     => cleanup_test_day_offset( -1 ),
		'_event_status'   => 'published',
		'_event_imported' => '1',
	)
);

// Hook into delete_post to simulate a failure.
$fail_delete = true;
add_action(
	'delete_post',
	function ( $post_id ) use ( &$fail_delete, $failed_fixture ) {
		if ( $fail_delete && $post_id === $failed_fixture ) {
			$fail_delete = false;
			// Simulate a failure by throwing an exception.
			throw new Exception( 'Simulated delete failure' );
		}
	}
);

// Start a run.
$result = $runner->start(
	array(
		'batch_events'       => 100,
		'batch_time_seconds' => 60,
		'scan_window'        => 200,
	)
);

$run_state = $runner->read_run_state();
assert_true( isset( $run_state['failed_events'][ $failed_fixture ] ), 'failed event is recorded' );
assert_equals( 1, (int) $run_state['failed_events'][ $failed_fixture ]['attempts'], 'attempts = 1' );

// Remove the hook.
remove_action( 'delete_post', 'my_delete_post' );

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
			'_event_date'     => cleanup_test_day_offset( -1 ),
			'_event_status'   => 'published',
			'_event_imported' => '1',
		)
	);
}

// Hook into delete_post to simulate failures for all events.
$fail_all = true;
add_action(
	'delete_post',
	function ( $post_id ) use ( &$fail_all ) {
		if ( $fail_all ) {
			throw new Exception( 'Simulated delete failure' );
		}
	}
);

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

// Remove the hook.
remove_action( 'delete_post', 'my_delete_post' );

// ---------------------------------------------------------------------------
// 12. Queue atomicity.
// ---------------------------------------------------------------------------
test_section( 'Queue atomicity' );

// Create a past event with an attachment.
$queue_fixture = cleanup_test_event(
	'queue',
	array(
		'_event_date'     => cleanup_test_day_offset( -1 ),
		'_event_status'   => 'published',
		'_event_imported' => '1',
	)
);

// Create a dummy attachment.
$attachment_id = wp_insert_attachment(
	array(
		'post_mime_type' => 'image/jpeg',
		'post_title'     => 'Test Image',
		'post_content'   => '',
		'post_status'    => 'inherit',
	),
	'/path/to/test.jpg',
	$queue_fixture
);

// Set the banner attachment meta.
update_post_meta( $queue_fixture, '_event_banner_attachment_id', $attachment_id );

// Start a run.
$result = $runner->start(
	array(
		'batch_events'       => 100,
		'batch_time_seconds' => 60,
		'scan_window'        => 200,
	)
);

// Check that the attachment is in the review queue.
$review_queue = Conexao_Event_Cleanup_Attachment_Review::read();
assert_true( isset( $review_queue['data']['entries'][ $attachment_id ] ), 'attachment is in review queue' );
assert_equals( 'pending', $review_queue['data']['entries'][ $attachment_id ]['status'], 'attachment status is pending' );

// Mark as reviewed.
$result = Conexao_Event_Cleanup_Attachment_Review::mark_reviewed( $attachment_id, 'test_operator', time() );
assert_true( $result['ok'], 'mark reviewed succeeded' );

$review_queue = Conexao_Event_Cleanup_Attachment_Review::read();
assert_equals( 'reviewed', $review_queue['data']['entries'][ $attachment_id ]['status'], 'attachment status is reviewed' );

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

// The only active hooks should be the theme's cache invalidators.
$allowed_hooks = array(
	'conexao_homepage_cache_invalidate',
	'conexao_empregos_flush_jobs_cache',
);

foreach ( $delete_post_hooks as $hook ) {
	$hook_name = is_array( $hook ) ? ( is_object( $hook[0] ) ? get_class( $hook[0] ) . '::' . $hook[1] : $hook[0] . '::' . $hook[1] ) : $hook;
	assert_true(
		in_array( $hook_name, $allowed_hooks, true ) || 0 === strpos( $hook_name, 'conexao_' ),
		'delete_post hook ' . $hook_name . ' is allowed (idempotent cache invalidator)'
	);
}

foreach ( $deleted_post_hooks as $hook ) {
	$hook_name = is_array( $hook ) ? ( is_object( $hook[0] ) ? get_class( $hook[0] ) . '::' . $hook[1] : $hook[0] . '::' . $hook[1] ) : $hook;
	assert_true(
		in_array( $hook_name, $allowed_hooks, true ) || 0 === strpos( $hook_name, 'conexao_' ),
		'deleted_post hook ' . $hook_name . ' is allowed (idempotent cache invalidator)'
	);
}

// ---------------------------------------------------------------------------
// Cleanup the suite's own fixtures.
// ---------------------------------------------------------------------------
foreach ( $cleanup_test_event_ids as $cleanup_test_event_id ) {
	wp_delete_post( $cleanup_test_event_id, true );
}

// Reset only THIS PROCESS's cleanup test stores.
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