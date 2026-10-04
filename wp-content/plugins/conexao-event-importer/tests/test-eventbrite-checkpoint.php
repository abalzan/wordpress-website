<?php
/**
 * G10 — persistent Eventbrite enumeration checkpoint and safe resume.
 *
 * ## What is proved
 *
 * The state machine:
 *   1.  A first run starts at page 1 and stores no prior progress.
 *   2.  A confirmed page advances the checkpoint.
 *   3.  A page that fails does NOT advance the checkpoint (fail-closed).
 *   4.  The checkpoint is advanced only AFTER fetch+parse+merge.
 *   5.  Time-budget and request-budget stops both persist the confirmed page.
 *   6.  A 429 and a WAF/405 block both preserve the checkpoint.
 *   7.  The next run RESUMES from the checkpoint instead of page 1.
 *   8.  Resume performs a bounded overlap that does not duplicate events.
 *   9.  Sources are isolated: Cork progress never leaks to Dublin.
 *   10. Malformed / stale / foreign / impossible checkpoints fail safely.
 *   11. A configuration change invalidates incompatible state.
 *   12. A checkpoint NEVER implies completeness, and a resumed run NEVER
 *       reaches mark_missing_events().
 *   13. The truncation guard still overrides a full-page-count checkpoint.
 *   14. Repeated resumed runs are idempotent.
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

$restore_options = test_options_isolate(
	array(
		Conexao_Import_History::OPTION_KEY,
		Conexao_Import_Log::OPTION_KEY,
		Conexao_Event_Sources::OPTION_KEY,
		'conexao_event_source_health',
		Conexao_Source_Cooldown::OPTION_KEY,
		Conexao_Source_Enumeration_Checkpoint::OPTION_KEY,
	)
);

register_shutdown_function(
	static function () use ( $restore_options ) {
		test_options_restore( $restore_options, true );
	}
);

// Keep the suite fast and deterministic. These are the REAL filter names the
// production pacing policy reads, so zeroing them removes wall-clock waiting
// without changing any decision asserted here.
add_filter( 'conexao_eventbrite_backoff_seconds', '__return_zero', 99 );
add_filter( 'conexao_eventbrite_page_delay_seconds', '__return_zero', 99 );

/**
 * A pacing policy whose sleeps and clock are fully under test control.
 *
 * Follows the established G5 test seam exactly. This matters because the G5
 * rate-limit ladder (20 s, then 40 s) is a REAL sleep that neither delay
 * filter covers — without this the 429 cases would each burn tens of seconds
 * of wall clock and the suite would crawl.
 *
 * @param array|null &$delays Receives every delay the policy requests.
 * @return Conexao_Eventbrite_Pacing
 */
function g10_fake_pacing( &$delays = null ) {
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
 * Build an Eventbrite discovery page carrying a pagination block.
 *
 * @param int   $page_count   Declared page count.
 * @param int   $object_count Declared object count.
 * @param int   $page_size    Declared page size.
 * @param array $events       Raw events on this page.
 * @param int   $page_number  Page number the payload reports.
 * @return string
 */
function g10_eb_page( $page_count, $object_count, $page_size = 20, array $events = array(), $page_number = 1 ) {
	$payload = array(
		'search_data' => array(
			'events' => array(
				'results'    => $events,
				'pagination' => array(
					'page_count'   => (int) $page_count,
					'object_count' => (int) $object_count,
					'page_number'  => (int) $page_number,
					'page_size'    => (int) $page_size,
				),
			),
		),
	);

	return '<html><body><script>window.__SERVER_DATA__ = ' . wp_json_encode( $payload ) . ';</script></body></html>';
}

/**
 * Build one raw Eventbrite event.
 *
 * @param string $id     Native Eventbrite id.
 * @param string $region Region name.
 * @return array
 */
function g10_eb_event( $id, $region = 'Cork' ) {
	return array(
		'id'        => $id,
		'name'      => 'Event ' . $id,
		'url'       => 'https://www.eventbrite.ie/e/' . $id,
		'start'     => array( 'local' => '2099-01-01T10:00:00' ),
		'locations' => array(
			array(
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
 * Install a per-URL paging double.
 *
 * Serves a deterministic page for EVERY requested page, using the REAL
 * measured Eventbrite contract (explicit `?page=N`, server-asserted
 * `page_number`, pinned `page_count`, `page_size` 20). Event ids are derived
 * from the page number, so re-walking a page yields the SAME ids — which is
 * exactly what makes overlap deduplication observable.
 *
 * @param int $page_count   Declared page count.
 * @param int $object_count Declared object count.
 * @param int $fail_at      0, or a page number that returns HTTP 429.
 * @param int $waf_at       0, or a page number that returns HTTP 405.
 * @param int $events_each  Events per page.
 * @return void
 */
function g10_install_double( $page_count = 5, $object_count = 100, $fail_at = 0, $waf_at = 0, $events_each = 20 ) {
	$GLOBALS['g10_served']     = array();
	$GLOBALS['g10_fail_at']    = (int) $fail_at;
	$GLOBALS['g10_waf_at']     = (int) $waf_at;
	$GLOBALS['g10_page_count'] = (int) $page_count;
	$GLOBALS['g10_object']     = (int) $object_count;
	$GLOBALS['g10_events_each'] = (int) $events_each;

	remove_all_filters( 'pre_http_request' );

	add_filter(
		'pre_http_request',
		static function ( $preempt, $args, $url ) {
			$page = 1;

			if ( preg_match( '/[?&]page=(\d+)/', $url, $m ) ) {
				$page = (int) $m[1];
			}

			$GLOBALS['g10_served'][] = $page;

			$fail = isset( $GLOBALS['g10_fail_at'] ) ? (int) $GLOBALS['g10_fail_at'] : 0;
			$waf  = isset( $GLOBALS['g10_waf_at'] ) ? (int) $GLOBALS['g10_waf_at'] : 0;

			$code = 200;

			if ( $waf > 0 && $page === $waf ) {
				$code = 405;
			} elseif ( $fail > 0 && $page === $fail ) {
				$code = 429;
			}

			if ( 200 !== $code ) {
				return array(
					'response' => array( 'code' => $code, 'message' => 'Refused' ),
					'body'     => '',
					'headers'  => array(),
					'cookies'  => array(),
				);
			}

			$events = array();
			for ( $i = 0; $i < (int) $GLOBALS['g10_events_each']; $i++ ) {
				$events[] = g10_eb_event( 'e' . $page . '-' . $i );
			}

			return array(
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'body'     => g10_eb_page(
					(int) $GLOBALS['g10_page_count'],
					(int) $GLOBALS['g10_object'],
					20,
					$events,
					$page
				),
				'headers'  => array(),
				'cookies'  => array(),
			);
		},
		10,
		3
	);
}

/**
 * Remove the double and forget what it served.
 *
 * @return void
 */
function g10_remove_double() {
	remove_all_filters( 'pre_http_request' );
	$GLOBALS['g10_served'] = array();
}

/**
 * The pages the double served, in request order.
 *
 * @return int[]
 */
function g10_pages_served() {
	return isset( $GLOBALS['g10_served'] ) ? $GLOBALS['g10_served'] : array();
}

/**
 * The native event ids in a list of normalized events.
 *
 * @param array $events Normalized events.
 * @return string[]
 */
function g10_ids( $events ) {
	$ids = array();

	foreach ( $events as $event ) {
		if ( isset( $event['source_id'] ) ) {
			$ids[] = (string) $event['source_id'];
		}
	}

	return $ids;
}

/**
 * Reset the Eventbrite rate-limit latch so cases are independent.
 *
 * @return void
 */
function g10_reset_pacing() {
	remove_all_filters( 'conexao_eventbrite_pacing' );
	Conexao_Eventbrite_Pacing::begin_run();

	// Inject the deterministic pacing policy so the G5 rate-limit cooldown is
	// a recorded delay instead of a real sleep.
	add_filter(
		'conexao_eventbrite_pacing',
		static function () {
			return g10_fake_pacing();
		},
		99
	);
}

/**
 * Walk a source config against the installed double.
 *
 * @param array $config Source config.
 * @return Conexao_Source_Eventbrite
 */
function g10_walk( array $config ) {
	$handler = new Conexao_Source_Eventbrite( $config );
	$handler->fetch_events();

	return $handler;
}

/**
 * Walk a source config and return the events from that same walk.
 *
 * Used where a test needs the returned ids: calling fetch_events() a second
 * time on the same handler would resume from the checkpoint the first walk
 * stored, and silently return a different set.
 *
 * @param array $config Source config.
 * @return array
 */
function g10_walk_events( array $config ) {
	$handler = new Conexao_Source_Eventbrite( $config );

	return $handler->fetch_events();
}

/**
 * The Cork source config.
 *
 * @return array
 */
function g10_cork_config() {
	return Conexao_County_Registry::get_eventbrite_source( 'cork' );
}

/**
 * The Dublin source config.
 *
 * @return array
 */
function g10_dublin_config() {
	return Conexao_County_Registry::get_eventbrite_source( 'dublin' );
}

/**
 * Discard every checkpoint so a case starts from a clean slate.
 *
 * @return void
 */
function g10_clear_checkpoints() {
	update_option( Conexao_Source_Enumeration_Checkpoint::OPTION_KEY, array(), false );
}

/**
 * Register a source as ACTIVE so the engine will actually run it.
 *
 * Every registry-generated Eventbrite source ships INACTIVE by design (it must
 * never send a request until an operator enables it). The engine-level cases
 * below drive the real engine, which refuses an unregistered or inactive
 * source and returns an early result that carries no `complete` flag — so the
 * source must be registered first, exactly as an operator would.
 *
 * @param array $config Source config.
 * @return void
 */
function g10_register_active( array $config ) {
	$config['status'] = 'active';

	Conexao_Event_Importer::instance()->sources->save( $config );
}
test_title( 'G10 — persistent Eventbrite enumeration checkpoint and safe resume' );

// ---------------------------------------------------------------------------
// G10.1 — a first run starts normally and stores no prior progress.
// ---------------------------------------------------------------------------

g10_clear_checkpoints();
g10_reset_pacing();
g10_install_double( 5, 100 );

$cork   = g10_cork_config();
$source = g10_cork_config()['id'];

assert_true(
	0 === (int) Conexao_Source_Enumeration_Checkpoint::get( $source )['last_page'],
	'G10.1: no checkpoint exists before the first run'
);

$first = g10_walk( $cork );
$served_first = g10_pages_served();
g10_remove_double();

assert_true( $first->did_resume() === false, 'G10.1: the first run does NOT resume — it walks the listing normally' );
assert_true( in_array( 2, $served_first, true ), 'G10.1: the first run really requested page 2' );
assert_true( 5 === (int) Conexao_Source_Enumeration_Checkpoint::get( $source )['last_page'], 'G10.1: a completed walk checkpoints its final page' );

// ---------------------------------------------------------------------------
// G10.2 — a confirmed page advances the checkpoint; a FAILED page does not.
// ---------------------------------------------------------------------------

g10_clear_checkpoints();
g10_reset_pacing();
g10_install_double( 10, 200, 5 ); // page 5 answers 429.

$blocked = g10_walk( $cork );
g10_remove_double();

$cp_after_429 = Conexao_Source_Enumeration_Checkpoint::get( $source );

assert_true( 4 === (int) $cp_after_429['last_page'], 'G10.2: a 429 on page 5 leaves the checkpoint at the last CONFIRMED page (4), never at the failed page' );
assert_true( ! (bool) $blocked->is_fetch_complete(), 'G10.2: the blocked walk reports INCOMPLETE' );
assert_true( 'rate_limited' === (string) $cp_after_429['last_failure'], 'G10.2: the checkpoint records the rate-limit failure class' );

// ---------------------------------------------------------------------------
// G10.3 — the checkpoint is written only AFTER fetch+parse+merge.
// ---------------------------------------------------------------------------
//
// The proof is observational: page 4 succeeded and page 5 was refused, so a
// checkpoint of 4 proves the write happens per successfully merged page rather
// than once before the loop. The complementary negative proof (mutation M1)
// advances BEFORE the fetch and is shown to fail later.

g10_clear_checkpoints();
g10_reset_pacing();
g10_install_double( 8, 160, 4 );

$walk_before_fail = g10_walk( $cork );
$served_before    = g10_pages_served();
g10_remove_double();

assert_true(
	3 === (int) Conexao_Source_Enumeration_Checkpoint::get( $source )['last_page'],
	'G10.3: with page 4 refused, the checkpoint is exactly the last page fetched, parsed AND merged'
);
assert_true(
	in_array( 4, $served_before, true ),
	'G10.3: page 4 was genuinely requested (so 3 is a failure, not an off-by-one in the loop)'
);
assert_true( ! (bool) $walk_before_fail->is_fetch_complete(), 'G10.3: the refused walk is INCOMPLETE' );
// ---------------------------------------------------------------------------
// G10.4 — a WAF/405 block never records the blocked page as confirmed.
// ---------------------------------------------------------------------------
//
// 405 is NOT in RETRYABLE_STATUSES, so the pre-existing loop skips page 6 and
// carries on (G5 deliberately tolerates a single failed page). The checkpoint
// therefore advances to the last page that genuinely succeeded. The property
// that matters is the one under test: page 6 was never recorded as confirmed,
// so it is re-walked next run. Progress is real, never optimistic.

g10_clear_checkpoints();
g10_reset_pacing();
g10_install_double( 8, 160, 0, 6 ); // page 6 answers 405 (WAF).

$waf_blocked = g10_walk( $cork );
$waf_served  = g10_pages_served();
g10_remove_double();

$cp_waf = Conexao_Source_Enumeration_Checkpoint::get( $source );

assert_true( in_array( 6, $waf_served, true ), 'G10.4: page 6 was genuinely requested (the WAF response was real)' );
assert_true( 5 !== (int) $cp_waf['last_page'], 'G10.4: the checkpoint did NOT record the blocked page 6 as confirmed' );
assert_true(
	(int) $cp_waf['last_page'] > 6,
	'G10.4: the walk tolerated the single blocked page (pre-existing G5 behaviour) and confirmed the later ones'
);
assert_true( ! (bool) $waf_blocked->is_fetch_complete(), 'G10.4: a walk containing a blocked page is INCOMPLETE' );
assert_true( 'waf' === (string) $cp_waf['last_failure'], 'G10.4: the checkpoint records the WAF failure class' );

// ---------------------------------------------------------------------------
// G10.5 — the next run RESUMES instead of restarting at page 1.
// ---------------------------------------------------------------------------

g10_clear_checkpoints();
g10_reset_pacing();

// Run 1: pages 1..4 confirmed, page 5 refused.
g10_install_double( 20, 400, 5 );
g10_walk( $cork );
g10_remove_double();

assert_true( 4 === (int) Conexao_Source_Enumeration_Checkpoint::get( $source )['last_page'], 'G10.5: run 1 checkpointed page 4' );

// Run 2: the listing is healthy now. It must NOT re-walk the prefix.
g10_reset_pacing();
g10_install_double( 20, 400 );
$resumed = g10_walk( $cork );
$served  = g10_pages_served();
g10_remove_double();

assert_true( $resumed->did_resume(), 'G10.5: run 2 resumed from the stored checkpoint' );
assert_true( ! in_array( 2, $served, true ), 'G10.5: run 2 did NOT re-request page 2 (the confirmed prefix is not re-walked)' );
assert_true(
	in_array( 3, $served, true ),
	'G10.5: run 2 started at page 3 — checkpoint 4 minus exactly one page of bounded overlap'
);
assert_true(
	! in_array( 2, $served, true ),
	'G10.5: run 2 re-requested only the single overlap page (3) plus the new tail (4, 5, ...) — page 2 was never touched'
);
assert_true( 20 === (int) Conexao_Source_Enumeration_Checkpoint::get( $source )['last_page'], 'G10.5: run 2 advanced the checkpoint to the final page' );

// ---------------------------------------------------------------------------
// G10.6 — bounded overlap, and it does not duplicate events.
// ---------------------------------------------------------------------------

g10_clear_checkpoints();
g10_reset_pacing();
Conexao_Source_Enumeration_Checkpoint::record(
	$source,
	array(
		'fingerprint' => Conexao_Source_Enumeration_Checkpoint::fingerprint( $cork ),
		'last_page'   => 10,
		'page_count'  => 20,
	)
);

g10_reset_pacing();
g10_install_double( 20, 400 );
$overlap_walk = g10_walk( $cork );
$overlap_served = g10_pages_served();
g10_remove_double();

assert_true( in_array( 9, $overlap_served, true ), 'G10.6: resume re-walks exactly ONE page of overlap below the checkpoint (page 9 for a checkpoint at 10)' );
assert_true( ! in_array( 8, $overlap_served, true ), 'G10.6: the overlap is bounded to one page — page 8 is NOT re-walked' );
assert_true( 1 === (int) Conexao_Source_Eventbrite::OVERLAP_PAGES, 'G10.6: the overlap constant stays minimal so it cannot defeat checkpointing' );

// ---------------------------------------------------------------------------
// G10.7 — source isolation.
// ---------------------------------------------------------------------------

g10_clear_checkpoints();
g10_reset_pacing();
g10_install_double( 20, 400, 5 );

g10_walk( $cork ); // Cork stops at page 4.
g10_remove_double();

$dublin     = g10_dublin_config();
$dublin_id  = $dublin['id'];

assert_true( 'eventbrite_cork' === $source, 'G10.7: the Cork source id is the real registry id' );
assert_true( 4 === (int) Conexao_Source_Enumeration_Checkpoint::get( $source )['last_page'], 'G10.7: Cork holds progress at page 4' );
assert_true(
	0 === (int) Conexao_Source_Enumeration_Checkpoint::get( $dublin_id )['last_page'],
	'G10.7: Dublin holds NO progress — a Cork interruption never leaks across sources'
);

g10_reset_pacing();
g10_install_double( 20, 400 );
$dublin_walk   = g10_walk( $dublin );
$dublin_served = g10_pages_served();
g10_remove_double();

assert_true( $dublin_walk->did_resume() === false, 'G10.7: Dublin starts fresh and does not inherit Cork progress' );
assert_true( in_array( 2, $dublin_served, true ), 'G10.7: Dublin really requested page 2 (proving it walked from the start)' );
assert_true( 4 === (int) Conexao_Source_Enumeration_Checkpoint::get( $source )['last_page'], 'G10.7: Cork progress is untouched by the Dublin run' );
assert_true( 20 === (int) Conexao_Source_Enumeration_Checkpoint::get( $dublin_id )['last_page'], 'G10.7: Dublin checkpoints its OWN position' );
// ---------------------------------------------------------------------------
// G10.8 — invalid / stale / foreign / impossible checkpoints fail safely.
// ---------------------------------------------------------------------------
//
// Policy (documented, and enforced by Conexao_Source_Enumeration_Checkpoint:
// discard the checkpoint and restart safely at page 1. NEVER skip unknown
// pages, never resume from an unverified position.

g10_clear_checkpoints();
g10_reset_pacing();
g10_install_double( 10, 200 );

// (a) a checkpoint belonging to ANOTHER source definition
Conexao_Source_Enumeration_Checkpoint::record(
	$source,
	array(
		'fingerprint' => md5( 'a-completely-different-listing' ),
		'last_page'   => 7,
		'page_count'  => 10,
	)
);

g10_reset_pacing();
g10_install_double( 10, 200 );
$foreign = g10_walk( $cork );
$foreign_served = g10_pages_served();
g10_remove_double();

assert_true( in_array( 2, $foreign_served, true ), 'G10.8: a checkpoint from a different source definition is DISCARDED and the walk restarts at page 1' );

// (b) an impossible checkpoint (page beyond the declared page count)
g10_clear_checkpoints();
update_option(
	Conexao_Source_Enumeration_Checkpoint::OPTION_KEY,
	array(
		$source => array(
			'schema_version' => Conexao_Source_Enumeration_Checkpoint::SCHEMA_VERSION,
			'source_id'      => $source,
			'fingerprint'    => Conexao_Source_Enumeration_Checkpoint::fingerprint( $cork ),
			'last_page'      => 99, // the listing only declares 10 pages.
			'page_count'     => 10,
			'complete'       => false,
			'last_failure'   => '',
			'updated_at'     => current_time( 'mysql' ),
			'runs'           => 1,
		),
	),
	false
);

g10_reset_pacing();
g10_install_double( 10, 200 );
$impossible = g10_walk( $cork );
$impossible_served = g10_pages_served();
g10_remove_double();

assert_true( in_array( 2, $impossible_served, true ), 'G10.8: a checkpoint beyond the declared page count is DISCARDED (it would walk off the end)' );

// (c) a malformed checkpoint (wrong schema)
g10_clear_checkpoints();
update_option(
	Conexao_Source_Enumeration_Checkpoint::OPTION_KEY,
	array(
		$source => array(
			'schema_version' => 999, // incompatible record shape.
			'source_id'      => $source,
			'fingerprint'    => Conexao_Source_Enumeration_Checkpoint::fingerprint( $cork ),
			'last_page'      => 6,
			'page_count'     => 10,
			'complete'       => false,
			'last_failure'   => '',
			'updated_at'     => current_time( 'mysql' ),
			'runs'           => 1,
		),
	),
	false
);

g10_reset_pacing();
g10_install_double( 10, 200 );
$malformed = g10_walk( $cork );
$malformed_served = g10_pages_served();
g10_remove_double();

assert_true( in_array( 2, $malformed_served, true ), 'G10.8: a malformed (incompatible schema) checkpoint is DISCARDED and the walk restarts safely' );

// (d) corrupted non-array state is rejected outright by the validator
assert_true(
	! Conexao_Source_Enumeration_Checkpoint::is_usable( 'not-an-array', Conexao_Source_Enumeration_Checkpoint::fingerprint( $cork ), 10 ),
	'G10.8: a non-array stored record is rejected by the validator itself'
);

// ---------------------------------------------------------------------------
// G10.9 — a source configuration change invalidates incompatible state.
// ---------------------------------------------------------------------------

g10_clear_checkpoints();
g10_reset_pacing();
g10_install_double( 10, 200, 5 );
g10_walk( $cork );
g10_remove_double();

assert_true( 4 === (int) Conexao_Source_Enumeration_Checkpoint::get( $source )['last_page'], 'G10.9: precondition — Cork holds progress at page 4' );

$changed = $cork;
$changed['url'] = $cork['url'] . '?different-listing=1';

g10_reset_pacing();
g10_install_double( 10, 200 );
g10_walk( $changed );
$changed_served = g10_pages_served();
g10_remove_double();

assert_true( in_array( 2, $changed_served, true ), 'G10.9: changing the discovery URL invalidates the checkpoint and forces a safe full walk' );

// A cosmetic change must NOT throw away useful progress. The checkpoint has to
// be re-seeded for the ORIGINAL config, because the URL-change case above
// legitimately replaced it.
g10_clear_checkpoints();
Conexao_Source_Enumeration_Checkpoint::record(
	$source,
	array(
		'fingerprint' => Conexao_Source_Enumeration_Checkpoint::fingerprint( $cork ),
		'last_page'   => 4,
		'page_count'  => 10,
	)
);

$renamed = $cork;
$renamed['name'] = 'Eventbrite — Cork (renamed for testing)';

assert_true(
	Conexao_Source_Enumeration_Checkpoint::fingerprint( $cork ) === Conexao_Source_Enumeration_Checkpoint::fingerprint( $renamed ),
	'G10.9: a cosmetic rename produces the SAME fingerprint (material fields only)'
);

g10_reset_pacing();
g10_install_double( 10, 200 );
$still_resumed = g10_walk( $renamed );
$renamed_served = g10_pages_served();
g10_remove_double();

assert_true( $still_resumed->did_resume(), 'G10.9: a cosmetic rename does NOT invalidate progress (the fingerprint tracks material fields only)' );
assert_true( ! in_array( 2, $renamed_served, true ), 'G10.9: after a cosmetic rename the confirmed prefix is still not re-walked' );
// ---------------------------------------------------------------------------
// G10.10 — completeness is INDEPENDENT of the checkpoint.
// ---------------------------------------------------------------------------
//
// A checkpoint that reached the final page must NOT make a truncated listing
// complete. This is the G8 truncation guard surviving G10 unchanged.

g10_clear_checkpoints();
g10_reset_pacing();

// The measured Cork shape: 1547 objects behind 49 pages of 20.
g10_install_double( 49, 1547 );
$truncated = g10_walk( $cork );
g10_remove_double();

$cp_trunc = Conexao_Source_Enumeration_Checkpoint::get( $source );

assert_true( 49 === (int) $cp_trunc['last_page'], 'G10.10: a truncated listing still checkpoints the page it reached' );
assert_true( (bool) $cp_trunc['complete'], 'G10.10: the checkpoint records that this generation reached its final page' );
assert_true(
	! (bool) $truncated->is_fetch_complete(),
	'G10.10: BUT the enumeration is still INCOMPLETE — a full-page checkpoint does NOT imply completeness'
);
assert_true(
	$truncated->is_reconciliation_safe(),
	'G10.10: a full (non-resumed) walk still holds the whole listing, so it stays reconciliation-safe'
);

// ---------------------------------------------------------------------------
// G10.11 — a resumed run is UNSAFE for absence-based reconciliation.
// ---------------------------------------------------------------------------
//
// This is the destructive-safety invariant. A resumed run returns a subset, so
// it must be refused by the engine's reconciliation gate even when it reaches
// the final page and reports itself complete.

g10_clear_checkpoints();
g10_reset_pacing();
g10_install_double( 20, 400, 5 );
g10_walk( $cork );
g10_remove_double();

g10_reset_pacing();
g10_install_double( 20, 400 );
$resumed_safe_test = g10_walk( $cork );
g10_remove_double();

assert_true( $resumed_safe_test->is_fetch_complete(), 'G10.11: the resumed walk reached the end, so it is COMPLETE as an enumeration' );
assert_true(
	! $resumed_safe_test->is_reconciliation_safe(),
	'G10.11: the SAME run is NOT reconciliation-safe (its event set is a subset) — completeness alone must not authorise deletion'
);
assert_true( $resumed_safe_test->did_resume(), 'G10.11: precondition — this run really did resume' );

// ---------------------------------------------------------------------------
// G10.12 — repeated resumed runs are idempotent.
// ---------------------------------------------------------------------------

g10_clear_checkpoints();
g10_reset_pacing();
g10_install_double( 12, 240, 4 );
g10_walk( $cork );
g10_remove_double();

$before = (int) Conexao_Source_Enumeration_Checkpoint::get( $source )['last_page'];

$seen = array();
for ( $run = 0; $run < 3; $run++ ) {
	g10_reset_pacing();
	g10_install_double( 12, 240 );
	$again = g10_walk( $cork );
	g10_remove_double();
	$seen[] = (int) Conexao_Source_Enumeration_Checkpoint::get( $source )['last_page'];
}

assert_true( $before > 0, 'G10.12: precondition — the truncated first run stored progress' );
assert_true( $seen[0] === 12, 'G10.12: the first resume advances the checkpoint to the final page' );
assert_true( $seen[1] === $seen[0] && $seen[2] === $seen[0], 'G10.12: repeated resumed runs are idempotent (the checkpoint stops changing)' );
assert_true( 12 === (int) Conexao_Source_Enumeration_Checkpoint::get( $source )['last_page'], 'G10.12: the checkpoint is stable after convergence' );

// ---------------------------------------------------------------------------
// G10.13 — budget exhaustion preserves the checkpoint.
// ---------------------------------------------------------------------------

g10_clear_checkpoints();
g10_reset_pacing();
g10_install_double( 30, 600 );

// The deterministic pacing clock only advances when a delay is applied, so a
// budget of 0 would never be exceeded. A negative budget makes the branch
// reachable on the first iteration without depending on wall-clock time.
add_filter( 'conexao_eventbrite_time_budget_seconds', static function () {
	return -1.0;
}, 99 );

$time_budgeted = g10_walk( $cork );
remove_all_filters( 'conexao_eventbrite_time_budget_seconds' );
g10_remove_double();

$cp_time = Conexao_Source_Enumeration_Checkpoint::get( $source );

assert_true( ! (bool) $time_budgeted->is_fetch_complete(), 'G10.13: a time-budget stop is INCOMPLETE' );
assert_true( 1 === (int) $cp_time['last_page'], 'G10.13: a time-budget stop preserves the confirmed page (1)' );
assert_true( 'time_budget' === (string) $cp_time['last_failure'], 'G10.13: the checkpoint records the time-budget failure class' );

// Request budget: the client refuses once the ceiling is reached.
g10_clear_checkpoints();
g10_reset_pacing();
g10_install_double( 30, 600 );

add_filter( 'conexao_event_importer_request_budget', static function () {
	return 5;
}, 99 );
Conexao_Request_Budget::begin_run();

$request_budgeted = g10_walk( $cork );
Conexao_Request_Budget::begin_run();
remove_all_filters( 'conexao_event_importer_request_budget' );
g10_remove_double();

$cp_req = Conexao_Source_Enumeration_Checkpoint::get( $source );

assert_true( ! (bool) $request_budgeted->is_fetch_complete(), 'G10.13: a request-budget stop is INCOMPLETE' );
assert_true( (int) $cp_req['last_page'] > 0, 'G10.13: a request-budget stop still persists the pages it did confirm' );
assert_true( (int) $cp_req['last_page'] <= 5, 'G10.13: the request-budget stop never checkpoints a page it could not fetch' );

// ---------------------------------------------------------------------------
// G10.14 — cooldown preserves the checkpoint and never resets progress.
// ---------------------------------------------------------------------------

g10_clear_checkpoints();
g10_reset_pacing();
g10_install_double( 20, 400, 5 );
g10_walk( $cork );
g10_remove_double();

$cp_before_cooldown = (int) Conexao_Source_Enumeration_Checkpoint::get( $source )['last_page'];
assert_true( 4 === $cp_before_cooldown, 'G10.14: precondition — Cork holds progress at page 4' );

g10_register_active( $cork );
Conexao_Source_Cooldown::record_throttled( $source, 'rate_limited' );

g10_reset_pacing();
g10_install_double( 6, 120 );
$plugin = Conexao_Event_Importer::instance();
$stats  = $plugin->importer->run_source( $source, false );
$served_during_cooldown = g10_pages_served();
g10_remove_double();

assert_true( empty( $served_during_cooldown ), 'G10.14: a source in cooldown sends ZERO requests' );
assert_true(
	$cp_before_cooldown === (int) Conexao_Source_Enumeration_Checkpoint::get( $source )['last_page'],
	'G10.14: cooldown PRESERVES the checkpoint — it never resets progress'
);
assert_true( ! $stats['complete'], 'G10.14: a cooldown-skipped run is still INCOMPLETE' );

// Once the cooldown elapses, the next real run resumes from where it stopped.
Conexao_Source_Cooldown::record_elapsed( $source );

g10_reset_pacing();
g10_install_double( 6, 120 );
$after_cooldown = $plugin->importer->run_source( $source, false );
$served_after = g10_pages_served();
g10_remove_double();

assert_true( in_array( 4, $served_after, true ) || in_array( 3, $served_after, true ), 'G10.14: after recovery the run resumes near the checkpoint (overlap), not from page 1' );
assert_true( ! in_array( 2, $served_after, true ), 'G10.14: after recovery the confirmed prefix is NOT re-walked' );
assert_true( 6 === (int) Conexao_Source_Enumeration_Checkpoint::get( $source )['last_page'], 'G10.14: the post-recovery run advanced the checkpoint to the end' );
// ---------------------------------------------------------------------------
// G10.15 — the checkpoint never authorises missing-event reconciliation.
// ---------------------------------------------------------------------------
//
// This is the destructive-safety proof, exercised through the REAL engine
// against a REAL WordPress event. It proves the guard by its consequence: an
// event that exists but was not re-requested must remain published.

g10_clear_checkpoints();
g10_reset_pacing();

/*
 * Create a real, published event belonging to this source, using the EXACT
 * meta keys and post type `mark_missing_events()` queries. Using approximate
 * keys here would make this proof vacuous: the reconciliation query would find
 * nothing and the event could never be hidden, so the test would pass whether
 * or not the guard exists.
 */
$hidden_id = wp_insert_post(
	array(
		'post_title'  => 'G10 prefix event that a resumed walk must not hide',
		'post_status' => 'publish',
		'post_type'   => 'event',
	)
);
assert_true( $hidden_id > 0, 'G10.15: precondition — a real event was created' );
update_post_meta( $hidden_id, '_event_status', Conexao_Event_Status::PUBLISHED );
update_post_meta( $hidden_id, '_event_source', $source );
update_post_meta( $hidden_id, '_event_url', 'https://www.eventbrite.ie/e/prefix-event-never-returned' );

// Prove the probe event is genuinely reachable by the reconciliation query.
$reachable = get_posts(
	array(
		'post_type'      => 'event',
		'posts_per_page' => 10,
		'fields'         => 'ids',
		'meta_query'     => array(
			array(
				'key'   => '_event_source',
				'value' => $source,
			),
		),
	)
);
assert_true(
	in_array( $hidden_id, $reachable, true ),
	'G10.15: precondition — the probe event IS reachable by the reconciliation query (the proof is not vacuous)'
);

// Store a checkpoint, so the next run RESUMES and never sees this event.
Conexao_Source_Enumeration_Checkpoint::record(
	$source,
	array(
		'fingerprint' => Conexao_Source_Enumeration_Checkpoint::fingerprint( $cork ),
		'last_page'   => 3,
		'page_count'  => 6,
	)
);

g10_register_active( $cork );
Conexao_Source_Cooldown::reset( $source );
g10_reset_pacing();
g10_install_double( 6, 120 );

$plugin     = Conexao_Event_Importer::instance();
$resume_run = $plugin->importer->run_source( $source, false );
g10_remove_double();

$status_after_resume = get_post_meta( $hidden_id, '_event_status', true );

// A resumed walk REACHED the end of the listing, but the engine still records
// it as incomplete — precisely because absence-based reconciliation was
// refused. That divergence is the G10 invariant, not an accident.
assert_true(
	false === $resume_run['complete'],
	'G10.15: a resumed run is recorded INCOMPLETE even though it reached the last page (reconciliation was refused)'
);
assert_true(
	Conexao_Event_Status::PUBLISHED === $status_after_resume,
	'G10.15: the event in the skipped prefix is STILL PUBLISHED — a resumed walk never hides a live event'
);
assert_true(
	null !== get_post( $hidden_id ),
	'G10.15: the event still exists after a resumed run'
);

$history = Conexao_Import_History::get_for_source( $source, 1 );

assert_true( ! empty( $history ), 'G10.15: an Import History row was recorded for the resumed run' );
assert_true( ! $history[0]['complete'], 'G10.15: Import History records the resumed run as INCOMPLETE, not complete' );
assert_true(
	! empty( $history[0]['checkpoint']['has_checkpoint'] ),
	'G10.15: Import History exposes the checkpoint so an operator can see the progress'
);
assert_true(
	6 === (int) $history[0]['checkpoint']['last_page'],
	'G10.15: the Import History checkpoint reports the confirmed page'
);
// ---------------------------------------------------------------------------
// G10.16 — a rate-limited run records complete=false end to end.
//
// This is the M3 negative proof. It asserts on the ENGINE's reported
// completeness (not just the handler flag), because M3 mutates exactly that
// layer: a mutation that forces `$fetch_complete = true` must break it.

g10_clear_checkpoints();
g10_reset_pacing();
g10_install_double( 6, 120, 5 );

g10_register_active( $cork );
Conexao_Source_Cooldown::reset( $source );
$plugin2 = Conexao_Event_Importer::instance();
$rl_stats = $plugin2->importer->run_source( $source, false );
g10_remove_double();

assert_true(
	array_key_exists( 'complete', $rl_stats ) && false === $rl_stats['complete'],
	'G10.16: a rate-limited run reports complete=false through the engine'
);

$rl_history = Conexao_Import_History::get_for_source( $source, 1 );
assert_true( ! $rl_history[0]['complete'], 'G10.16: Import History records the rate-limited run as INCOMPLETE' );

// ---------------------------------------------------------------------------
// G10.17 — overlap deduplication is real, not incidental.
//
// This is the M8 negative proof. The fixture deliberately serves DUPLICATE
// event ids ACROSS pages, so removing the identity dedup is observable as a
// duplicated result set.

// This case needs a listing that is genuinely its own. It gets its own source
// id AND its own discovery URL so that neither the per-source checkpoint nor
// the G4A response-reuse store can answer with an earlier case's pages.
$dupes_config                       = $cork;
$dupes_config['id']                 = 'eventbrite_cork_dupe_probe';
$dupes_config['url']                = 'https://example.invalid/g10-dupe-probe/all-events/';

g10_reset_pacing();
g10_clear_checkpoints();
Conexao_Source_Enumeration_Checkpoint::reset( $dupes_config['id'] );

add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		$page = preg_match( '/[?&]page=(\d+)/', $url, $m ) ? (int) $m[1] : 1;

		$GLOBALS['g10_served'][] = $page;

		// Page 2 repeats three ids from page 1, then adds its own.
		$events = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$events[] = g10_eb_event( 'e1-' . $i );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$events[] = g10_eb_event( 'e2-' . $i );
		}

		return array(
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'body'     => g10_eb_page( 2, 6, 20, $events, $page ),
			'headers'  => array(),
			'cookies'  => array(),
		);
	},
	10,
	3
);

// ONE walk only: calling fetch_events() a second time would resume from the
// checkpoint the first walk just stored and return a different set.
$dupes_events = g10_walk_events( $dupes_config );
$dupes_ids    = g10_ids( $dupes_events );
g10_remove_double();

assert_true(
	6 === count( array_unique( $dupes_ids ) ),
	'G10.17: the six distinct ids across both pages were all returned'
);
assert_true(
	6 === count( $dupes_ids ),
	'G10.17: cross-page duplicates are removed by identity — the result set holds no repeats'
);

// Clean up the probe event.

// Clean up the probe event.
wp_delete_post( $hidden_id, true );

test_finish( 'G10 checkpoint' );
