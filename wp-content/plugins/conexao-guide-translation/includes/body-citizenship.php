<?php
// Citizenship EN body.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_citizenship(): string {
$c = '';
$c .= g_h2( 'What is Irish citizenship?' );
$c .= g_p( 'Irish citizenship can be obtained by <strong>birth</strong>, by <strong>descent</strong> (Foreign Birth Registration) or by <strong>naturalisation</strong>. For most Brazilians the route is <strong>naturalisation</strong> after a period of legal residence in Ireland.' ) . "\n";
$c .= g_links( array( 'Apply online for citizenship by naturalisation' => 'https://www.irishimmigration.ie/how-to-become-a-citizen/become-an-irish-citizen-by%20-naturalisation/', 'Official citizenship and residence guide' => 'https://www.irishimmigration.ie/how-to-become-a-citizen/' ) );
$c .= g_h2( 'Who can apply?' );
$c .= g_p( 'You can apply for citizenship by naturalisation if:' );
$c .= g_ul( array( 'You have <strong>legal residence</strong> in Ireland for a continuous period', 'You <strong>intend to continue residing</strong> in Ireland', 'You are of <strong>good character</strong> (no relevant criminal convictions)', 'You intend to remain in Ireland after obtaining citizenship' ) ) . "\n";
$c .= g_h2( 'Residence requirements' );
$c .= g_p( 'The residence requirements for naturalisation are:' );
$c .= g_ul( array( '<strong>1 year of continuous residence</strong> immediately before the application', 'Plus <strong>3 years of residence</strong> in the previous 8 years (4 years in the last 8 in total)', 'Or <strong>3 years of continuous residence</strong> if you are married to an Irish citizen' ) ) . "\n";
$c .= g_h2( 'What you need' );
$c .= g_ul( array( 'Valid <strong>passport</strong>', 'Valid <strong>IRP (Irish Residence Permit)</strong>', '<strong>Proof of residence</strong> in Ireland', '<strong>Birth certificate</strong> (translated if necessary)', '<strong>Proof of address</strong>', '<strong>Proof of good character</strong> (criminal record certificate)' ) ) . "\n";
$c .= g_h2( 'How to apply' );
$c .= g_ol( array( 'Complete the naturalisation form (Form 8)', 'Gather all required documents', 'Send the application with the fee to the <strong>Department of Justice</strong>', 'Wait for the assessment (it can take several months)', 'If approved, attend the <strong>citizenship ceremony</strong>' ) ) . "\n";
$c .= g_h2( 'How much does it cost?' );
$c .= g_p( 'The naturalisation application fee is <strong>€175</strong> (not refundable). If approved, the certificate fee is <strong>€950</strong> for adults.' ) . "\n";
$c .= g_h2( 'How long does it take?' );
$c .= g_p( 'Processing can take from <strong>6 to 12 months</strong> or more, depending on application volumes. The Department of Justice does not publish a guaranteed fixed timeframe.' ) . "\n";
$c .= g_h2( 'Where to apply' );
$c .= g_p( '<strong>Apply for citizenship:</strong> ' . g_a( 'Irish Immigration - How to Become a Citizen', 'https://www.irishimmigration.ie/how-to-become-a-citizen/' ) ) . "\n";
$c .= g_know( array( 'Ireland <strong>allows dual citizenship</strong> &#8211; you do not have to give up Brazilian citizenship', 'Residence time is counted from your <strong>immigration registration</strong> (IRP)', 'Student (Stamp 2) residence periods count only in part', 'If you have Irish ancestry (grandparents or great-grandparents) you may be entitled to citizenship by <strong>Foreign Birth Registration</strong>' ) );
$c .= g_faq( array( 'Do I have to give up my Brazilian citizenship?' => 'No. Ireland allows dual citizenship, and so does Brazil. You can hold both citizenships.', 'How much residence is required?' => 'Generally 4 years of legal residence in the last 8 years, including at least 1 continuous year immediately before the application. Specific categories have their own rules.', 'What is the citizenship ceremony?' => 'It is an official ceremony where you take the oath of fidelity to Ireland and receive your naturalisation certificate. It is a formal and mandatory event.' ) ) . "\n\n";
$c .= g_src( array( 'Irish Immigration - How to Become a Citizen' => 'https://www.irishimmigration.ie/how-to-become-a-citizen/', 'Citizens Information - Naturalisation' => 'https://www.citizensinformation.ie/en/moving-country/irish-citizenship/becoming-an-irish-citizen-through-naturalisation/', 'Department of Justice' => 'https://www.gov.ie/en/department-of-justice-home-affairs-and-migration/' ) );
	return $c;
}
