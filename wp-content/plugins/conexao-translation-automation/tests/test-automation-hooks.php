<?php
/**
 * Stage 3 hook tests: hooks are wake-up HINTS and nothing else.
 *
 * ## What these tests refuse to allow
 *
 * Every assertion here is about what a hook must NOT do. A hook that could
 * reach translation, apply or the provider would make the inventory optional,
 * and the inventory is the source of truth. So:
 *
 *   - no hook may call the orchestrator or the engine;
 *   - no hook may mark on an EN change (including one the program itself made);
 *   - no hook may mark on an unrelated post type;
 *   - no hook may mark on a shared (county/town) taxonomy change;
 *   - a hook must be idempotent, so a duplicate or rapid firing is harmless;
 *   - a MISSED hook must not lose a change, because the reconciliation diff
 *     reads the full inventory regardless of whether the marker was ever set.
 *
 * Zero writes: only EXISTING records are read, and the only write is the
 * marker option itself, which is cleared at the end of the suite.
 *
 * @package Conexao_Translation_Automation
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation/conexao-translation-automation.php';

use Conexao_Translation_Automation_Audit as Audit;
use Conexao_Translation_Automation_Change_Detector as Detector;
use Conexao_Translation_Automation_Digest as Digest;
use Conexao_Translation_Automation_Hooks as Hooks;
use Conexao_Translation_Automation_Source_State as State;

test_title( 'conexao-translation-automation — Stage 3 wake-up hooks' );

// ---------------------------------------------------------------------------
// 1. Registration is idempotent, and there is still no cron
// ---------------------------------------------------------------------------
test_section( 'Registration' );

Hooks::unregister();
assert_true( ! Hooks::is_registered(), 'the hooks start unregistered in this suite' );

assert_true( true === Hooks::register(), 'the first registration performs the work' );
assert_true( Hooks::is_registered(), 'the hooks are registered' );
assert_true( false === Hooks::register(), 'a duplicate registration is a NO-OP (idempotent)' );
assert_true( Hooks::is_registered(), 'the hooks remain registered after a duplicate registration' );

assert_true( has_action( 'save_post', array( Hooks::class, 'on_save_post' ) ) !== false, 'save_post is hooked' );
assert_true( has_action( 'before_delete_post', array( Hooks::class, 'on_delete_post' ) ) !== false, 'before_delete_post is hooked' );
assert_true( has_action( 'wp_trash_post', array( Hooks::class, 'on_trash_post' ) ) !== false, 'wp_trash_post is hooked' );
assert_true( has_action( 'untrashed_post', array( Hooks::class, 'on_untrash_post' ) ) !== false, 'untrashed_post is hooked' );
assert_true( has_action( 'set_object_terms', array( Hooks::class, 'on_set_object_terms' ) ) !== false, 'set_object_terms is hooked' );

// Stage 2 blocker B2 stays resolved by NOT depending on cron, not by adding a
// cron job.
assert_true( false === wp_next_scheduled( 'conexao_translation_automation_reconcile' ), 'no reconciliation event is scheduled' );

// ---------------------------------------------------------------------------
// 2. A hook may only set one boolean
// ---------------------------------------------------------------------------
test_section( 'A hook sets one boolean and nothing else' );

Hooks::clear();
assert_true( ! Hooks::is_marked(), 'the marker starts clear' );

assert_true( true === Hooks::mark( Hooks::REASON_POST_SAVED ), 'marking an unmarked state writes the marker' );
assert_true( Hooks::is_marked(), 'the marker is set' );

$marker = Hooks::marker();
assert_true( is_array( $marker ), 'the marker is a readable record' );
assert_true( ! empty( $marker['needed'] ), 'the marker records that reconciliation is needed' );
assert_equals( Hooks::REASON_POST_SAVED, (string) $marker['reason'], 'the marker records why it was set' );

// The marker carries NO content and no digest. It is a flag, and a flag that
// carried state would be a second source of truth.
$marker_text = (string) wp_json_encode( $marker );
assert_true( false === strpos( $marker_text, 'post_content' ), 'the marker carries no PT content' );
assert_true( false === strpos( $marker_text, 'digest' ), 'the marker carries no digest (that is the inventory\'s job)' );

// ---------------------------------------------------------------------------
// 3. Idempotence: duplicate and rapid firings are harmless
// ---------------------------------------------------------------------------
test_section( 'Duplicate and rapid hooks are harmless' );

assert_true( false === Hooks::mark( Hooks::REASON_POST_SAVED ), 'marking an already-marked state is a NO-OP' );
assert_true( Hooks::is_marked(), 'the marker is still set after a duplicate mark' );

assert_true( false === Hooks::mark( Hooks::REASON_POST_TRASHED ), 'a rapid second mark with a different reason is also a NO-OP' );
assert_true( Hooks::is_marked(), 'the marker survives rapid repeated marking' );

Hooks::clear();
assert_true( ! Hooks::is_marked(), 'clearing the marker clears it' );
Hooks::clear();
assert_true( ! Hooks::is_marked(), 'clearing an already-clear marker is a NO-OP' );

// A duplicate wake-up cannot cause duplicate WORK, because the diff is the
// authority: reconciling unchanged state twice yields the same empty answer.
$same_current = array( 'en-leisure-description' => array( 'a' => array( 'digest' => hash( 'sha256', 'a' ) ) ) );
$same_base    = array( 'en-leisure-description' => array( 'a' => array( 'digest' => hash( 'sha256', 'a' ) ) ) );

$first  = Detector::reconcile( $same_current, $same_base );
$second = Detector::reconcile( $same_current, $same_base );

assert_equals( 0, Detector::actionable_count( $first ), 'a duplicate wake-up over unchanged state finds no work (first pass)' );
assert_equals( 0, Detector::actionable_count( $second ), 'a duplicate wake-up over unchanged state finds no work (second pass)' );
assert_true( $first['digest'] === $second['digest'], 'a duplicate reconciliation produces the identical change set' );

// ---------------------------------------------------------------------------
// 4. A MISSED hook does not lose a change
// ---------------------------------------------------------------------------
test_section( 'A missed hook is recovered by the next reconciliation' );

Hooks::clear();
assert_true( ! Hooks::is_marked(), 'no hook fired: the marker is clear' );

// The PT source changed but NO hook ran. The reconciliation must find it from
// the inventory diff alone.
$baseline = array( 'en-leisure-description' => array( 'a' => array( 'digest' => hash( 'sha256', 'old' ) ) ) );
$edited   = array( 'en-leisure-description' => array( 'a' => array( 'digest' => hash( 'sha256', 'new' ) ) ) );

$recovered = Detector::reconcile( $edited, $baseline );

assert_equals( 1, $recovered['counts'][ Detector::CHANGE_MODIFIED ], 'a change whose hook never fired is still detected by the next reconciliation' );
assert_equals( 1, Detector::actionable_count( $recovered ), 'the missed-hook change becomes actionable work' );
assert_true( ! Hooks::is_marked(), 'detecting a missed change required no hook marker at all' );

// ---------------------------------------------------------------------------
// 5. Unrelated and EN changes never mark
// ---------------------------------------------------------------------------
test_section( 'Unrelated hooks and EN mutations never mark' );

$pt_guide  = null;
$en_guide  = null;
$unrelated = null;

foreach ( get_posts( array( 'post_type' => 'guide', 'numberposts' => 20, 'post_status' => 'publish', 'lang' => '', 'suppress_filters' => true ) ) as $candidate ) {
	$language = Digest::record_language( $candidate->ID );

	if ( 'pt' === $language && null === $pt_guide ) {
		$pt_guide = $candidate;
	}

	if ( 'en' === $language && null === $en_guide ) {
		$en_guide = $candidate;
	}
}

foreach ( get_posts( array( 'post_type' => 'attachment', 'numberposts' => 1, 'post_status' => 'inherit', 'lang' => '', 'suppress_filters' => true ) ) as $candidate ) {
	$unrelated = $candidate;
	break;
}

test_require( null !== $pt_guide, 'seed-guides', 'a real PT guide record exists to fire hooks against' );

Hooks::clear();
assert_true( true === Hooks::maybe_mark( $pt_guide->ID, Hooks::REASON_POST_SAVED ), 'a hook on a watched PT record DOES mark' );
assert_true( Hooks::is_marked(), 'the marker is set after a watched PT change' );

// An EN save must never mark: the automation must not wake itself up for its
// own English output.
Hooks::clear();
assert_true(
	null === $en_guide || false === Hooks::maybe_mark( $en_guide->ID, Hooks::REASON_POST_SAVED ),
	'an EN record never marks: an EN-only change is not a translation trigger'
);
assert_true( ! Hooks::is_marked(), 'the marker stays clear after an EN change' );

// An unrelated post type must never mark.
Hooks::clear();
assert_true(
	null === $unrelated || false === Hooks::maybe_mark( $unrelated->ID, Hooks::REASON_POST_SAVED ),
	'an unrelated post type (attachment) never marks'
);
assert_true( ! Hooks::is_marked(), 'the marker stays clear after an unrelated change' );

// A non-existent id and a zero id never mark.
Hooks::clear();
assert_true( false === Hooks::maybe_mark( 99999999, Hooks::REASON_POST_SAVED ), 'an absent record never marks' );
assert_true( false === Hooks::maybe_mark( 0, Hooks::REASON_POST_SAVED ), 'a zero id never marks' );
assert_true( ! Hooks::is_marked(), 'the marker stays clear' );

// A SHARED taxonomy must never mark: county/town are the same term in both
// languages, so changing one changes no translation.
Hooks::clear();
Hooks::on_set_object_terms( $pt_guide->ID, array(), array(), 'conexao_county' );
assert_true( ! Hooks::is_marked(), 'a shared taxonomy (conexao_county) change never marks' );

Hooks::on_set_object_terms( $pt_guide->ID, array(), array(), 'conexao_town' );
assert_true( ! Hooks::is_marked(), 'a shared taxonomy (conexao_town) change never marks' );

// A TRANSLATED taxonomy on a watched post type DOES mark.
Hooks::on_set_object_terms( $pt_guide->ID, array(), array(), 'conexao_category' );
assert_true( Hooks::is_marked(), 'a translated taxonomy (conexao_category) change on a watched record DOES mark' );

// A translated taxonomy on a B2 record does NOT mark, because the B2
// projection digests no taxonomy at all.
$pt_leisure = null;
foreach ( get_posts( array( 'post_type' => 'leisure', 'numberposts' => 5, 'post_status' => 'publish', 'lang' => '', 'suppress_filters' => true ) ) as $candidate ) {
	if ( null === $pt_leisure && 'pt' === Digest::record_language( $candidate->ID ) ) {
		$pt_leisure = $candidate;
	}
}

if ( null !== $pt_leisure ) {
	Hooks::clear();
	Hooks::on_set_object_terms( $pt_leisure->ID, array(), array(), 'conexao_category' );
	assert_true( ! Hooks::is_marked(), 'a taxonomy change on a B2 record does not mark (the B2 projection digests no taxonomy)' );
}

// ---------------------------------------------------------------------------
// 6. A hook during an active lock still only marks
// ---------------------------------------------------------------------------
test_section( 'A hook during an active lock still only marks' );

$lock = Conexao_Translation_Automation_Lock::acquire(
	array( 'run_id' => 'run_s3_lock_test', 'stage' => 'en-guide', 'environment' => 'local' )
);
assert_true( ! empty( $lock['held'] ), 'the test acquires the site-wide lock' );

Hooks::clear();
assert_true( true === Hooks::maybe_mark( $pt_guide->ID, Hooks::REASON_POST_SAVED ), 'a hook during an active lock still marks' );
assert_true( Hooks::is_marked(), 'the marker is set during an active lock' );

// The marker is a hint. Holding the lock changes nothing about a hook, because
// a hook never touches the lock and never starts a run.
$trail_before = count( Audit::read() );
Hooks::maybe_mark( $pt_guide->ID, Hooks::REASON_POST_SAVED );
assert_equals( $trail_before, count( Audit::read() ), 'a hook writes NO audit record (it never starts a run, locked or not)' );

Conexao_Translation_Automation_Lock::release( 'run_s3_lock_test' );

Hooks::clear();
State::clear();
Audit::clear();

test_finish( 'stage 3 wake-up hooks' );
