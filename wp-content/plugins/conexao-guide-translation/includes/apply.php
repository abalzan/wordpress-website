<?php
// Stage 9 — Guide EN translation apply engine (part 1).
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_translation_snapshot_guide( int $post_id ): array {
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) { return array(); }
	$terms = array();
	foreach ( array( 'conexao_category', 'conexao_county', 'conexao_tag' ) as $taxonomy ) {
		$terms[ $taxonomy ] = array_map( 'intval', wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) ) );
	}
	return array(
		'post_name' => $post->post_name,
		'post_title' => $post->post_title,
		'post_content' => $post->post_content,
		'post_excerpt' => $post->post_excerpt,
		'post_status' => $post->post_status,
		'post_date' => $post->post_date,
		'post_author' => (int) $post->post_author,
		'menu_order' => (int) $post->menu_order,
		'thumbnail' => (int) get_post_thumbnail_id( $post_id ),
		'terms' => $terms,
		'guide_status' => (string) get_post_meta( $post_id, '_guide_status', true ),
		'language' => function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $post_id, 'slug' ) : '',
		'meta_desc' => (string) get_post_meta( $post_id, 'conexao_meta_description', true ),
	);
}
function conexao_guide_translation_pair_ok( int $pt_id, int $en_id ): bool {
	if ( $pt_id <= 0 || $en_id <= 0 || $pt_id === $en_id ) { return false; }
	return (int) pll_get_post( $pt_id, 'en' ) === $en_id && (int) pll_get_post( $en_id, 'pt' ) === $pt_id;
}
function conexao_guide_translation_ensure_terms( bool $dry_run ): array {
	$manifest = conexao_guide_translation_term_manifest();
	$rows = array( 'created' => 0, 'linked' => 0, 'skipped' => 0 );
	foreach ( $manifest as $pt_slug => $en ) {
		$pt_term = get_term_by( 'slug', (string) $pt_slug, 'conexao_category' );
		if ( ! $pt_term instanceof WP_Term ) { continue; }
		$en_term_id = function_exists( 'pll_get_term' ) ? (int) pll_get_term( (int) $pt_term->term_id, 'en' ) : 0;
		if ( $en_term_id > 0 ) {
			// Already linked: repair manifest-owned drift (slug/name/description)
			// so a re-run converges on the authored English term.
			if ( ! $dry_run ) {
				$en_term = get_term( $en_term_id, 'conexao_category' );
				if ( $en_term instanceof WP_Term ) {
					$update = array();
					if ( $en_term->slug !== (string) $en['slug'] ) { $update['slug'] = (string) $en['slug']; }
					if ( $en_term->name !== (string) $en['name'] ) { $update['name'] = (string) $en['name']; }
					if ( '' !== (string) $en['description'] && $en_term->description !== (string) $en['description'] ) { $update['description'] = (string) $en['description']; }
					if ( ! empty( $update ) ) { wp_update_term( $en_term_id, 'conexao_category', $update ); }
				}
			}
			++$rows['linked']; ++$rows['skipped']; continue;
		}
		if ( $dry_run ) { ++$rows['created']; continue; }
		$existing = get_term_by( 'slug', (string) $en['slug'], 'conexao_category' );
		if ( $existing instanceof WP_Term ) {
			pll_set_term_language( (int) $existing->term_id, 'en' );
			pll_save_term_translations( array( 'pt' => (int) $pt_term->term_id, 'en' => (int) $existing->term_id ) );
			++$rows['linked'];
			continue;
		}
		$created = wp_insert_term( (string) $en['name'], 'conexao_category', array( 'slug' => (string) $en['slug'], 'description' => (string) $en['description'] ) );
		if ( is_wp_error( $created ) ) { continue; }
		pll_set_term_language( (int) $created['term_id'], 'en' );
		pll_save_term_translations( array( 'pt' => (int) $pt_term->term_id, 'en' => (int) $created['term_id'] ) );
		++$rows['created'];
		++$rows['linked'];
	}
	return $rows;
}
function conexao_guide_translation_en_term_id( int $pt_term_id ): int {
	if ( ! function_exists( 'pll_get_term' ) ) { return 0; }
	return (int) pll_get_term( $pt_term_id, 'en' );
}
// __PART2__
