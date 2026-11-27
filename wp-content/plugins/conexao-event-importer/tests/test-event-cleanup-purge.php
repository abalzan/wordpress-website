<?php
/**
 * Tests for the manual event cleanup engine (Conexao_Event_Cleanup).
 *
 * Regression suite for the "past events not purged" defect.
 *
 * ## Safety (Stage 0.5)
 *
 * This suite creates its own event fixtures and deletes exactly those. It does
 * NOT call Conexao_Event_Cleanup::run_cleanup(), because that deletes EVERY past
 * event in the shared database; the per-fixture apply path (`delete_event_with_media`)
 * plus a sentinel prove the same deletion semantics with a bounded blast radius.
 *
 * The cleanup status/history options are redirected to this process's test rows
 * by their accessors and reset through test_options_reset_test_store(), which
 * validates the keys before deleting anything.
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/includes/class-event-cleanup.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/includes/class-import-settings.php';

// Secondary safeguard: prove the per-process store isolation before touching
// any cleanup option or content.
test_require_operator_store_isolation();

// Read-only baseline of the operator's cleanup records. The suite must leave
// both byte-identical: its own writes go to this process's test rows.
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

// Replicate the production trigger context: cleanup runs from wp-admin
// (admin-post.php) or WP-CLI, where is_admin() / WP_CLI bypass the event
// runtime's public "_event_status" gate. Define WP_ADMIN so this suite
// exercises the same candidate set the admin "Run Cleanup Now" button sees.
// Without this, a plain CLI run would let the frontend gate hide expired /
// draft / source_not_found events from cleanup — which never happens in
// production.
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

function cleanup_test_is_selected( $cleanup, $post_id ) {
	$method = new ReflectionMethod( $cleanup, 'find_past_events' );
	$method->setAccessible( true );
	$selected = array_map( 'intval', (array) $method->invoke( $cleanup ) );
	return in_array( (int) $post_id, $selected, true );
}

function cleanup_test_selected_ids( $cleanup ) {
	$method = new ReflectionMethod( $cleanup, 'find_past_events' );
	$method->setAccessible( true );
	return array_map( 'intval', (array) $method->invoke( $cleanup ) );
}

function cleanup_test_apply_one( $cleanup, $post_id ) {
	$result = array(
		'events_found'     => 1,
		'events_deleted'   => 0,
		'images_evaluated' => 0,
		'images_deleted'   => 0,
		'images_preserved' => 0,
		'errors'           => array(),
	);
	$method = new ReflectionMethod( $cleanup, 'delete_event_with_media' );
	$method->setAccessible( true );
	$method->invokeArgs( $cleanup, array( (int) $post_id, &$result ) );
	return $result;
}

$cleanup = new Conexao_Event_Cleanup();
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

assert_true(
	cleanup_test_is_selected( $cleanup, $past_one_time ),
	'past one-time event (ended yesterday) is selected for cleanup'
);
assert_true(
	! cleanup_test_is_selected( $cleanup, $today_dateonly ),
	'today date-only event is NOT selected (still running until end of day)'
);
assert_true(
	! cleanup_test_is_selected( $cleanup, $future_event ),
	'future event (tomorrow) is NOT selected'
);
assert_true(
	! cleanup_test_is_selected( $cleanup, $ongoing_multiday ),
	'ongoing multi-day event (ends tomorrow) is NOT selected'
);
assert_true(
	! cleanup_test_is_selected( $cleanup, $no_date_event ),
	'event with no date is NOT selected (manual review territory, conservatively preserved)'
);
// ---------------------------------------------------------------------------
// 2. Ownership: cleanup_scope = imported_only protects manual events.
// ---------------------------------------------------------------------------
test_section( 'Ownership — cleanup_scope' );

Conexao_Import_Settings::update( array( 'cleanup_scope' => 'imported_only' ) );

// Both owned fixtures carry _event_imported => '1'. A *manual* event is one
// created WITHOUT this meta key — it must never be selected under imported_only.
$owned_manual_past_event = cleanup_test_event(
	'manual past (no _event_imported)',
	array(
		'_event_date'   => cleanup_test_day_offset( -1 ),
		'_event_status' => 'published',
	)
);

$owned_imported_past_event = cleanup_test_event(
	'imported past (with _event_imported)',
	array(
		'_event_date'     => cleanup_test_day_offset( -1 ),
		'_event_status'   => 'published',
		'_event_imported' => '1',
	)
);

assert_true(
	! cleanup_test_is_selected( $cleanup, $owned_manual_past_event ),
	'manual (non-imported) past event is NOT selected when cleanup_scope=imported_only'
);
assert_true(
	cleanup_test_is_selected( $cleanup, $owned_imported_past_event ),
	'imported past event IS selected when cleanup_scope=imported_only'
);

Conexao_Import_Settings::update( array() );

// ---------------------------------------------------------------------------
// 3. Recurring events and series.
// ---------------------------------------------------------------------------
test_section( 'Recurrence' );

$recurrence_ended_series = cleanup_test_event(
	'weekly series already ended',
	array(
		'_event_date'            => cleanup_test_day_offset( -21 ),
		'_event_status'          => 'published',
		'_event_imported'        => '1',
		'_event_recurrence'      => 'weekly',
		'_event_recurrence_days' => (string) current_datetime()->format( 'N' ),
		'_event_recurrence_end'  => cleanup_test_day_offset( -7 ),
	)
);

$recurrence_active_series = cleanup_test_event(
	'weekly series still active',
	array(
		'_event_date'            => cleanup_test_day_offset( -14 ),
		'_event_status'          => 'published',
		'_event_imported'        => '1',
		'_event_recurrence'      => 'weekly',
		'_event_recurrence_days' => (string) current_datetime()->format( 'N' ),
		'_event_recurrence_end'  => cleanup_test_day_offset( 7 ),
	)
);

assert_true(
	cleanup_test_is_selected( $cleanup, $recurrence_ended_series ),
	'weekly series whose recurrence window has already ENDED is selected (all occurrences are past)'
);
assert_true(
	! cleanup_test_is_selected( $cleanup, $recurrence_active_series ),
	'weekly series with future occurrences remaining is NOT selected (an ended single occurrence must not purge an active series)'
);

// ---------------------------------------------------------------------------
// 4. Hidden-status events (expired/draft/source_not_found) are still eligible.
// ---------------------------------------------------------------------------
test_section( 'Hidden-status events are eligible' );

$hidden_statuses = array();

foreach ( array( 'expired', 'draft', 'source_not_found' ) as $hidden_status ) {
	$hidden_statuses[ $hidden_status ] = cleanup_test_event(
		'past event with _event_status=' . $hidden_status,
		array(
			'_event_date'     => cleanup_test_day_offset( -3 ),
			'_event_status'   => $hidden_status,
			'_event_imported' => '1',
		)
	);
}

foreach ( $hidden_statuses as $hidden_status => $hidden_id ) {
	assert_true(
		cleanup_test_is_selected( $cleanup, $hidden_id ),
		'past event hidden from the public frontend by _event_status=' . $hidden_status . ' is STILL selected for cleanup'
	);
}

// ---------------------------------------------------------------------------
// 5. Dry-run: selection performs zero writes.
// ---------------------------------------------------------------------------
test_section( 'Dry-run performs zero writes' );

$dry_run_snapshot = array();
foreach ( $cleanup_test_event_ids as $fixture_id ) {
	$dry_run_snapshot[ $fixture_id ] = array(
		'status' => get_post_status( $fixture_id ),
		'title'  => get_the_title( $fixture_id ),
	);
}

$selected_before_dry_run = cleanup_test_selected_ids( $cleanup );
$selected_again          = cleanup_test_selected_ids( $cleanup );

assert_true(
	$selected_before_dry_run === $selected_again,
	'selection is deterministic: two consecutive selection passes return the identical candidate list'
);

foreach ( $dry_run_snapshot as $fixture_id => $snapshot ) {
	assert_equals(
		$snapshot['status'],
		get_post_status( $fixture_id ),
		'selection does not change the post_status of fixture #' . $fixture_id
	);
	assert_equals(
		$snapshot['title'],
		get_the_title( $fixture_id ),
		'selection does not change the title of fixture #' . $fixture_id
	);
}
// ---------------------------------------------------------------------------
// 6. Apply: eligible events are purged; ineligible ones are untouched.
// ---------------------------------------------------------------------------
test_section( 'Apply purges eligible events' );

$eligible_fixtures = array(
	'past one-time'                    => $past_one_time,
	'imported past (ownership scope)'  => $owned_imported_past_event,
	'manual past (ownership scope)'    => $owned_manual_past_event,
	'ended weekly series'              => $recurrence_ended_series,
);
foreach ( $hidden_statuses as $hidden_status => $hidden_id ) {
	$eligible_fixtures[ 'hidden-status ' . $hidden_status ] = $hidden_id;
}

$protected_fixtures = array(
	'today date-only'                  => $today_dateonly,
	'future'                           => $future_event,
	'ongoing multi-day'                => $ongoing_multiday,
	'no date'                          => $no_date_event,
	'active weekly series'             => $recurrence_active_series,
);

foreach ( $eligible_fixtures as $label => $fixture_id ) {
	$apply_result = cleanup_test_apply_one( $cleanup, $fixture_id );

	assert_equals(
		1,
		(int) $apply_result['events_deleted'],
		'applying cleanup to ' . $label . ' reports exactly one deletion'
	);
	assert_true(
		empty( $apply_result['errors'] ),
		'applying cleanup to ' . $label . ' reports no errors'
	);
	assert_true(
		false === get_post_status( $fixture_id ),
		$label . ' is PURGED (post row removed from the database, not merely trashed)'
	);
}

foreach ( $protected_fixtures as $label => $fixture_id ) {
	assert_equals(
		'publish',
		get_post_status( $fixture_id ),
		$label . ' SURVIVES (still publish) — never passed through the apply path'
	);
	assert_true(
		! cleanup_test_is_selected( $cleanup, $fixture_id ),
		$label . ' was never a cleanup candidate in the first place'
	);
}
// ---------------------------------------------------------------------------
// 8. Failure handling: a failed delete is surfaced, never counted as success.
// ---------------------------------------------------------------------------
test_section( 'Failure handling' );

$failed_delete_fixture = cleanup_test_event(
	'past event whose delete will be blocked',
	array(
		'_event_date'     => cleanup_test_day_offset( -1 ),
		'_event_status'   => 'published',
		'_event_imported' => '1',
	)
 );

add_filter(
	'pre_delete_post',
	function ( $check, $post ) use ( $failed_delete_fixture ) {
		if ( (int) $post->ID === (int) $failed_delete_fixture ) {
			return false;
		}
		return $check;
	},
	10,
	2
 );

$threw       = false;

try {
	cleanup_test_apply_one( $cleanup, $failed_delete_fixture );
} catch ( Exception $e ) {
	$threw = true;
}

assert_true(
	$threw,
	'a blocked delete throws instead of silently reporting success'
 );
assert_equals(
	'publish',
	get_post_status( $failed_delete_fixture ),
	'the blocked fixture SURVIVES (the delete genuinely did not happen)'
 );
assert_true(
	cleanup_test_is_selected( $cleanup, $failed_delete_fixture ),
	'the blocked fixture remains a cleanup candidate for a later, successful run (idempotent retry path)'
 );

remove_all_filters( 'pre_delete_post' );

$retry_result = cleanup_test_apply_one( $cleanup, $failed_delete_fixture );
assert_true(
	false === get_post_status( $failed_delete_fixture ),
	'once the block is removed, the same fixture is PURGED on retry'
 );

// ---------------------------------------------------------------------------
// 9. Idempotency: re-applying to an already-purged fixture is a clean no-op.
// ---------------------------------------------------------------------------
test_section( 'Idempotency' );

$purge_again_threw = false;
try {
	$purge_again = cleanup_test_apply_one( $cleanup, $past_one_time );
	assert_equals(
		0,
		(int) $purge_again['events_deleted'],
		're-applying cleanup to an already-purged fixture deletes nothing (events_deleted=0)'
	 );
} catch ( Exception $e ) {
	$purge_again_threw = true;
}

assert_true(
	! $purge_again_threw,
	're-applying cleanup to an already-purged fixture does not throw a spurious error'
 );

// ---------------------------------------------------------------------------
// 10. Cache invalidation helper remains reachable.
// ---------------------------------------------------------------------------
test_section( 'Cache invalidation' );

assert_true(
	class_exists( 'Conexao_Event_Query' ) && method_exists( 'Conexao_Event_Query', 'flush_cache' ),
	'Conexao_Event_Query::flush_cache() exists for cache invalidation after deletion'
 );

// ---------------------------------------------------------------------------
// 11. Eligibility: more than one cleanup batch of expired events.
// ---------------------------------------------------------------------------
test_section( 'Eligibility — multi-batch (beyond a single SQL batch)' );

$multi_batch_fixtures = array();
for ( $i = 0; $i < 210; $i++ ) {
    $multi_batch_fixtures[] = cleanup_test_event(
        'multi-batch expired ' . $i,
        array(
            '_event_date'     => cleanup_test_day_offset( -1 ),
            '_event_status'   => 'published',
            '_event_imported' => '1',
        )
    );
}

// With the fix, find_past_events() paginates through every batch, so events
// beyond the first MAX_EVENTS_PER_RUN SQL rows are still selected.
$selected = cleanup_test_selected_ids( $cleanup );
foreach ( $multi_batch_fixtures as $fixture_id ) {
    assert_true(
        in_array( (int) $fixture_id, $selected, true ),
        'multi-batch expired fixture #' . $fixture_id . ' is selected even though it falls beyond the first SQL batch'
    );
}

// ---------------------------------------------------------------------------
// 12. Re-query after cleanup: deleted expired events are no longer selected.
// ---------------------------------------------------------------------------
test_section( 'Re-query after cleanup' );

foreach ( $multi_batch_fixtures as $fixture_id ) {
    $r = array(
        'events_found'     => 1,
        'events_deleted'   => 0,
        'images_evaluated' => 0,
        'images_deleted'   => 0,
        'images_preserved' => 0,
        'errors'           => array(),
    );
    $method = new ReflectionMethod( $cleanup, 'delete_event_with_media' );
    $method->setAccessible( true );
    $method->invokeArgs( $cleanup, array( (int) $fixture_id, &$r ) );
    assert_equals( 1, (int) $r['events_deleted'], 'fixture #' . $fixture_id . ' deleted by apply path' );
}

$selected_again = cleanup_test_selected_ids( $cleanup );
foreach ( $multi_batch_fixtures as $fixture_id ) {
    assert_true(
        ! in_array( (int) $fixture_id, $selected_again, true ),
        'expired fixture #' . $fixture_id . ' is no longer selected after deletion (second query confirms purge)'
    );
}

// ---------------------------------------------------------------------------
// 13. Cache invalidation: flush_cache() actually clears event-query transients.
// ---------------------------------------------------------------------------
test_section( 'Cache invalidation — flush_cache() clears transients' );

if ( class_exists( 'Conexao_Event_Query' ) ) {
    $today = Conexao_Event_Query::today();
    $cache_key_method = new ReflectionMethod( 'Conexao_Event_Query', 'cache_key' );
    $cache_key_method->setAccessible( true );
    $key = $cache_key_method->invoke( null, $today, 'pt' );
    set_transient( $key, 'stale-event-list', HOUR_IN_SECONDS );
    assert_true( false !== get_transient( $key ), 'sentinel transient is present before flush' );
    Conexao_Event_Query::flush_cache();
    assert_true( false === get_transient( $key ), 'event-query transient is cleared by flush_cache()' );
}

// ---------------------------------------------------------------------------
// 14. Per-fixture cleanup: each fixture is purged individually, and an
//     unrelated past event is NOT touched.
//
// Stage 0.5 removed the global run_cleanup() call from this suite. run_cleanup()
// deletes EVERY past event in the shared database — operator content included —
// and cannot be made safe without a dedicated test database. The per-fixture
// apply path below deletes exactly the ids this suite created, and the sentinel
// proves the suite's scope is its own fixtures.
//
// Coverage note (docs/testing.md (Test isolation)): the
// end-to-end run_cleanup() proof is DEFERRED to the isolated-database stage.
// ---------------------------------------------------------------------------
test_section( 'Per-fixture cleanup — each fixture purged individually' );

$end_to_end_fixtures = array();
for ( $i = 0; $i < 5; $i++ ) {
    $end_to_end_fixtures[] = cleanup_test_event(
        'end-to-end expired ' . $i,
        array(
            '_event_date'     => cleanup_test_day_offset( -1 ),
            '_event_status'   => 'published',
            '_event_imported' => '1',
        )
    );
}

// A past event that belongs to no fixture list. If the suite still deleted
// everything the engine selected, this would disappear; it must survive.
$cleanup_unrelated_sentinel = cleanup_test_event(
    'unrelated past sentinel (must survive)',
    array(
        '_event_date'     => cleanup_test_day_offset( -2 ),
        '_event_status'   => 'published',
        '_event_imported' => '1',
    )
);

assert_true(
    cleanup_test_is_selected( $cleanup, $cleanup_unrelated_sentinel ),
    'the unrelated past sentinel IS a cleanup candidate (so its survival proves scope, not eligibility)'
);

foreach ( $end_to_end_fixtures as $fixture_id ) {
    $run_result = cleanup_test_apply_one( $cleanup, $fixture_id );

    assert_equals(
        1,
        (int) $run_result['events_deleted'],
        'end-to-end fixture #' . $fixture_id . ' deleted by the per-fixture apply path'
    );
    assert_equals(
        0,
        count( $run_result['errors'] ),
        'end-to-end fixture #' . $fixture_id . ' reports no deletion errors'
    );
    assert_true(
        null === get_post( $fixture_id ),
        'end-to-end fixture #' . $fixture_id . ' is PURGED (post row removed from the database)'
    );
}

assert_equals(
    'publish',
    get_post_status( $cleanup_unrelated_sentinel ),
    'an unrelated past event SURVIVES the per-fixture cleanup (no global purge)'
);


// ---------------------------------------------------------------------------
// Cleanup the suite's own fixtures.
// ---------------------------------------------------------------------------
foreach ( $cleanup_test_event_ids as $cleanup_test_event_id ) {
	wp_delete_post( $cleanup_test_event_id, true );
}

// Reset only THIS PROCESS's cleanup test stores. The helper validates every
// requested key before deleting anything, so it cannot reach an operator row.
$cleanup_reset = test_options_reset_test_store( array( 'cleanup_status', 'cleanup_history' ) );

assert_true(
	$cleanup_reset['ok'],
	'the reset helper accepts the two cleanup test stores (' . wp_json_encode( $cleanup_reset ) . ')'
);

assert_equals(
	2,
	count( $cleanup_reset['deleted'] ) + count( $cleanup_reset['absent'] ),
	'the reset helper accounts for exactly the two cleanup test stores'
);

assert_equals(
	array(),
	$cleanup_reset['rejected'],
	'the reset helper rejected nothing'
);

foreach ( array( 'cleanup_status', 'cleanup_history' ) as $cleanup_store_key ) {
	$cleanup_test_key = test_operator_store_option_name( $cleanup_store_key );

	assert_true(
		0 === strpos( $cleanup_test_key, 'conexao_event_cleanup_' ) && false !== strpos( $cleanup_test_key, '__test__' ),
		'the "' . $cleanup_store_key . '" store resolved to a test key, not the operator option'
	);
	assert_true(
		null === get_option( $cleanup_test_key, null ),
		'the "' . $cleanup_store_key . '" test store no longer exists after the reset'
	);
}

// The operator's cleanup records are byte-identical: the suite never wrote them.
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

test_finish( 'Event Cleanup Purge' );
