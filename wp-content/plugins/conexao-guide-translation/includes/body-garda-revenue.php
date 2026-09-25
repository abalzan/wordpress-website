<?php
// Garda + Revenue MyAccount EN bodies.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_garda(): string {
$c = '';
$c .= g_h2( 'What is An Garda Síochána?' );
$c .= g_p( '<strong>An Garda Síochána</strong> (pronounced &#8220;Garda Siokana&#8221;) is the national police service of Ireland. It is responsible for public safety, crime prevention and law enforcement.' ) . "\n";
$c .= g_links( array( 'An Garda Síochána' => 'https://www.garda.ie/en/' ) );
$c .= g_h2( 'Who needs to know about the Garda?' );
$c .= g_p( 'All residents and visitors in Ireland. It is important to know how to contact the Garda in an emergency and how to deal with the police.' ) . "\n";
$c .= g_h2( 'Emergency numbers' );
$c .= g_ul( array( '<strong>112</strong> or <strong>999</strong>: emergencies (police, ambulance, fire brigade)', '<strong>Garda Confidential Line</strong>: 1800 666 111 (for anonymous reports)' ) ) . "\n";
$c .= g_h2( 'How to deal with the Garda' );
$c .= g_ul( array( 'In an emergency, call <strong>112</strong> or <strong>999</strong>', 'For non-urgent matters, go to your local <strong>Garda station</strong>', 'If stopped by the Garda, <strong>cooperate</strong> and present your documents', 'You are entitled to an <strong>interpreter</strong> if you do not speak English' ) ) . "\n";
$c .= g_links( array( 'Official Garda contacts' => 'https://www.garda.ie/en/contact-us/' ) );
$c .= g_h2( 'Immigration registration' );
$c .= g_p( 'Outside Dublin, immigration registration (IRP) is done at the <strong>Garda National Immigration Bureau (GNIB)</strong>. In Dublin it is done at the <strong>Immigration Office</strong>.' ) . "\n";
$c .= g_h2( 'Where to access it' );
$c .= g_p( '<strong>An Garda Síochána:</strong> ' . g_a( 'garda.ie', 'https://www.garda.ie/en/' ) ) . "\n";
$c .= g_know( array( 'The Garda is <strong>approachable</strong> and generally helpful with information', 'You can ask for a <strong>reference number</strong> when reporting a crime', 'If you are a victim of crime the Garda can provide <strong>support</strong>', 'Never offer a <strong>bribe</strong> to the Garda &#8211; it is a serious crime' ) );
$c .= g_faq( array( 'What is the Garda?' => 'It is the national police service of Ireland. The full name is Garda Síochána, which means &#8220;Guardians of the Peace&#8221; in Irish.', 'Which number do I call in an emergency?' => 'Call 112 or 999. Both are free and work 24 hours.' ) ) . "\n\n";
$c .= g_src( array( 'An Garda Síochána' => 'https://www.garda.ie/en/', 'Citizens Information - Justice' => 'https://www.citizensinformation.ie/en/justice/' ) );
	return $c;
}
function conexao_guide_en_myaccount(): string {
$c = '';
$c .= g_h2( 'What is Revenue MyAccount?' );
$c .= g_p( '<strong>Revenue MyAccount</strong> is the online portal of the <strong>Revenue (Office of the Revenue Commissioners)</strong> for individual taxpayers. It is where you manage your tax, check your tax credits, claim refunds and get tax information.' ) . "\n";
$c .= g_links( array( 'Sign in or register for Revenue myAccount' => 'https://www.revenue.ie/en/online-services/services/myaccount/register-for-myaccount.aspx', 'Access Revenue myAccount' => 'https://www.ros.ie/myaccount-web/sign_in.html' ) );
$c .= g_h2( 'Who needs to use it?' );
$c .= g_p( 'All workers in Ireland. If you are employed (PAYE), Revenue MyAccount is essential to:' );
$c .= g_ul( array( 'Check your <strong>tax credits</strong>', 'Claim a tax <strong>refund</strong>', 'Register <strong>new jobs</strong>', 'Update your <strong>personal details</strong>', 'Access your <strong>income statements</strong>' ) ) . "\n";
$c .= g_h2( 'How to create an account' );
$c .= g_ol( array( 'Go to the <strong>Revenue</strong> website', 'Click on &#8220;MyAccount&#8221;', 'Register with your <strong>PPS Number</strong>', 'Verify your identity', 'Create your password' ) ) . "\n";
$c .= g_h2( 'What you can do' );
$c .= g_ul( array( 'Check and update <strong>tax credits</strong>', 'Claim a tax <strong>refund</strong>', 'Register <strong>new jobs</strong>', 'Declare <strong>additional income</strong>', 'Access <strong>income and tax certificates</strong>' ) ) . "\n";
$c .= g_h2( 'Where to access it' );
$c .= g_p( '<strong>Access Revenue MyAccount:</strong> ' . g_a( 'Revenue - MyAccount', 'https://www.revenue.ie/' ) ) . "\n";
$c .= g_know( array( 'Revenue MyAccount is <strong>free</strong>', 'You need a <strong>PPS Number</strong> to create an account', 'Check your tax credits <strong>regularly</strong>', 'If you paid too much tax, claim a <strong>refund</strong>' ) );
$c .= g_faq( array( 'Do I need a PPS Number for Revenue?' => 'Yes. The PPS Number is your tax identification in Ireland and is needed to create a Revenue account.', 'How do I claim a tax refund?' => 'Go to Revenue MyAccount, check your tax credits and claim the refund. Revenue processes it and deposits it into your bank account.' ) ) . "\n\n";
$c .= g_src( array( 'Revenue - Office of the Revenue Commissioners' => 'https://www.revenue.ie/', 'Citizens Information - Tax' => 'https://www.citizensinformation.ie/en/money-and-tax/tax/' ) );
	return $c;
}
