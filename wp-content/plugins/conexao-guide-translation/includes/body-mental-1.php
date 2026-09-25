<?php
// Winter / mental health EN body, part 1.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_mental_p1(): string {
$c = '';
$c .= g_p( 'Winter in Ireland can be challenging. The days get shorter, there is less natural light, temperatures drop and many people spend more time indoors. For anyone living far from family and home country, this period can also bring longing, loneliness and isolation.' );
$c .= g_p( 'For some people, the seasonal changes can significantly affect their mental health. This guide explains how to recognise the signs of seasonal depression, when to seek help and where to find support in Ireland.' ) . "\n";
$c .= g_h2( '🚨 If you are at immediate risk' );
$c .= g_p( 'If you or someone else is at <strong>immediate risk</strong>, do not wait for an online answer. Call <strong>112 or 999</strong> or go to a hospital <strong>Emergency Department</strong>.' ) . "\n";
$c .= g_p( 'The HSE also advises seeing a GP or an out-of-hours GP service when needed.' ) . "\n";
$c .= g_h2( '🌧️ Can winter affect our mental health?' );
$c .= g_p( 'Yes. Some people experience a type of depression linked to the changes of the seasons called <strong>Seasonal Affective Disorder (SAD)</strong>, known in Portuguese as seasonal depression or winter depression.' ) . "\n";
$c .= g_p( 'According to the HSE, symptoms usually start in autumn or winter and tend to improve during spring. For some people they are mild; for others they can significantly interfere with everyday life.' ) . "\n";
$c .= g_h3( 'Possible signs' );
$c .= g_ul( array(
	'persistent sadness or low mood;',
	'loss of interest or pleasure in activities;',
	'irritability;',
	'tiredness and lack of energy;',
	'anxiety or stress;',
	'feelings of guilt, worthlessness or hopelessness;',
	'frequent crying;',
	'low self-esteem;',
	'social isolation;',
	'changes in sleep;',
	'loss of interest in usual activities;',
	'thoughts about death or suicide.'
) );
$c .= g_p( 'Having some of these symptoms does not automatically mean a person has SAD or depression. Persistent or significant changes in mood and behaviour deserve attention.' ) . "\n";
$c .= g_p( 'If symptoms of depression are present most of the day, every day, for more than two weeks, the HSE recommends talking to a GP.' ) . "\n";
$c .= g_h2( '🇧🇷 And for those living far from home?' );
$c .= g_p( 'For many immigrants, winter brings feelings that go beyond the weather: missing family, friends, food, sunshine and the feeling of being at home.' ) . "\n";
$c .= g_p( 'It is possible to love the life you have built in Ireland and still miss the life you left behind. Someone can be working, smiling and looking after everyone and still not be emotionally well.' ) . "\n";
$c .= g_p( '<strong>Not all suffering is visible.</strong>' ) . "\n";
$c .= g_h2( '💭 &#8220;I&#8217;m fine.&#8221;' );
$c .= g_p( 'Sometimes the person who says &#8220;I&#8217;m fine&#8221; is just trying to get through the day. One simple question can make a difference: <strong>&#8220;How are you, really?&#8221;</strong>' ) . "\n";
$c .= g_p( 'And, most importantly: be ready to hear the answer.' ) . "\n";
$c .= g_h2( '🫂 How to help someone who is not well?' );
$c .= g_ul( array(
	'❤️ Ask how they are.',
	'❤️ Listen without judgement.',
	'❤️ Show them they are not alone.',
	'❤️ Avoid phrases that minimise their suffering.',
	'❤️ Encourage them to seek professional help.',
	'❤️ Stay in contact, especially if they are isolating themselves.'
) );
$c .= g_p( 'You do not need to be a psychologist to show concern. A conversation can be the first step for someone to accept seeking help.' ) . "\n";
$c .= g_p( 'The HSE also advises that support services can be contacted when we are worried about someone else, not only when we are going through a personal crisis.' ) . "\n";
$c .= g_h2( '🚨 And when someone talks about suicide?' );
$c .= g_p( '<strong>Take it seriously.</strong> If someone says they do not want to live any more, that they cannot take it any more, or shows suicidal thoughts, do not ignore it and do not simply try to convince them to &#8220;think positively&#8221;.' ) . "\n";
$c .= g_ul( array( 'Listen.', 'Stay close, when it is safe to do so.', 'Encourage them to seek help.', 'Use the services available.' ) );
$c .= g_p( 'The HSE advises that when someone is thinking about suicide it is important to tell someone and to seek support. The person does not have to face those feelings alone.' );
return $c;
}
