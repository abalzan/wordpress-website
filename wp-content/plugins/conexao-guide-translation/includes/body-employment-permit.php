<?php
// Employment Permit EN body.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_employment_permit(): string {
$c = '';
$c .= g_intro(
	'Understand when Brazilians need an Employment Permit to work in Ireland, the differences between the Critical Skills and General Employment Permit, and the main steps.',
	'/guias/employment-permit-irlanda-brasileiros/',
	'A job offer does not automatically mean a Brazilian citizen can start working. For non-EEA nationals the general rule is that an Employment Permit is needed, unless an exemption applies.'
);
$c .= g_audience( 'Brazilians and other workers from outside the EEA who are looking for a job, changing employer, or assessing a work offer in Ireland.' );
$c .= g_steps( array(
	'Confirm your immigration status and whether you are exempt from the Employment Permit requirement.',
	'Check the right type: the current system has nine types; the Critical Skills and General Employment Permits are important examples.',
	'Check the occupation, remuneration and other current criteria: the lists can change.',
	'Collect the offer and the contract. The DETE states that a contract signed by both parties must accompany each application.',
	'Use Employment Permits Online to create the account, submit the application and follow the process.',
	'When the Labour Market Needs Test applies, confirm the advertising rules before submitting.',
	'Plan ahead: the DETE states that the application must be received at least 12 weeks before the proposed start date.'
) );
$c .= g_documents( array(
	'Signed offer and contract.',
	'Employer and worker details.',
	'Role, remuneration, occupation and work location.',
	'The checklist and documents specific to the permit type.'
) );
$c .= g_costs( 'Fees depend on the type and duration of the Employment Permit. Check the specific type on the DETE website before submitting.' );
$c .= g_timelines( 'General rule published by the DETE: apply at least 12 weeks before the start date. Where applicable, the Labour Market Needs Test involves additional advertising requirements.' );
$c .= g_pitfalls( array(
	'Confusing an Employment Permit with a Residence Permission: the DETE is clear that they are not the same.',
	'Starting work before confirming the authorisation you need.',
	'Using old lists of eligible occupations.',
	'Not checking the DETE&#8217;s current processing times.'
) );
$links = array(
	'DETE - Employment Permits' => 'https://enterprise.gov.ie/en/what-we-do/workplace-and-skills/employment-permits/',
	'DETE - permit types' => 'https://enterprise.gov.ie/en/what-we-do/workplace-and-skills/employment-permits/permit-types/permit-types.html',
	'DETE - Critical Skills Occupations List' => 'https://enterprise.gov.ie/en/what-we-do/workplace-and-skills/employment-permits/employment-permit-eligibility/highly-skilled-eligible-occupations-list/',
	'Employment Permits Online' => 'https://employmentpermits.enterprise.gov.ie/home',
);
$c .= g_plain_links( 'Official sources', $links );
$c .= g_plain_links( 'Useful links', $links );
$c .= g_faq( array(
	'Do Brazilians always need an Employment Permit?' => 'Not in every case. The general rule applies to non-EEA nationals, but there are exemptions and immigration permissions that authorise work.',
	'Are Critical Skills and General the same?' => 'No. They are different categories, with their own criteria and consequences.',
	'Does an Employment Permit give residence automatically?' => 'No. The DETE expressly states that the permit is not a Residence Permission.'
) );
$c .= g_links( array(
	'Access the Employment Permits Online portal' => 'https://employmentpermits.enterprise.gov.ie/home',
	'Check Employment Permit types and criteria' => 'https://enterprise.gov.ie/en/what-we-do/workplace-and-skills/employment-permits/'
) );
$c .= g_check( '18 August 2026', '' );
$c .= g_disclaimer( 'For information only; it does not replace immigration or legal advice.' );
$c .= g_sep();
	return $c;
}
