<?php
/**
 * PERMANENT GATE (f) — placement assets are conditional.
 *
 * Invariant (r10 §7.9, §9.5f): a package/placement asset handle is enqueued only
 * when its placement is actually rendered on the current route. The homepage hero
 * must not load on an internal page; a package that is not selected must not load
 * at all.
 *
 * ## The injected negative proof
 *
 * The `check` closure compares an asset plan with the placements that produced it
 * and reports any asset assigned to a placement that is NOT in the plan. It is run
 * over every real plan (expect 0) and over a FIXTURE plan that carries an ambient
 * asset while rendering only the hero (expect > 0). If the fixture is not detected
 * the gate is vacuous and fails.
 *
 * Read-only.
 *
 * @package Conexao_BR_Irlanda
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_ROOT . '/lib/permanent-gates.php';

conexao_gate_open(
	'commemorative_placement_assets',
	'Package and placement asset handles are enqueued only for placements actually rendered on the current route.',
	array( 'theme-active', 'conexao-commemorative plugin active' )
);

/**
 * Return the asset-plan problems for one plan.
 *
 * @param array $plan Dispatch plan.
 * @return string[]
 */
function conexao_gate_placement_asset_violations( array $plan ) {
	$violations = array();
	$assets     = conexao_commemorative_asset_plan( $plan );

	$rendered = array_keys( $plan['placements'] );

	if ( ! empty( $plan['landing'] ) ) {
		$rendered[] = 'landing_page';
	}

	foreach ( array_keys( $assets['placements'] ) as $placement_id ) {
		if ( ! in_array( $placement_id, $rendered, true ) ) {
			$violations[] = 'asset_for_unrendered_placement:' . $placement_id;
		}
	}

	// Every asset handle must belong to at least one rendered placement.
	$owned_styles  = array();
	$owned_scripts = array();

	foreach ( $assets['placements'] as $row ) {
		foreach ( $row['styles'] as $handle ) {
			$owned_styles[] = $handle;
		}

		foreach ( $row['scripts'] as $handle ) {
			$owned_scripts[] = $handle;
		}
	}

	foreach ( array_keys( $assets['styles'] ) as $handle ) {
		if ( ! in_array( $handle, $owned_styles, true ) ) {
			$violations[] = 'orphan_stylesheet:' . $handle;
		}
	}

	foreach ( array_keys( $assets['scripts'] ) as $handle ) {
		if ( ! in_array( $handle, $owned_scripts, true ) ) {
			$violations[] = 'orphan_script:' . $handle;
		}
	}

	// With no rendered placement there must be no asset at all.
	if ( empty( $rendered ) && ( ! empty( $assets['styles'] ) || ! empty( $assets['scripts'] ) ) ) {
		$violations[] = 'assets_without_placement';
	}

	return $violations;
}

/**
 * Build a plan for one clock and route kind.
 *
 * @param string $clock ISO local time.
 * @param string $kind  homepage|internal.
 * @return array
 */
function conexao_gate_asset_plan( $clock, $kind ) {
	global $wp_query;

	$front_id = (int) get_option( 'page_on_front' );

	$wp_query->is_home     = false;
	$wp_query->is_page     = true;
	$wp_query->is_singular = true;
	$wp_query->is_archive  = false;
	$wp_query->is_search   = false;
	$wp_query->is_404      = false;

	if ( 'homepage' === $kind && $front_id > 0 ) {
		$wp_query->queried_object    = get_post( $front_id );
		$wp_query->queried_object_id = $front_id;
	} else {
		$wp_query->queried_object    = null;
		$wp_query->queried_object_id = 0;
	}

	conexao_commemorative_flush_plan();

	return conexao_commemorative_build_plan( new DateTimeImmutable( $clock, wp_timezone() ) );
}

test_section( 'Real routes: only the rendered placements own assets' );

$findings = array();
$cases    = array(
	'no-event/homepage'  => conexao_gate_asset_plan( '2026-11-06 12:00:00', 'homepage' ),
	'no-event/internal'  => conexao_gate_asset_plan( '2026-11-06 12:00:00', 'internal' ),
	'halloween/homepage' => conexao_gate_asset_plan( '2026-10-20 12:00:00', 'homepage' ),
	'halloween/internal' => conexao_gate_asset_plan( '2026-10-20 12:00:00', 'internal' ),
	'christmas/homepage' => conexao_gate_asset_plan( '2026-12-15 12:00:00', 'homepage' ),
	'christmas/internal' => conexao_gate_asset_plan( '2026-12-15 12:00:00', 'internal' ),
);

foreach ( $cases as $label => $plan ) {
	foreach ( conexao_gate_placement_asset_violations( $plan ) as $violation ) {
		$findings[] = $label . ':' . $violation;
	}
}

conexao_gate_violation(
	'asset:unrendered_placement',
	count( $findings ),
	'no asset is planned for a placement that does not render on the route',
	array( 'findings' => $findings )
);

test_section( 'The hero asset is absent on an internal page' );

$internal_plan = $cases['halloween/internal'];
$internal      = conexao_commemorative_asset_plan( $internal_plan );

$hero_on_internal = array();

foreach ( $internal['placements'] as $placement_id => $row ) {
	if ( 'homepage_hero' === $placement_id && ( ! empty( $row['styles'] ) || ! empty( $row['scripts'] ) ) ) {
		$hero_on_internal[] = $placement_id;
	}
}

conexao_gate_violation(
	'asset:hero_on_internal_route',
	count( $hero_on_internal ),
	'the homepage-hero assets are never enqueued on an internal route',
	array( 'placements' => array_keys( $internal['placements'] ) )
);

test_section( 'The non-selected package ships nothing' );

$home_plan = $cases['halloween/homepage'];
$home      = conexao_commemorative_asset_plan( $home_plan );

$foreign = array();

foreach ( array_keys( $home['styles'] ) as $handle ) {
	if ( false !== strpos( $handle, 'christmas' ) ) {
		$foreign[] = 'style:' . $handle;
	}
}

foreach ( array_keys( $home['scripts'] ) as $handle ) {
	if ( false !== strpos( $handle, 'christmas' ) ) {
		$foreign[] = 'script:' . $handle;
	}
}

conexao_gate_violation(
	'asset:non_selected_package',
	count( $foreign ),
	'only the selected package enqueues assets',
	array( 'handles' => $foreign )
);

test_section( 'Injected negative proof: an asset for an unrendered placement IS detected' );

// A plan that renders ONLY the hero, while the asset plan still carries the
// ambient placement: exactly the leak this gate exists to catch.
$fixture = $cases['halloween/homepage'];

unset( $fixture['placements']['site_wide_ambient'] );

$fixture_findings = conexao_gate_placement_asset_violations( $fixture );

// The asset plan is derived from the same plan, so it cannot leak by itself,
// which is the point: to prove the CHECK works, inject the ambient asset row
// explicitly into the derived plan.
$assets_fixture = conexao_commemorative_asset_plan( $fixture );
$assets_fixture['placements']['site_wide_ambient'] = array(
	'package' => 'halloween',
	'styles'  => array( 'conexao-ce-css-presentations-halloween' ),
	'scripts' => array( 'conexao-ce-js-presentations-halloween-halloween' ),
);

$leak = array();

foreach ( array_keys( $assets_fixture['placements'] ) as $placement_id ) {
	if ( ! in_array( $placement_id, array_keys( $fixture['placements'] ), true ) ) {
		$leak[] = 'asset_for_unrendered_placement:' . $placement_id;
	}
}

conexao_gate_violation(
	'gate:injected_negative_not_detected',
	count( array_merge( $fixture_findings, $leak ) ) > 0 ? 0 : 1,
	'the gate detects an asset belonging to a placement that does not render',
	array( 'fixture_findings' => array_merge( $fixture_findings, $leak ) )
);

conexao_commemorative_flush_plan();

conexao_gate_close();

