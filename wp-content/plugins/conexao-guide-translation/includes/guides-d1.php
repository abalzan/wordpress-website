<?php
// Stage 9 map D1: legal aid, marriage/birth, financial and public-service complaints.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_translation_manifest_d1(): array {
	return array(
		'assistencia-juridica-legal-aid-irlanda-brasileiros' => array(
			'en_slug' => 'legal-aid-in-ireland-how-to-apply-for-civil-legal-aid',
			'en_title' => 'Legal Aid in Ireland: How to Apply for Civil Legal Aid',
			'en_excerpt' => 'How Civil Legal Aid works, who can apply, the means test and possible contributions.',
			'en_meta_description' => 'See how Civil Legal Aid from the Legal Aid Board works, who can apply, how the means test works and what contributions may apply.',
			'en_content' => conexao_guide_en_legalaid(),
		),
		'casamento-registro-nascimento-irlanda-brasileiros' => array(
			'en_slug' => 'marriage-and-birth-registration-in-ireland-guide-for-brazilian-families',
			'en_title' => 'Marriage and Birth Registration in Ireland: A Guide for Brazilian Families',
			'en_excerpt' => 'How to register a birth and how civil marriage works, including notice and fees.',
			'en_meta_description' => 'Understand how to register a birth in Ireland and how civil marriage works, including notice, documents, fees and steps for foreign nationals.',
			'en_content' => conexao_guide_en_marriage_birth(),
		),
		'reclamar-banco-seguro-servico-financeiro-irlanda' => array(
			'en_slug' => 'how-to-complain-about-a-bank-insurer-or-financial-service-in-ireland',
			'en_title' => 'How to Complain About a Bank, Insurer or Financial Service in Ireland',
			'en_excerpt' => 'Check that a firm is authorised and complain about a bank, insurer or financial service.',
			'en_meta_description' => 'Learn how to check that a financial firm is authorised in Ireland and how to complain about a bank, insurer, credit or other financial service.',
			'en_content' => conexao_guide_en_financial_complaints(),
		),
		'reclamar-servico-publico-irlanda-ombudsman' => array(
			'en_slug' => 'how-to-complain-about-a-public-service-in-ireland-when-to-use-the-ombudsman',
			'en_title' => 'How to Complain About a Public Service in Ireland: When to Use the Ombudsman',
			'en_excerpt' => 'Complain about a government department, local authority or public service in Ireland.',
			'en_meta_description' => 'See how to complain about a government department, local authority or public service in Ireland and when the Ombudsman can examine it.',
			'en_content' => conexao_guide_en_ombudsman(),
		),
	);
}
