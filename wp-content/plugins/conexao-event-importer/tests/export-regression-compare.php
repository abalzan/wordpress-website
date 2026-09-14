<?php
/**
 * Local-only regression verification for the streaming event export.
 *
 * Modes:
 *   hash <export.json> <out-map.json>
 *     Decodes a complete v1.1.0 export file, validates the contract
 *     (schema version, manifest, uuid/identity uniqueness, localhost scan,
 *     embedded-image count) and writes a small map:
 *       { count, images, uuids_in_order[], sha1: {uuid: sha1(wp_json_encode(event))} }
 *
 *   compare <mapA.json> <mapB.json> <out-report.json>
 *     Compares two hash maps: same event count, same UUID sequence (order),
 *     identical per-event hashes. Reports any differences.
 *
 * Both modes are memory-hungry (full JSON decode); run with
 *   docker compose exec -T wordpress php -d memory_limit=2G \
 *     wp-content/plugins/conexao-event-importer/tests/export-regression-compare.php <mode> ...
 */

set_time_limit(0);

$wp_load = '/var/www/html/wp-load.php';
if ( ! file_exists( $wp_load ) ) {
	$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
}
require_once $wp_load;

if ( php_sapi_name() !== 'cli' ) {
	fwrite( STDERR, "CLI only\n" );
	exit( 1 );
}

$mode = isset( $argv[1] ) ? $argv[1] : '';
$path = isset( $argv[2] ) ? $argv[2] : '';

if ( 'hash' === $mode ) {
	$out = isset( $argv[3] ) ? $argv[3] : '';
	if ( '' === $path || '' === $out ) {
		fwrite( STDERR, "usage: hash <export.json> <out-map.json>\n" );
		exit( 1 );
	}

	$raw = file_get_contents( $path );
	if ( false === $raw ) {
		fwrite( STDERR, "cannot read $path\n" );
		exit( 1 );
	}

	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) || ! isset( $data['manifest'], $data['events'] ) || ! is_array( $data['events'] ) ) {
		fwrite( STDERR, "not a valid v1.1.0 export document: $path\n" );
		exit( 1 );
	}

	$manifest      = $data['manifest'];
	$events        = $data['events'];
	$uuids         = array();
	$sha1          = array();
	$images        = 0;
	$dup_uuid      = 0;
	$identity_seen = array();
	$identity_dup  = 0;
	$localhost     = 0;
	$missing_uuid  = 0;

	foreach ( $events as $event ) {
		$uuid = isset( $event['uuid'] ) ? (string) $event['uuid'] : '';
		if ( '' === $uuid ) {
			$missing_uuid++;
		} elseif ( isset( $sha1[ $uuid ] ) ) {
			$dup_uuid++;
		}
		$sha1[ $uuid ] = sha1( wp_json_encode( $event ) );

		$meta = isset( $event['meta'] ) && is_array( $event['meta'] ) ? $event['meta'] : array();
		$src  = isset( $meta['_event_source'] ) ? (string) $meta['_event_source'] : '';
		$sid  = isset( $meta['_event_source_id'] ) ? (string) $meta['_event_source_id'] : '';
		$ident = $src . '|' . $sid;
		if ( isset( $identity_seen[ $ident ] ) ) {
			$identity_dup++;
		} else {
			$identity_seen[ $ident ] = true;
		}

		foreach ( array( '_event_url', '_event_source_url', '_event_map_url', '_event_banner' ) as $uk ) {
			if ( ! empty( $meta[ $uk ] ) && preg_match( '#https?://(localhost|127\.0\.0\.1)#i', (string) $meta[ $uk ] ) ) {
				$localhost++;
			}
		}

		$fi = isset( $event['featured_image'] ) && is_array( $event['featured_image'] ) ? $event['featured_image'] : array();
		if ( ! empty( $fi['data_base64'] ) ) {
			$images++;
		}
		if ( ! empty( $fi['source_url'] ) && preg_match( '#https?://(localhost|127\.0\.0\.1)#i', (string) $fi['source_url'] ) ) {
			$localhost++;
		}

		$uuids[] = $uuid;
	}

	$map = array(
		'file'                 => basename( $path ),
		'manifest'             => $manifest,
		'count'                => count( $events ),
		'images'               => $images,
		'missing_uuid'         => $missing_uuid,
		'duplicate_uuid'       => $dup_uuid,
		'duplicate_identity'   => $identity_dup,
		'localhost_hits'       => $localhost,
		'uuids_in_order'       => $uuids,
		'sha1'                 => $sha1,
	);

	file_put_contents( $out, wp_json_encode( $map ) );
	echo wp_json_encode(
		array(
			'file'               => basename( $path ),
			'version'            => isset( $manifest['version'] ) ? $manifest['version'] : null,
			'count'              => count( $events ),
			'images'             => $images,
			'missing_uuid'       => $missing_uuid,
			'duplicate_uuid'     => $dup_uuid,
			'duplicate_identity' => $identity_dup,
			'localhost_hits'     => $localhost,
			'peak_mem_mb'        => round( memory_get_peak_usage( true ) / 1048576 ),
		)
	) . "\n";
	exit( 0 );
}

if ( 'compare' === $mode ) {
	$pathB = isset( $argv[3] ) ? $argv[3] : '';
	$out   = isset( $argv[4] ) ? $argv[4] : '';
	if ( '' === $path || '' === $pathB || '' === $out ) {
		fwrite( STDERR, "usage: compare <mapA.json> <mapB.json> <out-report.json>\n" );
		exit( 1 );
	}

	$A = json_decode( (string) file_get_contents( $path ), true );
	$B = json_decode( (string) file_get_contents( $pathB ), true );
	if ( ! is_array( $A ) || ! is_array( $B ) ) {
		fwrite( STDERR, "cannot read maps\n" );
		exit( 1 );
	}

	$report = array(
		'a'                    => $A['file'],
		'b'                    => $B['file'],
		'count_equal'          => $A['count'] === $B['count'],
		'order_equal'          => $A['uuids_in_order'] === $B['uuids_in_order'],
		'hashes_equal'         => $A['sha1'] === $B['sha1'],
		'images_equal'         => $A['images'] === $B['images'],
	);

	$diff_uuids = array();
	if ( ! $report['hashes_equal'] ) {
		$only_a = array_diff_key( $A['sha1'], $B['sha1'] );
		$only_b = array_diff_key( $B['sha1'], $A['sha1'] );
		foreach ( $A['sha1'] as $uuid => $h ) {
			if ( isset( $B['sha1'][ $uuid ] ) && $B['sha1'][ $uuid ] !== $h ) {
				$diff_uuids[] = $uuid;
			}
		}
		$report['only_in_a']       = array_keys( $only_a );
		$report['only_in_b']       = array_keys( $only_b );
		$report['changed_uuids']   = $diff_uuids;
	}

	$report['result'] = ( $report['count_equal'] && $report['order_equal'] && $report['hashes_equal'] && $report['images_equal'] )
		? 'IDENTICAL' : 'DIFFERENT';

	file_put_contents( $out, wp_json_encode( $report, JSON_PRETTY_PRINT ) . "\n" );
	echo wp_json_encode( $report ) . "\n";
	exit( 'IDENTICAL' === $report['result'] ? 0 : 2 );
}

fwrite( STDERR, "usage: <hash|compare> ...\n" );
exit( 1 );
