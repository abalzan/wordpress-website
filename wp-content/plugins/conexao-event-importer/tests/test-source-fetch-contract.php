<?php
/**
 * Stage 2 / P3 + P4 — the explicit source-handler fetch contract and the
 * incomplete-enumeration gate on every remaining active handler.
 *
 * ## The contract (P3)
 *
 *   upstream failure  ->  throw Conexao_Source_Fetch_Exception
 *   successful listing (empty or not) -> return array()
 *   retrieved but unparsable          -> return array() AND report the
 *                                        enumeration as INCOMPLETE
 *
 * A handler that returns `array()` on a failed request makes a dead source
 * indistinguishable from an empty one: the engine records `found=0,
 * status=success` and `mark_missing_events()` is allowed to treat the whole
 * stored set as absent.
 *
 * ## What was already covered (not duplicated here)
 *
 * Eventbrite and Heritage Week already implement the contract and are proven
 * by `test-source-fetch-failure.php`, `test-source-pagination-completeness.php`,
 * `test-eventbrite-checkpoint.php` and `test-stage1-run-coordination.php`. This
 * suite covers the handlers those tests do not:
 *
 *   - Mondello Park      (returned [] on transport error AND on non-200)
 *   - Laois Tourism      (returned [] on an empty/unparsable listing)
 *   - IVVCC              (returned [] on an empty body)
 *   - Motorsport Ireland (returned [] on an empty body)
 *   - iCalendar          (returned [] with no feed configured / no data)
 *
 * Each case asserts BOTH halves of the interaction: the fetch result type
 * (exception vs array) and the gate value (`is_fetch_complete()`), because a
 * handler that fails P3 cannot implement P4 correctly.
 *
 * No live provider is contacted: every request goes through the
 * `pre_http_request` double, and the test bootstrap blocks external HTTP.
 *
 * @package Conexao_Event_Importer
 */

// Shared test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

test_require_operator_store_isolation();

$restore_options = test_options_isolate(
	array(
		Conexao_Import_Continuation::OPTION_KEY,
		Conexao_Event_Sources::OPTION_KEY,
	)
);

register_shutdown_function(
	static function () use ( $restore_options ) {
		test_options_restore( $restore_options, true );
	}
);

$GLOBALS['s2c_responses'] = array();
$GLOBALS['s2c_requests']  = array();
$GLOBALS['s2c_double_on'] = false;

add_filter( 'pre_http_request', 's2c_http_double', 10, 3 );

/**
 * The `pre_http_request` filter callback (exact-URL matching).
 *
 * @param false|array|WP_Error $preempt Short-circuit value.
 * @param array                $args    Request args.
 * @param string               $url     Request URL.
 * @return array|WP_Error
 */
function s2c_http_double( $preempt, $args, $url ) {
	if ( empty( $GLOBALS['s2c_double_on'] ) ) {
		return $preempt;
	}

	$GLOBALS['s2c_requests'][] = $url;

	if ( ! isset( $GLOBALS['s2c_responses'][ $url ] ) ) {
		return new WP_Error( 's2c_unmocked_url', 'No HTTP double registered for ' . $url );
	}

	$response = $GLOBALS['s2c_responses'][ $url ];

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

/**
 * Install the HTTP double for a map of exact URL => response.
 *
 * @param array $responses URL => array( code, body ) or WP_Error.
 * @return void
 */
function s2c_install_http( array $responses ) {
	$GLOBALS['s2c_responses'] = $responses;
	$GLOBALS['s2c_requests']  = array();
	$GLOBALS['s2c_double_on'] = true;

	Conexao_Request_Reuse::begin_run();
	Conexao_Request_Budget::begin_run( 50 );
}

/**
 * Remove the HTTP double.
 *
 * @return void
 */
function s2c_remove_http() {
	$GLOBALS['s2c_double_on'] = false;
}

/**
 * Shorthand for a successful (HTTP 200) double response.
 *
 * @param string $body Response body.
 * @return array
 */
function s2c_ok( $body ) {
	return array( 'code' => 200, 'body' => $body );
}

/**
 * Shorthand for a failing double response.
 *
 * @param int $code HTTP status code.
 * @return array
 */
function s2c_status( $code ) {
	return array( 'code' => (int) $code, 'body' => '' );
}

/**
 * Run a callable and return the Conexao_Source_Fetch_Exception it threw.
 *
 * @param callable $callable Callable expected to throw.
 * @return Conexao_Source_Fetch_Exception|null
 */
function s2c_catch_fetch_exception( $callable ) {
	try {
		$callable();
	} catch ( Conexao_Source_Fetch_Exception $e ) {
		return $e;
	}

	return null;
}

test_title( 'Stage 2 — source fetch contract (P3) and handler completeness gates (P4)' );

// ===========================================================================
// Mondello Park — the handler that returned [] on transport AND non-200.
// ===========================================================================
test_section( 'P3: Mondello Park throws on transport/HTTP failure and reports incomplete on an empty payload' );

$mondello_url = 'https://mondello.test/wp-json/wp/v2/event';

/**
 * Build a Mondello Park handler bound to the probe URL.
 *
 * @return Conexao_Source_Mondello_Park
 */
function s2c_mondello() {
	return new Conexao_Source_Mondello_Park(
		array(
			'id'  => 'mondello_park',
			'url' => 'https://mondello.test/wp-json/wp/v2/event',
		)
	);
}

// A1 — transport failure (no HTTP response at all).
s2c_install_http( array( $mondello_url => new WP_Error( 's2c_down', 'connection refused' ) ) );

$e = s2c_catch_fetch_exception(
	static function () {
		s2c_mondello()->fetch_events();
	}
);
s2c_remove_http();

assert_true( $e instanceof Conexao_Source_Fetch_Exception, 'P3 Mondello: a transport failure THROWS instead of returning an empty array' );
assert_equals( 0, null !== $e ? $e->get_http_status() : -1, 'P3 Mondello: the transport failure carries HTTP status 0' );

// A2 — HTTP failure.
s2c_install_http( array( $mondello_url => s2c_status( 503 ) ) );

$e = s2c_catch_fetch_exception(
	static function () {
		s2c_mondello()->fetch_events();
	}
);
s2c_remove_http();

assert_true( $e instanceof Conexao_Source_Fetch_Exception, 'P3 Mondello: HTTP 503 THROWS instead of returning an empty array' );
assert_equals( 503, null !== $e ? $e->get_http_status() : -1, 'P3 Mondello: the HTTP status is preserved on the exception' );

// A3 — retrieved but empty body: not an enumerable listing.
s2c_install_http( array( $mondello_url => s2c_ok( '' ) ) );

$mondello_empty  = s2c_mondello();
$mondello_events = $mondello_empty->fetch_events();
s2c_remove_http();

assert_equals( array(), $mondello_events, 'P3 Mondello: an empty response body yields no events' );
assert_true( ! $mondello_empty->is_fetch_complete(), 'P4 Mondello: an empty body is NOT a complete empty walk' );

// A4 — retrieved but not JSON.
s2c_install_http( array( $mondello_url => s2c_ok( '<html>not json</html>' ) ) );

$mondello_bad_json = s2c_mondello();
$mondello_events   = $mondello_bad_json->fetch_events();
s2c_remove_http();

assert_equals( array(), $mondello_events, 'P3 Mondello: an unparsable payload yields no events' );
assert_true( ! $mondello_bad_json->is_fetch_complete(), 'P4 Mondello: an unparsable payload is NOT a complete empty walk' );

// A5 — successfully retrieved, genuinely empty listing: complete, zero events.
s2c_install_http( array( $mondello_url => s2c_ok( '[]' ) ) );

$mondello_genuine = s2c_mondello();
$mondello_events  = $mondello_genuine->fetch_events();
s2c_remove_http();

assert_equals( array(), $mondello_events, 'P3 Mondello: a successfully retrieved empty listing returns no events' );
assert_true( $mondello_genuine->is_fetch_complete(), 'P4 Mondello: a genuinely empty listing is COMPLETE' );


// ===========================================================================
// Laois Tourism — returned [] on an empty/unparsable listing.
// ===========================================================================
test_section( 'P3/P4: Laois Tourism — failure throws, unparsable is incomplete, empty listing is complete' );

$laois_url = 'https://laois.test/events/';

/**
 * Build a Laois Tourism handler bound to the probe URL.
 *
 * @return Conexao_Source_Laois_Tourism
 */
function s2c_laois() {
	return new Conexao_Source_Laois_Tourism(
		array(
			'id'  => 'laois_tourism',
			'url' => 'https://laois.test/events/',
		)
	);
}

s2c_install_http( array( $laois_url => s2c_status( 403 ) ) );

$e = s2c_catch_fetch_exception(
	static function () {
		s2c_laois()->fetch_events();
	}
);
s2c_remove_http();

assert_true( $e instanceof Conexao_Source_Fetch_Exception, 'P3 Laois: HTTP 403 THROWS instead of returning an empty array' );
assert_equals( 403, null !== $e ? $e->get_http_status() : -1, 'P3 Laois: the HTTP status is preserved on the exception' );

s2c_install_http( array( $laois_url => s2c_ok( '' ) ) );

$laois_empty = s2c_laois();
$laois_events = $laois_empty->fetch_events();
s2c_remove_http();

assert_equals( array(), $laois_events, 'P3 Laois: an empty body yields no events' );
assert_true( ! $laois_empty->is_fetch_complete(), 'P4 Laois: an empty body is NOT a complete empty walk' );

// A retrieved page with no event containers is a legitimate empty listing.
s2c_install_http( array( $laois_url => s2c_ok( '<html><body><p>No events scheduled.</p></body></html>' ) ) );

$laois_genuine = s2c_laois();
$laois_events  = $laois_genuine->fetch_events();
s2c_remove_http();

assert_equals( array(), $laois_events, 'P3 Laois: a retrieved page with no event cards returns no events' );
assert_true( $laois_genuine->is_fetch_complete(), 'P4 Laois: a retrieved empty listing is COMPLETE' );

// ===========================================================================
// IVVCC — returned [] on an empty body.
// ===========================================================================
test_section( 'P3/P4: IVVCC — failure throws, empty body is incomplete, parsed listing is complete' );

$ivvcc_url = 'https://ivvcc.test/events/';

/**
 * Build an IVVCC handler bound to the probe URL.
 *
 * @return Conexao_Source_Ivvcc
 */
function s2c_ivvcc() {
	return new Conexao_Source_Ivvcc(
		array(
			'id'  => 'ivvcc',
			'url' => 'https://ivvcc.test/events/',
		)
	);
}

s2c_install_http( array( $ivvcc_url => s2c_status( 403 ) ) );

$e = s2c_catch_fetch_exception(
	static function () {
		s2c_ivvcc()->fetch_events();
	}
);
s2c_remove_http();

assert_true( $e instanceof Conexao_Source_Fetch_Exception, 'P3 IVVCC: HTTP 403 THROWS instead of returning an empty array' );

s2c_install_http( array( $ivvcc_url => s2c_ok( '' ) ) );

$ivvcc_empty  = s2c_ivvcc();
$ivvcc_events = $ivvcc_empty->fetch_events();
s2c_remove_http();

assert_equals( array(), $ivvcc_events, 'P3 IVVCC: an empty body yields no events' );
assert_true( ! $ivvcc_empty->is_fetch_complete(), 'P4 IVVCC: an empty body is NOT a complete empty walk' );

s2c_install_http( array( $ivvcc_url => s2c_ok( '<html><body><div class="calendar">No events</div></body></html>' ) ) );

$ivvcc_genuine = s2c_ivvcc();
$ivvcc_events  = $ivvcc_genuine->fetch_events();
s2c_remove_http();

assert_equals( array(), $ivvcc_events, 'P3 IVVCC: a parsed calendar with no cards returns no events' );
assert_true( $ivvcc_genuine->is_fetch_complete(), 'P4 IVVCC: a parsed single-page listing is COMPLETE' );

// ===========================================================================
// Motorsport Ireland — returned [] on an empty body.
// ===========================================================================
test_section( 'P3/P4: Motorsport Ireland — failure throws, empty body is incomplete, parsed listing is complete' );

$mi_url = 'https://motorsport.test/calendar/';

/**
 * Build a Motorsport Ireland handler bound to the probe URL.
 *
 * @return Conexao_Source_Motorsport_Ireland
 */
function s2c_motorsport() {
	return new Conexao_Source_Motorsport_Ireland(
		array(
			'id'  => 'motorsport_ireland',
			'url' => 'https://motorsport.test/calendar/',
		)
	);
}

s2c_install_http( array( $mi_url => s2c_status( 403 ) ) );

$e = s2c_catch_fetch_exception(
	static function () {
		s2c_motorsport()->fetch_events();
	}
);
s2c_remove_http();

assert_true( $e instanceof Conexao_Source_Fetch_Exception, 'P3 Motorsport: HTTP 403 THROWS instead of returning an empty array' );

s2c_install_http( array( $mi_url => s2c_ok( '' ) ) );

$mi_empty  = s2c_motorsport();
$mi_events = $mi_empty->fetch_events();
s2c_remove_http();

assert_equals( array(), $mi_events, 'P3 Motorsport: an empty body yields no events' );
assert_true( ! $mi_empty->is_fetch_complete(), 'P4 Motorsport: an empty body is NOT a complete empty walk' );

s2c_install_http( array( $mi_url => s2c_ok( '<html><body><div class="calendar">No events</div></body></html>' ) ) );

$mi_genuine = s2c_motorsport();
$mi_events  = $mi_genuine->fetch_events();
s2c_remove_http();

assert_equals( array(), $mi_events, 'P3 Motorsport: a parsed calendar with no cards returns no events' );
assert_true( $mi_genuine->is_fetch_complete(), 'P4 Motorsport: a parsed single-page listing is COMPLETE' );


// ===========================================================================
// iCalendar — returned [] with no feed configured / no data.
// ===========================================================================
test_section( 'P3/P4: iCalendar — failure throws, missing/empty feed is incomplete, empty calendar is complete' );

$ics_url = 'https://ical.test/feed.ics';

/**
 * Build an iCalendar handler.
 *
 * @param array $config Extra config.
 * @return Conexao_Source_ICalendar
 */
function s2c_ical( array $config = array() ) {
	return new Conexao_Source_ICalendar(
		array_merge(
			array(
				'id'  => 's2c_ical_' . substr( md5( wp_json_encode( $config ) . microtime( true ) ), 0, 8 ),
				'url' => 'https://ical.test/feed.ics',
			),
			$config
		)
	);
}

// E1 — no feed configured at all.
$ical_none = s2c_ical( array( 'url' => '' ) );
$events    = $ical_none->fetch_events();

assert_equals( array(), $events, 'P3 iCalendar: no feed configured yields no events' );
assert_true( ! $ical_none->is_fetch_complete(), 'P4 iCalendar: a missing feed is NOT a complete empty walk' );

// E2 — HTTP failure.
s2c_install_http( array( $ics_url => s2c_status( 500 ) ) );

$e = s2c_catch_fetch_exception(
	static function () use ( $ics_url ) {
		$handler = new Conexao_Source_ICalendar( array( 'id' => 's2c_ical_500', 'url' => $ics_url ) );
		$handler->fetch_events();
	}
);
s2c_remove_http();

assert_true( $e instanceof Conexao_Source_Fetch_Exception, 'P3 iCalendar: HTTP 500 THROWS instead of returning an empty array' );
assert_equals( 500, null !== $e ? $e->get_http_status() : -1, 'P3 iCalendar: the HTTP status is preserved on the exception' );

// E3 — retrieved but empty body.
s2c_install_http( array( $ics_url => s2c_ok( '' ) ) );

$ical_empty = new Conexao_Source_ICalendar( array( 'id' => 's2c_ical_emptybody', 'url' => $ics_url ) );
$events     = $ical_empty->fetch_events();
s2c_remove_http();

assert_equals( array(), $events, 'P3 iCalendar: an empty feed body yields no events' );
assert_true( ! $ical_empty->is_fetch_complete(), 'P4 iCalendar: an empty feed body is NOT a complete empty walk' );

// E4 — retrieved, parsed, genuinely empty calendar.
s2c_install_http( array( $ics_url => s2c_ok( "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n" ) ) );

$ical_genuine = new Conexao_Source_ICalendar( array( 'id' => 's2c_ical_genuine', 'url' => $ics_url ) );
$events       = $ical_genuine->fetch_events();
s2c_remove_http();

assert_equals( array(), $events, 'P3 iCalendar: a valid calendar without VEVENTs returns no events' );
assert_true( $ical_genuine->is_fetch_complete(), 'P4 iCalendar: a valid empty calendar is COMPLETE' );

// ===========================================================================
// Engine integration — a failed / incomplete iCalendar walk is never a
// successful empty import and never reconciles absence.
// ===========================================================================
test_section( 'P3/P4 engine: failed and incomplete walks are not complete empty imports and hide no event' );

$plugin = Conexao_Event_Importer::instance();
$engine_source_id = 's2c_ical_engine';

$plugin->sources->save(
	array(
		'id'     => $engine_source_id,
		'name'   => 'S2C iCalendar engine probe',
		'url'    => $ics_url,
		'type'   => 'icalendar',
		'status' => 'active',
	)
);

// A live event owned by this source. Its URL is NOT in the (failing) walk, so
// an unsound reconciliation would hide it.
$live_id = wp_insert_post(
	array(
		'post_type'   => 'event',
		'post_status' => 'publish',
		'post_title'  => 'S2C live event that must never be hidden',
	)
);

update_post_meta( $live_id, '_event_source', $engine_source_id );
update_post_meta( $live_id, '_event_source_id', 's2c-live-1' );
update_post_meta( $live_id, '_event_url', 'https://ical.test/e/still-live' );
update_post_meta( $live_id, '_event_date', '2026-12-01' );
Conexao_Event_Status::set_status( $live_id, Conexao_Event_Status::PUBLISHED );

// 403 is a non-retryable HTTP failure and does not record a throttle cooldown.
s2c_install_http( array( $ics_url => s2c_status( 403 ) ) );
$failed_result = $plugin->importer->run_source( $engine_source_id, false );
s2c_remove_http();

assert_equals( 'failed', $failed_result['status'], 'P3 engine: a failed first-page fetch is recorded as a FAILED source' );
assert_true( ! $failed_result['complete'], 'P3 engine: a failed fetch is never recorded as a complete import' );
assert_equals( 0, (int) $failed_result['found'], 'P3 engine: a failed fetch reports found=0 truthfully, with a fatal error' );
assert_true( ! empty( $failed_result['fatal_errors'] ), 'P3 engine: the failed fetch carries a fatal error, not a silent empty success' );
assert_equals(
	Conexao_Event_Status::PUBLISHED,
	get_post_meta( $live_id, '_event_status', true ),
	'P4 engine: a live event is NOT marked source_not_found by a failed fetch'
);

// Retrieved but empty: enumeration incomplete -> still zero reconciliation.
s2c_install_http( array( $ics_url => s2c_ok( '' ) ) );
$incomplete_result = $plugin->importer->run_source( $engine_source_id, false );
s2c_remove_http();

assert_true( ! $incomplete_result['complete'], 'P4 engine: an incomplete enumeration is reported as complete=false' );
assert_equals(
	Conexao_Event_Status::PUBLISHED,
	get_post_meta( $live_id, '_event_status', true ),
	'P4 engine: an incomplete enumeration does NOT mark an absent live event source_not_found'
);

// Cleanup — only this suite's fixtures.
wp_delete_post( $live_id, true );
$plugin->sources->delete( $engine_source_id );

test_options_restore( $restore_options );
$GLOBALS['conexao_test_option_snapshots'] = array();

test_finish( 'stage2-source-fetch-contract' );

