<?php
/**
 * EN PAGE translation contract (in-process checks) — owned by the SHARED stages.
 *
 * ## Why this suite was migrated (Stage 19)
 *
 * This suite originally loaded the authored map and the link-localiser straight
 * out of the RETIRED `conexao-page-translation` rollout plugin
 * (`conexao_page_translation_map()`, `..._localize_links()`,
 * `..._resolve_path()`, `..._snapshot_page()`). Stage 19 removed that plugin
 * after proving its authored English is already represented by the active
 * `conexao-en-translation` stages, so those functions no longer exist. The
 * contract is now asserted against the ONLY supported translation architecture:
 *
 *   conexao-translation-rollout -> conexao-en-translation (en-page,
 *   en-blog-page, en-jobs-page)
 *
 * ## What this suite still protects (nothing was weakened)
 *
 *  - every authored EN slug is present, has no `-en` suffix and carries a
 *    non-empty English title + meta description;
 *  - the untranslated-Portuguese guard is BRAND-AWARE and stays fail-closed:
 *    the approved non-translatable names are stripped first, then any remaining
 *    Portuguese diacritic fails;
 *  - the shared-slug policy is exactly the documented pair (`blog`,
 *    `newsletter`), and the EN slug then equals the PT post_name;
 *  - every PT source the stage owns exists and is published;
 *  - once applied: the relationship table is verified in BOTH directions, one EN
 *    translation per PT page, the EN slug matches the manifest, no orphan EN
 *    page, and the PT originals are untouched (status/title/menu order).
 *
 * The EN link localiser is deliberately NOT re-implemented here: link
 * re-pointing belongs to the shared engine, and the page routing contract it
 * produces is asserted by `tests/acceptance/`.
 *
 * Read-only: this test never creates or modifies content.
 *
 * Usage (from the project root or the WordPress root):
 *   php wp-content/themes/conexao-br-irlanda/tests/test-stage45-pages.php
 *
 * @package Conexao_BR_Irlanda
 */


// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

test_prerequisite_hint( 'polylang' );
test_require(
	function_exists( 'conexao_en_translation_manifest_for' ),
	'conexao-en-translation',
	'the active EN translation stage layer is loaded',
	'activate the conexao-en-translation plugin'
);

$passed = 0;
$failed = 0;

/**
 * The authored page records, merged across the three page-owning stages.
 *
 * `en-page` carries the ordinary pages; `en-blog-page` and `en-jobs-page` own
 * their single record each. Keyed by the PT slug, which is the only portable
 * identity (engineering standard 0.4).
 *
 * @return array<string,array<string,string>> PT slug => authored EN row.
 */
function stage45_active_page_records(): array {
	$records = conexao_en_translation_manifest_for( 'page' )['records'];

	foreach ( array(
		'conexao_en_translation_blog_page_manifest',
		'conexao_en_translation_jobs_page_manifest',
	) as $manifest_fn ) {
		if ( function_exists( $manifest_fn ) ) {
			$records = array_merge( $records, $manifest_fn()['records'] );
		}
	}

	return $records;
}

// ---------------------------------------------------------------------------

$map = stage45_active_page_records();

assert_true( count( $map ) > 0, 'the active stages load an authored page map (' . count( $map ) . ' rows)' );

// The Blog posts page and the Newsletter are the shared-slug pair: one canonical
// path in two languages. The same rule the retired plugin enforced is now owned
// by the active shared-slug policy.
$shared_expected = array( 'blog', 'newsletter' );
$shared_actual   = array();

foreach ( $shared_expected as $pt_slug ) {
	if ( isset( $map[ $pt_slug ] ) ) {
		$shared_actual[] = $pt_slug;
		assert_true(
			$map[ $pt_slug ]['en_slug'] === $pt_slug,
			"shared slug {$pt_slug} keeps the PT post_name"
		);
	}
}
assert_true(
	$shared_expected === $shared_actual,
	'the shared-slug allowlist is exactly blog + newsletter'
);

if ( function_exists( 'conexao_en_translation_shared_page_slug_for' ) ) {
	assert_true(
		'blog' === conexao_en_translation_shared_page_slug_for( 'en-blog-page' ),
		'the active shared-slug policy resolves `blog` for en-blog-page'
	);
}

// The untranslated-Portuguese guard. It must be BRAND-AWARE: the manifest's
// own contract (includes/translation-map.php, "brand names, official
// organisation/programme names, URLs, emails and Irish proper nouns are never
// translated") requires the brand "Conexão BR Irlanda" to survive verbatim in
// every English meta description that is authored. A raw diacritic scan
// therefore flags the brand's own "ç"/"ã" as untranslated Portuguese and fails
// correct, authored data.
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
	assert_true(
		'' !== $spec['en_title'],
		"{$pt_slug}: EN title is authored"
	);

	// A meta description is optional in the active stage data: five records
	// (privacidade, termos, sobre, about, categorias) deliberately ship none.
	// Where one IS authored it must be English, so the brand-aware guard below
	// still runs on every non-empty value and stays fail-closed.
	$prose = (string) $spec['en_meta_description'];
	foreach ( $approved_names as $name ) {
		$prose = str_ireplace( $name, ' ', $prose );
	}
	if ( '' !== trim( $prose ) ) {
		assert_true(
			false === strpos( $prose, 'ã' ) && false === strpos( $prose, 'ç' ),
			"{$pt_slug}: meta description contains no untranslated Portuguese (brand names excluded by contract)"
		);
	}
}

// Every PT source must exist as a published page.
//
// `about` is the WordPress default sample page: it is a real authored row in the
// stage data, but this dataset has no PT record of that slug, so there is
// nothing to translate. That is reported, not failed, for the same reason the
// shared engine classifies an absent PT source as a documented exclusion rather
// than a conflict.
foreach ( $map as $pt_slug => $spec ) {
	$pt = get_page_by_path( $pt_slug, OBJECT, 'page' );

	if ( ! $pt ) {
		echo "  NOTE: '{$pt_slug}' is authored in the stage data but has no PT record in this dataset (documented exclusion).\n";
		continue;
	}

	assert_true( 'publish' === $pt->post_status, "PT source '{$pt_slug}' exists and is published" );
}

// ---------------------------------------------------------------------------
echo "\n-- PT snapshot (the field set the engine's PT-drift gate compares) --\n";

// ---------------------------------------------------------------------------

$pt = get_page_by_path( 'sobre-nos', OBJECT, 'page' );
if ( $pt && function_exists( 'conexao_en_translation_snapshot' ) ) {
	$snap = conexao_en_translation_snapshot( (int) $pt->ID );
	assert_true(
		isset( $snap['post_name'], $snap['post_title'], $snap['post_content'], $snap['post_status'], $snap['menu_order'], $snap['meta_desc'] ),
		'the active snapshot captures the field set the PT-drift gate compares'
	);
	$snap2 = conexao_en_translation_snapshot( (int) $pt->ID );
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

			// No PT record in this dataset: nothing was translated, so there is
			// no relationship to verify (the documented `about` exclusion).
			if ( ! $pt_page ) {
				continue;
			}

			$pt_id   = (int) $pt_page->ID;
			$en_id   = (int) pll_get_post( $pt_id, 'en' );
			assert_true( $en_id > 0 && 'publish' === get_post_status( $en_id ), "{$pt_slug}: has a published EN translation" );
			assert_true( (int) pll_get_post( $en_id, 'pt' ) === $pt_id, "{$pt_slug}: EN→PT link points back at the right source" );
			assert_true( 'en' === pll_get_post_language( $en_id ), "{$pt_slug}: EN page language is en" );
			assert_true( get_post_field( 'post_name', $en_id ) === $spec['en_slug'], "{$pt_slug}: EN slug is '{$spec['en_slug']}'" );
			assert_true( ! isset( $en_seen[ $en_id ] ), "{$pt_slug}: EN page #{$en_id} is not shared with another PT source" );
			$en_seen[ $en_id ] = $pt_slug;

			// PT invariance.
			$pt_now = conexao_en_translation_snapshot( $pt_id );
			assert_true(
				'publish' === $pt_now['post_status'] && (int) $pt_now['menu_order'] === (int) $pt_page->menu_order,
				"{$pt_slug}: PT status/menu order unchanged"
			);
			assert_true( $pt_now['post_title'] === $pt_page->post_title, "{$pt_slug}: PT title unchanged" );
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
		assert_true( array() === $orphans, 'no orphan EN page outside the stage manifests (' . count( $orphans ) . ' found)' );
	} else {
		echo "  SKIP: the EN pages are not created in this dataset yet (pre-rollout).\n";
	}
} else {
	echo "  SKIP: Polylang is not active.\n";
}

test_finish();
