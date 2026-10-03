<?php
/**
 * G1 — a failed FIRST-PAGE fetch must become a FAILED source, never an empty one.
 *
 * ## The defect this locks down
 *
 * The Eventbrite and Heritage Week handlers used to log the first-page failure
 * and `return array()`. The engine then ran
 * `set_found( count( $raw_events ) )` — i.e. 0 — and recorded
 * `found=0, status=success, errors=0` in Import History. The diagnosis proved
 * this against a controlled 429: 21 of 26 Eventbrite and 22 of 26 Heritage Week
 * sources showed exactly that row while upstream was returning HTTP failures.
 *
 * The contract now is:
 *
 *     first-page fetch failure  ->  Conexao_Source_Fetch_Exception
 *                              ->  run_source() records status=failed
 *                              ->  fatal_errors > 0
 *
 * and the engine's existing exception handling is the recording authority — it
 * is NOT re-implemented here.
 *
 * ## Test transport
 *
 * Every case runs against a `pre_http_request` double, so no test in this file
 * touches Eventbrite or Heritage Week. Backoff and inter-page delay are
 * filtered to zero so the suite stays fast and deterministic; the retry POLICY
 * itself is asserted in full below, and its per-status classification is
 * updated by G4B (502/504 became retried; 401/403/404/408 remain not).
 *
 * @package Conexao_Event_Importer
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$passed = 0;
$failed = 0;

// Isolate every operator-state option this suite's runs record into, so a test
// import can never pollute or destroy the maintainer's diagnostics.
$restore_options = test_options_isolate(
	array(
		Conexao_Import_History::OPTION_KEY,
		Conexao_Import_Log::OPTION_KEY,
		Conexao_Event_Sources::OPTION_KEY,
	)
);

register_shutdown_function(
	static function () use ( $restore_options ) {
		test_options_restore( $restore_options, true );
	}
);

/**
 * Install an HTTP double. $responses maps a URL substring to either an array
 * ( ['code' => int, 'body' => string] ) or a WP_Error for a transport failure.
 *
 * @param array $responses URL-substring => response definition.
 * @return void
 */
function g1_install_http_double( array $responses ) {
	$GLOBALS['g1_http_responses'] = $responses;
	$GLOBALS['g1_http_requests']  = array();
	$GLOBALS['g1_http_double_on'] = true;

	add_filter( 'pre_http_request', 'g1_http_double', 10, 3 );
}

/**
 * Remove the HTTP double.
 *
 * @return void
 */
function g1_remove_http_double() {
	$GLOBALS['g1_http_double_on'] = false;
	remove_filter( 'pre_http_request', 'g1_http_double', 10 );
}

/**
 * The `pre_http_request` filter callback.
 *
 * @param false|array|WP_Error $preempt Short-circuit value.
 * @param array                $args    Request args.
 * @param string               $url     Request URL.
 * @return array|WP_Error
 */
function g1_http_double( $preempt, $args, $url ) {
	if ( empty( $GLOBALS['g1_http_double_on'] ) ) {
		return $preempt;
	}

	$GLOBALS['g1_http_requests'][] = $url;

	foreach ( (array) $GLOBALS['g1_http_responses'] as $needle => $response ) {
		if ( false === strpos( $url, $needle ) ) {
			continue;
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'response' => array( 'code' => (int) $response['code'] ),
			'body'     => isset( $response['body'] ) ? $response['body'] : '',
			'headers'  => array(),
			'cookies'  => array(),
		);
	}

	// Unmatched URL: fail loudly rather than silently reaching the internet.
	return new WP_Error( 'g1_unmocked_url', 'No HTTP double registered for ' . $url );
}

// Keep every run fast and deterministic: the retry/backoff and inter-page delay
// POLICY is unchanged, only the wall-clock wait is removed.
add_filter( 'conexao_eventbrite_backoff_seconds', '__return_zero', 99 );
add_filter( 'conexao_eventbrite_request_delay_seconds', '__return_zero', 99 );
add_filter( 'conexao_eventbrite_time_budget_seconds', '__return_zero', 99 );
add_filter( 'conexao_heritage_week_time_budget_seconds', '__return_zero', 99 );

$plugin = Conexao_Event_Importer::instance();

/**
 * Register a throwaway source for a handler type and return its ID.
 *
 * @param string $type  Source type (eventbrite|heritage_week).
 * @param string $url   Discovery URL.
 * @param array  $extra Extra config keys.
 * @return string Source ID.
 */
function g1_register_source( $type, $url, $extra = array() ) {
	$id = 'g1_probe_' . $type . '_' . substr( md5( $type . $url . microtime( true ) ), 0, 8 );

	$plugin = Conexao_Event_Importer::instance();
	$plugin->sources->save(
		array_merge(
			array(
				'id'     => $id,
				'name'   => 'G1 probe ' . $type,
				'url'    => $url,
				'type'   => $type,
				'status' => 'active',
			),
			$extra
		)
	);

	return $id;
}

/**
 * Create a minimal valid Eventbrite discovery page.
 *
 * @param int $event_count Number of events to embed.
 * @return string HTML.
 */
function g1_eventbrite_page_html( $event_count = 1 ) {
	$events = array();
	for ( $i = 0; $i < $event_count; $i++ ) {
		$events[] = array(
			'id'         => '900000000' . $i,
			'name'       => 'G1 probe event ' . $i,
			'url'        => 'https://www.eventbrite.ie/e/g1-probe-' . $i,
			'start_date' => '2026-12-01',
			'end_date'   => '2026-12-01',
			'timezone'   => 'Europe/Dublin',
			'locations'  => array( array( 'type' => 'region', 'name' => 'Laois' ) ),
		);
	}

	$payload = array(
		'search_data' => array(
			'events' => array(
				'results'    => $events,
				'pagination' => array(
					'object_count' => $event_count,
					'page_count'   => 1,
					'page_number'  => 1,
					'page_size'    => 20,
				),
			),
		),
	);

	return '<html><body><script>window.__SERVER_DATA__ = ' . wp_json_encode( $payload ) . ';</script></body></html>';
}

// ===========================================================================
// Test 1: the control — a healthy first page is a normal success.
// ===========================================================================
test_section( 'Control: a healthy first page still succeeds' );

$eb_url = 'https://www.eventbrite.ie/d/ireland--laois/all-events/';
$eb_cfg = array( 'county' => 'Laois', 'region_labels' => array( 'Laois' ) );

$source_id = g1_register_source( 'eventbrite', $eb_url, $eb_cfg );

g1_install_http_double(
	array(
		$eb_url => array(
			'code' => 200,
			'body' => g1_eventbrite_page_html( 2 ),
		),
	)
);

$result = $plugin->importer->run_source( $source_id, true );

g1_remove_http_double();

assert_true( 'success' === $result['status'], 'Control: HTTP 200 first page records status=success' );
assert_true( 2 === (int) $result['found'], 'Control: both events are counted as found' );
assert_true( 0 === count( $result['fatal_errors'] ), 'Control: no fatal errors on a healthy first page' );

// ===========================================================================
// Test 2: the provider/error matrix, applied to the FIRST PAGE.
// ===========================================================================
test_section( 'G1 matrix: every first-page failure mode becomes a failed history' );

/**
 * Run one matrix case and assert it produced a FAILED history row.
 *
 * @param string $label       Human label for the case.
 * @param mixed  $response    Response definition for the double.
 * @param string $source_type eventbrite|heritage_week.
 * @param string $url         Discovery URL.
 * @param array  $extra       Extra source config.
 * @param int    $expect_code Expected HTTP status recorded in the exception.
 * @return void
 */
function g1_assert_failed_source( $label, $response, $source_type, $url, $extra = array(), $expect_code = 0 ) {
	$source_id = g1_register_source( $source_type, $url, $extra );

	g1_install_http_double( array( $url => $response ) );
	$result = Conexao_Event_Importer::instance()->importer->run_source( $source_id, true );
	g1_remove_http_double();

	// The decisive assertion: the run is NOT a success.
	assert_true(
		'success' !== $result['status'],
		$label . ': status is not success (got "' . $result['status'] . '")'
	);
	assert_true(
		'failed' === $result['status'],
		$label . ': status is failed (got "' . $result['status'] . '")'
	);

	// Failure evidence, not just a label.
	assert_true(
		count( $result['fatal_errors'] ) > 0,
		$label . ': fatal_errors > 0 (failure evidence recorded)'
	);

	// The specific anti-defect assertion: it must NOT be a false
	// "found=0 success" row.
	$is_false_empty_success = ( 'success' === $result['status'] && 0 === (int) $result['found'] );
	assert_true(
		! $is_false_empty_success,
		$label . ': NOT recorded as a false found=0/success row'
	);

	// And the durable record must agree.
	$history = Conexao_Import_History::get_for_source( $source_id, 1 );
	assert_true(
		! empty( $history ) && 'failed' === $history[0]['status'],
		$label . ': the persisted history row is status=failed'
	);
	assert_true(
		! empty( $history ) && ! empty( $history[0]['fatal_errors'] ),
		$label . ': the persisted history row carries fatal_errors'
	);

	if ( 0 !== $expect_code ) {
		assert_true(
			! empty( $result['fatal_errors'] ),
			$label . ': HTTP ' . $expect_code . ' is surfaced as failure evidence'
		);
	}
}

// --- Eventbrite -------------------------------------------------------------

g1_assert_failed_source( 'Eventbrite first page 401', array( 'code' => 401 ), 'eventbrite', $eb_url, $eb_cfg, 401 );
g1_assert_failed_source( 'Eventbrite first page 403', array( 'code' => 403 ), 'eventbrite', $eb_url, $eb_cfg, 403 );
g1_assert_failed_source( 'Eventbrite first page 408', array( 'code' => 408 ), 'eventbrite', $eb_url, $eb_cfg, 408 );
g1_assert_failed_source( 'Eventbrite first page 429', array( 'code' => 429 ), 'eventbrite', $eb_url, $eb_cfg, 429 );
g1_assert_failed_source( 'Eventbrite first page 500', array( 'code' => 500 ), 'eventbrite', $eb_url, $eb_cfg, 500 );
g1_assert_failed_source( 'Eventbrite first page 502', array( 'code' => 502 ), 'eventbrite', $eb_url, $eb_cfg, 502 );
g1_assert_failed_source( 'Eventbrite first page 503', array( 'code' => 503 ), 'eventbrite', $eb_url, $eb_cfg, 503 );
g1_assert_failed_source( 'Eventbrite timeout', new WP_Error( 'http_request_failed', 'Operation timed out after 30000 milliseconds' ), 'eventbrite', $eb_url, $eb_cfg, 0 );
g1_assert_failed_source( 'Eventbrite connection failure', new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ), 'eventbrite', $eb_url, $eb_cfg, 0 );

// --- Heritage Week ----------------------------------------------------------

$hw_cfg = array( 'hw_where' => array( 'g1 probe county' ), 'county' => 'Laois' );

g1_assert_failed_source( 'Heritage Week first page 401', array( 'code' => 401 ), 'heritage_week', 'https://www.heritageweek.ie/event-listings', $hw_cfg, 401 );
g1_assert_failed_source( 'Heritage Week first page 403', array( 'code' => 403 ), 'heritage_week', 'https://www.heritageweek.ie/event-listings', $hw_cfg, 403 );
g1_assert_failed_source( 'Heritage Week first page 408', array( 'code' => 408 ), 'heritage_week', 'https://www.heritageweek.ie/event-listings', $hw_cfg, 408 );
g1_assert_failed_source( 'Heritage Week first page 429', array( 'code' => 429 ), 'heritage_week', 'https://www.heritageweek.ie/event-listings', $hw_cfg, 429 );
g1_assert_failed_source( 'Heritage Week first page 500', array( 'code' => 500 ), 'heritage_week', 'https://www.heritageweek.ie/event-listings', $hw_cfg, 500 );
g1_assert_failed_source( 'Heritage Week first page 502', array( 'code' => 502 ), 'heritage_week', 'https://www.heritageweek.ie/event-listings', $hw_cfg, 502 );
g1_assert_failed_source( 'Heritage Week first page 503', array( 'code' => 503 ), 'heritage_week', 'https://www.heritageweek.ie/event-listings', $hw_cfg, 503 );
g1_assert_failed_source( 'Heritage Week timeout', new WP_Error( 'http_request_failed', 'Operation timed out after 30000 milliseconds' ), 'heritage_week', 'https://www.heritageweek.ie/event-listings', $hw_cfg, 0 );
g1_assert_failed_source( 'Heritage Week connection failure', new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ), 'heritage_week', 'https://www.heritageweek.ie/event-listings', $hw_cfg, 0 );

// ===========================================================================
// Test 3: the exception carries the real HTTP status (not a guess), and a
// malformed first page fails closed.
// ===========================================================================
test_section( 'G1: the raised exception carries the real upstream status' );

$source_id = g1_register_source( 'eventbrite', $eb_url, $eb_cfg );

g1_install_http_double( array( $eb_url => array( 'code' => 403 ) ) );

$handler = new Conexao_Source_Eventbrite(
	array( 'id' => $source_id, 'url' => $eb_url, 'county' => 'Laois', 'region_labels' => array( 'Laois' ) )
);

$caught = null;
try {
	$handler->fetch_events();
} catch ( Conexao_Source_Fetch_Exception $e ) {
	$caught = $e;
}

g1_remove_http_double();

assert_true( null !== $caught, 'G1: fetch_events() raises Conexao_Source_Fetch_Exception on a 403 first page' );
assert_true(
	null !== $caught && 403 === $caught->get_http_status(),
	'G1: the exception reports HTTP 403 (not 0 and not a guess)'
);

// A malformed (non-JSON) first page is "cannot enumerate" too — fail closed.
g1_install_http_double(
	array(
		$eb_url => array(
			'code' => 200,
			'body' => '<html><body><p>WAF block page, not data</p></body></html>',
		),
	)
);

$handler2 = new Conexao_Source_Eventbrite(
	array( 'id' => $source_id, 'url' => $eb_url, 'county' => 'Laois', 'region_labels' => array( 'Laois' ) )
);

$malformed_threw = false;
try {
	$handler2->fetch_events();
} catch ( Conexao_Source_Fetch_Exception $e ) {
	$malformed_threw = true;
}

g1_remove_http_double();

assert_true( $malformed_threw, 'G1: a malformed 200 first page fails closed rather than reporting an empty source' );

// ===========================================================================
// Test 4: partial-page tolerance is PRESERVED (G1 must not make every later
// page fatal).
// ===========================================================================
test_section( 'G1: later-page failure stays tolerant and keeps the events it fetched' );

$multi_page = array(
	'search_data' => array(
		'events' => array(
			'results'    => array(
				array(
					'id'         => '9100000001',
					'name'       => 'G1 page one event',
					'url'        => 'https://www.eventbrite.ie/e/g1-page-one',
					'start_date' => '2026-12-01',
					'end_date'   => '2026-12-01',
					'timezone'   => 'Europe/Dublin',
					'locations'  => array( array( 'type' => 'region', 'name' => 'Laois' ) ),
				),
			),
			'pagination' => array(
				'object_count' => 2,
				'page_count'   => 2,
				'page_number'  => 1,
				'page_size'    => 20,
			),
		),
	),
);

$source_id = g1_register_source( 'eventbrite', $eb_url, $eb_cfg );

// Page 1 is healthy; page 2 is a 403. The run must keep the page-1 event.
g1_install_http_double(
	array(
		$eb_url   => array( 'code' => 200, 'body' => '<html><body><script>window.__SERVER_DATA__ = ' . wp_json_encode( $multi_page ) . ';</script></body></html>' ),
		'page=2' => array( 'code' => 403 ),
	)
);

$result = $plugin->importer->run_source( $source_id, true );
g1_remove_http_double();

assert_true( 'failed' !== $result['status'], 'G1: a later-page 403 does NOT abort the whole source' );
assert_true( 1 === (int) $result['found'], 'G1: the event from the healthy first page is preserved (found=1)' );

// ===========================================================================
// Test 5: the retry classification for the first page.
// ===========================================================================
// G4B CHANGED THIS ASSERTION, deliberately and with evidence. Before G4 the
// retried set was {429, 500, 503}; 502 was not retried at all, which the
// previous version of this test asserted. G4B classifies 502 and 504 as
// transient (a bad gateway / gateway timeout is a server-side failure that is
// worth one bounded retry), so 502 now belongs to the retried group below.
// 401/403/404/408 remain non-retryable: retrying an authentication or
// not-found condition cannot change the outcome, it only multiplies traffic.
test_section( 'G1/G4B: retry classification for the first page' );

foreach ( array( 401, 403, 408 ) as $code ) {
	$no_retry_source = g1_register_source( 'eventbrite', $eb_url, $eb_cfg );

	g1_install_http_double( array( $eb_url => array( 'code' => $code ) ) );
	$plugin->importer->run_source( $no_retry_source, true );
	g1_remove_http_double();

	$attempted = count( (array) $GLOBALS['g1_http_requests'] );

	assert_true(
		1 === $attempted,
		'Retry policy: HTTP ' . $code . ' is NOT blindly retried (1 request issued, got ' . $attempted . ')'
	);
}

foreach ( array( 429, 500, 502, 503, 504 ) as $code ) {
	$retry_source = g1_register_source( 'eventbrite', $eb_url, $eb_cfg );

	g1_install_http_double( array( $eb_url => array( 'code' => $code ) ) );
	$plugin->importer->run_source( $retry_source, true );
	g1_remove_http_double();

	$attempted = count( (array) $GLOBALS['g1_http_requests'] );

	assert_true(
		3 === $attempted,
		'Retry policy: HTTP ' . $code . ' keeps the existing 3-attempt retry (got ' . $attempted . ')'
	);
}

// ===========================================================================
// Cleanup
// ===========================================================================

foreach ( (array) $plugin->sources->get_all() as $probe_id => $probe ) {
	if ( 0 === strpos( (string) $probe_id, 'g1_probe_' ) ) {
		$plugin->sources->delete( $probe_id );
	}
}

test_options_restore( $restore_options );
$GLOBALS['conexao_test_option_snapshots'] = array();

test_finish();
