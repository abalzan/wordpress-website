<?php
$j = json_decode( file_get_contents( '/home/andrei/IdeaProjects/wordpress-website/wp-content/plugins/conexao-event-importer/tests/fixtures/mp-rest.json' ), true );
$targets = array( 'james-deane-130-showdown', 'drift-games-winter-bash', 'iccr-september-2026', 'irx-october-2026', 'iccr-october-2026', 'irx-november-2026', 'fia-euro-rx' );
foreach ( $j as $item ) {
	if ( in_array( $item['slug'], $targets, true ) ) {
		echo $item['slug'] . ' | event_category=' . json_encode( $item['event_category'] ?? array() ) . PHP_EOL;
	}
}
// Full distribution of category arrays across all 29 events:
$multi = 0; $none = 0;
foreach ( $j as $item ) {
	$c = $item['event_category'] ?? array();
	if ( count( $c ) > 1 ) { $multi++; }
	if ( count( $c ) === 0 ) { $none++; }
}
echo "multi-category_events={$multi}, no-category_events={$none}, total=" . count( $j ) . PHP_EOL;

