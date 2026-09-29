<?php
/**
 * PERMANENT GATE — translation completeness (Stage L, engineering standard §6.1,
 * §6.2 step 6 and §6.3).
 *
 * Normative rule, quoted from the standard:
 *
 *   "New EN coverage of a content type ends with the gate
 *    `eligible public PT <type> missing EN = 0` (or an explicit, documented
 *    allowlist)."
 *
 * ## Content types come from the runtime, not from a list in this file
 *
 * The public content types are read from the live WordPress/Polylang
 * registration (`get_post_types(['public' => true])` intersected with the
 * taxonomies/post types Polylang actually translates, minus attachments). This
 * gate never hard-codes a second copy of the content model, and it never
 * silently skips a type: every discovered type is reported, and a type that
 * cannot be evaluated is a FAILURE (`insufficient data`), never a zero.
 *
 * ## B1 vs B2 is the documented bilingual policy, reused
 *
 * The existing architecture (docs/routing.md, inc/i18n/fallback.php) splits
 * public content into:
 *   - B2 types: the PT record is deliberately served under the EN URL with a
 *     notice and a PT canonical. These are ALLOWLISTED BY DOCUMENTED POLICY
 *     (`conexao_b2_post_types()`), so "no EN translation" is correct for them.
 *   - B2 pages: an explicit slug allowlist (`conexao_b2_page_allowlist()`).
 *   - B1 types (guides, blog posts, the remaining pages): a real linked EN
 *     record is REQUIRED. Missing EN here is a genuine violation.
 *
 * Every allowlisted record is reported explicitly (Stage L rule 18). No new
 * global content allowlist is introduced: the gate reuses the policy functions
 * the runtime already owns.
 *
 * Read-only. Creates nothing, mutates nothing.
 *
 * @package Conexao_BR_Irlanda
 */

// Shared Stage E bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_ROOT . '/lib/permanent-gates.php';

conexao_gate_open(
	'translation_completeness',
	'For every public content type: eligible public PT records missing a linked EN translation = 0 '
	. '(B2 fallback types and the documented B2 page allowlist are exempt by policy).',
	array( 'polylang', 'published PT content' )
);

conexao_gate_require(
	function_exists( 'pll_get_post' ) && function_exists( 'pll_get_post_language' ),
	'polylang',
	'test prerequisite is available: the Polylang post API'
);
conexao_gate_require(
	function_exists( 'conexao_b2_post_types' ) && function_exists( 'conexao_b2_page_allowlist' ),
	'theme-active',
	'test prerequisite is available: the documented B2 policy helpers'
);

// ---------------------------------------------------------------------------
// Discover the public content types from the RUNTIME.
// ---------------------------------------------------------------------------

$translated_post_types = PLL()->model->get_translated_post_types();

// Attachments are shared media, never a translated content type (documented in
// inc/i18n/guard.php), and `wp_block` is a technical type with no public URL.
$excluded = array( 'attachment', 'wp_block' );

$content_types = array();
foreach ( get_post_types( array( 'public' => true ), 'names' ) as $post_type ) {
	if ( in_array( $post_type, $excluded, true ) ) {
		continue;
	}
	// Only types Polylang manages can ever have an EN translation.
	if ( ! isset( $translated_post_types[ $post_type ] ) ) {
		continue;
	}
	$content_types[] = $post_type;
}

sort( $content_types );

// Anti-vacuity: the gate must have discovered something to check. A zero-type
// result means the discovery itself broke, which is a FAILURE.
assert_true(
	count( $content_types ) > 0,
	'the gate discovered the public content types from the runtime',
	'no public translated post types found — discovery is broken, not satisfied'
);

echo "\n  content types under test: " . implode( ', ', $content_types ) . "\n";

// ---------------------------------------------------------------------------
// ANTI-VACUITY: an empty population must FAIL, not pass.
//
// The invariant this gate enforces is "eligible public PT records missing a
// linked EN translation = 0". On an empty registered content type that reduces
// to `0 = 0`: the gate goes green without having proved that a single record
// has, or lacks, an English translation. That is a vacuous pass, and a
// vacuous permanent gate is worse than no gate, because it reports safety it
// never checked.
//
// So the gate now ALSO requires that the B1 types it evaluates actually have a
// population to evaluate. The floors are the ones documented in
// `conexao_ci_fixture_minimums()` — the deterministic CI fixture set — and they
// are not arbitrary: each is the minimum a MAINTAINED contract needs.
//
//   guide  >= 11   /guias/ and /en/guias/ paginate at 10 per page, so the
//                   `pt-guides-page-2` / `en-guides-page-2` acceptance rows
//                   are 404 below 11 published guides.
//   post   >= 11   same reason, for `en-archive-en-blog-page-2` (/en/blog/page/2/).
//   page   >=  1   the `en-page` stage authors real page translations; zero
//                   published pages means the bilingual page layer was never
//                   exercised at all.
//
// WHY THESE TYPES ONLY. B2 types (event, leisure, sponsor, course_provider, job)
// are deliberately exempt: a B2 type with no EN record is the DOCUMENTED,
// CORRECT outcome, so demanding English coverage of them would change the
// bilingual policy to satisfy a test — exactly what the standard forbids. The
// anti-vacuity floor applies only to types that are B1 by policy, where English
// coverage is genuinely required.
//
// This does NOT create EN records to satisfy itself, does not weaken the
// completeness arithmetic below, and still fails on a genuinely missing EN
// translation: it only refuses to declare victory over nothing.
// ---------------------------------------------------------------------------
$b2_post_types  = (array) conexao_b2_post_types();
$b2_pages       = (array) conexao_b2_page_allowlist();
$default_lang   = pll_default_language( 'slug' );
$other_lang     = 'en';

// The documented B1 population floors. Hard-coded HERE rather than imported
// from the CI fixture dataset on purpose: this gate is a permanent invariant
// about the SITE, so it must not become coupled to a test-only data file. If
// the two ever disagree, the gate still holds the site to the real contract.
$b1_population_floors = array(
	'guide' => 11,
	'post'  => 11,
	'page'  => 1,
);

echo "\n  anti-vacuity: a B1 type with an empty population is a FAILURE, not a pass\n";

// ---------------------------------------------------------------------------
// Per content type: eligible PT records missing a linked EN translation.
// ---------------------------------------------------------------------------

$report = array();

foreach ( $content_types as $post_type ) {
	// Every published record of the type, in every language. B1 eligibility is
	// decided per record below, so the query must not pre-filter by language.
	$ids = get_posts(
		array(
			'post_type'        => $post_type,
			'post_status'      => 'publish',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'lang'             => '',
			'suppress_filters' => false,
			'no_found_rows'    => true,
		)
	);

	if ( ! is_array( $ids ) ) {
		assert_true( false, "{$post_type}: the PT population is readable", 'get_posts returned no array' );
		continue;
	}

	$eligible        = 0;   // PT records that MUST have a linked EN record.
	$translated      = 0;   // Of those, how many genuinely have one.
	$allowlisted     = 0;   // Exempt by the documented B2 policy.
	$malformed       = 0;   // Unexpected / broken relationship shapes.
	$missing_samples = array();
	$malformed_notes = array();

	foreach ( $ids as $id ) {
		$language = (string) pll_get_post_language( (int) $id, 'slug' );
		$post     = get_post( (int) $id );

		if ( ! $post instanceof WP_Post ) {
			continue;
		}

		// EN-side records: they must point back at a PT master. An EN record
		// with no PT master is a malformed relationship (a forked identity),
		// which §6.1 forbids ("never a fork").
		//
		// EXCEPTION — source-inherited EN. An event whose upstream source is
		// already English has no Portuguese original to translate: the EN
		// record IS the record (docs/routing.md: clients distinguish "real EN"
		// from "source-inherited EN" through the conexao_language field, and
		// test-stage32-bilingual.php asserts the same contract). It is not a
		// fork, so it is not counted as a malformed relationship.
		if ( $other_lang === $language ) {
			$master = (int) pll_get_post( (int) $id, $default_lang );

			if ( $master <= 0 || $master === (int) $id ) {
				$is_source_inherited = class_exists( 'Conexao_Event_Source_Language' )
					&& 'en' === get_post_meta( (int) $id, '_event_source_language', true );

				if ( ! $is_source_inherited ) {
					$malformed++;
					if ( count( $malformed_notes ) < 10 ) {
						$malformed_notes[] = 'en_without_pt:' . $post->post_name;
					}
				}
			}
			continue;
		}

		// Only default-language (PT) records drive the completeness gate. A
		// legacy record with no language at all is treated as PT: it is served
		// in the default context and therefore needs EN coverage too.
		if ( '' !== $language && $default_lang !== $language ) {
			continue;
		}

		$eligible++;

		$en_id = (int) pll_get_post( (int) $id, $other_lang );

		if ( $en_id > 0 && $en_id !== (int) $id ) {
			// A real linked EN record exists. The link must also be
			// bidirectional, otherwise it is malformed, not translated.
			$back = (int) pll_get_post( $en_id, $default_lang );
			if ( $back === (int) $id ) {
				$translated++;
			} else {
				$malformed++;
				if ( count( $malformed_notes ) < 10 ) {
					$malformed_notes[] = 'one_way_link:' . $post->post_name;
				}
			}
			continue;
		}

		// No EN record. Is this exempt by the DOCUMENTED B2 policy?
		$is_b2_type = in_array( $post_type, $b2_post_types, true );
		$is_b2_page = ( 'page' === $post_type && in_array( $post->post_name, $b2_pages, true ) );

		if ( $is_b2_type || $is_b2_page ) {
			$allowlisted++;
			conexao_gate_allowlisted(
				"{$post_type}:{$post->post_name}",
				$is_b2_type
					? 'B2 fallback post type (conexao_b2_post_types) — PT content is served under the EN URL by design'
					: 'B2 fallback page (conexao_b2_page_allowlist) — PT content is served under the EN URL by design'
			);
			continue;
		}

		$missing_samples[] = $post->post_name;
	}

	$missing = $eligible - $translated - $allowlisted;

	echo sprintf(
		"  %-16s eligible PT=%d translated=%d allowlisted=%d missing EN=%d malformed=%d\n",
		$post_type,
		$eligible,
		$translated,
		$allowlisted,
		$missing,
		$malformed
	);

	// THE anti-vacuity invariant, checked BEFORE the completeness arithmetic is
	// interpreted. On a B1 type the `missing = 0` result below is only
	// meaningful when there was a population to be missing from: `eligible = 0`
	// makes `missing = 0` trivially true. So the floor is enforced as a NAMED
	// gate violation, which means it is reported in gate.json, classified
	// against the baseline like every other violation, and fails the run.
	//
	// It is deliberately narrow: only types with a documented floor, and only
	// when the floor is not met. A B2 type is never subject to it, because
	// "no EN record" is the documented, correct state for a B2 type and
	// demanding English coverage of one would change the bilingual policy to
	// satisfy a test.
	if ( isset( $b1_population_floors[ $post_type ] ) ) {
		$floor = (int) $b1_population_floors[ $post_type ];

		conexao_gate_violation(
			"post_type:{$post_type}:empty_population",
			$eligible >= $floor ? 0 : 1,
			"{$post_type}: the B1 population has enough PT records for the invariant to be non-vacuous (eligible PT >= {$floor})",
			array( 'eligible' => $eligible, 'floor' => $floor )
		);
	}

	// THE invariant: eligible public PT records missing EN = 0.
	conexao_gate_violation(
		"post_type:{$post_type}:missing_en",
		$missing,
		"{$post_type}: eligible public PT records missing a linked EN translation = 0",
		array( 'eligible' => $eligible, 'translated' => $translated, 'allowlisted' => $allowlisted, 'sample' => array_slice( $missing_samples, 0, 10 ) )
	);

	conexao_gate_violation(
		"post_type:{$post_type}:malformed_relationships",
		$malformed,
		"{$post_type}: every translation relationship is a bidirectional link to a PT master",
		array( 'sample' => $malformed_notes )
	);

	$report[ $post_type ] = array(
		'eligible'    => $eligible,
		'translated'  => $translated,
		'allowlisted' => $allowlisted,
		'missing'     => $missing,
		'malformed'   => $malformed,
	);
}

assert_true(
	count( $report ) === count( $content_types ),
	'every discovered content type was evaluated (no type silently skipped)',
	'evaluated ' . count( $report ) . ' of ' . count( $content_types )
);

conexao_gate_close();
