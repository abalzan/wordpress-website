<?php
/**
 * Commemorative experience-package registry suite (r10 §7.4–§7.7, §7.12).
 *
 * Proves the package CONTRACT rather than any single package:
 *   - every package is validated before use, and validation fails closed;
 *   - an implemented package carries the seven §7.12 brief fields;
 *   - the four public design tokens exist, are valid colours, and agree with the
 *     package stylesheet (no drift between registry and CSS);
 *   - two packages are distinguishable at a glance (motif + palette);
 *   - the energy table matches r10 exactly, and a package may narrow it;
 *   - hard bounds (one simultaneous decoration, no inverted range) always hold.
 *
 * @package Conexao_BR_Irlanda
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

test_title( 'Commemorative experience packages' );

test_section( 'Every registered package validates' );

$packages = conexao_commemorative_packages();

assert_equals( 6, count( $packages ), 'six packages validate' );

foreach ( $packages as $slug => $package ) {
	assert_equals( $slug, $package['slug'], $slug . ': the slug round-trips' );
	assert_true( '' !== $package['label'], $slug . ': a human label exists' );
	assert_true( in_array( $package['status'], array( 'implemented', 'planned' ), true ), $slug . ': the status is implemented or planned' );
	assert_true( in_array( $package['energy'], array( 'calm', 'moderate', 'playful' ), true ), $slug . ': the energy is one of the three levels' );
	assert_true( '' !== $package['motif'], $slug . ': a signature motif is declared' );
}

test_section( 'Implemented packages carry the full §7.12 brief' );

foreach ( array( 'halloween', 'christmas' ) as $slug ) {
	$package = $packages[ $slug ];

	assert_equals( 4, count( $package['tokens'] ), $slug . ': the four public tokens are present' );

	foreach ( array( 'primary', 'secondary', 'accent', 'surface' ) as $token ) {
		assert_true( isset( $package['tokens'][ $token ] ), $slug . ': token ' . $token . ' is declared' );
		assert_true( 1 === preg_match( '/^#[0-9a-f]{6}$/i', (string) $package['tokens'][ $token ] ), $slug . ': token ' . $token . ' is a hex colour' );
	}

	assert_true( isset( $package['motion']['duration_ms'] ), $slug . ': the motion language states a duration' );
	assert_true( '' !== (string) $package['motion']['easing'], $slug . ': the motion language states an easing' );
	assert_true( '' !== (string) $package['motion']['entry'], $slug . ': the motion language states an entry' );
	assert_true( '' !== (string) $package['motion']['exit'], $slug . ': the motion language states an exit' );

	assert_true( '' !== (string) $package['voice']['tone'], $slug . ': the copy voice states a tone' );
	assert_true( isset( $package['voice']['example_pt']['headline'], $package['voice']['example_en']['headline'] ), $slug . ': PT and EN headline examples exist' );
	assert_true( isset( $package['voice']['example_pt']['cta'], $package['voice']['example_en']['cta'] ), $slug . ': PT and EN CTA examples exist' );

	assert_true( count( $package['do_not'] ) >= 3, $slug . ': the do-not list has at least three entries' );
	assert_true( count( $package['placements'] ) >= 2, $slug . ': the package declares at least two placements' );
}

test_section( 'Registry tokens agree with the package stylesheet' );

foreach ( array( 'halloween', 'christmas' ) as $slug ) {
	$package = $packages[ $slug ];
	$css     = (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/presentations/' . $slug . '.css' );

	assert_true( '' !== $css, $slug . ': the package stylesheet exists' );

	foreach ( $package['tokens'] as $token => $value ) {
		assert_contains(
			'--ce-' . $token . ': ' . $value . ';',
			$css,
			$slug . ': the stylesheet mirrors --ce-' . $token . ' from the registry'
		);
	}
}

test_section( 'Two packages are distinguishable at a glance' );

assert_true( $packages['halloween']['motif'] !== $packages['christmas']['motif'], 'the signature motifs differ' );
assert_true( $packages['halloween']['tokens']['primary'] !== $packages['christmas']['tokens']['primary'], 'the primary colours differ' );
assert_true( $packages['halloween']['tokens']['secondary'] !== $packages['christmas']['tokens']['secondary'], 'the secondary colours differ' );
assert_true( $packages['halloween']['tokens']['accent'] !== $packages['christmas']['tokens']['accent'], 'the accent colours differ' );
assert_true( $packages['halloween']['energy'] !== $packages['christmas']['energy'], 'the energy levels differ' );
assert_true( $packages['halloween']['motion'] !== $packages['christmas']['motion'], 'the motion languages differ' );

test_section( 'The energy table matches r10 §7.7 exactly' );

$table = conexao_commemorative_energy_table();

assert_equals( 60000, $table['calm']['interval_min_ms'], 'calm starts at 60 s' );
assert_equals( 120000, $table['calm']['interval_max_ms'], 'calm ends at 120 s' );
assert_equals( 2000, $table['calm']['duration_min_ms'], 'calm lasts 2 s' );
assert_equals( 4000, $table['calm']['duration_max_ms'], 'calm lasts at most 4 s' );
assert_equals( 3, $table['calm']['session_cap'], 'calm caps at 3 decorations' );

assert_equals( 45000, $table['moderate']['interval_min_ms'], 'moderate starts at 45 s' );
assert_equals( 90000, $table['moderate']['interval_max_ms'], 'moderate ends at 90 s' );
assert_equals( 3000, $table['moderate']['duration_min_ms'], 'moderate lasts 3 s' );
assert_equals( 6000, $table['moderate']['duration_max_ms'], 'moderate lasts at most 6 s' );
assert_equals( 5, $table['moderate']['session_cap'], 'moderate caps at 5 decorations' );

assert_equals( 30000, $table['playful']['interval_min_ms'], 'playful starts at 30 s' );
assert_equals( 60000, $table['playful']['interval_max_ms'], 'playful ends at 60 s' );
assert_equals( 3000, $table['playful']['duration_min_ms'], 'playful lasts 3 s' );
assert_equals( 5000, $table['playful']['duration_max_ms'], 'playful lasts at most 5 s' );
assert_equals( 7, $table['playful']['session_cap'], 'playful caps at 7 decorations' );

foreach ( $table as $energy => $row ) {
	assert_equals( 1, $row['max_simultaneous'], $energy . ': only one decoration may run at a time' );
}



test_section( 'Ambient options: approved cadence override and energy defaults' );

$halloween_ambient = conexao_commemorative_ambient_options( $packages['halloween']['placements']['site_wide_ambient'] );

assert_equals( 'playful', $halloween_ambient['energy'], 'Halloween keeps the playful energy row' );
assert_equals( 20000, $halloween_ambient['interval_min_ms'], 'the first mobile surprise waits at least 20 s' );
assert_equals( 30000, $halloween_ambient['interval_max_ms'], 'the first mobile surprise waits no more than 30 s' );
assert_equals( 25000, $halloween_ambient['subsequent_interval_min_ms'], 'later surprises wait at least 25 s' );
assert_equals( 35000, $halloween_ambient['subsequent_interval_max_ms'], 'later surprises wait no more than 35 s' );
assert_equals( 900, $halloween_ambient['duration_min_ms'], 'the bat effect lasts 0.9 s' );
assert_equals( 3000, $halloween_ambient['duration_max_ms'], 'the witch effect lasts 3 s' );
assert_equals( 1, $halloween_ambient['max_simultaneous'], 'only one Halloween decoration at a time' );
assert_equals( 7, $halloween_ambient['session_cap'], 'the playful session cap is 7 decorations' );

$christmas_ambient = conexao_commemorative_ambient_options( $packages['christmas']['placements']['site_wide_ambient'] );

assert_equals( 'calm', $christmas_ambient['energy'], 'Christmas uses the calm energy row' );
assert_equals( 60000, $christmas_ambient['interval_min_ms'], 'a Christmas star appears every 60 s at the earliest' );
assert_equals( 120000, $christmas_ambient['interval_max_ms'], 'a Christmas star appears every 120 s at the latest' );
assert_equals( 2000, $christmas_ambient['duration_min_ms'], 'Christmas decorations last 2–4 s' );
assert_equals( 4000, $christmas_ambient['duration_max_ms'], 'Christmas decorations last 2–4 s' );
assert_equals( 3, $christmas_ambient['session_cap'], 'the calm session cap is 3 decorations' );

// Halloween's cadence must never be silently applied to another package.
assert_true(
	$christmas_ambient['interval_min_ms'] !== $halloween_ambient['interval_min_ms'],
	'the approved Halloween cadence does not leak into the generic energy defaults'
);

test_section( 'Hard bounds always hold, whatever a package declares' );

$hostile = conexao_commemorative_ambient_options(
	array(
		'energy'  => 'playful',
		'options' => array(
			'interval_min_ms'  => 90000,
			'interval_max_ms'  => 10000,
			'duration_min_ms'  => 9000,
			'duration_max_ms'  => 100,
			'max_simultaneous' => 12,
			'session_cap'      => 0,
		),
	)
);

assert_true( $hostile['interval_max_ms'] >= $hostile['interval_min_ms'], 'an inverted interval range is repaired' );
assert_true( $hostile['duration_max_ms'] >= $hostile['duration_min_ms'], 'an inverted duration range is repaired' );
assert_equals( 1, $hostile['max_simultaneous'], 'a package can never ask for concurrent decorations' );
assert_equals( 1, $hostile['session_cap'], 'a zero cap is raised to one rather than disabling the barrier' );

$unknown_energy = conexao_commemorative_ambient_options( array( 'energy' => 'chaotic' ) );

assert_equals( 'calm', $unknown_energy['energy'], 'an unknown energy level falls back to calm' );
assert_equals( 60000, $unknown_energy['interval_min_ms'], 'the calm default applies to an unknown energy level' );

test_section( 'Validation fails closed' );

assert_equals( null, conexao_commemorative_validate_package( 'ghost', 'not-an-array' ), 'a non-array definition is rejected' );
assert_equals( null, conexao_commemorative_validate_package( '', array() ), 'an empty slug is rejected' );
assert_equals( null, conexao_commemorative_package( 'nope' ), 'an unknown package resolves to null' );

$base = array(
	'label'      => 'Fixture',
	'design'     => array(
		'motif'  => 'dot',
		'energy' => 'calm',
		'tokens' => array( 'primary' => 'not-a-colour' ),
	),
	'placements' => array(
		'homepage_hero'          => array(
			'template' => 'template-parts/commemorative/homepage-hero',
			'content'  => 'template-parts/commemorative/halloween/hero',
		),
		'not_a_known_placement'  => array(
			'template' => 'template-parts/commemorative/homepage-hero',
			'content'  => 'template-parts/commemorative/halloween/hero',
		),
		'site_wide_ambient'      => array(
			'template' => 'template-parts/commemorative/does-not-exist',
			'content'  => 'template-parts/commemorative/halloween/ambient',
		),
		'landing_page'           => array(
			'template' => 'template-parts/commemorative/landing-page',
			'content'  => 'template-parts/commemorative/does-not-exist',
		),
	),
);

$validated = conexao_commemorative_validate_package( 'fixture', $base );

assert_true( null !== $validated, 'a partially valid package still validates' );
assert_equals( array( 'homepage_hero' ), array_keys( $validated['placements'] ), 'unknown placements, missing templates and missing content templates are dropped' );
assert_equals( array(), $validated['tokens'], 'an invalid token value is dropped rather than emitted' );

$with_missing_asset               = $base;
$with_missing_asset['placements'] = array(
	'homepage_hero' => array(
		'template' => 'template-parts/commemorative/homepage-hero',
		'content'  => 'template-parts/commemorative/halloween/hero',
		'styles'   => array( 'assets/css/presentations/does-not-exist.css', 'assets/css/presentations/christmas.css' ),
		'scripts'  => array( 'assets/js/presentations/does-not-exist.js' ),
	),
);

$validated = conexao_commemorative_validate_package( 'fixture', $with_missing_asset );

assert_equals(
	array( 'assets/css/presentations/christmas.css' ),
	$validated['placements']['homepage_hero']['styles'],
	'a missing stylesheet is dropped, an existing one is kept'
);
assert_equals( array(), $validated['placements']['homepage_hero']['scripts'], 'a missing script is dropped' );

test_finish( 'commemorative-packages' );

