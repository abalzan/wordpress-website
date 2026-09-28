<?php
/**
 * Authored English translations for the TRANSLATED `conexao_category` terms
 * used by the published PT `guide` records (stage `en-guide`).
 *
 * Imported mechanically from the authored term data that shipped with the retired
 * `conexao-guide-translation` plugin (Stage 9). Only the terms a published PT guide
 * actually uses are carried here, so the stage never mints an EN term for a concept
 * no guide references.
 *
 * SCOPE — this file is deliberately `conexao_category` ONLY. `conexao_county` and
 * `conexao_town` are SHARED proper-name taxonomies: one physical term serves both
 * languages and must never be duplicated or translated per language
 * (engineering standard 6.1, asserted by test-taxonomy-policy.php).
 *
 * Identity: the PT term slug. A local term ID is never identity.
 *
 * @package Conexao_EN_Translation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The authored EN `conexao_category` terms, keyed by PT term slug.
 *
 * @return array<string,array{name:string,slug:string,description:string}>
 */
function conexao_en_translation_guide_terms_v1(): array {
	return array(
		// PT category term: beneficios
		'beneficios' => array(
			'name'        => 'Benefits',
			'slug'        => 'benefits',
			'description' => 'Social benefits and supports in Ireland.',
		),
		// PT category term: documentos
		'documentos' => array(
			'name'        => 'Documents',
			'slug'        => 'documents',
			'description' => 'Official documents and identification in Ireland.',
		),
		// PT category term: educacao
		'educacao' => array(
			'name'        => 'Education',
			'slug'        => 'education',
			'description' => 'Education in Ireland.',
		),
		// PT category term: empregos
		'empregos' => array(
			'name'        => 'Jobs',
			'slug'        => 'jobs',
			'description' => 'Work and employment rights in Ireland.',
		),
		// PT category term: financas
		'financas' => array(
			'name'        => 'Finances',
			'slug'        => 'finances',
			'description' => 'Money, banks and consumer finance in Ireland.',
		),
		// PT category term: imigracao-e-vistos
		'imigracao-e-vistos' => array(
			'name'        => 'Immigration & Visas',
			'slug'        => 'immigration-and-visas',
			'description' => 'Immigration, visas and residence in Ireland.',
		),
		// PT category term: impostos-e-revenue
		'impostos-e-revenue' => array(
			'name'        => 'Tax & Revenue',
			'slug'        => 'tax-and-revenue',
			'description' => 'Tax and Revenue in Ireland.',
		),
		// PT category term: justica-e-seguranca
		'justica-e-seguranca' => array(
			'name'        => 'Justice & Safety',
			'slug'        => 'justice-and-safety',
			'description' => 'Justice, police and safety in Ireland.',
		),
		// PT category term: moradia
		'moradia' => array(
			'name'        => 'Housing',
			'slug'        => 'housing',
			'description' => 'Renting and housing in Ireland.',
		),
		// PT category term: negocios
		'negocios' => array(
			'name'        => 'Businesses',
			'slug'        => 'businesses',
			'description' => 'Business and self-employment in Ireland.',
		),
		// PT category term: saude
		'saude' => array(
			'name'        => 'Health',
			'slug'        => 'health',
			'description' => 'Health services and wellbeing in Ireland.',
		),
		// PT category term: servicos-publicos
		'servicos-publicos' => array(
			'name'        => 'Public Services',
			'slug'        => 'public-services',
			'description' => 'Public services in Ireland.',
		),
		// PT category term: transporte
		'transporte' => array(
			'name'        => 'Transport',
			'slug'        => 'transport',
			'description' => 'Transport and driving in Ireland.',
		),
	);
}
