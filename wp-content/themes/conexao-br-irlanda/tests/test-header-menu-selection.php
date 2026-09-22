<?php
/**
 * Per-language primary-menu SELECTION test (runs the real request context).
 *
 * Companion to tests/test-nav-menu-regression.php (data-level). This test
 * boots WordPress with a real request URI for ONE language and asserts the
 * behaviour the header actually gets:
 *
 *  - Polylang's theme_mod_nav_menu_locations filter resolves 'primary' to the
 *    correct per-language menu (PT: "Menu Principal"; EN: "Main Menu").
 *  - wp_nav_menu() renders the curated menu with the language's labels —
 *    never the wp_page_menu page list, and never an empty nav when a valid
 *    per-language menu is assigned.
 *  - English destinations stay in the /en/ URL space (except the approved B1
 *    destinations /empregos/ and /blog/).
 *  - The current-menu-item state lands on the right item in both languages
 *    (including the /en/ language-prefix path handling).
 *
 * Read-only. No content is created or modified.
 *
 * Usage (from the project root, inside the WordPress container):
 *   php wp-content/themes/conexao-br-irlanda/tests/test-header-menu-selection.php pt
 *   php wp-content/themes/conexao-br-irlanda/tests/test-header-menu-selection.php en
 *
 * @package conexao-br-irlanda
 */

$language = isset( $argv[1] ) ? strtolower( trim( $argv[1] ) ) : '';
if ( ! in_array( $language, array( 'pt', 'en' ), true ) ) {
	fwrite( STDERR, "Usage: php test-header-menu-selection.php [pt|en]\n" );
	exit( 2 );
}

$_SERVER['HTTP_HOST']      = 'localhost:8080';
$_SERVER['SERVER_NAME']    = 'localhost';
$_SERVER['REQUEST_URI']    = 'pt' === $language ? '/' : '/en/';
$_SERVER['SCRIPT_NAME']    = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';

$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

/**
 * Switch the request language context the way Polylang does while routing
 * (same pattern as tests/test-stage33-bilingual.php — Polylang resolves the
 * language from the parsed request, which a CLI harness must set by hand).
 *
 * @param string $slug Language slug.
 */
function selection_set_language( $slug ) {
	if ( ! function_exists( 'PLL' ) || ! PLL() ) {
		return;
	}

	PLL()->curlang = PLL()->model->get_language( $slug );
}

selection_set_language( $language );

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

echo sprintf( "== Primary menu selection under a %s request (%s) ==\n", strtoupper( $language ), $_SERVER['REQUEST_URI'] );

$polylang_active = function_exists( 'pll_current_language' );
check( 'S1 Polylang is active', $polylang_active );
check( sprintf( 'S2 request resolves to the %s language context', strtoupper( $language ) ), $language === conexao_current_language_slug() );

// 1. Actual menu-location selection through Polylang's filter chain.
check( 'S3 the primary location has a valid menu', has_nav_menu( 'primary' ) );
$locations = get_nav_menu_locations();
$primary   = (int) ( $locations['primary'] ?? 0 );
check( 'S4 the primary location assignment is non-zero', $primary > 0 );

$expected_menu_name = 'pt' === $language ? 'Menu Principal' : 'Main Menu';
$expected_menu      = wp_get_nav_menu_object( $expected_menu_name );
check( sprintf( 'S5 the %s menu exists', $expected_menu_name ), (bool) $expected_menu );
check( sprintf( 'S6 the %s menu is the menu selected for primary', $expected_menu_name ), (int) ( $expected_menu->term_id ?? 0 ) === $primary );

// 2. Rendered output — the same call the header makes (desktop nav).
$rendered = wp_nav_menu(
	array(
		'theme_location' => 'primary',
		'menu_id'        => 'primary-menu',
		'container'      => false,
		'fallback_cb'    => 'conexao_safe_nav_menu_fallback',
		'depth'          => 3,
		'echo'           => false,
	)
);

// wp_page_menu signature: a page_item-only <li> with no menu-item class.
$page_list_fallback = (bool) preg_match( '/<li class="page_item[^"]*"\s/', (string) $rendered );

if ( 'pt' === $language ) {
	check( 'P1 PT nav renders the curated menu (not the empty fallback)', is_string( $rendered ) && '' !== trim( (string) $rendered ) );
	check( 'P2 PT nav renders the canonical PT labels', false !== strpos( (string) $rendered, 'Início' ) && false !== strpos( (string) $rendered, 'Lazer e turismo' ) && false !== strpos( (string) $rendered, 'Contato' ) );
	check( 'P3 PT nav renders all nine canonical labels', 9 === (int) preg_match_all( '/nav-link/', (string) $rendered ) );
	check( 'P4 PT nav never uses the wp_page_menu page-list fallback', ! $page_list_fallback );
	check( 'P5 PT homepage marks Início current', (bool) preg_match( '/>Início<\/a>/', (string) $rendered ) && false !== strpos( (string) $rendered, 'aria-current="page"' ) );
	check( 'P6 PT nav does not leak English labels', false === strpos( (string) $rendered, 'Sponsors' ) && false === strpos( (string) $rendered, 'Leisure &amp; Tourism' ) );
} else {
	$en_labels = array( 'Home', 'Sponsors', 'Guides', 'Events', 'Courses', 'Leisure &amp; Tourism', 'Jobs', 'Blog', 'Contact' );
	check( 'E1 EN nav renders the curated EN menu (not the empty fallback)', is_string( $rendered ) && '' !== trim( (string) $rendered ) );
	check( 'E2 EN nav renders all nine EN labels', 9 === (int) preg_match_all( '/nav-link/', (string) $rendered ) );
	$missing = array();
	foreach ( $en_labels as $label ) {
		if ( false === strpos( (string) $rendered, $label ) ) {
			$missing[] = $label;
		}
	}
	check( 'E3 EN nav renders the canonical EN labels (Home, Sponsors, Guides, Events, Courses, Leisure & Tourism, Jobs, Blog, Contact)', array() === $missing, 'missing ' . wp_json_encode( $missing ) );
	check( 'E4 EN nav never uses the wp_page_menu page-list fallback', ! $page_list_fallback );

	// Destinations: EN URL space, with the approved B1 exceptions. The bare
	// home link ('' / '/') is allowed here: in a CLI harness Polylang's
	// bare-home_url rewrite (a real-request behavior) does not apply, so the
	// Home item still carries the scheme/host canonical home; the /en/ form
	// of that link is asserted over HTTP by
	// scripts/nav-regression-http-verify.py.
	$matches_url = array();
	preg_match_all( '/<a href="([^"]*)"/', (string) $rendered, $matches_url );
	$bad = array();
	foreach ( $matches_url[1] as $url ) {
		$tail = untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$ok_url = '' === $tail || '/en' === $tail || 0 === strpos( $tail, '/en/' ) || in_array( $tail, array( '/empregos', '/blog' ), true );
		if ( ! $ok_url ) {
			$bad[] = $tail;
		}
	}
	check( 'E5 EN nav destinations stay in the EN URL space (B1 exceptions: /empregos/, /blog/)', array() === $bad, 'bad ' . wp_json_encode( $bad ) );
	check( 'E6 EN homepage marks Home current on /en/ (class level; aria-current is HTTP-verified)', (bool) preg_match( '/<li[^>]*current-menu-item[^>]*>\s*<a[^>]*>Home<\/a>/i', (string) $rendered ) );
	check( 'E7 EN nav does not leak Portuguese labels', false === strpos( (string) $rendered, 'Apoiadores</a>' ) && false === strpos( (string) $rendered, 'Lazer e turismo</a>' ) && false === strpos( (string) $rendered, 'Início</a>' ) );

	// Page-object items (Contact) must resolve to the linked EN translation.
	check( 'E8 EN Contact item points at the EN contact page', (bool) preg_match( '#href="[^"]*/en/contact/"#', (string) $rendered ) );
}

echo "\n{$passed} passed, {$failed} failed.\n";
exit( 0 === $failed ? 0 : 1 );