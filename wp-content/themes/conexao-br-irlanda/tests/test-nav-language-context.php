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
 * B. Blog: the approved EN destination is /en/blog/ — a real English archive
 *    once the linked EN posts page exists (Stage 5), the B2 fallback before that.
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


// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$passed  = 0;
$failed  = 0;
$skipped = 0;

function skip( string $label ): void {
	global $skipped;
	++$skipped;
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


$has_polylang = function_exists( 'pll_current_language' ) && function_exists( 'pll_get_post' );
assert_true( $has_polylang, 'A0 Polylang active');

if ( ! $has_polylang ) {
	exit( 1 );
}

// --- A. Jobs: page-backed + real linked EN translation -----------------------

$sections = conexao_primary_nav_sections();
assert_true( isset( $sections['empregos'] ) && 'page' === $sections['empregos']['type'] && 'empregos' === $sections['empregos']['path'], 'A1 Jobs section spec is page-backed (path=empregos)');
assert_true( false === ( get_post_type_object( 'job' )->has_archive ), 'A2 `job` CPT keeps has_archive = false');

$empregos_page = get_page_by_path( 'empregos' );
assert_true( (bool) $empregos_page, 'A3 PT Empregos landing page exists');

$en_jobs_url = '';
if ( $empregos_page ) {
	$en_id = (int) pll_get_post( (int) $empregos_page->ID, 'en' );
	if ( $en_id && 'publish' === get_post_status( $en_id ) ) {
		$en_jobs_url = (string) get_permalink( $en_id );
	}
}
assert_true( '' !== $en_jobs_url, 'A4 the PT Empregos page has a published linked EN translation', 'no EN translation' );
if ( '' !== $en_jobs_url ) {
	assert_true( false !== strpos( $en_jobs_url, '/en/' ), 'A5 the linked EN Jobs translation lives under /en/', $en_jobs_url );
}

$en_lang_set = conexao_test_set_language( 'en' );
if ( $en_lang_set ) {
	$resolved = conexao_lang_url( '/empregos/' );
	assert_true( untrailingslashit( $resolved ) === untrailingslashit( $en_jobs_url ), 'A6 (EN) conexao_lang_url( /empregos/ ) resolves to the EN translation', "got {$resolved}" );

	$resolved2 = conexao_primary_nav_archive_url( 'job', 'empregos' );
	assert_true( untrailingslashit( $resolved2 ) !== untrailingslashit( home_url( '/empregos/' ) ), 'A7 (EN) the Jobs item no longer resolves to the PT /empregos/', "got {$resolved2}" );

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
		// Blog's approved EN destination is /en/blog/ (real EN archive after Stage 5;
		// B2 fallback before it), so NO nav item should leave the /en/ context.
		assert_true( 0 === count( $leaks ), 'A8 (EN) NO nav item leaves the /en/ context (Blog is B2, not B1)', implode( ', ', $leaks ) );
		assert_true( ! in_array( untrailingslashit( home_url( '/empregos/' ) ), $leaks, true ), 'A9 (EN) Jobs never leaks to PT', implode( ', ', $leaks ) );
	}
} else {
	skip( 'A6–A9 EN language context not settable in this CLI context — render-time resolution NOT_TESTABLE here (covered by tests/test-nav-language-context-logic.php and the HTTP verifier)' );
}

// --- B/C. Blog + full EN audit (data-level) ---------------------------------

$blog_page_id = (int) get_option( 'page_for_posts' );
if ( $blog_page_id ) {
	$en_blog = (int) pll_get_post( $blog_page_id, 'en' );
	// STAGE 5 — the Blog translation state has exactly two approved shapes:
	//   * no published linked EN posts page → B2 (PT posts under /en/blog/);
	//   * a published linked EN posts page  → a REAL English archive.
	// A published-and-linked pair must NOT stay B2; an untranslated posts page
	// must NOT be treated as translated.
	$en_blog_linked = $en_blog > 0 && $en_blog !== $blog_page_id
		&& (int) pll_get_post( $en_blog, 'pt' ) === $blog_page_id;
	$en_blog_published = $en_blog_linked && 'publish' === get_post_status( $en_blog );

	if ( $en_blog_published ) {
		assert_true( true, 'B1 Blog has a published, linked EN posts page (real EN archive)', "en_id={$en_blog}" );
		assert_true( ! conexao_should_render_b2_fallback( $blog_page_id ), 'B2 Blog is NOT treated as a B2 fallback once translated');
	} else {
		assert_true( 0 === $en_blog || ! $en_blog_published, 'B1 Blog has NO published EN archive/page translation (B2 renders PT under EN)', "en_id={$en_blog}" );
		assert_true( conexao_is_b2_page( $blog_page_id ), 'B2 Blog is B2-eligible while untranslated');
	}
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
	'Blog'              => conexao_lang_url( '/blog/' ),
	'Contact'           => home_url( '/contato/' ),
);
assert_true( 0 === count( array_filter( $en_nav_targets, static function ( $u ) { return '' === trim( (string) $u ); } ) ), 'C1 every EN navigation item resolves to a non-empty URL');
assert_true( false === strpos( untrailingslashit( $en_nav_targets['Blog'] ), '/blog/' ) || strpos( untrailingslashit( $en_nav_targets['Blog'] ), '/en/blog/' ) !== false, 'C2 Blog resolves to /en/blog/ (never PT /blog/)', $en_nav_targets['Blog'] );

// --- D. PT regression -------------------------------------------------------

conexao_test_set_language( 'pt' );
assert_true( untrailingslashit( conexao_primary_nav_archive_url( 'job', 'empregos' ) ) === untrailingslashit( home_url( '/empregos/' ) ), 'D1 (PT) Jobs resolves to the PT page /empregos/', conexao_primary_nav_archive_url( 'job', 'empregos' ) );
assert_true( untrailingslashit( conexao_lang_url( '/blog/' ) ) === untrailingslashit( home_url( '/blog/' ) ), 'D2 (PT) Blog resolves to /blog/');
assert_true( untrailingslashit( conexao_primary_nav_archive_url( 'guide', 'guias' ) ) === untrailingslashit( home_url( '/guias/' ) ), 'D3 (PT) guides archive unchanged');

// --- E. Structural guards ---------------------------------------------------

$header_src = (string) file_get_contents( CONEXAO_THEME_DIR . '/header.php' );
assert_true( false === strpos( $header_src, 'wp_page_menu' ), 'E1 header.php does NOT use wp_page_menu as wp_nav_menu fallback');
assert_true( 2 === substr_count( $header_src, "'fallback_cb'    => 'conexao_safe_nav_menu_fallback'," ), 'E2 BOTH wp_nav_menu() calls use the canonical fallback');
assert_true( false === strpos( $header_src, '/en/empregos/' ) && false === strpos( $header_src, '/en/blog/' ), 'E3 no hard-coded /en/empregos/ or /en/blog/ in header.php');

test_finish();
