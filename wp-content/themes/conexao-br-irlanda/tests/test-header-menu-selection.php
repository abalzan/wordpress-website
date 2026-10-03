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
 *    destination /blog/ — there is no EN posts archive). EN Jobs resolves
 *    to the linked /en/empregos/ shared-slug translation (see
 *    CONEXAO_BR_EN_NAV_LANGUAGE_CONTEXT_FIX_REPORT.md).
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

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$language = isset( $argv[1] ) ? strtolower( trim( $argv[1] ) ) : '';
if ( ! in_array( $language, array( 'pt', 'en' ), true ) ) {
	fwrite( STDERR, "Usage: php test-header-menu-selection.php [pt|en]\n" );
	exit( 2 );
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

echo sprintf( "== Primary menu selection under a %s request (%s) ==\n", strtoupper( $language ), $_SERVER['REQUEST_URI'] );

$polylang_active = function_exists( 'pll_current_language' );
assert_true( $polylang_active, 'S1 Polylang is active');
assert_true( $language === conexao_current_language_slug(), sprintf( 'S2 request resolves to the %s language context', strtoupper( $language ) ));

// 1. Actual menu-location selection through Polylang's filter chain.
assert_true( has_nav_menu( 'primary' ), 'S3 the primary location has a valid menu');
$locations = get_nav_menu_locations();
$primary   = (int) ( $locations['primary'] ?? 0 );
assert_true( $primary > 0, 'S4 the primary location assignment is non-zero');

$expected_menu_name = 'pt' === $language ? 'Menu Principal' : 'Main Menu';
$expected_menu      = wp_get_nav_menu_object( $expected_menu_name );
assert_true( (bool) $expected_menu, sprintf( 'S5 the %s menu exists', $expected_menu_name ));
assert_true( (int) ( $expected_menu->term_id ?? 0 ) === $primary, sprintf( 'S6 the %s menu is the menu selected for primary', $expected_menu_name ));

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
	assert_true( is_string( $rendered ) && '' !== trim( (string) $rendered ), 'P1 PT nav renders the curated menu (not the empty fallback)');
	assert_true( false !== strpos( (string) $rendered, 'Início' ) && false !== strpos( (string) $rendered, 'Lazer e turismo' ) && false !== strpos( (string) $rendered, 'Contato' ), 'P2 PT nav renders the canonical PT labels');
	assert_true( 9 === (int) preg_match_all( '/nav-link/', (string) $rendered ), 'P3 PT nav renders all nine canonical labels');
	assert_true( ! $page_list_fallback, 'P4 PT nav never uses the wp_page_menu page-list fallback');
	assert_true( (bool) preg_match( '/>Início<\/a>/', (string) $rendered ) && false !== strpos( (string) $rendered, 'aria-current="page"' ), 'P5 PT homepage marks Início current');
	assert_true( false === strpos( (string) $rendered, 'Sponsors' ) && false === strpos( (string) $rendered, 'Leisure &amp; Tourism' ), 'P6 PT nav does not leak English labels');
} else {
	$en_labels = array( 'Home', 'Sponsors', 'Guides', 'Events', 'Courses', 'Leisure &amp; Tourism', 'Jobs', 'Blog', 'Contact' );
	assert_true( is_string( $rendered ) && '' !== trim( (string) $rendered ), 'E1 EN nav renders the curated EN menu (not the empty fallback)');
	assert_true( 9 === (int) preg_match_all( '/nav-link/', (string) $rendered ), 'E2 EN nav renders all nine EN labels');
	$missing = array();
	foreach ( $en_labels as $label ) {
		if ( false === strpos( (string) $rendered, $label ) ) {
			$missing[] = $label;
		}
	}
	assert_true( array() === $missing, 'E3 EN nav renders the canonical EN labels (Home, Sponsors, Guides, Events, Courses, Leisure & Tourism, Jobs, Blog, Contact)', 'missing ' . wp_json_encode( $missing ) );
	assert_true( ! $page_list_fallback, 'E4 EN nav never uses the wp_page_menu page-list fallback');

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
		$ok_url = '' === $tail || '/en' === $tail || 0 === strpos( $tail, '/en/' ) || '/blog' === $tail; // approved B1 exception: Blog only.
		if ( ! $ok_url ) {
			$bad[] = $tail;
		}
	}
	assert_true( array() === $bad, 'E5 EN nav destinations stay in the EN URL space (only B1 exception: /blog/)', 'bad ' . wp_json_encode( $bad ) );
	// The EN Jobs landing is a SHARED-SLUG page: the EN record reuses the PT
	// `empregos` post_name, so the approved shape is `/en/empregos/`
	// (docs/routing.md §"Shared-slug pages"), mirroring `/en/blog/`. The
	// assertion checks the DESTINATION, which is resolved at render time
	// through conexao_bind_section_object() → pll_get_post() → get_permalink(),
	// so it proves the Polylang binding still drives the nav and not a
	// hard-coded string.
	assert_true( (bool) preg_match( '#href="[^"]*/en/empregos/"#', (string) $rendered ), 'E5b EN Jobs item points at the linked EN translation /en/empregos/ (shared-slug shape)');
	assert_true( false === strpos( (string) $rendered, 'href="' . home_url( '/empregos/' ) . '"' ) && false === strpos( (string) $rendered, 'href="/empregos/"' ), 'E5c EN Jobs item is NOT the Portuguese /empregos/');
	assert_true( (bool) preg_match( '/<li[^>]*current-menu-item[^>]*>\s*<a[^>]*>Home<\/a>/i', (string) $rendered ), 'E6 EN homepage marks Home current on /en/ (class level; aria-current is HTTP-verified)');
	assert_true( false === strpos( (string) $rendered, 'Apoiadores</a>' ) && false === strpos( (string) $rendered, 'Lazer e turismo</a>' ) && false === strpos( (string) $rendered, 'Início</a>' ), 'E7 EN nav does not leak Portuguese labels');

	// Page-object items (Contact) must resolve to the linked EN translation.
	assert_true( (bool) preg_match( '#href="[^"]*/en/contact/"#', (string) $rendered ), 'E8 EN Contact item points at the EN contact page');
}

test_finish();
