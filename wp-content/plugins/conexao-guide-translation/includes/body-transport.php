<?php
// Public transport EN body.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_transport(): string {
$c = '';
$c .= g_h2( 'What is public transport in Ireland?' );
$c .= g_p( 'Public transport in Ireland is administered by the <strong>National Transport Authority (NTA)</strong> and operated by several companies. The system includes buses, trains (DART, Luas, Intercity) and regional services.' ) . "\n";
$c .= g_h2( 'Who uses public transport?' );
$c .= g_p( 'Public transport is used by residents and visitors all over Ireland. In Dublin the system is the most complete, with buses, Luas (light rail) and DART (coastal train).' ) . "\n";
$c .= g_links( array( 'Check transport fares and services - TFI' => 'https://www.transportforireland.ie/fares/' ) );
$c .= g_h2( 'Main services' );
$c .= g_ul( array( '<strong>Dublin Bus</strong>: buses in Dublin', '<strong>Luas</strong>: light rail in Dublin', '<strong>DART</strong>: coastal train in Dublin', '<strong>Irish Rail</strong>: intercity trains', '<strong>Bus Éireann</strong>: intercity and rural buses', '<strong>Local Link</strong>: rural buses' ) ) . "\n";
$c .= g_h2( 'How to pay' );
$c .= g_ul( array( '<strong>Leap Card</strong>: rechargeable transport card with reduced fares', '<strong>TFI Go</strong>: mobile payment app', 'Cash payment (higher fares)' ) ) . "\n";
$c .= g_h2( 'What is the Leap Card?' );
$c .= g_p( 'The <strong>Leap Card</strong> is Ireland&#8217;s public transport card. You can buy and top it up in stations, participating shops and online. With a Leap Card the fares are cheaper than paying cash.' ) . "\n";
$c .= g_h2( 'Where to buy a Leap Card' );
$c .= g_p( '<strong>Buy a Leap Card:</strong> ' . g_a( 'Leap Card', 'https://www.leapcard.ie/' ) ) . "\n";
$c .= g_links( array( 'Official information about the Leap Card' => 'https://www.transportforireland.ie/fares/leap-card/' ) );
$c .= g_know( array( 'A Leap Card costs <strong>€5</strong> (one-off card fee)', 'You can top it up online, at stations or in participating shops', 'Students may get <strong>discounts</strong> with the Student Leap Card', '<strong>TFI</strong> (Transport for Ireland) is the official transport information portal' ) );
$c .= g_faq( array( 'What is the Leap Card?' => 'It is Ireland&#8217;s rechargeable public transport card. It offers reduced fares on buses, trains and the Luas.', 'Do I need a Leap Card?' => 'It is not compulsory, but fares are cheaper with a Leap Card than paying cash.' ) ) . "\n\n";
$c .= g_src( array( 'Transport for Ireland' => 'https://www.transportforireland.ie/', 'National Transport Authority' => 'https://www.nationaltransport.ie/', 'Leap Card' => 'https://www.leapcard.ie/' ) );
	return $c;
}
