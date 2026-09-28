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
		'beneficios'          => array(
			'name'        => 'Benefits',
			'slug'        => 'benefits',
			'description' => 'Social benefits and supports in Ireland.',
		),
		'documentos'          => array(
			'name'        => 'Documents',
			'slug'        => 'documents',
			'description' => 'Official documents and identification in Ireland.',
		),
		'educacao'            => array(
			'name'        => 'Education',
			'slug'        => 'education',
			'description' => 'Education in Ireland.',
		),
		'empregos'            => array(
			'name'        => 'Jobs',
			'slug'        => 'jobs',
			'description' => 'Work and employment rights in Ireland.',
		),
		'financas'            => array(
			'name'        => 'Finances',
			'slug'        => 'finances',
			'description' => 'Money, banks and consumer finance in Ireland.',
		),
		'imigracao-e-vistos'  => array(
			'name'        => 'Immigration & Visas',
			'slug'        => 'immigration-and-visas',
			'description' => 'Immigration, visas and residence in Ireland.',
		),
		'impostos-e-revenue'  => array(
			'name'        => 'Tax & Revenue',
			'slug'        => 'tax-and-revenue',
			'description' => 'Tax and Revenue in Ireland.',
		),
		'justica-e-seguranca' => array(
			'name'        => 'Justice & Safety',
			'slug'        => 'justice-and-safety',
			'description' => 'Justice, police and safety in Ireland.',
		),
		'moradia'             => array(
			'name'        => 'Housing',
			'slug'        => 'housing',
			'description' => 'Renting and housing in Ireland.',
		),
		'negocios'            => array(
			'name'        => 'Businesses',
			'slug'        => 'businesses',
			'description' => 'Business and self-employment in Ireland.',
		),
		'saude'               => array(
			'name'        => 'Health',
			'slug'        => 'health',
			'description' => 'Health services and wellbeing in Ireland.',
		),
		'servicos-publicos'   => array(
			'name'        => 'Public Services',
			'slug'        => 'public-services',
			'description' => 'Public services in Ireland.',
		),
		'transporte'          => array(
			'name'        => 'Transport',
			'slug'        => 'transport',
			'description' => 'Transport and driving in Ireland.',
		),
	);
}
