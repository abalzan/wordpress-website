<?php
/**
 * PERMANENT GATE — shared-slug PAGE request resolution (the EN Jobs landing).
 *
 * Normative rule:
 *   "A page whose EN translation reuses the PT post_name resolves to the
 *    record of the REQUESTED language, and only when the two records are a
 *    linked translation pair."
 *
 * ## Why this exists
 *
 * `/empregos/` ↔ `/en/empregos/` deliberately share one `post_name`, the
 * approved shape that already governs `/blog/` ↔ `/en/blog/`. WordPress
 * resolves `pagename` language-blind, so it returns the FIRST page with that
 * slug — the Portuguese one — and the request then looks like a language
 * mismatch. `conexao_resolve_shared_slug_page_request()` hands WordPress the
 * explicit `page_id` of the record in the requested language, exactly as
 * `conexao_resolve_posts_page_request()` does for the Blog.
 *
 * Without it `/en/empregos/` answers 302 → `/empregos/` and the English
 * landing page is unreachable at its own URL.
 *
 * ## What is proven
 *
 *   1. the PT/EN Jobs pages really are a shared-slug linked pair;
 *   2. an EN request for that slug resolves to the EN record;
 *   3. a PT request is untouched (byte-identical to WordPress' own answer);
 *   4. a UNIQUE-slug EN page is NOT rewritten — the filter is a no-op, so no
 *      ordinary page can be captured by it;
 *   5. a CPT / non-page post type is never touched;
 *   6. an UNPAIRED duplicate on one slug is NOT bound to a language;
 *   7. the real shared-slug pairs on the site are exactly the declared set.
 *
 * ## Fixture discipline
 *
 * Every fixture is created and DELETED inside this suite, under names prefixed
 * `en-jobs-shared-slug-gate-`. No real content is touched, and the gate
 * removes what it created even when an assertion fails.
 *
 * @package Conexao_BR_Irlanda
 */

// Shared Stage E bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_ROOT . '/lib/permanent-gates.php';

conexao_gate_open(
	'shared_slug_page',
	'A page whose EN translation reuses the PT post_name resolves to the record of the REQUESTED '
	. 'language, and only when the two records are a linked translation pair.',
	array( 'polylang', 'conexao_resolve_shared_slug_page_request()' )
);

conexao_gate_require(
	function_exists( 'conexao_resolve_shared_slug_page_request' ),
	'theme-active',
	'test prerequisite is available: conexao_resolve_shared_slug_page_request()'
);

/**
 * Create a page, optionally in a given language, optionally linked to a partner.
 *
 * @param string $slug    Post slug.
 * @param string $lang    Language slug, or '' to leave unassigned.
 * @param int    $partner Linked translation id, or 0 for none.
 * @return int Created id, or 0 on failure.
 */
function conexao_jobs_gate_make_page( string $slug, string $lang, int $partner = 0 ) {
	// WordPress uniquifies a page slug against its siblings, so a SECOND page
	// created with the same slug would silently become `<slug>-2` and would
	// not be a shared-slug page at all. The shared-slug permit is armed for the
	// duration of the insert so the fixture really does land on `$slug` — the
	// same scoped mechanism the production stage uses. Without it the fixture
	// would test nothing.
	set_transient( 'conexao_en_translation_shared_page_slug', $slug, 5 * MINUTE_IN_SECONDS );
	add_filter( 'wp_unique_post_slug', 'conexao_en_translation_shared_page_slug_filter', 10, 6 );

	try {
		$post_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'EN Jobs shared-slug gate fixture',
				'post_name'    => $slug,
				'post_content' => 'Fixture created and removed by test-en-jobs-shared-slug.php.',
			),
			true
		);
	} finally {
		remove_filter( 'wp_unique_post_slug', 'conexao_en_translation_shared_page_slug_filter', 10 );
		delete_transient( 'conexao_en_translation_shared_page_slug' );
	}

	if ( is_wp_error( $post_id ) ) {
		return 0;
	}

	$post_id = (int) $post_id;

	if ( '' !== $lang && function_exists( 'pll_set_post_language' ) ) {
		pll_set_post_language( $post_id, $lang );
	}

	if ( $partner > 0 && function_exists( 'pll_save_post_translations' ) ) {
		pll_save_post_translations(
			array(
				'pt' => $partner,
				'en' => $post_id,
			)
		);
	}

	return $post_id;
}

/**
 * Run the resolver over a simulated request.
 *
 * @param string $slug Parsed pagename.
 * @param string $lang Requested language.
 * @return array{changed:bool,vars:array}
 */
function conexao_jobs_gate_resolve( string $slug, string $lang ): array {
	$vars = array(
		'pagename' => $slug,
		'lang'     => $lang,
	);

	$out = conexao_resolve_shared_slug_page_request( $vars );

	return array(
		'changed' => ( $out !== $vars ),
		'vars'    => $out,
	);
}

// ---------------------------------------------------------------------------
// 1. The real PT/EN Jobs pages are a shared-slug LINKED pair.
// ---------------------------------------------------------------------------

$pt_jobs = get_page_by_path( 'empregos', OBJECT, 'page' );

conexao_gate_require(
	$pt_jobs instanceof WP_Post,
	'page-empregos-exists',
	'the PT Jobs landing page exists'
);

$pt_id = $pt_jobs instanceof WP_Post ? (int) $pt_jobs->ID : 0;
$en_id = $pt_id > 0 ? (int) pll_get_post( $pt_id, 'en' ) : 0;

conexao_gate_violation(
	'jobs:en_translation_missing',
	$en_id > 0 ? 0 : 1,
	'the PT Jobs page has a linked EN translation',
	array( 'pt_id' => $pt_id, 'en_id' => $en_id )
);

if ( $en_id > 0 ) {
	conexao_gate_violation(
		'jobs:pair_not_bidirectional',
		( (int) pll_get_post( $en_id, 'pt' ) === $pt_id ) ? 0 : 1,
		'the EN Jobs page is linked back to the PT Jobs page',
		array( 'en_id' => $en_id, 'pt_of_en' => (int) pll_get_post( $en_id, 'pt' ) )
	);

	conexao_gate_violation(
		'jobs:slug_not_shared',
		( get_post_field( 'post_name', $en_id ) === get_post_field( 'post_name', $pt_id ) ) ? 0 : 1,
		'the EN Jobs page reuses the PT post_name (the shared-slug route shape)',
		array(
			'pt_slug' => get_post_field( 'post_name', $pt_id ),
			'en_slug' => get_post_field( 'post_name', $en_id ),
		)
	);

	// 2. An EN request for the shared slug resolves to the EN record.
	$en = conexao_jobs_gate_resolve( 'empregos', 'en' );

	conexao_gate_violation(
		'jobs:en_request_not_resolved',
		( $en['changed'] && (int) ( $en['vars']['page_id'] ?? 0 ) === $en_id ) ? 0 : 1,
		'an EN request for the shared slug resolves to the EN record',
		array( 'resolved' => $en['vars'], 'expected_page_id' => $en_id )
	);

	conexao_gate_violation(
		'jobs:en_request_keeps_pagename',
		empty( $en['vars']['pagename'] ) ? 0 : 1,
		'the resolved EN request no longer carries the ambiguous pagename',
		array( 'vars' => $en['vars'] )
	);

	// 3. A PT request is untouched.
	$pt = conexao_jobs_gate_resolve( 'empregos', 'pt' );

	conexao_gate_violation(
		'jobs:pt_request_rewritten',
		$pt['changed'] ? 1 : 0,
		'a default-language request is never rewritten by the shared-slug resolver',
		array( 'vars' => $pt['vars'] )
	);
}

// 4. A UNIQUE-slug EN page is NOT rewritten.
$unique = conexao_jobs_gate_resolve( 'contato', 'en' );

conexao_gate_violation(
	'shared_slug:unique_page_rewritten',
	$unique['changed'] ? 1 : 0,
	'an EN request for a uniquely-slugged page is left untouched',
	array( 'slug' => 'contato', 'vars' => $unique['vars'] )
);

// 5. A CPT / non-page slug is never touched.
$cpt = conexao_jobs_gate_resolve( 'guias', 'en' );

conexao_gate_violation(
	'shared_slug:cpt_rewritten',
	$cpt['changed'] ? 1 : 0,
	'an EN request for a CPT archive slug is left untouched',
	array( 'slug' => 'guias', 'vars' => $cpt['vars'] )
);

// 6. An UNPAIRED duplicate on one slug is NOT bound to a language.
$dup_slug = 'en-jobs-shared-slug-gate-unpaired';
$dup_a    = conexao_jobs_gate_make_page( $dup_slug, 'pt' );
$dup_b    = conexao_jobs_gate_make_page( $dup_slug, 'en' ); // deliberately NOT linked.

$dup = conexao_jobs_gate_resolve( $dup_slug, 'en' );

conexao_gate_violation(
	'shared_slug:unpaired_bound_to_language',
	$dup['changed'] ? 1 : 0,
	'two UNPAIRED pages sharing a slug are not bound to the requested language',
	array( 'a' => $dup_a, 'b' => $dup_b, 'vars' => $dup['vars'] )
);

// Positive control: a genuinely linked shared-slug PAIR IS resolved.
$pair_slug = 'en-jobs-shared-slug-gate-pair';
$pair_pt   = conexao_jobs_gate_make_page( $pair_slug, 'pt' );
$pair_en   = conexao_jobs_gate_make_page( $pair_slug, 'en', $pair_pt );

$pair = conexao_jobs_gate_resolve( $pair_slug, 'en' );

conexao_gate_violation(
	'shared_slug:linked_pair_not_resolved',
	( $pair['changed'] && (int) ( $pair['vars']['page_id'] ?? 0 ) === $pair_en ) ? 0 : 1,
	'a linked shared-slug pair IS resolved to the requested language',
	array( 'pair_pt' => $pair_pt, 'pair_en' => $pair_en, 'vars' => $pair['vars'] )
);

// 7. Blast radius: the real shared-slug pairs on the site.
$shared = get_posts(
	array(
		'post_type'      => 'page',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'lang'           => '',
	)
);

$by_slug = array();

foreach ( (array) $shared as $id ) {
	$by_slug[ (string) get_post_field( 'post_name', $id ) ][] = (int) $id;
}

$real_shared = array();

foreach ( $by_slug as $slug_name => $ids ) {
	if ( count( $ids ) < 2 || 0 === strpos( $slug_name, 'en-jobs-shared-slug-gate-' ) ) {
		continue;
	}

	$languages = array();

	foreach ( $ids as $id ) {
		$languages[] = (string) pll_get_post_language( $id );
	}

	if ( in_array( 'pt', $languages, true ) && in_array( 'en', $languages, true ) ) {
		$real_shared[] = $slug_name;
	}
}

sort( $real_shared );

echo '  real shared-slug pt+en pages: ' . ( empty( $real_shared ) ? 'none' : implode( ', ', $real_shared ) ) . "\n";

conexao_gate_violation(
	'shared_slug:jobs_pair_missing',
	in_array( 'empregos', $real_shared, true ) ? 0 : 1,
	'the EN Jobs landing is a declared shared-slug pair',
	array( 'pairs' => $real_shared )
);

// The Blog posts page and the Jobs landing are the only two deliberate
// shared-slug pairs; a third would mean the filter's reach had grown.
conexao_gate_violation(
	'shared_slug:unexpected_new_pair',
	( count( $real_shared ) <= 2 ) ? 0 : 1,
	'no unexpected additional shared-slug page pair exists',
	array( 'pairs' => $real_shared, 'count' => count( $real_shared ) )
);

// Cleanup — the fixtures must never survive the gate.
foreach ( array( $dup_a, $dup_b, $pair_pt, $pair_en ) as $fixture_id ) {
	if ( $fixture_id > 0 ) {
		wp_delete_post( $fixture_id, true );
	}
}

foreach ( array( $dup_slug, $pair_slug ) as $slug_name ) {
	$survivor = get_page_by_path( $slug_name, OBJECT, 'page' );

	conexao_gate_violation(
		'shared_slug:fixture_left_behind:' . $slug_name,
		$survivor instanceof WP_Post ? 1 : 0,
		'the shared-slug gate fixture was removed',
		array( 'slug' => $slug_name, 'survivor' => $survivor instanceof WP_Post ? $survivor->ID : 0 )
	);
}

conexao_gate_close();
