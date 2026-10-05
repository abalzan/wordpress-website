<?php
/**
 * G18 — a resumable enumeration is resumed automatically.
 *
 * ## What is proved
 *
 * The invariant the Import History promise depends on: a source that becomes
 * resumable must not silently stop. Concretely:
 *
 *   1.  A time-budget stop persists a checkpoint AND schedules a continuation.
 *   2.  A rate-limit stop persists a checkpoint, removes nothing, and schedules
 *       a continuation.
 *   3.  The cooldown is ~30 minutes, server-side, and NOTHING is sent before it
 *       elapses.
 *   4.  After the cooldown the continuation executes with no browser at all.
 *   5.  It resumes from the checkpoint (never page 1) and stops at the right
 *       page.
 *   6.  It repeats until the enumeration completes, then clears itself.
 *   7.  Duplicate continuations are impossible: repeated schedules, repeated
 *       runs and concurrent triggers produce ONE pending continuation.
 *   8.  Two workers cannot resume one source at once, and one source's lock
 *       never blocks another source.
 *   9.  Five sources continue independently and none starves another.
 *   10. The chain survives losing the cron event (a restart) because the state
 *       is persisted, not in memory.
 *   11. A scheduling failure is reported as a failure, never as success.
 *   12. A stall is declared VISIBLY; it is never a silent stop.
 *   13. Repeated interrupted/resumed imports introduce no duplicate events.
 *   14. A dry run changes no scheduling state.
 *   15. Import History can never say "resumable" without a live continuation.
 *
 * ## Transport
 *
 * Every case runs against a `pre_http_request` double and an injected pacing
 * clock. Nothing contacts a real upstream, nothing sleeps, and no production
 * write occurs.
 *
 * @package Conexao_Event_Importer
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$g18_restore = test_options_isolate(
	array(
		Conexao_Import_History::OPTION_KEY,
		Conexao_Import_Log::OPTION_KEY,
		Conexao_Event_Sources::OPTION_KEY,
		'conexao_event_source_health',
		Conexao_Source_Cooldown::OPTION_KEY,
		Conexao_Source_Enumeration_Checkpoint::OPTION_KEY,
		Conexao_Import_Continuation::OPTION_KEY,
		'cron',
	)
);

register_shutdown_function(
	static function () use ( $g18_restore ) {
		Conexao_Import_Continuation::reset_clock();
		Conexao_Import_Continuation::reset_runner();
		g18_delete_probe_events();
		test_options_restore( $g18_restore, true );
	}
);

/**
 * Delete every event this suite created, so a local development database is
 * left exactly as it was found.
 *
 * The suite owns only `g18_probe_*` sources, so this can never touch real
 * imported content.
 *
 * @return void
 */
function g18_delete_probe_events() {
	if ( ! function_exists( 'wp_delete_post' ) || ! class_exists( 'Conexao_Event_Internal_Query' ) ) {
		return;
	}

	$args = array(
		'post_type'      => 'event',
		'post_status'    => 'any',
		'posts_per_page' => 200,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => array(
			'relation' => 'OR',
			array(
				'key'     => '_event_source',
				'value'   => 'g18_probe_',
				'compare' => 'LIKE',
			),
			array(
				'key'     => '_event_url',
				'value'   => '/e/g18probe-',
				'compare' => 'LIKE',
			),
		),
	);

	// Loop until a pass finds nothing: the suite creates several hundred events,
	// so a single capped query would leave some behind. Bounded, so a bug in this
	// loop can never hang the suite.
	for ( $pass = 0; $pass < 20; $pass++ ) {
		$ids = Conexao_Event_Internal_Query::run(
			static function () use ( $args ) {
				$query = new WP_Query( Conexao_Event_Internal_Query::mark_query_args( $args ) );

				return $query->posts;
			}
		);

		if ( empty( $ids ) ) {
			return;
		}

		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}
}

// Real sleeps are never wanted here: the pacing policy's delays are replaced by
// recorded, clock-advancing no-ops.
add_filter( 'conexao_eventbrite_backoff_seconds', '__return_zero', 99 );

/**
 * A pacing policy whose clock is fully under test control.
 *
 * The production policy reads its clock through this seam (G5), so the
 * time-budget branch can be driven deterministically. The clock advances by a
 * fixed step on EVERY read, which is what makes a mid-walk stop reproducible:
 * with a budget of 30 and a step of 10, a walk stops three pages in.
 *
 * @param float $step Seconds added on every clock read.
 * @return Conexao_Eventbrite_Pacing
 */
function g18_pacing( $step = 10.0 ) {
	$t = 1000.0;

	$pacing = new Conexao_Eventbrite_Pacing();
	$pacing->set_sleeper(
		function ( $seconds ) use ( &$t ) {
			$t += (float) $seconds;
		}
	);
	$pacing->set_clock(
		function () use ( &$t, $step ) {
			$t += (float) $step;

			return $t;
		}
	);

	return $pacing;
}

/**
 * Install the deterministic pacing policy for the next walk.
 *
 * @param float $step Clock step per read.
 * @return void
 */
function g18_reset_pacing( $step = 10.0 ) {
	remove_all_filters( 'conexao_eventbrite_pacing' );
	Conexao_Eventbrite_Pacing::begin_run();

	add_filter(
		'conexao_eventbrite_pacing',
		static function () use ( $step ) {
			return g18_pacing( $step );
		},
		99
	);
}

/**
 * One Eventbrite discovery page carrying a pagination block.
 *
 * @param int   $page_count   Declared page count.
 * @param int   $object_count Declared object count.
 * @param int   $page_size    Declared page size.
 * @param array $events       Raw events on this page.
 * @param int   $page_number  Page number the payload reports.
 * @return string
 */
function g18_eb_page( $page_count, $object_count, $page_size = 20, array $events = array(), $page_number = 1 ) {
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
 * One raw Eventbrite event in the given county region.
 *
 * @param string $id     Native Eventbrite id.
 * @param string $region Region label.
 * @return array
 */
function g18_eb_event( $id, $region ) {
	return array(
		'id'         => $id,
		'name'       => 'Event ' . $id,
		'url'        => 'https://www.eventbrite.ie/e/' . $id,
		'start_date' => '2099-01-01',
		'start_time' => '10:00',
		'end_date'   => '2099-01-01',
		'end_time'   => '12:00',
		'locations'  => array(
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
 * Install a per-URL paging double, scoped to one county region.
 *
 * Event ids are derived from the region and page number, so re-walking a page
 * yields the SAME ids — which is what makes overlap dedup observable and what
 * makes the "no duplicate native id" assertions meaningful.
 *
 * @param string $region      Region label every event carries.
 * @param int    $page_count  Declared page count.
 * @param int    $object_count Declared object count.
 * @param int    $fail_at     0, or a page number that answers HTTP 429.
 * @param int    $events_each Events per page.
 * @return void
 */
function g18_install_double( $region, $page_count = 10, $object_count = 200, $fail_at = 0, $events_each = 10 ) {
	$GLOBALS['g18_served']      = array();
	$GLOBALS['g18_region']      = (string) $region;
	$GLOBALS['g18_page_count']  = (int) $page_count;
	$GLOBALS['g18_object']      = (int) $object_count;
	$GLOBALS['g18_fail_at']     = (int) $fail_at;
	$GLOBALS['g18_events_each'] = (int) $events_each;

	remove_all_filters( 'pre_http_request' );

	add_filter(
		'pre_http_request',
		static function ( $preempt, $args, $url ) {
			$page = 1;

			if ( preg_match( '/[?&]page=(\d+)/', $url, $m ) ) {
				$page = (int) $m[1];
			}

			$GLOBALS['g18_served'][] = $page;

			if ( (int) $GLOBALS['g18_fail_at'] > 0 && $page === (int) $GLOBALS['g18_fail_at'] ) {
				return array(
					'response' => array( 'code' => 429, 'message' => 'Too Many Requests' ),
					'body'     => '',
					'headers'  => array(),
					'cookies'  => array(),
				);
			}

			$events = array();
			for ( $i = 0; $i < (int) $GLOBALS['g18_events_each']; $i++ ) {
				$events[] = g18_eb_event( 'g18probe-' . $page . '-' . $i, (string) $GLOBALS['g18_region'] );
			}

			return array(
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'body'     => g18_eb_page(
					(int) $GLOBALS['g18_page_count'],
					(int) $GLOBALS['g18_object'],
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
 * Remove the transport double.
 *
 * @return void
 */
function g18_remove_double() {
	remove_all_filters( 'pre_http_request' );
}

/**
 * The pages the double served, in request order.
 *
 * @return int[]
 */
function g18_pages_served() {
	return isset( $GLOBALS['g18_served'] ) ? $GLOBALS['g18_served'] : array();
}

/**
 * Forget every checkpoint so a case starts from a clean slate.
 *
 * @return void
 */
function g18_clear_checkpoints() {
	update_option( Conexao_Source_Enumeration_Checkpoint::OPTION_KEY, array(), false );
}

/**
 * Register a source as ACTIVE so the engine will run it.
 *
 * @param array $config Source config.
 * @return void
 */
function g18_activate( array $config ) {
	$config['status'] = 'active';

	Conexao_Event_Importer::instance()->sources->save( $config );
}

/**
 * Override the walk's time budget, deterministically.
 *
 * @param float $seconds Budget in seconds; a negative value trips the branch on
 *                       the walk's very first iteration.
 * @return void
 */
function g18_force_time_budget( $seconds = -1.0 ) {
	add_filter(
		'conexao_eventbrite_time_budget_seconds',
		static function () use ( $seconds ) {
			return (float) $seconds;
		},
		99
	);
}

/**
 * Remove the time-budget override.
 *
 * @return void
 */
function g18_clear_time_budget() {
	remove_all_filters( 'conexao_eventbrite_time_budget_seconds' );
}

/**
 * The region label the double should stamp on its events for a county.
 *
 * @param array $config Source config.
 * @return string
 */
function g18_region_for( array $config ) {
	if ( ! empty( $config['region_labels'] ) && is_array( $config['region_labels'] ) ) {
		return (string) reset( $config['region_labels'] );
	}

	return isset( $config['county'] ) ? (string) $config['county'] : '';
}

/**
 * A synthetic, suite-owned source config for one county.
 *
 * The source ID is rewritten to `g18_probe_<county>` on purpose. A completed,
 * non-resumed walk legitimately marks every event it did NOT see as
 * source_not_found, and the local database holds real Cork / Wicklow / ...
 * events, so driving the REAL source IDs here would hide live local content.
 * A probe source is the only kind whose absence reconciliation is safe to
 * exercise: it can only ever touch events this suite created.
 *
 * The URL and the county are deliberately unchanged, so the region gate, the
 * checkpoint fingerprint and the pagination double all behave exactly as they
 * do for the real source.
 *
 * @param string $county County slug.
 * @return array
 */
function g18_config( $county ) {
	$config = Conexao_County_Registry::get_eventbrite_source( $county );

	$config['id']   = 'g18_probe_' . $county;
	$config['name'] = 'G18 probe — ' . ( isset( $config['county'] ) ? $config['county'] : $county );

	return $config;
}

/**
 * The armed continuation events, ONE ENTRY PER EVENT.
 *
 * WordPress groups the cron array by timestamp, so several sources that fall
 * due in the same second share a single timestamp key. Counting keys would
 * therefore under-count the events; this counts the events themselves and
 * reports which source each one belongs to.
 *
 * @return array[] Each entry: source, time, key.
 */
function g18_armed_events() {
	$cron   = _get_cron_array();
	$events = array();

	foreach ( $cron as $timestamp => $hooks ) {
		if ( ! isset( $hooks[ Conexao_Import_Continuation::HOOK ] ) ) {
			continue;
		}

		foreach ( (array) $hooks[ Conexao_Import_Continuation::HOOK ] as $key => $event ) {
			$args = ( isset( $event['args'] ) && is_array( $event['args'] ) ) ? $event['args'] : array();

			$events[] = array(
				'source' => isset( $args[0] ) ? (string) $args[0] : '',
				'time'   => (int) $timestamp,
				'key'    => (string) $key,
			);
		}
	}

	return $events;
}

/**
 * The time of every armed continuation event, one per event, ascending.
 *
 * @return int[]
 */
function g18_armed_times() {
	$times = array();

	foreach ( g18_armed_events() as $event ) {
		$times[] = (int) $event['time'];
	}

	sort( $times );

	return $times;
}

/**
 * The source of every armed continuation event.
 *
 * @return string[]
 */
function g18_armed_sources() {
	$sources = array();

	foreach ( g18_armed_events() as $event ) {
		$sources[] = (string) $event['source'];
	}

	return $sources;
}

/**
 * The reason codes present in the import log for a source, in order.
 *
 * @param string $source_id Source slug.
 * @return string[]
 */
function g18_log_reasons( $source_id ) {
	$reasons = array();

	foreach ( array_reverse( Conexao_Import_Log::get_for_source( $source_id, 500 ) ) as $entry ) {
		$reason = '';

		if ( isset( $entry['context'] ) && is_array( $entry['context'] ) && isset( $entry['context']['reason'] ) ) {
			$reason = (string) $entry['context']['reason'];
		} elseif ( isset( $entry['reason'] ) ) {
			$reason = (string) $entry['reason'];
		}

		if ( '' !== $reason ) {
			$reasons[] = $reason;
		}
	}

	return $reasons;
}

/**
 * How many stored events exist for a source and native id.
 *
 * @param string $source_key Canonical `_event_source` value.
 * @param string $native_id  `_event_source_id` value.
 * @return int[] Matching post ids.
 */
function g18_native_ids( $source_key, $native_id ) {
	$args = array(
		'post_type'      => 'event',
		'post_status'    => 'any',
		'posts_per_page' => 50,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => array(
			'relation' => 'AND',
			array(
				'key'   => '_event_source',
				'value' => $source_key,
			),
			array(
				'key'   => '_event_source_id',
				'value' => $native_id,
			),
		),
	);

	$ids = Conexao_Event_Internal_Query::run(
		static function () use ( $args ) {
			$query = new WP_Query( Conexao_Event_Internal_Query::mark_query_args( $args ) );

			return $query->posts;
		}
	);

	return array_map( 'intval', $ids );
}

/**
 * How many events of a source are currently hidden as source_not_found.
 *
 * The absence-reconciliation canary: a resumed walk must never be allowed to
 * flip the prefix it did not re-request, so this must stay 0 across the whole
 * continue chain.
 *
 * @param string $source_key Canonical `_event_source` value.
 * @return int
 */
function g18_count_source_not_found( $source_key ) {
	return g18_query_probe( $source_key, 'source_not_found' );
}

/**
 * Count a source's events, optionally restricted to one event status.
 *
 * Runs inside the importer's own reconciliation scope so a HIDDEN record is
 * still counted. That is the documented G17 exemption, not a relaxed public
 * gate: the frontend rule is untouched and still excludes these records.
 *
 * @param string $source_key Canonical `_event_source` value.
 * @param string $status     Optional `_event_status` to require.
 * @return int
 */
function g18_query_probe( $source_key, $status = '' ) {
	$meta = array(
		array(
			'key'   => '_event_source',
			'value' => $source_key,
		),
	);

	if ( '' !== $status ) {
		$meta[] = array(
			'key'   => '_event_status',
			'value' => $status,
		);
	}

	$args = array(
		'post_type'      => 'event',
		'post_status'    => 'any',
		'posts_per_page' => 500,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => $meta,
	);

	$ids = Conexao_Event_Internal_Query::run(
		static function () use ( $args ) {
			$query = new WP_Query( Conexao_Event_Internal_Query::mark_query_args( $args ) );

			return $query->posts;
		}
	);

	return count( $ids );
}

/**
 * The Import History Progress cell text for one source, via the REAL renderer.
 *
 * The promise "resumable" is only worth anything if the cell that shows it is
 * the cell the operator reads, so the assertion goes through the production
 * helper rather than a copy of its logic.
 *
 * @param string $source_id Source slug.
 * @return string
 */
function g18_progress_text( $source_id ) {
	$entry = Conexao_Import_History::get_last( $source_id );

	if ( ! is_array( $entry ) ) {
		return '';
	}

	return Conexao_Event_Sources::history_progress_text( $entry );
}

/**
 * Refuse every attempt to schedule a WordPress event (test double for a
 * WP-Cron failure). Named so it can be removed precisely.
 *
 * @return bool Always false: "the event was NOT scheduled".
 */
function g18_refuse_scheduling() {
	return false;
}

/**
 * Install one double that serves every county listing at once.
 *
 * The county slug is read from the requested URL (`…/ireland--<slug>/…`) and
 * mapped to the region label that county accepts, so a single pass of
 * `run_due()` can drive several sources end to end without the test having to
 * swap the transport per source.
 *
 * @param array $slug_to_region County slug => the region label its events carry.
 * @param int   $pages          Declared page count.
 * @param int   $events_each    Events per page.
 * @param int   $fail_at        0, or a page number that answers HTTP 429.
 * @return void
 */
/**
 * How many stored events belong to one source.
 *
 * @param string $source_key Canonical `_event_source` value.
 * @return int
 */
function g18_count_events( $source_key ) {
	return g18_query_probe( $source_key );
}

function g18_install_multi_double( array $slug_to_region, $pages = 20, $events_each = 3, $fail_at = 0 ) {
	$GLOBALS['g18_multi_map']   = $slug_to_region;
	$GLOBALS['g18_multi_pages'] = (int) $pages;
	$GLOBALS['g18_multi_each']  = (int) $events_each;
	$GLOBALS['g18_multi_fail']  = (int) $fail_at;
	$GLOBALS['g18_multi_served'] = array();

	remove_all_filters( 'pre_http_request' );

	add_filter(
		'pre_http_request',
		static function ( $preempt, $args, $url ) {
			$page = 1;

			if ( preg_match( '/[?&]page=(\d+)/', $url, $m ) ) {
				$page = (int) $m[1];
			}

			$slug = '';

			if ( preg_match( '#ireland--([a-z_]+)#', $url, $sm ) ) {
				$slug = (string) $sm[1];
			}

			$region = isset( $GLOBALS['g18_multi_map'][ $slug ] ) ? (string) $GLOBALS['g18_multi_map'][ $slug ] : '';

			$GLOBALS['g18_multi_served'][] = $slug . ':' . $page;

			if ( 0 === $GLOBALS['g18_multi_fail'] || $page !== (int) $GLOBALS['g18_multi_fail'] ) {
				$events = array();

				for ( $i = 0; $i < (int) $GLOBALS['g18_multi_each']; $i++ ) {
					$events[] = g18_eb_event( 'g18probe-' . $slug . '-' . $page . '-' . $i, $region );
				}

				return array(
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'body'     => g18_eb_page( (int) $GLOBALS['g18_multi_pages'], (int) $GLOBALS['g18_multi_pages'] * 20, 20, $events, $page ),
					'headers'  => array(),
					'cookies'  => array(),
				);
			}

			return array(
				'response' => array( 'code' => 429, 'message' => 'Too Many Requests' ),
				'body'     => '',
				'headers'  => array(),
				'cookies'  => array(),
			);
		},
		10,
		3
	);
}

test_title( 'G18 — automatic continuation of a resumable enumeration' );

// ---------------------------------------------------------------------------
// G18.0 — the resumable predicate itself, asserted directly.
//
// "Resumable" is the word the whole stage turns on, and it has to mean a
// precise thing: THIS generation still has a page to fetch. Asserting the
// predicate on constructed records is what makes the `complete` term
// detectable at all — in every REACHABLE state it agrees with the page-count
// comparison, so only a direct assertion can distinguish the two.
// ---------------------------------------------------------------------------

test_section( 'G18.0b — the harness isolates this option as an ARRAY' );

/*
 * Regression guard for a harness defect this suite exposed.
 *
 * `test_options_isolate()`/`test_options_restore()` are the documented way a
 * suite protects operator state. They used to snapshot the RAW value from
 * `wp_load_alloptions()` and write it back verbatim, which double-serialised
 * any AUTOLOADED option: the option then read back as the string
 * `a:0:{}` instead of an array, and every consumer saw a malformed value.
 *
 * This option is autoloaded on purpose, so it is the one that proved it. The
 * assertions below fail if the round trip ever loses the value’s type again.
 */
$g18_roundtrip = test_options_isolate( array( Conexao_Import_Continuation::OPTION_KEY ) );

test_options_restore( $g18_roundtrip, true );

assert_true(
	is_array( get_option( Conexao_Import_Continuation::OPTION_KEY, array() ) ),
	'G18.0b: isolating and restoring an AUTOLOADED option leaves it an array, not a serialised string'
);
assert_true(
	is_array( Conexao_Import_Continuation::get_all() ),
	'G18.0b: the continuation map reads back as an array after a harness round trip'
);

/* And the same round trip must preserve a NON-EMPTY array exactly. */
update_option( Conexao_Import_Continuation::OPTION_KEY, array( 'g18_roundtrip_src' => array( 'schema_version' => 1 ) ), true );

$g18_roundtrip2 = test_options_isolate( array( Conexao_Import_Continuation::OPTION_KEY ) );

test_options_restore( $g18_roundtrip2, true );

$g18_restored = get_option( Conexao_Import_Continuation::OPTION_KEY, array() );

assert_true( is_array( $g18_restored ), 'G18.0b: a non-empty array survives the round trip as an array' );
assert_true(
	isset( $g18_restored['g18_roundtrip_src'] ),
	'G18.0b: and its contents survive — the snapshot holds the VALUE, not its storage form'
);

/*
 * The AUTOLOAD flag is part of the option, not a test detail: this map is
 * autoloaded on purpose (read on every request to decide whether anything is
 * due). A harness that restored the value but not the flag would silently
 * change that documented property. Measured before the fix:
 * `update_option( $key, array(), true )` left the flag `off`, because
 * WordPress short-circuits an unchanged value; `wp_set_option_autoload()`
 * does not.
 */
global $wpdb;
$g18_autoload = $wpdb->get_var(
	$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Conexao_Import_Continuation::OPTION_KEY )
);

assert_true(
	in_array( $g18_autoload, array( 'on', 'auto-on', 'yes' ), true ),
	'G18.0b: the harness restores the AUTOLOAD flag too, so an autoloaded option stays autoloaded (got ' . (string) $g18_autoload . ')'
);

/* A deliberately malformed row is repaired rather than propagated. */
update_option( Conexao_Import_Continuation::OPTION_KEY, 'a:0:{}', true );

assert_true(
	array() === Conexao_Import_Continuation::get_all(),
	'G18.0b: a malformed (double-serialised) row reads as no continuations and fails safe'
);
assert_true(
	is_array( get_option( Conexao_Import_Continuation::OPTION_KEY, array() ) ),
	'G18.0b: and the malformed row is repaired in place, so it cannot be re-read forever'
);

Conexao_Import_Continuation::reset_all();

test_section( 'G18.0 — the resumable predicate is precise' );

assert_true(
	! Conexao_Source_Enumeration_Checkpoint::is_resumable( array( 'last_page' => 0, 'page_count' => 49, 'complete' => false ) ),
	'G18.0: a source that has confirmed nothing is not resumable'
);
assert_true(
	Conexao_Source_Enumeration_Checkpoint::is_resumable( array( 'last_page' => 17, 'page_count' => 49, 'complete' => false ) ),
	'G18.0: mid-listing progress with pages outstanding is resumable'
);
assert_true(
	! Conexao_Source_Enumeration_Checkpoint::is_resumable( array( 'last_page' => 49, 'page_count' => 49, 'complete' => false ) ),
	'G18.0: a position at the declared last page has nothing to resume into'
);
assert_true(
	! Conexao_Source_Enumeration_Checkpoint::is_resumable( array( 'last_page' => 49, 'page_count' => 49, 'complete' => true ) ),
	'G18.0: a FINISHED generation is not resumable'
);
assert_true(
	! Conexao_Source_Enumeration_Checkpoint::is_resumable( array( 'last_page' => 49, 'page_count' => 60, 'complete' => true ) ),
	'G18.0: a finished generation is NOT resumable even when a later listing declares more pages — this is the `complete` term, and it is what M6 removes'
);
assert_true(
	Conexao_Source_Enumeration_Checkpoint::is_resumable( array( 'last_page' => 49, 'page_count' => 60, 'complete' => false ) ),
	'G18.0: the SAME record with complete=false IS resumable, so the term is a real distinction and not a second spelling of the page count'
);
assert_true(
	! Conexao_Source_Enumeration_Checkpoint::is_resumable( array() ),
	'G18.0: an absent record is not resumable'
);
assert_true(
	! Conexao_Source_Enumeration_Checkpoint::is_resumable( 'not a record' ),
	'G18.0: a malformed record is not resumable (fail closed)'
);


// The continuation clock is the SERVER clock, so the cooldown is advanced by
// moving that clock. No case sleeps, and no browser time is ever consulted.
$GLOBALS['g18_now'] = 1800000000;

Conexao_Import_Continuation::set_clock(
	static function () {
		return (int) $GLOBALS['g18_now'];
	}
);

$g18_cork   = g18_config( 'cork' );
$g18_source = $g18_cork['id'];
$g18_region = g18_region_for( $g18_cork );

g18_activate( $g18_cork );

// ---------------------------------------------------------------------------
// G18.1 — a TIME-BUDGET stop persists a checkpoint and SCHEDULES a
//         continuation. This is the transition that did not exist before.
// ---------------------------------------------------------------------------

g18_clear_checkpoints();
Conexao_Import_Continuation::reset_all();

g18_reset_pacing( 10.0 );
g18_install_double( $g18_region, 10, 200 );

// The walk's time budget is read from the client; the injected pacing clock
// advances 10 s per read, so a 30 s budget stops the walk a few pages in —
// deterministically, with no wall-clock dependence.
add_filter(
	'conexao_eventbrite_time_budget_seconds',
	static function () {
		return 30.0;
	},
	99
);

$g18_stats = Conexao_Event_Importer::instance()->importer->run_source( $g18_source, false );

g18_clear_time_budget();
g18_remove_double();

$g18_cp = Conexao_Source_Enumeration_Checkpoint::get( $g18_source );

test_section( 'G18.1 — a time-budget stop schedules its own continuation' );

assert_true( 3 === (int) $g18_cp['last_page'], 'G18.1: the time budget stopped the walk mid-listing at page 3 (got ' . (int) $g18_cp['last_page'] . ')' );
assert_true( 3 < (int) $g18_cp['page_count'], 'G18.1: the stop is genuinely MID-listing, not at the end' );
assert_true( 10 === (int) $g18_cp['page_count'], 'G18.1: the checkpoint records the declared page count' );
assert_true( 'time_budget' === (string) $g18_cp['last_failure'], 'G18.1: the checkpoint records the time_budget failure class' );
assert_true( ! (bool) $g18_cp['complete'], 'G18.1: the checkpoint does NOT claim the enumeration finished' );
assert_true( Conexao_Source_Enumeration_Checkpoint::is_resumable( $g18_cp ), 'G18.1: the shared resumable predicate agrees there are pages outstanding' );
assert_true( ! $g18_stats['complete'], 'G18.1: the run reports itself INCOMPLETE (no false success)' );

$g18_cont = Conexao_Import_Continuation::get( $g18_source );

assert_true( Conexao_Import_Continuation::STATE_SCHEDULED === $g18_cont['state'], 'G18.1: a continuation is SCHEDULED (state=' . $g18_cont['state'] . ')' );
assert_true( 1 === (int) $g18_cont['attempts'], 'G18.1: the first continuation counts as attempt 1' );
assert_true( 0 === (int) $g18_cont['stalled'], 'G18.1: a first continuation is not stalled' );
assert_true( 3 === (int) $g18_cont['last_page'], 'G18.1: the continuation records the checkpoint page it resumes from' );
assert_true( 'time_budget' === (string) $g18_cont['reason'], 'G18.1: the continuation records why the source is resumable' );
assert_true(
	Conexao_Import_Continuation::COOLDOWN_SECONDS === ( (int) $g18_cont['run_at'] - (int) $GLOBALS['g18_now'] ),
	'G18.1: the next execution is scheduled exactly one cooldown away (' . Conexao_Import_Continuation::COOLDOWN_SECONDS . ' s)'
);
assert_true(
	1800 === Conexao_Import_Continuation::COOLDOWN_SECONDS,
	'G18.1: the default cooldown is 30 minutes, inside the required 20-30 minute band'
);

$g18_armed = g18_armed_times();

assert_true( 1 === count( $g18_armed ), 'G18.1: exactly ONE WordPress event is armed for this source (got ' . count( $g18_armed ) . ')' );
assert_true(
	isset( $g18_armed[0] ) && (int) $g18_armed[0] === (int) $g18_cont['run_at'],
	'G18.1: the armed event fires at the recorded run_at — the record and the timer agree'
);

$g18_state = Conexao_Import_Continuation::get_state( $g18_source );

assert_true( 'cooling' === $g18_state['phase'], 'G18.1: the operator-facing phase is cooling (got ' . $g18_state['phase'] . ')' );
assert_true( 1800 === (int) $g18_state['seconds_remaining'], 'G18.1: the remaining cooldown is reported from the SERVER clock' );
assert_true( true === $g18_state['scheduled'], 'G18.1: the state says a future execution exists' );

$g18_last = Conexao_Import_History::get_last( $g18_source );

assert_true( is_array( $g18_last ) && ! (bool) $g18_last['complete'], 'G18.1: Import History records the run as NOT complete' );
assert_true(
	is_array( $g18_last ) && 'time_budget' === (string) $g18_last['checkpoint']['last_failure'],
	'G18.1: Import History carries the checkpoint that the scheduler is acting on'
);

// ---------------------------------------------------------------------------
// G18.2 — the cooldown is enforced SERVER-SIDE: nothing is sent before it
//         elapses, and the continuation runs once it has.
// ---------------------------------------------------------------------------

test_section( 'G18.2 — no early retry, and execution exactly at the cooldown' );

$GLOBALS['g18_runner_calls'] = array();

Conexao_Import_Continuation::set_runner(
	static function ( $source_id ) {
		$GLOBALS['g18_runner_calls'][] = (string) $source_id;
	}
);

$g18_run_at = (int) $g18_cont['run_at'];

// Just before the cooldown expires: a trigger fires, and does NOTHING.
$GLOBALS['g18_now'] = $g18_run_at - 1;
g18_install_double( $g18_region, 10, 200 );

$g18_outcome = Conexao_Import_Continuation::run_due();
$g18_served_before = g18_pages_served();
g18_remove_double();

assert_true( 0 === count( $GLOBALS['g18_runner_calls'] ), 'G18.2: a trigger before the cooldown does NOT run the continuation' );
assert_true( array() === $g18_served_before, 'G18.2: a trigger before the cooldown sends ZERO upstream requests' );
assert_true( 'cooling' === $g18_outcome[ $g18_source ], 'G18.2: the outcome is reported as cooling (got ' . $g18_outcome[ $g18_source ] . ')' );
assert_true(
	Conexao_Import_Continuation::STATE_SCHEDULED === Conexao_Import_Continuation::get( $g18_source )['state'],
	'G18.2: the continuation is still pending after an early trigger — it was not consumed'
);
assert_true( 1 === count( g18_armed_times() ), 'G18.2: the armed event survives an early trigger' );

// At the cooldown boundary: it runs, once.
$GLOBALS['g18_now'] = $g18_run_at;
g18_install_double( $g18_region, 10, 200 );

$g18_outcome_due = Conexao_Import_Continuation::run_due();
g18_remove_double();

assert_true( 1 === count( $GLOBALS['g18_runner_calls'] ), 'G18.2: at the cooldown boundary the continuation runs exactly once' );
assert_true( $g18_source === $GLOBALS['g18_runner_calls'][0], 'G18.2: it runs the CORRECT source' );
assert_true( 'ran' === $g18_outcome_due[ $g18_source ], 'G18.2: the outcome is reported as ran (got ' . $g18_outcome_due[ $g18_source ] . ')' );
assert_true( ! Conexao_Import_Continuation::is_locked( $g18_source ), 'G18.2: the worker releases its lock when it finishes' );

// The injected runner made no progress, so the continuation is re-armed with an
// ESCALATED delay rather than hammering the source at the same cadence.
$g18_after_no_progress = Conexao_Import_Continuation::get( $g18_source );

assert_true(
	Conexao_Import_Continuation::STATE_SCHEDULED === $g18_after_no_progress['state'],
	'G18.2: a no-progress attempt is rescheduled rather than abandoned'
);
assert_true( 1 === (int) $g18_after_no_progress['stalled'], 'G18.2: the no-progress attempt is counted as a stall' );
assert_true(
	(int) $g18_after_no_progress['run_at'] === ( $g18_run_at + Conexao_Import_Continuation::COOLDOWN_SECONDS * 2 ),
	'G18.2: the delay ESCALATES after a no-progress attempt (30 -> 60 min), so no tight retry loop is possible'
);

// The escalation ladder is bounded, and never shorter than the policy floor.
assert_true( 1800 === Conexao_Import_Continuation::get_cooldown_seconds( 0 ), 'G18.2: the first delay is the 30-minute default' );
assert_true( 3600 === Conexao_Import_Continuation::get_cooldown_seconds( 1 ), 'G18.2: the second delay doubles' );
assert_true( 7200 === Conexao_Import_Continuation::get_cooldown_seconds( 2 ), 'G18.2: the third delay doubles again' );
assert_true(
	Conexao_Import_Continuation::MAX_BACKOFF_SECONDS === Conexao_Import_Continuation::get_cooldown_seconds( 8 ),
	'G18.2: the escalation is CAPPED — a permanently failing source cannot be retried faster and faster'
);
assert_true(
	Conexao_Import_Continuation::COOLDOWN_SECONDS === Conexao_Import_Continuation::get_cooldown_seconds( 0 ),
	'G18.2: the base delay IS the floor — no input can make a retry shorter than the 30-minute default'
);
assert_true(
	Conexao_Import_Continuation::get_cooldown_seconds( 0 ) >= Conexao_Import_Continuation::COOLDOWN_SECONDS,
	'G18.2: and no escalated delay is ever shorter either'
);

// ---------------------------------------------------------------------------
// G18.3 — the continuation RESUMES from the checkpoint and repeats until the
//         enumeration completes, then clears itself.
// ---------------------------------------------------------------------------

/**
 * Drive one source into the exact resumable state a stop produces.
 *
 * @param array $config  Source config.
 * @param int   $pages   Declared page count.
 * @param float $budget  Time budget for the interrupted run.
 * @param float $step    Pacing clock step per read.
 * @param int   $fail_at 0, or a page number that answers HTTP 429.
 * @param int   $events_each Events per page.
 * @return array The continuation record that was scheduled.
 */
function g18_interrupt( array $config, $pages = 20, $budget = 30.0, $step = 10.0, $fail_at = 0, $events_each = 3 ) {
	$source = (string) $config['id'];
	$region = g18_region_for( $config );

	g18_activate( $config );
	g18_reset_pacing( $step );
	g18_install_double( $region, $pages, $pages * 20, $fail_at, $events_each );
	g18_force_time_budget( $budget );

	Conexao_Event_Importer::instance()->importer->run_source( $source, false );

	g18_clear_time_budget();
	g18_remove_double();

	return Conexao_Import_Continuation::get( $source );
}

test_section( 'G18.3 — resume from the checkpoint, repeat, complete, clear' );

g18_clear_checkpoints();
Conexao_Import_Continuation::reset_all();
Conexao_Import_Continuation::reset_runner();
Conexao_Import_History::clear();
Conexao_Import_Log::clear();

$g18_page_count  = 20;
$g18_events_each = 3;

// Calibrated against the measured, deterministic rate: with a 10 s pacing clock
// step a 30 s budget confirms 3 pages, and a 60 s budget confirms ~6. That makes
// the interruption reproducible and the number of continues reproducible too.
$g18_resume_budget = 60.0;

$g18_rec = g18_interrupt( $g18_cork, $g18_page_count, 30.0, 10.0, 0, $g18_events_each );

assert_true( Conexao_Import_Continuation::STATE_SCHEDULED === $g18_rec['state'], 'G18.3: precondition — the interrupted run left a scheduled continuation' );

$g18_trace        = array();
$g18_attempts     = 0;
$g18_first_resume     = null;
$g18_after_first      = null;
$g18_served_by_attempt = array();
$g18_page_by_attempt   = array();

$g18_trace[] = sprintf(
	'source=%s | page %d of %d | %s | checkpoint persisted | resumable | cooldown=%ds | continuation scheduled | run_at=%d',
	$g18_source,
	(int) $g18_rec['last_page'],
	(int) $g18_rec['page_count'],
	(string) $g18_rec['reason'],
	Conexao_Import_Continuation::COOLDOWN_SECONDS,
	(int) $g18_rec['run_at']
);

while ( $g18_attempts < 12 ) {
	++$g18_attempts;

	$g18_before_rec = Conexao_Import_Continuation::get( $g18_source );

	if ( Conexao_Import_Continuation::STATE_SCHEDULED !== $g18_before_rec['state'] ) {
		break;
	}

	// Jump the server clock to the scheduled time: this is the cooldown
	// elapsing, not a sleep.
	$GLOBALS['g18_now'] = (int) $g18_before_rec['run_at'];

	$g18_resume_from = (int) $g18_before_rec['last_page'];

	g18_reset_pacing( 10.0 );
	g18_install_double( $g18_region, $g18_page_count, $g18_page_count * 20, 0, $g18_events_each );
	g18_force_time_budget( $g18_resume_budget );

	$g18_outcomes = Conexao_Import_Continuation::run_due();

	g18_clear_time_budget();
	$g18_served = g18_pages_served();
	g18_remove_double();

	$g18_cp_now = Conexao_Source_Enumeration_Checkpoint::get( $g18_source );

	$g18_served_by_attempt[ $g18_attempts ] = $g18_served;
	$g18_page_by_attempt[ $g18_attempts ]   = (int) $g18_cp_now['last_page'];

	if ( null === $g18_first_resume ) {
		$g18_first_resume = $g18_served;
		$g18_after_first  = (int) $g18_cp_now['last_page'];
	}

	$g18_trace[] = sprintf(
		'attempt %d | cooldown expired | continuation ran | served pages [%s] (scheduled from page %d) | now page %d of %d',
		$g18_attempts,
		implode( ',', $g18_served ),
		$g18_resume_from,
		(int) $g18_cp_now['last_page'],
		(int) $g18_cp_now['page_count']
	);

	assert_true( 'ran' === $g18_outcomes[ $g18_source ], 'G18.3: attempt ' . $g18_attempts . ' executed the continuation' );
}

$g18_final_cp      = Conexao_Source_Enumeration_Checkpoint::get( $g18_source );
$g18_final_rec     = Conexao_Import_Continuation::get( $g18_source );
$g18_final_history = Conexao_Import_History::get_last( $g18_source );

$g18_trace[] = sprintf(
	'final page %d of %d | checkpoint complete=%s | continuation state="%s" | phase=%s | history status=%s complete=%s',
	(int) $g18_final_cp['last_page'],
	(int) $g18_final_cp['page_count'],
	var_export( (bool) $g18_final_cp['complete'], true ),
	(string) $g18_final_rec['state'],
	Conexao_Import_Continuation::get_state( $g18_source )['phase'],
	(string) $g18_final_history['status'],
	var_export( (bool) $g18_final_history['complete'], true )
);

echo "\n  --- deterministic trace ---\n";
foreach ( $g18_trace as $g18_line ) {
	echo '  ' . $g18_line . "\n";
}
echo "\n";

assert_true( $g18_attempts > 1, 'G18.3: the enumeration needed MORE than one continue — the chain really repeats (' . $g18_attempts . ' attempts)' );
assert_true( $g18_attempts < 12, 'G18.3: the chain is BOUNDED — it converged well inside the guard (' . $g18_attempts . ' attempts)' );

// --- checkpoint correctness -------------------------------------------------
//
// Page 1 IS always fetched: it carries the pagination metadata the G8
// truncation guard depends on, and that is unchanged by this stage. The claim
// that matters is that the CONFIRMED PREFIX (pages 2..N-1) is never re-walked,
// and that the walk starts at the documented OVERLAP boundary (last_page - 1).
$g18_first_above_one = array_values(
	array_filter(
		$g18_first_resume,
		static function ( $page ) {
			return $page >= 2;
		}
	)
);

assert_true( 3 === (int) $g18_rec['last_page'], 'G18.3: precondition — the checkpoint the continuation resumed from was page 3' );
assert_true( ! empty( $g18_first_above_one ), 'G18.3: the continuation really requested listing pages' );
assert_true(
	2 === min( $g18_first_above_one ),
	'G18.3: the FIRST continuation starts at the overlap boundary (page 2 = last_page 3 - OVERLAP_PAGES 1)'
);
assert_true(
	in_array( 3, $g18_first_resume, true ),
	'G18.3: the first continuation re-validates the checkpoint page itself'
);
assert_true(
	1 === (int) Conexao_Source_Eventbrite::OVERLAP_PAGES,
	'G18.3: control — the overlap rule this stage relies on is unchanged (OVERLAP_PAGES = 1)'
);

// The load-bearing claim: a LATER continuation, resuming from a page well above
// the start, must not walk the pages it already confirmed. This is what proves
// the continuation resumes rather than restarts.
$g18_second_served = isset( $g18_served_by_attempt[2] ) ? $g18_served_by_attempt[2] : array();
$g18_second_page   = isset( $g18_page_by_attempt[2] ) ? (int) $g18_page_by_attempt[2] : 0;
$g18_second_cp     = isset( $g18_page_by_attempt[1] ) ? (int) $g18_page_by_attempt[1] : 0;

assert_true( $g18_second_cp > 3, 'G18.3: attempt 1 advanced the checkpoint to page ' . $g18_second_cp . ' (from 3)' );
assert_true( $g18_second_page >= $g18_second_cp, 'G18.3: attempt 2 did not lose progress (page ' . $g18_second_page . ')' );
assert_true(
	! in_array( 2, $g18_second_served, true ),
	'G18.3: attempt 2 (from checkpoint ' . $g18_second_cp . ') never requested page 2 — the confirmed prefix is NOT re-walked'
);
assert_true(
	! in_array( 3, $g18_second_served, true ),
	'G18.3: attempt 2 never requested page 3 either'
);
assert_true(
	in_array( $g18_second_cp - 1, $g18_second_served, true ),
	'G18.3: attempt 2 starts exactly at the overlap boundary ' . ( $g18_second_cp - 1 ) . ' (served [' . implode( ',', $g18_second_served ) . '])'
);

// --- monotonic progress, then completion -----------------------------------
$g18_pages_seen = array_values( $g18_page_by_attempt );

for ( $g18_i = 1; $g18_i < count( $g18_pages_seen ); $g18_i++ ) {
	assert_true(
		$g18_pages_seen[ $g18_i ] >= $g18_pages_seen[ $g18_i - 1 ],
		'G18.3: the checkpoint never moves backwards across continues (attempt ' . ( $g18_i + 1 ) . ': ' . $g18_pages_seen[ $g18_i ] . ')'
	);
}

assert_true(
	end( $g18_pages_seen ) > $g18_pages_seen[0],
	'G18.3: the chain made real progress (' . $g18_pages_seen[0] . ' -> ' . end( $g18_pages_seen ) . ')'
);

assert_true( (int) $g18_final_cp['last_page'] === $g18_page_count, 'G18.3: the chain reached the final page (' . (int) $g18_final_cp['last_page'] . ' of ' . $g18_page_count . ')' );
assert_true( (bool) $g18_final_cp['complete'], 'G18.3: the checkpoint records the enumeration as progress-complete' );
assert_true( ! Conexao_Source_Enumeration_Checkpoint::is_resumable( $g18_final_cp ), 'G18.3: nothing is outstanding any more' );
assert_true( '' === (string) $g18_final_rec['state'], 'G18.3: the continuation record was CLEARED on completion (state=' . $g18_final_rec['state'] . ')' );
assert_true( array() === g18_armed_times(), 'G18.3: no WordPress event is left armed after completion' );
assert_true( 'none' === Conexao_Import_Continuation::get_state( $g18_source )['phase'], 'G18.3: the operator-facing phase is none — nothing is pending' );
assert_true( ! Conexao_Import_Continuation::is_locked( $g18_source ), 'G18.3: no lock is left behind' );

// --- the INHERITED G10 rule, restated because this stage must not weaken it --
//
// A RESUMED walk is complete-but-subset: it reached the final page (so the
// CHECKPOINT is progress-complete) but it deliberately did not re-request the
// already-confirmed prefix. `mark_missing_events()` reasons from absence, so a
// resumed run must never authorise it, and the engine records such a run as
// "not a complete listing" for that reason. This is pre-existing behaviour and
// is the reason the final run is reported as `warning`, not `success`.
assert_true(
	'failed' !== (string) $g18_final_history['status'],
	'G18.3: the final run is not a failure (status=' . (string) $g18_final_history['status'] . ')'
);
assert_true(
	array() === (array) ( isset( $g18_final_history['fatal_errors'] ) ? $g18_final_history['fatal_errors'] : array() ),
	'G18.3: the final run has no fatal errors'
);
assert_true(
	! (bool) $g18_final_history['complete'],
	'G18.3: the final RESUMED run is conservatively not recorded as a complete LISTING (G10: a resumed subset never authorises absence reconciliation)'
);

// The consequence that actually matters: nothing was hidden. If the resumed
// subset had been allowed to reconcile absence, the whole confirmed prefix
// would now be source_not_found.
$g18_hidden = g18_count_source_not_found( $g18_source );

assert_true(
	0 === $g18_hidden,
	'G18.3: the whole continue chain hid NOTHING — no event was flipped to source_not_found (' . $g18_hidden . ' found)'
);

// Completion is therefore asserted where it is actually true: the checkpoint
// reached the final page, and the scheduler cleared itself. Import History
// renders exactly that as "page 20 of 20 — complete".
$g18_progress_text = g18_progress_text( $g18_source );

echo '  progress cell: ' . $g18_progress_text . "\n";

assert_true(
	false !== strpos( $g18_progress_text, '20 of 20' ),
	'G18.3: Import History renders the final position as 20 of 20'
);
assert_true(
	false !== strpos( $g18_progress_text, 'complete' ),
	'G18.3: Import History renders that position as complete'
);
assert_true(
	false === strpos( $g18_progress_text, 'resumable' ),
	'G18.3: Import History does NOT say "resumable" once the enumeration is done'
);
assert_true(
	(int) $g18_final_history['found'] === (int) $g18_final_history['new'] + (int) $g18_final_history['updated'] + (int) $g18_final_history['unchanged'] + (int) $g18_final_history['skipped'],
	'G18.3: the final counts reconcile: found = new + updated + unchanged + skipped'
);

// ---------------------------------------------------------------------------
// G18.4 — the lifecycle is reconstructable from the LOG, and no entry claims a
//         success that did not happen.
// ---------------------------------------------------------------------------

test_section( 'G18.4 — the log reconstructs the interrupted/resumed lifecycle' );

$g18_reasons = g18_log_reasons( $g18_source );

assert_true(
	in_array( 'continuation_scheduled', $g18_reasons, true ),
	'G18.4: the log records that a continuation was SCHEDULED'
);
assert_true(
	in_array( 'continuation_started', $g18_reasons, true ),
	'G18.4: the log records that a continuation STARTED'
);

$g18_history_all        = Conexao_Import_History::get_for_source( $g18_source, 50 );
$g18_incomplete_entries = 0;
$g18_false_success      = 0;

foreach ( $g18_history_all as $g18_entry ) {
	$g18_is_incomplete = array_key_exists( 'complete', $g18_entry ) && ! (bool) $g18_entry['complete'];

	if ( $g18_is_incomplete ) {
		++$g18_incomplete_entries;
	}

	if ( $g18_is_incomplete && 'success' === (string) $g18_entry['status'] ) {
		++$g18_false_success;
	}
}

assert_true( $g18_incomplete_entries >= 1, 'G18.4: an interrupted run is recorded as INCOMPLETE (' . $g18_incomplete_entries . ' incomplete entries)' );
assert_true( 0 === $g18_false_success, 'G18.4: NO entry reports success for an incomplete listing (found ' . $g18_false_success . ')' );
assert_true(
	'none' === Conexao_Import_Continuation::get_state( $g18_source )['phase'],
	'G18.4: after completion the scheduler reports no continuation — so Import History cannot claim "resumable" either'
);

// ---------------------------------------------------------------------------
// G18.5 — ONE logical continuation per source. Duplicates are impossible, and
//         two simultaneous triggers cannot both run it.
// ---------------------------------------------------------------------------

test_section( 'G18.5 — duplicate continuation protection' );

g18_clear_checkpoints();
Conexao_Import_Continuation::reset_all();

$GLOBALS['g18_now'] = 1800000000;

$g18_dup_rec = g18_interrupt( $g18_cork, 20, 30.0, 10.0, 0, $g18_events_each );
$g18_dup_at  = (int) $g18_dup_rec['run_at'];

// Two reconciles (a repeated checkpoint write, two simultaneous resumable
// events) must not create a second continuation or move the first one.
Conexao_Import_Continuation::reconcile( $g18_source );
Conexao_Import_Continuation::reconcile( $g18_source );

$g18_dup_after = Conexao_Import_Continuation::get( $g18_source );

assert_true( Conexao_Import_Continuation::STATE_SCHEDULED === $g18_dup_after['state'], 'G18.5: repeated reconciles leave the continuation scheduled' );
assert_true( $g18_dup_at === (int) $g18_dup_after['run_at'], 'G18.5: repeated reconciles do NOT postpone the pending continuation' );
assert_true( 1 === (int) $g18_dup_after['attempts'], 'G18.5: repeated reconciles do NOT double-count attempts (got ' . (int) $g18_dup_after['attempts'] . ')' );
assert_true( 1 === count( g18_armed_times() ), 'G18.5: still exactly ONE WordPress event' );

// Repeated schedule() calls (a repeated import, a repeated checkpoint write).
Conexao_Import_Continuation::schedule( $g18_source );
Conexao_Import_Continuation::schedule( $g18_source );

$g18_dup_after2 = Conexao_Import_Continuation::get( $g18_source );

assert_true( $g18_dup_at === (int) $g18_dup_after2['run_at'], 'G18.5: repeated schedule() calls do NOT postpone the continuation' );
assert_true( 1 === count( g18_armed_times() ), 'G18.5: repeated schedule() calls do NOT create a second event' );
assert_true( 1 === count( Conexao_Import_Continuation::get_all() ), 'G18.5: the map holds exactly one record for the source' );

// Two simultaneous triggers (a cron pass and the shutdown fail-safe, or two
// admin tabs): the first consumes the continuation, the second finds nothing.
$GLOBALS['g18_runner_calls'] = array();

Conexao_Import_Continuation::set_runner(
	static function ( $source_id ) {
		$GLOBALS['g18_runner_calls'][] = (string) $source_id;
	}
);

$GLOBALS['g18_now'] = $g18_dup_at;
g18_install_double( $g18_region, 20, 400, 0, $g18_events_each );

$g18_first_trigger  = Conexao_Import_Continuation::run_due();
$g18_second_trigger = Conexao_Import_Continuation::run_due();

$g18_dup_served = g18_pages_served();
g18_remove_double();

assert_true( 1 === count( $GLOBALS['g18_runner_calls'] ), 'G18.5: two simultaneous triggers run the continuation exactly ONCE (got ' . count( $GLOBALS['g18_runner_calls'] ) . ')' );
assert_true( array() === $g18_dup_served, 'G18.5: the second trigger sends no upstream requests at all' );
assert_true( 'ran' === $g18_first_trigger[ $g18_source ], 'G18.5: the first trigger ran it' );
assert_true( 'cooling' === $g18_second_trigger[ $g18_source ], 'G18.5: the second trigger found it already consumed and re-scheduled (got ' . $g18_second_trigger[ $g18_source ] . ')' );
assert_true( 1 === count( g18_armed_times() ), 'G18.5: never more than one armed event per source' );

Conexao_Import_Continuation::reset_runner();



// ---------------------------------------------------------------------------
// G18.6 — concurrency: a held lock blocks a second worker, the lock is per
//         source, and an abandoned lock self-heals.
// ---------------------------------------------------------------------------

test_section( 'G18.6 — a source-level lock, never a global one' );

g18_clear_checkpoints();
Conexao_Import_Continuation::reset_all();

$GLOBALS['g18_now'] = 1800000000;

$g18_lock_rec = g18_interrupt( $g18_cork, 20, 30.0, 10.0, 0, $g18_events_each );
$g18_lock_at  = (int) $g18_lock_rec['run_at'];

$GLOBALS['g18_runner_calls'] = array();

Conexao_Import_Continuation::set_runner(
	static function ( $source_id ) {
		$GLOBALS['g18_runner_calls'][] = (string) $source_id;
	}
);

// The concurrent worker takes its lock 10 s before the cooldown expires and is
// still inside its run when the continuation becomes due.
$GLOBALS['g18_now'] = $g18_lock_at - 10;

assert_true( Conexao_Import_Continuation::acquire_lock( $g18_source ), 'G18.6: a worker can take the source lock' );
assert_true( Conexao_Import_Continuation::is_locked( $g18_source ), 'G18.6: the lock is observable while it is held' );
assert_true(
	! Conexao_Import_Continuation::acquire_lock( $g18_source ),
	'G18.6: a SECOND worker cannot take the same source lock while it is held'
);

$GLOBALS['g18_now'] = $g18_lock_at;
g18_install_double( $g18_region, 20, 400, 0, $g18_events_each );

$g18_locked_outcome = Conexao_Import_Continuation::run_due();
$g18_locked_served  = g18_pages_served();
g18_remove_double();

assert_true( 0 === count( $GLOBALS['g18_runner_calls'] ), 'G18.6: a worker that finds the lock held does NOT start a second run' );
assert_true( array() === $g18_locked_served, 'G18.6: a locked-out worker sends zero upstream requests (no duplicate imports)' );
assert_true( 'locked' === $g18_locked_outcome[ $g18_source ], 'G18.6: the outcome is reported as locked (got ' . $g18_locked_outcome[ $g18_source ] . ')' );
assert_true(
	Conexao_Import_Continuation::STATE_SCHEDULED === Conexao_Import_Continuation::get( $g18_source )['state'],
	'G18.6: the continuation survives the locked-out worker — it is not consumed'
);

Conexao_Import_Continuation::release_lock( $g18_source );
assert_true( ! Conexao_Import_Continuation::is_locked( $g18_source ), 'G18.6: releasing the lock makes the source eligible again' );

// A lock whose holder was killed (a restart) must not wedge its source.
update_option(
	Conexao_Import_Continuation::LOCK_PREFIX . $g18_source,
	(string) ( $GLOBALS['g18_now'] - Conexao_Import_Continuation::LOCK_TTL - 5 ),
	false
);

assert_true( ! Conexao_Import_Continuation::is_locked( $g18_source ), 'G18.6: an expired lock is not honoured' );
assert_true( Conexao_Import_Continuation::acquire_lock( $g18_source ), 'G18.6: an abandoned lock is taken over, so a crash cannot wedge a source forever' );

Conexao_Import_Continuation::release_lock( $g18_source );

// Per-source isolation: Cork's lock must not block Wicklow.
$g18_wicklow     = g18_config( 'wicklow' );
$g18_wicklow_rec = g18_interrupt( $g18_wicklow, 20, 30.0, 10.0, 0, $g18_events_each );

$GLOBALS['g18_runner_calls'] = array();
$GLOBALS['g18_now']          = (int) $g18_wicklow_rec['run_at'];

assert_true( Conexao_Import_Continuation::acquire_lock( $g18_source ), 'G18.6: Cork holds its own lock' );

g18_install_double( g18_region_for( $g18_wicklow ), 20, 400, 0, $g18_events_each );

$g18_isolated = Conexao_Import_Continuation::run_due( (string) $g18_wicklow['id'] );

g18_remove_double();

assert_true( 'ran' === $g18_isolated[ $g18_wicklow['id'] ], 'G18.6: Wicklow resumes even while Cork is locked' );
assert_true( in_array( (string) $g18_wicklow['id'], $GLOBALS['g18_runner_calls'], true ), 'G18.6: the lock is PER SOURCE — no global lock blocks an unrelated source' );
assert_true( ! in_array( $g18_source, $GLOBALS['g18_runner_calls'], true ), 'G18.6: Cork was not run by the Wicklow continuation' );
assert_true( Conexao_Import_Continuation::is_locked( $g18_source ), 'G18.6: Cork still holds its lock' );

Conexao_Import_Continuation::release_lock( $g18_source );
Conexao_Import_Continuation::reset_runner();


// ---------------------------------------------------------------------------
// G18.7 — when the continuation itself cannot be armed, the source is NOT
//         reported as successful. The failure is visible.
// ---------------------------------------------------------------------------

test_section( 'G18.7 — a continuation that cannot be armed fails visibly' );

g18_clear_checkpoints();
Conexao_Import_Continuation::reset_all();
Conexao_Import_Log::clear();
Conexao_Import_History::clear();

$GLOBALS['g18_now'] = 1800000000;

add_filter( 'pre_schedule_event', 'g18_refuse_scheduling', 99 );

$g18_fail_rec = g18_interrupt( $g18_cork, 20, 30.0, 10.0, 0, $g18_events_each );

remove_filter( 'pre_schedule_event', 'g18_refuse_scheduling', 99 );

assert_true( Conexao_Import_Continuation::STATE_FAILED === $g18_fail_rec['state'], 'G18.7: the continuation is recorded as FAILED (state=' . $g18_fail_rec['state'] . ')' );
assert_true( 0 === (int) $g18_fail_rec['run_at'], 'G18.7: no run time is recorded for a continuation that was never armed' );
assert_true( array() === g18_armed_times(), 'G18.7: nothing is armed, and that is exactly what the record says' );
assert_true( 'failed' === Conexao_Import_Continuation::get_state( $g18_source )['phase'], 'G18.7: the operator-facing phase is failed' );
assert_true(
	in_array( 'continuation_scheduling_failed', g18_log_reasons( $g18_source ), true ),
	'G18.7: the scheduling failure is written to the log as an ERROR'
);

$g18_fail_text = g18_progress_text( $g18_source );

assert_true( false !== strpos( $g18_fail_text, 'NOT scheduled' ), 'G18.7: Import History says the job is NOT scheduled (got: ' . $g18_fail_text . ')' );
assert_true( false === strpos( $g18_fail_text, 'resumable' ), 'G18.7: Import History does NOT promise \"resumable\" when scheduling failed' );

$g18_fail_history = Conexao_Import_History::get_last( $g18_source );

assert_true( is_array( $g18_fail_history ) && ! (bool) $g18_fail_history['complete'], 'G18.7: the run that could not schedule its continuation is NOT recorded as complete' );

// The failure is recoverable: the next run that can schedule again does.
$g18_healed = g18_interrupt( $g18_cork, 20, 30.0, 10.0, 0, $g18_events_each );

assert_true( Conexao_Import_Continuation::STATE_SCHEDULED === $g18_healed['state'], 'G18.7: once scheduling works again the continuation is armed (state=' . $g18_healed['state'] . ')' );
assert_true( 1 === count( g18_armed_times() ), 'G18.7: and exactly one event is armed' );

// ---------------------------------------------------------------------------
// G18.8 — a source that stops advancing is declared stalled, VISIBLY, and an
//         operator run that makes progress clears the stall.
// ---------------------------------------------------------------------------

test_section( 'G18.8 — a stall is visible, never a silent stop' );

g18_clear_checkpoints();
Conexao_Import_Continuation::reset_all();
Conexao_Import_Log::clear();

$GLOBALS['g18_now'] = 1800000000;

$g18_stall_rec = g18_interrupt( $g18_cork, 20, 30.0, 10.0, 0, $g18_events_each );

// A runner that does nothing models the real stall: the source keeps answering
// without advancing the checkpoint.
Conexao_Import_Continuation::set_runner(
	static function () {
	}
);

$g18_stall_states = array();

for ( $g18_s = 1; $g18_s <= Conexao_Import_Continuation::MAX_STALLED; $g18_s++ ) {
	$g18_stall_now = Conexao_Import_Continuation::get( $g18_source );

	if ( Conexao_Import_Continuation::STATE_SCHEDULED !== $g18_stall_now['state'] ) {
		break;
	}

	$GLOBALS['g18_now'] = (int) $g18_stall_now['run_at'];
	Conexao_Import_Continuation::run_due();

	$g18_after = Conexao_Import_Continuation::get( $g18_source );
	$g18_stall_states[] = array(
		'state'   => (string) $g18_after['state'],
		'stalled' => (int) $g18_after['stalled'],
	);
}

assert_true( count( $g18_stall_states ) >= 2, 'G18.8: the stall takes several attempts to declare (got ' . count( $g18_stall_states ) . ')' );
$g18_last_stall = array();

foreach ( $g18_stall_states as $g18_candidate ) {
	$g18_last_stall = $g18_candidate;
}

assert_true( 'scheduled' === $g18_stall_states[0]['state'], 'G18.8: the FIRST no-progress attempt is retried, not abandoned' );
assert_true( Conexao_Import_Continuation::MAX_STALLED === (int) $g18_last_stall['stalled'], 'G18.8: the stall counter reaches MAX_STALLED (got ' . (int) $g18_last_stall['stalled'] . ')' );
assert_true( 'exhausted' === (string) $g18_last_stall['state'], 'G18.8: the continuation is declared EXHAUSTED (state=' . $g18_last_stall['state'] . ')' );

$g18_stall_final = Conexao_Import_Continuation::get( $g18_source );

assert_true( 0 === (int) $g18_stall_final['run_at'], 'G18.8: an exhausted continuation has no armed run time' );
assert_true( array() === g18_armed_times(), 'G18.8: no WordPress event is left armed' );
assert_true( 'exhausted' === Conexao_Import_Continuation::get_state( $g18_source )['phase'], 'G18.8: the phase is exhausted' );
assert_true( in_array( 'continuation_exhausted', g18_log_reasons( $g18_source ), true ), 'G18.8: the stall is logged as an ERROR, so it cannot be missed' );

$g18_stall_text = g18_progress_text( $g18_source );

assert_true( false !== strpos( $g18_stall_text, 'NOT scheduled' ), 'G18.8: Import History says NOT scheduled (got: ' . $g18_stall_text . ')' );
assert_true( false === strpos( $g18_stall_text, 'resumable' ), 'G18.8: Import History never claims \"resumable\" for a stalled job' );

// Recovery: an operator run that DOES advance the checkpoint clears the stall.
// A 30 s budget would simply reproduce the original stop, so the recovery run
// is given the longer budget that actually moves the checkpoint forward.
Conexao_Import_Continuation::reset_runner();
$GLOBALS['g18_now'] += 3600;

$g18_stall_page_before = (int) Conexao_Source_Enumeration_Checkpoint::get( $g18_source )['last_page'];
$g18_recovered         = g18_interrupt( $g18_cork, 20, 60.0, 10.0, 0, $g18_events_each );
$g18_stall_page_after  = (int) Conexao_Source_Enumeration_Checkpoint::get( $g18_source )['last_page'];

assert_true(
	$g18_stall_page_after > $g18_stall_page_before,
	'G18.8: the recovery run really advanced the checkpoint (' . $g18_stall_page_before . ' -> ' . $g18_stall_page_after . ')'
);

assert_true(
	Conexao_Import_Continuation::STATE_SCHEDULED === $g18_recovered['state'],
	'G18.8: a run that makes progress clears the stall and re-arms the continuation (state=' . $g18_recovered['state'] . ')'
);
assert_true( 0 === (int) $g18_recovered['stalled'], 'G18.8: real progress resets the stall counter' );



// ---------------------------------------------------------------------------
// G18.9 — a RATE-LIMIT stop keeps every event, keeps the checkpoint and
//         schedules its own retry after the cooldown.
// ---------------------------------------------------------------------------

test_section( 'G18.9 — a rate-limited stop retries itself after the cooldown' );

g18_clear_checkpoints();
Conexao_Import_Continuation::reset_all();
Conexao_Import_Log::clear();
Conexao_Import_History::clear();

$GLOBALS['g18_now'] = 1800000000;

g18_activate( $g18_cork );

// The rate limit must be the ONLY reason this walk stops, so the time budget is
// made irrelevant and the pacing clock is held still. Without this the default
// 45 s budget would trip first (the injected clock advances 10 s per read) and
// the case would silently be a time-budget case instead.
g18_reset_pacing( 0.0 );
g18_force_time_budget( 100000.0 );

g18_install_double( $g18_region, 20, 400, 5, $g18_events_each );

$g18_rl_stats  = Conexao_Event_Importer::instance()->importer->run_source( $g18_source, false );
$g18_rl_served = g18_pages_served();

g18_remove_double();

$g18_rl_cp = Conexao_Source_Enumeration_Checkpoint::get( $g18_source );

assert_true( 4 === (int) $g18_rl_cp['last_page'], 'G18.9: the checkpoint stays at the last CONFIRMED page (4), never at the refused page 5' );
assert_true( 'rate_limited' === (string) $g18_rl_cp['last_failure'], 'G18.9: the checkpoint records the rate_limited failure class' );
assert_true( ! $g18_rl_stats['complete'], 'G18.9: the run is INCOMPLETE — it is not falsely marked complete' );
assert_true( in_array( 5, $g18_rl_served, true ), 'G18.9: page 5 really was refused' );

$g18_rl_rec = Conexao_Import_Continuation::get( $g18_source );

assert_true( Conexao_Import_Continuation::STATE_SCHEDULED === $g18_rl_rec['state'], 'G18.9: a continuation is SCHEDULED for the rate-limited source' );
assert_true( 'rate_limited' === (string) $g18_rl_rec['reason'], 'G18.9: the continuation records the rate_limited reason' );
assert_true(
	Conexao_Import_Continuation::COOLDOWN_SECONDS === ( (int) $g18_rl_rec['run_at'] - (int) $GLOBALS['g18_now'] ),
	'G18.9: the retry is a full cooldown away'
);
assert_true( 1 === count( g18_armed_times() ), 'G18.9: exactly one retry is armed' );

// Nothing was removed, and nothing was hidden.
assert_true(
	1 === count( g18_native_ids( $g18_source, 'g18probe-1-0' ) ),
	'G18.9: an event imported before the rate limit is NOT removed'
);
assert_true( 0 === g18_count_source_not_found( $g18_source ), 'G18.9: the rate-limited walk hid nothing (no source_not_found)' );

// No retry before the cooldown expires.
$GLOBALS['g18_now']          = (int) $g18_rl_rec['run_at'] - 1;
$GLOBALS['g18_runner_calls'] = array();

Conexao_Import_Continuation::set_runner(
	static function ( $source_id ) {
		$GLOBALS['g18_runner_calls'][] = (string) $source_id;
	}
);

g18_install_double( $g18_region, 20, 400, 0, $g18_events_each );

$g18_rl_early = Conexao_Import_Continuation::run_due();
$g18_rl_early_served = g18_pages_served();

g18_remove_double();

assert_true( 'cooling' === $g18_rl_early[ $g18_source ], 'G18.9: the retry does not start before the cooldown' );
assert_true( 0 === count( $GLOBALS['g18_runner_calls'] ), 'G18.9: nothing is retried early' );
assert_true( array() === $g18_rl_early_served, 'G18.9: not one request is made before the cooldown expires' );

// After the cooldown the retry resumes from the saved page and continues.
Conexao_Import_Continuation::reset_runner();

$GLOBALS['g18_now'] = (int) $g18_rl_rec['run_at'];

g18_reset_pacing( 0.0 );
g18_install_double( $g18_region, 20, 400, 0, $g18_events_each );

$g18_rl_retry = Conexao_Import_Continuation::run_due();

$g18_rl_retry_served = g18_pages_served();
$g18_rl_after        = Conexao_Source_Enumeration_Checkpoint::get( $g18_source );
$g18_rl_continuation = Conexao_Import_Continuation::get( $g18_source );

g18_remove_double();

$g18_rl_pages = array_values(
	array_filter(
		$g18_rl_retry_served,
		static function ( $page ) {
			return $page >= 2;
		}
	)
);

assert_true( 'ran' === $g18_rl_retry[ $g18_source ], 'G18.9: the retry starts once the cooldown has expired' );
assert_true( ! empty( $g18_rl_pages ), 'G18.9: the retry really fetched listing pages' );
assert_true( 3 === min( $g18_rl_pages ), 'G18.9: it resumes at the overlap boundary (page 3) of the saved checkpoint' );
assert_true( ! in_array( 2, $g18_rl_pages, true ), 'G18.9: it does not restart from the beginning' );
assert_true( (int) $g18_rl_after['last_page'] === 20, 'G18.9: processing continued to the final page (' . (int) $g18_rl_after['last_page'] . ' of 20)' );
assert_true( (bool) $g18_rl_after['complete'], 'G18.9: the enumeration is progress-complete after the retry' );
assert_true( '' === (string) $g18_rl_continuation['state'], 'G18.9: the continuation is cleared once the enumeration completed' );
assert_true( array() === g18_armed_times(), 'G18.9: no retry is left armed' );
assert_true( 0 === g18_count_source_not_found( $g18_source ), 'G18.9: the retry did not hide the source' );

g18_clear_time_budget();


// ---------------------------------------------------------------------------
// G18.10 — five resumable sources continue INDEPENDENTLY, and none starves.
// ---------------------------------------------------------------------------

test_section( 'G18.10 — five resumable sources, five independent continuations' );

g18_clear_checkpoints();
Conexao_Import_Continuation::reset_all();
Conexao_Import_Log::clear();

$GLOBALS['g18_now'] = 1800000000;

$g18_counties = array( 'cork', 'wicklow', 'wexford', 'waterford', 'tipperary' );
$g18_multi_pages  = 6;
$g18_multi_each   = 2;
$g18_map      = array();
$g18_ids      = array();
$g18_started  = array();

foreach ( $g18_counties as $g18_county ) {
	$g18_cfg = g18_config( $g18_county );

	$g18_map[ $g18_county ] = g18_region_for( $g18_cfg );
	$g18_ids[ $g18_county ] = (string) $g18_cfg['id'];

	$g18_started[ $g18_county ] = g18_interrupt( $g18_cfg, $g18_multi_pages, 30.0, 10.0, 0, $g18_multi_each );

	assert_true(
		Conexao_Import_Continuation::STATE_SCHEDULED === $g18_started[ $g18_county ]['state'],
		'G18.10: ' . $g18_county . ' is scheduled for its own continue'
	);
}

assert_true( count( $g18_counties ) === count( Conexao_Import_Continuation::get_all() ), 'G18.10: every county has its own continuation record' );
assert_true( count( $g18_counties ) === count( g18_armed_times() ), 'G18.10: every county has its own armed event' );
$g18_armed_sources = g18_armed_sources();

assert_true(
	count( $g18_counties ) === count( array_unique( $g18_armed_sources ) ),
	'G18.10: the armed events are distinct — no county overwrote another (got ' . implode( ',', $g18_armed_sources ) . ')'
);

foreach ( $g18_counties as $g18_county ) {
	$g18_cp = Conexao_Source_Enumeration_Checkpoint::get( $g18_ids[ $g18_county ] );

	assert_true( Conexao_Source_Enumeration_Checkpoint::is_resumable( $g18_cp ), 'G18.10: ' . $g18_county . ' holds its own resumable checkpoint' );
	assert_true( 3 === (int) $g18_cp['last_page'], 'G18.10: ' . $g18_county . ' stopped at its own page 3' );
}

// One pass runs all five, exactly once each: no starvation, no interference.
$GLOBALS['g18_runner_calls'] = array();

Conexao_Import_Continuation::set_runner(
	static function ( $source_id ) {
		$GLOBALS['g18_runner_calls'][] = (string) $source_id;
	}
);

$GLOBALS['g18_now'] = max( array_map( 'intval', g18_armed_times() ) );
$g18_multi_outcomes = Conexao_Import_Continuation::run_due();

assert_true( 5 === count( $GLOBALS['g18_runner_calls'] ), 'G18.10: all five counties were continued in one pass (got ' . count( $GLOBALS['g18_runner_calls'] ) . ')' );
assert_true( 5 === count( array_unique( $GLOBALS['g18_runner_calls'] ) ), 'G18.10: no county was continued twice' );

foreach ( $g18_counties as $g18_county ) {
	assert_true( 'ran' === $g18_multi_outcomes[ $g18_ids[ $g18_county ] ], 'G18.10: ' . $g18_county . ' ran its own continuation' );
}

Conexao_Import_Continuation::reset_runner();

// Now let all five finish for real, in one pass, against one transport.
$GLOBALS['g18_now'] = max( array_map( 'intval', g18_armed_times() ) );

g18_reset_pacing( 0.0 );
g18_force_time_budget( 100000.0 );
g18_install_multi_double( $g18_map, $g18_multi_pages, $g18_multi_each );

$g18_multi_real = Conexao_Import_Continuation::run_due();

g18_remove_double();
g18_clear_time_budget();

foreach ( $g18_counties as $g18_county ) {
	$g18_id = $g18_ids[ $g18_county ];
	$g18_cp = Conexao_Source_Enumeration_Checkpoint::get( $g18_id );

	assert_true( 'ran' === $g18_multi_real[ $g18_id ], 'G18.10: ' . $g18_county . ' ran its continuation to completion' );
	assert_true( $g18_multi_pages === (int) $g18_cp['last_page'], 'G18.10: ' . $g18_county . ' completed at page ' . $g18_multi_pages . ' (got ' . (int) $g18_cp['last_page'] . ') ' );
	assert_true( (bool) $g18_cp['complete'], 'G18.10: ' . $g18_county . ' is progress-complete' );
	assert_true( '' === (string) Conexao_Import_Continuation::get( $g18_id )['state'], 'G18.10: ' . $g18_county . ' cleared its own continuation' );
	assert_true( g18_count_events( $g18_id ) > 0, 'G18.10: ' . $g18_county . ' imported its own events' );
}

foreach ( $g18_counties as $g18_county ) {
	$g18_id  = $g18_ids[ $g18_county ];
	$g18_h   = Conexao_Import_History::get_last( $g18_id );
	echo '  per-county: ' . $g18_county . ' region=' . $g18_map[ $g18_county ] . ' events=' . g18_count_events( $g18_id ) . ' found=' . ( is_array( $g18_h ) ? (int) $g18_h['found'] : -1 ) . ' skipped=' . ( is_array( $g18_h ) ? (int) $g18_h['skipped'] : -1 ) . ' status=' . ( is_array( $g18_h ) ? $g18_h['status'] : '?' ) . ' msg=' . ( is_array( $g18_h ) ? $g18_h['message'] : '' ) . "\n";
}

assert_true( array() === g18_armed_times(), 'G18.10: no events remain armed once every county is complete' );
assert_true( array() === Conexao_Import_Continuation::get_all(), 'G18.10: the continuation map is empty' );

// Cross-source isolation: each county carries its OWN source identity.
assert_true(
	array() === g18_native_ids( $g18_ids['cork'], 'g18probe-wicklow-3-0' ),
	'G18.10: a Wicklow event is not stored under the Cork source — no cross-source contamination'
);

// ---------------------------------------------------------------------------
// G18.11 — the chain survives a restart that loses its timer, because the state
//          is persisted and not in memory.
// ---------------------------------------------------------------------------

test_section( 'G18.11 — the continuation survives a restart' );

g18_clear_checkpoints();
Conexao_Import_Continuation::reset_all();
Conexao_Import_Log::clear();

$GLOBALS['g18_now'] = 1800000000;

$g18_restart_rec = g18_interrupt( $g18_cork, 20, 30.0, 10.0, 0, $g18_events_each );
$g18_restart_at  = (int) $g18_restart_rec['run_at'];

assert_true( 1 === count( g18_armed_times() ), 'G18.11: precondition — one event is armed' );

// A restart that clears the whole WordPress cron schedule. That is the worst
// case for a request-driven timer: only the database survives.
_set_cron_array( array() );

assert_true( array() === g18_armed_times(), 'G18.11: the timer was really lost' );

$g18_stored = get_option( Conexao_Import_Continuation::OPTION_KEY, array() );

assert_true( is_array( $g18_stored ) && isset( $g18_stored[ $g18_source ] ), 'G18.11: the continuation record lives in the DATABASE, not in memory' );
assert_true(
	Conexao_Import_Continuation::STATE_SCHEDULED === $g18_stored[ $g18_source ]['state'],
	'G18.11: the persisted record still says scheduled'
);

assert_true( 1 === Conexao_Import_Continuation::rearm_missing(), 'G18.11: the next request re-arms the lost timer from persisted state' );

$g18_rearmed_times = g18_armed_times();

assert_true( 1 === count( $g18_rearmed_times ), 'G18.11: exactly one event is armed again' );
assert_true(
	$g18_restart_at === (int) $g18_rearmed_times[0],
	'G18.11: it is re-armed at the SAME time — no cooldown is restarted, and none is skipped'
);
assert_true( 0 === Conexao_Import_Continuation::rearm_missing(), 'G18.11: re-arming is idempotent — a second call adds nothing' );

// And it still executes, resuming correctly.
$GLOBALS['g18_now'] = $g18_restart_at;

g18_reset_pacing( 0.0 );
g18_force_time_budget( 100000.0 );
g18_install_double( $g18_region, 20, 400, 0, $g18_events_each );

$g18_restart_outcome = Conexao_Import_Continuation::run_due();

g18_remove_double();
g18_clear_time_budget();

$g18_restart_cp = Conexao_Source_Enumeration_Checkpoint::get( $g18_source );

assert_true( 'ran' === $g18_restart_outcome[ $g18_source ], 'G18.11: the re-armed continuation executes after the restart' );
assert_true( 20 === (int) $g18_restart_cp['last_page'], 'G18.11: it resumed from the checkpoint and reached the final page' );
assert_true( '' === (string) Conexao_Import_Continuation::get( $g18_source )['state'], 'G18.11: it cleared itself on completion' );


// ---------------------------------------------------------------------------
// G18.12 — idempotence: repeating the whole exercise adds no events, and the
//          G17 reappearance restoration is intact.
// ---------------------------------------------------------------------------

test_section( 'G18.12 — no duplicate events, and G17 restoration still works' );

$g18_idem_before = g18_count_events( $g18_source );

g18_reset_pacing( 0.0 );
g18_force_time_budget( 100000.0 );
g18_install_double( $g18_region, 20, 400, 0, $g18_events_each );

Conexao_Event_Importer::instance()->importer->run_source( $g18_source, false );

g18_remove_double();
g18_clear_time_budget();

$g18_idem_after = g18_count_events( $g18_source );

assert_true( $g18_idem_before > 0, 'G18.12: precondition — the earlier sections stored events for this source' );
assert_true(
	$g18_idem_after === $g18_idem_before,
	'G18.12: re-running a completed source creates NO new events (' . $g18_idem_before . ' -> ' . $g18_idem_after . ')'
);

// No native id exists twice: the G16/G17 duplicate-record defect stays fixed.
foreach ( array( 'g18probe-1-0', 'g18probe-3-1', 'g18probe-12-2', 'g18probe-20-0' ) as $g18_probe_id ) {
	$g18_matches = g18_native_ids( $g18_source, $g18_probe_id );

	assert_true(
		count( $g18_matches ) <= 1,
		'G18.12: native id ' . $g18_probe_id . ' exists at most once (found ' . count( $g18_matches ) . ')'
	);
}

// G17: a hidden event that reappears must be RESTORED, not duplicated.
$g18_reappear = g18_native_ids( $g18_source, 'g18probe-5-0' );

assert_true( ! empty( $g18_reappear ), 'G18.12 (G17): precondition — a reappearance probe event exists' );

if ( ! empty( $g18_reappear ) ) {
	$g18_reappear_id  = (int) $g18_reappear[0];
	$g18_total_before = g18_count_events( $g18_source );

	$g18_hidden_before = g18_count_source_not_found( $g18_source );

	Conexao_Event_Status::set_status( $g18_reappear_id, Conexao_Event_Status::SOURCE_NOT_FOUND );

	assert_true(
		$g18_hidden_before + 1 === g18_count_source_not_found( $g18_source ),
		'G18.12 (G17): precondition — the event is hidden as source_not_found'
	);

	g18_reset_pacing( 0.0 );
	g18_force_time_budget( 100000.0 );
	g18_install_double( $g18_region, 20, 400, 0, $g18_events_each );

	Conexao_Event_Importer::instance()->importer->run_source( $g18_source, false );

	g18_remove_double();
	g18_clear_time_budget();

	$g18_restored = g18_native_ids( $g18_source, 'g18probe-5-0' );

	assert_true( 1 === count( $g18_restored ), 'G18.12 (G17): a reappearing event is RESTORED — still exactly one native record (found ' . count( $g18_restored ) . ')' );
	assert_true(
		Conexao_Event_Status::SOURCE_NOT_FOUND !== Conexao_Event_Status::get_status( $g18_reappear_id ),
		'G18.12 (G17): the restored event is visible again'
	);
	assert_true(
		g18_count_events( $g18_source ) === $g18_total_before,
		'G18.12 (G17): restoration did NOT insert a second event'
	);
}

// ---------------------------------------------------------------------------
// G18.13 — a DRY RUN never changes scheduling state.
// ---------------------------------------------------------------------------

test_section( 'G18.13 — a dry run changes no scheduling state' );

g18_clear_checkpoints();
Conexao_Import_Continuation::reset_all();

$GLOBALS['g18_now'] = 1800000000;

$g18_dry_rec    = g18_interrupt( $g18_cork, 20, 30.0, 10.0, 0, $g18_events_each );
$g18_dry_before = Conexao_Import_Continuation::get( $g18_source );

g18_reset_pacing( 0.0 );
g18_install_double( $g18_region, 20, 400, 0, $g18_events_each );

Conexao_Event_Importer::instance()->importer->run_source( $g18_source, true );

g18_remove_double();

$g18_dry_after = Conexao_Import_Continuation::get( $g18_source );

assert_true( $g18_dry_before['state'] === $g18_dry_after['state'], 'G18.13: a dry run leaves the continuation state unchanged' );
assert_true( (int) $g18_dry_before['run_at'] === (int) $g18_dry_after['run_at'], 'G18.13: a dry run does not move the scheduled time' );
assert_true( (int) $g18_dry_before['attempts'] === (int) $g18_dry_after['attempts'], 'G18.13: a dry run does not consume an attempt' );
assert_true( 1 === count( g18_armed_times() ), 'G18.13: a dry run does not disturb the armed event' );

// A dry run of a RESUMABLE source that has NO continuation must not create one:
// classification is not a run.
g18_clear_checkpoints();
Conexao_Import_Continuation::reset_all();

g18_reset_pacing( 10.0 );
g18_install_double( $g18_region, 20, 400, 0, $g18_events_each );
g18_force_time_budget( 30.0 );

Conexao_Event_Importer::instance()->importer->run_source( $g18_source, true );

g18_clear_time_budget();
g18_remove_double();

$g18_dry_cp = Conexao_Source_Enumeration_Checkpoint::get( $g18_source );

assert_true( Conexao_Source_Enumeration_Checkpoint::is_resumable( $g18_dry_cp ), 'G18.13: the dry run left a resumable checkpoint — it classifies, it does not write' );
assert_true( '' === (string) Conexao_Import_Continuation::get( $g18_source )['state'], 'G18.13: a DRY RUN does not schedule a continuation' );
assert_true( array() === g18_armed_times(), 'G18.13: a dry run arms nothing' );


// ---------------------------------------------------------------------------
// G18.14 — Import History tells the truth in EVERY phase.
//
// The whole point of this stage is the operator-facing promise, so the strings
// are asserted through the production renderer, not a copy of its logic.
// ---------------------------------------------------------------------------

/**
 * Re-point a synthetic history row at the source’s CURRENT checkpoint.
 *
 * The renderer deliberately makes no lifecycle claim for a row whose
 * checkpoint is no longer the live one, so a test that re-runs the source must
 * refresh the row it feeds the renderer — exactly as the real Import History
 * would, because a new run writes a new row.
 *
 * @param array  $entry     History entry (modified by reference).
 * @param string $source_id Source slug.
 * @return void
 */
function g18_ui_refresh( array &$entry, $source_id ) {
	$live = Conexao_Source_Enumeration_Checkpoint::get( $source_id );

	$entry['source']                    = $source_id;
	$entry['checkpoint']['last_page']   = (int) $live['last_page'];
	$entry['checkpoint']['page_count']  = (int) $live['page_count'];
	$entry['checkpoint']['complete']    = (bool) $live['complete'];
	$entry['checkpoint']['last_failure'] = (string) $live['last_failure'];
	$entry['checkpoint']['updated_at']  = (string) $live['updated_at'];
}

test_section( 'G18.14 — Import History is truthful in every phase' );

g18_clear_checkpoints();
Conexao_Import_Continuation::reset_all();
Conexao_Import_Log::clear();

$GLOBALS['g18_now'] = 1800000000;

$g18_ui_rec  = g18_interrupt( $g18_cork, 20, 30.0, 10.0, 0, $g18_events_each );
$g18_ui_live = Conexao_Source_Enumeration_Checkpoint::get( $g18_source );

$g18_ui_entry = array(
	'source'     => $g18_source,
	'checkpoint' => array(
		'has_checkpoint' => true,
		'last_page'      => (int) $g18_ui_live['last_page'],
		'page_count'     => (int) $g18_ui_live['page_count'],
		'complete'       => (bool) $g18_ui_live['complete'],
		'last_failure'   => (string) $g18_ui_live['last_failure'],
		'updated_at'     => (string) $g18_ui_live['updated_at'],
	),
);

// (1) scheduled, cooldown running — the ONLY shape allowed to say "resumable".
$g18_ui_text = Conexao_Event_Sources::history_progress_text( $g18_ui_entry );

echo '  phase cooling : ' . $g18_ui_text . "\n";

assert_true( false !== strpos( $g18_ui_text, 'page 3 of 20 (time_budget)' ), 'G18.14: the cell states the position and the reason' );
assert_true( false !== strpos( $g18_ui_text, 'resumable' ), 'G18.14: it says resumable — and a continuation IS scheduled' );
assert_true( false !== strpos( $g18_ui_text, 'next run in 30 minutes' ), 'G18.14: it states when the next run happens, from the SERVER clock (got: ' . $g18_ui_text . ')' );
assert_true( false === strpos( $g18_ui_text, 'NOT scheduled' ), 'G18.14: it does not contradict itself' );

// (2) cooldown elapsed, worker imminent.
$GLOBALS['g18_now'] = (int) $g18_ui_rec['run_at'];

$g18_ui_due = Conexao_Event_Sources::history_progress_text( $g18_ui_entry );

echo '  phase due     : ' . $g18_ui_due . "\n";

assert_true( false !== strpos( $g18_ui_due, 'resume scheduled' ), 'G18.14: an elapsed cooldown reads as resume scheduled (got: ' . $g18_ui_due . ')' );

// (3) a worker is holding the source right now.
Conexao_Import_Continuation::acquire_lock( $g18_source );

$g18_ui_running = Conexao_Event_Sources::history_progress_text( $g18_ui_entry );

echo '  phase running : ' . $g18_ui_running . "\n";

assert_true( false !== strpos( $g18_ui_running, 'resuming' ), 'G18.14: a held lock reads as resuming (got: ' . $g18_ui_running . ')' );
assert_true( false === strpos( $g18_ui_running, 'NOT scheduled' ), 'G18.14: a running job is never described as unscheduled' );

Conexao_Import_Continuation::release_lock( $g18_source );

// (4) THE defect shape: outstanding pages, but nothing will run. This is the
//     state the UI used to mis-describe as "resumable".
Conexao_Import_Continuation::reset_all();

$g18_ui_orphan = Conexao_Event_Sources::history_progress_text( $g18_ui_entry );

echo '  phase orphan  : ' . $g18_ui_orphan . "\n";

assert_true( false !== strpos( $g18_ui_orphan, 'NOT scheduled' ), 'G18.14: with no continuation the cell says NOT scheduled' );
assert_true( false === strpos( $g18_ui_orphan, 'resumable' ), 'G18.14: it NEVER says resumable when nothing is scheduled — the defect this stage fixes' );

// (5) scheduling failed.
add_filter( 'pre_schedule_event', 'g18_refuse_scheduling', 99 );
g18_interrupt( $g18_cork, 20, 30.0, 10.0, 0, $g18_events_each );
remove_filter( 'pre_schedule_event', 'g18_refuse_scheduling', 99 );

g18_ui_refresh( $g18_ui_entry, $g18_source );

$g18_ui_failed = Conexao_Event_Sources::history_progress_text( $g18_ui_entry );

echo '  phase failed  : ' . $g18_ui_failed . "\n";

assert_true( false !== strpos( $g18_ui_failed, 'NOT scheduled' ), 'G18.14: a scheduling failure reads as NOT scheduled' );
assert_true( false === strpos( $g18_ui_failed, 'resumable' ), 'G18.14: a scheduling failure never claims resumable' );


// (6) stalled out.
g18_clear_checkpoints();
Conexao_Import_Continuation::reset_all();
$GLOBALS['g18_now'] = 1800000000;

g18_interrupt( $g18_cork, 20, 30.0, 10.0, 0, $g18_events_each );

Conexao_Import_Continuation::set_runner(
	static function () {
	}
);

for ( $g18_ui_i = 0; $g18_ui_i < Conexao_Import_Continuation::MAX_STALLED; $g18_ui_i++ ) {
	$g18_ui_now = Conexao_Import_Continuation::get( $g18_source );

	if ( Conexao_Import_Continuation::STATE_SCHEDULED !== $g18_ui_now['state'] ) {
		break;
	}

	$GLOBALS['g18_now'] = (int) $g18_ui_now['run_at'];
	Conexao_Import_Continuation::run_due();
}

Conexao_Import_Continuation::reset_runner();

g18_ui_refresh( $g18_ui_entry, $g18_source );

$g18_ui_exhausted = Conexao_Event_Sources::history_progress_text( $g18_ui_entry );

echo '  phase stalled : ' . $g18_ui_exhausted . "\n";

assert_true( 'exhausted' === Conexao_Import_Continuation::get_state( $g18_source )['phase'], 'G18.14: precondition — the source is exhausted' );
assert_true( false !== strpos( $g18_ui_exhausted, 'NOT scheduled' ), 'G18.14: a stalled job reads as NOT scheduled' );
assert_true( false === strpos( $g18_ui_exhausted, 'resumable' ), 'G18.14: a stalled job never claims resumable' );

// (7) the enumeration is done.
$g18_ui_done_entry                    = $g18_ui_entry;
$g18_ui_done_entry['checkpoint']['last_page']    = 20;
$g18_ui_done_entry['checkpoint']['complete']     = true;
$g18_ui_done_entry['checkpoint']['last_failure'] = '';

$g18_ui_done = Conexao_Event_Sources::history_progress_text( $g18_ui_done_entry );

echo '  phase done    : ' . $g18_ui_done . "\n";

assert_true( false !== strpos( $g18_ui_done, '20 of 20' ), 'G18.14: a finished enumeration reads 20 of 20' );
assert_true( false !== strpos( $g18_ui_done, 'complete' ), 'G18.14: a finished enumeration reads complete' );
assert_true( false === strpos( $g18_ui_done, 'resumable' ), 'G18.14: a finished enumeration never claims resumable' );

// (8) an OLDER row must not borrow the current lifecycle.
$g18_ui_old_entry                             = $g18_ui_entry;
$g18_ui_old_entry['checkpoint']['updated_at'] = '1999-01-01 00:00:00';

$g18_ui_old = Conexao_Event_Sources::history_progress_text( $g18_ui_old_entry );

echo '  phase old row : ' . $g18_ui_old . "\n";

assert_true(
	'page 3 of 20 (time_budget)' === $g18_ui_old,
	'G18.14: a superseded row keeps the historical fact and makes NO lifecycle claim (got: ' . $g18_ui_old . ')'
);

// (9) no checkpoint at all: the cell stays a dash.
$g18_ui_none = Conexao_Event_Sources::history_progress_text(
	array(
		'source'     => $g18_source,
		'checkpoint' => array( 'has_checkpoint' => false ),
	)
);

assert_true( '—' === $g18_ui_none, 'G18.14: a source with no checkpoint renders a dash, as before' );

Conexao_Import_Continuation::reset_all();

test_finish( "G18 continuation" );
