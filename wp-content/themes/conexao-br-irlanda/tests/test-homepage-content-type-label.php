<?php
/**
 * EN homepage content-type label regression test.
 *
 * The defect this suite locks down: the homepage card chips read the post
 * type's registered `singular_name` DIRECTLY, and this project registers its
 * CPT labels as raw Portuguese literals (conexao-data-model.php).
 * `register_post_type()` labels are never translated by WordPress, so the
 * Portuguese label "Guia Prático" was rendered verbatim on the English
 * homepage `/en/` even though the correct English translation
 * ("Practical Guide") already existed in the theme catalogue.
 *
 * The fix is presentation-layer only: `conexao_content_type_label()` runs the
 * registered label through gettext. This suite proves:
 *
 *   1. the helper exists;
 *   2. the EN catalogue really carries the translation (the fix has a target);
 *   3. under `en_US` the helper returns the ENGLISH label for `guide`;
 *   4. under `pt_BR` the helper returns the IDENTICAL Portuguese label
 *      (PT immutability — pt_BR is an identity catalogue);
 *   5. a post type with no catalogue entry falls back to its registered label
 *      rather than losing the label or returning an empty string;
 *   6. an unknown/unregistered post type returns '' without a PHP error, so
 *      the caller's existing `if ( $content_type )` guard keeps its meaning;
 *   7. front-page.php no longer reads `->labels->singular_name` directly, so
 *      the leak cannot be reintroduced at the two chip call sites;
 *   8. the pre-existing `conexao_cpt_label()` (plural, SEO-scoped) is NOT
 *      reused or altered by this fix.
 *
 * Read-only: it creates, updates and deletes nothing, and never contacts
 * production.
 *
 * Usage (inside the WordPress container):
 *   php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-homepage-content-type-label.php
 *
 * @package Conexao_BR_Irlanda
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$theme_functions = get_template_directory() . '/functions.php';
if ( file_exists( $theme_functions ) ) {
	require_once $theme_functions;
}

$passed = 0;
$failed = 0;

/**
 * Assert strict equality, reporting expected vs actual on failure.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  Assertion message.
 * @return void
 */
function hcl_strict( $expected, $actual, $message ) {
	assert_true(
		$expected === $actual,
		$message . ( $expected === $actual ? '' : ' — expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) )
	);
}

/**
 * Activate a catalogue for the theme text domain, the way a real request does.
 *
 * WordPress 6.7+ resolves a text domain through the just-in-time loader
 * (`_load_textdomain_just_in_time`), which is gated on `after_setup_theme`
 * and memoises the loaded domain for the process. That memoisation means a
 * single CLI process cannot flip locales with `switch_to_locale()` alone: the
 * first catalogue loaded wins for the rest of the run, so a PT->EN->PT
 * sequence would silently keep returning the first result.
 *
 * This helper therefore drives the SAME public API the theme uses
 * (`load_textdomain()` against the real `languages/<locale>.mo` committed in
 * the repository) and unloads first so each switch is honoured. It asserts the
 * `.mo` file actually exists, so a missing or uncompiled catalogue fails loudly
 * instead of silently falling back to the untranslated source string.
 *
 * @param string $locale Locale to activate, e.g. 'en_US'.
 * @return void
 */
function hcl_switch_locale( $locale ) {
	$domain    = 'conexao-br-irlanda';
	$languages = get_template_directory() . '/languages';
	$mo_file   = $languages . '/' . $locale . '.mo';

	assert_true(
		file_exists( $mo_file ),
		"the {$locale} catalogue is compiled and present",
		$mo_file
	);

	unload_textdomain( $domain );
	load_textdomain( $domain, $mo_file, $locale );
}

$domain = 'conexao-br-irlanda';

// --- 1. The helper exists and is presentation-layer. ------------------------

assert_true(
	function_exists( 'conexao_content_type_label' ),
	'conexao_content_type_label() exists (the presentation-layer i18n helper)'
);

if ( ! function_exists( 'conexao_content_type_label' ) ) {
	echo "\n  helper missing — the remaining assertions cannot run\n";
	test_finish();
	return;
}

// The defect only exists if the CPT is registered with a raw PT literal, so
// prove the premise rather than assuming it.
$guide_object = get_post_type_object( 'guide' );
assert_true( (bool) $guide_object, 'the guide CPT is registered' );
$raw_guide_label = $guide_object ? $guide_object->labels->singular_name : '';
hcl_strict( 'Guia Prático', $raw_guide_label, 'the guide CPT registers the raw Portuguese singular label' );

// --- 2. The EN catalogue already carries the translation. -------------------

hcl_switch_locale( 'en_US' );
assert_true(
	is_textdomain_loaded( $domain ),
	'the EN catalogue is active for the theme text domain'
);
hcl_strict(
	'Practical Guide',
	__( 'Guia Prático', $domain ),
	'the EN catalogue already translates "Guia Prático" (no new msgid is needed)'
);

// --- 3. The leak is fixed: EN returns the English label. -------------------

hcl_strict(
	'Practical Guide',
	conexao_content_type_label( 'guide' ),
	'EN: the guide label is returned in English, not the Portuguese literal'
);
assert_true(
	'Guia Prático' !== conexao_content_type_label( 'guide' ),
	'EN: the Portuguese literal does not leak through the helper'
);
assert_true(
	'' !== conexao_content_type_label( 'guide' ),
	'EN: the helper never returns an empty label for a registered post type'
);

// --- 4. PT immutability: the identical Portuguese label comes back. --------

hcl_switch_locale( 'pt_BR' );
assert_true(
	is_textdomain_loaded( $domain ),
	'the PT catalogue is active for the theme text domain'
);
hcl_strict(
	'Guia Prático',
	conexao_content_type_label( 'guide' ),
	'PT: the guide label is byte-identical to the registered Portuguese label'
);
hcl_strict(
	$raw_guide_label,
	conexao_content_type_label( 'guide' ),
	'PT: the helper returns exactly the registered label (pt_BR is an identity catalogue)'
);

// The helper is locale-driven, not hardcoded: the two locales must disagree,
// otherwise the EN assertion above would be vacuous.
hcl_switch_locale( 'en_US' );
$en_label = conexao_content_type_label( 'guide' );
hcl_switch_locale( 'pt_BR' );
$pt_label = conexao_content_type_label( 'guide' );
assert_true(
	$en_label !== $pt_label,
	'the helper is genuinely locale-sensitive (EN and PT labels differ)',
	"en={$en_label} pt={$pt_label}"
);

// --- 5. Untranslated post types fall back to the registered label. ---------

// `course_provider` is registered as "Provedor de Cursos", which has no entry
// in the EN catalogue. The helper must return that registered label (not an
// empty string), so no card can ever lose its label.
$provider_object = get_post_type_object( 'course_provider' );
assert_true( (bool) $provider_object, 'the course_provider CPT is registered' );
if ( $provider_object ) {
	$provider_raw = $provider_object->labels->singular_name;
	hcl_strict(
		$provider_raw,
		conexao_content_type_label( 'course_provider' ),
		'an untranslated post type falls back to its registered label instead of an empty string'
	);
	assert_true(
		'' !== conexao_content_type_label( 'course_provider' ),
		'an untranslated post type still renders a non-empty label'
	);
}

// --- 6. An unknown post type is handled without error or label loss. -------

$unknown = conexao_content_type_label( 'conexao_definitely_not_registered' );
hcl_strict( '', $unknown, 'an unregistered post type returns an empty string (no PHP error)' );

// --- 7. The leak cannot be reintroduced at the chip call sites. -------------

$front_page = get_template_directory() . '/front-page.php';
$front_page_source = file_exists( $front_page ) ? file_get_contents( $front_page ) : '';

assert_true(
	'' !== $front_page_source,
	'front-page.php is readable for the static leak check'
);
assert_true(
	false === strpos( $front_page_source, '$content_type->labels->singular_name' ),
	'front-page.php no longer echoes the raw singular_name at the chip call sites'
);
assert_true(
	2 === substr_count( $front_page_source, 'conexao_content_type_label( get_post_type() )' ),
	'both homepage chips render the label through the helper',
	'occurrences: ' . substr_count( $front_page_source, 'conexao_content_type_label( get_post_type() )' )
);
assert_true(
	false !== strpos( $front_page_source, 'class="featured-article-category"' )
		&& false !== strpos( $front_page_source, 'class="post-card-category"' ),
	'the chip markup, classes and structure are preserved'
);

// --- 8. The pre-existing plural/SEO helper is untouched. -------------------

assert_true(
	function_exists( 'conexao_cpt_label' ),
	'the pre-existing conexao_cpt_label() helper still exists'
);
if ( function_exists( 'conexao_cpt_label' ) ) {
	hcl_switch_locale( 'pt_BR' );
	hcl_strict(
		'Guias Práticos',
		conexao_cpt_label( 'guide' ),
		'conexao_cpt_label() still returns the PLURAL label (SEO-scoped, unchanged by this fix)'
	);
	$schema_source = file_exists( get_template_directory() . '/inc/seo/schema.php' )
		? file_get_contents( get_template_directory() . '/inc/seo/schema.php' )
		: '';
	assert_true(
		false !== strpos( $schema_source, 'function conexao_cpt_label' ),
		'inc/seo/schema.php still owns conexao_cpt_label() (it was not moved or rewritten)'
	);
}

// Leave the theme text domain on the Portuguese catalogue, which is the
// site's source language.
hcl_switch_locale( 'pt_BR' );

test_finish();