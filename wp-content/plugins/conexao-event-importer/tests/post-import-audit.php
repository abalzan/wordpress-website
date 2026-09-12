<?php
/**
 * Stage C1 — post-import data audit (§11), duplicates (§12), media (§13).
 */
require '/var/www/html/wp-load.php';

$q = new WP_Query(
	array(
		'post_type'      => 'event',
		'posts_per_page' => -1,
		'post_status'    => 'any',
	)
);
echo "Total events: {$q->post_count}\n";

$by_src      = array();
$ids         = array();  // source|source_id -> post IDs
$urls        = array();  // url -> post IDs
$issues      = array();
$attach_ids  = array();
$pilot_events = array();

foreach ( $q->posts as $p ) {
	$src   = get_post_meta( $p->ID, '_event_source', true );
	$sid   = get_post_meta( $p->ID, '_event_source_id', true );
	$url   = get_post_meta( $p->ID, '_event_url', true );
	$date  = get_post_meta( $p->ID, '_event_date', true );
	$time  = get_post_meta( $p->ID, '_event_start_time', true );
	$addr  = get_post_meta( $p->ID, '_event_address', true );
	$banner= get_post_meta( $p->ID, '_event_banner', true );
	$title = get_the_title( $p->ID );
	$by_src[ $src ] = ( $by_src[ $src ] ?? 0 ) + 1;

	$is_pilot = preg_match( '/^(eventbrite|heritage_week)_(laois|cork|dublin)$/', $src );
	if ( $is_pilot ) {
		$pilot_events[] = $p->ID;
	}

	// §11: malformed data checks on pilot events.
	if ( $is_pilot ) {
		if ( empty( $title ) ) {
			$issues[] = "MALFORMED TITLE: post {$p->ID}";
		}
		if ( empty( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$issues[] = "INVALID DATE: post {$p->ID} [{$title}] date=[{$date}]";
		}
		if ( empty( $sid ) ) {
			$issues[] = "MISSING source_id: post {$p->ID} [{$title}]";
		}
		if ( empty( $url ) ) {
			$issues[] = "MISSING source_url: post {$p->ID} [{$title}]";
		}
		// Fabricated time check: time must be a valid H:i.
		if ( ! empty( $time ) && ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
			$issues[] = "INVALID TIME: post {$p->ID} [{$title}] time=[{$time}]";
		}
		// Fabricated address heuristic: addresses should reference the county's geography.
		// (Eircode or comma-separated; can't easily falsify, so just record format.)
		// Counties/towns are taxonomy-checked below.
		$counties = wp_get_object_terms( $p->ID, 'conexao_county', array( 'fields' => 'names' ) );
		$expected = preg_match( '/^eventbrite_(laois|cork|dublin)$/', $src ) ? ucfirst( preg_replace( '/^eventbrite_/', '', $src ) ) : null;
		if ( $expected && ( empty( $counties ) || ! in_array( $expected, $counties, true ) ) ) {
			$issues[] = "COUNTY MISMATCH: post {$p->ID} [{$title}] expected={$expected} got=" . implode( ',', $counties );
		}
	}

	// §12: duplicate source identity.
	if ( ! empty( $sid ) ) {
		$ids[ $src . '|' . $sid ][] = $p->ID;
	}
	// §12: duplicate URL.
	if ( ! empty( $url ) ) {
		$urls[ $url ][] = $p->ID;
	}

	// §13: image attachments.
	$attach = get_post_meta( $p->ID, Conexao_Event_Image_Handler::ATTACHMENT_META_KEY, true );
	if ( $attach && wp_attachment_is_image( $attach ) ) {
		$attach_ids[ $p->ID ] = $attach;
	}
}

echo "\nBy source:\n";
foreach ( $by_src as $k => $v ) {
	echo "  $k: $v\n";
}

// §12 duplicates.
echo "\n=== §12 Duplicate audit ===\n";
$dup_ids = 0;
foreach ( $ids as $key => $post_ids ) {
	if ( count( $post_ids ) > 1 ) {
		$dup_ids++;
		echo "  DUP source identity {$key}: posts " . implode( ',', $post_ids ) . "\n";
	}
}
echo "Duplicate source identities: {$dup_ids}\n";
$dup_urls = 0;
foreach ( $urls as $key => $post_ids ) {
	if ( count( $post_ids ) > 1 ) {
		$dup_urls++;
		echo "  DUP URL {$key}: posts " . implode( ',', $post_ids ) . " sources=" . implode( ',', array_map( 'get_post_meta_сцр1', array() ) ) . "\n";
	}
}
echo "Duplicate URLs: {$dup_urls}\n";

// Cross-source: same Eventbrite URL under both legacy and county source.
echo "\n=== Cross-source (legacy eventbrite vs eventbrite_laois) ===\n";
$cross = 0;
foreach ( $urls as $key => $post_ids ) {
	if ( count( $post_ids ) > 1 ) {
		$srcs = array();
		foreach ( $post_ids as $pid ) {
			$srcs[] = get_post_meta( $pid, '_event_source', true );
		}
		$srcs = array_unique( $srcs );
		if ( count( $srcs ) > 1 ) {
			$cross++;
			echo "  CROSS {$key}: posts " . implode( ',', $post_ids ) . " (sources: " . implode( ',', $srcs ) . ")\n";
		}
	}
}
echo "Cross-source URL duplicates: {$cross}\n";

// §11 validation issues.
echo "\n=== §11 Data validation ===\n";
if ( empty( $issues ) ) {
	echo "  No validation issues found.\n";
} else {
	foreach ( $issues as $i ) {
		echo "  {$i}\n";
	}
}

// §13 media audit.
echo "\n=== §13 Media audit ===\n";
echo "Pilot events: " . count( $pilot_events ) . "\n";
echo "Pilot events with local image attachments: " . count( array_intersect_key( $attach_ids, array_flip( $pilot_events ) ) ) . "\n";
echo "All events with local image attachments: " . count( $attach_ids ) . "\n";
// Check for provider logos / unexpected media.
$logo_like = 0;
$sizes     = array();
foreach ( $attach_ids as $pid => $aid ) {
	$file = get_attached_file( $aid );
	if ( $file && file_exists( $file ) ) {
		$size = filesize( $file );
		$sizes[ $pid ] = $size;
		$basename = strtolower( basename( $file ) );
		if ( strpos( $basename, 'logo' ) !== false || strpos( $basename, 'placeholder' ) !== false || strpos( $basename, 'default' ) !== false ) {
			$logo_like++;
			echo "  LOGO/PLACEHOLDER-LIKE attachment on post {$pid}: {$basename}\n";
		}
	}
}
echo "Logo/placeholder-like attachments: {$logo_like}\n";
$over_5mb = 0;
foreach ( $sizes as $size ) {
	if ( $size > 5 * 1024 * 1024 ) {
		$over_5mb++;
	}
}
echo "Attachments over 5MB: {$over_5mb}\n";
