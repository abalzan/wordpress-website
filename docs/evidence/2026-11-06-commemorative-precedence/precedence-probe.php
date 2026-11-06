<?php
/**
 * READ-ONLY in-memory demonstration of the annual-precedence rules.
 * Builds two synthetic eligible events (no WordPress records are created,
 * modified or deleted) and runs the engine's pure resolver over them.
 */
define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST']   = 'localhost:8080';
$_SERVER['REQUEST_URI'] = '/';
require '/var/www/html/wp-load.php';

$packages = Conexao_Commemorative_Resolver::packages();
$package  = ! empty( $packages ) ? (string) reset( $packages ) : '';
$tz       = new DateTimeZone( 'Europe/Dublin' );
$clock    = new DateTimeImmutable( '2026-12-15 12:00:00', $tz );

echo "engine: " . CONEXAO_COMMEMORATIVE_VERSION . " | packages registered: " . count( $packages ) . " (using '" . $package . "')\n";
echo "injected clock: " . $clock->format( 'c' ) . " (Europe/Dublin)\n";
echo "mutations: none (pure in-memory event arrays)\n\n";

function ce_probe_event( $key, array $over = array() ) {
	return array_merge(
		array(
			'id'              => 0,
			'event_key'       => $key,
			'title'           => $key,
			'status'          => 'publish',
			'enabled'         => true,
			'priority'        => 10,
			'presentation'    => '',
			'rule'            => 'window',
			'date'            => '',
			'variable'        => 'easter',
			'one_off_date'    => '',
			'window_start'    => '12-10',
			'window_end'      => '12-20',
			'lead_in_days'    => 0,
			'year_precedence' => array(),
			'description'     => '',
			'canonical_id'    => 0,
			'language'        => 'pt',
		),
		$over
	);
}

function ce_probe_resolve( array $events, $clock ) {
	$evaluated = array();
	foreach ( $events as $event ) {
		$evaluated[] = Conexao_Commemorative_Resolver::evaluate( $event, $clock );
	}
	$ranked = Conexao_Commemorative_Resolver::rank( $evaluated );
	return array( $evaluated, $ranked );
}

$cases = array(
	'1. no preference, equal priority (deterministic tie-break)' => array(
		ce_probe_event( 'alpha', array( 'presentation' => $package ) ),
		ce_probe_event( 'beta', array( 'presentation' => $package ) ),
		'alpha',
	),
	'2. beta preferred for 2026' => array(
		ce_probe_event( 'alpha', array( 'presentation' => $package ) ),
		ce_probe_event( 'beta', array( 'presentation' => $package, 'year_precedence' => array( '2026' => array( 'preferred' => true ) ) ) ),
		'beta',
	),
	'3. beta preferred for 2027 only (adjacent year unaffected)' => array(
		ce_probe_event( 'alpha', array( 'presentation' => $package ) ),
		ce_probe_event( 'beta', array( 'presentation' => $package, 'year_precedence' => array( '2027' => array( 'preferred' => true ) ) ) ),
		'alpha',
	),
	'4. beta preferred for 2026 but DISABLED' => array(
		ce_probe_event( 'alpha', array( 'presentation' => $package ) ),
		ce_probe_event( 'beta', array( 'presentation' => $package, 'enabled' => false, 'year_precedence' => array( '2026' => array( 'preferred' => true ) ) ) ),
		'alpha',
	),
	'5. beta preferred for 2026 but DRAFT' => array(
		ce_probe_event( 'alpha', array( 'presentation' => $package ) ),
		ce_probe_event( 'beta', array( 'presentation' => $package, 'status' => 'draft', 'year_precedence' => array( '2026' => array( 'preferred' => true ) ) ) ),
		'alpha',
	),
	'6. beta preferred for 2026 but OUT OF WINDOW (July window)' => array(
		ce_probe_event( 'alpha', array( 'presentation' => $package ) ),
		ce_probe_event( 'beta', array( 'presentation' => $package, 'window_start' => '07-01', 'window_end' => '07-10', 'year_precedence' => array( '2026' => array( 'preferred' => true ) ) ) ),
		'alpha',
	),
);

$exit = 0;

foreach ( $cases as $label => $case ) {
	
	$expected = $case[2];
	$events   = array( $case[0], $case[1] );

	list( $evaluated, $ranked ) = ce_probe_resolve( $events, $clock );
	$winner = ! empty( $ranked ) && $ranked[0]['eligible'] ? (string) $ranked[0]['event']['event_key'] : '(none)';

	$reasons = array();
	foreach ( $evaluated as $row ) {
		$reasons[] = $row['event']['event_key'] . '=' . $row['reason'] . ( $row['preferred'] ? ' (preferred)' : '' ) . ' year=' . var_export( $row['occurrence_year'], true );
	}

	$ok = ( $winner === $expected );
	if ( ! $ok ) {
		$exit = 1;
	}

	printf( "%s\n  %s\n  %s  expected=%s actual=%s\n", $label, implode( ' | ', $reasons ), $ok ? 'PASS' : 'FAIL', $expected, $winner );
}

echo "\nresult: " . ( 0 === $exit ? 'PASS' : 'FAIL' ) . "\n";
exit( $exit );
