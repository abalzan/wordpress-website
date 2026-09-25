<?php
// Company + immigration EN bodies.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_company(): string {
$c = '';
$c .= g_h2( 'What do you need to open a company in Ireland?' );
$c .= g_p( 'Opening a company in Ireland is a relatively simple process. Registration is done with the <strong>CRO (Companies Registration Office)</strong>, and you need to register with <strong>Revenue</strong> for tax purposes.' ) . "\n";
$c .= g_h2( 'Who can open a company?' );
$c .= g_p( 'Anyone aged 18 or over can open a company in Ireland, regardless of nationality. Brazilians with a valid immigration status can open companies.' ) . "\n";
$c .= g_h2( 'Types of company' );
$c .= g_ul( array( '<strong>Sole Trader</strong>: you trade in your own name. Simpler, but you are personally liable for the debts.', '<strong>Limited Company</strong>: a company separate from you. More protection, but more paperwork.', '<strong>Partnership</strong>: two or more partners trading together.' ) ) . "\n";
$c .= g_h2( 'How to open one' );
$c .= g_ol( array( 'Choose the type of company', 'Register the company with the <strong>CRO</strong> (for a Limited Company)', 'Register with <strong>Revenue</strong> for tax purposes', 'Get a <strong>PPS Number</strong> (if you do not have one yet)', 'Open a <strong>business bank account</strong>', 'Appoint an <strong>accountant</strong> (recommended)' ) ) . "\n";
$c .= g_h2( 'How much does it cost?' );
$c .= g_p( 'Registering a Limited Company with the CRO costs <strong>€50</strong> (online registration). Registering as a Sole Trader with Revenue is <strong>free</strong>.' ) . "\n";
$c .= g_h2( 'Where to register' );
$c .= g_p( '<strong>Register a company:</strong> ' . g_a( 'CRO - Companies Registration Office', 'https://www.cro.ie/' ) ) . "\n";
$c .= g_links( array( 'Register a company on CORE (CRO)' => 'https://cro.ie/services-and-help/core/', 'Register a company - official CRO page' => 'https://cro.ie/Registration/Company/Registration-Methods/' ) );
$c .= g_know( array( 'As a <strong>Sole Trader</strong> you are personally liable for the company&#8217;s debts', 'As a <strong>Limited Company</strong> the company is a separate entity &#8211; your personal assets are protected', 'You must file tax returns every year (Form 11 for a Sole Trader, CT1 for a Limited Company)', 'Appoint an <strong>accountant</strong> to handle the tax obligations', '<strong>Revenue</strong> can fine anyone who files tax incorrectly' ) );
$c .= g_faq( array( 'Can Brazilians open a company in Ireland?' => 'Yes, as long as they have a valid immigration status. Brazilians on Stamp 1 (work) or Stamp 4 (residence) can open companies.', 'Do I need an entrepreneur visa?' => 'It depends on your immigration status. If you already have Stamp 4 you can open a company. If you are on Stamp 1 you may need a specific permission. Check with the Department of Justice.' ) ) . "\n\n";
$c .= g_src( array( 'CRO - Companies Registration Office' => 'https://www.cro.ie/', 'Revenue - Office of the Revenue Commissioners' => 'https://www.revenue.ie/', 'Citizens Information - Self-Employment' => 'https://www.citizensinformation.ie/en/employment/types-of-employment/self-employment/' ) );
	return $c;
}
