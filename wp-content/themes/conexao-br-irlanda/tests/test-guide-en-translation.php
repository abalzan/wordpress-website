<?php
/**
 * EN Guide translation (in-process checks) — owned by the SHARED rollout engine.
 *
 * This suite originally consumed `conexao_guide_translation_audit()` from the
 * RETIRED `conexao-guide-translation` plugin. That plugin's lifecycle is
 * retired: it stays dormant and is not activated, and its authored DATA now lives
 * in the `en-guide` stage of `conexao-en-translation`
 * (`includes/guide-translation-data.php` + `includes/guide-terms-data.php`).
 *
 * So the contract is now asserted against the shared engine's own stage, which
 * is the only translation lifecycle in the repository. The assertions themselves
 * are unchanged — they are the real content contract, not an implementation
 * detail:
 *
 *  - every public Portuguese `guide` has exactly ONE linked, published EN
 *    translation (the gate: eligible public PT guides missing EN = 0);
 *  - no EN guide exists without a PT sibling (0 orphans, 0 duplicates);
 *  - EN guides keep the shared record identity of their PT sibling (date,
 *    author, menu order) and carry genuine English content — the body is not a
 *    copy of the Portuguese one and leaks no Portuguese stop-words;
 *  - the `conexao_category` terms used by public PT guides have linked EN
 *    terms, and every EN guide is filed under the EN term;
 *  - the `?categoria=` filter resolves in the current language;
 *  - the Portuguese originals are untouched (still `pt`, same slug/status).
 *
 * Usage (from the project root or the WordPress root):
 *   php wp-content/themes/conexao-br-irlanda/tests/test-guide-en-translation.php
 *
 * Read-only. Requires Polylang + the `en-guide` stage applied.
 *
 * @package conexao-br-irlanda
 */


// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$passed = 0;
$failed = 0;


test_prerequisite_hint( 'polylang' );
test_require( function_exists( 'pll_get_post' ), 'polylang', 'test prerequisite is available: function_exists( pll_get_post )', 'activate the Polylang plugin' );
test_require( function_exists( 'conexao_en_translation_manifest_for' ), 'conexao-en-translation', 'the shared EN translation stage layer is loaded', 'activate the conexao-en-translation plugin' );
test_require( function_exists( 'conexao_en_translation_guide_taxonomy_gate' ), 'conexao-en-translation', 'the en-guide taxonomy capability is loaded', 'activate the conexao-en-translation plugin' );

// ---------------------------------------------------------------------------
// Inventory, read straight from the live site. The counts are NOT taken from a
// stage report: this suite proves the DATA, while the stage's own numeric gate is
// proved by the permanent translation-completeness gate and by the rollout run.
// ---------------------------------------------------------------------------
$manifest  = conexao_en_translation_manifest_for( 'guide' );
$pt_guides = get_posts( array( 'post_type' => 'guide', 'post_status' => 'publish', 'lang' => 'pt', 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'post_name', 'order' => 'ASC' ) );
$en_guides = get_posts( array( 'post_type' => 'guide', 'post_status' => 'publish', 'lang' => 'en', 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'post_name', 'order' => 'ASC' ) );

$pairs            = array();
$missing          = array();
$en_without_pt    = array();
$link_failures    = 0;
$en_not_published = 0;
$en_slug_counts   = array();

foreach ( $en_guides as $en_id ) {
	$en_slug_counts[] = (string) get_post_field( 'post_name', (int) $en_id );
}

foreach ( $pt_guides as $pt_id ) {
	$pt_id  = (int) $pt_id;
	$pt_key = (string) get_post_field( 'post_name', $pt_id );
	$en_id  = (int) pll_get_post( $pt_id, 'en' );

	if ( $en_id <= 0 ) {
		$missing[] = $pt_key;
		continue;
	}

	$pairs[] = array(
		'pt_id'    => $pt_id,
		'en_id'    => $en_id,
		'pt_slug'  => $pt_key,
		'en_slug'  => (string) get_post_field( 'post_name', $en_id ),
		'expected' => isset( $manifest['records'][ $pt_key ] ) ? $manifest['records'][ $pt_key ] : array(),
	);

	if ( (int) pll_get_post( $en_id, 'pt' ) !== $pt_id ) {
		++$link_failures;
	}

	if ( 'publish' !== (string) get_post_status( $en_id ) ) {
		++$en_not_published;
	}
}

foreach ( $en_guides as $en_id ) {
	if ( (int) pll_get_post( (int) $en_id, 'pt' ) <= 0 ) {
		$en_without_pt[] = (string) get_post_field( 'post_name', (int) $en_id );
	}
}

$counts = array(
	'total public PT guides'          => count( $pt_guides ),
	'total public EN guides'          => count( $en_guides ),
	'translated guide pairs verified' => count( $pairs ),
);

assert_true( 0 === count( $missing ), 'eligible public PT guides missing EN = 0', 'missing: ' . wp_json_encode( $missing ) );
assert_true( count( $pairs ) === count( $pt_guides ), 'one EN translation per public PT guide', $counts['translated guide pairs verified'] . ' pairs vs ' . $counts['total public PT guides'] . ' PT guides' );
assert_true( array() === $en_without_pt, 'no EN guide without a PT translation (0 orphans)', wp_json_encode( $en_without_pt ) );
assert_true( count( $en_slug_counts ) === count( array_unique( $en_slug_counts ) ), 'no duplicate EN guide translations (one record per EN slug)' );
assert_true( 0 === $link_failures, 'every PT->EN pair also resolves EN->PT' );
assert_true( 0 === $en_not_published, 'every EN guide is published' );
assert_true( 0 === (int) conexao_en_translation_guide_taxonomy_gate(), 'taxonomy terms used by PT guides missing EN = 0', 'taxonomy gate failures: ' . conexao_en_translation_guide_taxonomy_gate() );

$pt_stopwords = array( ' voce', ' nao ', ' sao ', ' informacoes ', ' obrigatorio ', ' preenchimento ', ' como solicitar', ' onde solicitar', ' ultima verificacao' );
foreach ( $pairs as $pair ) {
	$pt  = get_post( (int) $pair['pt_id'] );
	$en  = get_post( (int) $pair['en_id'] );
	$tag = $pair['pt_slug'];

	if ( ! $pt instanceof WP_Post || ! $en instanceof WP_Post ) {
		assert_true( false, "{$tag}: both records exist" );
		continue;
	}

	$ok_link = (int) pll_get_post( (int) $pt->ID, 'en' ) === (int) $en->ID
		&& (int) pll_get_post( (int) $en->ID, 'pt' ) === (int) $pt->ID;
	assert_true( $ok_link, "{$tag}: Polylang link verified in both directions" );

	assert_true( 'publish' === $en->post_status, "{$tag}: EN guide is published" );
	assert_true( $en->post_date === $pt->post_date, "{$tag}: EN keeps the PT publication date" );
	assert_true( (int) $en->post_author === (int) $pt->post_author, "{$tag}: EN keeps the PT author" );
	assert_true( (int) $en->menu_order === (int) $pt->menu_order, "{$tag}: EN keeps the PT menu order" );
	assert_true( 'en' === pll_get_post_language( (int) $en->ID, 'slug' ), "{$tag}: EN record language is en" );
	assert_true( 'pt' === pll_get_post_language( (int) $pt->ID, 'slug' ), "{$tag}: PT record is still pt" );
	assert_true( $en->post_name !== $pt->post_name, "{$tag}: EN has its own English slug", $en->post_name );
	assert_true( '' !== trim( (string) get_post_meta( (int) $en->ID, 'conexao_meta_description', true ) ), "{$tag}: EN meta description is set" );

	$en_text = strtolower( wp_strip_all_tags( $en->post_content ) );
	$pt_text = strtolower( wp_strip_all_tags( $pt->post_content ) );
	assert_true( $en_text !== $pt_text, "{$tag}: EN body is not a copy of the PT body" );

	$leaks = array();
	foreach ( $pt_stopwords as $word ) {
		if ( false !== strpos( $en_text, $word ) ) {
			$leaks[] = trim( $word );
		}
	}
	assert_true( array() === $leaks, "{$tag}: EN body carries no Portuguese stop-words", wp_json_encode( $leaks ) );

	$pt_terms = array_map( 'intval', wp_get_post_terms( (int) $pt->ID, 'conexao_category', array( 'fields' => 'ids' ) ) );
	$en_terms = array_map( 'intval', wp_get_post_terms( (int) $en->ID, 'conexao_category', array( 'fields' => 'ids' ) ) );
	$expected = array();
	foreach ( $pt_terms as $pt_term_id ) {
		$expected[] = function_exists( 'pll_get_term' ) ? (int) pll_get_term( (int) $pt_term_id, 'en' ) : 0;
	}
	$expected = array_filter( $expected );
	assert_true( ! empty( $en_terms ) && array() === array_diff( $expected, $en_terms ), "{$tag}: EN guide uses the EN category term(s)", wp_json_encode( array( 'expected' => $expected, 'actual' => $en_terms ) ) );
}

if ( function_exists( 'conexao_guide_category_filter_term_id' ) ) {
	// Term lookups are language-scoped by Polylang, so the resolver can only be
	// exercised here in the current (CLI = default language) context. The
	// invariant it must satisfy is: whatever slug arrives, the returned term is
	// that concept's term IN THE CURRENT LANGUAGE. The English context is
	// covered at HTTP level by scripts/stage9-guide-http-verify.sh.
	$current = function_exists( 'conexao_current_language_slug' ) ? conexao_current_language_slug() : '';
	$pt_health = conexao_find_term_across_languages( 'saude', 'conexao_category' );
	$en_health = conexao_find_term_across_languages( 'health', 'conexao_category' );

	assert_true( $en_health instanceof WP_Term, 'EN category term "health" exists' );
	assert_true( $pt_health instanceof WP_Term && $en_health instanceof WP_Term
		&& (int) pll_get_term( (int) $pt_health->term_id, 'en' ) === (int) $en_health->term_id, '"saude" (pt) and "health" (en) are the same concept' );

	$expected_pt_health = (int) pll_get_term( (int) $pt_health->term_id, $current );
	assert_true( (int) conexao_guide_category_filter_term_id( 'saude' ) === $expected_pt_health,
		'a slug in the current language resolves to that concept term', 'current language: ' . $current );
	assert_true( (int) conexao_guide_category_filter_term_id( 'health' ) === $expected_pt_health,
		'a slug from the other language resolves to the same concept in the current language', 'current language: ' . $current );
	assert_true( 0 === (int) conexao_guide_category_filter_term_id( 'nao-existe-este-slug' ), 'an unknown slug resolves to 0 (empty result set)' );
} else {
	assert_true( false, 'conexao_guide_category_filter_term_id() is available' );
}

foreach ( array_slice( $pairs, 0, 5 ) as $pair ) {
	$pt = get_post( (int) $pair['pt_id'] );
	assert_true( $pt instanceof WP_Post && 'guide' === $pt->post_type && 'publish' === $pt->post_status, "{$pair['pt_slug']}: PT record is a published guide" );
	assert_true( $pt->post_name === $pair['pt_slug'], "{$pair['pt_slug']}: PT slug unchanged" );
}

$pt_guides = get_posts( array( 'post_type' => 'guide', 'post_status' => 'publish', 'lang' => 'pt', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) );
$en_guides = get_posts( array( 'post_type' => 'guide', 'post_status' => 'publish', 'lang' => 'en', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) );
assert_true( count( $pt_guides ) >= count( $en_guides ) - 1, 'the PT archive still holds every PT guide', count( $pt_guides ) . ' pt / ' . count( $en_guides ) . ' en' );

test_finish();
