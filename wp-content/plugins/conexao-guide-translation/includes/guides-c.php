<?php
// Stage 9 map C: IRP first/travel, Employment Permit, first job, sole trader, HSE care, EHIC, NARIC, Leap Card.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_translation_manifest_c(): array {
	return array(
		'primeira-inscricao-irp-irlanda-brasileiros' => array(
			'en_slug' => 'first-irp-registration-in-ireland-step-by-step-for-brazilians',
			'en_title' => 'First IRP Registration in Ireland: Step-by-Step for Brazilians',
			'en_excerpt' => 'How the first registration of your Irish immigration permission works, which documents to prepare and what it costs.',
			'en_meta_description' => 'See how the first IRP registration works for Brazilians and other non-EU/EEA citizens: documents to prepare, costs and where to follow the process.',
			'en_content' => conexao_guide_en_irp_first(),
		),
		'viajar-fora-irlanda-com-irp-brasileiros' => array(
			'en_slug' => 'travelling-outside-ireland-with-irp-what-brazilians-need-to-know',
			'en_title' => 'Travelling Outside Ireland with an IRP: What Brazilians Need to Know',
			'en_excerpt' => 'When you can return to Ireland without a re-entry visa, and what to do if your IRP is lost or expired.',
			'en_meta_description' => 'Understand when the IRP allows you to return to Ireland without a re-entry visa, and what to do if the card is lost or expired.',
			'en_content' => conexao_guide_en_irp_travel(),
		),
		'employment-permit-irlanda-brasileiros' => array(
			'en_slug' => 'employment-permit-in-ireland-when-a-brazilian-needs-one',
			'en_title' => 'Employment Permit in Ireland: When a Brazilian Needs One',
			'en_excerpt' => 'When Brazilians need an Employment Permit, the difference between Critical Skills and General, and the main steps.',
			'en_meta_description' => 'Understand when Brazilians need an Employment Permit to work in Ireland, the differences between permit types and the main steps.',
			'en_content' => conexao_guide_en_employment_permit(),
		),
		'primeiro-emprego-irlanda-emergency-tax' => array(
			'en_slug' => 'first-job-in-ireland-how-to-avoid-emergency-tax',
			'en_title' => 'First Job in Ireland: How to Avoid Emergency Tax',
			'en_excerpt' => 'Register your first job with Revenue, use myAccount and check your Tax Credit Certificate.',
			'en_meta_description' => 'Just started your first job in Ireland? See how to register the job with Revenue, avoid Emergency Tax and check your Tax Credit Certificate.',
			'en_content' => conexao_guide_en_firstjob(),
		),
		'sole-trader-autonomo-irlanda-brasileiros' => array(
			'en_slug' => 'how-to-work-as-a-sole-trader-in-ireland',
			'en_title' => 'How to Work as a Sole Trader in Ireland',
			'en_excerpt' => 'Sole trader tax registration, self-assessment and how ROS is used in Ireland.',
			'en_meta_description' => 'See how to set up sole trader tax registration in Ireland, when to register for Income Tax and how ROS is used.',
			'en_content' => conexao_guide_en_soletrapper(),
		),
		'hse-irlanda-gp-out-of-hours-injury-unit-emergency-department' => array(
			'en_slug' => 'hse-in-ireland-gp-out-of-hours-injury-unit-or-emergency-department',
			'en_title' => 'HSE in Ireland: When to See a GP, GP Out-of-Hours, Injury Unit or Emergency Department',
			'en_excerpt' => 'Where to seek medical care in Ireland, what it costs and when to call 112 or 999.',
			'en_meta_description' => 'Understand where to seek medical care in Ireland: GP, GP out-of-hours, Injury Unit and Emergency Department, including costs.',
			'en_content' => conexao_guide_en_hse_care(),
		),
		'ehic-irlanda-brasileiros-residentes' => array(
			'en_slug' => 'ehic-in-ireland-for-resident-brazilians',
			'en_title' => 'EHIC in Ireland: How Resident Brazilians Can Get the European Health Insurance Card',
			'en_excerpt' => 'Who can apply for an EHIC in Ireland, what the HSE asks for and where it is valid.',
			'en_meta_description' => 'Find out who can apply for an EHIC in Ireland, which documents the HSE asks for and where it is valid.',
			'en_content' => conexao_guide_en_ehic(),
		),
		'reconhecer-diploma-brasileiro-na-irlanda-naric-qqi' => array(
			'en_slug' => 'how-to-recognise-a-brazilian-diploma-in-ireland-naric-and-qqi-guide',
			'en_title' => 'How to Get a Brazilian Diploma Recognised in Ireland: NARIC and QQI Guide',
			'en_excerpt' => 'How to compare a Brazilian qualification with the Irish NFQ and what the limits are.',
			'en_meta_description' => 'See how to use NARIC Ireland/QQI to compare a Brazilian qualification with the National Framework of Qualifications.',
			'en_content' => conexao_guide_en_naric(),
		),
		'leap-card-irlanda-como-usar' => array(
			'en_slug' => 'leap-card-in-ireland-how-to-use-it-and-pay-less',
			'en_title' => 'Leap Card in Ireland: How to Use the Transport Card and Pay Less',
			'en_excerpt' => 'How the TFI Leap Card works, where to use it, how to top it up and what to do if you lose it.',
			'en_meta_description' => 'Learn how the TFI Leap Card works, where to use it, how to top it up, what fare caps exist and what to do if you lose it.',
			'en_content' => conexao_guide_en_leapcard(),
		),
		'learner-permit-theory-test-irlanda-cnh-brasileira' => array(
			'en_slug' => 'brazilian-driving-licence-in-ireland-theory-test-and-learner-permit',
			'en_title' => 'Brazilian Driving Licence in Ireland: When You Need the Theory Test and Learner Permit',
			'en_excerpt' => 'The route for Brazilians who cannot exchange their licence: theory test, learner permit, EDT and driving test.',
			'en_meta_description' => 'Understand the route for Brazilians who cannot exchange a foreign licence: theory test, learner permit, EDT and driving test.',
			'en_content' => conexao_guide_en_learner_permit(),
		),
	);
}

