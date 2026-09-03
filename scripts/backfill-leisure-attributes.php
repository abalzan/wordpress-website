<?php
/**
 * Backfill script: map legacy leisure attribute meta to the new
 * `conexao_leisure_attribute` taxonomy.
 *
 * Idempotent and safe to rerun. Legacy meta is NOT deleted.
 *
 * Run via:
 *   wp eval-file scripts/backfill-leisure-attributes.php --allow-root
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'WP_USE_THEMES', false );
	$dir = dirname( __FILE__ );
	while ( $dir !== dirname( $dir ) ) {
		if ( file_exists( $dir . '/wp-load.php' ) ) {
			require_once $dir . '/wp-load.php';
			break;
		}
		$dir = dirname( $dir );
	}
	if ( ! defined( 'ABSPATH' ) ) {
		fwrite( STDERR, "Unable to locate wp-load.php\n" );
		exit( 1 );
	}
}

if ( ! taxonomy_exists( 'conexao_leisure_attribute' ) ) {
	fwrite( STDERR, "Taxonomy not registered. Activate conexao-data-model first.\n" );
	exit( 1 );
}

function backfill_resolve_term( $name ) {
	$term = term_exists( $name, 'conexao_leisure_attribute' );
	if ( $term && ! is_wp_error( $term ) ) {
		return (int) $term['term_id'];
	}
	$created = wp_insert_term( $name, 'conexao_leisure_attribute' );
	if ( is_wp_error( $created ) ) {
		return 0;
	}
	return (int) $created['term_id'];
}

function backfill_is_free( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return false;
	}
	if ( '1' === $value ) {
		return true;
	}
	$lower = mb_strtolower( $value, 'UTF-8' );
	foreach ( array( 'gratuit', 'free', 'grátis' ) as $needle ) {
		if ( false !== strpos( $lower, $needle ) ) {
			return true;
		}
	}
	return false;
}

$term_map = array(
	'familias'           => backfill_resolve_term( 'Famílias' ),
	'exterior'           => backfill_resolve_term( 'Exterior' ),
	'interior'           => backfill_resolve_term( 'Interior' ),
	'interior-exterior'  => backfill_resolve_term( 'Interior + exterior' ),
	'gratuito'           => backfill_resolve_term( 'Gratuito' ),
	'pet-friendly'       => backfill_resolve_term( 'Pet friendly' ),
	'acessivel'          => backfill_resolve_term( 'Acessível' ),
	'estacionamento'     => backfill_resolve_term( 'Estacionamento' ),
	'necessita-reserva'  => backfill_resolve_term( 'Necessita reserva' ),
);

echo 'Term IDs: ' . wp_json_encode( $term_map ) . "\n";

$post_ids = get_posts( array(
	'post_type'              => 'leisure',
	'post_status'            => 'publish',
	'posts_per_page'         => -1,
	'fields'                 => 'ids',
	'no_found_rows'          => true,
	'update_post_meta_cache' => false,
	'update_post_term_cache' => false,
) );

$total   = count( $post_ids );
$updated = 0;
$skipped = 0;

echo "Processing {$total} published leisure posts...\n";

foreach ( $post_ids as $post_id ) {
	$post_id = (int) $post_id;

	$family        = get_post_meta( $post_id, '_leisure_family', true );
	$outdoor       = get_post_meta( $post_id, '_leisure_outdoor', true );
	$indoor        = get_post_meta( $post_id, '_leisure_indoor', true );
	$booking       = get_post_meta( $post_id, '_leisure_booking', true );
	$accessibility = get_post_meta( $post_id, '_leisure_accessibility', true );
	$pet_friendly  = get_post_meta( $post_id, '_leisure_pet_friendly', true );
	$parking       = get_post_meta( $post_id, '_leisure_parking', true );
	$free          = get_post_meta( $post_id, '_leisure_free', true );

	$to_assign = array();

	if ( '1' === (string) $family ) {
		$to_assign['familias'] = $term_map['familias'];
	}

	$has_indoor  = ( '1' === (string) $indoor );
	$has_outdoor = ( '1' === (string) $outdoor );
	if ( $has_indoor && $has_outdoor ) {
		$to_assign['interior-exterior'] = $term_map['interior-exterior'];
	} else {
		if ( $has_indoor ) {
			$to_assign['interior'] = $term_map['interior'];
		}
		if ( $has_outdoor ) {
			$to_assign['exterior'] = $term_map['exterior'];
		}
	}

	if ( '1' === (string) $booking ) {
		$to_assign['necessita-reserva'] = $term_map['necessita-reserva'];
	}
	if ( '1' === (string) $accessibility ) {
		$to_assign['acessivel'] = $term_map['acessivel'];
	}
	if ( '1' === (string) $pet_friendly ) {
		$to_assign['pet-friendly'] = $term_map['pet-friendly'];
	}
	if ( '1' === (string) $parking ) {
		$to_assign['estacionamento'] = $term_map['estacionamento'];
	}
	if ( backfill_is_free( $free ) ) {
		$to_assign['gratuito'] = $term_map['gratuito'];
	}

	$to_assign = array_filter( $to_assign, function ( $id ) {
		return $id > 0;
	} );

	if ( empty( $to_assign ) ) {
		$skipped++;
		continue;
	}

	$term_ids = array_values( array_unique( array_map( 'intval', $to_assign ) ) );

	$existing = wp_get_object_terms( $post_id, 'conexao_leisure_attribute', array( 'fields' => 'ids' ) );
	if ( ! is_wp_error( $existing ) && ! empty( $existing ) ) {
		$term_ids = array_values( array_unique( array_map( 'intval', array_merge( $existing, $term_ids ) ) ) );
	}

	$result = wp_set_object_terms( $post_id, $term_ids, 'conexao_leisure_attribute' );
	if ( is_wp_error( $result ) ) {
		echo "  ERROR: post {$post_id} - " . $result->get_error_message() . "\n";
		continue;
	}

	$updated++;
}

echo "\n=== Backfill complete ===\n";
echo "Total posts:    {$total}\n";
echo "Updated:        {$updated}\n";
echo "Skipped (none): {$skipped}\n";
