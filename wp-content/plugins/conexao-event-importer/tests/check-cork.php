<?php
require '/var/www/html/wp-load.php';

$q = new WP_Query(
	array(
		'post_type'      => 'event',
		'meta_query'     => array(
			array(
				'key'   => '_event_source',
				'value' => 'eventbrite_cork',
			),
		),
		'posts_per_page' => -1,
	)
);
echo 'eventbrite_cork events: ' . $q->post_count . "\n";
foreach ( $q->posts as $p ) {
	echo '  ' . get_the_title( $p->ID ) . ' | venue=' . get_post_meta( $p->ID, '_event_venue', true ) . "\n";
}
