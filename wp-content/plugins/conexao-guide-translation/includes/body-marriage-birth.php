<?php
// Marriage and birth registration EN body.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_marriage_birth(): string {
$c = '';
$c .= g_intro(
	'Understand how to register a birth in Ireland and how civil marriage works, including notice, documents, fees and the extra steps for foreign nationals.',
	'/guias/casamento-registro-nascimento-irlanda-brasileiros/',
	'Having children and getting married in Ireland involve civil registration procedures that can be different from the Brazilian ones. The General Register Office and the civil registration services hold the official information.'
);
$c .= g_audience( 'Brazilians who have children in Ireland, plan to marry in the country, or need civil registration certificates.' );
$c .= g_steps( array(
	'For a birth, register the child within the official deadline: gov.ie states a maximum of three months after the birth.',
	'Use the Civil Registration Service and check the online option available.',
	'Request the birth certificate when you need it for school, a passport or other processes.',
	'For a marriage, give at least three months notice to the civil registration service.',
	'Book the marriage notification appointment and both partners must attend.',
	'Prepare passports, birth certificates, proof of address and PPS Numbers, plus special documents where applicable.',
	'If one partner is a foreign national, check the interview, proof of immigration status, translation and interpreter requirements where they apply.',
	'After the ceremony, follow the registration procedure and request the marriage certificate if you need it.'
) );
$c .= g_documents( array(
	'For a birth: the information and documents requested by the Civil Registration Service.',
	'For a marriage: passport, birth certificate, proof of address, PPSN and specific documents.',
	'Translations where documents are not in English or Irish.'
) );
$c .= g_costs( 'The marriage notification fee is a non-refundable €200. The marriage registration itself is free, but certificates have fees. Confirm the current amounts.' );
$c .= g_timelines( 'Birth: up to three months. Marriage: at least three months notice before the ceremony.' );
$c .= g_pitfalls( array(
	'Leaving the birth registration too late.',
	'Booking a marriage without allowing for the three months notice.',
	'Bringing copies without the originals where they are required.',
	'Forgetting translations or the extra documentation for foreign nationals.'
) );
$links = array(
	'gov.ie - Register a birth' => 'https://www.gov.ie/en/department-of-social-protection/services/register-a-birth-in-ireland/',
	'gov.ie - Get married in Ireland' => 'https://www.gov.ie/en/department-of-social-protection/services/get-married-in-ireland/',
	'gov.ie - General Register Office services' => 'https://www.gov.ie/en/department-of-social-protection/collections/all-services-available-from-the-general-register-office/',
);
$c .= g_plain_links( 'Official sources', $links );
$c .= g_plain_links( 'Useful links', $links );
$c .= g_faq( array(
	'How long do I have to register a birth?' => 'gov.ie currently states a maximum of three months after the birth.',
	'How far in advance do I notify a marriage?' => 'The general rule is three months notice, given in person to the civil registration service.',
	'Do foreign nationals need an interview?' => 'gov.ie states that an interview is required when one of the partners is a foreign national, among other situations.',
	'How much does the marriage notice cost?' => 'The notification fee is currently €200 and non-refundable; registration and certificates have their own rules.'
) );
$c .= g_links( array(
	'Register a birth online' => 'https://www.gov.ie/en/department-of-social-protection/services/register-a-birth-in-ireland/',
	'Official information on getting married in Ireland' => 'https://www.gov.ie/en/department-of-social-protection/services/get-married-in-ireland/'
) );
$c .= g_check( '18 August 2026', '' );
$c .= g_disclaimer( 'General information; it does not replace official civil registration guidance or legal advice.' );
$c .= g_sep();
	return $c;
}
