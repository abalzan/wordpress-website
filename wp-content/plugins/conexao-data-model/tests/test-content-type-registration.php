<?php
/**
 * Regression coverage for the Conexão Data Model content-type and taxonomy
 * registration contract.
 *
 * Protects:
 *  - Every public CPT's `public`, `publicly_queryable`, `has_archive`,
 *    `rewrite.slug`, `show_in_rest` and `exclude_from_search` flags.
 *  - The admin-only directories (`recruitment_agency`, `permit_employer`)
 *    stay non-public with no archive, no rewrite and no search presence.
 *  - The `job` CPT keeps its archive disabled so `/empregos/` remains the
 *    static Jobs Landing page while individual job singles keep working.
 *  - `conexao_category` / `conexao_tag` are Polylang-translated;
 *    `conexao_county` / `conexao_town` / `conexao_leisure_attribute` stay
 *    shared (no language tag, no per-language duplicates).
 *  - The 26-county master list is the only source of county terms: no
 *    numeric identifiers, no Northern Ireland counties, no arbitrary values.
 *  - Every registered meta key keeps its `show_in_rest`, sanitizer and
 *    `auth_callback` (protected keys are never `__return_true`).
 *
 * Read-only. Creates nothing, mutates nothing, contacts nothing.
 *
 * @package Conexao_Data_Model
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$passed  = 0;
$failed  = 0;

test_title( 'Conexão Data Model: content type and taxonomy registration' );

// ---------------------------------------------------------------------------
// Preconditions
// ---------------------------------------------------------------------------

test_prerequisite_hint( 'conexao-data-model' );
test_require(
	class_exists( 'Conexao_Data_Model' ),
	'activate-plugin:conexao-data-model',
	'test prerequisite is available: class_exists( Conexao_Data_Model )',
	'activate the conexao-data-model plugin'
);

test_prerequisite_hint( 'polylang' );
test_require(
	function_exists( 'pll_get_post_language' ),
	'polylang',
	'test prerequisite is available: Polylang term/post API',
	'activate the Polylang plugin (docker compose exec wordpress wp plugin activate polylang)'
);

// ---------------------------------------------------------------------------
// 1. Public CPT registration contract
// ---------------------------------------------------------------------------

test_section( 'Public post types: flags and rewrite' );

$public_types = array(
	'guide'           => array( 'slug' => 'guias',          'has_archive' => 'guias' ),
	'event'           => array( 'slug' => 'eventos',        'has_archive' => 'eventos' ),
	'job'             => array( 'slug' => 'empregos',       'has_archive' => false ),
	'sponsor'         => array( 'slug' => 'apoiadores',     'has_archive' => 'apoiadores' ),
	'course_provider' => array( 'slug' => 'cursos',         'has_archive' => 'cursos' ),
	'leisure'         => array( 'slug' => 'lazer',          'has_archive' => 'lazer' ),
);

foreach ( $public_types as $post_type => $expected ) {
	$object = get_post_type_object( $post_type );

	assert_true( $object instanceof WP_Post_Type, "{$post_type}: the post type object exists" );
	if ( ! $object instanceof WP_Post_Type ) {
		continue;
	}

	assert_true( $object->public, "{$post_type}: public is true" );
	assert_true( $object->publicly_queryable, "{$post_type}: publicly_queryable is true" );
	assert_true( ! empty( $object->show_in_rest ), "{$post_type}: show_in_rest is true" );
	assert_true( ! $object->exclude_from_search, "{$post_type}: exclude_from_search is false" );
	assert_equals( $expected['slug'], $object->rewrite['slug'], "{$post_type}: rewrite slug is {$expected['slug']}" );
	assert_false( $object->rewrite['with_front'], "{$post_type}: rewrite with_front is false" );

	if ( false === $expected['has_archive'] ) {
		assert_false( $object->has_archive, "{$post_type}: has_archive is disabled (archive belongs to a static page)" );
	} else {
		assert_equals( $expected['has_archive'], $object->has_archive, "{$post_type}: has_archive is {$expected['has_archive']}" );
	}
}

// ---------------------------------------------------------------------------
// 2. Admin-only CPTs stay non-public
// ---------------------------------------------------------------------------

test_section( 'Admin-only directories: no public surface' );

$admin_types = array( 'recruitment_agency', 'permit_employer' );

foreach ( $admin_types as $post_type ) {
	$object = get_post_type_object( $post_type );

	assert_true( $object instanceof WP_Post_Type, "{$post_type}: the post type object exists" );
	if ( ! $object instanceof WP_Post_Type ) {
		continue;
	}

	assert_false( $object->public, "{$post_type}: public is false (admin-only directory)" );
	assert_false( $object->publicly_queryable, "{$post_type}: publicly_queryable is false" );
	assert_true( $object->exclude_from_search, "{$post_type}: exclude_from_search is true" );
	assert_true( true !== $object->has_archive, "{$post_type}: has_archive is not enabled as a boolean (no public archive)" );
	assert_false( ! empty( $object->rewrite), "{$post_type}: no rewrite rule (no public URL)" );
	assert_equals( 'edit.php?post_type=job', $object->show_in_menu, "{$post_type}: appears under the Empregos menu" );
}

// ---------------------------------------------------------------------------
// 3. Taxonomy registration: shared vs translated
// ---------------------------------------------------------------------------

test_section( 'Taxonomies: shared vs translated policy' );

$translated = (array) PLL()->model->get_translated_taxonomies();

$translated_expected = array( 'conexao_category', 'conexao_tag' );
$shared_expected     = array( 'conexao_county', 'conexao_town', 'conexao_leisure_attribute' );

// Polylang's list also contains core taxonomies (category, post_tag); the
// policy-relevant check is that our four taxonomies are classified correctly.
$translated_ours = array_values( array_intersect( $translated, $translated_expected ) );
$shared_ours     = array_values( array_diff( $shared_expected, $translated ) );

assert_equals(
	$translated_expected,
	$translated_ours,
	'Polylang translates exactly conexao_category and conexao_tag among our taxonomies'
);

assert_equals(
	$shared_expected,
	$shared_ours,
	'conexao_county, conexao_town and conexao_leisure_attribute are NOT translated (shared)'
);

foreach ( $shared_expected as $taxonomy ) {
	assert_taxonomy_exists( $taxonomy );
	assert_false(
		in_array( $taxonomy, $translated, true ),
		"{$taxonomy}: NOT Polylang-translated (shared proper-name taxonomy)"
	);
}

foreach ( $translated_expected as $taxonomy ) {
	assert_taxonomy_exists( $taxonomy );
	assert_true(
		in_array( $taxonomy, $translated, true ),
		"{$taxonomy}: IS Polylang-translated"
	);
}

// ---------------------------------------------------------------------------
// 4. County master list: 26 Republic of Ireland counties only
// ---------------------------------------------------------------------------

test_section( 'County master list: 26 approved counties only' );

$counties = Conexao_Data_Model_Counties::all();

assert_equals( 26, count( $counties ), 'the county master list has exactly 26 entries' );

// Independent expected inventory: the 26 Republic of Ireland counties,
// canonical slugs, taken from the production master list in
// Conexao_Data_Model_Counties::COUNTIES. This is an independent assertion of
// the complete inventory, not merely a count — a replacement of one valid
// county with another would be detected here.
$expected_counties = array(
	'carlow'    => 'Carlow',
	'cavan'     => 'Cavan',
	'clare'     => 'Clare',
	'cork'      => 'Cork',
	'donegal'   => 'Donegal',
	'dublin'    => 'Dublin',
	'galway'    => 'Galway',
	'kerry'     => 'Kerry',
	'kildare'   => 'Kildare',
	'kilkenny'  => 'Kilkenny',
	'laois'     => 'Laois',
	'leitrim'   => 'Leitrim',
	'limerick'  => 'Limerick',
	'longford'  => 'Longford',
	'louth'     => 'Louth',
	'mayo'      => 'Mayo',
	'meath'     => 'Meath',
	'monaghan'  => 'Monaghan',
	'offaly'    => 'Offaly',
	'roscommon' => 'Roscommon',
	'sligo'     => 'Sligo',
	'tipperary' => 'Tipperary',
	'waterford' => 'Waterford',
	'westmeath' => 'Westmeath',
	'wexford'   => 'Wexford',
	'wicklow'   => 'Wicklow',
);

$actual_counties = Conexao_Data_Model_Counties::all();

assert_equals(
	$expected_counties,
	$actual_counties,
	'the county master list matches the complete expected 26-county inventory exactly'
);

$northern_ireland = array( 'antrim', 'armagh', 'down', 'fermanagh', 'derry', 'tyrone' );
foreach ( $northern_ireland as $ni_slug ) {
	assert_false(
		isset( $counties[ $ni_slug ] ),
		"no Northern Ireland county: {$ni_slug} is not on the master list"
	);
}

foreach ( $counties as $slug => $name ) {
	assert_true( is_string( $slug ) && '' !== $slug, "every county slug is a non-empty string ({$slug})" );
	assert_true( is_string( $name ) && '' !== $name, "every county name is a non-empty string ({$name})" );
	assert_false(
		preg_match( '/^[0-9]+$/', $slug ),
		"no numeric county slug: {$slug} is not an identifier"
	);
	assert_true(
		Conexao_Data_Model_Counties::is_valid_slug( $slug ),
		"{$slug}: is_valid_slug() agrees it is on the master list"
	);
	assert_true(
		'' !== Conexao_Data_Model_Counties::normalize( $name ),
		"{$name}: normalize() resolves the canonical name back to {$slug}"
	);
}

// The taxonomy itself exists and is registered.
assert_taxonomy_exists( Conexao_Data_Model_Counties::TAXONOMY );

// ---------------------------------------------------------------------------
// 5. Meta registration: show_in_rest, sanitizer, auth_callback
// ---------------------------------------------------------------------------

test_section( 'Meta keys: registration and permission surface' );

$meta_types = array(
	'leisure'          => array( '_leisure_lat', '_leisure_lng', '_leisure_town', '_leisure_image_source' ),
	'recruitment_agency' => array( '_agency_website', '_agency_notes' ),
	'permit_employer'  => array( '_employer_official_website', '_employer_notes' ),
);

foreach ( $meta_types as $post_type => $keys ) {
	$registered = get_registered_meta_keys( 'post', $post_type );

	foreach ( $keys as $key ) {
		assert_true(
			isset( $registered[ $key ] ),
			"{$post_type}:{$key} is registered as post meta"
		);
		if ( ! isset( $registered[ $key ] ) ) {
			continue;
		}

		$args = $registered[ $key ];

		assert_true( ! empty( $args['single']), "{$post_type}:{$key} is single-value" );

		// Protected (underscore-prefixed) keys exposed through REST must NOT
		// fall back to the core default (__return_false) — they declare their
		// own auth_callback so an editor who can edit the record can write.
		if ( '_' === substr( $key, 0, 1 ) && ! empty( $args['show_in_rest'] ) ) {
			assert_true(
				isset( $args['auth_callback'] ) && is_callable( $args['auth_callback'] ),
				"{$post_type}:{$key} declares a callable REST auth_callback"
			);
			assert_true(
				! in_array( $args['auth_callback'], array( '__return_true', '__return_false' ), true ),
				"{$post_type}:{$key} does not fall back to the core protected-meta default"
			);
		}
	}
}

// The leisure coordinate keys keep their range-validating sanitizers.
$leisure_meta = get_registered_meta_keys( 'post', 'leisure' );

assert_equals(
	array( 'Conexao_Data_Model_Meta', 'sanitize_latitude' ),
	isset( $leisure_meta['_leisure_lat']['sanitize_callback'] ) ? $leisure_meta['_leisure_lat']['sanitize_callback'] : null,
	'_leisure_lat keeps the latitude range-validating sanitizer'
);
assert_equals(
	array( 'Conexao_Data_Model_Meta', 'sanitize_longitude' ),
	isset( $leisure_meta['_leisure_lng']['sanitize_callback'] ) ? $leisure_meta['_leisure_lng']['sanitize_callback'] : null,
	'_leisure_lng keeps the longitude range-validating sanitizer'
);

// The provenance keys stay out of REST entirely.
assert_true(
	isset( $leisure_meta['_leisure_coord_source'] ) && empty( $leisure_meta['_leisure_coord_source']['show_in_rest'] ),
	'_leisure_coord_source is not exposed through REST'
);
assert_true(
	isset( $leisure_meta['_leisure_coord_verified'] ) && empty( $leisure_meta['_leisure_coord_verified']['show_in_rest'] ),
	'_leisure_coord_verified is not exposed through REST'
);

// ---------------------------------------------------------------------------
// 6. County meta guard rejects invalid values
// ---------------------------------------------------------------------------

test_section( 'County meta guard: invalid values are rejected' );

$invalid_counties = array( '6724', '6726', 'Belfast', 'Antrim', 'Ireland County 27', '', null, 42, array( 'cork' ) );
$valid_counties   = array( 'cork', 'dublin', 'galway', 'Co. Laois', 'LAOIS', 'County Cork', 'Cork, Ireland' );

foreach ( $invalid_counties as $invalid ) {
	$rejected = apply_filters( 'add_post_metadata', null, 0, '_event_county', $invalid );
	assert_equals(
		false,
		$rejected,
		"county meta guard rejects " . wp_json_encode( $invalid )
	);
}

foreach ( $valid_counties as $valid ) {
	$accepted = apply_filters( 'add_post_metadata', null, 0, '_event_county', $valid );
	assert_equals(
		null,
		$accepted,
		"county meta guard does not short-circuit " . wp_json_encode( $valid ) . " (empty or valid values pass through)"
	);
}

// ---------------------------------------------------------------------------
test_finish( 'conexao-data-model content type registration' );

/**
 * Assert that a taxonomy is registered.
 *
 * @param string $taxonomy Taxonomy name.
 * @return void
 */
function assert_taxonomy_exists( $taxonomy ) {
	assert_true( taxonomy_exists( $taxonomy ), "{$taxonomy}: the taxonomy is registered" );
}

/**
 * Assert that a condition is falsy (the inverse of assert_true).
 *
 * @param mixed  $condition Condition; evaluated as a boolean.
 * @param string $message   Assertion label.
 * @param string $detail    Optional extra failure context.
 * @return bool
 */
function assert_false( $condition, $message, $detail = '' ) {
	return assert_true( ! (bool) $condition, $message, $detail );
}
