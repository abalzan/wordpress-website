<?php
/**
 * Local-only diagnostic: reproduce the admin one-shot export memory failure.
 *
 * Replicates the exact admin export path (Conexao_Event_Export::build_export()
 * + download()'s wp_json_encode calls) with instrumentation, WITHOUT modifying
 * the exporter. Run inside the container:
 *
 *   docker compose exec -T wordpress php -d memory_limit=512M \
 *     wp-content/plugins/conexao-event-importer/tests/export-memory-diagnostic.php
 *
 * Does not change the export contract; only reads data (plus UUID generation
 * already performed by the normal exporter).
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

$mb = function () {
	return round( memory_get_usage( true ) / 1048576, 1 ) . 'MB';
};
$peak = function () {
	return round( memory_get_peak_usage( true ) / 1048576, 1 ) . 'MB';
};

echo "=== EVENT EXPORT MEMORY DIAGNOSTIC ===\n";
echo 'memory_limit=' . ini_get( 'memory_limit' ) . "\n";
echo 'php_version=' . PHP_VERSION . "\n";

$exporter = new Conexao_Event_Export();
$total    = $exporter->count_events();
echo "total_events(count_events())={$total}\n";

// --- Phase 1: replicate build_export()'s one-shot in-memory array build ---
$query_args = array(
	'post_type'      => 'event',
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'orderby'        => 'ID',
	'order'          => 'ASC',
	'no_found_rows'  => true,
);
$query = new WP_Query( $query_args );

echo 'wp_query_done ' . $mb() . ' peak=' . $peak() . ' posts=' . count( $query->posts ) . "\n";

$ref = new ReflectionMethod( $exporter, 'export_event' );
$ref->setAccessible( true );

$events      = array();
$image_count = 0;
$i           = 0;

foreach ( $query->posts as $post ) {
	$event = $ref->invoke( $exporter, $post );
	if ( ! empty( $event['featured_image']['data_base64'] ) ) {
		$image_count++;
	}
	$events[] = $event;
	$i++;
	if ( 0 === $i % 100 ) {
		echo "built_n={$i} images={$image_count} " . $mb() . ' peak=' . $peak() . "\n";
	}
}

echo "array_build_complete events=" . count( $events ) . " images={$image_count} " . $mb() . ' peak=' . $peak() . "\n";

// --- Phase 2: replicate download()'s payload/manifest + wp_json_encode calls ---
$manifest = array(
	'format'      => Conexao_Event_Export::FORMAT,
	'version'     => Conexao_Event_Export::FORMAT_VERSION,
	'exported_at' => current_time( 'c' ),
	'source_url'  => home_url(),
	'event_count' => count( $events ),
);
$payload = array(
	'manifest' => $manifest,
	'events'   => $events,
);

echo "payload_built " . $mb() . ' peak=' . $peak() . "\n";

// Exact statement from download(), line 353: Content-Length header.
echo "attempting strlen(wp_json_encode(\$payload)) (download() line 353 equivalent)...\n";
$json_1 = wp_json_encode( $payload );
if ( false === $json_1 ) {
	echo "wp_json_encode returned false\n";
	exit( 1 );
}
echo 'first_encode_bytes=' . strlen( $json_1 ) . ' ' . $mb() . ' peak=' . $peak() . "\n";

// Exact statement from download(), line 356: the echo of the second encode.
echo "attempting second wp_json_encode(\$payload, ...) (download() line 356 equivalent)...\n";
$json_2 = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
echo 'second_encode_bytes=' . strlen( $json_2 ) . ' ' . $mb() . ' peak=' . $peak() . "\n";

unset( $json_1, $json_2, $payload, $events );
echo "=== DIAGNOSTIC COMPLETE (no fatal under this run) ===\n";
