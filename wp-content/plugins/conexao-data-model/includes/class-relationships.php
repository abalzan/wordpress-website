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
		// Counties come from the ONE master list (Conexao_Data_Model_Counties):
		// 26 fixed Republic of Ireland counties, canonical slug + name. A county
		// is never derived from existing terms or imported data here.
		Conexao_Data_Model_Counties::ensure_all_terms();
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
