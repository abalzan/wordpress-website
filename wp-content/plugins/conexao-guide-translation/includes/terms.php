<?php
// Stage 9: EN category terms for guides.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_translation_term_manifest(): array {
	return array(
		'documentos' => array( 'name' => 'Documents', 'slug' => 'documents', 'description' => 'Official documents and identification in Ireland.' ),
		'saude' => array( 'name' => 'Health', 'slug' => 'health', 'description' => 'Health services and wellbeing in Ireland.' ),
		'saude-e-bem-estar' => array( 'name' => 'Health & Wellbeing', 'slug' => 'health-and-wellbeing', 'description' => 'Health and wellbeing guides.' ),
		'financas' => array( 'name' => 'Finances', 'slug' => 'finances', 'description' => 'Money, banks and consumer finance in Ireland.' ),
		'moradia' => array( 'name' => 'Housing', 'slug' => 'housing', 'description' => 'Renting and housing in Ireland.' ),
		'transporte' => array( 'name' => 'Transport', 'slug' => 'transport', 'description' => 'Transport and driving in Ireland.' ),
		'impostos-e-revenue' => array( 'name' => 'Tax & Revenue', 'slug' => 'tax-and-revenue', 'description' => 'Tax and Revenue in Ireland.' ),
		'beneficios' => array( 'name' => 'Benefits', 'slug' => 'benefits', 'description' => 'Social benefits and supports in Ireland.' ),
		'negocios' => array( 'name' => 'Businesses', 'slug' => 'businesses', 'description' => 'Business and self-employment in Ireland.' ),
		'imigracao-e-vistos' => array( 'name' => 'Immigration & Visas', 'slug' => 'immigration-and-visas', 'description' => 'Immigration, visas and residence in Ireland.' ),
		'servicos-publicos' => array( 'name' => 'Public Services', 'slug' => 'public-services', 'description' => 'Public services in Ireland.' ),
		'educacao' => array( 'name' => 'Education', 'slug' => 'education', 'description' => 'Education in Ireland.' ),
		'empregos' => array( 'name' => 'Jobs', 'slug' => 'jobs', 'description' => 'Work and employment rights in Ireland.' ),
		'justica-e-seguranca' => array( 'name' => 'Justice & Safety', 'slug' => 'justice-and-safety', 'description' => 'Justice, police and safety in Ireland.' ),
		'seguranca-e-direitos' => array( 'name' => 'Safety & Rights', 'slug' => 'safety-and-rights', 'description' => 'Safety and rights guides.' ),
	);
}
function conexao_guide_translation_manifest(): array {
	return array_merge( conexao_guide_translation_manifest_a(), conexao_guide_translation_manifest_b(), conexao_guide_translation_manifest_c(), conexao_guide_translation_manifest_d() );
}
