<?php
// Leap Card EN body.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_leapcard(): string {
$c = '';
$c .= g_intro(
	'Learn how the TFI Leap Card works, where to use it, how to top it up, what fare caps exist and what to do if you lose the card.',
	'/guias/leap-card-irlanda-como-usar/',
	'For people who use buses, the Luas, DART and other services, the TFI Leap Card is a practical way to pay for journeys and can offer lower fares than cash in many situations.'
);
$c .= g_audience( 'Brazilians living, studying or working in Ireland who use public transport regularly.' );
$c .= g_steps( array(
	'Choose the type of Leap Card that suits your situation.',
	'Buy the card through an official channel and, where possible, register it.',
	'Add Travel Credit or the appropriate product.',
	'Use the card according to the service&#8217;s tap on/tap off rules.',
	'Check fares, zones and fare capping on the current TFI page.',
	'Register the card to make replacement or refunds easier where allowed.',
	'Use the app/online account to check your balance and transactions.'
) );
$c .= g_documents( array(
	'For a standard adult card there is normally no complex documentation.',
	'Discounted cards have specific eligibility and evidence requirements.'
) );
$c .= g_costs( 'Fares depend on the passenger, zone and service. TFI keeps the current fare tables: avoid publishing figures without checking the page on the day you publish.' );
$c .= g_timelines( 'There is no general deadline; validity rules depend on the product.' );
$c .= g_pitfalls( array(
	'Not registering the card.',
	'Assuming every line has the same fare or capping.',
	'Confusing Adult, Young Adult and Student cards.',
	'Using old fares from third parties.'
) );
$links = array(
	'TFI - Leap Card' => 'https://www.transportforireland.ie/fares/leap-card/',
	'TFI - Fare Zones' => 'https://www.transportforireland.ie/fares/new-fare-zones/',
	'TFI - Bus Fares' => 'https://www.transportforireland.ie/fares/bus-fares/',
);
$c .= g_plain_links( 'Official sources', $links );
$c .= g_plain_links( 'Useful links', $links );
$c .= g_faq( array(
	'Where can I use the Leap Card?' => 'TFI states that it works on many services in the network, including buses, the Luas, DART and some rail services.',
	'Is it worth registering?' => 'Yes. TFI recommends registering the card because unregistered cards cannot be replaced or refunded if lost.',
	'Is there a daily cap?' => 'Yes, in certain zones and services. TFI applies fare capping.',
	'Do all cards have the same discounts?' => 'No. There are different types, such as Adult, Young Adult/Student and Child, with their own rules.'
) );
$c .= g_links( array(
	'Check and order a Leap Card' => 'https://www.leapcard.ie/',
	'Check TFI fares and zones' => 'https://www.transportforireland.ie/fares/leap-card/'
) );
$c .= g_check( '18 August 2026', '' );
$c .= g_disclaimer( 'For information only; it does not replace official TFI or legal advice.' );
$c .= g_sep();
	return $c;
}
