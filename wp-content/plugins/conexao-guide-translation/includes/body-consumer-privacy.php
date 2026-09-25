<?php
// Consumer rights + data protection EN bodies.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_consumer(): string {
$c = '';
$c .= g_h2( 'What are consumer rights in Ireland?' );
$c .= g_p( 'Consumer rights in Ireland are protected by law and enforced by the <strong>CCPC (Competition and Consumer Protection Commission)</strong>. All consumers, including Brazilians, have guaranteed rights.' ) . "\n";
$c .= g_links( array( 'Check consumer rights and guidance - CCPC' => 'https://www.ccpc.ie/consumers/' ) );
$c .= g_h2( 'Who has consumer rights?' );
$c .= g_p( 'All consumers in Ireland, regardless of nationality. If you buy goods or services in Ireland your rights are protected.' ) . "\n";
$c .= g_h2( 'Main rights' );
$c .= g_ul( array( '<strong>Right of return</strong>: you can return faulty goods', '<strong>Guarantee</strong>: goods must work as advertised', '<strong>Clear information</strong>: prices and terms must be transparent', '<strong>Protection against misleading practices</strong>', '<strong>Right of cancellation</strong>: online purchases can be cancelled within 14 days' ) ) . "\n";
$c .= g_h2( 'Online shopping' );
$c .= g_p( 'For online purchases you have the right to <strong>cancel</strong> within 14 days (the cooling-off period). The seller must refund you within 14 days of the cancellation.' ) . "\n";
$c .= g_h2( 'Where to get help' );
$c .= g_p( 'If you have a problem with a product or service:' );
$c .= g_ol( array( 'Contact the <strong>seller</strong> first', 'If that does not solve it, contact the <strong>CCPC</strong>', 'For financial services, contact the <strong>FSPO (Financial Services and Pensions Ombudsman)</strong>' ) ) . "\n";
$c .= g_h2( 'Where to access it' );
$c .= g_p( '<strong>CCPC:</strong> ' . g_a( 'ccpc.ie', 'https://www.ccpc.ie/' ) ) . "\n";
$c .= g_know( array( 'Keep the <strong>receipts</strong> for all purchases', 'Read the <strong>terms and conditions</strong> before buying', 'Be wary of <strong>too-good-to-be-true</strong> offers', 'The CCPC has <strong>free information</strong> about your rights' ) );
$c .= g_faq( array( 'Can I return a product bought in Ireland?' => 'Yes, if the product is faulty or does not match the description. For online purchases you have 14 days to cancel.', 'What is the CCPC?' => 'It is Ireland&#8217;s consumer protection commission. It provides information and help with complaints.' ) ) . "\n\n";
$c .= g_src( array( 'CCPC - Competition and Consumer Protection Commission' => 'https://www.ccpc.ie/', 'FSPO - Financial Services and Pensions Ombudsman' => 'https://www.fspo.ie/', 'Citizens Information - Consumer' => 'https://www.citizensinformation.ie/en/consumer/' ) );
	return $c;
}
function conexao_guide_en_gdpr(): string {
$c = '';
$c .= g_h2( 'What is data protection in Ireland?' );
$c .= g_p( 'Data protection in Ireland is regulated by the <strong>Data Protection Commission (DPC)</strong>. Ireland follows the EU <strong>GDPR (General Data Protection Regulation)</strong>, which gives citizens strong rights over their personal data.' ) . "\n";
$c .= g_links( array( 'Exercise your data protection rights - DPC' => 'https://www.dataprotection.ie/en/individuals/exercising-your-rights' ) );
$c .= g_h2( 'Who is protected?' );
$c .= g_p( 'All residents of Ireland have data protection rights under the GDPR. This includes Brazilians living in Ireland.' ) . "\n";
$c .= g_h2( 'Your rights under the GDPR' );
$c .= g_ul( array( '<strong>Right of access</strong>: see what data a company holds about you', '<strong>Right of rectification</strong>: correct inaccurate data', '<strong>Right to erasure</strong>: ask for your data to be deleted', '<strong>Right to portability</strong>: transfer your data to another company', '<strong>Right to object</strong>: object to the processing of your data' ) ) . "\n";
$c .= g_h2( 'How to exercise your rights' );
$c .= g_ol( array( 'Contact the <strong>company</strong> that holds your data', 'Make the request in writing', 'The company must respond within <strong>1 month</strong>', 'If it is not resolved, contact the <strong>DPC</strong>' ) ) . "\n";
$c .= g_h2( 'Where to access it' );
$c .= g_p( '<strong>DPC:</strong> ' . g_a( 'dataprotection.ie', 'https://www.dataprotection.ie/' ) ) . "\n";
$c .= g_know( array( 'The GDPR applies to <strong>all</strong> companies processing your data in the EU', 'Companies must obtain your <strong>consent</strong> to process your data', 'You can request <strong>copies</strong> of your data at any time', 'The DPC can <strong>fine</strong> companies that breach the GDPR' ) );
$c .= g_faq( array( 'What is the GDPR?' => 'It is the EU General Data Protection Regulation. It gives citizens strong rights over their personal data.', 'How do I make a data protection complaint?' => 'Contact the company first. If it is not resolved, contact the Data Protection Commission (DPC).' ) ) . "\n\n";
$c .= g_src( array( 'Data Protection Commission' => 'https://www.dataprotection.ie/', 'Citizens Information - Data Protection' => 'https://www.citizensinformation.ie/en/government-in-ireland/data-protection/' ) );
	return $c;
}
