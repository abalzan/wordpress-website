<?php
/**
 * PERMANENT GATE (a) — no commemorative asset without a displayed event.
 *
 * Invariant (r10 §7.9, §9.5a): when no commemorative placement is displayed, no
 * commemorative CSS, JS, image or font handle may be enqueued. With no active
 * event the site returns to its pre-commemorative weight.
 *
 * ## The injected negative proof
 *
 * A gate that only ever observes "zero assets" cannot distinguish a correct
 * implementation from one that never enqueues anything at all. So the gate:
 *
 *   1. checks the REAL no-event plan and the REAL enqueue path (expect 0 assets);
 *   2. INJECTS a plan that DOES render a placement and checks that the SAME check
 *      now reports assets (expect > 0);
 *   3. fails if the injected condition was NOT detected — that is the mutation
 *      proof that the gate can actually see the forbidden condition.
 *
 * Read-only: it enqueues and then dequeues in-process; nothing is written.
 *
 * @package Conexao_BR_Irlanda
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_ROOT . '/lib/permanent-gates.php';

conexao_gate_open(
	'commemorative_no_asset_without_event',
	'No commemorative CSS/JS handle is enqueued (or planned) when no commemorative placement is displayed.',
	array( 'theme-active', 'conexao-commemorative plugin active' )
);

/**
 * Count the commemorative asset handles described by one plan.
 *
 * @param array $plan Dispatch plan.
 * @return array{styles:string[],scripts:string[],shared:bool}
 */
function conexao_gate_assets_of( array $plan ) {
	$assets = conexao_commemorative_asset_plan( $plan );

	return array(
		'styles'  => array_keys( $assets['styles'] ),
		'scripts' => array_keys( $assets['scripts'] ),
		'shared'  => (bool) $assets['shared'],
	);
}

/**
 * Put the main query on an internal page so route-dependent placements resolve.
 *
 * @return void
 */
function conexao_gate_simulate_internal_route() {
	global $wp_query;

	$wp_query->is_home     = false;
	$wp_query->is_page     = true;
	$wp_query->is_singular = true;
	$wp_query->is_archive  = false;
	$wp_query->is_search   = false;
	$wp_query->is_404      = false;

	conexao_commemorative_flush_plan();
}

conexao_gate_simulate_internal_route();

$no_event_plan = conexao_commemorative_build_plan();
$no_event      = conexao_gate_assets_of( $no_event_plan );

test_section( 'Real request: an internal page with no active event' );

conexao_gate_violation(
	'asset:planned_without_event:styles',
	count( $no_event['styles'] ),
	'the no-event plan schedules no commemorative stylesheet',
	array( 'handles' => $no_event['styles'] )
);

conexao_gate_violation(
	'asset:planned_without_event:scripts',
	count( $no_event['scripts'] ),
	'the no-event plan schedules no commemorative script',
	array( 'handles' => $no_event['scripts'] )
);

conexao_gate_violation(
	'asset:planned_without_event:shared',
	$no_event['shared'] ? 1 : 0,
	'the no-event plan does not load the shared framework'
);

// The real enqueue path, with the real (no-event) request plan.
$GLOBALS['conexao_ce_plan'] = $no_event_plan;
conexao_commemorative_enqueue_assets();

$leaked = array();

foreach ( array( 'conexao-commemorative', 'conexao-ce-css-presentations-halloween', 'conexao-ce-css-presentations-christmas', 'conexao-ce-css-presentations-new-year' ) as $handle ) {
	if ( wp_style_is( $handle, 'enqueued' ) ) {
		$leaked[] = 'style:' . $handle;
		wp_dequeue_style( $handle );
	}
}

foreach ( array( 'conexao-commemorative', 'conexao-ce-js-presentations-halloween-halloween', 'conexao-ce-js-presentations-christmas-christmas', 'conexao-ce-js-presentations-new-year-new-year' ) as $handle ) {
	if ( wp_script_is( $handle, 'enqueued' ) ) {
		$leaked[] = 'script:' . $handle;
		wp_dequeue_script( $handle );
	}
}

conexao_gate_violation(
	'asset:enqueued_without_event',
	count( $leaked ),
	'no commemorative handle is enqueued on a request with no active event',
	array( 'handles' => $leaked )
);

// -- Injected negative proof ------------------------------------------------
// A plan that DOES render the Halloween package must be visible to the same
// check. If it is not, this gate cannot detect the forbidden condition at all.
$halloween_plan = conexao_commemorative_build_plan( new DateTimeImmutable( '2026-10-20 12:00:00', wp_timezone() ) );

$injected = conexao_gate_assets_of( $halloween_plan );

$injected_detected = count( $injected['styles'] ) > 0 && count( $injected['scripts'] ) > 0 && $injected['shared'];

conexao_gate_violation(
	'gate:injected_negative_not_detected',
	$halloween_plan['package'] && $injected_detected ? 0 : 1,
	'the gate can detect the forbidden condition (an injected active placement IS reported)',
	array( 'injected' => $injected, 'package' => isset( $halloween_plan['package']['slug'] ) ? $halloween_plan['package']['slug'] : null )
);

// Restore the request-time plan.
conexao_commemorative_flush_plan();

conexao_gate_close();
