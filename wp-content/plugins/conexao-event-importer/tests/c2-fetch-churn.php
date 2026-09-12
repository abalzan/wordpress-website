<?php
/**
 * Stage C2 — Fetch-to-fetch churn probe (no DB writes).
 *
 * Fetches the same Eventbrite source twice and diffs the normalized
 * is_unchanged fields per source_id between the two fetches.
 *
 * Usage: php c2-fetch-churn.php <source_id>
 */
set_time_limit( 0 );
require '/var/www/html/wp-load.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$sid = $argv[1] ?? 'eventbrite_laois';
$sm  = new Conexao_Event_Sources();
$cfg = $sm->get( $sid );

$f = array();
for ( $i = 1; $i <= 2; $i++ ) {
	$handler = new Conexao_Source_Eventbrite( $cfg );
	$raws    = $handler->fetch_events();
	$norm    = array();
	foreach ( $raws as $r ) {
		$r['source'] = $cfg['id'];
		$n = ( new Conexao_Eventbrite_Normalizer() )->normalize( $r );
		$norm[ (string) $n['source_id'] ] = $n;
	}
	$f[ $i ] = $norm;
	if ( 1 === $i ) {
		sleep( 5 );
	}
}

echo 'fetch1=' . count( $f[1] ) . ' fetch2=' . count( $f[2] ) . "\n";
$churn = 0;
foreach ( $f[2] as $k => $n2 ) {
	if ( ! isset( $f[1][ $k ] ) ) { echo "MEMBERSHIP: $k only in fetch2\n"; continue; }
	$n1 = $f[1][ $k ];
	$diffs = array();
	foreach ( array( 'title', 'venue', 'organizer', 'banner', 'address', 'start_date', 'start_time', 'source_url' ) as $field ) {
		if ( (string) $n1[ $field ] !== (string) $n2[ $field ] ) {
			$diffs[] = $field . ': [' . $n1[ $field ] . '] -> [' . $n2[ $field ] . ']';
		}
	}
	if ( $diffs ) {
		$churn++;
		if ( $churn <= 8 ) {
			echo "CHURN $k " . $n2['title'] . "\n";
			foreach ( $diffs as $d ) { echo '   ' . $d . "\n"; }
		}
	}
}
echo "churned_events=$churn\n";
