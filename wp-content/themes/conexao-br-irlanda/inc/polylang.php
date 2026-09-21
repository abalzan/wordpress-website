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
 * Slug of the language serving the current request ('pt', 'en', …).
 *
 * @return string Empty string when Polylang is inactive.
 */
function conexao_current_language_slug(): string {
	if ( ! conexao_polylang_active() ) {
		return '';
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
 * `conexao_county` / `conexao_town` hold proper nouns and `conexao_category`
 * / `conexao_tag` are shared filters — in all four cases ONE term identity is
 * shared across languages (per-language names only). Slugs stay Portuguese
 * and are never fragmented into per-language duplicates.
 *
 * @param string[] $taxonomies  Current list (keys = values).
 * @param bool     $is_settings True when Polylang renders its settings screen.
 * @return string[]
 */
function conexao_polylang_translated_taxonomies( $taxonomies, $is_settings = false ) {
	if ( $is_settings ) {
		return $taxonomies;
	}

	foreach ( array( 'conexao_category', 'conexao_county', 'conexao_town', 'conexao_tag' ) as $taxonomy ) {
		$taxonomies[ $taxonomy ] = $taxonomy;
	}

	return $taxonomies;
}
add_filter( 'pll_get_taxonomies', 'conexao_polylang_translated_taxonomies', 10, 2 );

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
	$active = conexao_language_locale();

	return '' !== $active ? $active : $locale;
}
add_filter( 'conexao_current_locale', 'conexao_polylang_current_locale', 10 );

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
		// Blog: the EN home is the safe target until the Blog page/archive has
		// a real EN URL in Stage 3.
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

	if ( $target_slug === conexao_current_language_slug() ) {
		return conexao_current_request_url();
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
 * True when the current request renders a record of another language.
 *
 * This is the approved B2 state ("Portuguese content under an EN URL"): the
 * requested language has no translation of the resolved object. Canonical
 * must then point at the record's own language URL (see inc/seo.php).
 *
 * @return bool
 */
function conexao_is_language_fallback(): bool {
	if ( ! conexao_polylang_active() || ! is_singular() || ! function_exists( 'pll_get_post_language' ) ) {
		return false;
	}

	$object_language = pll_get_post_language( get_queried_object_id(), 'slug' );
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

	// Language mismatch: the approved B1/B2 Stage 2 behaviour is a temporary
	// redirect to the record's own language URL, never a permanent one.
	wp_safe_redirect( $redirect_url, 302 );
	exit;
}
add_filter( 'pll_check_canonical_url', 'conexao_polylang_language_redirect_is_temporary', 20, 2 );

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

	$current = conexao_current_language_slug();
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

	if ( is_singular() && conexao_is_language_fallback() ) {
		$permalink = get_permalink( get_queried_object_id() );
		if ( $permalink ) {
			return $permalink;
		}
	}

	return $canonical;
}
add_filter( 'conexao_seo_canonical_url', 'conexao_polylang_canonical_url', 10 );
