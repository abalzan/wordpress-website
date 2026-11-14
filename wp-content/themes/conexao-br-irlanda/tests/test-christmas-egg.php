<?php
/**
 * Tests for the Christmas Easter egg.
 *
 * Verifies:
 *  - The JS UI-string payload exposes the active language slug
 *    ('pt' / 'en') plus the Christmas greeting/close strings.
 *  - The PT catalog resolves the greeting/close to Portuguese.
 *  - The EN catalog resolves them to English (via switch_to_locale).
 *  - The hidden Easter egg markup rendered on wp_body_open contains the
 *    dialog role, the close control and its aria-label, and is hidden
 *    by default (aria-hidden="true").
 *  - The Christmas CSS asset is enqueued and its source file exists.
 *  - The main.js source contains the trigger, the input-guard, the
 *    Escape dismissal, and no unbounded animation loop.
 *
 * Read-only: creates nothing, mutates nothing, contacts nothing.
 *
 * Usage (from the project root):
 *   docker compose exec wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-christmas-egg.php
 */

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
$theme_dir = get_template_directory();

// --- Current language slug exposed to JS. ---

$js = conexao_js_i18n_strings();
assert_true( isset( $js['language'] ), 'JS i18n payload exposes "language"' );
t_strict( 'pt', $js['language'], 'JS "language" resolves to pt on the PT request' );

// --- Christmas strings present in the JS payload. ---
foreach ( array( 'christmasTitle', 'christmasMessage', 'christmasClose' ) as $key ) {
	assert_true( isset( $js[ $key ] ), 'JS i18n payload contains key "' . $key . '"' );
}

// --- PT catalog (identity). ---
t_strict( 'Feliz Natal!', __( 'Feliz Natal!', $domain ), 'PT catalog resolves "Feliz Natal!"' );
t_strict( 'Que esta época traga alegria, união e momentos especiais.', __( 'Que esta época traga alegria, união e momentos especiais.', $domain ), 'PT catalog resolves the Christmas message' );
t_strict( 'Fechar', __( 'Fechar', $domain ), 'PT catalog resolves "Fechar"' );
// --- EN catalog resolves directly (not publicly active on the PT request). ---
switch_to_locale( 'en_US' );
t_strict( 'Merry Christmas!', __( 'Feliz Natal!', $domain ), 'EN catalog resolves "Feliz Natal!" → Merry Christmas!' );
t_strict( 'Wishing you joy, connection, and special moments this Christmas.', __( 'Que esta época traga alegria, união e momentos especiais.', $domain ), 'EN catalog resolves the Christmas message' );
t_strict( 'Close', __( 'Fechar', $domain ), 'EN catalog resolves "Fechar" → Close' );
restore_current_locale();

// Re-assert the PT request language after restore.
t_strict( 'pt', conexao_current_language_slug(), 'language slug restored to pt after EN check' );

// --- Hidden Easter egg markup on wp_body_open. ---
ob_start();
do_action( 'wp_body_open' );
$body_open = (string) ob_get_clean();

assert_true( false !== strpos( $body_open, 'christmas-egg' ), 'wp_body_open output contains the Christmas egg wrapper' );
assert_true( false !== strpos( $body_open, 'role="dialog"' ), 'Easter egg markup exposes role="dialog"' );
assert_true( false !== strpos( $body_open, 'aria-modal="true"' ), 'Easter egg dialog is aria-modal="true"' );
assert_true( false !== strpos( $body_open, 'aria-hidden="true"' ), 'Easter egg dialog is hidden by default (aria-hidden="true")' );
assert_true( false !== strpos( $body_open, 'aria-label="Fechar"' ), 'Close control carries an accessible name (PT)' );
assert_true( false !== strpos( $body_open, 'aria-labelledby="christmas-egg-title"' ), 'Dialog titles its greeting via aria-labelledby' );

// --- CSS asset enqueued + source file exists. ---
$css_file = $theme_dir . '/assets/css/christmas-egg.css';
assert_true( file_exists( $css_file ), 'Christmas egg CSS source file exists' );

$assets_source = (string) file_get_contents( $theme_dir . '/inc/assets.php' );
assert_true( false !== strpos( $assets_source, 'conexao-christmas-egg' ), 'inc/assets.php enqueues conexao-christmas-egg' );
assert_true( false !== strpos( $assets_source, 'assets/css/christmas-egg.css' ), 'inc/assets.php references the Christmas egg CSS path' );

// --- main.js trigger + guards + dismissal + no animation loop. ---
$js_source = (string) file_get_contents( $theme_dir . '/assets/js/main.js' );
assert_true( false !== strpos( $js_source, 'initChristmasEgg' ), 'main.js defines initChristmasEgg' );
assert_true( false !== strpos( $js_source, 'initChristmasEgg();' ), 'main.js registers initChristmasEgg in DOMContentLoaded' );
assert_true( false !== strpos( $js_source, 'isInputControl' ), 'main.js guards against typing in inputs/editable controls' );
assert_true( false !== strpos( $js_source, "e.key === 'Escape'" ), 'main.js dismisses the egg on Escape' );
assert_true( false !== strpos( $js_source, 'aria-hidden' ), 'main.js toggles aria-hidden for show/dismiss' );
assert_true( false === strpos( $js_source, 'NO_SET_INTERVAL(' ), 'main.js has no setInterval() animation loop' );

// --- Translations are not fuzzy / empty in the compiled catalogs. ---
assert_true( '' !== __( 'Feliz Natal!', $domain ), 'PT "Feliz Natal!" is translated, not empty' );
assert_true( '' !== __( 'Fechar', $domain ), 'PT "Fechar" is translated, not empty' );
switch_to_locale( 'en_US' );
assert_true( 'Merry Christmas!' === __( 'Feliz Natal!', $domain ), 'EN "Feliz Natal!" → "Merry Christmas!"' );
assert_true( 'Close' === __( 'Fechar', $domain ), 'EN "Fechar" → "Close"' );
restore_current_locale();

test_finish( 'christmas-egg' );

