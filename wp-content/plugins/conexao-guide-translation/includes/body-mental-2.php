<?php
// Winter / mental health EN body, part 2 (contacts table and support services).
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_mental_p2(): string {
$c = '';
$c .= g_h2( '💛 Where to get help in Ireland' );
$c .= g_table( array( 'Situation', 'Contact' ), array(
	array( '🚨 Emergency', '<strong>112 or 999</strong>' ),
	array( '💛 Samaritans', '<strong>116 123 &#8212; 24/7</strong>' ),
	array( '💛 Pieta', '<strong>1800 247 247 &#8212; 24/7</strong>' ),
	array( '💬 Text About It', '<strong>HELLO to 50808 &#8212; 24/7</strong>' ),
	array( '🩺 HSE Mental Health Information Line', '<strong>1800 111 888</strong>' ),
	array( '👨‍⚕️ Medical care', '<strong>GP / GP out of hours / Emergency Department</strong>' ),
) );
$c .= g_h3( '📞 Samaritans' );
$c .= g_p( 'A confidential, non-judgemental emotional support service.' );
$c .= g_p( '<strong>116 123</strong> &#8212; available 24 hours a day, every day.' );
$c .= g_p( 'Email: <a href="mailto:jo@samaritans.ie">jo@samaritans.ie</a>' );
$c .= g_p( g_a( 'Samaritans Ireland - official website', 'https://www.samaritans.org/samaritans-ireland/' ) );
$c .= g_links( array( '24/7 emotional support - Samaritans Ireland' => 'https://www.samaritans.org/samaritans-ireland/' ) );
$c .= g_h3( '💛 Pieta' );
$c .= g_p( 'Pieta supports people who are experiencing suicidal thoughts or self-harm, and people affected by someone else&#8217;s suicide.' ) . "\n";
$c .= g_p( '<strong>Crisis Helpline: 1800 247 247 &#8212; 24 hours.</strong>' );
$c .= g_p( '<strong>Text support: send HELP to 51444.</strong> The message may be charged according to your plan.' );
$c .= g_p( 'Pieta also offers therapy for people affected by suicidal thoughts, self-harm or bereavement by suicide. The service can be reached on <strong>0818 111 126</strong>, subject to the criteria and services available.' );
$c .= g_p( g_a( 'Pieta - official website', 'https://www.pieta.ie/' ) );
$c .= g_links( array( 'Crisis support - Pieta' => 'https://www.pieta.ie/' ) );
$c .= g_h3( '💬 Text About It' );
$c .= g_p( 'For anyone who is not comfortable talking on the phone, there is a text-based alternative.' ) . "\n";
$c .= g_p( '<strong>Send HELLO to 50808.</strong>' );
$c .= g_p( 'It is a free text conversation service, available 24 hours a day, offering emotional support and help in crisis situations.' );
$c .= g_p( g_a( 'Text About It - official website', 'https://textaboutit.ie/' ) );
$c .= g_links( array( 'Text About It' => 'https://textaboutit.ie/' ) );
$c .= g_h2( '🩺 Talk to your GP' );
$c .= g_p( 'If you notice that your mood has changed, that you are constantly without energy, have lost interest in things, are isolating yourself or feel you are not coping, talk to your <strong>GP (General Practitioner)</strong>.' ) . "\n";
$c .= g_p( 'You do not have to wait until a crisis to seek help.' ) . "\n";
$c .= g_p( 'The HSE provides information on mental health services and can point you to where to find support.' ) . "\n";
$c .= g_h3( 'HSE Mental Health Information Line' );
$c .= g_p( '<strong>1800 111 888</strong>' );
$c .= g_p( 'This is an information line about mental health services. It does not replace an emergency service or clinical advice.' );
$c .= g_p( g_a( 'HSE - Your Mental Health Information Line', 'https://www2.hse.ie/mental-health/services-support/your-mental-health-information-line/' ) );
$c .= g_links( array(
	'HSE information on Seasonal Affective Disorder (SAD)' => 'https://www2.hse.ie/conditions/seasonal-affective-disorder/',
	'HSE - Help for thoughts about suicide' => 'https://www2.hse.ie/mental-health/services-support/suicide/',
	'HSE - Get urgent help for a mental health crisis' => 'https://www2.hse.ie/mental-health/services-support/get-urgent-help/',
	'HSE - Your Mental Health Information Line' => 'https://www2.hse.ie/mental-health/services-support/your-mental-health-information-line/'
) );
return $c;
}
