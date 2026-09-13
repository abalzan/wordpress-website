<?php
/**
 * Stage D — Events Expansion county rollout harness.
 *
 * Deterministic, local-only driver for the multi-county rollout. It reuses the
 * validated importer engine exactly as shipped; it does NOT re-implement any
 * parsing, normalization, deduplication or lifecycle logic.
 *
 * Modes:
 *   registry                      Print the authoritative registry-derived plan.
 *   preflight <id> [<id>...]      Report stored source config + registry diff.
 *   dry <id> [<id>...]            Dry-run each source (no Event writes).
 *   import <id> [<id>...]         Real import each source (writes Events).
 *   set-active <id> [<id>...]     Persist status=active for the ids.
 *   set-inactive <id> [<id>...]   Persist status=inactive for the ids.
 *   states [<id>...]              Dump status + event counts (optionally scoped).
 *
 * Options:
 *   --out=<path>                  Write the JSON report to this path (also stdout).
 *   --restore-dry                 (dry mode) restore prior status after dry-run.
 *
 * Usage:
 *   docker compose exec wordpress php \
 *     /var/www/html/wp-content/plugins/conexao-event-importer/tests/stage-d-rollout.php \
 *     dry eventbrite_carlow heritage_week_carlow
 *
 * @package Conexao_Event_Importer
 */

set_time_limit( 0 );

$wp_load = '/var/www/html/wp-load.php';
if ( ! file_exists( $wp_load ) ) {
	$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
}
require_once $wp_load;
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

if ( php_sapi_name() !== 'cli' ) {
	fwrite( STDERR, "This script must be run from the command line.\n" );
	exit( 1 );
}

// Parse args.
$argv_all = $argv;
array_shift( $argv_all );
$options    = array();
$positional = array();
foreach ( $argv_all as $arg ) {
	if ( 0 === strpos( $arg, '--' ) ) {
		$parts = explode( '=', substr( $arg, 2 ), 2 );
		$options[ $parts[0] ] = isset( $parts[1] ) ? $parts[1] : true;
	} else {
		$positional[] = $arg;
	}
}

$mode = isset( $positional[0] ) ? $positional[0] : 'registry';
$ids  = array_slice( $positional, 1 );

$plugin  = Conexao_Event_Importer::instance();
$sources = new Conexao_Event_Sources();

/**
 * Registry plan: all ROI counties, the pilot set, and the remaining set.
 *
 * @return array
 */
function conexao_stage_d_plan() {
	$all       = Conexao_County_Registry::get_slugs();
	$pilot     = array( 'cork', 'dublin', 'laois' );
	$remaining = array_values( array_diff( $all, $pilot ) );

	$batches = array(
		array( 'carlow', 'cavan', 'clare', 'donegal', 'galway' ),
		array( 'kerry', 'kildare', 'kilkenny', 'leitrim', 'limerick' ),
		array( 'longford', 'louth', 'mayo', 'meath', 'monaghan' ),
		array( 'offaly', 'roscommon', 'sligo', 'tipperary', 'waterford' ),
		array( 'westmeath', 'wexford', 'wicklow' ),
	);

	$batch_sources = array();
	foreach ( $batches as $i => $batch ) {
		$eb = array();
		$hw = array();
		foreach ( $batch as $slug ) {
			$eb[] = 'eventbrite_' . $slug;
			$hw[] = 'heritage_week_' . $slug;
		}
		$batch_sources[] = array(
			'batch'         => $i + 1,
			'counties'      => $batch,
			'eventbrite'    => $eb,
			'heritage_week' => $hw,
			'sources'       => array_merge( $eb, $hw ),
		);
	}

	return array(
		'all_counties'       => $all,
		'all_county_count'   => count( $all ),
		'pilot_counties'     => $pilot,
		'remaining_counties' => $remaining,
		'remaining_count'    => count( $remaining ),
		'batches'            => $batch_sources,
		'remaining_sources'  => array_merge(
			array_map( function ( $s ) {
				return 'eventbrite_' . $s;
			}, $remaining ),
			array_map( function ( $s ) {
				return 'heritage_week_' . $s;
			}, $remaining )
		),
	);
}

/**
 * Snapshot source state (status + event counts by _event_source).
 *
 * @param array $only Optional list of source ids to limit to.
 * @return array
 */
function conexao_stage_d_states( $only = array() ) {
	$sources = new Conexao_Event_Sources();
	$all     = $sources->get_all();

	global $wpdb;
	$counts = array();
	$rows   = $wpdb->get_results(
		"SELECT pm.meta_value AS src, p.post_status AS st, COUNT(*) AS c
		 FROM {$wpdb->postmeta} pm
		 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		 WHERE pm.meta_key = '_event_source' AND p.post_type = 'event'
		 GROUP BY pm.meta_value, p.post_status",
		ARRAY_A
	);
	foreach ( (array) $rows as $row ) {
		$src = $row['src'];
		if ( ! isset( $counts[ $src ] ) ) {
			$counts[ $src ] = array( 'total' => 0, 'by_status' => array() );
		}
		$counts[ $src ]['total']                   += (int) $row['c'];
		$counts[ $src ]['by_status'][ $row['st'] ]  = (int) $row['c'];
	}

	$out = array();
	foreach ( $all as $id => $source ) {
		if ( ! empty( $only ) && ! in_array( $id, $only, true ) ) {
			continue;
		}
		$out[ $id ] = array(
			'status' => isset( $source['status'] ) ? $source['status'] : '',
			'type'   => isset( $source['type'] ) ? $source['type'] : '',
			'county' => isset( $source['county'] ) ? $source['county'] : '',
			'events' => isset( $counts[ $id ] ) ? $counts[ $id ] : array( 'total' => 0, 'by_status' => array() ),
		);
	}
	return $out;
}

/**
 * Registry diff for one source id (stored vs authoritative config).
 *
 * @param string $id Source id.
 * @return array
 */
function conexao_stage_d_preflight_one( $id ) {
	$sources = new Conexao_Event_Sources();
	$stored  = $sources->get( $id );

	if ( 0 === strpos( $id, 'eventbrite_' ) ) {
		$registry = Conexao_County_Registry::get_eventbrite_source( substr( $id, strlen( 'eventbrite_' ) ) );
	} elseif ( 0 === strpos( $id, 'heritage_week_' ) ) {
		$registry = Conexao_County_Registry::get_heritage_week_source( substr( $id, strlen( 'heritage_week_' ) ) );
	} else {
		$registry = null;
	}

	$diffs = array();
	if ( $stored && $registry ) {
		foreach ( array( 'type', 'county', 'url' ) as $key ) {
			$sv = isset( $stored[ $key ] ) ? $stored[ $key ] : null;
			$rv = isset( $registry[ $key ] ) ? $registry[ $key ] : null;
			if ( $sv !== $rv ) {
				$diffs[ $key ] = array( 'stored' => $sv, 'registry' => $rv );
			}
		}
		$rtype = isset( $registry['type'] ) ? $registry['type'] : '';
		if ( 'eventbrite' === $rtype ) {
			$sv = isset( $stored['region_labels'] ) ? $stored['region_labels'] : null;
			$rv = isset( $registry['region_labels'] ) ? $registry['region_labels'] : null;
			if ( $sv !== $rv ) {
				$diffs['region_labels'] = array( 'stored' => $sv, 'registry' => $rv );
			}
		}
		if ( 'heritage_week' === $rtype ) {
			$sv = isset( $stored['hw_where'] ) ? $stored['hw_where'] : null;
			$rv = isset( $registry['hw_where'] ) ? $registry['hw_where'] : null;
			if ( $sv !== $rv ) {
				$diffs['hw_where'] = array( 'stored' => $sv, 'registry' => $rv );
			}
		}
	}

	return array(
		'id'             => $id,
		'registered'     => (bool) $stored,
		'status'         => $stored && isset( $stored['status'] ) ? $stored['status'] : null,
		'stored'         => $stored ? array(
			'type'          => isset( $stored['type'] ) ? $stored['type'] : null,
			'county'        => isset( $stored['county'] ) ? $stored['county'] : null,
			'url'           => isset( $stored['url'] ) ? $stored['url'] : null,
			'region_labels' => isset( $stored['region_labels'] ) ? $stored['region_labels'] : null,
			'hw_where'      => isset( $stored['hw_where'] ) ? $stored['hw_where'] : null,
		) : null,
		'registry_type'  => $registry && isset( $registry['type'] ) ? $registry['type'] : null,
		'registry_match' => empty( $diffs ),
		'diffs'          => $diffs,
	);
}

/**
 * Set a source status persistently.
 *
 * @param string $id     Source id.
 * @param string $status active|inactive.
 * @return bool
 */
function conexao_stage_d_set_status( $id, $status ) {
	$sources = new Conexao_Event_Sources();
	$source  = $sources->get( $id );
	if ( ! $source ) {
		return false;
	}
	$source['status'] = $status;
	return (bool) $sources->save( $source );
}

$report = array(
	'mode'      => $mode,
	'timestamp' => current_time( 'c' ),
	'ids'       => $ids,
	'sources'   => array(),
);

switch ( $mode ) {
	case 'registry':
		$report['plan'] = conexao_stage_d_plan();
		break;

	case 'preflight':
		foreach ( $ids as $id ) {
			$report['sources'][ $id ] = conexao_stage_d_preflight_one( $id );
		}
		break;

	case 'states':
		$report['states'] = conexao_stage_d_states( $ids );
		break;

	case 'set-active':
	case 'set-inactive':
		$status = 'set-active' === $mode ? 'active' : 'inactive';
		foreach ( $ids as $id ) {
			$report['sources'][ $id ] = array(
				'set'    => conexao_stage_d_set_status( $id, $status ),
				'status' => $status,
			);
		}
		break;

	case 'dry':
		foreach ( $ids as $id ) {
			$prior  = conexao_stage_d_preflight_one( $id );
			$before = isset( $prior['status'] ) ? $prior['status'] : null;
			conexao_stage_d_set_status( $id, 'active' );

			$started = microtime( true );
			try {
				$result = $plugin->importer->run_source( $id, true );
			} catch ( Throwable $e ) {
				$result = array( 'status' => 'FATAL', 'error' => $e->getMessage() );
			}
			$elapsed = round( microtime( true ) - $started, 1 );

			if ( isset( $options['restore-dry'] ) ) {
				conexao_stage_d_set_status( $id, $before ? $before : 'inactive' );
			}

			$report['sources'][ $id ] = array(
				'prior_status'  => $before,
				'elapsed'       => $elapsed,
				'found'         => isset( $result['found'] ) ? (int) $result['found'] : null,
				'would_create'  => isset( $result['created'] ) ? (int) $result['created'] : null,
				'would_update'  => isset( $result['updated'] ) ? (int) $result['updated'] : null,
				'unchanged'     => isset( $result['unchanged'] ) ? (int) $result['unchanged'] : null,
				'duplicates'    => isset( $result['duplicates'] ) ? (int) $result['duplicates'] : null,
				'skipped'       => isset( $result['skipped'] ) ? (int) $result['skipped'] : null,
				'skipped_past'  => isset( $result['skipped_past'] ) ? (int) $result['skipped_past'] : null,
				'skipped_invalid_date' => isset( $result['skipped_invalid_date'] ) ? (int) $result['skipped_invalid_date'] : null,
				'failed'        => isset( $result['failed'] ) ? (int) $result['failed'] : null,
				'status'        => isset( $result['status'] ) ? $result['status'] : 'unknown',
				'address_audit' => isset( $result['address_audit'] ) ? $result['address_audit'] : null,
				'fatal_errors'  => isset( $result['fatal_errors'] ) ? $result['fatal_errors'] : array(),
			);
		}
		break;

	case 'import':
		foreach ( $ids as $id ) {
			conexao_stage_d_set_status( $id, 'active' );

			$started = microtime( true );
			try {
				$result = $plugin->importer->run_source( $id, false );
			} catch ( Throwable $e ) {
				$result = array( 'status' => 'FATAL', 'error' => $e->getMessage() );
			}
			$elapsed = round( microtime( true ) - $started, 1 );

			$report['sources'][ $id ] = array(
				'elapsed'      => $elapsed,
				'found'        => isset( $result['found'] ) ? (int) $result['found'] : null,
				'created'      => isset( $result['created'] ) ? (int) $result['created'] : null,
				'updated'      => isset( $result['updated'] ) ? (int) $result['updated'] : null,
				'unchanged'    => isset( $result['unchanged'] ) ? (int) $result['unchanged'] : null,
				'duplicates'   => isset( $result['duplicates'] ) ? (int) $result['duplicates'] : null,
				'skipped'      => isset( $result['skipped'] ) ? (int) $result['skipped'] : null,
				'skipped_past' => isset( $result['skipped_past'] ) ? (int) $result['skipped_past'] : null,
				'failed'       => isset( $result['failed'] ) ? (int) $result['failed'] : null,
				'status'       => isset( $result['status'] ) ? $result['status'] : 'unknown',
				'fatal_errors' => isset( $result['fatal_errors'] ) ? $result['fatal_errors'] : array(),
			);
		}
		$report['states'] = conexao_stage_d_states( $ids );
		break;

	default:
		fwrite( STDERR, "Unknown mode: {$mode}\n" );
		exit( 2 );
}

$json = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
if ( ! empty( $options['out'] ) ) {
	file_put_contents( $options['out'], $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI tooling.
}
echo $json . "\n";
