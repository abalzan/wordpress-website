<?php
// Autism diagnosis in Ireland EN body, part 1.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_autism_p1(): string {
$c = '';
$c .= g_h2( 'Autism in Ireland: diagnosis, the HSE and where to find support' );
$c .= g_p( 'For Brazilians living in Ireland who are autistic &#8212; or who have a child who may be autistic &#8212; it is important to understand how the Irish health system works. The HSE provides information and different kinds of autism-related support, but the route depends on the person&#8217;s age, their needs and the services available in the area where they live.' );
$c .= '<!-- wp:quote --><blockquote class="wp-block-quote is-layout-flow wp-block-quote-is-layout-flow"><p><strong>Important:</strong> this guide is for information and does not replace a medical, psychological or individual professional assessment.</p></blockquote><!-- /wp:quote -->' . "\n";
$c .= g_links( array(
	'HSE information on autism assessment and support' => 'https://www2.hse.ie/conditions/autism/assessment-and-support/assessment-autism/',
	'Find HSE autism-related support' => 'https://www2.hse.ie/conditions/autism/assessment-and-support/where-to-get-support/',
	'HSE - Autism' => 'https://www2.hse.ie/conditions/autism/'
) );
$c .= g_h2( 'Who is this guide for?' );
$c .= g_ul( array( 'autistic adults;', 'parents or carers of autistic children;', 'adults who suspect they may be autistic;', 'families moving to Ireland;', 'Brazilians who already have a diagnosis from Brazil and want to understand which supports they can seek in Ireland.' ) ) . "\n";
$c .= g_h2( 'How does autism assessment work in Ireland?' );
$c .= g_p( 'The route varies by age. For children, the HSE describes different assessment and support pathways through health and disability services. For adults, the current HSE information is different: the HSE states that it does not provide adult autism diagnostic assessment, and advises anyone seeking an assessment to consider a private assessment with a psychologist.' ) . "\n";
$c .= g_p( 'Check the official HSE page on ' . g_a( 'autism assessment', 'https://www2.hse.ie/conditions/autism/assessment-and-support/assessment-autism/' ) . ' before starting the process, because services, referrals and availability can change.' ) . "\n";
$c .= g_h2( 'If the person is a child' );
$c .= g_p( 'If you believe your child may be autistic, the HSE recommends speaking to a health or education professional. Depending on the situation, the route may involve the GP, the Public Health Nurse, the Primary Care Team, the Children&#8217;s Disability Network Team (CDNT) or the school.' ) . "\n";
$c .= g_ul( array(
	'book an appointment with the GP, or speak to the health professional who already looks after the child;',
	'explain which behaviours, communication needs, learning difficulties or sensory issues led you to seek help;',
	'ask which local service is the right one;',
	'keep relevant reports and documentation ready to show professionals.'
) );
$c .= g_h2( 'If the child already has a diagnosis in Brazil' );
$c .= g_p( 'A diagnosis carried out in Brazil can help explain the child&#8217;s history to the professionals who will support them in Ireland. Bring diagnostic reports, school reports, therapy information and other relevant documents.' ) . "\n";
$c .= g_ul( array(
	'diagnostic report;',
	'reports from psychologists, therapists or doctors;',
	'school reports;',
	'information about therapies carried out;',
	'a list of medication, where applicable;',
	'a description of communication and sensory needs.'
) );
$c .= g_p( 'If the documents are in Portuguese, check in advance with the service receiving them whether a translation will be required. Do not assume a certified translation is mandatory: the requirement depends on the service and the purpose of the documents.' ) . "\n";
$c .= g_h2( 'Adults who suspect they are autistic' );
$c .= g_p( 'The HSE currently states that it does not provide adult autism diagnostic assessment. The official advice is to seek a private assessment. The HSE also points to professional organisations such as the Psychological Society of Ireland (PSI) as a reference for finding professionals.' ) . "\n";
$c .= g_p( 'Before booking, confirm directly with the professional what type of assessment is offered, the cost, the timeframe and which documents will be needed. ' . g_a( 'HSE - adult autism assessment', 'https://www2.hse.ie/conditions/autism/assessment-and-support/assessment-autism/' ) ) . "\n";
$c .= g_h2( 'Is it possible to get support without a diagnosis?' );
$c .= g_p( 'The HSE states that a person does not necessarily have to wait for a formal diagnosis to access certain services. A health professional can advise on the most suitable service for the needs presented.' ) . "\n";
$c .= g_p( 'This can be especially relevant for families waiting for an assessment. See ' . g_a( 'HSE - where to get support', 'https://www2.hse.ie/conditions/autism/assessment-and-support/where-to-get-support/' ) . ' and talk to the GP or another professional who supports the person.' ) . "\n";
$c .= g_h2( 'What kinds of support may be available?' );
$c .= g_p( 'Support depends on individual needs. The HSE describes different services and professionals who may be involved, including psychologists, psychiatrists, occupational therapists, speech and language therapists, disability services and mental health services where needed.' ) . "\n";
$c .= g_p( 'The HSE also points to organisations such as AsIAm and the Irish Society for Autism as sources of information and support. ' . g_a( 'HSE - where to get support', 'https://www2.hse.ie/conditions/autism/assessment-and-support/where-to-get-support/' ) ) . "\n";
return $c;
}
