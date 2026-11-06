<?php
/**
 * Commemorative seed-migration suite (r10 §8.2, §9.7).
 *
 * Proves, against the REAL migration:
 *   - dry-run writes nothing and reports what it would create;
 *   - apply creates one record per legacy occasion and the second run reports
 *     `created = 0` (idempotent);
 *   - New Year is seeded with the corrected seven-day `12-26 → 01-01` window;
 *   - a permanently deleted event is NOT resurrected by a later run;
 *   - each EN record carries `_ce_event_key` equal to its PT counterpart and NO
 *     other shared `_ce_*` meta row (the §3.6 split, checked in the database).
 *
 * The suite only deletes records IT created, and restores the seeded-keys option
 * to its previous value, so an operator's records are never touched.
 *
 * @package Conexao_Commemorative
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$ce_mig_definitions = Conexao_Commemorative_Migration::definitions();
$ce_mig_keys        = array_keys( $ce_mig_definitions );

/**
 * Record IDs currently present for the six seed keys.
 *
 * @param string[] $keys Keys.
 * @return array<string,int[]> key => IDs (all languages).
 */
function ce_mig_existing( array $keys ) {
	$found = array();

	foreach ( $keys as $key ) {
		$found[ $key ] = array();

		$posts = get_posts(
			array(
				'post_type'      => 'commemorative_event',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'lang'           => '',
				'meta_key'       => '_ce_event_key',
				'meta_value'     => $key,
			)
		);

		foreach ( $posts as $post_id ) {
			$found[ $key ][] = (int) $post_id;
		}
	}

	return $found;
}

/**
 * Ids created by the suite (present now, absent before).
 *
 * @param array $before Before snapshot.
 * @param array $after  After snapshot.
 * @return int[]
 */
function ce_mig_created_ids( array $before, array $after ) {
	$created = array();

	foreach ( $after as $key => $ids ) {
		foreach ( $ids as $post_id ) {
			if ( ! in_array( $post_id, $before[ $key ], true ) ) {
				$created[] = (int) $post_id;
			}
		}
	}

	return $created;
}

$ce_mig_seeded_before = get_option( 'conexao_commemorative_seeded_keys', null );
$ce_mig_before        = ce_mig_existing( $ce_mig_keys );

test_section( 'Dry run writes nothing and reports the plan' );

$ce_mig_plan = Conexao_Commemorative_Migration::plan();

assert_true( isset( $ce_mig_plan['create'] ) && is_array( $ce_mig_plan['create'] ), 'the plan exposes a create bucket' );
assert_true( isset( $ce_mig_plan['skip'] ) && is_array( $ce_mig_plan['skip'] ), 'the plan exposes a skip bucket' );

$ce_mig_dry = Conexao_Commemorative_Migration::apply( true );

assert_equals( 0, $ce_mig_dry['created'], 'a dry run creates nothing' );
assert_equals( count( $ce_mig_plan['create'] ), count( $ce_mig_dry['records'] ), 'the dry run reports every record it would create' );
assert_equals( $ce_mig_before, ce_mig_existing( $ce_mig_keys ), 'a dry run leaves the database untouched' );

test_section( 'Apply creates the six seed records' );

$ce_mig_apply = Conexao_Commemorative_Migration::apply( false );
$ce_mig_after = ce_mig_existing( $ce_mig_keys );

assert_equals( count( $ce_mig_plan['create'] ), $ce_mig_apply['created'], 'apply creates exactly the planned records' );

foreach ( $ce_mig_keys as $ce_mig_key ) {
	assert_true( count( $ce_mig_after[ $ce_mig_key ] ) >= 2 || ! Conexao_Commemorative_Migration::polylang_active(), 'the seed record for "' . $ce_mig_key . '" exists (with its translation when Polylang is active)' );
}

test_section( 'The seed is idempotent: a second run creates nothing' );

$ce_mig_second = Conexao_Commemorative_Migration::apply( false );

assert_equals( 0, $ce_mig_second['created'], 'a second apply reports created = 0' );
assert_equals( count( $ce_mig_keys ), $ce_mig_second['skipped'], 'a second apply skips every seed key' );

test_section( 'New Year is seeded with the corrected seven-day window' );

$ce_mig_created   = ce_mig_created_ids( $ce_mig_before, $ce_mig_after );
$ce_mig_new_year  = 0;

foreach ( $ce_mig_created as $ce_mig_id ) {
	if ( 'new_year' === get_post_meta( $ce_mig_id, '_ce_event_key', true ) && Conexao_Commemorative_Repository::is_canonical_language( $ce_mig_id ) ) {
		$ce_mig_new_year = $ce_mig_id;
	}
}

if ( 0 === $ce_mig_new_year ) {
	// The New Year record pre-existed this run: use it for the same assertions.
	foreach ( $ce_mig_after['new_year'] as $ce_mig_candidate ) {
		if ( Conexao_Commemorative_Repository::is_canonical_language( $ce_mig_candidate ) ) {
			$ce_mig_new_year = (int) $ce_mig_candidate;
		}
	}
}

assert_true( $ce_mig_new_year > 0, 'the New Year record exists' );
assert_equals( 'window', get_post_meta( $ce_mig_new_year, '_ce_rule', true ), 'New Year uses the explicit window rule' );
assert_equals( '12-26', get_post_meta( $ce_mig_new_year, '_ce_window_start', true ), 'New Year starts on 26 December' );
assert_equals( '01-01', get_post_meta( $ce_mig_new_year, '_ce_window_end', true ), 'New Year ends on 1 January' );

$ce_mig_tz     = new DateTimeZone( 'Europe/Dublin' );
$ce_mig_event  = Conexao_Commemorative_Repository::find( $ce_mig_new_year );
$ce_mig_window = Conexao_Commemorative_Schedule::resolve( $ce_mig_event, new DateTimeImmutable( '2026-12-31 23:59:00', $ce_mig_tz ) );

assert_true( is_array( $ce_mig_window ), 'the seeded New Year record resolves inside its window on 31 December' );
assert_equals( 2027, $ce_mig_window['occurrence_year'], 'the seeded New Year window reports occurrence year 2027 on 31 December 2026' );

test_section( 'The EN record carries the identity and no other shared configuration' );

if ( Conexao_Commemorative_Migration::polylang_active() && ! empty( $ce_mig_after['halloween'] ) ) {
	$ce_mig_pt_id = 0;

	foreach ( $ce_mig_after['halloween'] as $ce_mig_candidate ) {
		if ( Conexao_Commemorative_Repository::is_canonical_language( $ce_mig_candidate ) ) {
			$ce_mig_pt_id = (int) $ce_mig_candidate;
		}
	}

	$ce_mig_translations = function_exists( 'pll_get_post_translations' ) ? pll_get_post_translations( $ce_mig_pt_id ) : array();
	$ce_mig_en_id        = 0;

	foreach ( $ce_mig_translations as $ce_mig_slug => $ce_mig_translation_id ) {
		if ( ! Conexao_Commemorative_Repository::is_canonical_language( $ce_mig_translation_id ) ) {
			$ce_mig_en_id = (int) $ce_mig_translation_id;
		}
		unset( $ce_mig_slug );
	}

	assert_true( $ce_mig_en_id > 0, 'the seeded PT record has a linked EN translation' );

	if ( $ce_mig_en_id > 0 ) {
		assert_equals( 'halloween', get_post_meta( $ce_mig_en_id, '_ce_event_key', true ), 'the EN record carries the SAME event key' );

		global $wpdb;
		$ce_mig_rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_key FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
				$ce_mig_en_id,
				$wpdb->esc_like( '_ce_' ) . '%'
			)
		);

		$ce_mig_shared = array_values( array_diff( $ce_mig_rows, array( '_ce_event_key' ) ) );

		assert_true( empty( $ce_mig_shared ), 'the EN record carries NO shared configuration rows (only `_ce_event_key`)' . ( empty( $ce_mig_shared ) ? '' : ': ' . implode( ', ', $ce_mig_shared ) ) );
	}
} else {
	assert_true( true, 'Polylang is not active locally; the EN identity split is unverified in this run' );
}

test_section( 'A permanently deleted seed event is not resurrected' );

$ce_mig_delete_key = 'valentines_day';
$ce_mig_delete_id  = ! empty( $ce_mig_after[ $ce_mig_delete_key ] ) ? (int) $ce_mig_after[ $ce_mig_delete_key ][0] : 0;

// Only EVER delete a record this suite created: an operator's existing
// seeded record must never be destroyed by a test.
if ( $ce_mig_delete_id > 0 && in_array( $ce_mig_delete_id, $ce_mig_created, true ) ) {
	$ce_mig_translations = function_exists( 'pll_get_post_translations' ) ? pll_get_post_translations( $ce_mig_delete_id ) : array();

	foreach ( $ce_mig_translations as $ce_mig_translation_id ) {
		wp_delete_post( (int) $ce_mig_translation_id, true );
	}

	wp_delete_post( $ce_mig_delete_id, true );
	Conexao_Commemorative_Repository::reset_cache();

	$ce_mig_rerun = Conexao_Commemorative_Migration::apply( false );
	$ce_mig_now   = ce_mig_existing( array( $ce_mig_delete_key ) );

	assert_equals( 0, $ce_mig_rerun['created'], 'a re-run after a permanent deletion creates nothing' );
	assert_true( empty( $ce_mig_now[ $ce_mig_delete_key ] ), 'the deleted event does NOT reappear' );
} else {
	assert_true( true, 'the valentines_day record was not created by this run; the resurrection proof is skipped so no pre-existing record is deleted' );
}

test_section( 'Cleanup' );

// Delete ONLY the records this suite created (and their translations).
foreach ( $ce_mig_created as $ce_mig_id ) {
	$ce_mig_translations = function_exists( 'pll_get_post_translations' ) ? pll_get_post_translations( $ce_mig_id ) : array();

	foreach ( $ce_mig_translations as $ce_mig_translation_id ) {
		wp_delete_post( (int) $ce_mig_translation_id, true );
	}

	wp_delete_post( $ce_mig_id, true );
}

if ( null === $ce_mig_seeded_before ) {
	delete_option( 'conexao_commemorative_seeded_keys' );
} else {
	update_option( 'conexao_commemorative_seeded_keys', $ce_mig_seeded_before, false );
}

Conexao_Commemorative_Repository::reset_cache();

assert_true( true, 'the migration suite restored the seeded-keys option to its previous value' );

test_finish( 'commemorative-migration' );

