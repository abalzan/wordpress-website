<?php
$trace = json_decode( file_get_contents( '/var/www/html/tests/tmp/full-run-trace.json' ), true );
$by = array(); $host = array(); $dupes = 0; $seen = array();
foreach ( $trace as $u ) {
	$h = parse_url( $u, PHP_URL_HOST );
	$host[$h] = isset($host[$h]) ? $host[$h] + 1 : 1;
	// page-2 style duplicates are identical URLs; count repeats
	if ( isset( $seen[$u] ) ) { $dupes++; } else { $seen[$u] = true; }
}
echo "TOTAL=" . count($trace) . "\n";
echo "UNIQUE=" . count($seen) . "\n";
echo "DUPES=" . $dupes . "\n";
echo "BY_HOST=" . json_encode( $host ) . "\n";
