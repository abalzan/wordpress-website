<?php
/**
 * XML sitemap generation
 *
 * The lightweight custom XML sitemap (core's sitemap is disabled in its
 * favour), the per-URL sitemap entry builder and the EN pass. URL sets,
 * priorities and alternates are unchanged by the Stage F modularisation.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * ---------------------------------------------------------------------------
 * 8. XML SITEMAP
 * ---------------------------------------------------------------------------
 * Custom lightweight sitemap (no plugin needed). Includes pages, all 5 CPTs,
 * and relevant taxonomies. Excludes search, admin, utility, and empty archives.
 * The WordPress core sitemap (/wp-sitemap.xml) is disabled to avoid competing
 * sitemaps.
 */
function conexao_seo_disable_core_sitemap() {
	return false;
}
add_filter( 'wp_sitemaps_enabled', 'conexao_seo_disable_core_sitemap' );

function conexao_seo_sitemap() {
	// Only serve on the exact sitemap path.
	if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
		return;
	}

	$path = wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
	$path = untrailingslashit( $path );

	if ( '/sitemap.xml' !== $path && '/sitemap_index.xml' !== $path ) {
		return;
	}

	/*
	 * Stage 2 language scoping.
	 *
	 * The theme sitemap is the canonical producer (architecture §15) and lists
	 * the Portuguese URL set: every Portuguese URL is unchanged and no
	 * redirect-only / fallback / empty-archive URL is ever listed. Queries are
	 * pinned to the default language explicitly so the output cannot depend on
	 * which language context happens to serve the request; translations are
	 * exposed through `<xhtml:link rel="alternate">` entries only where a real
	 * translation pair exists.
	 */
	$sitemap_lang = conexao_default_language_slug();

	// Prevent WordPress from processing this as a normal request.
	status_header( 200 );
	header( 'Content-Type: application/xml; charset=UTF-8' );
	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

	// Homepage — `/` and `/en/` are both real pages, so the pair is declared.
	$home_alternates = array();
	if ( conexao_polylang_active() ) {
		$home_alternates = conexao_hreflang_with_default(
			array(
				'pt' => array( 'hreflang' => conexao_hreflang_code( 'pt' ), 'url' => trailingslashit( pll_home_url( 'pt' ) ) ),
				'en' => array( 'hreflang' => conexao_hreflang_code( 'en' ), 'url' => trailingslashit( pll_home_url( 'en' ) ) ),
			),
			'pt'
		);
	}
	conexao_seo_sitemap_url( home_url( '/' ), '1.0', 'daily', $home_alternates );

	// Static pages (exclude utility/redirect pages).
	$excluded_pages = array( 'privacidade', 'termos', 'sobre', 'search', 'cookies' );
	$page_query_args = array(
		'post_type'      => 'page',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'no_found_rows'  => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	);
	if ( '' !== $sitemap_lang ) {
		$page_query_args['lang'] = $sitemap_lang;
	}
	$pages = get_posts( $page_query_args );
	$front_page_id = (int) get_option( 'page_on_front' );
	foreach ( $pages as $page ) {
		if ( in_array( $page->post_name, $excluded_pages, true ) ) {
			continue;
		}
		// STAGE 3.2 — the static front page's own permalink IS the homepage
		// URL (get_page_link() maps page_on_front to home_url('/')), so
		// emitting it here would duplicate the homepage entry above.
		if ( $front_page_id > 0 && (int) $page->ID === $front_page_id ) {
			continue;
		}
		// STAGE 3.1: conexao_object_translation_links() only returns REAL
		// translation pairs, so untranslated pages emit no EN alternate. B2
		// fallback URLs (no EN record) are never listed as indexable EN
		// <url> entries; B1 redirect-only URLs are excluded (no alternate).
		conexao_seo_sitemap_url( get_permalink( $page->ID ), '0.8', 'monthly', conexao_object_translation_links( (int) $page->ID ) );
	}

	// STAGE 3.1 — EN pass: real EN translations get their own indexable EN
	// <url> entry (self-canonical EN). B2 fallbacks and B1 redirect-only URLs
	// are never emitted as EN entries.
	if ( conexao_polylang_active() ) {
		$en_page_args = array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'lang'           => 'en',
		);
		$en_pages = get_posts( $en_page_args );
		foreach ( $en_pages as $en_page ) {
			if ( in_array( $en_page->post_name, $excluded_pages, true ) ) {
				continue;
			}
			conexao_seo_sitemap_url( get_permalink( $en_page->ID ), '0.8', 'monthly', conexao_object_translation_links( (int) $en_page->ID ) );
		}
	}

	// CPTs — batched so the sitemap stays lightweight even when a CPT grows
	// to thousands of posts (no posts_per_page => -1 full-table load).
	$cpt_priorities = array(
		'guide'    => '0.9',
		'event'    => '0.8',
		'job'      => '0.7',
		'sponsor'  => '0.7',
		'leisure'  => '0.7',
	);
	$sitemap_batch = 500;
	foreach ( $cpt_priorities as $cpt => $priority ) {
		$page = 1;
		do {
			$cpt_query_args = array(
				'post_type'      => $cpt,
				'post_status'    => 'publish',
				'posts_per_page' => $sitemap_batch,
				'paged'          => $page,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			);
			if ( '' !== $sitemap_lang ) {
				$cpt_query_args['lang'] = $sitemap_lang;
			}
			$items = get_posts( $cpt_query_args );
			foreach ( $items as $item_id ) {
				if ( 'leisure' === $cpt && conexao_leisure_external_url( $item_id ) ) {
					continue;
				}
				// STAGE 3.1: PT <url> entries carry alternates for REAL
				// translations only (B2 fallbacks / B1 redirect-only URLs emit
				// no EN alternate and no EN <url> entry).
				conexao_seo_sitemap_url( get_permalink( $item_id ), $priority, 'weekly', conexao_object_translation_links( (int) $item_id ) );
			}
			$page++;
		} while ( count( $items ) === $sitemap_batch );

		// STAGE 3.1 — EN pass: real EN records get their own indexable EN
		// <url> entry. Only emitted where an EN post actually exists (B2
		// fallbacks have no EN record, so they never appear here).
		if ( conexao_polylang_active() ) {
			$en_page = 1;
			do {
				$en_items = get_posts( array(
					'post_type'      => $cpt,
					'post_status'    => 'publish',
					'posts_per_page' => $sitemap_batch,
					'paged'          => $en_page,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'lang'           => 'en',
				) );
				foreach ( $en_items as $en_item_id ) {
					if ( 'leisure' === $cpt && conexao_leisure_external_url( $en_item_id ) ) {
						continue;
					}
					conexao_seo_sitemap_url( get_permalink( $en_item_id ), $priority, 'weekly', conexao_object_translation_links( (int) $en_item_id ) );
				}
				$en_page++;
			} while ( count( $en_items ) === $sitemap_batch );
		}
	}

	// Blog posts (`post`) — same contract as the CPT passes above: exactly one
	// self-canonical entry per public URL, alternates only for REAL translation
	// pairs, one pass per language (so the English translated posts are listed
	// under their own /en/ URLs and the Portuguese originals keep theirs).
	// B2 fallback and B1 redirect-only URLs are never emitted by any pass, and
	// the posts page itself is already listed by the Pages pass above.
	$blog_langs = array( $sitemap_lang );
	if ( conexao_polylang_active() ) {
		$blog_langs[] = 'en';
	}
	foreach ( array_unique( $blog_langs ) as $blog_lang ) {
		$blog_page = 1;
		do {
			$blog_args = array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => $sitemap_batch,
				'paged'          => $blog_page,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			);
			if ( '' !== $blog_lang ) {
				$blog_args['lang'] = $blog_lang;
			}
			$blog_items = get_posts( $blog_args );
			foreach ( $blog_items as $blog_item_id ) {
				conexao_seo_sitemap_url( get_permalink( $blog_item_id ), '0.6', 'weekly', conexao_object_translation_links( (int) $blog_item_id ) );
			}
			$blog_page++;
		} while ( count( $blog_items ) === $sitemap_batch );
	}

	// Taxonomies (categories + counties, only non-empty).
	foreach ( array( 'conexao_category', 'conexao_county' ) as $tax ) {
		$terms = get_terms( array(
			'taxonomy'   => $tax,
			'hide_empty' => true,
		) );
		if ( is_wp_error( $terms ) ) {
			continue;
		}
		foreach ( $terms as $term ) {
			conexao_seo_sitemap_url( get_term_link( $term ), '0.6', 'monthly', conexao_object_translation_links( (int) $term->term_id, 'term' ) );
		}
	}

	echo '</urlset>';
	exit;
}
add_action( 'template_redirect', 'conexao_seo_sitemap', 0 );

/**
 * Helper: output a single sitemap <url> entry.
 *
 * @param string  $url        Canonical URL of the entry (default language).
 * @param string  $priority   sitemap priority.
 * @param string  $freq       sitemap changefreq.
 * @param array[] $alternates Translation alternates (hreflang => array(…)).
 *                            Only real translation sets are passed in, so an
 *                            untranslated URL emits no alternate at all.
 */
function conexao_seo_sitemap_url( $url, $priority, $freq, array $alternates = array() ) {
	echo "\t<url>\n";
	echo "\t\t<loc>" . esc_url( $url ) . "</loc>\n";
	foreach ( $alternates as $alternate ) {
		if ( empty( $alternate['hreflang'] ) || empty( $alternate['url'] ) ) {
			continue;
		}
		echo "\t\t<xhtml:link rel=\"alternate\" hreflang=\"" . esc_attr( $alternate['hreflang'] ) . '" href="' . esc_url( $alternate['url'] ) . "\" />\n";
	}
	echo "\t\t<changefreq>" . esc_html( $freq ) . "</changefreq>\n";
	echo "\t\t<priority>" . esc_html( $priority ) . "</priority>\n";
	echo "\t</url>\n";
}
