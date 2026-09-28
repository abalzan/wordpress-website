<?php
/**
 * PHP-level redirects and legacy redirect maps
 *
 * The legacy EN->PT and Wix redirect map, the missing-translation 302 and
 * the Lazer external redirect. Targets, status codes, precedence and the
 * .htaccess companion rules are unchanged by the Stage F modularisation.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * ---------------------------------------------------------------------------
 * 10. MIGRATION-SAFE REDIRECTS
 * ---------------------------------------------------------------------------
 * Additional 301 redirects for legacy/alternate URLs. No redirect chains.
 * All point directly to the final destination.
 */
function conexao_seo_redirects() {
	if ( is_admin() ) {
		return;
	}

	$path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
	$path = untrailingslashit( rawurldecode( $path ) );

	// Direct 301 map: old URL -> final URL (no chains).
	// Portuguese URLs are canonical. English URLs redirect to Portuguese.
	$redirects = array(
		// English guide paths -> Portuguese /guias/ CPT structure.
		'/guides'                      => '/guias/',
		'/guides/pps-number'           => '/guias/pps-number/',
		'/guides/medical-card'         => '/guias/medical-card/',
		'/guides/gp-registration'      => '/guias/gp-registration/',
		'/guides/abrir-conta-bancaria' => '/guias/abrir-conta-bancaria/',
		'/guides/alugar-casa'          => '/guias/alugar-casa/',
		'/guides/carteira-de-motorista' => '/guias/carteira-de-motorista/',
		'/guides/impostos'             => '/guias/impostos/',
		'/guides/cidadania-irlandesa'  => '/guias/cidadania-irlandesa/',
		'/guides/comprar-carro'        => '/guias/comprar-carro/',
		'/guides/child-benefit'        => '/guias/child-benefit/',
		'/guides/social-welfare'       => '/guias/social-welfare/',
		'/guides/abrir-empresa'        => '/guias/abrir-empresa/',
		'/guides/visto-irlanda'        => '/guias/visto-irlanda/',
		'/guides/irp-renewal'          => '/guias/irp-renewal/',
		'/guides/passaporte-irlandes'  => '/guias/passaporte-irlandes/',
		'/guides/mygovid'              => '/guias/mygovid/',
		'/guides/direitos-trabalhistas' => '/guias/direitos-trabalhistas/',
		'/guides/transporte-publico'   => '/guias/transporte-publico/',
		'/guides/susi'                 => '/guias/susi/',
		'/guides/servicos-emergencia'  => '/guias/servicos-emergencia/',
		'/guides/nct'                  => '/guias/nct/',
		'/guides/motor-tax'            => '/guias/motor-tax/',
		'/guides/eircode'              => '/guias/eircode/',
		'/guides/hap'                  => '/guias/hap/',
		'/guides/rtb'                  => '/guias/rtb/',
		'/guides/cao'                  => '/guias/cao/',
		'/guides/direitos-consumidor'  => '/guias/direitos-consumidor/',
		'/guides/protecao-dados'       => '/guias/protecao-dados/',
		'/guides/garda'                => '/guias/garda/',
		'/guides/revenue-myaccount'    => '/guias/revenue-myaccount/',

		// Legacy guide paths -> Portuguese /guias/ CPT structure.
		'/guias-praticos'              => '/guias/',
		'/guias-praticos/pps-number'   => '/guias/pps-number/',
		'/guias-praticos/medical-card' => '/guias/medical-card/',
		'/guias-praticos/gp-registration' => '/guias/gp-registration/',
		'/guias-praticos/abrir-conta-bancaria' => '/guias/abrir-conta-bancaria/',
		'/guias-praticos/alugar-casa'  => '/guias/alugar-casa/',
		'/guias-praticos/carteira-de-motorista' => '/guias/carteira-de-motorista/',
		'/guias-praticos/impostos'     => '/guias/impostos/',
		'/guias-praticos/cidadania-irlandesa' => '/guias/cidadania-irlandesa/',
		'/guias-praticos/comprar-carro' => '/guias/comprar-carro/',
		'/guias-praticos/child-benefit' => '/guias/child-benefit/',
		'/guias-praticos/social-welfare' => '/guias/social-welfare/',
		'/guias-praticos/abrir-empresa' => '/guias/abrir-empresa/',
		'/guias-praticos/visto-irlanda' => '/guias/visto-irlanda/',
		'/guias-praticos/irp-renewal'  => '/guias/irp-renewal/',
		'/guias-praticos/passaporte-irlandes' => '/guias/passaporte-irlandes/',
		'/guias-praticos/mygovid' => '/guias/mygovid/',
		'/guias-praticos/direitos-trabalhistas' => '/guias/direitos-trabalhistas/',
		'/guias-praticos/transporte-publico' => '/guias/transporte-publico/',
		'/guias-praticos/susi' => '/guias/susi/',
		'/guias-praticos/servicos-emergencia' => '/guias/servicos-emergencia/',
		'/guias-praticos/nct' => '/guias/nct/',
		'/guias-praticos/motor-tax' => '/guias/motor-tax/',
		'/guias-praticos/eircode' => '/guias/eircode/',
		'/guias-praticos/hap' => '/guias/hap/',
		'/guias-praticos/rtb' => '/guias/rtb/',
		'/guias-praticos/cao' => '/guias/cao/',
		'/guias-praticos/direitos-consumidor' => '/guias/direitos-consumidor/',
		'/guias-praticos/protecao-dados' => '/guias/protecao-dados/',
		'/guias-praticos/garda' => '/guias/garda/',
		'/guias-praticos/revenue-myaccount' => '/guias/revenue-myaccount/',

		// English events paths -> Portuguese.
		'/events'                      => '/eventos/',
		'/turismo-e-lazer'             => '/eventos/',

		// English jobs paths -> Portuguese.
		'/jobs'                        => '/empregos/',

		// English courses paths -> Portuguese.
		'/courses'                     => '/cursos/',

		// English sponsors paths -> Portuguese.
		'/sponsors'                    => '/apoiadores/',

		// English static pages -> Portuguese.
		'/ireland'                     => '/irlanda/',
		'/about-us'                    => '/sobre-nos/',
		'/about'                       => '/sobre-nos/',
		'/contact'                     => '/contato/',

		// Legacy business paths -> Apoiadores.
		'/empresas'                    => '/apoiadores/',
		'/businesses'                  => '/apoiadores/',

		// Legacy category paths -> static category landing pages.
		'/categories/moradia'          => '/moradia/',
		'/categories/saude'            => '/saude/',
		'/categories/familia'          => '/familia/',
		'/categories/transporte'       => '/transporte/',
		'/categories/financas'         => '/financas/',
		'/categories/beneficios'       => '/beneficios/',
		'/categories/educacao'         => '/educacao/',
		'/categories/documentos'       => '/documentos/',
		'/categories/onde-comer'       => '/onde-comer/',
		'/categories/empregos'         => '/empregos/',

		// Legacy county paths -> static county landing pages.
		'/counties/dublin'             => '/dublin/',
		'/counties/laois'              => '/laois/',
		'/counties/cork'               => '/cork/',
		'/counties/galway'             => '/galway/',
		'/counties/limerick'           => '/limerick/',
		'/counties/kildare'            => '/kildare/',
		'/counties/meath'              => '/meath/',
		'/counties/wicklow'            => '/wicklow/',
		'/counties/waterford'          => '/waterford/',

		// Legacy utility pages.
		'/privacidade'                 => '/politica-de-privacidade/',
		'/termos'                      => '/termos-de-uso/',
		'/sobre'                       => '/sobre-nos/',

		// Legacy Wix migration paths (Spanish/legacy) -> Portuguese structure.
		'/capacitação'                 => '/cursos/',
		'/capacitacao'                 => '/cursos/',
		'/s-projects-basic'            => '/guias/',
	);

	if ( isset( $redirects[ $path ] ) ) {
		wp_safe_redirect( home_url( $redirects[ $path ] ), 301 );
		exit;
	}

	// Pattern: /guides/{slug} -> /guias/{slug}/ (any remaining).
	if ( preg_match( '#^/guides/([^/]+)$#', $path, $m ) ) {
		wp_safe_redirect( home_url( '/guias/' . $m[1] . '/' ), 301 );
		exit;
	}

	// Pattern: /guias-praticos/{slug} -> /guias/{slug}/ (any remaining).
	if ( preg_match( '#^/guias-praticos/([^/]+)$#', $path, $m ) ) {
		wp_safe_redirect( home_url( '/guias/' . $m[1] . '/' ), 301 );
		exit;
	}

	// Pattern: /events/{slug} -> /eventos/{slug}/ (any remaining).
	if ( preg_match( '#^/events/([^/]+)$#', $path, $m ) ) {
		wp_safe_redirect( home_url( '/eventos/' . $m[1] . '/' ), 301 );
		exit;
	}

	// Pattern: /jobs/{slug} -> /empregos/{slug}/ (any remaining).
	if ( preg_match( '#^/jobs/([^/]+)$#', $path, $m ) ) {
		wp_safe_redirect( home_url( '/empregos/' . $m[1] . '/' ), 301 );
		exit;
	}

	// Pattern: /courses/{slug} -> /cursos/{slug}/ (any remaining).
	if ( preg_match( '#^/courses/([^/]+)$#', $path, $m ) ) {
		wp_safe_redirect( home_url( '/cursos/' . $m[1] . '/' ), 301 );
		exit;
	}

	// Pattern: /sponsors/{slug} -> /apoiadores/{slug}/ (any remaining).
	if ( preg_match( '#^/sponsors/([^/]+)$#', $path, $m ) ) {
		wp_safe_redirect( home_url( '/apoiadores/' . $m[1] . '/' ), 301 );
		exit;
	}

	// Pattern: /categories/{slug} -> /{slug}/ (any remaining).
	if ( preg_match( '#^/categories/([^/]+)$#', $path, $m ) ) {
		wp_safe_redirect( home_url( '/' . $m[1] . '/' ), 301 );
		exit;
	}

	// Pattern: /counties/{slug} -> /{slug}/ (any remaining).
	if ( preg_match( '#^/counties/([^/]+)$#', $path, $m ) ) {
		wp_safe_redirect( home_url( '/' . $m[1] . '/' ), 301 );
		exit;
	}

	// Pattern: /post/{slug} -> /blog/{slug}/ (legacy Wix blog posts).
	if ( preg_match( '#^/post/([^/]+)$#', $path, $m ) ) {
		wp_safe_redirect( home_url( '/blog/' . $m[1] . '/' ), 301 );
		exit;
	}

	// Pattern: /blog/categories/{slug} -> /category/{slug}/ (legacy Wix blog categories).
	if ( preg_match( '#^/blog/categories/([^/]+)$#', $path, $m ) ) {
		wp_safe_redirect( home_url( '/category/' . $m[1] . '/' ), 301 );
		exit;
	}
}
/*
 * STAGE 3.2 — priority 2 (was 5): the legacy redirect table must keep
 * precedence over Polylang's language canonical (template_redirect priority
 * 4). English pages now exist whose slugs collide with legacy English source
 * paths (e.g. /jobs/, /about-us/, /contact/ resolve the EN page by slug), and
 * Polylang would bounce them to /en/... before this table could apply the
 * production 301. Root-anchored legacy paths keep their exact redirect
 * contract; every other request is unaffected (the table only matches exact
 * legacy paths).
 */
add_action( 'template_redirect', 'conexao_seo_redirects', 2 );

/**
 * ---------------------------------------------------------------------------
 * 10b. MISSING-TRANSLATION REDIRECT (Stage 2 — approved B5 model)
 * ---------------------------------------------------------------------------
 *
 * When an `/en/` URL resolves to a record that has no English translation,
 * Polylang's frontend canonical would send a **301** to the Portuguese URL.
 * A permanent redirect is wrong for a URL that is scheduled to become a real
 * English page, so Stage 2 takes ownership of the status code:
 *
 *   - `inc/polylang.php` suppresses Polylang's own 301 for exactly these
 *     requests (`pll_check_canonical_url` filter);
 *   - this rule issues the approved **302** to the record's own URL instead.
 *
 * Approved policy mapping (architecture §12):
 *   - B1 (Guides, Blog, key static pages): EN URL 302 → PT page.
 *   - B2 (Events, Lazer, Sponsors, Courses, Employment, county pages): the
 *     approved end state renders PT content under an EN shell; that rendering
 *     (plus its EN archive inclusion and hreflang pair) is Stage 3 work, so
 *     Stage 2 falls back to the same temporary 302 — never a 301, never a
 *     fake EN detail page. Recorded in the Stage 2 report as a known
 *     limitation and Stage 3 prerequisite.
 *
 * Guarantees:
 *   - Never fires on a Portuguese request (all Portuguese content is `pt`).
 *   - Never fires on a real translation (record language === request language).
 *   - Never fires on the front page (`/en/` is a real, routable home).
 *   - Never fires on archives, search, 404 or feeds.
 *   - Targets Portuguese URLs only, so `/en/x → /x` can never loop back.
 */
function conexao_seo_missing_translation_redirect() {
	if ( ! conexao_polylang_active() || is_admin() || is_feed() || is_robots() || is_preview() || is_trackback() ) {
		return;
	}

	// STAGE 3.1 — B2 fallback owns its response: an EN request for a B2
	// record with no EN translation renders the PT record under the EN URL
	// (200 + notice + PT canonical) instead of the B1 302.
	if ( function_exists( 'conexao_should_render_b2_fallback' ) ) {
		// 1. B2 single records (events, Lazer, sponsors, courses, jobs).
		if ( is_singular() ) {
			$candidate = get_queried_object_id();
			if ( $candidate > 0 && conexao_should_render_b2_fallback( (int) $candidate ) ) {
				return;
			}
		}

		// 2. B2 posts page (Blog) — /en/blog/ renders PT content under the EN URL
		// without redirecting to /blog/. The posts page is a real page object
		// (page_for_posts) that is allowlisted as B2.
		if ( is_home() && ! empty( $GLOBALS['wp_query']->is_posts_page ) ) {
			$posts_page_id = (int) get_option( 'page_for_posts' );
			if ( $posts_page_id > 0 && conexao_should_render_b2_fallback( $posts_page_id ) ) {
				return;
			}
		}
	}

	$requested = conexao_requested_object_language();

	if ( ! $requested || $requested['language'] === conexao_requested_language_slug() ) {
		return;
	}

	if ( 'term' === $requested['type'] ) {
		$target = get_term_link( $requested['id'] );
		$target = is_wp_error( $target ) ? '' : $target;
	} else {
		$target = (string) get_permalink( $requested['id'] );
	}

	if ( ! $target ) {
		return;
	}

	// 302 (temporary): the English page may be created at this URL later.
	wp_safe_redirect( $target, 302 );
	exit;
}
add_action( 'template_redirect', 'conexao_seo_missing_translation_redirect', 6 );

/**
 * Resolve the external destination URL for a leisure location, if any.
 *
 * Priority: Offical Website URL, then Discover Ireland URL. Returns '' when
 * the location should use its internal /lazer/{slug}/ page.
 *
 * Phase 3B — redirect/display separation. This function remains the single
 * canonical classification for the external redirect (template_redirect hook,
 * sitemap, archive cards and the related-destinations selector all read it).
 * A record with the `_leisure_internal_page` flag set keeps its internal page
 * even when an Official Website or Discover Ireland URL exists: those URLs
 * then become display-only authoritative links (see
 * conexao_leisure_authoritative_links() in functions.php) and are never used
 * as a redirect destination. The mere presence of a display link must never
 * trigger the redirect — only the absence of the flag preserves the legacy
 * external classification, so all existing externally classified records
 * keep redirecting exactly as before.
 *
 * @param int $post_id Leisure post ID.
 * @return string External URL, or '' when none configured.
 */
function conexao_leisure_external_url( $post_id = 0 ) {
	$post_id = $post_id ? (int) $post_id : get_the_ID();

	if ( ! $post_id || 'leisure' !== get_post_type( $post_id ) ) {
		return '';
	}

	// Phase 3B — explicit "keep internal page" classification. When set, the
	// Official Website / Discover Ireland URLs are display-only references and
	// the record is internal for every consumer of this function.
	if ( get_post_meta( $post_id, '_leisure_internal_page', true ) ) {
		return '';
	}

	$official  = get_post_meta( $post_id, '_leisure_official_website', true );
	$discover  = get_post_meta( $post_id, '_leisure_discover_ireland', true );

	// Pick the first valid (non-empty) external destination by priority.
	$candidates = array_filter( array( $official, $discover ) );
	foreach ( $candidates as $candidate ) {
		$candidate = trim( (string) $candidate );
		// Defence in depth: only ever redirect to an absolute http(s) URL.
		if ( $candidate && preg_match( '#^https?://#i', $candidate ) && esc_url_raw( $candidate ) === $candidate ) {
			return $candidate;
		}
	}

	return '';
}

/**
 * Redirect a single leisure post to its configured external destination.
 *
 * Hooked on template_redirect at priority 6 (after the migration-safe 301
 * redirects at priority 5, before normal template rendering). Only applies to
 * singular leisure queries; uses a 302 so the destination can be changed later.
 */
function conexao_leisure_redirect() {
	if ( is_admin() || ! is_singular( 'leisure' ) ) {
		return;
	}

	$external = conexao_leisure_external_url( get_the_ID() );
	if ( ! $external ) {
		return; // No external destination -> render the normal internal page.
	}

	wp_redirect( $external, 302 );
	exit;
}
add_action( 'template_redirect', 'conexao_leisure_redirect', 6 );
