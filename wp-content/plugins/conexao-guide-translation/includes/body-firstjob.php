<?php
// First job / Emergency Tax EN body.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_firstjob(): string {
$c = '';
$c .= g_intro(
	'Just started your first job in Ireland? See how to register the job with Revenue, use myAccount, avoid Emergency Tax and check your Tax Credit Certificate.',
	'/guias/primeiro-emprego-irlanda-emergency-tax/',
	'Your first payslip can carry more tax than expected when Revenue cannot yet give your employer the correct Revenue Payroll Notification (RPN). This is called Emergency Tax.'
);
$c .= g_audience( 'Brazilians in their first job in Ireland, especially those who have just obtained their PPSN and have not yet registered their first job with Revenue.' );
$c .= g_steps( array(
	'Have your PPSN.',
	'Create or access Revenue myAccount.',
	'In PAYE Services, use Add Job or Pension Details to register the first job.',
	'Have your PPSN, the employer&#8217;s Tax Registration Number and the start date to hand.',
	'Give your PPSN to the employer and check that the job appears in myAccount.',
	'Check the Tax Credit Certificate.',
	'If Emergency Tax applied, follow Revenue&#8217;s instructions; once the correct RPN is available, amounts overpaid can be corrected through payroll under the applicable rules.'
) );
$c .= g_documents( array( 'PPSN.', 'Employer details and Tax Registration Number.', 'Start date.', 'An active myAccount.', 'Tax Credit Certificate, when available.' ) );
$c .= g_costs( 'There is no fee to register a job in myAccount.' );
$c .= g_timelines( 'Register as soon as possible, ideally before the first payment.' );
$c .= g_pitfalls( array(
	'Assuming the employer always registers the first job.',
	'Not giving the employer your PPSN.',
	'Not checking the TCC and the jobs listed in myAccount.',
	'Confusing Emergency Tax with a fine.'
) );
$links = array(
	'Revenue - Starting your first job' => 'https://www.revenue.ie/en/jobs-and-pensions/starting-your-first-job/index.aspx',
	'Revenue - what to do' => 'https://www.revenue.ie/en/jobs-and-pensions/starting-your-first-job/what-you-should-do.aspx',
	'Revenue - Emergency Tax' => 'https://www.revenue.ie/en/jobs-and-pensions/emergency-tax/index.aspx',
	'Revenue - getting off Emergency Tax' => 'https://www.revenue.ie/en/jobs-and-pensions/emergency-tax/getting-off-emergency-tax.aspx',
);
$c .= g_plain_links( 'Official sources', $links );
$c .= g_plain_links( 'Useful links', $links );
$c .= g_faq( array(
	'Do I need to register every job in myAccount?' => 'For the first job in the State, Revenue advises the worker to register it. For later jobs the employer normally reports the employment.',
	'What happens if I do not register?' => 'The employer may not be able to obtain the required RPN and may apply Emergency Tax.',
	'When does the TCC appear?' => 'Revenue states that after registration the TCC can be available within up to two working days.',
	'Is Emergency Tax refunded?' => 'Amounts overpaid can be corrected once the correct RPN is available, under Revenue&#8217;s rules.'
) );
$c .= g_links( array(
	'Register your first job in Revenue myAccount' => 'https://www.revenue.ie/en/jobs-and-pensions/starting-your-first-job/index.aspx',
	'Official guidance on getting off Emergency Tax' => 'https://www.revenue.ie/en/jobs-and-pensions/emergency-tax/getting-off-emergency-tax.aspx'
) );
$c .= g_check( '18 August 2026', '' );
$c .= g_disclaimer( 'For information only; it does not replace tax or legal advice.' );
$c .= g_sep();
	return $c;
}
