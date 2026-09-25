<?php
// Stage 9 apply part 2a: field copy.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_translation_copy_fields( int $pt_id, int $en_id, array $en ): array {
	$copied = array();
	$thumb = (int) get_post_thumbnail_id( $pt_id );
	if ( $thumb > 0 ) { set_post_thumbnail( $en_id, $thumb ); $copied[] = '_thumbnail_id'; }
	foreach ( array( '_guide_status', '_conexao_featured' ) as $key ) {
		$val = get_post_meta( $pt_id, $key, true );
		if ( '' !== $val && null !== $val ) { update_post_meta( $en_id, $key, $val ); $copied[] = $key; }
	}
	$pt_terms = wp_get_post_terms( $pt_id, 'conexao_category', array( 'fields' => 'ids' ) );
	if ( ! is_wp_error( $pt_terms ) && ! empty( $pt_terms ) ) {
		$en_terms = array();
		foreach ( array_map( 'intval', $pt_terms ) as $pt_term_id ) {
			$en_term_id = conexao_guide_translation_en_term_id( $pt_term_id );
			if ( $en_term_id > 0 ) { $en_terms[] = $en_term_id; }
		}
		if ( ! empty( $en_terms ) ) { wp_set_post_terms( $en_id, $en_terms, 'conexao_category', false ); $copied[] = 'conexao_category'; }
	}
	return $copied;
}
