<?php
/**
 * Course providers shortcode
 *
 * The [conexao_course_providers] shortcode that renders the course
 * provider directory cards for the Cursos page.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function conexao_course_providers_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'categories' => '',
		),
		$atts,
		'conexao_course_providers'
	);

	// Provider categories. Prefer the Admin UX config when available, otherwise
	// fall back to this theme-local list so the directory always works.
	if ( class_exists( 'Conexao_Admin_Ux_Config' ) ) {
		$categories = Conexao_Admin_Ux_Config::provider_categories();
	} else {
		$categories = array(
			'Educação',
			'Formação Profissional',
			'Cursos Online',
			'Negócios',
			'Diretórios de Cursos',
		);
	}
	$current    = isset( $_GET['categoria'] ) ? sanitize_text_field( wp_unslash( $_GET['categoria'] ) ) : '';

	$args = array(
		'post_type'           => 'course_provider',
		'post_status'         => 'publish',
		'posts_per_page'      => -1,
		'no_found_rows'       => true,
		'update_post_meta_cache' => true,
		'update_post_term_cache' => false,
	);

	// Filter by provider status meta (published) to match the admin status model.
	$args['meta_query'] = array(
		array(
			'key'     => '_provider_status',
			'value'   => 'published',
			'compare' => '=',
		),
	);

	// Category filter via ?categoria=<slug> (slugified category label).
	if ( $current ) {
		$args['meta_query'][] = array(
			'key'   => '_provider_category',
			'value' => $current,
		);
	}

	// Optional comma-separated categories restriction from the shortcode.
	$allowed = array();
	if ( ! empty( $atts['categories'] ) ) {
		$allowed = array_map( 'trim', explode( ',', $atts['categories'] ) );
	}

	// Order by the display order meta, then title.
	$args['meta_key'] = '_provider_order';
	$args['orderby']  = 'meta_value_num title';
	$args['order']    = 'ASC';

	$query = new WP_Query( $args );

	if ( ! $query->have_posts() ) {
		return '';
	}

	// Build the category filter bar (Todos + provider categories).
	$filter = '<div class="events-filter-bar providers-filter-bar">';
	$filter .= '<span class="events-filter-label">' . esc_html__( 'Categorias', 'conexao-br-irlanda' ) . '</span>';
	$filter .= '<a class="events-filter-link' . ( $current ? '' : ' is-active' ) . '" href="' . esc_url( get_permalink() ) . '">' . esc_html__( 'Todos', 'conexao-br-irlanda' ) . '</a>';
	foreach ( $categories as $category ) {
		if ( $allowed && ! in_array( $category, $allowed, true ) ) {
			continue;
		}
		$slug = sanitize_title( $category );
		$url  = add_query_arg( 'categoria', $slug, get_permalink() );
		$filter .= '<a class="events-filter-link' . ( $current === $slug ? ' is-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $category ) . '</a>';
	}
	$filter .= '</div>';

	$html  = '<div class="provider-directory">';
	$html .= $filter;
	$html .= '<div class="provider-grid">';

	while ( $query->have_posts() ) {
		$query->the_post();
		ob_start();
		get_template_part( 'template-parts/provider', 'card' );
		$html .= ob_get_clean();
	}

	wp_reset_postdata();

	$html .= '</div></div>';
	return $html;
}
add_shortcode( 'conexao_course_providers', 'conexao_course_providers_shortcode' );


/**
 * Custom image sizes
 */
