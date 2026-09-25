<?php
// Domestic violence EN body, part 3 (next 24 hours, phone table, links, FAQ, closing).
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_dv_p3(): string {
$c = '';
$c .= g_h2( 'What to do in the next 24 hours?' );
$c .= g_ol( array(
	'If there is immediate danger, call 999 or 112.',
	'If there is no immediate danger, reach out to someone you trust.',
	'Contact a specialist service.',
	'Consider making a safety plan.',
	'Seek advice about your children, housing and immigration situation.',
	'Consider speaking to the Garda.',
	'Seek legal advice if you need a court order.',
	'Do not confront the perpetrator if that could increase the risk.'
) );
$c .= g_h2( 'Important phone numbers' );
$c .= g_table( array( 'Situation', 'Contact' ), array(
	array( 'Emergency / immediate danger', '<strong>999 or 112</strong>' ),
	array( 'Women&#8217;s Aid', '<strong>1800 341 900 &#8212; 24h</strong>' ),
	array( 'Men&#8217;s Aid', '<strong>01 554 3811</strong>' ),
	array( 'National Male Advice Line', '<strong>1800 816 588</strong>' ),
	array( 'Legal Aid Board &#8212; domestic violence emergency in Dublin', '<strong>01 675 5566</strong>' ),
) );
$c .= g_h2( 'Useful official links' );
$links = array(
	'An Garda Síochána - Domestic Abuse' => 'https://www.garda.ie/en/crime/domestic-abuse/',
	'Department of Justice - Domestic Violence' => 'https://www.gov.ie/en/department-of-justice-home-affairs-and-migration/policy-information/domestic-violence/',
	'HSE - Domestic, Sexual and Gender-Based Violence Services' => 'https://www2.hse.ie/services/domestic-sexual-gender-based-violence/',
	'Courts Service - Domestic Violence' => 'https://www.courts.ie/hubs/domestic-violence',
	'Courts Service - Apply for a Domestic Violence Court Order' => 'https://www.courts.ie/guides/apply-for-a-domestic-violence-court-order',
	'Courts Service - Court Forms' => 'https://www.courts.ie/guides/court-forms',
	'Irish Immigration Service - Victims of Domestic Abuse' => 'https://www.irishimmigration.ie/my-situation-has-changed-since-i-arrived-in-ireland/immigration-guidelines-for-victims-of-domestic-abuse/',
	'Legal Aid Board' => 'https://www.legalaidboard.ie/',
);
$c .= g_plain_links( 'Useful official links', $links );
$c .= g_links( array( 'Check the Courts Service forms' => 'https://www.courts.ie/guides/court-forms' ) );
$c .= g_faq( array(
	'Can I call the Garda even if I have no evidence?' => 'You can go to the Garda and explain what is happening. The Garda can assess the situation, investigate possible crimes and advise on protective measures and services.',
	'Do I have to leave home before seeking help?' => 'Not necessarily. You can seek advice and make a safety plan before deciding, as long as it is safe to do so.',
	'Can I get a court order even if I am not divorced?' => 'Domestic Violence Orders depend on the relationship between the people and on the applicable legal criteria. Being divorced is not a general requirement for every form of protection.',
	'My partner is responsible for my visa. Do I have to stay with them?' => 'Not necessarily. There are specific procedures for certain victims and survivors whose immigration permission is linked to the perpetrator to apply for an independent permission.',
	'I am a man. Can I seek help?' => 'Yes. There are specific male support services, including Men&#8217;s Aid and the National Male Advice Line.',
	'I do not speak English. Can I ask for help?' => 'Yes. When you contact the Garda, say that you need help with the language. The Garda has arrangements to make communication easier for people whose first language is not English.'
) );
$c .= g_h2( 'One last important thing' );
$c .= g_p( '<strong>The violence is not your fault.</strong>' );
$c .= g_p( 'You are not responsible for another person&#8217;s abusive behaviour. Nor do you have to make every decision all at once.' ) . "\n";
$c .= g_p( 'If you are in danger, call <strong>999 or 112</strong>. If you are not in immediate danger, contact a specialist service and talk to someone you trust.' ) . "\n";
$c .= g_h2( 'Last checked' );
$c .= g_p( '<strong>Information checked: 29 August 2026.</strong>' );
$c .= g_p( 'Phone numbers, services, forms, immigration procedures, fees and criteria can change. Always confirm the details directly on the official sources before acting.' );
$c .= g_p( '<em>Notice: This guide is for information only and does not constitute personalised legal, immigration or safety advice. In a situation of immediate danger, call 999 or 112.</em>' );
$c .= g_sep();
return $c;
}
function conexao_guide_en_dv(): string { return conexao_guide_en_dv_p1() . conexao_guide_en_dv_p2() . conexao_guide_en_dv_p3(); }
