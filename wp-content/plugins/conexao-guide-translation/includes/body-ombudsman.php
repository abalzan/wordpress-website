<?php
// Public service complaints EN body.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_ombudsman(): string {
$c = '';
$c .= g_intro(
	'See how to complain about a government department, local authority or public service in Ireland and when the Office of the Ombudsman can examine the complaint.',
	'/guias/reclamar-servico-publico-irlanda-ombudsman/',
	'If a Brazilian has a problem with an Irish public service, there is a formal route. The Office of the Ombudsman can investigate certain complaints against government departments, local authorities and the HSE.'
);
$c .= g_audience( 'Brazilians who had a problem with a government department, local authority or the HSE and could not resolve it through the normal channel.' );
$c .= g_steps( array(
	'Identify the body that made the decision or provided the service.',
	'First complain directly to the body, explaining what happened and what you expect to be corrected.',
	'Keep references, emails, letters, names, dates and documents.',
	'If the reply does not resolve it, check whether the Ombudsman can handle that type of complaint.',
	'Submit the complaint within the applicable time limit. The Ombudsman states a general rule of 12 months, save for special circumstances.',
	'Do not use the Ombudsman for matters outside its remit, such as many complaints against private companies, banks or An Garda Síochána.'
) );
$c .= g_documents( array( 'Description of the facts.', 'The body&#8217;s response.', 'References and case numbers.', 'Related documents, letters and emails.' ) );
$c .= g_costs( 'No fee is stated for making a complaint to the Ombudsman.' );
$c .= g_timelines( 'Published general rule: 12 months, save for very special circumstances.' );
$c .= g_pitfalls( array(
	'Going straight to the Ombudsman without trying to resolve it with the body.',
	'Waiting more than 12 months without checking whether you can complain.',
	'Sending a complaint without a timeline and evidence.',
	'Using the Ombudsman for an FSPO or WRC matter.'
) );
$links = array(
	'Ombudsman - how to complain' => 'https://www.ombudsman.ie/en/publication/b7cef-how-to-complain-to-a-public-service-provider/',
	'Ombudsman - making a complaint' => 'https://www.ombudsman.ie/en/publication/000df-making-a-complaint/',
);
$c .= g_plain_links( 'Official sources', $links );
$c .= g_plain_links( 'Useful links', $links );
$c .= g_faq( array(
	'Can I go straight to the Ombudsman?' => 'In general, try to resolve it with the public service first.',
	'Does the Ombudsman deal with private companies?' => 'Not in general: the body itself lists several categories that are outside its remit.',
	'Is there a deadline?' => 'The published general rule is 12 months, save for very special circumstances.',
	'Is the HSE included?' => 'Yes. The Ombudsman states that it investigates certain complaints about HSE services.'
) );
$c .= g_links( array( 'Make a complaint to the Ombudsman' => 'https://www.ombudsman.ie/en/publication/000df-making-a-complaint/' ) );
$c .= g_check( '18 August 2026', '' );
$c .= g_disclaimer( 'For information only; it does not replace legal advice.' );
$c .= g_sep();
	return $c;
}
