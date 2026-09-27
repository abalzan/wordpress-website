<?php
/**
 * B2 (English fallback) archive and search query widening
 *
 * The approved B2 fallback query layer: EN archives widen to the PT master
 * set with translated records substituted, the EN posts page is pre-resolved,
 * and EN search is widened at the clause level. Applies only on /en/ requests.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * STAGE 3.1/5 — B2 content for the static posts page (Blog).
 *
 * While the posts page has NO linked English translation it is an approved B2
 * destination (`/blog/` → `/en/blog/`): under `/en/` the Blog archive renders the
 * Portuguese posts (PT content under the English URL). Polylang creates ONE
 * page-for-posts per language and scopes the main query to the posts page's own
 * language, so the posts-page query would be empty under the English URL; the
 * archive is therefore served from an explicit Portuguese-scoped query, reusing
 * WordPress' own `posts_pre_query` short-circuit so pagination and the
 * `?categoria=` filter keep working.
 *
 * STAGE 5 — once the Blog has a REAL English translation (linked EN posts page +
 * translated EN posts, connexao-blog-translation), this whole path retires
 * itself: `conexao_b2_posts_page_is_en_request()` is false, Polylang's own
 * language-scoped posts-page query serves the English posts, and the fallback
 * notice disappears (conexao_is_language_fallback() is false). No other change
 * to the B2 architecture is involved.
 *
 * @return bool
 */
function conexao_b2_posts_page_is_en_request(): bool {
	if ( is_admin() ) {
		return false;
	}

	if ( ! function_exists( 'conexao_polylang_active' ) || ! conexao_polylang_active() || ! function_exists( 'pll_home_url' ) ) {
		return false;
	}

	$posts_page_id = (int) get_option( 'page_for_posts' );

	if ( $posts_page_id <= 0 ) {
		return false;
	}

	// STAGE 5 — a real, published EN posts page (the linked translation of
	// this posts page) RETIRES the fallback: `/en/blog/` then serves the
	// English archive through WordPress/Polylang itself, so the Portuguese
	// post set must never be substituted for it. Only the presence of that EN
	// posts page changes anything here — every install that has not run the
	// Blog translation keeps the exact pre-Stage-5 fallback behaviour.
	if ( function_exists( 'pll_get_post' ) ) {
		$en_posts_page = (int) pll_get_post( $posts_page_id, 'en' );

		if ( $en_posts_page > 0 && $en_posts_page !== $posts_page_id && 'publish' === get_post_status( $en_posts_page ) ) {
			return false;
		}
	}

	$pt_path = untrailingslashit( (string) wp_parse_url( (string) get_permalink( $posts_page_id ), PHP_URL_PATH ) );

	if ( '' === $pt_path || '/' === $pt_path ) {
		return false;
	}

	$en_home_path = untrailingslashit( (string) wp_parse_url( (string) pll_home_url( 'en' ), PHP_URL_PATH ) );

	if ( '' === $en_home_path || '/' === $en_home_path ) {
		$en_home_path = '/en';
	}

	$base         = $en_home_path . $pt_path;
	$request_path = untrailingslashit( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ) );

	// The posts page itself and its paginated views (/en/blog/page/N/).
	return $request_path === $base || 0 === strpos( $request_path, $base . '/' );
}

/**
 * Serve the Portuguese posts on the B2 English Blog archive.
 *
 * @param WP_Post[]|null $posts Posts to short-circuit with (null lets core run).
 * @param WP_Query       $query Query object.
 * @return WP_Post[]|null
 */
function conexao_b2_posts_page_pre_query( $posts, $query ) {
	if ( null !== $posts || ! $query instanceof WP_Query ) {
		return $posts;
	}

	if ( ! $query->is_main_query() || ! $query->is_home() || ! conexao_b2_posts_page_is_en_request() ) {
		return $posts;
	}

	$per_page = (int) $query->get( 'posts_per_page' );
	$per_page = $per_page > 0 ? $per_page : (int) get_option( 'posts_per_page' );

	$args = array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'paged'          => max( 1, (int) $query->get( 'paged' ) ),
		'posts_per_page' => $per_page,
		'tax_query'      => array(
			array(
				'taxonomy' => 'language',
				'field'    => 'slug',
				'terms'    => array( 'pt' ),
				'operator' => 'IN',
			),
		),
	);

	$category = isset( $_GET['categoria'] ) ? sanitize_title( wp_unslash( $_GET['categoria'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( '' !== $category ) {
		$args['category_name'] = $category;
	}

	$blog = new WP_Query( $args );

	$query->found_posts   = (int) $blog->found_posts;
	$query->max_num_pages = (int) $blog->max_num_pages;

	return $blog->posts;
}
add_filter( 'posts_pre_query', 'conexao_b2_posts_page_pre_query', 10, 2 );

/**
 * STAGE 3.1 — B2 search widen + tax_query modification.
 *
 * For EN search: replace Polylang's single-language tax_query (language='en'
 * only) with a B2-aware query that allows EN posts of any type + B2-type PT
 * posts. The lang var stays 'en' (Polylang's parse_query set it), so
 * is_already_filtered() returns TRUE and filter_query does NOT re-add the
 * language tax_query when we modify it here.
 *
 * @param WP_Query $query Query object.
 * @return void
 */
function conexao_b2_search_widen_query( $query ) {
	if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
		return;
	}

	if ( ! function_exists( 'conexao_polylang_active' ) || ! conexao_polylang_active() ) {
		return;
	}

	if ( 'en' !== conexao_requested_language_slug() ) {
		return;
	}

	// Mark for posts_clauses filter.
	$query->set( 'conexao_b2_search_enabled', true );

	// Replace Polylang's single-language tax_query (language='en') with a
	// B2-aware clause that allows EN posts + B2-type PT posts.
	$tax_query = $query->get( 'tax_query' );
	if ( ! is_array( $tax_query ) ) {
		$tax_query = array();
	}

	$b2_types = function_exists( 'conexao_b2_post_types' ) ? conexao_b2_post_types() : array();
	if ( empty( $b2_types ) ) {
		return;
	}

	// Remove Polylang's language='en' clause.
	$new_tax_query = array();
	foreach ( $tax_query as $clause ) {
		if ( is_array( $clause ) && isset( $clause['taxonomy'] ) && 'language' === $clause['taxonomy'] ) {
			continue; // Skip Polylang's language clause
		}
		$new_tax_query[] = $clause;
	}

	// Add B2-aware language clause: allow EN OR (PT AND B2 post types).
	// Use OR relation at top level so the two language branches are ORed.
	$new_tax_query = array(
		'relation' => 'OR',
		array(
			'taxonomy' => 'language',
			'field'    => 'slug',
			'terms'    => 'en',
			'operator' => 'IN',
		),
		array(
			'taxonomy' => 'language',
			'field'    => 'slug',
			'terms'    => 'pt',
			'operator' => 'IN',
		),
	);

	$query->set( 'tax_query', $new_tax_query );
	$query->set( 'post_type', 'any' ); // Ensure post_type stays 'any' for search.

	// STAGE 3.2 — the PT fallback branch must not surface a PT record whose
	// own EN translation is already in the result set (no duplicate identity).
	if ( function_exists( 'conexao_b2_translation_replaced_pt_ids' ) ) {
		$replaced = conexao_b2_translation_replaced_pt_ids( $b2_types );
		if ( ! empty( $replaced ) ) {
			$existing_not_in = $query->get( 'post__not_in' );
			$existing_not_in = is_array( $existing_not_in ) ? $existing_not_in : array();
			$query->set( 'post__not_in', array_map( 'intval', array_merge( $existing_not_in, $replaced ) ) );
		}
	}
}
add_action( 'pre_get_posts', 'conexao_b2_search_widen_query', 31 );

/**
 * STAGE 3.1 — B2 search language/post-type intersection filter.
 *
 * Wideens the language scope (lang=en,pt) THEN narrows the actual result set
 * in SQL so EN search returns:
 *   - EN posts of any type, plus
 *   - PT posts ONLY in B2 post types (event/leisure/sponsor/course_provider/job)
 *   - never PT guides, PT blog posts, PT pages (B1), never hidden-status events.
 *
 * The lang scope alone is not enough (it admits every PT publishable post).
 * This runs after the taxonomy query is built, so it keeps Polylang's behavior
 * intact for everything except the title/content/excerpt search columns.
 *
 * @param array    $clauses  The posts_clauses array (WHERE + JOIN fragments).
 * @param WP_Query $query    The query object.
 * @return array
 */
function conexao_b2_search_query_clauses( $clauses, $query ) {
	if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
		return $clauses;
	}

	if ( ! function_exists( 'conexao_polylang_active' ) || ! conexao_polylang_active() ) {
		return $clauses;
	}

	if ( 'en' !== conexao_requested_language_slug() ) {
		return $clauses;
	}

	// Only apply when the search widen hook has flagged this query.
	$b2_enabled = $query->get( 'conexao_b2_search_enabled' );
	if ( ! $b2_enabled ) {
		return $clauses;
	}

	global $wpdb;

	// Build the B2 WHERE condition: a post is EN-visible in search when:
	//   (language = 'en')  <- real EN content, any type
	// OR
	//   (language = 'pt' AND post_type IN (B2 types))  <- PT fallback only for B2 types
	$b2_types = function_exists( 'conexao_b2_post_types' ) ? conexao_b2_post_types() : array();
	$b2_types_literal = empty( $b2_types ) ? '' : "'" . implode( "','", array_map( 'esc_sql', $b2_types ) ) . "'";

	// LEFT JOIN the language taxonomy to check each post's language.
	$clauses['join'] .= " LEFT JOIN {$wpdb->term_relationships} tr_lang ON ( tr_lang.object_id = {$wpdb->posts}.ID ) ";
	$clauses['join'] .= " LEFT JOIN {$wpdb->term_taxonomy} tt_lang ON ( tt_lang.term_taxonomy_id = tr_lang.term_taxonomy_id AND tt_lang.taxonomy = 'language' ) ";
	$clauses['join'] .= " LEFT JOIN {$wpdb->terms} t_lang ON ( t_lang.term_id = tt_lang.term_id ) ";

	$en_visible_sql = "( t_lang.slug = 'en' )";
	if ( ! empty( $b2_types_literal ) ) {
		$en_visible_sql = "( t_lang.slug = 'en' OR ( t_lang.slug = 'pt' AND {$wpdb->posts}.post_type IN ( {$b2_types_literal} ) ) )";
	}

	// Event status gate: a PT event is only EN-visible if published or no status.
	// The runtime's public gate keeps precedence, and search goes through
	// WP_Query directly (post_type=any is outside the runtime's gate), so the
	// same rule is re-applied here for the PT event branch only.
	if ( in_array( 'event', $b2_types, true ) ) {
		$en_visible_sql .= ' AND NOT ( t_lang.slug = \'pt\' AND ' . $wpdb->posts . '.post_type = \'event\' )';
		$en_visible_sql .= ' OR ( ' . $wpdb->posts . '.post_type = \'event\' AND (';
		$en_visible_sql .= ' ( SELECT meta_value FROM ' . $wpdb->postmeta . ' WHERE meta_key = \'_event_status\' AND post_id = ' . $wpdb->posts . '.ID LIMIT 1 ) IS NULL';
		$en_visible_sql .= ' OR ( SELECT meta_value FROM ' . $wpdb->postmeta . ' WHERE meta_key = \'_event_status\' AND post_id = ' . $wpdb->posts . '.ID LIMIT 1 ) = \'published\'';
		$en_visible_sql .= ' ) )';
	}

	$clauses['where'] .= ' AND ( ' . $en_visible_sql . ' )';

	return $clauses;
}
add_filter( 'posts_clauses', 'conexao_b2_search_query_clauses', 35, 2 );

/**
 * Course Provider shortcode.
 *
 * Renders the Cursos directory: a category filter bar plus a grid of
 * course-provider cards. Each card links to the provider's external website
 * in a new browser tab. Providers are curated records (course_provider CPT) —
 * we intentionally do NOT list individual courses here.
 *
 * Optional attribute: [conexao_course_providers categories="Educação,Formação"]
 *
 * @param array $atts Shortcode attributes.
 * @return string HTML.
 */
