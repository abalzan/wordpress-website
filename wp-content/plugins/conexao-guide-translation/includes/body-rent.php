<?php
// Renting a house EN body.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_rent(): string {
$c = '';
$c .= g_h2( 'What is the Irish rental market?' );
$c .= g_p( 'The Irish property market is <strong>competitive</strong>, especially in Dublin and other large cities. Demand is high and prices are high. It is important to understand the rules and your rights as a tenant.' ) . "\n";
$c .= g_h2( 'Who needs to rent?' );
$c .= g_p( 'Almost everyone who has just arrived in Ireland starts by renting. The property purchase market is expensive and requires a credit history.' ) . "\n";
$c .= g_h2( 'What you need to rent' );
$c .= g_ul( array( '<strong>Identity document</strong>: valid passport', '<strong>Proof of income</strong>: employment contract, recent payslips (usually 3 months)', '<strong>References</strong>: from an employer and/or previous landlords', '<strong>PPS Number</strong>: in some cases', '<strong>Deposit</strong>: cannot exceed 1 month&#8217;s rent; as a rule the landlord also cannot charge more than 1 month of rent in advance', '<strong>First month&#8217;s rent in advance</strong>' ) ) . "\n";
$c .= g_h2( 'Where to look' );
$c .= g_ul( array( '<strong>daft.ie</strong> &#8211; Ireland&#8217;s largest property portal', '<strong>Rent.ie</strong> &#8211; rental portal', '<strong>MyHome.ie</strong> &#8211; property portal', 'Local letting agents', 'Brazilian community Facebook groups in Ireland (with caution)' ) ) . "\n";
$c .= g_h2( 'How to rent' );
$c .= g_ol( array( 'Search the property portals and agents', 'Contact them quickly &#8211; good properties are rented within days', 'Book a viewing', 'Prepare your documents in advance', 'If approved, sign the <strong>tenancy agreement</strong>', 'Pay the deposit and the first month', 'The landlord must register the tenancy with the <strong>RTB (Residential Tenancies Board)</strong>' ) ) . "\n";
$c .= g_h2( 'How much does it cost?' );
$c .= g_p( 'Prices vary widely by location. Rents change quickly and differ greatly by city, property and date. Avoid treating old ranges as current averages: look at recent listings and the RTB information.' ) . "\n";
$c .= g_h2( 'Your rights as a tenant' );
$c .= g_ul( array( 'The <strong>RTB (Residential Tenancies Board)</strong> is the body that regulates renting in Ireland', 'The deposit cannot be more than <strong>1 month&#8217;s rent</strong>; the rule about rent paid in advance is separate', 'The landlord must provide a <strong>receipt</strong> for the deposit', 'The tenancy must be <strong>registered with the RTB</strong> within 1 month', 'The landlord must give <strong>notice</strong> to end the tenancy (between 90 and 152 days depending on how long you have lived there)', 'Since <strong>1 March 2026</strong> there is a national rent control scheme for private tenancies and Student Specific Accommodation, with specific rules and exceptions' ) ) . "\n";
$c .= g_know( array( 'Beware of scams &#8211; never pay a deposit before viewing the property', 'Check that the property has an <strong>Eircode</strong> (postcode)', 'Read the contract carefully before signing', 'Keep all payment receipts', 'If you have problems with the landlord, contact the <strong>RTB</strong>' ) );
$c .= g_faq( array( 'Do I need a PPS Number to rent?' => 'Not always, but many landlords ask for it. Having a PPS Number makes the process easier.', 'What is the RTB?' => 'The RTB (Residential Tenancies Board) is the official body that regulates residential renting in Ireland. It registers tenancies, resolves disputes and provides information about tenant and landlord rights.', 'How much deposit can I pay?' => 'The landlord cannot ask for a deposit above <strong>1 month&#8217;s rent</strong> and, as a rule, cannot ask for more than 1 month of rent in advance.' ) );
$c .= g_links( array( 'Check renting rules and rights - RTB' => 'https://rtb.ie/renting/' ) ) . "\n\n";
$c .= g_src( array( 'RTB &#8211; Residential Tenancies Board' => 'https://www.rtb.ie/', 'Citizens Information &#8211; Renting a Home' => 'https://www.citizensinformation.ie/en/housing/renting-a-home/', 'Department of Housing' => 'https://www.gov.ie/en/department-of-housing-local-government-and-heritage/' ) );
return $c;
}
