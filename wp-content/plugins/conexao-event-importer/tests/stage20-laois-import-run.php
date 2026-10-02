<?php
/**
 * Stage 20 evidence runner — Laois ICS occurrence-date import verification.
 *
 * Drives the REAL importer engine (Conexao_Event_Importer_Engine) against the
 * COMMITTED ICS fixture, so every run is fully deterministic and never
 * contacts the network or any production host.
 *
 * Modes:
 *   baseline / snapshot — event identities + scheduling + recurrence (JSON)
 *   dry-run             — dry-run the Laois source, report the plan
 *   apply               — import twice, reporting per-run action counts
 *   verify              — evaluate stored occurrence dates with the real runtime
 *
 * Usage:
 *   docker compose exec wordpress php /var/www/html/wp-content/plugins/
 *     conexao-event-importer/tests/stage20-laois-import-run.php apply
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$mode      = isset( $argv[1] ) ? (string) $argv[1] : 'snapshot';
$fixture   = __DIR__ . '/fixtures/ics/laois-tourism.ics';
$source_id = 'laois_tourism';
$out_dir   = '/var/www/html/wp-content/uploads/stage20-evidence';

if ( ! is_dir( $out_dir ) ) {
	wp_mkdir_p( $out_dir );
}

/** Snapshot every event's identity, scheduling and recurrence state. */
function stage20_snapshot() {
	$rows = array();
	$q    = new WP_Query(
		array(
			'post_type'      => 'event',
			'posts_per_page' => -1,
			'post_status'    => 'any',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);
	foreach ( $q->posts as $post ) {
		$rows[] = array(
			'id'         => $post->ID,
			'title'      => $post->post_title,
			'source'     => (string) get_post_meta( $post->ID, '_event_source', true ),
			'source_id'  => (string) get_post_meta( $post->ID, '_event_source_id', true ),
			'date'       => (string) get_post_meta( $post->ID, '_event_date', true ),
			'end_date'   => (string) get_post_meta( $post->ID, '_event_end_date', true ),
			'start_time' => (string) get_post_meta( $post->ID, '_event_start_time', true ),
			'recurrence' => (string) get_post_meta( $post->ID, '_event_recurrence', true ),
			'rec_days'   => (string) get_post_meta( $post->ID, '_event_recurrence_days', true ),
			'rec_start'  => (string) get_post_meta( $post->ID, '_event_recurrence_start', true ),
			'rec_end'    => (string) get_post_meta( $post->ID, '_event_recurrence_end', true ),
		);
	}
	return $rows;
}

/** Stable digest of a snapshot. */
function stage20_digest( array $rows ) {
	return hash( 'sha256', wp_json_encode( $rows ) );
}

/** Point the source at the committed fixture instead of a live URL. */
function stage20_configure_source( $source_id, $fixture ) {
	$sources = new Conexao_Event_Sources();
	$source  = $sources->get( $source_id );

	if ( empty( $source ) ) {
		echo "FATAL: source '$source_id' is not configured.\n";
		exit( 1 );
	}

	$source['ics_content'] = file_get_contents( $fixture );
	$source['county']      = 'Laois';

	return array( $sources, $source );
}

/** Run the real engine for one pass. */
function stage20_run( $sources, $source, $dry_run ) {
	$engine = new Conexao_Event_Importer_Engine( $sources, new Conexao_Event_Location() );
	return $engine->run_source( $source['id'], $dry_run );
}

/** Normalise a result object to an array. */
function stage20_stats( $result ) {
	return is_object( $result ) && method_exists( $result, 'to_array' )
		? $result->to_array()
		: (array) $result;
}

echo "stage20 mode: $mode\n";
switch ( $mode ) {
	case 'baseline':
	case 'snapshot':
		$rows = stage20_snapshot();
		file_put_contents( "$out_dir/$mode.json", wp_json_encode( $rows, JSON_PRETTY_PRINT ) );
		printf( "events=%d digest=%s\n", count( $rows ), stage20_digest( $rows ) );
		$series = array_values(
			array_filter(
				$rows,
				static function ( $r ) {
					return 'weekly' === $r['recurrence'];
				}
			)
		);
		printf( "weekly-series=%d\n", count( $series ) );
		foreach ( $series as $r ) {
			printf( "  #%d %s | %s | days=%s | %s..%s\n", $r['id'], $r['title'], $r['date'], $r['rec_days'], $r['rec_start'], $r['rec_end'] );
		}
		break;

	case 'dry-run':
		list( $sources, $source ) = stage20_configure_source( $source_id, $fixture );
		$stats                   = stage20_stats( stage20_run( $sources, $source, true ) );
		file_put_contents( "$out_dir/dry-run.json", wp_json_encode( $stats, JSON_PRETTY_PRINT ) );
		echo wp_json_encode( $stats, JSON_PRETTY_PRINT ) . "\n";
		break;

	case 'apply':
		list( $sources, $source ) = stage20_configure_source( $source_id, $fixture );
		$runs                    = array();

		for ( $i = 1; $i <= 2; $i++ ) {
			$stats  = stage20_stats( stage20_run( $sources, $source, false ) );
			$runs[] = array( 'run' => $i, 'stats' => $stats );
			printf( "run %d: %s\n", $i, wp_json_encode( $stats ) );
		}

		file_put_contents( "$out_dir/apply-runs.json", wp_json_encode( $runs, JSON_PRETTY_PRINT ) );
		$rows = stage20_snapshot();
		file_put_contents( "$out_dir/apply-snapshot.json", wp_json_encode( $rows, JSON_PRETTY_PRINT ) );
		printf( "events=%d digest=%s\n", count( $rows ), stage20_digest( $rows ) );
		break;
case 'verify':
		$rows  = stage20_snapshot();
		$chair = null;

		foreach ( $rows as $r ) {
			if ( 'Chair Yoga At Portlaoise Library' === $r['title'] ) {
				$chair = $r;
				break;
			}
		}
		if ( ! $chair ) {
			echo "FATAL: Chair Yoga not found in the database.\n";
			exit( 1 );
		}

		printf(
			"Chair Yoga record: #%d %s..%s days=%s rec=%s window=%s..%s\n",
			$chair['id'],
			$chair['date'],
			$chair['end_date'],
			$chair['rec_days'],
			$chair['recurrence'],
			$chair['rec_start'],
			$chair['rec_end']
		);

		$days        = array_values( array_filter( array_map( 'intval', explode( ',', $chair['rec_days'] ) ), 'strlen' ) );
		$occurrences = Conexao_ICS_Recurrence::occurrence_dates( $chair['rec_start'], $days, $chair['rec_end'] );
		printf( "stored occurrence dates (%d): %s\n", count( $occurrences ), implode( ', ', $occurrences ) );

		// Evaluate EVERY calendar day of the window with the real evaluator.
		$match   = array();
		$nomatch = array();
		$cursor  = strtotime( '2026-09-29 00:00:00 UTC' );
		$stop    = strtotime( '2026-10-20 00:00:00 UTC' );

		while ( $cursor <= $stop ) {
			$day = gmdate( 'Y-m-d', $cursor );
			if ( Conexao_Event_Recurrence::occurs_on_date( (int) $chair['id'], new DateTimeImmutable( $day, wp_timezone() ) ) ) {
				$match[] = $day;
			} else {
				$nomatch[] = $day;
			}
			$cursor += DAY_IN_SECONDS;
		}

		printf( "MATCH (%d): %s\n", count( $match ), implode( ', ', $match ) );
		printf( "NO MATCH (%d): %s\n", count( $nomatch ), implode( ', ', $nomatch ) );

		$expected = array( '2026-09-29', '2026-10-06', '2026-10-13', '2026-10-20' );
		$ok       = ( $match === $expected );
		printf( "expected: %s\nverdict: %s\n", implode( ', ', $expected ), $ok ? 'PASS' : 'FAIL' );

		file_put_contents(
			"$out_dir/verify.json",
			wp_json_encode(
				array(
					'post_id'      => $chair['id'],
					'record'       => $chair,
					'occurrences'  => $occurrences,
					'match'        => $match,
					'no_match'     => $nomatch,
					'expected'     => $expected,
					'verdict'      => $ok ? 'PASS' : 'FAIL',
					'total_events' => count( $rows ),
					'digest'       => stage20_digest( $rows ),
				),
				JSON_PRETTY_PRINT
			)
		);
		exit( $ok ? 0 : 1 );

	default:
		echo "unknown mode\n";
		exit( 1 );
}

echo "done\n";
