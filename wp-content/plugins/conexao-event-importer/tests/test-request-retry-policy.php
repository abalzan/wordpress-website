<?php
/**
 * G4B — Retry-After handling and retry classification.
 *
 * ## What is proved
 *
 * - `Retry-After` is honoured in both standard forms, and ALWAYS clamped to the
 *   hard cap, so an upstream server can never impose an unbounded sleep.
 * - An unparseable `Retry-After` falls back to the existing exponential backoff.
 * - 429/500/502/503/504 are retried; 401/403/404/408 are NOT retried.
 * - The attempt ceiling (MAX_RETRIES = 3) is preserved: retry is never unbounded.
 * - The transport failure (WP_Error) path is unchanged.
 *
 * ## Determinism
 *
 * No test sleeps. The client is given an injected sleeper that records the
 * delay it *would* have applied, so the timing policy is asserted directly
 * rather than inferred from wall-clock time. Nothing here contacts Eventbrite.
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
	)
);

register_shutdown_function(
	static function () use ( $restore_options ) {
		test_options_restore( $restore_options, true );
	}
);

/**
 * Install an HTTP double returning a scripted sequence of responses.
 *
 * @param array $sequence Ordered list of array( code, headers ) or WP_Error.
 * @return void
 */
function g4b_install_script( array $sequence ) {
	$GLOBALS['g4b_sequence'] = $sequence;
	$GLOBALS['g4b_served']   = array();
	$GLOBALS['g4b_index']    = 0;

	add_filter( 'pre_http_request', 'g4b_http_double', 10, 3 );
}

/**
 * Remove the HTTP double.
 *
 * @return void
 */
function g4b_remove_script() {
	remove_filter( 'pre_http_request', 'g4b_http_double', 10 );
}

/**
 * The scripted `pre_http_request` double.
 *
 * @param mixed  $preempt Short-circuit value.
 * @param array  $args    Request args.
 * @param string $url     Request URL.
 * @return array|WP_Error
 */
function g4b_http_double( $preempt, $args, $url ) {
	$GLOBALS['g4b_served'][] = $url;

	$sequence = (array) $GLOBALS['g4b_sequence'];
	$index    = (int) $GLOBALS['g4b_index'];
	$item     = isset( $sequence[ $index ] ) ? $sequence[ $index ] : end( $sequence );
	++$GLOBALS['g4b_index'];

	if ( is_wp_error( $item ) ) {
		return $item;
	}

	return array(
		'response' => array(
			'code'    => isset( $item['code'] ) ? (int) $item['code'] : 200,
			'message' => 'scripted',
		),
		'body'     => isset( $item['body'] ) ? $item['body'] : 'BODY',
		'headers'  => isset( $item['headers'] ) ? $item['headers'] : array(),
		'cookies'  => array(),
	);
}

// ===========================================================================
// Test 1: parse_retry_after — both standard forms, capped, and safe fallback.
// ===========================================================================
test_section( 'G4B: Retry-After is parsed, capped, and safely rejected' );

$now = 1800000000;

assert_equals( 7.0, Conexao_Eventbrite_Client::parse_retry_after( '7', $now ), 'G4B: delta-seconds "7" parses to 7' );
assert_equals( 0.0, Conexao_Eventbrite_Client::parse_retry_after( '0', $now ), 'G4B: delta-seconds "0" parses to 0' );

$http_date = gmdate( 'D, d M Y H:i:s', $now + 25 ) . ' GMT';
$parsed_date = Conexao_Eventbrite_Client::parse_retry_after( $http_date, $now );
assert_true( null !== $parsed_date && $parsed_date > 20 && $parsed_date <= 26, 'G4B: an HTTP-date Retry-After parses to a delay relative to now (got ' . var_export( $parsed_date, true ) . ')' );

// The hard cap: an upstream server must never impose an unbounded sleep.
$cap = (float) Conexao_Eventbrite_Client::MAX_RETRY_AFTER_SECONDS;
assert_equals( $cap, Conexao_Eventbrite_Client::parse_retry_after( '999999', $now ), 'G4B: Retry-After=999999 is capped at ' . (int) $cap . 's' );
assert_equals( $cap, Conexao_Eventbrite_Client::parse_retry_after( '3600', $now ), 'G4B: Retry-After=3600 is capped' );
$future_date = gmdate( 'D, d M Y H:i:s', $now + 100000 ) . ' GMT';
assert_equals( $cap, Conexao_Eventbrite_Client::parse_retry_after( $future_date, $now ), 'G4B: a far-future HTTP-date is capped' );

// Invalid values fall back to the existing policy (null => exponential backoff).
assert_equals( null, Conexao_Eventbrite_Client::parse_retry_after( 'soon', $now ), 'G4B: a non-numeric Retry-After is rejected (falls back)' );
assert_equals( null, Conexao_Eventbrite_Client::parse_retry_after( '', $now ), 'G4B: an empty Retry-After is rejected (falls back)' );
assert_equals( null, Conexao_Eventbrite_Client::parse_retry_after( '   ', $now ), 'G4B: a whitespace Retry-After is rejected (falls back)' );
assert_equals( 0.0, Conexao_Eventbrite_Client::parse_retry_after( gmdate( 'D, d M Y H:i:s', $now - 500 ) . ' GMT', $now ), 'G4B: a past HTTP-date clamps to 0 rather than going negative' );

// ===========================================================================
// Test 2: the retry classification itself.
// ===========================================================================
test_section( 'G4B: only transient statuses are classified retryable' );

foreach ( array( 429, 500, 502, 503, 504 ) as $code ) {
	assert_true(
		Conexao_Eventbrite_Client::is_retryable_status( $code ),
		'G4B: HTTP ' . $code . ' IS retried'
	);
}

foreach ( array( 401, 403, 404, 408, 200, 301, 410 ) as $code ) {
	assert_true(
		! Conexao_Eventbrite_Client::is_retryable_status( $code ),
		'G4B: HTTP ' . $code . ' is NOT retried (no blind retry)'
	);
}
// ===========================================================================
// Test 3: 429 + Retry-After=7 waits 7 seconds before the next attempt.
// ===========================================================================
test_section( 'G4B: 429 with Retry-After=7 waits the parsed, bounded delay' );

g4b_install_script(
	array(
		array(
			'code'    => 429,
			'headers' => array( 'retry-after' => '7' ),
		),
		array(
			'code' => 200,
			'body' => 'RECOVERED-BODY',
		),
	)
);

$client = new Conexao_Eventbrite_Client( 'g4b_probe' );
$client->set_sleeper(
	function ( $seconds ) {
		$GLOBALS['g4b_slept'][] = $seconds;
	}
);
$GLOBALS['g4b_slept'] = array();

$body = $client->fetch_page( 'https://example.invalid/discovery', 1 );
g4b_remove_script();

assert_equals( 'RECOVERED-BODY', $body, 'G4B: the retry after 429 recovered the page' );
assert_equals( 2, count( $GLOBALS['g4b_served'] ), 'G4B: exactly 2 requests were made (one failure, one retry)' );
assert_equals( 7.0, $GLOBALS['g4b_slept'][0], 'G4B: the client waited exactly the Retry-After value (7s), not the 2s default backoff' );
assert_equals( 7.0, $client->get_applied_delays()[0], 'G4B: the applied delay is recorded for diagnostics' );

// ===========================================================================
// Test 4: an excessive Retry-After is capped.
// ===========================================================================
test_section( 'G4B: an excessive Retry-After is capped, never slept literally' );

g4b_install_script(
	array(
		array(
			'code'    => 429,
			'headers' => array( 'retry-after' => '999999' ),
		),
		array(
			'code' => 200,
			'body' => 'OK',
		),
	)
);

$capped = new Conexao_Eventbrite_Client( 'g4b_probe' );
$capped->set_sleeper(
	function ( $seconds ) {
		$GLOBALS['g4b_slept_capped'][] = $seconds;
	}
);
$GLOBALS['g4b_slept_capped'] = array();

$capped->fetch_page( 'https://example.invalid/discovery', 1 );
g4b_remove_script();

assert_equals(
	(float) Conexao_Eventbrite_Client::MAX_RETRY_AFTER_SECONDS,
	$GLOBALS['g4b_slept_capped'][0],
	'G4B: Retry-After=999999 was clamped to the hard cap before sleeping'
);
assert_true(
	$GLOBALS['g4b_slept_capped'][0] < 999999,
	'G4B: the client did NOT sleep the literal upstream value'
);

// ===========================================================================
// Test 5: an invalid Retry-After falls back to the existing backoff policy.
// ===========================================================================
test_section( 'G4B: an invalid Retry-After falls back to the exponential backoff' );

g4b_install_script(
	array(
		array(
			'code'    => 429,
			'headers' => array( 'retry-after' => 'whenever' ),
		),
		array(
			'code' => 200,
			'body' => 'OK',
		),
	)
);

$fallback = new Conexao_Eventbrite_Client( 'g4b_probe' );
$fallback->set_sleeper(
	function ( $seconds ) {
		$GLOBALS['g4b_slept_fallback'][] = $seconds;
	}
);
$GLOBALS['g4b_slept_fallback'] = array();

$fallback->fetch_page( 'https://example.invalid/discovery', 1 );
g4b_remove_script();

assert_equals(
	(float) Conexao_Eventbrite_Pacing::RATE_LIMIT_BASE_SECONDS,
	$GLOBALS['g4b_slept_fallback'][0],
	'G5: an invalid Retry-After falls back to the rate-limit cooldown, not the generic 2s backoff'
);

// A 429 with NO Retry-After header behaves identically.
g4b_install_script(
	array(
		array( 'code' => 429 ),
		array(
			'code' => 200,
			'body' => 'OK',
		),
	)
);

$no_header = new Conexao_Eventbrite_Client( 'g4b_probe' );
$no_header->set_sleeper(
	function ( $seconds ) {
		$GLOBALS['g4b_slept_none'][] = $seconds;
	}
);
$GLOBALS['g4b_slept_none'] = array();

$no_header->fetch_page( 'https://example.invalid/discovery', 1 );
g4b_remove_script();

assert_equals(
	(float) Conexao_Eventbrite_Pacing::RATE_LIMIT_BASE_SECONDS,
	$GLOBALS['g4b_slept_none'][0],
	'G5: a 429 WITHOUT Retry-After uses the rate-limit cooldown (G5 supersedes the G4 2s backoff for 429)'
);
assert_true(
	(float) Conexao_Eventbrite_Pacing::RATE_LIMIT_BASE_SECONDS > (float) Conexao_Eventbrite_Client::BASE_BACKOFF_SECONDS,
	'G5: the rate-limit cooldown is strictly longer than the generic backoff — the measured 2s/4s were insufficient'
);
// ===========================================================================
// Test 6: the per-status request matrix (attempt counts and outcomes).
// ===========================================================================
test_section( 'G4B: the status matrix — retried vs not, and the attempt ceiling' );

// code => [ requests expected, body expected ]
$matrix = array(
	401 => array( 1, false ),
	403 => array( 1, false ),
	404 => array( 1, false ),
	408 => array( 1, false ),
	429 => array( Conexao_Eventbrite_Client::MAX_RETRIES, false ),
	500 => array( Conexao_Eventbrite_Client::MAX_RETRIES, false ),
	502 => array( Conexao_Eventbrite_Client::MAX_RETRIES, false ),
	503 => array( Conexao_Eventbrite_Client::MAX_RETRIES, false ),
	504 => array( Conexao_Eventbrite_Client::MAX_RETRIES, false ),
);

foreach ( $matrix as $code => $expectation ) {
	list( $expected_requests, $expect_success ) = $expectation;

	g4b_install_script(
		array(
			array(
				'code' => $code,
				'body' => 'ERROR-PAGE',
			),
		)
	);

	// Zero the applied delays without changing the POLICY: the wait is
	// observed, not endured, so this suite stays fast and deterministic.
	//
	// G5: the generic backoff filter alone is no longer sufficient. Since G5
	// a 429 waits on the RATE-LIMIT cooldown, which is longer than the generic
	// backoff and is owned by the pacing policy. Both waits are injected so
	// this matrix really remains observation-only — without this the 429 row
	// would endure the full cooldown for every status in the matrix.
	add_filter( 'conexao_eventbrite_backoff_seconds', '__return_zero', 99 );
	add_filter( 'conexao_eventbrite_page_delay_seconds', '__return_zero', 99 );

	$m_client = new Conexao_Eventbrite_Client( 'g4b_matrix' );
	$m_client->set_sleeper(
		function ( $seconds ) {
			$GLOBALS['g4b_matrix_slept'][] = $seconds;
		}
	);
	$GLOBALS['g4b_matrix_slept'] = array();

	$m_body = $m_client->fetch_page( 'https://example.invalid/matrix', 1 );

	remove_filter( 'conexao_eventbrite_backoff_seconds', '__return_zero', 99 );
	remove_filter( 'conexao_eventbrite_page_delay_seconds', '__return_zero', 99 );
	g4b_remove_script();

	assert_equals(
		$expected_requests,
		count( $GLOBALS['g4b_served'] ),
		'G4B: HTTP ' . $code . ' issues exactly ' . $expected_requests . ' request(s)'
	);
	assert_true(
		$expect_success ? '' !== $m_body : '' === $m_body,
		'G4B: HTTP ' . $code . ' returns ' . ( $expect_success ? 'a body' : 'an empty result (failure)' )
	);
}

// A transport error (WP_Error) keeps the existing retry semantics.
g4b_install_script(
	array(
		new WP_Error( 'http_request_failed', 'Connection timed out' ),
	)
);

$timeout_client = new Conexao_Eventbrite_Client( 'g4b_timeout' );
// G5: inject the sleeper so the wait is observed, not endured.
$timeout_client->set_sleeper(
	function ( $seconds ) {
		$GLOBALS['g4b_slept_timeout'][] = $seconds;
	}
);
$GLOBALS['g4b_slept_timeout'] = array();

$timeout_body = $timeout_client->fetch_page( 'https://example.invalid/timeout', 1 );
g4b_remove_script();

assert_equals(
	Conexao_Eventbrite_Client::MAX_RETRIES,
	count( $GLOBALS['g4b_served'] ),
	'G4B: a transport timeout keeps the existing ' . Conexao_Eventbrite_Client::MAX_RETRIES . '-attempt retry'
);
assert_equals( '', $timeout_body, 'G4B: a transport timeout yields an empty result, never a fake success' );
assert_equals( 0, $timeout_client->get_last_http_status(), 'G4B: a transport failure reports status 0' );

// ===========================================================================
// Test 7: the ceiling is a CEILING — a permanently failing page stops at it.
// ===========================================================================
test_section( 'G4B: a permanently failing page stops at the attempt ceiling' );

g4b_install_script(
	array(
		array( 'code' => 429 ),
	)
);

add_filter( 'conexao_eventbrite_backoff_seconds', '__return_zero', 99 );

// G5: a permanently 429 page waits on the rate-limit cooldown three times
// (20s + 40s + 60s). Inject the sleeper so this stays a fast, deterministic
// observation rather than a 2-minute wait.
$ceiling_client = new Conexao_Eventbrite_Client( 'g4b_ceiling' );
$ceiling_client->set_sleeper(
	function ( $seconds ) {
		$GLOBALS['g4b_slept_ceiling'][] = $seconds;
	}
);
$GLOBALS['g4b_slept_ceiling'] = array();

$ceiling_body = $ceiling_client->fetch_page( 'https://example.invalid/ceiling', 1 );

remove_filter( 'conexao_eventbrite_backoff_seconds', '__return_zero', 99 );
g4b_remove_script();

assert_equals(
	3,
	count( $GLOBALS['g4b_served'] ),
	'G4B: a permanently 429-ing page is attempted exactly 3 times — retry is never unbounded'
);
assert_equals( '', $ceiling_body, 'G4B: the exhausted page returns an empty result (a failure, not an empty success)' );
assert_equals( 429, $ceiling_client->get_last_http_status(), 'G4B: the last observed status is reported truthfully' );

// A success on the FINAL allowed attempt still counts as a success.
g4b_install_script(
	array(
		array( 'code' => 503 ),
		array( 'code' => 503 ),
		array(
			'code' => 200,
			'body' => 'THIRD-ATTEMPT-WINS',
		),
	)
);

add_filter( 'conexao_eventbrite_backoff_seconds', '__return_zero', 99 );
$last_ok = new Conexao_Eventbrite_Client( 'g4b_last_ok' );
$last_ok->set_sleeper(
	function ( $seconds ) {
		$GLOBALS['g4b_slept_last_ok'][] = $seconds;
	}
);
$GLOBALS['g4b_slept_last_ok'] = array();

$last_body = $last_ok->fetch_page( 'https://example.invalid/last-ok', 1 );
remove_filter( 'conexao_eventbrite_backoff_seconds', '__return_zero', 99 );
g4b_remove_script();

assert_equals( 'THIRD-ATTEMPT-WINS', $last_body, 'G4B: a success on the final permitted attempt is honoured' );
assert_equals( 3, count( $GLOBALS['g4b_served'] ), 'G4B: it took exactly 3 attempts' );

// ===========================================================================
// Test 8: the retry diagnostics stay run-correlated.
// ===========================================================================
test_section( 'G4B: retry diagnostics carry the run_id' );

g4b_install_script(
	array(
		array(
			'code'    => 429,
			'headers' => array( 'retry-after' => '3' ),
		),
		array(
			'code' => 200,
			'body' => 'OK',
		),
	)
);

add_filter( 'conexao_event_importer_current_run_id', 'g4b_run_id_filter' );
$run_client = new Conexao_Eventbrite_Client( 'g4b_run' );
$run_client->set_sleeper(
	function ( $seconds ) {}
);
$run_client->fetch_page( 'https://example.invalid/run', 1 );
remove_filter( 'conexao_event_importer_current_run_id', 'g4b_run_id_filter' );
g4b_remove_script();

$entries = Conexao_Import_Log::get_for_run( 'g4b-run-42' );
assert_true( ! empty( $entries ), 'G4B: the retry produced a run-correlated diagnostic entry' );

$correlated = true;
foreach ( Conexao_Import_Log::get_for_source( 'g4b_run', 50 ) as $entry ) {
	if ( '' === $entry['run_id'] ) {
		$correlated = false;
	}
}
assert_true( $correlated, 'G4B: no diagnostic entry written by the retry path lacks a run_id' );

test_options_restore( $restore_options );
$GLOBALS['conexao_test_option_snapshots'] = array();

test_finish();

/**
 * Supplies the current run ID for the correlation assertions.
 *
 * @return string
 */
function g4b_run_id_filter() {
	return 'g4b-run-42';
}