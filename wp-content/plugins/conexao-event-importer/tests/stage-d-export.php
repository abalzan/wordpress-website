<?php
/**
 * Stage D — scoped rollout export + machine-readable manifest.
 *
 * Exports ONLY the events belonging to the 46 remaining-county source IDs using
 * the existing v1.1.0 `conexao-event-export` contract (featured images embedded),
 * then computes payload integrity checks and writes a manifest.
 *
 * Usage:
 *   docker compose exec wordpress php \
 *     /var/www/html/wp-content/plugins/conexao-event-importer/tests/stage-d-export.php \
 *     <export.json> <manifest.json>
 *
 * No production contact. No writes to non-event data (export assigns
 * `_event_export_uuid` to events missing one, which is the documented contract).
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

$export_path   = isset( $argv[1] ) ? $argv[1] : '/tmp/stage-d/stage-d-rollout-export.json';
$manifest_path = isset( $argv[2] ) ? $argv[2] : '/tmp/stage-d/stage-d-rollout-manifest.json';

// Rollout source set = registry counties minus the pilot counties.
$all       = Conexao_County_Registry::get_slugs();
$pilot     = array( 'cork', 'dublin', 'laois' );
$remaining = array_values( array_diff( $all, $pilot ) );
$sources   = array();
foreach ( $remaining as $slug ) {
	$sources[] = 'eventbrite_' . $slug;
}
foreach ( $remaining as $slug ) {
	$sources[] = 'heritage_week_' . $slug;
}

$exporter = new Conexao_Event_Export();
$payload  = $exporter->build_export( array( 'sources' => $sources ) );

$json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
file_put_contents( $export_path, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI tooling.

// ---------------------------------------------------------------------------
// Integrity checks.
// ---------------------------------------------------------------------------
$today = current_time( 'Y-m-d' );

$event_count   = count( $payload['events'] );
$image_count   = 0;
$uuid_seen     = array();
$uuid_dups     = 0;
$identity_seen = array();
$identity_dups = 0;
$url_seen      = array();
$url_dups      = 0;
$past_count    = 0;
$source_counts = array();
$county_counts = array();
$missing_uuid  = 0;
$missing_srcid = 0;

foreach ( $payload['events'] as $event ) {
	$uuid = isset( $event['uuid'] ) ? (string) $event['uuid'] : '';
	if ( '' === $uuid ) {
		$missing_uuid++;
	} elseif ( isset( $uuid_seen[ $uuid ] ) ) {
		$uuid_dups++;
	} else {
		$uuid_seen[ $uuid ] = true;
	}

	$meta      = isset( $event['meta'] ) && is_array( $event['meta'] ) ? $event['meta'] : array();
	$source    = isset( $meta['_event_source'] ) ? (string) $meta['_event_source'] : '';
	$source_id = isset( $meta['_event_source_id'] ) ? (string) $meta['_event_source_id'] : '';

	$source_counts[ $source ] = isset( $source_counts[ $source ] ) ? $source_counts[ $source ] + 1 : 1;

	if ( '' === $source_id ) {
		$missing_srcid++;
	} else {
		$identity = $source . '|' . $source_id;
		if ( isset( $identity_seen[ $identity ] ) ) {
			$identity_dups++;
		} else {
			$identity_seen[ $identity ] = true;
		}
	}

	$url = isset( $meta['_event_url'] ) ? trim( (string) $meta['_event_url'] ) : '';
	if ( '' !== $url ) {
		if ( isset( $url_seen[ $url ] ) ) {
			$url_dups++;
		} else {
			$url_seen[ $url ] = true;
		}
	}

	// An event is "past" only once its relevant end date has passed. Prefer the
	// explicit end date; fall back to the start date for single-day events.
	$effective_end = '';
	if ( ! empty( $meta['_event_end_date'] ) ) {
		$effective_end = (string) $meta['_event_end_date'];
	} elseif ( ! empty( $meta['_event_date'] ) ) {
		$effective_end = (string) $meta['_event_date'];
	}
	if ( '' !== $effective_end && $effective_end < $today ) {
		$past_count++;
	}

	$fi = isset( $event['featured_image'] ) && is_array( $event['featured_image'] ) ? $event['featured_image'] : array();
	if ( ! empty( $fi['data_base64'] ) ) {
		$image_count++;
	}

	$taxes    = isset( $event['taxonomies'] ) && is_array( $event['taxonomies'] ) ? $event['taxonomies'] : array();
	$counties = isset( $taxes['conexao_county'] ) && is_array( $taxes['conexao_county'] ) ? $taxes['conexao_county'] : array();
	foreach ( $counties as $c ) {
		$county_counts[ $c ] = isset( $county_counts[ $c ] ) ? $county_counts[ $c ] + 1 : 1;
	}
}

ksort( $source_counts );
ksort( $county_counts );

// Localhost check must inspect EVENT data only (the export manifest's own
// `source_url` is intentionally the local site URL and is not event data).
$events_json = wp_json_encode( $payload['events'] );
$localhost_in_events = preg_match( '/https?:\/\/localhost/i', $events_json );

$validation = array(
	'schema_version_ok'     => isset( $payload['manifest']['version'] ) && '1.1.0' === $payload['manifest']['version'],
	'no_localhost_urls'     => 0 === $localhost_in_events,
	'no_missing_uuid'       => 0 === $missing_uuid,
	'no_duplicate_uuid'     => 0 === $uuid_dups,
	'no_duplicate_identity' => 0 === $identity_dups,
	'no_duplicate_url'      => 0 === $url_dups,
	'no_past_events'        => 0 === $past_count,
	'event_count_matches'   => (int) $payload['manifest']['event_count'] === $event_count,
);

$manifest = array(
	'format'                  => 'conexao-events-stage-d-rollout-manifest',
	'generated_at'            => current_time( 'c' ),
	'export_filename'         => basename( $export_path ),
	'export_bytes'            => strlen( $json ),
	'schema'                  => isset( $payload['manifest']['format'] ) ? $payload['manifest']['format'] : '',
	'schema_version'          => isset( $payload['manifest']['version'] ) ? $payload['manifest']['version'] : '',
	'source_url'              => isset( $payload['manifest']['source_url'] ) ? $payload['manifest']['source_url'] : '',
	'scope'                   => array(
		'counties'     => $remaining,
		'county_count' => count( $remaining ),
		'source_ids'   => $sources,
		'source_count' => count( $sources ),
		'excludes'     => array( 'eventbrite_cork', 'eventbrite_dublin', 'eventbrite_laois', 'heritage_week_cork', 'heritage_week_dublin', 'heritage_week_laois' ),
	),
	'event_count'             => $event_count,
	'image_count'             => $image_count,
	'uuid_count'              => count( $uuid_seen ),
	'source_identity_count'   => count( $identity_seen ),
	'url_count'               => count( $url_seen ),
	'duplicate_count'         => array(
		'uuid'     => $uuid_dups,
		'identity' => $identity_dups,
		'url'      => $url_dups,
		'total'    => $uuid_dups + $identity_dups + $url_dups,
	),
	'past_event_count'        => $past_count,
	'missing_uuid_count'      => $missing_uuid,
	'missing_source_id_count' => $missing_srcid,
	'source_breakdown'        => $source_counts,
	'county_breakdown'        => $county_counts,
	'validation'              => $validation,
	'validation_result'       => ( count( array_filter( $validation ) ) === count( $validation ) ) ? 'PASS' : 'FAIL',
);

file_put_contents( $manifest_path, wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI tooling.

echo wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
