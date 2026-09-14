<?php
/**
 * Event Town Term Cleanup Script.
 *
 * Cleans up contaminated conexao_town terms caused by Eircode fragments
 * being stored as town names during Eventbrite import.
 *
 * ROOT CAUSE: Conexao_Eventbrite_Normalizer::get_town() returns
 * locations[].locality / venue.address.city with only trim() — no Eircode
 * stripping. The main normalizer passes raw town through when the derived
 * town is empty. ensure_town() creates terms with any name.
 *
 * FIX: This script sanitizes existing terms by:
 *   1. Stripping Eircode fragments from contaminated town names
 *   2. Migrating event relationships to the clean town term
 *   3. Removing standalone Eircode terms (town undeterminable)
 *   4. Removing non-town terms (Co X, X Ireland)
 *
 * Usage:
 *   wp --allow-root eval-file scripts/cleanup-event-town-terms.php
 *   CLEANUP_DRY_RUN=true wp --allow-root eval-file scripts/cleanup-event-town-terms.php
 *
 * @package Conxao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;

$dry_run = ( 'true' === getenv( 'CLEANUP_DRY_RUN' ) );

if ( $dry_run ) {
	echo "=== DRY RUN MODE (no changes will be made) ===\n\n";
}

// Eircode regex (same as Conexao_Event_Address::EIRCODE_REGEX).
$eircode_regex = '/\b[A-Z]\d{2}\s?[A-Z0-9]{4}\b/i';

/**
 * Sanitize a town name by stripping Eircode fragments.
 *
 * Returns '' when the value contains no alphabetic content after
 * stripping (e.g. standalone Eircode "A92 DF7X." → "." → invalid).
 */
function sanitize_town_name( $town, $eircode_regex ) {
	$town = trim( $town );
	if ( '' === $town ) {
		return '';
	}
	$cleaned = preg_replace( $eircode_regex, '', $town );
	$cleaned = preg_replace( '/\s*,\s*|\s+/', ' ', $cleaned );
	$cleaned = trim( $cleaned, ' ,-.' );
	// A valid town name must contain at least one letter. Punctuation/
	// separator-only remnants (e.g. "." from "A92 DF7X.") are invalid.
	if ( '' === $cleaned || ! preg_match( '/[A-Za-z]/', $cleaned ) ) {
		return '';
	}
	return $cleaned;
}

/**
 * Check if a term name is a non-town value (Co X, Co.X, X Ireland, etc.).
 */
function is_non_town_name( $name ) {
	$name = trim( $name );
	if ( '' === $name ) {
		return true;
	}
	// "Co X", "Co. X" (with space) OR "Co.X" (no space, uppercase follows).
	// Does NOT match town names starting with "Co" like Cobh, Cork,
	// Collon, Cong, Corofin (lowercase letter follows "Co").
	if ( preg_match( '/^co\.?\s+/i', $name ) || preg_match( '/^co\.[A-Z]/', $name ) ) {
		return true;
	}
	if ( preg_match( '/,\s*ireland\s*$/i', $name ) || preg_match( '/\s+ireland\s*$/i', $name ) ) {
		return true;
	}
	return false;
}

/**
 * Strip non-town suffixes to extract the locality.
 */
function extract_locality_from_non_town( $name ) {
	$name = trim( $name );
	// "Co X", "Co. X", "Co.X" → undeterminable (county, not town).
	if ( preg_match( '/^co\.?\s+/i', $name ) || preg_match( '/^co\.[A-Z]/', $name ) ) {
		return '';
	}
	if ( preg_match( '/^(.+?)\s*,\s*ireland\s*$/i', $name, $m ) ) {
		return trim( $m[1] );
	}
	if ( preg_match( '/^(.+?)\s+ireland\s*$/i', $name, $m ) ) {
		return trim( $m[1] );
	}
	return '';
}

// Get all conexao_town terms.
$terms = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT t.term_id, t.name, t.slug, tt.count, tt.term_taxonomy_id
		FROM {$wpdb->terms} t
		INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
		WHERE tt.taxonomy = %s
		ORDER BY t.name",
		'conexao_town'
	)
);

if ( empty( $terms ) ) {
	echo "No conexao_town terms found.\n";
	return;
}

// Categorize terms.
$eircode_contaminated = [];
$standalone_eircode   = [];
$non_town_terms       = [];
$clean_terms          = [];

foreach ( $terms as $term ) {
	$name = $term->name;
	if ( preg_match( $eircode_regex, $name ) ) {
		$sanitized = sanitize_town_name( $name, $eircode_regex );
		if ( '' === $sanitized ) {
			$standalone_eircode[] = $term;
		} else {
			$eircode_contaminated[] = (object) array_merge(
				(array) $term,
				array( 'clean_name' => $sanitized )
			);
		}
	} elseif ( is_non_town_name( $name ) ) {
		$locality = extract_locality_from_non_town( $name );
		$non_town_terms[] = (object) array_merge(
			(array) $term,
			array( 'clean_name' => $locality )
		);
	} else {
		$clean_terms[] = $term;
	}
}

// Build a map of clean term names to term IDs.
$clean_term_map = [];
foreach ( $clean_terms as $term ) {
	$key = strtolower( $term->name );
	$clean_term_map[ $key ] = $term->term_id;
}

echo "=== EVENT TOWN TERM CLEANUP REPORT ===\n\n";
echo "Total conexao_town terms: " . count( $terms ) . "\n";
echo "Clean terms: " . count( $clean_terms ) . "\n";
echo "Eircode-contaminated terms: " . count( $eircode_contaminated ) . "\n";
echo "Standalone Eircode terms: " . count( $standalone_eircode ) . "\n";
echo "Non-town terms (Co X / X Ireland): " . count( $non_town_terms ) . "\n";
echo "\n";

/**
 * Find or create a clean term, returning its term_id.
 *
 * @param string $clean_name     Clean town name.
 * @param array  $clean_term_map Map of lowercase name → term_id (by ref).
 * @param bool   $dry_run        If true, do not create new terms.
 * @return int Term ID (0 if not found and dry_run, or on error).
 */
function get_or_create_clean_term( $clean_name, &$clean_term_map, $dry_run = false ) {
	global $wpdb;
	$clean_key = strtolower( $clean_name );
	if ( isset( $clean_term_map[ $clean_key ] ) ) {
		return $clean_term_map[ $clean_key ];
	}
	$existing = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT t.term_id FROM {$wpdb->terms} t
			INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
			WHERE tt.taxonomy = 'conexao_town' AND LOWER(t.name) = %s",
			$clean_key
		)
	);
	if ( $existing ) {
		$clean_term_map[ $clean_key ] = (int) $existing->term_id;
		return (int) $existing->term_id;
	}
	if ( $dry_run ) {
		return 0;
	}
	$new_term = wp_insert_term( $clean_name, 'conexao_town' );
	if ( is_wp_error( $new_term ) ) {
		return 0;
	}
	$clean_term_map[ $clean_key ] = (int) $new_term['term_id'];
	return (int) $new_term['term_id'];
}

/**
 * Migrate events from a contaminated term to a clean term.
 */
function migrate_events( $source_ttid, $target_term_id, $event_label ) {
	global $wpdb;
	$event_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d",
			$source_ttid
		)
	);
	$clean_ttid = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = 'conexao_town'",
			$target_term_id
		)
	);
	if ( ! $clean_ttid ) {
		return;
	}
	foreach ( $event_ids as $event_id ) {
		$has_clean = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				WHERE tr.object_id = %d AND tt.term_id = %d AND tt.taxonomy = 'conexao_town'",
				$event_id,
				$target_term_id
			)
		);
		if ( ! $has_clean ) {
			$wpdb->insert(
				$wpdb->term_relationships,
				array( 'object_id' => $event_id, 'term_taxonomy_id' => $clean_ttid ),
				array( '%d', '%d' )
			);
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->term_taxonomy} SET count = count + 1 WHERE term_taxonomy_id = %d",
					$clean_ttid
				)
			);
		}
		$wpdb->delete(
			$wpdb->term_relationships,
			array( 'object_id' => $event_id, 'term_taxonomy_id' => $source_ttid ),
			array( '%d', '%d' )
		);
		echo "    Event {$event_id}: {$event_label}\n";
	}
}

/**
 * Remove term relationships and delete a term.
 */
function remove_term_and_relationships( $term_taxonomy_id, $term_id ) {
	global $wpdb;
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d",
			$term_taxonomy_id
		)
	);
	$wpdb->delete( $wpdb->term_taxonomy, array( 'term_taxonomy_id' => $term_taxonomy_id ), array( '%d' ) );
	$wpdb->delete( $wpdb->terms, array( 'term_id' => $term_id ), array( '%d' ) );
}

// Process eircode-contaminated terms.
if ( ! empty( $eircode_contaminated ) ) {
	echo "--- Eircode-contaminated terms ---\n";
	foreach ( $eircode_contaminated as $term ) {
		$clean_name      = $term->clean_name;
		$target_term_id  = get_or_create_clean_term( $clean_name, $clean_term_map, $dry_run );
		echo "  [term_id={$term->term_id}] \"{$term->name}\" ({$term->count} events) → \"{$clean_name}\"";
		if ( $target_term_id ) {
			echo " (existing term_id={$target_term_id})";
		} else {
			echo " (will create new term)";
		}
		echo "\n";
		if ( ! $dry_run && $target_term_id ) {
			migrate_events( $term->term_taxonomy_id, $target_term_id, "migrated to term_id={$target_term_id}" );
			remove_term_and_relationships( $term->term_taxonomy_id, $term->term_id );
			echo "  Deleted contaminated term_id={$term->term_id}\n";
		}
	}
	echo "\n";
}

// Process standalone Eircode terms.
if ( ! empty( $standalone_eircode ) ) {
	echo "--- Standalone Eircode terms (town undeterminable) ---\n";
	foreach ( $standalone_eircode as $term ) {
		echo "  [term_id={$term->term_id}] \"{$term->name}\" ({$term->count} events) → REMOVE (town undeterminable)\n";
		if ( ! $dry_run ) {
			$event_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d",
					$term->term_taxonomy_id
				)
			);
			foreach ( $event_ids as $event_id ) {
				echo "    Event {$event_id}: removed town classification\n";
			}
			remove_term_and_relationships( $term->term_taxonomy_id, $term->term_id );
			echo "  Deleted standalone Eircode term_id={$term->term_id}\n";
		}
	}
	echo "\n";
}

// Process non-town terms.
if ( ! empty( $non_town_terms ) ) {
	echo "--- Non-town terms (Co X / X Ireland) ---\n";
	foreach ( $non_town_terms as $term ) {
		$clean_name = $term->clean_name;
		if ( '' === $clean_name ) {
			echo "  [term_id={$term->term_id}] \"{$term->name}\" ({$term->count} events) → REMOVE (county marker, not town)\n";
			if ( ! $dry_run ) {
				$event_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d",
						$term->term_taxonomy_id
					)
				);
				foreach ( $event_ids as $event_id ) {
					echo "    Event {$event_id}: removed town classification\n";
				}
				remove_term_and_relationships( $term->term_taxonomy_id, $term->term_id );
				echo "  Deleted non-town term_id={$term->term_id}\n";
			}
		} else {
			$target_term_id = get_or_create_clean_term( $clean_name, $clean_term_map, $dry_run );
			echo "  [term_id={$term->term_id}] \"{$term->name}\" ({$term->count} events) → \"{$clean_name}\"";
			if ( $target_term_id ) {
				echo " (existing term_id={$target_term_id})";
			} else {
				echo " (will create new term)";
			}
			echo "\n";
			if ( ! $dry_run && $target_term_id ) {
				migrate_events( $term->term_taxonomy_id, $target_term_id, "migrated to term_id={$target_term_id}" );
				remove_term_and_relationships( $term->term_taxonomy_id, $term->term_id );
				echo "  Deleted non-town term_id={$term->term_id}\n";
			}
		}
	}
	echo "\n";
}

// Final verification.
echo "=== VERIFICATION ===\n";
$remaining = $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->terms} t
	INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
	WHERE tt.taxonomy = 'conexao_town'
	AND (
		t.name REGEXP '[A-Z][0-9]{2}[[:space:]]?[A-Z0-9]{4}'
		OR t.name REGEXP '^[A-Z][0-9]{2}[[:space:]]'
		OR t.name REGEXP '^co\\.?[[:space:]]'
		OR t.name REGEXP '^co\\.[A-Z]'
		OR t.name LIKE '%, Ireland'
		OR t.name LIKE '% Ireland'
	)"
);
$total = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'conexao_town'" );
echo "Remaining contaminated terms: {$remaining}\n";
echo "Total conexao_town terms: {$total}\n";
echo $dry_run ? "\n=== DRY RUN COMPLETE (no changes made) ===\n" : "\n=== CLEANUP COMPLETE ===\n";
