<?php
// Bank account + renting EN bodies.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_bank(): string {
$c = '';
$c .= g_h2( 'What is an Irish bank account?' );
$c .= g_p( 'Having an Irish bank account is essential to receive salary, pay bills, receive social welfare payments and manage your finances. Most employers pay salary by bank transfer.' ) . "\n";
$c .= g_h2( 'Who needs a bank account?' );
$c .= g_p( 'A bank account is useful for many people, but it is not a general legal requirement for all residents. Whether you need one depends on your situation and on the services you use.' );
$c .= g_ul( array( 'Receiving salary', 'Paying bills (rent, electricity, internet, etc.)', 'Receiving social welfare payments (Child Benefit, etc.)', 'Receiving tax refunds from Revenue', 'Making online purchases and payments' ) ) . "\n";
$c .= g_h2( 'What you need to open an account' );
$c .= g_ul( array( '<strong>Identity document</strong>: valid passport', '<strong>Proof of address in Ireland</strong>: an electricity, water or internet bill, rental contract, or a letter from a public body', '<strong>PPS Number</strong>: some banks ask for it, but the required documents vary by institution', '<strong>Proof of income</strong> (in some cases): employment contract, recent payslips' ) ) . "\n";
$c .= g_h2( 'How to open an account' );
$c .= g_ol( array( 'Choose a bank (AIB, Bank of Ireland, Permanent TSB, etc.)', 'Book a branch appointment or start the process online', 'Bring your documents (identity, proof of address, PPS Number)', 'Complete the account opening form', 'The bank reviews your application and activates the account' ) ) . "\n";
$c .= g_links( array( 'Compare and understand bank accounts - CCPC' => 'https://www.ccpc.ie/consumers/money/banking/current-accounts/' ) );
$c .= g_h2( 'How much does it cost?' );
$c .= g_p( 'Fees and charges vary between accounts and institutions. Do not treat a fixed €4&#8211;€6 range as a current rule: check the bank&#8217;s charges and compare the options before opening the account.' ) . "\n";
$c .= g_h2( 'Where to open one' );
$c .= g_p( 'The main banks in Ireland include: <strong>AIB</strong>, <strong>Bank of Ireland</strong>, <strong>Permanent TSB</strong>, <strong>Revolut</strong> (digital bank). The <strong>Central Bank of Ireland</strong> regulates banks in the country.' ) . "\n";
$c .= g_know( array( 'Some banks may require you to already have a job or income in Ireland', 'The Irish <strong>IBAN</strong> starts with &#8220;IE&#8221;', 'You may need a PPS Number to open an account &#8211; if you do not have one yet, some banks allow you to open with a passport and proof of address', 'Digital banks such as Revolut and N26 can be easier for new arrivals' ) );
$c .= g_faq( array( 'Do I need a PPS Number to open an account?' => 'Most banks require a PPS Number, but some may allow you to open an account with a passport and proof of address. Check with the bank you choose.', 'Can I open an account without a job?' => 'Yes, it is possible, but some banks may ask for proof of income or require an opening deposit.' ) ) . "\n\n";
$c .= g_src( array( 'Citizens Information &#8211; Bank Accounts' => 'https://www.citizensinformation.ie/en/consumer/financial-services/bank-accounts/', 'CCPC &#8211; Bank Accounts' => 'https://www.ccpc.ie/consumers/money/bank-accounts/', 'Central Bank of Ireland' => 'https://www.centralbank.ie/' ) );
return $c;
}
