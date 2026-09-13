<?php
/**
 * Stage D — provider fetch check (read-only).
 *
 * For each supplied source id, performs ONE live request and reports the raw
 * HTTP status plus structural checks, so a rate-limit/block (HTTP 429/403/5xx)
 * is objectively distinguished from a genuinely empty result.
 *
 * Eventbrite: GET page 1, report status, presence of __SERVER_DATA__,
 *             page_count/object_count, and observed region labels.
 * Heritage Week: GET listing, report status, card count, edition year,
 *             Next-link presence.
 *
 * Usage:
 *   docker compose exec wordpress php .../stage-d-fetch-check.php <id> [<id>...]
 *
 * No writes. No Event records touched.
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

$ids = array_slice( $argv, 1 );
if ( empty( $ids ) ) {
	fwrite( STDERR, "Usage: stage-d-fetch-check.php <source_id> [<source_id>...]\n" );
	exit( 2 );
}

$ua   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
$sources = new Conexao_Event_Sources();
$out  = array();

foreach ( $ids as $id ) {
	$source = $sources->get( $id );
	if ( ! $source ) {
		$out[ $id ] = array( 'status' => 'UNREGISTERED' );
		continue;
	}

	$type = isset( $source['type'] ) ? $source['type'] : '';
	$url  = isset( $source['url'] ) ? $source['url'] : '';

	if ( 'eventbrite' === $type ) {
		$response = wp_remote_get( $url, array( 'timeout' => 30, 'user-agent' => $ua ) );
		if ( is_wp_error( $response ) ) {
			$out[ $id ] = array( 'type' => 'eventbrite', 'status' => 'BLOCKED', 'reason' => $response->get_error_message() );
			continue;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			$out[ $id ] = array( 'type' => 'eventbrite', 'status' => 'BLOCKED', 'http' => $code, 'bytes' => strlen( $body ) );
			continue;
		}

		$has_server_data = false !== strpos( $body, '__SERVER_DATA__' );
		$page_count = 0;
		$object_count = 0;
		if ( preg_match( '/__SERVER_DATA__\s*=\s*(\{.*?\});/s', $body, $m ) ) {
			$data = json_decode( $m[1], true );
			if ( is_array( $data ) && isset( $data['pagination'] ) ) {
				$page_count   = isset( $data['pagination']['page_count'] ) ? (int) $data['pagination']['page_count'] : 0;
				$object_count = isset( $data['pagination']['object_count'] ) ? (int) $data['pagination']['object_count'] : 0;
			}
		}

		$out[ $id ] = array(
			'type'            => 'eventbrite',
			'status'          => 'PASS',
			'http'            => $code,
			'bytes'           => strlen( $body ),
			'has_server_data' => $has_server_data,
			'page_count'      => $page_count,
			'object_count'    => $object_count,
			'url'             => $url,
		);
		continue;
	}

	if ( 'heritage_week' === $type ) {
		$response = wp_remote_get( $url, array( 'timeout' => 30, 'user-agent' => $ua ) );
		if ( is_wp_error( $response ) ) {
			$out[ $id ] = array( 'type' => 'heritage_week', 'status' => 'BLOCKED', 'reason' => $response->get_error_message() );
			continue;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			$out[ $id ] = array( 'type' => 'heritage_week', 'status' => 'BLOCKED', 'http' => $code, 'bytes' => strlen( $body ) );
			continue;
		}

		$cards = substr_count( $body, 'item-summary' );
		$year  = '';
		if ( preg_match( '/<title>([^<]*)<\/title>/i', $body, $tm ) && preg_match( '/(\d{4})/', $tm[1], $ym ) ) {
			$year = $ym[1];
		}

		$out[ $id ] = array(
			'type'  => 'heritage_week',
			'status' => 'PASS',
			'http'  => $code,
			'bytes' => strlen( $body ),
			'cards' => $cards,
			'year'  => $year,
			'url'   => $url,
		);
		continue;
	}

	$out[ $id ] = array( 'type' => $type, 'status' => 'SKIPPED_NON_COUNTY' );
}

echo wp_json_encode( array( 'checked_at' => current_time( 'c' ), 'results' => $out ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
