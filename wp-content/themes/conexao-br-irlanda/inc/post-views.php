<?php
/**
 * Conexão BR Irlanda - Post view counting ("Mais Lidos" data source)
 *
 * Records one view per front-end content page load into the `_conexao_view_count`
 * post meta. The homepage "Mais Lidos" section (conexao_popular_posts()) ranks
 * content by this meta:
 *
 *   post ID → _conexao_view_count (meta) → ORDER BY meta_value_num DESC
 *
 * Design constraints (see docs/themes/conexao-br-irlanda.md):
 *
 *  - Server-side only. No AJAX beacon, no third-party script, no extra
 *    homepage query. The single cost is one indexed postmeta UPDATE per
 *    content page view.
 *  - One increment per page load. Guarded by is_main_query() (only the
 *    template's main query counts, never secondary WP_Query lookups) plus a
 *    per-request static flag so multiple hooks firing for the same request
 *    can never double-count.
 *  - Intended content scope only (matches conexao_popular_posts()):
 *    post (Blog) and guide (Guias). Other content types are never counted.
 *  - Inflation guards: admin/AJAX/CLI/cron/REST requests, previews, feeds,
 *    logged-in users and the most common bots/crawlers are not counted.
 *    Fetching a page again is a genuine view and is counted (standard
 *    behavior for server-side counters) — no aggressive bot infrastructure.
 *  - Freshness: the ranking is transient-cached under `conexao_home_popular`
 *    for 5 minutes (see conexao_popular_posts()), so the homepage reflects
 *    new views at most 5 minutes late while keeping cache hits for every
 *    load in between. The transient is also invalidated on post save/delete
 *    (see conexao_homepage_cache_invalidate()).
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post types whose single views are counted for "Mais Lidos".
 *
 * "Mais Lidos" is an informational-content ranking: it must contain ONLY
 * Blog (`post`) and Guias (`guide`). Events, jobs, sponsors, courses and
 * leisure are never counted or ranked, even if they accumulate more views.
 *
 * Single source of truth — must stay in sync with conexao_popular_posts()
 * in functions.php, which reads this function for both its queries.
 *
 * @return string[] Post type names.
 */
function conexao_view_count_post_types() {
	return array( 'post', 'guide' );
}

/**
 * Whether the current request is a real, countable front-end content view.
 *
 * @return bool True when a view should be recorded for this request.
 */
function conexao_is_countable_view() {
	// Never count admin, AJAX, cron or REST (preflight/API) requests.
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || wp_is_json_request() ) {
		return false;
	}

	// Only front-end singular views of the intended content types.
	if ( ! is_singular( conexao_view_count_post_types() ) ) {
		return false;
	}

	// Only the template's main query — never secondary/inline WP_Query calls.
	if ( ! is_main_query() ) {
		return false;
	}

	// Draft/preview, feeds and embeds are not real reader views.
	if ( is_preview() || is_feed() || is_embed() ) {
		return false;
	}

	// Logged-in users (editors reviewing content, subscribers) are not counted.
	if ( is_user_logged_in() ) {
		return false;
	}

	// Lightweight bot filter: missing or recognisable crawler user agents.
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	if ( '' === $ua ) {
		return false;
	}
	$bot_hints = array(
		'bot', 'crawl', 'spider', 'slurp', 'curl', 'wget', 'python-requests',
		'python-urllib', 'libwww', 'httpclient', 'java/', 'go-http-client',
		'facebookexternalhit', 'linkedinbot', 'twitterbot', 'whatsapp',
		'telegrambot', 'headlesschrome', 'phantomjs', 'monitor', 'uptime',
		'lighthouse', 'pagespeed', 'pingdom', 'gtmetrix', 'semrush', 'ahrefs',
		'majestic', 'mj12bot', 'dotbot', 'petalbot', 'yandex', 'bingpreview',
	);
	foreach ( $bot_hints as $hint ) {
		if ( false !== strpos( $ua, $hint ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Record a view for a post (atomic increment).
 *
 * Uses a single indexed UPDATE (meta_value = meta_value + 1) so concurrent
 * requests can never lose an increment; the meta row is only inserted when
 * it does not exist yet.
 *
 * @param int $post_id Post ID.
 * @return bool True when the count was recorded.
 */
function conexao_record_view( $post_id ) {
	$post_id = absint( $post_id );
	if ( ! $post_id ) {
		return false;
	}

	global $wpdb;

	$updated = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = '_conexao_view_count'",
			$post_id
		)
	);

	if ( $updated ) {
		return true;
	}

	// No row yet (first view, or meta deleted) — create it atomically.
	// The unique flag prevents duplicates if two first-views race.
	return false !== add_post_meta( $post_id, '_conexao_view_count', 1, true );
}

/**
 * Count the view on `wp`, once the main query is known and the template
 * context is resolved, but before any output is sent.
 *
 * @return void
 */
function conexao_maybe_count_view() {
	static $counted = false;

	// A single request can only ever record one view.
	if ( $counted ) {
		return;
	}

	if ( ! conexao_is_countable_view() ) {
		return;
	}

	$counted = true;
	conexao_record_view( get_queried_object_id() );
}
add_action( 'wp', 'conexao_maybe_count_view', 20 );
