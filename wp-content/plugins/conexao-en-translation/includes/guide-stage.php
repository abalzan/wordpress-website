<?php
/**
 * The `en-guide` stage's TRANSLATED-TAXONOMY capability for the shared engine.
 *
 * `en-guide` is a B1 stage: it creates one real, linked EN `guide` per eligible
 * published PT guide. Those EN records must be filed under the EN counterpart of
 * the PT `conexao_category` term, which means the EN term has to exist first.
 * The shared engine owned no term-creation step, so before this file the only way
 * to get those terms was to re-implement a lifecycle — forbidden. The engine now
 * exposes an OPTIONAL, generic `taxonomy_callback`; this file is the stage-owned
 * half of it. It contains the term data mapping and the Polylang calls ONLY: no
 * planning, no counting, no snapshot, no gate arithmetic, no lifecycle. The
 * engine owns all of that.
 *
 * SCOPE — this is `conexao_category` and nothing else:
 *
 *   - `conexao_category` is Polylang-TRANSLATED: one concept identity, an EN term
 *     linked to its PT term in BOTH directions.
 *   - `conexao_county` and `conexao_town` are SHARED proper-name taxonomies: the
 *     SAME physical term serves both languages. They are never created, renamed,
 *     re-slugged, duplicated or translated per language here. Creating an EN
 *     county/town term is the exact failure `test-taxonomy-policy.php` exists to
 *     detect, so the term manifest below is scoped to `conexao_category` and the
 *     gate re-asserts that county/town stayed shared.
 *
 * IDEMPOTENCE: an already-linked EN term is repaired toward the authored English
 * (slug/name/description) instead of creating a second term, so a re-run reports
 * zero creates. A pre-existing UNLINKED term holding the authored EN slug is
 * linked rather than duplicated, which is the safe convergence for a slug that
 * was created outside this stage.
 *
 * @package Conexao_EN_Translation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The taxonomy this stage translates. Deliberately a single, explicit taxonomy.
 *
 * @return string
 */
function conexao_en_translation_guide_taxonomy(): string {
	return 'conexao_category';
}

/**
 * The authored EN term translations for this stage, keyed by PT term slug.
 *
 * @return array<string,array{name:string,slug:string,description:string}>
 */
function conexao_en_translation_guide_terms(): array {
	return function_exists( 'conexao_en_translation_guide_terms_v1' )
		? conexao_en_translation_guide_terms_v1()
		: array();
}

/**
 * The form WordPress actually STORES for a term name/description.
 *
 * `wp_insert_term()` runs the value through `sanitize_term_field( …, 'db' )`,
 * which applies KSES: a bare `&` is stored as `&amp;`. So the authored
 * `'Immigration & Visas'` is stored as `'Immigration &amp; Visas'`.
 *
 * This matters twice, and both are correctness, not cosmetics:
 *
 *   - REPAIR: comparing the stored term against the raw authored string would
 *     make every term containing `&` differ on EVERY run, so the idempotence
 *     guarantee ("a re-run reports zero writes") would be false.
 *   - GATE: comparing the two raw strings would report a correct term as wrong.
 *
 * This is the same discipline the retired Stage 9 importer applied to the guide
 * bodies through its `g_content()` helper, and the same narrow normalisation the
 * `en-leisure-description` and `en-course-provider-description` stages use for
 * their PT-drift comparison: normalise to the stored form, never invent content.
 *
 * @param string $value Authored term field.
 * @return string The stored form of that value.
 */
function conexao_en_translation_guide_term_stored( string $value ): string {
	return wp_kses( $value, array() );
}

/**
 * Create/link the EN `conexao_category` terms the EN guides are filed under.
 *
 * Invoked by the shared engine through the optional `taxonomy_callback` key,
 * BEFORE the record plan, and with the run's dry-run flag — so a dry-run reports
 * the same plan and performs zero writes.
 *
 * @param bool   $dry_run When true, count only; write nothing.
 * @param string $mode    Run mode (run|remove).
 * @return array<string,mixed> Counters for the run summary.
 */
function conexao_en_translation_guide_taxonomy_run( bool $dry_run, string $mode = 'run' ): array {
	$taxonomy = conexao_en_translation_guide_taxonomy();
	$terms    = conexao_en_translation_guide_terms();

	$counters = array(
		'taxonomy'         => $taxonomy,
		'pt_terms_missing' => 0,
		'en_terms_present' => 0,
		'en_terms_planned' => 0,
		'en_terms_created' => 0,
		'en_terms_linked'  => 0,
		'errors'           => 0,
	);

	foreach ( $terms as $pt_slug => $en ) {
		$pt_term = get_term_by( 'slug', (string) $pt_slug, $taxonomy );

		// The manifest is authored for a concept that may not exist on this
		// site. That is reported, never a silent pass and never a created term.
		if ( ! $pt_term instanceof WP_Term ) {
			++$counters['pt_terms_missing'];
			continue;
		}

		$en_term_id = function_exists( 'pll_get_term' ) ? (int) pll_get_term( (int) $pt_term->term_id, 'en' ) : 0;

		if ( $en_term_id > 0 ) {
			++$counters['en_terms_present'];

			// Already linked: converge on the authored English instead of
			// creating a second term, so a re-run is a true no-op.
			if ( ! $dry_run ) {
				$en_term = get_term( $en_term_id, $taxonomy );

				if ( $en_term instanceof WP_Term ) {
					$update = array();

					if ( $en_term->slug !== (string) $en['slug'] ) {
						$update['slug'] = (string) $en['slug'];
					}

					// Compared in the STORED form, so a term whose authored name
					// contains `&` is not rewritten on every run.
					$authored_name = conexao_en_translation_guide_term_stored( (string) $en['name'] );

					if ( $en_term->name !== $authored_name ) {
						$update['name'] = (string) $en['name'];
					}

					$authored_description = conexao_en_translation_guide_term_stored( (string) $en['description'] );

					if ( '' !== $authored_description && $en_term->description !== $authored_description ) {
						$update['description'] = (string) $en['description'];
					}

					if ( ! empty( $update ) ) {
						$done = wp_update_term( $en_term_id, $taxonomy, $update );

						if ( is_wp_error( $done ) ) {
							++$counters['errors'];
						}
					}
				}
			}

			++$counters['en_terms_linked'];
			continue;
		}

		++$counters['en_terms_planned'];

		if ( $dry_run ) {
			continue;
		}

		$created = wp_insert_term(
			(string) $en['name'],
			$taxonomy,
			array(
				'slug'        => (string) $en['slug'],
				'description' => (string) $en['description'],
			)
		);

		// A term already sitting on the authored EN slug (created outside this
		// stage) is LINKED, never duplicated.
		if ( is_wp_error( $created ) ) {
			$existing = get_term_by( 'slug', (string) $en['slug'], $taxonomy );

			if ( ! $existing instanceof WP_Term ) {
				++$counters['errors'];
				continue;
			}

			$en_term_id = (int) $existing->term_id;
		} else {
			$en_term_id = (int) $created['term_id'];
		}

		if ( function_exists( 'pll_set_term_language' ) ) {
			pll_set_term_language( $en_term_id, 'en' );
		}

		if ( function_exists( 'pll_save_term_translations' ) ) {
			pll_save_term_translations(
				array(
					'pt' => (int) $pt_term->term_id,
					'en' => $en_term_id,
				)
			);
		}

		++$counters['en_terms_created'];
		++$counters['en_terms_linked'];
	}

	return $counters + conexao_en_translation_guide_taxonomy_remove( $terms, $taxonomy, $dry_run, $mode );
}

/**
 * REMOVE path: delete only the EN terms this stage's manifest owns.
 *
 * A term is removed only when it is an EN term, is linked to this manifest's PT
 * term, carries the authored EN slug, and is attached to no post. Anything else
 * is left alone, so an unrelated term is never touched and a term still in use is
 * reported as an error rather than deleted out from under a record.
 *
 * @param array  $terms    Authored term manifest keyed by PT slug.
 * @param string $taxonomy Taxonomy name.
 * @param bool   $dry_run  When true, count only; write nothing.
 * @param string $mode     Run mode (run|remove).
 * @return array<string,int> Removal counters.
 */
function conexao_en_translation_guide_taxonomy_remove( array $terms, string $taxonomy, bool $dry_run, string $mode ): array {
	$counters = array(
		'en_terms_removed' => 0,
		'errors'           => 0,
	);

	if ( 'remove' !== $mode || $dry_run ) {
		return $counters;
	}

	foreach ( $terms as $pt_slug => $en ) {
		$pt_term = get_term_by( 'slug', (string) $pt_slug, $taxonomy );

		if ( ! $pt_term instanceof WP_Term || ! function_exists( 'pll_get_term' ) ) {
			continue;
		}

		$en_term_id = (int) pll_get_term( (int) $pt_term->term_id, 'en' );

		if ( $en_term_id <= 0 ) {
			continue;
		}

		$en_term = get_term( $en_term_id, $taxonomy );

		if ( ! $en_term instanceof WP_Term || (string) $en_term->slug !== (string) $en['slug'] ) {
			continue;
		}

		// Still in use by a record: refuse rather than orphan a post's filing.
		if ( (int) $en_term->count > 0 ) {
			++$counters['errors'];
			continue;
		}

		$done = wp_delete_term( $en_term_id, $taxonomy );

		if ( is_wp_error( $done ) ) {
			++$counters['errors'];
			continue;
		}

		++$counters['en_terms_removed'];
	}

	return $counters;
}

/**
 * Numeric taxonomy gate for this stage. Returns a FAILURE COUNT, so the shared
 * engine folds it into the same PASS|FAIL verdict as the record gate.
 *
 * Fails closed on: a PT term with no EN counterpart, an EN term that does not
 * link back, an EN term whose slug drifted from the authored English, and any
 * per-language county/town duplicate (the shared-taxonomy policy).
 *
 * @return int Number of taxonomy failures; 0 means the taxonomy is correct.
 */
function conexao_en_translation_guide_taxonomy_gate(): int {
	$taxonomy = conexao_en_translation_guide_taxonomy();
	$terms    = conexao_en_translation_guide_terms();
	$failures = 0;

	if ( ! function_exists( 'pll_get_term' ) ) {
		return 1;
	}

	foreach ( $terms as $pt_slug => $en ) {
		$pt_term = get_term_by( 'slug', (string) $pt_slug, $taxonomy );

		// The concept is absent on this site; the term cannot be verified.
		if ( ! $pt_term instanceof WP_Term ) {
			++$failures;
			continue;
		}

		$en_term_id = (int) pll_get_term( (int) $pt_term->term_id, 'en' );

		if ( $en_term_id <= 0 ) {
			++$failures;
			continue;
		}

		$en_term = get_term( $en_term_id, $taxonomy );

		// The pair must link in BOTH directions.
		if ( ! $en_term instanceof WP_Term || (int) pll_get_term( $en_term_id, 'pt' ) !== (int) $pt_term->term_id ) {
			++$failures;
			continue;
		}

		if ( (string) $en_term->slug !== (string) $en['slug'] ) {
			++$failures;
		}

		// The name and description are asserted in their STORED form, because that
		// is the form the site legitimately holds (see
		// conexao_en_translation_guide_term_stored()).
		if ( conexao_en_translation_guide_term_stored( (string) $en['name'] ) !== (string) $en_term->name ) {
			++$failures;
		}

		$authored_description = conexao_en_translation_guide_term_stored( (string) $en['description'] );

		if ( '' !== $authored_description && (string) $en_term->description !== $authored_description ) {
			++$failures;
		}
	}

	// The SHARED proper-name taxonomies must still be shared: no per-language
	// suffixed duplicate and no duplicated slug.
	foreach ( array( 'conexao_county', 'conexao_town' ) as $shared ) {
		$all = get_terms(
			array(
				'taxonomy'   => $shared,
				'hide_empty' => false,
				'lang'       => '',
			)
		);

		if ( is_wp_error( $all ) ) {
			++$failures;
			continue;
		}

		$seen = array();

		foreach ( $all as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			if ( preg_match( '/-(en|pt)$/', (string) $term->slug ) ) {
				++$failures;
			}

			$seen[] = (string) $term->slug;
		}

		$failures += count( $seen ) - count( array_unique( $seen ) );
	}

	return $failures;
}
