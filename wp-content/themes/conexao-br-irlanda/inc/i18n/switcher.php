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
