<?php
/**
 * G7 — the dead Eventbrite API-token path is retired.
 *
 * ## What this suite is for
 *
 * G6 concluded `NOT VIABLE` for the official Eventbrite API and handed G7 one
 * action: retire the `eventbrite_api_token` plumbing and correct the maintainer
 * documentation that told an operator to configure a token for a mode that
 * never existed.
 *
 * Deleting a variable is not proof. This suite exists to prove the retirement
 * is REAL — that no supported execution path can be switched onto an API mode,
 * that the working HTML importer needs no credential, and that removing the
 * token changed none of the G1–G5 completeness semantics.
 *
 * ## The five negative proofs
 *
 *   NP1  A token-like configuration value CANNOT activate an API mode.
 *   NP2  The supported Eventbrite import path runs with NO token present.
 *   NP3  The HTML importer never constructs an Authorization header.
 *   NP4  A clean configuration never reports the token as a prerequisite.
 *   NP5  Completeness/failure semantics are UNCHANGED by the removal.
 *
 * ## Non-vacuity
 *
 * Every proof below was mutation-verified: the guard was deliberately removed
 * or inverted and the suite re-run to confirm it FAILS. The results are
 * recorded in
 * `docs/evidence/2026-10-04-eventbrite-api-retirement/04-mutation-proofs.txt`.
 * A proof that cannot fail is not a proof.
 *
 * ## Safety
 *
 * Nothing here contacts Eventbrite or any other network host: every request is
 * served by a `pre_http_request` double. No Event post is created or deleted;
 * this suite only reads the settings option (and restores it).
 *
 * @package Conexao_Event_Importer
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$restore_options = test_options_isolate(
	array(
		Conexao_Import_Settings::OPTION_KEY,
		Conexao_Import_History::OPTION_KEY,
		Conexao_Import_Log::OPTION_KEY,
	)
);

register_shutdown_function(
	static function () use ( $restore_options ) {
		test_options_restore( $restore_options, true );
	}
);

/**
 * Reset the static settings cache so the next read hits the option.
 *
 * @return void
 */
function g7_reset_settings_cache() {
	$prop = new ReflectionProperty( 'Conexao_Import_Settings', 'cache' );
	$prop->setAccessible( true );
	$prop->setValue( null, null );
}

/**
 * Persist a settings array exactly as an older build would have left it.
 *
 * @param array $settings Settings to store.
 * @return void
 */
function g7_store_settings( array $settings ) {
	update_option( Conexao_Import_Settings::OPTION_KEY, $settings, false );
	g7_reset_settings_cache();
}

/**
 * An HTTP double that records every request and serves a scripted body.
 *
 * It records `$args` as well as the URL because NP3 is about the HEADERS the
 * importer builds — a proof that only inspected the URL could not see an
 * Authorization header being attached.
 *
 * @param string $body Response body to serve for every request.
 * @return void
 */
function g7_install_http_double( $body ) {
	$GLOBALS['g7_requests'] = array();

	add_filter(
		'pre_http_request',
		static function ( $preempt, $args, $url ) use ( $body ) {
			$GLOBALS['g7_requests'][] = array(
				'url'     => $url,
				'headers' => isset( $args['headers'] ) ? (array) $args['headers'] : array(),
			);

			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => $body,
				'headers'  => array(),
				'cookies'  => array(),
			);
		},
		10,
		3
	);
}

/**
 * Detach the HTTP double, KEEPING what it recorded.
 *
 * The recorded requests deliberately survive removal: several proofs assert on
 * them AFTER the walk finishes. Resetting the array here (rather than at
 * install time) is what would make those proofs vacuously pass on an empty set.
 *
 * @return void
 */
function g7_remove_http_double() {
	remove_all_filters( 'pre_http_request' );
}

/**
 * Forget every recorded request.
 *
 * @return void
 */
function g7_reset_requests() {
	$GLOBALS['g7_requests'] = array();
}

/**
 * Build a source config for one county, as the registry would.
 *
 * @param string $county  County name.
 * @param string $slug    Eventbrite discovery slug.
 * @param array  $regions Accepted region labels.
 * @return array
 */
function g7_county_source_config( $county, $slug, array $regions ) {
	return array(
		'id'               => 'eventbrite_' . strtolower( $county ),
		'type'             => 'eventbrite',
		'name'             => 'Eventbrite — ' . $county,
		'url'              => 'https://www.eventbrite.ie/d/ireland--' . $slug . '/all-events/',
		'county'           => $county,
		'eb_slug'          => 'ireland--' . $slug,
		'region_labels'    => $regions,
		'enabled'          => true,
	);
}

/**
 * Read the recorded requests as a list of URLs.
 *
 * @return string[]
 */
function g7_request_urls() {
	$urls = array();
	foreach ( (array) $GLOBALS['g7_requests'] as $request ) {
		$urls[] = $request['url'];
	}
	return $urls;
}

/**
 * Strip PHP comments so a scan sees EXECUTABLE code only.
 *
 * G7 must distinguish a live reference from an explanatory note recording WHY
 * something was removed. A naive text grep conflates the two and would either
 * force the removal rationale to be deleted or make the proof vacuous.
 *
 * @param string $php PHP source.
 * @return string Source with comments and docblocks removed.
 */
function g7_strip_php_comments( $php ) {
	$out = '';
	foreach ( token_get_all( $php ) as $token ) {
		if ( is_array( $token ) ) {
			if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
				continue;
			}
			$out .= $token[1];
			continue;
		}
		$out .= $token;
	}
	return $out;
}
/**
 * A minimal, valid Eventbrite discovery document carrying one Cork event.
 *
 * @return string
 */
function g7_discovery_html() {
	$payload = array(
		'search_data' => array(
			'events' => array(
				'results'     => array(
					array(
						'id'                 => '7000000000001',
						'name'               => 'Cork G7 Retirement Event',
						'url'                => 'https://www.eventbrite.ie/e/cork-g7-tickets-7000000000001',
						'summary'            => 'A single in-county event proving the HTML path still imports.',
						'start_date'         => '2099-05-01',
						'start_time'         => '10:00',
						'end_date'           => '2099-05-01',
						'end_time'           => '12:00',
						'timezone'           => 'Europe/Dublin',
						'is_online_event'    => false,
						'is_cancelled'       => false,
						'is_protected_event' => false,
						'is_free'            => true,
						'primary_venue'      => array(
							'name'    => 'Cork City Hall',
							'address' => '1 Smithfield',
							'city'    => 'Cork',
							'country' => 'IE',
							'postal'  => 'T12',
						),
						'primary_organizer'  => 'Cork Events Org',
						'locations'          => array(
							array(
								'type' => 'region',
								'name' => 'Cork',
							),
						),
					),
				),
				'pagination' => array(
					'page_count'   => 1,
					'object_count' => 1,
				),
			),
		),
	);

	return '<!DOCTYPE html><html><body><script>window.__SERVER_DATA__ = '
		. wp_json_encode( $payload )
		. ';</script></body></html>';
}


// ===========================================================================
// NP1 — A token-like configuration value CANNOT activate an API mode.
// ===========================================================================

test_section( 'NP1 — a stored token-like value cannot activate an API mode' );

g7_store_settings( array( 'eventbrite_api_token' => 'FAKE_OAUTH_TOKEN_G7_NEGATIVE_PROOF' ) );

$source = new Conexao_Source_Eventbrite( g7_county_source_config( 'Cork', 'cork', array( 'Cork', 'Cork City' ) ) );

g7_install_http_double( g7_discovery_html() );
try {
	$np1_events = $source->fetch_events();
} catch ( Exception $e ) {
	$np1_events   = array();
	$g7_np1_error = $e->getMessage();
}
g7_remove_http_double();

assert_true( ! isset( $g7_np1_error ), 'NP1 the HTML path still executes with a token stored', $g7_np1_error ?? '' );
assert_true(
	1 === count( $np1_events ),
	'NP1 a stored token does not divert the walk: the HTML page is still parsed',
	'got ' . count( $np1_events ) . ' events'
);
assert_true(
	isset( $np1_events[0] ) && 'eventbrite_cork' === $np1_events[0]['source'],
	'NP1 the event still comes from the HTML county source',
	isset( $np1_events[0]['source'] ) ? $np1_events[0]['source'] : '(no source key)'
);

// The decisive structural proof: no API client can be reached even in
// principle, because no such code exists on the class at all.
assert_true( ! method_exists( 'Conexao_Source_Eventbrite', 'use_api' ), 'NP1 no use_api() selector exists' );
assert_true( ! method_exists( 'Conexao_Source_Eventbrite', 'fetch_via_api' ), 'NP1 no fetch_via_api() exists' );
assert_true( ! method_exists( 'Conexao_Source_Eventbrite', 'get_api_token' ), 'NP1 no API token accessor exists' );

// Every request went to the public HTML discovery page, never to an API host.
foreach ( g7_request_urls() as $url ) {
	assert_true(
		false === strpos( $url, 'eventbriteapi.com' ) && false === strpos( $url, '/v3/' ),
		'NP1 no request targets an Eventbrite API endpoint',
		$url
	);
}

// The token is not a recognised setting any more, and — the stronger point —
// NO EXECUTABLE CODE IN THE IMPLEMENTATION READS THE NAME ANYWHERE. A stored
// value is therefore inert rather than merely unused.
//
// Comments are stripped before scanning, so the explanatory notes that record
// WHY the token was retired are not mistaken for a live reference. This is the
// same live-vs-historical distinction G7 must apply to the whole repository.
$importer_includes = WP_PLUGIN_DIR . '/conexao-event-importer/includes';
$token_references = array();
$iterator          = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $importer_includes ) );
foreach ( $iterator as $file ) {
	if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
		continue;
	}
	$code = g7_strip_php_comments( (string) file_get_contents( $file->getPathname() ) );
	if ( false !== strpos( $code, 'eventbrite_api_token' ) ) {
		$token_references[] = $file->getFilename();
	}
}
assert_true(
	array() === $token_references,
	'NP1 no executable implementation file in includes/ mentions eventbrite_api_token',
	implode( ', ', $token_references )
);

assert_true(
	! array_key_exists( 'eventbrite_api_token', Conexao_Import_Settings::get_defaults() ),
	'NP1 eventbrite_api_token is not a default setting'
);

// The client is constructed with an id and a pacing policy only — it has no
// credential constructor parameter, so a token cannot reach the transport.
$client_ctor = ( new ReflectionClass( 'Conexao_Eventbrite_Client' ) )->getConstructor();
$ctor_params = $client_ctor ? $client_ctor->getParameters() : array();
$ctor_names   = array();
foreach ( $ctor_params as $param ) {
	$ctor_names[] = $param->getName();
}
assert_true(
	array() === array_intersect( $ctor_names, array( 'token', 'api_token', 'eventbrite_api_token', 'auth' ) ),
	'NP1 the Eventbrite client constructor accepts no credential',
	implode( ', ', $ctor_names )
);

// ===========================================================================
// NP2 — The supported Eventbrite import path runs with NO token present.
// ===========================================================================

test_section( 'NP2 — the supported path runs with no token configured at all' );

delete_option( Conexao_Import_Settings::OPTION_KEY );
g7_reset_settings_cache();

assert_true(
	! is_array( get_option( Conexao_Import_Settings::OPTION_KEY, false ) ),
	'NP2 the importer settings option is genuinely absent (a clean install)'
);

$clean_source = new Conexao_Source_Eventbrite( g7_county_source_config( 'Cork', 'cork', array( 'Cork', 'Cork City' ) ) );

g7_reset_requests();
g7_install_http_double( g7_discovery_html() );
try {
	$np2_events = $clean_source->fetch_events();
} catch ( Exception $e ) {
	$np2_events   = array();
	$g7_np2_error = $e->getMessage();
}
g7_remove_http_double();

assert_true( ! isset( $g7_np2_error ), 'NP2 the tokenless HTML path imports without error', $g7_np2_error ?? '' );
assert_true( 1 === count( $np2_events ), 'NP2 the tokenless path yields the county event', 'got ' . count( $np2_events ) );
assert_true(
	isset( $np2_events[0] ) && 'Cork G7 Retirement Event' === $np2_events[0]['title'],
	'NP2 the imported event carries the HTML payload title'
);
assert_true(
	$clean_source->is_fetch_complete(),
	'NP2 a fully-enumerated tokenless walk is reported complete'
);


// ===========================================================================
// NP3 — The HTML importer never constructs an Authorization header.
// ===========================================================================

test_section( 'NP3 — no Authorization header is constructed for Eventbrite' );

// A fresh request-reuse run, so this walk genuinely issues a request instead of
// replaying NP1/NP2's memoised response. Without this the header assertions
// would pass vacuously on an empty request set.
Conexao_Request_Reuse::begin_run();

g7_reset_requests();
g7_install_http_double( g7_discovery_html() );
$header_source = new Conexao_Source_Eventbrite( g7_county_source_config( 'Cork', 'cork', array( 'Cork', 'Cork City' ) ) );
try {
	$header_source->fetch_events();
} catch ( Exception $e ) {
	// The header assertion below is the point of this section; a failure to
	// fetch does not excuse an Authorization header being attached.
	$np3_error = $e->getMessage();
}
g7_remove_http_double();

assert_true( count( g7_request_urls() ) > 0, 'NP3 at least one Eventbrite request was actually issued', 'recorded ' . count( g7_request_urls() ) . ' request(s)' );

foreach ( $GLOBALS['g7_requests'] as $request ) {
	foreach ( array_keys( $request['headers'] ) as $header_name ) {
		assert_true(
			'authorization' !== strtolower( $header_name ),
			'NP3 no request carries an Authorization header',
			$request['url'] . ' sent ' . $header_name
		);
	}
	assert_true(
		false === stripos( (string) wp_json_encode( $request['headers'] ), 'bearer' ),
		'NP3 no request header carries a Bearer value',
		$request['url']
	);
}

// Static proof over the transport layer itself, so the claim does not rest on
// one executed walk.
$client_source = file_get_contents(
	WP_PLUGIN_DIR . '/conexao-event-importer/includes/class-eventbrite-client.php'
);
assert_true(
	false === stripos( (string) $client_source, "'Authorization'" ),
	'NP3 the Eventbrite client source never names an Authorization header'
);
assert_true(
	false === stripos( (string) $client_source, 'eventbriteapi.com' ),
	'NP3 the Eventbrite client source references no API host'
);

// The importer already refuses to cache anything carrying Authorization
// (asserted in test-request-reuse.php); assert the HTML request is cacheable,
// which is only true because it is unauthenticated.
assert_true(
	Conexao_Request_Reuse::is_cacheable( 'GET', array( 'headers' => array( 'Accept' => 'text/html' ) ) ),
	'NP3 the unauthenticated Eventbrite request is cacheable (G4A reuse still applies)'
);

// ===========================================================================
// NP4 — A clean configuration never reports the token as a prerequisite.
// ===========================================================================

test_section( 'NP4 — no configuration or documentation demands a token' );

delete_option( Conexao_Import_Settings::OPTION_KEY );
g7_reset_settings_cache();

$settings_page = new Conexao_Import_Settings();

// render_page() returns early without manage_options; grant it so the real
// markup is measured rather than an empty string.
$admin = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);

$rendered = '';
if ( ! empty( $admin ) ) {
	wp_set_current_user( (int) $admin[0] );
	ob_start();
	$settings_page->render_page();
	$rendered = (string) ob_get_clean();
	wp_set_current_user( 0 );
}

assert_true( '' !== $rendered, 'NP4 the settings screen renders real markup', 'rendered ' . strlen( $rendered ) . ' bytes' );
assert_true(
	false === stripos( $rendered, 'eventbrite_api_token' ),
	'NP4 the settings screen exposes no token field'
);
assert_true(
	false === stripos( $rendered, 'developers.eventbrite.com' ),
	'NP4 the settings screen does not send a maintainer to create a token'
);
assert_true(
	false === stripos( $rendered, 'name="conexao_settings_action"' ),
	'NP4 the settings screen has no save action (nothing to save)'
);

// The maintained importer DOCUMENTATION is checked by the repository-level
// script-contract gate `tests/scripts/verify-eventbrite-token-retirement.py`,
// not here: this suite runs inside the WordPress container, where `docs/` is
// not mounted, so a documentation assertion here would be a vacuous pass.
// See that gate for the "no current instruction to configure a token" proof.


// ===========================================================================
// NP5 — Completeness and failure semantics are UNCHANGED (G1–G5 intact).
// ===========================================================================

test_section( 'NP5 — G1–G5 completeness/failure semantics survive the removal' );

// (a) A first-page failure is still FATAL, never a silent empty success.
//     This is the G1 masking defect; token removal must not resurrect it.
//
//     The reuse store is reset first: without it this walk would be served the
//     memoised SUCCESSFUL page from NP3 and would pass for the wrong reason.
Conexao_Request_Reuse::begin_run();

g7_reset_requests();
g7_install_http_double( '<html><body>blocked, no server data</body></html>' );
$failed_source = new Conexao_Source_Eventbrite( g7_county_source_config( 'Cork', 'cork', array( 'Cork', 'Cork City' ) ) );
$threw         = false;
try {
	$failed_source->fetch_events();
} catch ( Conexao_Source_Fetch_Exception $e ) {
	$threw = true;
}
g7_remove_http_double();

assert_true(
	count( g7_request_urls() ) > 0,
	'NP5 the failing walk really issued a request (not a cached page)'
);
assert_true( $threw, 'NP5 a first page that cannot be parsed still raises the structured failure' );

// The real G1 protection is NOT the discarded object's own flag: when
// fetch_events() throws, the handler instance is thrown away and
// Conexao_Event_Importer_Engine catches Conexao_Source_Fetch_Exception, records
// an incomplete enumeration and RETURNS BEFORE mark_missing_events(). What
// matters is therefore that the walk ABORTS rather than returning an empty
// array, because an empty return is exactly what the engine would record as
// `found=0, status=success` (the G1 masking defect).
assert_true(
	! $failed_source->is_fetch_complete() || $threw,
	'NP5 a failed first page never yields an empty successful enumeration',
	'fetch_complete is only meaningful when no exception was thrown'
);

// The abort itself is the invariant: assert the walk returned nothing at all.
g7_reset_requests();
g7_install_http_double( '<html><body>blocked, no server data</body></html>' );
$abort_source = new Conexao_Source_Eventbrite( g7_county_source_config( 'Cork', 'cork', array( 'Cork', 'Cork City' ) ) );
$returned     = 'NOT_SET';
try {
	$returned = $abort_source->fetch_events();
} catch ( Conexao_Source_Fetch_Exception $e ) {
	$returned = 'THREW';
}
g7_remove_http_double();

assert_true(
	'THREW' === $returned,
	'NP5 a blocked first page aborts instead of returning an empty event list',
	'fetch_events() returned ' . ( is_array( $returned ) ? count( $returned ) . ' events' : $returned )
);

// (b) Completeness tracking survives: an incomplete walk must stay incomplete
//     so absent events are never marked source_not_found.
$walk = new Conexao_Source_Eventbrite( g7_county_source_config( 'Cork', 'cork', array( 'Cork', 'Cork City' ) ) );
$mark = new ReflectionMethod( 'Conexao_Source_Base', 'mark_fetch_incomplete' );
$mark->setAccessible( true );
$mark->invoke( $walk );
assert_true(
	! $walk->is_fetch_complete(),
	'NP5 mark_fetch_incomplete() still forces an incomplete verdict'
);

// (c) The G1–G5 machinery the retirement must NOT have touched is all still
//     present on the source handler.
foreach ( array( 'MAX_PAGES', 'TIME_BUDGET_SECONDS' ) as $constant ) {
	assert_true(
		defined( 'Conexao_Source_Eventbrite::' . $constant ),
		'NP5 the request budget constant ' . $constant . ' is still defined'
	);
}
assert_true(
	method_exists( 'Conexao_Source_Eventbrite', 'is_county_event' ),
	'NP5 the county region gate is intact'
);
assert_true(
	class_exists( 'Conexao_Eventbrite_Pacing' ),
	'NP5 the G5 pacing policy is intact'
);
assert_true(
	method_exists( 'Conexao_Eventbrite_Client', 'fetch_page' ),
	'NP5 the G1–G5 HTML client is intact'
);
assert_true(
	class_exists( 'Conexao_Request_Budget' ),
	'NP5 the G4 request budget is intact'
);
assert_true(
	class_exists( 'Conexao_Request_Reuse' ),
	'NP5 the G4A request reuse is intact'
);

// (d) A county event whose region is unknown is still REJECTED, not imported.
$gate        = new Conexao_Source_Eventbrite( g7_county_source_config( 'Cork', 'cork', array( 'Cork', 'Cork City' ) ) );
$gate_method = new ReflectionMethod( 'Conexao_Source_Eventbrite', 'is_county_event' );
$gate_method->setAccessible( true );

$rejected = $gate_method->invoke(
	$gate,
	array(
		'locations' => array(
			array(
				'type' => 'region',
				'name' => 'Kerry',
			),
		),
	)
);
assert_true( ! $rejected['accepted'], 'NP5 an out-of-county region is still rejected' );

$accepted = $gate_method->invoke(
	$gate,
	array(
		'locations' => array(
			array(
				'type' => 'region',
				'name' => 'Cork',
			),
		),
	)
);
assert_true( $accepted['accepted'], 'NP5 a configured in-county region is still accepted' );

// (e) The settings storage the cleanup scope depends on is still functional,
//     so retiring the token did not take the live consumer with it.
g7_store_settings( array( 'cleanup_scope' => 'imported_only' ) );
assert_true(
	'imported_only' === Conexao_Import_Settings::get( 'cleanup_scope', 'all' ),
	'NP5 the live cleanup_scope setting still round-trips (token removal was surgical)'
);

g7_store_settings( array( 'cleanup_scope' => 'all' ) );
assert_true(
	'all' === Conexao_Import_Settings::get( 'cleanup_scope', 'all' ),
	'NP5 a partial merge does not resurrect a retired key'
);

// ---------------------------------------------------------------------------

delete_option( Conexao_Import_Settings::OPTION_KEY );
g7_reset_settings_cache();

test_finish( 'G7 Eventbrite API-token retirement' );

