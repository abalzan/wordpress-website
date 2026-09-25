<?php
// Lone parents benefits EN body, part 1 (sections 1-5).
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_loneparents_p1(): string {
$c = '';
$c .= g_p( 'Raising a child on your own is already a big challenge. For anyone living in Ireland as an immigrant, understanding which benefits, payments and supports may be available can be even more complicated.' );
$c .= g_p( 'Ireland has different schemes for families with children, but <strong>there is no single &#8220;single mother&#8221; or &#8220;single father&#8221; benefit that automatically entitles everyone to all the supports</strong>.' ) . "\n";
$c .= g_h2( 'What are the main benefits for lone parents?' );
$c .= g_ul( array( 'One-Parent Family Payment (OFP)', 'Jobseeker&#8217;s Transitional Payment (JST)', 'Child Benefit', 'Working Family Payment (WFP)', 'Single Person Child Carer Credit (SPCCC)', 'Domiciliary Care Allowance, where the child meets the criteria', 'Carer&#8217;s Allowance or Carer&#8217;s Benefit in certain situations', 'housing and income supports, depending on your financial situation' ) );
$c .= g_links( array( 'Explore and start other payments on MyWelfare' => 'https://services.mywelfare.ie/en/explore-services/' ) );
$c .= g_h2( '1. One-Parent Family Payment' );
$c .= g_p( 'The <strong>One-Parent Family Payment (OFP)</strong> is one of the main payments for people raising children without the support of a partner. It is generally a payment subject to a <strong>means test</strong>, where certain income and resources are assessed.' ) . "\n";
$c .= g_p( 'The rules depend on your individual situation and include requirements linked to the child&#8217;s age, income and family situation.' ) . "\n";
$c .= g_links( array( 'Check the One-Parent Family Payment' => 'https://www.gov.ie/en/department-of-social-protection/services/one-parent-family-payment/' ) );
$c .= g_h3( 'Can I work and still receive the OFP?' );
$c .= g_p( 'The fact that you work does not necessarily mean the payment is automatically cancelled. The OFP is subject to an income assessment under the scheme rules.' );
$c .= g_h3( 'What about child maintenance?' );
$c .= g_p( 'The rules on child maintenance have changed. Do not use old information found on blogs or videos without checking the current rule directly with the Department of Social Protection.' ) . "\n";
$c .= g_h2( '2. Jobseeker&#8217;s Transitional Payment' );
$c .= g_p( 'The <strong>Jobseeker&#8217;s Transitional Payment (JST)</strong> was created to help certain lone parents who stop receiving the One-Parent Family Payment because their youngest child reaches the OFP age limit. The JST has its own eligibility rules.' ) . "\n";
$c .= g_links( array( 'Check the Jobseeker&#8217;s Transitional Payment' => 'https://www.gov.ie/en/department-of-social-protection/services/jobseekers-transitional-payment/' ) );
$c .= g_h2( '3. Child Benefit' );
$c .= g_p( '<strong>Child Benefit</strong> is different from the OFP. It is a payment linked to the child and can be received by families who meet the scheme criteria. A lone parent should check Child Benefit even if they are not entitled to the OFP.' ) . "\n";
$c .= g_links( array( 'Apply for Child Benefit' => 'https://services.mywelfare.ie/topics/parents-children-family/child-benefit/', 'Check Child Benefit' => 'https://www.gov.ie/en/department-of-social-protection/services/child-benefit/' ) );
$c .= g_h2( '4. Working Family Payment' );
$c .= g_p( 'If you work but your family income is relatively low, the <strong>Working Family Payment (WFP)</strong> is worth looking at. The scheme is for workers with children whose family income is below the limit that applies to their family size.' ) . "\n";
$c .= g_p( 'A common mistake is to think: &#8220;I work, so I am not entitled to any benefit.&#8221; That is not necessarily true.' ) . "\n";
$c .= g_links( array( 'Apply for the Working Family Payment' => 'https://services.mywelfare.ie/en/topics/parents-children-family/working-family-payment/', 'Check and apply for the Working Family Payment' => 'https://www.gov.ie/WFP' ) );
$c .= g_h2( '5. Single Person Child Carer Credit' );
$c .= g_p( 'The <strong>Single Person Child Carer Credit (SPCCC)</strong> is a tax credit administered by Revenue. For 2026 the credit is <strong>€1,900 per year</strong>. Anyone who qualifies may also have an additional band of income taxed at 20%, under Revenue&#8217;s rules.' ) . "\n";
$c .= g_links( array( 'Check and claim the SPCCC' => 'https://www.revenue.ie/en/personal-tax-credits-reliefs-and-exemptions/children/single-person-child-carer-credit/index.aspx' ) );
$c .= g_h3( 'Who can apply?' );
$c .= g_p( 'You must meet Revenue&#8217;s conditions to be considered eligible and have a qualifying child. There are specific rules for separated and widowed people and for those who share the care of the child.' );
$c .= g_h3( 'How do I apply?' );
$c .= g_p( 'If you are PAYE you can claim through Revenue myAccount, under <em>Manage your tax for the current year</em>, <em>PAYE Services</em>, <em>Claim tax credits</em> and <em>You and your family</em>. If you are self-employed, follow Revenue&#8217;s instructions for ROS.' );
return $c;
}
