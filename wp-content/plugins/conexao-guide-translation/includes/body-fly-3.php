<?php
// Autism and air travel EN body, part 3 (what to pack, medication, overload, checklist, FAQ).
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_fly_p3(): string {
$c = '';
$c .= g_h2( 'What to put in the bag?' );
$c .= g_ul( array( 'headphones;', 'ear defenders;', 'a toy or regulating object;', 'a tablet or entertainment device;', 'a charger or power bank, where allowed;', 'suitable food;', 'any medication needed;', 'documents;', 'identification cards;', 'visual resources;', 'contact details.' ) ) . "\n";
$c .= g_h2( 'And medication?' );
$c .= g_p( 'If the person takes medication or has specific dietary needs, check the rules of the airline, the airport and the destination country well in advance. Cork Airport states that, in certain situations, a letter from a GP can help explain needs related to medication or diet during the trip.' ) . "\n";
$c .= g_p( 'This does not replace airport security rules or immigration/customs rules.' ) . "\n";
$c .= g_h2( 'And if there is a meltdown or sensory overload?' );
$c .= g_ol( array( 'move to a quieter place;', 'reduce stimuli;', 'use headphones or another familiar resource;', 'use simple communication;', 'offer a break;', 'avoid too many questions;', 'follow the visual plan;', 'tell the airport team if assistance is needed.' ) );
$c .= g_p( 'There is no universal strategy for every autistic person. Cork Airport emphasises that each person understands and experiences social situations differently and that strategies must be adapted to the individual.' ) . "\n";
$c .= g_h2( 'Checklist before leaving home' );
$c .= g_h3( '2&#8211;3 weeks before' );
$c .= g_ul( array( 'confirm the flight;', 'confirm the assistance services;', 'contact the airline;', 'check the options available at the airport;', 'prepare visual resources;', 'practise the travel sequence;', 'check documents;', 'prepare medication and medical information;', 'book the Sensory Room, where available;', 'request the Important Flyer, if applicable;', 'arrange the Sunflower or another identifier.' ) ) . "\n";
$c .= g_h3( 'A few days before' );
$c .= g_ul( array( 'confirm times;', 'check the baggage rules again;', 'charge devices;', 'prepare the bag;', 'print important documents;', 'review the visual plan.' ) ) . "\n";
$c .= g_h3( 'On the day of travel' );
$c .= g_ul( array( 'arrive with enough time;', 'tell the team if you need assistance;', 'keep comfort resources accessible;', 'follow the visual sequence;', 'allow time for breaks;', 'have a plan for delays.' ) ) . "\n";
$c .= g_h2( 'Note: services can change' );
$c .= g_p( 'Accessibility programmes, availability of rooms, assistance procedures and Fast Track conditions can change. Always confirm directly with the airport, the airline or the responsible organisation before the trip.' ) . "\n";
$c .= g_h2( 'Useful links' );
$links = array(
	'Dublin Airport - Autism &amp; Non-Visible Disabilities' => 'https://www.dublinairport.com/accessibility/autism-non-visible-disabilities',
	'Dublin Airport - Sensory Room' => 'https://www.dublinairport.com/accessibility/sensory-room',
	'Cork Airport - Travelling With Autism' => 'https://www.corkairport.com/at-the-airport/help-support/autism-asd',
	'Aer Lingus - Needs Relating to Autism' => 'https://www.aerlingus.com/support/special-assistance/needs-relating-to-autism/',
	'AsIAm - Autism ID Card' => 'https://asiam.ie/what-we-do/asiam-autism-id-card',
	'ASD Ireland - ID Card' => 'https://www.asdireland.ie/resources/idcard/',
	'Hidden Disabilities Sunflower - Airports Around the World' => 'https://hdsunflower.com/uk/insights/post/airports-around-the-world',
);
$c .= g_plain_links( 'Useful links', $links );
$c .= g_faq( array(
	'Do I need an autism card to travel?' => 'Not necessarily. The cards are a form of identification or proof, but the assistance procedures depend on the airport and the airline.',
	'Does the AsIAm Autism ID Card guarantee Fast Track?' => 'No. The AsIAm card and the Dublin Airport Important Flyer are different things. The free Fast Track described by Dublin Airport is tied to the Important Flyer programme and requires advance booking.',
	'Can I use the Sunflower at the airport?' => 'Dublin Airport takes part in the Hidden Disabilities Sunflower network and states that its staff are trained to recognise the symbol. Cork Airport also provides Sunflower lanyards.',
	'Is the Dublin Airport sensory room free?' => 'Yes. The airport states that the sessions are free, last 60 minutes and must be booked in advance. There are no walk-ins.',
	'Can I just arrive at the airport and ask for assistance?' => 'It is better not to rely on that. Cork Airport recommends booking assistance in advance because there may be a wait if the request is only made on arrival.',
	'Does the airline need to know I am travelling with an autistic person?' => 'It is highly recommended to tell the airline in advance and ask which assistance services are available. Aer Lingus has a specific procedure for needs relating to autism.',
	'Does the Sunflower work in other countries?' => 'The Hidden Disabilities Sunflower network is international and includes many airports, but the kind of support offered varies. Always check the specific airport before travelling.'
) );
$c .= g_h2( 'Last checked' );
$c .= g_p( '<strong>Information checked: 19 August 2026.</strong> Card prices, booking rules, room availability, assistance procedures and Fast Track conditions can change. Always confirm directly with the airport, the airline or the responsible organisation before the trip.' );
$c .= g_p( '<strong>Notice:</strong> this guide is for information and does not constitute personalised medical, legal or travel advice.' );
$c .= g_sep();
return $c;
}
function conexao_guide_en_fly(): string { return conexao_guide_en_fly_p1() . conexao_guide_en_fly_p2() . conexao_guide_en_fly_p3(); }
