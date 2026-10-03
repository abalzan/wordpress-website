<?php
require_once '/var/www/html/tests/bootstrap.php';
$q = new WP_Query( array( 'post_type'=>'event','posts_per_page'=>50,'fields'=>'ids','no_found_rows'=>true,
  'meta_query'=>array('relation'=>'AND',
    array('key'=>'_event_source','value'=>'eventbrite_cavan'),
    array('key'=>'_event_status','value'=>'source_not_found')) ) );
echo "CAVAN_SOURCE_NOT_FOUND=" . count($q->posts) . "\n";
foreach ( $q->posts as $pid ) { echo "  - " . get_the_title($pid) . " (" . $pid . ")\n"; }

$q2 = new WP_Query( array( 'post_type'=>'event','posts_per_page'=>50,'fields'=>'ids','no_found_rows'=>true,
  'meta_query'=>array('relation'=>'AND',
    array('key'=>'_event_source','value'=>'eventbrite_cavan'),
    array('key'=>'_event_status','compare'=>'NOT EXISTS')) ) );
echo "CAVAN_NO_STATUS_META=" . count($q2->posts) . "\n";

$all = new WP_Query( array( 'post_type'=>'event','posts_per_page'=>100,'fields'=>'ids','no_found_rows'=>true,
  'meta_query'=>array( array('key'=>'_event_status','value'=>'source_not_found') ) ) );
echo "TOTAL_SOURCE_NOT_FOUND=" . count($all->posts) . "\n";
$by = array();
foreach ( $all->posts as $pid ) { $s = get_post_meta($pid,'_event_source',true); $by[$s] = isset($by[$s])?$by[$s]+1:1; }
arsort($by);
foreach ( $by as $src=>$n ) { echo "  $src = $n\n"; }
