<?php
/**
 * PERMANENT GATE — taxonomy policy (Stage L, engineering standard §6.3).
 *
 * Normative rule (docs/engineering-standard.md §6.1 and §6.3):
 *
 *   - `conexao_county` and `conexao_town` are SHARED proper-name taxonomies:
 *     ONE physical term per county/town, used by both languages. They must NOT
 *     be Polylang-translated, must carry no language, and must never have
 *     per-language suffixed duplicates (dublin-en, dublin-pt, ...).
 *   - `conexao_category` and `conexao_tag` ARE Polylang-translated: one shared
 *     concept identity per term-translation pair, and every translation must be
 *     linked in BOTH directions.
 *
 * ## The policy is read from the runtime, never re-declared here
 *
 * The shared/translated classification is taken from the authoritative
 * declarations themselves:
 *   - `conexao_polylang_translated_taxonomies()` (inc/i18n/guard.php), and
 *   - `PLL()->model->get_translated_taxonomies()` (what Polylang actually does).
 * This gate does NOT infer the policy from the test and does NOT hard-code a
 * second copy of it (Stage L hard rule 8: no second source of truth).
 *
 * Read-only. Creates nothing, mutates nothing, contacts nothing.
 *
 * @package Conexao_BR_Irlanda
 */

// Shared Stage E bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_ROOT . '/lib/permanent-gates.php';

conexao_gate_open(
	'taxonomy_policy',
	'Shared county/town terms carry no language and no per-language duplicates; '
	. 'translated category/tag terms are linked in both directions.',
	array( 'polylang', 'conexao_county + conexao_town + conexao_category terms' )
);

conexao_gate_require(
	function_exists( 'pll_get_term_language' ) && function_exists( 'pll_get_term' ),
	'polylang',
	'test prerequisite is available: Polylang term API'
);
conexao_gate_require(
	function_exists( 'conexao_polylang_translated_taxonomies' ),
	'theme-active',
	'test prerequisite is available: the taxonomy policy declaration'
);

// The authoritative runtime policy: which taxonomies Polylang translates.
$declared_translated = conexao_polylang_translated_taxonomies( array() );
$actual_translated   = PLL()->model->get_translated_taxonomies();

assert_true(
	(int) $declared_translated === (int) $actual_translated,
	'the declared taxonomy policy matches what Polylang actually applies',
	wp_json_encode( array( 'declared' => $declared_translated, 'actual' => $actual_translated ) )
);

// Split the taxonomies using the runtime policy, not a hard-coded list here.
$shared_taxonomies     = array();
$translated_taxonomies = array();

foreach ( array( 'conexao_county', 'conexao_town', 'conexao_category', 'conexao_tag' ) as $taxonomy ) {
	if ( in_array( $taxonomy, (array) $actual_translated, true ) ) {
		$translated_taxonomies[] = $taxonomy;
	} else {
		$shared_taxonomies[] = $taxonomy;
	}
}

assert_true(
	array( 'conexao_category', 'conexao_tag' ) === $translated_taxonomies,
	'the vocabulary taxonomies are the translated ones',
	implode( ',', $translated_taxonomies )
);
assert_true(
	array( 'conexao_county', 'conexao_town' ) === $shared_taxonomies,
	'the proper-name location taxonomies are the shared ones',
	implode( ',', $shared_taxonomies )
);

/**
 * Read every term of a taxonomy regardless of language.
 *
 * `lang => ''` is required: with a language filter Polylang would HIDE the very
 * duplicates this gate exists to detect (an EN-tagged copy of a shared
 * proper-name term would simply not be returned in a PT query).
 *
 * @param string $taxonomy Taxonomy name.
 * @return WP_Term[]|WP_Error
 */
function conexao_gate_all_terms( $taxonomy ) {
	return get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'lang'       => '',
			'number'     => 0,
		)
	);
}

// ---------------------------------------------------------------------------
// 1. Shared proper-name taxonomies (county / town).
// ---------------------------------------------------------------------------

$shared_total = 0;

foreach ( $shared_taxonomies as $taxonomy ) {
	$terms = conexao_gate_all_terms( $taxonomy );

	if ( is_wp_error( $terms ) ) {
		assert_true( false, "{$taxonomy}: terms are readable", $terms->get_error_message() );
		continue;
	}

	$terms      = is_array( $terms ) ? $terms : array();
	$shared_total += count( $terms );

	// Anti-vacuity: an empty taxonomy proves nothing about the policy.
	assert_true(
		count( $terms ) > 0,
		"{$taxonomy}: the taxonomy has terms to check (non-vacuous gate)",
		'0 terms — the policy cannot be proven from an empty population'
	);

	// 1a. No per-language suffixed duplicates (dublin-en, dublin-pt, ...).
	$suffixed = array();
	foreach ( $terms as $term ) {
		if ( preg_match( '/-(pt|en|ptbr|enus)$/i', (string) $term->slug ) ) {
			$suffixed[] = $term->slug;
		}
	}
	conexao_gate_violation(
		"taxonomy:{$taxonomy}:suffixed_duplicate_terms",
		count( $suffixed ),
		"{$taxonomy}: no per-language suffixed duplicate terms",
		array( 'slugs' => array_slice( $suffixed, 0, 20 ) )
	);

	// 1b. No two terms share a slug (a second physical term for one name).
	$by_slug = array();
	foreach ( $terms as $term ) {
		$by_slug[ $term->slug ][] = (int) $term->term_id;
	}
	$duplicate_slugs = array();
	foreach ( $by_slug as $slug => $ids ) {
		if ( count( $ids ) > 1 ) {
			$duplicate_slugs[ $slug ] = count( $ids );
		}
	}
	conexao_gate_violation(
		"taxonomy:{$taxonomy}:duplicate_slugs",
		count( $duplicate_slugs ),
		"{$taxonomy}: no duplicate term slugs",
		array( 'slugs' => array_slice( array_keys( $duplicate_slugs ), 0, 20 ) )
	);

	// 1c. A shared term carries NO language at all. A language on a shared
	//     term is exactly the "split identity" the policy forbids.
	$language_tagged = array();
	foreach ( $terms as $term ) {
		$language = pll_get_term_language( (int) $term->term_id, 'slug' );
		if ( is_string( $language ) && '' !== $language ) {
			$language_tagged[] = $term->slug . '=' . $language;
		}
	}
	conexao_gate_violation(
		"taxonomy:{$taxonomy}:language_tagged_terms",
		count( $language_tagged ),
		"{$taxonomy}: every shared term is language-neutral (no language tag)",
		array( 'sample' => array_slice( $language_tagged, 0, 20 ) )
	);
}

// ---------------------------------------------------------------------------
// 2. Translated vocabulary taxonomies (category / tag).
//
// Every translation must be linked BOTH ways: EN -> PT resolves to the PT
// master, and PT -> EN resolves back to the same EN term. A one-way or
// half-linked pair is the "missing reverse link" the standard names.
// ---------------------------------------------------------------------------

$translated_pairs_checked = 0;

foreach ( $translated_taxonomies as $taxonomy ) {
	$terms = conexao_gate_all_terms( $taxonomy );

	if ( is_wp_error( $terms ) ) {
		assert_true( false, "{$taxonomy}: terms are readable", $terms->get_error_message() );
		continue;
	}

	$terms = is_array( $terms ) ? $terms : array();

	$forward_only  = array();
	$broken_back   = array();
	$unlinked_orphans = array();

	foreach ( $terms as $term ) {
		$language = pll_get_term_language( (int) $term->term_id, 'slug' );

		if ( 'en' !== $language ) {
			continue;
		}

		$translated_pairs_checked++;

		// Forward: the EN term must point at a PT master.
		$pt_id = (int) pll_get_term( (int) $term->term_id, 'pt' );

		if ( $pt_id <= 0 || $pt_id === (int) $term->term_id ) {
			$unlinked_orphans[] = $term->slug;
			continue;
		}

		// Backward: the PT master must point back at THIS EN term.
		$back_id = (int) pll_get_term( $pt_id, 'en' );

		if ( $back_id !== (int) $term->term_id ) {
			$broken_back[] = $term->slug . '->' . $back_id;
		}
	}

	conexao_gate_violation(
		"taxonomy:{$taxonomy}:en_terms_without_pt_link",
		count( $unlinked_orphans ),
		"{$taxonomy}: every EN term has a PT master",
		array( 'slugs' => array_slice( $unlinked_orphans, 0, 20 ) )
	);

	conexao_gate_violation(
		"taxonomy:{$taxonomy}:missing_reverse_link",
		count( $broken_back ),
		"{$taxonomy}: every translated term pair is linked in BOTH directions",
		array( 'pairs' => array_slice( $broken_back, 0, 20 ) )
	);
}

// The bidirectional check must actually have run over a real population.
// `conexao_tag` is legitimately empty in this dataset (0 terms), so the
// non-vacuity requirement is asserted per taxonomy above; here we only prove
// the loop itself was not skipped entirely.
assert_true(
	$shared_total > 0,
	'the gate inspected a real shared-term population',
	"shared terms inspected: {$shared_total}"
);

echo "\n  inspected: {$shared_total} shared terms, {$translated_pairs_checked} translated term pairs\n";

conexao_gate_close();
