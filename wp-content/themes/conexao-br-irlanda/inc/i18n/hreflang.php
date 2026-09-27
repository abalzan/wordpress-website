<?php
/**
 * Alternate language links and the canonical bridge
 *
 * Builds the hreflang alternate set for the current request, adds the
 * default-language link, and supplies the canonical URL through the
 * conexao_seo_canonical_url filter so the SEO layer and the language layer
 * agree on exactly one canonical value.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * hreflang attribute value for a Polylang language slug.
 *
 * pt → "pt-BR", en → "en" (a bare "en" covers English for all regions; the
 * site has no per-region English variant).
 *
 * @param string $slug Language slug.
 * @return string
 */
function conexao_hreflang_code( string $slug ): string {
	return 'pt' === $slug ? 'pt-BR' : $slug;
}

/**
 * Translation-aware alternate links for hreflang output.
 *
 * Only REAL translation relationships (or real, content-backed language
 * archives) produce alternates — an object with no English translation emits
 * no `hreflang="en"` link, and no alternate ever points at a URL that would
 * redirect. `x-default` always points at the Portuguese (default-language)
 * URL, which is also the canonical of a fallback rendering.
 *
 * @return array[] Each entry: array( 'hreflang' => 'pt-BR', 'url' => '…' ).
 */
function conexao_hreflang_links(): array {
	if ( ! conexao_polylang_active() ) {
		return array();
	}

	$languages = pll_languages_list( array( 'fields' => '' ) );
	if ( ! is_array( $languages ) || count( $languages ) < 2 ) {
		return array();
	}

	$default = conexao_default_language_slug();
	$current = conexao_current_language_slug();
	$links   = array();

	// 1. Singular content: the translation group is the only source of truth.
	// A record without a translation (and any fallback rendering of it) emits
	// just x-default → the record's own URL, matching its canonical.
	if ( is_singular() ) {
		$object_id = (int) get_queried_object_id();
		$links     = conexao_is_language_fallback() ? array() : conexao_object_translation_links( $object_id );

		if ( empty( $links ) ) {
			$permalink = get_permalink( $object_id );

			return $permalink ? array( array( 'hreflang' => 'x-default', 'url' => $permalink ) ) : array();
		}

		return conexao_hreflang_with_default( $links, $default );
	}

	// 2. Language homes and the posts page.
	//    - `/` and `/en/` are both real, router-served home URLs (note that a
	//      secondary-language home is `is_home()` without `is_posts_page`, and
	//      that `/en/` is NOT `is_front_page()` while the Portuguese front page
	//      has no EN translation).
	//    - The static posts page (`/blog/`) is an ordinary page record, so it
	//      follows the singular rule: translation links, otherwise x-default.
	if ( is_front_page() || ( is_home() && empty( $GLOBALS['wp_query']->is_posts_page ) ) ) {
		foreach ( $languages as $language ) {
			if ( ! is_object( $language ) || empty( $language->slug ) ) {
				continue;
			}

			$slug           = (string) $language->slug;
			$links[ $slug ] = array(
				'hreflang' => conexao_hreflang_code( $slug ),
				'url'      => trailingslashit( pll_home_url( $slug ) ),
			);
		}

		return conexao_hreflang_with_default( $links, $default );
	}

	if ( is_home() && ! empty( $GLOBALS['wp_query']->is_posts_page ) ) {
		$posts_page_id = (int) get_option( 'page_for_posts' );
		$links         = $posts_page_id ? conexao_object_translation_links( $posts_page_id ) : array();

		if ( empty( $links ) ) {
			$permalink = $posts_page_id ? get_permalink( $posts_page_id ) : '';

			return $permalink ? array( array( 'hreflang' => 'x-default', 'url' => $permalink ) ) : array();
		}

		return conexao_hreflang_with_default( $links, $default );
	}

	// 3. Post type archives: every language archive is routable, but an
	// alternate only carries meaning when that language actually has content
	// of the post type — an empty shell gets no hreflang (and no sitemap URL).
	if ( is_post_type_archive() ) {
		$post_type = (string) get_query_var( 'post_type' );

		if ( '' === $post_type ) {
			return array();
		}

		foreach ( $languages as $language ) {
			if ( ! is_object( $language ) || empty( $language->slug ) ) {
				continue;
			}

			$slug = (string) $language->slug;

			// The archive being viewed always gets an alternate (it exists);
			// other languages only when they actually hold content of this
			// post type — an empty shell is never advertised.
			$has_content = ( $slug === $current ) || conexao_language_has_post_type_content( $post_type, $slug );

			if ( ! $has_content ) {
				continue;
			}

			$links[ $slug ] = array(
				'hreflang' => conexao_hreflang_code( $slug ),
				'url'      => conexao_language_archive_url( $post_type, $slug ),
			);
		}

		return conexao_hreflang_with_default( $links, $default );
	}

	// 4. Taxonomy archives: shared terms are ONE term per language model, so an
	// alternate is emitted only when Polylang links an actual term
	// translation. Search, 404, author and date archives emit nothing.
	if ( is_tax() || is_category() || is_tag() ) {
		$links = conexao_object_translation_links( (int) get_queried_object_id(), 'term' );

		return conexao_hreflang_with_default( $links, $default );
	}

	return array();
}

/**
 * Append the x-default alternate (the default-language URL) to a link set.
 *
 * x-default is omitted when the default language has no link of its own: it
 * must never point at a URL that would itself redirect.
 *
 * @param array[] $links   hreflang => array( hreflang, url ).
 * @param string  $default Default language slug.
 * @return array[]
 */
function conexao_hreflang_with_default( array $links, string $default ): array {
	if ( '' !== $default && ! empty( $links[ $default ] ) ) {
		$links['x-default'] = array(
			'hreflang' => 'x-default',
			'url'      => $links[ $default ]['url'],
		);
	}

	return array_values( $links );
}

/**
 * Canonical URL for the current request, language-aware.
 *
 * Rules (architecture §14):
 *  - A record in the current language → its own (self-referencing) permalink.
 *  - A record rendered as a fallback in another language (B2) → the record's
 *    own language permalink, so Portuguese stays the canonical of
 *    untranslated data.
 *
 * @param string $canonical Canonical computed by inc/seo.php.
 * @return string
 */
function conexao_polylang_canonical_url( $canonical ) {
	if ( ! conexao_polylang_active() || '' === $canonical ) {
		return $canonical;
	}

	$is_posts_page = is_home() && ! empty( $GLOBALS['wp_query']->is_posts_page );

	if ( ( is_singular() || $is_posts_page ) && conexao_is_language_fallback() ) {
		$object_id = $is_posts_page ? (int) get_option( 'page_for_posts' ) : (int) get_queried_object_id();
		$permalink = $object_id > 0 ? get_permalink( $object_id ) : '';

		if ( $permalink ) {
			return $permalink;
		}
	}

	return $canonical;
}
add_filter( 'conexao_seo_canonical_url', 'conexao_polylang_canonical_url', 10 );
