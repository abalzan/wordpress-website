<?php
/**
 * PERMANENT GATE (d) — one package, multiple placements.
 *
 * Invariant (r10 §6.1, §7.11, §9.5d): the engine selects at most ONE event, the
 * theme resolves at most ONE package, and the only placements that may render are
 * that selected package's own placements. Multiple placements from the SAME
 * package are expected; a placement from any other package is a violation.
 *
 * ## The injected negative proof
 *
 * The `check` closure is run twice:
 *   - over every real dispatch plan for a set of clocks and routes (expect 0);
 *   - over a FIXTURE plan whose placement list is stolen from another package
 *     (expect > 0). If the fixture is not detected, the check is vacuous and the
 *     gate fails.
 *
 * Read-only.
 *
 * @package Conexao_BR_Irlanda
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_ROOT . '/lib/permanent-gates.php';

conexao_gate_open(
	'commemorative_single_package',
	'At most one event/package is selected, and only that package renders placements.',
	array( 'theme-active', 'conexao-commemorative plugin active' )
);

/**
 * Return the violations for one plan.
 *
 * @param array $plan Dispatch plan.
 * @return string[]
 */
function conexao_gate_single_package_violations( array $plan ) {
	$violations = array();
	$package    = isset( $plan['package'] ) && is_array( $plan['package'] ) ? $plan['package'] : null;
	$placements = isset( $plan['placements'] ) && is_array( $plan['placements'] ) ? $plan['placements'] : array();

	if ( empty( $placements ) ) {
		return $violations;
	}

	if ( null === $package ) {
		$violations[] = 'placements_without_package';

		return $violations;
	}

	$allowed = array_keys( $package['placements'] );

	foreach ( array_keys( $placements ) as $placement_id ) {
		if ( ! in_array( $placement_id, $allowed, true ) ) {
			$violations[] = 'placement_not_owned_by_package:' . $placement_id;
		}
	}

	// A placement definition must carry its own template; a mismatch means the
	// plan mixed two packages' definitions.
	foreach ( $placements as $placement_id => $placement ) {
		if ( ! isset( $placement['template'] ) || $placement['template'] !== $package['placements'][ $placement_id ]['template'] ) {
			$violations[] = 'template_mismatch:' . $placement_id;
		}
	}

	return $violations;
}

test_section( 'Every real plan renders only its own package' );

$route_sets = array(
	array( 'kind' => 'homepage', 'page' => 0 ),
	array( 'kind' => 'internal', 'page' => 0 ),
);

$clocks = array(
	'2026-02-10 12:00:00',
	'2026-03-10 12:00:00',
	'2026-04-05 12:00:00',
	'2026-06-12 12:00:00',
	'2026-10-20 12:00:00',
	'2026-12-15 12:00:00',
	'2026-12-30 12:00:00',
	'2026-11-06 12:00:00',
);

$real_violations = array();
$plans_checked   = 0;
$packages_seen   = array();

foreach ( $route_sets as $route ) {
	global $wp_query;

	$wp_query->is_home     = false;
	$wp_query->is_page     = true;
	$wp_query->is_singular = true;
	$wp_query->is_archive  = false;
	$wp_query->is_search   = false;
	$wp_query->is_404      = false;

	if ( 'homepage' === $route['kind'] ) {
		$front_id = (int) get_option( 'page_on_front' );

		$wp_query->queried_object    = $front_id > 0 ? get_post( $front_id ) : null;
		$wp_query->queried_object_id = $front_id;
	} else {
		$wp_query->queried_object    = null;
		$wp_query->queried_object_id = 0;
	}

	foreach ( $clocks as $clock ) {
		conexao_commemorative_flush_plan();

		$plan = conexao_commemorative_build_plan( new DateTimeImmutable( $clock, wp_timezone() ) );

		$plans_checked++;

		if ( ! empty( $plan['package']['slug'] ) ) {
			$packages_seen[] = $plan['package']['slug'];
		}

		foreach ( conexao_gate_single_package_violations( $plan ) as $violation ) {
			$real_violations[] = $clock . '/' . $route['kind'] . ':' . $violation;
		}
	}
}

assert_true( $plans_checked >= 8, 'the gate inspected at least eight real dispatch plans' );

conexao_gate_violation(
	'package:leaked_placement',
	count( $real_violations ),
	'no plan ever renders a placement that belongs to another package',
	array( 'violations' => $real_violations )
);

// The engine may select at most one event for one request.
$resolution = conexao_commemorative_resolution( new DateTimeImmutable( '2026-10-20 12:00:00', wp_timezone() ) );

$single_event = isset( $resolution['event'] ) && is_array( $resolution['event'] ) && isset( $resolution['event']['event']['event_key'] );

conexao_gate_violation(
	'multiple:selected_events',
	$single_event ? 0 : 1,
	'a resolution holds exactly one selected event'
);

test_section( 'Injected negative proof: a stolen placement IS detected' );

$halloween_plan = conexao_commemorative_build_plan( new DateTimeImmutable( '2026-10-20 12:00:00', wp_timezone() ) );

conexao_gate_require(
	! empty( $halloween_plan['package'] ),
	'the christmas package must be complete (templates + assets)',
	'the gate prerequisite is available: a resolvable package on the homepage'
);

// Steal the christmas landing_page placement (Halloween declares no such
// placement) and inject it into the Halloween plan.
$christmas = conexao_commemorative_package( 'christmas' );

conexao_gate_require(
	isset( $christmas['placements']['landing_page'] ),
	'the christmas package must declare the landing_page placement',
	'the gate prerequisite is available: the stolen placement definition'
);

$fixture               = $halloween_plan;
$fixture['placements'] = $halloween_plan['placements'];
$fixture['placements']['landing_page'] = $christmas['placements']['landing_page'];

$fixture_violations = conexao_gate_single_package_violations( $fixture );

conexao_gate_violation(
	'gate:injected_negative_not_detected',
	count( $fixture_violations ) > 0 ? 0 : 1,
	'the gate detects a placement that does not belong to the selected package',
	array( 'fixture_violations' => $fixture_violations )
);

conexao_commemorative_flush_plan();

conexao_gate_close();
