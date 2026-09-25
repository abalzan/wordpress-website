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

/**
 * Assert a condition.
 *
 * @param bool   $condition Condition.
 * @param string $message   Message.
 * @param string $detail    Extra detail printed on failure.
 * @return void
 */
function s9_assert( $condition, $message, $detail = '' ) {
	global $passed, $failed;

	if ( $condition ) {
		++$passed;
		echo "  PASS: {$message}\n";
		return;
	}

	++$failed;
	echo "  FAIL: {$message}" . ( '' !== $detail ? " — {$detail}" : '' ) . "\n";
}

echo "== Stage 9 — Guide EN translation ==\n";

if ( ! function_exists( 'pll_get_post' ) ) {
	echo "  SKIP: Polylang is not active.\n";
	exit( 0 );
}
if ( ! function_exists( 'conexao_guide_translation_audit' ) ) {
	echo "  SKIP: the conexao-guide-translation plugin is not loaded.\n";
	exit( 0 );
}

$audit = conexao_guide_translation_audit();

echo "\n-- completeness gate --\n";
s9_assert( 0 === (int) $audit['counts']['eligible public PT guides missing EN'], 'eligible public PT guides missing EN = 0', 'missing: ' . wp_json_encode( array_column( $audit['missing'], 'slug' ) ) );
s9_assert( 0 === (int) $audit['counts']['taxonomy terms missing EN'], 'taxonomy terms used by PT guides missing EN = 0', wp_json_encode( $audit['taxonomy']['missing'] ) );
s9_assert( (int) $audit['counts']['translated guide pairs verified'] === (int) $audit['counts']['total public PT guides'], 'one EN translation per public PT guide', $audit['counts']['translated guide pairs verified'] . ' pairs vs ' . $audit['counts']['total public PT guides'] . ' PT guides' );

// The stray EN records are the pre-existing Stage 3.2 / 4.2 editorial
// fixtures that were already in the database before this stage (they are
// reported, never created or modified here).
$stray    = wp_list_pluck( $audit['en_without_pt'], 'slug' );
$stray_ok = array( 'stage42-contract-fixture-en', 'stage32-editorial-translation' );
s9_assert( array() === array_diff( $stray, $stray_ok ), 'no EN guide without a PT translation (except the documented editorial fixtures)', wp_json_encode( $stray ) );

echo "\n-- pair integrity, shared identity, real English --\n";
$pt_stopwords = array( ' voce', ' nao ', ' sao ', ' informacoes ', ' obrigatorio ', ' preenchimento ', ' como solicitar', ' onde solicitar', ' ultima verificacao' );
foreach ( $audit['pairs'] as $pair ) {
	$pt  = get_post( (int) $pair['pt_id'] );
	$en  = get_post( (int) $pair['en_id'] );
	$tag = $pair['pt_slug'];

	if ( ! $pt instanceof WP_Post || ! $en instanceof WP_Post ) {
		s9_assert( false, "{$tag}: both records exist" );
		continue;
	}

	$ok_link = (int) pll_get_post( (int) $pt->ID, 'en' ) === (int) $en->ID
		&& (int) pll_get_post( (int) $en->ID, 'pt' ) === (int) $pt->ID;
	s9_assert( $ok_link, "{$tag}: Polylang link verified in both directions" );

	s9_assert( 'publish' === $en->post_status, "{$tag}: EN guide is published" );
	s9_assert( $en->post_date === $pt->post_date, "{$tag}: EN keeps the PT publication date" );
	s9_assert( (int) $en->post_author === (int) $pt->post_author, "{$tag}: EN keeps the PT author" );
	s9_assert( (int) $en->menu_order === (int) $pt->menu_order, "{$tag}: EN keeps the PT menu order" );
	s9_assert( 'en' === pll_get_post_language( (int) $en->ID, 'slug' ), "{$tag}: EN record language is en" );
	s9_assert( 'pt' === pll_get_post_language( (int) $pt->ID, 'slug' ), "{$tag}: PT record is still pt" );
	s9_assert( $en->post_name !== $pt->post_name, "{$tag}: EN has its own English slug", $en->post_name );
	s9_assert( '' !== trim( (string) get_post_meta( (int) $en->ID, 'conexao_meta_description', true ) ), "{$tag}: EN meta description is set" );

	$en_text = strtolower( wp_strip_all_tags( $en->post_content ) );
	$pt_text = strtolower( wp_strip_all_tags( $pt->post_content ) );
	s9_assert( $en_text !== $pt_text, "{$tag}: EN body is not a copy of the PT body" );

	$leaks = array();
	foreach ( $pt_stopwords as $word ) {
		if ( false !== strpos( $en_text, $word ) ) {
			$leaks[] = trim( $word );
		}
	}
	s9_assert( array() === $leaks, "{$tag}: EN body carries no Portuguese stop-words", wp_json_encode( $leaks ) );

	$pt_terms = array_map( 'intval', wp_get_post_terms( (int) $pt->ID, 'conexao_category', array( 'fields' => 'ids' ) ) );
	$en_terms = array_map( 'intval', wp_get_post_terms( (int) $en->ID, 'conexao_category', array( 'fields' => 'ids' ) ) );
	$expected = array();
	foreach ( $pt_terms as $pt_term_id ) {
		$expected[] = conexao_guide_translation_en_term_id( $pt_term_id );
	}
	$expected = array_filter( $expected );
	s9_assert( ! empty( $en_terms ) && array() === array_diff( $expected, $en_terms ), "{$tag}: EN guide uses the EN category term(s)", wp_json_encode( array( 'expected' => $expected, 'actual' => $en_terms ) ) );
}

echo "\n-- language-aware archive filter (theme) --\n";
if ( function_exists( 'conexao_guide_category_filter_term_id' ) ) {
	// Term lookups are language-scoped by Polylang, so the resolver can only be
	// exercised here in the current (CLI = default language) context. The
	// invariant it must satisfy is: whatever slug arrives, the returned term is
	// that concept's term IN THE CURRENT LANGUAGE. The English context is
	// covered at HTTP level by scripts/stage9-guide-http-verify.sh.
	$current = function_exists( 'conexao_current_language_slug' ) ? conexao_current_language_slug() : '';
	$pt_health = conexao_find_term_across_languages( 'saude', 'conexao_category' );
	$en_health = conexao_find_term_across_languages( 'health', 'conexao_category' );

	s9_assert( $en_health instanceof WP_Term, 'EN category term "health" exists' );
	s9_assert( $pt_health instanceof WP_Term && $en_health instanceof WP_Term
		&& (int) pll_get_term( (int) $pt_health->term_id, 'en' ) === (int) $en_health->term_id, '"saude" (pt) and "health" (en) are the same concept' );

	$expected_pt_health = (int) pll_get_term( (int) $pt_health->term_id, $current );
	s9_assert( (int) conexao_guide_category_filter_term_id( 'saude' ) === $expected_pt_health,
		'a slug in the current language resolves to that concept term', 'current language: ' . $current );
	s9_assert( (int) conexao_guide_category_filter_term_id( 'health' ) === $expected_pt_health,
		'a slug from the other language resolves to the same concept in the current language', 'current language: ' . $current );
	s9_assert( 0 === (int) conexao_guide_category_filter_term_id( 'nao-existe-este-slug' ), 'an unknown slug resolves to 0 (empty result set)' );
} else {
	s9_assert( false, 'conexao_guide_category_filter_term_id() is available' );
}

echo "\n-- Portuguese originals untouched --\n";
foreach ( array_slice( $audit['pairs'], 0, 5 ) as $pair ) {
	$pt = get_post( (int) $pair['pt_id'] );
	s9_assert( $pt instanceof WP_Post && 'guide' === $pt->post_type && 'publish' === $pt->post_status, "{$pair['pt_slug']}: PT record is a published guide" );
	s9_assert( $pt->post_name === $pair['pt_slug'], "{$pair['pt_slug']}: PT slug unchanged" );
}

$pt_guides = get_posts( array( 'post_type' => 'guide', 'post_status' => 'publish', 'lang' => 'pt', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) );
$en_guides = get_posts( array( 'post_type' => 'guide', 'post_status' => 'publish', 'lang' => 'en', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) );
s9_assert( count( $pt_guides ) >= count( $en_guides ) - 1, 'the PT archive still holds every PT guide', count( $pt_guides ) . ' pt / ' . count( $en_guides ) . ' en' );

echo "\n== {$passed} passed, {$failed} failed ==\n";
exit( $failed > 0 ? 1 : 0 );

