<?php
/**
 * EN primary-navigation LANGUAGE-CONTEXT regression test (live WordPress).
 *
 * Regression fixed in CONEXAO_BR_EN_NAV_LANGUAGE_CONTEXT_FIX_REPORT.md: clicking
 * "Jobs" in the English primary navigation sent the visitor to the Portuguese
 * /empregos/, silently switching the site back to Portuguese. Root cause: the
 * Jobs section was modelled as a CPT archive while the `job` CPT is registered
 * with has_archive = false, so the language-aware archive URL was empty and the
 * code fell back to the Portuguese path. Jobs is a STATIC LANDING PAGE whose EN
 * counterpart (/en/jobs/) is a real linked Polylang translation.
 *
 * A. Jobs resolves to the linked /en/jobs/ translation, not /empregos/.
 * B. Blog stays on the documented B1 /blog/ destination.
 * C. every EN nav item is audited; only Blog may leave /en/.
 * D. the Portuguese destinations are unchanged.
 * E. no wp_page_menu fallback, no hard-coded nav URLs in header.php.
 *
 * Requires live WordPress + Polylang with the Stage 3.2 EN page translations and
 * the EN "Main Menu". Data-dependent checks SKIP when the EN data is absent.
 *
 * Usage (inside the WordPress container):
 *   php wp-content/themes/conexao-br-irlanda/tests/test-nav-language-context.php
 *
 * Read-only. No content is created or modified.
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

$passed  = 0;
$failed  = 0;
$skipped = 0;

function check( string $label, bool $ok, string $detail = '' ): void {
	global $passed, $failed;
	if ( $ok ) {
		++$passed;
		echo "  PASS  {$label}\n";
	} else {
		++$failed;
		echo "  FAIL  {$label}" . ( '' !== $detail ? "  [{$detail}]" : '' ) . "\n";
	}
}
function skip( string $label ): void {
	global $skipped;
	++$skipped;
	echo "  SKIP  {$label}\n";
}

/**
 * Best-effort switch of Polylang's current language in a CLI context.
 *
 * @param string $slug Language slug.
 * @return bool True when pll_current_language() now reports $slug.
 */
function conexao_test_set_language( string $slug ): bool {
	if ( function_exists( 'PLL' ) ) {
		$pll = PLL();
		if ( $pll && isset( $pll->model ) && method_exists( $pll->model, 'get_language' ) ) {
			$lang = $pll->model->get_language( $slug );
			if ( $lang ) {
				$pll->curlang = $lang;
			}
		}
	}
	return function_exists( 'pll_current_language' ) && $slug === pll_current_language( 'slug' );
}

echo "== EN primary-navigation language-context regression (live WP) ==\n";

$has_polylang = function_exists( 'pll_current_language' ) && function_exists( 'pll_get_post' );
check( 'A0 Polylang active', $has_polylang );

if ( ! $has_polylang ) {
	echo "\n{$passed} passed, {$failed} failed, {$skipped} skipped (Polylang inactive).\n";
	exit( 1 );
}

// --- A. Jobs: page-backed + real linked EN translation -----------------------
echo "== A. Jobs ==\n";

$sections = conexao_primary_nav_sections();
check( 'A1 Jobs section spec is page-backed (path=empregos)', isset( $sections['empregos'] ) && 'page' === $sections['empregos']['type'] && 'empregos' === $sections['empregos']['path'] );
check( 'A2 `job` CPT keeps has_archive = false', false === ( get_post_type_object( 'job' )->has_archive ) );

$empregos_page = get_page_by_path( 'empregos' );
check( 'A3 PT Empregos landing page exists', (bool) $empregos_page );

$en_jobs_url = '';
if ( $empregos_page ) {
	$en_id = (int) pll_get_post( (int) $empregos_page->ID, 'en' );
	if ( $en_id && 'publish' === get_post_status( $en_id ) ) {
		$en_jobs_url = (string) get_permalink( $en_id );
	}
}
check( 'A4 the PT Empregos page has a published linked EN translation', '' !== $en_jobs_url, 'no EN translation' );
if ( '' !== $en_jobs_url ) {
	check( 'A5 the linked EN Jobs translation lives under /en/', false !== strpos( $en_jobs_url, '/en/' ), $en_jobs_url );
}

$en_lang_set = conexao_test_set_language( 'en' );
if ( $en_lang_set ) {
	$resolved = conexao_lang_url( '/empregos/' );
	check( 'A6 (EN) conexao_lang_url( /empregos/ ) resolves to the EN translation', untrailingslashit( $resolved ) === untrailingslashit( $en_jobs_url ), "got {$resolved}" );

	$resolved2 = conexao_primary_nav_archive_url( 'job', 'empregos' );
	check( 'A7 (EN) the Jobs item no longer resolves to the PT /empregos/', untrailingslashit( $resolved2 ) !== untrailingslashit( home_url( '/empregos/' ) ), "got {$resolved2}" );

	$leaks      = array();
	$en_menu_id = (int) ( get_option( 'polylang' )['nav_menus'][ get_option( 'stylesheet' ) ]['primary']['en'] ?? 0 );
	if ( $en_menu_id ) {
		$args  = (object) array( 'theme_location' => 'primary' );
		$items = wp_get_nav_menu_items( $en_menu_id );
		$items = $items ? $items : array();
		$items = conexao_override_guides_menu_links( $items, $args );
		$items = conexao_modify_primary_nav_items( $items, $args );
		$items = conexao_normalize_primary_nav_sections( $items, $args );
		$en_home = untrailingslashit( pll_home_url( 'en' ) );
		foreach ( $items as $item ) {
			$url = untrailingslashit( (string) $item->url );
			if ( $url !== $en_home && 0 !== strpos( $url, $en_home . '/' ) ) {
				$leaks[] = $url;
			}
		}
	} else {
		skip( 'A8 EN primary menu not assigned — render-time audit skipped' );
	}
	if ( $en_menu_id ) {
		check( 'A8 (EN) only the documented B1 Blog destination leaves /en/', array( untrailingslashit( home_url( '/blog/' ) ) ) === $leaks, implode( ', ', $leaks ) );
		check( 'A9 (EN) Jobs never leaks to PT', ! in_array( untrailingslashit( home_url( '/empregos/' ) ), $leaks, true ), implode( ', ', $leaks ) );
	}
} else {
	skip( 'A6–A9 EN language context not settable in this CLI context — render-time resolution NOT_TESTABLE here (covered by tests/test-nav-language-context-logic.php and the HTTP verifier)' );
}

// --- B/C. Blog + full EN audit (data-level) ---------------------------------
echo "== B/C. Blog + EN audit ==\n";

$blog_page_id = (int) get_option( 'page_for_posts' );
if ( $blog_page_id ) {
	$en_blog = (int) pll_get_post( $blog_page_id, 'en' );
	// No EN Blog archive is expected: /en/blog/ is the approved B1 302-only URL.
	check( 'B1 Blog has NO published EN archive/page translation (approved B1)', 0 === $en_blog || 'publish' !== get_post_status( $en_blog ), "en_id={$en_blog}" );
} else {
	skip( 'B1 no page_for_posts configured' );
}

$en_nav_targets = array(
	'Home'              => home_url( '/' ),
	'Sponsors'          => conexao_primary_nav_archive_url( 'sponsor', 'apoiadores' ),
	'Guides'            => conexao_primary_nav_archive_url( 'guide', 'guias' ),
	'Events'            => conexao_primary_nav_archive_url( 'event', 'eventos' ),
	'Courses'           => conexao_primary_nav_archive_url( 'course_provider', 'cursos' ),
	'Leisure & Tourism' => conexao_primary_nav_archive_url( 'leisure', 'lazer' ),
	'Jobs'              => conexao_primary_nav_archive_url( 'job', 'empregos' ),
	'Blog'              => home_url( '/blog/' ),
	'Contact'           => home_url( '/contato/' ),
);
check( 'C1 every EN navigation item resolves to a non-empty URL', 0 === count( array_filter( $en_nav_targets, static function ( $u ) { return '' === trim( (string) $u ); } ) ) );

// --- D. PT regression -------------------------------------------------------
echo "== D. PT regression ==\n";

conexao_test_set_language( 'pt' );
check( 'D1 (PT) Jobs resolves to the PT page /empregos/', untrailingslashit( conexao_primary_nav_archive_url( 'job', 'empregos' ) ) === untrailingslashit( home_url( '/empregos/' ) ), conexao_primary_nav_archive_url( 'job', 'empregos' ) );
check( 'D2 (PT) Blog resolves to /blog/', untrailingslashit( conexao_lang_url( '/blog/' ) ) === untrailingslashit( home_url( '/blog/' ) ) );
check( 'D3 (PT) guides archive unchanged', untrailingslashit( conexao_primary_nav_archive_url( 'guide', 'guias' ) ) === untrailingslashit( home_url( '/guias/' ) ) );

// --- E. Structural guards ---------------------------------------------------
echo "== E. Structural guards ==\n";

$header_src = (string) file_get_contents( CONEXAO_THEME_DIR . '/header.php' );
check( 'E1 header.php does NOT use wp_page_menu as wp_nav_menu fallback', false === strpos( $header_src, 'wp_page_menu' ) );
check( 'E2 BOTH wp_nav_menu() calls use the safe empty fallback', 2 === substr_count( $header_src, "'fallback_cb'    => 'conexao_safe_nav_menu_fallback'," ) );
check( 'E3 no hard-coded /en/empregos/ or /en/blog/ in header.php', false === strpos( $header_src, '/en/empregos/' ) && false === strpos( $header_src, '/en/blog/' ) );

echo "\n{$passed} passed, {$failed} failed, {$skipped} skipped.\n";
exit( 0 === $failed ? 0 : 1 );

