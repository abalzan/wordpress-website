<?php
/**
 * Language switcher data and rendering
 *
 * The data behind the header language switcher and its renderer.
 * Purely presentational: it reads the current language context and the
 * translation URLs from inc/i18n/urls.php and emits the markup.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
 * Is the language switcher exposed in the UI?
 *
 * The single visibility condition for the switcher, read once at the shared
 * rendering boundary (conexao_language_switcher() below) so the desktop
 * header row and the mobile menu drawer can never disagree and the condition
 * is never duplicated across templates.
 *
 * This is UI EXPOSURE only. A false value removes the switcher markup; it does
 * NOT disable English. EN content, /en/ routes, canonical URLs, hreflang,
 * Polylang registrations and PT/EN translation relationships are untouched,
 * and the switcher's own data layer
 * (conexao_language_switcher_data() / conexao_language_switch_url()) keeps
 * working so re-enabling needs no other change.
 *
 * Re-enable by changing CONEXAO_LANGUAGE_SWITCHER_ENABLED from false to true
 * in functions.php.
 *
 * @return bool
 */
function conexao_is_language_switcher_enabled(): bool {
	return defined( 'CONEXAO_LANGUAGE_SWITCHER_ENABLED' )
		&& CONEXAO_LANGUAGE_SWITCHER_ENABLED;
}

/**
 * Render the language switcher.
 *
 * Text labels ("PT" / "EN") — concise and consistent with the existing design
 * system (no flags: flags are not part of the site's visual language).
 *
 * The visibility condition is applied here, at the shared rendering boundary,
 * so BOTH call sites (header.php desktop context and header.php mobile
 * context) are governed by the single CONEXAO_LANGUAGE_SWITCHER_ENABLED flag
 * and by no other condition. When the flag is false the renderer returns
 * before the template part is loaded, so the switcher container — and any
 * language-switcher ARIA markup — is absent from the document entirely rather
 * than merely hidden with CSS.
 *
 * @param array $args {
 *     @type string $context 'desktop' or 'mobile' — styling class.
 * }
 * @return void
 */
function conexao_language_switcher( $args = array() ) {
	if ( ! conexao_is_language_switcher_enabled() ) {
		return;
	}

	get_template_part( 'template-parts/language-switcher', null, $args );
}
