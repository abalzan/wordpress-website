<?php
// CAO EN body.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_cao(): string {
$c = '';
$c .= g_h2( 'What is the CAO?' );
$c .= g_p( 'The <strong>CAO (Central Applications Office)</strong> is the central body that processes admission applications for higher education courses (universities and institutes) in Ireland. It is the system through which you apply for undergraduate courses.' ) . "\n";
$c .= g_h2( 'Who needs to use the CAO?' );
$c .= g_p( 'Students who want to enter undergraduate courses at universities and institutes of technology in Ireland. The CAO is used by both Irish and international students.' ) . "\n";
$c .= g_links( array( 'Make or access your CAO application' => 'https://www.cao.ie/apply.php?altmenu=aentry', 'Access My CAO Application' => 'https://www.cao.ie/apply.php?page=myapp' ) );
$c .= g_h2( 'How it works' );
$c .= g_ol( array( 'Create an account on the <strong>CAO</strong> website', 'Choose up to <strong>10 courses</strong> in order of preference', 'Pay the application fee', 'Send the required documents', 'Wait for offers in August' ) ) . "\n";
$c .= g_h2( 'Key dates' );
$c .= g_ul( array( '<strong>1 February</strong>: normal application deadline', '<strong>1 May</strong>: deadline to change preferences', '<strong>August</strong>: offers are made' ) ) . "\n";
$c .= g_h2( 'How much does it cost?' );
$c .= g_p( 'The CAO application fee is <strong>€45</strong> (for 10 courses) or <strong>€30</strong> (for late applications).' ) . "\n";
$c .= g_h2( 'Where to apply' );
$c .= g_p( '<strong>Apply through the CAO:</strong> ' . g_a( 'CAO - Central Applications Office', 'https://www.cao.ie/' ) ) . "\n";
$c .= g_know( array( 'The CAO is used for <strong>undergraduate</strong> courses', 'For postgraduate study you apply <strong>directly</strong> to the university', 'International students may have <strong>additional</strong> requirements (English, grade equivalency)', 'Offers are based on <strong>points</strong> (Leaving Certificate or equivalent)' ) );
$c .= g_faq( array( 'Can Brazilians use the CAO?' => 'Yes, international students can apply through the CAO. You will need a grade equivalency and proof of English proficiency.', 'When should I apply?' => 'The normal deadline is 1 February for the following academic year. Apply as early as possible.' ) ) . "\n\n";
$c .= g_src( array( 'CAO - Central Applications Office' => 'https://www.cao.ie/', 'Citizens Information - CAO' => 'https://www.citizensinformation.ie/en/education/third-level-education/applying-to-college/central-applications-office/', 'Higher Education Authority' => 'https://hea.ie/' ) );
	return $c;
}
