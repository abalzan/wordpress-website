<?php
/**
 * G11 — analyse the captured Eventbrite observations.
 *
 * ## What it does
 *
 * Reads every observation JSON written by
 * `tests/experiments/g11-eventbrite-volatility.php`, pairs each source's
 * observations by time, and produces a deterministic summary:
 *
 *   - per source, per observation pair: new / changed / unchanged / removed,
 *     page movement, boundary crossings, duplicates, requests, completeness;
 *   - a volatility classification per source;
 *   - an overlap verdict for the CURRENT `OVERLAP_PAGES` constant (read from
 *     the production class, never written to it).
 *
 * It is read-only and pure: it opens JSON files and prints. It changes no
 * constant, no option and no source state.
 *
 * ## Usage
 *
 *     docker compose exec wordpress php /var/www/html/tests/experiments/g11-analyse.php \
 *         [observations-dir] [--json]
 *
 * @package Conexao_Event_Importer
 */

require_once dirname( __DIR__, 2 ) . '/tests/bootstrap.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';
require_once CONEXAO_TESTS_ROOT . '/lib/eb-volatility.php';

/**
 * Load every observation file from a directory.
 *
 * @param string $dir Directory path.
 * @return array Observations keyed by source, then sorted by timestamp.
 */
function g11_load_observations( $dir ) {
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

		$source            = (string) $raw['source'];
		$raw['source_id']  = $source;
		$by_source[ $source ][] = $raw;
	}

	foreach ( $by_source as $source => $observations ) {
		usort(
			$observations,
			static function ( $a, $b ) {
				return strcmp( (string) $a['timestamp'], (string) $b['timestamp'] );
			}
		);
		$by_source[ $source ] = $observations;
	}

	ksort( $by_source );

	return $by_source;
}

/**
 * Summarise one source's observation series.
 *
 * @param string $source       Source id.
 * @param array  $observations Observations in timestamp order.
 * @return array
 */
function g11_summarise_source( $source, array $observations ) {
	$comparisons = array();
	$pairs       = array();

	// Each consecutive pair is compared. The boundary used is the number of
	// pages the PREVIOUS observation actually walked — i.e. where a checkpoint
	// left off — which is exactly G10's resume position.
	for ( $i = 1; $i < count( $observations ); $i++ ) {
		$previous = eb_vol_normalise_observation( $observations[ $i - 1 ] );
		$current  = eb_vol_normalise_observation( $observations[ $i ] );

		$boundary = (int) $previous['pages_walked'];

		$summary = eb_vol_source_summary( $previous, $current, $boundary );

		// Carry the operational facts the comparison does not model but the
		// report must cite (request cost and wall clock).
		$summary['elapsed_seconds'] = isset( $observations[ $i ]['elapsed_seconds'] ) ? $observations[ $i ]['elapsed_seconds'] : null;
		$summary['http_status']     = isset( $observations[ $i ]['http_status'] ) ? $observations[ $i ]['http_status'] : 0;
		$summary['truncated_upstream'] = ! empty( $observations[ $i ]['truncated_upstream'] );

		$comparisons[] = $summary['comparison'];
		$pairs[]       = $summary;
	}

	$overlap = eb_vol_classify_overlap( $comparisons, (int) Conexao_Source_Eventbrite::OVERLAP_PAGES );

	return array(
		'source'              => $source,
		'observations'        => count( $observations ),
		'complete_observations' => count(
			array_filter(
				$observations,
				static function ( $o ) {
					return ! empty( $o['complete'] );
				}
			)
		),
		'pairs'               => $pairs,
		'volatility'          => eb_vol_classify_volatility( $comparisons ),
		'overlap_verdict'     => $overlap,
	);
}

/**
 * Render the human-readable analysis report.
 *
 * @param array  $summaries    Per-source summaries.
 * @param bool   $as_json      Emit JSON instead of a table.
 * @return string
 */
function g11_render( array $summaries, $as_json = false ) {
	if ( $as_json ) {
		return (string) wp_json_encode( $summaries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	$out = array();

	$out[] = sprintf(
		'OVERLAP_PAGES in production = %d (read-only; G11 changes nothing)',
		(int) Conexao_Source_Eventbrite::OVERLAP_PAGES
	);
	$out[] = '';

	foreach ( $summaries as $summary ) {
		$out[] = sprintf( '=== %s ===', $summary['source'] );
		$out[] = sprintf(
			'observations: %d (%d complete)   volatility: %s',
			$summary['observations'],
			$summary['complete_observations'],
			strtoupper( $summary['volatility'] )
		);
		$out[] = sprintf(
			'overlap verdict: %s (max movement %d page(s), %d complete comparison(s))',
			strtoupper( $summary['overlap_verdict']['verdict'] ),
			$summary['overlap_verdict']['max_movement'],
			$summary['overlap_verdict']['complete_comparisons']
		);
		$out[] = '';

		$out[] = sprintf(
			'%-22s %7s %5s %5s %5s %5s %5s %5s %6s %6s %5s',
			'PAIR',
			'present',
			'new',
			'chg',
			'unch',
			'rem',
			'moved',
			'bound',
			'requests',
			'sec',
			'cmpl'
		);

		foreach ( $summary['pairs'] as $pair ) {
			$out[] = sprintf(
				'%-22s %7d %5d %5d %5d %5d %5d %5d %6d %6s %5s',
				substr( (string) $pair['current_observation'], 0, 22 ),
				$pair['present'],
				$pair['new'],
				$pair['changed'],
				$pair['unchanged'],
				$pair['removed'],
				$pair['page_movement_count'],
				$pair['boundary_crossed'],
				$pair['requests'],
				isset( $pair['elapsed_seconds'] ) ? (string) $pair['elapsed_seconds'] : '-',
				$pair['complete'] ? 'yes' : ( '' === $pair['failure_class'] ? 'no' : $pair['failure_class'] )
			);
		}

		$out[] = '';
	}

	return implode( "\n", $out ) . "\n";
}

// ---------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------

$g11_args = array();
foreach ( array_slice( $argv, 1 ) as $g11_token ) {
	if ( 0 === strpos( $g11_token, '--' ) ) {
		$g11_args[ substr( $g11_token, 2 ) ] = true;
		continue;
	}
	$g11_args['dir'] = $g11_token;
}

$g11_dir = isset( $g11_args['dir'] )
	? (string) $g11_args['dir']
	: '/var/www/html/tests/experiments/observations';

$g11_summaries = array();

foreach ( g11_load_observations( $g11_dir ) as $g11_source => $g11_observations ) {
	$g11_summaries[] = g11_summarise_source( $g11_source, $g11_observations );
}

echo g11_render( $g11_summaries, isset( $g11_args['json'] ) );

/*
 * SECOND OUTPUT — interior boundary sweep.
 *
 * The primary analysis puts the boundary at the edge of the walk (the last
 * page actually fetched), which is G10's real resume position. That position
 * is also the LEAST informative place to look for movement, because nothing
 * beyond it was observed.
 *
 * This sweep therefore re-runs the same boundary logic at EVERY interior page
 * boundary that both observations covered. It answers the question the overlap
 * constant actually cares about: across ANY page boundary, how far did events
 * travel between two observations?
 */
$g11_sweep = array();

foreach ( g11_load_observations( $g11_dir ) as $g11_source => $g11_observations ) {
	for ( $i = 1; $i < count( $g11_observations ); $i++ ) {
		$previous = eb_vol_normalise_observation( $g11_observations[ $i - 1 ] );
		$current  = eb_vol_normalise_observation( $g11_observations[ $i ] );

		$movement = eb_vol_compare( $previous, $current, 0 )['page_movement'];

		$shared = min( (int) $previous['pages_walked'], (int) $current['pages_walked'] );

		$rows = array();
		for ( $boundary = 1; $boundary < $shared; $boundary++ ) {
			$crossing   = eb_vol_boundary_crossing( $movement, $boundary );
			$rows[]     = array(
				'boundary'  => $boundary,
				'upward'    => count( $crossing['upward'] ),
				'downward'  => count( $crossing['downward'] ),
				'total'     => $crossing['total'],
			);
		}

		$g11_sweep[] = array(
			'source'           => $g11_source,
			'pair'             => (string) $g11_observations[ $i ]['observation_id'],
			'moved_events'     => count( $movement ),
			'max_displacement' => $movement ? eb_vol_recommended_overlap( eb_vol_compare( $previous, $current, 0 ) ) : 0,
			'boundaries'       => $rows,
		);
	}
}

echo "\n=== INTERIOR BOUNDARY SWEEP (movement across every observed page boundary) ===\n";

foreach ( $g11_sweep as $row ) {
	printf(
		"%-38s %-40s moved=%-3d max_displacement=%d page(s)\n",
		$row['source'],
		$row['pair'],
		$row['moved_events'],
		$row['max_displacement']
	);

	if ( $row['moved_events'] > 0 ) {
		foreach ( $row['boundaries'] as $b ) {
			if ( $b['total'] > 0 ) {
				printf( "    boundary p%-3d crossed=%-3d (up %d / down %d)\n", $b['boundary'], $b['total'], $b['upward'], $b['downward'] );
			}
		}
	}
}
