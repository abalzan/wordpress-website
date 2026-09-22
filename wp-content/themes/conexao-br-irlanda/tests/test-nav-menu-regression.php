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

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

$passed = 0;
$failed = 0;

function check( string $label, bool $ok ): void {
	global $passed, $failed;
	if ( $ok ) {
		++$passed;
		echo "  PASS  {$label}\n";
	} else {
		++$failed;
		echo "  FAIL  {$label}\n";
	}
}

echo "== A. PT primary menu ==\n";

$registered = get_registered_nav_menus();
check( "A1 'primary' nav menu location is registered", isset( $registered['primary'] ) );
check( "A2 'footer'/'social' locations remain registered", isset( $registered['footer'], $registered['social'] ) );

check( 'A3 a menu is assigned to the primary location', has_nav_menu( 'primary' ) );
$locations       = get_nav_menu_locations();
$primary_menu_id = (int) ( $locations['primary'] ?? 0 );
check( 'A4 primary location assignment is non-zero', $primary_menu_id > 0 );

$primary_menu = wp_get_nav_menu_object( 'Menu Principal' );
check( 'A5 curated menu "Menu Principal" exists', (bool) $primary_menu );
check( 'A6 "Menu Principal" is the menu assigned to primary', (int) ( $primary_menu->term_id ?? 0 ) === $primary_menu_id );

// Polylang per-language assignment — the actual regression cause. Polylang's
// theme_mod_nav_menu_locations filter resolves the location EXCLUSIVELY from
// nav_menus[stylesheet][location][lang]; without the 'pt' entry every
// language (including PT) lost the menu and hit the fallback.
$polylang_active = function_exists( 'pll_current_language' );
check( 'A7 Polylang is active', $polylang_active );

if ( $polylang_active ) {
	$stylesheet = (string) get_option( 'stylesheet' );
	$options    = get_option( 'polylang', array() );
	$pll_pt     = (int) ( $options['nav_menus'][ $stylesheet ]['primary']['pt'] ?? 0 );
	check( 'A8 Polylang nav_menus[primary][pt] is assigned', $pll_pt > 0 );
	check( 'A9 Polylang nav_menus[primary][pt] points at "Menu Principal"', $pll_pt === $primary_menu_id );

	$pll_en = (int) ( $options['nav_menus'][ $stylesheet ]['primary']['en'] ?? 0 );
	check(
		'A10 EN per-language state is coherent (no EN menu yet, or a valid one assigned)',
		0 === $pll_en || wp_get_nav_menu_object( $pll_en ) !== false
	);

	$en_exists = (bool) pll_languages_list( array( 'slug' => 'en' ) );
	check( 'A11 EN language exists (EN layer intact)', $en_exists );
}

echo "== C. EN primary menu ==\n";

// The EN header regression: with no English menu and no per-language
// assignment, Polylang nullified the 'primary' location on /en/ and the
// header rendered its safe empty fallback. The EN menu must exist and be
// assigned through the same per-language mechanism the PT menu uses.
$en_menu = wp_get_nav_menu_object( 'Main Menu' );
check( 'C1 English menu "Main Menu" exists', (bool) $en_menu );
$en_menu_id = (int) ( $en_menu->term_id ?? 0 );

$stylesheet = (string) get_option( 'stylesheet' );
$options    = get_option( 'polylang', array() );
$pll_en     = (int) ( $options['nav_menus'][ $stylesheet ]['primary']['en'] ?? 0 );
check( 'C2 Polylang nav_menus[primary][en] is assigned', $pll_en > 0 );
check( 'C3 Polylang nav_menus[primary][en] points at "Main Menu"', $pll_en === $en_menu_id );

$pll_pt = (int) ( $options['nav_menus'][ $stylesheet ]['primary']['pt'] ?? 0 );
check( 'C4 PT assignment is untouched by the EN work (still "Menu Principal")', $pll_pt === $primary_menu_id );

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
check(
	'C5 EN menu stores the canonical curated destinations (Home, Sponsors, Guides, Events, Courses, Leisure & Tourism, Jobs, Blog, About Us, Contact)',
	array(
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
	),
	'got ' . wp_json_encode( $en_urls )
);
$expected_en_titles = array( 'Home', 'Sponsors', 'Guides', 'Events', 'Courses', 'Leisure & Tourism', 'Jobs', 'Blog', 'About Us', 'Contact' );
check(
	'C6 EN menu stores English titles (existing EN labels of the linked objects/directories)',
	$expected_en_titles === $en_titles,
	'got ' . wp_json_encode( $en_titles )
);
check(
	'C7 EN stored menu has no "Notícias" item',
	0 === count( array_filter( $en_urls, static function ( $url ) {
		return false !== strpos( $url, '/noticias' );
	} ) )
);

// Language-aware render-time helpers (guarded: no language context in CLI →
// PT behaviour must be byte-identical).
check( 'C8 language-aware archive URL helper exists', function_exists( 'conexao_primary_nav_archive_url' ) );
check( 'C9 language-aware label/url helpers preserve PT defaults in no-language context', conexao_lang_url( '/blog/' ) === home_url( '/blog/' ) && conexao_primary_nav_archive_url( 'event', 'eventos' ) === ( get_post_type_archive_link( 'event' ) ? get_post_type_archive_link( 'event' ) : home_url( '/eventos/' ) ) );

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
check( 'A12 stored menu carries the canonical curated destinations (Início, Apoiadores, Guias, Eventos, Cursos, Empregos, Blog, Contato)', array() === $missing );
$noticias = array_filter( $urls, static function ( $url ) {
	return false !== strpos( $url, '/noticias' );
} );
check( 'A13 stored menu has no "Notícias" item', 0 === count( $noticias ) );

check( 'A14 render-time nav modifier conexao_modify_primary_nav_items() exists (Início rename, Sobre Nós removal, Lazer e turismo insertion, Blog fallback insert)', function_exists( 'conexao_modify_primary_nav_items' ) );
check( 'A15 render-time nav normalizer conexao_normalize_primary_nav_sections() exists', function_exists( 'conexao_normalize_primary_nav_sections' ) );

echo "== B. Fallback safety ==\n";

$header_src = (string) file_get_contents( CONEXAO_THEME_DIR . '/header.php' );
check( 'B1 header.php does not use wp_page_menu as wp_nav_menu fallback', false === strpos( $header_src, 'wp_page_menu' ) );
$count = substr_count( $header_src, "'fallback_cb'    => 'conexao_safe_nav_menu_fallback'," );
check( 'B2 BOTH wp_nav_menu calls (desktop + mobile drawer) use the safe empty fallback', 2 === $count );

check( 'B3 safe fallback helper exists', function_exists( 'conexao_safe_nav_menu_fallback' ) );
ob_start();
conexao_safe_nav_menu_fallback( array( 'theme_location' => 'primary' ) );
$emitted = ob_get_clean();
check( 'B4 safe fallback renders no markup (explicitly empty output)', '' === trim( (string) $emitted ) );

echo "== F. Regression guards ==\n";

check( 'F1 nav-menu class/args filters still registered (nav-item/nav-link classes preserved)', function_exists( 'conexao_nav_menu_args' ) && function_exists( 'conexao_nav_menu_css_class' ) );
check( 'F2 guides-link override filter still active', function_exists( 'conexao_override_guides_menu_links' ) );
check( 'F3 language switcher renderer still present', function_exists( 'conexao_language_switcher' ) );

echo "\n{$passed} passed, {$failed} failed.\n";
exit( 0 === $failed ? 0 : 1 );

