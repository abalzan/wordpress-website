<?php
/**
 * G4A — bounded, per-run response reuse.
 *
 * ## What is proved
 *
 * 1. The same GET twice **inside one run** → exactly ONE outbound request; the
 *    second call is served from the reuse store.
 * 2. The same GET in a **different run** → a second outbound request. Runs never
 *    share cache state.
 * 3. A differing response-relevant input (method, URL, headers, body) → NO false
 *    cache hit.
 * 4. POST / state-changing / auth-bearing requests are **never** cached.
 * 5. A **cached** malformed body fails closed, exactly as a direct fetch does.
 * 6. A cache hit preserves status handling and `run_id` correlation; a failure
 *    response is never replayed.
 *
 * ## Transport
 *
 * Every case runs against a `pre_http_request` double that counts the requests
 * it actually serves. The double is the ONLY source of network traffic here:
 * nothing contacts Eventbrite, Heritage Week or any other host, and no real
 * sleep occurs.
 *
 * @package Conexao_Event_Importer
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$passed = 0;
$failed = 0;

// G3 discipline: never mutate or clear live operator diagnostics.
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
 * A source handler that exposes the shared transport seam for testing.
 *
 * It performs no parsing of its own: the point is to observe exactly what
 * `request_get()` does, which is the single seam every handler uses.
 */
class G4A_Probe_Source extends Conexao_Source_Base {

	/**
	 * Source ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'g4a_probe';
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
	 * @param string $url  URL.
	 * @param array  $args Request args.
	 * @return array|WP_Error
	 */
	public function probe( $url, $args = array() ) {
		$defaults = array(
			'timeout'    => 30,
			'user-agent' => 'g4a-probe-agent',
		);

		foreach ( array( 'method', 'headers', 'body' ) as $key ) {
			if ( isset( $args[ $key ] ) ) {
				$defaults[ $key ] = $args[ $key ];
			}
		}

		return $this->request_get( $url, array_merge( $defaults, $args ) );
	}

	/**
	 * Public wrapper around the protected parser, so a test can assert that a
	 * reused body reaches exactly the same parser as a fetched one.
	 *
	 * @param string $html Raw HTML.
	 * @return array{length:int}
	 */
	public function count_summary_cards( $html ) {
		$dom = $this->parse_html( $html );

		if ( ! $dom ) {
			return array( 'length' => 0 );
		}

		$xpath = new DOMXPath( $dom );
		$cards = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " item-summary ")]' );

		return array( 'length' => $cards ? $cards->length : 0 );
	}
}

/**
 * Install an HTTP double that counts every request it serves.
 *
 * @param array $responses URL-substring => array( code, body, headers ) or WP_Error.
 * @return void
 */
function g4a_install_double( array $responses ) {
	$GLOBALS['g4a_responses'] = $responses;
	$GLOBALS['g4a_served']    = array();

	add_filter( 'pre_http_request', 'g4a_http_double', 10, 3 );
}

/**
 * Remove the HTTP double.
 *
 * @return void
 */
function g4a_remove_double() {
	remove_filter( 'pre_http_request', 'g4a_http_double', 10 );
}

/**
 * The `pre_http_request` double.
 *
 * @param mixed  $preempt Short-circuit value.
 * @param array  $args    Request args.
 * @param string $url     Request URL.
 * @return array|WP_Error
 */
function g4a_http_double( $preempt, $args, $url ) {
	$GLOBALS['g4a_served'][] = $url;

	$method = isset( $args['method'] ) ? strtoupper( (string) $args['method'] ) : 'GET';

	foreach ( (array) $GLOBALS['g4a_responses'] as $needle => $response ) {
		if ( false === strpos( $url, $needle ) ) {
			continue;
		}

		// The double may key on the method too, so POST and GET for the same
		// URL can differ (proving POST is never served from the GET store).
		if ( is_array( $response ) && isset( $response['by_method'] ) ) {
			if ( ! isset( $response['by_method'][ $method ] ) ) {
				continue;
			}
			$response = $response['by_method'][ $method ];
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'response' => array(
				'code'    => isset( $response['code'] ) ? (int) $response['code'] : 200,
				'message' => isset( $response['message'] ) ? $response['message'] : 'OK',
			),
			'body'     => isset( $response['body'] ) ? $response['body'] : '',
			'headers'  => isset( $response['headers'] ) ? $response['headers'] : array(),
			'cookies'  => array(),
		);
	}

	return new WP_Error( 'g4a_unmocked_url', 'No HTTP double registered for ' . $url );
}
// ===========================================================================
// Test 1: the same GET twice in one run → one outbound request.
// ===========================================================================
test_section( 'G4A: a repeated GET in one run performs ONE outbound request' );

Conexao_Request_Reuse::begin_run();
Conexao_Request_Budget::begin_run();
$GLOBALS['g4a_served'] = array();

g4a_install_double(
	array(
		'probe-one' => array(
			'code' => 200,
			'body' => 'BODY-FROM-UPSTREAM',
		),
	)
);

$probe = new G4A_Probe_Source( array( 'id' => 'g4a_probe' ) );
$url   = 'https://example.invalid/probe-one';

$first  = $probe->probe( $url );
$second = $probe->probe( $url );

assert_true( ! is_wp_error( $first ), 'G4A: the first GET succeeded' );
assert_equals( 'BODY-FROM-UPSTREAM', wp_remote_retrieve_body( $first ), 'G4A: the first call returned the upstream body' );
assert_equals( 'BODY-FROM-UPSTREAM', wp_remote_retrieve_body( $second ), 'G4A: the second call returned the same body (reused)' );
assert_equals( 1, count( $GLOBALS['g4a_served'] ), 'G4A: exactly ONE outbound request was performed (got ' . count( $GLOBALS['g4a_served'] ) . ')' );

$stats = Conexao_Request_Reuse::get_stats();
assert_equals( 1, (int) $stats['hits'], 'G4A: the reuse store recorded 1 hit' );
assert_equals( 1, (int) $stats['misses'], 'G4A: the reuse store recorded 1 miss' );
assert_equals( 1, (int) $stats['stores'], 'G4A: the reuse store recorded 1 store' );

g4a_remove_double();

// ===========================================================================
// Test 2: the same URL in a DIFFERENT run → a second outbound request.
// ===========================================================================
test_section( 'G4A: a new run does NOT reuse the previous run response' );

Conexao_Request_Reuse::begin_run();
Conexao_Request_Budget::begin_run();
$GLOBALS['g4a_served'] = array();

g4a_install_double(
	array(
		'probe-one' => array(
			'code' => 200,
			'body' => 'BODY-FROM-UPSTREAM',
		),
	)
);

$run_two = $probe->probe( $url );
g4a_remove_double();

assert_equals( 1, count( $GLOBALS['g4a_served'] ), 'G4A: the second run issued its OWN outbound request' );
assert_equals( 'BODY-FROM-UPSTREAM', wp_remote_retrieve_body( $run_two ), 'G4A: the second run got fresh content, not a stale replay' );

// ===========================================================================
// Test 3: cache-key discrimination — no false collisions.
// ===========================================================================
test_section( 'G4A: differing response-relevant inputs never collide' );

$base_args = array();

$key_url_a = Conexao_Request_Reuse::make_key( 'GET', 'https://example.invalid/a', $base_args );
$key_url_b = Conexao_Request_Reuse::make_key( 'GET', 'https://example.invalid/b', $base_args );
assert_true( $key_url_a !== $key_url_b, 'G4A: a different URL produces a different cache key' );

$key_get  = Conexao_Request_Reuse::make_key( 'GET', 'https://example.invalid/a', $base_args );
$key_post = Conexao_Request_Reuse::make_key( 'POST', 'https://example.invalid/a', $base_args );
assert_true( $key_get !== $key_post, 'G4A: a different method produces a different cache key' );

$key_plain  = Conexao_Request_Reuse::make_key( 'GET', 'https://example.invalid/a', $base_args );
$key_accept = Conexao_Request_Reuse::make_key( 'GET', 'https://example.invalid/a', array( 'headers' => array( 'Accept' => 'application/json' ) ) );
assert_true( $key_plain !== $key_accept, 'G4A: a different Accept header produces a different cache key' );

$key_lang  = Conexao_Request_Reuse::make_key( 'GET', 'https://example.invalid/a', array( 'headers' => array( 'Accept-Language' => 'en-IE' ) ) );
$key_lang2 = Conexao_Request_Reuse::make_key( 'GET', 'https://example.invalid/a', array( 'headers' => array( 'Accept-Language' => 'pt-PT' ) ) );
assert_true( $key_lang !== $key_lang2, 'G4A: a different Accept-Language produces a different cache key' );

$key_ua  = Conexao_Request_Reuse::make_key( 'GET', 'https://example.invalid/a', array( 'user-agent' => 'agent-one' ) );
$key_ua2 = Conexao_Request_Reuse::make_key( 'GET', 'https://example.invalid/a', array( 'user-agent' => 'agent-two' ) );
assert_true( $key_ua !== $key_ua2, 'G4A: a different User-Agent produces a different cache key' );

$key_body  = Conexao_Request_Reuse::make_key( 'GET', 'https://example.invalid/a', array( 'body' => 'x=1' ) );
$key_body2 = Conexao_Request_Reuse::make_key( 'GET', 'https://example.invalid/a', array( 'body' => 'x=2' ) );
assert_true( $key_body !== $key_body2, 'G4A: a different body produces a different cache key' );

$key_q1 = Conexao_Request_Reuse::make_key( 'GET', 'https://example.invalid/a?page=1', $base_args );
$key_q2 = Conexao_Request_Reuse::make_key( 'GET', 'https://example.invalid/a?page=2', $base_args );
assert_true( $key_q1 !== $key_q2, 'G4A: a different query string produces a different cache key' );

// Identical inputs must reproduce the same key, or nothing could ever be reused.
assert_true(
	Conexao_Request_Reuse::make_key( 'GET', 'https://example.invalid/a', $base_args ) === $key_plain,
	'G4A: an identical request reproduces the same cache key'
);

// Header name case and ordering are not response-relevant, so they must not
// create a false miss.
$key_h1 = Conexao_Request_Reuse::make_key( 'GET', 'https://example.invalid/a', array( 'headers' => array( 'Accept' => 'text/html', 'Accept-Language' => 'en-IE' ) ) );
$key_h2 = Conexao_Request_Reuse::make_key( 'GET', 'https://example.invalid/a', array( 'headers' => array( 'accept-language' => 'en-IE', 'accept' => 'text/html' ) ) );
assert_true( $key_h1 === $key_h2, 'G4A: header name case/order does not create a false miss' );
// ===========================================================================
// Test 4: no FALSE HIT at runtime for a differing request input.
// ===========================================================================
test_section( 'G4A: a differing request input causes no false cache hit' );

Conexao_Request_Reuse::begin_run();
Conexao_Request_Budget::begin_run();
$GLOBALS['g4a_served'] = array();

g4a_install_double(
	array(
		'probe-lang' => array(
			'code' => 200,
			'body' => 'UPSTREAM-BODY',
		),
	)
);

$probe->probe( 'https://example.invalid/probe-lang', array( 'headers' => array( 'Accept-Language' => 'en-IE' ) ) );
$other = $probe->probe( 'https://example.invalid/probe-lang', array( 'headers' => array( 'Accept-Language' => 'pt-PT' ) ) );
g4a_remove_double();

assert_equals( 2, count( $GLOBALS['g4a_served'] ), 'G4A: the differing Accept-Language was NOT served from cache' );
assert_equals( 'UPSTREAM-BODY', wp_remote_retrieve_body( $other ), 'G4A: the differing request returned the upstream body' );

// ===========================================================================
// Test 5: POST is NEVER cached.
// ===========================================================================
test_section( 'G4A: POST is never cached' );

assert_true( ! Conexao_Request_Reuse::is_cacheable( 'POST', array() ), 'G4A: is_cacheable() rejects POST' );
assert_true( ! Conexao_Request_Reuse::is_cacheable( 'PUT', array() ), 'G4A: is_cacheable() rejects PUT' );
assert_true( ! Conexao_Request_Reuse::is_cacheable( 'DELETE', array() ), 'G4A: is_cacheable() rejects DELETE' );
assert_true( ! Conexao_Request_Reuse::is_cacheable( 'PATCH', array() ), 'G4A: is_cacheable() rejects PATCH' );
assert_true( Conexao_Request_Reuse::is_cacheable( 'GET', array() ), 'G4A: is_cacheable() accepts GET' );
assert_true( Conexao_Request_Reuse::is_cacheable( 'HEAD', array() ), 'G4A: is_cacheable() accepts HEAD' );

Conexao_Request_Reuse::begin_run();
Conexao_Request_Budget::begin_run();
$GLOBALS['g4a_served'] = array();

g4a_install_double(
	array(
		'probe-post' => array(
			'by_method' => array(
				'POST' => array(
					'code' => 200,
					'body' => 'CREATED',
				),
			),
		),
	)
);

$p1 = $probe->probe( 'https://example.invalid/probe-post', array( 'method' => 'POST' ) );
$p2 = $probe->probe( 'https://example.invalid/probe-post', array( 'method' => 'POST' ) );
g4a_remove_double();

assert_true( ! is_wp_error( $p1 ), 'G4A: the first POST succeeded' );
assert_equals( 'CREATED', wp_remote_retrieve_body( $p1 ), 'G4A: first POST body' );
assert_equals( 'CREATED', wp_remote_retrieve_body( $p2 ), 'G4A: second POST body' );
assert_equals( 2, count( $GLOBALS['g4a_served'] ), 'G4A: BOTH POSTs hit the network — POST is never cached (got ' . count( $GLOBALS['g4a_served'] ) . ' outbound)' );

// Auth-bearing, conditional and body-bearing requests bypass reuse entirely.
assert_true(
	! Conexao_Request_Reuse::is_cacheable( 'GET', array( 'headers' => array( 'Authorization' => 'Bearer x' ) ) ),
	'G4A: a request carrying Authorization is not cacheable'
);
assert_true(
	! Conexao_Request_Reuse::is_cacheable( 'GET', array( 'headers' => array( 'If-None-Match' => 'W/"x"' ) ) ),
	'G4A: a conditional request is not cacheable'
);
assert_true(
	! Conexao_Request_Reuse::is_cacheable( 'GET', array( 'body' => 'a=1' ) ),
	'G4A: a request with a body is not cacheable'
);
// ===========================================================================
// Test 6: a CACHED malformed response fails closed exactly like a direct one.
// ===========================================================================
test_section( 'G4A: a cached malformed response fails closed' );

Conexao_Request_Reuse::begin_run();
Conexao_Request_Budget::begin_run();
$GLOBALS['g4a_served'] = array();

g4a_install_double(
	array(
		'probe-malformed' => array(
			'code' => 200,
			'body' => '<html><body>not what the parser expects</body></html>',
		),
	)
);

$mal_url = 'https://example.invalid/probe-malformed';

$direct = $probe->probe( $mal_url );
$reused = $probe->probe( $mal_url );
g4a_remove_double();

assert_equals( 1, count( $GLOBALS['g4a_served'] ), 'G4A: the malformed response was fetched once and then reused' );
assert_equals(
	wp_remote_retrieve_body( $direct ),
	wp_remote_retrieve_body( $reused ),
	'G4A: the reused body is byte-identical to the fetched body'
);
assert_equals( 200, (int) wp_remote_retrieve_response_code( $reused ), 'G4A: the reused response carries the same status code' );

// The parser must reach the same verdict on the cached body as on the fetched
// one: reuse is NOT a shortcut around validation.
$direct_cards = $probe->count_summary_cards( wp_remote_retrieve_body( $direct ) )['length'];
$reused_cards = $probe->count_summary_cards( wp_remote_retrieve_body( $reused ) )['length'];

assert_equals( $direct_cards, $reused_cards, 'G4A: the cached body yields exactly the same parse result' );
assert_equals( 0, $reused_cards, 'G4A: the cached malformed body still yields ZERO events — it fails closed' );

// A malformed 200 that reaches the REAL Eventbrite parser must still throw,
// whether it was fetched or reused.
Conexao_Request_Reuse::begin_run();
Conexao_Request_Budget::begin_run();
$GLOBALS['g4a_served'] = array();

g4a_install_double(
	array(
		'probe-waf' => array(
			'code' => 200,
			'body' => '<html><head><title>CloudFront</title></head><body>Access denied</body></html>',
		),
	)
);

$eb_parser = new Conexao_Eventbrite_Parser();
$waf_url   = 'https://example.invalid/probe-waf';
$waf_first = $probe->probe( $waf_url );

$direct_threw = false;
try {
	$eb_parser->parse( wp_remote_retrieve_body( $waf_first ) );
} catch ( Exception $e ) {
	$direct_threw = true;
}

$waf_second = $probe->probe( $waf_url );
$reused_threw = false;
try {
	$eb_parser->parse( wp_remote_retrieve_body( $waf_second ) );
} catch ( Exception $e ) {
	$reused_threw = true;
}
g4a_remove_double();

assert_equals( 1, count( $GLOBALS['g4a_served'] ), 'G4A: the malformed 200 was fetched once and reused' );
assert_true( $direct_threw, 'G4A: control — the malformed 200 raises when fetched directly' );
assert_true( $reused_threw, 'G4A: the CACHED malformed 200 raises exactly the same way (no silent acceptance)' );
// ===========================================================================
// Test 7: a cache hit preserves status handling and run correlation.
// ===========================================================================
test_section( 'G4A: a cache hit preserves status handling and run_id correlation' );

Conexao_Request_Reuse::begin_run();
Conexao_Request_Budget::begin_run();
$GLOBALS['g4a_served'] = array();

g4a_install_double(
	array(
		'probe-500' => array(
			'code' => 500,
			'body' => 'upstream exploded',
		),
	)
);

// A non-2xx response is NEVER stored, so the second fetch goes out again rather
// than replaying a failure. This is what keeps "failure != empty success" true
// under caching, and it is also why a retry is never served from the store.
$fail_first  = $probe->probe( 'https://example.invalid/probe-500' );
$fail_second = $probe->probe( 'https://example.invalid/probe-500' );
g4a_remove_double();

assert_equals( 500, (int) wp_remote_retrieve_response_code( $fail_first ), 'G4A: first failure status preserved' );
assert_equals( 500, (int) wp_remote_retrieve_response_code( $fail_second ), 'G4A: second failure status preserved' );
assert_equals( 2, count( $GLOBALS['g4a_served'] ), 'G4A: a 500 response is NOT reused — the second attempt hit the network (got ' . count( $GLOBALS['g4a_served'] ) . ')' );

$stats = Conexao_Request_Reuse::get_stats();
assert_equals( 0, (int) $stats['stores'], 'G4A: no failure response was ever stored in the reuse store' );

// A successful (200) response IS stored, and its headers survive the round trip,
// so header-dependent downstream code behaves identically on a hit.
Conexao_Request_Reuse::begin_run();
Conexao_Request_Budget::begin_run();
$GLOBALS['g4a_served'] = array();

g4a_install_double(
	array(
		'probe-200h' => array(
			'code'    => 200,
			'body'    => 'OK-BODY',
			'headers' => array( 'content-type' => 'text/html; charset=utf-8' ),
		),
	)
);

$h_url    = 'https://example.invalid/probe-200h';
$h_first  = $probe->probe( $h_url );
$h_second = $probe->probe( $h_url );
g4a_remove_double();

assert_equals( 1, count( $GLOBALS['g4a_served'] ), 'G4A: the 200 response was fetched once' );
assert_equals(
	wp_remote_retrieve_header( $h_first, 'content-type' ),
	wp_remote_retrieve_header( $h_second, 'content-type' ),
	'G4A: a cache hit preserves the response headers'
);

// ===========================================================================
// Test 8: run correlation survives, and reuse is scoped to a run.
// ===========================================================================
test_section( 'G4A: run correlation is preserved and reuse is run-scoped' );

Conexao_Import_Log::add( 'g4a_probe', 'info', 'G4A correlation probe entry', array( 'run_id' => 'g4a-run-id-123' ) );
$entries = Conexao_Import_Log::get_for_run( 'g4a-run-id-123' );
assert_true( ! empty( $entries ), 'G4A: a diagnostic entry remains retrievable by its run_id' );
assert_equals( 'g4a-run-id-123', $entries[0]['run_id'], 'G4A: the entry carries the run_id it was written with' );

add_filter( 'conexao_request_reuse_enabled', '__return_false', 99 );
assert_true( ! Conexao_Request_Reuse::is_active(), 'G4A: reuse reports inactive when the run-scope filter disables it' );
remove_filter( 'conexao_request_reuse_enabled', '__return_false', 99 );
assert_true( Conexao_Request_Reuse::is_active(), 'G4A: reuse is active inside a begun run' );

// The engine's own accounting summary must be run-correlated too.
Conexao_Request_Budget::begin_run();
Conexao_Request_Budget::consume( 2 );
Conexao_Request_Budget::record_cache_hit();
$b_stats = Conexao_Request_Budget::get_stats();
assert_equals( 2, (int) $b_stats['used'], 'G4A: the budget counted 2 outbound requests' );
assert_equals( 1, (int) $b_stats['cache_hits'], 'G4A: the budget recorded a cache hit separately (not charged)' );

test_options_restore( $restore_options );
$GLOBALS['conexao_test_option_snapshots'] = array();

test_finish();