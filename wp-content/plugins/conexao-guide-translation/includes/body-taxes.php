<?php
// Taxes EN body.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_taxes(): string {
$c = '';
$c .= g_h2( 'What is the tax system in Ireland?' );
$c .= g_p( 'The Irish tax system is run by <strong>Revenue (Office of the Revenue Commissioners)</strong>. If you work in Ireland, your tax is deducted automatically from your salary through the <strong>PAYE (Pay As You Earn)</strong> system.' ) . "\n";
$c .= g_h2( 'Who has to pay tax?' );
$c .= g_p( 'All workers in Ireland pay tax. If you are an employee the tax is deducted automatically. If you are self-employed you must file a return and pay your own tax.' ) . "\n";
$c .= g_h2( 'Main taxes' );
$c .= g_ul( array( '<strong>Income Tax</strong>: progressive tax on your income', '<strong>USC (Universal Social Charge)</strong>: universal social charge', '<strong>PRSI (Pay Related Social Insurance)</strong>: social insurance contribution' ) ) . "\n";
$c .= g_h2( 'How Income Tax works' );
$c .= g_p( 'Income Tax is progressive. For 2026 the rates are:' );
$c .= g_ul( array( '<strong>20%</strong> on income up to the standard rate band', '<strong>40%</strong> on income above that limit' ) );
$c .= g_p( 'The standard rate band depends on your status (single, married, etc.). For a single person the limit is approximately <strong>€42,000</strong> per year.' ) . "\n";
$c .= g_h2( 'How USC works' );
$c .= g_p( 'USC is a social tax charged on gross income. Rates range from <strong>0.5% to 8%</strong> depending on your income. People with annual income below <strong>€13,000</strong> do not pay USC.' ) . "\n";
$c .= g_h2( 'How PRSI works' );
$c .= g_p( 'PRSI is the contribution to Irish social insurance. The rate is <strong>4%</strong> for most workers (with some exceptions). PRSI gives access to benefits such as Jobseeker&#8217;s Benefit, Illness Benefit and the old-age pension.' ) . "\n";
$c .= g_h2( 'How PAYE works' );
$c .= g_p( '<strong>PAYE (Pay As You Earn)</strong> is the system through which your employer automatically deducts Income Tax, USC and PRSI from your salary. You do not need to do anything &#8211; the tax is withheld at source.' ) . "\n";
$c .= g_h2( 'Tax credits' );
$c .= g_p( 'You are entitled to <strong>tax credits</strong> that reduce the tax you pay. The main one is the <strong>Personal Tax Credit</strong>, which for 2026 is approximately <strong>€1,875</strong> per year for single people.' ) . "\n";
$c .= g_h2( 'How to register with Revenue' );
$c .= g_ol( array( 'Create a <strong>Revenue MyAccount</strong> account (using your PPS Number)', 'Register your employment or activity', 'Check that your tax credits are correct', 'If you paid too much tax, claim a refund' ) ) . "\n";
$c .= g_links( array( 'Sign in or register for Revenue myAccount' => 'https://www.revenue.ie/en/online-services/services/myaccount/register-for-myaccount.aspx', 'Access Revenue' => 'https://www.revenue.ie/' ) );
$c .= g_h2( 'Where to access it' );
$c .= g_p( '<strong>Access Revenue:</strong> ' . g_a( 'Revenue - Office of the Revenue Commissioners', 'https://www.revenue.ie/' ) ) . "\n";
$c .= g_know( array( 'If you worked for more than one employer you may have paid too much tax and be entitled to a <strong>refund</strong>', 'The <strong>tax year</strong> in Ireland runs from January to December', 'If you are self-employed you must file an annual return (Form 11)', 'Revenue can <strong>fine</strong> people who do not file their tax correctly' ) );
$c .= g_faq( array( 'Do I need to file an income tax return in Ireland?' => 'If you are an employee (PAYE) the tax is deducted automatically. You may need to file if you have other sources of income, are self-employed, or want to claim a refund.', 'How do I claim a tax refund?' => 'You can claim a refund through <strong>Revenue MyAccount</strong>. If you paid too much tax, Revenue refunds it automatically or you can claim it.', 'What is the PPS Number in a tax context?' => 'The PPS Number is your tax identification in Ireland. It is the equivalent of the Brazilian CPF for tax purposes.' ) ) . "\n\n";
$c .= g_src( array( 'Revenue - Office of the Revenue Commissioners' => 'https://www.revenue.ie/', 'Citizens Information - How Your Tax is Calculated' => 'https://www.citizensinformation.ie/en/money-and-tax/tax/income-tax/how-your-tax-is-calculated/', 'Citizens Information - USC' => 'https://www.citizensinformation.ie/en/money-and-tax/tax/income-tax/universal-social-charge/' ) );
	return $c;
}
