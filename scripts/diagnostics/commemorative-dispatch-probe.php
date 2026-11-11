#!/usr/bin/env php
<?php
/**
 * Read-only probe of the commemorative presentation dispatcher.
 *
 * Purpose: show, from the real theme + plugin code, which package and which
 * placements the dispatcher resolves for a given route and clock, and which asset
 * handles that would enqueue. It is the human-readable companion to the
 * in-process gates.
 *
 * Scope: read-only. It creates no record, term, option or meta row.
 *
 * Safety: local only. It uses the shared script bootstrap and never writes.
 *
 * Usage (from the project root):
 *   docker compose exec -T wordpress php /var/www/html/scripts/diagnostics/commemorative-dispatch-probe.php
 *
 * @package Conexao_BR_Scripts
 */

require_once dirname( __DIR__ ) . '/lib/bootstrap.php';

conexao_script_boot(
	array(
		'script'             => 'scripts/diagnostics/commemorative-dispatch-probe.php',
		'purpose'            => 'Print the commemorative dispatch plan and conditional asset handles for simulated routes and clocks (read-only).',
		'scope'              => 'read-only',
		'safety'             => 'Reads the theme registry and the engine. Simulates the query object in memory. Never writes.',
		'safety_level'       => 'read-only',
		'read_only'          => true,
		'writes'             => false,
		'production_capable' => false,
		'target_description' => 'the WordPress install it is executed against (local Docker WordPress by default)',
		'modes_description'  => "  --dry-run   The only mode (read-only).\n",
		'arguments'          => "  (no arguments)\n",
	)
);

if ( ! function_exists( 'conexao_commemorative_package_definitions' ) ) {
	fwrite( STDERR, "ERROR: the theme is not active (no commemorative presentation framework).\n" );
	exit( 1 );
}

/**
 * Reset the simulated route to the given kinds.
 *
 * @param string[] $kinds Route kinds: homepage, internal, landing.
 * @param int      $page_id Optional landing page ID.
 * @return void
 */
function conexao_probe_route( array $kinds, $page_id = 0 ) {
	global $wp_query;

	// `is_front_page()` delegates to `is_page( page_on_front )` on a static front
	// page, so the homepage is simulated by marking that page as the queried one.
	$front_id = (int) get_option( 'page_on_front' );
	$is_home  = in_array( 'homepage', $kinds, true );

	$wp_query->is_home       = false;
	$wp_query->is_page       = $is_home || in_array( 'internal', $kinds, true ) || in_array( 'landing', $kinds, true );
	$wp_query->is_singular   = in_array( 'internal', $kinds, true ) || in_array( 'landing', $kinds, true );
	$wp_query->is_archive    = false;
	$wp_query->is_search     = false;
	$wp_query->is_404        = false;

	if ( $is_home && $front_id > 0 ) {
		$wp_query->queried_object    = get_post( $front_id );
		$wp_query->queried_object_id = $front_id;
	} elseif ( $page_id > 0 ) {
		$wp_query->queried_object    = get_post( $page_id );
		$wp_query->queried_object_id = $page_id;
	} else {
		$wp_query->queried_object    = null;
		$wp_query->queried_object_id = 0;
	}

	conexao_commemorative_flush_plan();
}

/**
 * Print one plan for a label + clock + route.
 *
 * @param string $label Label.
 * @param string $clock ISO-ish date in the site timezone.
 * @param array  $kinds Route kinds.
 * @param int    $page_id Optional page ID.
 * @return void
 */
function conexao_probe_plan( $label, $clock, array $kinds, $page_id = 0 ) {
	conexao_probe_route( $kinds, $page_id );

	$now  = new DateTimeImmutable( $clock, wp_timezone() );
	$plan = conexao_commemorative_build_plan( $now );
	$assets = conexao_commemorative_asset_plan( $plan );

	printf( "\n== %s ==\n", $label );
	printf( "  clock:        %s (%s)\n", $now->format( 'Y-m-d H:i:s' ), wp_timezone()->getName() );
	printf( "  route kinds:  %s\n", implode( ', ', $plan['route_kinds'] ) ? implode( ', ', $plan['route_kinds'] ) : '(none)' );
	printf( "  event:        %s\n", $plan['event'] ? $plan['event']->event_key . ' (occurrence ' . (string) $plan['event']->occurrence_year . ')' : 'none' );
	printf( "  package:      %s\n", isset( $plan['package']['slug'] ) ? $plan['package']['slug'] : 'none' );
	printf( "  placements:   %s\n", $plan['placements'] ? implode( ', ', array_keys( $plan['placements'] ) ) : '(none)' );
	printf( "  landing:      %s\n", ! empty( $plan['landing'] ) ? $plan['landing']['package']['slug'] . ' (page ' . (int) $plan['landing']['page_id'] . ')' : 'none' );
	printf( "  styles:       %s\n", $assets['styles'] ? implode( ', ', array_keys( $assets['styles'] ) ) : '(none)' );
	printf( "  scripts:      %s\n", $assets['scripts'] ? implode( ', ', array_keys( $assets['scripts'] ) ) : '(none)' );
}

/**
 * Render the placements of one simulated route and report what was produced.
 *
 * @param string $label   Label.
 * @param string $clock   Clock.
 * @param array  $kinds   Route kinds.
 * @param int    $page_id Optional page ID.
 * @return void
 */
function conexao_probe_render( $label, $clock, array $kinds, $page_id = 0 ) {
	conexao_probe_route( $kinds, $page_id );

	$now = new DateTimeImmutable( $clock, wp_timezone() );

	$GLOBALS['conexao_ce_plan'] = conexao_commemorative_build_plan( $now );

	printf( "\n-- render: %s --\n", $label );

	foreach ( array( 'homepage_hero', 'site_wide_ambient', 'landing_page' ) as $placement_id ) {
		ob_start();
		$rendered = conexao_commemorative_render_placement( $placement_id );
		$markup   = (string) ob_get_clean();

		printf(
			"  %-18s rendered=%-3s bytes=%-6d aria-hidden=%-3s pointer-events-free=%-3s pumpkin=%s\n",
			$placement_id,
			$rendered ? 'yes' : 'no',
			strlen( $markup ),
			false !== strpos( $markup, 'aria-hidden' ) ? 'yes' : 'no',
			false === strpos( $markup, 'tabindex="0"' ) ? 'yes' : 'no',
			false !== strpos( $markup, 'seasonal-halloween-pumpkin' ) ? 'yes' : 'no'
		);
	}
}

echo "\n== registered packages ==\n";
foreach ( conexao_commemorative_packages() as $slug => $package ) {
	printf(
		"  %-18s status=%-12s energy=%-9s motif=%-18s placements=[%s]\n",
		$slug,
		$package['status'],
		$package['energy'],
		$package['motif'],
		implode( ', ', array_keys( $package['placements'] ) )
	);
}

$landing_page = get_page_by_path( 'natal', OBJECT, 'page' );

conexao_probe_plan( 'no active event, homepage', '2026-11-06 12:00:00', array( 'homepage' ) );
conexao_probe_plan( 'Halloween active, homepage', '2026-10-20 12:00:00', array( 'homepage' ) );
conexao_probe_plan( 'Halloween active, internal page', '2026-10-20 12:00:00', array( 'internal' ) );
conexao_probe_plan( 'Christmas active, homepage', '2026-12-15 12:00:00', array( 'homepage' ) );
conexao_probe_plan( 'Christmas active, internal page', '2026-12-15 12:00:00', array( 'internal' ) );
conexao_probe_plan( 'landing page after the window (archival)', '2027-02-01 12:00:00', array( 'landing' ), $landing_page ? (int) $landing_page->ID : 0 );

echo "\n== rendered placements ==\n";
conexao_probe_render( 'no active event, homepage', '2026-11-06 12:00:00', array( 'homepage' ) );
conexao_probe_render( 'Halloween, homepage', '2026-10-20 12:00:00', array( 'homepage' ) );
conexao_probe_render( 'Halloween, internal page', '2026-10-20 12:00:00', array( 'internal' ) );

echo "\nsummary: read-only dispatch probe complete\n";
