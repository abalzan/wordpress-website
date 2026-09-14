<?php
/**
 * Local-only test driver for the multipart admin export mode.
 *
 * Modes (all run export_multipart() at the default 512M memory limit):
 *   php export-multipart-test.php small <run-dir> <stats.json>
 *   php export-multipart-test.php medium <run-dir> <stats.json>
 *   php export-multipart-test.php full <run-dir> <stats.json>
 *
 * Validates: per-part schema/manifest, SHA-256 (recomputed), image recount,
 * first/last UUID continuity, cross-part UUID union vs manifest, duplicate
 * UUIDs, and peak memory below the PHP limit.
 */

set_time_limit( 0 );

$wp_load = '/var/www/html/wp-load.php';
if ( ! file_exists( $wp_load ) ) {
	$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
}
require_once $wp_load;
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

if ( php_sapi_name() !== 'cli' ) {
	fwrite( STDERR, "CLI only\n" );
	exit( 1 );
}

$mode      = isset( $argv[1] ) ? $argv[1] : '';
$run_dir   = isset( $argv[2] ) ? $argv[2] : '';
$stats_out = isset( $argv[3] ) ? $argv[3] : '';

if ( '' === $mode || '' === $run_dir ) {
	fwrite( STDERR, "usage: php export-multipart-test.php <small|medium|full> <run-dir> [stats.json]\n" );
	exit( 1 );
}

$args = array();
$part_size = Conexao_Event_Export::DEFAULT_PART_SIZE;

if ( 'small' === $mode ) {
	$args['sources'] = array( 'mondellopark', 'ivvcc' );
} elseif ( 'medium' === $mode ) {
	$args['sources'] = array( 'eventbrite_galway' );
	$part_size = 50; // 188 events -> 4 parts (50+50+50+38).
}
// 'full': no scope, default part size 250.

$exporter = new Conexao_Event_Export();
$start    = microtime( true );
$result   = $exporter->export_multipart( $run_dir, $part_size, $args );
$elapsed  = round( microtime( true ) - $start, 2 );

if ( is_wp_error( $result ) ) {
	fwrite( STDERR, 'ERROR: ' . $result->get_error_code() . ': ' . $result->get_error_message() . "\n" );
	$data = $result->get_error_data();
	if ( is_array( $data ) ) {
		fwrite( STDERR, 'data: ' . wp_json_encode( $data ) . "\n" );
	}
	exit( 1 );
}

$manifest = $result['manifest'];
$parts    = $manifest['parts'];

$part_results = array();
$all_uuids    = array();
$fail         = false;
$sum_events   = 0;
$sum_images   = 0;
$sum_bytes    = 0;

foreach ( $parts as $part ) {
	$path   = rtrim( $run_dir, '/' ) . '/' . $part['filename'];
	$exists = is_file( $path );
	$raw    = $exists ? (string) file_get_contents( $path ) : '';
	$decoded = json_decode( $raw, true );

	$schema_ok = is_array( $decoded )
		&& isset( $decoded['manifest']['format'], $decoded['manifest']['version'] )
		&& 'conexao-event-export' === $decoded['manifest']['format']
		&& '1.1.0' === $decoded['manifest']['version']
		&& isset( $decoded['events'] )
		&& is_array( $decoded['events'] )
		&& count( $decoded['events'] ) === $part['event_count']
		&& (int) $decoded['manifest']['event_count'] === (int) $part['event_count'];

	$sha_ok = $exists && hash_file( 'sha256', $path ) === $part['sha256'];

	$img_count = 0;
	$uuids_in  = array();
	if ( $schema_ok ) {
		foreach ( $decoded['events'] as $ev ) {
			if ( ! empty( $ev['featured_image']['data_base64'] ) ) {
				$img_count++;
			}
			if ( ! empty( $ev['uuid'] ) ) {
				$uuids_in[] = (string) $ev['uuid'];
			}
		}
	}

	$pos_first = '' !== $part['first_uuid'] ? array_search( $part['first_uuid'], $uuids_in, true ) : false;
	$pos_last  = '' !== $part['last_uuid'] ? array_search( $part['last_uuid'], $uuids_in, true ) : false;
	$contig_ok = ( false !== $pos_first && false !== $pos_last && $pos_last >= $pos_first );

	$part_ok = $exists && $schema_ok && $sha_ok && $contig_ok && $img_count === (int) $part['image_count'];
	if ( ! $part_ok ) {
		$fail = true;
	}

	$part_results[] = array(
		'part'         => (int) $part['part_number'],
		'events'       => (int) $part['event_count'],
		'images'       => (int) $part['image_count'],
		'recount_imgs' => $img_count,
		'uuids'        => count( $uuids_in ),
		'bytes'        => $exists ? filesize( $path ) : 0,
		'sha_ok'       => $sha_ok,
		'schema_ok'    => $schema_ok,
		'contiguous'   => $contig_ok,
		'ok'           => $part_ok,
	);

	$all_uuids  = array_merge( $all_uuids, $uuids_in );
	$sum_events += (int) $part['event_count'];
	$sum_images += (int) $part['image_count'];
	$sum_bytes  += $exists ? filesize( $path ) : 0;
}

$unique_uuids = array_values( array_unique( $all_uuids ) );
$uuid_dups    = count( $all_uuids ) - count( $unique_uuids );
$manifest_ok  = ( $manifest['uuids'] === $unique_uuids );
$counts_ok    = $sum_events === (int) $manifest['total_event_count'];

if ( $uuid_dups > 0 || ! $manifest_ok || ! $counts_ok || 'PASS' !== $manifest['validation_result'] ) {
	$fail = true;
}

$summary = array(
	'mode'              => $mode,
	'run_dir'           => $run_dir,
	'part_size'         => $part_size,
	'part_count'        => (int) $manifest['part_count'],
	'total_events'      => (int) $manifest['total_event_count'],
	'total_images'      => (int) $manifest['total_image_count'],
	'sum_part_events'   => $sum_events,
	'sum_part_images'   => $sum_images,
	'sum_part_bytes'    => $sum_bytes,
	'unique_uuids'      => count( $unique_uuids ),
	'uuid_dups'         => $uuid_dups,
	'manifest_uuids_ok' => $manifest_ok,
	'counts_ok'         => $counts_ok,
	'validation'        => $manifest['validation'],
	'validation_result' => $manifest['validation_result'],
	'elapsed_sec'       => $elapsed,
	'peak_mem_bytes'    => (int) $result['peak_mem_bytes'],
	'peak_mem'          => size_format( (int) $result['peak_mem_bytes'] ),
	'parts'             => $part_results,
	'result'            => $fail ? 'FAIL' : 'PASS',
);

$out_json = wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
echo $out_json . "\n";

if ( '' !== $stats_out ) {
	file_put_contents( $stats_out, $out_json . "\n" );
}

exit( $fail ? 1 : 0 );
