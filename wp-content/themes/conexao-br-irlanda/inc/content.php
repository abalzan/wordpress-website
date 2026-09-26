<?php
/**
 * Presentation helpers: excerpts, body classes, reading time, sharing
 *
 * Excerpt length/more, body_class additions, reading time, the post meta
 * line, the share buttons and the Jetpack sharing suppression. Presentation
 * only - no queries and no caching.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Custom excerpt
 */
function conexao_excerpt_length( $length ) {
	return is_admin() ? $length : 30;
}
add_filter( 'excerpt_length', 'conexao_excerpt_length' );

function conexao_excerpt_more( $more ) {
	return '&hellip;';
}
add_filter( 'excerpt_more', 'conexao_excerpt_more' );

/**
 * Body classes
 */
function conexao_body_classes( $classes ) {
	if ( is_singular() ) $classes[] = 'singular';
	if ( is_home() || is_archive() || is_search() ) $classes[] = 'blog-page';
	if ( is_front_page() ) $classes[] = 'front-page';
	return $classes;
}
add_filter( 'body_class', 'conexao_body_classes' );

/**
 * Reading time
 * Cached per-post to avoid repeated get_post_field() DB calls on card grids.
 */
function conexao_reading_time() {
	$post_id = get_the_ID();
	$cached  = wp_cache_get( 'conexao_reading_time_' . $post_id, 'conexao' );
	if ( false !== $cached ) {
		return $cached;
	}

	$content = get_post_field( 'post_content', $post_id );
	$words   = str_word_count( strip_tags( $content ) );
	$minutes = max( 1, ceil( $words / 200 ) );

	wp_cache_set( 'conexao_reading_time_' . $post_id, $minutes, 'conexao', 300 );
	return $minutes;
}

function conexao_reading_time_text() {
	$minutes = conexao_reading_time();
	return sprintf( _n( '%d min de leitura', '%d min de leitura', $minutes, 'conexao-br-irlanda' ), $minutes );
}

/**
 * Post meta
 */
function conexao_post_meta() {
	$time_string = sprintf(
		'<time class="entry-date published updated" datetime="%1$s">%2$s</time>',
		esc_attr( get_the_date( DATE_W3C ) ),
		esc_html( get_the_date() )
	);
	printf( '<span class="posted-on">%1$s</span>', $time_string );
	if ( ! is_singular() ) {
		printf( '<span class="reading-time">%1$s</span>', esc_html( conexao_reading_time_text() ) );
	}
}

/**
 * Share buttons (canonical component, shared by Blog posts and Guias).
 *
 * The markup lives in template-parts/share-buttons.php so Blog and Guias
 * always render the exact same component. Call this function (or
 * get_template_part( 'template-parts/share-buttons' )) from within The Loop.
 */
function conexao_share_buttons() {
	if ( ! is_singular( array( 'post', 'guide' ) ) ) {
		return;
	}
	get_template_part( 'template-parts/share-buttons' );
}

/**
 * Disable the Jetpack/WordPress.com sharing module output.
 *
 * On WordPress.com the Jetpack Share module injects a duplicate sharing UI
 * (div.sd-sharing via sharing_display) into post content, which duplicates
 * the theme's canonical .share-buttons component. Remove only that output —
 * no other Jetpack functionality is touched.
 */
function conexao_disable_jetpack_sharing() {
	if ( function_exists( 'sharing_display' ) ) {
		remove_filter( 'the_content', 'sharing_display', 19 );
		remove_filter( 'the_excerpt', 'sharing_display', 19 );
	}
	if ( function_exists( 'sharing_add_header' ) ) {
		// Jetpack's Share module CSS/JS header block (only rendered when
		// sharing buttons are displayed — safe to drop alongside the output).
		remove_action( 'wp_head', 'sharing_add_header', 1 );
	}
}
add_action( 'init', 'conexao_disable_jetpack_sharing', 20 );

/**
 * Related posts
 */
