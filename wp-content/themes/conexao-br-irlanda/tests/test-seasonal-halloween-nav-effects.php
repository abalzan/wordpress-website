<?php
/**
 * Halloween navigation and spontaneous mobile effects regression suite.
 *
 * Verifies the server-side Halloween eligibility marker that gates the
 * client-side navigation click effects. The occasion engine remains the
 * sole authority: no date calculation is duplicated here.
 *
 * @package Conexao_BR_Irlanda
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once dirname( __DIR__ ) . '/inc/occasion.php';

test_title( 'Halloween navigation and spontaneous mobile effects' );

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
assert_true( $halloween->is_active( new DateTimeImmutable( '2026-10-20 12:00:00', wp_timezone() ) ), 'Halloween occasion reports itself active inside its window (injected clock)' );
assert_true( ! $halloween->is_active( new DateTimeImmutable( '2026-11-20 12:00:00', wp_timezone() ) ), 'Halloween occasion reports itself inactive outside its window (injected clock)' );
assert_true( $halloween->enabled, 'Halloween occasion reports itself enabled' );

test_section( 'Navigation continuity contract' );

$theme_root = dirname( __DIR__ );

/*
 * Stage 4 moved the Halloween presentation out of the global bundles (r10 §7.9).
 * These assertions are unchanged in what they verify; only the file each one
 * reads moved to the package home:
 *   assets/js/main.js            -> assets/js/presentations/halloween/halloween.js
 *   assets/css/main.css          -> assets/css/presentations/halloween.css
 *   assets/css/dark-mode.css     -> assets/css/presentations/halloween.css (dark block)
 *   header.php (marker)          -> template-parts/commemorative/halloween/ambient.php
 */
$nav_script    = file_get_contents( $theme_root . '/assets/js/presentations/halloween/halloween.js' );
$nav_styles    = file_get_contents( $theme_root . '/assets/css/presentations/halloween.css' );
$dark_styles   = $nav_styles;
$global_script = file_get_contents( $theme_root . '/assets/js/main.js' );
$global_style  = file_get_contents( $theme_root . '/assets/css/main.css' );
$global_dark   = file_get_contents( $theme_root . '/assets/css/dark-mode.css' );
$ambient_src   = file_get_contents( $theme_root . '/template-parts/commemorative/halloween/ambient.php' );
$header_src    = file_get_contents( $theme_root . '/header.php' );
$witch_asset   = file_get_contents( $theme_root . '/assets/images/presentations/halloween/decorations/witch-broom.svg' );

assert_true( is_string( $nav_script ), 'Halloween navigation script is readable' );
assert_true( is_string( $nav_styles ), 'Halloween navigation styles are readable' );
assert_true( is_string( $dark_styles ), 'Halloween dark-mode styles are readable' );
assert_true( is_string( $ambient_src ), 'The ambient placement template is readable' );
assert_true( is_string( $header_src ), 'Header source is readable' );
assert_true( is_string( $witch_asset ), 'The witch illustration is readable' );


assert_contains(
	"var TRANSITION_KEY = 'conexao-halloween-nav-effect-v1';",
	$nav_script,
	'Transition state uses a namespaced, versioned session key'
);
assert_contains(
	"var EFFECTS = ['bat', 'pumpkin', 'ghost', 'witch'];",
	$nav_script,
	'Transition state is constrained to the four known effects'
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
	'Existing navigation effects retain their 1,000 millisecond handoff duration'
);
assert_contains(
	'var WITCH_ANIMATION_DURATION_MS = 3000;',
	$nav_script,
	'The witch uses the requested 3,000 millisecond flight duration'
);
assert_contains(
	'halloween-nav-witch-flight 3s cubic-bezier',
	$nav_styles,
	'The CSS flight animation lasts three seconds with a smooth easing curve'
);
assert_contains(
	'translate3d(-110%, 3vh, 0)',
	$nav_styles,
	'The witch begins fully beyond the left viewport edge'
);
assert_contains(
	'calc(100vw + 110%)',
	$nav_styles,
	'The witch exits fully beyond the opposite viewport edge'
);
assert_contains(
	'seasonal-halloween-effect-layer',
	$nav_styles,
	'The animation uses the existing fixed, overflow-clipped decorative layer'
);
assert_contains(
	'color: var(--halloween-bat-color);',
	$nav_styles,
	'The flying bat follows the scoped theme-aware silhouette token'
);
assert_contains(
	'filter: drop-shadow(0 0 2px var(--halloween-bat-rim))',
	$nav_styles,
	'The navigation illustrations retain a visible rim in both themes'
);
assert_contains(
	'var(--halloween-pumpkin-highlight, #ffbf69)',
	$nav_script,
	'The navigation pumpkin inherits theme colors without JavaScript theme state'
);
if ( is_string( $dark_styles ) ) {
	assert_contains(
		':root[data-theme="dark"] .seasonal-halloween-effect-layer',
		$dark_styles,
		'In-flight navigation effects use the existing root theme attribute'
	);
	assert_contains(
		'--halloween-witch-glow: rgba(255, 174, 87, 0.9);',
		$dark_styles,
		'The witch receives theme-specific separation without changing its SVG'
	);
}
assert_contains(
	'pointer-events: none;',
	$nav_styles,
	'The decorative layer does not intercept interaction'
);
assert_contains(
	".seasonal-halloween-witch-nav {\n    display: none;",
	$nav_styles,
	'Reduced-motion users do not see a static substitute witch'
);
assert_contains(
	"return 'witch' === effectName ? WITCH_ANIMATION_DURATION_MS : ANIMATION_DURATION_MS;",
	$nav_script,
	'The handoff derives its total duration from the selected effect'
);
assert_contains(
	'Math.random() < 0.125',
	$nav_script,
	'The witch is selected as an uncommon one-in-eight surprise'
);
assert_contains(
	'if (effectInProgress || EFFECTS.indexOf(effectName) === -1 ||',
	$nav_script,
	'Only one Halloween effect can be active at a time'
);
assert_contains(
	'var activeLayer = null;',
	$nav_script,
	'The shared effect layer is tracked explicitly inside the strict-mode initializer'
);
assert_contains(
	'for (var s = 0; s < 4; s++)',
	$nav_script,
	'The witch trail is bounded to four sparkles'
);
assert_contains(
	'activeAnimationTimer = window.setTimeout(function() {',
	$nav_script,
	'Each effect layer stores a cleanup timer that can be cancelled safely'
);
assert_contains(
	'}, Math.max(0, animationDuration - elapsedMs));',
	$nav_script,
	'Each effect layer is removed after only its remaining animation duration'
);
assert_contains(
	'var STATE_EXPIRY_MS = 3500;',
	$nav_script,
	'Persisted state has a bounded 3,500 millisecond ceiling for the witch flight'
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
	'Math.max(0, animationDuration - elapsedMs)',
	$nav_script,
	'Destination cleanup uses only the remaining effect duration'
);
assert_contains(
	"element.style.animationDelay = '-' + elapsedMs + 'ms';",
	$nav_script,
	'Destination CSS animation resumes at the elapsed progress'
);
assert_contains(
	'transition.elapsedMs < getAnimationDuration(transition.effect)',
	$nav_script,
	'Expired effects are rejected using their selected animation duration'
);
assert_contains(
	'window.sessionStorage.removeItem(TRANSITION_KEY);',
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
	'seasonal-halloween-active-marker',
	$ambient_src,
	'The package marker is emitted by the site-wide ambient placement'
);
assert_contains(
	"'halloween' !== \$ce['package']['slug']",
	$ambient_src,
	'Only the Halloween package emits the navigation eligibility marker'
);
assert_contains(
	'data-witch-asset="<?php echo esc_url( get_template_directory_uri() . \'/assets/images/presentations/halloween/decorations/witch-broom.svg\' ); ?>"',
	$ambient_src,
	'The active marker exposes only the locally stored, escaped witch illustration URL'
);
assert_true(
	false === strpos( $header_src, 'halloween' ),
	'header.php no longer contains any Halloween identity (the dispatcher owns the marker)'
);
assert_true(
	file_exists( $theme_root . '/assets/images/presentations/halloween/decorations/witch-broom.svg' ),
	'The broom-riding witch illustration is stored in the package asset directory'
);
assert_not_contains(
	'seasonal-halloween',
	$global_script,
	'The global main.js bundle no longer ships the Halloween code'
);
assert_not_contains(
	'seasonal-halloween',
	$global_style,
	'The global main.css bundle no longer ships the Halloween styles'
);
assert_not_contains(
	'halloween',
	$global_dark,
	'The global dark-mode.css bundle no longer ships the Halloween tokens'
);
assert_contains(
	'seasonal-halloween-witch-nav flying',
	$nav_script,
	'The witch is added through the existing decorative effect layer'
);
assert_contains(
	'viewBox="0 0 420 230"',
	$witch_asset,
	'The local SVG has a compact, responsive illustration viewBox'
);
assert_contains(
	'<path d="m183 73 37-58-10 65"',
	$witch_asset,
	'The SVG includes the witch’s distinctive pointed hat'
);
assert_contains(
	'M92 174 365 190',
	$witch_asset,
	'The SVG includes a long broom handle and bristle silhouette'
);
assert_contains(
	'var witch = document.createElement(\'div\');',
	$nav_script,
	'The witch is created as temporary decorative markup rather than persistent page content'
);
assert_contains(
	'witchAsset',
	$nav_script,
	'The illustration source is read from the Halloween-gated marker'
);
assert_contains(
	"sessionStorage.setItem(dismissalKey, 'dismissed');",
	$nav_script,
	'Welcome-card dismissal remains independent from navigation-effect state'
);

test_section( 'Spontaneous mobile effect schedule and safety' );

assert_contains(
	"var mobileViewport = window.matchMedia ? window.matchMedia('(max-width: 768px)') : null;",
	$nav_script,
	'Spontaneous appearances are limited to the existing mobile breakpoint'
);
assert_contains(
	'var INITIAL_DELAY_MIN_MS = 20000;',
	$nav_script,
	'The first surprise waits at least twenty seconds'
);
assert_contains(
	'var INITIAL_DELAY_MAX_MS = 30000;',
	$nav_script,
	'The first surprise waits no longer than thirty seconds'
);
assert_contains(
	'var INTERVAL_MIN_MS = 25000;',
	$nav_script,
	'Subsequent surprises wait at least twenty-five seconds'
);
assert_contains(
	'var INTERVAL_MAX_MS = 35000;',
	$nav_script,
	'Subsequent surprises wait no longer than thirty-five seconds'
);
assert_contains(
	'var hasSpontaneousAppeared = false;',
	$nav_script,
	'The first-appearance delay remains in force until a surprise is actually shown'
);
assert_contains(
	'var minimum = hasSpontaneousAppeared ? INTERVAL_MIN_MS : INITIAL_DELAY_MIN_MS;',
	$nav_script,
	'Visibility and interface resumes preserve the initial delay until the first appearance'
);
assert_contains(
	'function getSpontaneousAnimationDuration(effectName)',
	$nav_script,
	'Spontaneous cleanup waits for each selected effect’s own animation duration'
);
assert_contains(
	'getSpontaneousAnimationDuration(effectName)',
	$nav_script,
	'Only spontaneous effects use their full visual duration'
);
assert_contains(
	'return 1100;',
	$nav_script,
	'The spontaneous pumpkin laugh is allowed to complete'
);
assert_contains(
	'return 1200;',
	$nav_script,
	'The spontaneous ghost float is allowed to complete'
);
assert_contains(
	'spontaneousTimer = window.setTimeout(appearSpontaneously, randomDelay(minimum, maximum));',
	$nav_script,
	'Only one stored appearance timer is scheduled with a bounded random delay'
);
assert_contains(
	'window.clearTimeout(spontaneousTimer);',
	$nav_script,
	'An existing appearance timer is cleared before a replacement is scheduled'
);
assert_contains(
	"marker.isConnected &&",
	$nav_script,
	'The existing selected-Halloween marker remains the live eligibility authority'
);
assert_contains(
	"'visible' === document.visibilityState",
	$nav_script,
	'The scheduler is eligible only while the page is visible'
);
assert_contains(
	'!prefersReducedMotion()',
	$nav_script,
	'Reduced-motion users never receive spontaneous motion'
);
assert_contains(
	'playEffect(effectName, placement.x, placement.y, 0, true);',
	$nav_script,
	'Spontaneous effects use the existing selector and one-effect playback guard'
);
assert_contains(
	'window.addEventListener(\'pagehide\', stopSpontaneous);',
	$nav_script,
	'Pending work is cleaned up when the page lifecycle ends'
);
assert_contains(
	'if (event.persisted) scheduleSpontaneous();',
	$nav_script,
	'Back-forward-cache restoration schedules a fresh delay instead of replaying state'
);
assert_contains(
	'padding:env(safe-area-inset-top, 0px) env(safe-area-inset-right, 0px) env(safe-area-inset-bottom, 0px) env(safe-area-inset-left, 0px);',
	$nav_script,
	'Safe-area insets are measured before selecting a placement'
);
assert_contains(
	'window.innerWidth',
	$nav_script,
	'Placement uses viewport width instead of document height or scroll position'
);
assert_contains(
	'height - insets.bottom - 12',
	$nav_script,
	'Placement respects the visible bottom edge and safe-area inset'
);
assert_contains(
	'var clearance = 8;',
	$nav_script,
	'Decorative placements keep a bounded margin from viewport edges and nearby content'
);
assert_contains(
	'viewport.left + size.width / 2 + clearance',
	$nav_script,
	'Corner candidates include their collision clearance inside the safe viewport'
);
assert_contains(
	'h1, h2, h3, h4, h5, h6, p, li, blockquote,',
	$nav_script,
	'Text-bearing content is excluded from spontaneous placements'
);
assert_contains(
	'.hero-badge, [class*="title"],',
	$nav_script,
	'Decorative placements also avoid important text in non-semantic content wrappers'
);
assert_contains(
	'input, select, \' +',
	$nav_script,
	'Form controls are excluded from spontaneous placements'
);
assert_contains(
	'if (!placement) {',
	$nav_script,
	'A dense viewport skips an unsafe effect rather than forcing a position'
);
assert_contains(
	'halloween-nav-bat-spontaneous-fly',
	$nav_styles,
	'The bat uses a deliberate, clipped horizontal flight path'
);
assert_contains(
	'halloween-nav-pumpkin-laugh 1.1s',
	$nav_styles,
	'The pumpkin animation retains its complete silent laugh'
);
assert_contains(
	'halloween-nav-ghost-float 1.2s',
	$nav_styles,
	'The ghost animation retains its complete float-away'
);
assert_contains(
	'width: 56px;',
	$nav_styles,
	'The spontaneous pumpkin remains large enough to recognize on a phone'
);
assert_contains(
	'height: 66px;',
	$nav_styles,
	'The spontaneous ghost remains recognizable on a phone'
);
assert_contains(
	'halloween-nav-witch-flight 3s cubic-bezier',
	$nav_styles,
	'The spontaneous witch continues to use the established three-second flight'
);
assert_contains(
	'var interruptedSpontaneous = activeEffectIsSpontaneous;',
	$nav_script,
	'Direct navigation interrupts a spontaneous appearance before its own effect'
);
assert_contains(
	'function handleSpontaneousUiChange(event)',
	$nav_script,
	'Closing the welcome greeting can resume scheduling without changing its dismissal key'
);

test_finish( 'seasonal-halloween-nav-effects' );