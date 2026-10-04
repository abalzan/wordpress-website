<?php
/**
 * G2 — pagination correctness and the incomplete-enumeration guard.
 *
 * ## G2A: Heritage Week pagination
 *
 * `get_next_page_url()` used to return `nav->item(0)`. The Heritage Week nav
 * renders "Previous" BEFORE "Next" from page 2 onwards, so the walk selected a
 * BACKWARDS destination and oscillated p1 <-> p2 until MAX_PAGES — measured
 * live as 651 requests for 24 events with only 25 unique URLs.
 *
 * The fix selects a forward-only destination and keeps a per-run visited set.
 * This suite proves p1 -> p2 -> p3 progresses, that a backwards "Next" is never
 * followed, and that a revisited URL terminates the traversal.
 *
 * ## G2B: incomplete Eventbrite enumeration
 *
 * Eventbrite's 45-second budget can stop the walk at page 18 of 49. The
 * diagnosis proved the resulting partial list was treated as a complete
 * enumeration, so `mark_missing_events()` flipped live events — 5 real Cavan
 * events — to `source_not_found`, which excludes them from every public query.
 *
 * The contract now is:
 *
 *     incomplete walk  ->  do NOT mark absent events source_not_found
 *                     ->  still import every event that WAS fetched
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

$GLOBALS['g2_http_responses'] = array();
$GLOBALS['g2_http_requests']  = array();
$GLOBALS['g2_http_double_on'] = false;

add_filter( 'pre_http_request', 'g2_http_double', 10, 3 );

/**
 * Install an HTTP double keyed by exact URL.
 *
 * @param array $responses Exact URL => array( code, body ) or WP_Error.
 * @return void
 */
function g2_install_http_double( array $responses ) {
	$GLOBALS['g2_http_responses'] = $responses;
	$GLOBALS['g2_http_requests']  = array();
	$GLOBALS['g2_http_double_on'] = true;
}

/**
 * Remove the HTTP double.
 *
 * @return void
 */
function g2_remove_http_double() {
	$GLOBALS['g2_http_double_on'] = false;
}

/**
 * The `pre_http_request` filter callback (exact-URL matching).
 *
 * @param false|array|WP_Error $preempt Short-circuit value.
 * @param array                $args    Request args.
 * @param string               $url     Request URL.
 * @return array|WP_Error
 */
function g2_http_double( $preempt, $args, $url ) {
	if ( empty( $GLOBALS['g2_http_double_on'] ) ) {
		return $preempt;
	}

	$GLOBALS['g2_http_requests'][] = $url;

	if ( ! isset( $GLOBALS['g2_http_responses'][ $url ] ) ) {
		return new WP_Error( 'g2_unmocked_url', 'No HTTP double registered for ' . $url );
	}

	$response = $GLOBALS['g2_http_responses'][ $url ];

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

add_filter( 'conexao_eventbrite_backoff_seconds', '__return_zero', 99 );
add_filter( 'conexao_eventbrite_request_delay_seconds', '__return_zero', 99 );

// G5: page pacing moved to the pacing policy under a new filter name, and a
// 429 now waits on the rate-limit cooldown (20s + 40s + 60s). Without this the
// 429 scenarios in this suite would really sleep for two minutes each, which is
// why the whole suite took 123s. The waits are OBSERVED, never endured.
add_filter( 'conexao_eventbrite_page_delay_seconds', '__return_zero', 99 );

add_filter(
	'conexao_eventbrite_pacing',
	static function () {
		static $policy = null;

		if ( null === $policy ) {
			$policy = new Conexao_Eventbrite_Pacing();
			$policy->set_sleeper(
				static function ( $seconds ) {
					$GLOBALS['g2_pacing_slept'][] = $seconds;
				}
			);
		}

		return $policy;
	},
	99
);

$plugin = Conexao_Event_Importer::instance();

/**
 * Shorthand for a successful (HTTP 200) double response.
 *
 * @param string $body Response body.
 * @return array
 */
function g2_ok( $body ) {
	return array( 'code' => 200, 'body' => $body );
}

/**
 * Shorthand for a failing double response.
 *
 * @param int $code HTTP status code.
 * @return array
 */
function g2_status( $code ) {
	return array( 'code' => (int) $code, 'body' => '' );
}

/**
 * Build a Heritage Week listing page with the given paging anchors.
 *
 * @param array $events Array of [ id, title, url ] tuples.
 * @param array $links  Array of [ href, label ] paging anchors, in DOM order.
 * @return string HTML.
 */
function g2_hw_page_html( array $events, array $links ) {
	$cards = '';
	foreach ( $events as $event ) {
		$cards .= sprintf(
			'<article class="item-summary" data-id="%1$s"><h3 class="title">%2$s</h3>'
			. '<a class="link-block" href="%3$s">Read more</a>'
			. '<ul class="list-details"><li>%4$s</li><li>Test Venue</li></ul></article>',
			esc_attr( $event[0] ),
			esc_html( $event[1] ),
			esc_attr( $event[2] ),
			esc_html( '12 December 2026' )
		);
	}

	$anchors = '';
	foreach ( $links as $link ) {
		$anchors .= sprintf(
			'<a class="btn-paging" href="%s">%s</a>',
			esc_attr( $link[0] ),
			esc_html( $link[1] )
		);
	}

	$nav = '' !== $anchors ? '<nav class="paging">' . $anchors . '</nav>' : '';

	return '<html><head><title>2026 Events</title></head><body>' . $cards . $nav . '</body></html>';
}

$hw_base   = 'https://www.heritageweek.ie/event-listings?q=&where%5B%5D=g2county';
$hw_p2     = 'https://www.heritageweek.ie/event-listings/p2?q=&where%5B%5D=g2county';
$hw_p3     = 'https://www.heritageweek.ie/event-listings/p3?q=&where%5B%5D=g2county';
$hw_detail = 'https://www.heritageweek.ie/event-listings/g2-event';

/**
 * Build a Heritage Week handler bound to a throwaway source ID.
 *
 * @param string $source_id Source ID.
 * @return Conexao_Source_Heritage_Week
 */
function g2_hw_handler( $source_id ) {
	return new Conexao_Source_Heritage_Week(
		array(
			'id'       => $source_id,
			'county'   => 'Laois',
			'hw_where' => array( 'g2county' ),
			'category' => 'Heritage',
		)
	);
}

/**
 * Collect the listing-page requests (not detail pages) from the double.
 *
 * @return string[]
 */
function g2_listing_requests() {
	global $hw_detail;

	$listing = array();
	foreach ( (array) $GLOBALS['g2_http_requests'] as $req ) {
		if ( false !== strpos( $req, 'heritageweek.ie/event-listings' ) && false === strpos( $req, $hw_detail ) ) {
			$listing[] = $req;
		}
	}
	return $listing;
}

// ===========================================================================
// Test 1 (G2A): page 1 -> page 2 -> page 3 actually progresses.
// The page-2 nav deliberately leads with "Previous", exactly like the live site.
// ===========================================================================
test_section( 'G2A: the walk progresses p1 -> p2 -> p3, never p2 -> p1' );

$hw_next_p2 = '/event-listings/p2?q=&where%5B%5D=g2county';
$hw_next_p3 = '/event-listings/p3?q=&where%5B%5D=g2county';

g2_install_http_double(
	array(
		$hw_base => g2_ok( g2_hw_page_html( array( array( 'g2-1', 'G2 page one event', $hw_detail . '-1' ) ), array( array( $hw_next_p2, 'Next' ) ) ) ),
		$hw_p2   => g2_ok(
			g2_hw_page_html(
				array( array( 'g2-2', 'G2 page two event', $hw_detail . '-2' ) ),
				// Previous FIRST, exactly as measured live.
				array(
					array( '/event-listings?q=&where%5B%5D=g2county', 'Previous' ),
					array( $hw_next_p3, 'Next' ),
				)
			)
		),
		// Last page: only a Previous link remains.
		$hw_p3    => g2_ok( g2_hw_page_html( array( array( 'g2-3', 'G2 page three event', $hw_detail . '-3' ) ), array( array( $hw_next_p2, 'Previous' ) ) ) ),
		( $hw_detail . '-1' ) => g2_ok( '<html><body>detail 1</body></html>' ),
		( $hw_detail . '-2' ) => g2_ok( '<html><body>detail 2</body></html>' ),
		( $hw_detail . '-3' ) => g2_ok( '<html><body>detail 3</body></html>' ),
	)
);

$handler = g2_hw_handler( 'g2_hw_progress' );
$events  = $handler->fetch_events();

$listing_requests = g2_listing_requests();
g2_remove_http_double();

assert_true( 3 === count( $events ), 'G2A: all three pages contributed an event (got ' . count( $events ) . ')' );
assert_true( $handler->is_fetch_complete(), 'G2A: a walk that reaches the last page is COMPLETE' );

$unique_listing_requests = array_values( array_unique( $listing_requests ) );

assert_true(
	count( $unique_listing_requests ) === count( $listing_requests ),
	'G2A: no listing URL was fetched twice (ping-pong impossible)'
);
assert_true(
	array( $hw_base, $hw_p2, $hw_p3 ) === $unique_listing_requests,
	'G2A: pages were visited in forward order p1 -> p2 -> p3'
);

// The explicit anti-ping-pong assertion.
assert_true(
	false === array_search( $hw_base, array_slice( $listing_requests, 1 ), true ),
	'G2A: never page 2 -> page 1 -> page 2'
);

// ===========================================================================
// Test 2 (G2A): a "Next" that points BACKWARDS is never followed.
// ===========================================================================
test_section( 'G2A: a backwards Next link is rejected and the traversal stops' );

g2_install_http_double(
	array(
		$hw_base => g2_ok( g2_hw_page_html( array( array( 'g2-b1', 'G2 backwards one', $hw_detail . '-b1' ) ), array( array( '/event-listings/p2?q=&where%5B%5D=g2county', 'Next' ) ) ) ),
		$hw_p2   => array(
			'code' => 200,
			// "Next" is LABELLED Next but resolves to the page we already
			// fetched. It must be rejected: following it would ping-pong.
			'body' => g2_hw_page_html(
				array( array( 'g2-b2', 'G2 backwards two', $hw_detail . '-b2' ) ),
				array( array( '/event-listings?q=&where%5B%5D=g2county', 'Next' ) )
			),
		),
		(  $hw_detail . '-b1'  ) => g2_ok( '<html><body>b1</body></html>' ),
		(  $hw_detail . '-b2'  ) => g2_ok( '<html><body>b2</body></html>' ),
	)
);

$cycle_handler = g2_hw_handler( 'g2_hw_cycle' );
$cycle_events  = $cycle_handler->fetch_events();

$cycle_requests = g2_listing_requests();
g2_remove_http_double();

assert_true(
	false === array_search( $hw_base, array_slice( $cycle_requests, 1 ), true ),
	'G2A cycle: page 1 was never re-fetched after leaving it'
);
assert_true(
	count( $cycle_requests ) <= 3,
	'G2A cycle: the traversal terminated promptly instead of looping to MAX_PAGES (requests=' . count( $cycle_requests ) . ')'
);
assert_true(
	! $cycle_handler->is_fetch_complete(),
	'G2A cycle: a walk stopped by a repeated URL is reported INCOMPLETE'
);
assert_true( 2 === count( $cycle_events ), 'G2A cycle: events fetched before the cycle are preserved (got ' . count( $cycle_events ) . ')' );

// ===========================================================================
// Test 3 (G2A): no valid Next link at all terminates cleanly and COMPLETELY.
// ===========================================================================
test_section( 'G2A: a listing with no forward link is a complete single-page walk' );

g2_install_http_double(
	array(
		$hw_base => g2_ok( g2_hw_page_html( array( array( 'g2-s1', 'G2 single page', $hw_detail . '-s1' ) ), array() ) ),
		(  $hw_detail . '-s1'  ) => g2_ok( '<html><body>s1</body></html>' ),
	)
);

$single_handler = g2_hw_handler( 'g2_hw_single' );
$single_events  = $single_handler->fetch_events();
g2_remove_http_double();

assert_true( 1 === count( $single_events ), 'G2A: the single page contributed its event' );
assert_true( $single_handler->is_fetch_complete(), 'G2A: no Next link means the walk legitimately COMPLETED' );

// ===========================================================================
// Test 4 (G2B): Eventbrite completeness is explicitly represented.
// ===========================================================================
test_section( 'G2B: Eventbrite reports complete=true only for a full traversal' );

$eb_url = 'https://www.eventbrite.ie/d/ireland--laois/all-events/';

/**
 * Build an Eventbrite discovery page with a given page count.
 *
 * @param int $page_count  Declared page count.
 * @param int $event_count Events on the page.
 * @return string HTML.
 */
function g2_eb_page_html( $page_count, $event_count = 1 ) {
	$events = array();
	for ( $i = 0; $i < $event_count; $i++ ) {
		$events[] = array(
			'id'         => '920000000' . $i,
			'name'       => 'G2 event ' . $i,
			'url'        => 'https://www.eventbrite.ie/e/g2-event-' . $i,
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
					'object_count' => $page_count * $event_count,
					'page_count'   => $page_count,
					'page_number'  => 1,
					'page_size'    => 20,
				),
			),
		),
	);

	return '<html><body><script>window.__SERVER_DATA__ = ' . wp_json_encode( $payload ) . ';</script></body></html>';
}

/**
 * Build an Eventbrite handler bound to a throwaway source ID.
 *
 * @param string $source_id Source ID.
 * @return Conexao_Source_Eventbrite
 */
function g2_eb_handler( $source_id ) {
	return new Conexao_Source_Eventbrite(
		array(
			'id'            => $source_id,
			'url'           => 'https://www.eventbrite.ie/d/ireland--laois/all-events/',
			'county'        => 'Laois',
			'region_labels' => array( 'Laois' ),
		)
	);
}

// --- 4a: a complete walk is complete = true -------------------------------
g2_install_http_double( array( $eb_url => array( 'code' => 200, 'body' => g2_eb_page_html( 1, 2 ) ) ) );

$eb_complete       = g2_eb_handler( 'g2_eb_complete' );
$eb_complete_events = $eb_complete->fetch_events();
g2_remove_http_double();

assert_true( $eb_complete->is_fetch_complete(), 'G2B: a full traversal reports complete=true' );
assert_true( 2 === count( $eb_complete_events ), 'G2B: the complete walk returned its events' );

// --- 4b: time-budget truncation is complete = false -----------------------
add_filter( 'conexao_eventbrite_time_budget_seconds', '__return_zero', 99 );

g2_install_http_double( array( $eb_url => array( 'code' => 200, 'body' => g2_eb_page_html( 3, 2 ) ) ) );

$eb_truncated        = g2_eb_handler( 'g2_eb_truncated' );
$eb_truncated_events = $eb_truncated->fetch_events();
g2_remove_http_double();

remove_filter( 'conexao_eventbrite_time_budget_seconds', '__return_zero', 99 );

assert_true( ! $eb_truncated->is_fetch_complete(), 'G2B: a time-budget-truncated walk reports complete=false' );
assert_true( 2 === count( $eb_truncated_events ), 'G2B: a truncated walk STILL returns the events it did fetch (got ' . count( $eb_truncated_events ) . ')' );

// --- 4c: a later-page fetch failure is complete = false -------------------
g2_install_http_double(
	array(
		$eb_url          => array( 'code' => 200, 'body' => g2_eb_page_html( 3, 2 ) ),
		(  $eb_url . '?page=2'  ) => g2_status( 403 ),
		(  $eb_url . '?page=3'  ) => array( 'code' => 200, 'body' => g2_eb_page_html( 3, 2 ) ),
	)
);

$eb_partial        = g2_eb_handler( 'g2_eb_partial' );
$eb_partial_events = $eb_partial->fetch_events();
g2_remove_http_double();

assert_true( ! $eb_partial->is_fetch_complete(), 'G2B: a later-page fetch failure reports complete=false' );
assert_true( 2 === count( $eb_partial_events ), 'G2B: partial-page tolerance still keeps the fetched events' );

// ===========================================================================
// Test 5 (G2B, THE CAVAN SCENARIO): a live event absent from an INCOMPLETE
// walk must NOT become source_not_found.
//
// This is the exact mechanism the diagnosis proved hid 5 currently-live Cavan
// events from the public site. Absence from a truncated listing is not
// evidence of upstream disappearance.
// ===========================================================================
test_section( 'G2B Cavan scenario: a live event absent from an incomplete walk survives' );

$cavan_source = 'g2_cavan_probe';

$plugin->sources->save(
	array(
		'id'            => $cavan_source,
		'name'          => 'G2 Cavan probe',
		'url'           => $eb_url,
		'type'          => 'eventbrite',
		'status'        => 'active',
		'county'        => 'Laois',
		'region_labels' => array( 'Laois' ),
	)
);

// Create a real event that the upstream listing will NOT contain — the
// stand-in for "Cavan Plein Air Festival", which is live upstream but was
// never seen because the walk stopped at page 18 of 49.
$hidden_url  = 'https://www.eventbrite.ie/e/cavan-plein-air-festival-tickets-1999210552580';
$hidden_post = wp_insert_post(
	array(
		'post_type'   => 'event',
		'post_title'  => 'G2 Cavan Plein Air Festival (live upstream)',
		'post_status' => 'publish',
	)
);

update_post_meta( $hidden_post, '_event_source', $cavan_source );
update_post_meta( $hidden_post, '_event_source_id', '1999210552580' );
update_post_meta( $hidden_post, '_event_url', $hidden_url );
Conexao_Event_Status::set_status( $hidden_post, Conexao_Event_Status::PUBLISHED );

// The upstream walk returns ONE event, and page 2 fails: the enumeration is
// incomplete, so the stored event above is absent from it.
$partial_listing = array(
	'search_data' => array(
		'events' => array(
			'results'    => array(
				array(
					'id'         => '9300000001',
					'name'       => 'G2 partial listing event',
					'url'        => 'https://www.eventbrite.ie/e/g2-partial-1',
					'start_date' => '2026-12-01',
					'end_date'   => '2026-12-01',
					'timezone'   => 'Europe/Dublin',
					'locations'  => array( array( 'type' => 'region', 'name' => 'Laois' ) ),
				),
			),
			'pagination' => array( 'object_count' => 2, 'page_count' => 2, 'page_number' => 1, 'page_size' => 20 ),
		),
	),
);

g2_install_http_double(
	array(
		$eb_url          => g2_ok( '<html><body><script>window.__SERVER_DATA__ = ' . wp_json_encode( $partial_listing ) . ';</script></body></html>' ),
		(  $eb_url . '?page=2'  ) => g2_status( 429 ),
	)
);

// A REAL (non-dry) run: this is the only path that calls mark_missing_events().
$result = $plugin->importer->run_source( $cavan_source, false );
g2_remove_http_double();

$status_after = get_post_meta( $hidden_post, '_event_status', true );

assert_true( ! $result['complete'], 'G2B Cavan: the run reports complete=false' );
assert_true( 'success' !== $result['status'], 'G2B Cavan: an incomplete run is not a bare success (got "' . $result['status'] . '")' );
assert_true( 1 === (int) $result['found'], 'G2B Cavan: the event that WAS fetched is still imported (found=1)' );
assert_true(
	'source_not_found' !== $status_after,
	'G2B Cavan: the live event was NOT marked source_not_found (got "' . $status_after . '")'
);
assert_true(
	null !== get_post( $hidden_post ),
	'G2B Cavan: the live event still exists after an incomplete walk'
);

// The control that proves the guard is doing the work: on a COMPLETE walk, the
// same absence DOES mark it — which is the correct behaviour.
$complete_listing = $partial_listing;
$complete_listing['search_data']['events']['pagination']['page_count'] = 1;

g2_install_http_double(
	array(
		$eb_url => g2_ok( '<html><body><script>window.__SERVER_DATA__ = ' . wp_json_encode( $complete_listing ) . ';</script></body></html>' ),
	)
);

$complete_result = $plugin->importer->run_source( $cavan_source, false );
g2_remove_http_double();

$status_after_complete = get_post_meta( $hidden_post, '_event_status', true );

assert_true( $complete_result['complete'], 'G2B Cavan control: a complete walk reports complete=true' );
assert_true(
	'source_not_found' === $status_after_complete,
	'G2B Cavan control: on a COMPLETE walk, an absent event IS marked source_not_found (guard is not blanket-disabled)'
);

// Clean up the probe post and source.
wp_delete_post( $hidden_post, true );
$plugin->sources->delete( $cavan_source );

foreach ( (array) $plugin->sources->get_all() as $probe_id => $probe ) {
	if ( 0 === strpos( (string) $probe_id, 'g2_' ) ) {
		$plugin->sources->delete( $probe_id );
	}
}

test_options_restore( $restore_options );
$GLOBALS['conexao_test_option_snapshots'] = array();

test_finish();
