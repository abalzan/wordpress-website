<?php
/**
 * Stage C2 — Baseline capture (pre second-run snapshot).
 *
 * Captures the full local Event + Media state before the idempotency
 * re-run so that first-run vs second-run comparisons (identity stability,
 * unchanged-record hashing, media delta) are data-level, not memory.
 *
 * Usage: php c2-baseline.php [output-file]
 * Writes a JSON snapshot and prints a human summary to stdout.
 */
set_time_limit( 0 );
require '/var/www/html/wp-load.php';

$pilot_sources = array(
	'eventbrite_laois',
	'eventbrite_cork',
	'eventbrite_dublin',
	'heritage_week_laois',
	'heritage_week_cork',
	'heritage_week_dublin',
);

$out = empty( $argv[1] ) ? __DIR__ . '/c2-baseline.json' : $argv[1];

// ---------- Full event census ----------
$all = get_posts(
	array(
		'post_type'      => 'event',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'orderby'        => 'ID',
		'order'          => 'ASC',
		'fields'         => 'ids',
	)
);

$by_source = array();
$by_county = array();
$by_town   = array();
$no_county = array();
$no_town   = array();
$snapshot  = array();

foreach ( $all as $pid ) {
	$source = get_post_meta( $pid, '_event_source', true );
	$src_id = get_post_meta( $pid, '_event_source_id', true );
	$source = '' === $source ? '(empty)' : $source;
	$by_source[ $source ] = ( $by_source[ $source ] ?? 0 ) + 1;

	$county_terms = wp_get_object_terms( $pid, 'conexao_county', array( 'fields' => 'slugs' ) );
	$county       = ( $county_terms && ! is_wp_error( $county_terms ) ) ? implode( ',', $county_terms ) : '';
	$town_terms   = wp_get_object_terms( $pid, 'conexao_town', array( 'fields' => 'slugs' ) );
	$town         = ( $town_terms && ! is_wp_error( $town_terms ) ) ? implode( ',', $town_terms ) : '';

	if ( '' === $county ) {
		$no_county[] = $pid;
	} else {
		foreach ( explode( ',', $county ) as $c ) {
			$by_county[ $c ] = ( $by_county[ $c ] ?? 0 ) + 1;
		}
	}
	if ( '' === $town ) {
		$no_town[] = $pid;
	} else {
		foreach ( explode( ',', $town ) as $t ) {
			$by_town[ $t ] = ( $by_town[ $t ] ?? 0 ) + 1;
		}
	}

	$meta_keys = array( '_event_date', '_event_start_time', '_event_end_date', '_event_end_time', '_event_venue', '_event_address', '_event_url', '_event_source_url', '_event_banner', '_event_status', '_event_export_uuid' );
	$meta      = array();
	foreach ( $meta_keys as $k ) {
		$meta[ $k ] = (string) get_post_meta( $pid, $k, true );
	}

	$content = (string) get_post_field( 'post_content', $pid );
	$thumb   = (int) get_post_meta( $pid, Conexao_Event_Image_Handler::ATTACHMENT_META_KEY, true );

	$snapshot[] = array(
		'id'            => $pid,
		'status'        => get_post_status( $pid ),
		'source'        => $source,
		'source_id'     => $src_id,
		'title'         => get_the_title( $pid ),
		'county'        => $county,
		'town'          => $town,
		'meta'          => $meta,
		'content_len'   => strlen( $content ),
		'hash'          => md5( get_the_title( $pid ) . '|' . $content . '|' . $meta['_event_date'] . '|' . $meta['_event_start_time'] . '|' . $meta['_event_end_date'] . '|' . $meta['_event_end_time'] . '|' . $meta['_event_venue'] . '|' . $meta['_event_address'] . '|' . $meta['_event_url'] . '|' . $src_id ),
		'banner'        => $meta['_event_banner'],
		'attachment_id' => $thumb,
		'uuid'          => $meta['_event_export_uuid'],
	);
}

// ---------- Media census ----------
$att_total = (int) wp_count_posts( 'attachment' )->inherit;
$att_event = (int) ( new WP_Query(
	array(
		'post_type'       => 'attachment',
		'post_status'     => 'inherit',
		'post_parent__in' => $all,
		'posts_per_page'  => 1,
		'fields'          => 'ids',
		'no_found_rows'   => false,
	)
) )->found_posts;
$att_src_url = (int) ( new WP_Query(
	array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'meta_key'       => '_event_source_url',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'no_found_rows'  => false,
	)
) )->found_posts;
$att_max_id = 0;
foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 1, 'orderby' => 'ID', 'order' => 'DESC', 'fields' => 'ids' ) ) as $a ) {
	$att_max_id = (int) $a;
}

// ---------- Category census ----------
$cats = array();
foreach ( get_terms( array( 'taxonomy' => 'conexao_category', 'hide_empty' => false ) ) as $t ) {
	$cats[ $t->slug ] = array( 'name' => $t->name, 'count' => (int) $t->count );
}

// ---------- Town census (creation detection) ----------
$towns = array();
foreach ( get_terms( array( 'taxonomy' => 'conexao_town', 'hide_empty' => false ) ) as $t ) {
	$towns[ $t->slug ] = (int) $t->count;
}

// ---------- Pilot-only detail ----------
$pilot_detail = array();
foreach ( $snapshot as $e ) {
	if ( in_array( $e['source'], $pilot_sources, true ) ) {
		$pilot_detail[] = $e;
	}
}

$report = array(
	'captured_at'   => current_time( 'c' ),
	'total_events'  => count( $all ),
	'by_source'     => $by_source,
	'by_county'     => $by_county,
	'by_town'       => $by_town,
	'county_only'   => count( $all ) - count( $no_town ),
	'town_level'    => count( $all ) - count( $no_county ),
	'no_county_ids' => $no_county,
	'no_town_ids'   => $no_town,
	'media'         => array(
		'total_attachments'     => $att_total,
		'attached_to_events'    => $att_event,
		'with_event_source_url' => $att_src_url,
		'max_attachment_id'     => $att_max_id,
	),
	'categories'    => $cats,
	'towns'         => $towns,
	'pilot_count'   => count( $pilot_detail ),
	'pilot'         => $pilot_detail,
);

file_put_contents( $out, wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

echo "=== C2 BASELINE CAPTURED ===\n";
echo 'total_events=' . count( $all ) . "\n";
echo 'pilot_events=' . count( $pilot_detail ) . "\n";
echo "by_source:\n";
foreach ( $by_source as $s => $n ) {
	echo "  $s: $n\n";
}
echo "by_county:\n";
foreach ( $by_county as $s => $n ) {
	echo "  $s: $n\n";
}
echo 'no_county=' . count( $no_county ) . ' no_town=' . count( $no_town ) . "\n";
echo "media: total=$att_total attached_to_events=$att_event with_source_url=$att_src_url max_id=$att_max_id\n";
echo "towns_terms=" . count( $towns ) . " categories_terms=" . count( $cats ) . "\n";
echo "written=$out\n";

