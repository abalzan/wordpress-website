<?php
// IRP renewal + MyGovID EN bodies.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_irprenewal(): string {
$c = '';
$c .= g_h2( 'What is the IRP?' );
$c .= g_p( 'The <strong>IRP (Irish Residence Permit)</strong> is the document that proves your legal residence in Ireland. It is a card with your personal details, photo and immigration status (Stamp). You must renew it <strong>annually</strong>.' ) . "\n";
$c .= g_h2( 'Who needs to renew it?' );
$c .= g_p( 'All non-EEA residents in Ireland with a residence permission need to renew their IRP. Brazilians on Stamp 1, Stamp 2, Stamp 3 or Stamp 4 need to renew.' ) . "\n";
$c .= g_h2( 'What you need' );
$c .= g_ul( array( 'Valid <strong>passport</strong>', 'Your <strong>current IRP</strong> (if you have it)', '<strong>Proof of address</strong> in Ireland', '<strong>Proof of status</strong>: Employment Permit, school letter, etc.', '<strong>Photo</strong> to the required standard' ) ) . "\n";
$c .= g_h2( 'How to renew' );
$c .= g_ol( array( 'Go to the <strong>Irish Immigration Service</strong> website', 'Complete the online renewal form', 'Send the required documents', 'Pay the €300 fee', 'Track the application status', 'Receive your new IRP by post' ) ) . "\n";
$c .= g_h2( 'How much does it cost?' );
$c .= g_p( 'The IRP renewal fee is <strong>€300</strong> per year.' ) . "\n";
$c .= g_h2( 'Where to renew' );
$c .= g_p( '<strong>Renew your IRP:</strong> ' . g_a( 'Irish Immigration - Registration Renewal', 'https://www.irishimmigration.ie/registration-renewal/' ) ) . "\n";
$c .= g_know( array( 'Renew your IRP <strong>before</strong> it expires', 'If your IRP has expired you may be <strong>illegally</strong> in Ireland', 'The IRP is <strong>personal and non-transferable</strong>', 'Keep your IRP in a safe place &#8211; it is your residence document', 'If you lose your job, notify the Department of Justice immediately' ) );
$c .= g_faq( array( 'How long does it take to renew the IRP?' => 'Processing can take from a few weeks to a few months, depending on application volumes. Apply in advance.', 'What happens if my IRP expires?' => 'You may be illegally in Ireland. Request the renewal as soon as possible and explain your situation to the Department of Justice.' ) );
$c .= g_links( array( 'Renew your IRP online' => 'https://www.irishimmigration.ie/registering-your-immigration-permission/how-to-renew-your-current-permission/renewing-your-registration-permission-if-you-live-in-the-republic-of-ireland/' ) ) . "\n\n";
$c .= g_src( array( 'Irish Immigration - Registration Renewal' => 'https://www.irishimmigration.ie/registration-renewal/', 'Irish Immigration - Registration' => 'https://www.irishimmigration.ie/registration/', 'Department of Justice' => 'https://www.gov.ie/en/department-of-justice-home-affairs-and-migration/' ) );
	return $c;
}
function conexao_guide_en_mygovid(): string {
$c = '';
$c .= g_h2( 'What is MyGovID?' );
$c .= g_p( '<strong>MyGovID</strong> is the Irish government&#8217;s authentication system. It works like a &#8220;single sign-on&#8221; that lets you access several public services online with one account. It is essential for <strong>MyWelfare</strong>, <strong>Revenue</strong> and other government services.' ) . "\n";
$c .= g_links( array( 'Access MyGovID' => 'https://www.mygovid.ie/' ) );
$c .= g_h2( 'Who needs MyGovID?' );
$c .= g_p( 'Anyone who needs to access public services online in Ireland, including to:' );
$c .= g_ul( array( 'Apply for social welfare payments (MyWelfare)', 'Access Revenue (tax)', 'Apply for a PPS Number', 'Access health services', 'Renew documents' ) ) . "\n";
$c .= g_h2( 'MyGovID levels' );
$c .= g_ul( array( '<strong>Basic</strong>: basic account with an email and password. It gives access to some services.', '<strong>Verified</strong>: verified account with an identity document. It gives access to most services.', '<strong>Mobile</strong>: additional verification with the mobile app for more sensitive services.' ) ) . "\n";
$c .= g_h2( 'How to create an account' );
$c .= g_ol( array( 'Go to the <strong>MyGovID</strong> website', 'Click &#8220;Create Account&#8221;', 'Enter your email and create a password', 'Verify your email', 'To verify your identity you will need your <strong>PPS Number</strong> and an identity document' ) ) . "\n";
$c .= g_h2( 'Where to create it' );
$c .= g_p( '<strong>Create a MyGovID account:</strong> ' . g_a( 'MyGovID', 'https://www.mygovid.ie/' ) ) . "\n";
$c .= g_know( array( 'MyGovID is <strong>free</strong>', 'You need a <strong>PPS Number</strong> to verify your account', 'Keep your password in a safe place &#8211; it is the key to all your public services', 'Never share your password with third parties' ) );
$c .= g_faq( array( 'Do I need a PPS Number to create MyGovID?' => 'Yes. To verify your account and access most services you need your PPS Number.', 'Is MyGovID free?' => 'Yes, creating and using MyGovID is free.' ) ) . "\n\n";
$c .= g_src( array( 'MyGovID' => 'https://www.mygovid.ie/', 'Citizens Information - MyGovID' => 'https://www.citizensinformation.ie/en/government-in-ireland/how-government-works/egovernment/mygovid/' ) );
	return $c;
}
