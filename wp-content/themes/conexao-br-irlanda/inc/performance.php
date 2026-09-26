<?php
/**
 * Output trimming and delivery optimisation
 *
 * Emoji/embed removal, REST response trimming, core block-library and
 * gravatar dequeues, hero fetchpriority and content image dimension/lazy
 * handling. Deliberately contains no cache-key logic.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function conexao_disable_emoji() {
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );
	remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
	remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
	remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
}
add_action( 'init', 'conexao_disable_emoji' );

/**
 * Remove the global "wp-embed" script (saves ~2KB on every page).
 * The site does not use oEmbed embeds in the theme templates.
 */
function conexao_disable_embeds() {
	wp_deregister_script( 'wp-embed' );
}
add_action( 'wp_footer', 'conexao_disable_embeds' );

/**
 * Add fetchpriority="high" to the hero image (LCP element) on the front page.
 * WordPress 6.3+ supports fetchpriority natively; this is a safe fallback.
 */
function conexao_hero_fetchpriority( $html, $post_id ) {
	if ( is_front_page() && has_post_thumbnail( $post_id ) ) {
		$html = preg_replace( '/<img /', '<img fetchpriority="high" ', $html, 1 );
	}
	return $html;
}
add_filter( 'post_thumbnail_html', 'conexao_hero_fetchpriority', 10, 2 );

/**
 * Add explicit width/height to images that lack them (CLS prevention).
 * WordPress already adds width/height for registered sizes; this catches
 * any images output without dimensions.
 */
function conexao_add_image_dimensions( $html ) {
	if ( ! $html || is_admin() ) {
		return $html;
	}

	// Only process <img> tags without width/height attributes.
	if ( preg_match( '/<img(?![^>]*\bwidth=)[^>]*>/i', $html, $matches ) ) {
		$img = $matches[0];
		if ( preg_match( '/src="([^"]+)"/', $img, $src_match ) ) {
			$src = $src_match[1];
			$size = @getimagesize( $src );
			if ( $size ) {
				$html = str_replace( $img, preg_replace( '/\/?>/', ' width="' . $size[0] . '" height="' . $size[1] . '" />', $img, 1 ), $html );
			}
		}
	}

	return $html;
}
add_filter( 'the_content', 'conexao_add_image_dimensions', 20 );

/**
 * Add loading="lazy" to content images that don't have it.
 * The hero image is handled separately (eager + fetchpriority).
 */
function conexao_lazy_content_images( $content ) {
	if ( is_admin() || is_feed() ) {
		return $content;
	}

	// Only add loading="lazy" to images that don't already have it.
	$content = preg_replace(
		'/<img(?![^>]*\bloading=)[^>]*>/i',
		'<img loading="lazy"$0',
		$content
	);

	return $content;
}
add_filter( 'the_content', 'conexao_lazy_content_images', 20 );

/**
 * Homepage query cache.
 *
 * The homepage runs 8 WP_Query calls. Cache the results in transients for
 * 5 minutes to avoid re-running expensive meta queries on every page load.
 * The cache is invalidated whenever any of the relevant CPTs are saved.
 */
function conexao_rest_api_optimize() {
	// Remove the global REST API link from the head.
	remove_action( 'wp_head', 'rest_output_link_wp_head' );
	remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
	remove_action( 'wp_head', 'wp_oembed_add_host_js' );
}
add_action( 'init', 'conexao_rest_api_optimize' );

/**
 * Remove the global "wp-block-library" CSS only when the current page does not
 * render Gutenberg blocks or the plugin's block-based shortcode pages.
 *
 * The theme templates (homepage, CPT archives, CPT singles, search, 404) are
 * fully custom and do not need block CSS — keeping it dequeued there saves
 * ~90KB on the most important pages. However, the "conexao-content" plugin
 * creates static pages (eventos, cursos, contato, blog) whose body
 * uses Gutenberg block markup (headings, lists, paragraphs, shortcodes). On
 * those pages we selectively restore the block-library CSS so the migrated
 * content keeps its intended styling. We do NOT blanket-restore wc-blocks-style
 * (WooCommerce is not used).
 */
function conexao_dequeue_block_library() {
	if ( is_admin() ) {
		return;
	}

	$needs_blocks = false;

	// A queried post whose content uses Gutenberg blocks.
	if ( is_singular() ) {
		$post = get_queried_object();
		if ( $post && ! empty( $post->post_content ) ) {
			$needs_blocks = has_blocks( $post->post_content );
		}
	}

	// The plugin's shortcodes render block-styled card grids.
	if ( ! $needs_blocks && is_singular() ) {
		$post = get_queried_object();
		if ( $post && ( has_shortcode( $post->post_content, 'conexao_grid' ) || has_shortcode( $post->post_content, 'conexao_blog_categories' ) || has_shortcode( $post->post_content, 'conexao_course_providers' ) ) ) {
			$needs_blocks = true;
		}
	}

	// Front page may host block content in the future; keep it lightweight now.
	if ( ! $needs_blocks ) {
		wp_dequeue_style( 'wp-block-library' );
		wp_dequeue_style( 'wp-block-library-theme' );
		wp_dequeue_style( 'wc-blocks-style' );
	}
}
/**
 * Dequeue the "Gravatar Enhanced" pattern stylesheets on the frontend.
 *
 * PageSpeed audit evidence: gravatar-enhanced-patterns-shared,
 * -edit and -view load as render-blocking CSS on every public page,
 * including the homepage — yet the theme and all custom templates
 * contain no Gravatar pattern blocks (comments use core get_avatar(),
 * which needs no stylesheet). The "-edit" sheet is editor-only markup
 * leaking into the public head.
 *
 * They are only kept when the queried singular content actually embeds
 * a Gravatar block, so a future block-based page keeps working.
 */
function conexao_dequeue_gravatar_patterns() {
	if ( is_admin() ) {
		return;
	}

	$content = '';
	if ( is_singular() ) {
		$post = get_queried_object();
		if ( $post && ! empty( $post->post_content ) ) {
			$content = $post->post_content;
		}
	}

	if ( false === strpos( $content, 'gravatar' ) ) {
		wp_dequeue_style( 'gravatar-enhanced-patterns-shared' );
		wp_dequeue_style( 'gravatar-enhanced-patterns-edit' );
		wp_dequeue_style( 'gravatar-enhanced-patterns-view' );
	}
}
add_action( 'wp_enqueue_scripts', 'conexao_dequeue_gravatar_patterns', 100 );

