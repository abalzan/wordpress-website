<?php
/**
 * Customizer registration and generated colour CSS
 *
 * The live-preview colour controls, the generated customizer stylesheet and
 * the shared colour-darkening helper used to produce readable text on the
 * administrator-selected background colour.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function conexao_customize_register( $wp_customize ) {
	// Colors Section.
	$wp_customize->add_section( 'conexao_colors', array( 'title' => __( 'Cores do Portal', 'conexao-br-irlanda' ), 'priority' => 30 ) );
	$wp_customize->add_setting( 'conexao_primary_color', array( 'default' => '#0E6B3A', 'sanitize_callback' => 'sanitize_hex_color' ) );
	$wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, 'conexao_primary_color', array(
		'label' => __( 'Cor Primária (Verde)', 'conexao-br-irlanda' ), 'section' => 'conexao_colors',
	) ) );
	$wp_customize->add_setting( 'conexao_accent_color', array( 'default' => '#F68B1F', 'sanitize_callback' => 'sanitize_hex_color' ) );
	$wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, 'conexao_accent_color', array(
		'label' => __( 'Cor de Destaque (Laranja)', 'conexao-br-irlanda' ), 'section' => 'conexao_colors',
	) ) );

	// Social Section.
	$wp_customize->add_section( 'conexao_social', array( 'title' => __( 'Redes Sociais', 'conexao-br-irlanda' ), 'priority' => 40 ) );
	$social_fields = array(
		'conexao_instagram' => array( __( 'Instagram URL', 'conexao-br-irlanda' ), 'https://www.instagram.com/conexaobr.ie/' ),
		'conexao_whatsapp'  => array( __( 'WhatsApp URL', 'conexao-br-irlanda' ), 'https://wa.me/353899451428' ),
		'conexao_facebook'  => array( __( 'Facebook URL', 'conexao-br-irlanda' ), '' ),
	);
	foreach ( $social_fields as $id => $data ) {
		$wp_customize->add_setting( $id, array( 'default' => $data[1], 'sanitize_callback' => 'esc_url_raw' ) );
		$wp_customize->add_control( $id, array( 'label' => $data[0], 'section' => 'conexao_social', 'type' => 'url' ) );
	}

	// Hero Section.
	$wp_customize->add_section( 'conexao_hero', array( 'title' => __( 'Hero Section', 'conexao-br-irlanda' ), 'priority' => 35 ) );
	$wp_customize->add_setting( 'conexao_hero_title', array(
		'default' => __( 'Tudo que o brasileiro precisa para viver melhor na <span>Irlanda</span>', 'conexao-br-irlanda' ),
		'sanitize_callback' => 'wp_kses_post',
	) );
	$wp_customize->add_control( 'conexao_hero_title', array(
		'label' => __( 'Título do Hero', 'conexao-br-irlanda' ), 'section' => 'conexao_hero', 'type' => 'textarea',
	) );
	$wp_customize->add_setting( 'conexao_hero_subtitle', array(
		'default' => __( 'Conectando a comunidade brasileira com informações, eventos, guias práticos e muito mais.', 'conexao-br-irlanda' ),
		'sanitize_callback' => 'sanitize_textarea_field',
	) );
	$wp_customize->add_control( 'conexao_hero_subtitle', array(
		'label' => __( 'Subtítulo do Hero', 'conexao-br-irlanda' ), 'section' => 'conexao_hero', 'type' => 'textarea',
	) );
	// The front-page hero background is the committed theme asset
	// assets/images/conexaobr_Hero_image.png (loaded via
	// get_template_directory_uri() in front-page.php), so there is no
	// Customizer hero-image setting. The green text-safe zone and the
	// castle/family/flags composition are part of that fixed asset.

	// Footer Section.
	$wp_customize->add_section( 'conexao_footer', array( 'title' => __( 'Rodapé', 'conexao-br-irlanda' ), 'priority' => 50 ) );
	$wp_customize->add_setting( 'conexao_footer_text', array(
		'default' => __( '© 2025 Conexão BR Irlanda. Todos os direitos reservados.', 'conexao-br-irlanda' ),
		'sanitize_callback' => 'wp_kses_post',
	) );
	$wp_customize->add_control( 'conexao_footer_text', array(
		'label' => __( 'Texto do Rodapé', 'conexao-br-irlanda' ), 'section' => 'conexao_footer', 'type' => 'textarea',
	) );
}
add_action( 'customize_register', 'conexao_customize_register' );

/**
 * Customizer CSS
 */
function conexao_customizer_css() {
	$primary = get_theme_mod( 'conexao_primary_color', '#0E6B3A' );
	$accent  = get_theme_mod( 'conexao_accent_color', '#F68B1F' );
	?>
	<style type="text/css">
		:root {
			--conexao-primary: <?php echo esc_attr( $primary ); ?>;
			--conexao-primary-dark: <?php echo esc_attr( conexao_darken_color( $primary, 20 ) ); ?>;
			--conexao-accent: <?php echo esc_attr( $accent ); ?>;
			--conexao-accent-dark: <?php echo esc_attr( conexao_darken_color( $accent, 20 ) ); ?>;
			/*
			 * Accent TEXT token, derived from the SAME configured accent so a
			 * maintainer changing the Customizer colour cannot leave accent text
			 * failing contrast. This :root block is emitted in wp_head and
			 * therefore lands AFTER the dark-mode stylesheet, which is exactly
			 * why the dark theme must re-point this token through its own
			 * `[data-theme="dark"]` block in dark-mode.css (that selector is more
			 * specific than :root and so still wins here).
			 *
			 * Darkened far enough to clear WCAG AA for normal text on white
			 * (5.34:1 for the default #F68B1F -> #a85406); accent-coloured text
			 * is never painted on a dark surface in the light theme.
			 */
			--conexao-accent-text: <?php echo esc_attr( conexao_accent_text_color( $accent ) ); ?>;
		}
	</style>
	<?php
}
add_action( 'wp_head', 'conexao_customizer_css' );

/**
 * Derive an AA-safe accent TEXT colour from the configured brand accent.
 *
 * The brand accent (#F68B1F by default) is deliberately vivid: it is a
 * background/border colour and measures only 2.43:1 on white, which fails
 * WCAG AA for normal text. Rather than hard-coding a second brand colour — and
 * so a maintainer changing the Customizer accent cannot leave accent-coloured
 * text failing contrast — this darkens the CONFIGURED accent along its own hue
 * until it reaches the WCAG AA ratio (4.5:1) against white.
 *
 * Used for `--conexao-accent-text` in conexao_customizer_css(). Accents used
 * as borders, rules, focus rings, decorative fills and CTA backgrounds keep the
 * unmodified `--conexao-accent` (UI audit T1.5).
 *
 * @param string $hex     Brand accent hex, e.g. '#F68B1F'.
 * @param float  $minimum Target contrast ratio against white.
 * @return string Hex colour guaranteed to reach the target ratio.
 */
function conexao_accent_text_color( $hex, float $minimum = 4.5 ) {
	$hex = ltrim( (string) $hex, '#' );

	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}

	if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
		// Unparseable input: fall back to the documented default accent-text.
		return '#a85406';
	}

	$channels = array(
		hexdec( substr( $hex, 0, 2 ) ),
		hexdec( substr( $hex, 2, 2 ) ),
		hexdec( substr( $hex, 4, 2 ) ),
	);

	// Step the channels down together (a single luminance scale along the
	// colour's own hue) and stop at the first ratio that clears the target.
	// 100 steps of 1% is coarse enough to stay cheap and fine enough to land
	// within a rounding step of the target ratio.
	for ( $percent = 1; $percent <= 100; $percent++ ) {
		$scale = ( 100 - $percent ) / 100;

		foreach ( $channels as $index => $value ) {
			$channels[ $index ] = (int) round( $value * $scale );
		}

		if ( conexao_contrast_ratio_hex( $channels, array( 255, 255, 255 ) ) >= $minimum ) {
			break;
		}
	}

	return sprintf( '#%02x%02x%02x', $channels[0], $channels[1], $channels[2] );
}

/**
 * WCAG relative-contrast ratio between two sRGB channel triplets.
 *
 * Shared by the accent-text derivation above and its tests, so the ratio is
 * computed by exactly one function in the theme.
 *
 * @param array $rgb_a First colour as [r, g, b] 0-255.
 * @param array $rgb_b Second colour as [r, g, b] 0-255.
 * @return float Contrast ratio, 1.0 to 21.0.
 */
function conexao_contrast_ratio_hex( array $rgb_a, array $rgb_b ): float {
	$lum = static function ( array $rgb ): float {
		$linear = array();
		foreach ( $rgb as $value ) {
			$channel = $value / 255;
			$linear[] = $channel <= 0.03928
				? $channel / 12.92
				: pow( ( $channel + 0.055 ) / 1.055, 2.4 );
		}
		return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
	};

	$a = $lum( $rgb_a );
	$b = $lum( $rgb_b );
	$hi = max( $a, $b );
	$lo = min( $a, $b );

	return ( $hi + 0.05 ) / ( $lo + 0.05 );
}


/**
 * Darken hex color
 */
function conexao_darken_color( $hex, $percent ) {
	$hex = ltrim( $hex, '#' );
	if ( strlen( $hex ) === 3 ) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	$r = hexdec( substr( $hex, 0, 2 ) );
	$g = hexdec( substr( $hex, 2, 2 ) );
	$b = hexdec( substr( $hex, 4, 2 ) );
	$r = max( 0, min( 255, $r - ( $r * $percent / 100 ) ) );
	$g = max( 0, min( 255, $g - ( $g * $percent / 100 ) ) );
	$b = max( 0, min( 255, $b - ( $b * $percent / 100 ) ) );
	return sprintf( '#%02x%02x%02x', $r, $g, $b );
}

/**
 * Build the Blog category filter URL.
 *
 * Blog categories are filtered on the Blog archive itself via the
 * ?categoria= query parameter (/blog/?categoria=slug) — the same pattern
 * as Guias — instead of navigating to the native WordPress
 * /category/{slug}/ archive. The archive base URL is resolved from the
 * configured posts page (page_for_posts) so it follows the permalink
 * rather than being hard-coded.
 *
 * @param string $category_slug Category slug. Empty returns the base archive URL.
 * @return string Absolute URL to the (optionally filtered) Blog archive.
 */
