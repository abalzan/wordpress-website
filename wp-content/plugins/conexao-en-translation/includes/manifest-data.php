<?php
/**
 * Stage M authored English translations — the DATA half of the stage.
 *
 * Versioned, human-reviewable, and separate from the adapter so the copy can be
 * proofread without touching the rollout logic. Keyed by PT post type and then
 * by the PT slug, which is the ONLY portable identity (standard §0.4).
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
		// guide — 1 record(s).
		'guide' => array(
			'outono-irlanda-alimentacao-bem-estar' => array(
				'en_slug'             => 'autumn-in-ireland-eating-and-wellbeing',
				'en_title'            => 'Autumn has arrived in Ireland… and so has the change in our bodies',
				'en_excerpt'          => 'For Brazilians living in Ireland, autumn brings shorter days and changes to your routine. Here are simple ideas for adapting your diet, staying hydrated and looking after your wellbeing through the colder months.',
				'en_meta_description' => 'See how to adapt your diet and habits to autumn in Ireland, with ideas for warm meals, fruit, hydration and wellbeing.',
				'en_content'          => '<!-- wp:paragraph -->
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
		// page — 29 record(s).
		'page'  => array(
			'politica-de-privacidade' => array(
				'en_slug'             => 'privacy-policy',
				'en_title'            => 'Privacy Policy',
				'en_excerpt'          => '',
				'en_meta_description' => 'Conexão BR Irlanda privacy policy. Learn how we collect, use and protect your information.',
				'en_content'          => '<!-- wp:heading --><h2>Privacy Policy</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>This Privacy Policy describes how Conexão BR Irlanda collects, uses and protects the personal information of users of our site.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Information we collect</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>We collect information you voluntarily provide us, such as your name and email address when you subscribe to our newsletter, and browsing information through cookies.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>How we use your information</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>We use your information to send newsletters, improve our content and personalise your experience on the site.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Your rights</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>You have the right to access, correct or delete your personal data at any time. Contact us to exercise these rights.</p><!-- /wp:paragraph -->
',
			),
			'termos-de-uso'           => array(
				'en_slug'             => 'terms-of-use',
				'en_title'            => 'Terms of Use',
				'en_excerpt'          => '',
				'en_meta_description' => 'Conexão BR Irlanda terms of use. The conditions for accessing and using the content of the portal.',
				'en_content'          => '<!-- wp:heading --><h2>Terms of Use</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>By accessing and using the Conexão BR Irlanda site, you agree to the following terms and conditions.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Use of content</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>All content on this site is provided for information purposes only. We are not responsible for decisions made on the basis of the information published here.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>External links</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Our site may contain links to external sites. We are not responsible for the content or privacy practices of those sites.</p><!-- /wp:paragraph -->
',
			),
			'cookies'                 => array(
				'en_slug'             => 'cookie-policy',
				'en_title'            => 'Cookie Policy',
				'en_excerpt'          => '',
				'en_meta_description' => 'Conexão BR Irlanda cookie policy. Learn how we use cookies on our site.',
				'en_content'          => '<!-- wp:heading --><h2>Cookie Policy</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>This site uses cookies to improve your browsing experience and to provide relevant content.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>What are cookies?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Cookies are small text files stored in your browser that help us remember your preferences and understand how you use our site.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Types of cookies we use</h3><!-- /wp:heading -->
<!-- wp:list --><ul><li>Essential cookies: needed for the site to work</li><li>Analytics cookies: to understand how you use the site</li><li>Preference cookies: to remember your choices</li></ul><!-- /wp:list -->
',
			),
			'privacidade'             => array(
				'en_slug'             => 'privacy',
				'en_title'            => 'Privacy',
				'en_excerpt'          => '',
				'en_meta_description' => '',
				'en_content'          => '<!-- wp:paragraph --><p>This page has been moved. <a href="/en/privacy-policy/">Click here to read Privacy</a>.</p><!-- /wp:paragraph -->
',
			),
			'termos'                  => array(
				'en_slug'             => 'terms',
				'en_title'            => 'Terms',
				'en_excerpt'          => '',
				'en_meta_description' => '',
				'en_content'          => '<!-- wp:paragraph --><p>This page has been moved. <a href="/en/terms-of-use/">Click here to read Terms</a>.</p><!-- /wp:paragraph -->
',
			),
			'sobre'                   => array(
				'en_slug'             => 'about-redirect',
				'en_title'            => 'About Us',
				'en_excerpt'          => '',
				'en_meta_description' => '',
				'en_content'          => '<!-- wp:paragraph --><p>This page has been moved. <a href="/en/about-us/">Click here to read About Us</a>.</p><!-- /wp:paragraph -->
',
			),
			'about'                   => array(
				'en_slug'             => 'about-page',
				'en_title'            => 'About',
				'en_excerpt'          => '',
				'en_meta_description' => '',
				'en_content'          => '<!-- wp:paragraph -->
<p>This is an example of a page. Unlike posts, which are displayed on your blog’s front page in the order they’re published, pages are better suited for more timeless content that you want to be easily accessible, like your About or Contact information. Click the Edit link to make changes to this page or <a title="Direct link to Add New Page in your Dashboard" href="https://wordpress.com/page/256777017/new/">add another page</a>.</p>
<!-- /wp:paragraph -->
',
			),
			'jobs-2'                  => array(
				'en_slug'             => 'jobs-2',
				'en_title'            => 'Jobs',
				'en_excerpt'          => '',
				'en_meta_description' => '',
				'en_content'          => '<!-- wp:paragraph -->
<p>The most recent openings are on our Instagram.<br>Follow our posts to find new job opportunities in Ireland.</p>
<!-- /wp:paragraph -->
',
			),
			'newsletter'              => array(
				'en_slug'             => 'newsletter',
				'en_title'            => 'Newsletter',
				'en_excerpt'          => '',
				'en_meta_description' => 'Subscribe to the Conexão BR Irlanda newsletter for news, events and guides for Brazilians in Ireland.',
				'en_content'          => '<!-- wp:heading --><h2>Subscribe to our Newsletter</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Get the latest news, events and practical guides straight to your inbox. No spam, only content that matters to Brazilians in Ireland.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Join thousands of Brazilians who already receive our weekly updates.</p><!-- /wp:paragraph -->
',
			),
			'revista'                 => array(
				'en_slug'             => 'digital-magazine',
				'en_title'            => 'Digital Magazine',
				'en_excerpt'          => '',
				'en_meta_description' => 'The Conexão BR Irlanda digital magazine, with articles, interviews and exclusive content for Brazilians in Ireland.',
				'en_content'          => '<!-- wp:heading --><h2>Conexão BR Irlanda Digital Magazine</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Our digital magazine brings special articles, interviews, inspiring stories and exclusive content for the Brazilian community in Ireland.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>New editions will be available here shortly.</p><!-- /wp:paragraph -->
',
			),
			'anuncie'                 => array(
				'en_slug'             => 'advertise',
				'en_title'            => 'Advertise Here',
				'en_excerpt'          => '',
				'en_meta_description' => 'Advertise on Conexão BR Irlanda and reach thousands of Brazilians in Ireland. Promote your business or service.',
				'en_content'          => '<!-- wp:heading --><h2>Advertise on Conexão BR Irlanda</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Reach the Brazilian community in Ireland! Promote your business, service or event to thousands of Brazilians across the country.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Why advertise with us?</h3><!-- /wp:heading -->
<!-- wp:list --><ul><li>A qualified audience: Brazilians in Ireland</li><li>High relevance and engagement</li><li>A range of advertising formats</li><li>Presence on social media and on the site</li></ul><!-- /wp:list -->
<!-- wp:paragraph --><p>Get in touch on WhatsApp — <a href="https://wa.me/353899451428">click here</a> — to find out more.</p><!-- /wp:paragraph -->
',
			),
			'europa'                  => array(
				'en_slug'             => 'europe',
				'en_title'            => 'Europe for Brazilians',
				'en_excerpt'          => '',
				'en_meta_description' => 'A guide to European countries for Brazilians. Portugal, Spain, Italy, Germany, France and more.',
				'en_content'          => '<!-- wp:heading --><h2>Europe for Brazilians</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>A guide with information about other European countries for Brazilians who want to explore Europe.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Portugal</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Information about Portugal for Brazilians: visas, housing, work and more.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Spain</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>A guide to Spain: culture, opportunities and information for Brazilians.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Italy</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Discover Italy: Italian citizenship, opportunities and lifestyle.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Germany</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Information about Germany: the job market, visas and quality of life.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>France</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>A guide to France for Brazilians: immigration, culture and opportunities.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Netherlands</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Information about the Netherlands: work, housing and lifestyle.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Belgium</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>A guide to Belgium: opportunities, culture and information for Brazilians.</p><!-- /wp:paragraph -->
',
			),
			'search'                  => array(
				'en_slug'             => 'search-page',
				'en_title'            => 'Search',
				'en_excerpt'          => '',
				'en_meta_description' => 'Search for content on Conexão BR Irlanda.',
				'en_content'          => '<!-- wp:search /-->
',
			),
			'moradia'                 => array(
				'en_slug'             => 'housing',
				'en_title'            => 'Housing',
				'en_excerpt'          => '',
				'en_meta_description' => 'A complete guide to Housing for Brazilians in Ireland. Information, tips and useful resources.',
				'en_content'          => '<!-- wp:heading --><h2>Housing</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Help the Brazilian community in Ireland with information about housing.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Content for this section is being prepared. In the meantime, browse our housing guides below.</p><!-- /wp:paragraph -->
',
			),
			'saude'                   => array(
				'en_slug'             => 'health',
				'en_title'            => 'Health',
				'en_excerpt'          => '',
				'en_meta_description' => 'A complete guide to Health for Brazilians in Ireland. Information, tips and useful resources.',
				'en_content'          => '<!-- wp:heading --><h2>Health</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Help the Brazilian community in Ireland with information about health.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Content for this section is being prepared. In the meantime, browse our health guides below.</p><!-- /wp:paragraph -->
',
			),
			'familia'                 => array(
				'en_slug'             => 'family',
				'en_title'            => 'Family',
				'en_excerpt'          => '',
				'en_meta_description' => 'A complete guide to Family for Brazilians in Ireland. Information, tips and useful resources.',
				'en_content'          => '<!-- wp:heading --><h2>Family</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Help the Brazilian community in Ireland with information about family.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Content for this section is being prepared. In the meantime, browse our family guides below.</p><!-- /wp:paragraph -->
',
			),
			'transporte'              => array(
				'en_slug'             => 'transport',
				'en_title'            => 'Transport',
				'en_excerpt'          => '',
				'en_meta_description' => 'A complete guide to Transport for Brazilians in Ireland. Information, tips and useful resources.',
				'en_content'          => '<!-- wp:heading --><h2>Transport</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Help the Brazilian community in Ireland with information about transport.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Content for this section is being prepared. In the meantime, browse our transport guides below.</p><!-- /wp:paragraph -->
',
			),
			'financas'                => array(
				'en_slug'             => 'finance',
				'en_title'            => 'Finance',
				'en_excerpt'          => '',
				'en_meta_description' => 'A complete guide to Finance for Brazilians in Ireland. Information, tips and useful resources.',
				'en_content'          => '<!-- wp:heading --><h2>Finance</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Help the Brazilian community in Ireland with information about finances.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Content for this section is being prepared. In the meantime, browse our finances guides below.</p><!-- /wp:paragraph -->
',
			),
			'beneficios'              => array(
				'en_slug'             => 'benefits',
				'en_title'            => 'Benefits',
				'en_excerpt'          => '',
				'en_meta_description' => 'A complete guide to Benefits for Brazilians in Ireland. Information, tips and useful resources.',
				'en_content'          => '<!-- wp:heading --><h2>Benefits</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Help the Brazilian community in Ireland with information about benefits.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Content for this section is being prepared. In the meantime, browse our benefits guides below.</p><!-- /wp:paragraph -->
',
			),
			'educacao'                => array(
				'en_slug'             => 'education',
				'en_title'            => 'Education',
				'en_excerpt'          => '',
				'en_meta_description' => 'A complete guide to Education for Brazilians in Ireland. Information, tips and useful resources.',
				'en_content'          => '<!-- wp:heading --><h2>Education</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Help the Brazilian community in Ireland with information about education.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Content for this section is being prepared. In the meantime, browse our education guides below.</p><!-- /wp:paragraph -->
',
			),
			'documentos'              => array(
				'en_slug'             => 'documents',
				'en_title'            => 'Documents',
				'en_excerpt'          => '',
				'en_meta_description' => 'A complete guide to Documents for Brazilians in Ireland. Information, tips and useful resources.',
				'en_content'          => '<!-- wp:heading --><h2>Documents</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Help the Brazilian community in Ireland with information about documents.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Content for this section is being prepared. In the meantime, browse our documents guides below.</p><!-- /wp:paragraph -->
',
			),
			'onde-comer'              => array(
				'en_slug'             => 'where-to-eat',
				'en_title'            => 'Where to Eat',
				'en_excerpt'          => '',
				'en_meta_description' => 'A complete guide to Where to Eat for Brazilians in Ireland. Information, tips and useful resources.',
				'en_content'          => '<!-- wp:heading --><h2>Where to Eat</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Help the Brazilian community in Ireland with information about food.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Content for this section is being prepared. In the meantime, browse our where to eat guides below.</p><!-- /wp:paragraph -->
',
			),
			'lazer'                   => array(
				'en_slug'             => 'leisure',
				'en_title'            => 'Leisure',
				'en_excerpt'          => '',
				'en_meta_description' => 'A complete guide to Leisure for Brazilians in Ireland. Information, tips and useful resources.',
				'en_content'          => '<!-- wp:heading --><h2>Leisure</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Help the Brazilian community in Ireland with information about leisure.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Content for this section is being prepared. In the meantime, browse our leisure guides below.</p><!-- /wp:paragraph -->
',
			),
			'turismo'                 => array(
				'en_slug'             => 'tourism',
				'en_title'            => 'Tourism',
				'en_excerpt'          => '',
				'en_meta_description' => 'A complete guide to Tourism for Brazilians in Ireland. Information, tips and useful resources.',
				'en_content'          => '<!-- wp:heading --><h2>Tourism</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Help the Brazilian community in Ireland with information about tourism.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Content for this section is being prepared. In the meantime, browse our tourism guides below.</p><!-- /wp:paragraph -->
',
			),
			'compras'                 => array(
				'en_slug'             => 'shopping',
				'en_title'            => 'Shopping',
				'en_excerpt'          => '',
				'en_meta_description' => 'A complete guide to Shopping for Brazilians in Ireland. Information, tips and useful resources.',
				'en_content'          => '<!-- wp:heading --><h2>Shopping</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Help the Brazilian community in Ireland with information about shopping.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Content for this section is being prepared. In the meantime, browse our shopping guides below.</p><!-- /wp:paragraph -->
',
			),
			'negocios'                => array(
				'en_slug'             => 'business',
				'en_title'            => 'Business',
				'en_excerpt'          => '',
				'en_meta_description' => 'A complete guide to Business for Brazilians in Ireland. Information, tips and useful resources.',
				'en_content'          => '<!-- wp:heading --><h2>Business</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Help the Brazilian community in Ireland with information about business.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Content for this section is being prepared. In the meantime, browse our business guides below.</p><!-- /wp:paragraph -->
',
			),
			'servicos'                => array(
				'en_slug'             => 'services',
				'en_title'            => 'Services',
				'en_excerpt'          => '',
				'en_meta_description' => 'A complete guide to Services for Brazilians in Ireland. Information, tips and useful resources.',
				'en_content'          => '<!-- wp:heading --><h2>Services</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Help the Brazilian community in Ireland with information about services.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Content for this section is being prepared. In the meantime, browse our services guides below.</p><!-- /wp:paragraph -->
',
			),
			'voluntariado'            => array(
				'en_slug'             => 'volunteering',
				'en_title'            => 'Volunteering',
				'en_excerpt'          => '',
				'en_meta_description' => 'A complete guide to Volunteering for Brazilians in Ireland. Information, tips and useful resources.',
				'en_content'          => '<!-- wp:heading --><h2>Volunteering</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Help the Brazilian community in Ireland with information about volunteering.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Content for this section is being prepared. In the meantime, browse our volunteering guides below.</p><!-- /wp:paragraph -->
',
			),
			'categorias'              => array(
				'en_slug'             => 'categories',
				'en_title'            => 'Categories',
				'en_excerpt'          => '',
				'en_meta_description' => '',
				'en_content'          => '<!-- wp:heading --><h2>All Categories</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Browse every category on Conexão BR Irlanda.</p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="/en/housing/">Housing</a></li>
<li><a href="/en/jobs/">Jobs</a></li>
<li><a href="/en/health/">Health</a></li>
<li><a href="/en/family/">Family</a></li>
<li><a href="/en/transport/">Transport</a></li>
<li><a href="/en/finance/">Finance</a></li>
<li><a href="/en/benefits/">Benefits</a></li>
<li><a href="/en/education/">Education</a></li>
<li><a href="/en/documents/">Documents</a></li>
<li><a href="/en/where-to-eat/">Where to Eat</a></li>
<li><a href="/en/leisure/">Leisure</a></li>
<li><a href="/en/tourism/">Tourism</a></li>
<li><a href="/en/shopping/">Shopping</a></li>
<li><a href="/en/business/">Business</a></li>
<li><a href="/en/services/">Services</a></li>
<li><a href="/en/volunteering/">Volunteering</a></li>
<li><a href="/en/eventos/">Events</a></li>
<li><a href="/en/guias/">Guides</a></li>
</ul><!-- /wp:list -->
',
			),
		),
		// post — 8 record(s).
		'post'  => array(
			'guia-pratico-para-brasileiros-em-laois'     => array(
				'en_slug'             => 'practical-guide-for-brazilians-in-laois',
				'en_title'            => 'A Practical Guide for Brazilians in Laois',
				'en_excerpt'          => '',
				'en_meta_description' => '',
				'en_content'          => '<p></p>
',
			),
			'guia-para-quem-esta-com-dificuldades-financeiras' => array(
				'en_slug'             => 'guide-for-those-facing-financial-difficulties',
				'en_title'            => 'A Guide for Anyone Facing Financial Difficulties',
				'en_excerpt'          => '',
				'en_meta_description' => '',
				'en_content'          => '<p></p>
',
			),
			'auxilios-para-familias-atipicas-na-irlanda' => array(
				'en_slug'             => 'support-for-extraordinary-families-in-ireland',
				'en_title'            => 'Support for Non-Standard Families in Ireland',
				'en_excerpt'          => '',
				'en_meta_description' => '',
				'en_content'          => '<p></p>
',
			),
			'auxilios-e-apoio-relacionados-a-saude-e-bem-estar-na-irlanda' => array(
				'en_slug'             => 'health-and-wellbeing-support-in-ireland',
				'en_title'            => 'Health and Wellbeing Support in Ireland',
				'en_excerpt'          => '',
				'en_meta_description' => '',
				'en_content'          => '<p></p>
',
			),
			'dica-de-saude-para-quem-viaja'              => array(
				'en_slug'             => 'a-health-tip-for-travellers',
				'en_title'            => 'A Health Tip for Travellers',
				'en_excerpt'          => 'Text: Patricia Vidal. Instagram: https://www.instagram.com/coachpatriciavidal/',
				'en_meta_description' => '',
				'en_content'          => '<p>Text: Patricia Vidal</p><p>Instagram: <u>https://www.instagram.com/coachpatriciavidal/</u></p><p></p><p></p><p></p>
',
			),
			'turismo-e-lazer-em-co-laois-na-irlanda'     => array(
				'en_slug'             => 'tourism-and-leisure-in-county-laois-ireland',
				'en_title'            => 'Tourism and Leisure in County Laois, Ireland',
				'en_excerpt'          => 'One of the most complete sites for our region is Laois Tourism, where you will find attractions, dining recommendations and events across the county.',
				'en_meta_description' => '',
				'en_content'          => '<p></p><p> One of the most complete sites for our region <strong>is</strong> <u>Laois Tourism</u>, where you will find attractions, dining recommendations and events across the county.</p><p></p><p></p>
',
			),
			'informacoes-para-as-mulheres-na-irlanda'    => array(
				'en_slug'             => 'information-for-women-in-ireland',
				'en_title'            => 'Information for Women in Ireland',
				'en_excerpt'          => 'A practical guide for women in Ireland, published as a flip book.',
				'en_meta_description' => '',
				'en_content'          => '<p><u>https://heyzine.com/flip-book/CartilhaDM.html#page/1</u></p><p></p><p></p><p></p><p></p>
',
			),
			'carne-refogada-ao-estilo-korean-bbq'        => array(
				'en_slug'             => 'korean-bbq-style-stir-fried-beef',
				'en_title'            => 'Korean BBQ Style Stir-Fried Beef',
				'en_excerpt'          => 'By: Chef Anderson Balico, Food Content Creator & Digital Marketing.',
				'en_meta_description' => '',
				'en_content'          => '<h2>By: Chef Anderson Balico, Food Content Creator &amp; Digital Marketing</h2><p></p><p></p><p></p><p><strong>Ingredients</strong></p><p><strong>400 g of strips of beef</strong></p><p><strong>2 tablespoons of Korean BBQ ÍON Organic Seasoning</strong></p><p><strong>2 tablespoons of soy sauce (shoyu)</strong></p><p><strong>1 tablespoon of honey</strong></p><p><strong>1 tablespoon of ÍON sunflower oil</strong></p><p><strong>1 red pepper, sliced</strong></p><p><strong>1 onion, sliced</strong></p><p><strong>1 spring onion, finely chopped</strong></p><p></p><p><strong>Method:</strong></p><p><strong>Coat the beef with the soy sauce, honey and Korean BBQ seasoning. Leave to marinate for 15 minutes.</strong></p><p><strong>Heat the sunflower oil in a very hot frying pan or wok and stir-fry the beef for about 5 minutes, until golden.</strong></p><p><strong>Add the onion and red pepper and cook for a further 3 to 4 minutes, until tender.</strong></p><p></p><p><strong>Finish with the spring onion and serve with rice or pasta.</strong></p><p></p><p><u><strong>@chefandersonbalico</strong></u></p>
',
			),
		),
	);
}
