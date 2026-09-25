<?php
// HAP + RTB EN bodies.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_hap(): string {
$c = '';
$c .= g_h2( 'What is HAP?' );
$c .= g_p( '<strong>HAP (Housing Assistance Payment)</strong> is an Irish government scheme that helps eligible people pay their rent. HAP is administered by the <strong>local authorities</strong>.' ) . "\n";
$c .= g_links( array( 'Apply for HAP online' => 'https://www.hap.ie/apply/', 'Find your local authority for HAP' => 'https://www.hap.ie/localauthorities/' ) );
$c .= g_h2( 'Who can apply?' );
$c .= g_p( 'HAP is for people who:' );
$c .= g_ul( array( 'Are on the <strong>social housing waiting list</strong>', 'Have a <strong>low income</strong>', 'Are <strong>habitual residents</strong> of Ireland', 'Do not have suitable housing' ) ) . "\n";
$c .= g_h2( 'How it works' );
$c .= g_p( 'With HAP, the local authority pays the rent directly to the landlord. The tenant pays a contribution to the council (usually between 15% and 35% of income). The tenant finds the property on the private market.' ) . "\n";
$c .= g_h2( 'How to apply' );
$c .= g_ol( array( 'Contact the <strong>local authority</strong> for your area', 'Request an assessment for HAP', 'If approved, find a property on the private market', 'The council inspects the property and the landlord', 'The council starts paying the rent to the landlord' ) ) . "\n";
$c .= g_h2( 'Where to apply' );
$c .= g_p( '<strong>HAP information:</strong> ' . g_a( 'gov.ie - HAP', 'https://www.gov.ie/en/service/76f3e-housing-assistance-payment/' ) ) . "\n";
$c .= g_know( array( 'HAP is for people on the <strong>social housing waiting list</strong>', 'You have to find the property <strong>yourself</strong> on the private market', 'The local authority pays the rent <strong>directly to the landlord</strong>', 'You pay a <strong>contribution</strong> to the council based on your income' ) );
$c .= g_faq( array( 'Can Brazilians apply for HAP?' => 'Yes, if you are a habitual resident of Ireland and on the social housing waiting list. Having a valid immigration status is important.', 'How much do I pay with HAP?' => 'You pay a contribution based on your income, usually between 15% and 35% of your weekly income.' ) ) . "\n\n";
$c .= g_src( array( 'gov.ie - HAP' => 'https://www.gov.ie/en/service/76f3e-housing-assistance-payment/', 'Citizens Information - HAP' => 'https://www.citizensinformation.ie/en/housing/renting-a-home/housing-assistance-payment/', 'Local Authorities' => 'https://www.gov.ie/en/help/local-authorities/' ) );
	return $c;
}
function conexao_guide_en_rtb(): string {
$c = '';
$c .= g_h2( 'What is the RTB?' );
$c .= g_p( 'The <strong>RTB (Residential Tenancies Board)</strong> is the official body that regulates residential renting in Ireland. It registers tenancy agreements, resolves disputes between tenants and landlords, and provides information on rights and responsibilities.' ) . "\n";
$c .= g_links( array( 'Check renting rights and rules - RTB' => 'https://rtb.ie/renting/' ) );
$c .= g_h2( 'Who needs to know about the RTB?' );
$c .= g_p( 'All tenants and landlords in Ireland. The RTB is essential to protect your rights as a tenant.' ) . "\n";
$c .= g_links( array( 'Tenant rights at the RTB' => 'https://rtb.ie/renting/rights-responsibilities/tenant-rights-responsibilities/' ) );
$c .= g_h2( 'What the RTB does' );
$c .= g_ul( array( '<strong>Registers</strong> tenancy agreements', '<strong>Resolves disputes</strong> between tenants and landlords', '<strong>Provides information</strong> on rights and responsibilities', '<strong>Regulates rent increases</strong>', '<strong>Holds the deposit</strong> (in some cases)' ) ) . "\n";
$c .= g_h2( 'Your rights as a tenant' );
$c .= g_ul( array( 'The landlord must <strong>register the tenancy</strong> with the RTB within 1 month', 'The maximum deposit is <strong>1 month&#8217;s rent</strong>', 'The landlord must give <strong>notice</strong> to end the tenancy', 'In <strong>Rent Pressure Zones</strong> the rent increase is limited', 'The landlord must provide a <strong>receipt</strong> for the deposit' ) ) . "\n";
$c .= g_h2( 'How to register a dispute' );
$c .= g_ol( array( 'Go to the <strong>RTB</strong> website', 'Complete the dispute form', 'Pay the fee (if applicable)', 'The RTB reviews the case', 'Take part in mediation or a hearing' ) ) . "\n";
$c .= g_h2( 'Where to access it' );
$c .= g_p( '<strong>RTB:</strong> ' . g_a( 'rtb.ie', 'https://www.rtb.ie/' ) ) . "\n";
$c .= g_know( array( 'Check that your tenancy is <strong>registered with the RTB</strong>', 'Keep <strong>all payment receipts</strong>', 'If the landlord does not return the deposit you can <strong>take the case to the RTB</strong>', 'The RTB has <strong>decision-making power</strong> in disputes' ) );
$c .= g_faq( array( 'What is the RTB?' => 'It is the official body that regulates residential renting in Ireland. It registers tenancies and resolves disputes.', 'Does my landlord have to register the tenancy?' => 'Yes, the landlord must register the tenancy with the RTB within 1 month of the tenancy starting.' ) ) . "\n\n";
$c .= g_src( array( 'RTB - Residential Tenancies Board' => 'https://www.rtb.ie/', 'Citizens Information - Renting a Home' => 'https://www.citizensinformation.ie/en/housing/renting-a-home/' ) );
	return $c;
}
