<?php
/**
 * SEO related-content queries
 *
 * The related Guides / Events / Apoiadores queries used by the SEO and
 * single-page related sections. Data queries only - no head output.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * ---------------------------------------------------------------------------
 * 12. INTERNAL LINKING HELPERS
 * ---------------------------------------------------------------------------
 * Provide reusable helpers for templates to link related content naturally.
 */

/**
 * Get related guides for a given post (by shared category).
 */
function conexao_seo_related_guides( $post_id = 0, $limit = 3 ) {
	$post_id = $post_id ? $post_id : get_the_ID();
	$terms   = get_the_terms( $post_id, 'conexao_category' );
	if ( empty( $terms ) || is_wp_error( $terms ) ) {
		return array();
	}

	$query = new WP_Query( array(
		'post_type'      => 'guide',
		'posts_per_page' => $limit,
		'post__not_in'   => array( $post_id ),
		'tax_query'      => array(
			array(
				'taxonomy' => 'conexao_category',
				'field'    => 'term_id',
				'terms'    => wp_list_pluck( $terms, 'term_id' ),
			),
		),
		'no_found_rows'  => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	) );

	$guides = array();
	if ( $query->have_posts() ) {
		while ( $query->have_posts() ) {
			$query->the_post();
			$guides[] = array(
				'title' => get_the_title(),
				'url'   => get_permalink(),
			);
		}
		wp_reset_postdata();
	}

	return $guides;
}

/**
 * Get related events for a given post (by shared county).
 */
function conexao_seo_related_events( $post_id = 0, $limit = 3 ) {
	$post_id = $post_id ? $post_id : get_the_ID();
	$counties = get_the_terms( $post_id, 'conexao_county' );
	if ( empty( $counties ) || is_wp_error( $counties ) ) {
		return array();
	}

	$query = new WP_Query( array(
		'post_type'      => 'event',
		'posts_per_page' => $limit,
		'post__not_in'   => array( $post_id ),
		'tax_query'      => array(
			array(
				'taxonomy' => 'conexao_county',
				'field'    => 'term_id',
				'terms'    => wp_list_pluck( $counties, 'term_id' ),
			),
		),
		'no_found_rows'  => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	) );

	$events = array();
	if ( $query->have_posts() ) {
		while ( $query->have_posts() ) {
			$query->the_post();
			$events[] = array(
				'title' => get_the_title(),
				'url'   => get_permalink(),
			);
		}
		wp_reset_postdata();
	}

	return $events;
}

/**
 * Get related apoiaiadores for a given post (by shared category or county).
 */
function conexao_seo_related_sponsors( $post_id = 0, $limit = 3 ) {
	$post_id = $post_id ? $post_id : get_the_ID();
	$terms   = get_the_terms( $post_id, 'conexao_category' );
	$counties = get_the_terms( $post_id, 'conexao_county' );

	$tax_query = array( 'relation' => 'OR' );
	if ( $terms && ! is_wp_error( $terms ) ) {
		$tax_query[] = array(
			'taxonomy' => 'conexao_category',
			'field'    => 'term_id',
			'terms'    => wp_list_pluck( $terms, 'term_id' ),
		);
	}
	if ( $counties && ! is_wp_error( $counties ) ) {
		$tax_query[] = array(
			'taxonomy' => 'conexao_county',
			'field'    => 'term_id',
			'terms'    => wp_list_pluck( $counties, 'term_id' ),
		);
	}

	if ( empty( $tax_query ) ) {
		return array();
	}

	$query = new WP_Query( array(
		'post_type'      => 'sponsor',
		'posts_per_page' => $limit,
		'post__not_in'   => array( $post_id ),
		'tax_query'      => $tax_query,
		'no_found_rows'  => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	) );

	$sponsors = array();
	if ( $query->have_posts() ) {
		while ( $query->have_posts() ) {
			$query->the_post();
			$sponsors[] = array(
				'title' => get_the_title(),
				'url'   => get_permalink(),
			);
		}
		wp_reset_postdata();
	}

	return $sponsors;
}
