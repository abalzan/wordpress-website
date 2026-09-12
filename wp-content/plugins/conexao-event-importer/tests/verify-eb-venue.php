<?php
require '/var/www/html/wp-load.php';

$sources = array( 'eventbrite_laois', 'eventbrite_cork', 'eventbrite_dublin' );

foreach ( $sources as $src ) {
	$q = new WP_Query(
		array(
			'post_type'      => 'event',
			'meta_query'     => array(
				array(
					'key'   => '_event_source',
					'value' => $src,
				),
			),
			'posts_per_page' => -1,
		)
	);

	$with_venue  = 0;
	$with_addr   = 0;
	$with_county = 0;
	$with_town   = 0;
	$with_date   = 0;
	$with_time   = 0;
	$with_cat    = 0;
	$sample      = array();

	foreach ( $q->posts as $p ) {
		if ( get_post_meta( $p->ID, '_event_venue', true ) ) {
			$with_venue++;
		}
		if ( get_post_meta( $p->ID, '_event_address', true ) ) {
			$with_addr++;
		}
		if ( get_post_meta( $p->ID, '_event_date', true ) ) {
			$with_date++;
		}
		if ( get_post_meta( $p->ID, '_event_start_time', true ) ) {
			$with_time++;
		}
		$county_terms = wp_get_object_terms( $p->ID, 'conexao_county', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $county_terms ) && ! empty( $county_terms ) ) {
			$with_county++;
		}
		$town_terms = wp_get_object_terms( $p->ID, 'conexao_town', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $town_terms ) && ! empty( $town_terms ) ) {
			$with_town++;
		}
		$cat_terms = wp_get_object_terms( $p->ID, 'conexao_category', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $cat_terms ) && ! empty( $cat_terms ) ) {
			$with_cat++;
		}
		if ( count( $sample ) < 2 ) {
			$sample[] = $p;
		}
	}

	echo "=== $src ===\n";
	echo "  Total: {$q->post_count}\n";
	echo "  venue: $with_venue, address: $with_addr, county: $with_county, town: $with_town, date: $with_date, time: $with_time, category: $with_cat\n";
	foreach ( $sample as $p ) {
		echo '  Sample: ' . get_the_title( $p->ID ) . "\n";
		echo '    venue=' . get_post_meta( $p->ID, '_event_venue', true ) . "\n";
		echo '    address=' . get_post_meta( $p->ID, '_event_address', true ) . "\n";
		echo '    date=' . get_post_meta( $p->ID, '_event_date', true ) . ' time=' . get_post_meta( $p->ID, '_event_start_time', true ) . "\n";
		$county = wp_get_object_terms( $p->ID, 'conexao_county', array( 'fields' => 'names' ) );
		echo '    county=' . ( is_wp_error( $county ) ? 'ERR' : implode( ', ', $county ) ) . "\n";
		$cats = wp_get_object_terms( $p->ID, 'conexao_category', array( 'fields' => 'names' ) );
		echo '    category=' . ( is_wp_error( $cats ) ? 'ERR' : implode( ', ', $cats ) ) . "\n";
	}
	echo "\n";
}
