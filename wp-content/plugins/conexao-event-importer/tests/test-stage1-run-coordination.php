<?php
/**
 * Stage 1 — Run All coordination, rate limiting, parent history, and Heritage
 * Week listing eligibility.
 *
 * All provider traffic is intercepted by pre_http_request. The suite writes
 * only its isolated event-source registry and the per-process history store;
 * it never creates or updates event posts.
 *
 * @package Conexao_Event_Importer
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

test_require_operator_store_isolation();

$stage1_sources_restore = test_options_isolate(
	array(
		Conexao_Event_Sources::OPTION_KEY,
		Conexao_Source_Cooldown::OPTION_KEY,
		Conexao_Source_Enumeration_Checkpoint::OPTION_KEY,
		Conexao_Import_Continuation::OPTION_KEY,
	)
);

register_shutdown_function(
	static function () use ( $stage1_sources_restore ) {
		remove_filter( 'pre_http_request', 'stage1_http_double', 10 );
		remove_filter( 'conexao_eventbrite_pacing', 'stage1_pacing_filter', 10 );
		Conexao_Eventbrite_Pacing::begin_run();
		test_options_restore( $stage1_sources_restore, true );
	}
);

/**
 * Create deterministic pacing with an injectable clock and sleeper.
 *
 * @param float $clock Current fake timestamp, passed by reference.
 * @return Conexao_Eventbrite_Pacing
 */
function stage1_pacing( &$clock ) {
	$pacing = new Conexao_Eventbrite_Pacing();
	$pacing->set_clock(
		static function () use ( &$clock ) {
			return $clock;
		}
	);
	$pacing->set_sleeper(
		static function ( $seconds ) use ( &$clock ) {
			$clock += (float) $seconds;
		}
	);
	return $pacing;
}

/**
 * Install URL-keyed response sequences for mocked HTTP.
 *
 * @param array $routes URL => response sequence.
 * @return void
 */
function stage1_install_http( array $routes ) {
	$GLOBALS['stage1_routes'] = $routes;
	$GLOBALS['stage1_route_index'] = array();
	$GLOBALS['stage1_requests'] = array();
	add_filter( 'pre_http_request', 'stage1_http_double', 10, 3 );
}

/**
 * Return one scripted response without contacting the network.
 *
 * @param mixed  $preempt Existing short-circuit value.
 * @param array  $args    HTTP request arguments.
 * @param string $url     Requested URL.
 * @return array|WP_Error
 */
function stage1_http_double( $preempt, $args, $url ) {
	$GLOBALS['stage1_requests'][] = $url;
	$routes = isset( $GLOBALS['stage1_routes'] ) ? $GLOBALS['stage1_routes'] : array();
	if ( ! isset( $routes[ $url ] ) ) {
		return new WP_Error( 'stage1_unexpected_url', 'Unexpected mocked URL: ' . $url );
	}
	$index = isset( $GLOBALS['stage1_route_index'][ $url ] ) ? (int) $GLOBALS['stage1_route_index'][ $url ] : 0;
	$item  = isset( $routes[ $url ][ $index ] ) ? $routes[ $url ][ $index ] : end( $routes[ $url ] );
	$GLOBALS['stage1_route_index'][ $url ] = $index + 1;
	if ( is_wp_error( $item ) ) {
		return $item;
	}
	return array(
		'response' => array( 'code' => isset( $item['code'] ) ? (int) $item['code'] : 200, 'message' => 'stage1' ),
		'headers'  => isset( $item['headers'] ) ? $item['headers'] : array(),
		'body'     => isset( $item['body'] ) ? $item['body'] : '',
		'cookies'  => array(),
	);
}

/**
 * Filter the Eventbrite handler to use the deterministic shared pacing.
 *
 * @return Conexao_Eventbrite_Pacing
 */
function stage1_pacing_filter() {
	return isset( $GLOBALS['stage1_filter_pacing'] ) && $GLOBALS['stage1_filter_pacing'] instanceof Conexao_Eventbrite_Pacing
		? $GLOBALS['stage1_filter_pacing']
		: Conexao_Eventbrite_Pacing::get_shared();
}

/**
 * Build a small valid Eventbrite listing page.
 *
 * @return string
 */
function stage1_eventbrite_listing() {
	$payload = array(
		'search_data' => array(
			'events' => array(
				'results' => array(),
				'pagination' => array( 'page_count' => 1, 'object_count' => 0, 'page_number' => 1 ),
			),
		),
	);
	return '<html><script>window.__SERVER_DATA__ = ' . wp_json_encode( $payload ) . ';</script></html>';
}

/**
 * Build a Heritage Week listing containing the date edge cases.
 *
 * @return string
 */
function stage1_heritage_listing() {
	// Compute fixture dates relative to the current site-local date so the
	// P2 date-gate assertions do not drift when the suite runs on a different
	// calendar day. A single cached base date ensures a run crossing local
	// midnight cannot split its "today"/"tomorrow" calculations.
	$stage1_fixture_today = new DateTimeImmutable( 'today', wp_timezone() );

	$stage1_fixture_date = static function ( int $offset_days ) use ( $stage1_fixture_today ): string {
		$modifier = ( $offset_days >= 0 ? '+' : '' ) . $offset_days . ' days';
		// Heritage Week listings use "DD Month YYYY" (e.g. "15 November 2026"),
		// which is the format the source's parse_dates_times() regex expects.
		return $stage1_fixture_today->modify( $modifier )->format( 'j F Y' );
	};

	$past_date    = $stage1_fixture_date( -1 );
	$today_date   = $stage1_fixture_date( 0 );
	$future_date  = $stage1_fixture_date( 1 );
	$conflict_end = $stage1_fixture_date( 4 );
	$conflict_beg = $stage1_fixture_date( 2 );

	$cards = array(
		array( 'past', 'Past event', $past_date, $past_date, 'https://example.invalid/hw/past' ),
		array( 'today', 'Today event', $today_date, $today_date, 'https://example.invalid/hw/today' ),
		array( 'future', 'Future event', $future_date, $future_date, 'https://example.invalid/hw/future' ),
		array( 'missing', 'Missing date event', '', '', 'https://example.invalid/hw/missing' ),
		array( 'conflict', 'Conflicting range event', $conflict_end, $conflict_beg, 'https://example.invalid/hw/conflict' ),
	);
	$html = '<html><head><title>2026 Events</title></head><body>';
	foreach ( $cards as $card ) {
		$date_line = '' === $card[2] ? '' : '<li>' . $card[2] . ' - ' . $card[3] . '</li>';
		$html .= '<article class="item-summary" data-id="' . esc_attr( $card[0] ) . '">'
			. '<h3 class="title">' . esc_html( $card[1] ) . '</h3>'
			. '<a class="link-block" href="' . esc_url( $card[4] ) . '">Details</a>'
			. '<ul class="list-details"><li>Heritage organiser</li>' . $date_line . '<li>County venue</li></ul>'
			. '</article>';
	}
	return $html . '</body></html>';
}

test_title( 'Stage 1: Event Importer coordination, parent history, and Heritage eligibility' );

test_section( 'P1: shared breaker, Retry-After formats, and invocation-window deferral' );

$clock = 1000.0;
$pacing = stage1_pacing( $clock );
Conexao_Eventbrite_Pacing::begin_run();
Conexao_Eventbrite_Pacing::set_shared( $pacing );
stage1_install_http(
	array(
		'https://example.invalid/eventbrite/a' => array( array( 'code' => 429, 'headers' => array( 'retry-after' => '7' ) ), array( 'code' => 200, 'body' => 'A' ) ),
		'https://example.invalid/eventbrite/b' => array( array( 'code' => 200, 'body' => stage1_eventbrite_listing() ) ),
	)
);
$client_a = new Conexao_Eventbrite_Client( 'stage1_eventbrite_a', $pacing );
assert_equals( 'A', $client_a->fetch_page( 'https://example.invalid/eventbrite/a', 1 ), 'P1: the first Eventbrite source can recover after one bounded 429 cooldown' );
$before_b = count( $GLOBALS['stage1_requests'] );
$source_b = new Conexao_Source_Eventbrite( array( 'id' => 'stage1_eventbrite_b', 'url' => 'https://example.invalid/eventbrite/b', 'county' => 'Cork', 'region_labels' => array( 'Cork' ) ) );
$caught_b = false;
try {
	$source_b->fetch_events();
} catch ( Conexao_Source_Fetch_Exception $e ) {
	$caught_b = 429 === $e->get_http_status();
}
assert_true( $caught_b, 'P1: the next Eventbrite source is marked rate-limited, not empty' );
assert_equals( $before_b, count( $GLOBALS['stage1_requests'] ), 'P1: an active shared breaker sends no repeated request for the next Eventbrite source' );
assert_true( ! empty( Conexao_Eventbrite_Pacing::get_run_state()['active'] ), 'P1: the breaker exposes structured active logical-run state' );

assert_equals( 7.0, Conexao_Eventbrite_Client::parse_retry_after( '7', 1000 ), 'P1: delta-seconds Retry-After is parsed' );
assert_equals( 7.0, Conexao_Eventbrite_Client::parse_retry_after( 'Thu, 01 Jan 1970 00:16:47 GMT', 1000 ), 'P1: IMF-fixdate Retry-After is parsed deterministically' );
assert_equals( null, Conexao_Eventbrite_Client::parse_retry_after( 'Wed, 01 Foo 2026 12:00:00 GMT', 1000 ), 'P1: malformed date-like Retry-After does not become a guessed delay' );
assert_equals( 'unsupported', Conexao_Eventbrite_Client::parse_retry_after_details( 'Wed, 01 Foo 2026 12:00:00 GMT', 1000 )['kind'], 'P1: unsupported plausible HTTP-date is classified separately' );
assert_equals( 20.0, $pacing->get_cooldown_seconds( 1, null ), 'P1: absent Retry-After uses the bounded fallback ladder' );

Conexao_Eventbrite_Pacing::begin_run();
$clock = 1000.0;
$deferred_pacing = stage1_pacing( $clock );
$deferred_pacing->set_wait_deadline( 1010.0 );
Conexao_Request_Budget::begin_run( 10 );
assert_equals( 0.0, $deferred_pacing->apply_rate_limit_cooldown( 1, 20 ), 'P1: a cooldown that cannot fit the window is not slept' );
assert_true( $deferred_pacing->was_wait_deferred(), 'P1: the oversized wait is explicitly deferred' );
assert_equals( 0, Conexao_Request_Budget::get_stats()['used'], 'P1: waiting/defer state does not consume request budget' );

test_section( 'P6: parent/child IDs, aggregate fields, statuses, and idempotence' );

Conexao_Import_History::clear();
$parent_group = 'stage1-parent-' . wp_generate_uuid4();
$parent_stats = array(
	'found' => 4,
	'new' => 1,
	'created' => 1,
	'updated' => 1,
	'unchanged' => 1,
	'skipped' => 1,
	'failed' => 2,
	'errors' => 2,
	'source_skipped_count' => 1,
	'event_skipped_count' => 1,
	'aggregate_error_count' => 2,
	'total_failed_source_count' => 21,
);
$failed_sources = array();
for ( $i = 1; $i <= 21; $i++ ) {
	$failed_sources[] = 'stage1_failed_' . $i;
}
Conexao_Import_History::record_parent( $parent_group, $parent_stats, 'rate_limited', 'rate limited with mixed source failure', array( 'failed_sources' => $failed_sources ) );
Conexao_Import_History::record( 'stage1_child_a', array( 'found' => 2, 'new' => 1, 'errors' => 1 ), 'failed', 'child failed', array( 'run_id' => $parent_group, 'source_run_id' => 'stage1-child-a', 'parent_run_group_id' => $parent_group ) );
Conexao_Import_History::record( 'stage1_child_b', array( 'found' => 2, 'new' => 0, 'errors' => 0 ), 'rate_limited', 'child deferred', array( 'run_id' => $parent_group, 'source_run_id' => 'stage1-child-b', 'parent_run_group_id' => $parent_group, 'source_skipped' => true ) );
$parent_rows = Conexao_Import_History::get_for_parent( $parent_group );
assert_equals( 3, count( $parent_rows ), 'P6: one parent and two child history rows share the same parent group' );
$parent_row = null;
$child_a_row = null;
foreach ( $parent_rows as $row ) {
	if ( 'parent' === $row['record_type'] ) {
		$parent_row = $row;
	} elseif ( 'stage1-child-a' === (string) $row['source_run_id'] ) {
		$child_a_row = $row;
	}
}
assert_equals( 'stage1-child-a', $child_a_row['source_run_id'], 'P6: child source-run ID is retained separately from the parent group' );
assert_equals( 21, $parent_row['total_failed_source_count'], 'P6: failed-source disclosure is not silently capped at twenty' );
assert_equals( 'rate_limited', $parent_row['status'], 'P6: rate_limited is an explicit parent status' );
assert_true( ! empty( $parent_row['failed_sources'][20] ), 'P6: the final failed source remains visible in parent history' );
Conexao_Import_History::record_parent( $parent_group, $parent_stats, 'rate_limited', 'rate limited with mixed source failure', array( 'failed_sources' => $failed_sources ) );
assert_equals( 3, count( Conexao_Import_History::get_for_parent( $parent_group ) ), 'P6: replacing a parent is idempotent and does not duplicate final aggregation' );

$source_configs = array(
	'stage1_eventbrite_a' => array( 'id' => 'stage1_eventbrite_a', 'name' => 'Stage 1 A', 'type' => 'eventbrite', 'status' => 'active', 'url' => 'https://example.invalid/run-all/a', 'county' => 'Cork', 'region_labels' => array( 'Cork' ) ),
	'stage1_eventbrite_b' => array( 'id' => 'stage1_eventbrite_b', 'name' => 'Stage 1 B', 'type' => 'eventbrite', 'status' => 'active', 'url' => 'https://example.invalid/run-all/b', 'county' => 'Cork', 'region_labels' => array( 'Cork' ) ),
);
update_option( Conexao_Event_Sources::OPTION_KEY, $source_configs, false );
Conexao_Import_History::clear();
stage1_install_http( array( 'https://example.invalid/run-all/a' => array( array( 'code' => 429 ) ) ) );
$run_all_clock = 1000.0;
$GLOBALS['stage1_filter_pacing'] = stage1_pacing( $run_all_clock );
add_filter( 'conexao_eventbrite_pacing', 'stage1_pacing_filter', 10 );
$run_all = Conexao_Event_Importer::instance()->importer->run_all(
	array(
		'dry_run' => true,
		'source_ids' => array( 'stage1_eventbrite_a', 'stage1_eventbrite_b' ),
	)
);
remove_filter( 'conexao_eventbrite_pacing', 'stage1_pacing_filter', 10 );
assert_equals( 'rate_limited', $run_all['status'], 'P6: a Run All with an Eventbrite breaker is rate_limited, not completed' );
assert_equals( 1, $run_all['source_skipped_count'], 'P6: skipped source count is distinct from skipped event count' );
assert_true( ! empty( $run_all['parent_run_group_id'] ), 'P6: Run All returns a stable parent run-group ID' );
$run_all_history = Conexao_Import_History::get_for_parent( $run_all['parent_run_group_id'] );
assert_equals( 3, count( $run_all_history ), 'P6: Run All history contains one parent and one child row per attempted source' );
$run_all_parent = null;
$run_all_children = array();
foreach ( $run_all_history as $history_row ) {
	if ( 'parent' === $history_row['record_type'] ) {
		$run_all_parent = $history_row;
	} else {
		$run_all_children[] = $history_row;
	}
}
assert_true( null !== $run_all_parent && $run_all_parent['parent_run_group_id'] === $run_all_children[0]['parent_run_group_id'], 'P6: the parent group is propagated to child history' );
assert_true( ! empty( $run_all_parent['rate_limit_state']['active'] ), 'P6: parent history retains structured breaker state' );
assert_true( $run_all_children[0]['source_run_id'] !== $run_all_children[1]['source_run_id'], 'P6: child source-run IDs are unique' );

test_section( 'P2: Heritage listing date gate runs before detail enrichment' );

$listing_url = 'https://www.heritageweek.ie/event-listings?q=&where%5B%5D=laois';
stage1_install_http(
	array(
		$listing_url => array( array( 'code' => 200, 'body' => stage1_heritage_listing() ) ),
		'https://example.invalid/hw/today' => array( array( 'code' => 200, 'body' => '' ) ),
		'https://example.invalid/hw/future' => array( new WP_Error( 'stage1_detail_failure', 'detail failed' ) ),
		'https://example.invalid/hw/missing' => array( array( 'code' => 200, 'body' => '<html><body>empty detail</body></html>' ) ),
		'https://example.invalid/hw/conflict' => array( array( 'code' => 200, 'body' => '<html><body>conflict detail</body></html>' ) ),
	)
);
Conexao_Request_Reuse::begin_run();
Conexao_Request_Budget::begin_run( 20 );
$heritage = new Conexao_Source_Heritage_Week( array( 'id' => 'stage1_heritage', 'county' => 'Laois', 'hw_where' => array( 'laois' ) ) );
$heritage_events = $heritage->fetch_events();
$detail_requests = array_values( array_filter( $GLOBALS['stage1_requests'], static function ( $url ) { return false !== strpos( $url, '/hw/' ); } ) );
$titles = array_column( $heritage_events, 'title' );
assert_equals( 4, count( $heritage_events ), 'P2: current, future, missing-date, and ambiguous-range listings remain eligible' );
assert_true( ! in_array( 'Past event', $titles, true ), 'P2: definitely past listing is skipped before enrichment' );
assert_equals( 4, count( $detail_requests ), 'P2: detail fetch count excludes the definitely past event' );
assert_true( ! in_array( 'https://example.invalid/hw/past', $detail_requests, true ), 'P2: no detail request is sent for a definitely past event' );
$by_title = array();
foreach ( $heritage_events as $event ) {
	$by_title[ $event['title'] ] = $event;
}
assert_equals( 'empty', $by_title['Today event']['detail_fetch_status'], 'P2: successful empty detail response remains distinguishable' );
assert_equals( 'failed', $by_title['Future event']['detail_fetch_status'], 'P2: detail transport failure remains distinguishable from empty detail' );
assert_set( $by_title['Missing date event'], 'detail_fetch_status', 'P2: missing listing date is retained for detail enrichment' );
assert_set( $by_title['Conflicting range event'], 'detail_fetch_status', 'P2: end-before-start conflict is retained for detail enrichment' );

test_finish( 'stage1-run-coordination' );
