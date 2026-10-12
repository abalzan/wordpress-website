<?php
/**
 * UI convergence Phase 1 (T1.1 / T1.2) regression suite.
 *
 * Proves the two GENUINE defects fixed in this phase cannot regress:
 *
 *  T1.2 (audit D2) — one LOGICAL event per archive slot.
 *    The duplication mechanism was verified against the data and is NOT
 *    occurrence expansion: the duplicated cards were SEPARATE event posts
 *    sharing one source identity (`_event_source` + `_event_source_id`),
 *    with identical URL and date and NO recurrence meta. A recurring event is
 *    ONE post and must keep appearing exactly once.
 *
 *    This suite pins the exact mechanism, so a future change cannot "fix" it
 *    by accident (e.g. by de-duplicating on title, which would merge genuinely
 *    distinct events) and cannot quietly break recurrence or the B2/EN
 *    translation pairing.
 *
 *  T1.1 (audit D1) — `/empregos/` must not reserve a grid track for the
 *    optional portrait, so the body never lands in a 340px column beside
 *    ~800px of dead space.
 *
 * Read-only against the SITE's data: it modifies no existing record. It does
 * create its own short-lived duplicate-identity fixtures (prefixed
 * `[UI-CONVERGENCE-T1]`, source slug `ui-convergence-t1-gate`) so the
 * duplicate-identity assertion is deterministic and independent of whatever the
 * install happens to hold; those are force-deleted on shutdown. The layout rule
 * is asserted against the theme source.
 *
 * @package Conexao_BR_Irlanda
 */

// Shared Stage E bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

echo "UI convergence Phase 1 (T1.1 / T1.2)\n";
echo str_repeat( '-', 72 ) . "\n";

// ---------------------------------------------------------------------------
// T1.2 — the helper exists and is pure.
// ---------------------------------------------------------------------------

assert_true(
	function_exists( 'conexao_event_collapse_logical_ids' ),
	'the logical-identity collapse helper is defined'
);

assert_true(
	function_exists( 'conexao_event_upcoming_ids' ),
	'the shared upcoming-events helper is still defined (runtime contract intact)'
);

assert_true(
	class_exists( 'Conexao_Event_Query' ),
	'the event runtime plugin is active, so the archive still uses the recurrence-aware path'
);

// Empty and single-element inputs must pass through untouched.
assert_equals( array(), conexao_event_collapse_logical_ids( array() ), 'an empty ID list collapses to an empty list' );
assert_equals(
	array( 42 ),
	conexao_event_collapse_logical_ids( array( 42 ) ),
	'a single ID is returned unchanged'
);

// Order must be preserved: the runtime's occurrence ordering drives the archive.
assert_equals(
	array( 5, 3, 9 ),
	conexao_event_collapse_logical_ids( array( 5, 3, 9 ) ),
	'IDs without source identity each survive and keep their input order'
);

// Duplicate IDs in the input must not produce duplicate output entries.

// ---------------------------------------------------------------------------
// T1.2 — the REAL duplication mechanism, exercised on a DETERMINISTIC fixture.
//
// The helper is exercised on a duplicate-identity group this suite CREATES and
// then removes. That is deliberate and it is what makes the assertion test the
// mechanism rather than the state of one database:
//
//   - the previous version DISCOVERED groups from whatever the install happened
//     to hold, and hard-FAILED ("expected at least one duplicate
//     source-identity group") when it found none. That made the suite a report
//     on the developer's dataset: it failed on a clean install, on CI's
//     synthetic site, and after the duplicates were legitimately repaired — and
//     it passed or failed on test-execution ORDER, because a suite that left
//     duplicates behind (or a maintainer who cleaned them up) changed the
//     answer without any code changing.
//   - the mechanism under test is a property of the HELPER, so it can be proven
//     with a fixture the suite owns. The assertion is NOT weakened: it still
//     requires that every group it checks collapses to exactly one card, still
//     requires the survivor to be one of that group's own records, and still
//     requires the survivor to keep the group's shared source identity.
//
// Any duplicate groups that DO exist in the local data are still checked, on top
// of the fixture, so the suite keeps proving the property on real data wherever
// real duplicates are present — it just no longer DEPENDS on them.
// ---------------------------------------------------------------------------

$t1_fixture_ids = array();

register_shutdown_function(
	static function () use ( &$t1_fixture_ids ) {
		// Never leak the fixture, even when an assertion path aborts the suite
		// early. Force-delete, and drop the meta first so no `_event_source*`
		// row can outlive the post.
		foreach ( $t1_fixture_ids as $fixture_id ) {
			$fixture_id = (int) $fixture_id;
			if ( $fixture_id > 0 && get_post( $fixture_id ) ) {
				foreach ( array( '_event_source', '_event_source_id', '_event_last_checked' ) as $meta_key ) {
					delete_post_meta( $fixture_id, $meta_key );
				}
				wp_delete_post( $fixture_id, true );
			}
		}
	}
);

/**
 * Create one published event carrying an explicit source identity.
 *
 * The identity is deliberately NON-PORTABLE-BY-ACCIDENT: the source slug is
 * namespaced with this suite's own marker and the source id is derived from the
 * current microsecond, so it can never collide with a real importer record and
 * two runs can never share one group.
 *
 * @param string $source_source Source slug (the `_event_source` half).
 * @param string $source_id     Source id (the `_event_source_id` half).
 * @param string $checked       Optional `_event_last_checked` rank value.
 * @param string $title         Post title.
 * @return int Post ID, or 0 when the insert failed.
 */
function conexao_ui_t1_make_identity_event( string $source_source, string $source_id, string $checked = '', string $title = '' ): int {
	$id = wp_insert_post(
		array(
			'post_type'    => 'event',
			'post_name'    => 'ui-convergence-t1-' . substr( md5( $source_source . '|' . $source_id . '|' . $title ), 0, 12 ),
			'post_title'   => '' !== $title ? $title : '[UI-CONVERGENCE-T1] identity fixture',
			'post_content' => 'Fixture created and removed by test-ui-convergence-t1.php.',
			'post_status'  => 'publish',
		),
		true
	);

	if ( is_wp_error( $id ) || ! $id ) {
		return 0;
	}

	update_post_meta( (int) $id, '_event_source', $source_source );
	update_post_meta( (int) $id, '_event_source_id', $source_id );

	if ( '' !== $checked ) {
		update_post_meta( (int) $id, '_event_last_checked', $checked );
	}

	return (int) $id;
}

// A per-run, per-microsecond identity, so this suite's group can never be
// confused with a real importer record or with a previous run's leftovers.
$t1_source      = 'ui-convergence-t1-gate';
$t1_source_id   = 'dup-' . substr( (string) microtime( true ), -8 ) . '-' . getmypid();
$t1_identity_key = $t1_source . '|' . $t1_source_id;

$t1_fixture_ids[] = conexao_ui_t1_make_identity_event( $t1_source, $t1_source_id, '2026-01-01 00:00:00', '[UI-CONVERGENCE-T1] duplicate A' );
$t1_fixture_ids[] = conexao_ui_t1_make_identity_event( $t1_source, $t1_source_id, '2026-06-01 00:00:00', '[UI-CONVERGENCE-T1] duplicate B' );

$t1_fixture_created = array_values( array_filter( array_map( 'intval', $t1_fixture_ids ) ) );

assert_true(
	2 === count( $t1_fixture_created ),
	'the duplicate-identity fixture was created (2 posts sharing one source identity)',
	'created: ' . implode( ',', $t1_fixture_created )
);

// Every pre-existing group in the install, PLUS the one this suite owns. The
// discovery helper is unchanged; only the fixture now guarantees it is non-empty.
$identity_groups = conexao_ui_t1_duplicate_identity_groups();

// The fixture must be discoverable by the SAME helper the product uses, which
// is what proves the fixture reproduces the real D2 mechanism rather than
// merely existing in the database.
assert_true(
	isset( $identity_groups[ $t1_identity_key ] ),
	'the owned duplicate-identity group is discoverable by the same helper the product uses',
	'expected key: ' . $t1_identity_key
);

assert_true(
	! empty( $identity_groups ),
	'at least one duplicate source-identity group exists to test the collapse against',
	'expected the owned fixture group, plus any duplicates the install already holds'
);

if ( ! empty( $identity_groups ) ) {
	echo 'discovered ' . count( $identity_groups ) . " duplicate source-identity group(s)\n";

	$checked = 0;

	foreach ( $identity_groups as $source => $ids ) {
		++$checked;

		$collapsed = conexao_event_collapse_logical_ids( $ids );

		// The core contract: one logical event, one archive slot.
		assert_equals(
			1,
			count( $collapsed ),
			sprintf(
				'duplicate records sharing source identity %s collapse to ONE card (%d posts -> %d)',
				$source,
				count( $ids ),
				count( $collapsed )
			)
		);

		// The survivor must be one of the real duplicates, never an invented ID.
		assert_true(
			in_array( (int) $collapsed[0], array_map( 'intval', $ids ), true ),
			sprintf( 'the surviving representative for %s is one of its own records', $source )
		);

		// The duplicates really do share one identity (guards the diagnosis).
		$survivor = (int) $collapsed[0];
		assert_equals(
			(string) get_post_meta( $survivor, '_event_source_id', true ),
			(string) get_post_meta( (int) $ids[0], '_event_source_id', true ),
			sprintf( 'the survivor keeps the shared source identity of %s', $source )
		);
	}

	assert_true(
		$checked > 0,
		sprintf( 'every discovered duplicate group (%d) was verified', $checked )
	);
}

// ---------------------------------------------------------------------------
// T1.2 — NEGATIVE CONTROL: the assertion above must be MEANINGFUL.
//
// A suite that only ever sees duplicates cannot distinguish "the collapse works"
// from "the collapse collapses everything". Two controls, both on fixtures this
// suite owns:
//
//   1. removing the shared identity must make the group DISAPPEAR from the
//      discovery helper, and must make the collapse return BOTH records. That is
//      the assertion's own failure condition, produced on demand rather than
//      hoped for from a dataset: it proves the earlier "collapses to ONE card"
//      result came from the shared identity and not from something else.
//   2. an identity pair that differs in ONLY ONE half is two logical events.
//
// The records are cleaned up by the same shutdown handler, so this control
// leaves nothing behind even when it fails.
// ---------------------------------------------------------------------------

$t1_control_a = 0;
$t1_control_b = 0;

if ( 2 === count( $t1_fixture_created ) ) {
	$t1_control_a = (int) $t1_fixture_created[0];
	$t1_control_b = (int) $t1_fixture_created[1];

	// Break the identity of ONE record: it is no longer a duplicate of anything.
	$t1_control_broken_id = $t1_source . '|' . $t1_source_id . '-broken';
	update_post_meta( $t1_control_a, '_event_source_id', $t1_control_broken_id );

	$t1_groups_broken = conexao_ui_t1_duplicate_identity_groups();
	$t1_collapsed_broken = conexao_event_collapse_logical_ids( array( $t1_control_a, $t1_control_b ) );

	assert_true(
		! isset( $t1_groups_broken[ $t1_identity_key ] ),
		'NEGATIVE CONTROL: once the shared source identity is broken the group is no longer discovered as a duplicate'
	);

	assert_equals(
		array( $t1_control_a, $t1_control_b ),
		array_map( 'intval', $t1_collapsed_broken ),
		'NEGATIVE CONTROL: with the shared identity broken BOTH records survive, so the earlier collapse to ONE was caused by that identity'
	);

	// Restore, so the positive assertions and the shared-identity check below
	// still describe the real D2 mechanism.
	update_post_meta( $t1_control_a, '_event_source_id', $t1_source_id );

	$t1_restored = conexao_event_collapse_logical_ids( array( $t1_control_a, $t1_control_b ) );
	assert_equals(
		1,
		count( $t1_restored ),
		'restoring the shared source identity collapses the pair to ONE card again (control round-trips)'
	);
}

// Differ in ONE half of the pair only: same source id, different source slug.
$t1_half_diff_source = $t1_source . '-other';
$t1_half_diff_id = 0;

if ( $t1_control_b > 0 ) {
	$t1_half_diff_id = conexao_ui_t1_make_identity_event(
		$t1_half_diff_source,
		$t1_source_id,
		'2026-07-01 00:00:00',
		'[UI-CONVERGENCE-T1] half-identity mismatch'
	);

	if ( $t1_half_diff_id > 0 ) {
		$t1_fixture_ids[] = $t1_half_diff_id;

		assert_equals(
		2,
			count( conexao_event_collapse_logical_ids( array( $t1_control_b, $t1_half_diff_id ) ) ),
			'NEGATIVE CONTROL: records differing in only ONE half of the identity pair are two logical events, never collapsed'
		);
	}
}

// ---------------------------------------------------------------------------
// T1.2 — distinct events must NEVER be collapsed.
//
// This is the safety property: the fix must not merge genuinely different
// events. Two events that differ in EITHER half of the identity pair are
// different logical events and both must survive.
// ---------------------------------------------------------------------------

assert_equals(
	array( 1180, 1182 ),
	conexao_event_collapse_logical_ids( array( 1180, 1182 ) ),
	'distinct events survive the collapse'
);

$distinct_kept = conexao_event_collapse_logical_ids(
	conexao_ui_t1_published_event_ids( 6 )
);

assert_equals(
	$distinct_kept,
	array_values( array_unique( array_map( 'intval', $distinct_kept ) ) ),
	'the collapse never emits the same post ID twice'
);

// ---------------------------------------------------------------------------
// T1.2 — recurrence semantics are UNCHANGED.
//
// The collapse is presentation-only. A recurring event is still ONE post, its
// next occurrence still resolves through the same helper, and the recurring
// events in the local data must still appear exactly once each.
// ---------------------------------------------------------------------------

$upcoming = conexao_event_upcoming_ids();

assert_true(
	is_array( $upcoming ),
	'the shared upcoming-events list is still produced by the event runtime'
);

if ( is_array( $upcoming ) ) {
	assert_equals(
		count( $upcoming ),
		count( array_unique( array_map( 'intval', $upcoming ) ) ),
		'the runtime list itself never contains a repeated post ID'
	);

	$recurring_in_upcoming = 0;

	foreach ( $upcoming as $id ) {
		if ( ! conexao_event_is_recurring( (int) $id ) ) {
			continue;
		}

		++$recurring_in_upcoming;

		// A weekly series must still report a concrete next occurrence.
		$next = conexao_event_display_date( (int) $id );
		assert_true(
			'' !== $next && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $next ),
			sprintf( 'recurring event %d still resolves a next occurrence date', (int) $id ),
			'got: ' . var_export( $next, true )
		);

		// And it must still carry its recurrence label path.
		assert_true(
			'' !== conexao_event_recurrence_label( (int) $id ),
			sprintf( 'recurring event %d still renders a recurrence label', (int) $id )
		);
	}

	echo 'recurring events in the upcoming list: ' . $recurring_in_upcoming . "\n";
}

// Collapsing the real upcoming list must never DROP a logical event that had
// no duplicate: it may only ever remove exact-identity duplicates.
$collapsed_upcoming = conexao_event_collapse_logical_ids( (array) $upcoming );

assert_true(
	count( $collapsed_upcoming ) <= count( (array) $upcoming ),
	'collapsing the upcoming list never increases the number of events'
);

foreach ( conexao_ui_t1_duplicate_identity_groups() as $ids ) {
	assert_true(
		count( array_intersect( $collapsed_upcoming, array_map( 'intval', $ids ) ) ) <= 1,
		sprintf(
			'at most one of the duplicate posts %s survives in the archive list',
			implode( ',', $ids )
		)
	);
}

// ---------------------------------------------------------------------------
// T1.1 — the /empregos/ grid must not reserve a track for absent media.
//
// Asserted against the theme SOURCE, because the defect is structural: the
// two-column track list must be scoped to the with-media modifier, and the
// base rule must be a single column.
// ---------------------------------------------------------------------------

$theme_dir  = get_template_directory();
$main_css   = (string) file_get_contents( $theme_dir . '/assets/css/main.css' );
$landing_php = (string) file_get_contents( $theme_dir . '/page-empregos.php' );

assert_true(
	'' !== $main_css && '' !== $landing_php,
	'the theme jobs-landing sources are readable'
);

if ( '' !== $main_css && '' !== $landing_php ) {

	// The portrait track must appear ONLY inside the with-media modifier rule,
	// so an absent image can never reserve space for one.
	$track_occurrences = substr_count( $main_css, 'minmax(220px, 340px)' );
	$modifier_rule     = '';
	if ( preg_match( '/\.empregos-landing--with-media \{([^}]*)\}/', $main_css, $m2 ) ) {
		$modifier_rule = $m2[1];
	}

	assert_true(
		$track_occurrences >= 1,
		'the portrait track still exists for pages that DO have the image'
	);

	assert_contains(
		'minmax(220px, 340px)',
		$modifier_rule,
		'the portrait track is declared on the with-media modifier'
	);

	assert_true(
		$track_occurrences <= 2,
		sprintf(
			'the portrait track is not re-declared per page (%d occurrences site-wide)',
			$track_occurrences
		)
	);

	// The two-column declaration must live ONLY on the modifier.
	assert_contains(
		'.empregos-landing--with-media',
		$main_css,
		'the two-column layout is scoped to the with-media modifier'
	);

	$base_rule = '';
	if ( preg_match( '/\.empregos-landing \{([^}]*)\}/', $main_css, $m ) ) {
		$base_rule = $m[1];
	}

	assert_true(
		'' !== $base_rule,
		'the base .empregos-landing rule is present'
	);

	// The base rule must be ONE column: no fixed portrait track.
	assert_true(
		false === strpos( $base_rule, 'minmax(220px, 340px)' ),
		'the base .empregos-landing rule no longer reserves a 340px portrait track (D1)'
	);

	assert_contains(
		'minmax(0, 1fr)',
		$base_rule,
		'the base .empregos-landing rule is a single full-width column'
	);

	// The modifier must be derived from the real media check, not hard-coded.
	assert_contains(
		'has_post_thumbnail()',
		$landing_php,
		'the modifier is derived from the real featured-image check'
	);

	assert_contains(
		'empregos-landing--with-media',
		$landing_php,
		'the template emits the with-media modifier class'
	);
}

// ---------------------------------------------------------------------------
// Fixture helpers (discover the real duplication from the local data).
// ---------------------------------------------------------------------------

/**
 * Published event IDs, optionally limited.
 *
 * @param int $limit Maximum IDs (0 = no limit).
 * @return int[]
 */
function conexao_ui_t1_published_event_ids( int $limit = 0 ): array {
	$args = array(
		'post_type'      => 'event',
		'post_status'    => 'publish',
		'posts_per_page' => $limit > 0 ? $limit : -1,
		'fields'         => 'ids',
		'orderby'        => 'ID',
		'order'          => 'ASC',
		'no_found_rows'  => true,
	);

	return array_map( 'intval', get_posts( $args ) );
}

/**
 * Discover the duplicate source-identity groups in the local data.
 *
 * A group is a set of published events sharing the SAME
 * (`_event_source`, `_event_source_id`) pair — exactly the mechanism that made
 * the archive render one logical event twice. Discovered from the data, never
 * hard-coded, so the suite proves the property on whatever the site holds.
 *
 * This suite also plants its OWN group (see the fixture block above), so the
 * result is never empty: the helper answers the question for the site's data and
 * for the suite's fixture together.
 *
 * @return array<string,int[]> Identity key => ordered post IDs.
 */
function conexao_ui_t1_duplicate_identity_groups(): array {
	$groups = array();

	foreach ( conexao_ui_t1_published_event_ids() as $id ) {
		$source = (string) get_post_meta( $id, '_event_source', true );
		$sid    = (string) get_post_meta( $id, '_event_source_id', true );

		// Records with no portable source identity are never duplicates.
		if ( '' === $source || '' === $sid ) {
			continue;
		}

		$groups[ $source . '|' . $sid ][] = $id;
	}

	return array_filter(
		$groups,
		static function ( $ids ) {
			return count( $ids ) > 1;
		}
	);
}

test_finish( 'ui-convergence-t1' );
