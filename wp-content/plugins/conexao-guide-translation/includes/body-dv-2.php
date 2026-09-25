<?php
// Domestic violence EN body, part 2 (court orders, immigration, children, financial abuse).
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_dv_p2(): string {
$c = '';
$c .= g_h2( 'Can I get a court order against the perpetrator?' );
$c .= g_p( 'Yes. Ireland has different types of Domestic Violence Orders:' );
$c .= g_ul( array( 'Protection Order;', 'Safety Order;', 'Barring Order;', 'Interim Barring Order;', 'Emergency Barring Order.' ) );
$c .= g_h3( 'Protection Order' );
$c .= g_p( 'This is a short-term protective measure. It can order the person to stop perpetrating violence or making threats. If the perpetrator lives with you, a Protection Order does not automatically order them to leave the home.' ) . "\n";
$c .= g_h3( 'Safety Order' );
$c .= g_p( 'This is a longer-term protective order. It can prevent certain acts of violence, threats, stalking or contact. If the perpetrator lives with you, a Safety Order does not order them to leave the house. A Safety Order granted by the District Court can last up to <strong>5 years</strong>.' ) . "\n";
$c .= g_h3( 'Barring Order' );
$c .= g_p( 'A Barring Order can order the perpetrator to leave or not enter the home, and can impose other restrictions relating to violence, threats, stalking or contact. A Barring Order granted by the District Court can last up to <strong>3 years</strong>.' ) . "\n";
$c .= g_h3( 'Interim Barring Order and Emergency Barring Order' );
$c .= g_p( 'These are emergency protective measures. Whether each one can be applied for depends on the relationship between the people and on the rights to the home. The Courts Service explains the specific criteria for each order.' ) . "\n";
$c .= g_h2( 'How do I apply for a Domestic Violence Order?' );
$c .= g_ol( array(
	'Complete the Application for a Domestic Violence Order.',
	'Submit the application at the appropriate Court Office, normally the District Court.',
	'The Court Office will arrange the hearing before a judge.',
	'The Courts Service or the Gardaí will notify the respondent in line with the procedure.',
	'You may attend the full hearing so that the judge can decide on long-term protection.'
) );
$c .= g_p( 'If you have dependent children you want included in the protection, they must be included in the application.' ) . "\n";
$c .= g_p( 'The Courts Service currently provides the official Application for a Domestic Violence Order form and advises that the correct version is used before filing.' ) . "\n";
$c .= g_links( array(
	'Garda - Domestic Abuse' => 'https://www.garda.ie/en/crime/domestic-abuse/',
	'HSE - Domestic, Sexual and Gender-Based Violence' => 'https://www2.hse.ie/services/domestic-sexual-gender-based-violence/',
	'Courts Service - Domestic Violence' => 'https://www.courts.ie/hubs/domestic-violence',
	'Irish Immigration Service - Victims of Domestic Abuse' => 'https://www.irishimmigration.ie/my-situation-has-changed-since-i-arrived-in-ireland/immigration-guidelines-for-victims-of-domestic-abuse/'
) );
$c .= g_h2( 'What if the perpetrator breaches the order?' );
$c .= g_p( 'Breaching a Domestic Violence Order is a <strong>crime</strong>. If it happens, contact the Gardaí immediately. If there is immediate danger, call <strong>999 or 112</strong>.' ) . "\n";
$c .= g_h2( 'What if I cannot afford a lawyer?' );
$c .= g_p( 'The <strong>Legal Aid Board</strong> provides legal advice and mediation services subject to the applicable criteria. The Government also advises victims of domestic violence to contact the Legal Aid Board for legal assistance information.' ) . "\n";
$c .= g_h2( 'I am Brazilian. What if my immigration status is linked to my partner?' );
$c .= g_p( 'If your Irish immigration permission is linked to the partner perpetrating the violence, <strong>do not assume you have to stay in the relationship to keep your immigration status</strong>.' ) . "\n";
$c .= g_p( 'The Department of Justice has a procedure for victims and survivors of domestic violence whose permission is linked to the perpetrator to apply for an <strong>independent immigration permission</strong>, where the applicable criteria are met.' ) . "\n";
$c .= g_p( 'The Government has also announced a waiver of the immigration registration fee for people who receive this independent permission on domestic violence grounds.' ) . "\n";
$c .= g_p( 'Everyone&#8217;s immigration situation is different. If you hold another type of Stamp, are in an international protection process, hold European citizenship, have children or other specific circumstances, seek proper advice before making an important immigration decision.' ) . "\n";
$c .= g_h2( 'What if I have children?' );
$c .= g_p( 'If children are involved, include their safety in your plan. Depending on the court order and the situation, dependent children can be included in the protection.' ) . "\n";
$c .= g_p( 'If a child is in immediate danger, call <strong>999 or 112</strong>. You may also need advice from Tusla, the Child and Family Agency, where there are concerns about a child&#8217;s safety or welfare.' ) . "\n";
$c .= g_h2( 'Financial and digital abuse' );
$c .= g_p( 'Domestic violence can include control over money, accounts, transport or other resources. It can also involve the use of technology to control, stalk or intimidate someone.' ) . "\n";
$c .= g_p( 'If you suspect your phone or computer is being monitored, be careful when researching help or installing apps. Where possible, use a secure device or ask someone you trust for help.' ) . "\n";
return $c;
}
