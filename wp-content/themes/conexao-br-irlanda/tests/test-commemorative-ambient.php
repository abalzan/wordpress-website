<?php
/**
 * Commemorative ambient-layer suite (r10 §7.7, §7.8, §9.4).
 *
 * Proves the two halves of the ambient contract:
 *   - the SERVER half: the layer is decorative markup only (aria-hidden, no
 *     focusable node, no control, no audio) and publishes the resolved timing,
 *     cap and energy profile for its package;
 *   - the CLIENT half: the shared scheduler is the single timer, enforces the
 *     session cap, pauses on a hidden tab, never runs under reduced motion, and
 *     removes every node it creates.
 *
 * The scheduler's RUNTIME behaviour is executed by
 * `tests/node/commemorative-ambient.test.js` (see verify-commemorative-runtime.py);
 * this suite proves the contract the templates and the package scripts must meet.
 *
 * @package Conexao_BR_Irlanda
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

test_title( 'Commemorative ambient layer' );

$theme = dirname( __DIR__ );

/**
 * Build a plan and render the ambient placement on an internal route.
 *
 * @param string $clock ISO local time.
 * @return array{markup:string,config:array}
 */
function conexao_ambient_test_render( $clock ) {
	global $wp_query;

	$wp_query->is_home     = false;
	$wp_query->is_page     = true;
	$wp_query->is_singular = true;
	$wp_query->is_archive  = false;
	$wp_query->is_search   = false;
	$wp_query->is_404      = false;
	$wp_query->is_feed     = false;

	conexao_commemorative_flush_plan();

	$plan = conexao_commemorative_build_plan( new DateTimeImmutable( $clock, wp_timezone() ) );

	$GLOBALS['conexao_ce_plan'] = $plan;

	ob_start();
	conexao_commemorative_render_placement( 'site_wide_ambient' );
	$markup = (string) ob_get_clean();

	$config = array();

	if ( preg_match( '/data-ce-ambient-config="([^"]*)"/', $markup, $matches ) ) {
		$decoded = json_decode( html_entity_decode( $matches[1], ENT_QUOTES ), true );
		$config  = is_array( $decoded ) ? $decoded : array();
	}

	return array( 'markup' => $markup, 'config' => $config );
}

test_section( 'Halloween ambient markup is decorative only' );

$halloween = conexao_ambient_test_render( '2026-10-20 12:00:00' );

assert_true( '' !== $halloween['markup'], 'the ambient placement renders inside the Halloween window' );
assert_contains( 'aria-hidden="true"', $halloween['markup'], 'the layer is hidden from assistive technology' );
assert_contains( 'ce-placement--site_wide_ambient', $halloween['markup'], 'the layer uses the shared placement class' );
assert_not_contains( '<button', $halloween['markup'], 'the layer contains no control' );
assert_not_contains( '<a ', $halloween['markup'], 'the layer contains no link' );
assert_not_contains( '<audio', $halloween['markup'], 'the layer contains no audio' );
assert_not_contains( 'tabindex="0"', $halloween['markup'], 'the layer contains no focusable node' );

test_section( 'Halloween ambient config publishes the approved cadence and cap' );

assert_equals( 'playful', $halloween['config']['energy'], 'the playful energy profile is published' );
assert_equals( 20000, $halloween['config']['intervalMinMs'], 'the first surprise waits 20 s at the earliest' );
assert_equals( 30000, $halloween['config']['intervalMaxMs'], 'the first surprise waits 30 s at the latest' );
assert_equals( 25000, $halloween['config']['subsequentIntervalMinMs'], 'later surprises wait 25 s at the earliest' );
assert_equals( 35000, $halloween['config']['subsequentIntervalMaxMs'], 'later surprises wait 35 s at the latest' );
assert_equals( 900, $halloween['config']['durationMinMs'], 'the shortest effect lasts 0.9 s' );
assert_equals( 3000, $halloween['config']['durationMaxMs'], 'the longest effect lasts 3 s' );
assert_equals( 1, $halloween['config']['maxSimultaneous'], 'only one decoration may run at a time' );
assert_equals( 7, $halloween['config']['sessionCap'], 'the session cap is seven decorations' );
assert_equals( 2026, $halloween['config']['occurrenceYear'], 'the cap is scoped to the occurrence year' );

test_section( 'Christmas ambient config publishes the calm profile' );

$christmas = conexao_ambient_test_render( '2026-12-15 12:00:00' );

assert_true( '' !== $christmas['markup'], 'the ambient placement renders inside the Christmas window' );
assert_contains( 'aria-hidden="true"', $christmas['markup'], 'the Christmas layer is decorative too' );
assert_equals( 'calm', $christmas['config']['energy'], 'the calm energy profile is published' );
assert_equals( 60000, $christmas['config']['intervalMinMs'], 'a bauble appears every 60 s at the earliest' );
assert_equals( 120000, $christmas['config']['intervalMaxMs'], 'a bauble appears every 120 s at the latest' );
assert_equals( 2000, $christmas['config']['durationMinMs'], 'the bauble lasts 2 s at the shortest' );
assert_equals( 4000, $christmas['config']['durationMaxMs'], 'the bauble lasts 4 s at the longest' );
assert_equals( 3, $christmas['config']['sessionCap'], 'the calm session cap is three decorations' );
assert_not_contains( 'seasonal-halloween', $christmas['markup'], 'no Halloween marker leaks into the Christmas layer' );

test_section( 'The shared stylesheet enforces non-interaction' );

$shared_css = (string) file_get_contents( $theme . '/assets/css/commemorative.css' );

assert_contains( '.ce-placement--site_wide_ambient', $shared_css, 'the shared stylesheet scopes the ambient layer' );
assert_contains( 'pointer-events: none;', $shared_css, 'the layer never intercepts pointer events' );
assert_contains( 'position: fixed;', $shared_css, 'the layer cannot move content' );
assert_contains( 'overflow: hidden;', $shared_css, 'the layer cannot add a scrollbar' );
assert_contains( '.ce-ambient-decoration', $shared_css, 'every decoration uses one class' );
assert_contains( '@media (prefers-reduced-motion: reduce)', $shared_css, 'reduced motion is honoured in CSS as well' );

test_section( 'The shared scheduler owns timing, cap, visibility and cleanup' );

$shared_js = (string) file_get_contents( $theme . '/assets/js/commemorative.js' );

assert_contains( "'conexao-ce-ambient:'", $shared_js, 'the session key is namespaced by package and occurrence' );
assert_contains( 'storage.getItem(key)', $shared_js, 'the counter reads the tab-scoped session storage' );
assert_contains( 'storage.setItem(key, String(next))', $shared_js, 'the counter persists within the tab session' );
assert_contains( 'counter.read() < config.sessionCap', $shared_js, 'the cap is enforced before every scheduling decision' );
assert_contains( "'visible' !== visibility()", $shared_js, 'a hidden tab is not eligible' );
assert_contains( 'RESUME_COOLDOWN_MS', $shared_js, 'returning to the tab waits for a cooldown' );
assert_contains( 'if (reduced()) {', $shared_js, 'reduced motion stops the scheduler regardless of energy or cap' );
assert_contains( 'clearTimeout', $shared_js, 'a pending timer is always cleared before a new one' );
assert_contains( 'parentNode.removeChild(activeNode)', $shared_js, 'each decoration node is removed after its duration' );
assert_contains( "node.setAttribute('aria-hidden', 'true')", $shared_js, 'every decoration is hidden from assistive technology' );
assert_contains( "node.setAttribute('tabindex', '-1')", $shared_js, 'every decoration is removed from the tab order' );
assert_contains( "node.style.pointerEvents = 'none'", $shared_js, 'every decoration ignores pointer events' );
assert_contains( 'maxSimultaneous: 1', $shared_js, 'the scheduler normalises the simultaneous cap to one' );
assert_contains( 'if (!isEligible() || null !== activeNode)', $shared_js, 'a second decoration can never be shown while one is active' );

test_section( 'The Halloween package script preserves its approved behaviour' );

$halloween_js = (string) file_get_contents( $theme . '/assets/js/presentations/halloween/halloween.js' );

assert_contains( 'var INITIAL_DELAY_MIN_MS = 20000;', $halloween_js, 'the first surprise waits at least 20 s' );
assert_contains( 'var INITIAL_DELAY_MAX_MS = 30000;', $halloween_js, 'the first surprise waits no more than 30 s' );
assert_contains( 'var INTERVAL_MIN_MS = 25000;', $halloween_js, 'later surprises wait at least 25 s' );
assert_contains( 'var INTERVAL_MAX_MS = 35000;', $halloween_js, 'later surprises wait no more than 35 s' );
assert_contains( 'if (decorationCapReached()) return;', $halloween_js, 'the session cap is consulted before a decoration appears' );
assert_contains( 'decorationBudget().increment();', $halloween_js, 'each decoration is counted against the cap' );
assert_contains( 'var SESSION_CAP = 7;', $halloween_js, 'the playful cap is the package fallback' );

test_finish( 'commemorative-ambient' );

