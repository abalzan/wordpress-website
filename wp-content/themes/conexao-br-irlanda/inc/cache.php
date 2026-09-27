<?php
/**
 * Homepage transients and their invalidation
 *
 * conexao_homepage_query() and conexao_homepage_cache_invalidate(): the
 * conexao_home_* / conexao_404_* transient layer and the save/delete/
 * insert hooks that invalidate it. Cache keys stay language-scoped through
 * conexao_lang_cache_key() (owned by inc/i18n/).
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function conexao_homepage_query( $args, $cache_key, $expiration = 300 ) {
	// Stage 2: homepage section caches are per-language (PT cache ≠ EN cache).
	$cache_key = conexao_lang_cache_key( $cache_key );
	$cached    = get_transient( $cache_key );
	if ( false !== $cached ) {
		return $cached;
	}

	$query = new WP_Query( $args );
	$posts = $query->posts;

	// Store minimal post data (ID, title, permalink, date, excerpt, thumbnail).
	$data = array();
	foreach ( $posts as $post ) {
		$data[] = array(
			'ID'         => $post->ID,
			'post_title' => $post->post_title,
			'post_type'  => $post->post_type,
			'post_date'  => $post->post_date,
			'post_excerpt' => $post->post_excerpt,
			'permalink'  => get_permalink( $post->ID ),
			'thumbnail'  => get_the_post_thumbnail_url( $post->ID, 'conexao-card' ),
			'thumbnail_hero' => get_the_post_thumbnail_url( $post->ID, 'conexao-hero' ),
		);
	}

	set_transient( $cache_key, $data, $expiration );
	return $data;
}

/**
 * Invalidate all transient caches that depend on portal content.
 *
 * Runs whenever a Guide, Event, Job, Apoiador or standard post is
 * published, updated, or deleted. This keeps:
 *  - the homepage card/featured/popular transients fresh,
 *  - the 404 page's guides/events transients fresh,
 *  - the per-post reading-time object-cache entry fresh.
 *
 * Transients store only public, non-user-specific data (ID, title, permalink,
 * date, excerpt, thumbnail URL), so no logged-in/admin data can leak. Keys are
 * predictable and unique per concern. Expiration is 5 minutes as a safety net
 * even if a save hook is missed.
 */
function conexao_homepage_cache_invalidate( $post_id ) {
	$post_type = get_post_type( $post_id );
	$cpt_types = array( 'guide', 'event', 'job', 'sponsor', 'course_provider', 'leisure', 'post' );
	if ( in_array( $post_type, $cpt_types, true ) ) {
		/*
		 * Stage 2: every language variant is flushed. Saving a PT record must
		 * also invalidate the EN caches (and vice versa) — see
		 * conexao_flush_language_cache() in inc/polylang.php.
		 */

		// Homepage sections.
		conexao_flush_language_cache( 'conexao_home_news' );
		conexao_flush_language_cache( 'conexao_home_events' );
		conexao_flush_language_cache( 'conexao_home_sponsors' );
		conexao_flush_language_cache( 'conexao_home_jobs' );
		conexao_flush_language_cache( 'conexao_home_featured' );
		conexao_flush_language_cache( 'conexao_home_popular' );
		conexao_flush_language_cache( 'conexao_home_latest' );

		// 404 page sections.
		conexao_flush_language_cache( 'conexao_404_guides' );
		conexao_flush_language_cache( 'conexao_404_events' );

		// Recurring-events ordered ID list (date- and language-keyed
		// transient owned by the event runtime's Conexao_Event_Query
		// helper; only events can change the set). The helper flushes
		// every language variant.
		if ( 'event' === $post_type && class_exists( 'Conexao_Event_Query' ) ) {
			Conexao_Event_Query::flush_cache();
		}

		// Reading-time object-cache entry for this post (keyed by post ID:
		// a translation is its own record, so it can never collide).
		wp_cache_delete( 'conexao_reading_time_' . $post_id, 'conexao' );

		// Filter bar caches (per-content-type term/category lists) — per
		// language, because they carry term names and language-scoped posts.
		conexao_flush_language_object_cache( 'conexao_terms_conexao_category_event', 'conexao_filters' );
		conexao_flush_language_object_cache( 'conexao_terms_conexao_town_event', 'conexao_filters' );
		conexao_flush_language_object_cache( 'conexao_terms_conexao_category_guide', 'conexao_filters' );
		conexao_flush_language_object_cache( 'conexao_provider_categories', 'conexao_filters' );

		// Stage 3.2 — B2 replacement sets (PT masters hidden behind their EN
		// translation). Language-independent data, one key per B2 type subset;
		// the archive hook uses the queried type, search uses the full B2 set.
		$b2_sets = array( conexao_b2_post_types() );
		foreach ( conexao_b2_post_types() as $b2_single ) {
			$b2_sets[] = array( $b2_single );
		}
		foreach ( $b2_sets as $b2_set ) {
			wp_cache_delete( 'conexao_b2_replaced_' . md5( implode( ',', $b2_set ) ), 'conexao_filters' );
		}
	}
}
add_action( 'save_post', 'conexao_homepage_cache_invalidate' );
add_action( 'delete_post', 'conexao_homepage_cache_invalidate' );
add_action( 'wp_insert_post', 'conexao_homepage_cache_invalidate' );

/**
 * Limit REST API exposure: only expose the endpoints the theme actually uses.
 * The REST API is still fully functional for admin/editor use.
 */
