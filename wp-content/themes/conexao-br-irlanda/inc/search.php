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
 * No stored content is modified and no PHP-side loop rewrites strings. Each
 * search LIKE comparison is rewritten so BOTH sides are normalised to a
 * byte-correct utf8mb4 expression under an explicit accent-insensitive
 * collation:
 *
 *   CONVERT(CONVERT(wp_posts.post_title USING binary) USING utf8mb4)
 *     COLLATE utf8mb4_unicode_ci
 *     LIKE CONVERT(CONVERT('%beneficios%' USING binary) USING utf8mb4)
 *
 * Why both sides are re-interpreted through BINARY
 * ------------------------------------------------
 * On the production host the wp_posts columns are declared latin1 but contain
 * GENUINE UTF-8 BYTES (UTF-8 written over a latin1 connection), e.g. "Saúde"
 * is stored as the bytes 5361C3BA6465. The previous single-sided rewrite,
 *
 *   CONVERT(wp_posts.post_title USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE '%beneficios%',
 *
 * asks MySQL to convert the column FROM ITS DECLARED CHARSET (latin1) to
 * utf8mb4 — which re-encodes the already-UTF-8 bytes into mojibake
 * ("CapacitaÃ§Ã£o"), so unaccented patterns still never match. The production
 * symptom therefore persisted: only plain-ASCII searches found rows.
 *
 * The fixed expression converts the column to BINARY first (byte-transparent:
 * the raw stored bytes, no charset re-encoding) and THEN re-interprets those
 * bytes as utf8mb4 — the exact text that was stored. The pattern literal
 * receives the same treatment: WordPress sends it as UTF-8 bytes over a
 * latin1 connection, so CONVERT(... USING binary) recovers the raw bytes and
 * the second CONVERT re-interprets them as utf8mb4. The comparison is thus
 * byte-correct and accent-insensitive in EVERY environment:
 *   - latin1 columns + latin1 connection (production today),
 *   - utf8mb4 columns + utf8mb4 connection (local Docker, or after a storage
 *     migration — the two-step CONVERT is then a no-op wrapper),
 * and both operands are always utf8mb4 expressions, so ERROR 1253
 * ("COLLATION 'utf8mb4_unicode_ci' is not valid for CHARACTER SET 'latin1'")
 * can never occur. Verified against a byte-faithful production copy
 * (latin1 columns, latin1 session): "saude" → 27 matches (plain LIKE: 1),
 * accented "saúde" → 5; evidence in scripts/charset-migration/artifacts/.
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
 * Each `column (NOT) LIKE '<pattern>'` pair is rewritten to:
 *
 *   CONVERT(CONVERT(column USING binary) USING utf8mb4)
 *     COLLATE utf8mb4_unicode_ci (NOT) LIKE
 *   CONVERT(CONVERT('%pattern%' USING binary) USING utf8mb4)
 *
 * Both sides go through BINARY first: BINARY is byte-transparent, so the
 * second CONVERT re-interprets the *stored / sent* bytes as utf8mb4 instead
 * of re-encoding them from the declared charset (latin1 on the WordPress.com
 * production host, utf8mb4 locally). The explicit COLLATE is always accepted
 * because both operands are utf8mb4 expressions, and the comparison is always
 * accent-insensitive. Idempotent: after the transformation the column is
 * followed by " USING binary" rather than whitespace + LIKE, so re-running is
 * a no-op (no risk of double CONVERT/COLLATE if another hook re-enters the
 * fragment).
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
	 * Match `column (NOT) LIKE '<pattern>'`: group 1 is the table-qualified
	 * searchable column ($wpdb->posts.post_title etc.), group 2 the (NOT) LIKE
	 * operator, group 3 the inside of the quoted pattern literal (escaped
	 * characters such as \' or \% are captured verbatim). Both sides are
	 * rebuilt through CONVERT(CONVERT(... USING binary) USING utf8mb4) so the
	 * comparison is byte-correct and accent-insensitive regardless of the
	 * column charset or the connection charset (see the file docblock). The
	 * column set is exactly the native WordPress search columns.
	 */
	$pattern = '/(' . preg_quote( (string) $wpdb->posts, '/' )
		. '\.(?:post_title|post_excerpt|post_content))\s+'
		. '((?:NOT\s+)?LIKE)\s+\'((?:[^\'\\\\]|\\\\.)*)\'/i';

	return preg_replace_callback(
		$pattern,
		function ( $m ) use ( $collation ) {
			return 'CONVERT(CONVERT(' . $m[1] . ' USING binary) USING utf8mb4)'
				. ' COLLATE ' . $collation . ' ' . $m[2]
				. ' CONVERT(CONVERT(\'' . $m[3] . '\' USING binary) USING utf8mb4)';
		},
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

