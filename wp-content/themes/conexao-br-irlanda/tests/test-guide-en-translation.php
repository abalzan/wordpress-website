<?php
/**
 * Stage 9 — Guide EN translation (in-process checks).
 *
 * Mirrors tests/test-job-en-translation.php (Stage 6) and asserts the
 * content/architecture contract of the Guide translation:
 *
 *  - every public Portuguese `guide` has exactly ONE linked, published EN
 *    translation (the gate: eligible public PT guides missing EN = 0);
 *  - the only EN guide without a PT sibling is the pre-existing Stage 4.2
 *    contract fixture (documented, not created by this stage);
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
 * Read-only. Requires Polylang + a completed Guide translation run.
 *
 * @package conexao-br-irlanda
 */


// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$passed = 0;
$failed = 0;


test_prerequisite_hint( 'polylang' );
test_require( function_exists( 'pll_get_post' ), 'polylang', 'test prerequisite is available: function_exists( pll_get_post )', 'activate the Polylang plugin' );
test_prerequisite_hint( 'activate-plugin:conexao-guide-translation' );
test_require( function_exists( 'conexao_guide_translation_audit' ), 'activate-plugin:conexao-guide-translation', 'test prerequisite is available: function_exists( conexao_guide_translation_audit )', 'activate the conexao-guide-translation plugin' );
$audit = conexao_guide_translation_audit();

assert_true( 0 === (int) $audit['counts']['eligible public PT guides missing EN'], 'eligible public PT guides missing EN = 0', 'missing: ' . wp_json_encode( array_column( $audit['missing'], 'slug' ) ) );
assert_true( 0 === (int) $audit['counts']['taxonomy terms missing EN'], 'taxonomy terms used by PT guides missing EN = 0', wp_json_encode( $audit['taxonomy']['missing'] ) );
assert_true( (int) $audit['counts']['translated guide pairs verified'] === (int) $audit['counts']['total public PT guides'], 'one EN translation per public PT guide', $audit['counts']['translated guide pairs verified'] . ' pairs vs ' . $audit['counts']['total public PT guides'] . ' PT guides' );

// The stray EN records are the pre-existing Stage 3.2 / 4.2 editorial
// fixtures that were already in the database before this stage (they are
// reported, never created or modified here).
$stray    = wp_list_pluck( $audit['en_without_pt'], 'slug' );
$stray_ok = array( 'stage42-contract-fixture-en', 'stage32-editorial-translation' );
assert_true( array() === array_diff( $stray, $stray_ok ), 'no EN guide without a PT translation (except the documented editorial fixtures)', wp_json_encode( $stray ) );

$pt_stopwords = array( ' voce', ' nao ', ' sao ', ' informacoes ', ' obrigatorio ', ' preenchimento ', ' como solicitar', ' onde solicitar', ' ultima verificacao' );
foreach ( $audit['pairs'] as $pair ) {
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
		$expected[] = conexao_guide_translation_en_term_id( $pt_term_id );
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

foreach ( array_slice( $audit['pairs'], 0, 5 ) as $pair ) {
	$pt = get_post( (int) $pair['pt_id'] );
	assert_true( $pt instanceof WP_Post && 'guide' === $pt->post_type && 'publish' === $pt->post_status, "{$pair['pt_slug']}: PT record is a published guide" );
	assert_true( $pt->post_name === $pair['pt_slug'], "{$pair['pt_slug']}: PT slug unchanged" );
}

$pt_guides = get_posts( array( 'post_type' => 'guide', 'post_status' => 'publish', 'lang' => 'pt', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) );
$en_guides = get_posts( array( 'post_type' => 'guide', 'post_status' => 'publish', 'lang' => 'en', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) );
assert_true( count( $pt_guides ) >= count( $en_guides ) - 1, 'the PT archive still holds every PT guide', count( $pt_guides ) . ' pt / ' . count( $en_guides ) . ' en' );

test_finish();
