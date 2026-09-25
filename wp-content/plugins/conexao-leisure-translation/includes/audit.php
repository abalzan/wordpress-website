<?php
/**
 * Stage 7 — EN Leisure description completeness audit.
 *
 * Gate: published PT leisure records missing `_leisure_excerpt_en` = 0.
 * Also reports the record-level facts the rollout must preserve:
 *   - total leisure records (any status);
 *   - published leisure records;
 *   - records carrying an EN description;
 *   - EN descriptions present on non-leisure posts (must be 0 — the meta key
 *     belongs to the leisure description layer only).
 *
 * @package Conexao_Leisure_Translation
 */

defined( 'ABSPATH' ) || exit;

/**
 * Run the audit.
 *
 * @return array {counts: array, missing: array[], pass: bool}
 */
function conexao_leisure_translation_audit() {
	$counts = array(
		'leisure records (any status)'                      => 0,
		'published leisure records'                         => 0,
		'published leisure records with EN description'     => 0,
		'published PT leisure records missing EN description' => 0,
		'EN descriptions on non-leisure posts'              => 0,
	);
	$missing = array();

	$all = get_posts(
		array(
			'post_type'        => 'leisure',
			'post_status'      => 'any',
			'numberposts'      => -1,
			'lang'             => '',
			'suppress_filters' => true,
			'no_found_rows'    => true,
		)
	);
	$counts['leisure records (any status)'] = is_array( $all ) ? count( $all ) : 0;

	$published = array_filter(
		(array) $all,
		static function ( $post ) {
			return 'publish' === $post->post_status;
		}
	);
	$counts['published leisure records'] = count( $published );

	foreach ( $published as $post ) {
		$en = trim( (string) get_post_meta( $post->ID, CONEXAO_LEISURE_TRANSLATION_META, true ) );
		if ( '' !== $en ) {
			++$counts['published leisure records with EN description'];
			continue;
		}

		// Stage 7 owns the DESCRIPTIONS of the Portuguese records (every
		// leisure record is PT until a future stage says otherwise). With
		// Polylang active, a record of another language would carry its own
		// description model and is not gated here.
		$is_pt = true;
		if ( function_exists( 'pll_get_post_language' ) ) {
			$lang = pll_get_post_language( $post->ID, 'slug' );
			$is_pt = ( ! is_string( $lang ) || '' === $lang || 'pt' === $lang );
		}
		if ( ! $is_pt ) {
			continue;
		}

		++$counts['published PT leisure records missing EN description'];
		$missing[] = array(
			'id'    => (int) $post->ID,
			'slug'  => $post->post_name,
			'title' => $post->post_title,
		);
	}

	// The meta key must never leak onto other post types.
	$leak = get_posts(
		array(
			'post_type'        => 'any',
			'post_status'      => 'any',
			'numberposts'      => -1,
			'lang'             => '',
			'suppress_filters' => true,
			'no_found_rows'    => true,
			'meta_key'         => CONEXAO_LEISURE_TRANSLATION_META,
			'fields'           => 'ids',
			'post__not_in'     => array_map(
				static function ( $post ) {
					return (int) $post->ID;
				},
				(array) $all
			),
		)
	);
	$counts['EN descriptions on non-leisure posts'] = is_array( $leak ) ? count( $leak ) : 0;

	return array(
		'counts'  => $counts,
		'missing' => $missing,
		'pass'    => ( 0 === $counts['published PT leisure records missing EN description'] ),
	);
}
