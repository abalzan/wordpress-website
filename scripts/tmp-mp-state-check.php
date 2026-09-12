<?php
/**
 * LOCAL-ONLY reset: delete all local events with _event_source=mondellopark.
 */
define( 'WP_USE_THEMES', false );
require '/var/www/html/wp-load.php';

$q = new WP_Query( array(
	'post_type'      => 'event',
	'post_status'    => 'any',
	'posts_per_page' => 100,
	'meta_query'     => array( array( 'key' => '_event_source', 'value' => 'mondellopark' ) ),
) );
$deleted = 0;
foreach ( $q->posts as $p ) {
	if ( wp_delete_post( $p->ID, true ) ) {
		$deleted++;
		echo "deleted #{$p->ID} {$p->post_name}" . PHP_EOL;
	}
}
echo "deleted_total={$deleted}" . PHP_EOL;
$check = new WP_Query( array(
	'post_type'      => 'event',
	'post_status'    => 'any',
	'posts_per_page' => 10,
	'meta_query'     => array( array( 'key' => '_event_source', 'value' => 'mondellopark' ) ),
) );
echo 'mondello_remaining=' . count( $check->posts ) . PHP_EOL;








