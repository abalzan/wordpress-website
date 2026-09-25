<?php
// Immigration / stamps EN body.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_immigration(): string {
$c = '';
$c .= g_h2( 'What is the Irish immigration system?' );
$c .= g_p( 'The Irish immigration system is run by the <strong>Department of Justice</strong> and the <strong>Irish Immigration Service (ISD)</strong>. Brazilians <strong>do not need a visa</strong> to enter Ireland as tourists (up to 90 days), but they do need a permission to work or to reside for longer.' ) . "\n";
$c .= g_links( array( 'Official immigration portal' => 'https://www.irishimmigration.ie/' ) );
$c .= g_h2( 'Who needs a visa?' );
$c .= g_p( 'Brazilians <strong>do not need a visa</strong> to enter Ireland as tourists for up to 90 days. However, to <strong>work</strong>, <strong>study</strong> or <strong>reside</strong> for more than 90 days you need the right permission.' ) . "\n";
$c .= g_h2( 'Types of permission (Stamps)' );
$c .= g_ul( array( '<strong>Stamp 1</strong>: work permission (Employment Permit)', '<strong>Stamp 2</strong>: student', '<strong>Stamp 2A</strong>: student not entitled to work', '<strong>Stamp 3</strong>: visitor/family member (cannot work)', '<strong>Stamp 4</strong>: residence (can work without a separate permit)', '<strong>Stamp 5</strong>: residence without a time limit', '<strong>Stamp 6</strong>: Irish citizen with dual citizenship' ) ) . "\n";
$c .= g_h2( 'How to get work permission' );
$c .= g_p( 'To work in Ireland you generally need an <strong>Employment Permit</strong>. The process is:' );
$c .= g_ol( array( 'Find a job with an Irish employer', 'The employer applies for the <strong>Employment Permit</strong> to the Department of Enterprise', 'With the permit approved you can enter Ireland', 'Register at the <strong>Immigration Office</strong> and receive your <strong>IRP (Irish Residence Permit)</strong>' ) ) . "\n";
$c .= g_h2( 'What is the IRP?' );
$c .= g_p( 'The <strong>IRP (Irish Residence Permit)</strong> is the document that proves your legal residence in Ireland. It is a card with your details and your immigration status (Stamp). You must renew it periodically.' ) . "\n";
$c .= g_h2( 'How to register with immigration' );
$c .= g_ol( array( 'Book a visit to the <strong>Immigration Office</strong> (in Dublin) or the <strong>Garda National Immigration Bureau (GNIB)</strong> (outside Dublin)', 'Bring your passport, Employment Permit, proof of address and photo', 'Pay the registration fee', 'Receive your IRP' ) ) . "\n";
$c .= g_h2( 'How much does it cost?' );
$c .= g_p( 'The immigration registration (IRP) fee is <strong>€300</strong> per year.' ) . "\n";
$c .= g_h2( 'Where to apply' );
$c .= g_p( '<strong>Immigration information:</strong> ' . g_a( 'Irish Immigration Service', 'https://www.irishimmigration.ie/' ) ) . "\n";
$c .= g_know( array( 'You must register with immigration <strong>within 90 days</strong> of arriving in Ireland (if you are staying more than 90 days)', 'The IRP must be <strong>renewed annually</strong>', 'Do not work without the right permission &#8211; this can lead to <strong>deportation</strong>', 'If you lose your job, notify the Department of Justice immediately', '<strong>Stamp 4</strong> allows you to work without a separate permit' ) );
$c .= g_faq( array( 'Do Brazilians need a visa for Ireland?' => 'Not for tourism (up to 90 days). To work or study you need the right permission (an Employment Permit or a Student visa).', 'What is Stamp 4?' => 'Stamp 4 is a residence permission that allows you to work without a separate Employment Permit. It is granted in several situations, such as after 2 years on Stamp 1 or for family members of Irish citizens.', 'How much does the IRP cost?' => 'The IRP registration fee is €300 per year.' ) ) . "\n\n";
$c .= g_src( array( 'Irish Immigration Service' => 'https://www.irishimmigration.ie/', 'Department of Justice' => 'https://www.gov.ie/en/department-of-justice-home-affairs-and-migration/', 'Citizens Information - Moving to Ireland' => 'https://www.citizensinformation.ie/en/moving-country/' ) );
	return $c;
}
