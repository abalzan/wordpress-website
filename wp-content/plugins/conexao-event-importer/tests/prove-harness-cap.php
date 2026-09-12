<?php
/**
 * Prove: the plugin-separation harness's posts_per_page=100 cap is the cause
 * of the 4 visibility failures (DB grew to 720 events during Stage C1).
 */
require '/var/www/html/wp-load.php';

// Reproduce the exact harness query.
$query = new WP_Query(
	array(
		'post_type'        => 'event',
		'post_status'      => 'publish',
		'posts_per_page'   => 100,
		'orderby'          => 'ID',
		'order'            => 'ASC',
		'no_found_rows'    => true,
		'suppress_filters' => false,
	)
);
$ids        = wp_list_pluck( $query->posts, 'ID' );
$oldest     = min( $ids );
$newest_in  = max( $ids );
$total      = (int) $query->found_posts;

echo "DB event count: {$total}\n";
echo "Harness query (cap 100) returns IDs {$oldest}–{$newest_in}\n";
echo "Max real event ID: " . max( wp_list_pluck( ( new WP_Query( array( 'post_type' => 'event', 'posts_per_page' => 1, 'orderby' => 'ID', 'order' => 'DESC', 'no_found_rows' => true ) ) )->posts, 'ID' ) ) . "\n";

// Create a harness-style test event and check visibility under both caps.
$test_id = wp_insert_post(
	array(
		'post_type'   => 'event',
		'post_title'  => '[HARNESS-PROOF] upcoming published',
		'post_status' => 'publish',
		'meta_input'  => array(
			'_event_status' => 'published',
			'_event_date'   => gmdate( 'Y-m-d', strtotime( '+30 days' ) ),
			'_event_time'   => '18:00',
		),
	)
);
echo "Created harness-style event: {$test_id}\n";
echo "  In cap-100 ID-ASC list: " . ( in_array( $test_id, $ids, true ) ? 'yes' : 'NO — this is the harness failure' ) . "\n";

// Same event in the harness query with the cap removed (unchanged gate semantics).
$query_uncapped = new WP_Query(
	array(
		'post_type'        => 'event',
		'post_status'      => 'publish',
		'posts_per_page'   => -1,
		'orderby'          => 'ID',
		'order'            => 'ASC',
		'no_found_rows'    => true,
		'suppress_filters' => false,
	)
);
$ids_uncapped = wp_list_pluck( $query_uncapped->posts, 'ID' );
echo "  In uncapped list (gate semantics unchanged): " . ( in_array( $test_id, $ids_uncapped, true ) ? 'yes' : 'no' ) . "\n";

// And confirm a hidden event stays hidden under the uncapped list (gate works both ways).
$hidden_id = wp_insert_post(
	array(
		'post_type'   => 'event',
		'post_title'  => '[HARNESS-PROOF] expired hidden',
		'post_status' => 'publish',
		'meta_input'  => array(
			'_event_status' => 'expired',
			'_event_date'   => gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
		),
	)
);
$query_hidden = new WP_Query(
	array(
		'post_type'        => 'event',
		'post_status'      => 'publish',
		'posts_per_page'   => -1,
		'no_found_rows'    => true,
		'suppress_filters' => false,
	)
);
$hidden_list = wp_list_pluck( $query_hidden->posts, 'ID' );
echo "Expired-style event in uncapped list: " . ( in_array( $hidden_id, $hidden_list, true ) ? 'yes (gate would be broken)' : 'no — hidden correctly, gate works' ) . "\n";

wp_delete_post( $test_id, true );
wp_delete_post( $hidden_id, true );
echo "Proof events cleaned up.\n";
