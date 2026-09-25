<?php
// Driving licence exchange EN body.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_licence(): string {
$c = '';
$c .= g_h2( 'What is the Irish driving licence?' );
$c .= g_p( 'The Irish driving licence is issued by the <strong>NDLS (National Driver Licence Service)</strong>. Brazilians can drive in Ireland with their Brazilian licence for up to <strong>12 months</strong> after arriving in the country. After that period you must exchange it for an Irish licence.' ) . "\n";
$c .= g_links( array( 'Exchange a foreign licence through the NDLS' => 'https://www.ndls.ie/licensed-driver/exchange-my-foreign-driving-licence.html' ) );
$c .= g_h2( 'Who needs to exchange their licence?' );
$c .= g_p( 'Brazilians who intend to live in Ireland for more than 12 months need to exchange their Brazilian licence for the Irish one. The exchange is possible because Brazil has a <strong>reciprocity agreement</strong> with Ireland.' ) . "\n";
$c .= g_h2( 'What you need to exchange it' );
$c .= g_ul( array( '<strong>Valid Brazilian licence</strong> (it must not be suspended or revoked)', 'Valid <strong>passport</strong>', '<strong>Proof of address in Ireland</strong> (less than 6 months old)', '<strong>PPS Number</strong>', '<strong>Proof of residence in Ireland</strong> (at least 185 days in the year)', '<strong>Certified translation</strong> of your licence into English (if required)' ) ) . "\n";
$c .= g_h2( 'How to exchange it' );
$c .= g_ol( array( 'Complete the online form on the <strong>NDLS</strong> website', 'Book a visit to your nearest <strong>NDLS</strong> centre', 'Bring your original documents', 'Pay the fee', 'Once the application is processed, the licence is sent according to the NDLS procedure; the timeframe can vary' ) ) . "\n";
$c .= g_h2( 'How much does it cost?' );
$c .= g_p( 'Fees and validity periods depend on the licence type and the current NDLS rules. Confirm the amount directly with the official service before you pay.' ) . "\n";
$c .= g_h2( 'Where to apply' );
$c .= g_p( '<strong>Apply for a licence exchange:</strong> ' . g_a( 'NDLS - National Driver Licence Service', 'https://www.ndls.ie/' ) ) . "\n";
$c .= g_know( array( 'The rules on driving with a foreign licence depend on your residence status and on the validity of the licence. Check the current NDLS/RSA rules instead of assuming a universal time limit.', 'After 12 months it is <strong>mandatory</strong> to have an Irish licence', 'If your Brazilian licence expires you cannot renew it in Ireland &#8211; you must exchange it for an Irish one', '<strong>Car insurance</strong> can be more expensive for holders of a foreign licence', 'If you cannot exchange your licence (for example, because it expired), you will need to do the full process: <strong>theory test, lessons and a practical test</strong>' ) );
$c .= g_faq( array( 'Can I drive in Ireland with a Brazilian licence?' => 'Yes, for up to 12 months after you arrive in Ireland. After that you must exchange it for the Irish licence.', 'Do I need a test to exchange my licence?' => 'No. If your licence is valid and Brazil has an agreement with Ireland, you can exchange it without a theory or practical test.', 'How long does it take to receive the licence?' => 'Usually 5 to 10 working days after the application, but it can vary.' ) ) . "\n\n";
$c .= g_src( array( 'NDLS - National Driver Licence Service' => 'https://www.ndls.ie/', 'RSA - Road Safety Authority' => 'https://www.rsa.ie/', 'Citizens Information - Exchanging a Foreign Driving Permit' => 'https://www.citizensinformation.ie/en/travel-and-recreation/motoring/driver-licensing/exchanging-foreign-driving-permit/' ) );
	return $c;
}
