<?php
/**
 * Header/primary-navigation regression test (menu selection, not markup).
 *
 * Regression fixed in CONEXAO_BR_HEADER_NAVIGATION_REGRESSION_REPORT.md:
 * after the i18n/Polylang work the primary navigation silently fell back to
 * WordPress' automatic page list (wp_page_menu) because
 *   1. Polylang's per-language `nav_menus` option had no entry for the
 *      'primary' location, so its `theme_mod_nav_menu_locations` filter
 *      nullified the plain WordPress assignment for EVERY language, and
 *   2. the theme's wp_nav_menu() calls used `fallback_cb => 'wp_page_menu'`.
 *
 * Covers:
 *  A. PT primary menu — the 'primary' location is registered, has a valid
 *     curated menu, the Polylang per-language assignment exists, and the
 *     canonical render-time nav filters are in place.
 *  B. Fallback safety — the theme must NOT use wp_page_menu() as
 *     wp_nav_menu() fallback; the safe fallback must render nothing.
 *  F. Regression — header still registers the menu locations and the safe
 *     fallback helper exists (guarded from accidental removal).
 *
 * Read-only. No content is created or modified.
 *
 * Usage (from the project root):
 *   php wp-content/themes/conexao-br-irlanda/tests/test-nav-menu-regression.php
 *
 * @package conexao-br-irlanda
 */


// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$passed = 0;
$failed = 0;


$registered = get_registered_nav_menus();
assert_true( isset( $registered['primary'] ), "A1 'primary' nav menu location is registered");
assert_true( isset( $registered['footer'], $registered['social'] ), "A2 'footer'/'social' locations remain registered");

assert_true( has_nav_menu( 'primary' ), 'A3 a menu is assigned to the primary location');
$locations       = get_nav_menu_locations();
$primary_menu_id = (int) ( $locations['primary'] ?? 0 );
assert_true( $primary_menu_id > 0, 'A4 primary location assignment is non-zero');

$primary_menu = wp_get_nav_menu_object( 'Menu Principal' );
assert_true( (bool) $primary_menu, 'A5 curated menu "Menu Principal" exists');
assert_true( (int) ( $primary_menu->term_id ?? 0 ) === $primary_menu_id, 'A6 "Menu Principal" is the menu assigned to primary');

// Polylang per-language assignment — the actual regression cause. Polylang's
// theme_mod_nav_menu_locations filter resolves the location EXCLUSIVELY from
// nav_menus[stylesheet][location][lang]; without the 'pt' entry every
// language (including PT) lost the menu and hit the fallback.
$polylang_active = function_exists( 'pll_current_language' );
assert_true( $polylang_active, 'A7 Polylang is active');

if ( $polylang_active ) {
	$stylesheet = (string) get_option( 'stylesheet' );
	$options    = get_option( 'polylang', array() );
	$pll_pt     = (int) ( $options['nav_menus'][ $stylesheet ]['primary']['pt'] ?? 0 );
	assert_true( $pll_pt > 0, 'A8 Polylang nav_menus[primary][pt] is assigned');
	assert_true( $pll_pt === $primary_menu_id, 'A9 Polylang nav_menus[primary][pt] points at "Menu Principal"');

	$pll_en = (int) ( $options['nav_menus'][ $stylesheet ]['primary']['en'] ?? 0 );
	assert_true( 0 === $pll_en || wp_get_nav_menu_object( $pll_en ) !== false, 'A10 EN per-language state is coherent (no EN menu yet, or a valid one assigned)');

	$en_exists = (bool) pll_languages_list( array( 'slug' => 'en' ) );
	assert_true( $en_exists, 'A11 EN language exists (EN layer intact)');
}


// The EN header regression: with no English menu and no per-language
// assignment, Polylang nullified the 'primary' location on /en/ and the
// header rendered its safe empty fallback. The EN menu must exist and be
// assigned through the same per-language mechanism the PT menu uses.
$en_menu = wp_get_nav_menu_object( 'Main Menu' );
assert_true( (bool) $en_menu, 'C1 English menu "Main Menu" exists');
$en_menu_id = (int) ( $en_menu->term_id ?? 0 );

$stylesheet = (string) get_option( 'stylesheet' );
$options    = get_option( 'polylang', array() );
$pll_en     = (int) ( $options['nav_menus'][ $stylesheet ]['primary']['en'] ?? 0 );
assert_true( $pll_en > 0, 'C2 Polylang nav_menus[primary][en] is assigned');
assert_true( $pll_en === $en_menu_id, 'C3 Polylang nav_menus[primary][en] points at "Main Menu"');

$pll_pt = (int) ( $options['nav_menus'][ $stylesheet ]['primary']['pt'] ?? 0 );
assert_true( $pll_pt === $primary_menu_id, 'C4 PT assignment is untouched by the EN work (still "Menu Principal")');

// Stored EN menu content mirrors the PT menu's stored structure: the same
// canonical destinations (URLs are resolved per language at render time),
// with English titles. The "About Us" page item is present in the stored
// menu (parity with the stored PT "Sobre Nós" item) and is removed at
// render time, exactly as PT does.
$en_items = wp_get_nav_menu_items( $en_menu_id );
$en_items = $en_items ? $en_items : array();
$en_titles = array();
$en_urls   = array();
foreach ( $en_items as $item ) {
	// Stored nav titles are entity-encoded by WordPress core for non-ASCII
	// characters ("&" → "&amp;") and decoded again at output; compare decoded.
	$en_titles[] = html_entity_decode( trim( wp_strip_all_tags( $item->title ) ), ENT_QUOTES, 'UTF-8' );
	$en_urls[]   = untrailingslashit( (string) $item->url );
}
assert_true( array(
		'/',
		'/apoiadores/',
		'/guias/',
		'/eventos/',
		'/cursos/',
		'/lazer/',
		'/empregos/',
		'/blog/',
		'/sobre-nos/',
		'/contato/',
	) === array_map(
		static function ( $url ) {
			return trailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		},
		$en_urls
	), 'C5 EN menu stores the canonical curated destinations (Home, Sponsors, Guides, Events, Courses, Leisure & Tourism, Jobs, Blog, About Us, Contact)', 'got ' . wp_json_encode( $en_urls ) );
$expected_en_titles = array( 'Home', 'Sponsors', 'Guides', 'Events', 'Courses', 'Leisure & Tourism', 'Jobs', 'Blog', 'About Us', 'Contact' );
assert_true( $expected_en_titles === $en_titles, 'C6 EN menu stores English titles (existing EN labels of the linked objects/directories)', 'got ' . wp_json_encode( $en_titles ) );
assert_true( 0 === count( array_filter( $en_urls, static function ( $url ) {
		return false !== strpos( $url, '/noticias' );
	} ) ), 'C7 EN stored menu has no "Notícias" item');

// Language-aware render-time helpers (guarded: no language context in CLI →
// PT behaviour must be byte-identical).
assert_true( function_exists( 'conexao_primary_nav_archive_url' ), 'C8 language-aware archive URL helper exists');
assert_true( conexao_lang_url( '/blog/' ) === home_url( '/blog/' ) && conexao_primary_nav_archive_url( 'event', 'eventos' ) === ( get_post_type_archive_link( 'event' ) ? get_post_type_archive_link( 'event' ) : home_url( '/eventos/' ) ), 'C9 language-aware label/url helpers preserve PT defaults in no-language context');

// Curated stored menu content. Canonical order is enforced by menu_order +
// the documented render-time filters, never by this test.
$items = wp_get_nav_menu_items( $primary_menu_id );
$items = $items ? $items : array();
$urls  = array();
foreach ( $items as $item ) {
	$urls[] = untrailingslashit( (string) $item->url );
}
$home          = untrailingslashit( home_url( '/' ) );
$expected_urls = array( '/', '/apoiadores/', '/guias/', '/eventos/', '/cursos/', '/empregos/', '/blog/', '/contato/' );
$missing       = array();
foreach ( $expected_urls as $suffix ) {
	$needle = '/' === $suffix ? $home : untrailingslashit( home_url( $suffix ) );
	if ( ! in_array( $needle, $urls, true ) ) {
		$missing[] = $suffix;
	}
}
assert_true( array() === $missing, 'A12 stored menu carries the canonical curated destinations (Início, Apoiadores, Guias, Eventos, Cursos, Empregos, Blog, Contato)');
$noticias = array_filter( $urls, static function ( $url ) {
	return false !== strpos( $url, '/noticias' );
} );
assert_true( 0 === count( $noticias ), 'A13 stored menu has no "Notícias" item');

assert_true( function_exists( 'conexao_modify_primary_nav_items' ), 'A14 render-time nav modifier conexao_modify_primary_nav_items() exists (Início rename, Sobre Nós removal, Lazer e turismo insertion, Blog fallback insert)');
assert_true( function_exists( 'conexao_normalize_primary_nav_sections' ), 'A15 render-time nav normalizer conexao_normalize_primary_nav_sections() exists');


$header_src = (string) file_get_contents( CONEXAO_THEME_DIR . '/header.php' );
assert_true( false === strpos( $header_src, 'wp_page_menu' ), 'B1 header.php does not use wp_page_menu as wp_nav_menu fallback');
$count = substr_count( $header_src, "'fallback_cb'    => 'conexao_safe_nav_menu_fallback'," );
assert_true( 2 === $count, 'B2 BOTH wp_nav_menu calls (desktop + mobile drawer) use the canonical fallback');

assert_true( function_exists( 'conexao_safe_nav_menu_fallback' ), 'B3 safe fallback helper exists');

/*
 * B4 — the fallback must render the CANONICAL navigation, not nothing.
 *
 * This assertion previously required empty output. That encoded the
 * production regression: when Polylang has no per-language nav_menus
 * assignment it nullifies the 'primary' location to 0, wp_nav_menu() finds no
 * menu and calls this fallback — so an empty fallback produced a header with
 * a completely empty <nav id="site-navigation">. The fallback now renders the
 * theme's own canonical nine sections, so the header stays navigable.
 *
 * The safety property that caused the original report (never fall back to
 * WordPress' full page list) is still asserted below.
 */
$canonical = conexao_canonical_primary_nav_items();
assert_true( 9 === count( $canonical ), 'B4a canonical item builder returns exactly the nine canonical sections', 'got ' . count( $canonical ) );

$canonical_titles = wp_list_pluck( $canonical, 'title' );
$expected_titles  = array( 'Início', 'Apoiadores', 'Guias', 'Eventos', 'Cursos', 'Lazer e turismo', 'Empregos', 'Blog', 'Contato' );
assert_true( $expected_titles === $canonical_titles, 'B4b canonical items are the documented nine, in the documented order', 'got ' . wp_json_encode( $canonical_titles ) );

// Idempotent / pure: a second call must produce the same result, not mutate.
$again = conexao_canonical_primary_nav_items();
assert_true( wp_list_pluck( $again, 'title' ) === $canonical_titles, 'B4c canonical item builder is idempotent (same order and labels on a second call)');
$repeat = array_map(
	static function ( $item ) {
		return $item->url;
	},
	$canonical
);
assert_true( $repeat === array_map( static function ( $item ) { return $item->url; }, $again ), 'B4d canonical items are not mutated by repeated calls (URLs stable)');

ob_start();
conexao_safe_nav_menu_fallback( array( 'theme_location' => 'primary', 'menu_id' => 'primary-menu', 'menu_class' => 'primary-menu' ) );
$emitted = ob_get_clean();
$emitted = (string) $emitted;

assert_true( '' !== trim( $emitted ), 'B4e safe fallback renders the canonical navigation instead of nothing' );
assert_true( false !== strpos( $emitted, 'id="primary-menu"' ), 'B4f safe fallback keeps the primary-menu id the CSS and tests target' );
assert_true( 9 === (int) preg_match_all( '/nav-link/', $emitted ), 'B4g safe fallback renders all nine canonical items', 'got ' . (int) preg_match_all( '/nav-link/', $emitted ) );

$emitted_titles = array();
if ( preg_match_all( '/<a href="[^"]*"[^>]*>([^<]+)<\/a>/', $emitted, $m ) ) {
	$emitted_titles = $m[1];
}
assert_true( $expected_titles === $emitted_titles, 'B4h safe fallback renders the canonical nine in the canonical order', 'got ' . wp_json_encode( $emitted_titles ) );

// The original regression this fallback exists for must stay fixed.
assert_true( ! preg_match( '/<li class="page_item[^\"]*"\s/', $emitted ), 'B4i safe fallback never renders the WordPress page-list (wp_page_menu) output' );
assert_true( false === strpos( $emitted, 'page-item' ), 'B4j safe fallback emits no page-item classes' );

// Non-primary locations keep WordPress' own default behaviour.
ob_start();
conexao_safe_nav_menu_fallback( array( 'theme_location' => 'footer' ) );
$other = ob_get_clean();
assert_true( '' === trim( (string) $other ), 'B5 the canonical fallback is scoped to the primary location only (footer renders nothing)' );


assert_true( function_exists( 'conexao_nav_menu_args' ) && function_exists( 'conexao_nav_menu_css_class' ), 'F1 nav-menu class/args filters still registered (nav-item/nav-link classes preserved)');
assert_true( function_exists( 'conexao_override_guides_menu_links' ), 'F2 guides-link override filter still active');
assert_true( function_exists( 'conexao_language_switcher' ), 'F3 language switcher renderer still present');

test_finish();
