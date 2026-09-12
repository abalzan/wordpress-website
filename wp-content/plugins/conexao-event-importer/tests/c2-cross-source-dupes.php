<?php
/**
 * Stage C2 — Cross-source duplicate review (data-level, read-only).
 *
 * Groups events by (normalized title, date) across DIFFERENT sources to
 * surface likely cross-provider overlaps. Mirrors the conservative
 * content-match rule of Conexao_Event_Deduplicator::find_by_content()
 * (exact sanitized title + exact date; time/venue/organizer compared
 * only when both sides carry them).
 *
 * Usage: php c2-cross-source-dupes.php
 */
set_time_limit( 0 );
require '/var/www/html/wp-load.php';

$pilot = array(
	'eventbrite_laois' => 'eb', 'eventbrite_cork' => 'eb', 'eventbrite_dublin' => 'eb',
	'heritage_week_laois' => 'hw', 'heritage_week_cork' => 'hw', 'heritage_week_dublin' => 'hw',
);
$existing = array( 'eventbrite', 'motorsport_ireland', 'ivvcc', 'mondellopark', 'laois_tourism' );

$all = get_posts(
	array( 'post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC' )
);

$groups = array();
foreach ( $all as $p ) {
	$source = (string) get_post_meta( $p->ID, '_event_source', true );
	$date   = (string) get_post_meta( $p->ID, '_event_date', true );
	$title  = sanitize_title( $p->post_title );
	if ( '' === $title || '' === $date ) {
		continue;
	}
	$groups[ $title . '|' . $date ][] = array(
		'id'       => $p->ID,
		'source'   => $source,
		'title'    => $p->post_title,
		'venue'    => (string) get_post_meta( $p->ID, '_event_venue', true ),
		'address'  => (string) get_post_meta( $p->ID, '_event_address', true ),
		'town'     => wp_get_object_terms( $p->ID, 'conexao_town', array( 'fields' => 'slugs' ) ),
		'time'     => (string) get_post_meta( $p->ID, '_event_start_time', true ),
		'organizer'=> (string) get_post_meta( $p->ID, '_event_organizer', true ),
		'url'      => (string) get_post_meta( $p->ID, '_event_url', true ),
	);

}

$cross = 0;
$same = 0;
foreach ( $groups as $key => $members ) {
	if ( count( $members ) < 2 ) {
		continue;
	}
	// Same-source groups = in-source duplicates (should not exist).
	$sources = array_unique( wp_list_pluck( $members, 'source' ) );
	if ( 1 === count( $sources ) ) {
		$same++;
		echo "[SAME-SOURCE GROUP] $key\n";
		foreach ( $members as $m ) {
			echo '   id=' . $m['id'] . ' source=' . $m['source'] . ' venue=' . $m['venue'] . ' url=' . $m['url'] . "\n";
		}
		continue;
	}
	$cross++;
	echo "[CROSS-SOURCE GROUP] $key\n";
	foreach ( $members as $m ) {
		$tag = isset( $pilot[ $m['source'] ] ) ? $pilot[ $m['source'] ] : ( in_array( $m['source'], $existing, true ) ? 'existing' : '?' );
		echo '   id=' . $m['id'] . ' src=' . $m['source'] . " ($tag) time=" . $m['time'] . ' venue=' . $m['venue'] . ' addr=' . $m['address'] . ' town=' . implode( '/', (array) $m['town'] ) . ' org=' . $m['organizer'] . "\n";
		echo '      url=' . $m['url'] . "\n";
	}
}
echo "\ncross_source_groups=$cross same_source_groups=$same\n";
