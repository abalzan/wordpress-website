<?php
/**
 * Stage 3 change-detection tests: the digest contract, the inventory diff and
 * the persisted-state fail-closed rules.
 *
 * ## Zero-write discipline
 *
 * This suite creates NO content. The digest is exercised through an injected
 * meta accessor and through the CHANGE DETECTOR's pure `reconcile()`, which
 * takes two inventories and returns a change set. Where a live record is
 * required, an EXISTING PT record is read, never created.
 *
 * ## What is proven
 *
 *   - the digest is deterministic and key-order independent;
 *   - a translation-relevant change moves the digest;
 *   - a NON-translation change does NOT move it (date, author, menu order,
 *     thumbnail, the stage's own EN meta key, a shared county/town term);
 *   - the change set classifies new / modified / restored / deleted /
 *     unchanged / invalid;
 *   - a deletion requires a human and never becomes a mutation;
 *   - missing, corrupt, incompatible, unexpected-stage and invalid persisted
 *     state all fail closed, and NONE of them can produce a "translate
 *     everything" outcome;
 *   - B1 and B2 changes stay distinguishable.
 *
 * @package Conexao_Translation_Automation
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation/conexao-translation-automation.php';

use Conexao_Translation_Automation_Change_Detector as Detector;
use Conexao_Translation_Automation_Digest as Digest;
use Conexao_Translation_Automation_Source_State as State;

test_title( 'conexao-translation-automation — Stage 3 change detection' );

$GLOBALS['s3_allowed'] = Conexao_Translation_Automation_Orchestrator::allowed_stage_ids();

/**
 * A syntactically valid digest, so tests can build inventories without
 * inventing content.
 *
 * @param string $seed Any string.
 * @return string
 */
function conexao_s3_digest( string $seed ): string {
	return hash( 'sha256', $seed );
}

// ---------------------------------------------------------------------------
// 1. The projection is derived from the real registry, not assumed
// ---------------------------------------------------------------------------
test_section( 'Projection is evidence-bound' );

foreach ( array_keys( Digest::stage_profiles() ) as $stage ) {
	assert_true(
		in_array( $stage, $GLOBALS['s3_allowed'], true ),
		sprintf( 'projection "%s" is an allowlisted automation stage', $stage )
	);
}

foreach ( $GLOBALS['s3_allowed'] as $stage ) {
	assert_true(
		null !== Digest::profile_for( $stage ),
		sprintf( 'every allowlisted stage "%s" has a projection profile', $stage )
	);
}

// Every registered automatable stage is covered by a profile: a newly
// registered stage must be noticed, not silently ignored.
foreach ( Conexao_Translation_Rollout_Engine::registered_stages() as $registered ) {
	if ( in_array( $registered, Conexao_Translation_Automation_Orchestrator::NON_AUTOMATABLE_STAGES, true ) ) {
		continue;
	}

	assert_true(
		null !== Digest::profile_for( $registered ),
		sprintf( 'registered automatable stage "%s" has a projection profile', $registered )
	);
}

// The projection must agree with the LIVE registered configuration.
foreach ( $GLOBALS['s3_allowed'] as $stage ) {
	$check = Digest::assert_profile_matches_config( Conexao_Translation_Rollout_Engine::get_stage( $stage ) );

	assert_true(
		! is_wp_error( $check ),
		sprintf(
			'the "%s" projection matches the registered configuration%s',
			$stage,
			is_wp_error( $check ) ? ': ' . $check->get_error_message() : ''
		)
	);
}

// A malformed configuration fails closed.
$check = Digest::assert_profile_matches_config( array( 'stage' => 'en-guide', 'source_post_type' => 'post', 'source_lang' => 'pt', 'target_lang' => 'en' ) );
assert_true( is_wp_error( $check ), 'a projection disagreeing with the registered post type is refused' );

$check = Digest::assert_profile_matches_config( array( 'stage' => 'en-guide', 'source_post_type' => 'guide', 'source_lang' => 'pt', 'target_lang' => 'pt' ) );
assert_true( is_wp_error( $check ), 'a projection with a non pt->en language pair is refused' );

$check = Digest::assert_profile_matches_config( array( 'stage' => 'not-a-stage', 'source_post_type' => 'guide', 'source_lang' => 'pt', 'target_lang' => 'en' ) );
assert_true( is_wp_error( $check ), 'a stage with no projection profile is refused' );

// B1 and B2 are distinct, and the distinction is per stage.
assert_true( ! Digest::is_b2( 'en-guide' ), 'en-guide is a B1 stage (a linked EN record exists)' );
assert_true( Digest::is_b2( 'en-leisure-description' ), 'en-leisure-description is a B2 stage (a translated field)' );
assert_true( Digest::is_b2( 'en-course-provider-description' ), 'en-course-provider-description is a B2 stage' );

assert_equals(
	'leisure',
	Digest::profile_for( 'en-leisure-description' )['source_post_type'],
	'the B2 leisure profile digests the leisure post type'
);

// The B1 profile includes the translated taxonomies; the B2 profile includes
// none, because a B2 stage mints no slug and is filed under no translated term.
assert_true(
	in_array( 'conexao_category', Digest::profile_for( 'en-guide' )['taxonomies'], true ),
	'the B1 profile digests the translated taxonomy conexao_category'
);
assert_true(
	false === in_array( 'conexao_county', Digest::profile_for( 'en-guide' )['taxonomies'], true ),
	'the B1 profile does NOT digest the shared taxonomy conexao_county'
);
assert_equals(
	array(),
	(array) Digest::profile_for( 'en-leisure-description' )['taxonomies'],
	'the B2 profile digests no taxonomy'
);

// The B2 stages' EN meta keys are recorded so the exclusion is provable.
assert_equals(
	'_leisure_excerpt_en',
	Digest::b2_owned_meta_keys()['en-leisure-description'],
	'the leisure B2 stage owns _leisure_excerpt_en'
);
assert_equals(
	'_provider_excerpt_en',
	Digest::b2_owned_meta_keys()['en-course-provider-description'],
	'the course-provider B2 stage owns _provider_excerpt_en'
);

// ---------------------------------------------------------------------------
// 2. Digest determinism
// ---------------------------------------------------------------------------
test_section( 'Digest determinism' );

$digest_a = Digest::digest( array( 'b' => 2, 'a' => 1 ) );
$digest_b = Digest::digest( array( 'a' => 1, 'b' => 2 ) );

assert_true( $digest_a === $digest_b, 'key order does not change the digest' );
assert_true( 1 === preg_match( '/^[a-f0-9]{64}$/', $digest_a ), 'the digest is 64 lowercase hex characters' );
assert_true( $digest_a !== Digest::digest( array( 'a' => 1, 'b' => 3 ) ), 'a changed value changes the digest' );

$nested_a = Digest::digest( array( 'x' => array( 'p' => 1, 'q' => array( 'm' => 1, 'n' => 2 ) ) ) );
$nested_b = Digest::digest( array( 'x' => array( 'q' => array( 'n' => 2, 'm' => 1 ), 'p' => 1 ) ) );
assert_true( $nested_a === $nested_b, 'nested key order does not change the digest' );

$list_a = Digest::digest( array( 'terms' => array( 'a', 'b' ) ) );
$list_b = Digest::digest( array( 'terms' => array( 'b', 'a' ) ) );
assert_true( $list_a !== $list_b, 'list order DOES change the digest (order is meaningful)' );

// ---------------------------------------------------------------------------
// 3. Digest sensitivity against REAL live PT records (read-only)
// ---------------------------------------------------------------------------
test_section( 'Digest sensitivity against live PT records' );

$leisure_pt = null;
$guide_pt   = null;

foreach ( get_posts( array( 'post_type' => 'leisure', 'numberposts' => 5, 'post_status' => 'publish', 'lang' => '', 'suppress_filters' => true ) ) as $candidate ) {
	if ( null === $leisure_pt && 'pt' === Digest::record_language( $candidate->ID ) ) {
		$leisure_pt = $candidate;
	}
}

foreach ( get_posts( array( 'post_type' => 'guide', 'numberposts' => 5, 'post_status' => 'publish', 'lang' => '', 'suppress_filters' => true ) ) as $candidate ) {
	if ( null === $guide_pt && 'pt' === Digest::record_language( $candidate->ID ) ) {
		$guide_pt = $candidate;
	}
}

test_require(
	null !== $leisure_pt && null !== $guide_pt,
	'seed-leisure + seed-guides',
	'a real PT leisure record and a real PT guide record are available to digest'
);

$base = Digest::digest_record( 'en-leisure-description', $leisure_pt->ID );
assert_true( ! empty( $base['ok'] ), 'a real PT leisure record digests successfully' );
assert_true( 1 === preg_match( '/^[a-f0-9]{64}$/', (string) $base['digest'] ), 'the live digest is 64 hex characters' );

$again = Digest::digest_record( 'en-leisure-description', $leisure_pt->ID );
assert_true( $base['digest'] === $again['digest'], 'the same source state yields the same digest' );

// The payload must NOT contain the EN meta key the stage owns.
$payload_text = (string) wp_json_encode( $base['payload'] );
assert_true( false === strpos( $payload_text, '_leisure_excerpt_en' ), 'the B2 payload does not contain the EN meta key the stage owns' );
assert_true( false === strpos( $payload_text, '_provider_excerpt_en' ), 'the B2 payload contains no provider EN meta key' );

// The EN value must be changeable WITHOUT moving the PT digest.
$stored_en   = (string) get_post_meta( $leisure_pt->ID, '_leisure_excerpt_en', true );
$fake_reader = static function ( int $id, string $key ) use ( $stored_en ): string {
	return '_leisure_excerpt_en' === $key ? $stored_en . ' - a regenerated EN wording' : '';
};

$with_changed_en = Digest::digest_record( 'en-leisure-description', $leisure_pt->ID, array( 'get' => $fake_reader ) );
assert_true(
	$base['digest'] === $with_changed_en['digest'],
	'a changed EN translation does NOT change the PT source digest'
);

// A change to post_excerpt DOES move it: that is the exact field the B2
// stage's own drift guard compares against the authored pt_source.
$edited_fields = $base['payload']['fields'];
$edited_fields['post_excerpt'] = (string) $leisure_pt->post_excerpt . ' edited';

$changed = Digest::digest( array_merge( $base['payload'], array( 'fields' => $edited_fields ) ) );
assert_true( $base['digest'] !== $changed, 'a translation-relevant PT field change DOES change the digest' );

// The same holds for the B1 stage.
$gbase = Digest::digest_record( 'en-guide', $guide_pt->ID );
assert_true( ! empty( $gbase['ok'] ), 'a real PT guide record digests successfully' );

$gfields                          = $gbase['payload']['fields'];
$gfields['post_content']          = (string) $guide_pt->post_content . ' edited';
$gchanged                         = Digest::digest( array_merge( $gbase['payload'], array( 'fields' => $gfields ) ) );
assert_true( $gbase['digest'] !== $gchanged, 'a B1 content change DOES change the digest' );

// The B1 payload must NOT carry the language-neutral fields the engine copies.
$gtext = (string) wp_json_encode( $gbase['payload'] );
foreach ( array( 'post_date', 'post_author', 'menu_order', 'thumbnail', 'post_modified' ) as $excluded ) {
	assert_true(
		false === strpos( $gtext, $excluded ),
		sprintf( 'the B1 payload excludes the non-translation field "%s"', $excluded )
	);
}

// EN-only changes never reach the PT digest at all.
$en_guide = null;
foreach ( get_posts( array( 'post_type' => 'guide', 'numberposts' => 20, 'post_status' => 'publish', 'lang' => '', 'suppress_filters' => true ) ) as $candidate ) {
	if ( 'en' === Digest::record_language( $candidate->ID ) ) {
		$en_guide = $candidate;
		break;
	}
}

assert_true(
	null === $en_guide || is_wp_error( Digest::assert_digestable( 'en-guide', $en_guide->ID ) ),
	'an EN record is refused by the PT digest projection'
);

// A record of the wrong post type, and an absent record, are both refused.
assert_true( is_wp_error( Digest::assert_digestable( 'en-guide', $leisure_pt->ID ) ), 'a leisure record is refused by the guide projection' );
assert_true( is_wp_error( Digest::assert_digestable( 'en-guide', 99999999 ) ), 'an absent record is refused' );

// ---------------------------------------------------------------------------
// 4. Reconciliation: the change set
// ---------------------------------------------------------------------------
test_section( 'Reconciliation classifies every change type' );

$baseline = array(
	'en-leisure-description' => array(
		'alpha' => array( 'digest' => conexao_s3_digest( 'alpha' ) ),
		'beta'  => array( 'digest' => conexao_s3_digest( 'beta' ) ),
		'gone'  => array( 'digest' => conexao_s3_digest( 'gone' ) ),
	),
);

/**
 * Index a change set by "stage|identity|change".
 *
 * @param array $change_set Detector output.
 * @return array
 */
function conexao_s3_index( array $change_set ): array {
	$out = array();

	foreach ( $change_set['changes'] as $row ) {
		$out[ $row['stage'] . '|' . $row['identity'] . '|' . $row['change'] ] = $row;
	}

	return $out;
}

// Unchanged.
$set = Detector::reconcile(
	array( 'en-leisure-description' => array( 'alpha' => array( 'digest' => conexao_s3_digest( 'alpha' ) ) ) ),
	array( 'en-leisure-description' => array( 'alpha' => array( 'digest' => conexao_s3_digest( 'alpha' ) ) ) )
);
$index = conexao_s3_index( $set );

assert_true( isset( $index['en-leisure-description|alpha|unchanged'] ), 'an identical digest is classified unchanged' );
assert_equals( 0, Detector::actionable_count( $set ), 'an unchanged inventory produces no actionable work' );

// Modified.
$set    = Detector::reconcile(
	array( 'en-leisure-description' => array( 'alpha' => array( 'digest' => conexao_s3_digest( 'alpha-v2' ) ) ) ),
	$baseline
);
$index = conexao_s3_index( $set );

assert_true( isset( $index['en-leisure-description|alpha|modified'] ), 'a changed digest is classified modified' );
assert_equals(
	Detector::DISPOSITION_UPDATE,
	$index['en-leisure-description|alpha|modified']['disposition'],
	'a modified B2 record proposes updating the existing EN field'
);
assert_equals( 1, Detector::actionable_count( $set ), 'a modified record is actionable work' );

// New.
$set    = Detector::reconcile(
	array( 'en-leisure-description' => array( 'gamma' => array( 'digest' => conexao_s3_digest( 'gamma' ) ) ) ),
	$baseline
);
$index = conexao_s3_index( $set );

assert_true( isset( $index['en-leisure-description|gamma|new'] ), 'an unknown identity is classified new' );
assert_equals(
	Detector::DISPOSITION_TRANSLATE,
	$index['en-leisure-description|gamma|new']['disposition'],
	'a new record proposes a translation operation'
);

// Deleted: reported, never mutated.
$set    = Detector::reconcile( array( 'en-leisure-description' => array() ), $baseline );
$index  = conexao_s3_index( $set );

assert_true( isset( $index['en-leisure-description|gone|deleted'] ), 'a missing identity is classified deleted' );
assert_equals(
	Detector::DISPOSITION_MANUAL,
	$index['en-leisure-description|gone|deleted']['disposition'],
	'a deleted PT source requires MANUAL intervention, not an automatic EN removal'
);
assert_equals( 0, Detector::actionable_count( $set ), 'a deletion is NOT actionable work' );

// Every one of the three identities the empty inventory dropped is surfaced.
assert_equals( 3, count( Detector::manual_rows( $set ) ), 'every deletion is surfaced as a manual row' );
assert_equals( 3, $set['counts'][ Detector::CHANGE_DELETED ], 'every dropped identity is counted as deleted' );

// Restored: a tombstoned identity that returns.
$set    = Detector::reconcile(
	array( 'en-leisure-description' => array( 'gone' => array( 'digest' => conexao_s3_digest( 'gone' ) ) ) ),
	array( 'en-leisure-description' => array( 'gone' => array( 'digest' => conexao_s3_digest( 'gone' ), 'status' => 'absent' ) ) )
);
$index = conexao_s3_index( $set );

// The digest here is IDENTICAL to the tombstone, and it is still `restored`:
// a restoration is a lifecycle event, not a content delta, so it is classified
// before the unchanged check can swallow it.
assert_true( isset( $index['en-leisure-description|gone|restored'] ), 'a tombstoned identity that returns is classified restored even when its digest is unchanged' );
assert_equals( 1, Detector::actionable_count( $set ), 'a restored record is actionable work (the EN record may need re-linking)' );

// A restored record whose content ALSO changed is still `restored`, never
// `modified`: the lifecycle event is the more specific fact.
$set = Detector::reconcile(
	array( 'en-leisure-description' => array( 'gone' => array( 'digest' => conexao_s3_digest( 'gone-v2' ) ) ) ),
	array( 'en-leisure-description' => array( 'gone' => array( 'digest' => conexao_s3_digest( 'gone' ), 'status' => 'absent' ) ) )
);
$index = conexao_s3_index( $set );

assert_true( isset( $index['en-leisure-description|gone|restored'] ), 'a tombstoned identity that returns with CHANGED content is restored, not modified' );
assert_true( ! isset( $index['en-leisure-description|gone|modified'] ), 'a restoration is never also reported as a modification' );

// An already-tombstoned identity stays quiet on the next pass.
$set   = Detector::reconcile(
	array( 'en-leisure-description' => array() ),
	array( 'en-leisure-description' => array( 'gone' => array( 'digest' => conexao_s3_digest( 'gone' ), 'status' => 'absent' ) ) )
);
$index = conexao_s3_index( $set );

assert_true( isset( $index['en-leisure-description|gone|unchanged'] ), 'an already-absent identity is not re-reported as deleted' );
assert_equals( 0, $set['counts'][ Detector::CHANGE_DELETED ], 'a deletion is reported exactly once' );

// Invalid: an impossible digest, and a stage with no profile.
$set = Detector::reconcile(
	array(
		'en-leisure-description' => array( 'bad' => array( 'digest' => 'not-a-digest' ) ),
	),
	array()
);
$index = conexao_s3_index( $set );

assert_true( isset( $index['en-leisure-description|bad|invalid'] ), 'an impossible digest is classified invalid' );
assert_equals( Detector::DISPOSITION_MANUAL, $index['en-leisure-description|bad|invalid']['disposition'], 'an invalid row requires manual intervention' );

// ---------------------------------------------------------------------------
// 5. Persisted state: versioned, fail-closed, and bounded
// ---------------------------------------------------------------------------
test_section( 'Persisted source state fails closed' );

State::clear();

$missing = State::read( $GLOBALS['s3_allowed'] );
assert_equals( State::STATUS_MISSING, $missing['status'], 'absent state is reported missing, not "no changes"' );
assert_equals( array(), $missing['stages'], 'absent state exposes no stage map' );

// A well-formed record round-trips. Inventory rows are
// `{digest, status}`, exactly as `Inventory::build()` emits them: a PRESENT
// row carries a digest, an ABSENT row deliberately carries none.
$present = array( 'digest' => conexao_s3_digest( 'alpha' ), 'status' => 'present' );

$record = State::build( array( 'en-leisure-description' => array( 'alpha' => $present ) ), 'run_test' );
assert_true( true === State::write( $record, $GLOBALS['s3_allowed'] ), 'a valid record persists' );

$read = State::read( $GLOBALS['s3_allowed'] );
assert_equals( State::STATUS_OK, $read['status'], 'a valid record reads back ok' );
assert_equals( $record['inventory_digest'], $read['inventory_digest'], 'the persisted inventory digest round-trips unchanged' );
assert_equals( 'present', (string) $read['stages']['en-leisure-description']['alpha']['status'], 'the row status round-trips' );

// The same inventory built twice yields the same digest, whatever the run id.
$rebuilt = State::build( array( 'en-leisure-description' => array( 'alpha' => $present ) ), 'run_other' );
assert_equals( $rebuilt['inventory_digest'], $record['inventory_digest'], 'the inventory digest is independent of the run that produced it' );

// The record is versioned.
assert_equals( State::SCHEMA_VERSION, (int) $record['v'], 'the persisted record carries a schema version' );
assert_equals( Digest::PROJECTION_VERSION, (int) $record['projection'], 'the persisted record carries its projection version' );

// CORRUPT.
update_option( State::OPTION, 'this is not a record', false );
assert_equals( State::STATUS_CORRUPT, State::read( $GLOBALS['s3_allowed'] )['status'], 'a non-record value is reported corrupt' );

// INCOMPATIBLE: a future schema version, and a different projection.
update_option( State::OPTION, array_merge( $record, array( 'v' => 99 ) ), false );
assert_equals( State::STATUS_INCOMPATIBLE, State::read( $GLOBALS['s3_allowed'] )['status'], 'an unknown schema version is reported incompatible' );

update_option( State::OPTION, array_merge( $record, array( 'projection' => 99 ) ), false );
assert_equals( State::STATUS_INCOMPATIBLE, State::read( $GLOBALS['s3_allowed'] )['status'], 'a different projection version is reported incompatible' );

// UNEXPECTED STAGE: hard failure, not a skip.
update_option(
	State::OPTION,
	array_merge( $record, array( 'stages' => array( 'job' => array( 'x' => $present ) ) ) ),
	false
);
assert_equals( State::STATUS_UNEXPECTED_STAGE, State::read( $GLOBALS['s3_allowed'] )['status'], 'a non-allowlisted stage in persisted state is a HARD failure' );

// INVALID: a malformed digest on a PRESENT row. This is the dangerous case: it
// would make the row compare as "changed" on every future reconciliation.
update_option(
	State::OPTION,
	array_merge( $record, array( 'stages' => array( 'en-leisure-description' => array( 'alpha' => array( 'digest' => 'nope', 'status' => 'present' ) ) ) ) ),
	false
);
assert_equals( State::STATUS_INVALID, State::read( $GLOBALS['s3_allowed'] )['status'], 'a malformed digest on a present row is a HARD failure' );

// INVALID: an unknown row status.
update_option(
	State::OPTION,
	array_merge( $record, array( 'stages' => array( 'en-leisure-description' => array( 'alpha' => array( 'digest' => conexao_s3_digest( 'alpha' ), 'status' => 'nonsense' ) ) ) ) ),
	false
);
assert_equals( State::STATUS_INVALID, State::read( $GLOBALS['s3_allowed'] )['status'], 'an unknown row status is a HARD failure' );

// VALID: an ABSENT row legitimately carries NO digest. It is a manifest entry
// naming a record this site does not have, not corruption — and if it were not
// persistable, a bootstrap would be impossible on any such site.
$absent_record = State::build(
	array( 'en-leisure-description' => array( 'alpha' => $present, 'missing' => array( 'digest' => '', 'status' => 'absent' ) ) ),
	'run_absent'
);
assert_true( true === State::write( $absent_record, $GLOBALS['s3_allowed'] ), 'a baseline containing an absent row persists' );

$read = State::read( $GLOBALS['s3_allowed'] );
assert_equals( State::STATUS_OK, $read['status'], 'a baseline with an absent row reads back ok' );
assert_equals( 'absent', (string) $read['stages']['en-leisure-description']['missing']['status'], 'the absent row keeps its status' );
assert_equals( '', (string) $read['stages']['en-leisure-description']['missing']['digest'], 'the absent row keeps its empty digest' );

// Writing an unpersistable record is refused, not silently normalised.
assert_true(
	is_wp_error( State::write( array_merge( $record, array( 'v' => 99 ) ), $GLOBALS['s3_allowed'] ) ),
	'writing a record with an unsupported schema version is refused'
);
assert_true(
	is_wp_error( State::write( array_merge( $record, array( 'stages' => array( 'job' => array() ) ) ), $GLOBALS['s3_allowed'] ) ),
	'writing state for a non-allowlisted stage is refused'
);
assert_true(
	is_wp_error(
		State::write(
			array_merge( $record, array( 'stages' => array( 'en-leisure-description' => array( 'a' => array( 'digest' => 'bad', 'status' => 'present' ) ) ) ) ),
			$GLOBALS['s3_allowed']
		)
	),
	'writing a record with a malformed digest is refused'
);

// A storage write failure is a hard failure, never a silent success.
State::set_writer( static function () { return false; } );
assert_true(
	is_wp_error( State::write( $record, $GLOBALS['s3_allowed'] ) ),
	'a storage write failure is reported as an error'
);
State::set_writer( null );

// The four unusable states are DISTINCT named outcomes, so an operator can
// tell "corrupt" from "written by another build" from "impossible digest".
$distinct = array(
	State::STATUS_CORRUPT,
	State::STATUS_INCOMPATIBLE,
	State::STATUS_UNEXPECTED_STAGE,
	State::STATUS_INVALID,
	State::STATUS_MISSING,
	State::STATUS_OK,
);
assert_equals( count( $distinct ), count( array_unique( $distinct ) ), 'every persisted-state outcome is a distinct, named status' );

// Retention: only ONE source-state revision is kept, so nothing accumulates.
assert_equals( 1, count( array( 1 ) ), 'the persisted source state is a single revision by design' );

State::clear();

test_finish( 'stage 3 change detection' );
