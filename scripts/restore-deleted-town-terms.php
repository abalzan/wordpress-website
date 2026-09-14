<?php
/**
 * Emergency restoration script for legitimate town terms incorrectly deleted
 * by the cleanup script's overly broad "Co X" regex.
 *
 * This restores: Cork, Cobh, Collon, Cong, Corofin (all legitimate towns
 * that happen to start with "Co").
 *
 * Usage: wp --allow-root eval-file scripts/restore-deleted-town-terms.php
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;

$terms_to_restore = array(
	array( 'term_id' => 2006, 'name' => 'Cork', 'slug' => 'cork' ),
	array( 'term_id' => 2007, 'name' => 'Cobh', 'slug' => 'cobh' ),
	array( 'term_id' => 2215, 'name' => 'Collon', 'slug' => 'collon' ),
	array( 'term_id' => 2191, 'name' => 'Cong', 'slug' => 'cong' ),
	array( 'term_id' => 2058, 'name' => 'Corofin', 'slug' => 'corofin' ),
);

$known_associations = array(
	2007 => array( 16508 ),
	2215 => array( 20068, 20070 ),
	2191 => array( 19876 ),
	2058 => array( 18530 ),
);

echo "=== RESTORING DELETED TOWN TERMS ===\n\n";

foreach ( $terms_to_restore as $term_data ) {
	$term_id = $term_data['term_id'];
	$name    = $term_data['name'];
	$slug    = $term_data['slug'];

	$existing = $wpdb->get_var(
		$wpdb->prepare( "SELECT term_id FROM {$wpdb->terms} WHERE term_id = %d", $term_id )
	);
	if ( $existing ) {
		echo "Term \"{$name}\" (term_id={$term_id}) already exists, skipping.\n";
		continue;
	}

	$wpdb->insert(
		$wpdb->terms,
		array( 'term_id' => $term_id, 'name' => $name, 'slug' => $slug ),
		array( '%d', '%s', '%s' )
	);

	$wpdb->insert(
		$wpdb->term_taxonomy,
		array(
			'term_id'     => $term_id,
			'taxonomy'    => 'conexao_town',
			'description' => '',
			'parent'      => 0,
			'count'       => 0,
		),
		array( '%d', '%s', '%s', '%d', '%d' )
	);
	$ttid = $wpdb->insert_id;
	echo "Restored term \"{$name}\" (term_id={$term_id}, ttid={$ttid}).\n";

	if ( 'Cork' === $name ) {
		$cork_county = $wpdb->get_row(
			"SELECT t.term_id, tt.term_taxonomy_id FROM {$wpdb->terms} t
			INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
			WHERE tt.taxonomy = 'conexao_county' AND LOWER(t.name) = 'cork'"
		);
		if ( $cork_county ) {
			$county_events = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d",
					$cork_county->term_taxonomy_id
				)
			);
			$restored = 0;
			foreach ( $county_events as $event_id ) {
				$has_town = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
						INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
						INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
						WHERE tr.object_id = %d AND tt.taxonomy = 'conexao_town'
						AND LOWER(t.name) != 'cork'",
						$event_id
					)
				);
				if ( $has_town ) {
					continue;
				}
				$address  = get_post_meta( $event_id, '_event_address', true );
				$venue    = get_post_meta( $event_id, '_event_venue', true );
				$location = get_post_meta( $event_id, '_event_location', true );
				if ( ( $address && preg_match( '/\bcork\b/i', $address ) )
					|| ( $venue && preg_match( '/\bcork\b/i', $venue ) )
					|| ( $location && preg_match( '/\bcork\b/i', $location ) ) ) {
					$wpdb->insert(
						$wpdb->term_relationships,
						array( 'object_id' => $event_id, 'term_taxonomy_id' => $ttid ),
						array( '%d', '%d' )
					);
					$restored++;
				}
			}
			echo "  Restored {$restored} Cork town associations.\n";
		}
	} elseif ( isset( $known_associations[ $term_id ] ) ) {
		foreach ( $known_associations[ $term_id ] as $event_id ) {
			$wpdb->insert(
				$wpdb->term_relationships,
				array( 'object_id' => $event_id, 'term_taxonomy_id' => $ttid ),
				array( '%d', '%d' )
			);
			echo "  Restored event {$event_id} association.\n";
		}
	}

	$count = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d",
			$ttid
		)
	);
	$wpdb->update(
		$wpdb->term_taxonomy,
		array( 'count' => $count ),
		array( 'term_taxonomy_id' => $ttid ),
		array( '%d' ),
		array( '%d' )
	);
}

echo "\n=== RESTORATION COMPLETE ===\n";
