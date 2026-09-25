<?php
// First IRP registration EN body.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_irp_first(): string {
$c = '';
$c .= g_intro(
	'See how the first IRP registration works for Brazilians and other non-EU/EEA citizens, which documents to prepare, what it costs and where to follow the process.',
	'/guias/primeira-inscricao-irp-irlanda-brasileiros/',
	'If you are Brazilian and have come to Ireland to work, study, live or join family for more than 90 days, registering your immigration permission for the first time is one of the most important steps of your arrival. The current process is carried out by the Immigration Service Delivery (ISD).'
);
$c .= g_links( array( 'Official information for first-time registration' => 'https://www.irishimmigration.ie/registering-your-immigration-permission/how-to-register-your-immigration-permission-for-the-first-time/information-on-registering-your-immigration-permission-for-the-first-time/' ) );
$c .= g_audience( 'Brazilians and other citizens from outside the EU/EEA and Switzerland who need to register a residence permission in the Republic of Ireland for the first time.' );
$c .= g_steps( array(
	'Check whether you need to register: the ISD states that people from outside the EU/EEA and Switzerland who come to work, study, live or join family for more than 90 days must register their permission.',
	'Create or access your personal account in the ISD Customer Service Portal and use it to book your first appointment.',
	'Prepare the documents specific to your Stamp. The official page lists, among others, a valid passport and proof of your current address; additional documents vary by permission.',
	'Attend the Registration Office at Burgh Quay, Dublin. Your documents are reviewed, a photo and fingerprints are taken and the permission is registered.',
	'Pay the fee where applicable. The standard fee is €300 for many categories, but exemptions exist.',
	'After registration, track the issue of your IRP and keep your passport, address and other details updated with the ISD when needed.'
) );
$c .= g_documents( array(
	'Valid passport, including the biometric page.',
	'Proof of your current address in Ireland.',
	'Documents proving the basis of your permission, such as an enrolment letter, employment contract or family documents, where applicable.',
	'Your appointment details and any additional documents requested by the ISD.'
) );
$c .= g_costs( 'Booking the first appointment is free. The standard registration fee is €300 for many categories; exemptions exist. The official fee table was updated on 17 July 2026.' );
$c .= g_timelines( 'Do not leave the registration until the end of the period in which you are allowed to remain legally in the State. Availability of appointments must be checked on the official portal.' );
$c .= g_pitfalls( array(
	'Paying third parties for an appointment: the ISD warns that valid appointments are made through the Customer Service Portal.',
	'Assuming every Stamp has the same documentation or fee.',
	'Travelling without checking the validity of the IRP and the return rules.'
) );
$links = array(
	'ISD - first-time registration' => 'https://www.irishimmigration.ie/registering-your-immigration-permission/how-to-register-your-immigration-permission-for-the-first-time/information-on-registering-your-immigration-permission-for-the-first-time/',
	'ISD - documents and fees' => 'https://www.irishimmigration.ie/registering-your-immigration-permission/how-to-register-your-immigration-permission-for-the-first-time/required-documents/',
	'ISD - Customer Service Portal' => 'https://www.irishimmigration.ie/customer-service-portal-a-guide-to-using-the-online-self-service-portal/',
);
$c .= g_plain_links( 'Official sources', $links );
$c .= g_plain_links( 'Useful links', $links );
$c .= g_faq( array(
	'Do I have to pay to book the appointment?' => 'No. Booking the first appointment has no fee; the registration fee, where applicable, is a separate step.',
	'Is the fee always €300?' => 'No. €300 is the standard fee for many categories, but exemptions exist.',
	'Can I pay someone to get me an appointment?' => 'No. The ISD states that valid appointments are made through the Customer Service Portal and warns about scams.',
	'Are the IRP and the immigration permission the same thing?' => 'Not exactly. The permission sets the conditions of residence; the IRP is the card that proves the registration.'
) );
$c .= g_links( array(
	'Book your first IRP registration through the ISD service portal' => 'https://www.irishimmigration.ie/customer-service-portal-a-guide-to-using-the-online-self-service-portal/',
	'Official information on first-time IRP registration' => 'https://www.irishimmigration.ie/registering-your-immigration-permission/how-to-register-your-immigration-permission-for-the-first-time/information-on-registering-your-immigration-permission-for-the-first-time/'
) );
$c .= g_check( '18 August 2026', '' );
$c .= g_disclaimer( 'General information; it does not replace individual legal or immigration advice.' );
$c .= g_sep();
	return $c;
}
