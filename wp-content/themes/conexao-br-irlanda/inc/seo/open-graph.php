<?php
/**
 * Open Graph, Twitter cards and social image alt text
 *
 * The og:* and twitter:* head output, including the og:locale alternates,
 * the B2 Jetpack Open Graph suppression so the theme remains the single
 * owner of social metadata, and the featured-image alt text used by social
 * previews.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}



/**
 * ---------------------------------------------------------------------------
 * 4. OPEN GRAPH + TWITTER CARDS
 * ---------------------------------------------------------------------------
 * Uses featured image by default, falls back to the Conexão BR logo so social
 * previews are never blank.
 */
function conexao_seo_og_meta() {
	$og_type  = 'website';
	$og_title = get_bloginfo( 'name' );
	$og_url   = home_url( '/' );
	$og_desc  = get_bloginfo( 'description' );
	$og_image = '';

	// Fallback social card image (always present).
	$fallback_image = CONEXAO_THEME_URI . '/assets/images/conexao-social-card.svg';

	if ( is_singular() ) {
		$og_type  = 'article';
		$og_title = get_the_title();
		$og_url   = get_permalink();
		$og_desc  = has_excerpt() ? get_the_excerpt() : wp_trim_words( get_the_content(), 30, '...' );
		$og_image = has_post_thumbnail() ? get_the_post_thumbnail_url( null, 'large' ) : $fallback_image;
	} elseif ( is_post_type_archive() ) {
		$post_type = get_query_var( 'post_type' );
		$og_url    = get_post_type_archive_link( $post_type );
		$og_title  = conexao_archive_title();
		$og_desc   = conexao_archive_description();
		$og_image  = $fallback_image;
	} elseif ( is_tax() || is_category() || is_tag() ) {
		$og_url   = get_term_link( get_queried_object() );
		$og_title = single_term_title( '', false );
		$og_desc  = term_description();
		$og_image = $fallback_image;
	} elseif ( is_front_page() || is_home() ) {
		$og_image = $fallback_image;
	}

	// Never allow a blank social image.
	if ( ! $og_image ) {
		$og_image = $fallback_image;
	}

	$og_desc = wp_strip_all_tags( $og_desc );
	$og_desc = mb_substr( $og_desc, 0, 200 );

	?>
	<meta property="og:type" content="<?php echo esc_attr( $og_type ); ?>" />
	<meta property="og:title" content="<?php echo esc_attr( $og_title ); ?>" />
	<meta property="og:url" content="<?php echo esc_url( $og_url ); ?>" />
	<meta property="og:description" content="<?php echo esc_attr( $og_desc ); ?>" />
	<meta property="og:site_name" content="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
	<meta property="og:locale" content="<?php echo esc_attr( conexao_og_locale() ); ?>" />
	<meta property="og:image" content="<?php echo esc_url( $og_image ); ?>" />
	<meta property="og:image:alt" content="<?php echo esc_attr( $og_title ); ?>" />
	<meta name="twitter:card" content="summary_large_image" />
	<meta name="twitter:title" content="<?php echo esc_attr( $og_title ); ?>" />
	<meta name="twitter:description" content="<?php echo esc_attr( $og_desc ); ?>" />
	<meta name="twitter:image" content="<?php echo esc_url( $og_image ); ?>" />
	<?php
}
add_action( 'wp_head', 'conexao_seo_og_meta', 5 );

/**
 * ---------------------------------------------------------------------------
 * STAGE 3.1 — B2 FALLBACK: SINGLE SEO PRODUCER
 * ---------------------------------------------------------------------------
 * On a B2 fallback page the resolved record belongs to another language, so
 * Jetpack's Open Graph module (which resolves the locale from the detected
 * record language) emits `og:locale=pt_BR` while the theme's own SEO layer —
 * the site's single SEO owner — correctly emits the shell locale `en_US`.
 * Two producers must never emit conflicting SEO tags, so Jetpack's Open Graph
 * output is disabled on exactly those pages; the theme's complete OG/Twitter
 * set (see conexao_seo_og_meta()) keeps rendering.
 *
 * Scope is deliberately narrow: every non-B2 request keeps Jetpack's default
 * behavior, so nothing else on the site changes.
 *
 * @param bool $enabled Whether Jetpack should emit Open Graph tags.
 * @return bool
 */
function conexao_seo_disable_jetpack_og_on_b2( $enabled ) {
	if ( function_exists( 'conexao_is_language_fallback' ) && conexao_is_language_fallback() ) {
		return false;
	}

	return $enabled;
}
add_filter( 'jetpack_enable_open_graph', 'conexao_seo_disable_jetpack_og_on_b2', 10, 1 );

/**
 * ---------------------------------------------------------------------------
 * 11. IMAGE SEO
 * ---------------------------------------------------------------------------
 * Ensure featured images always have descriptive alt text. Falls back to the
 * post title when the editor left alt blank.
 */
function conexao_seo_image_alt( $html, $post_id ) {
	if ( ! $html ) {
		return $html;
	}

	// Only add alt if it's missing or empty.
	if ( preg_match( '/alt=["\']\s*["\']/', $html ) || ! preg_match( '/alt=/', $html ) ) {
		$title = get_the_title( $post_id );
		$html  = preg_replace( '/alt=["\']\s*["\']/', 'alt="' . esc_attr( $title ) . '"', $html, 1 );
		if ( ! preg_match( '/alt=/', $html ) ) {
			$html = preg_replace( '/(<img[^>]+?)(\/?>)/', '$1 alt="' . esc_attr( $title ) . '"$2', $html, 1 );
		}
	}

	return $html;
}
add_filter( 'post_thumbnail_html', 'conexao_seo_image_alt', 10, 2 );
