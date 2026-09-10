<?php
/**
 * Seed script: Lazer expansion — imports new leisure locations from
 * scripts/data/leisure-expansion-data-{1,2}.php into the `leisure` CPT.
 *
 * Duplicate prevention (runs before every insert):
 *   - Slug match against ALL existing leisure posts (any status, incl. trash).
 *   - Normalized title match (case/accent/&-vs-and insensitive).
 *   - Official website URL match.
 *   - Token-overlap (Jaccard >= 0.80) treated as duplicate.
 *   - Intra-dataset duplicates are skipped as well.
 *
 * Uses the SAME data model as scripts/seed-leisure-locations.php:
 * leisure CPT + conexao_category/conexao_county taxonomies + _leisure_* meta,
 * so records remain fully compatible with the conexao-leisure-migration
 * export/import pipeline and the Wikimedia image workflow.
 *
 * Run via:
 *   wp eval-file scripts/seed-leisure-expansion.php --allow-root
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

/**
 * Normalize a title for fuzzy duplicate comparison.
 */
function conexao_expand_normalize_title( $title ) {
	$t = mb_strtolower( $title, 'UTF-8' );
	$t = str_replace( array( '&', '–', '—', '/' ), ' and ', $t );
	// Transliterate common accented characters.
	$t = iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $t );
	$t = preg_replace( '/[^a-z0-9]+/', ' ', $t );
	// Drop single-character tokens (e.g. possessive "s" from apostrophes)
	// so they don't inflate token-overlap similarity.
	$tokens = array_filter( explode( ' ', trim( preg_replace( '/\s+/', ' ', $t ) ) ), function ( $tok ) {
		return strlen( $tok ) > 1;
	} );
	return implode( ' ', $tokens );
}

/**
 * Jaccard similarity between two normalized strings.
 */
function conexao_expand_jaccard( $a, $b ) {
	$wa = explode( ' ', $a );
	$wb = explode( ' ', $b );
	if ( ! $wa || ! $wb ) {
		return 0;
	}
	$inter = count( array_intersect( $wa, $wb ) );
	$union = count( array_unique( array_merge( $wa, $wb ) ) );
	return $union ? ( $inter / $union ) : 0;
}

// ------------------------------------------------------------------
// Load candidate dataset.
// Parts 1/2: earlier expansion batches. Part 3: Stage C (Lazer
// expansion — 12 approved NEW records; see
// docs/importers/lazer-expansion-stage-c-report.md).
// ------------------------------------------------------------------
$part1 = require dirname( __FILE__ ) . '/data/leisure-expansion-data-1.php';
$part2 = require dirname( __FILE__ ) . '/data/leisure-expansion-data-2.php';
$candidates = array_merge( $part1, $part2 );
$part3_file = dirname( __FILE__ ) . '/data/leisure-expansion-data-3.php';
if ( file_exists( $part3_file ) ) {
	$candidates = array_merge( $candidates, require $part3_file );
}


echo 'Loaded ' . count( $candidates ) . " candidate locations.\n";

// ------------------------------------------------------------------
// Build duplicate-prevention index from ALL existing leisure posts.
// ------------------------------------------------------------------
$existing = get_posts( array(
	'post_type'      => 'leisure',
	'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash', 'inherit' ),
	'numberposts'    => -1,
	'orderby'        => 'title',
	'order'          => 'ASC',
) );

$index_slugs   = array();
$index_titles  = array(); // normalized title => original title
$index_urls    = array();

foreach ( $existing as $post ) {
	$index_slugs[ $post->post_name ] = true;
	$norm = conexao_expand_normalize_title( $post->post_title );
	if ( '' !== $norm ) {
		$index_titles[ $norm ] = $post->post_title;
	}
	foreach ( array( '_leisure_official_website', '_leisure_website', '_leisure_discover_ireland' ) as $key ) {
		$url = get_post_meta( $post->ID, $key, true );
		if ( $url ) {
			$index_urls[ untrailingslashit( strtolower( trim( $url ) ) ) ] = true;
		}
	}
}

echo 'Existing leisure posts indexed: ' . count( $existing ) . "\n";

// ------------------------------------------------------------------
// Resolve/create taxonomy terms.
// ------------------------------------------------------------------
function conexao_expand_term( $name, $taxonomy ) {
	$term = term_exists( $name, $taxonomy );
	if ( $term && ! is_wp_error( $term ) ) {
		return (int) ( is_array( $term ) ? $term['term_id'] : $term );
	}
	$created = wp_insert_term( $name, $taxonomy );
	if ( is_wp_error( $created ) ) {
		return 0;
	}
	return (int) $created['term_id'];
}

// ------------------------------------------------------------------
// Import loop.
// ------------------------------------------------------------------
$created = 0;
$duplicates = array();
$failures = array();
$seen_in_dataset = array();

foreach ( $candidates as $data ) {
	$title = $data['title'];
	$slug  = sanitize_title( $data['slug'] );
	$norm  = conexao_expand_normalize_title( $title );
	$url = untrailingslashit( strtolower( trim( isset( $data['official_website'] ) ? $data['official_website'] : '' ) ) );
	$di_url = untrailingslashit( strtolower( trim( isset( $data['discover_ireland'] ) ? $data['discover_ireland'] : '' ) ) );

	// --- Duplicate checks (slug, normalized title, official URL,
	// Discover Ireland URL, token overlap) ---
	$dup_reason = '';

	if ( isset( $index_slugs[ $slug ] ) ) {
		$dup_reason = "slug '{$slug}' already exists";
	} elseif ( isset( $index_titles[ $norm ] ) ) {
		$dup_reason = "normalized title matches existing '{$index_titles[$norm]}'";
	} elseif ( $url && isset( $index_urls[ $url ] ) ) {
		$dup_reason = "official website {$url} already used";
	} elseif ( $di_url && isset( $index_urls[ $di_url ] ) ) {
		$dup_reason = "Discover Ireland URL {$di_url} already used";
	} else {
		// Token overlap against existing titles.
		foreach ( $index_titles as $existing_norm => $existing_orig ) {
			if ( conexao_expand_jaccard( $norm, $existing_norm ) >= 0.80 ) {
				$dup_reason = "similar to existing '{$existing_orig}'";
				break;
			}
		}
		// Intra-dataset duplicates.
		if ( ! $dup_reason ) {
			if ( isset( $seen_in_dataset[ $slug ] ) || isset( $seen_in_dataset[ 't:' . $norm ] ) ) {
				$dup_reason = 'duplicated within expansion dataset';
			} else {
				foreach ( $seen_in_dataset as $key => $_ ) {
					if ( 0 === strpos( $key, 't:' ) && conexao_expand_jaccard( $norm, substr( $key, 2 ) ) >= 0.80 ) {
						$dup_reason = 'similar within expansion dataset';
						break;
					}
				}
			}
		}
	}

	if ( $dup_reason ) {
		echo "  DUPLICATE-SKIP: {$title} ({$slug}) — {$dup_reason}\n";
		$duplicates[] = array( 'title' => $title, 'slug' => $slug, 'reason' => $dup_reason );
		continue;
	}

	// --- Resolve terms ---
	$category_term = conexao_expand_term( isset( $data['category'] ) ? $data['category'] : 'Outros', 'conexao_category' );
	$county_term   = conexao_expand_term( $data['county'], 'conexao_county' );

	// --- Insert post ---
	$post_id = wp_insert_post( array(
		'post_title'   => $title,
		'post_name'    => $slug,
		'post_content' => isset( $data['content'] ) ? $data['content'] : '',
		'post_excerpt' => isset( $data['excerpt'] ) ? $data['excerpt'] : '',
		'post_status'  => 'publish',
		'post_type'    => 'leisure',
	), true );

	if ( is_wp_error( $post_id ) ) {
		echo "  ERROR: {$title} - " . $post_id->get_error_message() . "\n";
		$failures[] = array( 'title' => $title, 'error' => $post_id->get_error_message() );
		continue;
	}
	$post_id = (int) $post_id;

	// --- Taxonomies ---
	if ( $category_term ) {
		wp_set_object_terms( $post_id, array( (int) $category_term ), 'conexao_category' );
	}
	if ( $county_term ) {
		wp_set_object_terms( $post_id, array( (int) $county_term ), 'conexao_county' );
	}

	// --- Structured characteristics (conexao_leisure_attribute taxonomy) ---
	// Canonical representation for the practical attributes. Legacy meta is
	// also written below for transition compatibility.
	$attr_term_ids = array();
	$attr_map = array(
		'family'        => 'Famílias',
		'outdoor'       => 'Exterior',
		'indoor'        => 'Interior',
		'booking'       => 'Necessita reserva',
		'pet'           => 'Pet friendly',
		'parking'       => 'Estacionamento',
		'accessibility' => 'Acessível',
		'transport'     => 'Acesso de transporte público',
		'bicycle'       => 'Bicicleta',
	);
	foreach ( $attr_map as $data_key => $term_name ) {
		if ( ! empty( $data[ $data_key ] ) ) {
			$term = term_exists( $term_name, 'conexao_leisure_attribute' );
			if ( $term && ! is_wp_error( $term ) ) {
				$attr_term_ids[] = (int) $term['term_id'];
			}
		}
	}
	// Indoor + outdoor collapse into the combined term.
	if ( ! empty( $data['indoor'] ) && ! empty( $data['outdoor'] ) ) {
		$attr_term_ids = array_diff( $attr_term_ids, array_map( 'intval', array() ) );
		// Remove the separate interior/exterior entries.
		$int_term = term_exists( 'Interior', 'conexao_leisure_attribute' );
		$ext_term = term_exists( 'Exterior', 'conexao_leisure_attribute' );
		$combo    = term_exists( 'Interior + exterior', 'conexao_leisure_attribute' );
		$filtered = array();
		foreach ( $attr_term_ids as $tid ) {
			if ( $int_term && ! is_wp_error( $int_term ) && (int) $int_term['term_id'] === $tid ) {
				continue;
			}
			if ( $ext_term && ! is_wp_error( $ext_term ) && (int) $ext_term['term_id'] === $tid ) {
				continue;
			}
			$filtered[] = $tid;
		}
		$attr_term_ids = $filtered;
		if ( $combo && ! is_wp_error( $combo ) ) {
			$attr_term_ids[] = (int) $combo['term_id'];
		}
	}
	// Free/paid classification. Part 3 (Stage C) records carry an explicit
	// `free_status` label ('Gratuito', 'Pago', 'Gratuito em determinadas
	// condições'); legacy parts keep the bare free flag ('Gratuito').
	$free_label = '';
	if ( isset( $data['free_status'] ) && '' !== trim( (string) $data['free_status'] ) ) {
		$free_label = trim( (string) $data['free_status'] );
	} elseif ( ! empty( $data['free'] ) ) {
		$free_label = 'Gratuito';
	}
	$free_term_names = array( 'Gratuito', 'Pago', 'Gratuito em determinadas condições' );
	if ( in_array( $free_label, $free_term_names, true ) ) {
		$free_attr_term = term_exists( $free_label, 'conexao_leisure_attribute' );
		if ( $free_attr_term && ! is_wp_error( $free_attr_term ) ) {
			$attr_term_ids[] = (int) $free_attr_term['term_id'];
		}
	}
	if ( ! empty( $attr_term_ids ) ) {
		$attr_term_ids = array_values( array_unique( array_map( 'intval', $attr_term_ids ) ) );
		wp_set_object_terms( $post_id, $attr_term_ids, 'conexao_leisure_attribute' );
	}

	// --- Meta (same keys as seed-leisure-locations.php) ---
	update_post_meta( $post_id, '_leisure_county', $data['county'] );
	update_post_meta( $post_id, '_leisure_town', isset( $data['town'] ) ? $data['town'] : '' );
	update_post_meta( $post_id, '_leisure_address', isset( $data['address'] ) ? $data['address'] : '' );
	update_post_meta( $post_id, '_leisure_website', '' );
	update_post_meta( $post_id, '_leisure_official_website', isset( $data['official_website'] ) ? $data['official_website'] : '' );
	update_post_meta( $post_id, '_leisure_discover_ireland', isset( $data['discover_ireland'] ) ? $data['discover_ireland'] : '' );
	update_post_meta( $post_id, '_leisure_map_url', '' );
	update_post_meta( $post_id, '_leisure_feature', '' );
	// _leisure_free: store the canonical Portuguese label instead of a bare '1'
	// so the value is meaningful if ever displayed directly.
	update_post_meta( $post_id, '_leisure_free', $free_label );
	update_post_meta( $post_id, '_leisure_family', ! empty( $data['family'] ) ? '1' : '' );
	update_post_meta( $post_id, '_leisure_accessibility', ! empty( $data['accessibility'] ) ? '1' : '' );
	update_post_meta( $post_id, '_leisure_pet_friendly', ! empty( $data['pet'] ) ? '1' : '' );
	update_post_meta( $post_id, '_leisure_indoor', ! empty( $data['indoor'] ) ? '1' : '' );
	update_post_meta( $post_id, '_leisure_outdoor', ! empty( $data['outdoor'] ) ? '1' : '' );
	update_post_meta( $post_id, '_leisure_parking', ! empty( $data['parking'] ) ? '1' : '' );
	update_post_meta( $post_id, '_leisure_booking', ! empty( $data['booking'] ) ? '1' : '' );
	update_post_meta( $post_id, '_leisure_duration', '' );
	update_post_meta( $post_id, '_leisure_best_time', '' );
	// Phase 2 practical-verification metadata (Stage C).
	update_post_meta( $post_id, '_leisure_practical_notes', isset( $data['practical_notes'] ) ? $data['practical_notes'] : '' );
	update_post_meta( $post_id, '_leisure_practical_source_url', isset( $data['practical_source_url'] ) ? $data['practical_source_url'] : '' );
	update_post_meta( $post_id, '_leisure_practical_last_checked', isset( $data['practical_last_checked'] ) ? $data['practical_last_checked'] : '' );

	// --- Update indexes so later candidates see this record ---
	$index_slugs[ $slug ] = true;
	$index_titles[ $norm ] = $title;
	if ( $url ) {
		$index_urls[ $url ] = true;
	}
	if ( $di_url ) {
		$index_urls[ $di_url ] = true;
	}
	$seen_in_dataset[ $slug ] = true;
	$seen_in_dataset[ 't:' . $norm ] = true;

	$created++;
	echo "  CREATED: {$title} (ID: {$post_id}, /lazer/{$slug}/)\n";
}

// ------------------------------------------------------------------
// Summary + machine-readable report.
// ------------------------------------------------------------------
echo "\n=== Summary ===\n";
echo "Candidates: " . count( $candidates ) . "\n";
echo "Created: {$created}\n";
echo "Duplicates skipped: " . count( $duplicates ) . "\n";
echo "Failures: " . count( $failures ) . "\n";

$report = array(
	'ran_at'      => current_time( 'c' ),
	'candidates'  => count( $candidates ),
	'created'     => $created,
	'duplicates'  => $duplicates,
	'failures'    => $failures,
);
file_put_contents( '/tmp/lazer-expansion-import-report.json', json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
echo "Report saved to /tmp/lazer-expansion-import-report.json\n";