<?php
// Autism and air travel EN body, part 2 (Sunflower, sensory room, Cork, assistance, ID cards).
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_fly_p2(): string {
$c = '';
$c .= g_h2( 'Sunflower at Dublin Airport' );
$c .= g_p( g_a( 'Dublin Airport', 'https://www.dublinairport.com/accessibility/autism-non-visible-disabilities' ) . ' takes part in the <strong>Hidden Disabilities Sunflower</strong> scheme. The airport states that its staff are trained to recognise the symbol and to offer additional support, understanding or more time when needed. The airport advises passengers to use the PRM Assist App to request Sunflower use.' ) . "\n";
$c .= g_p( g_a( 'Hidden Disabilities Sunflower', 'https://hdsunflower.com/uk/insights/post/airports-around-the-world' ) . ' keeps an international list of participating airports, but the kind of support varies by airport.' ) . "\n";
$c .= g_links( array( 'Dublin Airport - Autism &amp; non-visible disabilities' => 'https://www.dublinairport.com/accessibility/autism-non-visible-disabilities' ) );
$c .= g_h2( 'Sensory room at Dublin Airport' );
$c .= g_p( g_a( 'Dublin Airport - Sensory Room', 'https://www.dublinairport.com/accessibility/sensory-room' ) . ' has Sensory Rooms in both terminals for passengers who may become overwhelmed by the airport environment.' ) . "\n";
$c .= g_ul( array( 'the sessions are free;', 'each session lasts 60 minutes;', 'there is no walk-in service;', 'the rooms are after security;', 'there is one room in Terminal 1, near the Gates 200;', 'there is one room in Terminal 2, near the Gates 400.' ) );
$c .= g_p( 'The room must be booked in advance. If you have already booked assistance, an OCS agent can help during security and accompany the passenger to the room and then to the gate.' ) . "\n";
$c .= g_h2( 'Cork Airport: Sunflower Lanyard' );
$c .= g_p( g_a( 'Cork Airport - Autism / ASD', 'https://www.corkairport.com/at-the-airport/help-support/autism-asd' ) . ' offers support for passengers with non-visible disabilities. The airport states that passengers with autism can use a <strong>Sunflower Lanyard</strong>, available free of charge at the OCS desk in the terminal.' ) . "\n";
$c .= g_links( array( 'Cork Airport - Autism/ASD support' => 'https://www.corkairport.com/at-the-airport/help-support/autism-asd' ) );
$c .= g_h2( 'Cork Airport: Sensory Pod' );
$c .= g_p( 'Cork Airport has a sensory pod for passengers with non-visible disabilities. The space is free, must be booked, and it is recommended to request it in advance; the airport recommends around 48 hours where possible.' ) . "\n";
$c .= g_h2( 'Assistance to move through the airport' );
$c .= g_p( 'If the person needs help to move through the airport, request assistance in advance. At Cork Airport the service can help from arrival at the airport to boarding. The airport recommends booking through the airline or the responsible agent, because asking for assistance only on arrival can mean waiting.' ) . "\n";
$c .= g_p( '<strong>Identification and assistance are not necessarily the same thing.</strong> A Sunflower lanyard does not replace an assistance booking.' ) . "\n";
$c .= g_links( array( 'Request special assistance with Aer Lingus' => 'https://www.aerlingus.com/support/special-assistance/needs-relating-to-autism/' ) );
$c .= g_h2( 'Autism ID cards' );
$c .= g_p( 'There are different identification cards available in Ireland and they are not equivalent.' ) . "\n";
$c .= g_h3( 'AsIAm Autism ID Card' );
$c .= g_p( g_a( 'AsIAm', 'https://asiam.ie/what-we-do/asiam-autism-id-card' ) . ' offers an Autism ID Card. The organisation currently states a cost of €27.50, inclusion of a lanyard and holder, processing of up to eight weeks, validity of three years for children and five years for adults, plus formal proof of diagnosis and a photograph.' ) . "\n";
$c .= g_p( 'AsIAm itself explains that the card serves as proof of diagnosis and can be shown to service providers, but it does not automatically guarantee discounts, preferential treatment or benefits. Any support depends on the service used.' ) . "\n";
$c .= g_h3( 'ASD Ireland ID Card' );
$c .= g_p( g_a( 'ASD Ireland', 'https://www.asdireland.ie/resources/idcard/' ) . ' also has its own card. The organisation currently states a cost of €20 and validity of two years, and requires clinical documentation proving the diagnosis.' ) . "\n";
$c .= g_p( 'This card is an ASD Ireland programme of its own and should not be confused with the AsIAm card or the Dublin Airport Important Flyer.' ) . "\n";
return $c;
}
