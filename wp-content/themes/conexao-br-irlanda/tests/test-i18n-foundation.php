<?php
/**
 * Tests for the Stage 1 i18n foundation.
 *
 * Verifies the i18n groundwork added by Stage 1 of the English-support
 * architecture (CONEXAO_BR_ENGLISH_ARCHITECTURE_DECISION.md):
 *
 *  - The theme textdomain is loaded and the pt_BR (identity) catalog
 *      resolves the known Stage 1 strings.
 *  - The curated en_US catalog resolves when the locale is switched
 *      directly (it is NOT publicly active — no /en/ routing in Stage 1).
 *  - The locale helpers resolve to pt_BR / pt-BR and the dynamic OG locale.
 *  - The JavaScript UI-string payload is complete with singular/plural
 *      forms.
 *  - The 7 plugin textdomain loaders are registered on 'init'.
 *  - Dates render with Portuguese month names.
 *  - No hreflang mechanism is registered by Stage 1.
 *
 * Usage (from the project root):
 *   docker compose exec wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-i18n-foundation.php
 */

// --- Bootstrap WordPress (plugins + theme option). ---

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$theme_functions = get_template_directory() . '/functions.php';
if ( file_exists( $theme_functions ) ) {
	require_once $theme_functions;
}

$passed = 0;
$failed = 0;

function t_strict( $expected, $actual, $message ) {
	assert_true( $expected === $actual, $message . ( $expected === $actual ? '' : ' — expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ) );
}

$domain = 'conexao-br-irlanda';


// --- Locale model (Phase 5/7). ---
t_strict( 'pt_BR', get_locale(), 'site locale is pt_BR' );
t_strict( 'pt_BR', conexao_current_locale(), 'conexao_current_locale() resolves to pt_BR' );
t_strict( 'pt_BR', conexao_og_locale(), 'dynamic og:locale resolves to pt_BR' );
t_strict( 'pt-BR', str_replace( '_', '-', get_locale() ), 'html lang renders pt-BR' );

// --- Theme textdomain + PT catalog (identity). ---
t_strict( 'Início', __( 'Início', $domain ), 'PT catalog resolves nav label "Início"' );
t_strict( 'Link copiado', __( 'Link copiado', $domain ), 'PT catalog resolves "Link copiado"' );
t_strict( 'Toda %s', __( 'Toda %s', $domain ), 'PT catalog resolves recurrence pattern' );
t_strict( 'Página não encontrada', __( 'Página não encontrada', $domain ), 'PT catalog resolves 404 title' );

// --- en_US catalog resolves directly (not publicly active in Stage 1). ---
switch_to_locale( 'en_US' );
t_strict( 'Home', __( 'Início', $domain ), 'EN catalog resolves "Início" → Home' );
t_strict( 'Link copied', __( 'Link copiado', $domain ), 'EN catalog resolves "Link copiado" → Link copied' );
t_strict( 'Every %s', __( 'Toda %s', $domain ), 'EN catalog resolves "Toda %s" → Every %s' );
t_strict( 'Filter', __( 'Filtrar', $domain ), 'EN catalog resolves "Filtrar" → Filter' );
restore_current_locale();

// --- JavaScript UI-string payload (Phase 3.4). ---
$js = conexao_js_i18n_strings();
$expected_keys = array(
	'submenuToggleTemplate', 'linkCopied', 'filterLabel', 'filterCountOne',
	'filterCountMany', 'sponsorPositionTemplate', 'loading', 'loadMore',
	'loadMoreFailed', 'loadMoreFailedRetry', 'retry', 'reachedEnd',
	'moreLoadedOneTemplate', 'moreLoadedManyTemplate', 'nounEvents',
	'nounEventsPlural', 'nounCourses', 'nounCoursesPlural',
);
assert_true( count( array_diff( $expected_keys, array_keys( $js ) ) ) === 0, 'JS i18n payload contains all expected keys' );
t_strict( '%d filtro ativo', $js['filterCountOne'], 'JS payload singular filter label' );
assert_true( $js['filterCountOne'] !== $js['filterCountMany'], 'JS payload filter label has distinct singular/plural forms' );
assert_true( $js['moreLoadedOneTemplate'] !== $js['moreLoadedManyTemplate'], 'JS payload load-more status has distinct singular/plural forms' );
t_strict( 'Mais %1$d %2$s carregado.', $js['moreLoadedOneTemplate'], 'JS payload singular load-more wording is exact PT' );

// --- Plugin textdomain loaders (Phase 4). ---
$plugins = array(
	'conexao-data-model',
	'conexao-content',
	'conexao-admin-ux',
	'conexao-event-runtime',
	'conexao-event-importer',
	'conexao-leisure-migration',
	'conexao-sponsor-migration',
);
foreach ( $plugins as $plugin ) {
	$fn = str_replace( '-', '_', $plugin ) . '_load_textdomain';
	$registered = function_exists( $fn ) && false !== has_action( 'init', $fn );
	// Inactive plugins (not loaded in this environment) are verified by
	// confirming the loader is present in the plugin's main file.
	if ( ! $registered ) {
		$main_file = WP_PLUGIN_DIR . '/' . $plugin . '/' . $plugin . '.php';
		$registered = file_exists( $main_file )
			&& false !== strpos( (string) file_get_contents( $main_file ), 'load_plugin_textdomain(' )
			&& false !== strpos( (string) file_get_contents( $main_file ), "'" . $plugin . "'" );
	}
	assert_true( $registered, "plugin textdomain loader registered: {$plugin}" );
}

// --- Dates (Phase 6). ---
assert_true(
	false !== strpos( date_i18n( 'F', strtotime( '2026-09-06' ) ), 'etembro' ),
	'dates render Portuguese month names ("setembro")'
);

// --- Stage 2 supersedes the Stage 1 "no multilingual output" scope -------
// Stage 1 deliberately shipped without an hreflang emitter or a language
// switcher; Stage 2 adds both (inc/polylang.php). These checks now assert the
// invariants the Stage 1 foundation still guarantees in the PT context.
assert_true( function_exists( 'conexao_seo_hreflang' ), 'hreflang emitter registered (Stage 2)' );
assert_true( function_exists( 'conexao_language_switcher' ), 'language switcher helper exists (Stage 2)' );
t_strict( 'pt_BR', conexao_og_locale(), 'og:locale is pt_BR in the Portuguese context' );
assert_true( is_array( conexao_hreflang_links() ), 'hreflang link resolver returns an array' );

test_finish();
