<?php
/**
 * Polylang capability guard, translated content types and language homes
 *
 * The only place that asks whether Polylang is active and exposing its
 * public API. Every other language module and the bilingual REST contract
 * gate on conexao_polylang_active(), so a single-language site behaves
 * exactly as it did before Polylang was installed. Also declares, at theme
 * load and in this exact order, which post types and taxonomies are
 * translated (conexao_category / conexao_tag only; conexao_county and
 * conexao_town stay shared and untranslated), the EN language home URL, and
 * the one-time guard that keeps Polylang's cached language list
 * authoritative once those declarations are registered.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Is Polylang active and exposing its public API?
 *
 * @return bool
 */
function conexao_polylang_active(): bool {
	return function_exists( 'pll_current_language' ) && function_exists( 'pll_languages_list' ) && function_exists( 'pll_home_url' );
}

/**
 * Post types Polylang translates.
 *
 * Kept as theme policy (registered on every environment) so the identity
 * model can never drift between local, staging and production:
 *
 *  - `guide` / `post` → B1 editorial content (EN translates 1:1).
 *  - `event` / `leisure` / `sponsor` / `job` / `course_provider`
 *    → B2 directories. Language is a record attribute only; identity meta
 *    (`_event_*`, `_leisure_uuid`, …) stays language-neutral.
 *
 * `recruitment_agency` and `permit_employer` are admin-only (no public URLs)
 * and deliberately excluded, as are attachments (media is shared).
 *
 * @param string[] $post_types  Current list (keys = values).
 * @param bool     $is_settings True when Polylang renders its settings screen.
 * @return string[]
 */
function conexao_polylang_translated_post_types( $post_types, $is_settings = false ) {
	if ( $is_settings ) {
		return $post_types;
	}

	foreach ( array( 'guide', 'event', 'leisure', 'sponsor', 'job', 'course_provider' ) as $post_type ) {
		$post_types[ $post_type ] = $post_type;
	}

	return $post_types;
}
add_filter( 'pll_get_post_types', 'conexao_polylang_translated_post_types', 10, 2 );

/**
 * Taxonomies Polylang translates.
 *
 * `conexao_category` / `conexao_tag` carry real vocabulary (Natureza →
 * Nature), so they are Polylang-translated: ONE shared concept identity per
 * term-translation pair, Portuguese slugs never touched, English terms get
 * genuine English names/slugs (Stage 3.2 pilot: scripts/stage32-translate-terms.php).
 *
 * STAGE 3.2 POLICY CORRECTION — `conexao_county` and `conexao_town` are
 * proper nouns and are deliberately NOT Polylang-translated anymore:
 *
 *  - The architecture decision §1 invariant says "Counties/towns are shared —
 *    no per-language duplicate terms". One physical term per county/town is
 *    the truest form of that invariant: every record of either language
 *    carries the SAME term, so `?county=dublin` / `?cidade=dublin` match PT
 *    fallback records and EN records identically in both language contexts.
 *  - Polylang Free ≥ 3.5 auto-creates a term translation (slug-suffixed, e.g.
 *    `dublin-en`, name copied verbatim) whenever a term of another language
 *    is assigned to a post (PLL_Crud_Posts::set_object_terms). For proper
 *    nouns that produces a junk duplicate that splits the filter slugs; for
 *    categories it is the intended mechanism (EN names are authored first,
 *    see the term-translation script, so the auto-create path never triggers).
 *
 * Consequence: county/town terms have no language and no translations.
 * Their filter URLs (`?county=`, `?cidade=`) are language-neutral and keep
 * their exact pre-Polylang behaviour.
 *
 * @param string[] $taxonomies  Current list (keys = values).
 * @param bool     $is_settings True when Polylang renders its settings screen.
 * @return string[]
 */
function conexao_polylang_translated_taxonomies( $taxonomies, $is_settings = false ) {
	if ( $is_settings ) {
		return $taxonomies;
	}

	foreach ( array( 'conexao_category', 'conexao_tag' ) as $taxonomy ) {
		$taxonomies[ $taxonomy ] = $taxonomy;
	}

	return $taxonomies;
}
add_filter( 'pll_get_taxonomies', 'conexao_polylang_translated_taxonomies', 10, 2 );

/**
 * STAGE 3.2 — serve the translated static front page at the language home URL.
 *
 * Polylang's per-language static-front-page model derives each language's
 * `page_on_front` from the translation set of the site's `page_on_front`
 * option (PLL_Static_Pages): the English record 126 ("home") is the English
 * front page *because* it is the linked translation of the Portuguese front
 * page 4 ("inicio"). That part of the flow is untouched here.
 *
 * What changes is the URL Polylang publishes as the language home. Out of the
 * box, Polylang Free's links layer (PLL_Links_Model::set_language_home_url)
 * reports the language home as the translated page's slugged URL
 * (/en/home/) and its own canonical redirect then 301s /en/ → /en/home/.
 * The approved architecture requires the English homepage at the bare /en/
 * URL — the exact mirror of Portuguese, whose front page lives at / while
 * /inicio/ consolidates onto it.
 *
 * The supported declaration point for that is Polylang's language-construction
 * pipeline: the `pll_additional_language_data` filter (whose allowlist exists
 * precisely for `home_url`/`search_url`/`page_on_front`/`page_for_posts`, and
 * on which Polylang registers its own `set_language_home_urls`). Declaring the
 * directory home there makes every consumer agree on one canonical home URL:
 * pll_home_url(), the language switcher, hreflang, the nav "Home" link and
 * Polylang's OWN canonical redirect, which then consolidates
 * /en/home/ → /en/ instead of the reverse.
 *
 * The read-time `pll_language_home_url` filter is additionally wired so the
 * declaration also holds when a site defines PLL_CACHE_HOME_URL=false (it is
 * guarded by that constant and is otherwise a no-op).
 *
 * This is configuration through Polylang's API, not a redirect or rewrite:
 * no template_redirect special case is added, no URL is hidden, and the
 * page_on_front resolution (which record serves /en/) is unchanged.
 *
 * @param array $additional_data Additional language data being built.
 * @param array $language        Language data (db shape).
 * @return array
 */
function conexao_polylang_language_home_data( $additional_data, $language ) {
	if ( ! conexao_polylang_active() ) {
		return $additional_data;
	}

	$slug = is_array( $language ) && ! empty( $language['slug'] ) ? (string) $language['slug'] : '';
	if ( '' !== $slug ) {
		$additional_data['home_url'] = trailingslashit( PLL()->links_model->home_url( $slug ) );
	}

	return $additional_data;
}
add_filter( 'pll_additional_language_data', 'conexao_polylang_language_home_data', 20, 2 );

/**
 * Read-time companion of conexao_polylang_language_home_data() — see the
 * rationale there. Only consulted when PLL_CACHE_LANGUAGES/PLL_CACHE_HOME_URL
 * are disabled; returns the same directory home so behaviour is constant.
 *
 * @param string $url      Language home URL computed so far.
 * @param array  $language PLL_Language::to_array( 'db' ) shape.
 * @return string
 */
function conexao_polylang_language_home_url( $url, $language ) {
	if ( ! conexao_polylang_active() ) {
		return $url;
	}

	$slug = is_array( $language ) && ! empty( $language['slug'] ) ? (string) $language['slug'] : '';
	if ( '' === $slug ) {
		return $url;
	}

	return trailingslashit( PLL()->links_model->home_url( $slug ) );
}
add_filter( 'pll_language_home_url', 'conexao_polylang_language_home_url', 20, 2 );

/**
 * STAGE 3.2 — keep the declared language home URLs authoritative.
 *
 * Polylang builds its language list at plugins_loaded priority 1 — BEFORE the
 * theme (and therefore the declaration above) is loaded. Whenever the
 * persistent language cache (the pll_languages_list transient) is rebuilt in
 * that window — a language was edited, the permalink structure changed, or a
 * cache clean ran — the stored home_url reverts to Polylang's default
 * (/en/home/). This guard detects that state at theme load and rebuilds the
 * list once, now that the declaration is registered, so the corrected list
 * is persisted again for every later request. One comparison in the steady
 * state; no writes.
 *
 * @return void
 */
function conexao_polylang_language_home_ensure(): void {
	if ( ! conexao_polylang_active() || ! function_exists( 'PLL' ) || ! PLL() ) {
		return;
	}

	$en = PLL()->model->get_language( 'en' );
	if ( ! $en ) {
		return;
	}

	$declared = trailingslashit( PLL()->links_model->home_url( 'en' ) );

	if ( (string) $en->get_home_url() === $declared ) {
		return;
	}

	// Stale list (built before this theme file loaded). Rebuild it now, with
	// the pll_additional_language_data declaration in place.
	PLL()->model->clean_languages_cache();
	PLL()->model->get_languages_list();
}

// Runs at theme load: after Polylang's bootstrap (PLL() exists) and after the
// declaration filters above are registered.
conexao_polylang_language_home_ensure();
