<?php
/**
 * Halloween homepage presentation regression suite.
 *
 * Stage 4 moved the Halloween presentation onto the theme dispatcher: the template
 * no longer receives a `Conexao_Seasonal_Occasion`, it receives the package
 * context the dispatcher resolved from the ENGINE's selected event. Every
 * behavioural assertion of the original suite is kept; the three integration
 * assertions that encoded the old hard-coded `'halloween' ===` branch in
 * `front-page.php` are replaced by assertions on the generic contract, because
 * that branch is exactly what r10 §7.11 requires removing.
 *
 * @package Conexao_BR_Irlanda
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

test_title( 'Halloween homepage presentation' );
test_section( 'Selected event controls presentation output' );

/**
 * Build a plan for a clock and render one placement, returning its markup.
 *
 * @param string|null $clock ISO local time (null = the real clock).
 * @param string      $placement Placement id.
 * @return array{markup:string,plan:array}
 */
function conexao_halloween_presentation_render( $clock, $placement = 'homepage_hero' ) {
	global $wp_query;

	$front_id = (int) get_option( 'page_on_front' );

	$wp_query->is_home     = false;
	$wp_query->is_page     = true;
	$wp_query->is_singular = true;
	$wp_query->is_archive  = false;
	$wp_query->is_search   = false;
	$wp_query->is_404      = false;

	if ( $front_id > 0 ) {
		$wp_query->queried_object    = get_post( $front_id );
		$wp_query->queried_object_id = $front_id;
	}

	conexao_commemorative_flush_plan();

	$now  = null !== $clock ? new DateTimeImmutable( $clock, wp_timezone() ) : null;
	$plan = conexao_commemorative_build_plan( $now );

	$GLOBALS['conexao_ce_plan'] = $plan;

	ob_start();
	conexao_commemorative_render_placement( $placement );
	$markup = (string) ob_get_clean();

	return array( 'markup' => $markup, 'plan' => $plan );
}

$halloween = conexao_halloween_presentation_render( '2026-10-20 12:00:00' );
$markup    = $halloween['markup'];

assert_equals( 'halloween', $halloween['plan']['event']->event_key, 'the engine selects the Halloween event inside its window' );
assert_contains( 'seasonal-halloween-presentation', $markup, 'the selected Halloween event renders its presentation' );
assert_contains( 'hidden', $markup, 'the presentation stays hidden until the deferred enhancement initializes' );
assert_contains( 'seasonal-halloween-title', $markup, 'the presentation has a labelled semantic heading' );
assert_true(
	false !== strpos( $markup, 'Feliz Halloween!' ) || false !== strpos( $markup, 'Happy Halloween!' ),
	'the presentation renders the localized Halloween greeting'
);
assert_contains( 'type="button"', $markup, 'the dismiss control is a real button' );
assert_true(
	false !== strpos( $markup, 'Fechar mensagem de Halloween' ) || false !== strpos( $markup, 'Close Halloween message' ),
	'the dismiss control has a localized accessible name'
);
assert_contains( 'seasonal-halloween-bat-wing--left', $markup, 'bat artwork includes distinct articulated wings' );
assert_contains( 'seasonal-halloween-pumpkin', $markup, 'the greeting includes the signature pumpkin motif' );
assert_contains(
	'var(--halloween-pumpkin-highlight, #ffbf69)',
	$markup,
	'the inline pumpkin inherits the presentation theme palette with a readable fallback'
);
assert_contains( 'aria-hidden="true"', $markup, 'every decorative scene is hidden from assistive technology' );
assert_contains( 'data-ce-dismiss', $markup, 'the dismiss control is marked for the no-JS rule' );

$theme_root  = dirname( __DIR__ );
$light_style = file_get_contents( $theme_root . '/assets/css/presentations/halloween.css' );
$dark_style  = $light_style;

assert_true( is_string( $light_style ), 'Halloween light-mode styles are readable' );
assert_true( is_string( $dark_style ), 'Halloween dark-mode styles are readable' );
if ( is_string( $light_style ) && is_string( $dark_style ) ) {
	assert_contains( '--halloween-surface: #fff2dd;', $light_style, 'the welcome card has a warm light-mode surface' );
	assert_contains( '--halloween-text: #35243d;', $light_style, 'light-mode greeting text uses a deep plum token' );
	assert_contains( 'var(--halloween-surface-raised)', $light_style, 'the card uses its scoped semantic surface token' );
	assert_contains(
		':root[data-theme="dark"] .seasonal-halloween-presentation',
		$dark_style,
		'dark-mode palette follows the existing root theme attribute'
	);
	assert_contains( '--halloween-surface: #181522;', $dark_style, 'dark mode has its own cinematic card surface' );
	assert_contains( '--halloween-text-muted: #f0e5d4;', $dark_style, 'dark-mode supporting copy uses a high-contrast token' );
}

assert_contains( 'data-dismiss-key="conexao-seasonal-halloween-2026"', $markup, 'the dismissal key is namespaced by occasion and occurrence year' );

test_section( 'No selected event, and a different package, never render Halloween' );

$outside = conexao_halloween_presentation_render( '2026-11-06 12:00:00' );

assert_true( null === $outside['plan']['event'], 'no event is selected outside every window' );
assert_equals( '', trim( $outside['markup'] ), 'no active event renders no Halloween wrapper' );

$christmas = conexao_halloween_presentation_render( '2026-12-15 12:00:00' );

assert_equals( 'christmas', $christmas['plan']['package']['slug'], 'the Christmas window selects the christmas package' );
assert_not_contains( 'seasonal-halloween-presentation', $christmas['markup'], 'a different selected package renders no Halloween presentation' );
assert_contains( 'ce-christmas-hero', $christmas['markup'], 'the Christmas package renders its own component instead' );

test_section( 'Homepage integration is generic (no event identity in the template)' );

$front_page_path   = get_template_directory() . '/front-page.php';
$front_page_source = file_exists( $front_page_path ) ? file_get_contents( $front_page_path ) : false;

assert_true( is_string( $front_page_source ) && '' !== $front_page_source, 'front-page.php is readable for the integration check' );

if ( is_string( $front_page_source ) ) {
	assert_contains( 'conexao_commemorative_render_placement', $front_page_source, 'the homepage calls the generic dispatcher' );
	assert_contains( "'homepage_hero'", $front_page_source, 'the homepage asks for the homepage_hero placement' );
	assert_true( false === strpos( $front_page_source, 'halloween' ), 'the homepage template contains no holiday identity' );
	assert_true( false === strpos( $front_page_source, 'ocons_get_primary_active' ), 'the homepage no longer reads the legacy occasion engine directly' );
}

$header_source = file_get_contents( get_template_directory() . '/header.php' );

assert_true( false === strpos( (string) $header_source, 'halloween' ), 'header.php contains no holiday identity' );
assert_contains( 'wp_body_open', (string) $header_source, 'the header still fires the body-open hook that prints the generic marker' );

test_finish( 'seasonal-halloween-presentation' );

