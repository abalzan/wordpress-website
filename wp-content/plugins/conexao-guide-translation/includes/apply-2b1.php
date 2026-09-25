<?php
// Stage 9 apply part 2b-1: create path.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_translation_create_en( WP_Post $pt_post, array $en ): array {
	$new_id = wp_insert_post( array( 'post_type' => 'guide', 'post_name' => (string) $en['en_slug'], 'post_title' => (string) $en['en_title'], 'post_content' => g_content( (string) $en['en_content'] ), 'post_excerpt' => (string) $en['en_excerpt'], 'post_status' => 'publish', 'post_date' => $pt_post->post_date, 'post_date_gmt' => $pt_post->post_date_gmt, 'post_author' => (int) $pt_post->post_author, 'menu_order' => (int) $pt_post->menu_order ), true );
	if ( is_wp_error( $new_id ) ) { return array( 0, 'create failed: ' . $new_id->get_error_message() ); }
	$new_id = (int) $new_id;
	$pt_id = (int) $pt_post->ID;
	pll_set_post_language( $new_id, 'en' );
	if ( ! pll_get_post_language( $pt_id ) ) { pll_set_post_language( $pt_id, 'pt' ); }
	pll_save_post_translations( array( 'pt' => $pt_id, 'en' => $new_id ) );
	conexao_guide_translation_copy_fields( $pt_id, $new_id, $en );
	update_post_meta( $new_id, 'conexao_meta_description', (string) $en['en_meta_description'] );
	if ( ! conexao_guide_translation_pair_ok( $pt_id, $new_id ) ) { return array( $new_id, 'link verify failed' ); }
	return array( $new_id, '' );
}
