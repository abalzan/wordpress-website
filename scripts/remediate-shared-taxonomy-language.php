<?php
/**
 * Remove stale language tags from SHARED proper-name taxonomy terms.
 *
 * Stage M, engineering standard 6.1 / 6.3.
 *
 * Engineering standard §6.1 / §6.3 and AGENTS.md: `conexao_county` and
 * `conexao_town` are SHARED proper-name taxonomies. ONE physical term per
 * county/town, used by both languages, carrying NO language and no translations.
 * `conexao_category` and `conexao_tag` are Polylang-TRANSLATED and are never
 * touched by this script.
 *
 * ## Why this debt exists
 *
 * Before the Stage 3.2 policy correction, county/town WERE Polylang-translated,
 * so Polylang tagged every term on insert. Those `term_language` relationships
 * are historical residue: `conexao_polylang_translated_taxonomies()` no longer
 * declares these taxonomies as translated, and the seed
 * (`Conexao_Data_Model_Relationships::seed_terms()`) uses a bare
 * `wp_insert_term()`, which today assigns NO language. This script therefore
 * repairs DATA ONLY. It deliberately does NOT change the seed or any runtime
 * code, because the source of the debt is already correct.
 *
 * ## The policy is read from the RUNTIME, never hard-coded
 *
 * Which taxonomies are "shared" is taken from Polylang itself
 * (`PLL()->model->get_translated_taxonomies()`), so this script can never drift
 * from the theme's declaration and never becomes a second source of truth. If a
 * taxonomy IS translated, this script refuses to touch it.
 *
 * ## Safety
 *
 * - Dry run by DEFAULT. `--apply` is required to write.
 * - Never creates, renames, re-slugs, merges or deletes a term. The ONLY write
 *   is clearing the term's language assignment through Polylang's own model
 *   API, so Polylang's caches and bookkeeping stay consistent.
 * - Refuses a translated taxonomy, a term that has a real cross-language
 *   counterpart, or an empty scope. Fails closed.
 * - Emits a JSON snapshot sufficient to restore the previous state.
 *
 * Usage:
 *   php scripts/remediate-shared-taxonomy-language.php --dry-run
 *   php scripts/remediate-shared-taxonomy-language.php --apply
 *   php scripts/remediate-shared-taxonomy-language.php --apply --json
 *
 * @package Conexao_BR_Scripts
 */

require_once __DIR__ . '/lib/bootstrap.php';

$ctx = conexao_script_boot(
	array(
		'script'             => 'remediate-shared-taxonomy-language.php',
		'purpose'            => 'Remove the stale Polylang language tag from SHARED proper-name taxonomy terms (conexao_county / conexao_town) so the taxonomy-policy invariant holds in the DATA, not only in policy.',
		'scope'              => 'Terms of every taxonomy Polylang does NOT translate. Read-only by default; --apply clears the language assignment only. No term is created, renamed, re-slugged, merged or deleted.',
		'safety'             => 'local-only; dry-run by default; --apply required to write; refuses translated taxonomies and terms with a real cross-language counterpart; emits a restorable JSON snapshot',
		'target_description' => 'the WordPress install the script is connected to (site URL printed in the header)',
		'modes_description'  => '--dry-run (default) prints the plan and writes nothing. --apply clears the stale language assignment. --json prints a machine-readable result.',
		'arguments'          => "--dry-run   Plan only, zero writes (default).\n"
			. "                --apply     Clear the stale language assignment on the listed terms.\n"
			. "                --json      Emit the machine-readable result and snapshot.\n"
			. '                --help      This message.',
		'writes'             => true,
		'read_only'          => false,
		'production_capable' => false,
	)
);

if ( ! conexao_polylang_active() ) {
	conexao_script_fail( 'Polylang is not active. The taxonomy policy is read from Polylang, so this script cannot run without it.' );
}

/**
 * Taxonomies Polylang does NOT translate - the shared ones.
 *
 * Read from Polylang's runtime model, so the script can never disagree with the
 * theme's `conexao_polylang_translated_taxonomies()` declaration and never
 * becomes a second source of truth for the policy.
 *
 * @return string[]
 */
function conexao_stage_m_shared_taxonomies() {
	$translated = (array) PLL()->model->get_translated_taxonomies();
	$all        = get_taxonomies( array( 'public' => true ), 'names' );

	return array_values(
		array_filter(
			$all,
			static function ( $taxonomy ) use ( $translated ) {
				return taxonomy_exists( $taxonomy )
					&& 0 === strpos( (string) $taxonomy, 'conexao_' )
					&& ! in_array( $taxonomy, $translated, true );
			}
		)
	);
}

/**
 * Every term of a taxonomy, regardless of language.
 *
 * `lang => ''` is required: with a language filter Polylang would HIDE the very
 * terms this script exists to inspect.
 *
 * @param string $taxonomy Taxonomy name.
 * @return WP_Term[]|WP_Error
 */
function conexao_stage_m_all_terms( string $taxonomy ) {
	return get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'lang'       => '',
			'hide_empty' => false,
		)
	);
}

/**
 * A real cross-language counterpart of a term, or 0 when there is none.
 *
 * A shared proper-name term must never have one: it would mean the term had
 * been split into per-language identities, which is exactly what the taxonomy
 * policy forbids.
 *
 * @param int $term_id Term ID.
 * @return int Counterpart term ID, or 0.
 */
function conexao_stage_m_counterpart( int $term_id ) {
	$own = pll_get_term_language( $term_id, 'slug' );

	foreach ( (array) pll_languages_list( array( 'fields' => 'slug' ) ) as $other ) {
		if ( $other === $own ) {
			continue;
		}
		$counterpart = (int) pll_get_term( $term_id, (string) $other );
		if ( $counterpart > 0 && $counterpart !== $term_id ) {
			return $counterpart;
		}
	}

	return 0;
}

$taxonomies = conexao_stage_m_shared_taxonomies();

if ( empty( $taxonomies ) ) {
	conexao_script_fail( 'no shared conexao_* taxonomy resolved from the runtime. Refusing to continue: an empty scope is never a valid plan.' );
}

// ---------------------------------------------------------------------------
// INVENTORY + PLAN. Identical in both modes; only the write differs.
// ---------------------------------------------------------------------------

$snapshot_terms = array();
$plan           = array();
$conflicts      = array();
$scanned_total  = 0;

foreach ( $taxonomies as $taxonomy ) {
	$tax_terms = conexao_stage_m_all_terms( $taxonomy );

	if ( is_wp_error( $tax_terms ) ) {
		conexao_script_fail( sprintf( 'cannot read terms of %s: %s', $taxonomy, $tax_terms->get_error_message() ) );
	}

	$scanned = 0;
	$tagged  = 0;

	foreach ( (array) $tax_terms as $term ) {
		++$scanned;

		$language = pll_get_term_language( (int) $term->term_id, 'slug' );

		if ( ! is_string( $language ) || '' === $language ) {
			// Already language-neutral: nothing to do, nothing to snapshot.
			continue;
		}

		++$tagged;

		// SAFETY: a term with a real cross-language counterpart must never be
		// de-tagged - that would destroy a genuine translation. Hard conflict.
		$counterpart = conexao_stage_m_counterpart( (int) $term->term_id );

		if ( $counterpart > 0 ) {
			$conflicts[] = array(
				'taxonomy'    => $taxonomy,
				'slug'        => $term->slug,
				'language'    => $language,
				'counterpart' => $counterpart,
				'reason'      => 'the term has a real cross-language counterpart; clearing the language would destroy a genuine translation',
			);
			continue;
		}

		$snapshot_terms[] = array(
			'taxonomy'     => $taxonomy,
			'slug'         => $term->slug,
			'name'         => $term->name,
			'term_id'      => (int) $term->term_id,
			'language'     => $language,
			'count'        => (int) $term->count,
			'restore_with' => 'pll_set_term_language( (int) term_id, $language )',
		);

		$plan[ $taxonomy ] = ( isset( $plan[ $taxonomy ] ) ? $plan[ $taxonomy ] : 0 ) + 1;
	}

	$scanned_total += $scanned;

	printf(
		'  %-20s terms=%-5d language-tagged=%-5d to-clear=%d' . "\n",
		esc_html( $taxonomy ),
		(int) $scanned,
		(int) $tagged,
		isset( $plan[ $taxonomy ] ) ? (int) $plan[ $taxonomy ] : 0
	);
}

$to_clear = count( $snapshot_terms );

echo "\n";

if ( ! empty( $conflicts ) ) {
	foreach ( $conflicts as $conflict ) {
		fprintf(
			STDERR,
			"ERROR: conflict on %s/%s: %s (counterpart term_id=%d). Refusing to apply.\n",
			esc_html( (string) $conflict['taxonomy'] ),
			esc_html( (string) $conflict['slug'] ),
			$conflict['reason'],
			$conflict['counterpart']
		);
	}

	conexao_script_fail(
		sprintf(
			'%d term(s) have a real cross-language counterpart. This is data this script must not touch; resolve it by hand.',
			count( $conflicts )
		)
	);
}

$result = array(
	'target'            => $ctx['target_url'],
	'mode'              => $ctx['mode'],
	'policy_source'     => 'PLL()->model->get_translated_taxonomies() (runtime)',
	'shared_taxonomies' => array_values( $taxonomies ),
	'plan'              => $plan,
	'scanned'           => $scanned_total,
	'to_clear'          => $to_clear,
	'conflicts'         => count( $conflicts ),
	'cleared'           => 0,
	// The snapshot is what makes the apply reversible: it records, per term,
	// exactly the language that was there before, and how to restore it.
	'snapshot'          => $snapshot_terms,
);

// The bootstrap reports the mode with a hyphen ("dry-run"), and that exact
// string is the ONLY thing standing between a plan and a write. A typo here
// would make --dry-run silently apply, so it is compared case-sensitively
// against a named constant and anything unexpected fails closed.
if ( 'dry-run' !== $ctx['mode'] && 'apply' !== $ctx['mode'] ) {
	conexao_script_fail( sprintf( 'unrecognised mode "%s": refusing to continue.', (string) $ctx['mode'] ) );
}

if ( 'dry-run' === $ctx['mode'] ) {
	echo "MODE: dry-run - ZERO writes performed.\n";
	echo 'plan: clear the stale language assignment on ' . (int) $to_clear . " shared term(s).\n";
	echo "no term is created, renamed, re-slugged, merged or deleted.\n";

	exit(
		conexao_script_summary(
			$ctx,
			array(
				'scanned'  => $scanned_total,
				'to_clear' => $to_clear,
				'cleared'  => 0,
				'errors'   => 0,
			),
			$result
		)
	);
}

// ---------------------------------------------------------------------------
// APPLY
// ---------------------------------------------------------------------------

$cleared = 0;
$failed  = array();

foreach ( $snapshot_terms as $entry ) {
	// Polylang's own model API, so its caches and bookkeeping stay consistent.
	// delete_language() is the documented inverse of set_language(). It returns
	// void, so the return value is deliberately NOT treated as success: the
	// only thing that counts is re-reading the language afterwards.
	PLL()->model->term->delete_language( (int) $entry['term_id'] );

	// Verify the write actually took effect rather than trusting the call.
	// Polylang caches term languages per request, so the cache is primed for
	// this term before it is read back, otherwise the assertion would test the
	// stale in-memory value rather than the stored one.
	clean_term_cache( (int) $entry['term_id'], 'term_language' );

	$after = pll_get_term_language( (int) $entry['term_id'], 'slug' );

	if ( ! is_string( $after ) || '' === $after ) {
		++$cleared;
	} else {
		$failed[] = array(
			'taxonomy' => $entry['taxonomy'],
			'slug'     => $entry['slug'],
			'term_id'  => $entry['term_id'],
			'after'    => $after,
		);
	}
}

$result['cleared'] = $cleared;
$result['failed']  = $failed;

echo "MODE: apply\n";
echo 'cleared ' . (int) $cleared . ' of ' . (int) $to_clear . " shared term language assignment(s).\n";

foreach ( $failed as $entry ) {
	fprintf(
		STDERR,
		"ERROR: %s/%s (term_id=%d) still reports a language after the write.\n",
		esc_html( (string) $entry['taxonomy'] ),
		esc_html( (string) $entry['slug'] ),
		(int) $entry['term_id']
	);
}

exit(
	conexao_script_summary(
		$ctx,
		array(
			'scanned'  => $scanned_total,
			'to_clear' => $to_clear,
			'cleared'  => $cleared,
			'errors'   => count( $failed ),
		),
		$result
	)
);
