<?php
/**
 * Backfill script: map legacy leisure attribute meta to the
 * `conexao_leisure_attribute` taxonomy.
 *
 * Idempotent and safe to rerun. Legacy meta is NOT deleted.
 *
 * Phase 2: `_leisure_free` values are classified with a dedicated report
 * (clearly free / clearly paid / clearly conditional / ambiguous / empty).
 * Only unambiguous cases are migrated automatically — ambiguous text is
 * listed for manual review and never converted.
 *
 * Run via:
 *   wp eval-file scripts/backfill-leisure-attributes.php --allow-root
 *   wp eval-file scripts/backfill-leisure-attributes.php --dry-run --allow-root
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

/**
 * Phase 2 — classify a legacy `_leisure_free` value.
 *
 * Returns one of:
 *   'free_clear'   — unambiguous free admission ('1', "Gratuito", "Free"…)
 *   'paid_clear'   — unambiguous paid admission ("Pago", "Paid"…)
 *   'conditional'  — reliable evidence of conditional free access
 *                    (e.g. "Gratuito aos domingos", "Free on Sundays")
 *   'ambiguous'    — non-empty text that cannot be classified safely
 *   'empty'        — no value / unknown
 *
 * Only 'free_clear', 'paid_clear' and 'conditional' are auto-migrated;
 * everything else is reported for manual review. Never guess.
 */
function backfill_classify_free( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return 'empty';
	}

	$lower = mb_strtolower( $value, 'UTF-8' );

	$free_markers  = array( 'gratuit', 'grátis', 'free', 'entrada gratuita' );
	$paid_markers  = array( 'pago', 'paid', 'pagamento', 'taxa', 'ticket', 'bilhete', 'admission' );
	$cond_markers  = array(
		'condiç', 'condic', 'domingo', 'sunday', 'feriado', 'holiday',
		'determinad', 'período', 'periodo', 'apenas', 'only', 'às quartas',
		'wednesday', 'first sunday', 'primeiro domingo', 'menores', 'children',
		'students', 'estudantes', 'descont', 'reduc',
	);

	$has_free = false;
	foreach ( $free_markers as $needle ) {
		if ( false !== strpos( $lower, $needle ) ) {
			$has_free = true;
			break;
		}
	}
	if ( '1' === $value ) {
		return 'free_clear';
	}

	$has_paid = false;
	foreach ( $paid_markers as $needle ) {
		if ( false !== strpos( $lower, $needle ) ) {
			$has_paid = true;
			break;
		}
	}

	$has_cond = false;
	foreach ( $cond_markers as $needle ) {
		if ( false !== strpos( $lower, $needle ) ) {
			$has_cond = true;
			break;
		}
	}

	if ( $has_free && $has_paid ) {
		// Mixed statement (e.g. "gratuito para menores, pago para adultos")
		// → conditional access, reported but only migrated when a
		// conditional marker confirms the free-period evidence.
		return $has_cond ? 'conditional' : 'ambiguous';
	}
	if ( $has_free && $has_cond ) {
		return 'conditional';
	}
	if ( $has_free ) {
		return 'free_clear';
	}
	if ( $has_paid ) {
		return 'paid_clear';
	}
	return 'ambiguous';
}

$term_map = array(
	'familias'           => backfill_resolve_term( 'Famílias' ),
	'exterior'           => backfill_resolve_term( 'Exterior' ),
	'interior'           => backfill_resolve_term( 'Interior' ),
	'interior-exterior'  => backfill_resolve_term( 'Interior + exterior' ),
	'gratuito'           => backfill_resolve_term( 'Gratuito' ),
	'pago'               => backfill_resolve_term( 'Pago' ),
	'gratuito-condicional' => backfill_resolve_term( 'Gratuito em determinadas condições' ),
	'pet-friendly'       => backfill_resolve_term( 'Pet friendly' ),
	'acessivel'          => backfill_resolve_term( 'Acessível' ),
	'estacionamento'     => backfill_resolve_term( 'Estacionamento' ),
	'necessita-reserva'  => backfill_resolve_term( 'Necessita reserva' ),
);

// Phase 2 — dry-run mode: classify and report without writing anything.
// Usage:
//   wp eval-file scripts/backfill-leisure-attributes.php --dry-run
//   wp eval-file scripts/backfill-leisure-attributes.php dry-run
// (wp-cli consumes --flags, so the positional "dry-run" form also works).
$args            = isset( $argv ) ? $argv : array();
$dry_run_markers = array( '--dry-run', 'dry-run', 'dry_run' );
$dry_run         = count( array_intersect( $dry_run_markers, $args ) ) > 0;
if ( $dry_run ) {
	echo "DRY RUN — no data will be modified.\n\n";
}

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

// Phase 2 — `_leisure_free` migration report. Separates clearly free,
// clearly paid, clearly conditional, ambiguous (manual review) and
// empty/unknown. Only unambiguous cases are auto-migrated.
$free_report = array(
	'free_clear'  => array(),
	'paid_clear'  => array(),
	'conditional' => array(),
	'ambiguous'   => array(),
	'empty'       => array(),
);
$free_labels = array(
	'free_clear'  => 'Claramente Gratuito',
	'paid_clear'  => 'Claramente Pago',
	'conditional' => 'Claramente condicional',
	'ambiguous'   => 'Ambíguo (revisão manual)',
	'empty'       => 'Vazio/desconhecido',
);

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

	// `_leisure_free` — Phase 2 classification. Never guess: ambiguous
	// values are reported for manual review, never auto-migrated. A
	// record never receives both Gratuito and Pago (mutually exclusive
	// admission concepts); the existing taxonomy always wins.
	$free_class = backfill_classify_free( $free );
	$free_report[ $free_class ][] = $post_id;

	$existing_terms = wp_get_object_terms( $post_id, 'conexao_leisure_attribute', array( 'fields' => 'slugs' ) );
	if ( is_wp_error( $existing_terms ) ) {
		$existing_terms = array();
	}
	$has_free_term = in_array( 'gratuito', $existing_terms, true )
		|| in_array( 'pago', $existing_terms, true )
		|| in_array( 'gratuito-em-determinadas-condicoes', $existing_terms, true );

	if ( ! $has_free_term ) {
		if ( 'free_clear' === $free_class ) {
			$to_assign['gratuito'] = $term_map['gratuito'];
		} elseif ( 'paid_clear' === $free_class ) {
			$to_assign['pago'] = $term_map['pago'];
		} elseif ( 'conditional' === $free_class ) {
			$to_assign['gratuito-condicional'] = $term_map['gratuito-condicional'];
		}
		// 'ambiguous' / 'empty' → no admission term is assigned.
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

	if ( $dry_run ) {
		echo "  [dry-run] post {$post_id}: would assign " . implode( ', ', $term_ids ) . "\n";
		$updated++;
		continue;
	}

	$result = wp_set_object_terms( $post_id, $term_ids, 'conexao_leisure_attribute' );
	if ( is_wp_error( $result ) ) {
		echo "  ERROR: post {$post_id} - " . $result->get_error_message() . "\n";
		continue;
	}

	$updated++;
}

// Legacy meta is intentionally NEVER deleted here (transition-safe).
echo "\n=== Backfill complete ===\n";
echo "Total posts:    {$total}\n";
echo "Updated:        {$updated}\n";
echo "Skipped (none): {$skipped}\n";

echo "\n=== _leisure_free migration report ===\n";
foreach ( $free_report as $class => $ids ) {
	echo $free_labels[ $class ] . ': ' . count( $ids ) . "\n";
	if ( 'ambiguous' === $class && ! empty( $ids ) ) {
		echo '  Manual review needed (post IDs): ' . implode( ', ', $ids ) . "\n";
	}
}
