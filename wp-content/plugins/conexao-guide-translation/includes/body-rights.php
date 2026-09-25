<?php
// Employment rights + public transport EN bodies.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_rights(): string {
$c = '';
$c .= g_h2( 'What are employment rights in Ireland?' );
$c .= g_p( 'Employment rights in Ireland are protected by law and administered by the <strong>Workplace Relations Commission (WRC)</strong>. All workers, including Brazilians with work permission, have guaranteed rights.' ) . "\n";
$c .= g_h2( 'Who has employment rights?' );
$c .= g_p( 'All workers in Ireland have employment rights regardless of nationality. This includes the minimum wage, annual leave, working hours and protection against discrimination.' ) . "\n";
$c .= g_h2( 'Minimum wage' );
$c .= g_p( 'The Irish national minimum wage is <strong>€13.50 per hour</strong> (from January 2026). Reduced rates apply to under-20s.' ) . "\n";
$c .= g_h2( 'Annual leave' );
$c .= g_ul( array( 'All workers are entitled to <strong>4 weeks of paid annual leave</strong>', 'Leave accrues in proportion to time worked', 'The employer must pay out unused leave at the end of the contract' ) ) . "\n";
$c .= g_h2( 'Working hours' );
$c .= g_ul( array( 'The maximum working week is <strong>48 hours</strong> (on average)', 'You are entitled to <strong>11 hours of rest</strong> between shifts', 'You are entitled to <strong>1 rest day</strong> per week', 'Overtime must be paid or time off in lieu' ) ) . "\n";
$c .= g_h2( 'Contract of employment' );
$c .= g_p( 'Your employer must give you a <strong>written contract of employment</strong> within 5 days of starting work. The contract must include pay, hours, leave and the notice period.' ) . "\n";
$c .= g_h2( 'Notice period' );
$c .= g_p( 'The notice period depends on length of service:' );
$c .= g_ul( array( '1-2 years: 1 week', '2-5 years: 2 weeks', '5-10 years: 4 weeks', '10+ years: 8 weeks' ) ) . "\n";
$c .= g_h2( 'Where to get help' );
$c .= g_p( 'If you have a problem at work, contact the <strong>Workplace Relations Commission (WRC)</strong>:' );
$c .= g_p( '<strong>WRC:</strong> ' . g_a( 'workplacerelations.ie', 'https://www.workplacerelations.ie/' ) ) . "\n";
$c .= g_know( array( 'The minimum wage applies to <strong>all</strong> workers, including non-nationals', 'You are entitled to detailed <strong>payslips</strong>', 'It is <strong>illegal</strong> to discriminate on the basis of nationality, race, gender, etc.', 'If you were unfairly dismissed you can take the case to the WRC' ) );
$c .= g_faq( array( 'What is the minimum wage in Ireland?' => 'The national minimum wage is €13.50 per hour (from January 2026). Check the current rate on the government website.', 'How much annual leave am I entitled to?' => 'All workers are entitled to 4 weeks of paid annual leave per year.', 'What is the WRC?' => 'The WRC (Workplace Relations Commission) is the official body dealing with workplace disputes, inspections and information on employment rights.' ) );
$c .= g_links( array( 'Make a complaint to the WRC' => 'https://www.workplacerelations.ie/en/complaints_disputes/refer_a_dispute_make_a_complaint/how_to_make_a_complaint_refer_a_dispute.html' ) ) . "\n\n";
$c .= g_src( array( 'Workplace Relations Commission' => 'https://www.workplacerelations.ie/', 'Citizens Information - Employment Rights' => 'https://www.citizensinformation.ie/en/employment/employment-rights-and-conditions/', 'gov.ie - National Minimum Wage' => 'https://www.gov.ie/en/publication/3a4d5-national-minimum-wage/' ) );
	return $c;
}
