<?php
/**
 * Stage 4.5 — human-authored English translations of the public Pages.
 *
 * Single source of truth for the page-translation migration. Consumed by:
 *   - the admin importer (../conexao-page-translation.php) — the production
 *     path on WordPress.com (no WP-CLI there, and Polylang Free cannot link
 *     translations over the public REST API);
 *   - scripts/stage45-translate-pages.php — the WP-CLI runner for
 *     local/staging validation.
 *
 * Rules honoured here (task Phases 3–7):
 *   - human-authored English, no machine translation;
 *   - the Gutenberg block structure of each Portuguese original is preserved
 *     (same blocks, same order, same layout classes as the PT raw markup);
 *   - page template, hierarchy (all production pages are top-level), menu
 *     order and featured media are applied by the importer from the PT source;
 *   - internal links stay as canonical PT paths in this file and are resolved
 *     to the EN counterpart at apply time through the Polylang relationship
 *     (never string-mangled — see includes/apply.php);
 *   - external links (wa.me, mailto, instagram) are preserved untouched;
 *   - brand names, official organisation/programme names, URLs, emails and
 *     Irish proper nouns are never translated;
 *   - 'meta_desc' feeds conexao_meta_description (translated, user-facing).
 *
 * 'shared_slug' => true keeps the PT post_name for the EN page (blog,
 * newsletter): the word is identical in English and the documented EN URLs
 * are /en/blog/ and /en/newsletter/. Polylang Free has no shared-slug support,
 * so the importer permits exactly these slugs through a scoped
 * wp_unique_post_slug filter while it runs. Polylang's pagename
 * auto-translate disambiguates PT/EN requests by language.
 *
 * @package Conexao_Page_Translation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Data file — no direct access.
}

/**
 * The page translation map, in mandatory creation order (Phase 2):
 * front page first, then top-level pages, then utility/legal pages.
 * (No PT page has children on production, so no parent passes are needed.)
 *
 * @return array<string,array<string,mixed>>
 */
function conexao_page_translation_map(): array {
	return array(

		// ------------------------------------------------------------------
		// 1. Front page (class B).
		// ------------------------------------------------------------------
		'inicio' => array(
			'en_slug'  => 'home',
			'title'    => 'Home',
			'content'  => '<!-- wp:paragraph --><p>Welcome to Conexão BR Irlanda, the portal of the Brazilian community in Ireland.</p><!-- /wp:paragraph -->',
			'meta_desc' => 'Conexão BR Irlanda — the portal of the Brazilian community in Ireland. Guides, events, courses, jobs and more.',
		),

		// ------------------------------------------------------------------
		// 2. Key narrative pages + jobs landing + posts page.
		// ------------------------------------------------------------------
		'sobre-nos' => array(
			'en_slug'  => 'about-us',
			'title'    => 'About Us',
			'content'  => '<!-- wp:heading --><h2>Who we are</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Conexão BR Irlanda is the portal of the Brazilian community in Ireland. Our goal is to connect, inform and support Brazilians who live in Ireland or plan to move here.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h2>Our Mission</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>To provide relevant information, practical guides, events and a support network so that every Brazilian can live better in Ireland.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h2>Our Vision</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>To be the main reference for the Brazilian community in Ireland, promoting integration, knowledge and opportunities.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h2>Our Values</h2><!-- /wp:heading -->
<!-- wp:list --><ul><li>Community and solidarity</li><li>Quality, verified information</li><li>Inclusion and diversity</li><li>Transparency and trust</li></ul><!-- /wp:list -->',
			'meta_desc' => 'Meet Conexão BR Irlanda, the portal of the Brazilian community in Ireland. Our mission, vision and values.',
		),
		'contato' => array(
			'en_slug'  => 'contact',
			'title'    => 'Contact',
			// Mirrors the current PT structure exactly, incl. the WhatsApp CTA,
			// the mailto line and the trailing empty paragraph.
			'content'  => '<!-- wp:heading --><h2>Get in touch with us</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Have a suggestion, a question or want to advertise with us? Send us a message!</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><a href="https://wa.me/353899451428">Talk to us on WhatsApp</a></p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Email: <a href="mailto:tdcriativo@gmail.com">tdcriativo@gmail.com</a></p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->',
			'meta_desc' => 'Get in touch with the Conexão BR Irlanda team. Ask questions, send suggestions or find out how to advertise.',
		),
		'empregos' => array(
			'en_slug'  => 'jobs',
			'title'    => 'Jobs',
			// Translates the CURRENT production content (Instagram CTA). The
			// page-empregos.php template and the _empregos_link meta are
			// inherited from the PT source at apply time.
			'content'  => '<!-- wp:paragraph --><p>Our latest job openings are on our Instagram.<br>Follow our posts to find new work opportunities in Ireland.</p><!-- /wp:paragraph -->',
			'meta_desc' => 'The latest job openings are on our Instagram. Follow our posts to find new work opportunities in Ireland.',
			'template' => 'page-empregos.php',
		),
		'blog' => array(
			'en_slug'  => 'blog',
			'title'    => 'Blog',
			'shared_slug' => true,
			'content'  => '<!-- wp:paragraph --><p>Articles and news for the Brazilian community in Ireland.</p><!-- /wp:paragraph -->',
			'meta_desc' => 'The Conexão BR Irlanda blog — articles, news and information for Brazilians in Ireland.',
		),

		// ------------------------------------------------------------------
		// 3. Topic hub pages (class A). Content follows the exact PT hub
		//    pattern (see conexao_page_translation_hub_content() below);
		//    'topic' is the natural English lower-case topic phrase.
		// ------------------------------------------------------------------
		'moradia'      => conexao_page_translation_hub( 'Housing',      'housing' ),
		'saude'        => conexao_page_translation_hub( 'Healthcare',   'healthcare' ),
		'transporte'   => conexao_page_translation_hub( 'Transport',    'transport' ),
		'familia'      => conexao_page_translation_hub( 'Family',       'family' ),
		'financas'     => conexao_page_translation_hub( 'Finances',     'finances' ),
		'beneficios'   => conexao_page_translation_hub( 'Benefits',     'benefits' ),
		'educacao'     => conexao_page_translation_hub( 'Education',    'education' ),
		'documentos'   => conexao_page_translation_hub( 'Documents',    'documents' ),
		'onde-comer'   => conexao_page_translation_hub( 'Where to Eat', 'where to eat' ),
		'turismo'      => conexao_page_translation_hub( 'Tourism',      'tourism' ),
		'compras'      => conexao_page_translation_hub( 'Shopping',     'shopping' ),
		'negocios'     => conexao_page_translation_hub( 'Business',     'business' ),
		'servicos'     => conexao_page_translation_hub( 'Services',     'services' ),
		'voluntariado' => conexao_page_translation_hub( 'Volunteering', 'volunteering' ),

		// Category index: internal links stay canonical PT paths here and are
		// resolved to the EN counterpart at apply time via the Polylang
		// relationship (includes/apply.php — never string replacement).
		'categorias' => array(
			'en_slug'  => 'categories',
			'title'    => 'Categories',
			'content'  => '<!-- wp:heading --><h2>All Categories</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Explore all Conexão BR Irlanda categories.</p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="/moradia/">Housing</a></li>
<li><a href="/empregos/">Jobs</a></li>
<li><a href="/saude/">Healthcare</a></li>
<li><a href="/familia/">Family</a></li>
<li><a href="/transporte/">Transport</a></li>
<li><a href="/financas/">Finances</a></li>
<li><a href="/beneficios/">Benefits</a></li>
<li><a href="/educacao/">Education</a></li>
<li><a href="/documentos/">Documents</a></li>
<li><a href="/onde-comer/">Where to Eat</a></li>
<li><a href="/lazer/">Leisure</a></li>
<li><a href="/turismo/">Tourism</a></li>
<li><a href="/compras/">Shopping</a></li>
<li><a href="/negocios/">Business</a></li>
<li><a href="/servicos/">Services</a></li>
<li><a href="/voluntariado/">Volunteering</a></li>
<li><a href="/eventos/">Events</a></li>
<li><a href="/guias/">Guides</a></li>
</ul><!-- /wp:list -->',
			'meta_desc' => 'All Conexão BR Irlanda categories: housing, jobs, healthcare, family, transport, finances, benefits, education, documents and more for Brazilians in Ireland.',
		),


		// ------------------------------------------------------------------
		// 4. Ireland + Europe (class G / A).
		// ------------------------------------------------------------------
		'irlanda' => array(
			'en_slug'  => 'ireland',
			'title'    => 'Ireland for Brazilians',
			'content'  => '<!-- wp:heading --><h2>Ireland for Brazilians</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>A complete guide to Ireland for Brazilians. Information about housing, healthcare, immigration, transport, education and much more.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Housing</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Find tips and information about renting houses, apartments and accommodation in Ireland.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Healthcare</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Learn how the Irish health system works, how to register with a GP and how to get a Medical Card.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Immigration</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Information about visas, work permits, the IRP and Irish citizenship.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Driving in Ireland</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Learn how to exchange your Brazilian driver\'s licence (CNH), get an Irish driving licence and understand the rules of the road.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Banks and Finances</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>A guide to opening a bank account, understanding taxes and managing your finances in Ireland.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Education</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Information about the Irish education system, schools, universities and courses.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Employment</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>The job market, employment rights and job opportunities in Ireland.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Benefits</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Find out which social benefits are available and how to apply for each of them.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Transport</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Getting around Ireland: public transport, taxis, cycling and mobility tips.</p><!-- /wp:paragraph -->',
			'meta_desc' => 'A complete guide to Ireland for Brazilians. Housing, healthcare, immigration, transport, education and much more.',
		),
		'europa' => array(
			'en_slug'  => 'europe',
			'title'    => 'Europe for Brazilians',
			'content'  => '<!-- wp:heading --><h2>Europe for Brazilians</h2><!-- /wp:heading -->
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
<!-- wp:heading --><h3>The Netherlands</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Information about the Netherlands: work, housing and lifestyle.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Belgium</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>A guide to Belgium: opportunities, culture and information for Brazilians.</p><!-- /wp:paragraph -->',
			'meta_desc' => 'A guide to European countries for Brazilians. Portugal, Spain, Italy, Germany, France and more.',
		),

		// ------------------------------------------------------------------
		// 5. County pages (class G — real translations retiring the B2
		//    fallback). Content follows the exact PT county pattern.
		// ------------------------------------------------------------------
		'laois'     => conexao_page_translation_county( 'Laois' ),
		'dublin'    => conexao_page_translation_county( 'Dublin' ),
		'cork'      => conexao_page_translation_county( 'Cork' ),
		'galway'    => conexao_page_translation_county( 'Galway' ),
		'limerick'  => conexao_page_translation_county( 'Limerick' ),
		'kildare'   => conexao_page_translation_county( 'Kildare' ),
		'meath'     => conexao_page_translation_county( 'Meath' ),
		'wicklow'   => conexao_page_translation_county( 'Wicklow' ),
		'waterford' => conexao_page_translation_county( 'Waterford' ),


		// ------------------------------------------------------------------
		// 6. Utility pages (class A).
		// ------------------------------------------------------------------
		'newsletter' => array(
			'en_slug'  => 'newsletter',
			'title'    => 'Newsletter',
			'shared_slug' => true,
			'content'  => '<!-- wp:heading --><h2>Subscribe to our Newsletter</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Get the latest news, events and practical guides straight to your inbox. No spam, only content relevant to Brazilians in Ireland.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Join thousands of Brazilians who already receive our weekly updates.</p><!-- /wp:paragraph -->',
			'meta_desc' => 'Subscribe to the Conexão BR Irlanda newsletter and receive news, events and guides for Brazilians in Ireland.',
		),
		'revista' => array(
			'en_slug'  => 'digital-magazine',
			'title'    => 'Digital Magazine',
			'content'  => '<!-- wp:heading --><h2>Conexão BR Irlanda Digital Magazine</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Our digital magazine brings special articles, interviews, inspiring stories and exclusive content for the Brazilian community in Ireland.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>New editions will be available here soon.</p><!-- /wp:paragraph -->',
			'meta_desc' => 'The Conexão BR Irlanda digital magazine with articles, interviews and exclusive content for Brazilians in Ireland.',
		),
		'anuncie' => array(
			'en_slug'  => 'advertise',
			'title'    => 'Advertise With Us',
			'content'  => '<!-- wp:heading --><h2>Advertise on Conexão BR Irlanda</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Reach the Brazilian community in Ireland! Promote your business, service or event to thousands of Brazilians across the country.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Why advertise with us?</h3><!-- /wp:heading -->
<!-- wp:list --><ul><li>Qualified target audience: Brazilians in Ireland</li><li>High relevance and engagement</li><li>Several advertising formats</li><li>Presence on social media and on the website</li></ul><!-- /wp:list -->
<!-- wp:paragraph --><p><a href="https://wa.me/353899451428">Contact us on WhatsApp</a> to find out more.</p><!-- /wp:paragraph -->',
			'meta_desc' => 'Advertise on Conexão BR Irlanda and reach thousands of Brazilians in Ireland. Promote your business or service.',
		),

		// ------------------------------------------------------------------
		// 7. Legal pages (class F).
		// ------------------------------------------------------------------
		'politica-de-privacidade' => array(
			'en_slug'  => 'privacy-policy',
			'title'    => 'Privacy Policy',
			'content'  => '<!-- wp:heading --><h2>Privacy Policy</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>This Privacy Policy describes how Conexão BR Irlanda collects, uses and protects the personal information of the users of our website.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Information we collect</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>We collect information you provide voluntarily, such as your name and email address when subscribing to our newsletter, and browsing information through cookies.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>How we use your information</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>We use your information to send newsletters, improve our content and personalise your experience on the website.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Your rights</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>You have the right to access, correct or delete your personal data at any time. Contact us to exercise these rights.</p><!-- /wp:paragraph -->',
			'meta_desc' => 'Conexão BR Irlanda privacy policy. Learn how we collect, use and protect your information.',
		),
		'termos-de-uso' => array(
			'en_slug'  => 'terms-of-use',
			'title'    => 'Terms of Use',
			'content'  => '<!-- wp:heading --><h2>Terms of Use</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>By accessing and using the Conexão BR Irlanda website, you agree to the following terms and conditions.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Use of Content</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>All content on this website is provided for informational purposes only. We are not responsible for decisions made based on the information published here.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>External Links</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Our website may contain links to external sites. We are not responsible for the content or privacy practices of those sites.</p><!-- /wp:paragraph -->',
			'meta_desc' => 'Conexão BR Irlanda terms of use. Conditions for accessing and using the portal content.',
		),
		'cookies' => array(
			'en_slug'  => 'cookie-policy',
			'title'    => 'Cookie Policy',
			'content'  => '<!-- wp:heading --><h2>Cookie Policy</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>This website uses cookies to improve your browsing experience and provide relevant content.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>What are cookies?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Cookies are small text files stored in your browser that help us remember your preferences and understand how you use our website.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Types of cookies we use</h3><!-- /wp:heading -->
<!-- wp:list --><ul><li>Essential cookies: required for the website to work</li><li>Analytics cookies: to understand how you use the website</li><li>Preference cookies: to remember your choices</li></ul><!-- /wp:list -->',
			'meta_desc' => 'Conexão BR Irlanda cookie policy. Learn how we use cookies on our website.',
		),
	);
}


/**
 * English content spec for a topic hub page.
 *
 * Mirrors the PT hub pattern generated by the site seed
 * (wp-content/plugins/conexao-content/create-pages.php):
 *   h2 {Title}
 *   p  "Ajude a comunidade brasileira na Irlanda com informações sobre {topic}."
 *   p  "Conteúdo para esta seção está sendo preparado. Enquanto isso, explore
 *       nossos guias abaixo."
 * plus the PT meta pattern
 *   "Guia completo sobre {Title} para brasileiros na Irlanda. …".
 *
 * @param string $title English page title (e.g. "Housing").
 * @param string $topic Lower-case English topic phrase (e.g. "housing").
 * @return array<string,mixed> Map entry (en_slug derived from the PT key by the caller).
 */
function conexao_page_translation_hub( string $title, string $topic ): array {
	$en_slug = sanitize_title( $title );
	return array(
		'en_slug'  => $en_slug,
		'title'    => $title,
		'content'  => sprintf(
			'<!-- wp:heading --><h2>%1$s</h2><!-- /wp:heading -->' . "\n" .
			'<!-- wp:paragraph --><p>Help the Brazilian community in Ireland with information about %2$s.</p><!-- /wp:paragraph -->' . "\n" .
			'<!-- wp:paragraph --><p>Content for this section is being prepared. In the meantime, explore our guides below.</p><!-- /wp:paragraph -->',
			$title,
			$topic
		),
		'meta_desc' => sprintf(
			'A complete guide to %1$s for Brazilians in Ireland. Information, tips and useful resources.',
			$title
		),
	);
}

/**
 * English content spec for a county page.
 *
 * Mirrors the PT county pattern from create-pages.php:
 *   h2 "{Name} para brasileiros" + intro + Eventos / Negócios e Serviços /
 *   Guias Práticos / Empregos / Restaurantes sections.
 * County names are Irish proper nouns and are never translated; the English
 * page title uses the official English form "County {Name}".
 *
 * @param string $name County proper noun (e.g. "Dublin").
 * @return array<string,mixed>
 */
function conexao_page_translation_county( string $name ): array {
	return array(
		'en_slug'  => 'county-' . strtolower( $name ),
		'title'    => 'County ' . $name,
		'content'  => sprintf(
			'<!-- wp:heading --><h2>%1$s for Brazilians</h2><!-- /wp:heading -->' . "\n" .
			'<!-- wp:paragraph --><p>A complete guide to County %1$s in Ireland. Information about housing, events, businesses, guides, jobs and restaurants.</p><!-- /wp:paragraph -->' . "\n" .
			'<!-- wp:heading --><h3>Events</h3><!-- /wp:heading -->' . "\n" .
			'<!-- wp:paragraph --><p>Check out the events and activities happening in County %1$s.</p><!-- /wp:paragraph -->' . "\n" .
			'<!-- wp:heading --><h3>Businesses and Services</h3><!-- /wp:heading -->' . "\n" .
			'<!-- wp:paragraph --><p>Find Brazilian businesses and services in County %1$s.</p><!-- /wp:paragraph -->' . "\n" .
			'<!-- wp:heading --><h3>Practical Guides</h3><!-- /wp:heading -->' . "\n" .
			'<!-- wp:paragraph --><p>Useful guides for living in County %1$s.</p><!-- /wp:paragraph -->' . "\n" .
			'<!-- wp:heading --><h3>Jobs</h3><!-- /wp:heading -->' . "\n" .
			'<!-- wp:paragraph --><p>Work opportunities in County %1$s.</p><!-- /wp:paragraph -->' . "\n" .
			'<!-- wp:heading --><h3>Restaurants</h3><!-- /wp:heading -->' . "\n" .
			'<!-- wp:paragraph --><p>Discover restaurants and food options in County %1$s.</p><!-- /wp:paragraph -->',
			$name
		),
		'meta_desc' => sprintf(
			'A complete guide to County %1$s in Ireland. Events, businesses, guides and jobs for Brazilians.',
			$name
		),
	);
}

