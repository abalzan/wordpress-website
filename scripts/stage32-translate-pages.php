<?php
/**
 * Stage 3.2 — controlled English translation of approved static pages.
 *
 * LOCAL / STAGING ONLY. Creates ONE linked English translation per approved
 * Portuguese page through Polylang (`pll_save_post_translations`). The
 * Portuguese original is never modified; the English page is a *linked
 * translation of the same identity*, never a second independent record.
 *
 * English copy is human-authored for this stage (no machine translation).
 * The Gutenberg block structure of the Portuguese original is preserved.
 *
 * Page selection follows CONEXAO_BR_ENGLISH_ARCHITECTURE_DECISION.md §8/§12:
 *   - Key pages are translated first (B1 redirect retires automatically
 *     because the missing-translation redirect stops firing once a real,
 *     published translation exists).
 *   - County pages and /irlanda/ stay B2 (see conexao_b2_page_allowlist()).
 *   - All other pages keep B1 behaviour.
 *
 * Idempotent: a page whose English translation already exists is skipped.
 *
 * Usage (local only, NEVER production):
 *   wp eval-file scripts/stage32-translate-pages.php
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	$dir = __DIR__;
	while ( $dir !== dirname( $dir ) ) {
		if ( file_exists( $dir . '/wp-load.php' ) ) {
			require_once $dir . '/wp-load.php';
			break;
		}
		$dir = dirname( $dir );
	}
}
if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "WordPress bootstrap failed\n" );
	exit( 1 );
}

if ( ! function_exists( 'pll_set_post_language' ) ) {
	fwrite( STDERR, "Polylang is not active.\n" );
	exit( 1 );
}

/**
 * Approved page translation map (Stage 3.2): Portuguese slug => English data.
 *
 * 'title'/'content' are human-authored English. 'meta_desc' feeds
 * conexao_meta_description. 'template' copies the source page template when
 * the template must render the English record as well (Jobs landing).
 */
function stage32_page_translation_map() {
	return array(
		'inicio' => array(
			'en_slug'  => 'home',
			'title'    => 'Home',
			'content'  => '<!-- wp:paragraph --><p>Welcome to Conexão BR Irlanda, the portal of the Brazilian community in Ireland.</p><!-- /wp:paragraph -->',
			'meta_desc' => 'Conexão BR Irlanda — the portal of the Brazilian community in Ireland. Guides, events, courses, jobs and more.',
		),
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
			'content'  => '<!-- wp:heading --><h2>Get in touch with us</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Have a suggestion, a question or want to advertise with us? Contact the Conexão BR Irlanda team through our social channels or by email.</p><!-- /wp:paragraph -->',
			'meta_desc' => 'Contact the Conexão BR Irlanda team. Questions, suggestions or advertising.',
		),
		'empregos' => array(
			'en_slug'  => 'jobs',
			'title'    => 'Jobs',
			'content'  => '<!-- wp:paragraph --><p>Information about job opportunities for the Brazilian community in Ireland. This content is editable — replace it with details about how opportunities are shared, Instagram guidance and per-county tips.</p><!-- /wp:paragraph -->',
			'meta_desc' => 'Jobs and work in Ireland for the Brazilian community: opportunities, recruitment agencies, employment-permit employers and practical resources.',
			'template' => 'page-empregos.php',
		),
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

echo "== Stage 3.2 — static page translations (EN) ==\n\n";

$stage32_linked = array();

foreach ( stage32_page_translation_map() as $pt_slug => $en ) {
	$pt_page = get_page_by_path( $pt_slug );
	if ( ! $pt_page ) {
		echo "  SKIP {$pt_slug}: Portuguese source page not found.\n";
		continue;
	}

	$pt_id    = (int) $pt_page->ID;
	$existing = (int) pll_get_post( $pt_id, 'en' );
	if ( $existing ) {
		echo "  EXISTS {$pt_slug} → en #{$existing} (skipped)\n";
		$stage32_linked[ $pt_slug ] = $existing;
		continue;
	}

	// Never reuse an existing non-linked page that happens to carry the slug.
	$clash = get_page_by_path( $en['en_slug'] );
	if ( $clash && (int) $clash->ID !== $pt_id ) {
		echo "  ERROR {$pt_slug}: slug '{$en['en_slug']}' already taken by page #{$clash->ID} — refusing to collide.\n";
		continue;
	}

	$en_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_name'    => $en['en_slug'],
			'post_title'   => $en['title'],
			'post_content' => $en['content'],
			'post_status'  => 'publish',
		),
		true
	);

	if ( is_wp_error( $en_id ) ) {
		echo "  ERROR {$pt_slug}: " . $en_id->get_error_message() . "\n";
		continue;
	}

	// Language assignment + translation link (the identity relationship).
	pll_set_post_language( $en_id, 'en' );
	pll_set_post_language( $pt_id, 'pt' );
	pll_save_post_translations( array( 'pt' => $pt_id, 'en' => $en_id ) );

	// SEO description + page template parity with the Portuguese source.
	if ( ! empty( $en['meta_desc'] ) ) {
		update_post_meta( $en_id, 'conexao_meta_description', $en['meta_desc'] );
	}
	$template = ! empty( $en['template'] ) ? $en['template'] : get_post_meta( $pt_id, '_wp_page_template', true );
	if ( $template && 'default' !== $template ) {
		update_post_meta( $en_id, '_wp_page_template', $template );
	}

	// Featured media is shared (media has no language layer in this setup).
	$thumb = get_post_thumbnail_id( $pt_id );
	if ( $thumb ) {
		set_post_thumbnail( $en_id, $thumb );
	}

	// Verify the relationship from BOTH sides before reporting success.
	$check_en = (int) pll_get_post( $pt_id, 'en' );
	$check_pt = (int) pll_get_post( $en_id, 'pt' );
	if ( $check_en !== $en_id || $check_pt !== $pt_id ) {
		echo "  ERROR {$pt_slug}: translation link verification failed (pt{$pt_id}→en{$check_en}, en{$en_id}→pt{$check_pt}).\n";
		continue;
	}

	echo "  OK {$pt_slug} (#{$pt_id}) → {$en['en_slug']} (#{$en_id}) [linked both ways]\n";
	$stage32_linked[ $pt_slug ] = $en_id;
}

echo "\nLinked pairs:\n";
foreach ( $stage32_linked as $pt_slug => $en_id ) {
	$pt_id = (int) pll_get_post( $en_id, 'pt' );
	echo "  {$pt_slug} (#{$pt_id}) <=> " . get_post_field( 'post_name', $en_id ) . " (#{$en_id})\n";
}
echo "\nDone.\n";
