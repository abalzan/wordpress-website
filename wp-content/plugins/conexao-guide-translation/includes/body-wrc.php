<?php
// WRC workplace complaint EN body.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_wrc(): string {
$c = '';
$c .= g_intro(
	'Understand how to file a workplace complaint or dispute with the Workplace Relations Commission (WRC) and what to keep before starting the process.',
	'/guias/reclamacao-trabalhista-wrc-irlanda-brasileiros/',
	'Even with a general guide on employment rights, many Brazilians need practical guidance on what to do once the problem has happened. The WRC provides an eComplaint Portal for referring certain complaints and disputes.'
);
$c .= g_audience( 'Brazilians who work or worked in Ireland and need to understand the official route for a workplace complaint.' );
$c .= g_steps( array(
	'Identify which right or obligation may have been breached and check the WRC information.',
	'Collect the contract, payslips, timesheets, messages, emails, internal policies and other documents.',
	'Where possible, try to resolve the issue with the employer and record what was requested and answered.',
	'Use the WRC eComplaint Portal when the matter falls within the WRC&#8217;s remit.',
	'Complete the complaint with facts, dates and relevant documents, without exaggerating or leaving out information.',
	'After submitting, follow the WRC communications. The case may proceed to inspection, conciliation or adjudication depending on the nature of the dispute.'
) );
$c .= g_documents( array( 'Contract of employment.', 'Payslips.', 'Timesheets.', 'Emails and messages.', 'Documents or responses from the employer.' ) );
$c .= g_costs( 'The WRC provides the eComplaint Portal: check the official page to see whether there is any specific cost for your procedure.' );
$c .= g_timelines( 'Complaint time limits vary with the legislation and the type of case. Do not wait until close to the limit; confirm the applicable deadline directly with the WRC.' );
$c .= g_pitfalls( array(
	'Not keeping evidence.',
	'Missing the applicable deadline.',
	'Sending a generic complaint with no dates or facts.',
	'Confusing the WRC with the Labour Court or another body.'
) );
$links = array(
	'WRC - Make a Complaint / Refer a Dispute' => 'https://www.workplacerelations.ie/en/complaints_disputes/refer_a_dispute_make_a_complaint/how_to_make_a_complaint_refer_a_dispute.html',
	'WRC - main website' => 'https://www.workplacerelations.ie/',
);
$c .= g_plain_links( 'Official sources', $links );
$c .= g_plain_links( 'Useful links', $links );
$c .= g_faq( array(
	'Do I have to complain to the employer first?' => 'Not every procedure requires exactly the same step, but trying to resolve it internally can help and should be checked according to the type of complaint.',
	'Can I submit the complaint online?' => 'The WRC provides an eComplaint Portal for the complaints and disputes it covers.',
	'Which evidence should I keep?' => 'The contract, payslips, timesheets, emails, messages and the employer&#8217;s responses are useful examples.',
	'How long do I have?' => 'The time limit depends on the legislation and the type of complaint. Check with the WRC for your case.'
) );
$c .= g_links( array(
	'File a complaint in the WRC eComplaint Portal' => 'https://ecomplaint.workplacerelations.ie/en-IE/',
	'Official guidance on making a complaint' => 'https://www.workplacerelations.ie/en/complaints_disputes/refer_a_dispute_make_a_complaint/how_to_make_a_complaint_refer_a_dispute.html'
) );
$c .= g_check( '18 August 2026', '' );
$c .= g_disclaimer( 'For information only; it does not replace legal advice.' );
$c .= g_sep();
	return $c;
}
