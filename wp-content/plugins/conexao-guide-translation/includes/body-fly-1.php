<?php
// Autism and air travel EN body, part 1 (intro, audience, airline, preparation, Dublin).
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_fly_p1(): string {
$c = '';
$c .= g_h2( 'Autism and air travel in Ireland: a guide for Dublin and Cork Airport' );
$c .= g_p( 'Flying can be particularly challenging for some autistic people because of queues, noise, lighting, changes of routine, security, waiting, communication and unfamiliar environments.' ) . "\n";
$c .= g_p( 'Dublin Airport and Cork Airport offer specific resources for passengers with autism and other non-visible disabilities, and some airlines also have preparation programmes and materials.' ) . "\n";
$c .= '<!-- wp:quote --><blockquote class="wp-block-quote is-layout-flow wp-block-quote-is-layout-flow"><p><strong>Important:</strong> airport and airline services, requirements and procedures can change. Always confirm the information directly with the airport and the airline before each trip.</p></blockquote><!-- /wp:quote -->' . "\n";
$c .= g_links( array(
	'Accessibility/autism at Dublin Airport' => 'https://www.dublinairport.com/accessibility/autism-non-visible-disabilities',
	'Autism support at Cork Airport' => 'https://www.corkairport.com/at-the-airport/help-support/autism-asd'
) );
$c .= g_h2( 'Who is this guide for?' );
$c .= g_ul( array( 'autistic adults;', 'parents of autistic children;', 'Brazilians travelling between Ireland and Brazil;', 'families who have never flown with an autistic person;', 'passengers with other non-visible disabilities;', 'people who may need more time or a different way of communicating during the journey.' ) ) . "\n";
$c .= g_h2( 'First of all: tell the airline' );
$c .= g_p( 'One of the most important recommendations is to contact the airline before the trip. ' . g_a( 'Aer Lingus', 'https://www.aerlingus.com/support/special-assistance/needs-relating-to-autism/' ) . ' has a specific area for needs relating to autism and provides an assistance request form.' ) . "\n";
$c .= g_p( 'If the flight is not operated by Aer Lingus, do not assume the same services will be available. Check directly with the airline operating the flight.' ) . "\n";
$c .= g_h2( 'Prepare the person before the trip' );
$c .= g_p( 'Making the process predictable can help. Cork Airport recommends preparing the person well in advance using visual resources, photographs and explanations of each step of the journey.' ) . "\n";
$c .= g_ol( array( 'leaving home;', 'arriving at the airport;', 'checking in;', 'handing over the bag;', 'going through security;', 'finding the gate;', 'waiting;', 'boarding;', 'sitting on the plane;', 'taking off;', 'arriving at the destination;', 'leaving the airport.' ) ) . "\n";
$c .= g_h2( 'Visit the airport before the trip' );
$c .= g_p( 'If possible, getting to know the airport before the day of the flight can help. ' . g_a( 'Cork Airport', 'https://www.corkairport.com/at-the-airport/help-support/autism-asd' ) . ' recommends a prior visit to familiarise the person with the environment, including the movement and the noise.' ) . "\n";
$c .= g_h2( 'Use visual resources' );
$c .= g_p( 'Photographs and symbols can help explain what will happen. Cork Airport recommends visual guides for situations such as check-in, security, queues, the gate, boarding and waiting. Aer Lingus also offers visual guides for the different stages of the journey.' ) . "\n";
$c .= g_h2( 'Important Flyer &#8212; Dublin Airport' );
$c .= g_p( g_a( 'Dublin Airport', 'https://www.dublinairport.com/accessibility/autism-non-visible-disabilities' ) . ' has a programme called <strong>Important Flyer</strong> for passengers with autism or a Sensory Processing Disorder. The identifier can be a lanyard or a wristband, and it discreetly signals to staff that the passenger may need more time, clear communication, a calmer environment or additional understanding.' ) . "\n";
$c .= g_h3( 'How to apply' );
$c .= g_ol( array( 'complete the Important Flyer form;', 'attach a one-page letter from a GP or an AsIAm card confirming an autism or SPD diagnosis;', 'make a separate application for each person who needs the identifier;', 'provide the Eircode.' ) );
$c .= g_p( 'Dublin Airport states that Important Flyer delivery is available to postal addresses in Ireland, including Northern Ireland, and that there is currently no airport collection option.' ) . "\n";
$c .= g_h2( 'Important Flyer and Fast Track' );
$c .= g_p( 'Dublin Airport states that Fast Track must be booked in advance. Up to four Fast Track passes can be free when the programme conditions are met. The Important Flyer holder must be travelling; the airport states that up to three companions may travel with the holder using this benefit on the same day.' ) . "\n";
$c .= g_p( '<strong>Do not confuse:</strong> having an autism card is not the same as taking part in the Important Flyer programme. You have to follow the airport&#8217;s procedure and make the booking where it applies.' ) . "\n";
return $c;
}
