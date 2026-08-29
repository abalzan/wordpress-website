<?php
/**
 * Search module: accent-insensitive matching.
 *
 * Problem
 * -------
 * The site stores Portuguese content with diacritics ("Benefícios", "Saúde",
 * "Educação", "Organização", "Informação", ...). A visitor typing the plain,
 * unaccented, any-case form ("beneficios", "BENEFICIOS", "saude") expects
 * those pages to be found, but the match depends on the collation of the
 * wp_posts columns:
 *
 *   wp_posts.post_title LIKE '%beneficios%'
 *
 * With an accent-sensitive collation (e.g. utf8mb4_bin) this does not match
 * "Benefícios", so the page is missed. Case is already handled by the *_ci
 * collations; accents are the remaining gap.
 *
 * Approach
 * --------
 * WordPress's native search is preserved exactly:
 *   - Same WHERE clause over post_title, post_excerpt and post_content.
 *   - Same relevance ordering (title > excerpt > content matches rank first).
 * No stored content is modified and no PHP-side loop rewrites strings. We
 * only append an explicit accent-insensitive collation to each search LIKE
 * comparison at query time:
 *
 *   wp_posts.post_title COLLATE utf8mb4_unicode_ci LIKE '%beneficios%'
 *
 * utf8mb4_unicode_ci is the project's configured collation (see
 * compose.yaml WORDPRESS_DB_COLLATION) and exists on every MySQL 5.6+/8.x
 * and MariaDB. When the column is already accent-insensitive (the local
 * Docker stack) the COLLATE is a harmless no-op; when it is accent-sensitive
 * (a stricter collation on the production WordPress.com host) it forces a
 * correct match. The comparison is performed by MySQL, so it adds no extra
 * DB query and no per-request PHP loop over posts (a single preg_replace on
 * a short SQL fragment per search request).
 *
 * Scope: the main public search query only (is_search + is_main_query and
 * not in wp-admin), so it never interferes with the event importer's
 * _event_status gating, the archive filters in conexao_content_archive_query(),
 * admin searches, or secondary WP_Query searches. The search URL (?s=...)
 * is untouched.
 *
 * @package Conexao_BR_Irlanda
 */

defined( 'ABSPATH' ) || exit;

/**
 * Accent-insensitive MySQL collation applied to search LIKE comparisons.
 *
 * Defaults to the project's configured collation (utf8mb4_unicode_ci, see
 * compose.yaml). Exposed via a filter so a host whose MySQL lacks this exact
 * collation can supply an equivalent accent-insensitive one (for example
 * utf8mb4_0900_ai_ci on MySQL 8.0+) without changing code. Cached for the
 * duration of the request.
 *
 * @return string A MySQL utf8mb4 collation name.
 */
function conexao_search_collation() {
	static $collation = null;

	if ( null === $collation ) {
		$collation = (string) apply_filters( 'conexao_search_collation', 'utf8mb4_unicode_ci' );
	}

	return $collation;
}

/**
 * Whether the given query is the main, public front-end search query.
 *
 * @param WP_Query $query The query being built.
 * @return bool
 */
function conexao_is_main_search( $query ) {
	if ( ! $query instanceof WP_Query ) {
		return false;
	}

	// Restrict to the main query so archive filters, the event importer's
	// status gating, admin searches and secondary WP_Query searches are left
	// untouched.
	return ! is_admin() && $query->is_main_query() && $query->is_search();
}

/**
 * Append an accent-insensitive COLLATE to every search LIKE comparison.
 *
 * WordPress emits fragments of these shapes (all column references qualified
 * with $wpdb->posts):
 *
 *   (wp_posts.post_title LIKE '%term%')          posts_search (WHERE)
 *   (wp_posts.post_title NOT LIKE '%-term%')     posts_search (exclusion)
 *   wp_posts.post_title LIKE '%term%' DESC       posts_search_orderby (relevance)
 *   WHEN wp_posts.post_title LIKE '%term%' THEN  posts_search_orderby (CASE, multi-term)
 *
 * Each column reference immediately before a (NOT) LIKE operator is rewritten
 * to:
 *
 *   wp_posts.post_title COLLATE utf8mb4_unicode_ci LIKE '%term%'
 *
 * The COLLATE is placed on the column so the comparison is forced to the
 * accent-insensitive collation regardless of the column's own collation or
 * the connection collation. Idempotent: after transformation the column is
 * followed by "COLLATE ..." rather than whitespace + LIKE, so re-running is
 * a no-op (no risk of double COLLATE if another hook re-enters the fragment).
 *
 * @param string $sql The search fragment (WHERE or ORDER BY relevance SQL).
 * @return string The fragment with accent-insensitive collations applied.
 */
function conexao_apply_accent_insensitive_search_collation( $sql ) {
	global $wpdb;

	if ( ! $sql || empty( $wpdb->posts ) ) {
		return $sql;
	}

	$collation = conexao_search_collation();

	/*
	 * Match a table-qualified searchable column ($wpdb->posts.post_title etc.)
	 * followed by whitespace and a LIKE / NOT LIKE operator. Group 1 captures
	 * the "table.column" reference (NOT the trailing whitespace); group 2
	 * captures the operator. The COLLATE is inserted between them. The column
	 * set is exactly the native WordPress search columns.
	 */
	$pattern = '/(' . preg_quote( (string) $wpdb->posts, '/' )
		. '\.(?:post_title|post_excerpt|post_content))\s+'
		. '((?:NOT\s+)?LIKE)/i';

	return preg_replace( $pattern, '${1} COLLATE ' . $collation . ' ${2}', $sql );
}

/**
 * Make the main search WHERE clause accent-insensitive.
 *
 * @param string   $search The WHERE search SQL fragment.
 * @param WP_Query $query  The query.
 * @return string
 */
function conexao_accent_insensitive_posts_search( $search, $query ) {
	if ( ! conexao_is_main_search( $query ) ) {
		return $search;
	}

	return conexao_apply_accent_insensitive_search_collation( $search );
}
add_filter( 'posts_search', 'conexao_accent_insensitive_posts_search', 10, 2 );

/**
 * Keep search relevance ordering accent-insensitive so an accented title
 * still receives its title-match ranking boost (the SAME ranking logic
 * WordPress builds in WP_Query::parse_search_order()).
 *
 * @param string   $orderby The ORDER BY search fragment.
 * @param WP_Query $query   The query.
 * @return string
 */
function conexao_accent_insensitive_posts_orderby( $orderby, $query ) {
	if ( ! conexao_is_main_search( $query ) ) {
		return $orderby;
	}

	return conexao_apply_accent_insensitive_search_collation( $orderby );
}
add_filter( 'posts_search_orderby', 'conexao_accent_insensitive_posts_orderby', 10, 2 );

