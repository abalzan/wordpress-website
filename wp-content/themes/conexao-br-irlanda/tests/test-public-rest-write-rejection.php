<?php
/**
 * The two public leisure REST endpoints are READ-ONLY, proven by request.
 *
 * `GET /conexao/v1/leisure-coordinates` and `GET /conexao/v1/leisure-cards` are
 * deliberately public: they answer the Lazer archive's location filter for a
 * visitor who has granted browser geolocation, so `permission_callback =>
 * __return_true` is correct for a READ. The risk this suite closes is the one a
 * behavioural suite cannot see: `test-leisure-location-services.php` exercises
 * the filter logic, so if someone changed `WP_REST_Server::READABLE` to
 * `ALLMETHODS` the existing coverage would still pass while the endpoints
 * silently became an anonymous write surface.
 *
 * It therefore asserts the CONTRACT rather than the payload:
 *
 *   - both routes are registered with exactly one method, and that method is
 *     GET (read from the live REST server registry, not from source text);
 *   - an anonymous GET still succeeds (the public behaviour is preserved);
 *   - an anonymous POST / PUT / PATCH / DELETE is refused for each route;
 *   - the refusal is WordPress's own `rest_no_route` (404) — a matched route
 *     with an unsupported method is a 404 in `WP_REST_Server::dispatch()`, not a
 *     405, and asserting the real code is what makes the test meaningful;
 *   - no post, term or meta row is created by any of those attempts.
 *
 * Read-only: it creates no fixture and deletes nothing. The write attempts are
 * refused before any callback runs, which the row-count assertions prove.
 *
 * @package Conexao_BR_Irlanda
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

test_title( 'Public leisure REST endpoints are read-only' );

test_require(
	function_exists( 'conexao_leisure_location_namespace' ),
	'theme',
	'the theme\'s leisure-location module is loaded',
	'docker compose exec wordpress wp --allow-root theme activate conexao-br-irlanda'
);

$plw_namespace = conexao_leisure_location_namespace();

test_section( 'Route registration' );

/**
 * Registered handler entries for a route path, from the live REST server.
 *
 * @param string $path Absolute route path (e.g. "/conexao/v1/leisure-cards").
 * @return array<int, array<string, mixed>>
 */
function plw_route_handlers( $path ) {
	$routes = rest_get_server()->get_routes();
	if ( ! isset( $routes[ $path ] ) ) {
		return array();
	}
	return is_array( $routes[ $path ] ) ? $routes[ $path ] : array();
}

$plw_paths = array(
	'leisure-coordinates' => '/' . $plw_namespace . '/leisure-coordinates',
	'leisure-cards'       => '/' . $plw_namespace . '/leisure-cards',
);

foreach ( $plw_paths as $plw_slug => $plw_path ) {
	$plw_handlers = plw_route_handlers( $plw_path );

	assert_equals( 1, count( $plw_handlers ), 'route /' . $plw_slug . ' is registered exactly once' );

	$plw_entry   = isset( $plw_handlers[0] ) ? $plw_handlers[0] : array();
	$plw_methods = isset( $plw_entry['methods'] ) && is_array( $plw_entry['methods'] )
		? array_keys( $plw_entry['methods'] )
		: array();

	assert_equals(
		array( 'GET' ),
		array_values( $plw_methods ),
		'route /' . $plw_slug . ' accepts GET and NOTHING else (a write method here would make the public permission callback a vulnerability)'
	);

	assert_true(
		isset( $plw_entry['permission_callback'] ),
		'route /' . $plw_slug . ' still declares an explicit permission_callback'
	);

	assert_true(
		! empty( $plw_entry['permission_callback'] ),
		'route /' . $plw_slug . ' declares a permission_callback that is not a falsy value'
	);
}

test_section( 'Anonymous reads still work (public behaviour preserved)' );

wp_set_current_user( 0 );

$plw_get = rest_do_request( new WP_REST_Request( 'GET', $plw_paths['leisure-coordinates'] ) );

assert_true( ! is_wp_error( $plw_get ), 'an anonymous GET of leisure-coordinates is not an error' );
assert_equals( 200, $plw_get->get_status(), 'an anonymous GET of leisure-coordinates returns 200' );

$plw_get_data = $plw_get->get_data();
assert_true( is_array( $plw_get_data ), 'the leisure-coordinates response body is an array' );
assert_set( $plw_get_data, 'items', 'the leisure-coordinates response carries an items list' );
assert_true( is_array( $plw_get_data['items'] ), 'the items value is an array' );
assert_set( $plw_get_data, 'count', 'the leisure-coordinates response carries a count' );
assert_equals(
	(int) $plw_get_data['count'],
	count( $plw_get_data['items'] ),
	'the reported count equals the number of items returned (the read is self-consistent)'
);

test_section( 'Anonymous writes are refused, and persist nothing' );

/**
 * A content fingerprint used to prove a refused write persisted nothing.
 *
 * Counts only: no record body, no personal data. It deliberately includes the
 * Lezer post types and the shared county/town taxonomies, which a writable
 * leisure endpoint could otherwise create or retag.
 *
 * @return array<string, int>
 */
function plw_content_fingerprint() {
	global $wpdb;

	$counts = array();
	foreach ( array( 'post', 'page', 'leisure', 'event', 'guide' ) as $type ) {
		$row                    = wp_count_posts( $type );
		$counts[ 'posts:' . $type ] = (int) $row->publish + (int) $row->draft + (int) $row->pending;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- aggregate counts for a test fingerprint.
	$counts['terms'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->term_taxonomy );

	return $counts;
}

$plw_before = plw_content_fingerprint();

$plw_write_methods = array( 'POST', 'PUT', 'PATCH', 'DELETE' );

foreach ( $plw_paths as $plw_slug => $plw_path ) {
	foreach ( $plw_write_methods as $plw_method ) {
		$plw_write = new WP_REST_Request( $plw_method, $plw_path );
		$plw_write->set_body_params(
			array(
				'ids'     => '1,2,3',
				'county'  => 'dublin',
				'lang'    => 'pt',
				'title'   => 'should never be stored',
				'content' => 'should never be stored',
			)
		);
		$plw_response = rest_do_request( $plw_write );

		assert_equals(
			404,
			$plw_response->get_status(),
			sprintf(
				'an anonymous %1$s of the public /%2$s route is refused with 404 (WP_REST_Server::dispatch() answers rest_no_route for a matched path with an unsupported method)',
				$plw_method,
				$plw_slug
			)
		);

		$plw_error = $plw_response->as_error();
		assert_true( is_wp_error( $plw_error ), sprintf( 'the refused %1$s /%2$s response is a WP_Error', $plw_method, $plw_slug ) );
		if ( is_wp_error( $plw_error ) ) {
			assert_equals(
				'rest_no_route',
				$plw_error->get_error_code(),
				sprintf( 'the %1$s /%2$s refusal carries the expected rest_no_route code', $plw_method, $plw_slug )
			);
		}
	}
}

$plw_after = plw_content_fingerprint();

assert_equals(
	$plw_before,
	$plw_after,
	'no post, draft or term was created by the refused write attempts (the fingerprint is unchanged)'
);

test_section( 'A write request cannot reach a callback by masquerading as a read' );

$plw_spoof = new WP_REST_Request( 'GET', $plw_paths['leisure-cards'] );
$plw_spoof->set_query_params( array( 'ids' => '1' ) );
$plw_spoof->set_body_params( array( 'title' => 'spoofed', 'content' => 'spoofed' ) );
$plw_spoof_response = rest_do_request( $plw_spoof );

assert_equals(
	200,
	$plw_spoof_response->get_status(),
	'a GET carrying body parameters is still served as a read (the body is ignored, never written)'
);
assert_equals(
	$plw_before,
	plw_content_fingerprint(),
	'a GET with body parameters persists nothing (the endpoint has no write path at all)'
);

test_finish( 'public-rest-write-rejection' );

