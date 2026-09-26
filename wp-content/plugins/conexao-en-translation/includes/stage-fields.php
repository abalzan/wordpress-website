<?php
/**
 * Stage M per-post-type field mapping.
 *
 * These are thin, stage-specific adapters: lookup, create, repair, link, verify,
 * snapshot, remove. No planning, counting, snapshot orchestration or gate
 * calculation lives here — the shared engine owns all of it.
 *
 * @package Conexao_EN_Translation
 */

defined( 'ABSPATH' ) || exit;

/**
 * Snapshot a PT record: everything that must be unchanged after the run.
 *
 * The shared engine re-captures this after an apply and compares, which is what
 * proves PT immutability numerically (`PT sources changed must be 0`).
 *
 * @param int $post_id PT post ID.
 * @return array<string,mixed>
 */
function conexao_en_translation_snapshot( int $post_id ): array {
	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post ) {
		return array();
	}

	$terms = array();
	foreach ( array( 'conexao_category', 'conexao_county', 'conexao_tag', 'conexao_town' ) as $taxonomy ) {
		$ids = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
		$terms[ $taxonomy ] = is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
	}

	return array(
		'post_name'   => $post->post_name,
		'post_title'  => $post->post_title,
		'post_content'=> $post->post_content,
		'post_excerpt'=> $post->post_excerpt,
		'post_status' => $post->post_status,
		'post_date'   => $post->post_date,
		'post_author' => (int) $post->post_author,
		'menu_order'  => (int) $post->menu_order,
		'thumbnail'   => (int) get_post_thumbnail_id( $post_id ),
		'terms'       => $terms,
		'language'    => function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $post_id, 'slug' ) : '',
		'meta_desc'   => (string) get_post_meta( $post_id, 'conexao_meta_description', true ),
	);
}

/**
 * The Polylang pair is valid only when it links in BOTH directions.
 *
 * @param int $pt_id PT post ID.
 * @param int $en_id EN post ID.
 * @return bool
 */
function conexao_en_translation_pair_ok( int $pt_id, int $en_id ): bool {
	if ( $pt_id <= 0 || $en_id <= 0 || $pt_id === $en_id ) {
		return false;
	}

	return (int) pll_get_post( $pt_id, 'en' ) === $en_id
		&& (int) pll_get_post( $en_id, 'pt' ) === $pt_id;
}

/**
 * Copy the shared, language-neutral fields from the PT record to the EN record.
 *
 * Deliberately copies: date, author, menu order, featured image and the SHARED
 * proper-name taxonomies (county/town), which are the same physical terms in
 * both languages. It deliberately does NOT copy the body, title, excerpt or the
 * translated `conexao_category` terms: those are authored English here, and the
 * category counterpart is linked through Polylang rather than copied.
 *
 * @param int   $pt_id PT post ID.
 * @param int   $en_id EN post ID.
 * @param array $row   Manifest row.
 * @return int Number of fields written.
 */
function conexao_en_translation_copy_fields( int $pt_id, int $en_id, array $row ): int {
	$pt  = get_post( $pt_id );
	$copied = 0;

	if ( $pt instanceof WP_Post ) {
		$update = array(
			'ID'           => $en_id,
			'post_date'    => $pt->post_date,
			'post_date_gmt'=> $pt->post_date_gmt,
			'post_author'  => (int) $pt->post_author,
			'menu_order'   => (int) $pt->menu_order,
		);
		wp_update_post( $update );
		$copied += 4;

		$thumbnail = (int) get_post_thumbnail_id( $pt_id );
		if ( $thumbnail > 0 ) {
			set_post_thumbnail( $en_id, $thumbnail );
			++$copied;
		}
	}

	// Shared proper-name terms: the SAME term in both languages by policy.
	foreach ( array( 'conexao_county', 'conexao_town' ) as $taxonomy ) {
		$ids = wp_get_post_terms( $pt_id, $taxonomy, array( 'fields' => 'ids' ) );
		if ( ! is_wp_error( $ids ) && ! empty( $ids ) ) {
			wp_set_post_terms( $en_id, array_map( 'intval', $ids ), $taxonomy );
			++$copied;
		}
	}

	// Translated category terms: link the EN counterpart, never copy the PT one.
	$pt_terms = wp_get_post_terms( $pt_id, 'conexao_category', array( 'fields' => 'ids' ) );
	if ( ! is_wp_error( $pt_terms ) ) {
		$en_terms = array();
		foreach ( (array) $pt_terms as $pt_term_id ) {
			$en_term_id = function_exists( 'pll_get_term' ) ? (int) pll_get_term( (int) $pt_term_id, 'en' ) : 0;
			if ( $en_term_id > 0 ) {
				$en_terms[] = $en_term_id;
			}
		}
		if ( ! empty( $en_terms ) ) {
			wp_set_post_terms( $en_id, $en_terms, 'conexao_category' );
		}
		++$copied;
	}

	$meta_description = (string) ( $row['en_meta_description'] ?? '' );
	if ( '' !== $meta_description ) {
		update_post_meta( $en_id, 'conexao_meta_description', $meta_description );
		++$copied;
	}

	return $copied;
}
