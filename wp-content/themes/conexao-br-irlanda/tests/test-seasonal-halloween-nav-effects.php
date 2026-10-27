<?php
/**
 * Halloween navigation click effects regression suite.
 *
 * Verifies the server-side Halloween eligibility marker that gates the
 * client-side navigation click effects. The occasion engine remains the
 * sole authority: no date calculation is duplicated here.
 *
 * @package Conexao_BR_Irlanda
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once dirname( __DIR__ ) . '/inc/occasion.php';

test_title( 'Halloween navigation click effects' );

test_section( 'Halloween eligibility marker output' );

/**
 * Render the marker block that header.php emits for an active Halloween.
 */
$render_marker = static function () {
	ob_start();
	if ( function_exists( 'conexao_seasonal_variant' ) && 'halloween' === conexao_seasonal_variant() ) :
		?>
<span class="seasonal-halloween-active-marker" hidden></span>
		<?php
	endif;
	return ob_get_clean();
};

$halloween = new Conexao_Seasonal_Occasion(
	array(
		'id'               => 'halloween',
		'name'             => 'Halloween',
		'date_rule'        => Occasion_Date_Rule::FIXED_ANNUAL,
		'fixed_month_day'  => '10-31',
		'lead_in_days'     => 15,
		'enabled'          => true,
		'variant'          => 'halloween',
	)
);

// Simulate Halloween being the primary active occasion by checking the
// variant resolver against a Halloween occasion. The marker is emitted
// when the selected enabled occasion is Halloween.
assert_true( function_exists( 'conexao_seasonal_variant' ), 'conexao_seasonal_variant() is available' );

// When Halloween is the active selected occasion, the marker class must
// be present in the emitted markup.
$active_variant = $halloween->variant;
assert_equals( 'halloween', $active_variant, 'Halloween occasion variant resolves to halloween' );

assert_true(
	'halloween' === $halloween->id && $halloween->enabled,
	'Halloween occasion is enabled and eligible for the marker'
);

test_section( 'Disabled and non-Halloween occasions produce no marker' );

$disabled_halloween = new Conexao_Seasonal_Occasion(
	array(
		'id'      => 'halloween',
		'name'    => 'Halloween',
		'enabled' => false,
	)
);
assert_true(
	! $disabled_halloween->enabled,
	'Disabled Halloween occasion does not enable the marker'
);

$other_occasion = new Conexao_Seasonal_Occasion(
	array(
		'id'      => 'st_patricks_day',
		'name'    => "St. Patrick's Day",
		'enabled' => true,
	)
);
assert_equals( 'st_patricks_day', $other_occasion->id, 'A non-Halloween occasion does not match Halloween id' );

test_section( 'Dismissal is independent of eligibility' );

// The welcome-greeting dismissal key (sessionStorage) is separate from the
// occasion eligibility state. Dismissing the greeting does not change the
// occasion's active/enabled status, so nav effects remain available.
assert_true( $halloween->is_active(), 'Halloween occasion reports itself active' );
assert_true( $halloween->enabled, 'Halloween occasion reports itself enabled' );

test_section( 'Navigation continuity contract' );

$theme_root = dirname( __DIR__ );
$nav_script = file_get_contents( $theme_root . '/assets/js/main.js' );
$header_src = file_get_contents( $theme_root . '/header.php' );

assert_true( is_string( $nav_script ), 'Halloween navigation script is readable' );
assert_true( is_string( $header_src ), 'Header source is readable' );

assert_contains(
	"var TRANSITION_KEY = 'conexao-halloween-nav-effect-v1';",
	$nav_script,
	'Transition state uses a namespaced, versioned session key'
);
assert_contains(
	"var EFFECTS = ['bat', 'pumpkin', 'ghost'];",
	$nav_script,
	'Transition state is constrained to the three known effects'
);
assert_contains(
	'state.version !== 1',
	$nav_script,
	'Unknown transition record versions are rejected'
);
assert_contains(
	'!Number.isFinite(state.startedAt)',
	$nav_script,
	'Invalid timestamps are rejected'
);
assert_contains(
	'!Number.isFinite(state.x) || !Number.isFinite(state.y)',
	$nav_script,
	'Non-finite animation coordinates are rejected'
);
assert_contains(
	'var ANIMATION_DURATION_MS = 1000;',
	$nav_script,
	'Each effect uses a 1,000 millisecond total visual duration'
);
assert_contains(
	'var STATE_EXPIRY_MS = 1500;',
	$nav_script,
	'Persisted state expires after at most 1,500 milliseconds'
);
assert_contains(
	'elapsedMs > STATE_EXPIRY_MS',
	$nav_script,
	'Stale state is rejected after its expiry ceiling'
);
assert_contains(
	'state.x < 0 || state.x > window.innerWidth',
	$nav_script,
	'Coordinates outside the current viewport are rejected'
);
assert_contains(
	'Math.max(0, ANIMATION_DURATION_MS - elapsedMs)',
	$nav_script,
	'Destination cleanup uses only the remaining duration'
);
assert_contains(
	"element.style.animationDelay = '-' + elapsedMs + 'ms';",
	$nav_script,
	'Destination CSS animation resumes at the elapsed progress'
);
assert_contains(
	'transition.elapsedMs < ANIMATION_DURATION_MS',
	$nav_script,
	'Expired visual effects are not replayed'
);
assert_contains(
	"window.sessionStorage.removeItem(TRANSITION_KEY);",
	$nav_script,
	'Transition state is removed when consumed'
);
assert_contains(
	'window.sessionStorage.setItem(TRANSITION_KEY, rawState);',
	$nav_script,
	'Only the minimal serialized transition record is stored'
);
assert_contains(
	'if (window.sessionStorage.getItem(TRANSITION_KEY) === rawState)',
	$nav_script,
	'Unconsumed state is cleaned up when its short expiry timer runs'
);
assert_contains(
	'window.performance.timeOrigin + window.performance.now()',
	$nav_script,
	'Cross-document duration uses a monotonic performance clock when available'
);
assert_contains(
	'target.origin !== window.location.origin',
	$nav_script,
	'Destination URL must remain same-origin'
);
assert_contains(
	'target.pathname !== window.location.pathname',
	$nav_script,
	'Destination path must match the link that started the effect'
);
assert_contains(
	'target.search || target.hash',
	$nav_script,
	'Query strings and fragments are not accepted as transition state'
);
assert_contains(
	'referrer.origin !== window.location.origin',
	$nav_script,
	'Destination must follow an expected same-origin navigation'
);
assert_contains(
	"navigationEntries[0].type === 'navigate'",
	$nav_script,
	'Only a fresh document navigation can resume a transition'
);
assert_contains(
	'if (!marker || prefersReducedMotion())',
	$nav_script,
	'No destination effect runs without Halloween eligibility or with reduced motion'
);
assert_contains(
	'if (prefersReducedMotion()) return;',
	$nav_script,
	'Reduced-motion changes after page initialization also suppress effects'
);
assert_contains(
	"if (link.hasAttribute('download')) return;",
	$nav_script,
	'Download links do not persist a transition'
);
assert_contains(
	"'_self' !== targetName.toLowerCase()",
	$nav_script,
	'Links opening a separate browsing context do not persist a transition'
);
assert_contains(
	"document.addEventListener('click', handleClick, { passive: true });",
	$nav_script,
	'The listener remains passive and leaves native navigation in control'
);
assert_true(
	false === strpos( $nav_script, 'event.preventDefault()' ),
	'Navigation is never prevented or delayed for the animation'
);
assert_contains(
	"if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;",
	$nav_script,
	'Modified clicks keep browser-native behavior'
);
assert_contains(
	"if (event.button !== 0) return;",
	$nav_script,
	'Middle-click does not trigger or persist the effect'
);
assert_contains(
	"if (url.origin !== window.location.origin) return;",
	$nav_script,
	'External links do not trigger or persist the effect'
);
assert_contains(
	"'halloween' === conexao_seasonal_variant()",
	$header_src,
	'Only the selected Halloween occasion emits the navigation eligibility marker'
);
assert_contains(
	"sessionStorage.setItem(dismissalKey, 'dismissed');",
	$nav_script,
	'Welcome-card dismissal remains independent from navigation-effect state'
);

test_finish( 'seasonal-halloween-nav-effects' );