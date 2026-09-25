<?php
// Stage 9 map A: batch 1.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_translation_manifest_a(): array {
	return array(
		'pps-number-2' => array(
			'en_slug' => 'pps-number-ireland',
			'en_title' => 'How to get a PPS Number in Ireland',
			'en_excerpt' => 'What the PPS Number is, who needs it, which documents to prepare, how to apply, what it costs and how long it takes.',
			'en_meta_description' => 'Complete guide to getting your PPS Number in Ireland. Required documents, step-by-step process, costs and timelines. Information verified against official sources.',
			'en_content' => conexao_guide_en_pps(),
		),
		'medical-card-2' => array(
			'en_slug' => 'medical-card-ireland-complete-guide',
			'en_title' => 'Medical Card in Ireland: Complete Guide',
			'en_excerpt' => 'What the Medical Card is, who qualifies, the income limits, what it covers and how to apply through the HSE.',
			'en_meta_description' => 'Complete guide to the Medical Card in Ireland. Eligibility, income limits, benefits, how to apply and verified official information.',
			'en_content' => conexao_guide_en_medical(),
		),
		'gp-registration-2' => array(
			'en_slug' => 'how-to-register-with-a-gp-in-ireland',
			'en_title' => 'How to register with a GP (family doctor) in Ireland',
			'en_excerpt' => 'How to find a GP, how to register, what it costs and what to do out of hours or in an emergency.',
			'en_meta_description' => 'Guide to registering with a GP (family doctor) in Ireland. How to find one, what it costs, required documents and verified official information.',
			'en_content' => conexao_guide_en_gpreg(),
		),
		'abrir-conta-bancaria-2' => array(
			'en_slug' => 'how-to-open-a-bank-account-in-ireland',
			'en_title' => 'Opening a Bank Account in Ireland',
			'en_excerpt' => 'Why you need an Irish bank account, which documents to bring, how to apply and what it costs.',
			'en_meta_description' => 'Step-by-step guide to opening a bank account in Ireland. Required documents, banks, costs and verified official information.',
			'en_content' => conexao_guide_en_bank(),
		),
		'alugar-casa-2' => array(
			'en_slug' => 'renting-a-house-in-ireland-complete-guide',
			'en_title' => 'Renting a House in Ireland: Complete Guide',
			'en_excerpt' => 'Where to look for a rental, what documents you need, what it costs and your rights as a tenant in Ireland.',
			'en_meta_description' => 'Complete guide to renting a house in Ireland. Where to look, required documents, tenant rights, costs and verified official information.',
			'en_content' => conexao_guide_en_rent(),
		),
		'comprar-carro-2' => array(
			'en_slug' => 'buying-a-car-in-ireland-complete-guide',
			'en_title' => 'Buying a Car in Ireland: Complete Guide',
			'en_excerpt' => 'How to buy a car in Ireland: what you need, the buying steps, the NCT, Motor Tax and insurance.',
			'en_meta_description' => 'Guide to buying a car in Ireland. Documentation, NCT, motor tax, insurance and information verified against official sources.',
			'en_content' => conexao_guide_en_car(),
		),
		'carteira-de-motorista-2' => array(
			'en_slug' => 'driving-licence-in-ireland-how-to-exchange-your-cnh',
			'en_title' => 'Driving Licence in Ireland: How to Exchange Your CNH',
			'en_excerpt' => 'How Brazilians exchange their Brazilian driving licence for an Irish one through the NDLS, and what it requires.',
			'en_meta_description' => 'Guide to getting an Irish driving licence. Exchanging a Brazilian CNH, documents, costs and information verified against official sources.',
			'en_content' => conexao_guide_en_licence(),
		),
		'impostos-2' => array(
			'en_slug' => 'taxes-in-ireland-complete-guide-income-tax-usc-prsi',
			'en_title' => 'Taxes in Ireland: Complete Guide (Income Tax, USC, PRSI)',
			'en_excerpt' => 'How Income Tax, USC, PRSI and PAYE work in Ireland, what tax credits you get and how to register with Revenue.',
			'en_meta_description' => 'Complete guide to taxes in Ireland. Income Tax, USC, PRSI, PAYE, tax credits and how to file with Revenue.',
			'en_content' => conexao_guide_en_taxes(),
		),
		'cidadania-irlandesa-2' => array(
			'en_slug' => 'irish-citizenship-complete-naturalisation-guide',
			'en_title' => 'Irish Citizenship: Complete Naturalisation Guide',
			'en_excerpt' => 'Who can apply for Irish citizenship by naturalisation, the residence requirements, fees and the process.',
			'en_meta_description' => 'Guide to Irish citizenship. Residence requirements, the naturalisation process, fees and information verified against official sources.',
			'en_content' => conexao_guide_en_citizenship(),
		),
		'passaporte-irlandes-2' => array(
			'en_slug' => 'irish-passport-how-to-apply',
			'en_title' => 'Irish Passport: How to Apply',
			'en_excerpt' => 'Who can apply for an Irish passport, what you need, the fees and how long it takes.',
			'en_meta_description' => 'Guide to applying for an Irish passport. Documents, fees, timelines and the online process. Information verified against official sources.',
			'en_content' => conexao_guide_en_passport(),
		),
	);
}
