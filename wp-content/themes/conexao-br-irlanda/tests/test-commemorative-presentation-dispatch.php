<?php
/**
 * Commemorative presentation dispatch suite (r10 §7.4–§7.11, §9.4).
 *
 * Proves, with the REAL theme dispatcher and the REAL engine:
 *   - the engine's single selected event decides the package;
 *   - a package may render several placements together;
 *   - placements are route-specific (hero on the homepage only, ambient where
 *     configured, landing page only on the opted-in page);
 *   - assets follow the placements actually rendered;
 *   - no active event means no plan, no markup and no asset;
 *   - placements never leak from another package;
 *   - the landing page still renders after the event window (archival).
 *
 * @package Conexao_BR_Irlanda
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

test_title( 'Commemorative presentation dispatch' );
test_prerequisite_hint( 'activate conexao-commemorative + the theme, and run the commemorative seed' );

/**
 * Simulate a route on the main query.
 *
 * `is_front_page()` delegates to `is_page( page_on_front )` on a static front
 * page, so the homepage is simulated by marking that page as the queried one.
 *
 * @param string $kind    homepage|internal|landing.
 * @param int    $page_id Optional page ID for the landing route.
 * @return void
 */
function conexao_dispatch_test_route( $kind, $page_id = 0 ) {
	global $wp_query;

	$front_id = (int) get_option( 'page_on_front' );

	$wp_query->is_home     = false;
	$wp_query->is_page     = in_array( $kind, array( 'homepage', 'internal', 'landing' ), true );
	$wp_query->is_singular = in_array( $kind, array( 'internal', 'landing' ), true );
	$wp_query->is_archive  = false;
	$wp_query->is_search   = false;
	$wp_query->is_404      = false;
	$wp_query->is_feed     = false;

	if ( 'homepage' === $kind && $front_id > 0 ) {
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
 * Build a plan for a clock + simulated route, and install it as the request plan.
 *
 * @param string $clock ISO local time.
 * @param string $kind  Route kind.
 * @param int    $page  Optional page ID.
 * @return array
 */
function conexao_dispatch_test_plan( $clock, $kind, $page = 0 ) {
	conexao_dispatch_test_route( $kind, $page );

	$plan = conexao_commemorative_build_plan( new DateTimeImmutable( $clock, wp_timezone() ) );

	$GLOBALS['conexao_ce_plan'] = $plan;

	return $plan;
}

test_section( 'Engine and registry are wired' );

assert_true( function_exists( 'conexao_commemorative_active' ), 'the plugin public API is available' );
assert_true( function_exists( 'conexao_commemorative_packages' ), 'the theme registry is available' );

$packages = conexao_commemorative_packages();

assert_equals( 6, count( $packages ), 'the six package identities are registered' );
assert_true( isset( $packages['halloween'], $packages['christmas'] ), 'halloween and christmas are registered' );
assert_equals( 'implemented', $packages['halloween']['status'], 'halloween is an implemented package' );
assert_equals( 'implemented', $packages['christmas']['status'], 'christmas is an implemented package' );

foreach ( array( 'new_year', 'st_patricks_day', 'valentines_day', 'easter' ) as $planned ) {
	assert_equals( 'planned', $packages[ $planned ]['status'], $planned . ' is documented as a planned identity' );
	assert_equals( 0, count( $packages[ $planned ]['placements'] ), $planned . ' registers no placement yet' );
}


test_section( 'No active event: no plan, no markup, no assets' );

$plan = conexao_dispatch_test_plan( '2026-11-06 12:00:00', 'homepage' );

assert_equals( null, $plan['event'], 'the engine selects no event on 2026-11-06' );
assert_equals( null, $plan['package'], 'no package is resolved' );
assert_equals( 0, count( $plan['placements'] ), 'no placement is planned' );
assert_true( ! conexao_commemorative_has_dispatch(), 'the dispatcher reports nothing to render' );

$assets = conexao_commemorative_asset_plan( $plan );
assert_equals( 0, count( $assets['styles'] ), 'no stylesheet would be enqueued' );
assert_equals( 0, count( $assets['scripts'] ), 'no script would be enqueued' );
assert_true( ! $assets['shared'], 'the shared framework would not load either' );

foreach ( array( 'homepage_hero', 'site_wide_ambient', 'landing_page' ) as $placement_id ) {
	ob_start();
	$rendered = conexao_commemorative_render_placement( $placement_id );
	$markup   = (string) ob_get_clean();

	assert_true( ! $rendered, 'the ' . $placement_id . ' placement renders nothing with no active event' );
	assert_equals( '', $markup, 'the ' . $placement_id . ' placement outputs no bytes with no active event' );
}

test_section( 'Halloween: one package, two placements, route-specific' );

$plan = conexao_dispatch_test_plan( '2026-10-20 12:00:00', 'homepage' );

assert_true( null !== $plan['event'], 'the engine selects an event inside the Halloween window' );
assert_equals( 'halloween', $plan['event']->event_key, 'the selected event is halloween' );
assert_equals( 'halloween', $plan['event']->presentation, 'the event carries its package slug' );
assert_equals( 'halloween', $plan['package']['slug'], 'the selected package is halloween' );
assert_equals( 2026, $plan['event']->occurrence_year, 'the occurrence year is 2026' );
assert_equals( 'carved_pumpkin', $plan['package']['motif'], 'the package declares its signature motif' );
assert_true( '' !== $plan['event']->title, 'the localised title resolves from the record (no engine warnings)' );
assert_true( in_array( 'homepage_hero', array_keys( $plan['placements'] ), true ), 'the homepage hero is planned on the homepage' );
assert_true( in_array( 'site_wide_ambient', array_keys( $plan['placements'] ), true ), 'the ambient layer is planned on the homepage' );
assert_equals( 2, count( $plan['placements'] ), 'exactly the two Halloween placements are planned' );

$home_assets = conexao_commemorative_asset_plan( $plan );

assert_true( $home_assets['shared'], 'the shared framework loads for a rendered placement' );
assert_equals( 1, count( $home_assets['styles'] ), 'one package stylesheet is enqueued' );
assert_equals( 1, count( $home_assets['scripts'] ), 'one package script is enqueued' );
assert_true( isset( $home_assets['styles']['conexao-ce-css-presentations-halloween'] ), 'the Halloween stylesheet handle is conditional' );
assert_true( isset( $home_assets['scripts']['conexao-ce-js-presentations-halloween-halloween'] ), 'the Halloween script handle is conditional' );
assert_equals( 2, count( $home_assets['placements'] ), 'both placements appear in the asset plan' );

$plan = conexao_dispatch_test_plan( '2026-10-20 12:00:00', 'internal' );

assert_true( ! isset( $plan['placements']['homepage_hero'] ), 'the homepage hero is NEVER planned on an internal page' );
assert_true( isset( $plan['placements']['site_wide_ambient'] ), 'the ambient layer IS planned on an internal page' );
assert_equals( 1, count( $plan['placements'] ), 'only the ambient placement is planned on an internal page' );

$internal_assets = conexao_commemorative_asset_plan( $plan );

assert_true( ! isset( $internal_assets['placements']['homepage_hero'] ), 'no hero assets are enqueued on an internal page' );
assert_true( isset( $internal_assets['placements']['site_wide_ambient'] ), 'the ambient placement assets are enqueued' );

ob_start();
$hero_rendered = conexao_commemorative_render_placement( 'homepage_hero' );
$hero_markup   = (string) ob_get_clean();

ob_start();
$ambient_rendered = conexao_commemorative_render_placement( 'site_wide_ambient' );
$ambient_markup   = (string) ob_get_clean();

assert_true( ! $hero_rendered && '' === $hero_markup, 'the hero outputs nothing on an internal page' );
assert_true( $ambient_rendered, 'the ambient placement renders on an internal page' );
assert_contains( 'ce-placement--site_wide_ambient', $ambient_markup, 'the ambient markup is the shared shell' );
assert_contains( 'seasonal-halloween-active-marker', $ambient_markup, 'the package publishes its own client marker' );
assert_contains( 'decorations/witch-broom.svg', $ambient_markup, 'the package art moved into the package asset directory' );

$plan = conexao_dispatch_test_plan( '2026-10-20 12:00:00', 'homepage' );

ob_start();
$hero_rendered = conexao_commemorative_render_placement( 'homepage_hero' );
$hero_markup   = (string) ob_get_clean();

assert_true( $hero_rendered, 'the hero renders on the homepage' );
assert_contains( 'ce-placement--homepage_hero', $hero_markup, 'the hero uses the shared placement shell' );
assert_contains( 'data-ce-motif="carved_pumpkin"', $hero_markup, 'the shared shell exposes the package motif' );
assert_contains( '--ce-primary:#4b2163', $hero_markup, 'the design tokens are emitted as namespaced custom properties' );
assert_contains( 'seasonal-halloween-pumpkin', $hero_markup, 'the signature pumpkin motif is present' );
assert_contains( 'data-ce-reveal', $hero_markup, 'the hero carries the no-JS reveal hook' );
assert_contains( 'data-ce-dismiss', $hero_markup, 'the dismiss control is marked so no-JS can hide it' );
assert_contains( 'conexao-seasonal-halloween-2026', $hero_markup, 'the dismissal key is scoped to the occurrence year' );
assert_not_contains( 'ce-package--christmas', $hero_markup, 'no other package leaks into the hero' );

test_section( 'Christmas: the second package reuses the same dispatcher' );

$plan = conexao_dispatch_test_plan( '2026-12-15 12:00:00', 'homepage' );

assert_equals( 'christmas', $plan['event']->event_key, 'the engine selects christmas inside its window' );
assert_equals( 'christmas', $plan['package']['slug'], 'the christmas package resolves' );
assert_equals( 'bauble_ornament', $plan['package']['motif'], 'christmas declares a different signature motif' );
assert_equals( 'calm', $plan['package']['energy'], 'christmas declares a different energy level' );
assert_true( in_array( 'homepage_hero', array_keys( $plan['placements'] ), true ), 'christmas renders a homepage hero' );
assert_true( in_array( 'site_wide_ambient', array_keys( $plan['placements'] ), true ), 'christmas renders the ambient layer' );

$christmas_assets = conexao_commemorative_asset_plan( $plan );

assert_true( isset( $christmas_assets['styles']['conexao-ce-css-presentations-christmas'] ), 'the christmas stylesheet is enqueued' );
assert_true( ! isset( $christmas_assets['styles']['conexao-ce-css-presentations-halloween'] ), 'the halloween stylesheet is NOT enqueued' );

ob_start();
conexao_commemorative_render_placement( 'homepage_hero' );
$christmas_hero = (string) ob_get_clean();

assert_contains( 'ce-christmas-hero', $christmas_hero, 'the christmas hero renders its own component' );
assert_contains( 'data-ce-motif="bauble_ornament"', $christmas_hero, 'the christmas motif is exposed' );
assert_not_contains( 'seasonal-halloween', $christmas_hero, 'no Halloween markup leaks into the christmas hero' );
assert_not_contains( 'data-dismiss-key', $christmas_hero, 'the christmas hero is not a dismissible overlay' );

$plan = conexao_dispatch_test_plan( '2026-12-15 12:00:00', 'internal' );

assert_true( ! isset( $plan['placements']['homepage_hero'] ), 'the christmas hero is homepage-only' );
assert_true( isset( $plan['placements']['site_wide_ambient'] ), 'christmas ambient renders on internal pages' );

test_section( 'The landing page is independent of the active event (archival)' );

$landing_page = get_page_by_path( 'natal', OBJECT, 'page' );

test_require(
	$landing_page instanceof WP_Post,
	'seed-commemorative-landing-pages',
	'the PT landing page exists (scripts/seed-commemorative-landing-pages.php --apply)'
);

$landing_id = (int) $landing_page->ID;

assert_equals( 'christmas', conexao_commemorative_landing_page_package( $landing_id ), 'the page opts into the christmas package' );
assert_true( conexao_commemorative_is_landing_page( $landing_id ), 'the page is recognised as a commemorative landing page' );

// February: no event is window-eligible, yet the page must still render.
$plan = conexao_dispatch_test_plan( '2027-02-05 12:00:00', 'landing', $landing_id );

assert_true( null === $plan['event'] || 'christmas' !== $plan['event']->event_key, 'christmas is not the active event in February' );
assert_true( ! empty( $plan['landing'] ), 'the landing placement is resolved without an active event (archival)' );
assert_equals( 'christmas', $plan['landing']['package']['slug'], 'the landing page resolves its own package from the page opt-in' );
assert_true( ! isset( $plan['placements']['homepage_hero'] ), 'the landing route does not add the homepage hero' );

$landing_assets = conexao_commemorative_asset_plan( $plan );

assert_true( in_array( 'landing_page', array_keys( $landing_assets['placements'] ), true ), 'the landing placement owns its assets' );
assert_true( isset( $landing_assets['styles']['conexao-ce-css-presentations-christmas'] ), 'the landing page loads its package stylesheet' );

ob_start();
$landing_rendered = conexao_commemorative_render_placement( 'landing_page' );
$landing_markup   = (string) ob_get_clean();

assert_true( $landing_rendered, 'the landing placement renders in February (archival)' );
assert_contains( 'ce-module--agenda', $landing_markup, 'the landing page renders its real modules' );
assert_contains( 'ce-package--christmas', $landing_markup, 'the landing page uses its package identity' );
assert_true( substr_count( $landing_markup, 'ce-module--' ) >= 3, 'at least three modules are rendered (empty-page rule)' );

test_section( 'A page without the opt-in is not a landing page' );

$plain = get_page_by_path( 'documentos', OBJECT, 'page' );

if ( $plain instanceof WP_Post ) {
	assert_equals( '', conexao_commemorative_landing_page_package( (int) $plain->ID ), 'a normal page has no landing package' );

	$plan = conexao_dispatch_test_plan( '2026-12-15 12:00:00', 'landing', (int) $plain->ID );
	assert_true( empty( $plan['landing'] ), 'a normal page never resolves a landing placement' );

	ob_start();
	$rendered = conexao_commemorative_render_placement( 'landing_page' );
	$markup   = (string) ob_get_clean();

	assert_true( ! $rendered && '' === $markup, 'the landing placement outputs nothing on a normal page' );
}

test_section( 'No placement ever leaks from another package' );

$clocks = array(
	'2026-02-10 12:00:00',
	'2026-03-10 12:00:00',
	'2026-10-20 12:00:00',
	'2026-12-15 12:00:00',
	'2026-12-30 12:00:00',
);

foreach ( $clocks as $clock ) {
	$plan = conexao_dispatch_test_plan( $clock, 'homepage' );

	if ( null === $plan['package'] ) {
		assert_equals( 0, count( $plan['placements'] ), $clock . ': no package means no placement' );
		continue;
	}

	$allowed = array_keys( $plan['package']['placements'] );
	$planned = array_keys( $plan['placements'] );

	assert_equals( array(), array_values( array_diff( $planned, $allowed ) ), $clock . ': every planned placement belongs to the selected package' );

	$packages_in_plan = array();

	foreach ( $plan['placements'] as $placement ) {
		$packages_in_plan[] = $plan['package']['slug'];
		unset( $placement );
	}

	assert_equals( 1, count( array_unique( $packages_in_plan ) ), $clock . ': exactly one package renders' );
}

test_finish( 'commemorative-presentation-dispatch' );

