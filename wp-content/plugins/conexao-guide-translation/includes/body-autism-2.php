<?php
// Autism diagnosis in Ireland EN body, part 2 (benefits, education, transition, orgs, FAQ).
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_autism_p2(): string {
$c = '';
$c .= g_h2( 'Benefits and social supports' );
$c .= g_p( 'Depending on the person&#8217;s and the family&#8217;s situation, different social welfare payments may exist. The HSE mentions, among others, Carer&#8217;s Allowance, Carer&#8217;s Benefit, Carer&#8217;s Support Grant, Domiciliary Care Allowance and Disability Allowance.' ) . "\n";
$c .= g_p( 'Being autistic does not automatically mean you are entitled to a payment. Each scheme has its own criteria. To check eligibility, contact the Department of Social Protection and Citizens Information.' ) . "\n";
$c .= g_h2( 'Autism and education' );
$c .= g_p( 'For children, talk to the school about individual communication, learning, routine and sensory needs. For third-level education, the HSE also points to DARE as a possible support route for eligible students.' ) . "\n";
$c .= g_h2( 'Transitioning from children&#8217;s services to adult services' );
$c .= g_p( 'The transition should be planned well in advance. The HSE recommends starting to prepare the move to adult services at around 14 or 15 years old where possible. More recent HSE information also indicates that, in some disability services, the transition can begin about 18 months before the young person leaves school.' ) . "\n";
$c .= g_p( 'See ' . g_a( 'HSE - changing from child to adult care', 'https://www2.hse.ie/conditions/autism/assessment-and-support/changing-from-child-to-adult-care/' ) . ' and ' . g_a( 'HSE - moving from child to adult services', 'https://www2.hse.ie/babies-children/disabilities/services/moving-from-child-to-adult-services/' ) . '.' ) . "\n";
$c .= g_h2( 'Organisations that can help' );
$c .= g_ul( array(
	g_a( 'HSE - Autism', 'https://www2.hse.ie/conditions/autism/' ) . ' &#8212; official health information.',
	g_a( 'AsIAm', 'https://asiam.ie/' ) . ' &#8212; an Irish organisation dedicated to autism.',
	g_a( 'Irish Society for Autism', 'https://autism.ie/' ) . ' &#8212; autism-related information and resources.',
	g_a( 'Citizens Information', 'https://www.citizensinformation.ie/' ) . ' &#8212; information on public services and benefits.',
	g_a( 'Department of Social Protection', 'https://www.gov.ie/en/department-of-social-protection/' ) . ' &#8212; social welfare payments and social protection.'
) ) . "\n";
$c .= g_faq( array(
	'I have an autism diagnosis from Brazil. Do I need another diagnosis in Ireland?' => 'It is not possible to say that every Brazilian diagnosis will have to be repeated. The need depends on the service and the purpose. Bring your reports and confirm directly with the Irish service which documents are needed.',
	'Can adults be assessed by the HSE?' => 'The HSE currently states that it does not provide adult autism diagnostic assessment, and advises seeking a private assessment.',
	'My child is waiting for an assessment. Can they get any support?' => 'The HSE states that certain services can be accessed without waiting for a formal diagnosis, depending on needs. Talk to the GP or another professional to identify the right route.',
	'Does a diagnosis automatically give entitlement to a social welfare payment?' => 'No. Each payment has its own criteria and must be assessed individually.'
) );
$c .= g_h2( 'Last checked' );
$c .= g_p( '<strong>Information checked: 19 August 2026.</strong> We recommend checking the official pages before making decisions, as service availability, criteria, procedures and contacts can change.' );
$c .= g_p( '<strong>Notice:</strong> this guide is for information only. It does not constitute personalised medical, legal, financial or professional advice.' );
$c .= g_sep();
return $c;
}
function conexao_guide_en_autism(): string { return conexao_guide_en_autism_p1() . conexao_guide_en_autism_p2(); }
