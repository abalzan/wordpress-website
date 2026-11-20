<?php
/**
 * Eventbrite HTTP 405 WAF diagnostics (Candidate E).
 *
 * ## What is proved
 *
 * - A 405 response retains ONLY the bounded, allowlisted `x-amzn-waf-action`
 *   value in the log context, so the WAF classification is provable from the
 *   log rather than inferred from the code path.
 * - The stored value is sanitised and length-limited, and matched
 *   case-insensitively against a fixed allowlist; anything else is 'unknown'.
 * - A 405 with no WAF header stores no `waf_action` key.
 * - A 405 with a sensitive-looking header value is never stored verbatim.
 * - 405 remains NON-RETRYABLE: exactly one request is sent, no retry, no
 *   second attempt.
 * - The change is additive: 429 handling, the shared breaker, cooldowns and
 *   incomplete-enumeration safety are untouched (covered by the existing
 *   G5/G9/G10/G18 suites, re-run as a regression gate).
 *
 * ## Determinism
 *
 * No test sleeps and nothing contacts Eventbrite. Every HTTP response is a
 * scripted `pre_http_request` double; the pacing policy takes an injected
 * sleeper and clock.
 *
 * @package Conexao_Event_Importer
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

test_require_operator_store_isolation();

test_options_isolate(
	array(
		Conexao_Event_Sources::OPTION_KEY,
	)
);

register_shutdown_function(
	static function () {
		remove_filter( 'pre_http_request', 'waf_http_double', 10 );
		Conexao_Eventbrite_Pacing::begin_run();
		test_options_restore( $GLOBALS['conexao_test_option_snapshots'] ?? array(), true );
	}
);

/**
 * Install a single scripted HTTP response.
 *
 * @param array $response Response shape (code, headers, body).
 * @return void
 */
function waf_install_script( array $response ) {
	$GLOBALS['waf_response'] = $response;
	$GLOBALS['waf_served']   = 0;

	add_filter( 'pre_http_request', 'waf_http_double', 10, 3 );
}

/**
 * The scripted `pre_http_request` double.
 *
 * @param mixed  $preempt Short-circuit value.
 * @param array  $args    Request args.
 * @param string $url     Request URL.
 * @return array
 */
function waf_http_double( $preempt, $args, $url ) {
	++$GLOBALS['waf_served'];

	$item = (array) $GLOBALS['waf_response'];

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

/**
 * A pacing policy whose sleeps and clock are fully under test control.
 *
 * @return Conexao_Eventbrite_Pacing
 */
function waf_fake_pacing() {
	$t      = 1000.0;
	$pacing = new Conexao_Eventbrite_Pacing();
	$pacing->set_sleeper(
		static function ( $seconds ) use ( &$t ) {
			$t += (float) $seconds;
		}
	);
	$pacing->set_clock(
		static function () use ( &$t ) {
			return $t;
		}
	);

	return $pacing;
}

/**
 * The most recent log entry for a source.
 *
 * @param string $source_id Source slug.
 * @return array|null
 */
function waf_last_entry( $source_id ) {
	$entries = Conexao_Import_Log::get_for_source( $source_id, 5 );

	return ( is_array( $entries ) && isset( $entries[0] ) ) ? $entries[0] : null;
}

// ===========================================================================
// Test 1: a 405 with x-amzn-waf-action: captcha retains the allowlisted value.
// ===========================================================================
test_section( 'WAF1: a 405 retains the allowlisted x-amzn-waf-action value' );

Conexao_Eventbrite_Pacing::begin_run();
Conexao_Import_Log::clear();

waf_install_script(
	array(
		'code'    => 405,
		'headers' => array( 'x-amzn-waf-action' => 'captcha' ),
	)
);

$pacing = waf_fake_pacing();
$client = new Conexao_Eventbrite_Client( 'waf_captcha', $pacing );
$body   = $client->fetch_page( 'https://example.invalid/waf-captcha', 1 );
remove_filter( 'pre_http_request', 'waf_http_double', 10 );

assert_equals( '', $body, 'WAF1: a 405 returns an empty body (non-retryable failure)' );
assert_equals( 1, $GLOBALS['waf_served'], 'WAF1: exactly ONE request was sent — 405 is NOT retried' );
assert_equals( 'http_error', $client->get_last_failure_reason(), 'WAF1: a 405 is classified as a plain HTTP error' );
assert_equals( 405, $client->get_last_http_status(), 'WAF1: the 405 status is exposed to the caller' );

$entry = waf_last_entry( 'waf_captcha' );
assert_true( null !== $entry, 'WAF1: a log entry was written for the 405' );
assert_true(
	null !== $entry && isset( $entry['context']['waf_action'] ),
	'WAF1: the log context carries a waf_action key'
);
assert_equals(
	'captcha',
	null !== $entry && isset( $entry['context']['waf_action'] ) ? $entry['context']['waf_action'] : null,
	'WAF1: the allowlisted value "captcha" is retained verbatim'
);

// ===========================================================================
// Test 2: a 405 with no WAF header stores no waf_action key.
// ===========================================================================
test_section( 'WAF2: a 405 without a WAF header stores no waf_action' );

Conexao_Eventbrite_Pacing::begin_run();
Conexao_Import_Log::clear();

waf_install_script(
	array(
		'code'    => 405,
		'headers' => array(),
	)
);

$pacing = waf_fake_pacing();
$client = new Conexao_Eventbrite_Client( 'waf_noheader', $pacing );
$client->fetch_page( 'https://example.invalid/waf-noheader', 1 );
remove_filter( 'pre_http_request', 'waf_http_double', 10 );

$entry = waf_last_entry( 'waf_noheader' );
assert_true( null !== $entry, 'WAF2: a log entry was written for the 405' );
assert_true(
	null !== $entry && ! isset( $entry['context']['waf_action'] ),
	'WAF2: no waf_action key is stored when the header is absent'
);

// ===========================================================================
// Test 3: an unrecognised WAF action value is stored as 'unknown'.
// ===========================================================================
test_section( 'WAF3: an unrecognised WAF action is bounded to "unknown"' );

Conexao_Eventbrite_Pacing::begin_run();
Conexao_Import_Log::clear();

waf_install_script(
	array(
		'code'    => 405,
		'headers' => array( 'x-amzn-waf-action' => 'totally-made-up-value' ),
	)
);

$pacing = waf_fake_pacing();
$client = new Conexao_Eventbrite_Client( 'waf_unknown', $pacing );
$client->fetch_page( 'https://example.invalid/waf-unknown', 1 );
remove_filter( 'pre_http_request', 'waf_http_double', 10 );

$entry = waf_last_entry( 'waf_unknown' );
assert_equals(
	'unknown',
	null !== $entry && isset( $entry['context']['waf_action'] ) ? $entry['context']['waf_action'] : null,
	'WAF3: an unrecognised value is stored as "unknown", never verbatim'
);

// ===========================================================================
// Test 4: a sensitive-looking WAF header value is never stored verbatim.
// ===========================================================================
test_section( 'WAF4: a sensitive-looking header value is not stored verbatim' );

Conexao_Eventbrite_Pacing::begin_run();
Conexao_Import_Log::clear();

waf_install_script(
	array(
		'code'    => 405,
		'headers' => array( 'x-amzn-waf-action' => 'captcha; session=SECRETVALUE; token=abc123' ),
	)
);

$pacing = waf_fake_pacing();
$client = new Conexao_Eventbrite_Client( 'waf_sensitive', $pacing );
$client->fetch_page( 'https://example.invalid/waf-sensitive', 1 );
remove_filter( 'pre_http_request', 'waf_http_double', 10 );

$entry  = waf_last_entry( 'waf_sensitive' );
$stored = null !== $entry && isset( $entry['context']['waf_action'] ) ? $entry['context']['waf_action'] : null;

assert_equals( 'unknown', $stored, 'WAF4: a value carrying a session/token is not matched against the allowlist' );
assert_true(
	null === $stored || false === strpos( (string) $stored, 'SECRETVALUE' ),
	'WAF4: the sensitive value is never stored'
);
assert_true(
	null === $stored || strlen( (string) $stored ) <= 32,
	'WAF4: the stored value is length-limited'
);

// ===========================================================================
// Test 5: the allowlist is case-insensitive and bounded.
// ===========================================================================
test_section( 'WAF5: the allowlist match is case-insensitive' );

Conexao_Eventbrite_Pacing::begin_run();
Conexao_Import_Log::clear();

waf_install_script(
	array(
		'code'    => 405,
		'headers' => array( 'x-amzn-waf-action' => 'CAPTCHA' ),
	)
);

$pacing = waf_fake_pacing();
$client = new Conexao_Eventbrite_Client( 'waf_case', $pacing );
$client->fetch_page( 'https://example.invalid/waf-case', 1 );
remove_filter( 'pre_http_request', 'waf_http_double', 10 );

$entry = waf_last_entry( 'waf_case' );
assert_equals(
	'captcha',
	null !== $entry && isset( $entry['context']['waf_action'] ) ? $entry['context']['waf_action'] : null,
	'WAF5: "CAPTCHA" matches the allowlist and is normalised to lowercase "captcha"'
);

// ===========================================================================
// Test 6: a non-405 non-retryable status never reads the WAF header.
// ===========================================================================
test_section( 'WAF6: a non-405 non-retryable status stores no waf_action' );

Conexao_Eventbrite_Pacing::begin_run();
Conexao_Import_Log::clear();

waf_install_script(
	array(
		'code'    => 403,
		'headers' => array( 'x-amzn-waf-action' => 'captcha' ),
	)
);

$pacing = waf_fake_pacing();
$client = new Conexao_Eventbrite_Client( 'waf_403', $pacing );
$client->fetch_page( 'https://example.invalid/waf-403', 1 );
remove_filter( 'pre_http_request', 'waf_http_double', 10 );

$entry = waf_last_entry( 'waf_403' );
assert_true(
	null !== $entry && ! isset( $entry['context']['waf_action'] ),
	'WAF6: a 403 does not retain the WAF header — only 405 does'
);

// ===========================================================================
// Test 7: a 429 is unaffected — no waf_action, breaker still opens.
// ===========================================================================
test_section( 'WAF7: 429 handling is unchanged (no waf_action, breaker opens)' );

Conexao_Eventbrite_Pacing::begin_run();
Conexao_Import_Log::clear();

waf_install_script(
	array(
		'code'    => 429,
		'headers' => array( 'retry-after' => '1' ),
	)
);

$pacing = waf_fake_pacing();
$client = new Conexao_Eventbrite_Client( 'waf_429', $pacing );
$client->fetch_page( 'https://example.invalid/waf-429', 1 );
remove_filter( 'pre_http_request', 'waf_http_double', 10 );

assert_true( $client->was_rate_limited(), 'WAF7: a 429 still enters the rate-limited state' );
assert_true( Conexao_Eventbrite_Pacing::is_rate_limited(), 'WAF7: a 429 still opens the shared breaker' );

$entry = waf_last_entry( 'waf_429' );
assert_true(
	null !== $entry && ! isset( $entry['context']['waf_action'] ),
	'WAF7: a 429 never carries a waf_action key'
);

Conexao_Eventbrite_Pacing::begin_run();

test_finish();