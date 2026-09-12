<?php
/**
 * Stage C2 — Live API title byte probe (no DB writes, no logs).
 *
 * Fetches the source, normalizes, and prints the byte-exact titles.
 * Usage: php c2-title-probe.php <source_id> [count]
 */
set_time_limit( 0 );
require '/var/www/html/wp-load.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$sid = $argv[1] ?? 'eventbrite_laois';
$count = (int) ( $argv[2] ?? 8 );

$sm  = new Conexao_Event_Sources();
$cfg = $sm->get( $sid );

// Compare against stored DB titles for the same source_ids.
$stored = array();
$posts = get_posts( array( 'post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => -1, 'meta_key' => '_event_source', 'meta_value' => $cfg['id'], 'number' => 500 ) );
foreach ( $posts as $p ) {
	$stored[ (string) get_post_meta( $p->ID, '_event_source_id', true ) ] = array( 'id' => $p->ID, 'title' => $p->post_title );
}

$handler = new Conexao_Source_Eventbrite( $cfg );
$raws    = $handler->fetch_events();
echo 'fetched=' . count( $raws ) . "\n";

$shown = 0;
$diffs = 0;
foreach ( $raws as $r ) {
	if ( $shown >= $count ) { break; }
	$r['source'] = $cfg['id'];
	$n = ( new Conexao_Eventbrite_Normalizer() )->normalize( $r );
	$k = (string) $n['source_id'];
	if ( ! isset( $stored[ $k ] ) ) { continue; }
	$st = $stored[ $k ]['title'];
	$in = (string) $n['title'];
	$eq = ( $st === $in );
	if ( ! $eq ) { $diffs++; }
	if ( ! $eq || $shown < $count ) {
		echo ( $eq ? '  EQ ' : '**DIFF' ) . ' post=' . $stored[ $k ]['id'] . "\n"
			. '     db =' . bin2hex( $st ) . "\n"
			. '     api=' . bin2hex( $in ) . "\n"
			. '     db =[' . $st . "]\n"
			. '     api=[' . $in . "]\n";
		$shown++;
	}
}
echo "diffs_shown=$diffs\n";
