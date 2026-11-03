<?php
/**
 * Tests for the Lazer location services (coordinates + read-only endpoints).
 *
 * Covers the whole server-side half of the feature:
 *   - coordinate meta registration (REST visibility + provenance privacy);
 *   - numeric validation / sanitisation (range, zero, empty, malformed);
 *   - the editorial inventory states (missing / unverified / verified /
 *     invalid);
 *   - the admin-ux coordinate field (render + save + validation messages);
 *   - GET /conexao/v1/leisure-coordinates (complete matching set, nulls
 *     included, filters, published-only);
 *   - GET /conexao/v1/leisure-cards (requested order preserved, invalid IDs
 *     rejected, batch limit, drafts/other post types never rendered).
 *
 * Creates temporary leisure/job posts and temporary terms, and deletes them
 * at the end; it never modifies existing records.
 *
 * Usage (from the project root):
 *   docker compose exec wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-leisure-location-services.php
 *
 * @package Conexao_BR_Irlanda
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$theme_functions = get_template_directory() . '/functions.php';
if ( file_exists( $theme_functions ) ) {
	require_once $theme_functions;
}

$passed        = 0;
$failed        = 0;
$created_posts = array();
$created_terms = array();

/**
 * Get or create a temporary term; returns its slug.
 *
 * @param string $name     Term name.
 * @param string $slug     Term slug.
 * @param string $taxonomy Taxonomy.
 * @return string
 */
function lls_test_term( $name, $slug, $taxonomy ) {
	global $created_terms;
	$existing = term_exists( $slug, $taxonomy );
	if ( $existing && ! is_wp_error( $existing ) ) {
		return $slug;
	}
	$inserted = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
	if ( is_wp_error( $inserted ) ) {
		return '';
	}
	$created_terms[ $taxonomy ][] = (int) $inserted['term_id'];
	return $slug;
}

/**
 * Create a temporary post.
 *
 * @param array $args wp_insert_post args.
 * @return int Post ID (0 on failure).
 */
function lls_test_post( array $args ) {
	global $created_posts;
	$post_id = wp_insert_post( $args );
	if ( is_wp_error( $post_id ) || ! $post_id ) {
		return 0;
	}
	$created_posts[] = (int) $post_id;
	return (int) $post_id;
}

/**
 * Perform a REST request against the theme's own namespace.
 *
 * @param string $route Route (without namespace).
 * @param array  $params Query params.
 * @return WP_REST_Response|WP_Error
 */
function lls_test_rest( $route, array $params = array() ) {
	$request = new WP_REST_Request( 'GET', '/conexao/v1' . $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_do_request( $request );
}

/**
 * The IDs in a coordinates-endpoint response.
 *
 * @param WP_REST_Response|WP_Error $response Response.
 * @return int[]
 */
function lls_test_item_ids( $response ) {
	if ( is_wp_error( $response ) ) {
		return array();
	}
	$data = $response->get_data();
	return isset( $data['items'] ) ? array_map( static function ( $item ) {
		return (int) $item['id'];
	}, $data['items'] ) : array();
}

/**
 * The item payload for one attraction.
 *
 * @param WP_REST_Response|WP_Error $response Response.
 * @param int                       $id       Attraction ID.
 * @return array|null
 */
function lls_test_item( $response, $id ) {
	if ( is_wp_error( $response ) ) {
		return null;
	}
	foreach ( (array) $response->get_data()['items'] as $item ) {
		if ( (int) $item['id'] === (int) $id ) {
			return $item;
		}
	}
	return null;
}

/**
 * The HTTP status of a REST result (WP_REST_Response or WP_Error).
 *
 * `rest_do_request()` converts a handler/validation WP_Error into a
 * WP_REST_Response (error_to_response), so a status helper must accept both.
 *
 * @param mixed $response Result.
 * @return int
 */
function lls_test_status( $response ) {
	if ( is_wp_error( $response ) ) {
		$data = $response->get_error_data();
		return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
	}
	if ( $response instanceof WP_REST_Response ) {
		return (int) $response->get_status();
	}
	return 0;
}

/**
 * The machine-readable error code of a REST result.
 *
 * @param mixed $response Result.
 * @return string
 */
function lls_test_code( $response ) {
	if ( is_wp_error( $response ) ) {
		return (string) $response->get_error_code();
	}
	$data = $response instanceof WP_REST_Response ? $response->get_data() : array();
	return is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : '';
}

// ---------------------------------------------------------------------------
test_title( 'Lazer location services' );

// ---------------------------------------------------------------------------
test_section( 'Coordinate meta registration' );

$registered = get_registered_meta_keys( 'post', 'leisure' );

assert_true( isset( $registered['_leisure_lat'] ), '_leisure_lat is registered for the leisure post type' );
assert_true( isset( $registered['_leisure_lng'] ), '_leisure_lng is registered for the leisure post type' );
assert_true( ! empty( $registered['_leisure_lat']['show_in_rest'] ), '_leisure_lat is readable through REST' );
assert_true( ! empty( $registered['_leisure_lng']['show_in_rest'] ), '_leisure_lng is readable through REST' );
assert_true( isset( $registered['_leisure_coord_verified'] ), 'the coordinate verification flag is registered' );
assert_true( isset( $registered['_leisure_coord_source'] ), 'the coordinate source field is registered' );
assert_true( empty( $registered['_leisure_coord_verified']['show_in_rest'] ), 'the verification flag is NOT exposed through REST' );
assert_true( empty( $registered['_leisure_coord_source']['show_in_rest'] ), 'the coordinate source is NOT exposed through REST' );

// ---------------------------------------------------------------------------
test_section( 'Coordinate validation and sanitisation' );

$meta_class = 'Conexao_Data_Model_Meta';

assert_equals( '53.3498', $meta_class::sanitize_latitude( '53.3498' ), 'a valid latitude is preserved' );
assert_equals( '-6.2603', $meta_class::sanitize_longitude( '-6.2603' ), 'a valid negative longitude is preserved' );
assert_equals( '0', $meta_class::sanitize_latitude( '0' ), 'zero is a VALID coordinate (not treated as missing)' );
assert_equals( '0', $meta_class::sanitize_longitude( '0' ), 'zero longitude is a VALID coordinate' );
assert_equals( '53.5', $meta_class::sanitize_latitude( '53,5' ), 'a comma decimal separator is normalised' );
assert_equals( '53.5', $meta_class::sanitize_latitude( '+53.5' ), 'a leading plus sign is accepted' );

assert_equals( '', $meta_class::sanitize_latitude( '' ), 'an empty latitude stays empty (missing, never 0)' );
assert_equals( '', $meta_class::sanitize_latitude( '   ' ), 'a whitespace-only latitude is treated as missing' );
assert_equals( '', $meta_class::sanitize_latitude( null ), 'a null latitude is treated as missing' );
assert_equals( '', $meta_class::sanitize_latitude( '91' ), 'a latitude above 90 is rejected' );
assert_equals( '', $meta_class::sanitize_latitude( '-91' ), 'a latitude below -90 is rejected' );
assert_equals( '', $meta_class::sanitize_longitude( '181' ), 'a longitude above 180 is rejected' );
assert_equals( '', $meta_class::sanitize_longitude( '-181' ), 'a longitude below -180 is rejected' );
assert_equals( '', $meta_class::sanitize_latitude( 'abc' ), 'a non-numeric latitude is rejected' );
assert_equals( '', $meta_class::sanitize_latitude( '1.2.3' ), 'a malformed decimal is rejected' );
assert_equals( '', $meta_class::sanitize_latitude( '1e2' ), 'exponent notation is rejected' );
assert_equals( '', $meta_class::sanitize_latitude( array( 1 ) ), 'an array value is rejected' );

assert_true( $meta_class::is_valid_latitude( '90' ), '90 is a valid latitude boundary' );
assert_true( $meta_class::is_valid_latitude( '-90' ), '-90 is a valid latitude boundary' );
assert_true( ! $meta_class::is_valid_latitude( '' ), 'an empty latitude is not valid' );
assert_true( ! $meta_class::is_valid_latitude( '90.0001' ), 'a latitude just above the boundary is invalid' );
assert_true( $meta_class::is_valid_longitude( '180' ), '180 is a valid longitude boundary' );
assert_true( ! $meta_class::is_valid_longitude( '-180.5' ), 'a longitude past the boundary is invalid' );

// ---------------------------------------------------------------------------
test_section( 'Coordinate persistence (write paths are sanitised)' );

$persist_id = lls_test_post(
	array(
		'post_type'   => 'leisure',
		'post_title'  => '[LOCATION TEST] Persistence',
		'post_status' => 'publish',
	)
);
assert_true( $persist_id > 0, 'a temporary leisure record was created' );

update_post_meta( $persist_id, '_leisure_lat', '53.3498' );
update_post_meta( $persist_id, '_leisure_lng', '-6.2603' );
assert_equals( '53.3498', get_post_meta( $persist_id, '_leisure_lat', true ), 'a valid latitude persists through update_post_meta' );

// The registered sanitize_callback is the hard gate on EVERY write path.
update_post_meta( $persist_id, '_leisure_lat', '999' );
assert_equals( '', get_post_meta( $persist_id, '_leisure_lat', true ), 'an out-of-range latitude can never be persisted (sanitised to empty)' );

update_post_meta( $persist_id, '_leisure_lat', '53.3498' );
$coordinates = $meta_class::get_coordinates( $persist_id );
assert_true( is_array( $coordinates ), 'get_coordinates returns a pair for a valid record' );
assert_equals( 53.3498, $coordinates['lat'], 'get_coordinates returns the latitude as a float' );
assert_equals( -6.2603, $coordinates['lng'], 'get_coordinates returns the longitude as a float' );

// ---------------------------------------------------------------------------
test_section( 'Coordinate inventory states' );

$state = $meta_class::coordinate_status( $persist_id );
assert_equals( 'unverified', $state['status'], 'a record with coordinates but no confirmation is "unverified"' );

update_post_meta( $persist_id, '_leisure_coord_verified', 1 );
$state = $meta_class::coordinate_status( $persist_id );
assert_equals( 'verified', $state['status'], 'confirming the coordinates moves the record to "verified"' );

update_post_meta( $persist_id, '_leisure_coord_verified', 0 );
$missing_id = lls_test_post(
	array(
		'post_type'   => 'leisure',
		'post_title'  => '[LOCATION TEST] No coordinates',
		'post_status' => 'publish',
	)
);
$state = $meta_class::coordinate_status( $missing_id );
assert_equals( 'missing', $state['status'], 'a record without coordinates is "missing"' );
assert_true( null === $meta_class::get_coordinates( $missing_id ), 'get_coordinates returns null when coordinates are missing' );

// An invalid stored value (e.g. legacy/imported data written before the
// sanitizer existed) is reported as invalid. The registered sanitize_callback
// prevents creating such a value through any normal write path, so the row is
// written directly to simulate the legacy state the inventory must catch.
global $wpdb;
$wpdb->insert(
	$wpdb->postmeta,
	array(
		'post_id'    => $missing_id,
		'meta_key'   => '_leisure_lat',
		'meta_value' => 'not-a-number',
	)
);
clean_post_cache( $missing_id );

$state = $meta_class::coordinate_status( $missing_id );
assert_equals( 'invalid', $state['status'], 'a stored malformed value is reported as "invalid"' );

$wpdb->delete(
	$wpdb->postmeta,
	array(
		'post_id'  => $missing_id,
		'meta_key' => '_leisure_lat',
	)
);
clean_post_cache( $missing_id );

// ---------------------------------------------------------------------------
test_section( 'Admin editor: coordinate field, save and validation' );

$config = Conexao_Admin_Ux_Config::get( 'leisure' );
assert_true( is_array( $config ) && isset( $config['post_type'] ) && 'leisure' === $config['post_type'], 'the leisure admin configuration is available' );

$lat_field = array( 'key' => '_leisure_lat', 'label' => 'Latitude', 'type' => 'coordinate', 'min' => -90, 'max' => 90 );
$lat_html  = Conexao_Admin_Ux_Fields::render( $lat_field, '53.3498', $persist_id );

assert_contains( 'type="number"', $lat_html, 'the latitude field renders a numeric input' );
assert_contains( 'min="-90"', $lat_html, 'the latitude input carries the -90 lower bound' );
assert_contains( 'max="90"', $lat_html, 'the latitude input carries the 90 upper bound' );
assert_contains( 'value="53.3498"', $lat_html, 'the latitude field displays the saved value' );
assert_contains( 'name="conexao_fields[leisure_lat]"', $lat_html, 'the latitude field posts to the expected meta key' );
assert_contains( 'Latitude', $lat_html, 'the latitude field carries its label' );

$lng_html = Conexao_Admin_Ux_Fields::render( array( 'key' => '_leisure_lng', 'label' => 'Longitude', 'type' => 'coordinate', 'min' => -180, 'max' => 180 ), '-6.2603', $persist_id );
assert_contains( 'min="-180"', $lng_html, 'the longitude input carries the -180 lower bound' );
assert_contains( 'value="-6.2603"', $lng_html, 'the longitude field displays a negative saved value' );

$save_id = lls_test_post(
	array(
		'post_type'   => 'leisure',
		'post_title'  => '[LOCATION TEST] Admin save',
		'post_status' => 'publish',
	)
);

Conexao_Admin_Ux_Fields::save(
	$save_id,
	$config,
	array( 'conexao_fields' => array( 'leisure_lat' => '53.3498', 'leisure_lng' => '-6.2603' ) )
);
assert_equals( '53.3498', get_post_meta( $save_id, '_leisure_lat', true ), 'the admin save path persists a valid latitude' );
assert_equals( '-6.2603', get_post_meta( $save_id, '_leisure_lng', true ), 'the admin save path persists a valid longitude' );

// An invalid submission must PRESERVE the previously valid value.
Conexao_Admin_Ux_Fields::save(
	$save_id,
	$config,
	array( 'conexao_fields' => array( 'leisure_lat' => '999', 'leisure_lng' => '-6.2603' ) )
);
assert_equals( '53.3498', get_post_meta( $save_id, '_leisure_lat', true ), 'an invalid latitude submission preserves the stored value' );

// An explicitly emptied field clears the coordinate.
Conexao_Admin_Ux_Fields::save(
	$save_id,
	$config,
	array( 'conexao_fields' => array( 'leisure_lat' => '', 'leisure_lng' => '' ) )
);
assert_equals( '', get_post_meta( $save_id, '_leisure_lat', true ), 'an explicitly emptied latitude clears the meta' );
assert_equals( '', get_post_meta( $save_id, '_leisure_lng', true ), 'an explicitly emptied longitude clears the meta' );

// Human-readable validation messages.
$errors = Conexao_Admin_Ux_Fields::validate(
	$config,
	array(
		'conexao_fields' => array(
			'leisure_name' => 'Teste',
			'leisure_lat'  => '91',
			'leisure_lng'  => '',
		),
	)
);
$error_text = strtolower( implode( ' | ', $errors ) );
assert_contains( 'latitude', $error_text, 'an out-of-range latitude produces a specific validation message' );
assert_contains( 'conjunto', $error_text, 'a half-supplied coordinate pair produces a pairing message' );

$errors = Conexao_Admin_Ux_Fields::validate(
	$config,
	array(
		'conexao_fields' => array(
			'leisure_name' => 'Teste',
			'leisure_lat'  => '53.3498',
			'leisure_lng'  => '-6.2603',
		),
	)
);
assert_equals( array(), $errors, 'a valid coordinate pair produces no validation errors' );

// ---------------------------------------------------------------------------
test_section( 'REST: coordinate dataset endpoint' );

$county_slug = lls_test_term( 'Location Test County', 'lls-test-county', 'conexao_county' );
$cat_slug    = lls_test_term( 'Location Test Category', 'lls-test-category', 'conexao_category' );
$attr_slug   = lls_test_term( 'Location Test Attribute', 'lls-test-attribute', 'conexao_leisure_attribute' );

$alpha_id = lls_test_post(
	array(
		'post_type'   => 'leisure',
		'post_title'  => '[LOCATION TEST] Alpha',
		'post_status' => 'publish',
	)
);
$beta_id = lls_test_post(
	array(
		'post_type'   => 'leisure',
		'post_title'  => '[LOCATION TEST] Beta',
		'post_status' => 'publish',
	)
);
$draft_id = lls_test_post(
	array(
		'post_type'   => 'leisure',
		'post_title'  => '[LOCATION TEST] Draft',
		'post_status' => 'draft',
	)
);
$job_id = lls_test_post(
	array(
		'post_type'   => 'job',
		'post_title'  => '[LOCATION TEST] Job',
		'post_status' => 'publish',
	)
);

update_post_meta( $alpha_id, '_leisure_lat', '53.3498' );
update_post_meta( $alpha_id, '_leisure_lng', '-6.2603' );
wp_set_object_terms( $alpha_id, array( $county_slug ), 'conexao_county', false );
wp_set_object_terms( $alpha_id, array( $cat_slug ), 'conexao_category', false );
wp_set_object_terms( $alpha_id, array( $attr_slug ), 'conexao_leisure_attribute', false );
wp_set_object_terms( $beta_id, array( $cat_slug ), 'conexao_category', false );

$response = lls_test_rest( '/leisure-coordinates' );
assert_true( ! is_wp_error( $response ), 'the coordinate dataset endpoint answers without error' );
assert_equals( 200, (int) $response->get_status(), 'the coordinate dataset endpoint answers 200' );

$dataset_ids = lls_test_item_ids( $response );
assert_true( in_array( $alpha_id, $dataset_ids, true ), 'a published attraction with coordinates is returned' );
assert_true( in_array( $beta_id, $dataset_ids, true ), 'a published attraction WITHOUT coordinates is still returned' );
assert_true( ! in_array( $draft_id, $dataset_ids, true ), 'a draft attraction is never returned' );
assert_true( ! in_array( $job_id, $dataset_ids, true ), 'a non-leisure record is never returned' );

$alpha_item = lls_test_item( $response, $alpha_id );
$beta_item  = lls_test_item( $response, $beta_id );

assert_equals( 53.3498, (float) $alpha_item['lat'], 'the dataset carries the attraction latitude' );
assert_equals( -6.2603, (float) $alpha_item['lng'], 'the dataset carries the attraction longitude' );
assert_true( null === $beta_item['lat'] && null === $beta_item['lng'], 'an attraction without coordinates is represented with null lat/lng' );
assert_true( isset( $alpha_item['index'], $beta_item['index'] ), 'each dataset entry carries its original archive position' );

$alpha_keys = array_keys( (array) $alpha_item );
assert_true( ! in_array( 'verified', $alpha_keys, true ), 'the dataset never exposes the verification flag' );
assert_true( ! in_array( 'source', $alpha_keys, true ), 'the dataset never exposes the coordinate source' );
assert_true( ! in_array( 'coord_source', $alpha_keys, true ), 'the dataset never exposes editorial provenance' );

// Filter scope.
$county_ids = lls_test_item_ids( lls_test_rest( '/leisure-coordinates', array( 'county' => $county_slug ) ) );
assert_true( in_array( $alpha_id, $county_ids, true ), 'the county filter keeps the matching attraction' );
assert_true( ! in_array( $beta_id, $county_ids, true ), 'the county filter drops the non-matching attraction' );

$cat_ids = lls_test_item_ids( lls_test_rest( '/leisure-coordinates', array( 'categoria' => $cat_slug ) ) );
assert_true( in_array( $alpha_id, $cat_ids, true ) && in_array( $beta_id, $cat_ids, true ), 'the category filter keeps every matching attraction' );

$attr_ids = lls_test_item_ids( lls_test_rest( '/leisure-coordinates', array( 'atributo' => $attr_slug ) ) );
assert_true( in_array( $alpha_id, $attr_ids, true ), 'the attribute filter keeps the matching attraction' );
assert_true( ! in_array( $beta_id, $attr_ids, true ), 'the attribute filter drops the non-matching attraction' );

$unknown_ids = lls_test_item_ids( lls_test_rest( '/leisure-coordinates', array( 'county' => 'lls-no-such-county' ) ) );
assert_true( ! in_array( $alpha_id, $unknown_ids, true ), 'an unknown filter slug matches nothing' );

// The dataset ordering must mirror the archive ordering.
$matching_ids = conexao_leisure_location_matching_ids( array( 'lang' => '' ) );
assert_true( array_slice( $matching_ids, 0, 1 ) === array_slice( $dataset_ids, 0, 1 ), 'the dataset order starts with the same attraction as the archive query' );

// ---------------------------------------------------------------------------
test_section( 'REST: ordered card endpoint' );

$cards = lls_test_rest( '/leisure-cards', array( 'ids' => $alpha_id . ',' . $beta_id ) );
assert_true( ! is_wp_error( $cards ), 'the card endpoint answers without error' );

$cards_data = $cards->get_data();
assert_equals( array( $alpha_id, $beta_id ), array_map( 'intval', $cards_data['ids'] ), 'the card endpoint preserves the requested order' );
assert_equals( 2, (int) $cards_data['count'], 'the card endpoint reports the rendered count' );
assert_contains( 'id="post-' . $alpha_id . '"', $cards_data['html'], 'the card HTML is the real leisure card template' );
assert_contains( 'id="post-' . $beta_id . '"', $cards_data['html'], 'every requested attraction is rendered' );

$reversed_data = lls_test_rest( '/leisure-cards', array( 'ids' => $beta_id . ',' . $alpha_id ) )->get_data();
assert_equals( array( $beta_id, $alpha_id ), array_map( 'intval', $reversed_data['ids'] ), 'a reversed request is returned reversed (the order is the caller\'s)' );

$duplicate_data = lls_test_rest( '/leisure-cards', array( 'ids' => $alpha_id . ',' . $alpha_id ) )->get_data();
assert_equals( array( $alpha_id ), array_map( 'intval', $duplicate_data['ids'] ), 'duplicate IDs are de-duplicated' );

// Out-of-scope records are dropped, never rendered.
$job_response = lls_test_rest( '/leisure-cards', array( 'ids' => (string) $job_id ) );
assert_true( ! is_wp_error( $job_response ), 'a non-leisure ID is handled safely' );
assert_equals( 0, (int) $job_response->get_data()['count'], 'a non-leisure ID renders nothing' );
assert_equals( '', $job_response->get_data()['html'], 'a non-leisure ID produces no HTML' );

$draft_data = lls_test_rest( '/leisure-cards', array( 'ids' => (string) $draft_id ) )->get_data();
assert_equals( 0, (int) $draft_data['count'], 'a draft attraction is never rendered' );

// The active filter scope is enforced on the card endpoint too.
$filtered_data = lls_test_rest( '/leisure-cards', array( 'ids' => $alpha_id . ',' . $beta_id, 'county' => $county_slug ) )->get_data();
assert_equals( array( $alpha_id ), array_map( 'intval', $filtered_data['ids'] ), 'the card endpoint honours the active filter scope' );

// Invalid IDs are rejected with a 400.
foreach ( array( 'abc', '0', '-1', '1,abc' ) as $bad_ids ) {
	$bad_response = lls_test_rest( '/leisure-cards', array( 'ids' => $bad_ids ) );
	assert_equals( 400, lls_test_status( $bad_response ), 'an invalid IDs parameter is rejected with 400: ' . $bad_ids );
	assert_true( '' !== lls_test_code( $bad_response ), 'the invalid IDs rejection carries a machine-readable code: ' . $bad_ids );
}

// The batch size is bounded.
$oversized = lls_test_rest( '/leisure-cards', array( 'ids' => implode( ',', range( 1, 51 ) ) ) );
assert_equals( 400, lls_test_status( $oversized ), 'a batch above the maximum size is rejected with 400' );

$at_limit = lls_test_rest( '/leisure-cards', array( 'ids' => implode( ',', range( 1, 50 ) ) ) );
assert_equals( 200, lls_test_status( $at_limit ), 'a batch exactly at the maximum size is accepted' );

// ---------------------------------------------------------------------------
test_section( 'Cleanup' );

foreach ( $created_posts as $post_id ) {
	wp_delete_post( $post_id, true );
}
foreach ( $created_terms as $taxonomy => $term_ids ) {
	foreach ( $term_ids as $term_id ) {
		wp_delete_term( $term_id, $taxonomy );
	}
}

$leftover = get_posts(
	array(
		'post_type'   => 'any',
		'post_status' => 'any',
		's'           => '[LOCATION TEST]',
		'numberposts' => 10,
		'fields'      => 'ids',
	)
);
assert_true( empty( $leftover ), 'no temporary test posts remain' );

test_finish();
