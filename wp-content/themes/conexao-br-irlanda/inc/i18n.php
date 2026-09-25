<?php
/**
 * Conexão BR Irlanda — i18n foundation (Stage 1).
 *
 * This file is the single, minimal language-infrastructure layer of the
 * theme. Stage 1 of the English-support architecture (see
 * CONEXAO_BR_ENGLISH_ARCHITECTURE_DECISION.md) deliberately keeps the public
 * site Portuguese-only; this file only provides:
 *
 *  1. `conexao_current_locale()` — the one internal answer to "which UI
 *     locale is active?", with a narrow Stage 2 extension point (Polylang
 *     will override the `conexao_current_locale` filter; nothing else in the
 *     theme needs to change).
 *  2. A `locale` filter that corrects the accidental `en_US` site-locale
 *     state documented by the audit (production renders `<html lang="en-US">`
 *     while all content is Portuguese). The default language of the site is
 *     pt_BR, so an unconfigured `en_US` install is normalized to `pt_BR`.
 *     An explicitly configured non-English locale is never overridden.
 *  3. `conexao_og_locale()` — dynamic Open Graph locale (replaces the former
 *     hard-coded `pt_BR` literal in inc/seo.php).
 *  4. `conexao_js_i18n_strings()` — the centralized PHP → JavaScript UI
 *     string payload injected for assets/js/main.js (see
 *     `conexao_enqueue_scripts()` in functions.php).
 *
 * Stage 1 deliberately does NOT contain: Polylang integration, /en/ routing,
 * hreflang output, language detection from URL/cookie/browser, or a language
 * switcher. Those belong to Stage 2.
 *
 * @package conexao-br-irlanda
 */

defined( 'ABSPATH' ) || exit;

/**
 * The currently active UI locale.
 *
 * Stage 1 always resolves to the WordPress site locale (pt_BR once the
 * Stage 1 locale correction is in effect). There is no URL/cookie/browser
 * language detection in this stage.
 *
 * Stage 2 extension point: once Polylang is active it will filter
 * `conexao_current_locale` to return the per-request language (e.g. en_US
 * for /en/ requests). Callers must treat the return value as an opaque
 * WordPress-style locale code ("pt_BR", "en_US", …).
 *
 * @return string WordPress-style locale code.
 */
function conexao_current_locale(): string {
	$locale = get_locale();
	if ( ! $locale ) {
		$locale = 'en_US';
	}

	/**
	 * Filters the currently active UI locale.
	 *
	 * Stage 1: unused — no consumer changes this value yet.
	 * Stage 2: Polylang will filter this to the active per-request language.
	 *
	 * @param string $locale WordPress-style locale code.
	 */
	return (string) apply_filters( 'conexao_current_locale', $locale );
}

/**
 * Correct the accidental en_US site-locale state (Stage 1 locale fix).
 *
 * The production audit found the site running with WordPress' default
 * `en_US` locale while every piece of content is Portuguese, which made
 * `<html lang>` render as "en-US" and dates render in English. The site's
 * approved default language is pt_BR, so an *unconfigured* en_US locale is
 * corrected here. A locale that was explicitly configured to anything other
 * than en_US is never overridden.
 *
 * Note: this makes the theme self-sufficient on locale, but the underlying
 * site setting should still be corrected via Settings → General (or
 * `wp core language activate pt_BR`); production settings are NOT changed in
 * Stage 1 (see CONEXAO_BR_ENGLISH_STAGE_1_REPORT.md).
 *
 * @param string $locale Current locale.
 * @return string Corrected locale.
 */
function conexao_correct_default_locale( $locale ) {
	/*
	 * Stage 2: once Polylang is active, `locale` is a per-request, per-language
	 * value — `en_US` on an /en/ request is legitimate and must be preserved.
	 * The correction below only applies to the single-language state it was
	 * written for (an unconfigured install whose content is Portuguese).
	 */
	if ( function_exists( 'conexao_polylang_active' ) && conexao_polylang_active() ) {
		return $locale;
	}

	if ( ! $locale || 'en_US' === $locale ) {
		return 'pt_BR';
	}
	return $locale;
}
add_filter( 'locale', 'conexao_correct_default_locale', 5 );

/**
 * The Open Graph locale for the current request (og:locale format).
 *
 * Replaces the hard-coded `pt_BR` literal that inc/seo.php used to emit.
 * og:locale uses underscores ("pt_BR", "en_US", "en_IE"), while WordPress
 * locale codes normally use underscores too — a defensive replace handles
 * any hyphenated form.
 *
 * Stage 2: automatically follows the active language because it reads
 * `conexao_current_locale()`.
 *
 * @return string Open Graph locale code, e.g. "pt_BR".
 */
function conexao_og_locale(): string {
	$locale = conexao_current_locale();
	return str_replace( '-', '_', $locale );
}

/**
 * User-facing UI strings used by assets/js/main.js.
 *
 * main.js previously hard-coded Portuguese UI strings (copy-link feedback,
 * filter accessibility labels, sponsor carousel status, infinite-scroll and
 * load-more status text). They are externalized here — the single gettext
 * source for them — and injected into the page next to main.js via
 * wp_add_inline_script() as `window.ConexaoI18n` (see
 * conexao_enqueue_scripts()). main.js falls back to the current Portuguese
 * literals only when the payload is missing, so the page can never regress
 * if the inline script is stripped by an optimization layer.
 *
 * Pluralization is resolved with the gettext plural machinery (see the
 * filterCount* / moreLoaded* keys); main.js only picks the correct
 * pre-translated form.
 *
 * @return array Key → translated string map.
 */
function conexao_js_i18n_strings(): array {
	return array(
		/* translators: %s is the parent menu item title. */
		'submenuToggleTemplate'   => __( 'Abrir submenu de %s', 'conexao-br-irlanda' ),
		/* Copy-link button feedback. */
		'linkCopied'              => __( 'Link copiado', 'conexao-br-irlanda' ),
		/* Directory filter trigger (Lazer / Empregos / Eventos). */
		'filterLabel'             => __( 'Filtrar', 'conexao-br-irlanda' ),
		/* translators: %d is the number of active filters (exactly one). */
		'filterCountOne'          => __( '%d filtro ativo', 'conexao-br-irlanda' ),
		/* translators: %d is the number of active filters (two or more). */
		'filterCountMany'         => __( '%d filtros ativos', 'conexao-br-irlanda' ),
		/* translators: 1: current sponsor position, 2: total sponsors. */
		'sponsorPositionTemplate' => __( 'Apoiador %1$d de %2$d', 'conexao-br-irlanda' ),
		/* Infinite scroll / load-more status strings. */
		'loading'                 => __( 'Carregando...', 'conexao-br-irlanda' ),
		'loadMore'                => __( 'Carregar mais', 'conexao-br-irlanda' ),
		'loadMoreFailed'          => __( 'Não foi possível carregar mais conteúdo.', 'conexao-br-irlanda' ),
		'loadMoreFailedRetry'     => __( 'Não foi possível carregar mais conteúdo. Tente novamente.', 'conexao-br-irlanda' ),
		'retry'                   => __( 'Tentar novamente', 'conexao-br-irlanda' ),
		'reachedEnd'              => __( 'Você chegou ao fim.', 'conexao-br-irlanda' ),
		/* translators: 1: number of newly loaded cards, 2: singular noun ("evento"/"curso"). */
		'moreLoadedOneTemplate'   => __( 'Mais %1$d %2$s carregado.', 'conexao-br-irlanda' ),
		/* translators: 1: number of newly loaded cards, 2: plural noun ("eventos"/"cursos"). */
		'moreLoadedManyTemplate'  => __( 'Mais %1$d %2$s carregados.', 'conexao-br-irlanda' ),
		'nounEvents'              => __( 'evento', 'conexao-br-irlanda' ),
		'nounEventsPlural'        => __( 'eventos', 'conexao-br-irlanda' ),
		'nounCourses'             => __( 'curso', 'conexao-br-irlanda' ),
		'nounCoursesPlural'       => __( 'cursos', 'conexao-br-irlanda' ),
	);
}

/**
 * 5. Language-aware `date_format`.
 *
 * WordPress stores ONE site-wide `date_format` option, authored for the
 * default language. The site's approved Portuguese format is
 * `j \d\e F \d\e Y` ("25 de agosto de 2026"), and because the literal "de"
 * connectors live INSIDE that format string, switching only the locale (the
 * Stage 2 `locale` filter) translated the month name but kept the Portuguese
 * connectors — the observed `25 de August de 2026` on `/en/jobs/`.
 *
 * The connectors are user-facing text, so they belong in the catalog: this
 * filter returns the site option unchanged while the default language is
 * active (Portuguese output is byte-identical), and the locale-appropriate
 * format on every other language. It is the WordPress-native
 * `option_date_format` hook, so every consumer — `get_the_date()`,
 * `date_i18n()`, `wp_date()` — is corrected at once; no template changes and
 * no hardcoded English string in the codebase.
 *
 * The English entry is a real translation of the same pattern, and a locale
 * with no catalog entry simply falls back to the site option (its own
 * WordPress default), so an unknown language is never rendered in the
 * wrong order.
 *
 * @param string $format Stored/site date format.
 * @return string
 */
function conexao_localized_date_format( $format ) {
	// inc/polylang.php (the language-context owner) loads after this file, so
	// its helpers are checked at call time; without Polylang there is no
	// language layer and the site option is used exactly as before.
	if ( ! function_exists( 'conexao_current_language_slug' ) || ! function_exists( 'conexao_default_language_slug' ) ) {
		return $format;
	}

	$current = conexao_current_language_slug();
	$default = conexao_default_language_slug();

	// Portuguese (and a site with no language layer at all) is untouched.
	if ( '' === $current || '' === $default || $current === $default ) {
		return $format;
	}

	/**
	 * Filters the date format used for a non-default language.
	 *
	 * @param string $format   Site date format.
	 * @param string $language Current language slug.
	 */
	$localized = apply_filters( 'conexao_localized_date_format', $format, $current );

	return is_string( $localized ) && '' !== $localized ? $localized : $format;
}
add_filter( 'option_date_format', 'conexao_localized_date_format' );

/**
 * Apply the language-aware format to a rendered post date.
 *
 * `get_the_date()` resolves `get_option( 'date_format' )` into
 * `get_post_time()` → `date_i18n()`, which formats the date BEFORE the
 * `option_date_format` filter is consulted again inside the same request in
 * some contexts (notably WP-CLI, where the option is read from a primed
 * alloptions cache). This second, WordPress-native `get_the_date` filter is
 * the render-time guarantee: every consumer of the post date — the Job card,
 * archives, singular pages — gets the language-correct presentation, while
 * Portuguese (the default language) is returned byte-identical because
 * `conexao_localized_date_format()` short-circuits there.
 *
 * Only the FORMAT is touched: the timestamp, the timezone, the post and the
 * published date itself are untouched, and no English string is hardcoded.
 *
 * @param string     $the_date Formatted date.
 * @param string     $format   Requested format ('' means the site option).
 * @param WP_Post|null $post   Post object.
 * @return string
 */
function conexao_localize_post_date( $the_date, $format, $post = null ) {
	// An explicit format from the caller always wins (it is already a choice).
	if ( ! empty( $format ) ) {
		return $the_date;
	}

	$current = function_exists( 'conexao_current_language_slug' ) ? conexao_current_language_slug() : '';
	$default = function_exists( 'conexao_default_language_slug' ) ? conexao_default_language_slug() : '';

	if ( '' === $current || '' === $default || $current === $default ) {
		return $the_date;
	}

	$post_format = (string) get_option( 'date_format' );
	$localized   = conexao_localized_date_format( $post_format );

	if ( $localized === $post_format || '' === $localized ) {
		return $the_date;
	}

	$timestamp = $post instanceof WP_Post
		? (int) get_post_timestamp( $post )
		: (int) get_post_time( 'U', true, $post );

	if ( $timestamp <= 0 ) {
		return $the_date;
	}

	return wp_date( $localized, $timestamp );
}
add_filter( 'get_the_date', 'conexao_localize_post_date', 10, 3 );

/**
 * The English long date pattern, in the site's catalog.
 *
 * Returned through gettext so the format is translated like any other user
 * -facing string rather than hardcoded per language branch. Exposed as a
 * helper so `conexao_localized_date_format()` and the tests share one source.
 *
 * @return string
 */
function conexao_english_date_format(): string {
	return __( 'F j, Y', 'conexao-br-irlanda' );
}

/**
 * Apply the English date pattern to a non-default language.
 *
 * @param string $format   Site date format.
 * @param string $language Current language slug.
 * @return string
 */
function conexao_localized_date_format_english( $format, $language ) {
	return 'en' === $language ? conexao_english_date_format() : $format;
}
add_filter( 'conexao_localized_date_format', 'conexao_localized_date_format_english', 10, 2 );
