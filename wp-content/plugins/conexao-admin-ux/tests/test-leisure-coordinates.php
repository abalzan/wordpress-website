<?php
/**
 * Tests for the Lazer coordinate fields in the admin UX.
 *
 * Exercises the real API the editor uses (Conexao_Admin_Ux_Config +
 * Conexao_Admin_Ux_Fields) so the rendered control, the save path and the
 * validation messages are covered end to end:
 *
 *   - the leisure config declares latitude/longitude + provenance fields in
 *     the "Localização e contato" section and a coordinate list column;
 *   - the `coordinate` field renders a numeric input with the geographic
 *     range and the stored value;
 *   - a valid pair persists; an INVALID submission preserves the previously
 *     stored value instead of wiping it; an emptied field clears it;
 *   - out-of-range and half-supplied pairs produce specific messages.
 *
 * Creates one temporary leisure post and deletes it at the end.
 *
 * Usage (from the project root):
 *   docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-admin-ux/tests/test-leisure-coordinates.php
 *
 * @package Conexao_Admin_Ux
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$passed    = 0;
$failed    = 0;
$test_post = 0;

test_title( 'Lazer coordinate admin fields' );

// ---------------------------------------------------------------------------
test_section( 'Config: fields and list column' );

$config = Conexao_Admin_Ux_Config::get( 'leisure' );
assert_true( is_array( $config ), 'the leisure config is available' );

$fields = Conexao_Admin_Ux_Fields::collect_fields( $config );
$by_key = array();
foreach ( $fields as $field ) {
	$by_key[ $field['key'] ] = $field;
}

assert_true( isset( $by_key['_leisure_lat'] ) && 'coordinate' === $by_key['_leisure_lat']['type'], 'latitude is a coordinate field' );
assert_true( isset( $by_key['_leisure_lng'] ) && 'coordinate' === $by_key['_leisure_lng']['type'], 'longitude is a coordinate field' );
assert_equals( -90, $by_key['_leisure_lat']['min'], 'the latitude field declares the -90 minimum' );
assert_equals( 90, $by_key['_leisure_lat']['max'], 'the latitude field declares the 90 maximum' );
assert_equals( -180, $by_key['_leisure_lng']['min'], 'the longitude field declares the -180 minimum' );
assert_equals( 180, $by_key['_leisure_lng']['max'], 'the longitude field declares the 180 maximum' );
assert_true( isset( $by_key['_leisure_coord_verified'] ) && 'checkbox' === $by_key['_leisure_coord_verified']['type'], 'coordinate verification is a checkbox' );
assert_true( isset( $by_key['_leisure_coord_source'] ), 'the coordinate source field exists' );
assert_true( isset( $config['columns']['coordinates'] ), 'the leisure list declares a coordinate inventory column' );

// The coordinate fields live in the existing location section (no new screen).
$location_keys = array();
foreach ( $config['sections']['localizacao']['fields'] as $field ) {
	$location_keys[] = $field['key'];
}
assert_true( in_array( '_leisure_lat', $location_keys, true ), 'latitude sits in the existing location section' );
assert_true( in_array( '_leisure_lng', $location_keys, true ), 'longitude sits in the existing location section' );

// ---------------------------------------------------------------------------
test_section( 'Field rendering' );

$lat_html = Conexao_Admin_Ux_Fields::render( $by_key['_leisure_lat'], '53.3498', 0 );
assert_contains( 'type="number"', $lat_html, 'the latitude control is a numeric input' );
assert_contains( 'min="-90"', $lat_html, 'the latitude control carries the lower bound' );
assert_contains( 'max="90"', $lat_html, 'the latitude control carries the upper bound' );
assert_contains( 'value="53.3498"', $lat_html, 'the latitude control shows the stored value' );
assert_contains( 'name="conexao_fields[leisure_lat]"', $lat_html, 'the latitude control posts to the meta key' );

$lng_html = Conexao_Admin_Ux_Fields::render( $by_key['_leisure_lng'], '-6.2603', 0 );
assert_contains( 'min="-180"', $lng_html, 'the longitude control carries the lower bound' );
assert_contains( 'value="-6.2603"', $lng_html, 'the longitude control shows a negative value' );

// ---------------------------------------------------------------------------
test_section( 'Save + validation' );

$test_post = wp_insert_post(
	array(
		'post_type'   => 'leisure',
		'post_title'  => '[TEST] Coordinate admin fields',
		'post_status' => 'publish',
	),
	true
);
assert_true( ! is_wp_error( $test_post ) && $test_post > 0, 'a temporary leisure post was created' );

Conexao_Admin_Ux_Fields::save( $test_post, $config, array( 'conexao_fields' => array( 'leisure_lat' => '53.3498', 'leisure_lng' => '-6.2603' ) ) );
assert_equals( '53.3498', get_post_meta( $test_post, '_leisure_lat', true ), 'a valid latitude is saved' );
assert_equals( '-6.2603', get_post_meta( $test_post, '_leisure_lng', true ), 'a valid longitude is saved' );

Conexao_Admin_Ux_Fields::save( $test_post, $config, array( 'conexao_fields' => array( 'leisure_lat' => '900', 'leisure_lng' => '-6.2603' ) ) );
assert_equals( '53.3498', get_post_meta( $test_post, '_leisure_lat', true ), 'an invalid latitude never overwrites the stored value' );

Conexao_Admin_Ux_Fields::save( $test_post, $config, array( 'conexao_fields' => array( 'leisure_lat' => '', 'leisure_lng' => '' ) ) );
assert_equals( '', get_post_meta( $test_post, '_leisure_lat', true ), 'an emptied latitude clears the meta' );
assert_equals( '', get_post_meta( $test_post, '_leisure_lng', true ), 'an emptied longitude clears the meta' );

$errors = Conexao_Admin_Ux_Fields::validate( $config, array( 'conexao_fields' => array( 'leisure_name' => 'Teste', 'leisure_lat' => '91', 'leisure_lng' => '' ) ) );
$text   = strtolower( implode( ' ', $errors ) );
assert_contains( 'latitude', $text, 'an out-of-range latitude produces a specific message' );
assert_contains( 'conjunto', $text, 'a half-supplied pair produces a pairing message' );

$errors = Conexao_Admin_Ux_Fields::validate( $config, array( 'conexao_fields' => array( 'leisure_name' => 'Teste', 'leisure_lat' => '53.3498', 'leisure_lng' => '-6.2603' ) ) );
assert_equals( array(), $errors, 'a valid pair produces no validation errors' );

// ---------------------------------------------------------------------------
if ( $test_post ) {
	wp_delete_post( $test_post, true );
}

$leftover = get_posts(
	array(
		'post_type'   => 'leisure',
		'post_status' => 'any',
		's'           => '[TEST] Coordinate admin fields',
		'numberposts' => 5,
		'fields'      => 'ids',
	)
);
assert_true( empty( $leftover ), 'no temporary test post remains' );

test_finish();
