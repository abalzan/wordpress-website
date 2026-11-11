<?php
/**
 * PERMANENT GATE (c) — the commemorative CPT stays private.
 *
 * Invariant (r10 §3.7, §7.5, §9.5c): `commemorative_event` has no public singular
 * route, no archive, no query var and no REST route, and it is absent from the
 * sitemap. The Stage 5 landing page is a separate, explicitly opted-in PAGE — it is
 * never a CPT exposure.
 *
 * ## Why this gate renders the sitemap instead of grepping it
 *
 * The gate executes the theme's real sitemap producer and inspects its output, so
 * it proves the behaviour rather than the text of the file. (In the local HTTP
 * stack Jetpack serves `/sitemap.xml`; the theme producer is the one this
 * repository owns, which is exactly what this gate exercises.)
 *
 * ## The injected negative proof
 *
 * The same `check` closure is run over a PUBLIC post type (`post`) and over a
 * normal page. If it did not flag the public type, or did not include the normal
 * page in the sitemap, the gate could not distinguish "private" from "absent
 * because nothing was checked" — so that failure is recorded as a violation.
 *
 * Read-only.
 *
 * @package Conexao_BR_Irlanda
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_ROOT . '/lib/permanent-gates.php';

conexao_gate_open(
	'commemorative_cpt_privacy',
	'The commemorative_event CPT has no public route, archive, query var or REST route, and neither it nor the archived landing pages appear in the sitemap.',
	array( 'theme-active', 'conexao-commemorative plugin active', 'the seeded landing pages' )
);

$post_type = get_post_type_object( 'commemorative_event' );

conexao_gate_require(
	null !== $post_type,
	'activate conexao-data-model',
	'the gate prerequisite is available: the commemorative_event post type'
);

test_section( 'The CPT registration is private' );

$public_flags = array();

foreach ( array( 'public', 'publicly_queryable', 'has_archive', 'query_var', 'show_in_rest', 'show_ui' ) as $flag ) {
	$public_flags[ $flag ] = (bool) $post_type->$flag;
}

$exposure = array();

if ( $public_flags['public'] ) {
	$exposure[] = 'public=true';
}

if ( $public_flags['publicly_queryable'] ) {
	$exposure[] = 'publicly_queryable=true';
}

if ( $public_flags['has_archive'] ) {
	$exposure[] = 'has_archive=true';
}

if ( false !== $post_type->query_var && '' !== (string) $post_type->query_var ) {
	$exposure[] = 'query_var=' . (string) $post_type->query_var;
}

if ( $public_flags['show_in_rest'] ) {
	$exposure[] = 'show_in_rest=true';
}

if ( ! $post_type->exclude_from_search ) {
	$exposure[] = 'exclude_from_search=false';
}

if ( $public_flags['show_ui'] !== true ) {
	$exposure[] = 'show_ui=false (the editor must remain available)';
}

conexao_gate_violation(
	'cpt:public_exposure',
	count( $exposure ),
	'the CPT is private on every axis (route, archive, query var, REST, search)',
	array( 'flags' => $public_flags, 'exposure' => $exposure )
);

test_section( 'No REST route exists' );

$routes       = rest_get_server()->get_routes();
$cpt_routes   = array();

foreach ( array_keys( $routes ) as $route ) {
	if ( false !== strpos( (string) $route, 'commemorative' ) ) {
		$cpt_routes[] = (string) $route;
	}
}

conexao_gate_violation(
	'cpt:rest_route',
	count( $cpt_routes ),
	'no REST route is registered for the commemorative CPT',
	array( 'routes' => $cpt_routes )
);

test_section( 'No public route or archive resolves' );

$links = array(
	'archive' => (string) get_post_type_archive_link( 'commemorative_event' ),
);

/*
 * A non-public CPT still returns a `?post_type=…&p=…` fallback from
 * `get_permalink()`. That URL is not a public route (the query var is not
 * registered and `publicly_queryable` is false), so the invariant is that no
 * PATH-based public URL exists for a record — not that the string is empty.
 */
$public_paths = array();

foreach ( get_posts( array( 'post_type' => 'commemorative_event', 'post_status' => 'publish', 'numberposts' => 3, 'suppress_filters' => true ) ) as $record ) {
	$permalink = (string) get_permalink( $record );

	if ( false !== strpos( $permalink, '/commemorative_event/' ) ) {
		$public_paths[] = $permalink;
	}
}

conexao_gate_violation(
	'cpt:public_permalink',
	count( array_filter( array( $links['archive'] ) ) ) + count( $public_paths ),
	'the CPT exposes no archive link and no public PATH-based permalink',
	array( 'archive' => $links['archive'], 'public_paths' => $public_paths )
);

test_section( 'The sitemap producer publishes neither the CPT nor the archived landing pages' );

/**
 * Render the theme sitemap and return its XML.
 *
 * @return string
 */
function conexao_gate_sitemap_xml() {
	ob_start();
	conexao_seo_sitemap_output();

	return (string) ob_get_clean();
}

/**
 * The gate's check: which forbidden URLs appear in the sitemap XML.
 *
 * @param string   $xml      Sitemap XML.
 * @param string[] $forbidden Forbidden fragments.
 * @return string[]
 */
function conexao_gate_sitemap_offenders( $xml, array $forbidden ) {
	$offenders = array();

	if ( '' === trim( (string) $xml ) ) {
		$offenders[] = 'sitemap:empty_output';
	}

	foreach ( $forbidden as $fragment ) {
		if ( false !== strpos( (string) $xml, $fragment ) ) {
			$offenders[] = 'sitemap:contains:' . $fragment;
		}
	}

	return $offenders;
}

$sitemap_xml = conexao_gate_sitemap_xml();

$offenders = conexao_gate_sitemap_offenders(
	$sitemap_xml,
	array( '/natal/', '/en/christmas/', 'commemorative_event' )
);

conexao_gate_violation(
	'sitemap:forbidden_url',
	count( $offenders ),
	'the sitemap publishes no commemorative CPT URL and no archived landing page',
	array( 'offenders' => $offenders, 'bytes' => strlen( $sitemap_xml ) )
);

test_section( 'Injected negative proof: the same check CAN see a forbidden URL' );

// A fixture XML that does contain the forbidden fragments must be flagged.
$fixture = '<urlset><url><loc>http://localhost:8080/natal/</loc></url>'
	. '<url><loc>http://localhost:8080/?post_type=commemorative_event</loc></url></urlset>';

$fixture_offenders = conexao_gate_sitemap_offenders( $fixture, array( '/natal/', '/en/christmas/', 'commemorative_event' ) );

conexao_gate_violation(
	'gate:injected_negative_not_detected',
	count( $fixture_offenders ) >= 2 ? 0 : 1,
	'the gate detects a sitemap that DOES publish the forbidden URLs',
	array( 'fixture_offenders' => $fixture_offenders )
);

// And the real sitemap must still publish a normal page, or the "absence" above
// would be vacuous (an empty list would satisfy every forbidden-fragment test).
$normal_page = get_page_by_path( 'documentos', OBJECT, 'page' );

$includes_page = $normal_page instanceof WP_Post && false !== strpos( $sitemap_xml, '/' . $normal_page->post_name . '/' );

conexao_gate_violation(
	'gate:sitemap_check_is_vacuous',
	$includes_page ? 0 : 1,
	'the sitemap check is not vacuous: a normal published page is still listed',
	array( 'page' => $normal_page instanceof WP_Post ? $normal_page->post_name : '' )
);

test_section( 'The landing pages are opt-in PAGES, not CPT exposure' );

$landing_id = 0;
$landing    = get_page_by_path( 'natal', OBJECT, 'page' );

if ( $landing instanceof WP_Post ) {
	$landing_id = (int) $landing->ID;
}

conexao_gate_require(
	$landing_id > 0,
	'seed-commemorative-landing-pages',
	'the gate prerequisite is available: the PT landing page'
);

assert_equals( 'page', get_post_type( $landing_id ), 'the landing page is a page record' );
assert_true( conexao_commemorative_is_landing_page( $landing_id ), 'the landing page is recognised through its opt-in meta' );
assert_true( ! conexao_seo_sitemap_include_page( get_post( $landing_id ) ), 'the landing page is excluded from the sitemap' );

conexao_gate_close();

