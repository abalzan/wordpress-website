<?php
// Stage 9 map D2: travel checklist, WRC, lone parents, mental health, domestic violence, autism.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_translation_manifest_d2(): array {
	return array(
		'checklist-viagem-internacional-irlanda-brasileiros-irp' => array(
			'en_slug' => 'first-international-trip-with-irp-documents-checklist-for-brazilians',
			'en_title' => 'First International Trip with an IRP: Documents Checklist for Brazilians',
			'en_excerpt' => 'A practical checklist before travelling: passport, IRP, destination, return and emergency documents.',
			'en_meta_description' => 'A practical checklist for Brazilians resident in Ireland before travelling: passport, IRP, destination, return and emergency documents.',
			'en_content' => conexao_guide_en_travel_checklist(),
		),
		'reclamacao-trabalhista-wrc-irlanda-brasileiros' => array(
			'en_slug' => 'how-to-file-and-track-a-workplace-complaint-with-the-wrc',
			'en_title' => 'How to File and Track a Workplace Complaint with the WRC',
			'en_excerpt' => 'How to file a complaint or dispute with the Workplace Relations Commission and what to keep.',
			'en_meta_description' => 'Understand how to file a workplace complaint or dispute with the WRC and what to keep before starting the process.',
			'en_content' => conexao_guide_en_wrc(),
		),
		'beneficios-pais-solteiros-irlanda' => array(
			'en_slug' => 'benefits-for-lone-parents-in-ireland-payments-tax-and-other-supports',
			'en_title' => 'Benefits for Lone Parents in Ireland: Payments, Tax and Other Supports',
			'en_excerpt' => 'Which payments, tax credits and supports may exist for lone parents in Ireland.',
			'en_meta_description' => 'Find out which benefits, payments and supports may be available to lone parents in Ireland, including tax credits and housing support.',
			'en_content' => conexao_guide_en_loneparents(),
		),
		'violencia-domestica-irlanda-onde-encontrar-ajuda' => array(
			'en_slug' => 'domestic-violence-in-ireland-where-to-find-help-report-and-your-rights',
			'en_title' => 'Domestic Violence in Ireland: Where to Find Help, How to Report and Your Rights',
			'en_excerpt' => 'Where to seek help, how to contact the Garda, protective orders and immigration rights.',
			'en_meta_description' => 'Experiencing domestic violence in Ireland? See where to seek help, how to contact the Garda, protective measures and your immigration options.',
			'en_content' => conexao_guide_en_dv(),
		),
		'inverno-irlanda-depressao-sazonal-saude-mental' => array(
			'en_slug' => 'winter-in-ireland-and-mental-health-seasonal-depression-loneliness-and-where-to-find-help',
			'en_title' => '🌧️ Winter in Ireland and Mental Health: Seasonal Depression, Loneliness and Where to Find Help',
			'en_excerpt' => 'Recognise the signs of seasonal depression, when to seek help and which services are available in Ireland.',
			'en_meta_description' => 'Winter in Ireland can affect your mental health. Learn to recognise the signs of seasonal depression, where to seek help and which services are available.',
			'en_content' => conexao_guide_en_mental(),
		),
		'autismo-na-irlanda-diagnostico-hse-apoio' => array(
			'en_slug' => 'autism-in-ireland-diagnosis-hse-and-where-to-find-support',
			'en_title' => 'Autism in Ireland: Diagnosis, HSE and Where to Find Support',
			'en_excerpt' => 'How autism assessment works in Ireland for children and adults, and what support exists.',
			'en_meta_description' => 'Understand how autism diagnosis works in Ireland, which HSE services are involved and where to find support.',
			'en_content' => conexao_guide_en_autism(),
		),
		'autismo-viagem-aviao-irlanda-dublin-cork-airport' => array(
			'en_slug' => 'autism-and-air-travel-in-ireland-dublin-and-cork-airport',
			'en_title' => 'Autism and Air Travel in Ireland: Dublin and Cork Airport',
			'en_excerpt' => 'Important Flyer, Sunflower, sensory rooms and what to do if you lose the card.',
			'en_meta_description' => 'A guide for autistic people and families travelling through Ireland: Important Flyer, Sunflower, sensory rooms and preparation tips.',
			'en_content' => conexao_guide_en_fly(),
		),
	);
}
