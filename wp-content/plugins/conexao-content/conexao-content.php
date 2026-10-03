<?php
/**
 * Plugin Name: Conexão BR Irlanda - Content
 * Description: Secao de conteudo, shortcodes, e helpers para o portal Conexão BR Irlanda
 * Version: 1.0.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Conexão BR Irlanda
 * Text Domain: conexao-content
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CONEXAO_CONTENT_VERSION', '1.0.1' );
define( 'CONEXAO_CONTENT_DIR', plugin_dir_path( __FILE__ ) );
define( 'CONEXAO_CONTENT_URI', plugin_dir_url( __FILE__ ) );

/**
 * Enqueue styles for plugin shortcodes and widgets
 *
 * The stylesheet is tiny (~0.9 KB) but it was previously a separate
 * render-blocking <link> on EVERY page, including the homepage, where
 * its classes are mostly unused. PageSpeed flags every blocking request,
 * and each one costs a full HTTP round-trip before first paint. It is
 * now inlined (well under the "very small critical CSS" threshold), which:
 *   - removes one render-blocking request from every page;
 *   - removes the stale-cache risk of versioning by a constant instead
 *     of filemtime (WordPress.com edge could serve the old file forever);
 *   - keeps assets/css as the single source of truth (read at runtime,
 *     never duplicated in PHP).
 * Cascade position is unchanged: the inline block prints exactly where
 * the <link> used to print, before the theme's dark-mode overrides.
 */
function conexao_content_enqueue_styles() {
	$css_file = CONEXAO_CONTENT_DIR . 'assets.css';

	if ( ! file_exists( $css_file ) ) {
		return;
	}

	$css = file_get_contents( $css_file );

	if ( false === $css || '' === trim( $css ) ) {
		return;
	}

	wp_register_style( 'conexao-content', false, array(), CONEXAO_CONTENT_VERSION );
	wp_enqueue_style( 'conexao-content' );
	wp_add_inline_style( 'conexao-content', $css );
}
add_action( 'wp_enqueue_scripts', 'conexao_content_enqueue_styles' );

/**
 * Plugin activation hook.
 *
 * When the plugin is activated, create all required pages and navigation menus.
 * This ensures the site works correctly after a fresh install or rebuild.
 *
 * create-pages.php is a standalone CLI script that prints progress while it
 * runs, and WordPress treats ANY output from an activation hook as a failure
 * ("The plugin generated unexpected output."). WP-CLI then refuses the
 * activation outright, so a fresh install could not activate this plugin at
 * all. The script's progress output is therefore buffered and discarded: the
 * pages and menus are still created, and activation stays silent.
 */
function conexao_content_activate() {
	ob_start();
	require_once CONEXAO_CONTENT_DIR . 'create-pages.php';
	ob_end_clean();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'conexao_content_activate' );

// NOTE: create-pages.php is a standalone WP-CLI utility that runs immediately
// when included. It must NOT be auto-included on every WP-CLI command or page
// load — it executes at plugin-include time (before pluggable.php is loaded),
// which fatals under `wp eval`/`wp post list`, and it calls
// wp_get_nav_menu_items() which requires fully initialized rewrite rules.
// Run it explicitly when needed:
//   wp eval-file wp-content/plugins/conexao-content/create-pages.php --allow-root
// It also runs automatically via the activation hook above on fresh installs.

/**
 * The shortcode [conexao_grid] renders an SEO-friendly grid of links
 * associated with a set of related categories (or all categories).
 *
 * This is a legacy shortcode used on the migrated pages (e.g., /irlanda/,
 * /moradia/, /saude/) created by the create-pages.php script.
 *
 * @param array $atts Shortcode attributes.
 * @return string HTML.
 */
function conexao_grid_shortcode( $atts ) {
    $atts = shortcode_atts( array( 'categories' => '', 'title' => '', 'count' => 10 ), $atts );

    $category_slugs = ! empty( $atts['categories'] ) ? array_map( 'trim', explode( ',', $atts['categories'] ) ) : array();

    $args = array(
        'post_type'      => 'any',
        'posts_per_page' => intval( $atts['count'] ),
        'no_found_rows'  => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    );

    // Event visibility guard: post_type=any can match the `event` type, but the
    // Event Runtime's pre_get_posts gate only constrains dedicated event queries
    // (event archives/tax archives) — not this generic grid query. Mirror the
    // runtime rule inline (status `published` OR no status = legacy manual
    // events) so hidden events (`draft`, `expired`, `source_not_found`,
    // `rejected`) never appear in the grid. Non-event posts never carry
    // `_event_status`, so they pass the NOT EXISTS branch and are unaffected.
    if ( post_type_exists( 'event' ) ) {
        $args['meta_query'] = array(
            'relation' => 'OR',
            array(
                'key'   => '_event_status',
                'value' => 'published',
            ),
            array(
                'key'     => '_event_status',
                'compare' => 'NOT EXISTS',
            ),
        );
    }

    if ( ! empty( $category_slugs ) ) {
        $args['tax_query'] = array(
            array(
                'taxonomy' => 'conexao_category',
                'field'    => 'slug',
                'terms'    => $category_slugs,
            ),
        );
    }

    $query = new WP_Query( $args );

    if ( ! $query->have_posts() ) {
        return '';
    }

    $html = '';

    if ( ! empty( $atts['title'] ) ) {
        $html .= '<h2 class="landing-section-title" id="section-' . sanitize_title( $atts['title'] ) . '">' . esc_html( $atts['title'] ) . '</h2>';
    }

    $html .= '<div class="conexao-card-grid">';

    while ( $query->have_posts() ) {
        $query->the_post();

        $post_id      = get_the_ID();
        $post_type    = get_post_type();
        $external_url = '';

        if ( 'event' === $post_type ) {
            $external_url = get_post_meta( $post_id, '_event_url', true );
        } elseif ( 'sponsor' === $post_type ) {
            $external_url = get_post_meta( $post_id, '_sponsor_link', true );
        } elseif ( 'job' === $post_type ) {
            $external_url = get_post_meta( $post_id, '_job_url', true );
        } elseif ( 'course_provider' === $post_type ) {
            $external_url = get_post_meta( $post_id, '_provider_url', true );
        }

        $link_url  = $external_url ? $external_url : get_permalink();
        $thumbnail = get_the_post_thumbnail_url( $post_id, 'conexao-card' );
        $target    = $external_url ? ' target="_blank" rel="noopener noreferrer"' : '';

        $html .= '<div class="conexao-card">';
        $html .= '<a href="' . esc_url( $link_url ) . '"' . $target . '>';

        if ( $thumbnail ) {
            $html .= '<img src="' . esc_url( $thumbnail ) . '" alt="' . esc_attr( get_the_title() ) . '" loading="lazy">';
        }

        $html .= '<h3>' . esc_html( get_the_title() ) . '</h3>';
        $html .= '</a>';
        $html .= '</div>';
    }

    wp_reset_postdata();

    $html .= '</div>';

    return $html;
}
add_shortcode( 'conexao_grid', 'conexao_grid_shortcode' );

/**
 * Shortcode [conexao_blog_categories] renders category links as styled pills,
 * linking to the blog page filtered by each category.
 *
 * @return string HTML.
 */
function conexao_blog_categories_shortcode() {
    $categories = get_categories( array(
        'orderby'    => 'name',
        'order'      => 'ASC',
        'hide_empty' => true,
    ) );

    if ( empty( $categories ) ) {
        return '<p>' . esc_html__( 'Nenhuma categoria encontrada.', 'conexao-content' ) . '</p>';
    }

    // Base blog URL (the native posts archive).
    $blog_url = home_url( '/blog/' );

    $html  = '<div class="landing-tags">';
    $html .= '<a href="' . esc_url( $blog_url ) . '" class="landing-tag">' . esc_html__( 'Todos', 'conexao-content' ) . '</a>';

    foreach ( $categories as $cat ) {
        $html .= '<a href="' . esc_url( get_category_link( $cat->term_id ) ) . '" class="landing-tag">' . esc_html( $cat->name ) . '</a>';
    }

    $html .= '</div>';

    return $html;
}
add_shortcode( 'conexao_blog_categories', 'conexao_blog_categories_shortcode' );
/**
 * Load the plugin textdomain (Stage 1 i18n foundation).
 *
 * Translation files live in this plugin's languages/ directory. Admin-only
 * strings may be catalogued here even though English admin support is
 * deferred to a later stage. No functionality changes.
 */
function conexao_content_load_textdomain() {
	load_plugin_textdomain(
		'conexao-content',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}
add_action( 'init', 'conexao_content_load_textdomain' );
