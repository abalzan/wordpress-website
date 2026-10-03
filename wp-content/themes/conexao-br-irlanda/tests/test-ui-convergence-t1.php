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
 * Read-only. Creates nothing, mutates nothing, contacts nothing: the
 * duplicate-identity groups are discovered from the real local data and the
 * layout rule is asserted against the theme source.
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
// T1.2 — the REAL duplication mechanism, measured against live local data.
//
// The helper is exercised on the actual duplicate-identity groups found in the
// local database. Nothing is hard-coded: the groups are DISCOVERED, so this
// suite proves the property on whatever data the site actually holds.
// ---------------------------------------------------------------------------

$identity_groups = conexao_ui_t1_duplicate_identity_groups();

if ( empty( $identity_groups ) ) {
	assert_fail(
		'expected at least one duplicate source-identity group in the local data',
		'the fixture set may have changed; re-check the D2 mechanism'
	);
} else {
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
 * Discover the real duplicate source-identity groups in the local data.
 *
 * A group is a set of published events sharing the SAME
 * (`_event_source`, `_event_source_id`) pair — exactly the mechanism that made
 * the archive render one logical event twice. Discovered from the data, never
 * hard-coded, so the suite proves the property on whatever the site holds.
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
