<?php
/**
 * PERMANENT GATE (e) — the ambient layer is decorative only.
 *
 * Invariant (r10 §7.7, §7.8, §9.5e): ambient DOM is `aria-hidden`, non-focusable,
 * non-interactive (`pointer-events: none`), has no audio, and cannot block links,
 * controls or text selection.
 *
 * ## The injected negative proof
 *
 * The `check` closure is run over the REAL ambient markup of every implemented
 * package (expect 0 findings) and over a FIXTURE that deliberately violates the
 * contract (a focusable button, a link, an audio element, a missing
 * `aria-hidden`). If the fixture is not detected the check is vacuous and the gate
 * fails.
 *
 * Read-only.
 *
 * @package Conexao_BR_Irlanda
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_ROOT . '/lib/permanent-gates.php';

conexao_gate_open(
	'commemorative_ambient_decorative',
	'Ambient decoration DOM is decorative-only: aria-hidden, non-focusable, pointer-events: none, no audio, cannot block interaction.',
	array( 'theme-active', 'conexao-commemorative plugin active' )
);

/**
 * Return the contractual problems found in one ambient markup string.
 *
 * @param string $markup Rendered ambient placement.
 * @return string[]
 */
function conexao_gate_ambient_violations( $markup ) {
	$violations = array();
	$markup     = (string) $markup;

	if ( '' === trim( $markup ) ) {
		return array( 'ambient:empty_markup' );
	}

	if ( false === strpos( $markup, 'aria-hidden="true"' ) ) {
		$violations[] = 'ambient:not_aria_hidden';
	}

	foreach ( array( '<button', '<a ', '<input', '<select', '<textarea', '<audio', '<video', '<iframe' ) as $forbidden ) {
		if ( false !== strpos( $markup, $forbidden ) ) {
			$violations[] = 'ambient:interactive_node:' . $forbidden;
		}
	}

	if ( preg_match( '/tabindex="(?!-1")[^"]*"/', $markup ) ) {
		$violations[] = 'ambient:focusable_tabindex';
	}

	return $violations;
}

/**
 * Render the ambient placement for one clock and return the markup.
 *
 * @param string $clock ISO local time.
 * @return string
 */
function conexao_gate_ambient_markup( $clock ) {
	global $wp_query;

	$wp_query->is_home     = false;
	$wp_query->is_page     = true;
	$wp_query->is_singular = true;
	$wp_query->is_archive  = false;
	$wp_query->is_search   = false;
	$wp_query->is_404      = false;

	conexao_commemorative_flush_plan();

	$GLOBALS['conexao_ce_plan'] = conexao_commemorative_build_plan( new DateTimeImmutable( $clock, wp_timezone() ) );

	ob_start();
	conexao_commemorative_render_placement( 'site_wide_ambient' );

	return (string) ob_get_clean();
}

test_section( 'The real ambient markup of every implemented package' );

$rendered = array(
	'halloween' => conexao_gate_ambient_markup( '2026-10-20 12:00:00' ),
	'christmas' => conexao_gate_ambient_markup( '2026-12-15 12:00:00' ),
);

$findings = array();

foreach ( $rendered as $package => $markup ) {
	assert_true( '' !== $markup, $package . ': the ambient layer renders inside its window' );

	foreach ( conexao_gate_ambient_violations( $markup ) as $violation ) {
		$findings[] = $package . ':' . $violation;
	}
}

conexao_gate_violation(
	'ambient:decorative_contract',
	count( $findings ),
	'the ambient layer is aria-hidden, non-focusable and free of interactive nodes',
	array( 'findings' => $findings )
);

test_section( 'The shared stylesheet and scheduler keep it non-interactive' );

$shared_css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/commemorative.css' );
$shared_js  = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/commemorative.js' );

$style_findings = array();

if ( false === strpos( $shared_css, 'pointer-events: none;' ) ) {
	$style_findings[] = 'css:missing_pointer_events';
}

if ( preg_match( '/\.ce-placement--site_wide_ambient\s*\{[^}]*pointer-events:\s*auto/', $shared_css ) ) {
	$style_findings[] = 'css:ambient_layer_is_interactive';
}

if ( false === strpos( $shared_css, '.ce-ambient-decoration' ) ) {
	$style_findings[] = 'css:missing_decoration_class';
}

if ( false === strpos( $shared_css, 'prefers-reduced-motion' ) ) {
	$style_findings[] = 'css:missing_reduced_motion';
}

conexao_gate_violation(
	'ambient:stylesheet_contract',
	count( $style_findings ),
	'the shared stylesheet keeps every ambient node non-interactive',
	array( 'findings' => $style_findings )
);

$script_findings = array();

foreach ( array( 'aria-hidden', "'tabindex', '-1'", 'pointerEvents', 'removeChild' ) as $required ) {
	if ( false === strpos( $shared_js, $required ) ) {
		$script_findings[] = 'js:missing:' . $required;
	}
}

conexao_gate_violation(
	'ambient:scheduler_contract',
	count( $script_findings ),
	'the shared scheduler marks every decoration decorative and removes it afterwards',
	array( 'findings' => $script_findings )
);

test_section( 'Injected negative proof: a violating layer IS detected' );

$fixture = '<div class="ce-placement--site_wide_ambient">'
	. '<button type="button" tabindex="0">Close</button>'
	. '<a href="/guias/">Guides</a>'
	. '<audio autoplay src="x.mp3"></audio>'
	. '</div>';

$fixture_findings = conexao_gate_ambient_violations( $fixture );

conexao_gate_violation(
	'gate:injected_negative_not_detected',
	count( $fixture_findings ) >= 4 ? 0 : 1,
	'the gate detects a focusable, interactive, audible ambient fixture',
	array( 'fixture_findings' => $fixture_findings )
);

test_section( 'Injected negative proof: a non-decorative stylesheet IS detected' );

$fixture_css = '.ce-placement--site_wide_ambient { position: fixed; pointer-events: auto; }';
$fixture_style_findings = array();

if ( preg_match( '/\.ce-placement--site_wide_ambient\s*\{[^}]*pointer-events:\s*auto/', $fixture_css ) ) {
	$fixture_style_findings[] = 'css:ambient_layer_is_interactive';
}

conexao_gate_violation(
	'gate:injected_negative_not_detected:css',
	count( $fixture_style_findings ) > 0 ? 0 : 1,
	'the gate detects an ambient layer that accepts pointer events'
);

conexao_commemorative_flush_plan();

conexao_gate_close();
