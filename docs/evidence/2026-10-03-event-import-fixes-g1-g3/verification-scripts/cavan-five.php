<?php
require_once '/var/www/html/tests/bootstrap.php';
$urls = array(
 'cavan-plein-air-festival-tickets-1999210552580',
 'cornstarch-messy-play-ages-3-6-tickets-2001940046570',
 'cornstarch-messy-play-age-7-tickets-2001939782781',
 'evagarden-christmas-makeup-masterclass-tickets-2002739094544',
 'cavan-in-ireland-remembers',
);
foreach ( $urls as $frag ) {
	$q = new WP_Query( array( 'post_type'=>'event','posts_per_page'=>5,'fields'=>'ids','no_found_rows'=>true,
		'meta_query'=>array(array('key'=>'_event_url','value'=>$frag,'compare'=>'LIKE')) ) );
	foreach ( $q->posts as $pid ) {
		printf( "%-45s id=%-5s status=%-16s post_status=%s source=%s\n", $frag, $pid,
			get_post_meta($pid,'_event_status',true) ?: '(none)', get_post_status($pid), get_post_meta($pid,'_event_source',true) );
	}
	if ( empty($q->posts) ) { echo "$frag -> NOT FOUND IN DB\n"; }
}
