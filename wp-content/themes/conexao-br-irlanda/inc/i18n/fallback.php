<?php
/**
 * B2 (PT fallback) policy and fallback rendering decisions
 *
 * The approved B2 policy: which post types and pages are B2, whether the
 * fallback should render for the current request, which PT masters are
 * replaced by their EN translation, the fallback notice, the B2 shell
 * language restore, the temporary-redirect policy, the language-home
 * front-page rule and the leisure card description source (authored EN
 * description, else the B2 PT fallback). The declared language home URLs
 * themselves live in inc/i18n/guard.php, where they are registered at load.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
 * STAGE 8 — the course-provider card description source (language-aware).
 *
 * The exact counterpart of `conexao_leisure_card_excerpt()` for the Cursos
 * directory. `/en/cursos` is a B2 destination (`course_provider` is in
 * `conexao_b2_post_types()`), so the same approved B2 fallback applies — but the
 * provider card previously had NO language-aware read path at all: it called
 * `get_the_excerpt()` directly, so every English card showed Portuguese text and
 * there was nowhere for a translation to live.
 *
 * This helper selects the description SOURCE for the request language; the
 * presentation pipeline (20-word trim + escaping) stays entirely in the
 * template, identical for both languages:
 *
 *  - PT request (or Polylang inactive, or any non-EN language) → the exact
 *    existing value, `get_the_excerpt()` — byte-identical previous behaviour;
 *  - EN request + an authored English description stored in the
 *    `_provider_excerpt_en` post meta of the SAME record → that translation
 *    (one identity, no second provider record, `_provider_url` /
 *    `_provider_category` / `_provider_location` / `_provider_logo` untouched);
 *  - EN request + no authored EN description → the approved B2 fallback:
 *    the Portuguese excerpt under the English shell (never an invented
 *    translation).
 *
 * Storage rationale: `course_provider` is a B2 directory CPT, so the EN layer is
 * a description translation on the existing PT records, exactly as Leisure is —
 * NOT linked EN provider posts. The authored English is written by the
 * `en-course-provider-description` stage on the shared
 * `conexao-translation-rollout` engine and is portable as a versioned dataset
 * in `conexao-en-translation`.
 *
 * @param int|null $provider_id Course provider post ID (defaults to the current loop post).
 * @return string Description source for the current language.
 */
function conexao_provider_card_excerpt( $provider_id = 0 ): string {
	$provider_id = $provider_id ? (int) $provider_id : get_the_ID();

	if ( $provider_id && 'en' === conexao_current_language_slug() ) {
		$en = trim( (string) get_post_meta( $provider_id, '_provider_excerpt_en', true ) );
		if ( '' !== $en ) {
			return $en;
		}
	}

	return get_the_excerpt( $provider_id ? $provider_id : null );
}

/**
 * The English presentation value for a stored `_provider_category` label.
 *
 * `/en/cursos/` is a B2 destination: there are no EN `course_provider` records,
 * so the English archive renders the Portuguese records under the English
 * shell. `_provider_category` is FREE-TEXT post meta, not a taxonomy, so unlike
 * `conexao_category` there is no linked EN term to resolve — the Portuguese
 * label has no English counterpart anywhere in the data. This function is the
 * presentation layer that supplies one.
 *
 * Deliberately a PRESENTATION mapping and nothing else:
 *  - it NEVER writes English into `_provider_category`, so the PT canonical
 *    data, the `?categoria=` slug and the meta_query that filters on it are all
 *    untouched (the meta is the identity; this is the chrome);
 *  - it NEVER creates a taxonomy term or an EN provider post;
 *  - a PT (or non-EN) request returns the stored value VERBATIM, so Portuguese
 *    output is byte-identical to before;
 *  - an unknown or unrecognised value returns exactly what is stored — never
 *    invented, never dropped, never silently mapped onto a wrong concept. This
 *    is the same contract as conexao_permit_employer_label_display(), the
 *    theme's existing precedent for language-aware display of a free-text meta
 *    value, and it reuses the SAME normalisation rule.
 *
 * The English strings are ordinary gettext entries in the theme catalogue
 * (en_US), so this adds no second localization mechanism: the catalogue is
 * translated in one place and PT needs no entry because PT returns the stored
 * value directly.
 *
 * @param mixed $category Stored `_provider_category` value; post meta is untyped,
 *                         so a non-string (null) must be accepted without a notice.
 * @return string Display label for the current request language.
 */
function conexao_provider_category_label( $category ): string {
	$category = is_string( $category ) ? trim( $category ) : '';

	if ( '' === $category ) {
		return '';
	}

	// PT (and any non-EN language, and a site without Polylang) is already
	// correct: return the stored value untouched.
	if ( 'en' !== conexao_current_language_slug() ) {
		return $category;
	}

	// Keys are the output of conexao_provider_category_key() — accent-stripped,
	// lower-cased and with dashes normalised to SPACES (the shared free-text
	// meta normalisation), so "Cursos Online", "cursos online" and
	// "Cursos-Online" all resolve to the same entry.
	$labels = array(
		'cursos online'         => __( 'Online Courses', 'conexao-br-irlanda' ),
		'diretorios de cursos'  => __( 'Course Directories', 'conexao-br-irlanda' ),
		'educacao'              => __( 'Education', 'conexao-br-irlanda' ),
		'formacao profissional' => __( 'Vocational Training', 'conexao-br-irlanda' ),
		'negocios'              => __( 'Business', 'conexao-br-irlanda' ),
	);

	$key = conexao_provider_category_key( $category );

	// Unknown value: return exactly what is stored (never invented, never lost).
	return isset( $labels[ $key ] ) ? $labels[ $key ] : $category;
}

/**
 * Normalise a stored provider category to its accent/case-insensitive lookup key.
 *
 * The shared normalisation used by the free-text meta display helpers
 * (conexao_permit_employer_label_key()): accents removed, case folded, dashes
 * normalised to spaces and whitespace collapsed. `_provider_category` is
 * hand-entered free text, so a maintainer typing "Educacao" or "educação"
 * must resolve to the same presentation value.
 *
 * @param mixed $category Stored `_provider_category` value; post meta is untyped,
 *                         so a non-string (null) must be accepted without a notice.
 * @return string Normalised lookup key.
 */
function conexao_provider_category_key( $category ): string {
	$category = is_string( $category ) ? trim( $category ) : '';

	if ( function_exists( 'remove_accents' ) ) {
		$category = remove_accents( $category );
	}

	$category = strtolower( $category );
	$category = str_replace( array( '—', '–', '-' ), ' ', $category );
	$category = preg_replace( '/\s+/', ' ', $category );

	return trim( (string) $category );
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
		// without redirecting to /blog/. The posts page is a real page object
		// (page_for_posts) that is allowlisted as B2, so the EN URL is a valid
		// B2 destination (PT content + EN chrome + B2 notice), never a 301/302
		// to the PT /blog/ URL.
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
