<?php
/**
 * G4C — bounded outbound request execution.
 *
 * ## What is proved
 *
 * 1. N permitted requests → exactly N outbound requests; request N+1 is blocked
 *    and never reaches the network.
 * 2. A cache hit does NOT consume outbound budget (the budget counts requests
 *    that actually leave the process).
 * 3. Budget exhaustion mid-pagination makes the walk **incomplete**, never
 *    complete and never an empty success.
 * 4. Successfully fetched events are RETAINED across the exhaustion boundary.
 * 5. An incomplete walk caused by the ceiling never calls `mark_missing_events()`:
 *    a live event absent from a truncated listing is not hidden from the site.
 *
 * ## Transport
 *
 * Every case runs against a `pre_http_request` double that counts what it
 * serves. The ceiling is injected through `Conexao_Request_Budget::begin_run( N )`
 * so a small, exact N can be driven deterministically. Nothing here contacts a
 * real upstream.
 *
 * @package Conexao_Event_Importer
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$passed = 0;
$failed = 0;

$restore_options = test_options_isolate(
	array(
		Conexao_Import_History::OPTION_KEY,
		Conexao_Import_Log::OPTION_KEY,
		Conexao_Event_Sources::OPTION_KEY,
		'conexao_event_source_health',
	)
);

register_shutdown_function(
	static function () use ( $restore_options ) {
		test_options_restore( $restore_options, true );
	}
);

// Keep every run fast and deterministic. The inter-page politeness delay and
// the retry backoff are zeroed so the suite does not wait; the TIME BUDGET is
// deliberately left at its real default, because a zero budget would end the
// walk before the request ceiling is ever reached and the test would prove
// nothing about G4C.
add_filter( 'conexao_eventbrite_backoff_seconds', '__return_zero', 99 );
add_filter( 'conexao_eventbrite_request_delay_seconds', '__return_zero', 99 );

$plugin = Conexao_Event_Importer::instance();

/**
 * Install an HTTP double that counts every request it serves.
 *
 * @param array $responses URL-substring => array( code, body ) or WP_Error.
 * @return void
 */
function g4c_install_double( array $responses ) {
	$GLOBALS['g4c_responses'] = $responses;
	$GLOBALS['g4c_served']    = array();

	add_filter( 'pre_http_request', 'g4c_http_double', 10, 3 );
}

/**
 * Remove the HTTP double.
 *
 * @return void
 */
function g4c_remove_double() {
	remove_filter( 'pre_http_request', 'g4c_http_double', 10 );
}

/**
 * The `pre_http_request` double.
 *
 * @param mixed  $preempt Short-circuit value.
 * @param array  $args    Request args.
 * @param string $url     Request URL.
 * @return array|WP_Error
 */
function g4c_http_double( $preempt, $args, $url ) {
	$GLOBALS['g4c_served'][] = $url;

	foreach ( (array) $GLOBALS['g4c_responses'] as $needle => $response ) {
		if ( false === strpos( $url, $needle ) ) {
			continue;
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'response' => array(
				'code'    => isset( $response['code'] ) ? (int) $response['code'] : 200,
				'message' => 'OK',
			),
			'body'     => isset( $response['body'] ) ? $response['body'] : '',
			'headers'  => array(),
			'cookies'  => array(),
		);
	}

	return new WP_Error( 'g4c_unmocked_url', 'No HTTP double registered for ' . $url );
}
/**
 * A source handler exposing the shared transport seam, for budget assertions.
 */
class G4C_Probe_Source extends Conexao_Source_Base {

	/**
	 * Source ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'g4c_probe';
	}

	/**
	 * Not exercised: the probe fetches explicitly.
	 *
	 * @return array
	 */
	public function fetch_events() {
		return array();
	}

	/**
	 * Fetch through the shared seam.
	 *
	 * @param string $url URL.
	 * @return array|WP_Error
	 */
	public function probe( $url ) {
		return $this->request_get( $url, array( 'user-agent' => 'g4c-agent' ) );
	}
}

// ===========================================================================
// Test 1: the budget is exact — N allowed, N+1 blocked, nothing on the wire.
// ===========================================================================
test_section( 'G4C: N permitted requests produce exactly N outbound requests' );

$ceiling = 5;

Conexao_Request_Reuse::begin_run();
Conexao_Request_Budget::begin_run( $ceiling );
$GLOBALS['g4c_served'] = array();

g4c_install_double(
	array(
		'g4c/page' => array(
			'code' => 200,
			'body' => 'PAGE-BODY',
		),
	)
);

$blocked_after_ceiling = false;

for ( $i = 1; $i <= ( $ceiling + 3 ); $i++ ) {
	// Charge the budget BEFORE the request, exactly as the transport seam does,
	// so a blocked request never reaches the wire.
	if ( ! Conexao_Request_Budget::consume( 1 ) ) {
		$blocked_after_ceiling = true;
		break;
	}

	wp_remote_get( 'https://example.invalid/g4c/page-' . $i, array( 'user-agent' => 'g4c-agent' ) );
}
g4c_remove_double();

assert_equals( $ceiling, count( $GLOBALS['g4c_served'] ), 'G4C: exactly ' . $ceiling . ' requests were sent' );
assert_true( $blocked_after_ceiling, 'G4C: the request beyond the ceiling was blocked' );
assert_equals( $ceiling, Conexao_Request_Budget::get_used(), 'G4C: the budget counted exactly ' . $ceiling . ' requests' );
assert_true( Conexao_Request_Budget::is_exhausted(), 'G4C: the budget reports itself exhausted' );
assert_equals( 0, Conexao_Request_Budget::get_stats()['remaining'], 'G4C: no budget remains' );
assert_equals( 1, Conexao_Request_Budget::get_blocked(), 'G4C: the blocked request was counted' );

// consume() must never grant a partial batch that would exceed the ceiling.
Conexao_Request_Budget::begin_run( 10 );
assert_true( Conexao_Request_Budget::consume( 10 ), 'G4C: consuming exactly the ceiling is allowed' );
assert_true( ! Conexao_Request_Budget::consume( 1 ), 'G4C: one more request is blocked' );
assert_true( ! Conexao_Request_Budget::consume( 5 ), 'G4C: a multi-request batch is refused rather than partially granted' );
assert_equals( 10, Conexao_Request_Budget::get_used(), 'G4C: the ceiling was never exceeded (got ' . Conexao_Request_Budget::get_used() . ')' );

// ===========================================================================
// Test 2: a cache hit does NOT consume outbound budget.
// ===========================================================================
test_section( 'G4C: a reused response does not consume outbound budget' );

Conexao_Request_Reuse::begin_run();
Conexao_Request_Budget::begin_run( 2 );
$GLOBALS['g4c_served'] = array();

g4c_install_double(
	array(
		'g4c/cached' => array(
			'code' => 200,
			'body' => 'CACHED-BODY',
		),
	)
);

$probe   = new G4C_Probe_Source( array( 'id' => 'g4c_probe' ) );
$repeat  = $probe->probe( 'https://example.invalid/g4c/cached' );
$repeat2 = $probe->probe( 'https://example.invalid/g4c/cached' );
g4c_remove_double();

assert_equals( 1, count( $GLOBALS['g4c_served'] ), 'G4C: the repeated URL was fetched once' );
assert_equals( 1, Conexao_Request_Budget::get_used(), 'G4C: only ONE request was charged to the budget' );
assert_equals( 1, Conexao_Request_Budget::get_stats()['cache_hits'], 'G4C: the reuse was recorded as a cache hit' );
assert_equals( 'CACHED-BODY', wp_remote_retrieve_body( $repeat ), 'G4C: the reused response carried the right body' );
assert_equals( 'CACHED-BODY', wp_remote_retrieve_body( $repeat2 ), 'G4C: the second reuse also carried the right body' );
assert_true( ! Conexao_Request_Budget::is_exhausted(), 'G4C: one outbound + two reuses does not exhaust a 2-request budget' );

// 10 identical requests must fit inside a 3-request budget.
Conexao_Request_Reuse::begin_run();
Conexao_Request_Budget::begin_run( 3 );
$GLOBALS['g4c_served'] = array();

g4c_install_double(
	array(
		'g4c/reuse' => array(
			'code' => 200,
			'body' => 'REUSED',
		),
	)
);

for ( $i = 0; $i < 10; $i++ ) {
	$probe->probe( 'https://example.invalid/g4c/reuse' );
}
g4c_remove_double();

assert_equals( 1, count( $GLOBALS['g4c_served'] ), 'G4C: 10 identical requests produced 1 outbound request' );
assert_equals( 1, Conexao_Request_Budget::get_used(), 'G4C: 10 identical requests consumed 1 unit of budget' );
assert_true( ! Conexao_Request_Budget::is_exhausted(), 'G4C: reuse alone cannot exhaust the budget' );
// ===========================================================================
// Test 3: exhaustion during pagination → incomplete, data retained.
// ===========================================================================
test_section( 'G4C: budget exhaustion during pagination yields an INCOMPLETE walk' );

$g4c_source = 'g4c_budget_probe';
$g4c_url    = 'https://example.invalid/g4c/eventbrite';

$plugin->sources->save(
	array(
		'id'            => $g4c_source,
		'name'          => 'G4C budget probe',
		'url'           => $g4c_url,
		'type'          => 'eventbrite',
		'status'        => 'active',
		'county'        => 'Laois',
		'region_labels' => array( 'Laois' ),
	)
);

// A 20-page upstream listing; a budget of 3 cuts the walk short.
$g4c_listing = array(
	'search_data' => array(
		'events' => array(
			'results'    => array(
				array(
					'id'         => '9400000001',
					'name'       => 'G4C page-one event',
					'url'        => 'https://example.invalid/g4c/e/1',
					'start_date' => '2026-12-01',
					'end_date'   => '2026-12-01',
					'timezone'   => 'Europe/Dublin',
					'locations'  => array( array( 'type' => 'region', 'name' => 'Laois' ) ),
				),
			),
			'pagination' => array(
				'object_count' => 400,
				'page_count'   => 20,
				'page_number'  => 1,
				'page_size'    => 20,
			),
		),
	),
);

g4c_install_double(
	array(
		$g4c_url => array(
			'code' => 200,
			'body' => '<html><body><script>window.__SERVER_DATA__ = ' . wp_json_encode( $g4c_listing ) . ';</script></body></html>',
		),
	)
);

// A tiny ceiling for this run. run_source() opens its own scope, so the ceiling
// is injected through the documented filter for the duration of the run.
add_filter( 'conexao_event_importer_request_budget', 'g4c_ceiling_three' );
$GLOBALS['g4c_served'] = array();

$g4c_result = $plugin->importer->run_source( $g4c_source, true );

remove_filter( 'conexao_event_importer_request_budget', 'g4c_ceiling_three' );
g4c_remove_double();

assert_equals( 3, count( $GLOBALS['g4c_served'] ), 'G4C: the run stopped at the ceiling of 3 outbound requests (got ' . count( $GLOBALS['g4c_served'] ) . ')' );
assert_true( ! $g4c_result['complete'], 'G4C: a budget-truncated walk reports complete=false' );
assert_true( (int) $g4c_result['found'] >= 1, 'G4C: the events fetched BEFORE exhaustion are retained (found=' . (int) $g4c_result['found'] . ')' );

// The status/history assertions below are made against a REAL (non-dry) run,
// because a dry run deliberately writes nothing to Import History. Asserting
// them here would be a vacuous test of a code path that never records.
$saw_incomplete = false;
foreach ( Conexao_Import_Log::get_for_source( $g4c_source, 50 ) as $entry ) {
	if ( false !== strpos( $entry['message'], 'INCOMPLETE' ) ) {
		$saw_incomplete = true;
	}
}
assert_true( $saw_incomplete, 'G4C: the source logged an explicit INCOMPLETE enumeration warning' );
// ===========================================================================
// Test 4: CROSS-STAGE — exhaustion must not mark a live event missing.
// ===========================================================================
test_section( 'G4C cross-stage: a ceiling-truncated walk never marks events missing' );

$hidden_url  = 'https://www.eventbrite.ie/e/g4c-live-event-still-upstream-123456';
$hidden_post = wp_insert_post(
	array(
		'post_type'   => 'event',
		'post_title'  => 'G4C live event (still upstream, never enumerated)',
		'post_status' => 'publish',
	)
);

update_post_meta( $hidden_post, '_event_source', $g4c_source );
update_post_meta( $hidden_post, '_event_source_id', '123456' );
update_post_meta( $hidden_post, '_event_url', $hidden_url );
Conexao_Event_Status::set_status( $hidden_post, Conexao_Event_Status::PUBLISHED );

g4c_install_double(
	array(
		$g4c_url => array(
			'code' => 200,
			'body' => '<html><body><script>window.__SERVER_DATA__ = ' . wp_json_encode( $g4c_listing ) . ';</script></body></html>',
		),
	)
);

add_filter( 'conexao_event_importer_request_budget', 'g4c_ceiling_three' );
$GLOBALS['g4c_served'] = array();

// A REAL run: this is the only path that calls mark_missing_events().
$g4c_real = $plugin->importer->run_source( $g4c_source, false );

remove_filter( 'conexao_event_importer_request_budget', 'g4c_ceiling_three' );
g4c_remove_double();

$status_after = get_post_meta( $hidden_post, '_event_status', true );

assert_true( ! $g4c_real['complete'], 'G4C cross-stage: the real run reports complete=false' );
assert_true(
	'success' !== $g4c_real['status'],
	'G4C cross-stage: a ceiling-truncated run is not recorded as a bare success (got "' . $g4c_real['status'] . '")'
);

$g4c_hist = Conexao_Import_History::get_for_source( $g4c_source, 1 );
assert_true( ! empty( $g4c_hist ), 'G4C cross-stage: an Import History row was recorded' );
assert_true(
	isset( $g4c_hist[0]['complete'] ) && false === $g4c_hist[0]['complete'],
	'G4C cross-stage: the Import History row records complete=false'
);
assert_true(
	'success' !== $g4c_hist[0]['status'],
	'G4C cross-stage: the Import History row is not a bare success (got "' . $g4c_hist[0]['status'] . '")'
);

assert_true(
	'source_not_found' !== $status_after,
	'G4C cross-stage: the live event was NOT marked source_not_found (got "' . $status_after . '")'
);
assert_true(
	null !== get_post( $hidden_post ),
	'G4C cross-stage: the live event still exists after the budget cut the walk short'
);
assert_equals(
	Conexao_Event_Status::PUBLISHED,
	$status_after,
	'G4C cross-stage: the live event is still published for the public site'
);

// THE CONTROL: with an ample budget the same absence IS marked missing. Without
// it, the guard could simply be disabling mark_missing_events() everywhere.
g4c_install_double(
	array(
		$g4c_url => array(
			'code' => 200,
			'body' => '<html><body><script>window.__SERVER_DATA__ = ' . wp_json_encode( $g4c_listing ) . ';</script></body></html>',
		),
	)
);

$g4c_control = $plugin->importer->run_source( $g4c_source, false );
g4c_remove_double();

$control_status = get_post_meta( $hidden_post, '_event_status', true );

assert_true( $g4c_control['complete'], 'G4C control: an unconstrained run completes the walk' );
assert_equals(
	'source_not_found',
	$control_status,
	'G4C control: on a COMPLETE walk the absent event IS marked source_not_found (the guard is not blanket-disabled)'
);

// Cleanup the probe event and source.
wp_delete_post( $hidden_post, true );
$plugin->sources->delete( $g4c_source );

test_options_restore( $restore_options );
$GLOBALS['conexao_test_option_snapshots'] = array();

test_finish();

/**
 * Supplies the small ceiling used by the exhaustion cases.
 *
 * @return int
 */
function g4c_ceiling_three() {
	return 3;
}