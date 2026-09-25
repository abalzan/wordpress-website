<?php
// Sole trader EN body.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_soletrapper(): string {
$c = '';
$c .= g_intro(
	'See how to set up sole trader tax registration in Ireland, when to register for Income Tax, how ROS is used and what to keep in mind.',
	'/guias/sole-trader-autonomo-irlanda-brasileiros/',
	'For freelancers and professionals who work for themselves, the tax route is usually that of the sole trader and self-assessment. This is different from incorporating a limited company.'
);
$c .= g_audience( 'Brazilians in Ireland who intend to provide services on their own account or start a sole activity.' );
$c .= g_steps( array(
	'Confirm that your working relationship really is self-employed.',
	'Have a PPSN: Revenue states that it is needed before the sole trader tax registration.',
	'Register for Income Tax according to your situation, using myAccount or the channel indicated by Revenue.',
	'After registration, use ROS where required for returns and payments.',
	'If you trade under a name different from your own, check the business name registration at the CRO.',
	'Keep records of income, expenses and documents from the start.',
	'Check additional obligations such as VAT or Employer PAYE where they apply.'
) );
$c .= g_documents( array( 'PPSN.', 'Start date and activity details.', 'Expected turnover information.', 'Records of income and expenses.', 'Additional details for VAT/PAYE, if applicable.' ) );
$c .= g_costs( 'Revenue does not present the sole trader tax registration as a set-up fee; other registrations or licences may have their own costs.' );
$c .= g_timelines( 'Returns and payments follow the tax calendar. Self-assessment has annual Pay and File dates: check the year in question.' );
$c .= g_pitfalls( array(
	'Confusing a sole trader with a limited company.',
	'Starting to invoice without checking the tax registration you need.',
	'Not keeping adequate documents and records.',
	'Using thresholds and dates from a previous year.'
) );
$links = array(
	'Revenue - Registering for tax' => 'https://www.revenue.ie/en/starting-a-business/registering-for-tax/index.aspx',
	'Revenue - sole trader' => 'https://www.revenue.ie/en/starting-a-business/registering-for-tax/how-to-register-for-tax-as-a-sole-trader.aspx',
	'Revenue - self-assessment' => 'https://www.revenue.ie/en/self-assessment-and-self-employment/guide-to-self-assessment/register-it-self-assessment.aspx',
	'Companies Registration Office' => 'https://www.cro.ie/',
);
$c .= g_plain_links( 'Official sources', $links );
$c .= g_plain_links( 'Useful links', $links );
$c .= g_faq( array(
	'Do I need a PPSN?' => 'Yes. Revenue states that a PPSN is needed before the sole trader tax registration.',
	'Do I need to use ROS?' => 'After registration, Revenue states that ROS is used for business-related returns and payments where applicable.',
	'Are there income limits for self-assessment?' => 'Yes. Revenue sets criteria and limits for non-PAYE income: check the current figures for the year in question.',
	'Is a sole trader the same as a company?' => 'No. A sole trader is an individual activity; a company is a separate legal entity.'
) );
$c .= g_links( array(
	'Sign in to ROS' => 'https://www.revenue.ie/en/online-services/services/ros/ros-help/getting-started-on-ros/registering-for-ros/index.aspx',
	'Register for Income Tax as a sole trader with Revenue' => 'https://www.revenue.ie/en/starting-a-business/registering-for-tax/how-to-register-for-tax-as-a-sole-trader.aspx'
) );
$c .= g_check( '18 August 2026', '' );
$c .= g_disclaimer( 'For information only; it does not replace tax or legal advice.' );
$c .= g_sep();
	return $c;
}
