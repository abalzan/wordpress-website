<?php
// Buying a car EN body.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_car(): string {
$c = '';
$c .= g_h2( 'What do you need to buy a car in Ireland?' );
$c .= g_p( 'Buying a car in Ireland involves several steps: choosing the vehicle, checking its history, transferring ownership, paying the motor tax and getting insurance. This guide explains the process.' ) . "\n";
$c .= g_h2( 'Who can buy a car?' );
$c .= g_p( 'Anyone aged 18 or over with a valid driving licence can buy a car in Ireland. You do not need to be an Irish citizen.' ) . "\n";
$c .= g_h2( 'What you need' );
$c .= g_ul( array( 'A valid <strong>driving licence</strong> (Irish or foreign)', '<strong>Identity document</strong>', '<strong>Proof of address</strong>', '<strong>Car insurance</strong> (compulsory in Ireland)', '<strong>Motor Tax</strong> (the annual vehicle tax)' ) ) . "\n";
$c .= g_h2( 'How to buy' );
$c .= g_ol( array( 'Set your budget (including insurance, tax and maintenance)', 'Search on sites such as <strong>DoneDeal</strong> and <strong>CarsIreland</strong>, or at dealerships', 'Check the vehicle history (mileage, accidents, etc.)', 'Take a test drive', 'Check that the car has a valid <strong>NCT</strong> (roadworthiness test)', 'Complete the change of ownership', 'Pay the <strong>Motor Tax</strong> and take out <strong>insurance</strong>' ) ) . "\n";
$c .= g_links( array( 'Official information on VRT - Revenue' => 'https://www.revenue.ie/en/vrt/index.aspx' ) );
$c .= g_h2( 'What is the NCT?' );
$c .= g_p( 'The <strong>NCT (National Car Test)</strong> is the compulsory roadworthiness test for cars over 4 years old. A car must pass the NCT to be driven legally. The inspection checks brakes, tyres, lights, emissions, etc.' ) . "\n";
$c .= g_h2( 'What is Motor Tax?' );
$c .= g_p( '<strong>Motor Tax</strong> is the annual tax to keep a vehicle on the road. The amount varies with engine size and CO2 emissions. You can pay online on the <strong>motortax.ie</strong> website.' ) . "\n";
$c .= g_links( array( 'Pay Motor Tax online' => 'https://www.motortax.ie/' ) );
$c .= g_know( array( '<strong>Car insurance</strong> is compulsory in Ireland', 'The <strong>NCT</strong> is compulsory for cars over 4 years old', '<strong>Motor Tax</strong> must be paid every year', 'Check that the car has no <strong>pending fines</strong> or motor tax debt', 'Consider the cost of insurance - it can be high for new arrivals with no Irish driving history' ) );
$c .= g_faq( array( 'Do I need an Irish licence to buy a car?' => 'Not necessarily. You can buy a car with a valid foreign licence, but you need insurance and motor tax. To drive for more than 1 year in Ireland you must exchange your licence for an Irish one.', 'What is the Eircode?' => 'The Eircode is the Irish postcode (7 characters, e.g. D01 F5P2). Every address in Ireland has a unique Eircode. You can look up the Eircode for an address on eircode.ie.' ) ) . "\n\n";
$c .= g_src( array( 'NCT - National Car Test' => 'https://www.ncts.ie/', 'Motor Tax Online' => 'https://www.motortax.ie/', 'RSA - Road Safety Authority' => 'https://www.rsa.ie/', 'Citizens Information - Buying a Vehicle' => 'https://www.citizensinformation.ie/en/travel-and-recreation/motoring/buying-or-selling-a-vehicle/' ) );
	return $c;
}
