<?php
/**
 * Homepage transients and their invalidation
 *
 * Homepage transients: conexao_homepage_query() and conexao_homepage_cache_invalidate() cover the
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
 * Clear every event-filter term cache (both layers, every language).
 *
 * Covers the term-list keys (county/town/category for events, category for
 * guides) plus every per-county town variant. Town variants are enumerated
 * from the live county taxonomy so no unrelated cache is flushed and no
 * second lifecycle is introduced: this is the same
 * conexao_homepage_cache_invalidate() entry point, extended.
 *
 * @return void
 */
function conexao_clear_event_filter_caches() {
	$term_keys = array(
		'conexao_terms_conexao_county_event',
		'conexao_terms_conexao_town_event',
		'conexao_terms_conexao_category_event',
		'conexao_terms_conexao_category_guide',
	);

	foreach ( $term_keys as $base ) {
		conexao_flush_language_cache( $base );
		conexao_flush_language_object_cache( $base, 'conexao_filters' );
	}

	conexao_flush_language_cache( 'conexao_event_towns' );
	conexao_flush_language_object_cache( 'conexao_event_towns', 'conexao_filters' );

	$counties = get_terms(
		array(
			'taxonomy'   => 'conexao_county',
			'hide_empty' => false,
			'fields'     => 'slugs',
		)
	);
	if ( is_wp_error( $counties ) || ! is_array( $counties ) ) {
		return;
	}

	foreach ( $counties as $slug ) {
		$slug = sanitize_title( (string) $slug );
		if ( '' === $slug ) {
			continue;
		}
		$town_key = 'conexao_event_towns_' . $slug;
		conexao_flush_language_cache( $town_key );
		conexao_flush_language_object_cache( $town_key, 'conexao_filters' );
	}
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
		// Both layers (object cache + persistent transient) and every
		// per-county town variant go through the shared clearer.
		conexao_clear_event_filter_caches();

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
 * Clear filter term caches when a filter taxonomy itself changes.
 *
 * Post save/delete hooks above miss taxonomy-only edits (rename a county,
 * delete a town, add a category): no post is saved, so the term lists would
 * stay stale until the 5-minute TTL. These hooks close that gap through the
 * same shared clearer — no second lifecycle.
 *
 * @param int $term_id Term ID (unused; the whole list is rebuilt lazily).
 * @return void
 */
function conexao_filter_term_cache_invalidate( $term_id = 0 ) {
	conexao_clear_event_filter_caches();
}
add_action( 'created_conexao_county', 'conexao_filter_term_cache_invalidate' );
add_action( 'edited_conexao_county', 'conexao_filter_term_cache_invalidate' );
add_action( 'delete_conexao_county', 'conexao_filter_term_cache_invalidate' );
add_action( 'created_conexao_town', 'conexao_filter_term_cache_invalidate' );
add_action( 'edited_conexao_town', 'conexao_filter_term_cache_invalidate' );
add_action( 'delete_conexao_town', 'conexao_filter_term_cache_invalidate' );
add_action( 'created_conexao_category', 'conexao_filter_term_cache_invalidate' );
add_action( 'edited_conexao_category', 'conexao_filter_term_cache_invalidate' );
add_action( 'delete_conexao_category', 'conexao_filter_term_cache_invalidate' );

/**
 * Limit REST API exposure: only expose the endpoints the theme actually uses.
 * The REST API is still fully functional for admin/editor use.
 */
