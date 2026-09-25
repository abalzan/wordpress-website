<?php
// Learner permit / theory test EN body.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_learner_permit(): string {
$c = '';
$c .= g_intro(
	'Understand the route for Brazilians who cannot exchange their foreign licence: theory test, learner permit, EDT and driving test.',
	'/guias/learner-permit-theory-test-irlanda-cnh-brasileira/',
	'Exchanging a foreign licence depends on exchange agreements. When the licence cannot be exchanged, the driver may have to follow the Irish process to obtain the licence.'
);
$c .= g_audience( 'Brazilians with a foreign driving licence who need to understand the route to an Irish licence when no exchange applies.' );
$c .= g_steps( array(
	'First check with the NDLS whether your licence is eligible for exchange.',
	'If you need the learner driver route, pass the Driver Theory Test for the correct category.',
	'Apply for the first learner permit through the NDLS, noting identity, address, ordinary residence, PPSN and reports where applicable.',
	'If you hold a full licence from a country with no exchange agreement, check whether you are entitled to the Reduced EDT Programme.',
	'For the first learner permit, the RSA states it must be held for at least six months and the required training completed before the driving test.',
	'Book the driving test once all the requirements are met.'
) );
$c .= g_documents( array(
	'Passport or an accepted document.',
	'PPSN.',
	'Proof of address where required.',
	'Proof of ordinary residence where applicable.',
	'Theory test certificate.',
	'Eye or medical report, if required.'
) );
$c .= g_costs( 'The theory test, learner permit, lessons and driving test each have their own costs. Check RSA/NDLS for the current amounts.' );
$c .= g_timelines( 'The RSA states that the learner permit must be applied for within two years of passing the theory test; after that the certificate expires.' );
$c .= g_pitfalls( array(
	'Driving unaccompanied with a learner permit.',
	'Booking the theory test on an unofficial site.',
	'Ignoring the six months for the first learner permit.',
	'Assuming a Brazilian licence is automatically exchangeable.'
) );
$links = array(
	'RSA - learner permit' => 'https://www.rsa.ie/services/learner-drivers/learner-permit/what-it-is',
	'RSA - first permit' => 'https://www.rsa.ie/services/learner-drivers/learner-permit/apply-for-your-first-permit',
	'RSA - theory test' => 'https://www.rsa.ie/services/learner-drivers/theory-test/what-it-is',
	'NDLS' => 'https://www.ndls.ie/',
);
$c .= g_plain_links( 'Official sources', $links );
$c .= g_plain_links( 'Useful links', $links );
$c .= g_faq( array(
	'Can I exchange my Brazilian licence directly?' => 'It depends on the exchange rules in force. Check with the NDLS before starting the process.',
	'Do I need to take the theory test?' => 'For the first learner permit, the RSA states that you must pass the theory test for the category.',
	'How long do I hold a learner permit?' => 'For the first learner permit, the RSA states six months, in addition to the required training.',
	'Can I drive on my own?' => 'No. The learner permit rules require an accompanying driver according to the category and the applicable conditions.'
) );
$c .= g_links( array(
	'Book the Driver Theory Test' => 'https://theorytest.ie/book-your-theory-test/',
	'Apply for your first Learner Permit' => 'https://www.rsa.ie/services/learner-drivers/learner-permit/apply-for-your-first-permit',
	'NDLS - Learner Permit' => 'https://www.ndls.ie/learner-driver/learner-permit.html'
) );
$c .= g_check( '18 August 2026', '' );
$c .= g_disclaimer( 'For information only; it does not replace RSA/NDLS guidance or legal advice.' );
$c .= g_sep();
return $c;
}
