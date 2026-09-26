<?php
/**
 * Stage 2 — REST language contract probe (local, read/write-free apart from
 * two temporary fixture posts that are deleted at the end).
 *
 * Usage:
 *   docker compose exec -T wordpress wp eval-file - --allow-root < scripts/stage2-rest-probe.php
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	require_once '/var/www/html/wp-load.php';
}

if ( ! function_exists( 'pll_set_post_language' ) ) {
	echo "SKIP: Polylang inactive\n";
	return;
}

$pt = wp_insert_post( array( 'post_type' => 'guide', 'post_title' => '[STAGE2-REST] Probe PT', 'post_content' => 'x', 'post_status' => 'publish' ) );
$en = wp_insert_post( array( 'post_type' => 'guide', 'post_title' => 'Stage2 REST Probe EN', 'post_content' => 'x', 'post_status' => 'publish' ) );
pll_set_post_language( $pt, 'pt' );
pll_set_post_language( $en, 'en' );
pll_save_post_translations( array( 'pt' => $pt, 'en' => $en ) );

/**
 * Collect (id, lang) pairs from a REST collection request.
 *
 * @param string $route Route, e.g. 'wp/v2/guide'.
 * @param array  $query Query args.
 * @return array
 */
function stage2_rest_probe( $route, $query = array() ) {
	$request  = new WP_REST_Request( 'GET', '/' . $route );
	foreach ( $query as $key => $value ) {
		$request->set_param( $key, $value );
	}
	$response = rest_do_request( $request );
	$data     = $response->get_data();
	$out      = array(
		'status' => $response->get_status(),
		'total'  => $response->get_headers()['X-WP-Total'] ?? '',
		'langs'  => array(),
	);

	if ( is_array( $data ) ) {
		foreach ( $data as $item ) {
			$out['langs'][] = isset( $item['lang'] ) ? (string) $item['lang'] : '(no lang field)';
		}
	}

	return $out;
}

echo "fixtures: pt={$pt} en={$en}\n";

foreach ( array( '', 'pt-br', 'en' ) as $lang ) {
	$query = array( 'per_page' => 100 );
	if ( '' !== $lang ) {
		$query['lang'] = $lang;
	}

	$result = stage2_rest_probe( 'wp/v2/guide', $query );
	echo "guide lang='" . $lang . "' → status " . $result['status'] . ', total ' . $result['total']
		. ', lang fields: ' . implode( ',', array_slice( array_count_values( $result['langs'] ), 0, 5 ) ) . "\n";
}

foreach ( array( '', 'pt-br', 'en' ) as $lang ) {
	$query = array( 'per_page' => 100 );
	if ( '' !== $lang ) {
		$query['lang'] = $lang;
	}

	$result = stage2_rest_probe( 'wp/v2/event', $query );
	echo "event lang='" . $lang . "' → status " . $result['status'] . ', total ' . $result['total'] . "\n";
}

// Translation links on a single object (both languages).
$request = new WP_REST_Request( 'GET', '/wp/v2/guide/' . $pt );
$data    = rest_do_request( $request )->get_data();
echo 'guide/' . $pt . ' → lang=' . ( $data['lang'] ?? '(none)' )
	. ' translations=' . wp_json_encode( $data['_links']['translations'] ?? null ) . "\n";

$request = new WP_REST_Request( 'GET', '/wp/v2/guide/' . $en );
$data    = rest_do_request( $request )->get_data();
echo 'guide/' . $en . ' → lang=' . ( $data['lang'] ?? '(none)' )
	. ' translations=' . wp_json_encode( $data['_links']['translations'] ?? null ) . "\n";

// Single-item query with a mismatching lang parameter.
$request = new WP_REST_Request( 'GET', '/wp/v2/guide/' . $pt );
$request->set_param( 'lang', 'en' );
$response = rest_do_request( $request );
echo 'guide/' . $pt . '?lang=en → status ' . $response->get_status() . "\n";

wp_delete_post( $pt, true );
wp_delete_post( $en, true );
echo "fixtures deleted\n";
