<?php
/** Shared taxonomy defaults. */

defined( 'ABSPATH' ) || exit;

final class Conexao_Data_Model_Relationships {

	public static function seed_terms() {
		$categories = array(
			'Moradia', 'Empregos', 'Saúde', 'Família', 'Transporte', 'Finanças',
			'Benefícios', 'Onde Comer', 'Educação', 'Documentos', 'Turismo', 'Negócios',
		);
		$counties = array( 'Dublin', 'Laois', 'Cork', 'Galway', 'Limerick', 'Kildare', 'Meath', 'Wicklow', 'Waterford' );

		foreach ( $categories as $term ) {
			if ( ! term_exists( $term, 'conexao_category' ) ) {
				wp_insert_term( $term, 'conexao_category' );
			}
		}

		foreach ( $counties as $term ) {
			if ( ! term_exists( $term, 'conexao_county' ) ) {
				wp_insert_term( $term, 'conexao_county' );
			}
		}
	}
}
