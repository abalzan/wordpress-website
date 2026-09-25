<?php
// NCT + motor tax EN bodies.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_nct(): string {
$c = '';
$c .= g_h2( 'What is the NCT?' );
$c .= g_p( 'The <strong>NCT (National Car Test)</strong> is the compulsory roadworthiness inspection for vehicles in Ireland. It is run by the <strong>RSA (Road Safety Authority)</strong> and checks the safety and emissions of the vehicle.' ) . "\n";
$c .= g_h2( 'Who needs an NCT?' );
$c .= g_p( 'Cars over <strong>4 years old</strong> need an NCT. The inspection is compulsory and the vehicle must pass to be driven legally.' ) . "\n";
$c .= g_links( array( 'Book or manage an NCT' => 'https://www.ncts.ie/bookings/', 'NCT - official website' => 'https://www.ncts.ie/' ) );
$c .= g_h2( 'When to do it' );
$c .= g_ul( array( 'Cars aged 4-9 years: NCT every <strong>2 years</strong>', 'Cars aged 10+ years: NCT <strong>every year</strong>' ) ) . "\n";
$c .= g_h2( 'What is checked' );
$c .= g_ul( array( 'Brakes', 'Tyres', 'Lights', 'Emissions', 'Steering', 'Suspension', 'Bodywork' ) ) . "\n";
$c .= g_h2( 'How much does it cost?' );
$c .= g_p( 'The NCT fee is <strong>€55</strong> for cars (full inspection). A retest costs <strong>€28</strong>.' ) . "\n";
$c .= g_h2( 'Where to do it' );
$c .= g_p( '<strong>Book an NCT:</strong> ' . g_a( 'NCT - National Car Test', 'https://www.ncts.ie/' ) ) . "\n";
$c .= g_know( array( 'Driving without a valid NCT is <strong>illegal</strong> and can result in a fine', 'A valid NCT is required to <strong>renew the motor tax</strong>', 'If the car fails you have a deadline to fix the faults and get a retest', 'The NCT certificate is valid for 1 or 2 years, depending on the age of the car' ) );
$c .= g_faq( array( 'Do I need an NCT to buy a car?' => 'It is not compulsory to buy, but the car needs a valid NCT to be driven legally. Check that the car has an NCT before you buy.', 'How much does the NCT cost?' => 'A full inspection costs €55. A retest costs €28.' ) ) . "\n\n";
$c .= g_src( array( 'NCT - National Car Test' => 'https://www.ncts.ie/', 'RSA - Road Safety Authority' => 'https://www.rsa.ie/', 'Citizens Information - NCT' => 'https://www.citizensinformation.ie/en/travel-and-recreation/motoring/buying-or-selling-a-vehicle/national-car-test/' ) );
	return $c;
}
function conexao_guide_en_motortax(): string {
$c = '';
$c .= g_h2( 'What is Motor Tax?' );
$c .= g_p( '<strong>Motor Tax</strong> is the annual road tax on vehicles in Ireland. Every vehicle registered in Ireland must have its motor tax paid to be driven legally.' ) . "\n";
$c .= g_links( array( 'Motor Tax Online' => 'https://www.motortax.ie/' ) );
$c .= g_h2( 'Who has to pay?' );
$c .= g_p( 'All owners of vehicles registered in Ireland must pay motor tax every year. The amount depends on the type of vehicle, engine size and CO2 emissions.' ) . "\n";
$c .= g_h2( 'How to pay' );
$c .= g_ol( array( 'Go to the <strong>motortax.ie</strong> website', 'Enter the vehicle registration number', 'Check the vehicle details', 'Choose the period (3, 6 or 12 months)', 'Pay by credit/debit card', 'Receive the receipt' ) ) . "\n";
$c .= g_h2( 'How much does it cost?' );
$c .= g_p( 'The motor tax amount depends on the vehicle. For cars it is based on CO2 emissions, so lower-emission cars pay less. Amounts range from roughly <strong>€180 to €2,400</strong> per year.' ) . "\n";
$c .= g_h2( 'Where to pay' );
$c .= g_p( '<strong>Pay Motor Tax:</strong> ' . g_a( 'Motor Tax Online', 'https://www.motortax.ie/' ) ) . "\n";
$c .= g_know( array( 'Driving without motor tax is <strong>illegal</strong> and can result in a fine', 'You need a <strong>valid NCT</strong> to renew the motor tax', 'Motor tax can be paid for <strong>3, 6 or 12 months</strong>', 'Paying for 12 months is cheaper than paying for 3 months' ) );
$c .= g_faq( array( 'Do I need an NCT to pay motor tax?' => 'Yes, for cars over 4 years old you need a valid NCT to renew the motor tax.', 'How much does motor tax cost?' => 'The amount depends on the vehicle. For cars it is based on CO2 emissions. Check the exact amount on motortax.ie.' ) ) . "\n\n";
$c .= g_src( array( 'Motor Tax Online' => 'https://www.motortax.ie/', 'Citizens Information - Motor Tax Rates' => 'https://www.citizensinformation.ie/en/travel-and-recreation/motoring/motor-tax-and-insurance/motor-tax-rates/' ) );
	return $c;
}
