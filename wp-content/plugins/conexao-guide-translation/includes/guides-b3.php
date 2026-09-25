<?php
// Stage 9 map B3: HAP, RTB, CAO, consumer rights, data protection, Garda, Revenue MyAccount.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_translation_manifest_b3(): array {
	return array(
		'hap' => array(
			'en_slug' => 'hap-in-ireland-help-paying-your-rent',
			'en_title' => 'HAP in Ireland: Help Paying Your Rent',
			'en_excerpt' => 'What the Housing Assistance Payment is, who qualifies and how to apply through your local authority.',
			'en_meta_description' => 'Guide to HAP (Housing Assistance Payment) in Ireland. Who can apply, how it works and information verified against official sources.',
			'en_content' => conexao_guide_en_hap(),
		),
		'rtb' => array(
			'en_slug' => 'rtb-in-ireland-your-rights-as-a-tenant',
			'en_title' => 'RTB in Ireland: Your Rights as a Tenant',
			'en_excerpt' => 'What the Residential Tenancies Board does and how to register a dispute.',
			'en_meta_description' => 'Guide to the RTB (Residential Tenancies Board) in Ireland. Tenant rights, registering a tenancy and information verified against official sources.',
			'en_content' => conexao_guide_en_rtb(),
		),
		'cao' => array(
			'en_slug' => 'cao-in-ireland-how-to-apply-to-university',
			'en_title' => 'CAO in Ireland: How to Apply to University',
			'en_excerpt' => 'What the CAO is, who applies through it, the key dates and the application fee.',
			'en_meta_description' => 'Guide to the CAO (Central Applications Office) in Ireland. How to apply, deadlines, fees and information verified against official sources.',
			'en_content' => conexao_guide_en_cao(),
		),
		'direitos-consumidor' => array(
			'en_slug' => 'consumer-rights-in-ireland-complete-guide',
			'en_title' => 'Consumer Rights in Ireland: Complete Guide',
			'en_excerpt' => 'Returns, guarantees, online shopping and where to complain about a problem.',
			'en_meta_description' => 'Guide to consumer rights in Ireland. Returns, guarantees, online purchases and information verified against official sources.',
			'en_content' => conexao_guide_en_consumer(),
		),
		'protecao-dados' => array(
			'en_slug' => 'data-protection-in-ireland-your-rights-gdpr',
			'en_title' => 'Data Protection in Ireland: Your Rights (GDPR)',
			'en_excerpt' => 'Your rights under the GDPR in Ireland and how to exercise them.',
			'en_meta_description' => 'Guide to data protection in Ireland. Your rights under the GDPR, how to exercise them and information verified against official sources.',
			'en_content' => conexao_guide_en_gdpr(),
		),
		'garda' => array(
			'en_slug' => 'garda-in-ireland-police-and-emergencies',
			'en_title' => 'An Garda Síochána in Ireland: Police and Emergencies',
			'en_excerpt' => 'How to contact An Garda Síochána, emergency numbers and what to expect.',
			'en_meta_description' => 'Guide to An Garda Síochána in Ireland. Emergency numbers, how to interact with the Garda and information verified against official sources.',
			'en_content' => conexao_guide_en_garda(),
		),
		'revenue-myaccount' => array(
			'en_slug' => 'revenue-myaccount-in-ireland-how-to-use-it',
			'en_title' => 'Revenue MyAccount in Ireland: How to Use It',
			'en_excerpt' => 'How to create a Revenue MyAccount and what you can do with it.',
			'en_meta_description' => 'Guide to Revenue MyAccount in Ireland. How to create an account, check tax credits, claim refunds and information verified against official sources.',
			'en_content' => conexao_guide_en_myaccount(),
		),
	);
}
