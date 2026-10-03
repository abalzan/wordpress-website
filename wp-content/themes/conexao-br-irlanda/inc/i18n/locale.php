<?php
/**
 * Polylang locale and language-context resolution
 *
 * Wires the repository locale foundation (inc/i18n.php,
 * conexao_current_locale()) to Polylang's active language, resolves the
 * current/default language slugs and locale, captures the requested language
 * slug for the request, and applies the B2 shell locale. The foundation
 * itself is NOT duplicated: only the Polylang-specific filter is registered
 * here, so every existing consumer of conexao_current_locale() keeps working
 * unchanged.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
