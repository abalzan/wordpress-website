<?php
// Civil Legal Aid EN body.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_legalaid(): string {
$c = '';
$c .= g_intro(
	'See how Civil Legal Aid from the Legal Aid Board works, who can apply, how the means test works and what contributions may apply.',
	'/guias/assistencia-juridica-legal-aid-irlanda-brasileiros/',
	'Legal problems are harder when you are still learning the Irish system. The Legal Aid Board provides civil legal advice and representation services for people who meet the financial criteria and the merits test.'
);
$c .= g_audience( 'Brazilians in Ireland with a civil legal matter who want to check access to the Legal Aid Board.' );
$c .= g_steps( array(
	'Check that the matter is within the scope of Civil Legal Aid. Criminal matters are not covered by the Civil Legal Aid Act.',
	'Check your possible financial eligibility. The Board currently states disposable income below €18,000, and also takes assets and circumstances into account.',
	'Use the financial eligibility indicator as a first guide, without treating it as a guarantee.',
	'Prepare proof of income, expenses and assets.',
	'Submit the application online or go to a Law Centre.',
	'Wait for the financial and merits assessment; there may be a waiting list.',
	'Check the contribution that applies and any exemptions.'
) );
$c .= g_links( array(
	'Apply for Civil Legal Aid' => 'https://www.legalaidboard.ie/our-legal-aid-service/apply-now/',
	'Check financial eligibility' => 'https://www.legalaidboard.ie/our-legal-aid-service/apply-now/financial-eligibility-and-merits-test/'
) );
$c .= g_documents( array(
	'Address and PPSN.',
	'Proof of income and benefits.',
	'Proof of expenses.',
	'Information about assets and debts.',
	'Documents relating to the legal problem.'
) );
$c .= g_costs( 'The Board currently states a minimum contribution of €30 for advice, plus contributions for representation depending on the situation. Exemptions and special rules exist.' );
$c .= g_timelines( 'Court deadlines keep running while you seek assistance. Do not let a deadline expire while waiting for a place.' );
$c .= g_pitfalls( array(
	'Confusing the Legal Aid Board with free help for any issue.',
	'Not sending financial evidence.',
	'Letting a court deadline pass.',
	'Assuming the calculator guarantees approval.'
) );
$links = array(
	'Legal Aid Board - Apply Now' => 'https://www.legalaidboard.ie/en/our-legal-aid-service/apply-now/',
	'Legal Aid Board - eligibility' => 'https://www.legalaidboard.ie/our-legal-aid-service/apply-now/financial-eligibility-and-merits-test/',
	'Legal Aid Board - Law Centres' => 'https://www.legalaidboard.ie/contact-us/find-a-law-centre/',
	'Legal Aid Board - waiting times' => 'https://www.legalaidboard.ie/en/our-legal-aid-service/apply-now/waiting-times/',
);
$c .= g_plain_links( 'Official sources', $links );
$c .= g_plain_links( 'Useful links', $links );
$c .= g_faq( array(
	'Can any Brazilian apply?' => 'Nationality by itself neither guarantees nor prevents it. The type of case, your financial situation and the merits test are what matter.',
	'What is the income limit?' => 'The Board currently states disposable income below €18,000, plus asset criteria and the specifics of the case.',
	'Is it always free?' => 'No. In many cases there is a contribution; exemptions exist.',
	'How long does it take?' => 'It varies by Law Centre and there may be a waiting list; the Board publishes statistics.'
) );
$c .= g_check( '18 August 2026', '' );
$c .= g_disclaimer( 'This is not legal advice. In an urgent situation seek qualified assistance immediately.' );
$c .= g_sep();
	return $c;
}
