<?php
// EHIC EN body.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_ehic(): string {
$c = '';
$c .= g_intro(
	'Find out who can apply for an EHIC in Ireland, which documents the HSE asks for, where it is valid and why it is not travel insurance.',
	'/guias/ehic-irlanda-brasileiros-residentes/',
	'The EHIC is useful for Brazilians living in Ireland who travel around Europe. It can give access to necessary public healthcare during temporary stays, subject to the rules of the country visited.'
);
$c .= g_audience( 'Brazilians living in Ireland who plan to travel to EU, EEA or Swiss countries.' );
$c .= g_steps( array(
	'Confirm your eligibility: the HSE states that people living in Ireland can apply if they intend to live here for at least one year, subject to the applicable criteria.',
	'Have proof of residence and proof of your PPSN ready, where they are required.',
	'Apply online &#8211; the option the HSE describes as the fastest &#8211; or use the official postal/email channels.',
	'Each family member needs their own card where applicable.',
	'If you are travelling in less than 10 days, look at the urgent option and the Provisional Replacement Certificate.',
	'Take the card with you and do not treat the EHIC as travel insurance.'
) );
$c .= g_documents( array( 'Proof of residence in Ireland.', 'Proof of your PPSN.', 'Personal details of the applicants and dependants.', 'Any additional documents required by the HSE.' ) );
$c .= g_costs( 'The HSE provides the EHIC application service; confirm the current conditions at the time you apply.' );
$c .= g_timelines( 'If the trip is coming up, use the online option and check the urgent procedure described by the HSE.' );
$c .= g_pitfalls( array(
	'Thinking the EHIC covers private treatment.',
	'Thinking the EHIC covers repatriation.',
	'Using the EHIC for planned treatment.',
	'Not checking the destination&#8217;s co-payment rules.'
) );
$links = array(
	'HSE - EHIC' => 'https://www2.hse.ie/services/schemes-allowances/ehic/',
	'HSE - apply for an EHIC' => 'https://www2.hse.ie/services/schemes-allowances/ehic/apply/',
	'HSE - using the EHIC' => 'https://pprd2.hse.ie/services/schemes-allowances/ehic/visitors-to-ireland/',
);
$c .= g_plain_links( 'Official sources', $links );
$c .= g_plain_links( 'Useful links', $links );
$c .= g_faq( array(
	'Can a Brazilian get an Irish EHIC?' => 'Brazilian nationality is not the only criterion. Eligibility depends on your residence situation and the HSE rules.',
	'Where is the EHIC valid?' => 'The HSE states that it is accepted in EU, EEA and Swiss countries, under the local rules.',
	'Does the EHIC cover a private hospital?' => 'No. The HSE states that it relates to necessary public care.',
	'Does the EHIC replace travel insurance?' => 'No. The HSE expressly states that it does not replace travel insurance.'
) );
$c .= g_links( array( 'Apply for an EHIC online' => 'https://www2.hse.ie/services/schemes-allowances/ehic/apply/', 'Official EHIC information' => 'https://www2.hse.ie/services/schemes-allowances/ehic/' ) );
$c .= g_check( '18 August 2026', '' );
$c .= g_disclaimer( 'Health information does not replace a medical assessment or travel advice.' );
$c .= g_sep();
	return $c;
}
