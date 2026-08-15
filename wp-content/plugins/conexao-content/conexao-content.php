<?php
/**
 * Plugin Name: Conexão BR Irlanda - Content
 * Description: Secao de conteudo, shortcodes, e helpers para o portal Conexão BR Irlanda
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Conexão BR Irlanda
 * Text Domain: conexao-content
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CONEXAO_CONTENT_VERSION', '1.0.0' );
define( 'CONEXAO_CONTENT_DIR', plugin_dir_path( __FILE__ ) );
define( 'CONEXAO_CONTENT_URI', plugin_dir_url( __FILE__ ) );

/**
 * Enqueue styles for plugin shortcodes and widgets
 */
function conexao_content_enqueue_styles() {
    wp_enqueue_style(
        'conexao-content',
        CONEXAO_CONTENT_URI . 'assets.css',
        array(),
        CONEXAO_CONTENT_VERSION
    );
}
add_action( 'wp_enqueue_scripts', 'conexao_content_enqueue_styles' );

/**
 * Include page creation logic.
 *
 * The create-pages.php script is a standalone WP-CLI utility that runs
 * immediately when included. It must NOT run on every page load — it calls
 * wp_get_nav_menu_items() which requires fully initialized rewrite rules.
 * Only include it when explicitly invoked via WP-CLI (wp eval-file).
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once CONEXAO_CONTENT_DIR . 'create-pages.php';
}

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
        } elseif ( 'course' === $post_type ) {
            $external_url = get_post_meta( $post_id, '_course_url', true );
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