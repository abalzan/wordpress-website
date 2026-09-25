<?php
// Child Benefit + Social Welfare EN bodies.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_childbenefit(): string {
$c = '';
$c .= g_h2( 'What is Child Benefit?' );
$c .= g_p( '<strong>Child Benefit</strong> is a monthly payment from the Irish government to families with children under 16 (or under 18 if they are in full-time education or have a disability).' ) . "\n";
$c .= g_links( array( 'Apply for Child Benefit on MyWelfare' => 'https://services.mywelfare.ie/topics/parents-children-family/child-benefit/', 'Official information about Child Benefit' => 'https://www.gov.ie/en/department-of-social-protection/services/child-benefit/' ) );
$c .= g_h2( 'Who is entitled?' );
$c .= g_ul( array( '<strong>Habitual residents</strong> in Ireland (living here and intending to stay for at least 1 year)', 'Families with children <strong>under 16</strong>', 'Children <strong>under 18</strong> if they are in full-time education or have a disability', 'The applicant must have a <strong>PPS Number</strong>' ) ) . "\n";
$c .= g_h2( 'How much is it?' );
$c .= g_p( 'Child Benefit is <strong>€140 per month</strong> for each child. Payment is made on the <strong>first working day of each month</strong>.' ) . "\n";
$c .= g_h2( 'How to apply' );
$c .= g_ol( array( 'Sign in to <strong>MyWelfare</strong> with your MyGovID', 'Complete the Child Benefit application form', 'Enter the child&#8217;s details (birth certificate)', 'Submit the application', 'Track the status on MyWelfare' ) ) . "\n";
$c .= g_h2( 'Where to apply' );
$c .= g_p( '<strong>Apply for Child Benefit:</strong> ' . g_a( 'MyWelfare', 'https://www.mywelfare.ie/' ) ) . "\n";
$c .= g_know( array( 'Child Benefit is paid <strong>monthly</strong> on the first working day of the month', 'Payment is made to <strong>one parent</strong> (usually the mother)', 'If you have children in Brazil, you may qualify for Child Benefit if they live with you in Ireland', 'The payment is <strong>not affected</strong> by family income' ) );
$c .= g_faq( array( 'Do I need a visa to receive Child Benefit?' => 'You need to be a habitual resident of Ireland. Having a valid immigration status (such as an Employment Permit) helps prove residence.', 'Does my child have to live in Ireland?' => 'Yes. Child Benefit is paid for children who reside in Ireland with you.' ) ) . "\n\n";
$c .= g_src( array( 'gov.ie - Child Benefit' => 'https://www.gov.ie/en/service/child-benefit/', 'Citizens Information - Child Benefit' => 'https://www.citizensinformation.ie/en/social-welfare/families-and-children/child-benefit/', 'MyWelfare' => 'https://www.mywelfare.ie/' ) );
	return $c;
}
function conexao_guide_en_socialwelfare(): string {
$c = '';
$c .= g_h2( 'What is Social Welfare?' );
$c .= g_p( '<strong>Social Welfare</strong> is the Irish social security system, run by the <strong>Department of Social Protection</strong>. It offers a range of payments for workers, families and people in vulnerable situations.' ) . "\n";
$c .= g_links( array( 'Explore and start Social Welfare claims on MyWelfare' => 'https://services.mywelfare.ie/en/explore-services/', 'Official collection of social payments and services' => 'https://www.gov.ie/en/department-of-social-protection/collections/social-welfare/' ) );
$c .= g_h2( 'Who can access it?' );
$c .= g_p( 'Social Welfare payments are available to <strong>habitual residents</strong> of Ireland. Some payments require PRSI contributions, while others are means-tested (allowances).' ) . "\n";
$c .= g_h2( 'Main payments' );
$c .= g_ul( array( '<strong>Jobseeker&#8217;s Benefit</strong>: unemployment insurance for those who have paid PRSI', '<strong>Jobseeker&#8217;s Allowance</strong>: means-tested unemployment assistance', '<strong>Illness Benefit</strong>: payment if you cannot work for health reasons', '<strong>Maternity Benefit</strong>: paid maternity leave', '<strong>Paternity Benefit</strong>: paid paternity leave', '<strong>Child Benefit</strong>: monthly payment for families with children', '<strong>State Pension</strong>: the state pension', '<strong>Working Family Payment</strong>: income top-up for working families' ) ) . "\n";
$c .= g_h2( 'How to apply' );
$c .= g_ol( array( 'Sign in to <strong>MyWelfare</strong> with your MyGovID', 'Find the payment you need', 'Complete the online form', 'Send the required documents', 'Track the status on MyWelfare' ) ) . "\n";
$c .= g_h2( 'Where to apply' );
$c .= g_p( '<strong>Apply for payments:</strong> ' . g_a( 'MyWelfare', 'https://www.mywelfare.ie/' ) ) . "\n";
$c .= g_know( array( 'For PRSI-based payments (such as Jobseeker&#8217;s Benefit) you must have <strong>contributed</strong> for a minimum period', 'For means-tested payments (such as Jobseeker&#8217;s Allowance) you must pass a <strong>means test</strong>', 'A <strong>PPS Number</strong> is required to apply for any payment', 'You must be a <strong>habitual resident</strong> of Ireland' ) );
$c .= g_faq( array( 'Can Brazilians claim Social Welfare?' => 'Yes, if you are a habitual resident of Ireland and meet the requirements for each payment. Having an Employment Permit or another valid immigration status is important.', 'What is MyGovID?' => 'MyGovID is the Irish government&#8217;s authentication system. You need it to access MyWelfare and other online government services.' ) ) . "\n\n";
$c .= g_src( array( 'MyWelfare' => 'https://www.mywelfare.ie/', 'Department of Social Protection' => 'https://www.gov.ie/en/department-of-social-protection/', 'Citizens Information - Social Welfare' => 'https://www.citizensinformation.ie/en/social-welfare/' ) );
	return $c;
}
