<?php
// Winter / mental health EN body, part 3 (language, prevention, closing, sources).
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_mental_p3(): string {
$c = '';
$c .= g_h2( '🌍 Do you not speak perfect English?' );
$c .= g_p( 'Do not let the language barrier stop you from seeking help. When contacting a health or emergency service, explain that you need help communicating and ask about the available options.' ) . "\n";
$c .= g_h2( '🌱 Looking after your mental health is also prevention' );
$c .= g_p( 'Prevention starts before a crisis: when we ask how someone is, we notice changes in behaviour and stop treating emotional suffering as weakness.' ) . "\n";
$c .= g_p( 'Seeking a GP, a psychologist or a support service does not mean someone has failed. Asking for help can be an important step in looking after yourself.' ) . "\n";
$c .= g_h2( '💛 You do not have to get through the winter alone' );
$c .= g_p( 'If the days are hard, reach out to someone. Talk. Ask for help. Listen to whoever needs it. Stay close to the people who make you feel looked after.' ) . "\n";
$c .= g_p( '<strong>Winter passes.</strong>' );
$c .= g_p( 'You do not have to solve everything today. Sometimes it is enough to take the next step.' ) . "\n";
$c .= g_p( '<strong>Talk. Listen. Hold space. Seek help.</strong>' ) . "\n";
$c .= g_h2( '💛 A message from Conexão BR Ireland' );
$c .= g_p( 'We are a community of Brazilians living in Ireland. And we believe that connection also means looking after one another.' ) . "\n";
$c .= g_p( 'If this article made you think of someone, <strong>send it to them</strong>. Maybe they need exactly this information today.' ) . "\n";
$c .= g_p( '<strong>You are not alone. 🇧🇷🇮🇪💛</strong>' ) . "\n";
$links = array(
	'HSE - Seasonal Affective Disorder (SAD)' => 'https://www2.hse.ie/conditions/seasonal-affective-disorder/',
	'HSE - Help for thoughts about suicide' => 'https://www2.hse.ie/mental-health/services-support/suicide/',
	'HSE - Get urgent help for a mental health crisis' => 'https://www2.hse.ie/mental-health/services-support/get-urgent-help/',
	'HSE - Your Mental Health Information Line' => 'https://www2.hse.ie/mental-health/services-support/your-mental-health-information-line/',
	'Samaritans Ireland' => 'https://www.samaritans.org/samaritans-ireland/',
	'Pieta - Crisis Helpline' => 'https://www.pieta.ie/how-we-can-help/helpline/',
	'Text About It' => 'https://textaboutit.ie/',
);
$c .= g_plain_links( 'Sources and official information', $links );
$c .= g_h2( 'Last checked' );
$c .= g_p( '<strong>Information checked: 31 August 2026.</strong>' );
$c .= g_p( 'Phone numbers, services, opening hours and ways of accessing them can change. Always confirm the contacts directly on the official pages before acting.' );
$c .= g_p( '<em>Notice: This guide is for information only and does not replace a professional assessment, diagnosis or treatment. In an emergency or a situation of immediate risk, call 112 or 999 or go to a hospital.</em>' );
$c .= g_sep();
return $c;
}
function conexao_guide_en_mental(): string {
	return conexao_guide_en_mental_p1() . conexao_guide_en_mental_p2() . conexao_guide_en_mental_p3();
}
