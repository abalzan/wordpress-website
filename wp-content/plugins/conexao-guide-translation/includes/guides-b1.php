<?php
// Stage 9 map B1: benefits, company, immigration, IRP renewal, MyGovID.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_translation_manifest_b1(): array {
	return array(
		'child-benefit-2' => array(
			'en_slug' => 'child-benefit-in-ireland-complete-guide',
			'en_title' => 'Child Benefit in Ireland: Complete Guide',
			'en_excerpt' => 'What Child Benefit is, who qualifies, how much it pays and how to apply through MyWelfare.',
			'en_meta_description' => 'Guide to Child Benefit in Ireland. Amount, eligibility, how to apply and information verified against official sources.',
			'en_content' => conexao_guide_en_childbenefit(),
		),
		'social-welfare-2' => array(
			'en_slug' => 'social-welfare-in-ireland-benefits-guide',
			'en_title' => 'Social Welfare in Ireland: Benefits Guide',
			'en_excerpt' => 'The main Irish social welfare payments, who qualifies and how to apply through MyWelfare.',
			'en_meta_description' => 'Guide to Social Welfare in Ireland. Available benefits, eligibility, how to apply and information verified against official sources.',
			'en_content' => conexao_guide_en_socialwelfare(),
		),
		'abrir-empresa-2' => array(
			'en_slug' => 'how-to-open-a-company-in-ireland-complete-guide',
			'en_title' => 'Opening a Company in Ireland: Complete Guide',
			'en_excerpt' => 'Types of company in Ireland, how to register with the CRO and Revenue, and what it costs.',
			'en_meta_description' => 'Guide to opening a company in Ireland. Company types, CRO registration, tax obligations and information verified against official sources.',
			'en_content' => conexao_guide_en_company(),
		),
		'visto-irlanda-2' => array(
			'en_slug' => 'visas-and-immigration-in-ireland-complete-guide',
			'en_title' => 'Visas and Immigration in Ireland: Complete Guide',
			'en_excerpt' => 'Which permissions (Stamps) exist in Ireland, who needs a visa and how to register with immigration.',
			'en_meta_description' => 'Guide to visas and immigration in Ireland. Permission types (Stamps), Employment Permit, IRP and information verified against official sources.',
			'en_content' => conexao_guide_en_immigration(),
		),
		'irp-renewal-2' => array(
			'en_slug' => 'irp-renewal-in-ireland-complete-guide',
			'en_title' => 'IRP Renewal in Ireland: Complete Guide',
			'en_excerpt' => 'Who has to renew the Irish Residence Permit, what you need and how to renew it online.',
			'en_meta_description' => 'Guide to renewing your IRP (Irish Residence Permit) in Ireland. Documents, fees, process and information verified against official sources.',
			'en_content' => conexao_guide_en_irprenewal(),
		),
		'mygovid' => array(
			'en_slug' => 'mygovid-in-ireland-how-to-create-and-use',
			'en_title' => 'MyGovID in Ireland: How to Create and Use It',
			'en_excerpt' => 'What MyGovID is, which levels exist and how to create a verified account.',
			'en_meta_description' => 'Guide to MyGovID in Ireland. What it is, how to create an account, verification levels and the services it unlocks.',
			'en_content' => conexao_guide_en_mygovid(),
		),
	);
}
