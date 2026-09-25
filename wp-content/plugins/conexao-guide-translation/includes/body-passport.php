<?php
// Irish passport EN body.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_passport(): string {
$c = '';
$c .= g_h2( 'What is the Irish passport?' );
$c .= g_p( 'The Irish passport is issued by the <strong>Passport Service</strong> (Department of Foreign Affairs) to Irish citizens. It allows travel to many countries without a visa.' ) . "\n";
$c .= g_links( array( 'Passport Online - Department of Foreign Affairs' => 'https://www.ireland.ie/en/dfa/passports/' ) );
$c .= g_h2( 'Who can apply?' );
$c .= g_p( 'You can apply for an Irish passport if you are:' );
$c .= g_ul( array( 'An <strong>Irish citizen</strong> by birth', 'An <strong>Irish citizen</strong> by naturalisation', 'An <strong>Irish citizen</strong> by Foreign Birth Registration' ) ) . "\n";
$c .= g_h2( 'What you need' );
$c .= g_ul( array( '<strong>Naturalisation certificate</strong> (if naturalised)', '<strong>Birth certificate</strong> (if a citizen by birth)', '<strong>Identity document</strong> with photo', '<strong>Digital photo</strong> to the required standard', '<strong>Witness</strong> to sign the form' ) ) . "\n";
$c .= g_h2( 'How to apply' );
$c .= g_ol( array( 'Go to the <strong>Passport Service</strong> website', 'Create an account or sign in', 'Complete the online form', 'Upload the digital photo', 'Pay the fee', 'Track the application status online' ) ) . "\n";
$c .= g_h2( 'How much does it cost?' );
$c .= g_p( 'Irish passport fees (online application) are:' );
$c .= g_ul( array( '<strong>Adult passport (10 years)</strong>: €75', '<strong>Child passport (5 years)</strong>: €20', '<strong>Large passport (66 pages)</strong>: €105' ) ) . "\n";
$c .= g_h2( 'How long does it take?' );
$c .= g_p( 'Online passport processing usually takes <strong>10 to 20 working days</strong> for straightforward applications. Postal applications can take longer. The Passport Service publishes current turnaround times on its website.' ) . "\n";
$c .= g_h2( 'Where to apply' );
$c .= g_p( '<strong>Apply for a passport:</strong> ' . g_a( 'Passport Service - Ireland.ie', 'https://www.ireland.ie/en/dfa/passports/' ) ) . "\n";
$c .= g_know( array( 'An Irish passport is valid for <strong>10 years</strong> for adults', 'You can apply for the passport <strong>immediately</strong> after receiving your naturalisation certificate', 'The Irish passport allows <strong>visa-free travel</strong> to many countries, including the United Kingdom and the European Union', 'If you hold both Brazilian and Irish citizenship, you can use the Irish passport to travel around Europe' ) );
$c .= g_faq( array( 'Can I have both a Brazilian and an Irish passport?' => 'Yes. Ireland and Brazil both allow dual citizenship. You can hold both passports.', 'How long does it take to receive the passport?' => 'For straightforward online applications it is usually 10 to 20 working days. Check the current turnaround times on the Passport Service website.' ) ) . "\n\n";
$c .= g_src( array( 'Passport Service - Ireland.ie' => 'https://www.ireland.ie/en/dfa/passports/', 'Citizens Information - Passports' => 'https://www.citizensinformation.ie/en/travel-and-recreation/passports/', 'Department of Foreign Affairs' => 'https://www.dfa.ie/' ) );
	return $c;
}
