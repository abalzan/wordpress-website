<?php
/**
 * Stage 3.2 — make conexao_county / conexao_town truly SHARED taxonomies.
 *
 * LOCAL / STAGING ONLY. Companion to the Stage 3.2 policy correction in the
 * theme (inc/i18n/guard.php, conexao_polylang_translated_taxonomies()):
 * county and town terms are proper nouns and are NOT Polylang-translated
 * anymore (architecture decision §1: "Counties/towns are shared — no
 * per-language duplicate terms").
 *
 * Background: Polylang Free ≥ 3.5 auto-creates a term translation for a
 * county/town term the first time that term is assigned to an English record
 * (PLL_Crud_Posts::set_object_terms), producing suffixed duplicates such as
 * `dublin-en` with the name copied verbatim — splitting filter slugs and
 * duplicating filter entries. With the taxonomies unregistered from Polylang
 * that path can no longer trigger; this script removes the artefacts it left.
 *
 * Polylang storage model (important — posts and terms use DIFFERENT
 * taxonomies):
 *   - posts: `language` + `post_translations` (object_id = post ID)
 *   - terms: `term_language` + `term_translations` (object_id = term ID)
 *
 * What this script does (idempotent):
 *   1. Suffixed duplicates (-en/-pt) in conexao_county/conexao_town: reassign
 *      their objects to the default-language sibling (read from the
 *      Polylang translation link), then delete the duplicate and its link.
 *   2. Remove `term_language` and `term_translations` relationships from
 *      every remaining conexao_county/conexao_town term (shared terms have
 *      no language). The `language`/`post_translations` taxonomies belong to
 *      POSTS and are never touched here.
 *   3. Self-heal: any PAGE that lost its `language` relationship is
 *      re-assigned the default language (defensive; covers the collateral
 *      damage of an earlier buggy version of this cleanup that matched
 *      post IDs instead of term IDs).
 *   4. Recalculate Polylang language counts and flush caches.
 *
 * Existing PT filter URLs are untouched: slugs and term IDs of every
 * default-language term stay exactly as they were.
 *
 * Usage (local only, NEVER production):
 *   wp eval-file scripts/stage32-share-location-taxonomies.php
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	$dir = __DIR__;
	while ( $dir !== dirname( $dir ) ) {
		if ( file_exists( $dir . '/wp-load.php' ) ) {
			require_once $dir . '/wp-load.php';
			break;
		}
		$dir = dirname( $dir );
	}
}
if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "WordPress bootstrap failed\n" );
	exit( 1 );
}

global $wpdb;

echo "== Stage 3.2 — share conexao_county / conexao_town ==\n\n";

if ( ! function_exists( 'pll_default_language' ) ) {
	fwrite( STDERR, "Polylang is not active.\n" );
	exit( 1 );
}

$shared_taxonomies = array( 'conexao_county', 'conexao_town' );
$default_lang      = pll_default_language( 'slug' );

// 1. Remove suffixed duplicates (auto-created by Polylang), reassigning
//    their objects to the default-language sibling first.
echo "-- Suffixed duplicates --\n";
foreach ( $shared_taxonomies as $taxonomy ) {
	$duplicates = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'lang'       => '',
		)
	);
	if ( is_wp_error( $duplicates ) ) {
		echo "  ERROR listing {$taxonomy}: " . $duplicates->get_error_message() . "\n";
		continue;
	}

	foreach ( $duplicates as $term ) {
		if ( ! $term instanceof WP_Term || ! preg_match( '/-(en|pt|ptbr|enus)$/i', (string) $term->slug ) ) {
			continue;
		}

		$translations = pll_get_term_translations( $term->term_id );
		$sibling_id   = isset( $translations[ $default_lang ] ) ? (int) $translations[ $default_lang ] : 0;

		if ( $sibling_id > 0 && $sibling_id !== (int) $term->term_id ) {
			$object_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d",
					$term->term_taxonomy_id
				)
			);
			foreach ( (array) $object_ids as $object_id ) {
				// Point the relationship straight at the shared sibling term.
				// The sibling keeps its slug/term id, so PT URLs are untouched.
				$sibling_tt = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s",
						$sibling_id,
						$taxonomy
					)
				);
				if ( $sibling_tt ) {
					$wpdb->query(
						$wpdb->prepare(
							"UPDATE {$wpdb->term_relationships} SET term_taxonomy_id = %d WHERE object_id = %d AND term_taxonomy_id = %d",
							(int) $sibling_tt,
							(int) $object_id,
							(int) $term->term_taxonomy_id
						)
					);
				}
			}
			echo "  reassigned {$taxonomy}/{$term->slug} objects → shared term #{$sibling_id}\n";
		} else {
			echo "  note: {$taxonomy}/{$term->slug} has no default-language sibling — deleting as orphan\n";
		}

		// Remove the translation link group, then the duplicate term itself.
		PLL()->model->term->delete_translation( (int) $term->term_id );
		wp_delete_term( (int) $term->term_id, $taxonomy );
		echo "  deleted duplicate {$taxonomy}/{$term->slug} (#{$term->term_id})\n";
	}
}

// 2. Remove the stale term-language and term-translation relationships from
//    every remaining county/town term (they are shared terms now).
//    object_id = the content TERM ID (never a post ID).
echo "\n-- Stale term_language / term_translations links --\n";
foreach ( $shared_taxonomies as $taxonomy ) {
	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'lang'       => '',
			'fields'     => 'ids',
		)
	);
	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		echo "  {$taxonomy}: no terms\n";
		continue;
	}

	$term_ids = array_map( 'intval', $terms );
	$ids_csv  = implode( ',', $term_ids );

	$deleted_lang = $wpdb->query(
		"DELETE FROM {$wpdb->term_relationships}
		 WHERE object_id IN ( {$ids_csv} )
		 AND term_taxonomy_id IN ( SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'term_language' )"
	);
	$deleted_tr = $wpdb->query(
		"DELETE FROM {$wpdb->term_relationships}
		 WHERE object_id IN ( {$ids_csv} )
		 AND term_taxonomy_id IN ( SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'term_translations' )"
	);

	echo "  {$taxonomy}: removed {$deleted_lang} term_language links, {$deleted_tr} term_translations links from " . count( $term_ids ) . " terms\n";
}

// 3. Self-heal pages: every page must belong to a language. A page without a
//    `language` relationship gets the default language back.
echo "\n-- Page language self-heal --\n";
$languageless_pages = $wpdb->get_col(
	"SELECT p.ID
	 FROM {$wpdb->posts} p
	 WHERE p.post_type = 'page'
	 AND p.post_status NOT IN ( 'trash', 'auto-draft' )
	 AND p.ID NOT IN (
		SELECT tr.object_id FROM {$wpdb->term_relationships} tr
		JOIN {$wpdb->term_taxonomy} ltt ON ltt.term_taxonomy_id = tr.term_taxonomy_id AND ltt.taxonomy = 'language'
	 )"
);
$healed = 0;
foreach ( (array) $languageless_pages as $page_id ) {
	pll_set_post_language( (int) $page_id, $default_lang );
	$healed++;
	echo "  restored {$default_lang} on page #{$page_id} (" . get_post_field( 'post_name', $page_id ) . ")\n";
}
if ( ! $healed ) {
	echo "  none needed\n";
}

// 4. Recalculate language counts and flush caches.
echo "\n-- Caches --\n";
foreach ( PLL()->model->get_languages_list() as $language ) {
	$language->update_count();
}
wp_cache_flush();
PLL()->model->clean_languages_cache();
clean_term_cache( array(), array( 'conexao_county', 'conexao_town', 'conexao_category', 'category' ), true );
echo "  flushed\n";

echo "\nDone.\n";
