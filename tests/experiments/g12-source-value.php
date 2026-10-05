<?php
/**
 * G12 — Eventbrite source-value analysis.
 *
 * ## Why this exists
 *
 * G13 §13 has to decide, per source, whether a listing earns a permanent place
 * in the supported ingestion path. Raw event volume cannot answer that: a
 * source that mostly re-serves events another source already reaches is not
 * worth an operator's request budget. The question is INCREMENTAL value.
 *
 * This script computes exactly the §13 measures from the observations the G11
 * harness already captured:
 *
 *     total unique events / new unique / repeat-unchanged /
 *     overlap with all-events / incremental unique /
 *     requests / events per request
 *
 * ## Reuse, not reinvention (G12 scope rule §0.3)
 *
 * There is deliberately no second observation architecture and no second
 * identity rule here. Identity, completeness and normalisation all come from
 * `tests/lib/eb-volatility.php` — the G11 measurement library — so this file is
 * a pure READ-ONLY reduction over already-captured JSON. It never fetches,
 * never writes, and never changes a constant.
 *
 * ## The denominator problem, stated honestly
 *
 * `eventbrite_cork` is measured to a BOUNDED page budget (protocol §3: 8
 * pages), while the window listings are walked to their declared end. So the
 * overlap computed against the G12 all-events observations is a LOWER BOUND on
 * the true overlap: the full all-events walk reaches far more pages.
 *
 * Because the true overlap can only grow with more all-events coverage, the
 * INCREMENTAL count computed here is an UPPER bound on the true incremental
 * value. Both bounds are reported, together with the wider G8 all-events walk
 * as the best available full-listing reference, so no reader mistakes the
 * conservative-in-one-direction number for the whole truth.
 *
 * ## Usage
 *
 *     docker compose exec wordpress php /var/www/html/tests/experiments/g12-source-value.php \
 *         [observations-dir] [--json] [--reference=<all-events-walk.json>]
 *
 * @package Conexao_Event_Importer
 */

require_once dirname( __DIR__, 2 ) . '/tests/bootstrap.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';
require_once CONEXAO_TESTS_ROOT . '/lib/eb-volatility.php';

/**
 * Load and normalise every observation in a directory.
 *
 * Reuses the G11 loader semantics (one entry per file, so a re-run overwrites
 * its own round rather than double-counting it), then hands each observation to
 * the shared normaliser so completeness is decided by exactly one
 * implementation.
 *
 * @param string $dir Directory path.
 * @return array<string,array> Observations keyed by source, in time order.
 */
function g12_load( $dir ) {
	$files = glob( rtrim( $dir, '/' ) . '/*.json' );

	if ( false === $files ) {
		return array();
	}

	$by_source = array();

	foreach ( $files as $file ) {
		$raw = json_decode( (string) file_get_contents( $file ), true );

		if ( ! is_array( $raw ) || empty( $raw['source'] ) ) {
			continue;
		}

		$by_source[ (string) $raw['source'] ][ basename( $file ) ] = eb_vol_normalise_observation( $raw );
	}

	ksort( $by_source );

	foreach ( $by_source as $source => $observations ) {
		uasort(
			$observations,
			static function ( $a, $b ) {
				return strcmp( (string) $a['timestamp'], (string) $b['timestamp'] );
			}
		);
		$by_source[ $source ] = array_values( $observations );
	}

	return $by_source;
}

/**
 * Compute the §13 source-value measures for one source.
 *
 * @param string $source        Source id.
 * @param array  $observations  Normalised observations.
 * @param array  $reference_ids All-events ID set to measure overlap against.
 * @return array
 */
function g12_value( $source, array $observations, array $reference_ids ) {
	$union    = array();
	$requests = 0;
	$declared = array();
	$failures = array();

	foreach ( $observations as $observation ) {
		$requests += (int) $observation['requests'];
		$declared[] = (int) $observation['declared_objects'];

		$failure = (string) $observation['failure_class'];

		if ( '' !== $failure ) {
			$failures[ $failure ] = isset( $failures[ $failure ] ) ? $failures[ $failure ] + 1 : 1;
		}

		foreach ( eb_vol_ids( $observation ) as $id ) {
			$union[ $id ] = true;
		}
	}

	$unique   = array_keys( $union );
	$requests = max( 1, $requests );

	return array(
		'source'                  => $source,
		'observations'            => count( $observations ),
		'total_unique'            => count( $unique ),
		'requests'                => $requests,
		'events_per_request'      => round( count( $unique ) / $requests, 2 ),
		'mean_declared_objects'   => count( $declared ) > 0 ? (int) round( array_sum( $declared ) / count( $declared ) ) : 0,
		'failures'                => $failures,
		'overlap_with_all_events' => count( array_intersect( $unique, $reference_ids ) ),
		'incremental'             => count( array_diff( $unique, $reference_ids ) ),
		'overlap_pct'             => count( $unique ) > 0 ? round( 100 * count( array_intersect( $unique, $reference_ids ) ) / count( $unique ), 1 ) : 0.0,
		'incremental_pct'         => count( $unique ) > 0 ? round( 100 * count( array_diff( $unique, $reference_ids ) ) / count( $unique ), 1 ) : 0.0,
	);
}

/**
 * How many of a source's unique IDs were seen in MORE THAN ONE observation.
 *
 * This is the §13 "repeat/unchanged" measure: it separates a listing that keeps
 * re-serving the same events from one that keeps surfacing new ones.
 *
 * @param array $observations Normalised observations.
 * @return array{repeat:int,seen_once:int,total:int}
 */
function g12_repeat( array $observations ) {
	$counts = array();

	foreach ( $observations as $observation ) {
		foreach ( eb_vol_ids( $observation ) as $id ) {
			$counts[ $id ] = isset( $counts[ $id ] ) ? $counts[ $id ] + 1 : 1;
		}
	}

	$repeat = 0;
	$once   = 0;

	foreach ( $counts as $count ) {
		if ( $count > 1 ) {
			++$repeat;
		} else {
			++$once;
		}
	}

	return array(
		'repeat'    => $repeat,
		'seen_once' => $once,
		'total'     => count( $counts ),
	);
}

// ---------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------

$g12_args = array( 'reference' => '' );

foreach ( array_slice( $argv, 1 ) as $g12_token ) {
	if ( 0 === strpos( $g12_token, '--' ) ) {
		$body = substr( $g12_token, 2 );
		$eq   = strpos( $body, '=' );

		if ( false === $eq ) {
			$g12_args[ $body ] = true;
		} else {
			$g12_args[ substr( $body, 0, $eq ) ] = substr( $body, $eq + 1 );
		}
		continue;
	}

	$g12_args['dir'] = $g12_token;
}

$g12_dir  = isset( $g12_args['dir'] ) ? (string) $g12_args['dir'] : '/var/www/html/tests/experiments/observations';
$g12_data = g12_load( $g12_dir );

/*
 * The all-events source is the reference set every window is measured against.
 * It is identified by PREFIX + the absence of a window marker, not by a
 * hard-coded county, so the analysis keeps working if the slug changes.
 */
$g12_reference_source = '';

foreach ( array_keys( $g12_data ) as $g12_candidate ) {
	if ( 0 === strpos( $g12_candidate, 'eventbrite_' ) && false === strpos( $g12_candidate, '_events_' ) ) {
		$g12_reference_source = $g12_candidate;
		break;
	}
}

$g12_reference_ids = array();

if ( '' !== $g12_reference_source ) {
	foreach ( $g12_data[ $g12_reference_source ] as $g12_observation ) {
		foreach ( eb_vol_ids( $g12_observation ) as $g12_id ) {
			$g12_reference_ids[ $g12_id ] = true;
		}
	}
}

$g12_reference_ids = array_keys( $g12_reference_ids );

/*
 * The wider G8 all-events walk, when supplied, gives a better (larger) overlap
 * denominator. It is reported alongside, never instead of, the bounded figure.
 */
$g12_reference_walk = 0;

if ( ! empty( $g12_args['reference'] ) && is_file( $g12_args['reference'] ) ) {
	$g12_walk = json_decode( (string) file_get_contents( $g12_args['reference'] ), true );

	if ( is_array( $g12_walk ) && isset( $g12_walk['events'] ) && is_array( $g12_walk['events'] ) ) {
		$g12_reference_walk = count( $g12_walk['events'] );
	}
}

$g12_rows = array();

foreach ( $g12_data as $g12_source => $g12_observations ) {
	$row          = g12_value( $g12_source, $g12_observations, $g12_reference_ids );
	$row['repeat'] = g12_repeat( $g12_observations );
	$g12_rows[]   = $row;
}

$g12_output = array(
	'observations_dir'           => $g12_dir,
	'reference_source'           => $g12_reference_source,
	'reference_unique_bounded'   => count( $g12_reference_ids ),
	'reference_unique_full_walk' => $g12_reference_walk,
	'sources'                    => $g12_rows,
);

if ( ! empty( $g12_args['json'] ) ) {
	echo wp_json_encode( $g12_output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	exit( 0 );
}

printf(
	"All-events reference (bounded G12 walk): %s - %d unique events\n",
	'' === $g12_reference_source ? '(none found)' : $g12_reference_source,
	count( $g12_reference_ids )
);

if ( $g12_reference_walk > 0 ) {
	printf( "All-events reference (full G8 walk):    %d unique events (better overlap denominator)\n", $g12_reference_walk );
}

print "\n";
printf(
	"%-40s %5s %8s %8s %8s %8s %9s %8s %7s\n",
	'SOURCE',
	'OBS',
	'UNIQUE',
	'REPEAT',
	'OVERLAP',
	'INCR',
	'EV/REQ',
	'REQ',
	'INCR%'
);

foreach ( $g12_rows as $row ) {
	printf(
		"%-40s %5d %8d %8d %8d %8d %9.2f %8d %6.1f%%\n",
		$row['source'],
		$row['observations'],
		$row['total_unique'],
		$row['repeat']['repeat'],
		$row['overlap_with_all_events'],
		$row['incremental'],
		$row['events_per_request'],
		$row['requests'],
		$row['incremental_pct']
	);
}

$g12_window_incremental = 0;
$g12_window_requests    = 0;

foreach ( $g12_rows as $row ) {
	if ( false !== strpos( $row['source'], '_events_' ) ) {
		$g12_window_incremental += (int) $row['incremental'];
		$g12_window_requests    += (int) $row['requests'];
	}
}

printf(
	"\nWindow union incremental (against the bounded walk, an UPPER bound): %d events for %d requests\n",
	$g12_window_incremental,
	$g12_window_requests
);

exit( 0 );