<?php
// Medical Card EN body p1.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_medical_p1(): string {
$c = '';
$c .= '<!-- wp:heading --><h2>What is the Medical Card?</h2><!-- /wp:heading -->' . "\n";
$c .= '<!-- wp:paragraph --><p>The <strong>Medical Card</strong> is a Health Service Executive (HSE) card that can give free access to certain health services. Eligibility normally involves a means test, and there are also specific situations in which a card can be granted on a discretionary basis.</p><!-- /wp:paragraph -->' . "\n\n";
$c .= '<!-- wp:paragraph --><p><strong>Official links:</strong> <a href="https://www2.hse.ie/services/schemes-allowances/medical-cards/applying/apply/" target="_blank" rel="noopener noreferrer">Apply for a Medical Card or GP Visit Card online</a></p><!-- /wp:paragraph -->' . "\n";
$c .= '<!-- wp:heading --><h2>Who is entitled?</h2><!-- /wp:heading -->' . "\n";
$c .= '<!-- wp:paragraph --><p>To qualify for the Medical Card, you must be <strong>ordinarily resident</strong> in Ireland (living here and intending to live here for at least one year) and pass the income test. The test looks at the family’s weekly <strong>net</strong> income (after tax, PRSI and USC).</p><!-- /wp:paragraph -->' . "\n\n";
$c .= '<!-- wp:heading --><h2>Income limits and assessment rules</h2><!-- /wp:heading -->' . "\n";
$c .= '<!-- wp:table --><figure class="wp-block-table"><table><thead><tr><th>Category</th><th>Medical Card (up to 66)</th><th>Medical Card (66-70)</th><th>GP Visit Card</th></tr></thead><tbody>' . "\n";
$c .= '<tr><td>Single person living alone</td><td>€184</td><td>€201.50</td><td>€418</td></tr>' . "\n";
$c .= '<tr><td>Single person living with family</td><td>€164</td><td>€173.50</td><td>€373</td></tr>' . "\n";
$c .= '<tr><td>Couple (or lone parent with children)</td><td>€266.50</td><td>€298</td><td>€607</td></tr>' . "\n";
$c .= '<tr><td>Extra for a child under 16 (1st and 2nd)</td><td>€38</td><td>€38</td><td>€57</td></tr>' . "\n";
$c .= '<tr><td>Extra for a child under 16 (3rd+)</td><td>€41</td><td>€41</td><td>€61.50</td></tr>' . "\n";
$c .= '</tbody></table></figure><!-- /wp:table -->' . "\n\n";
return $c;
}
