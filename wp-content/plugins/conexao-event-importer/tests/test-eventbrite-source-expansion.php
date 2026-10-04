<?php
/**
 * G8/G9 — complementary source expansion and operational hardening.
 *
 * ## What is proved
 *
 * G8 — source expansion:
 *   1. Registry: date-window sources exist, are well-formed, ship INACTIVE, and
 *      carry the real county geography hint.
 *   2. Identity: a window source reuses the existing `eventbrite` handler and
 *      emits the NATIVE Eventbrite event id as source_id.
 *   3. Cross-source dedup: the same event reached through two different window
 *      URLs resolves to ONE record (stable canonical URL).
 *   4. Geography: the county-region gate still decides eligibility; the window
 *      URL confers nothing.
 *   5. Upstream truncation (object_count > page_count x page_size) is INCOMPLETE,
 *      never complete — so it can never authorise mark_missing_events().
 *   6. A genuinely complete listing stays complete (no over-blocking).
 *
 * G9 — operational hardening:
 *   7. 429 / 405 / 5xx / network failures earn a bounded, source-level cooldown.
 *   8. A 404 does NOT earn a cooldown (a fixable source must stay fixable).
 *   9. A source in cooldown is skipped with ZERO requests, is recorded as
 *      INCOMPLETE, and never triggers missing-event reconciliation.
 *  10. Cooldown escalates and is bounded by MAX_SECONDS.
 *  11. Recovery is only inferred from a real, complete, non-empty listing.
 *  12. Cooldown state is visible in Import History.
 *
 * ## Transport
 *
 * Every case runs against a `pre_http_request` double. Nothing contacts a real
 * upstream, and no production write occurs.
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
		Conexao_Source_Cooldown::OPTION_KEY,
	)
);

register_shutdown_function(
	static function () use ( $restore_options ) {
		test_options_restore( $restore_options, true );
	}
);

// Keep the suite fast and deterministic. Both of these are the REAL filter
// names the production pacing policy reads, so zeroing them removes wall-clock
// waiting without changing any decision the tests assert on. The cooldown
// arithmetic is exercised deliberately in G9.2 against the real constants.
add_filter( 'conexao_eventbrite_backoff_seconds', '__return_zero', 99 );
add_filter( 'conexao_eventbrite_page_delay_seconds', '__return_zero', 99 );

$plugin = Conexao_Event_Importer::instance();

/**
 * Build a minimal Eventbrite discovery page carrying a pagination block.
 *
 * @param int   $page_count   Declared page count.
 * @param int   $object_count Declared object count.
 * @param int   $page_size    Declared page size.
 * @param array $events       Optional list of raw event arrays.
 * @return string
 */
function g8_eb_page( $page_count, $object_count, $page_size = 20, array $events = array() ) {
	$payload = array(
		'search_data' => array(
			'events' => array(
				'results'    => $events,
				'pagination' => array(
					'page_count'   => (int) $page_count,
					'object_count' => (int) $object_count,
					'page_number'  => 1,
					'page_size'    => (int) $page_size,
				),
			),
		),
	);

	return '<html><body><script>window.__SERVER_DATA__ = '
		. wp_json_encode( $payload )
		. ';</script></body></html>';
}
/**
 * Build one raw Eventbrite event as the public payload would carry it.
 *
 * @param string $id     Native Eventbrite id.
 * @param string $region Region name.
 * @param string $url    Canonical event URL.
 * @return array
 */
function g8_eb_event( $id, $region = 'Cork', $url = '' ) {
	return array(
		'id'        => $id,
		'name'      => 'Event ' . $id,
		'url'       => '' !== $url ? $url : 'https://www.eventbrite.ie/e/' . $id,
		'start'     => array( 'local' => '2099-01-01T10:00:00' ),
		'locations' => array(
			array(
				// The real payload marks the county entry as type=region, and
				// Conexao_Source_Eventbrite::is_county_event() only ever accepts a
				// location of that type. The fixture mirrors the real shape.
				'type'    => 'region',
				'name'    => $region,
				'address' => array(
					'region'  => $region,
					'country' => 'IE',
				),
			),
		),
	);
}

/**
 * Install an HTTP double serving a fixed response for any URL.
 *
 * @param int           $code  HTTP status.
 * @param string        $body  Response body.
 * @param WP_Error|null $error Transport failure to simulate instead.
 * @return void
 */
function g8_install_double( $code = 200, $body = '', $error = null ) {
	$GLOBALS['g8_served'] = array();

	add_filter(
		'pre_http_request',
		static function ( $preempt, $args, $url ) use ( $code, $body, $error ) {
			$GLOBALS['g8_served'][] = $url;

			if ( $error instanceof WP_Error ) {
				return $error;
			}

			return array(
				'response' => array( 'code' => (int) $code, 'message' => 'OK' ),
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
 * Remove the HTTP double and forget what it served.
 *
 * @return void
 */
function g8_remove_double() {
	remove_all_filters( 'pre_http_request' );
	$GLOBALS['g8_served'] = array();
}

/**
 * How many requests the double has served so far.
 *
 * @return int
 */
function g8_request_count() {
	return isset( $GLOBALS['g8_served'] ) ? count( $GLOBALS['g8_served'] ) : 0;
}

/**
 * Reset the Eventbrite rate-limit latch so cases are independent.
 *
 * @return void
 */
function g8_reset_pacing() {
	remove_all_filters( 'conexao_eventbrite_pacing' );
	// begin_run() clears the run-scoped rate-limit latch AND the shared policy
	// counters, so one case can never inherit another's throttle state.
	Conexao_Eventbrite_Pacing::begin_run();
}

/**
 * A minimal source handler used to drive cooldown-recovery decisions.
 *
 * Exposes the three signals `can_clear_cooldown()` reads — did we get anything,
 * and was the enumeration complete — without performing any I/O.
 */
class Conexao_Source_Base_Probe extends Conexao_Source_Base {

	/** @var string */
	protected $probe_id;

	/** @var bool */
	protected $probe_has_events;

	/** @var bool */
	protected $probe_complete;

	/**
	 * Constructor.
	 *
	 * @param string $id            Probe source id.
	 * @param bool   $has_events    Whether the enumeration produced events.
	 * @param bool   $fetch_complete Whether the enumeration was complete.
	 */
	public function __construct( $id, $has_events = false, $fetch_complete = true ) {
		parent::__construct( array( 'id' => $id ) );
		$this->probe_id         = $id;
		$this->probe_has_events = (bool) $has_events;
		$this->probe_complete   = (bool) $fetch_complete;

		$this->fetched_event_count = $has_events ? 3 : 0;
		$this->fetch_complete      = $fetch_complete;
	}

	/**
	 * Source ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return $this->probe_id;
	}

	/**
	 * Not exercised: the probe is configured, not fetched.
	 *
	 * @return array
	 */
	public function fetch_events() {
		return array();
	}
}

/**
 * Walk a source config against a fixed discovery page body.
 *
 * @param array  $config Source config.
 * @param string $body   Discovery page body.
 * @return Conexao_Source_Eventbrite
 */
function g8_walk( array $config, $body ) {
	g8_install_double( 200, $body );

	$handler = new Conexao_Source_Eventbrite( $config );
	$handler->fetch_events();
	g8_remove_double();

	return $handler;
}

echo "G8/G9 - source expansion and operational hardening\n";

// ---------------------------------------------------------------------------
// G8.1 - the registry produces well-formed, inactive date-window sources.
// ---------------------------------------------------------------------------

g8_reset_pacing();

$cork_today = Conexao_County_Registry::get_eventbrite_window_source( 'cork', 'events-today', 'Today' );

assert_true( is_array( $cork_today ), 'G8: a date-window source config is generated' );
assert_true( 'eventbrite_cork_events_today' === $cork_today['id'], 'G8: window source ID is derived from county + window' );
assert_true( 'eventbrite' === $cork_today['type'], 'G8: window source reuses the existing eventbrite TYPE (no new architecture)' );
assert_true( 'inactive' === $cork_today['status'], 'G8: window source ships INACTIVE and cannot start an import on its own' );
assert_true( 'Cork' === $cork_today['county'], 'G8: window source carries the real county geography hint' );
assert_true(
	false !== strpos( $cork_today['url'], 'ireland--cork/events-today/' ),
	'G8: window source URL targets the county date-window listing'
);
assert_true(
	is_array( $cork_today['region_labels'] ) && in_array( 'Cork', $cork_today['region_labels'], true ),
	'G8: window source inherits the county region labels used by the geography gate'
);
assert_true(
	null === Conexao_County_Registry::get_eventbrite_window_source( 'not-a-county', 'events-today', 'Today' ),
	'G8: an unknown county yields no window source'
);

$all_windows = Conexao_County_Registry::get_all_eventbrite_window_sources();
assert_true( is_array( $all_windows ) && count( $all_windows ) > 0, 'G8: the registry exposes the date-window source set' );

$all_inactive = true;
foreach ( $all_windows as $window_source ) {
	if ( 'inactive' !== $window_source['status'] ) {
		$all_inactive = false;
	}
}
assert_true( $all_inactive, 'G8: EVERY registered date-window source ships inactive' );

$all_county = Conexao_County_Registry::get_all_county_sources();
assert_true( isset( $all_county['eventbrite_cork_events_today'] ), 'G8: date-window sources join the single county source registry' );
assert_true(
	isset( $all_county['eventbrite_cork'] ) && isset( $all_county['heritage_week_cork'] ),
	'G8: adding window sources did not drop the pre-existing county sources'
);

// events-this-month measured 48 declared pages against the same 49-page cap, so
// registering it would cost requests without adding reachable coverage.
assert_true(
	! isset( $all_county['eventbrite_cork_events_this_month'] ),
	'G8: events-this-month is deliberately NOT registered (48 pages is as capped as all-events)'
);

// ---------------------------------------------------------------------------
// G8.2 - upstream truncation must never read as complete.
// ---------------------------------------------------------------------------

$config_cork = Conexao_County_Registry::get_eventbrite_source( 'cork' );

// A truncated listing: 1547 objects behind 49 pages of 20 (the measured shape).
$truncated = g8_walk( $config_cork, g8_eb_page( 49, 1547, 20, array( g8_eb_event( '111' ) ) ) );
assert_true( ! ( $truncated->is_fetch_complete() ), 'G8: a listing declaring more objects than its pagination can expose is INCOMPLETE' );
assert_true( $truncated->has_fetched_events(), 'G8: a truncated walk still returns what it did fetch' );

// The boundary: objects exactly filling the declared pages is NOT truncated.
$exactly_full = g8_walk( $config_cork, g8_eb_page( 49, 980, 20, array( g8_eb_event( '222' ) ) ) );
assert_true(
	$exactly_full->is_fetch_complete(),
	'G8: a listing whose objects exactly fit its declared pages stays COMPLETE'
);

// Comfortably inside the ceiling: a small date-window listing.
$small = g8_walk( $config_cork, g8_eb_page( 2, 25, 20, array( g8_eb_event( '333' ) ) ) );
assert_true(
	$small->is_fetch_complete(),
	'G8: a small date-window listing is COMPLETE (the guard does not over-block)'
);

// No declared objects is not evidence of truncation.
$no_objects = g8_walk( $config_cork, g8_eb_page( 1, 0, 20, array() ) );
assert_true(
	$no_objects->is_fetch_complete(),
	'G8: a listing declaring zero objects is not treated as truncated'
);

// An absent page_size makes the ceiling unknowable, so completeness is preserved.
$no_page_size = g8_walk( $config_cork, g8_eb_page( 49, 1547, 0, array( g8_eb_event( '444' ) ) ) );
assert_true(
	$no_page_size->is_fetch_complete(),
	'G8: an unknown ceiling (no page_size) does not silently downgrade completeness'
);

// ---------------------------------------------------------------------------
// G8.3 - identity: the window source emits the NATIVE Eventbrite id.
// ---------------------------------------------------------------------------

g8_reset_pacing();

$window_config = Conexao_County_Registry::get_eventbrite_window_source( 'cork', 'events-this-week', 'This week' );
$window_events = g8_walk( $window_config, g8_eb_page( 1, 1, 20, array( g8_eb_event( '999888777' ) ) ) );
assert_true(
	$window_events->has_fetched_events(),
	'G8: a date-window listing yields events through the existing handler'
);

$normalizer = new Conexao_Event_Normalizer( new Conexao_Event_Location() );
$window_raw = $normalizer->normalize(
	array(
		'title'      => 'Window Event',
		'url'        => 'https://www.eventbrite.ie/e/999888777',
		'source_id'  => '999888777',
		'source'     => 'eventbrite_cork_events_this_week',
		'start_date' => '2099-01-01',
		'start_time' => '10:00',
	)
);

assert_true(
	'999888777' === $window_raw['source_id'],
	'G8: identity is the NATIVE Eventbrite event id (not an array index, page number or title)'
);
assert_true(
	'eventbrite_cork_events_this_week' === $window_raw['source'],
	'G8: the window source records its own source slug for per-source bookkeeping'
);
assert_true(
	'https://www.eventbrite.ie/e/999888777' === $window_raw['source_url'],
	'G8: the canonical Eventbrite URL is retained and is stable across windows'
);

// ---------------------------------------------------------------------------
// G8.4 - geography: the window URL confers nothing.
// ---------------------------------------------------------------------------

$wrong_region = g8_walk( $window_config, g8_eb_page( 1, 1, 20, array( g8_eb_event( '555', 'Galway' ) ) ) );
assert_true( ! ( $wrong_region->has_fetched_events() ), 'G8: an event located outside the county is REJECTED even though a county window URL served it' );

$right_region = g8_walk( $window_config, g8_eb_page( 1, 1, 20, array( g8_eb_event( '556', 'Cork' ) ) ) );
assert_true(
	$right_region->has_fetched_events(),
	'G8: an event whose own location metadata IS in the county is accepted'
);

// ---------------------------------------------------------------------------
// G8.5 - cross-source dedup is deterministic, on the canonical URL.
// ---------------------------------------------------------------------------

$g8_a = $normalizer->normalize(
	array(
		'title'      => 'Shared Event',
		'url'        => 'https://www.eventbrite.ie/e/424242',
		'source_id'  => '424242',
		'source'     => 'eventbrite_cork',
		'start_date' => '2099-02-02',
		'start_time' => '19:00',
		'location'   => 'Cork',
		'venue'      => 'The Mardyke',
	)
);
$g8_b = $normalizer->normalize(
	array(
		'title'      => 'Shared Event',
		'url'        => 'https://www.eventbrite.ie/e/424242',
		'source_id'  => '424242',
		'source'     => 'eventbrite_cork_events_this_week',
		'start_date' => '2099-02-02',
		'start_time' => '19:00',
		'location'   => 'Cork',
		'venue'      => 'The Mardyke',
	)
);

$dedup = new Conexao_Event_Deduplicator();
$first_post = wp_insert_post(
	array(
		'post_type'    => 'event',
		'post_title'   => 'Shared Event',
		'post_status'  => 'publish',
		'post_content' => '',
	)
);
assert_true( $first_post > 0, 'G8: a shared event is created once' );

$shared_meta = array(
	'_event_source'      => 'eventbrite_cork',
	'_event_source_id'   => '424242',
	'_event_url'         => 'https://www.eventbrite.ie/e/424242',
	'_event_source_url'  => 'https://www.eventbrite.ie/e/424242',
	'_event_date'        => '2099-02-02',
	'_event_start_time'  => '19:00',
	'_event_venue'       => 'The Mardyke',
);
foreach ( $shared_meta as $meta_key => $meta_value ) {
	update_post_meta( $first_post, $meta_key, $meta_value );
}

$match_from_window = $dedup->find( $g8_b );
assert_true(
	(int) $first_post === (int) $match_from_window,
	'G8: the SAME event arriving through a different window source resolves to the SAME record'
);

$match_from_original = $dedup->find( $g8_a );
assert_true(
	(int) $first_post === (int) $match_from_original,
	'G8: dedup is symmetric - the original source still resolves to the same record'
);

wp_delete_post( $first_post, true );

// ---------------------------------------------------------------------------
// G9.1 - throttled failures earn a bounded, source-level cooldown.
// ---------------------------------------------------------------------------

$importer_engine = $plugin->importer;

$record_method = new ReflectionMethod( 'Conexao_Event_Importer_Engine', 'record_throttle_cooldown' );
$record_method->setAccessible( true );

Conexao_Source_Cooldown::reset( 'g9_probe' );
assert_true( 'rate_limited' === $record_method->invoke( $importer_engine, 'g9_probe', 429 ), 'G9: HTTP 429 is classified as rate_limited' );
assert_true( Conexao_Source_Cooldown::is_cooling_down( 'g9_probe' ), 'G9: a 429 puts the source into cooldown' );
assert_true( Conexao_Source_Cooldown::get_seconds_remaining( 'g9_probe' ) > 0, 'G9: the cooldown has a positive remaining window' );

assert_true( 'waf' === $record_method->invoke( $importer_engine, 'g9_probe_waf', 405 ), 'G9: HTTP 405 is classified as a WAF refusal' );
assert_true( Conexao_Source_Cooldown::is_cooling_down( 'g9_probe_waf' ), 'G9: a 405/WAF puts the source into cooldown' );

assert_true( 'server_error' === $record_method->invoke( $importer_engine, 'g9_probe_5xx', 503 ), 'G9: HTTP 5xx is classified as server_error' );
assert_true( Conexao_Source_Cooldown::is_cooling_down( 'g9_probe_5xx' ), 'G9: a 5xx puts the source into cooldown' );

assert_true( 'network' === $record_method->invoke( $importer_engine, 'g9_probe_net', 0 ), 'G9: a transport failure is classified as network' );
assert_true( Conexao_Source_Cooldown::is_cooling_down( 'g9_probe_net' ), 'G9: an unreachable source is put into cooldown' );

// A 404 is a fixable source, not a throttled one.
assert_true( '' === $record_method->invoke( $importer_engine, 'g9_probe_404', 404 ), 'G9: HTTP 404 is NOT classified as a throttle' );
assert_true( ! ( Conexao_Source_Cooldown::is_cooling_down( 'g9_probe_404' ) ), 'G9: a 404 earns NO cooldown - a fixable source stays immediately retryable' );

// ---------------------------------------------------------------------------
// G9.2 - cooldown escalates, then is bounded.
// ---------------------------------------------------------------------------

Conexao_Source_Cooldown::reset( 'g9_escalate' );

// Escalate well past the STEPS ceiling so the MAX_SECONDS bound is actually
// reachable. Without enough repetitions the ladder never hits the cap, and an
// assertion about the cap would pass even if the cap did not exist.
$rounds = Conexao_Source_Cooldown::STEPS + 3;
$seen   = array();
for ( $i = 0; $i < $rounds; $i++ ) {
	Conexao_Source_Cooldown::record_throttled( 'g9_escalate', 'rate_limited' );
	$seen[] = (int) Conexao_Source_Cooldown::get( 'g9_escalate' )['cooldown_seconds'];
}

assert_true( $seen[0] < $seen[1], 'G9: repeated throttling ESCALATES the cooldown (' . implode( 's -> ', $seen ) . ')' );
assert_true(
	in_array( Conexao_Source_Cooldown::MAX_SECONDS, $seen, true ),
	'G9: the cooldown ladder REACHES the MAX_SECONDS ceiling (' . Conexao_Source_Cooldown::MAX_SECONDS . 's)'
);
assert_true(
	max( $seen ) === Conexao_Source_Cooldown::MAX_SECONDS,
	'G9: the cooldown is BOUNDED - it never grows past MAX_SECONDS'
);
/*
 * The bound is asserted by its VALUE, not by the last two entries matching.
 * Comparing two adjacent entries is satisfied by any monotonic-then-flat
 * ladder, including one whose step cap was removed entirely, so it proves
 * nothing. Asserting that no entry exceeds MAX_SECONDS fails the moment the
 * ladder is allowed to keep growing past it.
 */
assert_true(
	$seen[ $rounds - 1 ] === Conexao_Source_Cooldown::MAX_SECONDS,
	'G9: escalation SETTLES at exactly MAX_SECONDS instead of growing without bound'
);

// Every rung must respect the ceiling, not only the settled one.
$over_ceiling = 0;
foreach ( $seen as $seconds ) {
	if ( $seconds > Conexao_Source_Cooldown::MAX_SECONDS ) {
		$over_ceiling++;
	}
}
assert_true(
	0 === $over_ceiling,
	'G9: NO cooldown rung ever exceeds MAX_SECONDS (the bound holds at every step)'
);

// A different category restarts the ladder rather than inheriting it.
Conexao_Source_Cooldown::reset( 'g9_category' );
Conexao_Source_Cooldown::record_throttled( 'g9_category', 'rate_limited' );
Conexao_Source_Cooldown::record_throttled( 'g9_category', 'rate_limited' );
Conexao_Source_Cooldown::record_throttled( 'g9_category', 'waf' );
assert_true(
	1 === (int) Conexao_Source_Cooldown::get( 'g9_category' )['consecutive'],
	'G9: a different throttle category restarts the ladder (a WAF does not inherit a 429 delay)'
);

// ---------------------------------------------------------------------------
// G9.3 - a source in cooldown sends ZERO requests and stays INCOMPLETE.
// ---------------------------------------------------------------------------

g8_reset_pacing();

$cool_id = 'eventbrite_cork_events_today';
Conexao_Source_Cooldown::record_throttled( $cool_id, 'rate_limited' );

$sources = new Conexao_Event_Sources();
$sources->save(
	array_merge(
		$window_config,
		array( 'id' => $cool_id, 'status' => 'active' )
	)
);

$run_method = new ReflectionMethod( 'Conexao_Event_Importer_Engine', 'run_source' );
$run_method->setAccessible( true );

g8_install_double( 200, g8_eb_page( 1, 1, 20, array( g8_eb_event( '1' ) ) ) );
$cool_stats = $run_method->invoke( $importer_engine, $cool_id );
$cool_requests = g8_request_count();
g8_remove_double();

assert_true( 0 === $cool_requests, 'G9: a source in cooldown sends ZERO outbound requests' );
assert_true(
	false !== strpos( wp_json_encode( $cool_stats ), 'cooldown' ),
	'G9: the skipped run reports its cooldown state to the operator'
);

$history_entry = Conexao_Import_History::get_last( $cool_id );
assert_true( is_array( $history_entry ), 'G9: the skipped run is still recorded in Import History' );
assert_true(
	array_key_exists( 'complete', $history_entry ) && false === $history_entry['complete'],
	'G9: a cooldown-skipped run is recorded INCOMPLETE, never complete'
);
assert_true(
	isset( $history_entry['cooldown'] ) && ! empty( $history_entry['cooldown']['cooling_down'] ),
	'G9: Import History exposes the cooldown state (reason + next eligible)'
);
assert_true(
	isset( $history_entry['cooldown']['seconds_remaining'] ),
	'G9: Import History tells the operator how long to wait before retrying'
);

// ---------------------------------------------------------------------------
// G9.4 - cooldown never claims a source is empty or complete.
// ---------------------------------------------------------------------------

assert_true(
	isset( $cool_stats['found'] ) && 0 === (int) $cool_stats['found'],
	'G9: a skipped source reports zero found WITHOUT claiming the upstream is empty'
);
assert_true(
	in_array( $cool_stats['status'], array( 'failed', 'warning' ), true ),
	'G9: a cooldown-skipped source is never reported as a clean success'
);

// ---------------------------------------------------------------------------
// G9.5 - recovery is only inferred from a real, complete, non-empty listing.
// ---------------------------------------------------------------------------

$clear_method = new ReflectionMethod( 'Conexao_Event_Importer_Engine', 'can_clear_cooldown' );
$clear_method->setAccessible( true );

$probe_empty = new Conexao_Source_Base_Probe( 'g9_probe', false, true );
assert_true( ! ( $clear_method->invoke( $importer_engine, $probe_empty ) ), 'G9: a handler that returned NO events does not prove recovery' );

$probe_partial = new Conexao_Source_Base_Probe( 'g9_probe', true, false );
assert_true( ! ( $clear_method->invoke( $importer_engine, $probe_partial ) ), 'G9: a handler whose walk was INCOMPLETE does not prove recovery' );

$probe_recovered = new Conexao_Source_Base_Probe( 'g9_probe', true, true );
assert_true( $clear_method->invoke( $importer_engine, $probe_recovered ), 'G9: a complete, non-empty listing DOES prove recovery' );

/*
 * NP9 (engine path, not just the predicate). Exercise the REAL run so the
 * "recovery clears the cooldown" wiring is proved end to end. Without this, a
 * mutation that clears the cooldown on ANY fetch would still pass every
 * assertion above, because they only call can_clear_cooldown() directly.
 */
g8_reset_pacing();

$recover_id = 'eventbrite_cork_events_tomorrow';
Conexao_Source_Cooldown::record_throttled( $recover_id, 'rate_limited' );
$sources->save(
	array_merge(
		Conexao_County_Registry::get_eventbrite_window_source( 'cork', 'events-tomorrow', 'Tomorrow' ),
		array( 'id' => $recover_id, 'status' => 'active' )
	)
);

/*
 * First: an INCOMPLETE walk must NOT clear the cooldown.
 *
 * The cooldown gate short-circuits before any request, so to reach the fetch
 * path the record must be present but ALREADY ELAPSED. `record_throttled()`
 * would arm a fresh window and simply skip the source again, proving nothing.
 * Writing an elapsed record directly is what puts the source back in play.
 */
/*
 * The signal to assert on is RECORD EXISTENCE, not is_cooling_down().
 *
 * An elapsed record reports is_cooling_down() === false whether or not the run
 * cleared it, so asserting on the window would pass either way and prove
 * nothing. `get_all()` keyed by source id is what actually distinguishes
 * "cooldown still recorded" from "cooldown cleared by recovery".
 */
Conexao_Source_Cooldown::record_elapsed( $recover_id );
$cooldown_map = Conexao_Source_Cooldown::get_all();
assert_true( isset( $cooldown_map[ $recover_id ] ), 'G9: precondition — an elapsed cooldown record exists' );

g8_install_double( 200, g8_eb_page( 49, 1547, 20, array( g8_eb_event( '9001' ) ) ) );
$run_method->invoke( $importer_engine, $recover_id );
g8_remove_double();
$cooldown_map = Conexao_Source_Cooldown::get_all();
assert_true(
	isset( $cooldown_map[ $recover_id ] ),
	'G9: an INCOMPLETE run does NOT clear the cooldown record (the source is not proven healthy)'
);

// Now: a COMPLETE, non-empty walk DOES clear it.
Conexao_Source_Cooldown::record_elapsed( $recover_id );
g8_install_double( 200, g8_eb_page( 1, 1, 20, array( g8_eb_event( '9002' ) ) ) );
$recover_stats = $run_method->invoke( $importer_engine, $recover_id );
g8_remove_double();
assert_true(
	isset( $recover_stats['found'] ) && $recover_stats['found'] > 0,
	'G9: the recovery run actually fetched events (otherwise the clear proves nothing)'
);
$cooldown_map = Conexao_Source_Cooldown::get_all();
assert_true(
	! isset( $cooldown_map[ $recover_id ] ),
	'G9: a COMPLETE, non-empty run CLEARS the cooldown record (recovery is automatic)'
);

// ---------------------------------------------------------------------------
// G9.6 - clearing a cooldown is observable, and never disables a source.
// ---------------------------------------------------------------------------

Conexao_Source_Cooldown::record_throttled( 'g9_recover_me', 'rate_limited' );
assert_true( Conexao_Source_Cooldown::is_cooling_down( 'g9_recover_me' ), 'G9: a throttled source is cooling down' );

Conexao_Source_Cooldown::record_recovered( 'g9_recover_me' );
assert_true( ! ( Conexao_Source_Cooldown::is_cooling_down( 'g9_recover_me' ) ), 'G9: a successful fetch clears the cooldown' );

Conexao_Source_Cooldown::record_throttled( 'g9_manual', 'waf' );
Conexao_Source_Cooldown::reset( 'g9_manual' );
assert_true( ! ( Conexao_Source_Cooldown::is_cooling_down( 'g9_manual' ) ), 'G9: an operator can explicitly clear a cooldown' );

Conexao_Source_Cooldown::record_throttled( $cool_id, 'rate_limited' );
assert_true( is_array( $sources->get( $cool_id ) ), 'G9: cooldown never DELETES a source registration' );
assert_true( ! ( Conexao_Source_Cooldown::is_cooling_down( 'never_seen_source' ) ), 'G9: a source with no recorded throttle is never blocked' );

echo "\n";
test_finish();
