<?php
// Domestic violence EN body, part 1 (immediate danger, what it is, where to get help, safety plan).
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_dv_p1(): string {
$c = '';
$c .= g_p( 'If you are living with domestic violence in Ireland, <strong>you do not have to face it alone</strong>. Public services, specialist organisations, legal support, police protection and court measures are available.' ) . "\n\n";
$c .= g_p( 'Domestic violence is not only physical assault. It can also involve threats, control, sexual violence, emotional abuse, financial control, digital abuse and coercive behaviour.' ) . "\n";
$c .= g_h2( 'If you are in danger now' );
$c .= g_p( 'If you or someone else is in <strong>immediate danger</strong>, call <strong>999 or 112</strong> and ask An Garda Síochána for help.' ) . "\n";
$c .= g_p( 'You can also go to your nearest Garda Station. If you do not speak English well, that should not stop you from asking for help.' ) . "\n";
$c .= g_h2( 'What is domestic violence?' );
$c .= g_ul( array( 'physical assault;', 'threats or intimidation;', 'sexual violence or coercion;', 'psychological or emotional control;', 'financial control;', 'control through technology or social media;', 'stalking or unwanted contact;', 'coercive or controlling behaviour;', 'threats against children or family members;', 'destruction of property.' ) );
$c .= g_p( 'The Courts Service explains that domestic violence can be carried out by current or former partners, spouses, civil partners, people with whom you have had an intimate relationship, the other parent of your children and certain family members.' ) . "\n";
$c .= g_links( array( 'Apply for a domestic violence protection order' => 'https://www.courts.ie/guides/apply-for-a-domestic-violence-court-order' ) );
$c .= g_h2( 'Where to find help in Ireland?' );
$c .= g_h3( 'Women&#8217;s Aid' );
$c .= g_p( 'Women&#8217;s Aid runs a free, confidential national helpline for women experiencing domestic violence.' ) . "\n";
$c .= g_p( '<strong>1800 341 900 &#8212; 24 hours a day, 7 days a week.</strong>' ) . "\n";
$c .= g_h3( 'Men&#8217;s Aid' );
$c .= g_p( 'Men can also be victims of domestic violence. The HSE lists Men&#8217;s Aid among the national support services.' ) . "\n";
$c .= g_p( '<strong>01 554 3811</strong>' ) . "\n";
$c .= g_h3( 'National Male Advice Line' );
$c .= g_p( 'The HSE also lists the National Male Advice Line as a support service.' ) . "\n";
$c .= g_p( '<strong>1800 816 588</strong>' ) . "\n";
$c .= g_h3( 'HSE' );
$c .= g_p( 'The HSE holds information on domestic, sexual and gender-based violence services and lists national and local services.' ) . "\n";
$c .= g_h2( 'Do I need to report to the Garda immediately?' );
$c .= g_p( 'If there is immediate danger, call <strong>999 or 112</strong>. In other situations you can go to your local Garda Station to explain what happened, ask for guidance or make a report.' ) . "\n";
$c .= g_p( 'You can also contact specialist services before deciding whether to report. The important thing is to find a safe way to get guidance.' ) . "\n";
$c .= g_links( array( 'Report to / get help from the Garda' => 'https://www.garda.ie/en/crime/domestic-abuse/' ) );
$c .= g_h2( 'Make a safety plan' );
$c .= g_p( 'If it is safe to do so, consider preparing a plan for a possible emergency.' );
$c .= g_ul( array(
	'choose someone you trust;',
	'identify a safe place where you can stay;',
	'keep important documents accessible;',
	'have in mind how to ask for help in an emergency;',
	'if it is safe, prepare a bag with documents, keys, money, clothes and anything the children need;',
	'be careful when researching help if your phone or computer is being monitored.'
) );
$c .= g_p( '<strong>Do not do anything that increases your risk.</strong> If preparing documents or a bag could put you in danger, put your safety first.' );
return $c;
}
