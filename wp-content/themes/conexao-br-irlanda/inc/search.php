<?php
/**
 * Search module: accent-insensitive matching.
 *
 * Problem
 * -------
 * The site stores Portuguese content with diacritics ("Benefícios", "Saúde",
 * "Educação", "Organização", "Informação", ...). A visitor typing the plain,
 * unaccented, any-case form ("beneficios", "BENEFICIOS", "saude") expects
 * those pages to be found, but the match depends on the storage collation of
 * the wp_posts columns:
 *
 *   wp_posts.post_title LIKE '%beneficios%'
 *
 * On the production WordPress.com host the wp_posts columns are stored with
 * a NON-utf8mb4 character set (the UpdraftPlus database dumps declare
 * `DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci`; see
 * scripts/restore-updraft-db.sh), so the encoding/collation behaviour in
 * production differs from the local Docker stack (utf8mb4_unicode_ci).
 * Case is already handled by the *_ci collations; accents are the remaining
 * gap that must be closed at SQL level without depending on the column's own
 * collation.
 *
 * Approach
 * --------
 * WordPress's native search is preserved exactly:
 *   - Same WHERE clause over post_title, post_excerpt and post_content.
 *   - Same relevance ordering (title > excerpt > content matches rank first).
 * No stored content is modified and no PHP-side loop rewrites strings. We
 * force each search LIKE comparison to run under an explicit
 * accent-insensitive utf8mb4 collation by wrapping the column in a CONVERT
 * that first normalises it to utf8mb4:
 *
 *   CONVERT(wp_posts.post_title USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE '%beneficios%'
 *
 * Why the previous bare `COLLATE` attempt broke production
 * --------------------------------------------------------
 * A bare `wp_posts.post_title COLLATE utf8mb4_unicode_ci LIKE ...` is only
 * valid when the column's character set is already utf8mb4. On a latin1
 * column, MySQL/MariaDB rejects it with:
 *
 *   ERROR 1253 (42000): COLLATION 'utf8mb4_unicode_ci' is not valid for
 *   CHARACTER SET 'latin1'
 *
 * WordPress swallows that error and returns an empty result set, so EVERY
 * search (?s=...) on the production host silently returned "Nada encontrado"
 * — including plain ASCII terms such as "dublin". Wrapping the column in
 * `CONVERT(... USING utf8mb4)` makes the expression utf8mb4 on every server,
 * so the COLLATE is always accepted and the comparison is always performed
 * accent-insensitively by MySQL, regardless of whether the column is stored
 * as latin1, utf8mb3 or utf8mb4. Verified against simulated latin1 and
 * utf8mb4 tables (see docs/themes/conexao-br-irlanda.md).
 *
 * utf8mb4_unicode_ci is the project's configured collation (see
 * compose.yaml WORDPRESS_DB_COLLATION) and exists on every MySQL 5.6+/8.x
 * and MariaDB. The comparison is performed by MySQL, so it adds no extra
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
 * Apply an accent-insensitive utf8mb4 COLLATE to every search LIKE comparison.
 *
 * WordPress emits fragments of these shapes (all column references qualified
 * with $wpdb->posts, LIKE wildcards rendered as per-request placeholders in
 * this WordPress version):
 *
 *   (wp_posts.post_title LIKE '%term%')          posts_search (WHERE)
 *   (wp_posts.post_title NOT LIKE '%-term%')     posts_search (exclusion)
 *   wp_posts.post_title LIKE '%term%' DESC       posts_search_orderby (relevance)
 *   WHEN wp_posts.post_title LIKE '%term%' THEN  posts_search_orderby (CASE, multi-term)
 *
 * Each column reference immediately before a (NOT) LIKE operator is rewritten
 * to:
 *
 *   CONVERT(wp_posts.post_title USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE '%term%'
 *
 * The CONVERT(... USING utf8mb4) wrapper is essential: it turns the column
 * into a utf8mb4 expression no matter how the column is actually stored
 * (latin1 on the WordPress.com production host, utf8mb4 locally, etc.), so
 * the explicit COLLATE is always accepted by MySQL and the comparison is
 * always accent-insensitive. A bare `wp_posts.post_title COLLATE
 * utf8mb4_unicode_ci` is invalid on a latin1 column (ERROR 1253) and made the
 * entire production search return an empty result set. Idempotent: after the
 * transformation the column is followed by " USING" rather than whitespace +
 * LIKE, so re-running is a no-op (no risk of double CONVERT/COLLATE if
 * another hook re-enters the fragment).
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
	 * captures the operator. The whole column reference is wrapped in
	 * CONVERT(... USING utf8mb4) and the COLLATE is inserted just before the
	 * operator, so the comparison is forced to the accent-insensitive
	 * collation regardless of the column's own charset/collation. The column
	 * set is exactly the native WordPress search columns.
	 */
	$pattern = '/(' . preg_quote( (string) $wpdb->posts, '/' )
		. '\.(?:post_title|post_excerpt|post_content))\s+'
		. '((?:NOT\s+)?LIKE)/i';

	return preg_replace(
		$pattern,
		'CONVERT(${1} USING utf8mb4) COLLATE ' . $collation . ' ${2}',
		$sql
	);
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

