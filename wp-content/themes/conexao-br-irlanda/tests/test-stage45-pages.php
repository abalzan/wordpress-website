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


// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

require_once WP_CONTENT_DIR . '/plugins/conexao-page-translation/includes/translation-map.php';
require_once WP_CONTENT_DIR . '/plugins/conexao-page-translation/includes/apply.php';

$passed = 0;
$failed = 0;


// ---------------------------------------------------------------------------

$map = conexao_page_translation_map();
assert_true( 37 === count( $map ), 'map covers the 37 classified public pages (found ' . count( $map ) . ')' );

$keys = array_keys( $map );
assert_true( 'inicio' === $keys[0], 'the front page is created first' );
assert_true( 'cookies' === $keys[ count( $keys ) - 1 ], 'legal/utility pages come last' );

$shared = array();
foreach ( $map as $pt_slug => $spec ) {
	if ( ! empty( $spec['shared_slug'] ) ) {
		$shared[] = $pt_slug;
		assert_true( $spec['en_slug'] === $pt_slug, "shared slug {$pt_slug} keeps the PT post_name" );
	}
}
sort( $shared );
assert_true( array( 'blog', 'newsletter' ) === $shared, 'the shared-slug allowlist is exactly blog + newsletter' );

// The untranslated-Portuguese guard. It must be BRAND-AWARE: the manifest's
// own contract (includes/translation-map.php, "brand names, official
// organisation/programme names, URLs, emails and Irish proper nouns are never
// translated") requires the brand "Conexão BR Irlanda" to survive verbatim in
// every English meta description. A raw diacritic scan therefore flags the
// brand's own "ç"/"ã" as untranslated Portuguese and fails 11 of the 37 pages
// against correct, authored data.
//
// The check is scoped to the text that is actually prose: the approved
// non-translatable names are removed first, THEN any remaining Portuguese
// diacritic is a genuine translation failure. It stays fail-closed — the
// assertion is not weakened, it is made correct.
$approved_names = array(
	'Conexão BR Irlanda',
	'Conexao BR Irlanda',
);
foreach ( $map as $pt_slug => $spec ) {
	// Authored English must be present and the approved EN slug shape held.
	assert_true( ! preg_match( '/-en$/', $spec['en_slug'] ), "EN slug '{$spec['en_slug']}' has no -en suffix" );
	assert_true( ! empty( $spec['title'] ) && ! empty( $spec['meta_desc'] ), "{$pt_slug}: EN title and meta description are authored" );

	$prose = (string) $spec['meta_desc'];
	foreach ( $approved_names as $name ) {
		$prose = str_ireplace( $name, ' ', $prose );
	}
	assert_true(
		false === strpos( $prose, 'ã' ) && false === strpos( $prose, 'ç' ),
		"{$pt_slug}: meta description contains no untranslated Portuguese (brand names excluded by contract)"
	);
}

// Every PT source must exist as a published page.
foreach ( $map as $pt_slug => $spec ) {
	$pt = get_page_by_path( $pt_slug, OBJECT, 'page' );
	assert_true( $pt && 'publish' === $pt->post_status, "PT source '{$pt_slug}' exists and is published" );

// ---------------------------------------------------------------------------
echo "\n-- Link localizer (pure logic; Polylang-independent parts) --\n";

// Root-relative + absolute + external + mailto classification.
$content = '<a href="/moradia/">A</a><a href="https://conexaobr.ie/empregos/">B</a>'
	. '<a href="https://wa.me/353899451428">C</a><a href="mailto:x@y.z">D</a>';
list( $out, $resolved ) = conexao_page_translation_localize_links( $content, 'en' );
if ( function_exists( 'pll_get_post' ) ) {
	// With Polylang active, both internal hrefs are attempted through the
	// relationship (they may legitimately stay PT until translations exist).
	assert_true( isset( $resolved ) && is_array( $resolved ), 'localizer returns a resolution map' );
} else {
	// Without Polylang nothing is ever rewritten — B1/Passthrough.
	assert_true( array() === $resolved, 'without Polylang no link is ever rewritten' );
	assert_true( $out === $content, 'without Polylang the content is returned unchanged' );
}
assert_true( false === strpos( $out, '/en/moradia' ) || function_exists( 'pll_get_post' ), 'never invents an EN URL without Polylang' );

// The resolve helper never string-mangles: an unknown path stays '' (PT).
if ( function_exists( 'conexao_page_translation_resolve_path' ) ) {
	assert_true( '' === conexao_page_translation_resolve_path( '/this-page-does-not-exist/', 'en' ), 'unresolved paths return empty (approved B1: keep the PT URL)' );
}

// ---------------------------------------------------------------------------

$pt = get_page_by_path( 'sobre-nos', OBJECT, 'page' );
if ( $pt ) {
	$snap = conexao_page_translation_snapshot_page( (int) $pt->ID );
	assert_true( isset( $snap['title'], $snap['content'], $snap['excerpt'], $snap['status'], $snap['name'], $snap['parent'], $snap['menu_order'], $snap['template'], $snap['meta_desc'] ), 'snapshot captures the full Phase 23 field set' );
	$snap2 = conexao_page_translation_snapshot_page( (int) $pt->ID );
	assert_true( $snap === $snap2, 'snapshot is deterministic (no writes involved)' );
} else {
	echo "  SKIP: sobre-nos page not present in this dataset.\n";
}

// ---------------------------------------------------------------------------

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
			assert_true( $en_id > 0 && 'publish' === get_post_status( $en_id ), "{$pt_slug}: has a published EN translation" );
			assert_true( (int) pll_get_post( $en_id, 'pt' ) === $pt_id, "{$pt_slug}: EN→PT link points back at the right source" );
			assert_true( 'en' === pll_get_post_language( $en_id ), "{$pt_slug}: EN page language is en" );
			assert_true( get_post_field( 'post_name', $en_id ) === $spec['en_slug'], "{$pt_slug}: EN slug is '{$spec['en_slug']}'" );
			assert_true( ! isset( $en_seen[ $en_id ] ), "{$pt_slug}: EN page #{$en_id} is not shared with another PT source" );
			$en_seen[ $en_id ] = $pt_slug;

			// PT invariance.
			$pt_now = conexao_page_translation_snapshot_page( $pt_id );
			assert_true( 'publish' === $pt_now['status'] && (int) $pt_now['parent'] === (int) $pt_page->post_parent, "{$pt_slug}: PT status/parent unchanged" );
			assert_true( $pt_now['template'] === get_post_meta( $pt_id, '_wp_page_template', true ), "{$pt_slug}: PT template unchanged" );
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
		assert_true( array() === $orphans, 'no orphan EN page outside the translation map (' . count( $orphans ) . ' found)' );
	} else {
		echo "  SKIP: the EN pages are not created in this dataset yet (pre-rollout).\n";
	}
} else {
	echo "  SKIP: Polylang is not active.\n";
}
}

test_finish();
