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
$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

$theme_functions = get_template_directory() . '/functions.php';
if ( file_exists( $theme_functions ) ) {
	require_once $theme_functions;
}

$passed = 0;
$failed = 0;

function t_assert( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		echo "  PASS: {$message}\n";
	} else {
		$failed++;
		echo "  FAIL: {$message}\n";
	}
}

function t_strict( $expected, $actual, $message ) {
	t_assert( $expected === $actual, $message . ( $expected === $actual ? '' : ' — expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ) );
}

$domain = 'conexao-br-irlanda';

echo "== Stage 1 i18n foundation ==\n";

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
t_assert( count( array_diff( $expected_keys, array_keys( $js ) ) ) === 0, 'JS i18n payload contains all expected keys' );
t_strict( '%d filtro ativo', $js['filterCountOne'], 'JS payload singular filter label' );
t_assert( $js['filterCountOne'] !== $js['filterCountMany'], 'JS payload filter label has distinct singular/plural forms' );
t_assert( $js['moreLoadedOneTemplate'] !== $js['moreLoadedManyTemplate'], 'JS payload load-more status has distinct singular/plural forms' );
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
	t_assert( $registered, "plugin textdomain loader registered: {$plugin}" );
}

// --- Dates (Phase 6). ---
t_assert(
	false !== strpos( date_i18n( 'F', strtotime( '2026-09-06' ) ), 'etembro' ),
	'dates render Portuguese month names ("setembro")'
);

// --- Stage 1 must NOT add multilingual SEO behavior (Phase 12). ---
t_assert( ! has_filter( 'wp_head', 'conexao_seo_hreflang' ), 'no hreflang emitter registered on wp_head' );
t_assert( ! function_exists( 'conexao_language_switcher' ), 'no language switcher helper exists' );
t_assert( false === strpos( conexao_og_locale(), 'en' ), 'og:locale is not English under the Stage 1 public context' );

echo "\ni18n foundation: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
