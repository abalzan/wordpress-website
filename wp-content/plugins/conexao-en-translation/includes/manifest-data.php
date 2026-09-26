<?php
/**
 * Stage M authored English translations — the DATA half of the stage.
 *
 * Versioned, human-reviewable, and separate from the adapter so the copy can be
 * proofread without touching the rollout logic. Keyed by PT post type and then
 * by the PT slug, which is the ONLY portable identity (standard 0.4).
 *
 * Every `en_content` is authored English, never a copy of the PT body, and every
 * entry carries its own English slug, excerpt and meta description.
 *
 * @package Conexao_EN_Translation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The authored manifest data.
 *
 * @return array<string,array<string,array<string,string>>>
 */
function conexao_en_translation_manifest_data(): array {
	return array(
		// guide
		'guide' => array(
			'outono-irlanda-alimentacao-bem-estar' => array(
				'en_slug' => 'autumn-in-ireland-eating-and-wellbeing',
				'en_title' => 'Autumn has arrived in Ireland… and so has the change in our bodies',
				'en_excerpt' => 'For Brazilians living in Ireland, autumn brings shorter days and changes to your routine. Here are simple ideas for adapting your diet, staying hydrated and looking after your wellbeing through the colder months.',
				'en_meta_description' => 'See how to adapt your diet and habits to autumn in Ireland, with ideas for warm meals, fruit, hydration and wellbeing.',
				'en_content' => '<!-- wp:paragraph -->
<p>For us Brazilians who live in Ireland, the arrival of autumn can be a real change.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Days start getting shorter, we have fewer hours of daylight, temperatures drop, and little by little our routine changes too. We start to notice differences in our energy, our mood, our appetite, and even that urge to get out of the house and move around.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Autumn changes our routine</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>And our diet follows that change too.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Perhaps that salad that tasted so good in summer is not quite as appealing now. The chilled smoothie loses its appeal and we start looking for warmer, more comforting food.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p><strong>And that is perfectly fine. 💚</strong></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>A healthy diet does not have to look the same all year round. We can adapt our choices to the seasons, respecting our preferences while keeping a balanced and nutritious way of eating.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Adapting your diet to autumn</h2>
<!-- /wp:heading -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">🍲 Not in the mood for salad?</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>You do not have to force yourself. Soups, stews, roasted vegetables, sautéed or grilled vegetables are all great ways to keep getting vegetables into your meals.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">🍎 What about fruit?</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Fruit can be given a cosier treatment too. Try baking apples or pears with cinnamon, stewing fruit, or adding fruit to dishes such as banana pancakes, warm overnight oats, or a homemade apple cake.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">💧 Have you noticed you are drinking less water?</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>That can happen when the cold sets in. Keep your bottle close by and take the opportunity to add sugar-free teas and infusions throughout the day. They can contribute to your hydration too.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">🥣 Use warm dishes to widen your variety</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Soups and the famous Irish <em>stews</em> are a great opportunity to include different vegetables, beans, lentils, chickpeas and other pulses. Warm, comforting food can be nutritious as well.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Adapt, do not fight the season</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>And that may be one of the most important things to remember at this time of year: we do not have to fight against the season.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>We can adapt our diet, our routine and our habits to what this time of year is asking of us, without giving up on looking after our health.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Autumn Survival Guide for Ireland 🍂</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>That is why I have put together an Autumn Survival Guide for Ireland 🍂, with practical tips to help you get through the colder months while looking after your diet and your wellbeing. I will leave the guide here for you all. 💚</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">One-to-one support</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>And if you feel you need more individualised support — whether for weight loss and weight management, high cholesterol, pre-diabetes, improving your relationship with food, or simply building healthier habits that really work in your routine here in Ireland — send me a message.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>It would be a pleasure to work with you. 🌿</p>
<!-- /wp:paragraph -->

<!-- wp:separator --><hr class="wp-block-separator has-alpha-channel-opacity" /><!-- /wp:separator -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">About Marcela Ferreira</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><strong>Nutritionist Marcela Ferreira</strong><br />Online and in-person consultations in Kilcock<br />Instagram: <strong>@marcela.holisticnutrition</strong></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>📲 Get in touch on WhatsApp for information and appointments.</p>
<!-- /wp:paragraph -->
',
			),
		),
		// page
		'page' => array(
		),
		// post
		'post' => array(
		),
	);
}
