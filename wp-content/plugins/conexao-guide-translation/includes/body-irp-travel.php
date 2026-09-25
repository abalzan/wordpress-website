<?php
// Travelling with IRP EN body.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_irp_travel(): string {
$c = '';
$c .= g_intro(
	'Understand when the IRP lets you return to Ireland without a re-entry visa, what to do if the card is lost or expired, and what to check before travelling.',
	'/guias/viajar-fora-irlanda-com-irp-brasileiros/',
	'For Brazilians living in Ireland, travelling raises two questions: can I enter the destination, and can I come back to Ireland? The IRP answers only part of it. The destination&#8217;s own rules must be checked separately.'
);
$c .= g_audience( 'Brazilians and other non-EU/EEA citizens who already hold a registered residence permission in Ireland and plan to travel internationally.' );
$c .= g_steps( array(
	'Check the validity of your IRP before travelling. For a visa-national who holds a valid IRP, the ISD states that no re-entry visa is needed to return to Ireland.',
	'Check the entry rules of the destination country: an Irish IRP does not guarantee entry to another country.',
	'Check your passport, your name and any other documents used for the trip.',
	'If the IRP is lost, stolen, has not arrived yet or has an important error, check the ISD emergency procedure before travelling.',
	'Do not count on being able to request a re-entry visa after you leave: the ISD states that the emergency procedure must be dealt with before travelling.',
	'Keep digital copies of your documents and confirm the airline and destination requirements.'
) );
$c .= g_links( array( 'Irish Immigration Service' => 'https://www.irishimmigration.ie/' ) );
$c .= g_documents( array( 'Valid passport.', 'Valid IRP, where applicable.', 'Proof of your permission and of the travel, if needed.', 'Documents required by the destination country.' ) );
$c .= g_costs( 'A normal return with a valid IRP does not require a re-entry visa where the ISD rule applies. Emergency procedures or specific visas may have their own rules.' );
$c .= g_timelines( 'Do this check before you buy or confirm the trip, especially if your IRP is close to expiring.' );
$c .= g_pitfalls( array(
	'Confusing the right to return to Ireland with the right to enter the destination.',
	'Travelling with an expired IRP or without the card when it is required.',
	'Assuming that a renewal request automatically replaces the physical card for travel.'
) );
$links = array(
	'ISD - Travel and Re-Entry Visas' => 'https://www.irishimmigration.ie/registering-your-immigration-permission/travel-and-re-entry-visas/',
	'ISD - Registration FAQ' => 'https://www.irishimmigration.ie/registering-your-immigration-permission/frequently-asked-questions-for-registration/',
	'Department of Foreign Affairs - Travel Advice' => 'https://www.dfa.ie/travel/travel-advice/',
);
$c .= g_plain_links( 'Official sources', $links );
$c .= g_plain_links( 'Useful links', $links );
$c .= g_faq( array(
	'Do I need a visa to come back to Ireland?' => 'With a valid IRP, the ISD states that a re-entry visa is not needed to return to Ireland.',
	'Does the IRP let me enter any country?' => 'No. An Irish IRP does not guarantee entry to another country: you must meet that country&#8217;s own entry rules.',
	'What if my IRP card was stolen while I am abroad?' => 'Contact the ISD before travelling to find out the emergency procedure that applies.'
) );
$c .= g_check( '18 August 2026', '' );
$c .= g_disclaimer( 'For information only; it does not replace immigration or legal advice.' );
$c .= g_sep();
	return $c;
}
