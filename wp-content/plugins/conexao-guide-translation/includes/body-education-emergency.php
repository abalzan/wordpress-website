<?php
// SUSI + emergency services EN bodies.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_susi(): string {
$c = '';
$c .= g_h2( 'What is SUSI?' );
$c .= g_p( '<strong>SUSI (Student Universal Support Ireland)</strong> is the official body that administers student grants for further-education students in Ireland. The grants help pay fees and living costs.' ) . "\n";
$c .= g_links( array( 'Start a SUSI application' => 'https://www.susi.ie/start-an-application/', 'Apply for or check a SUSI grant' => 'https://www.susi.ie/' ) );
$c .= g_h2( 'Who can apply?' );
$c .= g_p( 'To apply for SUSI you need:' );
$c .= g_ul( array( 'To be a <strong>habitual resident</strong> of Ireland (living here for at least 3 of the last 5 years)', 'To be enrolled in an <strong>eligible course</strong> (usually at third level)', 'To pass the <strong>income assessment</strong>', 'To have a <strong>PPS Number</strong>' ) ) . "\n";
$c .= g_h2( 'Types of grant' );
$c .= g_ul( array( '<strong>Maintenance Grant</strong>: for living costs (rent, food)', '<strong>Fee Grant</strong>: to pay tuition fees', '<strong>Field Trip Grant</strong>: for study trips' ) ) . "\n";
$c .= g_h2( 'How to apply' );
$c .= g_ol( array( 'Go to the <strong>SUSI</strong> website', 'Create an account', 'Complete the online form', 'Send the required documents (proof of income, residence, etc.)', 'Track the application status' ) ) . "\n";
$c .= g_h2( 'Where to apply' );
$c .= g_p( '<strong>Apply for a SUSI grant:</strong> ' . g_a( 'SUSI', 'https://www.susi.ie/' ) ) . "\n";
$c .= g_know( array( 'The application period is usually <strong>April to November</strong> for the following academic year', 'The income test considers parental income (if you are dependent) or your own income', 'The income limits vary according to family size', 'You must be a <strong>habitual resident</strong> &#8211; newly arrived Brazilians may not be eligible immediately' ) );
$c .= g_faq( array( 'Can Brazilians receive a SUSI grant?' => 'Yes, if you are a habitual resident of Ireland (usually 3 of the last 5 years) and meet the income and course requirements.', 'When should I apply?' => 'The main window is April to November for the following academic year. Apply as early as possible.' ) );
$c .= g_links( array( 'Apply for a SUSI grant' => 'https://www.susi.ie/how-to-apply/' ) ) . "\n\n";
$c .= g_src( array( 'SUSI - Student Universal Support Ireland' => 'https://www.susi.ie/', 'Citizens Information - Student Grant Scheme' => 'https://www.citizensinformation.ie/en/education/third-level-education/fees-and-supports-for-third-level-education/student-grant-scheme/' ) );
	return $c;
}
function conexao_guide_en_emergency(): string {
$c = '';
$c .= g_h2( 'What are emergency services in Ireland?' );
$c .= g_p( 'In Ireland, emergency services are reached on <strong>112</strong> or <strong>999</strong>. Both are free and work 24 hours a day, 7 days a week.' ) . "\n";
$c .= g_h2( 'Who needs to know?' );
$c .= g_p( 'All residents and visitors in Ireland should know how to reach emergency services. For a medical emergency, fire, crime or accident, call 112 or 999.' ) . "\n";
$c .= g_h2( 'Emergency numbers' );
$c .= g_ul( array( '<strong>112</strong>: European emergency number (works across the EU)', '<strong>999</strong>: Irish emergency number', '<strong>112/999</strong>: Garda (police), ambulance, fire brigade, sea rescue' ) ) . "\n";
$c .= g_h2( 'How to call' );
$c .= g_ol( array( 'Call <strong>112</strong> or <strong>999</strong>', 'Say which service you need (police, ambulance, fire brigade)', 'Give your location (address or Eircode)', 'Describe the emergency', 'Do not hang up until the operator tells you to' ) ) . "\n";
$c .= g_links( array( 'Garda emergency numbers and guidance' => 'https://www.garda.ie/en/contact-us/useful-contact-numbers/', 'HSE guidance on urgent care' => 'https://www2.hse.ie/services/find-urgent-emergency-care/' ) );
$c .= g_h2( 'What is the Eircode?' );
$c .= g_p( 'The <strong>Eircode</strong> is the Irish postcode (7 characters, e.g. D01 F5P2). Every address in Ireland has a unique Eircode. In an emergency, giving your Eircode helps services find you quickly.' ) . "\n";
$c .= g_know( array( 'Calls to 112/999 are <strong>free</strong>', 'You can call from any phone, including a mobile with no credit', 'The operator may speak <strong>English</strong> &#8211; have the basics ready', 'If you do not speak English, say the name of your language and ask for an interpreter', 'For non-urgent medical problems, call your <strong>GP</strong> or the <strong>HSE</strong>' ) );
$c .= g_faq( array( 'Which number should I call in an emergency?' => 'Call 112 or 999. Both work and are free.', 'What is the Eircode?' => 'It is the Irish postcode. Every address has a unique 7-character Eircode. You can look up the Eircode for an address on eircode.ie.' ) ) . "\n\n";
$c .= g_src( array( 'HSE - Emergency Services' => 'https://www.hse.ie/eng/services/list/4/healthservices/emergency/', 'Citizens Information - Emergency Health Services' => 'https://www.citizensinformation.ie/en/health/health-services/emergency-health-services/', 'Eircode' => 'https://www.eircode.ie/' ) );
	return $c;
}
