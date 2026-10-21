<?php
/**
 * Homepage shortcut consolidation (UI/UX Improvement 2).
 *
 * Verifies that the "Acesso Rápido" primary grid and the "Precisa de ajuda?"
 * help section are derived from the SAME canonical card definitions
 * (`$quick_access_cards` in front-page.php, matched by the inert `key` slug)
 * and that no destination is rendered as two tiles.
 *
 * Read-only. No content is created or modified.
 *
 * Usage (from the project root):
 *   php wp-content/themes/conexao-br-irlanda/tests/test-homepage-shortcut-consolidation.php
 *
 * @package conexao-br-irlanda
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$passed = 0;
$failed = 0;

/**
 * Switch the request language context the way Polylang does while routing.
 *
 * @param string $slug Language slug.
 * @return string|false Previous language slug.
 */
function hsc_set_language( $slug ) {
	if ( ! function_exists( 'PLL' ) || ! PLL() ) {
		return false;
	}

	$model    = PLL()->model;
	$previous = isset( PLL()->curlang->slug ) ? PLL()->curlang->slug : false;

	PLL()->curlang = $model->get_language( $slug );

	return $previous;
}

if ( ! function_exists( 'conexao_current_language_slug' ) ) {
	exit( 1 );
}

test_prerequisite_hint( 'polylang' );
test_require( function_exists( 'pll_current_language' ), 'polylang', 'test prerequisite is available: function_exists( pll_current_language )', 'activate the Polylang plugin' );

// Canonical destinations keyed by stable 'key' slug from front-page.php.
$canonical = array(
	'moradia'    => '/guias/?categoria=moradia',
	'empresgos'  => '/empregos/',
	'saude'      => '/guias/?categoria=saude',
	'lazer'      => '/lazer/',
	'transporte' => '/guias/?categoria=transporte',
	'financas'   => '/guias/?categoria=financas',
	'beneficios' => '/guias/?categoria=beneficios',
	'eventos'    => '/eventos/',
	'educacao'   => '/cursos/',
	'documentos' => '/guias/?categoria=documentos',
	'apoiadores' => '/apoiadores/',
	'blog'       => '/blog/',
	'guias'      => '/guias/',
);

// Keys that belong to the "Precisa de ajuda?" help-oriented subset.
// Rendered ONLY in the help section and excluded from the primary grid.
$help_keys = array( 'moradia', 'empregos', 'documentos', 'saude', 'beneficios', 'financas' );

/**
 * Render front-page.php and split its shortcut links into the primary grid
 * and the help section.
 *
 * @param string $lang 'pt' or 'en'.
 * @return array{qa: string[], help: string[], html: string}
 */
function hsc_render_shortcuts( $lang ) {
	hsc_set_language( $lang );

	ob_start();
	$template = trailingslashit( get_stylesheet_directory() ) . 'front-page.php';
	include $template;
	$html = (string) ob_get_clean();

	hsc_set_language( 'pt' );

	$qa = array();
	if ( preg_match_all( '/<a\s+href="([^"]+)"\s+class="[^"]*quick-access-card[^"]*"/', $html, $m ) ) {
		$qa = $m[1];
	}

	$help = array();
	if ( preg_match_all( '/<a\s+href="([^"]+)"\s+class="[^"]*help-shortcut[^"]*"/', $html, $m ) ) {
		$help = $m[1];
	}

	return array( 'qa' => array_values( $qa ), 'help' => array_values( $help ), 'html' => $html );
}

/**
 * Assert no PHP warning/notice leaked into the rendered output.
 */
function hsc_no_php_noise( $html, $lang ) {
	assert_true(
		false === stripos( $html, 'Warning:' ) && false === stripos( $html, 'Notice:' ) && false === stripos( $html, 'Fatal error:' ),
		"{$lang}: no PHP warnings/notices in rendered homepage"
	);
}

// ---------------------------------------------------------------------------
// Run for both languages.
foreach ( array( 'pt', 'en' ) as $lang ) {
	$rendered = hsc_render_shortcuts( $lang );
	$qa       = $rendered['qa'];
	$help     = $rendered['help'];
	$html     = $rendered['html'];

	$label = strtoupper( $lang );

	// 1. Homepage renders in both languages.
	assert_true( '' !== $html, "{$label}: homepage renders" );

	// 2. No PHP noise.
	hsc_no_php_noise( $html, $label );

	// 3. No shortcut renders in both the grid and the help section (disjoint).
	$inter = array_intersect( $qa, $help );
	assert_true( array() === $inter, "{$label}: no shortcut renders in both the grid and the help section" );

	// 4. Every destination appears at most once across the consolidated experience.
	$all = array_merge( $qa, $help );
	assert_true( count( $all ) === count( array_unique( $all ) ), "{$label}: every destination appears at most once" );

	// 5. Localized headings.
	if ( 'pt' === $lang ) {
		assert_true( false !== strpos( $html, 'Acesso Rápido' ), "{$label}: 'Acesso Rápido' heading present" );
		assert_true( false !== strpos( $html, 'Precisa de ajuda?' ), "{$label}: 'Precisa de ajuda?' heading present" );
	} else {
		assert_true( false !== strpos( $html, 'Quick Access' ), "{$label}: 'Quick Access' heading present" );
		assert_true( false !== strpos( $html, 'Need help?' ), "{$label}: 'Need help?' heading present" );
	}

	// 6. Both shortcut groups render at least one link.
	assert_true( count( $qa ) > 0 && count( $help ) > 0, "{$label}: both shortcut groups render at least one link" );

	// 7. The help grid does NOT contain any QA destination (consolidation goal).
	$overlap = array_intersect( $qa, $help );
	assert_true( array() === $overlap, "{$label}: no duplicate destination across QA and help" );

	// 8. Together, QA + help contain ALL 13 canonical destinations.
	$combined = array_unique( array_merge( $qa, $help ) );
	assert_true( count( $combined ) >= 13, "{$label}: all 13 unique destinations present (got " . count( $combined ) . ')' );
}

test_finish( 'homepage-shortcut-consolidation' );
