<?php
/**
 * Canonical URL output
 *
 * Emits exactly one canonical link for every indexable context (singular,
 * archives, terms, search), normalized for pagination and query parameters,
 * and removes core's rel_canonical so no duplicate tag is emitted. The value
 * passes through the conexao_seo_canonical_url filter, which the language
 * layer owns (inc/i18n/hreflang.php).
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * ---------------------------------------------------------------------------
 * 3. CANONICAL URLS
 * ---------------------------------------------------------------------------
 * Every indexable page gets a self-referencing canonical. Pagination and
 * query parameters are normalized to avoid duplicate content.
 */
function conexao_seo_canonical() {
	$canonical = '';

	if ( is_singular() ) {
		$canonical = get_permalink();
	} elseif ( is_front_page() ) {
		$canonical = home_url( '/' );
	} elseif ( is_home() ) {
		// The static posts page (Blog) is a real page record: its canonical is
		// its OWN permalink in the current language — `/blog/` in Portuguese,
		// `/en/blog/` in English once the linked EN posts page exists (Stage 5).
		// A bare language home serving the posts index (no static front page)
		// is still the home URL. This is what keeps the Blog archive
		// self-canonical instead of pointing the archive at the site home.
		$posts_page_id = ! empty( $GLOBALS['wp_query']->is_posts_page ) ? (int) get_option( 'page_for_posts' ) : 0;
		$canonical     = $posts_page_id > 0 ? (string) get_permalink( $posts_page_id ) : home_url( '/' );
	} elseif ( is_post_type_archive() ) {
		$canonical = get_post_type_archive_link( get_query_var( 'post_type' ) );
	} elseif ( is_tax() || is_category() || is_tag() ) {
		$canonical = get_term_link( get_queried_object() );
	} elseif ( is_search() ) {
		$canonical = home_url( '/?s=' . rawurlencode( get_search_query() ) );
	} elseif ( is_404() ) {
		return; // No canonical on 404.
	}

	if ( $canonical ) {
		// Strip pagination from canonical (page/2/ etc. canonicalizes to base).
		$canonical = preg_replace( '#/page/\d+/?#', '/', $canonical );

		/**
		 * Filters the canonical URL before it is printed.
		 *
		 * Stage 2: the Polylang integration (inc/polylang.php) uses this to
		 * point a fallback rendering (a Portuguese record served under an EN
		 * URL) at the record's own language URL, so Portuguese stays the
		 * canonical of untranslated content. The theme stays the single owner
		 * of canonical output — the filter only adjusts the value.
		 *
		 * @param string $canonical Canonical URL.
		 */
		$canonical = (string) apply_filters( 'conexao_seo_canonical_url', $canonical );

		echo '<link rel="canonical" href="' . esc_url( $canonical ) . '" />' . "\n";
	}
}
add_action( 'wp_head', 'conexao_seo_canonical', 5 );

// This theme renders its own canonical for every indexable context above
// (singular, archives, terms, search — normalized for pagination and query
// parameters). WordPress core's rel_canonical() would emit a SECOND,
// unnormalized canonical tag on singular pages (e.g. /empregos/?pagina=2
// rendered two identical <link rel="canonical"> tags), so remove it.
remove_action( 'wp_head', 'rel_canonical' );
