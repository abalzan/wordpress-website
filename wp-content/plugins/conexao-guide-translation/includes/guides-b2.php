<?php
// Stage 9 map B2: work rights, transport, SUSI, emergency services, NCT, motor tax, Eircode.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_translation_manifest_b2(): array {
	return array(
		'direitos-trabalhistas' => array(
			'en_slug' => 'employment-rights-in-ireland-complete-guide',
			'en_title' => 'Employment Rights in Ireland: Complete Guide',
			'en_excerpt' => 'Minimum wage, annual leave, working hours, written contracts and where to get help.',
			'en_meta_description' => 'Guide to employment rights in Ireland. Minimum wage, annual leave, working hours, notice and information verified against official sources.',
			'en_content' => conexao_guide_en_rights(),
		),
		'transporte-publico' => array(
			'en_slug' => 'public-transport-in-ireland-complete-guide',
			'en_title' => 'Public Transport in Ireland: Complete Guide',
			'en_excerpt' => 'Buses, trains, Luas and DART in Ireland, and how to pay with the Leap Card.',
			'en_meta_description' => 'Guide to public transport in Ireland. Buses, trains, Luas, Leap Card and information verified against official sources.',
			'en_content' => conexao_guide_en_transport(),
		),
		'susi' => array(
			'en_slug' => 'susi-in-ireland-how-to-apply-for-a-grant',
			'en_title' => 'SUSI in Ireland: How to Apply for a Grant',
			'en_excerpt' => 'Who can apply for a SUSI student grant in Ireland, the types of grant and how to apply.',
			'en_meta_description' => 'Guide to SUSI in Ireland. Who can apply, grant types, how to apply and information verified against official sources.',
			'en_content' => conexao_guide_en_susi(),
		),
		'servicos-emergencia' => array(
			'en_slug' => 'emergency-services-in-ireland-112-and-999',
			'en_title' => 'Emergency Services in Ireland: 112 and 999',
			'en_excerpt' => 'How to reach emergency services in Ireland, what to say and why your Eircode matters.',
			'en_meta_description' => 'Guide to emergency services in Ireland. The 112 and 999 numbers, how to call, Eircode and information verified against official sources.',
			'en_content' => conexao_guide_en_emergency(),
		),
		'nct' => array(
			'en_slug' => 'nct-in-ireland-vehicle-inspection-guide',
			'en_title' => 'NCT in Ireland: Vehicle Inspection Guide',
			'en_excerpt' => 'What the NCT is, which cars need it, how often and what it costs.',
			'en_meta_description' => 'Guide to the NCT (National Car Test) in Ireland. Who needs it, when to do it, costs and information verified against official sources.',
			'en_content' => conexao_guide_en_nct(),
		),
		'motor-tax' => array(
			'en_slug' => 'motor-tax-in-ireland-how-to-pay',
			'en_title' => 'Motor Tax in Ireland: How to Pay',
			'en_excerpt' => 'How to pay the Irish motor tax online, what it costs and what you need first.',
			'en_meta_description' => 'Guide to Motor Tax in Ireland. How to pay, amounts, requirements and information verified against official sources.',
			'en_content' => conexao_guide_en_motortax(),
		),
		'eircode' => array(
			'en_slug' => 'eircode-in-ireland-what-it-is-and-how-to-find-it',
			'en_title' => 'Eircode in Ireland: What It Is and How to Find It',
			'en_excerpt' => 'What an Eircode is, who needs one and how to look it up for any Irish address.',
			'en_meta_description' => 'Guide to the Eircode in Ireland. What it is, how to find it, why it matters and information verified against official sources.',
			'en_content' => conexao_guide_en_eircode(),
		),
	);
}
