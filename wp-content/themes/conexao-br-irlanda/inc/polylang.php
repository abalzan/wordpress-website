<?php
/**
 * Conexão BR Irlanda — Polylang integration (Stage 2).
 *
 * This is the theme's ONLY Polylang-aware file. It implements the approved
 * English-support architecture (CONEXAO_BR_ENGLISH_ARCHITECTURE_DECISION.md,
 * Stage 2) by adapting the Stage 1 locale abstraction — it does not replace
 * it. Everything else in the theme keeps depending on the Stage 1 extension
 * points:
 *
 *  - `conexao_current_locale()` (inc/i18n.php) stays the single
 *    application-level answer to "which locale is active?". This file hooks
 *    the `conexao_current_locale` filter to Polylang's per-request language,
 *    so every Stage 1 consumer (recurrence labels, JS i18n payload, static
 *    strings, dates, og:locale) follows the language context automatically.
 *  - The Stage 1 `locale` correction (inc/i18n.php) is made a no-op while
 *    Polylang owns the locale: once a language layer exists, `en_US` is a
 *    legitimate per-request locale and must not be rewritten to pt_BR.
 *
 * Responsibilities (Stage 2):
 *  1. Language context helpers (`conexao_current_language_slug()`,
 *     `conexao_default_language_slug()`, `conexao_language_suffix()`).
 *  2. Declare which post types/taxonomies Polylang must translate
 *     (`pll_get_post_types` / `pll_get_taxonomies`). This is policy, not a
 *     per-environment setting: the identity-critical CPTs (event, leisure,
 *     sponsor …) and the shared taxonomies (county, town, category, tag)
 *     must be language-aware everywhere the theme runs.
 *  3. Per-language transient cache keys (`conexao_lang_cache_key()` +
 *     `conexao_flush_language_cache()`), so PT and EN can never serve each
 *     other's cached HTML.
 *  4. The language switcher renderer (`conexao_language_switcher()`), which
 *     links to a real translation when one exists and to a real
 *     target-language archive/home otherwise — never to a fake detail page.
 *  5. Translation-aware SEO helpers consumed by inc/seo.php
 *     (`conexao_language_switch_url()`, `conexao_hreflang_links()`,
 *     `conexao_is_language_fallback()`), keeping inc/seo.php the single SEO
 *     owner.
 *
 * Rollback: every function here is guarded by `conexao_polylang_active()`
 * (Polylang's own functions), and no filter is registered when the plugin is
 * inactive — deactivating Polylang restores exact Stage 1 behaviour.
 *
 * @package conexao-br-irlanda
 */

defined( 'ABSPATH' ) || exit;

/**
 * Is Polylang active and exposing its public API?
 *
 * @return bool
 */
function conexao_polylang_active(): bool {
	return function_exists( 'pll_current_language' ) && function_exists( 'pll_languages_list' ) && function_exists( 'pll_home_url' );
}

/**
 * STAGE 3.1 — B2 fallback notice.
 *
 * Renders the approved English notice on B2 fallback pages ("Portuguese
 * content under an EN URL"). The Portuguese body is preserved exactly; the
 * notice is chrome only. Safe to call on any request: emits nothing unless
 * conexao_is_language_fallback() is true.
 *
 * @return void
 */
function conexao_b2_fallback_notice(): void {
	if ( ! conexao_is_language_fallback() ) {
		return;
	}
	?>
	<div class="language-fallback-notice" role="note" aria-live="polite" lang="en">
		<p><?php esc_html_e( 'This content is displayed in Portuguese.', 'conexao-br-irlanda' ); ?></p>
	</div>
	<?php
}

/**
 * Slug of the language serving the current request ('pt', 'en', …).
 *
 * @return string Empty string when Polylang is inactive.
 */
function conexao_current_language_slug(): string {
	if ( ! conexao_polylang_active() ) {
		return '';
	}

	// B2 fallback shell: the response is rendered in the language of the
	// REQUESTED URL even though the resolved object belongs to another
	// language. Polylang flips its own `curlang` to the object's language for
	// the static posts page, which would otherwise leak Portuguese URLs and
	// labels into the English shell (e.g. the Blog navigation item). Reading
	// the captured requested language keeps the whole shell consistent.
	if ( function_exists( 'conexao_is_language_fallback' ) && conexao_is_language_fallback() ) {
		$requested = conexao_requested_language_slug();

		if ( '' !== $requested ) {
			return $requested;
		}
	}

	$slug = pll_current_language( 'slug' );

	return is_string( $slug ) ? $slug : '';
}

/**
 * Slug of the site's default language (Portuguese).
 *
 * @return string Empty string when Polylang is inactive.
 */
function conexao_default_language_slug(): string {
	if ( ! conexao_polylang_active() ) {
		return '';
	}

	$slug = pll_default_language( 'slug' );

	return is_string( $slug ) ? $slug : '';
}

/**
 * The WordPress-style locale of a Polylang language slug.
 *
 * @param string $slug Language slug ('pt', 'en'). Defaults to the current one.
 * @return string e.g. "pt_BR", "en_US", or '' when unknown.
 */
function conexao_language_locale( string $slug = '' ): string {
	if ( ! conexao_polylang_active() ) {
		return '';
	}

	if ( '' === $slug ) {
		$slug = conexao_current_language_slug();
	}

	$languages = pll_languages_list( array( 'fields' => '' ) );
	if ( ! is_array( $languages ) ) {
		return '';
	}

	foreach ( $languages as $language ) {
		if ( is_object( $language ) && isset( $language->slug, $language->locale ) && $slug === $language->slug ) {
			return (string) $language->locale;
		}
	}

	return '';
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

/**
 * Point the Stage 1 locale extension point at Polylang's active language.
 *
 * This is the ONLY place where the theme reads Polylang for locale purposes.
 * Stage 1 consumers keep calling `conexao_current_locale()`.
 *
 * @param string $locale Locale resolved so far (usually the site locale).
 * @return string Locale of the active Polylang language, or $locale untouched.
 */
function conexao_polylang_current_locale( $locale ) {
	// STAGE 3.1 — B2 fallback: the shell locale follows the REQUESTED language,
	// not the resolved record's language, so the chrome stays English while the
	// Portuguese body is rendered unchanged beneath the fallback notice.
	if ( function_exists( 'conexao_is_language_fallback' ) && conexao_is_language_fallback() ) {
		$shell = conexao_language_locale( conexao_requested_language_slug() );

		if ( '' !== $shell ) {
			return $shell;
		}
	}

	$active = conexao_language_locale();

	return '' !== $active ? $active : $locale;
}
add_filter( 'conexao_current_locale', 'conexao_polylang_current_locale', 10 );

/**
 * STAGE 3.1 — B2 shell locale for `get_locale()`.
 *
 * `language_attributes()` (and any core consumer of `get_locale()`) reads the
 * locale directly, so the B2 shell must be applied there too — otherwise the
 * English shell would render `<html lang="pt-BR">` while the translated chrome
 * and the fallback notice are English. Scope is strictly the B2 fallback state
 * (see conexao_is_language_fallback()); every other request keeps Polylang's
 * own locale resolution untouched.
 *
 * @param string $locale Locale resolved so far.
 * @return string
 */
function conexao_polylang_b2_shell_locale( $locale ) {
	if ( ! function_exists( 'conexao_is_language_fallback' ) || ! conexao_is_language_fallback() ) {
		return $locale;
	}

	$shell = conexao_language_locale( conexao_requested_language_slug() );

	return '' !== $shell ? $shell : $locale;
}
add_filter( 'locale', 'conexao_polylang_b2_shell_locale', 99 );

/**
 * Slug of the language the REQUESTED URL asks for.
 *
 * This is deliberately different from `conexao_current_language_slug()`:
 * Polylang flips its own current-language state to the *detected* language
 * while it computes a canonical redirect (`PLL_Frontend_Canonical::
 * check_canonical_url()` sets `curlang = $language` on purpose), so
 * `pll_current_language()` can no longer tell which language the URL asked for
 * once a redirect decision is being made. The value is captured once, early
 * (`wp` / `template_redirect` priority 0 — after Polylang has parsed the URL
 * and before its canonical check at priority 4), then reused for the request.
 *
 * Returns '' when Polylang has not resolved a language yet; the empty value is
 * NOT cached so a later call can still capture it.
 *
 * @return string
 */
function conexao_requested_language_slug(): string {
	static $slug = null;

	if ( null !== $slug ) {
		return $slug;
	}

	if ( ! conexao_polylang_active() ) {
		$slug = '';

		return $slug;
	}

	$current = pll_current_language( 'slug' );

	if ( is_string( $current ) && '' !== $current ) {
		$slug = $current;
	}

	return '' === $slug || null === $slug ? '' : $slug;
}
add_action( 'wp', 'conexao_requested_language_slug', 0 );
add_action( 'template_redirect', 'conexao_requested_language_slug', 0 );

/**
 * Language suffix for cache keys ('_pt', '_en').
 *
 * @return string Empty string when Polylang is inactive.
 */
function conexao_language_suffix(): string {
	$slug = conexao_current_language_slug();

	return '' === $slug ? '' : '_' . sanitize_key( $slug );
}

/**
 * Make a transient / cache key language-specific.
 *
 * Architecture §18: PT and EN caches must be separate — the URL prefix alone
 * does not separate server-side transients.
 *
 * @param string $key Base key, e.g. "conexao_home_events".
 * @return string e.g. "conexao_home_events_en".
 */
function conexao_lang_cache_key( $key ) {
	return $key . conexao_language_suffix();
}

/**
 * Delete a language-scoped cache key in EVERY language.
 *
 * Save/invalidation hooks must clear all language variants, otherwise an EN
 * visitor keeps seeing stale content after a PT edit (and vice versa). The
 * un-suffixed key is cleared too, covering keys written by pre-Polylang code
 * paths.
 *
 * @param string $key Base key, e.g. "conexao_home_events".
 * @return void
 */
function conexao_flush_language_cache( $key ) {
	delete_transient( $key );

	if ( ! conexao_polylang_active() ) {
		return;
	}

	$slugs = pll_languages_list( array( 'fields' => 'slug' ) );
	if ( ! is_array( $slugs ) ) {
		return;
	}

	foreach ( $slugs as $slug ) {
		delete_transient( $key . '_' . sanitize_key( (string) $slug ) );
	}
}

/**
 * Delete a language-scoped object-cache key in EVERY language.
 *
 * The counterpart of conexao_flush_language_cache() for `wp_cache_*` entries
 * (filter bars, term lists). On hosts with a persistent object cache the
 * language-suffixed keys must all be purged on save, otherwise one language
 * keeps serving a stale filter list.
 *
 * @param string $key   Base cache key.
 * @param string $group Cache group.
 * @return void
 */
function conexao_flush_language_object_cache( $key, $group ) {
	wp_cache_delete( $key, $group );

	if ( ! conexao_polylang_active() ) {
		return;
	}

	$slugs = pll_languages_list( array( 'fields' => 'slug' ) );
	if ( ! is_array( $slugs ) ) {
		return;
	}

	foreach ( $slugs as $slug ) {
		wp_cache_delete( $key . '_' . sanitize_key( (string) $slug ), $group );
	}
}

/**
 * The current request URL (query string preserved).
 *
 * @return string
 */
function conexao_current_request_url(): string {
	$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- used for URL construction only; escaped by callers.

	return home_url( $path );
}

/**
 * Rewrite the current request into another language's URL space.
 *
 * Uses the language home URLs as anchors, so it also works in archive,
 * taxonomy, search and 404 contexts where no object translation exists:
 * `/eventos/?cidade=dublin` ↔ `/en/eventos/?cidade=dublin`.
 *
 * @param string $target_slug Target language slug.
 * @return string
 */
function conexao_switch_language_in_current_url( string $target_slug ): string {
	if ( ! conexao_polylang_active() ) {
		return conexao_current_request_url();
	}

	$current_home = trailingslashit( pll_home_url( conexao_current_language_slug() ) );
	$target_home  = trailingslashit( pll_home_url( $target_slug ) );
	$url          = conexao_current_request_url();

	if ( $current_home === $target_home ) {
		return $url;
	}

	$current_host = wp_parse_url( $current_home, PHP_URL_HOST );
	$current_path = (string) wp_parse_url( $current_home, PHP_URL_PATH );
	$url_host     = wp_parse_url( $url, PHP_URL_HOST );
	$url_path     = trailingslashit( '/' . ltrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) );

	if ( $current_host !== $url_host || 0 !== strpos( $url_path, $current_path ) ) {
		return $target_home;
	}

	$relative = substr( $url_path, strlen( $current_path ) );
	$query    = (string) wp_parse_url( $url, PHP_URL_QUERY );

	return $target_home . $relative . ( '' !== $query ? '?' . $query : '' );
}

/**
 * STAGE 5 — resolve the posts-page request in the REQUESTED language.
 *
 * The static posts page (Blog) is identified by WordPress through the
 * `page_for_posts` option, which Polylang filters per language. When the
 * English posts page reuses the canonical `blog` path (the approved
 * `/en/blog/` shape), the parsed request carries only `pagename=blog` and
 * WordPress' language-blind page lookup returns the FIRST page with that
 * slug — the Portuguese one — so the request looks like a language mismatch
 * and Polylang's canonical redirect sends /en/blog/ back to /blog/.
 *
 * Rewriting that ambiguous slug to the explicit `page_id` of the posts page
 * of the CURRENT language keeps WordPress and Polylang in agreement:
 *  - EN with a real EN posts page  → /en/blog/ serves the English archive;
 *  - EN without one (B2 fallback)  → `page_for_posts` falls back to the
 *    Portuguese page, so this resolves to the exact page WordPress already
 *    resolved and the approved fallback is untouched;
 *  - PT                            → the same page as before (no-op).
 *
 * Only a request whose parsed path is exactly the posts page's own slug is
 * touched — no other page, archive or URL shape can be affected.
 *
 * @param array $query_vars Parsed request query vars (WP `request` filter).
 * @return array
 */
function conexao_resolve_posts_page_request( $query_vars ) {
	if ( is_admin() || ! is_array( $query_vars ) || ! conexao_polylang_active() ) {
		return $query_vars;
	}

	if ( empty( $query_vars['pagename'] ) || ! empty( $query_vars['page_id'] ) ) {
		return $query_vars;
	}

	// Only a prefixed (non-default) language request can be ambiguous: the
	// default language keeps WordPress' own resolution untouched, so its
	// request handling is byte-identical to the pre-Stage-5 behaviour.
	$requested = isset( $query_vars['lang'] ) ? sanitize_key( (string) $query_vars['lang'] ) : '';

	if ( '' === $requested || $requested === conexao_default_language_slug() ) {
		return $query_vars;
	}

	$posts_page_id = (int) get_option( 'page_for_posts' );

	if ( $posts_page_id <= 0 ) {
		return $query_vars;
	}

	$posts_page = get_post( $posts_page_id );

	if ( ! $posts_page instanceof WP_Post || 'publish' !== $posts_page->post_status ) {
		return $query_vars;
	}

	if ( trim( (string) $query_vars['pagename'], '/' ) !== $posts_page->post_name ) {
		return $query_vars;
	}

	unset( $query_vars['pagename'] );
	$query_vars['page_id'] = $posts_page_id;

	return $query_vars;
}
add_filter( 'request', 'conexao_resolve_posts_page_request', 20 );

/**
 * STAGE 5 — give the resolved posts-page request the posts-page semantics.
 *
 * The companion of conexao_resolve_posts_page_request(): that filter hands
 * WordPress the explicit `page_id` of the posts page of the requested
 * language. The query must then behave like the posts archive (a posts loop
 * honouring `?categoria=`, pagination and the archive filters) instead of a
 * single-page query: the page id answered the ROUTING question, not the
 * CONTENT question, so it is cleared here. Only a main query whose page_id is
 * exactly the posts page of the current language is touched: the shape created
 * above, plus a hand-written `?page_id=<posts page>` request — which WordPress
 * itself also answers with the posts archive. No other query is affected.
 *
 * @param WP_Query $query Main query.
 * @return void
 */
function conexao_mark_posts_page_query( $query ) {
	if ( is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
		return;
	}

	if ( ! conexao_polylang_active() ) {
		return;
	}

	$posts_page_id = (int) get_option( 'page_for_posts' );

	if ( $posts_page_id <= 0 || (int) $query->get( 'page_id' ) !== $posts_page_id ) {
		return;
	}

	$query->is_page       = false;
	$query->is_singular   = false;
	$query->is_home       = true;
	$query->is_posts_page = true;
	$query->set( 'page_id', 0 );
}
add_action( 'pre_get_posts', 'conexao_mark_posts_page_query', 1 );

/**
 * Language-aware URL of the native posts page (Blog).
 *
 * The WordPress posts archive at `/blog/` is served by the `page_for_posts`
 * page, NOT by a `post` post type archive (WordPress registers `post` with
 * `has_archive = false`, so `get_post_type_archive_link( 'post' )` is false
 * and `conexao_lang_url_archive_post_type()` never reports `post`).
 *
 * While the posts page has no EN translation, Blog is an approved B2 destination:
 * its URL in a non-default language is the language home + the posts page's own
 * path (`/blog/` → `/en/blog/`), which renders the Portuguese posts under the
 * English URL (B2 notice) instead of redirecting the visitor back to the
 * Portuguese `/blog/`. The path is derived from the posts page permalink, so no
 * slug and no `/en/` prefix are hard-coded. Once the Blog has a real English
 * translation (Stage 5: linked EN posts page + translated EN posts), the EN
 * archive is a genuine English archive and the B2 rendering retires itself.
 *
 * A real linked Polylang translation always wins, so creating a genuine EN
 * posts page later keeps working with no code change.
 *
 * @param string $target_slug Target language slug.
 * @return string URL, or '' when no posts page is configured.
 */
function conexao_posts_page_url( string $target_slug ): string {
	$posts_page_id = (int) get_option( 'page_for_posts' );

	if ( $posts_page_id <= 0 ) {
		return '';
	}

	// A real linked translation always wins.
	if ( function_exists( 'pll_get_post' ) ) {
		$translation = pll_get_post( $posts_page_id, $target_slug );

		if ( $translation && (int) $translation !== $posts_page_id && 'publish' === get_post_status( (int) $translation ) ) {
			$permalink = get_permalink( (int) $translation );

			if ( $permalink ) {
				return (string) $permalink;
			}
		}
	}

	// No translation: B2 — the target URL is the language home + the posts
	// page's own path segment (derived, never hard-coded).
	$pt_path = untrailingslashit( (string) wp_parse_url( (string) get_permalink( $posts_page_id ), PHP_URL_PATH ) );

	if ( '' === $pt_path || '/' === $pt_path ) {
		return '';
	}

	return trailingslashit( pll_home_url( $target_slug ) ) . ltrim( $pt_path, '/' ) . '/';
}

/**
 * Target-language archive URL of a post type.
 *
 * Archive slugs are NOT translated (Portuguese slugs are canonical in both
 * languages, per architecture §6), so the target-language archive is the
 * target language home + the post type's own rewrite slug.
 *
 * @param string $post_type   Post type name.
 * @param string $target_slug Target language slug.
 * @return string Empty string when the post type has no archive.
 */
function conexao_language_archive_url( string $post_type, string $target_slug ): string {
	if ( ! conexao_polylang_active() || ! $post_type ) {
		return '';
	}

	if ( 'post' === $post_type ) {
		// Blog: the native WordPress posts archive, served by the posts page
		// (page_for_posts). Blog is an approved B2 destination, so the
		// language-aware URL is the language home + the posts page path
		// (/en/blog/) — never a redirect back to the Portuguese /blog/.
		$posts_url = conexao_posts_page_url( $target_slug );

		if ( '' !== $posts_url ) {
			return $posts_url;
		}

		return trailingslashit( pll_home_url( $target_slug ) );
	}

	$object = get_post_type_object( $post_type );
	if ( ! $object || empty( $object->has_archive ) ) {
		return '';
	}

	$slug = ( is_array( $object->rewrite ) && ! empty( $object->rewrite['slug'] ) ) ? $object->rewrite['slug'] : $post_type;

	return trailingslashit( pll_home_url( $target_slug ) ) . trailingslashit( $slug );
}

/**
 * STAGE 3.3 — language-aware destination for a canonical Portuguese path.
 *
 * Theme chrome has always built absolute internal links with
 * `home_url( '/eventos/' )`-style calls. Polylang rewrites `home_url()` only
 * for the BARE home URL (`PLL_Frontend_Filters_Links::home_url()` returns the
 * URL untouched as soon as `$path` is not empty — see its
 * `rtrim( $url, '/' ) != $this->links_model->home` guard), so every
 * path-bearing `home_url()` call keeps pointing at the Portuguese URL inside
 * an English request. That is the remaining leakage Stage 3.2 §26 recorded.
 *
 * This helper resolves such a path to the destination an English visitor must
 * reach, using the smallest existing APIs — no link rewriter, no HTML
 * post-processing, no hard-coded /en/ URLs:
 *
 *  1. Polylang inactive, unknown/empty current language, or current language
 *     is the default language → `home_url( $path )` byte-identical to the
 *     pre-Polylang behaviour. Portuguese output cannot change.
 *  2. The path addresses an existing object (page, or any post reachable at
 *     that path) that HAS a published translation in the current language →
 *     that translation's permalink (real EN translation: `/empregos/` →
 *     `/en/jobs/`).
 *  3. The path addresses a post type archive → the current language's archive
 *     URL (`conexao_language_archive_url()`): `/eventos/` → `/en/eventos/`.
 *     The posts page (the native Blog archive at `/blog/`) is handled before
 *     that in step (2b): it is an approved B2 destination, so `/blog/` →
 *     `/en/blog/` and never bounces back to the Portuguese `/blog/`.
 *  4. Everything else — a B1 page with no translation, a utility page, a path
 *     that does not resolve — returns `home_url( $path )`: the approved B1
 *     behaviour. No EN detail URL is ever invented for untranslated content,
 *     and B2 pages are NOT auto-promoted here (linking straight to the
 *     Portuguese B2 URL is the approved B1-style destination; see Stage 3.2
 *     §5 for the B2 allowlist contract).
 *
 * @param string $path Path relative to the site home, e.g. "/eventos/".
 * @return string Absolute URL.
 */
function conexao_lang_url( string $path ): string {
	$path = '/' . ltrim( $path, '/' );

	if ( ! conexao_polylang_active() ) {
		return home_url( $path );
	}

	$current = conexao_current_language_slug();
	$default = conexao_default_language_slug();

	if ( '' === $current || $current === $default ) {
		return home_url( $path );
	}

	// (2) Post type archive path. Checked before the page lookup because
	// WordPress serves the archive for a path that collides with a page slug
	// (e.g. `/lazer/` is the leisure archive even though a canonical `lazer`
	// page also exists in the catalogue).
	$post_type = conexao_lang_url_archive_post_type( $path );

	if ( '' !== $post_type ) {
		$archive = conexao_language_archive_url( $post_type, $current );

		if ( '' !== $archive ) {
			return $archive;
		}
	}

	// (2b) Posts page (Blog) path. /blog/ is the native WordPress posts archive
	// served by the page_for_posts page (WordPress registers `post` with
	// has_archive = false, so the archive branch above never matches it).
	// Blog is an approved B2 destination: in a non-default language it resolves
	// to the language home + the posts page path (/en/blog/) and renders the
	// Portuguese posts under the EN URL — never a redirect back to /blog/.
	$posts_page_id = (int) get_option( 'page_for_posts' );

	if ( $posts_page_id > 0 ) {
		$posts_page_path = untrailingslashit( (string) wp_parse_url( (string) get_permalink( $posts_page_id ), PHP_URL_PATH ) );

		if ( '' !== $posts_page_path && '/' !== $posts_page_path && untrailingslashit( $path ) === $posts_page_path ) {
			$posts_url = conexao_posts_page_url( $current );

			if ( '' !== $posts_url ) {
				return $posts_url;
			}
		}
	}

	// (3) Real translation of a real object addressed by this exact path.
	$object = conexao_lang_url_object( $path );

	if ( $object instanceof WP_Post ) {
		$translation = pll_get_post( (int) $object->ID, $current );

		if ( $translation && (int) $translation !== (int) $object->ID && 'publish' === get_post_status( $translation ) ) {
			$permalink = get_permalink( (int) $translation );

			if ( $permalink ) {
				return $permalink;
			}
		}

		// No translation: approved B1 behaviour — the Portuguese URL.
		return home_url( $path );
	}

	// (4) B1 / utility / unresolved: unchanged Portuguese URL.
	return home_url( $path );
}

/**
 * The object addressed by a canonical path, when there is one.
 *
 * Only single-segment-rooted permalinks are considered (pages, and the
 * hierarchical structures `get_page_by_path()` understands). Archive paths
 * are handled by conexao_lang_url_archive_post_type().
 *
 * @param string $path Path relative to the site home.
 * @return WP_Post|null
 */
function conexao_lang_url_object( string $path ) {
	$trimmed = trim( $path, '/' );

	if ( '' === $trimmed ) {
		return null;
	}

	$object = get_page_by_path( $trimmed, OBJECT, 'page' );

	// Stage 4.5 — shared slugs: the EN Blog/Newsletter pages intentionally
	// reuse the PT post_name (blog, newsletter), so get_page_by_path() can
	// return either record. Normalise to the default-language source so the
	// translation lookup above (pll_get_post) always starts from the same
	// canonical page. No-op for every unique-slug page and when the object
	// already is the default-language record.
	if ( $object instanceof WP_Post && conexao_polylang_active() && function_exists( 'pll_get_post' ) ) {
		$default_id = (int) pll_get_post( (int) $object->ID, conexao_default_language_slug() );
		if ( $default_id && $default_id !== (int) $object->ID ) {
			$object = get_post( $default_id );
		}
	}

	return $object instanceof WP_Post ? $object : null;
}

/**
 * The post type served by an archive path ("eventos" → "event").
 *
 * @param string $path Path relative to the site home.
 * @return string Post type name, or '' when the path is not an archive root.
 */
function conexao_lang_url_archive_post_type( string $path ): string {
	$trimmed = trim( $path, '/' );

	// Archives are single-segment roots (/eventos/, /lazer/, …).
	if ( '' === $trimmed || false !== strpos( $trimmed, '/' ) ) {
		return '';
	}

	foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type => $object ) {
		if ( empty( $object->has_archive ) ) {
			continue;
		}

		$slug = is_string( $object->has_archive ) ? $object->has_archive : '';

		if ( '' === $slug ) {
			$slug = ( is_array( $object->rewrite ) && ! empty( $object->rewrite['slug'] ) ) ? $object->rewrite['slug'] : $post_type;
		}

		if ( $slug === $trimmed ) {
			return (string) $post_type;
		}
	}

	return '';
}

/**
 * STAGE 3.3 — the current language's counterpart of a taxonomy term.
 *
 * Used by language-aware taxonomy links (the Guides category cards). Returns:
 *
 *  - the term itself when Polylang is inactive or no language context exists
 *    (exact pre-Polylang behaviour),
 *  - the LINKED term of the current language when one exists (and, when a
 *    post type is given, when that term is actually used by published content
 *    of that type in this language — a filter link must never lead to an empty
 *    English archive),
 *  - null when there is no usable counterpart, so the caller can fall back to
 *    the plain language archive URL instead of inventing a term URL.
 *
 * Shared proper-noun taxonomies (county/town) are deliberately not
 * Polylang-translated; `pll_get_term()` simply returns the same term for them,
 * so they keep resolving to themselves in both languages.
 *
 * @param mixed  $term      Candidate term (WP_Term expected).
 * @param string $post_type Optional post type the term must have content for.
 * @return WP_Term|null
 */
function conexao_lang_term( $term, string $post_type = '' ) {
	if ( ! $term instanceof WP_Term ) {
		return null;
	}

	if ( ! conexao_polylang_active() || ! function_exists( 'pll_get_term' ) ) {
		return $term;
	}

	$current = conexao_current_language_slug();

	if ( '' === $current ) {
		return $term;
	}

	$translation_id = pll_get_term( (int) $term->term_id, $current );

	if ( ! $translation_id ) {
		return null;
	}

	$translated = get_term( (int) $translation_id, $term->taxonomy );

	if ( ! $translated || is_wp_error( $translated ) ) {
		return null;
	}

	if ( '' !== $post_type && ! conexao_term_has_language_content( (int) $translated->term_id, $translated->taxonomy, $post_type, $current ) ) {
		return null;
	}

	return $translated;
}

/**
 * Does a term carry published content of a post type in a language?
 *
 * One cheap query (no_found_rows, ids only), memoised per request.
 *
 * @param int    $term_id    Term id.
 * @param string $taxonomy   Taxonomy name.
 * @param string $post_type  Post type name.
 * @param string $language   Language slug.
 * @return bool
 */
function conexao_term_has_language_content( int $term_id, string $taxonomy, string $post_type, string $language ): bool {
	static $cache = array();

	$key = $term_id . '|' . $taxonomy . '|' . $post_type . '|' . $language;

	if ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}

	$args = array(
		'post_type'              => $post_type,
		'post_status'            => 'publish',
		'posts_per_page'         => 1,
		'fields'                 => 'ids',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
		'tax_query'              => array(
			array(
				'taxonomy' => $taxonomy,
				'field'    => 'term_id',
				'terms'    => $term_id,
			),
		),
	);

	if ( '' !== $language && conexao_polylang_active() ) {
		$args['lang'] = $language;
	}

	$query = new WP_Query( $args );

	$cache[ $key ] = ! empty( $query->posts );

	return $cache[ $key ];
}

/**
 * STAGE 3.3 — look a term up by slug across every language.
 *
 * `get_term_by()` is language-filtered by Polylang, so a canonical Portuguese
 * slug cannot be found while an English request is being rendered. Card
 * definitions and other canonical configuration keep Portuguese slugs, so the
 * lookup is widened here (Polylang's documented `lang => ''` query argument)
 * and the caller then resolves the current language's counterpart with
 * `conexao_lang_term()`.
 *
 * @param string $slug     Term slug.
 * @param string $taxonomy Taxonomy name.
 * @return WP_Term|null
 */
function conexao_find_term_across_languages( string $slug, string $taxonomy ) {
	$slug = sanitize_title( $slug );

	if ( '' === $slug ) {
		return null;
	}

	$args = array(
		'taxonomy'   => $taxonomy,
		'slug'       => $slug,
		'hide_empty' => false,
	);

	if ( conexao_polylang_active() ) {
		$args['lang'] = '';
	}

	$terms = get_terms( $args );

	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return null;
	}

	return $terms[0] instanceof WP_Term ? $terms[0] : null;
}

/**
 * Translation-aware URL of a language for the object/context being viewed.
 *
 * Policy (approved B5 model, Stage 2 scope):
 *  1. Same language → the current URL (self link).
 *  2. A real translation exists → that translation's permalink.
 *  3. No translation → the SAME URL in the target language for
 *     archive/taxonomy/search/home contexts, otherwise that post type's
 *     target-language archive. Stage 2 never links to a detail page that does
 *     not resolve (no fake EN detail URLs); the approved B2 fallback URLs are
 *     created in Stage 3 together with their content, so until then the
 *     switcher points at the real EN archive.
 *
 * @param string $target_slug Target language slug.
 * @return string
 */
function conexao_language_switch_url( string $target_slug ): string {
	if ( ! conexao_polylang_active() ) {
		return '';
	}

	// STAGE 3.1 — B2 fallback: the request language is the shell language even
	// though the resolved record belongs to another language. The shell
	// language's row must self-link (its URL is the one being viewed).
	$requested = conexao_requested_language_slug();
	if ( '' === $requested ) {
		$requested = conexao_current_language_slug();
	}

	if ( $target_slug === $requested ) {
		return conexao_current_request_url();
	}

	// STAGE 3.1 — B2 fallback: the record's own language links to the record's
	// real (canonical) permalink, never to another fake shell URL.
	$is_posts_page = is_home() && ! empty( $GLOBALS['wp_query']->is_posts_page );

	if ( conexao_is_language_fallback() && ( is_singular() || $is_posts_page ) ) {
		$object_id       = $is_posts_page ? (int) get_option( 'page_for_posts' ) : (int) get_queried_object_id();
		$object_language = ( $object_id > 0 && function_exists( 'pll_get_post_language' ) ) ? pll_get_post_language( $object_id, 'slug' ) : '';

		if ( $target_slug === $object_language ) {
			$permalink = $object_id > 0 ? get_permalink( $object_id ) : '';

			if ( $permalink ) {
				return $permalink;
			}
		}
	}

	if ( is_singular() && function_exists( 'pll_get_post' ) ) {
		$translated_id = pll_get_post( get_queried_object_id(), $target_slug );

		if ( $translated_id && (int) $translated_id !== get_queried_object_id() ) {
			$permalink = get_permalink( (int) $translated_id );
			if ( $permalink ) {
				return $permalink;
			}
		}

		$fallback = conexao_language_archive_url( (string) get_post_type(), $target_slug );

		return $fallback ? $fallback : trailingslashit( pll_home_url( $target_slug ) );
	}

	return conexao_switch_language_in_current_url( $target_slug );
}

/**
 * STAGE 3.1 — Serve the translated front page AT the language home.
 *
 * With a static front page, WordPress 301-redirects the URL that does not
 * match the front page's permalink. For the default language that is correct
 * (`/inicio/` → `/`), and Polylang mirrors it for `/en/` — but in the EN
 * language the translated front page is a real page record with its own slug,
 * so `/en/` was 301-redirected to `/en/home/`. Stage 3.1 requires `/en/` to
 * render the English homepage itself (and PT `/` to stay untouched).
 *
 * Scope is narrow: the redirect is suppressed only when the requested URL is
 * EXACTLY a language home (already slash-terminated `pll_home_url()` for the
 * requested language). Every other canonical redirect — trailing-slash
 * correction, pagination, feeds, `/en/home/`-style page URLs — is untouched.
 *
 * @param string|false $redirect_url  Canonical redirect computed by core.
 * @param string       $requested_url Requested URL.
 * @return string|false
 */
function conexao_polylang_language_home_serves_front_page( $redirect_url, $requested_url ) {
	if ( is_admin() || is_feed() || is_robots() || is_preview() || is_trackback() ) {
		return $redirect_url;
	}

	if ( ! function_exists( 'pll_home_url' ) || ! function_exists( 'conexao_requested_language_slug' ) ) {
		return $redirect_url;
	}

	$requested = conexao_requested_language_slug();
	if ( '' === $requested ) {
		return $redirect_url;
	}

	$requested_path = (string) wp_parse_url( (string) $requested_url, PHP_URL_PATH );

	// Only slash-terminated URLs (i.e. real home requests, not `/en`).
	if ( '' === $requested_path || '/' !== substr( $requested_path, -1 ) ) {
		return $redirect_url;
	}

	// The front page of the requested language is what must be served here.
	if ( ! is_front_page() && ! ( is_home() && ! is_paged() ) ) {
		return $redirect_url;
	}

	// Compare PATHS only: the redirect core computes here is the same home
	// under a different host/scheme representation, and hosts must never
	// influence the decision.
	$home_path = (string) wp_parse_url( pll_home_url( $requested ), PHP_URL_PATH );

	if ( trailingslashit( $requested_path ) !== trailingslashit( $home_path ) ) {
		return $redirect_url;
	}

	// The requested URL is the language home itself: serve the translated
	// front page here instead of redirecting to its slugged permalink.
	return false;
}
add_filter( 'redirect_canonical', 'conexao_polylang_language_home_serves_front_page', 20, 2 );

/**
 * True when the current request renders a record of another language.
 *
 * This is the approved B2 state ("Portuguese content under an EN URL"): the
 * requested language has no translation of the resolved object. Canonical
 * must then point at the record's own language URL (see inc/seo.php).
 *
 * STAGE 3.1: returns true only for B2-eligible post types (see
 * conexao_should_render_b2_fallback()). B1 types never report fallback —
 * they keep the 302 policy until a real translation exists.
 *
 * @return bool
 */
function conexao_is_language_fallback(): bool {
	if ( ! conexao_polylang_active() || ! function_exists( 'pll_get_post_language' ) ) {
		return false;
	}

	// Two request shapes can render a fallback: a single record (event,
	// leisure, sponsor, course, job, B2 page) and the static posts page
	// (Blog, /blog/) — the latter is a real page object that resolves as
	// is_home(), not is_singular().
	$is_posts_page = is_home() && ! empty( $GLOBALS['wp_query']->is_posts_page );

	if ( ! is_singular() && ! $is_posts_page ) {
		return false;
	}

	$object_id = $is_posts_page ? (int) get_option( 'page_for_posts' ) : (int) get_queried_object_id();

	if ( $object_id <= 0 ) {
		return false;
	}

	if ( function_exists( 'conexao_should_render_b2_fallback' ) && ! conexao_should_render_b2_fallback( (int) $object_id ) ) {
		return false;
	}

	$object_language = pll_get_post_language( $object_id, 'slug' );
	$current         = conexao_requested_language_slug();

	return is_string( $object_language ) && '' !== $object_language && '' !== $current && $object_language !== $current;
}

/**
 * Translation links of a single object (post or term), keyed by language slug.
 *
 * Used by the hreflang emitter and the theme sitemap. Only real Polylang
 * translation relationships appear — a record with no translation returns an
 * empty array (never a self-link to a redirect target).
 *
 * @param int    $object_id Post ID or term ID.
 * @param string $type      'post' or 'term'.
 * @return array<string,array{hreflang:string,url:string}>
 */
function conexao_object_translation_links( int $object_id, string $type = 'post' ): array {
	if ( ! conexao_polylang_active() || $object_id <= 0 ) {
		return array();
	}

	$links = array();

	if ( 'term' === $type ) {
		if ( ! function_exists( 'pll_get_term_translations' ) ) {
			return array();
		}

		$translations = pll_get_term_translations( $object_id );

		foreach ( $translations as $slug => $term_id ) {
			$term_link = get_term_link( (int) $term_id );
			if ( is_wp_error( $term_link ) ) {
				continue;
			}

			$links[ (string) $slug ] = array(
				'hreflang' => conexao_hreflang_code( (string) $slug ),
				'url'      => $term_link,
			);
		}

		return $links;
	}

	if ( ! function_exists( 'pll_get_post_translations' ) ) {
		return array();
	}

	$translations = pll_get_post_translations( $object_id );

	foreach ( $translations as $slug => $translated_id ) {
		$permalink = get_permalink( (int) $translated_id );
		if ( ! $permalink ) {
			continue;
		}

		$links[ (string) $slug ] = array(
			'hreflang' => conexao_hreflang_code( (string) $slug ),
			'url'      => $permalink,
		);
	}

	return $links;
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
 * The translated object the current front-end request resolves to.
 *
 * Covers the three URL contexts that can carry a language mismatch: a
 * singular post/page/CPT single, the static posts page (`/blog/`), and a
 * taxonomy term archive. The static front page is deliberately excluded: its
 * secondary-language URL (`/en/`) is a real, routable page produced by
 * Polylang's static-pages module and must never be redirected away.
 *
 * @return array{id:int,type:string,language:string}|null
 */
function conexao_requested_object_language() {
	if ( ! conexao_polylang_active() || is_front_page() ) {
		return null;
	}

	$type = '';
	$id   = 0;

	if ( is_singular() ) {
		$type = 'post';
		$id   = (int) get_queried_object_id();
	} elseif ( is_home() && ! empty( $GLOBALS['wp_query']->is_posts_page ) ) {
		// The static posts page (`/blog/`) is a real page object. `is_home()`
		// WITHOUT `is_posts_page` is a language home serving the posts index
		// (e.g. `/en/` while the Portuguese front page has no EN translation):
		// that is a real, routable language home and must never redirect.
		$type = 'post';
		$id   = (int) get_option( 'page_for_posts' );
	} elseif ( is_tax() || is_category() || is_tag() ) {
		$object = get_queried_object();
		if ( $object instanceof WP_Term ) {
			$type = 'term';
			$id   = (int) $object->term_id;
		}
	}

	if ( ! $id ) {
		return null;
	}

	if ( 'term' === $type ) {
		$language = function_exists( 'pll_get_term_language' ) ? pll_get_term_language( $id, 'slug' ) : '';
	} else {
		$language = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $id, 'slug' ) : '';
	}

	if ( ! is_string( $language ) || '' === $language ) {
		return null;
	}

	return array(
		'id'       => $id,
		'type'     => $type,
		'language' => $language,
	);
}

/**
 * STAGE 3.1 — B2 fallback content types.
 *
 * B2 = "Portuguese content under an English URL": an EN request with no EN
 * translation renders the existing PT record with EN chrome + notice.
 *
 * B1 = Guides, Blog posts, key narrative static pages: keep the approved 302
 * policy until a real translation exists (never render fallback).
 *
 * @return string[]
 */
function conexao_b2_post_types(): array {
	return array( 'event', 'leisure', 'sponsor', 'course_provider', 'job' );
}

/**
 * Is a post type eligible for B2 fallback rendering?
 *
 * @param string $post_type Post type name.
 * @return bool
 */
function conexao_is_b2_post_type( $post_type ): bool {
	return in_array( (string) $post_type, conexao_b2_post_types(), true );
}

/**
 * STAGE 3.1/3.2 — B2-eligible static pages (explicit allowlist).
 *
 * Directory/county pages approved for B2 are allowlisted by their Portuguese
 * slug. The allowlist is the approved architecture decision
 * (CONEXAO_BR_ENGLISH_ARCHITECTURE_DECISION.md §8): county pages and /irlanda/
 * are directory/information pages → B2 (PT content under EN shell + notice).
 *
 * Everything else stays out on purpose:
 *  - key narrative/legal pages (inicio, sobre-nos, contato, empregos,
 *    politica-de-privacidade, termos-de-uso, cookies) are REAL TRANSLATIONS
 *    (B1 until translated, then the translation serves — never B2);
 *  - the remaining narrative/utility pages (category hubs, newsletter,
 *    revista, anuncie, search, …) keep B1;
 *  - B2 is never a catch-all and never a substitute for editorial translation.
 *
 * The list is static and code-reviewed; the filter exists only so tests can
 * exercise the boundary without editing the theme. Entries must be existing
 * Portuguese page slugs (never new URLs).
 *
 * @return string[] Portuguese page slugs eligible for B2 fallback rendering.
 */
function conexao_b2_page_allowlist(): array {
	$allowlist = array(
		// County landing pages (directory/information hubs — decision §8).
		'dublin',
		'cork',
		'galway',
		'limerick',
		'kildare',
		'meath',
		'wicklow',
		'waterford',
		'laois',
		// Ireland country guide hub (directory/information — decision §8).
		'irlanda',
		// Blog posts page - B2 WHILE it has no EN translation (PT content under
		// the EN shell + notice, so /en/blog/ renders under the EN URL without
		// redirecting to PT). See CONEXAO_BR_ENGLISH_BLOG_NAVIGATION_FIX_REPORT.md.
		// STAGE 5: once the linked EN posts page exists this entry becomes inert for
		// that site - conexao_should_render_b2_fallback() returns false as soon as a
		// published linked EN translation exists, so /en/blog/ serves the real
		// English archive. The entry is kept for installs that have not run the Blog
		// translation yet (B2 is never a substitute for editorial translation).
		'blog',
	);

	return (array) apply_filters( 'conexao_b2_page_allowlist', $allowlist );
}

/**
 * Is a static page eligible for B2 fallback rendering?
 *
 * @param int $post_id Page ID.
 * @return bool
 */
function conexao_is_b2_page( $post_id ): bool {
	$post = get_post( (int) $post_id );
	if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
		return false;
	}

	return in_array( $post->post_name, conexao_b2_page_allowlist(), true );
}

/**
 * Should a singular request be answered with B2 fallback instead of a 302?
 *
 * True only when ALL hold:
 *  - Polylang is active,
 *  - the requested language is English,
 *  - the resolved post is a B2 post type in another language,
 *  - no real translation exists in the requested language,
 *  - for events: the record passes the public _event_status gate
 *    (expired/rejected/source_not_found never render in EN).
 *
 * @param int|null $post_id Post ID (defaults to the queried object).
 * @return bool
 */
function conexao_should_render_b2_fallback( $post_id = null ): bool {
	if ( ! conexao_polylang_active() || ! function_exists( 'pll_get_post' ) ) {
		return false;
	}

	if ( 'en' !== conexao_requested_language_slug() ) {
		return false;
	}

	$post_id = $post_id ? (int) $post_id : get_queried_object_id();
	if ( $post_id <= 0 ) {
		return false;
	}

	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return false;
	}

	// Static pages: only explicitly allowlisted directory/county pages are
	// B2; every other page is B1 (302 until translated).
	if ( 'page' === $post->post_type ) {
		if ( ! function_exists( 'conexao_is_b2_page' ) || ! conexao_is_b2_page( $post_id ) ) {
			return false;
		}
	} elseif ( ! conexao_is_b2_post_type( $post->post_type ) ) {
		return false;
	}

	if ( 'publish' !== $post->post_status ) {
		return false;
	}

	// A real EN translation wins: render it (no fallback state).
	$translated_id = (int) pll_get_post( $post_id, 'en' );
	if ( $translated_id > 0 && $translated_id !== $post_id ) {
		return false;
	}

	// The resolved record must itself be Portuguese (or language-less legacy);
	// an EN record never "falls back" to itself.
	if ( function_exists( 'pll_get_post_language' ) ) {
		$object_lang = pll_get_post_language( $post_id, 'slug' );
		if ( 'en' === $object_lang ) {
			return false;
		}
	}

	// Event status gate keeps precedence over language fallback: hidden
	// events never render under /en/ (they keep the B1 302 → PT → 404 path).
	// Mirrors the runtime's public gate: published OR legacy no-status rows
	// are visible; every other _event_status hides the record.
	if ( 'event' === $post->post_type ) {
		$event_status = get_post_meta( $post_id, '_event_status', true );
		if ( '' !== $event_status && 'published' !== $event_status ) {
			return false;
		}
	}

	return true;
}

/**
 * STAGE 3.2 — default-language masters replaced by a linked EN translation.
 *
 * The B2 contract shows a Portuguese record in the English space ONLY as a
 * fallback while no real English record exists. Once a published, linked EN
 * translation exists, the EN record appears INSTEAD of the PT sibling —
 * never both (a record must never appear twice in EN archives or search).
 *
 * The events archive already curates this inside Conexao_Event_Query (the
 * language-scoped ID list carries the EN translation in place of the PT
 * master). This helper covers the generic B2 archives (leisure, sponsor,
 * course_provider, job) and EN search.
 *
 * Result set: published records in a non-default language that have a
 * default-language sibling. Cached briefly in the shared `conexao_filters`
 * object-cache group and flushed from the save/delete/insert hub
 * (conexao_homepage_cache_invalidate), so a new translation takes effect
 * immediately and PT/EN caches never cross.
 *
 * @param string[] $post_types Post types to consider (B2 types / 'any' search set).
 * @return int[] Default-language post IDs replaced by an EN translation.
 */
function conexao_b2_translation_replaced_pt_ids( array $post_types ): array {
	if ( ! conexao_polylang_active() || ! function_exists( 'pll_get_post' ) ) {
		return array();
	}

	$post_types = array_values( array_filter( array_map( 'strval', $post_types ) ) );
	if ( empty( $post_types ) ) {
		return array();
	}

	$default = pll_default_language( 'slug' );
	$others  = array_diff( (array) pll_languages_list( array( 'fields' => 'slug' ) ), array( $default ) );
	if ( '' === $default || empty( $others ) ) {
		return array();
	}

	$cache_key = 'conexao_b2_replaced_' . md5( implode( ',', $post_types ) );
	$cached    = wp_cache_get( $cache_key, 'conexao_filters' );
	if ( false !== $cached && is_array( $cached ) ) {
		return array_map( 'intval', $cached );
	}

	$translations = get_posts(
		array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'lang'           => implode( ',', array_values( $others ) ),
		)
	);

	$ids = array();
	foreach ( $translations as $translation_id ) {
		$pt_id = (int) pll_get_post( (int) $translation_id, $default );
		if ( $pt_id > 0 && $pt_id !== (int) $translation_id ) {
			$ids[] = $pt_id;
		}
	}

	$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
	wp_cache_set( $cache_key, $ids, 'conexao_filters', 300 );

	return $ids;
}

/**
 * STAGE 7 — language-aware Leisure card description.
 *
 * The Leisure archive card renders the record's description in
 * `.leisure-card-excerpt` (template-parts/leisure-card.php):
 * `esc_html( wp_trim_words( …, 18, '...' ) )`. This helper selects the
 * description SOURCE for the request language; the presentation pipeline
 * (18-word trim + escaping) stays entirely in the template, identical for
 * both languages:
 *
 *  - PT request (or Polylang inactive, or any non-EN language) → the exact
 *    existing value, `get_the_excerpt()` — byte-identical Stage ≤6 behaviour;
 *  - EN request + an authored English description stored in the
 *    `_leisure_excerpt_en` post meta of the SAME record → that translation
 *    (the Stage 7 translation layer: no second identity, no duplicated
 *    leisure record, `_leisure_uuid` / `_leisure_export_uuid` untouched);
 *  - EN request + no authored EN description → the approved B2 fallback:
 *    the Portuguese excerpt under the English shell (never an invented
 *    translation).
 *
 * Storage rationale: Leisure is a B2 directory CPT whose approved EN model
 * for this stage is a description translation layer on the existing PT
 * records (Stage 7 scope), NOT linked EN leisure posts — so the multilingual
 * store is a language-suffixed meta field on the same record, written by the
 * one-shot conexao-leisure-translation rollout plugin and portable through
 * the existing leisure-migration ZIP (both its exporter and importer carry
 * the key).
 *
 * @param int|null $leisure_id Leisure post ID (defaults to the current loop post).
 * @return string Description source for the current language.
 */
function conexao_leisure_card_excerpt( $leisure_id = 0 ): string {
	$leisure_id = $leisure_id ? (int) $leisure_id : get_the_ID();

	if ( $leisure_id && 'en' === conexao_current_language_slug() ) {
		$en = trim( (string) get_post_meta( $leisure_id, '_leisure_excerpt_en', true ) );
		if ( '' !== $en ) {
			return $en;
		}
	}

	return get_the_excerpt( $leisure_id ? $leisure_id : null );
}

/**
 * Take ownership of Polylang's language-mismatch redirect status.
 *
 * Polylang's frontend canonical sends a **301** when a URL is requested under
 * a language the resolved content does not belong to (an untranslated detail
 * page, the posts page, a static page, or a slug that only exists in the
 * other language). Stage 2 must never emit a permanent redirect for a URL
 * that is scheduled to become a real English page, so this filter converts
 * exactly those redirects into a **302** issued by the theme.
 *
 * Scope is deliberately narrow: only redirects whose DETECTED language differs
 * from the language of the requested URL are intercepted. Same-language
 * canonical redirects (trailing slash, pagination, attachment guessing) keep
 * Polylang's/WordPress' original status, so the Portuguese site behaviour is
 * unchanged.
 *
 * Runs at priority 20 on `pll_check_canonical_url` and exits after issuing the
 * 302 — `conexao_seo_missing_translation_redirect()` (inc/seo.php,
 * template_redirect priority 6) is the complementary rule for requests whose
 * mismatch is visible on the resolved query.
 *
 * @param string|false $redirect_url Polylang's canonical URL (false = none).
 * @param mixed        $language     Detected PLL_Language of the target.
 * @return string|false
 */
function conexao_polylang_language_redirect_is_temporary( $redirect_url, $language = null ) {
	if ( ! is_string( $redirect_url ) || '' === $redirect_url ) {
		return $redirect_url;
	}

	if ( is_admin() || is_feed() || is_robots() || is_preview() || is_trackback() ) {
		return $redirect_url;
	}

	$requested = conexao_requested_language_slug();
	$detected  = ( is_object( $language ) && isset( $language->slug ) ) ? (string) $language->slug : '';

	if ( '' === $detected || '' === $requested || $detected === $requested ) {
		return $redirect_url;
	}

	// STAGE 3.1 — B2 fallback: an EN request for a B2 record with no EN
	// translation renders the PT record under the EN URL (200, no redirect).
	// Returning false tells Polylang "no canonical redirect" so the request
	// reaches template_redirect, where the B2 renderer owns the response.
	if ( 'en' === $requested ) {
		// 1. B2 single records (events, Lazer, sponsors, courses, jobs).
		if ( is_singular() && ! is_tax() && ! is_category() && ! is_tag() ) {
			$candidate = get_queried_object_id();
			if ( $candidate > 0 && conexao_should_render_b2_fallback( (int) $candidate ) ) {
				return false;
			}
		}

		// 2. B2 post-type archives under EN, including their taxonomy filters
		// (`?county=`, `?cidade=`, `?categoria=`). Counties/towns are SHARED
		// geographic identity and never duplicated (architecture §6), so a
		// shared PT term must not bounce the EN archive back to PT — the
		// archive itself is already the B2 set and the term is the same
		// identity in both languages. This is what lets
		// `/en/lazer/?county=dublin` and `/en/eventos/?cidade=dublin` render.
		if ( is_post_type_archive() && conexao_is_b2_post_type( (string) get_query_var( 'post_type' ) ) ) {
			return false;
		}

		// 3. B2 posts page (Blog) — /en/blog/ renders PT content under the EN URL
		//    without redirecting to /blog/. The posts page is a real page object
		//    (page_for_posts) that is allowlisted as B2, so the EN URL is a valid
		//    B2 destination (PT content + EN chrome + B2 notice), never a 301/302
		//    to the PT /blog/ URL.
		if ( is_home() && ! empty( $GLOBALS['wp_query']->is_posts_page ) ) {
			$posts_page_id = (int) get_option( 'page_for_posts' );
			if ( $posts_page_id > 0 && function_exists( 'conexao_is_b2_page' ) && conexao_is_b2_page( $posts_page_id ) ) {
				return false;
			}
		}
	}

	// Language mismatch: the approved B1/B2 Stage 2 behaviour is a temporary
	// redirect to the record's own language URL, never a permanent one.
	wp_safe_redirect( $redirect_url, 302 );
	exit;
}
add_filter( 'pll_check_canonical_url', 'conexao_polylang_language_redirect_is_temporary', 20, 2 );

/**
 * Restore the shell language while a B2 fallback is being rendered.
 *
 * Polylang flips its own `curlang` to the resolved object's language while it
 * decides the canonical redirect. For a B2 fallback that redirect is suppressed
 * (the Portuguese object is rendered under the English URL), but the flipped
 * `curlang` would otherwise leak into the render: the per-language navigation
 * menu, the bare `home_url()` and every `pll_*` shell string would resolve to
 * the object's language instead of the language of the requested URL — exactly
 * the defect that made the Blog navigation item switch back to Portuguese.
 *
 * Runs on `template_redirect` priority 5 — after Polylang's own redirect
 * decision (priority 4) and before the template renders — and restores
 * `curlang` to the REQUESTED language so the whole response stays in the
 * visitor's language. It is a no-op for every non-B2 request and for a B2
 * request whose current language already matches the URL.
 *
 * @return void
 */
function conexao_restore_b2_shell_language(): void {
	if ( ! conexao_polylang_active() || ! function_exists( 'PLL' ) || ! function_exists( 'pll_languages_list' ) ) {
		return;
	}

	if ( ! function_exists( 'conexao_is_language_fallback' ) || ! conexao_is_language_fallback() ) {
		return;
	}

	$requested = conexao_requested_language_slug();

	if ( '' === $requested ) {
		return;
	}

	$pll       = PLL();
	$languages = pll_languages_list( array( 'fields' => '' ) );

	if ( ! $pll || ! is_array( $languages ) ) {
		return;
	}

	foreach ( $languages as $language ) {
		if ( isset( $language->slug ) && $requested === $language->slug ) {
			$pll->curlang = $language;
			break;
		}
	}
}
add_action( 'template_redirect', 'conexao_restore_b2_shell_language', 5 );

/**
 * Does a translated post type have any published content in a language?
 *
 * Used by the hreflang logic: an alternate is only emitted when the target
 * language archive is real content, never for an empty shell.
 * Request-cached (one lightweight query per post type/language pair).
 *
 * @param string $post_type Post type name.
 * @param string $slug      Language slug.
 * @return bool
 */
function conexao_language_has_post_type_content( string $post_type, string $slug ): bool {
	static $cache = array();

	$key = $post_type . '|' . $slug;

	if ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}

	$query = new WP_Query(
		array(
			'post_type'              => $post_type,
			'post_status'            => 'publish',
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'ignore_sticky_posts'    => true,
			'lang'                   => $slug,
		)
	);

	$cache[ $key ] = ! empty( $query->posts );

	return $cache[ $key ];
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
 * Language rows used by template-parts/language-switcher.php.
 *
 * @return array[] Each row: slug, label, name, url, current.
 */
function conexao_language_switcher_data(): array {
	if ( ! conexao_polylang_active() ) {
		return array();
	}

	$languages = pll_languages_list( array( 'fields' => '' ) );
	if ( ! is_array( $languages ) || count( $languages ) < 2 ) {
		return array();
	}

	// STAGE 3.1 — B2 fallback: the shell language is the language the URL asks
	// for, so the switcher marks IT as current (the resolved record's own
	// language gets the canonical permalink via conexao_language_switch_url()).
	$current = conexao_requested_language_slug();
	if ( '' === $current ) {
		$current = conexao_current_language_slug();
	}
	$rows    = array();

	foreach ( $languages as $language ) {
		if ( ! is_object( $language ) || empty( $language->slug ) ) {
			continue;
		}

		$slug = (string) $language->slug;

		$rows[] = array(
			'slug'    => $slug,
			'label'   => strtoupper( $slug ),
			'name'    => isset( $language->name ) ? (string) $language->name : strtoupper( $slug ),
			'url'     => conexao_language_switch_url( $slug ),
			'current' => ( $slug === $current ),
		);
	}

	return $rows;
}

/**
 * Render the language switcher.
 *
 * Text labels ("PT" / "EN") — concise and consistent with the existing design
 * system (no flags: flags are not part of the site's visual language).
 *
 * @param array $args {
 *     @type string $context 'desktop' or 'mobile' — styling class.
 * }
 * @return void
 */
function conexao_language_switcher( $args = array() ) {
	get_template_part( 'template-parts/language-switcher', null, $args );
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
