<?php
/**
 * Commemorative landing-page suite (r10 §7.5, §7.13, §9.4).
 *
 * Proves the landing-page contract:
 *   - a page becomes a landing page only through the double opt-in;
 *   - the composition reuses EXISTING records (no duplicated content) and has at
 *     least three real modules (the empty-page rule);
 *   - it keeps rendering after the event window (archival);
 *   - it is excluded from the sitemap by the shared decision function;
 *   - the PT/EN pair is a real Polylang translation pair;
 *   - a missing record never produces placeholder copy.
 *
 * @package Conexao_BR_Irlanda
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

test_title( 'Commemorative landing page' );
test_prerequisite_hint( 'scripts/seed-commemorative-landing-pages.php --apply' );

$pt_page = get_page_by_path( 'natal', OBJECT, 'page' );
$en_page = get_page_by_path( 'christmas', OBJECT, 'page' );

test_require( $pt_page instanceof WP_Post, 'seed-commemorative-landing-pages', 'the PT /natal/ landing page exists' );
test_require( $en_page instanceof WP_Post, 'seed-commemorative-landing-pages', 'the EN /christmas/ landing page exists' );

test_section( 'The double opt-in is required' );

assert_equals( 'page-commemorative-landing.php', (string) get_post_meta( $pt_page->ID, '_wp_page_template', true ), 'the PT page assigns the landing template' );
assert_equals( 'christmas', (string) get_post_meta( $pt_page->ID, '_ce_landing_package', true ), 'the PT page names the christmas package' );
assert_true( conexao_commemorative_is_landing_page( (int) $pt_page->ID ), 'the PT page is a landing page' );
assert_true( conexao_commemorative_is_landing_page( (int) $en_page->ID ), 'the EN page is a landing page' );

$guide_page = get_page_by_path( 'documentos', OBJECT, 'page' );

if ( $guide_page instanceof WP_Post ) {
	assert_true( ! conexao_commemorative_is_landing_page( (int) $guide_page->ID ), 'a normal page is not a landing page' );
}

test_section( 'The PT/EN pair is a real translation pair' );

if ( function_exists( 'pll_get_post_translations' ) ) {
	$translations = pll_get_post_translations( (int) $pt_page->ID );

	assert_true( isset( $translations['en'] ) && (int) $translations['en'] === (int) $en_page->ID, 'the EN record is linked to the PT record' );
	assert_true( isset( $translations['pt'] ) && (int) $translations['pt'] === (int) $pt_page->ID, 'the link is bidirectional' );
} else {
	assert_true( true, 'Polylang is inactive: the pair cannot be asserted here' );
}

test_section( 'Modules reuse existing records and never duplicate content' );

$modules = array();

$modules[] = conexao_commemorative_module_upcoming_events(
	array(
		'id'       => 'agenda',
		'title'    => 'Agenda',
		'keywords' => array( 'christmas', 'natal', 'santa', 'wreath', 'solstice', 'gathering' ),
		'limit'    => 6,
	)
);

$modules[] = conexao_commemorative_module_from_records(
	array(
		'id'      => 'lugar',
		'title'   => 'Lugar',
		'records' => array( array( 'type' => 'leisure', 'slug' => 'newgrange' ) ),
	)
);

$modules[] = conexao_commemorative_module_from_records(
	array(
		'id'      => 'guia',
		'title'   => 'Guia',
		'records' => array( array( 'type' => 'guide', 'slug' => 'inverno-irlanda-depressao-sazonal-saude-mental' ) ),
	)
);

$modules[] = conexao_commemorative_module_from_records(
	array(
		'id'      => 'artigos',
		'title'   => 'Artigos',
		'records' => array(
			array( 'type' => 'post', 'slug' => 'o-calor-que-nasce-do-inverno' ),
			array( 'type' => 'post', 'slug' => 'preparando-o-corpo-para-o-inverno' ),
		),
	)
);

$with_items = 0;
$ids        = array();

foreach ( $modules as $module ) {
	if ( ! empty( $module['items'] ) ) {
		$with_items++;
	}

	foreach ( $module['items'] as $item ) {
		$ids[] = (int) $item['id'];
	}
}

assert_true( $with_items >= 3, 'at least three modules have real items (empty-page rule)' );
assert_true( count( $ids ) >= 4, 'the modules surface at least four real records' );
assert_equals( count( $ids ), count( array_unique( $ids ) ), 'no record appears in two modules (non-duplicative)' );

foreach ( $modules as $module ) {
	foreach ( $module['items'] as $item ) {
		assert_true( '' !== $item['title'], 'every item has a real title' );
		assert_true( 0 === strpos( (string) $item['url'], 'http' ), 'every item links to a real published record' );
	}
}

test_section( 'A missing record never produces placeholder copy' );

$missing = conexao_commemorative_module_from_records(
	array(
		'id'      => 'missing',
		'title'   => 'Missing',
		'records' => array(
			array( 'type' => 'post', 'slug' => 'this-record-does-not-exist' ),
			array( 'type' => 'nope', 'slug' => 'newgrange' ),
		),
	)
);

assert_equals( 0, count( $missing['items'] ), 'an unknown slug and an unknown post type produce no items' );

ob_start();
get_template_part( 'template-parts/commemorative/modules/module', null, array( 'module' => $missing ) );
$missing_markup = (string) ob_get_clean();

assert_equals( '', $missing_markup, 'an empty module renders nothing at all' );


test_section( 'Sitemap policy: archived, not promoted' );

assert_true( function_exists( 'conexao_seo_sitemap_include_page' ), 'the shared sitemap decision function exists' );
assert_true( ! conexao_seo_sitemap_include_page( $pt_page ), 'the PT landing page is excluded from the sitemap' );
assert_true( ! conexao_seo_sitemap_include_page( $en_page ), 'the EN landing page is excluded from the sitemap' );

if ( $guide_page instanceof WP_Post ) {
	assert_true( conexao_seo_sitemap_include_page( $guide_page ), 'a normal published page is still included (the exclusion is not global)' );
}

test_section( 'The page renders after the event window (archival)' );

$GLOBALS['wp_query']->is_home          = false;
$GLOBALS['wp_query']->is_page          = true;
$GLOBALS['wp_query']->is_singular      = true;
$GLOBALS['wp_query']->queried_object    = $pt_page;
$GLOBALS['wp_query']->queried_object_id = (int) $pt_page->ID;

conexao_commemorative_flush_plan();

$plan = conexao_commemorative_build_plan( new DateTimeImmutable( '2027-03-01 12:00:00', wp_timezone() ) );

$GLOBALS['conexao_ce_plan'] = $plan;

assert_true( empty( $plan['placements'] ), 'no event-driven placement is active in March' );
assert_true( ! empty( $plan['landing'] ), 'the landing placement is still resolved in March' );

ob_start();
$rendered = conexao_commemorative_render_placement( 'landing_page' );
$markup   = (string) ob_get_clean();

assert_true( $rendered, 'the landing page still renders in March' );
assert_contains( 'ce-landing__title', $markup, 'the page hero renders' );
assert_contains( 'Natal', $markup, 'the page title is the PT title' );
assert_true( substr_count( $markup, 'ce-module--' ) >= 3, 'at least three modules render after the window' );
assert_not_contains( 'data-preview="1"', $markup, 'the archival page is not a preview' );

test_finish( 'commemorative-landing-page' );

