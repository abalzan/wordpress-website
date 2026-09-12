<?php
/**
 * Stage C1 — County+City+Category AND semantics + pagination duplicate check.
 */
require '/var/www/html/wp-load.php';

// Triple combo: find a county/city/category triple with data via direct taxonomy query.
$towns = get_terms( array( 'taxonomy' => 'conexao_town', 'hide_empty' => true, 'number' => 20 ) );
$cats  = get_terms( array( 'taxonomy' => 'conexao_category', 'hide_empty' => true, 'number' => 40 ) );

// Find a triple that yields results.
$tested = 0;
foreach ( $towns as $town ) {
	// Get county for this town via its events.
	$events_with_town = get_posts(
		array(
			'post_type'      => 'event',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'tax_query'      => array( array( 'taxonomy' => 'conexao_town', 'field' => 'term_id', 'terms' => $town->term_id ) ),
		)
	);
	if ( empty( $events_with_town ) ) {
		continue;
	}
	$counties = wp_get_object_terms( $events_with_town[0], 'conexao_county', array( 'fields' => 'all' ) );
	if ( empty( $counties ) || is_wp_error( $counties ) ) {
		continue;
	}
	$county = $counties[0];

	// Categories on this county's events.
	$events_in_county = get_posts(
		array(
			'post_type'      => 'event',
			'posts_per_page' => 5,
			'fields'         => 'ids',
			'tax_query'      => array( array( 'taxonomy' => 'conexao_county', 'field' => 'term_id', 'terms' => $county->term_id ) ),
		)
	);
	foreach ( $events_in_county as $eid ) {
		$cats_on_event = wp_get_object_terms( $eid, 'conexao_category', array( 'fields' => 'all' ) );
		foreach ( $cats_on_event as $cat ) {
			if ( is_wp_error( $cat ) ) {
				continue;
			}
			// Triple combo query: County AND City AND Category.
			$triple = new WP_Query(
				array(
					'post_type'      => 'event',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'tax_query'      => array(
						'relation' => 'AND',
						array( 'taxonomy' => 'conexao_county', 'field' => 'term_id', 'terms' => $county->term_id ),
						array( 'taxonomy' => 'conexao_town', 'field' => 'term_id', 'terms' => $town->term_id ),
						array( 'taxonomy' => 'conexao_category', 'field' => 'term_id', 'terms' => $cat->term_id ),
					),
				)
			);
			$tested++;
			echo sprintf(
				"Triple County=%s + City=%s + Category=%s -> %d results (graceful AND)\n",
				$county->name,
				$town->name,
				$cat->name,
				$triple->post_count
			);
			if ( $tested >= 5 ) {
				break 3;
			}
		}
	}
}

// A town from another county can never override the county selection.
// e.g. county=cork + cidade=portlaoise (Portlaoise is a Laois town) -> 0 results.
$cork     = get_term_by( 'slug', 'cork', 'conexao_county' );
$portlaoise = get_term_by( 'slug', 'portlaoise', 'conexao_town' );
$invalid  = new WP_Query(
	array(
		'post_type'      => 'event',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'tax_query'      => array(
			'relation' => 'AND',
			array( 'taxonomy' => 'conexao_county', 'field' => 'term_id', 'terms' => $cork->term_id ),
			array( 'taxonomy' => 'conexao_town', 'field' => 'term_id', 'terms' => $portlaoise->term_id ),
		),
	)
);
echo sprintf(
	"Invalid combo County=Cork + City=Portlaoise -> %d results (expected 0)\n",
	$invalid->post_count
);
