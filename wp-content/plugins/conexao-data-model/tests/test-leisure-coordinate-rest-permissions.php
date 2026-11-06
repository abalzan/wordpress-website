<?php
/**
 * REST permission contract for the leisure coordinate meta.
 *
 * `_leisure_lat` and `_leisure_lng` are PROTECTED meta keys (underscore
 * prefixed), so `register_meta()` defaults their REST write permission to
 * `__return_false` unless the registration declares its own `auth_callback`.
 * Without one, every REST write fails with `403 rest_cannot_update` — even for
 * an administrator — which is exactly the defect this suite guards against.
 *
 * The suite proves the contract in BOTH directions, and every permission
 * assertion is a REAL REST request through `rest_do_request()` (none of them
 * inspects PHP source text):
 *
 *   - registration: both keys are registered for `leisure`, single, string,
 *     REST-exposed, keep their sanitizer, and declare a permission callback
 *     that is neither `__return_true` nor `__return_false`;
 *   - authorised: a user who can edit the leisure record may update BOTH keys
 *     over REST, the response carries the stored value, the value persists,
 *     and the data model's own read path (`get_coordinates()`) returns it;
 *   - denied: an anonymous caller (401) and an authenticated user without the
 *     capability (403) are refused with `rest_cannot_edit`, and a refused
 *     request leaves the stored value untouched;
 *   - validation still applies over REST (an out-of-range value sanitises to
 *     empty rather than being stored);
 *   - regression: `_leisure_image_*` keeps its existing permission behaviour,
 *     unrelated leisure meta is unaffected, the provenance keys stay out of
 *     the REST surface, and an unrelated protected key WITHOUT its own
 *     `auth_callback` is still refused — proof the fix is narrow and does not
 *     weaken WordPress protected-meta handling globally.
 *
 * Creates one temporary `leisure` post, one temporary `guide` post and one
 * temporary subscriber, and deletes all three at the end. It never modifies an
 * existing record and never contacts production.
 *
 * Usage (from the project root):
 *   docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-data-model/tests/test-leisure-coordinate-rest-permissions.php
 *
 * @package Conexao_Data_Model
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$passed = 0;
$failed = 0;

/** Temporary posts created by this suite (deleted at the end). */
$lcperm_posts = array();

/** Temporary users created by this suite (deleted at the end). */
$lcperm_users = array();

/**
 * Perform a REST meta update against a post of the given type.
 *
 * @param string $post_type REST base of the post type (defaults to the type name).
 * @param int    $post_id   Target post ID.
 * @param array  $meta      Meta key => value pairs for the request `meta` body.
 * @return WP_REST_Response|WP_Error
 */
function lcperm_rest_update( $post_type, $post_id, array $meta ) {
	$request = new WP_REST_Request( 'POST', '/wp/v2/' . $post_type . '/' . (int) $post_id );
	$request->set_param( 'meta', $meta );
	return rest_do_request( $request );
}

/**
 * Read a REST response body.
 *
 * @param mixed $response Result of rest_do_request().
 * @return array
 */
function lcperm_data( $response ) {
	if ( is_wp_error( $response ) ) {
		$data = $response->get_error_data();
		return is_array( $data ) ? $data : array();
	}

	return $response instanceof WP_REST_Response ? (array) $response->get_data() : array();
}

/**
 * HTTP status of a REST result (WP_Error carries its own status).
 *
 * @param mixed $response Result of rest_do_request().
 * @return int
 */
function lcperm_status( $response ) {
	if ( $response instanceof WP_REST_Response ) {
		return (int) $response->get_status();
	}

	$data = lcperm_data( $response );
	return isset( $data['status'] ) ? (int) $data['status'] : 0;
}

/**
 * Machine-readable error code of a REST result ('' when it succeeded).
 *
 * @param mixed $response Result of rest_do_request().
 * @return string
 */
function lcperm_code( $response ) {
	if ( is_wp_error( $response ) ) {
		return (string) $response->get_error_code();
	}

	$data = lcperm_data( $response );
	return isset( $data['code'] ) ? (string) $data['code'] : '';
}

/**
 * The meta value echoed in a REST response, or null when it is absent.
 *
 * @param mixed  $response Result of rest_do_request().
 * @param string $key      Meta key.
 * @return mixed
 */
function lcperm_response_meta( $response, $key ) {
	$data = lcperm_data( $response );
	return ( is_array( $data ) && isset( $data['meta'] ) && is_array( $data['meta'] ) && array_key_exists( $key, $data['meta'] ) )
		? $data['meta'][ $key ]
		: null;
}

/**
 * Create a temporary post and remember it for cleanup.
 *
 * @param array $args wp_insert_post() arguments.
 * @return int Post ID (0 on failure).
 */
function lcperm_create_post( array $args ) {
	global $lcperm_posts;

	$post_id = wp_insert_post( $args );
	if ( is_wp_error( $post_id ) || ! $post_id ) {
		return 0;
	}

	$lcperm_posts[] = (int) $post_id;
	return (int) $post_id;
}


// ---------------------------------------------------------------------------
test_title( 'Leisure coordinate REST permissions' );

// --- Preconditions ---------------------------------------------------------

$lcperm_admins = get_users(
	array(
		'role'    => 'administrator',
		'number'  => 1,
		'fields'  => 'ID',
		'orderby' => 'ID',
	)
);

test_require(
	! empty( $lcperm_admins ),
	'seed-admin',
	'an administrator account exists for the authorised-write assertions',
	'Every WordPress install has one; a missing administrator means the bootstrap '
	. 'loaded the wrong database.'
);

$lcperm_admin_id = (int) $lcperm_admins[0];
wp_set_current_user( $lcperm_admin_id );
test_prerequisite_hint( 'seed-admin' );

$lcperm_leisure_id = lcperm_create_post(
	array(
		'post_type'   => 'leisure',
		'post_title'  => '[TEST] Coordinate REST permissions',
		'post_status' => 'publish',
	)
);
assert_true( $lcperm_leisure_id > 0, 'a temporary leisure record was created' );

// ---------------------------------------------------------------------------
test_section( 'Registration: the two coordinate keys' );

$lcperm_registered = get_registered_meta_keys( 'post', 'leisure' );

assert_true( isset( $lcperm_registered['_leisure_lat'] ), '_leisure_lat is registered for the leisure post type' );
assert_true( isset( $lcperm_registered['_leisure_lng'] ), '_leisure_lng is registered for the leisure post type' );

$lcperm_lat = isset( $lcperm_registered['_leisure_lat'] ) ? $lcperm_registered['_leisure_lat'] : array();
$lcperm_lng = isset( $lcperm_registered['_leisure_lng'] ) ? $lcperm_registered['_leisure_lng'] : array();

foreach ( array(
	'_leisure_lat' => $lcperm_lat,
	'_leisure_lng' => $lcperm_lng,
) as $lcperm_key => $lcperm_args ) {
	assert_true( ! empty( $lcperm_args['single'] ), $lcperm_key . ' is registered as single-value meta' );
	assert_equals( 'string', isset( $lcperm_args['type'] ) ? $lcperm_args['type'] : null, $lcperm_key . ' is registered as a decimal string' );
	assert_true( ! empty( $lcperm_args['show_in_rest'] ), $lcperm_key . ' stays exposed through REST' );

	$lcperm_expected_sanitizer = '_leisure_lat' === $lcperm_key
		? array( 'Conexao_Data_Model_Meta', 'sanitize_latitude' )
		: array( 'Conexao_Data_Model_Meta', 'sanitize_longitude' );
	assert_equals(
		$lcperm_expected_sanitizer,
		isset( $lcperm_args['sanitize_callback'] ) ? $lcperm_args['sanitize_callback'] : null,
		$lcperm_key . ' keeps its range-validating sanitize_callback'
	);

	assert_true(
		isset( $lcperm_args['auth_callback'] ) && is_callable( $lcperm_args['auth_callback'] ),
		$lcperm_key . ' declares a callable REST permission callback'
	);
	assert_true(
		! in_array( $lcperm_args['auth_callback'], array( '__return_false', '__return_true' ), true ),
		$lcperm_key . ' does not fall back to the core default (neither "deny all" nor "allow everyone")'
	);
}

// The registered permission callback is a real capability check, not a toggle:
// it must refuse an anonymous caller and allow a user who can edit the record.
$lcperm_lat_cb = isset( $lcperm_lat['auth_callback'] ) ? $lcperm_lat['auth_callback'] : null;
$lcperm_lng_cb = isset( $lcperm_lng['auth_callback'] ) ? $lcperm_lng['auth_callback'] : null;

if ( is_callable( $lcperm_lat_cb ) && is_callable( $lcperm_lng_cb ) ) {
	$lcperm_callbacks = array(
		'_leisure_lat' => $lcperm_lat_cb,
		'_leisure_lng' => $lcperm_lng_cb,
	);

	wp_set_current_user( 0 );
	foreach ( $lcperm_callbacks as $lcperm_key => $lcperm_cb ) {
		assert_true(
			! call_user_func( $lcperm_cb, true, $lcperm_key, $lcperm_leisure_id ),
			$lcperm_key . ': the permission callback refuses an anonymous caller'
		);
	}

	wp_set_current_user( $lcperm_admin_id );
	foreach ( $lcperm_callbacks as $lcperm_key => $lcperm_cb ) {
		assert_true(
			(bool) call_user_func( $lcperm_cb, true, $lcperm_key, $lcperm_leisure_id ),
			$lcperm_key . ': the permission callback allows a user who can edit the record'
		);
	}
} else {
	assert_fail( 'the coordinate permission callbacks are invocable' );
}

// ---------------------------------------------------------------------------
test_section( 'Authorised REST update (administrator)' );

$lcperm_response = lcperm_rest_update(
	'leisure',
	$lcperm_leisure_id,
	array(
		'_leisure_lat' => '53.3498',
		'_leisure_lng' => '-6.2603',
	)
);

assert_equals( 200, lcperm_status( $lcperm_response ), 'an authorised REST update of _leisure_lat/_leisure_lng succeeds (200, never 403 rest_cannot_update)' );
assert_equals( '', lcperm_code( $lcperm_response ), 'the authorised REST update returns no error code' );
assert_equals( '53.3498', lcperm_response_meta( $lcperm_response, '_leisure_lat' ), 'the REST response echoes the stored latitude' );
assert_equals( '-6.2603', lcperm_response_meta( $lcperm_response, '_leisure_lng' ), 'the REST response echoes the stored longitude' );
assert_equals( '53.3498', get_post_meta( $lcperm_leisure_id, '_leisure_lat', true ), 'the latitude persists after the REST update' );
assert_equals( '-6.2603', get_post_meta( $lcperm_leisure_id, '_leisure_lng', true ), 'the longitude persists after the REST update' );

// The data model's own read path — what the /lazer/ proximity feature consumes
// through the theme endpoint — must see the value written over REST.
$lcperm_coords = Conexao_Data_Model_Meta::get_coordinates( $lcperm_leisure_id );
assert_true( is_array( $lcperm_coords ), 'get_coordinates() returns a pair after the REST update' );
assert_equals( 53.3498, isset( $lcperm_coords['lat'] ) ? $lcperm_coords['lat'] : null, 'get_coordinates() reads back the latitude written over REST' );
assert_equals( -6.2603, isset( $lcperm_coords['lng'] ) ? $lcperm_coords['lng'] : null, 'get_coordinates() reads back the longitude written over REST' );

// A separate REST read confirms persistence, not just an echoed request body.
$lcperm_read = new WP_REST_Request( 'GET', '/wp/v2/leisure/' . $lcperm_leisure_id );
$lcperm_read->set_param( 'context', 'edit' );
$lcperm_read_response = rest_do_request( $lcperm_read );
assert_equals( 200, lcperm_status( $lcperm_read_response ), 'the record can be read back through REST' );
assert_equals( '53.3498', lcperm_response_meta( $lcperm_read_response, '_leisure_lat' ), 'a subsequent REST read confirms the persisted latitude' );
assert_equals( '-6.2603', lcperm_response_meta( $lcperm_read_response, '_leisure_lng' ), 'a subsequent REST read confirms the persisted longitude' );

// ---------------------------------------------------------------------------
test_section( 'Denied REST updates (anonymous and unprivileged)' );

// A known good value is in place from the authorised section: every denied
// request below must leave BOTH stored values exactly as they are.
assert_equals( '53.3498', get_post_meta( $lcperm_leisure_id, '_leisure_lat', true ), 'precondition: a stored latitude is in place before the denied requests' );
assert_equals( '-6.2603', get_post_meta( $lcperm_leisure_id, '_leisure_lng', true ), 'precondition: a stored longitude is in place before the denied requests' );

wp_set_current_user( 0 );
foreach ( array(
	'_leisure_lat' => '11.11',
	'_leisure_lng' => '22.22',
) as $lcperm_key => $lcperm_value ) {
	$lcperm_denied = lcperm_rest_update( 'leisure', $lcperm_leisure_id, array( $lcperm_key => $lcperm_value ) );
	assert_equals( 401, lcperm_status( $lcperm_denied ), 'an ANONYMOUS caller is refused when updating ' . $lcperm_key . ' (401)' );
	assert_equals( 'rest_cannot_edit', lcperm_code( $lcperm_denied ), 'the anonymous refusal for ' . $lcperm_key . ' is the expected authorisation error' );
}
assert_equals( '53.3498', get_post_meta( $lcperm_leisure_id, '_leisure_lat', true ), 'the anonymous refusal left the stored latitude unchanged' );
assert_equals( '-6.2603', get_post_meta( $lcperm_leisure_id, '_leisure_lng', true ), 'the anonymous refusal left the stored longitude unchanged' );

// A logged-in user WITHOUT the capability to edit the record.
$lcperm_subscriber_id = (int) wp_insert_user(
	array(
		'user_login' => 'conexao_lcperm_' . strtolower( wp_generate_password( 10, false, false ) ),
		'user_pass'  => wp_generate_password( 24 ),
		'user_email' => 'conexao-lcperm-' . strtolower( wp_generate_password( 10, false, false ) ) . '@example.invalid',
		'role'       => 'subscriber',
	)
);
assert_true( $lcperm_subscriber_id > 0, 'a temporary subscriber account was created' );
$lcperm_users[] = $lcperm_subscriber_id;

assert_true( ! user_can( $lcperm_subscriber_id, 'edit_post', $lcperm_leisure_id ), 'precondition: the subscriber cannot edit the leisure record' );

wp_set_current_user( $lcperm_subscriber_id );
foreach ( array(
	'_leisure_lat' => '11.11',
	'_leisure_lng' => '22.22',
) as $lcperm_key => $lcperm_value ) {
	$lcperm_denied = lcperm_rest_update( 'leisure', $lcperm_leisure_id, array( $lcperm_key => $lcperm_value ) );
	assert_equals( 403, lcperm_status( $lcperm_denied ), 'an authenticated caller WITHOUT the capability is refused when updating ' . $lcperm_key . ' (403)' );
	assert_equals( 'rest_cannot_edit', lcperm_code( $lcperm_denied ), 'the unprivileged refusal for ' . $lcperm_key . ' is the expected authorisation error' );
}
assert_equals( '53.3498', get_post_meta( $lcperm_leisure_id, '_leisure_lat', true ), 'the unprivileged refusal left the stored latitude unchanged' );
assert_equals( '-6.2603', get_post_meta( $lcperm_leisure_id, '_leisure_lng', true ), 'the unprivileged refusal left the stored longitude unchanged' );

wp_set_current_user( $lcperm_admin_id );

// ---------------------------------------------------------------------------
test_section( 'Validation still applies over REST' );

$lcperm_invalid = lcperm_rest_update( 'leisure', $lcperm_leisure_id, array( '_leisure_lat' => '999' ) );
assert_equals( 200, lcperm_status( $lcperm_invalid ), 'the schema accepts the request (the sanitizer, not the permission layer, owns the range rule)' );
assert_equals( '', get_post_meta( $lcperm_leisure_id, '_leisure_lat', true ), 'an out-of-range latitude written over REST sanitises to empty — the permission fix did not bypass validation' );

$lcperm_restore = lcperm_rest_update(
	'leisure',
	$lcperm_leisure_id,
	array(
		'_leisure_lat' => '53.3498',
		'_leisure_lng' => '-6.2603',
	)
);
assert_equals( 200, lcperm_status( $lcperm_restore ), 'the authorised writer can restore a valid pair over REST' );
assert_equals( '53.3498', get_post_meta( $lcperm_leisure_id, '_leisure_lat', true ), 'the restored latitude is stored' );
assert_equals( '-6.2603', get_post_meta( $lcperm_leisure_id, '_leisure_lng', true ), 'the restored longitude is stored' );

// ---------------------------------------------------------------------------
test_section( 'Regression: the fix is narrow' );

// 1. The existing _leisure_image_* permission behaviour is unchanged.
$lcperm_image = lcperm_rest_update( 'leisure', $lcperm_leisure_id, array( '_leisure_image_source' => 'Wikimedia Commons' ) );
assert_equals( 200, lcperm_status( $lcperm_image ), '_leisure_image_source is still writable over REST by a user who can edit the record' );
assert_equals( 'Wikimedia Commons', get_post_meta( $lcperm_leisure_id, '_leisure_image_source', true ), 'the image source persists' );

wp_set_current_user( $lcperm_subscriber_id );
$lcperm_image_denied = lcperm_rest_update( 'leisure', $lcperm_leisure_id, array( '_leisure_image_source' => 'Changed' ) );
assert_equals( 403, lcperm_status( $lcperm_image_denied ), '_leisure_image_source is still refused to a caller without the capability' );
assert_equals( 'Wikimedia Commons', get_post_meta( $lcperm_leisure_id, '_leisure_image_source', true ), 'the refused image-source write changed nothing' );
wp_set_current_user( $lcperm_admin_id );

// 2. Unrelated leisure meta is unaffected.
$lcperm_town = lcperm_rest_update( 'leisure', $lcperm_leisure_id, array( '_leisure_town' => 'Dublin' ) );
assert_equals( 200, lcperm_status( $lcperm_town ), 'unrelated leisure meta (_leisure_town) is unaffected' );
assert_equals( 'Dublin', get_post_meta( $lcperm_leisure_id, '_leisure_town', true ), 'the unrelated leisure meta value persists' );

// 3. The provenance keys stay OUT of the REST surface — the fix did not widen it.
assert_true(
	isset( $lcperm_registered['_leisure_coord_source'] ) && empty( $lcperm_registered['_leisure_coord_source']['show_in_rest'] ),
	'_leisure_coord_source is still NOT exposed through REST'
);
assert_true(
	isset( $lcperm_registered['_leisure_coord_verified'] ) && empty( $lcperm_registered['_leisure_coord_verified']['show_in_rest'] ),
	'_leisure_coord_verified is still NOT exposed through REST'
);

$lcperm_provenance = lcperm_rest_update( 'leisure', $lcperm_leisure_id, array( '_leisure_coord_source' => 'written-through-rest' ) );
assert_equals( 200, lcperm_status( $lcperm_provenance ), 'a REST request carrying a non-REST key is not a fatal error' );
assert_equals( '', get_post_meta( $lcperm_leisure_id, '_leisure_coord_source', true ), 'the provenance key cannot be written over REST' );

// 4. No global weakening: another protected key WITHOUT its own auth_callback
//    is still refused, which is the WordPress default this change must not alter.
$lcperm_guide_id = lcperm_create_post(
	array(
		'post_type'   => 'guide',
		'post_title'  => '[TEST] Untouched protected meta',
		'post_status' => 'draft',
	)
);
assert_true( $lcperm_guide_id > 0, 'a temporary guide record was created' );

$lcperm_untouched = lcperm_rest_update( 'guide', $lcperm_guide_id, array( '_conexao_featured' => '1' ) );
assert_equals(
	403,
	lcperm_status( $lcperm_untouched ),
	'an unrelated protected key without its own auth_callback is still refused (protected-meta handling was not weakened globally)'
);
assert_equals( 'rest_cannot_update', lcperm_code( $lcperm_untouched ), 'the unrelated refusal is the core rest_cannot_update error' );
assert_equals( '', get_post_meta( $lcperm_guide_id, '_conexao_featured', true ), 'the unrelated protected key was not written' );

// 5. Coordinates keep their canonical string form and the record is still read
//    by the same code path the /lazer/ proximity feature uses.
$lcperm_final = Conexao_Data_Model_Meta::get_coordinates( $lcperm_leisure_id );
assert_equals( 53.3498, isset( $lcperm_final['lat'] ) ? $lcperm_final['lat'] : null, 'the location read path still returns the latitude' );
assert_equals( -6.2603, isset( $lcperm_final['lng'] ) ? $lcperm_final['lng'] : null, 'the location read path still returns the longitude' );
$lcperm_status = Conexao_Data_Model_Meta::coordinate_status( $lcperm_leisure_id );
assert_equals( 'unverified', isset( $lcperm_status['status'] ) ? $lcperm_status['status'] : null, 'the inventory state reports a valid pair as unverified (verification stays a separate editorial flag)' );
assert_equals( '53.3498', isset( $lcperm_status['lat'] ) ? $lcperm_status['lat'] : null, 'the inventory state exposes the canonical latitude string' );

// ---------------------------------------------------------------------------
test_section( 'Cleanup' );

wp_set_current_user( $lcperm_admin_id );

foreach ( $lcperm_posts as $lcperm_post_id ) {
	wp_delete_post( $lcperm_post_id, true );
}

if ( ! empty( $lcperm_users ) ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $lcperm_users as $lcperm_user_id ) {
		wp_delete_user( $lcperm_user_id );
	}
}

$lcperm_leftover_posts = get_posts(
	array(
		'post_type'   => array( 'leisure', 'guide' ),
		'post_status' => 'any',
		's'           => '[TEST] Coordinate REST permissions',
		'numberposts' => 5,
		'fields'      => 'ids',
	)
);
assert_true( empty( $lcperm_leftover_posts ), 'no temporary post remains after the suite' );

$lcperm_leftover_guides = get_posts(
	array(
		'post_type'   => 'guide',
		'post_status' => 'any',
		's'           => '[TEST] Untouched protected meta',
		'numberposts' => 5,
		'fields'      => 'ids',
	)
);
assert_true( empty( $lcperm_leftover_guides ), 'no temporary guide record remains after the suite' );

$lcperm_leftover_users = 0;
foreach ( $lcperm_users as $lcperm_user_id ) {
	if ( get_user_by( 'id', $lcperm_user_id ) ) {
		++$lcperm_leftover_users;
	}
}
assert_equals( 0, $lcperm_leftover_users, 'no temporary user remains after the suite' );

test_finish();

