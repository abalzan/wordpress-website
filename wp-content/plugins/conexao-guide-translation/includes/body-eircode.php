<?php
// Eircode EN body.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_eircode(): string {
$c = '';
$c .= g_h2( 'What is the Eircode?' );
$c .= g_p( 'The <strong>Eircode</strong> is the Irish postcode. It is a unique 7-character code (e.g. D01 F5P2) assigned to every address in Ireland. It was introduced in 2015 and is essential for deliveries, emergencies and services.' ) . "\n";
$c .= g_h2( 'Who needs an Eircode?' );
$c .= g_p( 'All residents and businesses in Ireland have an Eircode. You need it to:' );
$c .= g_ul( array( 'Receive mail and parcels', 'Give your address on public services', 'Emergencies (112/999)', 'Register with services (banks, doctors, etc.)' ) ) . "\n";
$c .= g_h2( 'How to find your Eircode' );
$c .= g_ol( array( 'Go to the <strong>eircode.ie</strong> website', 'Type your address in the search box', 'Find the matching Eircode' ) ) . "\n";
$c .= g_links( array( 'Find an Eircode' => 'https://www.eircode.ie/' ) );
$c .= g_h2( 'Where to find it' );
$c .= g_p( '<strong>Find an Eircode:</strong> ' . g_a( 'Eircode', 'https://www.eircode.ie/' ) ) . "\n";
$c .= g_know( array( 'Every address has a <strong>unique</strong> Eircode', 'It is <strong>free</strong> to look up an Eircode', 'Use your Eircode on <strong>all</strong> correspondence and forms', 'In an emergency, giving your Eircode helps services find you quickly' ) );
$c .= g_faq( array( 'What is the Eircode?' => 'It is the Irish postcode: a unique 7-character code for each address in Ireland.', 'How do I find my Eircode?' => 'Go to eircode.ie and search for your address. The Eircode is displayed.' ) ) . "\n\n";
$c .= g_src( array( 'Eircode' => 'https://www.eircode.ie/', 'Citizens Information - Postal Services' => 'https://www.citizensinformation.ie/en/consumer/phones-internet-tv-and-postal-services/postal-services/' ) );
	return $c;
}
