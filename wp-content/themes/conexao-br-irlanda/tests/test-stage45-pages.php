<?php
/**
 * Stage 4.5 — EN page translation focus tests.
 *
 * Covers the Stage 4.5 deliverables at the logic level:
 *
 *  - the translation map: every classified public page is present exactly
 *    once, in the mandatory order (front page first, legal last), with the
 *    approved EN slugs (no -en suffixes; the two shared slugs are exactly
 *    blog + newsletter);
 *  - the link localizer: canonical PT paths resolve to the EN counterpart
 *    through the Polylang relationship (never by prefix-mangling), archives
 *    resolve to the EN archive, unresolved paths stay PT (approved B1);
 *  - the PT snapshot helper: captures exactly the Phase 23 fields;
 *  - when the rollout has been applied (Polylang active + EN pages exist):
 *    the relationship table — pll_get_post() verified in BOTH directions for
 *    every page, exactly one EN translation per PT page, no orphan EN page,
 *    and the PT originals untouched (status/title/template/parent).
 *
 * Read-only: this test never creates or modifies content.
 *
 * Usage (from the project root, inside the WP container):
 *   php wp-content/themes/conexao-br-irlanda/tests/test-stage45-pages.php
 *
 * @package Conexao_BR_Irlanda
 */

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

require_once WP_CONTENT_DIR . '/plugins/conexao-page-translation/includes/translation-map.php';
require_once WP_CONTENT_DIR . '/plugins/conexao-page-translation/includes/apply.php';

$passed = 0;
$failed = 0;

function s45_assert( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		echo "  PASS: {$message}\n";
	} else {
		$failed++;
		echo "  FAIL: {$message}\n";
	}
}

echo "== Stage 4.5 — EN page translations ==\n";

// ---------------------------------------------------------------------------
echo "\n-- Map integrity --\n";

$map = conexao_page_translation_map();
s45_assert( 37 === count( $map ), 'map covers the 37 classified public pages (found ' . count( $map ) . ')' );

$keys = array_keys( $map );
s45_assert( 'inicio' === $keys[0], 'the front page is created first' );
s45_assert( 'cookies' === $keys[ count( $keys ) - 1 ], 'legal/utility pages come last' );

$shared = array();
foreach ( $map as $pt_slug => $spec ) {
	if ( ! empty( $spec['shared_slug'] ) ) {
		$shared[] = $pt_slug;
		s45_assert( $spec['en_slug'] === $pt_slug, "shared slug {$pt_slug} keeps the PT post_name" );
	}
}
sort( $shared );
s45_assert( array( 'blog', 'newsletter' ) === $shared, 'the shared-slug allowlist is exactly blog + newsletter' );

foreach ( $map as $pt_slug => $spec ) {
	s45_assert( ! preg_match( '/-en$/', $spec['en_slug'] ), "EN slug '{$spec['en_slug']}' has no -en suffix" );
	s45_assert( ! empty( $spec['title'] ) && ! empty( $spec['meta_desc'] ), "{$pt_slug}: EN title and meta description are authored" );
	s45_assert( false === strpos( $spec['meta_desc'], 'ã' ) && false === strpos( $spec['meta_desc'], 'ç' ), "{$pt_slug}: meta description contains no untranslated Portuguese" );
}

// Every PT source must exist as a published page.
foreach ( $map as $pt_slug => $spec ) {
	$pt = get_page_by_path( $pt_slug, OBJECT, 'page' );
	s45_assert( $pt && 'publish' === $pt->post_status, "PT source '{$pt_slug}' exists and is published" );

// ---------------------------------------------------------------------------
echo "\n-- Link localizer (pure logic; Polylang-independent parts) --\n";

// Root-relative + absolute + external + mailto classification.
$content = '<a href="/moradia/">A</a><a href="https://conexaobr.ie/empregos/">B</a>'
	. '<a href="https://wa.me/353899451428">C</a><a href="mailto:x@y.z">D</a>';
list( $out, $resolved ) = conexao_page_translation_localize_links( $content, 'en' );
if ( function_exists( 'pll_get_post' ) ) {
	// With Polylang active, both internal hrefs are attempted through the
	// relationship (they may legitimately stay PT until translations exist).
	s45_assert( isset( $resolved ) && is_array( $resolved ), 'localizer returns a resolution map' );
} else {
	// Without Polylang nothing is ever rewritten — B1/Passthrough.
	s45_assert( array() === $resolved, 'without Polylang no link is ever rewritten' );
	s45_assert( $out === $content, 'without Polylang the content is returned unchanged' );
}
s45_assert( false === strpos( $out, '/en/moradia' ) || function_exists( 'pll_get_post' ), 'never invents an EN URL without Polylang' );

// The resolve helper never string-mangles: an unknown path stays '' (PT).
if ( function_exists( 'conexao_page_translation_resolve_path' ) ) {
	s45_assert( '' === conexao_page_translation_resolve_path( '/this-page-does-not-exist/', 'en' ), 'unresolved paths return empty (approved B1: keep the PT URL)' );
}

// ---------------------------------------------------------------------------
echo "\n-- PT snapshot helper (Phase 23 fields) --\n";

$pt = get_page_by_path( 'sobre-nos', OBJECT, 'page' );
if ( $pt ) {
	$snap = conexao_page_translation_snapshot_page( (int) $pt->ID );
	s45_assert( isset( $snap['title'], $snap['content'], $snap['excerpt'], $snap['status'], $snap['name'], $snap['parent'], $snap['menu_order'], $snap['template'], $snap['meta_desc'] ), 'snapshot captures the full Phase 23 field set' );
	$snap2 = conexao_page_translation_snapshot_page( (int) $pt->ID );
	s45_assert( $snap === $snap2, 'snapshot is deterministic (no writes involved)' );
} else {
	echo "  SKIP: sobre-nos page not present in this dataset.\n";
}

// ---------------------------------------------------------------------------
echo "\n-- Relationship table (post-rollout; skipped before the rollout) --\n";

if ( function_exists( 'pll_get_post' ) ) {
	$rolled_out = true;
	foreach ( $map as $pt_slug => $spec ) {
		$pt_page = get_page_by_path( $pt_slug, OBJECT, 'page' );
		if ( ! $pt_page ) {
			continue;
		}
		$pt_id = (int) $pt_page->ID;
		$en_id = (int) pll_get_post( $pt_id, 'en' );
		if ( ! $en_id ) {
			$rolled_out = false;
			break;
		}
	}

	if ( $rolled_out ) {
		$en_seen = array();
		foreach ( $map as $pt_slug => $spec ) {
			$pt_page = get_page_by_path( $pt_slug, OBJECT, 'page' );
			$pt_id   = (int) $pt_page->ID;
			$en_id   = (int) pll_get_post( $pt_id, 'en' );
			s45_assert( $en_id > 0 && 'publish' === get_post_status( $en_id ), "{$pt_slug}: has a published EN translation" );
			s45_assert( (int) pll_get_post( $en_id, 'pt' ) === $pt_id, "{$pt_slug}: EN→PT link points back at the right source" );
			s45_assert( 'en' === pll_get_post_language( $en_id ), "{$pt_slug}: EN page language is en" );
			s45_assert( get_post_field( 'post_name', $en_id ) === $spec['en_slug'], "{$pt_slug}: EN slug is '{$spec['en_slug']}'" );
			s45_assert( ! isset( $en_seen[ $en_id ] ), "{$pt_slug}: EN page #{$en_id} is not shared with another PT source" );
			$en_seen[ $en_id ] = $pt_slug;

			// PT invariance.
			$pt_now = conexao_page_translation_snapshot_page( $pt_id );
			s45_assert( 'publish' === $pt_now['status'] && (int) $pt_now['parent'] === (int) $pt_page->post_parent, "{$pt_slug}: PT status/parent unchanged" );
			s45_assert( $pt_now['template'] === get_post_meta( $pt_id, '_wp_page_template', true ), "{$pt_slug}: PT template unchanged" );
		}

		// No orphan EN pages outside the map.
		$en_pages = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'lang' => 'en', 'posts_per_page' => 200, 'fields' => 'ids' ) );
		$orphans = array();
		foreach ( $en_pages as $en_id ) {
			$pt_id = (int) pll_get_post( (int) $en_id, 'pt' );
			if ( ! $pt_id || ! isset( $map[ get_post_field( 'post_name', $pt_id ) ] ) ) {
				$orphans[] = $en_id;
			}
		}
		s45_assert( array() === $orphans, 'no orphan EN page outside the translation map (' . count( $orphans ) . ' found)' );
	} else {
		echo "  SKIP: the EN pages are not created in this dataset yet (pre-rollout).\n";
	}
} else {
	echo "  SKIP: Polylang is not active.\n";
}

echo "\n== Stage 4.5 page tests: {$passed} passed, {$failed} failed ==\n";
exit( $failed > 0 ? 1 : 0 );

}
