<?php
/**
 * Stage C2 — Fuzzy cross-source overlap review (read-only).
 *
 * Beyond the exact content-match rule, look for LIKELY overlaps:
 *  - same county + same date + same venue across different sources
 *  - same county + same date + near-identical titles (>= 80% similarity)
 * These are REVIEW candidates only — nothing is merged.
 */
set_time_limit( 0 );
require '/var/www/html/wp-load.php';

$all = get_posts( array( 'post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) );

$rows = array();
foreach ( $all as $p ) {
	$rows[] = array(
		'id' => $p->ID,
		'source' => (string) get_post_meta( $p->ID, '_event_source', true ),
		'title' => $p->post_title,
		'date' => (string) get_post_meta( $p->ID, '_event_date', true ),
		'venue' => strtolower( trim( (string) get_post_meta( $p->ID, '_event_venue', true ) ) ),
		'county' => wp_get_object_terms( $p->ID, 'conexao_county', array( 'fields' => 'slugs' ) ),
		'time' => (string) get_post_meta( $p->ID, '_event_start_time', true ),
	);
}

$venue_hits = 0;
$title_hits = 0;
$n = count( $rows );
for ( $i = 0; $i < $n; $i++ ) {
	for ( $j = $i + 1; $j < $n; $j++ ) {
		$a = $rows[ $i ];
		$b = $rows[ $j ];
		if ( '' === $a['date'] || $a['date'] !== $b['date'] || $a['source'] === $b['source'] ) {
			continue;
		}
		$ac = (array) $a['county'];
		$bc = (array) $b['county'];
		if ( $ac !== $bc || empty( $ac ) ) {
			continue;
		}
		$same_venue = '' !== $a['venue'] && $a['venue'] === $b['venue'];
		similar_text( strtolower( $a['title'] ), strtolower( $b['title'] ), $pct );
		if ( $same_venue ) {
			$venue_hits++;
			echo "[SAME VENUE+DATE, DIFF SOURCE] {$a['county'][0]} {$a['date']}\n"
				. "   A id={$a['id']} src={$a['source']} time={$a['time']} venue={$a['venue']}\n     {$a['title']}\n"
				. "   B id={$b['id']} src={$b['source']} time={$b['time']} venue={$b['venue']}\n     {$b['title']}\n";
		}
		if ( $pct >= 80 ) {
			$title_hits++;
			echo "[SIMILAR TITLE+DATE, DIFF SOURCE] {$a['county'][0]} {$a['date']} ({$pct}%)\n"
				. "   A id={$a['id']} src={$a['source']} venue={$a['venue']} time={$a['time']}\n     {$a['title']}\n"
				. "   B id={$b['id']} src={$b['source']} venue={$b['venue']} time={$b['time']}\n     {$b['title']}\n";
		}
	}
}
echo "\nvenue_overlap_candidates=$venue_hits title_overlap_candidates=$title_hits\n";
