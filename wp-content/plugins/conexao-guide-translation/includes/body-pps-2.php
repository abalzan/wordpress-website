<?php
// PPS EN body part 2.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_pps_p2(): string {
$c = '';
$c .= '<!-- wp:heading --><h2>How to apply</h2><!-- /wp:heading -->' . "\n";
$c .= '<!-- wp:paragraph --><p>The process is run by the <strong>Department of Social Protection (DSP)</strong> through MyWelfare. For people over 18, the official service allows you to apply for the PPS Number online; if you cannot use the online system, there is the REG1 form.</p><!-- /wp:paragraph -->' . "\n";
$c .= '<!-- wp:list --><ol>' . "\n" . '<li>Make the application through <strong>MyWelfare</strong> and follow the Department of Social Protection instructions</li>' . "\n" . '<li>Provide proof of identity, address and the reason you need the PPSN</li>' . "\n" . '<li>If you cannot use the online service, use the <strong>REG1</strong> form</li>' . "\n" . '<li>The DSP will inform you of the outcome and next steps</li>' . "\n" . '</ol><!-- /wp:list -->' . "\n\n";
$c .= '<!-- wp:heading --><h2>How much does it cost?</h2><!-- /wp:heading -->' . "\n";
$c .= '<!-- wp:paragraph --><p>The PPS Number is <strong>free</strong>. There is no fee to apply.</p><!-- /wp:paragraph -->' . "\n\n";
$c .= '<!-- wp:heading --><h2>How long does it take?</h2><!-- /wp:heading -->' . "\n";
$c .= '<!-- wp:paragraph --><p>Processing can take from <strong>a few days to a few weeks</strong>, depending on application volumes and whether you need an interview. The DSP does not publish a guaranteed fixed turnaround time.</p><!-- /wp:paragraph -->' . "\n\n";
$c .= '<!-- wp:heading --><h2>Where to apply</h2><!-- /wp:heading -->' . "\n";
$c .= '<!-- wp:paragraph --><p><strong>Apply for a PPS Number:</strong> <a href="https://www.gov.ie/en/department-of-social-protection/services/get-a-personal-public-service-pps-number/" target="_blank" rel="noopener noreferrer">gov.ie - Get a PPS Number</a></p><!-- /wp:paragraph -->' . "\n\n";
$c .= conexao_guide_en_know();
$c .= '<!-- wp:list --><ul>' . "\n" . '<li>The PPS Number is <strong>personal and non-transferable</strong>. Never share it with third parties.</li>' . "\n" . '<li>You can only apply if <strong>you need</strong> the number for a specific service. You cannot apply “just in case”.</li>' . "\n" . '<li>If you have had a PPS Number before, use the same number.</li>' . "\n" . '<li>Keep the card or letter with your PPS Number in a safe place.</li>' . "\n" . '</ul><!-- /wp:list -->' . "\n\n";
$c .= conexao_guide_en_faq_head();
$c .= '<!-- wp:heading --><h3>Can I work without a PPS Number?</h3><!-- /wp:heading -->' . "\n";
$c .= '<!-- wp:paragraph --><p>If you have started work and have not received your PPSN yet, talk to your employer and to Revenue about registering the job correctly.</p><!-- /wp:paragraph -->' . "\n";
$c .= '<!-- wp:heading --><h3>Do I need a visa to apply for a PPS Number?</h3><!-- /wp:heading -->' . "\n";
$c .= '<!-- wp:paragraph --><p>You need to be legally in Ireland. The PPSN is allocated when there is a valid reason to have the number. The right to work depends on your immigration status.</p><!-- /wp:paragraph -->' . "\n";
$c .= '<!-- wp:heading --><h3>Does the PPS Number expire?</h3><!-- /wp:heading -->' . "\n";
$c .= '<!-- wp:paragraph --><p>No. The PPS Number is permanent and does not expire, even if you leave Ireland and come back later.</p><!-- /wp:paragraph -->' . "\n\n\n";
$c .= conexao_guide_en_footer( '3 September 2026', array( 'gov.ie - Get a PPS Number' => 'https://www.gov.ie/en/department-of-social-protection/services/get-a-personal-public-service-pps-number/', 'Citizens Information - PPS Number' => 'https://www.citizensinformation.ie/en/social-welfare/irish-social-welfare-system/personal-public-service-number/', 'MyWelfare - social services portal' => 'https://www.mywelfare.ie/' ) );
return $c;
}
function conexao_guide_en_pps(): string { return conexao_guide_en_pps_p1() . conexao_guide_en_pps_p2(); }
