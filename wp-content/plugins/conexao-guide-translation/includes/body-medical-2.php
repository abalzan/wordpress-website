<?php
// Medical Card EN body p2.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_medical_p2(): string {
$c = '';
$c .= '<!-- wp:heading --><h2>What does the Medical Card cover?</h2><!-- /wp:heading -->' . "\n";
$c .= '<!-- wp:list --><ul>' . "\n" . '<li><strong>Free</strong> GP (family doctor) appointments</li>' . "\n" . '<li>Prescribed medicines at a reduced charge (€2 per item, with a monthly cap of €80 per family)</li>' . "\n" . '<li><strong>Free</strong> public hospital care (including surgery and inpatient stays)</li>' . "\n" . '<li>Tests and treatment in public hospitals</li>' . "\n" . '<li>Maternity services</li>' . "\n" . '<li>Dental, eye and hearing services (in some cases)</li>' . "\n" . '</ul><!-- /wp:list -->' . "\n\n";
$c .= '<!-- wp:heading --><h2>How to apply</h2><!-- /wp:heading -->' . "\n";
$c .= '<!-- wp:list --><ol>' . "\n" . '<li>Download the <strong>MC1</strong> form on the HSE website or request it by post</li>' . "\n" . '<li>Fill it in with your details and your family’s details</li>' . "\n" . '<li>Send the form with the supporting documents (identity, proof of residence, proof of income)</li>' . "\n" . '<li>The HSE reviews your application and sends the decision by post</li>' . "\n" . '</ol><!-- /wp:list -->' . "\n\n";
$c .= '<!-- wp:heading --><h2>How much does it cost?</h2><!-- /wp:heading -->' . "\n";
$c .= '<!-- wp:paragraph --><p>There is no fee to apply for the Medical Card. Check the income criteria and the applicable rules on the current HSE page.</p><!-- /wp:paragraph -->' . "\n\n";
$c .= '<!-- wp:heading --><h2>Where to apply</h2><!-- /wp:heading -->' . "\n";
$c .= '<!-- wp:paragraph --><p><strong>Apply for a Medical Card:</strong> <a href="https://www2.hse.ie/services/medical-cards/" target="_blank" rel="noopener noreferrer">HSE – Medical Cards</a></p><!-- /wp:paragraph -->' . "\n\n";
$c .= conexao_guide_en_know();
$c .= '<!-- wp:list --><ul>' . "\n" . '<li>The Medical Card is <strong>free</strong> for those who pass the income test</li>' . "\n" . '<li>If you do not pass the Medical Card test, you may qualify for the <strong>GP Visit Card</strong>, which covers free GP visits (but not medicines or hospital care)</li>' . "\n" . '<li>The card must be <strong>renewed</strong> periodically</li>' . "\n" . '<li>People with specific medical conditions may qualify even above the income limit (a <em>discretionary medical card</em>)</li>' . "\n" . '</ul><!-- /wp:list -->' . "\n\n";
$c .= conexao_guide_en_faq_head();
$c .= '<!-- wp:heading --><h3>Can Brazilians get a Medical Card?</h3><!-- /wp:heading -->' . "\n";
$c .= '<!-- wp:paragraph --><p>Yes, if you are ordinarily resident in Ireland (living here and intending to stay for at least 1 year) and pass the income test. A valid Employment Permit or other valid immigration status helps prove residence.</p><!-- /wp:paragraph -->' . "\n";
$c .= '<!-- wp:heading --><h3>What is the GP Visit Card?</h3><!-- /wp:heading -->' . "\n";
$c .= '<!-- wp:paragraph --><p>The GP Visit Card covers free family-doctor (GP) visits, but not medicines, tests or hospital care. The income limits are higher than for the Medical Card.</p><!-- /wp:paragraph -->' . "\n";
$c .= '<!-- wp:heading --><h3>Do I need a PPS Number to apply?</h3><!-- /wp:heading -->' . "\n";
$c .= '<!-- wp:paragraph --><p>You will need to provide the PPS Numbers of the people included in the application, plus dates of birth and income and expenses information.</p><!-- /wp:paragraph -->' . "\n\n\n";
$c .= conexao_guide_en_footer( '3 September 2026', array( 'HSE – Medical Cards' => 'https://www2.hse.ie/services/medical-cards/', 'Citizens Information – Medical Card' => 'https://www.citizensinformation.ie/en/health/medical-cards-and-gp-visit-cards/medical-card/', 'Citizens Information – Medical Card Means Test' => 'https://www.citizensinformation.ie/en/health/medical-cards-and-gp-visit-cards/medical-card-means-test-under-70s/' ) );
return $c;
}
function conexao_guide_en_medical(): string { return conexao_guide_en_medical_p1() . conexao_guide_en_medical_p2(); }
