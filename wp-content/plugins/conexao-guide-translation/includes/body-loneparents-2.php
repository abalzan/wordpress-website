<?php
// Lone parents benefits EN body, part 2 (sections 6-10, checklist, FAQ, sources).
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_loneparents_p2(): string {
$c = '';
$c .= g_h2( '6. Domiciliary Care Allowance' );
$c .= g_p( 'If your child has a disability and needs significantly more care and attention than a child of the same age would normally need, another important payment may apply: <strong>Domiciliary Care Allowance (DCA)</strong>. This payment is not exclusive to lone parents and has its own criteria.' ) . "\n";
$c .= g_h2( '7. Carer&#8217;s Allowance and Carer&#8217;s Benefit' );
$c .= g_p( 'If you have to stop working or significantly reduce your hours to care for someone who needs full-time care, there may be carer-related payments. They are not exclusive to lone parents and have their own requirements.' ) . "\n";
$c .= g_h2( '8. Support to return to work or start a business' );
$c .= g_p( 'The <strong>Back to Work Enterprise Allowance (BTWEA)</strong> may be relevant for some people who receive certain social payments and want to start a business. There are eligibility requirements, benefit duration rules and prior approval of the business.' ) . "\n";
$c .= g_h2( '9. Housing supports' );
$c .= g_p( 'Depending on your income and family situation, it may be worth checking the Housing Assistance Payment (HAP), social housing and other housing-related supports. There is no general rule saying that all lone parents get automatic priority in any housing scheme.' ) . "\n";
$c .= g_h2( '10. If I am unemployed' );
$c .= g_p( 'If you are a lone parent who has lost their job, the options may depend on the child&#8217;s age, PRSI record, income and other circumstances. Jobseeker&#8217;s Transitional Payment, Jobseeker&#8217;s Allowance, Jobseeker&#8217;s Benefit or other payments may be available.' ) . "\n";
$c .= g_h2( 'What should a Brazilian have ready?' );
$c .= g_ul( array( 'PPS Number', 'identity document', 'proof of address', 'bank details', 'the child&#8217;s documents and PPS Number', 'salary and payslips', 'information about other income', 'information about custody, separation or divorce, where relevant' ) ) . "\n";
$c .= g_h2( 'Checklist: am I a lone parent in Ireland?' );
$c .= g_ul( array( 'Do I have a PPS Number?', 'Does my child have a PPS Number?', 'Am I receiving Child Benefit?', 'Can I apply for the One-Parent Family Payment?', 'Should I check the Jobseeker&#8217;s Transitional Payment?', 'Could my income qualify me for the Working Family Payment?', 'Can I claim the Single Person Child Carer Credit?', 'Does my child have needs that may qualify for the Domiciliary Care Allowance?', 'Do I need to check Carer&#8217;s Allowance or Carer&#8217;s Benefit?', 'Am I entitled to any housing support?', 'Is my family and tax situation up to date?' ) ) . "\n";
$c .= g_faq( array(
	'Is there a specific single mother benefit in Ireland?' => 'The One-Parent Family Payment is one of the main payments for people raising children without the support of a partner, but it is not the only support available.',
	'Can a single father also receive it?' => 'Yes. The schemes are not exclusively for women. Eligibility depends on the rules of each payment.',
	'Can I work and receive benefits?' => 'In some cases, yes. How income is treated varies by payment.',
	'What is the SPCCC?' => 'It is the Single Person Child Carer Credit, a tax credit administered by Revenue. In 2026 the credit is €1,900.',
	'What if I share custody - can I receive the SPCCC?' => 'There may be a possibility of a secondary claimant, but there are specific rules about the days the child lives with each parent and about transferring the credit.',
	'Do I have to report a change in my family situation?' => 'Yes. Changes such as marriage, reconciliation or living together can affect certain payments and tax credits. Check and report to the responsible body when needed.'
) );
$links = array(
	'Department of Social Protection' => 'https://www.gov.ie/en/department-of-social-protection/',
	'Citizens Information - Parenting alone' => 'https://www.citizensinformation.ie/en/birth-family-relationships/parenting-alone/',
	'Revenue - Single Person Child Carer Credit' => 'https://www.revenue.ie/en/personal-tax-credits-reliefs-and-exemptions/children/single-person-child-carer-credit/index.aspx',
	'Revenue - Tax rates, bands and reliefs' => 'https://www.revenue.ie/en/personal-tax-credits-reliefs-and-exemptions/tax-relief-charts/index.aspx',
	'Revenue' => 'https://www.revenue.ie/',
);
$c .= g_plain_links( 'Official sources', $links );
$c .= g_h2( 'Last checked' );
$c .= g_p( '<strong>Information checked: 28 August 2026.</strong>' );
$c .= g_p( 'Social welfare payments and tax credits can change with the Budget, new laws, changes in rates, income limits, residence rules and eligibility criteria. Always confirm the information directly with the Department of Social Protection, Revenue or Citizens Information.' );
$c .= g_p( '<em>Notice: This guide is for information only. Social welfare payments, taxes and eligibility criteria depend on individual circumstances. This information does not constitute personalised legal, tax or financial advice.</em>' );
$c .= g_sep();
return $c;
}
function conexao_guide_en_loneparents(): string { return conexao_guide_en_loneparents_p1() . conexao_guide_en_loneparents_p2(); }
