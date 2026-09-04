<?php
/** Shared taxonomy defaults. */

defined( 'ABSPATH' ) || exit;

final class Conexao_Data_Model_Relationships {

	public static function seed_terms() {
		$categories = array(
			'Moradia', 'Empregos', 'Saúde', 'Família', 'Transporte', 'Finanças',
			'Benefícios', 'Onde Comer', 'Educação', 'Documentos', 'Turismo', 'Negócios',
			'Treinamento',
		);
		// All 26 Republic of Ireland counties for the /lazer/ directory.
		$counties = array(
			'Dublin', 'Wicklow', 'Meath', 'Kildare', 'Louth', 'Cavan', 'Monaghan',
			'Donegal', 'Sligo', 'Leitrim', 'Roscommon', 'Mayo', 'Westmeath', 'Longford',
			'Laois', 'Offaly', 'Galway', 'Clare', 'Limerick', 'Tipperary', 'Kilkenny',
			'Carlow', 'Wexford', 'Waterford', 'Cork', 'Kerry',
		);
		// Leisure/tourism categories for the /lazer/ directory.
		$leisure_categories = array(
			'Natureza', 'História', 'Cultura', 'Família', 'Praias', 'Caminhadas',
			'Aventura', 'Jardins', 'Museus', 'Castelos', 'Vida Selvagem', 'Patrimônio',
			'Cidades', 'Ilhas', 'Greenways', 'Outros',
		);

		foreach ( $categories as $term ) {
			if ( ! term_exists( $term, 'conexao_category' ) ) {
				wp_insert_term( $term, 'conexao_category' );
			}
		}

		foreach ( $leisure_categories as $term ) {
			if ( ! term_exists( $term, 'conexao_category' ) ) {
				wp_insert_term( $term, 'conexao_category' );
			}
		}

		foreach ( $counties as $term ) {
			if ( ! term_exists( $term, 'conexao_county' ) ) {
				wp_insert_term( $term, 'conexao_county' );
			}
		}

		// Structured practical/profile characteristics for leisure destinations.
		// These live in a dedicated non-hierarchical taxonomy so they stay
		// distinct from the experience/category concepts in conexao_category.
		$leisure_attributes = array(
			'Famílias',
			'Exterior',
			'Interior',
			'Interior + exterior',
			'Gratuito',
			'Pet friendly',
			'Acessível',
			'Estacionamento',
			'Necessita reserva',
			// Phase 2 — practical-information extensions.
			'Pago',
			'Gratuito em determinadas condições',
			'Acesso de transporte público',
			'Bicicleta',
		);

		foreach ( $leisure_attributes as $term ) {
			if ( ! term_exists( $term, 'conexao_leisure_attribute' ) ) {
				wp_insert_term( $term, 'conexao_leisure_attribute' );
			}
		}
	}
}
