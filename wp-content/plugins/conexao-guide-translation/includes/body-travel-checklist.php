<?php
// Travel checklist EN body.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_travel_checklist(): string {
$c = '';
$c .= g_intro(
	'A practical checklist for Brazilians resident in Ireland before travelling: passport, IRP, destination, return and emergency documents.',
	'/guias/checklist-viagem-internacional-irlanda-brasileiros-irp/',
	'This guide turns the official travel and return rules into a simple check before departure. It complements the guide on the IRP and travel, with a focus on planning.'
);
$c .= g_audience( 'Brazilians resident in Ireland who are travelling to Brazil, Europe or another country and want to reduce the risk of documentation problems.' );
$c .= g_steps( array(
	'Check the validity of your passport.',
	'Check the validity of your IRP and that the details are correct.',
	'Check the entry rules of the destination on the relevant official source.',
	'Check whether the airline requires any additional documentation.',
	'Save digital copies of your passport, IRP, insurance and bookings.',
	'If the IRP is lost or you have no valid card, contact the ISD before you leave.',
	'After you return, check whether your address, passport or other details changed and need to be reported to the ISD.'
) );
$c .= g_links( array( 'Irish Immigration Service' => 'https://www.irishimmigration.ie/' ) );
$c .= g_documents( array( 'Passport.', 'IRP.', 'Travel insurance, if purchased.', 'Destination entry documents.', 'Travel confirmations and emergency contacts.' ) );
$c .= g_costs( 'There is no cost to do the checklist. Visa, insurance and other document costs depend on the destination.' );
$c .= g_timelines( 'Do the check at least a few days before you travel and again on the eve of departure.' );
$c .= g_pitfalls( array(
	'Leaving the check until the airport.',
	'Assuming the IRP replaces a destination visa.',
	'Travelling without copies of your documents.'
) );
$links = array(
	'ISD - Travel and Re-Entry Visas' => 'https://www.irishimmigration.ie/registering-your-immigration-permission/travel-and-re-entry-visas/',
	'DFA - Travel Advice' => 'https://www.dfa.ie/travel/travel-advice/',
);
$c .= g_plain_links( 'Official sources', $links );
$c .= g_plain_links( 'Useful links', $links );
$c .= g_faq( array(
	'Does the IRP replace a passport?' => 'No. The IRP and the passport have different functions.',
	'Does the IRP guarantee entry to the destination?' => 'No. Check with the destination country.',
	'What do I do if I lose the IRP?' => 'Check the ISD emergency procedure before travelling.',
	'Do I need travel insurance?' => 'The EHIC does not replace travel insurance: for travel, consider cover that matches the destination and your situation.'
) );
$c .= g_links( array(
	'Official rules for travel and re-entry with an IRP' => 'https://www.irishimmigration.ie/registering-your-immigration-permission/travel-and-re-entry-visas/',
	'Department of Foreign Affairs travel advice' => 'https://www.dfa.ie/travel/travel-advice/'
) );
$c .= g_check( '18 August 2026', '' );
$c .= g_disclaimer( 'Informative checklist; always confirm the official rules for your destination and situation.' );
$c .= g_sep();
	return $c;
}
