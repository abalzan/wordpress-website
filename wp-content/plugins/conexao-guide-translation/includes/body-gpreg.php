<?php
// GP registration EN body.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_gpreg(): string {
$c = '';
$c .= '<!-- wp:heading --><h2>What is a GP?</h2><!-- /wp:heading -->' . "\n";
$c .= '<!-- wp:paragraph --><p>A <strong>GP (General Practitioner)</strong> is the family doctor in Ireland. It is the first point of contact for any health matter. For non-emergency problems the GP is usually the first place to go, but there are other services. In an emergency you can go straight to an Emergency Department or call 112/999.</p><!-- /wp:paragraph -->' . "\n\n";
$c .= '<!-- wp:heading --><h2>Who needs to register with a GP?</h2><!-- /wp:heading -->' . "\n";
$c .= '<!-- wp:paragraph --><p>There is no general rule saying every resident must register with one specific GP. Having access to a GP is, however, very useful for non-emergency care and ongoing health follow-up.</p><!-- /wp:paragraph -->' . "\n";
$c .= '<!-- wp:list --><ul>' . "\n" . '<li>Routine medical appointments</li>' . "\n" . '<li>Prescriptions</li>' . "\n" . '<li>Referrals to specialists</li>' . "\n" . '<li>Medical certificates</li>' . "\n" . '<li>Vaccinations</li>' . "\n" . '<li>Screening tests</li>' . "\n" . '</ul><!-- /wp:list -->' . "\n\n";
$c .= '<!-- wp:heading --><h2>How to find a GP</h2><!-- /wp:heading -->' . "\n";
$c .= '<!-- wp:list --><ol>' . "\n" . '<li>Use <strong>HSE Find a GP</strong> to search for practices in your area</li>' . "\n" . '<li>Ask friends, colleagues or neighbours</li>' . "\n" . '<li>Check whether the practice is <strong>taking new patients</strong> (some have a waiting list)</li>' . "\n" . '<li>Call the practice and ask about the registration process</li>' . "\n" . '</ol><!-- /wp:list -->' . "\n\n";
$c .= '<!-- wp:heading --><h2>How to register</h2><!-- /wp:heading -->' . "\n";
$c .= '<!-- wp:list --><ol>' . "\n" . '<li>Choose a practice (GP practice) near you</li>' . "\n" . '<li>Call or visit the practice to check whether they are taking new patients</li>' . "\n" . '<li>Fill in the practice registration form</li>' . "\n" . '<li>Bring your <strong>PPS Number</strong> and identity document</li>' . "\n" . '<li>If you have a Medical Card or GP Visit Card, tell the practice</li>' . "\n" . '</ol><!-- /wp:list -->' . "\n\n";
$c .= '<!-- wp:heading --><h2>How much does it cost?</h2><!-- /wp:heading -->' . "\n";
$c .= '<!-- wp:paragraph --><p>The price of a private consultation varies by practice. Do not rely on a single figure as the rule: confirm directly with the GP. If you have a Medical Card or GP Visit Card and the visit is covered by the card, the scheme conditions may allow a free appointment.</p><!-- /wp:paragraph -->' . "\n\n";
$c .= '<!-- wp:heading --><h2>Where to find one</h2><!-- /wp:heading -->' . "\n";
$c .= '<!-- wp:paragraph --><p><strong>Find a GP:</strong> <a href="https://www2.hse.ie/services/find-a-gp/" target="_blank" rel="noopener noreferrer">HSE &#8211; Find a GP</a></p><!-- /wp:paragraph -->' . "\n\n";
$c .= '<!-- wp:paragraph --><p><strong>Official links:</strong> <a href="https://www2.hse.ie/services/find-a-gp/" target="_blank" rel="noopener noreferrer">Find a GP through the official HSE finder</a></p><!-- /wp:paragraph -->' . "\n";
$c .= conexao_guide_en_know();
$c .= '<!-- wp:list --><ul>' . "\n" . '<li>You need to find a practice taking new patients; availability, registration criteria and distance vary</li>' . "\n" . '<li>If you have a Medical Card, the GP is <strong>free</strong></li>' . "\n" . '<li>In an emergency, call <strong>112</strong> or <strong>999</strong></li>' . "\n" . '<li>Outside opening hours, many practices have an out-of-hours service</li>' . "\n" . '</ul><!-- /wp:list -->' . "\n\n";
$c .= conexao_guide_en_faq_head();
$c .= '<!-- wp:heading --><h3>Do I need a PPS Number to register with a GP?</h3><!-- /wp:heading -->' . "\n";
$c .= '<!-- wp:paragraph --><p>Yes, a PPS Number is required to register with a GP in Ireland, especially if you want to use the Medical Card or GP Visit Card.</p><!-- /wp:paragraph -->' . "\n";
$c .= '<!-- wp:heading --><h3>Can I go straight to hospital without a GP?</h3><!-- /wp:heading -->' . "\n";
$c .= '<!-- wp:paragraph --><p>In an emergency, yes &#8211; call 112/999 or go to the Emergency Department. For non-urgent matters you should see a GP first.</p><!-- /wp:paragraph -->' . "\n\n\n";
$c .= conexao_guide_en_footer( '3 September 2026', array( 'HSE &#8211; Find a GP' => 'https://www2.hse.ie/services/find-a-gp/', 'Citizens Information &#8211; GP Services' => 'https://www.citizensinformation.ie/en/health/health-services/gp-and-hospital-services/gp-services/', 'HSE &#8211; Medical Cards' => 'https://www2.hse.ie/services/medical-cards/' ) );
	return $c;
}
