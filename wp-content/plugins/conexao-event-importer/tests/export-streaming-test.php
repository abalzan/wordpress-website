<?php
/**
 * Local-only test driver for the streaming admin export fix.
 *
 * Modes:
 *   php export-streaming-test.php small <out.json>     # stream_export_to_file(), 13-event scope
 *   php export-streaming-test.php medium <out.json>    # stream_export_to_file(), galway scope
 *   php export-streaming-test.php full <out.json> <stats.json>
 *                                                      # the REAL admin download() path
 *   php export-streaming-test.php baseline <out.json>  # original one-shot build_export() path
 *                                                      # (requires -d memory_limit>=2G)
 *
 * The "full" mode is the exact code the admin export button triggers
 * (Conexao_Event_Transfer_Admin::handle_export() -> download()); in CLI the
 * headers are no-ops and the JSON is written to stdout.
 */

set_time_limit(0);

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

$mode = isset( $argv[1] ) ? $argv[1] : '';
$out  = isset( $argv[2] ) ? $argv[2] : '';
if ( '' === $mode || '' === $out ) {
	fwrite( STDERR, "usage: php export-streaming-test.php <small|medium|full|baseline> <out.json> [stats.json]\n" );
	exit( 1 );
}

$exporter = new Conexao_Event_Export();

if ( 'small' === $mode || 'medium' === $mode ) {
	$sources = ( 'small' === $mode ) ? array( 'mondellopark', 'ivvcc' ) : array( 'eventbrite_galway' );
	$start   = microtime( true );
	$result  = $exporter->stream_export_to_file( $out, array( 'sources' => $sources ) );
	$elapsed = round( microtime( true ) - $start, 1 );

	if ( is_wp_error( $result ) ) {
		fwrite( STDERR, 'STREAM_ERROR: ' . $result->get_error_code() . ' ' . $result->get_error_message() . "\n" );
		exit( 1 );
	}

	echo wp_json_encode(
		array(
			'mode'           => $mode,
			'result'         => 'ok',
			'bytes'          => filesize( $out ),
			'seconds'        => $elapsed,
			'peak_mem_bytes' => memory_get_peak_usage( true ),
			'memory_limit'   => ini_get( 'memory_limit' ),
		),
		JSON_PRETTY_PRINT
	) . "\n";
	exit( 0 );
}

if ( 'baseline' === $mode ) {
	$start   = microtime( true );
	$payload = $exporter->build_export();
	$elapsed = round( microtime( true ) - $start, 1 );

	// Exactly like the original download(): pretty-printed full encode.
	$json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	file_put_contents( $out, $json );

	echo wp_json_encode(
		array(
			'mode'           => 'baseline',
			'result'         => 'ok',
			'bytes'          => strlen( $json ),
			'seconds'        => $elapsed,
			'peak_mem_bytes' => memory_get_peak_usage( true ),
			'memory_limit'   => ini_get( 'memory_limit' ),
			'event_count'    => count( $payload['events'] ),
		),
		JSON_PRETTY_PRINT
	) . "\n";
	exit( 0 );
}

if ( 'full' === $mode ) {
	$stats_path = isset( $argv[3] ) ? $argv[3] : ( $out . '.stats.json' );
	$start      = microtime( true );

	register_shutdown_function(
		static function () use ( $stats_path, $start, $out ) {
			$stats = array(
				'mode'           => 'full',
				'bytes'          => file_exists( $out ) ? filesize( $out ) : 0,
				'seconds'        => round( microtime( true ) - $start, 1 ),
				'peak_mem_bytes' => memory_get_peak_usage( true ),
				'memory_limit'   => ini_get( 'memory_limit' ),
				'tail'           => 'ok',
			);
			if ( file_exists( $out ) ) {
				$fh = fopen( $out, 'r' );
				if ( $fh ) {
					fseek( $fh, -2, SEEK_END );
					$stats['tail'] = (string) fread( $fh, 2 );
					fclose( $fh );
				}
			}
			file_put_contents( $stats_path, wp_json_encode( $stats, JSON_PRETTY_PRINT ) . "\n" );
		}
	);

	// The real admin code path (download() sends headers + readfile() + exit).
	$exporter->download();
}
