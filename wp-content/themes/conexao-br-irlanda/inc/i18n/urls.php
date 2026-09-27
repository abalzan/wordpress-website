<?php
/**
 * Language-aware URLs, archives and translation URLs
 *
 * Builds every language-aware URL: the current request URL, switching the
 * language segment inside a path, the language-scoped posts page URL, the
 * language archive URL and the switch URL, plus the request/pre_get_posts
 * wiring that resolves the posts page in the active language. Also owns the
 * language-scoped cache key (conexao_lang_cache_key) and its flush hooks,
 * because the cache scope IS the language context.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
