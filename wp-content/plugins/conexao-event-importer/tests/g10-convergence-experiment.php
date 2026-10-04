<?php
/**
 * G10 controlled convergence experiment (measurements only).
 *
 * §22/§23 of the G10 brief. Drives a deterministic, in-memory Eventbrite
 * fixture through a sequence of BOUNDED runs and reports, for each run:
 *
 *   - pages requested this run,
 *   - checkpoint before / after,
 *   - unique events recovered,
 *   - overlap requests and overlap duplicates,
 *   - cumulative coverage.
 *
 * It then runs the SAME bounded-run sequence twice — once with checkpointing
 * disabled and once with it enabled — so the request savings are a measured
 * comparison rather than an assertion of intent.
 *
 * Nothing here contacts a real upstream and nothing is written to production:
 * every "event" lives in an in-memory set.
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
		Conexao_Source_Cooldown::OPTION_KEY,
		Conexao_Source_Enumeration_Checkpoint::OPTION_KEY,
	)
);

register_shutdown_function(
	static function () use ( $restore_options ) {
		test_options_restore( $restore_options, true );
	}
);

add_filter( 'conexao_eventbrite_backoff_seconds', '__return_zero', 99 );
add_filter( 'conexao_eventbrite_page_delay_seconds', '__return_zero', 99 );

/**
 * Total pages the fixture listing declares.
 *
 * Mirrors the measured Eventbrite cap for a small county so the experiment
 * converges in a handful of bounded runs.
 *
 * @var int
 */
const G10_TOTAL_PAGES = 12;

/**
 * Events served per page (the measured Eventbrite page size).
 *
 * @var int
 */
const G10_PAGE_SIZE = 20;

/**
 * Per-run outbound request budget (the bounded-run constraint).
 *
 * Four requests is deliberately small: it models the measured reality that a
 * county walk is cut short long before the listing ends, so each run can only
 * advance the enumeration by a few pages.
 *
 * @var int
 */
const G10CONV_BUDGET = 4;

/**
 * How many bounded runs the sequence performs.
 *
 * @var int
 */
const G10CONV_MAX_RUNS = 10;

/**
 * Install the deterministic paging fixture.
 *
 * @return void
 */
function g10conv_install() {
	$GLOBALS['g10conv_served'] = array();

	remove_all_filters( 'pre_http_request' );

	add_filter(
		'pre_http_request',
		static function ( $preempt, $args, $url ) {
			$page = 1;

			if ( preg_match( '/[?&]page=(\d+)/', $url, $m ) ) {
				$page = (int) $m[1];
			}

			$GLOBALS['g10conv_served'][] = $page;

			$events = array();

			for ( $i = 0; $i < G10_PAGE_SIZE; $i++ ) {
				$events[] = array(
					'id'        => 'evt-' . $page . '-' . $i,
					'name'      => 'Event ' . $page . '-' . $i,
					'url'       => 'https://www.eventbrite.ie/e/evt-' . $page . '-' . $i,
					'start'     => array( 'local' => '2099-01-01T10:00:00' ),
					'locations' => array(
						array(
							'type'    => 'region',
							'name'    => 'Cork',
							'address' => array(
								'region'  => 'Cork',
								'country' => 'IE',
							),
						),
					),
				);
			}

			$payload = array(
				'search_data' => array(
					'events' => array(
						'results'    => $events,
						'pagination' => array(
							'page_count'   => G10_TOTAL_PAGES,
							// Objects fit exactly inside the declared pages, so
							// the G8 truncation guard stays out of the way and
							// the experiment measures checkpointing alone.
							'object_count' => G10_TOTAL_PAGES * G10_PAGE_SIZE,
							'page_number'  => $page,
							'page_size'    => G10_PAGE_SIZE,
						),
					),
				),
			);

			return array(
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'body'     => '<html><body><script>window.__SERVER_DATA__ = ' . wp_json_encode( $payload ) . ';</script></body></html>',
				'headers'  => array(),
				'cookies'  => array(),
			);
		},
		10,
		3
	);
}

/**
 * Run one bounded walk and collect its measurements.
 *
 * The bound is the REAL per-run request budget (the same G4C mechanism
 * production uses), not a synthetic page cap. That is the honest model of the
 * G8/G9 measurements: a Cork walk is cut short by a request budget or a 429
 * after roughly 31 requests, not by the listing running out of pages.
 *
 * @param string $source_id  Source slug.
 * @param array  $config     Source config.
 * @param array  $seen       Cumulative unique-event set (by reference).
 * @param int   $max_pages   Retained for readability; the budget is authoritative.
 * @return array
 */
function g10conv_run( $source_id, array $config, array &$seen, $max_pages ) {
	$GLOBALS['g10conv_served'] = array();

	Conexao_Eventbrite_Pacing::begin_run();
	Conexao_Request_Budget::begin_run( (int) $max_pages );

	$handler = new Conexao_Source_Eventbrite( $config );
	$events  = $handler->fetch_events();

	Conexao_Request_Budget::begin_run();

	$ids = array();

	foreach ( $events as $event ) {
		if ( isset( $event['source_id'] ) ) {
			$ids[] = (string) $event['source_id'];
		}
	}

	$before = count( $seen );

	foreach ( $ids as $id ) {
		$seen[ $id ] = true;
	}

	$cp = Conexao_Source_Enumeration_Checkpoint::get( $source_id );

	return array(
		'requests'   => count( $GLOBALS['g10conv_served'] ),
		'pages'      => array_values( array_unique( $GLOBALS['g10conv_served'] ) ),
		'raw_events' => count( $ids ),
		'unique_ids' => count( array_unique( $ids ) ),
		'new_unique' => count( $seen ) - $before,
		'checkpoint' => (int) $cp['last_page'],
		'complete'   => (bool) $handler->is_fetch_complete(),
		'resumed'    => (bool) $handler->did_resume(),
	);
}

/**
 * Drive the bounded-run sequence and print a measurement table.
 *
 * @param bool $checkpointing Whether resume is enabled for this sequence.
 * @return array Summary totals.
 */
function g10conv_sequence( $checkpointing ) {
	$source = 'g10conv_cork';
	$config = g10_cork_config_convergence();
	$seen   = array();

	if ( ! $checkpointing ) {
		add_filter( 'conexao_eventbrite_checkpoint_enabled', '__return_false', 99 );
	}

	echo "\n--- " . ( $checkpointing ? 'AFTER (checkpointed)' : 'BEFORE (restart from page 1)' ) . " ---\n";
	echo sprintf( "%-6s %-9s %-12s %-11s %-9s %-9s %s\n", 'run', 'requests', 'pages', 'checkpoint', 'new ids', 'cumul', 'complete' );

	$runs = array_fill( 0, G10CONV_MAX_RUNS, G10CONV_BUDGET );
	$total_requests   = 0;
	$overlap_requests = 0;
	$overlap_pages    = 0;
	$run_no           = 0;
	$converged        = false;
	$prev_checkpoint  = 0;

	foreach ( $runs as $max_pages ) {
		++$run_no;

		$r = g10conv_run( $source, $config, $seen, $max_pages );

		$total_requests += $r['requests'];

		/*
		 * Overlap accounting. Page 1 is always re-fetched for its pagination
		 * metadata (the G8 truncation guard depends on it), so it is counted
		 * separately from the overlap itself.
		 */
		foreach ( $r['pages'] as $p ) {
			if ( $p > 1 && $p <= $prev_checkpoint ) {
				++$overlap_requests;
				++$overlap_pages;
			}
		}

		$prev_checkpoint = max( $prev_checkpoint, $r['checkpoint'] );

		if ( $r['complete'] ) {
			$converged = true;
		}

		echo sprintf(
			"%-6d %-9d %-12s %-11d %-9d %-9d %s\n",
			$run_no,
			$r['requests'],
			implode( ',', $r['pages'] ),
			$r['checkpoint'],
			$r['new_unique'],
			count( $seen ),
			$r['complete'] ? 'yes' : 'no'
		);
	}

	if ( ! $checkpointing ) {
		remove_all_filters( 'conexao_eventbrite_checkpoint_enabled' );
	}

	echo sprintf( "TOTAL requests: %d | unique events recovered: %d / %d\n", $total_requests, count( $seen ), G10_TOTAL_PAGES * G10_PAGE_SIZE );

	return array(
		'requests'         => $total_requests,
		'unique'           => count( $seen ),
		'converged'        => $converged,
		'overlap_requests' => $overlap_requests,
		'overlap_pages'    => $overlap_pages,
	);
}

/**
 * The Cork source config used by the experiment.
 *
 * @return array
 */
function g10_cork_config_convergence() {
	$config = Conexao_County_Registry::get_eventbrite_source( 'cork' );
	$config['id'] = 'g10conv_cork';

	return $config;
}

remove_all_filters( 'pre_http_request' );
g10conv_install();

// Clean slate for each sequence.
Conexao_Source_Enumeration_Checkpoint::reset( 'g10conv_cork' );
$before = g10conv_sequence( false );

Conexao_Source_Enumeration_Checkpoint::reset( 'g10conv_cork' );
$after = g10conv_sequence( true );

echo "\n=== G10 convergence summary ===\n";
echo sprintf( "Runs performed      : %d (each capped at %d outbound requests)\n", G10CONV_MAX_RUNS, G10CONV_BUDGET );
echo sprintf( "Listing size         : %d pages / %d objects\n", G10_TOTAL_PAGES, G10_TOTAL_PAGES * G10_PAGE_SIZE );
echo sprintf(
	"BEFORE (restart)     : %d requests -> %d unique events (%d%% coverage), converged: %s\n",
	$before['requests'],
	$before['unique'],
	(int) round( ( $before['unique'] / ( G10_TOTAL_PAGES * G10_PAGE_SIZE ) ) * 100 ),
	$before['converged'] ? 'yes' : 'NO'
);
echo sprintf(
	"AFTER  (checkpointed): %d requests -> %d unique events (%d%% coverage), converged: %s\n",
	$after['requests'],
	$after['unique'],
	(int) round( ( $after['unique'] / ( G10_TOTAL_PAGES * G10_PAGE_SIZE ) ) * 100 ),
	$after['converged'] ? 'yes' : 'NO'
);
echo sprintf(
	"Coverage gain at the SAME request cost: +%d unique events (%.1fx)\n",
	$after['unique'] - $before['unique'],
	$before['unique'] > 0 ? $after['unique'] / $before['unique'] : 0.0
);
echo sprintf(
	"Overlap cost         : %d repeat requests across %d runs (%.1f%% of all requests)\n",
	$after['overlap_requests'],
	G10CONV_MAX_RUNS,
	$after['requests'] > 0 ? ( $after['overlap_requests'] / $after['requests'] ) * 100 : 0.0
);
echo sprintf(
	"Page-1 metadata cost : 1 request per run (the G8 truncation guard depends on it)\n"
);
echo "LISTING_TOTAL: " . ( G10_TOTAL_PAGES * G10_PAGE_SIZE ) . "\n";
