<?php
/**
 * Robots directives (per-page noindex and robots.txt)
 *
 * The per-context noindex meta emitted in wp_head and the robots.txt
 * filter that publishes the sitemap URL and controls indexing of the
 * language/fallback surfaces.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * ---------------------------------------------------------------------------
 * 5. INDEX / NOINDEX RULES
 * ---------------------------------------------------------------------------
 * Index: homepage, CPTs, useful categories/counties, static pages.
 * Noindex: search results, empty archives, paginated archives, utility pages.
 */
function conexao_seo_noindex() {
	$noindex = false;

	if ( is_search() ) {
		$noindex = true;
	} elseif ( is_archive() && ! is_post_type_archive() && ! is_tax() ) {
		// Empty or non-CPT archives (e.g. date archives) get noindexed.
		$noindex = true;
	} elseif ( is_paged() ) {
		// Paginated archives canonicalize to base; noindex to avoid dupes.
		$noindex = true;
	} elseif ( is_singular() && isset( $_GET['pagina'] ) && (int) $_GET['pagina'] > 1 ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only pagination state.
		// Query-string pagination on static pages (e.g. /empregos/?pagina=N on
		// the unified Empregos directory) is invisible to is_paged(). Treat it
		// exactly like /page/N/: the canonical above already points to the
		// base page, so paginated states are noindexed to avoid dupes.
		$noindex = true;
	} elseif ( is_tax( 'conexao_tag' ) ) {
		// Tag archives are thin/duplicative; noindex.
		$noindex = true;
	} elseif ( is_author() ) {
		$noindex = true;
	}

	if ( $noindex ) {
		echo '<meta name="robots" content="noindex, follow" />' . "\n";
	}
}
add_action( 'wp_head', 'conexao_seo_noindex', 3 );

/**
 * ---------------------------------------------------------------------------
 * 9. ROBOTS.TXT
 * ---------------------------------------------------------------------------
 * Serve a custom robots.txt that allows all public content and points to the
 * sitemap. Does not block CSS/JS or Googlebot.
 */
function conexao_seo_robots_txt( $output, $public ) {
	if ( ! $public ) {
		return $output;
	}

	$custom = "User-agent: *\n";
	$custom .= "Allow: /\n";
	$custom .= "Disallow: /wp-admin/\n";
	$custom .= "Disallow: /wp-includes/\n";
	$custom .= "Disallow: /?s=\n";
	$custom .= "Disallow: /search/\n";
	// Stage 2: mirror the search rules for the English namespace (architecture
	// §17) so /en/ search results are never indexed.
	$custom .= "Disallow: /en/?s=\n";
	$custom .= "Disallow: /en/search/\n";
	$custom .= "Disallow: /page/\n";
	$custom .= "Disallow: /feed/\n";
	$custom .= "Disallow: /trackback/\n";
	$custom .= "Disallow: /xmlrpc.php\n";
	$custom .= "Disallow: /wp-json/\n";
	$custom .= "\n";
	$custom .= "Sitemap: " . home_url( '/sitemap.xml' ) . "\n";

	return $custom;
}
add_filter( 'robots_txt', 'conexao_seo_robots_txt', 10, 2 );
