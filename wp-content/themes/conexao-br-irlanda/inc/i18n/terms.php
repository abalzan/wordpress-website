<?php
/**
 * Language-aware taxonomy term resolution
 *
 * Translated category/tag resolution (conexao_lang_term), the
 * per-language content probe used by the filter bars, cross-language term
 * lookup and the per-post-type language content check. The shared
 * proper-noun taxonomies (conexao_county, conexao_town) deliberately
 * resolve to the same term in both languages.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * STAGE 3.3 — the current language's counterpart of a taxonomy term.
 *
 * Used by language-aware taxonomy links (the Guides category cards). Returns:
 *
 *  - the term itself when Polylang is inactive or no language context exists
 *    (exact pre-Polylang behaviour),
 *  - the LINKED term of the current language when one exists (and, when a
 *    post type is given, when that term is actually used by published content
 *    of that type in this language — a filter link must never lead to an empty
 *    English archive),
 *  - null when there is no usable counterpart, so the caller can fall back to
 *    the plain language archive URL instead of inventing a term URL.
 *
 * Shared proper-noun taxonomies (county/town) are deliberately not
 * Polylang-translated; `pll_get_term()` simply returns the same term for them,
 * so they keep resolving to themselves in both languages.
 *
 * @param mixed  $term      Candidate term (WP_Term expected).
 * @param string $post_type Optional post type the term must have content for.
 * @return WP_Term|null
 */
function conexao_lang_term( $term, string $post_type = '' ) {
	if ( ! $term instanceof WP_Term ) {
		return null;
	}

	if ( ! conexao_polylang_active() || ! function_exists( 'pll_get_term' ) ) {
		return $term;
	}

	$current = conexao_current_language_slug();

	if ( '' === $current ) {
		return $term;
	}

	$translation_id = pll_get_term( (int) $term->term_id, $current );

	if ( ! $translation_id ) {
		return null;
	}

	$translated = get_term( (int) $translation_id, $term->taxonomy );

	if ( ! $translated || is_wp_error( $translated ) ) {
		return null;
	}

	if ( '' !== $post_type && ! conexao_term_has_language_content( (int) $translated->term_id, $translated->taxonomy, $post_type, $current ) ) {
		return null;
	}

	return $translated;
}

/**
 * Does a term carry published content of a post type in a language?
 *
 * One cheap query (no_found_rows, ids only), memoised per request.
 *
 * @param int    $term_id    Term id.
 * @param string $taxonomy   Taxonomy name.
 * @param string $post_type  Post type name.
 * @param string $language   Language slug.
 * @return bool
 */
function conexao_term_has_language_content( int $term_id, string $taxonomy, string $post_type, string $language ): bool {
	static $cache = array();

	$key = $term_id . '|' . $taxonomy . '|' . $post_type . '|' . $language;

	if ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}

	$args = array(
		'post_type'              => $post_type,
		'post_status'            => 'publish',
		'posts_per_page'         => 1,
		'fields'                 => 'ids',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
		'tax_query'              => array(
			array(
				'taxonomy' => $taxonomy,
				'field'    => 'term_id',
				'terms'    => $term_id,
			),
		),
	);

	if ( '' !== $language && conexao_polylang_active() ) {
		$args['lang'] = $language;
	}

	$query = new WP_Query( $args );

	$cache[ $key ] = ! empty( $query->posts );

	return $cache[ $key ];
}

/**
 * STAGE 3.3 — look a term up by slug across every language.
 *
 * `get_term_by()` is language-filtered by Polylang, so a canonical Portuguese
 * slug cannot be found while an English request is being rendered. Card
 * definitions and other canonical configuration keep Portuguese slugs, so the
 * lookup is widened here (Polylang's documented `lang => ''` query argument)
 * and the caller then resolves the current language's counterpart with
 * `conexao_lang_term()`.
 *
 * @param string $slug     Term slug.
 * @param string $taxonomy Taxonomy name.
 * @return WP_Term|null
 */
function conexao_find_term_across_languages( string $slug, string $taxonomy ) {
	$slug = sanitize_title( $slug );

	if ( '' === $slug ) {
		return null;
	}

	$args = array(
		'taxonomy'   => $taxonomy,
		'slug'       => $slug,
		'hide_empty' => false,
	);

	if ( conexao_polylang_active() ) {
		$args['lang'] = '';
	}

	$terms = get_terms( $args );

	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return null;
	}

	return $terms[0] instanceof WP_Term ? $terms[0] : null;
}

/**
 * Does a translated post type have any published content in a language?
 *
 * Used by the hreflang logic: an alternate is only emitted when the target
 * language archive is real content, never for an empty shell.
 * Request-cached (one lightweight query per post type/language pair).
 *
 * @param string $post_type Post type name.
 * @param string $slug      Language slug.
 * @return bool
 */
function conexao_language_has_post_type_content( string $post_type, string $slug ): bool {
	static $cache = array();

	$key = $post_type . '|' . $slug;

	if ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}

	$query = new WP_Query(
		array(
			'post_type'              => $post_type,
			'post_status'            => 'publish',
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'ignore_sticky_posts'    => true,
			'lang'                   => $slug,
		)
	);

	$cache[ $key ] = ! empty( $query->posts );

	return $cache[ $key ];
}
