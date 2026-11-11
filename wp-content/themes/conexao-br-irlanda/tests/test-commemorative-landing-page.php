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

test_section( 'Both languages render while the event is active' );

/*
 * Regression (`2026-11-06-christmas-landing-page-active-dispatch-fix`).
 *
 * With Christmas inside its window the landing route used to resolve the
 * event-driven copy of `landing_page` (page_id 0), so the shared shell returned
 * early and `/natal/` and `/en/christmas/` rendered nothing.
 */

/**
 * Switch the Polylang language context the way the frontend router does.
 *
 * @param string $slug Language slug.
 * @return string|false Previous slug.
 */
function conexao_landing_test_language( $slug ) {
	if ( ! function_exists( 'PLL' ) || ! PLL() ) {
		return false;
	}

	$previous      = isset( PLL()->curlang->slug ) ? PLL()->curlang->slug : false;
	PLL()->curlang = PLL()->model->get_language( $slug );

	return $previous;
}

/**
 * Put one page on the main query as the queried landing route.
 *
 * @param WP_Post $page Queried page.
 * @return void
 */
function conexao_landing_test_route( WP_Post $page ) {
	$query = $GLOBALS['wp_query'];

	$query->is_home           = false;
	$query->is_page           = true;
	$query->is_singular       = true;
	$query->is_archive        = false;
	$query->is_search         = false;
	$query->is_404            = false;
	$query->queried_object    = $page;
	$query->queried_object_id = (int) $page->ID;

	conexao_commemorative_flush_plan();
}

/**
 * Build the dispatch plan for the current route and install it for this request.
 *
 * @param string $clock ISO local time.
 * @return array
 */
function conexao_landing_test_plan( $clock ) {
	$plan = conexao_commemorative_build_plan( new DateTimeImmutable( $clock, wp_timezone() ) );

	$GLOBALS['conexao_ce_plan'] = $plan;

	return $plan;
}


$landing_routes = array(
	'pt' => array(
		'page'         => $pt_page,
		'own_copy'     => 'Dois céus, uma mesma estrela',
		'foreign_copy' => 'Two skies, one star',
	),
	'en' => array(
		'page'         => $en_page,
		'own_copy'     => 'Two skies, one star',
		'foreign_copy' => 'Dois céus, uma mesma estrela',
	),
);

$landing_original_language = isset( PLL()->curlang->slug ) ? PLL()->curlang->slug : 'pt';

foreach ( $landing_routes as $landing_lang => $landing_route ) {
	conexao_landing_test_language( $landing_lang );
	conexao_landing_test_route( $landing_route['page'] );

	$landing_page_id = (int) $landing_route['page']->ID;
	$active_plan     = conexao_landing_test_plan( '2026-12-15 12:00:00' );
	$active_context  = conexao_commemorative_placement_context( 'landing_page' );

	assert_equals( $landing_lang, conexao_current_language_slug(), $landing_lang . ': the language context is the route language' );
	assert_true( null !== $active_plan['event'] && 'christmas' === $active_plan['event']->event_key, $landing_lang . ': christmas is active at the clock' );
	assert_true( ! empty( $active_plan['landing'] ), $landing_lang . ': the page opt-in resolves its landing entry while the event is active' );
	assert_true( null !== $active_context, $landing_lang . ': the landing placement resolves a context' );
	assert_equals( 'landing', $active_context['source'], $landing_lang . ': the context comes from the page, not from the event' );
	assert_equals( $landing_page_id, (int) $active_context['page_id'], $landing_lang . ': the context carries this language page id' );

	ob_start();
	$active_rendered = conexao_commemorative_render_placement( 'landing_page' );
	$active_markup   = (string) ob_get_clean();

	assert_true( $active_rendered, $landing_lang . ': the landing placement renders' );
	assert_true( '' !== trim( $active_markup ), $landing_lang . ': the landing placement is not blank' );
	assert_equals( 1, substr_count( $active_markup, 'ce-landing__title' ), $landing_lang . ': the landing hero renders exactly once' );
	assert_contains( 'data-ce-landing="' . $landing_page_id . '"', $active_markup, $landing_lang . ': the shell is attributed to the queried page' );
	assert_not_contains( 'data-ce-welcome', $active_markup, $landing_lang . ': the landing route never renders the homepage welcome' );
	assert_not_contains( 'data-preview="1"', $active_markup, $landing_lang . ': a plain landing request is not a preview' );

	preg_match( '/<h1 class="ce-landing__title">([^<]*)<\/h1>/', $active_markup, $landing_heading );

	assert_equals(
		get_the_title( $landing_route['page'] ),
		isset( $landing_heading[1] ) ? $landing_heading[1] : '',
		$landing_lang . ': the landing hero uses the queried page title'
	);

	preg_match_all( '/id="(ce-module-[a-z\-]+)"/', $active_markup, $landing_module_ids );

	$landing_modules = isset( $landing_module_ids[1] ) ? $landing_module_ids[1] : array();

	assert_true( count( $landing_modules ) >= 3, $landing_lang . ': at least three landing modules render' );
	assert_equals( count( $landing_modules ), count( array_unique( $landing_modules ) ), $landing_lang . ': no landing module renders twice' );
	assert_contains( $landing_route['own_copy'], $active_markup, $landing_lang . ': the page copy is in the route language' );
	assert_not_contains( $landing_route['foreign_copy'], $active_markup, $landing_lang . ': the other language copy never leaks in' );
}

conexao_landing_test_language( $landing_original_language );
conexao_commemorative_flush_plan();


test_section( 'An unrelated page out of season stays commemorative-free' );

if ( $guide_page instanceof WP_Post ) {
	conexao_landing_test_route( $guide_page );

	$plain_plan = conexao_landing_test_plan( '2027-03-01 12:00:00' );

	assert_true( empty( $plain_plan['placements'] ), 'no event placement is planned on an unrelated page out of season' );
	assert_true( empty( $plain_plan['landing'] ), 'an unrelated page never resolves a landing placement' );
	assert_true( ! conexao_commemorative_has_dispatch(), 'no commemorative dispatch happens on an unrelated page out of season' );

	$plain_assets = conexao_commemorative_asset_plan( $plain_plan );

	assert_equals( array(), array_keys( $plain_assets['styles'] ), 'no package stylesheet is planned on an unrelated page' );
	assert_equals( array(), array_keys( $plain_assets['scripts'] ), 'no package script is planned on an unrelated page' );

	ob_start();
	$plain_rendered = conexao_commemorative_render_placement( 'landing_page' );
	$plain_markup   = (string) ob_get_clean();

	assert_true( ! $plain_rendered && '' === $plain_markup, 'the landing placement outputs nothing on an unrelated page' );
}

test_section( 'Preview authorisation is unchanged' );

if ( function_exists( 'conexao_commemorative_is_preview' ) ) {
	assert_true( ! conexao_commemorative_is_preview(), 'a plain landing request is not an authorised preview' );

	ob_start();
	conexao_commemorative_preview_banner();
	$preview_banner = (string) ob_get_clean();

	assert_equals( '', $preview_banner, 'no preview banner is printed without authorisation' );
}

conexao_commemorative_flush_plan();


test_finish( 'commemorative-landing-page' );

