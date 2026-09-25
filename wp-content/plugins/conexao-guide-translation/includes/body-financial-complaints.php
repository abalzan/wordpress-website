<?php
// Financial complaints EN body.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_financial_complaints(): string {
$c = '';
$c .= g_intro(
	'Learn how to check that a financial firm is authorised in Ireland and how to complain about a bank, insurer, credit provider or other financial service.',
	'/guias/reclamar-banco-seguro-servico-financeiro-irlanda/',
	'Problems with a bank, insurer, credit, investment or payments can be hard in another language. The Central Bank of Ireland keeps the register of authorised firms, and the FSPO offers an independent route for eligible complaints.'
);
$c .= g_audience( 'Brazilians who use banks, insurance, credit, investments, payments or other financial services in Ireland.' );
$c .= g_steps( array(
	'Before you sign up, look the firm up in the Central Bank Register of Authorised Firms.',
	'Confirm the legal name, because a firm may trade under a different name.',
	'If there is a problem, make a formal complaint directly to the firm: firms must have a complaints procedure.',
	'Keep the contract, statements, emails, letters, receipts and call logs.',
	'If the reply does not resolve it, check whether the case can go to the FSPO.',
	'Use the FSPO form and attach the relevant documents and the final response letter where applicable.',
	'If you suspect an unauthorised firm, report it to the Central Bank of Ireland.'
) );
$c .= g_documents( array( 'Contract/policy.', 'Statements and receipts.', 'Communications with the firm.', 'Final Response Letter where applicable.', 'Account or policy details.' ) );
$c .= g_costs( 'The FSPO states that its service is free. The Central Bank register is also public and free.' );
$c .= g_timelines( 'The FSPO states that the firm can take up to 40 working days in its internal process. There are time limits for referring a case to the Ombudsman: check them before you wait.' );
$c .= g_pitfalls( array(
	'Sending money without checking authorisation.',
	'Complaining without keeping evidence.',
	'Skipping the formal complaint to the provider.',
	'Confusing an individual complaint with a systemic regulatory report.'
) );
$links = array(
	'Central Bank - complaints' => 'https://www.centralbank.ie/contact-us/complaints-against-a-financial-service-provider',
	'Central Bank - registers' => 'https://registers.centralbank.ie/',
	'Central Bank - authorised firms' => 'https://www.centralbank.ie/consumer-hub/explainers/why-is-it-important-to-deal-with-an-authorised-financial-service-provider',
	'FSPO - complain to your provider' => 'https://www.fspo.ie/make-a-complaint/how-to-make-a-complaint-to-your-provider/financial-services-provider/',
	'FSPO - complaint form' => 'https://www.fspo.ie/complaint-form.aspx',
);
$c .= g_plain_links( 'Official sources', $links );
$c .= g_plain_links( 'Useful links', $links );
$c .= g_faq( array(
	'How do I check whether a firm is authorised?' => 'Search the Register of Authorised Firms and confirm the legal name.',
	'Can I complain to the Central Bank to get my money back?' => 'Generally not. For individual complaints the normal route is the firm and then, where applicable, the FSPO.',
	'Does the FSPO charge?' => 'The FSPO states that the service is free.',
	'Do I need to keep evidence?' => 'Yes. The FSPO recommends documents such as contracts, statements, emails and letters.'
) );
$c .= g_links( array(
	'Complain to your financial provider first' => 'https://www.fspo.ie/make-a-complaint/how-to-make-a-complaint-to-your-provider/',
	'Make a complaint to the FSPO' => 'https://www.fspo.ie/make-a-complaint/how-to-make-a-complaint-to-the-fspo/'
) );
$c .= g_check( '18 August 2026', '' );
$c .= g_disclaimer( 'For information only; it does not replace financial or legal advice.' );
$c .= g_sep();
	return $c;
}
