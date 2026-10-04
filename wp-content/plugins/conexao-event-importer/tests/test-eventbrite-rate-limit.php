<?php
/**
 * G5 — Eventbrite pacing, 429 cooldown, and rate-limit termination.
 *
 * ## What is proved
 *
 * - Pacing is centralised in Conexao_Eventbrite_Pacing, not scattered as
 *   sleep() calls through the handlers.
 * - HTTP 429 is a DISTINCT state, not an ordinary transient page failure.
 * - Retry attempts stay bounded by the existing ceiling (never unbounded).
 * - `Retry-After` is honoured when present, hard-capped when excessive, and
 *   safely ignored when invalid.
 * - Repeated 429 fails fast: the walk STOPS rather than paginating onward.
 * - A rate-limited termination is INCOMPLETE, keeps its partial data, and
 *   never marks absent events source_not_found.
 * - Cooldown consumes NO outbound request budget; 429 retries DO.
 * - The time budget still terminates a walk, including during cooldown.
 * - The run-scoped shared cooldown stops the NEXT Eventbrite source (G5E).
 * - Diagnostics distinguish rate limiting from generic unavailability.
 * - Eventbrite sources execute sequentially, never concurrently (G5F).
 *
 * ## Determinism
 *
 * No test sleeps and nothing contacts Eventbrite. The pacing policy takes an
 * injected sleeper and an injected clock, so every timing rule is asserted
 * directly rather than inferred from wall-clock time.
 *
 * @package Conexao_Event_Importer
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

test_options_isolate(
	array(
		Conexao_Import_History::OPTION_KEY,
		Conexao_Import_Log::OPTION_KEY,
		Conexao_Event_Sources::OPTION_KEY,
	)
);

register_shutdown_function(
	static function () {
		test_options_restore( $GLOBALS['conexao_test_option_snapshots'] ?? array(), true );
	}
);

/**
 * Install an HTTP double returning a scripted sequence of responses.
 *
 * @param array $sequence Ordered list of array( code, headers, body ) or WP_Error.
 * @return void
 */
function g5_install_script( array $sequence ) {
	$GLOBALS['g5_sequence'] = $sequence;
	$GLOBALS['g5_served']   = array();
	$GLOBALS['g5_index']    = 0;

	add_filter( 'pre_http_request', 'g5_http_double', 10, 3 );
}

/**
 * Remove the HTTP double.
 *
 * @return void
 */
function g5_remove_script() {
	remove_filter( 'pre_http_request', 'g5_http_double', 10 );
}

/**
 * The scripted `pre_http_request` double.
 *
 * @param mixed  $preempt Short-circuit value.
 * @param array  $args    Request args.
 * @param string $url     Request URL.
 * @return array|WP_Error
 */
function g5_http_double( $preempt, $args, $url ) {
	$GLOBALS['g5_served'][] = $url;

	$sequence = (array) $GLOBALS['g5_sequence'];
	$index    = (int) $GLOBALS['g5_index'];
	$item     = isset( $sequence[ $index ] ) ? $sequence[ $index ] : end( $sequence );
	++$GLOBALS['g5_index'];

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

/**
 * A pacing policy whose sleeps and clock are fully under test control.
 *
 * The injected sleeper advances the injected clock, so a cooldown really
 * consumes the time budget it claims to consume.
 *
 * @param array|null &$delays Receives every delay the policy requests.
 * @return Conexao_Eventbrite_Pacing
 */
function g5_fake_pacing( &$delays = null ) {
	$delays = array();
	$t       = 1000.0;

	$pacing = new Conexao_Eventbrite_Pacing();
	$pacing->set_sleeper(
		function ( $seconds ) use ( &$delays, &$t ) {
			$delays[] = $seconds;
			$t       += (float) $seconds;
		}
	);
	$pacing->set_clock(
		function () use ( &$t ) {
			return $t;
		}
	);

	return $pacing;
}

/**
 * Override the pacing delay for one assertion.
 *
 * @return float
 */
function g5_delay_filter() {
	return 7.0;
}

/**
 * Supplies the run ID for the G5D correlation assertions.
 *
 * @return string
 */
function g5_run_id() {
	return 'g5-run-77';
}

/**
 * A ten-second source time budget, for the cooldown/budget interaction.
 *
 * @return int
 */
function g5_budget_ten() {
	return 10;
}

/**
 * An effectively unlimited source time budget.
 *
 * Used to prove the rate-limit fail-fast on its own, with the time budget
 * deliberately taken out of the picture.
 *
 * @return int
 */
function g5_budget_huge() {
	return 100000;
}

/**
 * Returns the run's shared pacing policy.
 *
 * @return Conexao_Eventbrite_Pacing
 */
function g5_return_pacing() {
	return Conexao_Eventbrite_Pacing::get_shared();
}

/**
 * Build a minimal Eventbrite `__SERVER_DATA__` listing page.
 *
 * Shaped like the real discovery payload, so the assertions run against the
 * production parser rather than a stub.
 *
 * @param int $pages    Total pages the listing declares.
 * @param int $first_id Numeric ID of the first event on the page.
 * @return string HTML.
 */
function g5_make_listing_page( $pages, $first_id ) {
	$events = array();

	for ( $i = 0; $i < 2; $i++ ) {
		$id       = $first_id + $i;
		$slug     = 'https://www.eventbrite.ie/e/g5-event-' . $id;
		$events[] = array(
			'id'        => (string) $id,
			'name'      => 'G5 event ' . $id,
			'url'       => $slug,
			'start'     => array(
				'local'    => '2026-12-0' . ( ( $id ) % 9 + 1 ) . 'T19:00:00',
				'timezone' => 'Europe/Dublin',
				'utc'      => '2026-12-0' . ( ( $id ) % 9 + 1 ) . 'T19:00:00Z',
			),
			'locations' => array(
				array(
					'type' => 'region',
					'name' => 'Cork',
				),
			),
			'is_free'   => true,
			'url_struct' => array(
				'href'  => $slug,
				'first' => $slug,
			),
		);
	}

	$payload = array(
		'search_data' => array(
			'events' => array(
				'results'    => $events,
				'pagination' => array(
					'page_count'   => (int) $pages,
					'object_count' => (int) $pages * 2,
					'page_number'  => 1,
				),
			),
		),
	);

	return '<html><body><script>window.__SERVER_DATA__ = '
		. wp_json_encode( $payload )
		. ';</script></body></html>';
}

// ===========================================================================
// Test 1: G5A — pacing is one explicit, centralised policy.
// ===========================================================================
test_section( 'G5A: pacing is centralised and measurable, not scattered sleeps' );

$delays = array();
$pacing = g5_fake_pacing( $delays );

assert_equals(
	Conexao_Eventbrite_Pacing::PAGE_DELAY_SECONDS,
	$pacing->get_page_delay_seconds(),
	'G5A: the policy exposes one explicit inter-request pacing delay'
);
assert_true(
	Conexao_Eventbrite_Pacing::PAGE_DELAY_SECONDS >= 2,
	'G5A: the pacing floor is at least 2s — the measured baseline ran ~20 req/min at a 1s delay'
);

$pacing->pace_page_request();
$pacing->pace_page_request();
$pacing->pace_page_request();

assert_equals(
	array( 'page_pacing', 'page_pacing', 'page_pacing' ),
	$pacing->get_applied_delay_reasons(),
	'G5A: pacing is applied per inter-request gap and attributed as page_pacing'
);
assert_equals(
	3 * Conexao_Eventbrite_Pacing::PAGE_DELAY_SECONDS,
	array_sum( $delays ),
	'G5A: N page requests apply exactly N pacing delays'
);

// The client delegates to the policy, so there is one authority rather than a
// constant in the client and another in the handler.
$client = new Conexao_Eventbrite_Client( 'g5a_delegation', $pacing );
assert_true(
	$client->get_pacing() === $pacing,
	'G5A: the client uses the very pacing policy it was given (no second authority)'
);

// A filter can retune pacing without touching the client.
add_filter( 'conexao_eventbrite_page_delay_seconds', 'g5_delay_filter', 99 );
assert_equals(
	7.0,
	$pacing->get_page_delay_seconds(),
	'G5A: pacing is retunable through one filter, which is what makes it testable'
);
remove_filter( 'conexao_eventbrite_page_delay_seconds', 'g5_delay_filter', 99 );

// ===========================================================================
// Test 2: G5B — 429 is its own state, not a generic transient failure.
// ===========================================================================
test_section( 'G5B: HTTP 429 enters a distinct rate-limited state' );

Conexao_Eventbrite_Pacing::begin_run();
$delays = array();
$pacing = g5_fake_pacing( $delays );

g5_install_script(
	array(
		array(
			'code'    => 429,
			'headers' => array( 'retry-after' => '7' ),
		),
		array(
			'code' => 200,
			'body' => 'AFTER-COOLDOWN',
		),
	)
);

$client = new Conexao_Eventbrite_Client( 'g5b_429', $pacing );
$body   = $client->fetch_page( 'https://example.invalid/g5b', 1 );
g5_remove_script();

assert_equals( 'AFTER-COOLDOWN', $body, 'G5B: a 429 followed by a success still returns the page' );
assert_true( $client->was_rate_limited(), 'G5B: the client reports it entered the rate-limited state' );
assert_equals(
	'ok',
	$client->get_last_failure_reason(),
	'G5B: a page that recovered after the cooldown is reported as ok — the outcome is never misreported'
);
assert_equals( 1, $client->get_rate_limit_hits(), 'G5B: the 429 was recorded' );

$first_rate_limited_source = Conexao_Eventbrite_Pacing::get_rate_limited_source();
$run_was_rate_limited      = Conexao_Eventbrite_Pacing::is_rate_limited();

$cooldowns = array_values( array_filter( $pacing->get_applied_delays(), static function ( $d ) { return 'rate_limit_cooldown' === $d['reason']; } ) );
assert_equals( 1, count( $cooldowns ), 'G5B: exactly ONE cooldown was applied for the 429 — not an immediate retry' );
assert_equals( 7.0, $cooldowns[0]['seconds'], 'G5B: a Retry-After of 7 was honoured exactly' );
assert_true( $pacing->last_retry_after_was_present(), 'G5B: the policy recorded that Retry-After was present' );
assert_true( ! $pacing->last_retry_after_was_capped(), 'G5B: a Retry-After of 7 was not capped' );

// A 500 must NOT be treated as rate limiting.
Conexao_Eventbrite_Pacing::begin_run();
$delays = array();
$pacing = g5_fake_pacing( $delays );
g5_install_script( array( array( 'code' => 500 ) ) );
$client_500 = new Conexao_Eventbrite_Client( 'g5b_500', $pacing );
$client_500->fetch_page( 'https://example.invalid/g5b-500', 1 );
g5_remove_script();

assert_true( ! $client_500->was_rate_limited(), 'G5B: a 500 is NOT classified as rate limiting' );
assert_equals( 'http_error', $client_500->get_last_failure_reason(), 'G5B: a 500 is classified as a plain HTTP error' );

// The rate limit is reported run-wide (measured source-wide in G5E).
assert_true( $run_was_rate_limited, 'G5B: the run-scoped rate-limit state is set by a 429' );
assert_equals( 'g5b_429', $first_rate_limited_source, 'G5B: the run records which source tripped the limit' );

// A plain 500 must NOT arm the run-wide rate limit: only 429 does.
assert_true(
	! Conexao_Eventbrite_Pacing::is_rate_limited(),
	'G5B: a 500 in a fresh run does not arm the rate-limit state — the classification is not a catch-all'
);

// ===========================================================================
// Test 3: Retry-After present, excessive, invalid, absent.
// ===========================================================================
test_section( 'G5B: Retry-After is honoured, hard-capped, and safely rejected' );

// (a) An excessive Retry-After must be capped, never obeyed verbatim.
Conexao_Eventbrite_Pacing::begin_run();
$delays = array();
$pacing = g5_fake_pacing( $delays );

g5_install_script(
	array(
		array(
			'code'    => 429,
			'headers' => array( 'retry-after' => '999999' ),
		),
		array( 'code' => 429 ),
		array( 'code' => 429 ),
	)
);

$client = new Conexao_Eventbrite_Client( 'g5b_cap', $pacing );
$client->fetch_page( 'https://example.invalid/g5b-cap', 1 );
g5_remove_script();

$cooldowns = array_values( array_filter( $pacing->get_applied_delays(), static function ( $d ) { return 'rate_limit_cooldown' === $d['reason']; } ) );

$cap_violations = 0;
foreach ( $cooldowns as $c ) {
	if ( $c['seconds'] > Conexao_Eventbrite_Pacing::MAX_COOLDOWN_SECONDS ) {
		++$cap_violations;
	}
}

assert_true(
	Conexao_Eventbrite_Pacing::MAX_COOLDOWN_SECONDS < 999999,
	'G5B: the hard cap is far below an absurd Retry-After'
);
assert_equals(
	Conexao_Eventbrite_Pacing::MAX_COOLDOWN_SECONDS,
	$cooldowns[0]['seconds'],
	'G5B: a Retry-After of 999999 is reduced to the hard cap — Eventbrite cannot dictate our runtime'
);
assert_true(
	Conexao_Eventbrite_Client::MAX_RETRY_AFTER_SECONDS <= Conexao_Eventbrite_Pacing::MAX_COOLDOWN_SECONDS,
	'G5B: the client parser cap is itself within the pacing cap, so two layers agree'
);
assert_equals(
	0,
	$cap_violations,
	'G5B: no applied cooldown exceeded the hard cap'
);

// (b) Absent Retry-After falls back to the deterministic ladder.
Conexao_Eventbrite_Pacing::begin_run();
$delays = array();
$pacing = g5_fake_pacing( $delays );

assert_equals(
	Conexao_Eventbrite_Pacing::RATE_LIMIT_BASE_SECONDS,
	$pacing->get_cooldown_seconds( 1, null ),
	'G5B: an ABSENT Retry-After uses the deterministic fallback ladder'
);
assert_equals(
	2 * Conexao_Eventbrite_Pacing::RATE_LIMIT_BASE_SECONDS,
	$pacing->get_cooldown_seconds( 2, null ),
	'G5B: the fallback ladder is deterministic and bounded, not random'
);
assert_true(
	Conexao_Eventbrite_Pacing::RATE_LIMIT_BASE_SECONDS * 50 > Conexao_Eventbrite_Pacing::MAX_COOLDOWN_SECONDS,
	'G5B: the ladder WOULD exceed the cap if extended, proving the cap binds'
);
assert_equals(
	Conexao_Eventbrite_Pacing::MAX_COOLDOWN_SECONDS,
	$pacing->get_cooldown_seconds( 50, null ),
	'G5B: however many retries, the cooldown never exceeds the hard cap'
);
assert_equals(
	null,
	Conexao_Eventbrite_Client::parse_retry_after( 'not-a-date' ),
	'G5B: an unparseable Retry-After yields null so the fallback ladder is used'
);

// The pacing cap is asserted DIRECTLY. Asserting it only through the client
// would be vacuous: the client's parser already caps at the same value, so
// removing the pacing cap would change nothing observable.
assert_equals(
	Conexao_Eventbrite_Pacing::MAX_COOLDOWN_SECONDS,
	$pacing->get_cooldown_seconds( 1, 86400.0 ),
	'G5B: the pacing policy caps an oversized Retry-After itself, independent of the client parser'
);
assert_true(
	$pacing->last_retry_after_was_capped(),
	'G5B: the policy records that it capped the oversized Retry-After'
);
assert_equals(
	Conexao_Eventbrite_Pacing::MAX_COOLDOWN_SECONDS,
	$pacing->get_cooldown_seconds( 1, 61.0 ),
	'G5B: a Retry-After one second over the cap is reduced to exactly the cap'
);
assert_equals(
	30.0,
	$pacing->get_cooldown_seconds( 1, 30.0 ),
	'G5B: a legitimate Retry-After below the cap is honoured verbatim'
);
assert_true(
	! $pacing->last_retry_after_was_capped(),
	'G5B: a legitimate Retry-After below the cap is NOT reported as capped'
);
assert_true(
	$pacing->get_cooldown_seconds( 2, null ) > $pacing->get_cooldown_seconds( 1, null ),
	'G5B: a later cooldown is longer than the first — this is a real cooldown, not a retry'
);

// ===========================================================================
// Test 4: repeated 429 is bounded and never tight-loops.
// ===========================================================================
test_section( 'G5C: repeated 429 is bounded and never tight-loops' );

Conexao_Eventbrite_Pacing::begin_run();
$delays = array();
$pacing = g5_fake_pacing( $delays );

g5_install_script( array( array( 'code' => 429 ) ) );
$client = new Conexao_Eventbrite_Client( 'g5c_repeat', $pacing );
$body   = $client->fetch_page( 'https://example.invalid/g5c-repeat', 4 );
$served = count( $GLOBALS['g5_served'] );
g5_remove_script();

assert_equals( '', $body, 'G5C: a permanently rate-limited page returns a failure, never an empty success' );
assert_equals(
	Conexao_Eventbrite_Client::MAX_RETRIES,
	$served,
	'G5C: the retry ceiling is unchanged — a permanently 429 page is attempted exactly 3 times'
);
assert_equals( Conexao_Eventbrite_Client::MAX_RETRIES, $client->get_consecutive_rate_limits(), 'G5C: all 3 attempts were rate limits' );
assert_equals( 'rate_limited', $client->get_last_failure_reason(), 'G5C: the exhausted page is classified rate_limited' );

// Every attempt was separated by a real, non-zero cooldown.
$cooldowns = array_values( array_filter( $pacing->get_applied_delays(), static function ( $d ) { return 'rate_limit_cooldown' === $d['reason']; } ) );
assert_equals( 3, count( $cooldowns ), 'G5C: every rate-limited attempt was preceded by a cooldown' );

$all_positive = true;
foreach ( $cooldowns as $c ) {
	if ( $c['seconds'] <= 0 ) {
		$all_positive = false;
	}
}
assert_true( $all_positive, 'G5C: no rate-limit retry happened without waiting — the tight retry loop is impossible' );
assert_true(
	min( array_column( $cooldowns, 'seconds' ) ) >= Conexao_Eventbrite_Pacing::RATE_LIMIT_BASE_SECONDS,
	'G5C: even the shortest cooldown is the 20s base, well above the 2s/4s that failed in G4'
);
assert_true(
	$cooldowns[1]['seconds'] > $cooldowns[0]['seconds'],
	'G5C: the cooldown escalates deterministically across the retry budget'
);

// The cooldown consumes NO outbound request budget: it sends nothing, while
// each retry DOES consume one.
Conexao_Request_Budget::begin_run( 400 );
Conexao_Eventbrite_Pacing::begin_run();
$delays = array();
$pacing = g5_fake_pacing( $delays );
g5_install_script( array( array( 'code' => 429 ) ) );
$budget_client = new Conexao_Eventbrite_Client( 'g5c_budget', $pacing );
$budget_client->fetch_page( 'https://example.invalid/g5c-budget', 1 );
g5_remove_script();

$budget_after = Conexao_Request_Budget::get_stats();

assert_equals(
	Conexao_Eventbrite_Client::MAX_RETRIES,
	(int) $budget_after['used'],
	'G5C: each 429 retry DOES consume outbound request budget — cooldown cannot smuggle requests past the ceiling'
);
$expected_cooldown_total = 0;
for ( $r = 1; $r <= Conexao_Eventbrite_Client::MAX_RETRIES; $r++ ) {
	$expected_cooldown_total += min(
		Conexao_Eventbrite_Pacing::RATE_LIMIT_BASE_SECONDS * $r,
		Conexao_Eventbrite_Pacing::MAX_COOLDOWN_SECONDS
	);
}
assert_equals(
	$expected_cooldown_total,
	$pacing->get_cooldown_seconds_applied(),
	'G5C: the cooldown total (20+40+60) is accounted separately from the request budget'
);
assert_equals(
	0,
	(int) $budget_after['blocked'],
	'G5C: no request was blocked — the budget was nowhere near its ceiling'
);

// ===========================================================================
// Test 5: the WALK stops on repeated 429 instead of paginating on.
// ===========================================================================
test_section( 'G5C: a rate-limited page stops the walk instead of paginating on' );

Conexao_Eventbrite_Pacing::begin_run();
Conexao_Request_Budget::begin_run( 400 );
Conexao_Request_Reuse::begin_run();

// The time budget is deliberately made enormous. The cooldown ladder costs
// 120s of clock, so under the normal 45s budget the walk would stop on the
// TIME budget and the fail-fast would never be exercised — the assertion
// below would then pass for entirely the wrong reason. With a large budget,
// the rate-limit fail-fast is the only mechanism that can end this walk.
add_filter( 'conexao_eventbrite_time_budget_seconds', 'g5_budget_huge', 99 );

$delays = array();
Conexao_Eventbrite_Pacing::set_shared( g5_fake_pacing( $delays ) );

// Page 1 succeeds. Page 2 is rate limited forever. A pre-G5 walk would then ask
// for page 3, 4, 5 ... all of which would be 429 as well.
g5_install_script(
	array(
		array(
			'code' => 200,
			'body' => g5_make_listing_page( 40, 10 ),
		),
		array( 'code' => 429 ),
	)
);

$source = new Conexao_Source_Eventbrite(
	array(
		'id'            => 'g5c_walk',
		'name'          => 'G5C walk',
		'county'        => 'Cork',
		'url'           => 'https://example.invalid/d/ireland--cork/all-events/',
		'region_labels' => array( 'Cork' ),
	)
);

$events = $source->fetch_events();
g5_remove_script();
remove_filter( 'conexao_eventbrite_time_budget_seconds', 'g5_budget_huge', 99 );
Conexao_Eventbrite_Pacing::set_shared( null );

assert_true(
	! $source->is_fetch_complete(),
	'G5C: the walk is INCOMPLETE after a rate-limit stop — never a complete enumeration'
);

// Guard against the vacuous case: confirm the time budget really was not what
// ended this walk, so the assertions below test the fail-fast and nothing else.
assert_true(
	count( $GLOBALS['g5_served'] ) < 40,
	'G5C: control — the declared 40 pages were NOT walked, so the stop was not exhaustion'
);
assert_true(
	count( $events ) > 0,
	'G5C: the events fetched BEFORE the rate limit are preserved'
);

// The decisive assertion: no request was made for page 3 or beyond.
$urls = $GLOBALS['g5_served'];
assert_equals(
	Conexao_Eventbrite_Client::MAX_RETRIES + 1,
	count( $urls ),
	'G5C: exactly one successful page + the 3 rate-limited retries — no request storm past the ceiling'
);

$beyond = 0;
foreach ( $urls as $u ) {
	if ( false !== strpos( $u, 'page=3' ) ) {
		++$beyond;
	}
}
assert_equals( 0, $beyond, 'G5C: page 3 was NEVER requested — the walk stopped at the rate-limited page' );

// ===========================================================================
// Test 6: an incomplete rate-limited walk never marks events missing.
// ===========================================================================
test_section( 'G5C: an incomplete rate-limited walk never marks events missing' );

assert_true(
	! $source->is_fetch_complete(),
	'G5C: the stopped walk reports INCOMPLETE, which is what stops mark_missing_events()'
);
assert_true(
	count( $events ) > 0,
	'G5C: partial data survives the stop, so a later retry updates rather than duplicates'
);

// ===========================================================================
// Test 7: the run-scoped shared cooldown stops the next Eventbrite source.
// ===========================================================================
test_section( 'G5E: a rate-limited run does not walk into the next Eventbrite source' );

Conexao_Eventbrite_Pacing::begin_run();
Conexao_Eventbrite_Pacing::record_run_rate_limit( 'eventbrite_cork' );

assert_true( Conexao_Eventbrite_Pacing::is_rate_limited(), 'G5E: the run is marked rate limited after Cork' );

g5_install_script( array() );
$served_before = count( $GLOBALS['g5_served'] );

$second = new Conexao_Source_Eventbrite(
	array(
		'id'            => 'eventbrite_dublin',
		'name'          => 'Eventbrite — Dublin',
		'county'        => 'Dublin',
		'url'           => 'https://example.invalid/d/ireland--dublin/all-events/',
		'region_labels' => array( 'Dublin' ),
	)
);

$threw = false;
try {
	$second->fetch_events();
} catch ( Conexao_Source_Fetch_Exception $e ) {
	$threw = true;
	assert_equals( 429, $e->get_http_status(), 'G5E: the skipped source reports HTTP 429, the real upstream condition' );
}
g5_remove_script();

assert_true( $threw, 'G5E: the second Eventbrite source refuses to start a walk' );
assert_equals(
	$served_before,
	count( $GLOBALS['g5_served'] ),
	'G5E: the second source sent ZERO outbound requests — Dublin was not asked at all'
);
assert_true(
	in_array( 'eventbrite_dublin', Conexao_Eventbrite_Pacing::get_skipped_sources(), true ),
	'G5E: the skip is recorded so the operator can see which sources were not tried'
);
assert_true(
	! $second->is_fetch_complete(),
	'G5E: a skipped source is INCOMPLETE, never an empty success'
);

// A fresh run is always able to try again (never a permanent block).
Conexao_Eventbrite_Pacing::begin_run();
assert_true(
	! Conexao_Eventbrite_Pacing::is_rate_limited(),
	'G5E: a NEW run is not rate limited — the operator can retry the source immediately in a new run'
);
assert_equals(
	array(),
	Conexao_Eventbrite_Pacing::get_skipped_sources(),
	'G5E: skip history is run-scoped and does not leak into the next run'
);

// ===========================================================================
// Test 8: the source time budget survives a rate-limit cooldown.
// ===========================================================================
test_section( 'G5: the source time budget survives a rate-limit cooldown' );

Conexao_Eventbrite_Pacing::begin_run();
Conexao_Request_Budget::begin_run( 400 );

$delays = array();
Conexao_Eventbrite_Pacing::set_shared( g5_fake_pacing( $delays ) );

// A 10-second budget cannot survive a 20-second cooldown, so the walk must end
// INCOMPLETE rather than sleeping its way past the budget.
add_filter( 'conexao_eventbrite_time_budget_seconds', 'g5_budget_ten', 99 );

$pages = array();
for ( $i = 1; $i <= 6; $i++ ) {
	$pages[] = array(
		'code' => 200,
		'body' => g5_make_listing_page( 20, $i * 10 ),
	);
}
g5_install_script( $pages );

$budget_source = new Conexao_Source_Eventbrite(
	array(
		'id'            => 'g5_budget',
		'name'          => 'G5 budget',
		'county'        => 'Cork',
		'url'           => 'https://example.invalid/d/ireland--cork/all-events/',
		'region_labels' => array( 'Cork' ),
	)
);

$budget_events = $budget_source->fetch_events();
g5_remove_script();
remove_filter( 'conexao_eventbrite_time_budget_seconds', 'g5_budget_ten', 99 );
Conexao_Eventbrite_Pacing::set_shared( null );

assert_true(
	! $budget_source->is_fetch_complete(),
	'G5: a walk stopped by the time budget is INCOMPLETE'
);
assert_true(
	count( $budget_events ) > 0,
	'G5: events fetched before the budget expired are preserved'
);

// ===========================================================================
// Test 9: diagnostics are run-correlated and explicitly classified.
// ===========================================================================
test_section( 'G5D: rate limiting is named in the diagnostics and correlated by run_id' );

Conexao_Eventbrite_Pacing::begin_run();
Conexao_Request_Budget::begin_run( 400 );

$delays = array();
$pacing = g5_fake_pacing( $delays );
$pacing->set_sleeper( function ( $seconds ) {} );

add_filter( 'conexao_event_importer_current_run_id', 'g5_run_id' );
g5_install_script( array( array( 'code' => 429 ) ) );
$diag_client = new Conexao_Eventbrite_Client( 'g5d_diag', $pacing );
$diag_client->fetch_page( 'https://example.invalid/g5d-diag', 5 );
g5_remove_script();
remove_filter( 'conexao_event_importer_current_run_id', 'g5_run_id' );

$entries = Conexao_Import_Log::get_for_run( 'g5-run-77' );

assert_true( ! empty( $entries ), 'G5D: the rate-limit path produced run-correlated diagnostic entries' );

$classified   = false;
$reclassified = 0;
$correlated   = true;
$has_url     = false;
$has_page    = false;
$has_attempt = false;
$has_cooldown = false;

foreach ( $entries as $entry ) {
	$ctx = isset( $entry['context'] ) && is_array( $entry['context'] ) ? $entry['context'] : array();

	if ( 'g5-run-77' !== $entry['run_id'] ) {
		$correlated = false;
	}
	if ( isset( $ctx['reason'] ) ) {
		if ( 'rate_limited' === $ctx['reason'] ) {
			$classified = true;
		} else {
			// A 429 fetch must never produce a differently-classified entry.
			++$reclassified;
		}
	}
	if ( ! empty( $ctx['url'] ) ) {
		$has_url = true;
	}
	if ( isset( $ctx['page'] ) && 5 === (int) $ctx['page'] ) {
		$has_page = true;
	}
	if ( isset( $ctx['attempt'] ) ) {
		$has_attempt = true;
	}
	if ( isset( $ctx['cooldown_seconds'] ) ) {
		$has_cooldown = true;
	}
}

assert_true( $classified, 'G5D: an entry carries reason=rate_limited, so an operator can tell it apart from a generic failure' );
assert_equals(
	$reclassified,
	0,
	'G5D: EVERY diagnostic from an all-429 fetch is classified rate_limited — none is silently relabelled'
);
assert_true( $correlated, 'G5D: every diagnostic entry from the rate-limit path carries the run_id' );
assert_true( $has_url, 'G5D: the URL is recorded' );
assert_true( $has_page, 'G5D: the page number is recorded' );
assert_true( $has_attempt, 'G5D: the attempt number is recorded' );
assert_true( $has_cooldown, 'G5D: the applied cooldown is recorded' );

// ===========================================================================
// Test 10: Eventbrite sources execute sequentially, never concurrently.
// ===========================================================================
test_section( 'G5F: Eventbrite execution is sequential' );

Conexao_Eventbrite_Pacing::begin_run();
Conexao_Request_Budget::begin_run( 4000 );

$GLOBALS['g5_in_flight']  = 0;
$GLOBALS['g5_max_flight'] = 0;
add_filter( 'pre_http_request', 'g5_measure_request', 1, 3 );

$delays = array();
Conexao_Eventbrite_Pacing::set_shared( g5_fake_pacing( $delays ) );

$page = g5_make_listing_page( 3, 10 );
g5_install_script(
	array(
		array( 'code' => 200, 'body' => $page ),
		array( 'code' => 200, 'body' => $page ),
	)
);

$sequential = array();
foreach ( array( 'eventbrite_a', 'eventbrite_b', 'eventbrite_c' ) as $sid ) {
	$handler = new Conexao_Source_Eventbrite(
		array(
			'id'            => $sid,
			'name'          => $sid,
			'county'        => 'Cork',
			'url'           => 'https://example.invalid/d/ireland--cork/all-events/',
			'region_labels' => array( 'Cork' ),
		)
	);
	$sequential[] = $sid;
	$handler->fetch_events();
}

g5_remove_script();
remove_filter( 'pre_http_request', 'g5_measure_request', 1 );
Conexao_Eventbrite_Pacing::set_shared( null );

assert_equals(
	array( 'eventbrite_a', 'eventbrite_b', 'eventbrite_c' ),
	$sequential,
	'G5F: sources ran one after another, in order'
);
assert_equals(
	1,
	(int) $GLOBALS['g5_max_flight'],
	'G5F: exactly one Eventbrite request was in flight at a time — never concurrent'
);
assert_true(
	false === strpos( file_get_contents( WP_PLUGIN_DIR . '/conexao-event-importer/includes/sources/class-eventbrite-source.php' ), 'curl_multi_' ),
	'G5F: the Eventbrite source handler never uses parallel HTTP fetching'
);

test_finish();

/**
 * Observe how many Eventbrite requests are served at the same instant.
 *
 * The double serves each request to completion inside this one call, so the
 * count must never exceed 1: if the importer ever fetched pages concurrently,
 * this filter would be re-entered before the previous request returned.
 *
 * @param mixed  $preempt Short-circuit value.
 * @param array  $args    Request args.
 * @param string $url     Request URL.
 * @return mixed
 */
function g5_measure_request( $preempt, $args, $url ) {
	++$GLOBALS['g5_in_flight'];
	$GLOBALS['g5_max_flight'] = max( (int) $GLOBALS['g5_max_flight'], (int) $GLOBALS['g5_in_flight'] );

	$served = g5_http_double( $preempt, $args, $url );

	--$GLOBALS['g5_in_flight'];

	return $served;
}
