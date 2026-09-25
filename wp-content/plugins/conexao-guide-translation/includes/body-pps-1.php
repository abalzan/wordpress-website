<?php
// PPS EN body part 1.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_pps_p1(): string {
$c = '';
$c .= '<!-- wp:heading --><h2>What is the PPS Number?</h2><!-- /wp:heading -->' . "\n";
$c .= '<!-- wp:paragraph --><p>The <strong>PPS Number (Personal Public Service Number)</strong> is the most important personal identification number in Ireland. It is a unique number with 7 digits followed by 1 or 2 letters (example: 1234567A). It is used to access public services, work, pay tax, receive social welfare payments and access the health system.</p><!-- /wp:paragraph -->' . "\n\n";
$c .= '<!-- wp:paragraph --><p><strong>Official links:</strong> <a href="https://www.gov.ie/en/department-of-social-protection/services/get-a-personal-public-service-pps-number/" target="_blank" rel="noopener noreferrer">Official information about the PPS Number</a></p><!-- /wp:paragraph -->' . "\n";
$c .= '<!-- wp:heading --><h2>Who needs a PPS Number?</h2><!-- /wp:heading -->' . "\n";
$c .= '<!-- wp:paragraph --><p>You need a PPS Number if you are going to:</p><!-- /wp:paragraph -->' . "\n";
$c .= '<!-- wp:list --><ul>' . "\n" . '<li>Work in Ireland (employed or self-employed)</li>' . "\n" . '<li>Pay tax (Income Tax, USC, PRSI)</li>' . "\n" . '<li>Access health services (Medical Card, GP Visit Card)</li>' . "\n" . '<li>Apply for social welfare payments (Child Benefit, Jobseeker\'s, etc.)</li>' . "\n" . '<li>Open a bank account (some banks require it)</li>' . "\n" . '<li>Buy or rent a property (in some cases)</li>' . "\n" . '</ul><!-- /wp:list -->' . "\n\n";
$c .= '<!-- wp:paragraph --><p><strong>Official links:</strong> <a href="https://www.mywelfare.ie/" target="_blank" rel="noopener noreferrer">Apply for the PPS Number on MyWelfare</a></p><!-- /wp:paragraph -->' . "\n";
$c .= '<!-- wp:heading --><h2>What you need to apply</h2><!-- /wp:heading -->' . "\n";
$c .= '<!-- wp:paragraph --><p>To apply for the PPS Number, you need:</p><!-- /wp:paragraph -->' . "\n";
$c .= '<!-- wp:list --><ul>' . "\n" . '<li><strong>Identity document</strong>: valid passport (Brazilian or another country)</li>' . "\n" . '<li><strong>Proof of address in Ireland</strong>: an electricity, water or internet bill, rental contract or a letter from a public body</li>' . "\n" . '<li><strong>Proof that you need the PPS Number</strong>: a job offer letter, a letter from the Department of Social Protection, or another document showing why you need it</li>' . "\n" . '</ul><!-- /wp:list -->' . "\n\n";
return $c;
}
