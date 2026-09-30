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
	array( 'polylang', 'conexao_resolve_shared_slug_page_request()', 'conexao_en_translation_shared_page_slug_filter()' )
);

conexao_gate_require(
	function_exists( 'conexao_resolve_shared_slug_page_request' ),
	'theme-active',
	'test prerequisite is available: conexao_resolve_shared_slug_page_request()'
);

// A shared-slug FIXTURE can only be created with the same scoped
// `wp_unique_post_slug` permit the production stage arms: without it WordPress
// uniquifies the second page to `<slug>-2` and the fixture would be testing
// nothing. This is a declared DATA PREREQUISITE, not a best-effort nicety — when
// the permit is unavailable the gate reports `insufficient data:` and exits
// non-zero instead of fataling inside `wp_insert_post()`.
conexao_gate_require(
	function_exists( 'conexao_en_translation_shared_page_slug_filter' ),
	'plugin-conexao-en-translation-active',
	'test prerequisite is available: conexao_en_translation_shared_page_slug_filter() (the shared-slug permit)',
	'activate the conexao-en-translation plugin, or run this suite on an install where it is active'
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

/**
 * Read the FRONT-END URLs of two records in a FRESH WordPress request.
 *
 * ## Why a child process is not optional here
 *
 * Polylang memoises the translated URL of a record the first time it is asked
 * for in a request, and this suite creates the PT↔EN pair in the very request
 * that would ask for it. Polylang therefore answers with the value it had
 * before the pair existed, and NO in-process cache clear reaches it: the memo
 * lives in Polylang's own link directory, not in the WordPress object cache
 * (`wp_cache_flush()`, `clean_post_cache()` and
 * `conexao_en_translation_blog_page_refresh_routing_cache()` were all measured
 * to leave it untouched).
 *
 * The production path never has this problem: the EN record is created by one
 * request and served by the next. Asserting the canonical/hreflang contract
 * therefore requires crossing a real request boundary, which is exactly what a
 * short child process does. The child only READS: it loads `wp-load.php`,
 * returns two permalinks and the translation links as JSON, and writes nothing.
 *
 * The whole point of the assertion is that the two languages resolve to two
 * DIFFERENT URLs, so reading them in the same memoised request would prove
 * nothing at all.
 *
 * @param int $pt_id PT record id.
 * @param int $en_id EN record id.
 * @return array{pt:string,en:string,links:array,ran:bool}
 */
function conexao_jobs_gate_fresh_request_urls( int $pt_id, int $en_id ): array {
	$empty = array(
		'pt'   => '',
		'en'   => '',
		'links' => array(),
		'ran'  => false,
	);

	if ( ! function_exists( 'exec' ) || '' === (string) PHP_BINARY ) {
		return $empty;
	}

	$child = trailingslashit( ABSPATH ) . 'conexao-shared-slug-gate-reader.php';

	$code = '<?php require __DIR__ . "/wp-load.php";'
		. 'echo "CONEXAO-GATE-READ" . json_encode(array('
		. '"pt" => get_permalink( ' . (int) $pt_id . ' ),'
		. '"en" => get_permalink( ' . (int) $en_id . ' ),'
		. '"links" => function_exists( "conexao_object_translation_links" )'
		. ' ? conexao_object_translation_links( ' . (int) $pt_id . ' ) : array(),'
		. ')) . "\n";';

	if ( false === file_put_contents( $child, $code ) ) {
		return $empty;
	}

	$output = array();
	$status = 0;

	exec( escapeshellarg( (string) PHP_BINARY ) . ' ' . escapeshellarg( $child ) . ' 2>/dev/null', $output, $status );

	@unlink( $child );

	if ( 0 !== (int) $status ) {
		return $empty;
	}

	foreach ( (array) $output as $line ) {
		$position = strpos( (string) $line, 'CONEXAO-GATE-READ' );

		if ( false === $position ) {
			continue;
		}

		$decoded = json_decode( substr( (string) $line, $position + strlen( 'CONEXAO-GATE-READ' ) ), true );

		if ( ! is_array( $decoded ) ) {
			continue;
		}

		return array(
			'pt'    => (string) ( $decoded['pt'] ?? '' ),
			'en'    => (string) ( $decoded['en'] ?? '' ),
			'links' => (array) ( $decoded['links'] ?? array() ),
			'ran'   => true,
		);
	}

	return $empty;
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

// The Blog posts page, the Jobs landing and the Newsletter page are the three
// DECLARED shared-slug pages (each declared by its own stage manifest row
// whose en_slug equals its PT stable key — see
// `conexao_en_translation_shared_page_slug_for()`). Any OTHER slug forming a
// pt+en pair on one post_name would mean the filter's reach had grown beyond
// the declared set.
$declared_shared_slugs = array( 'blog', 'empregos', 'newsletter' );
$undeclared_shared      = array_values( array_diff( $real_shared, $declared_shared_slugs ) );

conexao_gate_violation(
	'shared_slug:undeclared_pair',
	empty( $undeclared_shared ) ? 0 : 1,
	'every real shared-slug pt+en page pair is one of the three declared slugs (blog, empregos, newsletter)',
	array( 'pairs' => $real_shared, 'declared' => $declared_shared_slugs, 'undeclared' => $undeclared_shared )
);

// ---------------------------------------------------------------------------
// 8. `newsletter` — the THIRD declared shared-slug page (B1, not B2).
//
// `/newsletter/` ↔ `/en/newsletter/` reuses one canonical path in two
// languages, exactly like `/blog/` and `/empregos/`. This section proves the
// SAME resolver answers correctly for the real `newsletter` slug, and that the
// exception stays narrow: it needs a published, linked PT↔EN pair, and nothing
// else reaches it.
//
// Fixture discipline: the EN `newsletter` record under test is created, linked,
// asserted and DELETED inside this section when no EN record holds the slug
// yet (the pre-pair production state). When the REAL linked EN record already
// holds the slug (the post-pair synthetic CI state, produced by the shared
// engine's own `en-page` stage), that record IS the pair under test: no
// duplicate EN fixture is created, the real record is never deleted, and the
// PT page's original translation link is restored and re-asserted before the
// section ends.
// ---------------------------------------------------------------------------

$pt_newsletter = get_page_by_path( 'newsletter', OBJECT, 'page' );

conexao_gate_require(
	$pt_newsletter instanceof WP_Post,
	'page-newsletter-exists',
	'the PT newsletter page exists'
);

// The PT page's translation state is captured BEFORE anything is touched, so the
// restore can be proven rather than assumed.
$newsletter_pt_id     = $pt_newsletter instanceof WP_Post ? (int) $pt_newsletter->ID : 0;
$newsletter_pt_slug   = $pt_newsletter instanceof WP_Post ? (string) $pt_newsletter->post_name : '';
$newsletter_pt_status = $pt_newsletter instanceof WP_Post ? (string) $pt_newsletter->post_status : '';
$newsletter_en_before = $newsletter_pt_id > 0 ? (int) pll_get_post( $newsletter_pt_id, 'en' ) : 0;

// NEGATIVE (pre-pair) / POSITIVE (post-pair): the EN half of the newsletter
// pair may or may not exist yet.
//
// - PRE-PAIR (the state production is in today): only the PT page holds the
//   slug, so the resolver must leave the request completely alone. This is
//   exactly why `/en/newsletter/` currently answers with the Portuguese body
//   in production.
// - POST-PAIR (the synthetic CI database runs the real `en-page` stage of
//   the SHARED engine, which authors the EN newsletter as a shared-slug B1
//   page): the REAL linked EN record already holds the slug, and the resolver
//   must bind the EN request to it.
//
// Both expectations are asserted; which one runs is decided by the install's
// actual state, and neither is skipped silently.
$newsletter_before = conexao_jobs_gate_resolve( 'newsletter', 'en' );

if ( $newsletter_en_before > 0 ) {
	conexao_gate_violation(
		'newsletter:paired_slug_not_resolved',
		( $newsletter_before['changed'] && (int) ( $newsletter_before['vars']['page_id'] ?? 0 ) === $newsletter_en_before ) ? 0 : 1,
		'an EN request for newsletter resolves to the real linked EN newsletter record',
		array( 'pt_id' => $newsletter_pt_id, 'en_id' => $newsletter_en_before, 'vars' => $newsletter_before['vars'] )
	);
} else {
	conexao_gate_violation(
		'newsletter:unpaired_slug_rewritten',
		$newsletter_before['changed'] ? 1 : 0,
		'an EN request for newsletter is NOT rewritten while only the PT page holds the slug',
		array( 'pt_id' => $newsletter_pt_id, 'vars' => $newsletter_before['vars'] )
	);
}

// POSITIVE 1: a linked PT + EN pair may share the slug, and the EN record really
// lands on `newsletter` — the permit holds, so no `newsletter-2` is produced.
//
// POST-PAIR discipline: when the REAL linked EN record already holds the slug,
// THAT record is the pair under test. A second EN page on the same slug would
// be exactly the duplicate EN identity the engine's own shared-slug guard
// refuses, so no fixture is created and the real record is never deleted.
$newsletter_en_is_real = (
	$newsletter_en_before > 0
	&& 'newsletter' === (string) get_post_field( 'post_name', $newsletter_en_before )
);

if ( $newsletter_en_is_real ) {
	$newsletter_en = $newsletter_en_before;
} else {
	$newsletter_en = conexao_jobs_gate_make_page( 'newsletter', 'en', $newsletter_pt_id );
}

conexao_gate_violation(
	'newsletter:pair_fixture_missing',
	$newsletter_en > 0 ? 0 : 1,
	'the EN newsletter record under test exists (the real linked record, or a temporary fixture)',
	array( 'pt_id' => $newsletter_pt_id, 'en_id' => $newsletter_en, 'en_is_real' => $newsletter_en_is_real )
);

if ( $newsletter_en > 0 ) {
	conexao_gate_violation(
		'newsletter:en_slug_not_shared',
		( 'newsletter' === (string) get_post_field( 'post_name', $newsletter_en ) ) ? 0 : 1,
		'the EN newsletter page retains the authored slug newsletter (not newsletter-2)',
		array(
			'pt_slug' => (string) get_post_field( 'post_name', $newsletter_pt_id ),
			'en_slug' => (string) get_post_field( 'post_name', $newsletter_en ),
		)
	);

	conexao_gate_violation(
		'newsletter:pair_not_bidirectional',
		( (int) pll_get_post( $newsletter_en, 'pt' ) === $newsletter_pt_id ) ? 0 : 1,
		'the EN newsletter page is linked back to the PT newsletter page',
		array( 'en_id' => $newsletter_en, 'pt_of_en' => (int) pll_get_post( $newsletter_en, 'pt' ) )
	);

	// POSITIVE 2: an EN request for the shared slug resolves to the EN record.
	$newsletter_en_request = conexao_jobs_gate_resolve( 'newsletter', 'en' );

	conexao_gate_violation(
		'newsletter:en_request_not_resolved',
		( $newsletter_en_request['changed'] && (int) ( $newsletter_en_request['vars']['page_id'] ?? 0 ) === $newsletter_en ) ? 0 : 1,
		'an EN request for newsletter resolves to the EN newsletter record',
		array( 'resolved' => $newsletter_en_request['vars'], 'expected_page_id' => $newsletter_en )
	);

	conexao_gate_violation(
		'newsletter:en_request_keeps_pagename',
		empty( $newsletter_en_request['vars']['pagename'] ) ? 0 : 1,
		'the resolved EN newsletter request no longer carries the ambiguous pagename',
		array( 'vars' => $newsletter_en_request['vars'] )
	);

	// POSITIVE 3: a PT request is untouched — WordPress' own answer stands.
	$newsletter_pt_request = conexao_jobs_gate_resolve( 'newsletter', 'pt' );

	conexao_gate_violation(
		'newsletter:pt_request_rewritten',
		$newsletter_pt_request['changed'] ? 1 : 0,
		'a default-language request for newsletter is never rewritten by the shared-slug resolver',
		array( 'vars' => $newsletter_pt_request['vars'] )
	);

	// POSITIVE 4 + 5: language-correct canonicals and the hreflang relationship.
	// The canonical of a shared-slug page is its OWN language URL, and the two
	// languages advertise each other — this is what
	// `conexao_object_translation_links()` feeds to both the canonical/hreflang
	// emitter and the theme sitemap.
	//
	// Read in a FRESH request: this suite creates the pair in this very request,
	// and Polylang's per-request URL memo would otherwise answer for the state
	// that existed before the pair did. See the reader's docblock.
	$newsletter_urls = conexao_jobs_gate_fresh_request_urls( $newsletter_pt_id, $newsletter_en );

	conexao_gate_violation(
		'newsletter:fresh_request_reader_failed',
		$newsletter_urls['ran'] ? 0 : 1,
		'the shared-slug gate could read the newsletter URLs in a fresh request',
		array( 'urls' => $newsletter_urls )
	);

	$newsletter_pt_link = $newsletter_urls['pt'];
	$newsletter_en_link = $newsletter_urls['en'];

	conexao_gate_violation(
		'newsletter:canonical_not_language_correct',
		( '' !== $newsletter_pt_link && '' !== $newsletter_en_link && $newsletter_pt_link !== $newsletter_en_link ) ? 0 : 1,
		'the PT and EN newsletter pages have two DISTINCT, language-correct self-canonical URLs',
		array( 'pt_permalink' => $newsletter_pt_link, 'en_permalink' => $newsletter_en_link )
	);

	conexao_gate_violation(
		'newsletter:canonical_missing_language_prefix',
		( '' !== $newsletter_pt_link && false !== strpos( $newsletter_pt_link, '/newsletter/' ) && false === strpos( $newsletter_pt_link, '/en/newsletter/' ) ) ? 0 : 1,
		'the PT newsletter canonical is the unprefixed /newsletter/ path',
		array( 'pt_permalink' => $newsletter_pt_link )
	);

	conexao_gate_violation(
		'newsletter:en_canonical_missing_language_prefix',
		( false !== strpos( $newsletter_en_link, '/en/newsletter/' ) ) ? 0 : 1,
		'the EN newsletter canonical is the /en/newsletter/ path, NOT the PT URL',
		array( 'en_permalink' => $newsletter_en_link )
	);

	$newsletter_links = (array) $newsletter_urls['links'];

	conexao_gate_violation(
		'newsletter:hreflang_pt_missing',
		isset( $newsletter_links['pt'] ) ? 0 : 1,
		'the PT newsletter page emits a pt-BR hreflang alternate',
		array( 'links' => $newsletter_links )
	);

	conexao_gate_violation(
		'newsletter:hreflang_en_missing',
		isset( $newsletter_links['en'] ) ? 0 : 1,
		'the PT newsletter page emits an en hreflang alternate to the EN newsletter record',
		array( 'links' => $newsletter_links )
	);

	// The alternates must be DISTINCT from each other and must each be the URL of
	// the record in that language. Without the distinctness check this assertion
	// would pass vacuously whenever both alternates collapse onto one URL.
	conexao_gate_violation(
		'newsletter:hreflang_alternates_collapsed',
		( isset( $newsletter_links['pt'], $newsletter_links['en'] )
			&& (string) $newsletter_links['pt']['url'] !== (string) $newsletter_links['en']['url'] ) ? 0 : 1,
		'the pt-BR and en hreflang alternates are two DIFFERENT URLs, not one collapsed URL',
		array( 'links' => $newsletter_links )
	);

	conexao_gate_violation(
		'newsletter:hreflang_en_points_elsewhere',
		( isset( $newsletter_links['en'] ) && $newsletter_en_link === (string) $newsletter_links['en']['url']
			&& $newsletter_pt_link === (string) ( $newsletter_links['pt']['url'] ?? '' ) ) ? 0 : 1,
		'each hreflang alternate points at the permalink of the record in its own language',
		array( 'links' => $newsletter_links, 'pt_permalink' => $newsletter_pt_link, 'en_permalink' => $newsletter_en_link )
	);
}

// The remaining newsletter negatives and the fixture teardown. They live
// OUTSIDE the `if ( $newsletter_en > 0 )` block above only in the sense of
// readability; every one of them needs the pair, so they are guarded the same
// way and are otherwise reported as a violation, never skipped silently.
if ( $newsletter_en > 0 ) {
	// NEGATIVE: an EN page on the shared slug with NO valid PT counterpart does
	// not gain the exception. The link is severed and the request must fall back
	// to WordPress' own answer.
	pll_save_post_translations( array( 'pt' => $newsletter_pt_id ) );

	$newsletter_orphan = conexao_jobs_gate_resolve( 'newsletter', 'en' );

	conexao_gate_violation(
		'newsletter:en_without_pt_bound_to_language',
		$newsletter_orphan['changed'] ? 1 : 0,
		'an EN newsletter page with no valid PT counterpart is NOT bound to the requested language',
		array( 'vars' => $newsletter_orphan['vars'], 'en_id' => $newsletter_en )
	);

	// Restore the pair for the remaining negative case.
	pll_set_post_language( $newsletter_en, 'en' );
	pll_save_post_translations( array( 'pt' => $newsletter_pt_id, 'en' => $newsletter_en ) );

	// NEGATIVE: TWO PT pages on one slug do not gain the exception. A second
	// Portuguese page is added to the shared slug, the English side is no longer
	// unambiguously paired, and the resolver must refuse to bind it.
	$newsletter_second_pt = conexao_jobs_gate_make_page( 'newsletter', 'pt' );

	$newsletter_two_pt = conexao_jobs_gate_resolve( 'newsletter', 'en' );

	conexao_gate_violation(
		'newsletter:two_pt_pages_bound_to_language',
		( (int) ( $newsletter_two_pt['vars']['page_id'] ?? 0 ) === $newsletter_en ) ? 0 : 1,
		'two PT pages on the shared slug do NOT hand the EN request to the EN record',
		array( 'second_pt' => $newsletter_second_pt, 'vars' => $newsletter_two_pt['vars'] )
	);

	if ( $newsletter_second_pt > 0 ) {
		wp_delete_post( $newsletter_second_pt, true );
	}

	// NEGATIVE: a linked pair with DIFFERENT slugs keeps working normally — the
	// ordinary translated-slug shape needs no shared-slug resolution at all.
	$distinct_pt = conexao_jobs_gate_make_page( 'en-jobs-shared-slug-gate-distinct', 'pt' );
	$distinct_en = conexao_jobs_gate_make_page( 'en-jobs-shared-slug-gate-distinct-en', 'en', $distinct_pt );

	$distinct_request = conexao_jobs_gate_resolve( 'en-jobs-shared-slug-gate-distinct', 'en' );

	conexao_gate_violation(
		'shared_slug:distinct_slug_pair_rewritten',
		$distinct_request['changed'] ? 1 : 0,
		'a linked pair with DIFFERENT slugs is left to WordPress (no shared-slug rewriting)',
		array( 'pt' => $distinct_pt, 'en' => $distinct_en, 'vars' => $distinct_request['vars'] )
	);

	// Cleanup: remove the temporary EN newsletter record (a REAL linked EN
	// record is never deleted — it is the site's own content, not this gate's
	// fixture) and restore the PT page's ORIGINAL translation link, so the
	// install ends exactly as it began.
	if ( ! $newsletter_en_is_real ) {
		wp_delete_post( $newsletter_en, true );
	}

	if ( function_exists( 'pll_save_post_translations' ) ) {
		if ( $newsletter_en_before > 0 ) {
			pll_set_post_language( $newsletter_en_before, 'en' );
			pll_save_post_translations( array( 'pt' => $newsletter_pt_id, 'en' => $newsletter_en_before ) );
		} else {
			pll_save_post_translations( array( 'pt' => $newsletter_pt_id ) );
		}
	}

	conexao_gate_violation(
		'newsletter:pt_link_not_restored',
		( (int) pll_get_post( $newsletter_pt_id, 'en' ) === $newsletter_en_before ) ? 0 : 1,
		"the PT newsletter page's original EN translation link is restored exactly",
		array( 'before' => $newsletter_en_before, 'after' => (int) pll_get_post( $newsletter_pt_id, 'en' ) )
	);

	foreach ( array( $distinct_pt, $distinct_en ) as $fixture_id ) {
		if ( $fixture_id > 0 ) {
			wp_delete_post( $fixture_id, true );
		}
	}
}

$newsletter_survivors = array_map(
	'intval',
	(array) get_posts(
		array(
			'post_type'      => 'page',
			'post_status'    => 'any',
			'name'           => 'newsletter',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'lang'           => '',
		)
	)
);

// The pages that must survive this gate: the real PT page, plus — in the
// POST-PAIR state — the REAL linked EN record the shared engine's `en-page`
// stage authored (it legitimately holds the slug; it is not a gate fixture).
$newsletter_expected_survivors = array( $newsletter_pt_id );

if ( $newsletter_en_is_real ) {
	$newsletter_expected_survivors[] = $newsletter_en;
}

sort( $newsletter_survivors );
sort( $newsletter_expected_survivors );

conexao_gate_violation(
	'newsletter:fixture_left_behind',
	( $newsletter_expected_survivors === $newsletter_survivors ) ? 0 : 1,
	'the newsletter shared-slug fixtures were removed and only the real pages hold the slug',
	array( 'survivors' => $newsletter_survivors, 'expected' => $newsletter_expected_survivors, 'pt_id' => $newsletter_pt_id )
);

conexao_gate_violation(
	'newsletter:pt_record_unchanged',
	( $newsletter_pt_slug === (string) get_post_field( 'post_name', $newsletter_pt_id )
		&& $newsletter_pt_status === (string) get_post_field( 'post_status', $newsletter_pt_id ) ) ? 0 : 1,
	'the real PT newsletter page was never modified by this gate',
	array(
		'pt_id'      => $newsletter_pt_id,
		'slug'       => $newsletter_pt_slug,
		'status'     => $newsletter_pt_status,
		'slug_now'   => (string) get_post_field( 'post_name', $newsletter_pt_id ),
		'status_now' => (string) get_post_field( 'post_status', $newsletter_pt_id ),
	)
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
