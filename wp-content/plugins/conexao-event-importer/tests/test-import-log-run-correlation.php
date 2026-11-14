<?php
/**
 * G3 — diagnostics safety: run-level correlation and operator-state survival.
 *
 * ## G3A: the test suite must not destroy operator diagnostics
 *
 * `test-import-log.php` used to call `Conexao_Import_Log::clear()` against the
 * LIVE `conexao_event_import_log` option. The diagnosis measured that option
 * going 599 -> 91 -> 0 purely because the canonical suite ran. The suite now
 * isolates and restores it; this file proves the isolation helper itself works.
 *
 * ## G3B: run_id correlation
 *
 * The engine already publishes `conexao_event_importer_current_run_id`. The
 * Eventbrite and Heritage Week handlers used to call `Conexao_Import_Log::add()`
 * WITHOUT it, so 126 of 599 diagnostic entries could not be tied back to the
 * run that produced them. This file proves those entries now carry the run ID
 * and that `get_for_run()` resolves them — using the EXISTING correlation
 * identifier, not a new one.
 *
 * @package Conexao_Event_Importer
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

test_require_operator_store_isolation();

$passed = 0;
$failed = 0;

// This suite CALLS Conexao_Import_Log::clear() (that is how it drives the
// Eventbrite/Heritage Week handlers onto paths that write diagnostics), so it
// must never do that against the live operator store. The log and history
// stores are redirected to this process's test rows by their accessors; the
// remaining operator options are snapshotted and restored as before.
$restore_options = test_options_isolate(
	array(
		Conexao_Source_Health::OPTION_KEY,
		// G18: run_source() now schedules a continuation when a source is left
		// resumable, so the scheduler's state is another option the engine
		// writes. It must be isolated here like every other one: otherwise a
		// resumable state created for an assertion would outlive the suite and
		// could later run a REAL import for that source.
		Conexao_Import_Continuation::OPTION_KEY,
		Conexao_Event_Sources::OPTION_KEY,
	)
);

register_shutdown_function(
	static function () use ( $restore_options ) {
		test_options_restore( $restore_options, true );
	}
);

$live_log_raw_before = (string) $GLOBALS['wpdb']->get_var(
	$GLOBALS['wpdb']->prepare(
		"SELECT option_value FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s",
		Conexao_Import_Log::OPTION_KEY
	)
);

$live_history_raw_before = (string) $GLOBALS['wpdb']->get_var(
	$GLOBALS['wpdb']->prepare(
		"SELECT option_value FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s",
		Conexao_Import_History::OPTION_KEY
	)
);

$eb_url = 'https://www.eventbrite.ie/d/ireland--laois/all-events/';
$hw_url = 'https://www.heritageweek.ie/event-listings';

/**
 * HTTP double: serves Eventbrite discovery pages and a Heritage Week listing.
 *
 * @param false|array|WP_Error $preempt Short-circuit value.
 * @param array                $args    Request args.
 * @param string               $url     Request URL.
 * @return array|WP_Error
 */
function g3_http_double( $preempt, $args, $url ) {
	if ( false !== strpos( $url, 'eventbrite.ie' ) ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => '<html><body><script>window.__SERVER_DATA__ = {"search_data":{"events":{"results":[{"id":"9400000001","name":"G3 event","url":"https://www.eventbrite.ie/e/g3-1","start_date":"2026-12-01","end_date":"2026-12-01","timezone":"Europe/Dublin","locations":[{"type":"region","name":"Laois"}]}],"pagination":{"object_count":1,"page_count":1,"page_number":1,"page_size":20}}}}</script></body></html>',
			'headers'  => array(),
			'cookies'  => array(),
		);
	}

	return array(
		'response' => array( 'code' => 200 ),
		'body'     => '<html><head><title>2026 Events</title></head><body>'
			. '<article class="item-summary" data-id="g3-hw-1"><h3 class="title">G3 heritage event</h3>'
			. '<a class="link-block" href="https://www.heritageweek.ie/event-listings/g3-hw-1">Read more</a>'
			. '<ul class="list-details"><li>12 December 2026</li><li>Test Venue</li></ul></article>'
			. '</body></html>',
		'headers'  => array(),
		'cookies'  => array(),
	);
}

add_filter( 'pre_http_request', 'g3_http_double', 10, 3 );
add_filter( 'conexao_eventbrite_backoff_seconds', '__return_zero', 99 );
add_filter( 'conexao_eventbrite_request_delay_seconds', '__return_zero', 99 );

$plugin = Conexao_Event_Importer::instance();

// ===========================================================================
// Test 1 (G3A): destructive log work never reaches the operator store.
// ===========================================================================
test_section( 'G3A: destructive log work never reaches the operator store' );

// Seed THIS PROCESS's test store with a realistic-looking history. The live
// option is never written: the accessors redirect every read and write.
$operator_entries = array();
for ( $i = 0; $i < 25; $i++ ) {
	$operator_entries[] = array(
		'time'        => current_time( 'mysql' ),
		'source'      => 'eventbrite_carlow',
		'level'       => 'error',
		'message'     => 'Seeded diagnostic entry ' . $i,
		'run_id'      => 'seeded-run',
		'event_id'    => '',
		'event_title' => '',
		'http_status' => 429,
		'context'     => array(),
	);
}
$test_log_key = test_options_write_test_store( 'import_log', $operator_entries );

assert_true(
	0 === strpos( $test_log_key, Conexao_Import_Log::TEST_OPTION_PREFIX ),
	'G3A: the seed went to a test-prefixed key'
);
assert_true( 25 === count( Conexao_Import_Log::get_all() ), 'G3A: this process\'s test log holds the 25 seeded entries' );

// Destroy it completely: a test process is allowed to do this to its own row.
Conexao_Import_Log::clear();
assert_true( 0 === count( Conexao_Import_Log::get_all() ), 'G3A: clear() empties this process\'s test store' );

Conexao_Import_Log::add( 'probe', 'error', 'test noise' );
assert_true( 1 === count( Conexao_Import_Log::get_all() ), 'G3A: the test row accepts new entries' );

// The live option is byte-identical throughout.
$live_log_raw_now = (string) $GLOBALS['wpdb']->get_var(
	$GLOBALS['wpdb']->prepare(
		"SELECT option_value FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s",
		Conexao_Import_Log::OPTION_KEY
	)
);

assert_true(
	$live_log_raw_now === $live_log_raw_before,
	'G3A: the live operator log is byte-identical after seeding, clearing and re-adding'
);

$live_messages = wp_list_pluck( (array) get_option( Conexao_Import_Log::OPTION_KEY, array() ), 'message' );
assert_true(
	! in_array( 'test noise', $live_messages, true ),
	'G3A: no test noise leaked into the operator log'
);
assert_true(
	0 === strpos( $test_log_key, Conexao_Import_Log::TEST_OPTION_PREFIX )
		&& $test_log_key !== Conexao_Import_Log::OPTION_KEY,
	'G3A: the resolved key can never be the production option'
);

// A second destructive pass proves the behaviour is not a one-shot.
Conexao_Import_Log::clear();
assert_true( 0 === count( Conexao_Import_Log::get_all() ), 'G3A: a second clear() still targets the test store' );

$live_log_raw_again = (string) $GLOBALS['wpdb']->get_var(
	$GLOBALS['wpdb']->prepare(
		"SELECT option_value FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s",
		Conexao_Import_Log::OPTION_KEY
	)
);
assert_true( $live_log_raw_again === $live_log_raw_before, 'G3A: and the live log is still byte-identical' );

// ===========================================================================
// Test 2 (G3B): Eventbrite diagnostic entries carry the run ID.
// ===========================================================================
test_section( 'G3B: Eventbrite log entries are correlated to their import run' );

$eb_source = 'g3_eventbrite_probe';
$plugin->sources->save(
	array(
		'id'            => $eb_source,
		'name'          => 'G3 Eventbrite probe',
		'url'           => $eb_url,
		'type'          => 'eventbrite',
		'status'        => 'active',
		'county'        => 'Laois',
		'region_labels' => array( 'Laois' ),
	)
);

Conexao_Import_Log::clear();

// A REAL run: a dry run records no import-history row, so the correlation
// proof (handler entry -> run_id -> history row -> resolvable log entries)
// requires the real path. The probe event it creates is deleted below.
$plugin->importer->run_source( $eb_source, false );

// The engine's raw run ID is a microtime string; Conexao_Import_Log stores it
// through sanitize_key(). Correlate on the STORED form, which is also what the
// Import Logs admin screen uses to look a run up.
$run_id     = sanitize_key( $plugin->importer->get_current_run_id() );
$eb_entries = Conexao_Import_Log::get_for_run( $run_id, 100 );
$eb_handler_entries = array_values(
	array_filter(
		$eb_entries,
		static function ( $entry ) {
			return 0 === strpos( (string) $entry['message'], 'Eventbrite:' );
		}
	)
);

assert_true( '' !== $run_id, 'G3B: the engine published a non-empty run ID' );
assert_true( count( $eb_entries ) > 0, 'G3B: Eventbrite entries are retrievable by that run ID (found ' . count( $eb_entries ) . ')' );
assert_true( count( $eb_handler_entries ) > 0, 'G3B: the Eventbrite handler emitted its own diagnostic entries' );

$eb_missing_run_id = 0;
$eb_missing_sample = '';

// Deliberately inspect EVERY entry written during the run, not just the ones
// that already carry the run ID: filtering by run ID first would make this
// assertion vacuous exactly when the propagation is broken.
foreach ( Conexao_Import_Log::get_all() as $entry ) {
	if ( '' === trim( (string) $entry['run_id'] ) ) {
		$eb_missing_run_id++;
		if ( '' === $eb_missing_sample ) {
			$eb_missing_sample = (string) $entry['message'];
		}
	}
}
assert_true(
	0 === $eb_missing_run_id,
	sprintf(
		'G3B: NO entry written during the run lacks a run_id (%d missing%s)',
		$eb_missing_run_id,
		'' === $eb_missing_sample ? '' : ', first: "' . $eb_missing_sample . '"'
	)
);

// The engine's history row must agree with the log's run ID.
$history = Conexao_Import_History::get_for_source( $eb_source, 1 );
assert_true(
	! empty( $history ) && isset( $history[0]['run_id'] ) && '' !== $history[0]['run_id'],
	'G3B: the recorded import-history row carries the same run ID'
);
assert_true(
	! empty( $history ) && isset( $history[0]['run_id'] ) && $history[0]['run_id'] === $run_id,
	'G3B: the history run_id equals the log run_id for the same import'
);
assert_true(
	! empty( $history ) && isset( $history[0]['run_id'] )
		&& count( Conexao_Import_Log::get_for_run( $history[0]['run_id'], 100 ) ) > 0,
	'G3B: the history run_id RESOLVES to real log entries (correlation is testable)'
);

// ===========================================================================
// Test 3 (G3B): Heritage Week diagnostic entries carry the run ID.
// ===========================================================================
test_section( 'G3B: Heritage Week log entries are correlated to their import run' );

$hw_source = 'g3_heritage_probe';
$plugin->sources->save(
	array(
		'id'       => $hw_source,
		'name'     => 'G3 Heritage probe',
		'url'      => $hw_url,
		'type'     => 'heritage_week',
		'status'   => 'active',
		'county'   => 'Laois',
		'hw_where' => array( 'g3county' ),
	)
);

// Drive the handler onto a path where it definitely writes diagnostics: a
// zero time budget truncates the walk and is reported as incomplete.
add_filter( 'conexao_heritage_week_time_budget_seconds', '__return_zero', 99 );

Conexao_Import_Log::clear();

$hw_budget_result = $plugin->importer->run_source( $hw_source, true );

remove_filter( 'conexao_heritage_week_time_budget_seconds', '__return_zero', 99 );

$hw_budget_run_id = sanitize_key( $plugin->importer->get_current_run_id() );
$hw_budget_entries = array_values(
	array_filter(
		Conexao_Import_Log::get_for_run( $hw_budget_run_id, 100 ),
		static function ( $entry ) use ( $hw_source ) {
			return $hw_source === $entry['source'] && false !== strpos( (string) $entry['message'], 'Heritage Week' );
		}
	)
);

assert_true( count( $hw_budget_entries ) > 0, 'G3B: the Heritage Week handler emitted diagnostic entries (' . count( $hw_budget_entries ) . ')' );
assert_true( ! $hw_budget_result['complete'], 'G3B: the truncated Heritage Week walk reported complete=false' );

$hw_missing_run_id = 0;
$hw_missing_sample = '';

// Same discipline as the Eventbrite check: inspect every entry written during
// the run, so this cannot pass vacuously when propagation is removed.
foreach ( Conexao_Import_Log::get_all() as $entry ) {
	if ( '' === trim( (string) $entry['run_id'] ) ) {
		$hw_missing_run_id++;
		if ( '' === $hw_missing_sample ) {
			$hw_missing_sample = (string) $entry['message'];
		}
	}
}
assert_true(
	0 === $hw_missing_run_id,
	sprintf(
		'G3B: NO Heritage Week run entry lacks a run_id (%d missing%s)',
		$hw_missing_run_id,
		'' === $hw_missing_sample ? '' : ', first: "' . $hw_missing_sample . '"'
	)
);

// ===========================================================================
// Test 4 (G3B): entries written OUTSIDE a run stay compatible.
// ===========================================================================
test_section( 'G3B: legacy entries with no run ID remain readable' );

Conexao_Import_Log::add( 'legacy_probe', 'info', 'Legacy entry with no run context' );

$all    = Conexao_Import_Log::get_all();
$legacy = $all[0];

assert_true( 'legacy_probe' === $legacy['source'], 'G3B: a legacy entry is still stored and readable' );
assert_true( '' === $legacy['run_id'], 'G3B: an entry written outside a run has an empty run_id (backward compatible)' );
assert_true( 'Legacy entry with no run context' === $legacy['message'], 'G3B: the legacy message is intact' );

// ===========================================================================
// Cleanup
// ===========================================================================

// Remove the probe event the real run above created.
$probe_posts = get_posts(
	array(
		'post_type'      => 'event',
		'post_status'    => 'any',
		'posts_per_page' => 50,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => array(
			array(
				'key'   => '_event_source',
				'value' => $eb_source,
			),
		),
	)
);
foreach ( (array) $probe_posts as $probe_post ) {
	wp_delete_post( $probe_post, true );
}

$plugin->sources->delete( $eb_source );
$plugin->sources->delete( $hw_source );

foreach ( (array) $plugin->sources->get_all() as $probe_id => $probe ) {
	if ( 0 === strpos( (string) $probe_id, 'g3_' ) ) {
		$plugin->sources->delete( $probe_id );
	}
}

// Put the snapshotted non-redirected operator options back. The log and history
// stores were never snapshotted: they are redirected to this process's test
// rows, so the live options must be untouched.
test_options_restore( $restore_options );
$GLOBALS['conexao_test_option_snapshots'] = array();

$live_log_raw_after = (string) $GLOBALS['wpdb']->get_var(
	$GLOBALS['wpdb']->prepare(
		"SELECT option_value FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s",
		Conexao_Import_Log::OPTION_KEY
	)
);

$live_history_raw_after = (string) $GLOBALS['wpdb']->get_var(
	$GLOBALS['wpdb']->prepare(
		"SELECT option_value FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s",
		Conexao_Import_History::OPTION_KEY
	)
);

assert_true(
	$live_log_raw_after === $live_log_raw_before,
	'G3A: the live operator log is byte-identical after the whole suite'
);
assert_true(
	$live_history_raw_after === $live_history_raw_before,
	'G3A: the live operator history is byte-identical after the whole suite'
);

test_finish();
