<?php
// HSE urgent care EN body.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_hse_care(): string {
$c = '';
$c .= g_intro(
	'Understand where to seek medical care in Ireland: GP, GP out-of-hours, Injury Unit and Emergency Department, including costs and when to call 112 or 999.',
	'/guias/hse-irlanda-gp-out-of-hours-injury-unit-emergency-department/',
	'Ireland&#8217;s urgent care system can be different from Brazil&#8217;s. Not every situation needs an Emergency Department. The HSE advises choosing the service according to the severity and type of problem.'
);
$c .= g_audience( 'Brazilians living in Ireland who need to know where to seek care when their GP is closed, or when an injury or emergency happens.' );
$c .= g_steps( array(
	'For non-urgent problems, see your GP during normal opening hours.',
	'If the GP is closed and you need urgent care, use the GP out-of-hours service; the HSE states that it normally works by phone with triage.',
	'For recent non-life-threatening injuries that are unlikely to need admission, check whether there is an Injury Unit suitable for your age and region.',
	'Go to an Emergency Department for serious or potentially life-threatening situations.',
	'If there is a risk to life or a need for urgent treatment, call 112 or 999.',
	'Before you leave, check the HSE finder for the unit, opening hours, the ages it treats and any disruptions.'
) );
$c .= g_links( array( 'Find urgent or emergency care - HSE' => 'https://www2.hse.ie/services/find-urgent-emergency-care/', 'Find a GP' => 'https://www2.hse.ie/services/find-a-gp/' ) );
$c .= g_documents( array( 'Medical Card or GP Visit Card, if you have one.', 'Information about your medication and relevant conditions.', 'Identity document, when requested.' ) );
$c .= g_costs( 'The HSE currently states €75 for an Injury Unit and €100 for an Emergency Department, with exemptions in situations such as holding a Medical Card or being referred. Confirm the amounts before publication.' );
$c .= g_timelines( 'There is no deadline in an emergency. In a serious situation do not wait: call 112 or 999.' );
$c .= g_pitfalls( array(
	'Going to an Emergency Department for any symptom.',
	'Using an Injury Unit for problems it does not treat.',
	'Not checking the unit&#8217;s age range and opening hours.',
	'Assuming the cost is always the same.'
) );
$links = array(
	'HSE - Urgent and emergency care' => 'https://www2.hse.ie/services/urgent-emergency-care/',
	'HSE - find care' => 'https://www2.hse.ie/services/find-urgent-emergency-care/',
	'HSE - GP out-of-hours' => 'https://www2.hse.ie/emergencies/when-to-go-to-a-gp-out-of-hours/',
	'HSE - Injury Unit' => 'https://www2.hse.ie/emergencies/when-to-visit-an-injury-unit/',
);
$c .= g_plain_links( 'Official sources', $links );
$c .= g_plain_links( 'Useful links', $links );
$c .= g_faq( array(
	'When should I use an Injury Unit?' => 'For recent, non-life-threatening injuries such as some fractures, sprains, cuts and minor burns.',
	'When should I go to an Emergency Department?' => 'For serious or potentially life-threatening problems; if life is at risk, call 112 or 999.',
	'Is GP out-of-hours walk-in?' => 'The HSE states that it normally works by phone with triage.',
	'How much does an Injury Unit cost?' => 'The amount currently stated by the HSE is €75, with some exemptions. Confirm before publishing.'
) );
$c .= g_check( '18 August 2026', '' );
$c .= g_disclaimer( 'Health information does not replace a medical assessment. In an emergency, follow the HSE and call 112/999.' );
$c .= g_sep();
	return $c;
}
