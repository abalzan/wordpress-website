<?php
/**
 * Archive filter URL builders (blog and leisure)
 *
 * Builds the public filter URLs used by the Blog and Lazer filter bars
 * (category, county) and the shared leisure filter query/multi-slug
 * parsing. URL construction only - no queries.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function conexao_blog_category_filter_url( $category_slug ) {
	$blog_url = get_permalink( (int) get_option( 'page_for_posts' ) );
	if ( ! is_string( $blog_url ) || '' === $blog_url ) {
		$blog_url = home_url( '/blog/' );
	}

	$category_slug = sanitize_title( $category_slug );
	if ( '' === $category_slug ) {
		return $blog_url;
	}

	return add_query_arg( 'categoria', $category_slug, $blog_url );
}
/**
 * Build the Lazer category filter URL.
 *
 * Lazer categories are filtered on the Lazer archive itself via the
 * ?categoria= query parameter (/lazer/?categoria=slug) — the same URL
 * contract the archive's own filter bar uses (see conexao_content_archive_query()).
 * The archive base URL is resolved from the CPT archive link so it follows
 * the permalink rather than being hard-coded.
 *
 * @param string $category_slug Category slug. Empty returns the base archive URL.
 * @return string Absolute URL to the (optionally filtered) Lazer archive.
 */
function conexao_leisure_category_filter_url( $category_slug ) {
	$leisure_url = get_post_type_archive_link( 'leisure' );
	if ( ! is_string( $leisure_url ) || '' === $leisure_url ) {
		$leisure_url = home_url( '/lazer/' );
	}

	$category_slug = sanitize_title( $category_slug );
	if ( '' === $category_slug ) {
		return $leisure_url;
	}

	return add_query_arg( 'categoria', $category_slug, $leisure_url );
}

/**
 * Build the Lazer county (location) filter URL.
 *
 * Same contract as above for the ?county= parameter (/lazer/?county=slug).
 * Town-level filtering does not exist — towns are display text only.
 *
 * @param string $county_slug County slug. Empty returns the base archive URL.
 * @return string Absolute URL to the (optionally filtered) Lazer archive.
 */
function conexao_leisure_county_filter_url( $county_slug ) {
	$leisure_url = get_post_type_archive_link( 'leisure' );
	if ( ! is_string( $leisure_url ) || '' === $leisure_url ) {
		$leisure_url = home_url( '/lazer/' );
	}

	$county_slug = sanitize_title( $county_slug );
	if ( '' === $county_slug ) {
		return $leisure_url;
	}

	return add_query_arg( 'county', $county_slug, $leisure_url );
}
/**
 * Normalize a Lazer multi-select filter parameter into a clean slug list.
 *
 * Accepts either a comma-separated string (desktop hyperlink dropdowns:
 * ?atributo=exterior,familias / ?categoria=natureza,cultura) or an array of
 * slugs (the mobile sheet's checkbox groups submit atributo[] / categoria[]).
 * Every value is sanitized with sanitize_title (lowercase, canonical slug
 * form), empty values are dropped and duplicates are removed while keeping
 * the user's selection order, so URL generation is deterministic:
 * "exterior,familias,exterior" → array( 'exterior', 'familias' ).
 *
 * @param string|array $raw Raw parameter value (already unslashed or plain).
 * @return string[] List of unique, sanitized slugs in selection order.
 */
function conexao_leisure_multi_slugs( $raw ) {
	$parts = is_array( $raw ) ? $raw : explode( ',', (string) $raw );

	$slugs = array();
	foreach ( $parts as $part ) {
		$slug = sanitize_title( trim( (string) $part ) );
		if ( '' !== $slug ) {
			$slugs[] = $slug;
		}
	}

	return array_values( array_unique( $slugs ) );
}

/**
 * Read a Lazer multi-select filter parameter from the current request.
 *
 * @param string $param Query parameter name ('categoria', 'atributo').
 * @return string[] Unique sanitized slugs, empty array when absent.
 */
function conexao_leisure_query_slugs( $param ) {
	if ( ! isset( $_GET[ $param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public archive filter.
		return array();
	}

	return conexao_leisure_multi_slugs( wp_unslash( $_GET[ $param ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

/**
 * Build a Lazer archive filter URL from a complete filter state.
 *
 * The single shared URL builder for every filter surface (desktop dropdown
 * options, active-filter chips, mobile fallback form action): the caller
 * passes the FULL state (county slug or '', plus slug arrays for the
 * multi-select dimensions) and empty dimensions are simply omitted from the
 * URL. Because callers always pass the complete state, changing one filter
 * can never drop the others, and the URL never carries stale pagination
 * (it is built from the clean archive link, so any filter change resets
 * to page 1).
 *
 * @param array  $filters Filter state: 'county' (string), 'categoria' (string[]), 'atributo' (string[]).
 * @param string $base    Optional archive base URL; defaults to the leisure archive link.
 * @return string Filtered archive URL.
 */
function conexao_leisure_filter_url( array $filters, $base = '' ) {
	if ( ! $base ) {
		$base = get_post_type_archive_link( 'leisure' );
		if ( ! $base ) {
			$base = home_url( '/lazer/' );
		}
	}

	$url = $base;

	if ( ! empty( $filters['county'] ) ) {
		$url = add_query_arg( 'county', $filters['county'], $url );
	}
	if ( ! empty( $filters['categoria'] ) ) {
		$url = add_query_arg( 'categoria', implode( ',', $filters['categoria'] ), $url );
	}
	if ( ! empty( $filters['atributo'] ) ) {
		$url = add_query_arg( 'atributo', implode( ',', $filters['atributo'] ), $url );
	}

	return $url;
}

/**
 * Content-type archive query filtering.
 *
 * Each archive (Eventos, Cursos, Guias, Blog, Apoiadores) applies its own filtering and ordering
 * to the main query:
 *
 *  - Eventos: only upcoming events (date >= today), ordered by date ascending,
 *    with optional ?county= (county), ?cidade= (town) and ?categoria=
 *    (category) taxonomy filters (AND-combined).
 *  - Cursos: only published providers (_provider_status = published), ordered
 *    by display order, with optional ?categoria= (provider category meta) filter.
 *  - Guias: optional ?categoria= (conexao_category taxonomy) filter.
 *  - Blog (/blog/): optional ?categoria= (native `category` taxonomy) filter.
 *  - Apoiadores (/apoiadores/): same editor-curated ordering as the homepage
 *    carousel — "Ordem de exibição" (_sponsor_display_order) ascending first,
 *    then supporters without an order value newest published first (see
 *    conexao_sponsor_archive_ordered_ids()).
 *
 * The ?categoria= parameter is content-type-aware: on /eventos/ it filters by
 * the conexao_category taxonomy, on /cursos/ by the _provider_category meta,
 * and on /guias/ by the conexao_category taxonomy again. A filter from one
 * content type never produces results in another's archive.
 */
