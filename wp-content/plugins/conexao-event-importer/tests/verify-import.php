<?php
require '/var/www/html/wp-load.php';

$by_src     = array();
$with_county = 0;
$with_town   = 0;
$with_venue  = 0;
$with_addr   = 0;
$sample      = array();

$q = new WP_Query(
	array(
		'post_type'      => 'event',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	)
);

foreach ( $q->posts as $pid ) {
	$src            = get_post_meta( $pid, '_event_source', true );
	$by_src[ $src ] = ( $by_src[ $src ] ?? 0 ) + 1;
	if ( get_post_meta( $pid, '_event_county', true ) ) {
		$with_county++;
	}
	if ( get_post_meta( $pid, '_event_town', true ) ) {
		$with_town++;
	}
	if ( get_post_meta( $pid, '_event_venue', true ) ) {
		$with_venue++;
	}
	if ( get_post_meta( $pid, '_event_address', true ) ) {
		$with_addr++;
	}
}

echo 'Total events: ' . $q->post_count . "\n";
foreach ( $by_src as $k => $v ) {
	echo "  $k: $v\n";
}
echo "With county: $with_county, town: $with_town, venue: $with_venue, address: $with_addr\n";

// Sample Laois eventbrite_laois events to verify venue/address.
$laois = new WP_Query(
	array(
		'post_type'      => 'event',
		'posts_per_page' => 3,
		'meta_query'     => array(
			array(
				'key'   => '_event_source',
				'value' => 'eventbrite_laois',
			),
		),
	)
);
echo "\nSample eventbrite_laois events:\n";
foreach ( $laois->posts as $post ) {
	echo '  ' . get_the_title( $post->ID ) . "\n";
	echo '    county=' . get_post_meta( $post->ID, '_event_county', true ) . "\n";
	echo '    town=' . get_post_meta( $post->ID, '_event_town', true ) . "\n";
	echo '    venue=' . get_post_meta( $post->ID, '_event_venue', true ) . "\n";
	echo '    address=' . get_post_meta( $post->ID, '_event_address', true ) . "\n";
	echo '    date=' . get_post_meta( $post->ID, '_event_date', true ) . "\n";
	echo '    source_id=' . get_post_meta( $post->ID, '_event_source_id', true ) . "\n";
}
