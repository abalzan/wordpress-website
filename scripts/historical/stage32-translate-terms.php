<?php
/**
 * Stage 3.2 — English taxonomy localization for the pilot content.
 *
 * LOCAL / STAGING ONLY. Creates ONE linked English term translation per
 * Portuguese term actually used by the Stage 3.2 English pilot records.
 *
 * Contract (CONEXAO_BR_ENGLISH_ARCHITECTURE_DECISION.md §8 + Stage 2/3.1
 * taxonomy guarantees):
 *   - One shared term identity per concept; EN terms are Polylang term
 *     translations linked with pll_save_term_translations().
 *   - Portuguese slugs are never touched; no -en/-pt suffix duplicates.
 *   - EN terms get natural English slugs (e.g. natureza → nature) because
 *     they are genuine distinct terms; WordPress uniqueness requires it.
 *   - Counties and towns are PROPER NOUNS: they stay single shared PT terms
 *     (an EN county term would need a suffixed slug for zero naming gain,
 *     which the taxonomy guarantees forbid).
 *   - Existing PT filter URLs keep working unchanged.
 *
 * Idempotent: a term that already has an EN translation is skipped.
 *
 * Usage (local only, NEVER production):
 *   wp eval-file scripts/stage32-translate-terms.php
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	$dir = __DIR__;
	while ( $dir !== dirname( $dir ) ) {
		if ( file_exists( $dir . '/wp-load.php' ) ) {
			require_once $dir . '/wp-load.php';
			break;
		}
		$dir = dirname( $dir );
	}
}
if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "WordPress bootstrap failed\n" );
	exit( 1 );
}
if ( ! function_exists( 'pll_save_term_translations' ) ) {
	fwrite( STDERR, "Polylang is not active.\n" );
	exit( 1 );
}

/**
 * English names for the terms the Stage 3.2 pilot English records use.
 * Human-authored. taxonomy => [ pt_slug => [ en_name, en_slug ] ].
 */
function stage32_term_translation_map() {
	return array(
		'conexao_category' => array(
			'documentos'  => array( 'Documents', 'documents' ),
			'trabalho'    => array( 'Work', 'work' ),
			'financas'    => array( 'Finances', 'finances' ),
			'festivais'   => array( 'Festivals', 'festivals' ),
			'musica'      => array( 'Music', 'music' ),
			'cultura'     => array( 'Culture', 'culture' ),
			'natureza'    => array( 'Nature', 'nature' ),
			'cidades'     => array( 'Cities', 'cities' ),
			'negocios'    => array( 'Businesses', 'businesses' ),
			'gastronomia' => array( 'Gastronomy', 'gastronomy' ),
			'empregos'    => array( 'Jobs', 'jobs' ),
		),
		'category' => array(
			'comunidade' => array( 'Community', 'community' ),
		),
	);
}

echo "== Stage 3.2 — term translations (EN) ==\n\n";

foreach ( stage32_term_translation_map() as $taxonomy => $map ) {
	echo "-- {$taxonomy} --\n";
	foreach ( $map as $pt_slug => $en ) {
		list( $en_name, $en_slug ) = $en;

		$pt_term = get_term_by( 'slug', $pt_slug, $taxonomy );
		if ( ! $pt_term || is_wp_error( $pt_term ) ) {
			echo "  SKIP {$pt_slug}: PT term missing in {$taxonomy}.\n";
			continue;
		}

		$existing = (int) pll_get_term( $pt_term->term_id, 'en' );
		if ( $existing ) {
			echo "  EXISTS {$pt_slug} → en term #{$existing} (skipped)\n";
			continue;
		}

		$clash = get_term_by( 'slug', $en_slug, $taxonomy );
		if ( $clash && ! is_wp_error( $clash ) ) {
			echo "  ERROR {$pt_slug}: EN slug '{$en_slug}' already exists in {$taxonomy} (#{$clash->term_id}) — refusing to collide.\n";
			continue;
		}

		$created = wp_insert_term( $en_name, $taxonomy, array( 'slug' => $en_slug ) );
		if ( is_wp_error( $created ) ) {
			echo "  ERROR {$pt_slug}: " . $created->get_error_message() . "\n";
			continue;
		}

		$en_id = (int) $created['term_id'];
		pll_set_term_language( $en_id, 'en' );
		pll_set_term_language( $pt_term->term_id, 'pt' );
		pll_save_term_translations(
			array(
				'pt' => $pt_term->term_id,
				'en' => $en_id,
			)
		);

		$check_en = (int) pll_get_term( $pt_term->term_id, 'en' );
		$check_pt = (int) pll_get_term( $en_id, 'pt' );
		if ( $check_en !== $en_id || $check_pt !== (int) $pt_term->term_id ) {
			echo "  ERROR {$pt_slug}: link verification failed.\n";
			continue;
		}

		echo "  OK {$pt_slug} (#{$pt_term->term_id}) <=> {$en_slug} (#{$en_id})\n";
	}
}

echo "\nDone.\n";
