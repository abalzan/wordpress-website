<?php
/**
 * Stage 3 — P12 diagnostics/correlation and P13 group-aware retention.
 *
 * ## What this suite proves
 *
 *   A. Continuation lifecycle correlation — every newly scheduled, cleared,
 *      exhausted and started continuation log entry carries the source, the
 *      child `source_run_id`, the logical `parent_run_group_id` and a stable
 *      `continuation_id`, and the four identifiers are persisted in the record
 *      so they survive the schedule/resume boundary.
 *   B. Truthful status model — `incomplete` is `partial`, never `warning`; a
 *      completed walk with a caveat is `warning`; `rate_limited` and `failed`
 *      are never downgraded; the machine-readable cause travels with the record
 *      and the registry status is mapped truthfully.
 *   C. Stuck/abandoned continuation diagnostics — a recent running record is not
 *      flagged, an expired fixture is, `running` with `run_at=0` is surfaced, a
 *      record with no timestamp is INDETERMINATE, and the diagnostics mutate
 *      nothing.
 *   D. `events_imported` semantics — the value is a per-run creation count and
 *      is labelled as such.
 *   E. P13 retention — a logical Run All (parent + children + >50 log entries)
 *      is retained, reconstructed and downloaded in full; groups are evicted
 *      whole and never partially; an active group is never evicted; retention is
 *      idempotent; capacity pressure is reported rather than silently discarded.
 *   F. Inline preview disclosure — shown count, total count, truncation and the
 *      full-download path are always stated.
 *   G. Legacy records — entries and rows with no run identity stay explicitly
 *      legacy and are never given an invented ID.
 *
 * ## Safety
 *
 * `conexao_event_import_log` and `conexao_event_import_history` redirect to this
 * process's own test rows. The continuation, checkpoint, cooldown, health and
 * source-registry options are snapshotted and restored. Every fixture below is
 * synthetic, identified by a `stage3_` prefix, and removed at shutdown. No event
 * post is created, no HTTP request leaves the process, no import runs.
 *
 * @package Conexao_Event_Importer
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

test_require_operator_store_isolation();

test_prerequisite_hint( 'activate-plugin:conexao-event-importer' );
test_require(
	class_exists( 'Conexao_Import_Log' )
		&& class_exists( 'Conexao_Import_History' )
		&& class_exists( 'Conexao_Import_Continuation' ),
	'activate-plugin:conexao-event-importer',
	'test prerequisite is available: the importer classes are loaded',
	'activate the conexao-event-importer plugin'
);

$stage3_restore = test_options_isolate(
	array(
		Conexao_Import_Continuation::OPTION_KEY,
		Conexao_Source_Enumeration_Checkpoint::OPTION_KEY,
		Conexao_Source_Cooldown::OPTION_KEY,
		Conexao_Source_Health::OPTION_KEY,
		Conexao_Event_Sources::OPTION_KEY,
	)
);

register_shutdown_function(
	static function () use ( $stage3_restore ) {
		Conexao_Import_Continuation::reset_clock();
		Conexao_Import_Continuation::reset_runner();
		Conexao_Import_Log::clear();
		Conexao_Import_History::clear();
		test_options_restore( $stage3_restore, true );
	}
);

// A fixed clock keeps every classification deterministic.
$stage3_now = 1770000000; // 2026-02-02 02:40:00 UTC.
Conexao_Import_Continuation::set_clock(
	static function () use ( &$stage3_now ) {
		return $stage3_now;
	}
);

/**
 * A synthetic continuation source slug.
 *
 * @param string $suffix Suffix.
 * @return string
 */
function stage3_src( $suffix ) {
	return 'stage3_probe_' . $suffix;
}

/**
 * Seed a resumable enumeration checkpoint for a source.
 *
 * @param string $source_id Source slug.
 * @param int    $last_page Last confirmed page.
 * @param int    $pages     Declared page count.
 * @param string $failure   Failure class recorded with the checkpoint.
 * @return void
 */
function stage3_seed_checkpoint( $source_id, $last_page = 3, $pages = 20, $failure = 'time_budget' ) {
	Conexao_Source_Enumeration_Checkpoint::record(
		$source_id,
		array(
			'last_page'    => $last_page,
			'page_count'   => $pages,
			'complete'     => false,
			'last_failure' => $failure,
		)
	);
}

/**
 * Find the stored log entries written for one continuation lifecycle.
 *
 * @param string $source_id Source slug.
 * @param string $lifecycle Lifecycle word (scheduled|cleared|exhausted|started|rearmed|failed).
 * @return array[]
 */
function stage3_lifecycle_entries( $source_id, $lifecycle ) {
	$found = array();
	foreach ( Conexao_Import_Log::get_all() as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}
		if ( ( isset( $entry['source'] ) ? (string) $entry['source'] : '' ) !== $source_id ) {
			continue;
		}
		$context = isset( $entry['context'] ) && is_array( $entry['context'] ) ? $entry['context'] : array();
		if ( isset( $context['lifecycle'] ) && (string) $context['lifecycle'] === $lifecycle ) {
			$found[] = $entry;
		}
	}
	return $found;
}

// ===========================================================================
// A. Continuation lifecycle correlation (P12)
// ===========================================================================
test_section( 'A. Continuation lifecycle entries are correlated to source, child run and parent group' );

$stage3_group   = 'stage3-group-' . wp_generate_uuid4();
$stage3_child   = 'stage3-child-' . wp_generate_uuid4();
$stage3_src_a   = stage3_src( 'corr' );

stage3_seed_checkpoint( $stage3_src_a, 3, 20, 'time_budget' );

$stage3_record = Conexao_Import_Continuation::reconcile(
	$stage3_src_a,
	array(
		'run_id'              => $stage3_group,
		'source_run_id'       => $stage3_child,
		'parent_run_group_id' => $stage3_group,
	)
);

assert_true( true === $stage3_record, 'A1: reconcile() schedules a continuation for a resumable enumeration' );

$stage3_stored = Conexao_Import_Continuation::get( $stage3_src_a );

assert_equals( $stage3_group, $stage3_stored['run_id'], 'A2: the record persists the owning run ID (the parent group for a child run)' );
assert_equals( $stage3_child, $stage3_stored['source_run_id'], 'A2: the record persists the child source-run ID separately from the parent group' );
assert_equals( $stage3_group, $stage3_stored['parent_run_group_id'], 'A2: the record persists the logical Run All parent ID' );
assert_true( '' !== (string) $stage3_stored['continuation_id'], 'A2: the record carries a stable continuation identifier' );

$stage3_scheduled = stage3_lifecycle_entries( $stage3_src_a, 'scheduled' );

assert_equals( 1, count( $stage3_scheduled ), 'A3: scheduling writes exactly one lifecycle entry' );

$stage3_entry    = $stage3_scheduled[0];
$stage3_context  = isset( $stage3_entry['context'] ) && is_array( $stage3_entry['context'] ) ? $stage3_entry['context'] : array();

assert_equals( $stage3_group, (string) $stage3_entry['run_id'], 'A3: the scheduled entry carries the owning run ID in the first-class field (so it files under the right History row)' );
assert_equals( $stage3_group, (string) $stage3_context['parent_run_group_id'], 'A3: the scheduled entry carries the structured parent group ID' );
assert_equals( $stage3_child, (string) $stage3_context['source_run_id'], 'A3: the scheduled entry carries the structured child run ID' );
assert_equals( (string) $stage3_stored['continuation_id'], (string) $stage3_context['continuation_id'], 'A3: the scheduled entry carries the continuation identifier' );
assert_equals( 'scheduled', (string) $stage3_context['lifecycle'], 'A3: the scheduled entry is labelled with its lifecycle transition' );
assert_equals( '3', (string) $stage3_context['last_page'], 'A3: the scheduled entry records the resume position' );
assert_equals( '20', (string) $stage3_context['page_count'], 'A3: the scheduled entry records the declared page count' );
assert_equals( 'time_budget', (string) $stage3_stored['reason'], 'A3: the RECORD keeps the enumerated stop reason, so the cause is not lost at schedule time' );
assert_equals( 'continuation_scheduled', (string) $stage3_entry['context']['reason'], 'A3: the lifecycle entry names the transition it records' );
assert_equals( 'continuation_scheduled', (string) $stage3_entry['context']['reason_code'], 'A3: the reason code is a closed-vocabulary lifecycle word' );

// The identifiers must survive the resume boundary: the record is re-read by a
// different request (here: a fresh lookup) and must still name the same run.
$stage3_reloaded = Conexao_Import_Continuation::get( $stage3_src_a );
assert_equals( $stage3_child, $stage3_reloaded['source_run_id'], 'A4: the child run ID survives re-reading the record (no new ID is invented on resume)' );
assert_equals( $stage3_group, $stage3_reloaded['parent_run_group_id'], 'A4: the parent group ID survives re-reading the record' );

// A scheduled record must never be reported as exhausted or stale.
$stage3_class = Conexao_Import_Continuation::classify( $stage3_reloaded );
assert_equals( 'queued', $stage3_class['class'], 'A5: a record scheduled in the future classifies as queued' );

// Clearing writes a correlated terminal entry even though the record is gone.
Conexao_Import_Continuation::clear( $stage3_src_a, 'enumeration_not_resumable' );

$stage3_cleared = stage3_lifecycle_entries( $stage3_src_a, 'cleared' );
assert_equals( 1, count( $stage3_cleared ), 'A6: clearing writes exactly one lifecycle entry' );

$stage3_clear_ctx = isset( $stage3_cleared[0]['context'] ) && is_array( $stage3_cleared[0]['context'] )
	? $stage3_cleared[0]['context']
	: array();

assert_equals( $stage3_group, (string) $stage3_cleared[0]['run_id'], 'A6: the cleared entry is still correlated to the run it belonged to' );
assert_equals( $stage3_child, (string) $stage3_clear_ctx['source_run_id'], 'A6: the cleared entry keeps the child run ID after removal' );
assert_equals( 'cleared', (string) $stage3_clear_ctx['lifecycle'], 'A6: the cleared entry is labelled cleared' );

// ===========================================================================
// B. Truthful status model (P12)
// ===========================================================================
test_section( 'B. Incomplete is partial, never warning; the cause travels with the result' );

$stage3_incomplete = new Conexao_Import_Result( stage3_src( 'status' ), 'Stage 3 status probe' );
$stage3_incomplete->add_incomplete_enumeration( 'Stopped at page 13 of 49.', 'time_budget' );

assert_equals( 'partial', $stage3_incomplete->get_status(), 'B1: an incomplete enumeration is partial, NOT warning' );
assert_true( $stage3_incomplete->is_enumeration_incomplete(), 'B1: the result still reports the enumeration as incomplete' );
assert_equals( 'time_budget', $stage3_incomplete->get_incomplete_enumeration_reason(), 'B1: the machine-readable stop cause is preserved separately from the status' );
assert_equals( false, $stage3_incomplete->get_stats()['complete'], 'B1: the stats payload reports complete=false' );
assert_equals( 'time_budget', $stage3_incomplete->get_stats()['incomplete_reason'], 'B1: the stats payload carries the stop cause' );

$stage3_warned = new Conexao_Import_Result( stage3_src( 'status' ), 'Stage 3 warning probe' );
$stage3_warned->add_warning( 'Resumed from a checkpoint; confirmed pages were not re-requested.' );

assert_equals( 'warning', $stage3_warned->get_status(), 'B2: a caveat on a COMPLETED walk stays warning' );
assert_true( ! $stage3_warned->is_enumeration_incomplete(), 'B2: a warning does not claim the enumeration was incomplete' );

$stage3_limited = new Conexao_Import_Result( stage3_src( 'status' ), 'Stage 3 rate-limit probe' );
$stage3_limited->mark_rate_limited( 'Not attempted: rate-limit cooldown.', true );
$stage3_limited->add_incomplete_enumeration( 'Also reported as incomplete.' );

assert_equals( 'rate_limited', $stage3_limited->get_status(), 'B3: an incomplete marker never downgrades rate_limited (P1 semantics preserved)' );

$stage3_fatal = new Conexao_Import_Result( stage3_src( 'status' ), 'Stage 3 fatal probe' );
$stage3_fatal->add_fatal_error( 'Upstream unreachable.' );
$stage3_fatal->add_incomplete_enumeration( 'Also reported as incomplete.' );

assert_equals( 'failed', $stage3_fatal->get_status(), 'B4: an incomplete marker never downgrades failed' );

$stage3_eventfail = new Conexao_Import_Result( stage3_src( 'status' ), 'Stage 3 event failure probe' );
$stage3_eventfail->add_failed( 'Bad event', 'Missing venue.' );

assert_equals( 'partial', $stage3_eventfail->get_status(), 'B5: a per-event failure is partial' );

test_section( 'B. The stored run status maps truthfully into the source registry' );

/**
 * Thin subclass used only to reach the protected status-mapping method.
 *
 * It changes no behaviour: the parent method is called unchanged.
 */
class Stage3_Test_Importer_Engine extends Conexao_Event_Importer_Engine {

	/**
	 * Expose update_source_stats_after_run() for the mapping assertions.
	 *
	 * @param string                $source_id Source slug.
	 * @param Conexao_Import_Result $result    Result.
	 * @return void
	 */
	public function stage3_update_stats( $source_id, Conexao_Import_Result $result ) {
		$this->update_source_stats_after_run( $source_id, $result );
	}
}

$stage3_sources = new Conexao_Event_Sources();
$stage3_engine  = new Stage3_Test_Importer_Engine( $stage3_sources, new Conexao_Event_Location() );

foreach ( array( 'ok', 'warn', 'part', 'rate', 'fail' ) as $stage3_kind ) {
	$stage3_sources->save(
		array(
			'id'                 => stage3_src( $stage3_kind ),
			'name'               => 'Stage 3 ' . $stage3_kind,
			'url'                => 'https://example.invalid/stage3-' . $stage3_kind,
			'type'               => 'website',
			'status'             => 'inactive',
			'last_import'        => '',
			'last_import_status' => '',
			'last_error'         => '',
			'events_imported'    => 0,
		)
	);
}

$stage3_ok = new Conexao_Import_Result( stage3_src( 'ok' ), 'ok' );
$stage3_engine->stage3_update_stats( stage3_src( 'ok' ), $stage3_ok );

$stage3_warn = new Conexao_Import_Result( stage3_src( 'warn' ), 'warn' );
$stage3_warn->add_skipped( 'Past event', 'The event has ended.' );
$stage3_engine->stage3_update_stats( stage3_src( 'warn' ), $stage3_warn );

$stage3_part = new Conexao_Import_Result( stage3_src( 'part' ), 'part' );
$stage3_part->add_incomplete_enumeration( 'Stopped at page 13 of 49.', 'time_budget' );
$stage3_engine->stage3_update_stats( stage3_src( 'part' ), $stage3_part );

$stage3_rate = new Conexao_Import_Result( stage3_src( 'rate' ), 'rate' );
$stage3_rate->mark_rate_limited( 'Rate limited by the provider.', false );
$stage3_engine->stage3_update_stats( stage3_src( 'rate' ), $stage3_rate );

$stage3_fail = new Conexao_Import_Result( stage3_src( 'fail' ), 'fail' );
$stage3_fail->add_fatal_error( 'Upstream unreachable.' );
$stage3_engine->stage3_update_stats( stage3_src( 'fail' ), $stage3_fail );

/**
 * Read one source registry row.
 *
 * @param string $source_id Source slug.
 * @return array
 */
function stage3_registry_row( $source_id ) {
	$sources = new Conexao_Event_Sources();
	$record  = $sources->get( $source_id );
	return is_array( $record ) ? $record : array();
}

assert_equals( 'success', stage3_registry_row( stage3_src( 'ok' ) )['last_import_status'], 'B6: success maps to success' );
assert_equals( 'warning', stage3_registry_row( stage3_src( 'warn' ) )['last_import_status'], 'B6: a completed walk with a caveat maps to warning' );
assert_equals( 'partial', stage3_registry_row( stage3_src( 'part' ) )['last_import_status'], 'B6: an incomplete walk maps to partial, not success' );
assert_equals( 'rate_limited', stage3_registry_row( stage3_src( 'rate' ) )['last_import_status'], 'B6: a rate-limited run maps to rate_limited, not success' );
assert_equals( 'error', stage3_registry_row( stage3_src( 'fail' ) )['last_import_status'], 'B6: a fatal run maps to error (the pre-Stage-3 value is preserved)' );

assert_contains( 'time budget exhausted', stage3_registry_row( stage3_src( 'part' ) )['last_error'], 'B7: the incomplete registry row names the actual stop cause' );
assert_true( '' !== (string) stage3_registry_row( stage3_src( 'rate' ) )['last_error'], 'B7: a rate-limited row carries a reason instead of reading as a clean success' );

assert_equals( false, Conexao_Event_Sources::is_last_import_successful( 'partial' ), 'B8: an incomplete run is not the "last successful import"' );
assert_equals( false, Conexao_Event_Sources::is_last_import_successful( 'rate_limited' ), 'B8: a rate-limited run is not the "last successful import"' );
assert_equals( true, Conexao_Event_Sources::is_last_import_successful( 'success' ), 'B8: a legacy success value still counts as successful' );
assert_equals( true, Conexao_Event_Sources::is_last_import_successful( '' ), 'B8: an empty legacy value keeps its old behaviour' );

test_section( 'B. The rendered labels distinguish the outcomes' );

assert_equals( 'incomplete or partial', Conexao_Event_Sources::history_status_label( 'partial' ), 'B9: partial renders as incomplete/partial' );
assert_equals( 'rate limited — incomplete', Conexao_Event_Sources::history_status_label( 'rate_limited' ), 'B9: rate_limited is never rendered as success' );
assert_equals( 'completed with warnings', Conexao_Event_Sources::history_status_label( 'warning' ), 'B9: warning renders as completed-with-warnings' );
assert_equals( 'failed', Conexao_Event_Sources::history_status_label( 'failed' ), 'B9: failed renders as failed' );
assert_equals( 'failed', Conexao_Event_Sources::history_status_label( 'error' ), 'B9: the legacy error value renders as failed' );
assert_equals( 'completed', Conexao_Event_Sources::history_status_label( 'completed' ), 'B9: the Stage 1 parent vocabulary still renders' );
assert_equals( 'completed with errors', Conexao_Event_Sources::history_status_label( 'completed_with_errors' ), 'B9: the Stage 1 parent vocabulary still renders' );

assert_equals(
	'time budget exhausted',
	Conexao_Event_Sources::history_status_reason( array( 'status' => 'partial', 'incomplete_reason' => 'time_budget' ) ),
	'B10: the status cell names the actual stop condition'
);
assert_equals(
	'request budget exhausted',
	Conexao_Event_Sources::history_status_reason( array( 'status' => 'partial', 'incomplete_reason' => 'budget_exhausted' ) ),
	'B10: request-budget exhaustion is distinguished from time-budget exhaustion'
);
assert_equals(
	'page cap reached',
	Conexao_Event_Sources::history_status_reason( array( 'status' => 'partial', 'incomplete_reason' => 'page_cap' ) ),
	'B10: a page cap is distinguished from a time budget'
);
assert_equals(
	'upstream fetch failure',
	Conexao_Event_Sources::history_status_reason( array( 'status' => 'failed', 'incomplete_reason' => 'fetch_failed' ) ),
	'B10: a fetch failure is distinguished from a budget stop'
);
assert_equals(
	'rate-limit cooldown deferred (Retry-After)',
	Conexao_Event_Sources::history_status_reason( array( 'status' => 'rate_limited', 'incomplete_reason' => 'rate_limited_deferred' ) ),
	'B10: a deferred Retry-After wait is distinguished from a hard cooldown'
);
assert_equals(
	'rate-limit cooldown',
	Conexao_Event_Sources::history_status_reason( array( 'status' => 'rate_limited' ) ),
	'B10: a rate-limited row explains the cooldown even with no stored cause'
);
assert_equals(
	'',
	Conexao_Event_Sources::history_status_reason( array( 'status' => 'success' ) ),
	'B10: a clean success carries no reason line'
);

// P1 protection: nothing in this suite armed the shared breaker or invented a
// competing status word.
assert_equals( false, Conexao_Eventbrite_Pacing::is_rate_limited(), 'B11: the shared Eventbrite breaker is untouched (P1 semantics preserved)' );

// ===========================================================================
// C. Stuck and abandoned continuation diagnostics (P12)
// ===========================================================================
test_section( 'C. Stuck/abandoned continuations are classified truthfully and read-only' );

/**
 * Write a synthetic continuation record directly into the isolated store.
 *
 * @param string $source_id Source slug.
 * @param array  $record    Record fields.
 * @return void
 */
function stage3_put_continuation( $source_id, array $record ) {
	$all = get_option( Conexao_Import_Continuation::OPTION_KEY, array() );
	if ( ! is_array( $all ) ) {
		$all = array();
	}
	$all[ $source_id ] = array_merge(
		array(
			'schema_version'      => 1,
			'source_id'           => $source_id,
			'state'               => '',
			'run_at'              => 0,
			'scheduled_at'        => 0,
			'last_run_at'         => 0,
			'attempts'            => 0,
			'stalled'             => 0,
			'last_page'           => 0,
			'page_count'          => 0,
			'reason'              => '',
			'run_id'              => '',
			'source_run_id'       => '',
			'parent_run_group_id' => '',
			'continuation_id'     => '',
			'message'             => '',
		),
		$record
	);
	update_option( Conexao_Import_Continuation::OPTION_KEY, $all, true );
}

$stage3_src_recent = stage3_src( 'recent' );
stage3_put_continuation(
	$stage3_src_recent,
	array(
		'state'               => 'running',
		'run_at'              => 0,
		'last_run_at'         => $stage3_now - 60,
		'last_page'           => 13,
		'page_count'          => 49,
		'attempts'            => 2,
		'parent_run_group_id' => 'stage3-group-recent',
		'source_run_id'       => 'stage3-child-recent',
	)
);

$stage3_recent = Conexao_Import_Continuation::classify( Conexao_Import_Continuation::get( $stage3_src_recent ) );

assert_equals( 'running', $stage3_recent['class'], 'C1: a recently progressing record is NOT flagged stale' );
assert_equals( false, $stage3_recent['stale'], 'C1: a recently progressing record is not stale' );
assert_equals( true, $stage3_recent['running_without_run_at'], 'C1: the running/run_at=0 shape is reported as a fact, not as a stall' );

$stage3_src_expired = stage3_src( 'expired' );
stage3_put_continuation(
	$stage3_src_expired,
	array(
		'state'               => 'running',
		'run_at'              => 0,
		'last_run_at'         => $stage3_now - ( Conexao_Import_Continuation::STALE_RUNNING_SECONDS + 600 ),
		'last_page'           => 13,
		'page_count'          => 49,
		'attempts'            => 4,
		'parent_run_group_id' => 'stage3-group-expired',
	)
);

$stage3_expired = Conexao_Import_Continuation::classify( Conexao_Import_Continuation::get( $stage3_src_expired ) );

assert_equals( 'running_stale', $stage3_expired['class'], 'C2: no progress beyond the documented threshold is surfaced as suspected stale' );
assert_equals( true, $stage3_expired['stale'], 'C2: the stale flag is set for the expired fixture' );
assert_true( $stage3_expired['age'] > Conexao_Import_Continuation::STALE_RUNNING_SECONDS, 'C2: the reported age is the real elapsed time' );
assert_equals( true, $stage3_expired['running_without_run_at'], 'C2: the historical running/run_at=0 shape is surfaced' );

$stage3_src_unknown = stage3_src( 'indeterminate' );
stage3_put_continuation(
	$stage3_src_unknown,
	array(
		'state'        => 'running',
		'run_at'       => 0,
		'last_run_at'  => 0,
		'scheduled_at' => 0,
		'last_page'    => 7,
		'page_count'   => 9,
	)
);

$stage3_unknown = Conexao_Import_Continuation::classify( Conexao_Import_Continuation::get( $stage3_src_unknown ) );

assert_equals( 'running_indeterminate', $stage3_unknown['class'], 'C3: a running record with no usable timestamp is INDETERMINATE, not declared stale' );
assert_equals( false, $stage3_unknown['stale'], 'C3: insufficient evidence never yields a stale verdict' );
assert_equals( true, $stage3_unknown['indeterminate'], 'C3: the indeterminate flag is set' );

$stage3_src_terminal = stage3_src( 'exhausted' );
stage3_put_continuation(
	$stage3_src_terminal,
	array(
		'state'      => 'exhausted',
		'run_at'     => 0,
		'last_page'  => 4,
		'page_count' => 49,
		'message'    => 'attempt_ceiling',
	)
);

assert_equals(
	'exhausted',
	Conexao_Import_Continuation::classify( Conexao_Import_Continuation::get( $stage3_src_terminal ) )['class'],
	'C4: an exhausted record is reported as a terminal stall'
);

$stage3_src_deferred = stage3_src( 'deferred' );
stage3_put_continuation(
	$stage3_src_deferred,
	array(
		'state'  => 'scheduled',
		'run_at' => $stage3_now - 120,
	)
);

assert_equals(
	'deferred',
	Conexao_Import_Continuation::classify( Conexao_Import_Continuation::get( $stage3_src_deferred ) )['class'],
	'C5: a scheduled record whose time has elapsed is deferred, not lost'
);

// READ-ONLY PROOF: reading the diagnostics must not mutate anything.
$stage3_before_raw = wp_json_encode( get_option( Conexao_Import_Continuation::OPTION_KEY, array() ) );
$stage3_before_ck  = wp_json_encode( get_option( Conexao_Source_Enumeration_Checkpoint::OPTION_KEY, array() ) );

$stage3_diag_rows   = Conexao_Import_Continuation::get_diagnostics();
$stage3_diag_counts = Conexao_Import_Continuation::get_diagnostic_counts();

$stage3_after_raw = wp_json_encode( get_option( Conexao_Import_Continuation::OPTION_KEY, array() ) );
$stage3_after_ck  = wp_json_encode( get_option( Conexao_Source_Enumeration_Checkpoint::OPTION_KEY, array() ) );

assert_true( ! empty( $stage3_diag_rows ), 'C6: diagnostics return rows for the synthetic fixtures' );
assert_equals( $stage3_before_raw, $stage3_after_raw, 'C6: reading the diagnostics leaves the continuation store byte-identical' );
assert_equals( $stage3_before_ck, $stage3_after_ck, 'C6: reading the diagnostics leaves the checkpoint store byte-identical' );
assert_equals( 1, isset( $stage3_diag_counts['running_stale'] ) ? $stage3_diag_counts['running_stale'] : 0, 'C6: the summary counts the stale fixture' );
assert_equals( 1, isset( $stage3_diag_counts['running_indeterminate'] ) ? $stage3_diag_counts['running_indeterminate'] : 0, 'C6: the summary counts the indeterminate fixture' );

// The stale record must be left exactly as it was: diagnostics never recover.
assert_equals( 'running', $stage3_diag_rows[ $stage3_src_expired ]['state'], 'C7: the diagnostics do not reset a stale record' );
assert_equals( 'stage3-group-expired', $stage3_diag_rows[ $stage3_src_expired ]['parent_run_group_id'], 'C7: the diagnostics expose the parent group for a stale record' );
assert_equals( '', $stage3_diag_rows[ $stage3_src_expired ]['source_run_id'], 'C7: a fixture with no child run ID is reported as uncorrelated rather than given one' );
assert_equals( true, $stage3_diag_rows[ $stage3_src_expired ]['correlated'], 'C7: a record with a parent group counts as correlated' );

// ===========================================================================
// D. events_imported semantics (P12)
// ===========================================================================
test_section( 'D. events_imported is presented as a per-run creation count' );

$stage3_sources_source = (string) file_get_contents(
	WP_PLUGIN_DIR . '/conexao-event-importer/includes/class-event-sources.php'
);

assert_contains( 'Created (last run)', $stage3_sources_source, 'D1: the Event Sources column is labelled as a last-run creation count' );
assert_true(
	false === strpos( $stage3_sources_source, "'Events Imported'" ),
	'D1: the misleading cumulative-sounding header is gone'
);
assert_true(
	'' !== stage3_registry_row( stage3_src( 'ok' ) )['events_imported'],
	'D2: the stored field still exists (no silent reset or reinterpretation)'
);

$stage3_created_result = new Conexao_Import_Result( stage3_src( 'count' ), 'count' );
$stage3_created_result->add_created( 'Event one' );
$stage3_created_result->add_created( 'Event two' );
$stage3_created_result->add_updated( 'Event three' );

assert_equals( 2, (int) $stage3_created_result->get_counts()['created'], 'D3: created is a distinct per-run counter' );
assert_equals( 1, (int) $stage3_created_result->get_counts()['updated'], 'D3: updated is not collapsed into created' );

// ===========================================================================
// E. Group-aware retention and full logical-run reconstruction (P13)
// ===========================================================================
test_section( 'E. A logical Run All is retained, reconstructed and downloaded as one unit' );

$stage3_run_group = 'stage3-run-' . wp_generate_uuid4();
$stage3_children  = array(
	'stage3-child-a' => 'stage3_source_a',
	'stage3-child-b' => 'stage3_source_b',
	'stage3-child-c' => 'stage3_source_c',
);

// The parent row, exactly as the engine writes it.
Conexao_Import_History::record_parent(
	$stage3_run_group,
	array( 'found' => 3, 'new' => 1, 'updated' => 1, 'skipped' => 1 ),
	'completed',
	'Stage 3 synthetic Run All',
	array( 'planned_source_count' => 3 )
);

// Three child rows in the same group.
foreach ( $stage3_children as $child_run_id => $source_id ) {
	Conexao_Import_History::record(
		$source_id,
		array( 'found' => 1, 'new' => 0, 'updated' => 0, 'skipped' => 0 ),
		'partial',
		'child ' . $source_id,
		array(
			'run_id'              => $stage3_run_group,
			'parent_run_group_id' => $stage3_run_group,
			'source_run_id'       => $child_run_id,
			'complete'            => false,
			'incomplete_reason'   => 'time_budget',
		)
	);
}

// 60 log entries across the parent and all three children, plus the parent
// lifecycle — more than the 50-entry inline preview.
$stage3_logged = 0;
foreach ( array_merge( array( 'all' => $stage3_run_group ), $stage3_children ) as $log_child => $log_source ) {
	$entries = 'all' === $log_child ? 6 : 18;
	for ( $i = 1; $i <= $entries; $i++ ) {
		Conexao_Import_Log::add(
			'all' === $log_child ? 'all' : $log_source,
			'info',
			sprintf( 'stage3 synthetic entry %s #%d', $log_child, $i ),
			array(
				'run_id'              => $stage3_run_group,
				'parent_run_group_id' => $stage3_run_group,
				'source_run_id'       => 'all' === $log_child ? '' : $log_child,
			)
		);
		++$stage3_logged;
	}
}
Conexao_Import_Log::flush();

$stage3_group_entries = Conexao_Import_Log::get_for_group( $stage3_run_group );

assert_equals( $stage3_logged, count( $stage3_group_entries ), 'E1: every parent and child entry is retained under the one logical group' );

$stage3_group_rows = Conexao_Import_History::get_for_parent( $stage3_run_group );

assert_equals( 4, count( $stage3_group_rows ), 'E2: the parent and all three child rows share one group (one parent + three children)' );

$stage3_parent_rows = array_values(
	array_filter(
		$stage3_group_rows,
		static function ( $row ) {
			return 'parent' === (string) ( isset( $row['record_type'] ) ? $row['record_type'] : '' );
		}
	)
);

assert_equals( 1, count( $stage3_parent_rows ), 'E2: exactly one parent row exists for the group' );
assert_equals( 'completed', $stage3_parent_rows[0]['status'], 'E2: the parent keeps its terminal aggregate status' );

$stage3_export = Conexao_Import_Log::export_run( $stage3_run_group );

assert_contains( '# Retained entries: ' . $stage3_logged, $stage3_export, 'E3: the download states how many entries it contains' );
assert_contains( 'stage3-child-a', $stage3_export, 'E3: the download manifest names a child source run' );
assert_contains( 'stage3_source_a', $stage3_export, 'E3: the download includes child A entries' );
assert_contains( 'stage3_source_b', $stage3_export, 'E3: the download includes child B entries' );
assert_contains( 'stage3_source_c', $stage3_export, 'E3: the download includes child C entries' );

$stage3_export_pos_a = strpos( $stage3_export, 'stage3 synthetic entry all #1' );
$stage3_export_pos_z = strpos( $stage3_export, 'stage3 synthetic entry stage3-child-c #18' );

assert_true( false !== $stage3_export_pos_a && false !== $stage3_export_pos_z, 'E4: both ends of the run are present in the download' );
assert_true( $stage3_export_pos_a < $stage3_export_pos_z, 'E4: the download is in chronological order (oldest first)' );

$stage3_export_lines = count( array_filter( explode( "\n", $stage3_export ) ) );
assert_true( $stage3_export_lines > 50, 'E4: the download is not capped at the 50-entry inline preview (got ' . $stage3_export_lines . ' lines)' );

// ===========================================================================
// F. Inline preview disclosure (P13)
// ===========================================================================
test_section( 'F. The inline preview discloses the shown count, the total and the full-download path' );

$stage3_preview = Conexao_Import_Log::preview( $stage3_group_entries, 50 );

assert_equals( 50, $stage3_preview['shown'], 'F1: the preview is bounded at 50 entries' );
assert_equals( $stage3_logged, $stage3_preview['total'], 'F1: the preview reports the true total available' );
assert_equals( true, $stage3_preview['truncated'], 'F1: the preview declares itself truncated' );

$stage3_small_preview = Conexao_Import_Log::preview( array_slice( $stage3_group_entries, 0, 3 ), 50 );
assert_equals( false, $stage3_small_preview['truncated'], 'F2: a short run is not falsely declared truncated' );
assert_equals( 3, $stage3_small_preview['shown'], 'F2: a short run reports the count it really has' );

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$stage3_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
	)
);

test_require(
	! empty( $stage3_admins ),
	'fixture:administrator',
	'an administrator account exists for rendering the table',
	'create an administrator in the local install'
);

wp_set_current_user( (int) $stage3_admins[0]->ID );

ob_start();
( new Conexao_Event_Sources() )->render_history_table( Conexao_Import_History::get_all() );
$stage3_history_html = (string) ob_get_clean();

assert_contains( 'Preview: showing the newest 50 of ' . $stage3_logged, $stage3_history_html, 'F3: the rendered panel states the shown count and the true total' );
assert_contains( 'truncated view', $stage3_history_html, 'F3: the rendered panel says the view is truncated' );
assert_contains( 'Download the complete run (' . $stage3_logged . ' entries)', $stage3_history_html, 'F3: the rendered panel names the full-download path' );
assert_contains( 'conexao_download_run_log', $stage3_history_html, 'F4: the download link reuses the protected per-run handler' );
assert_contains( 'History retention:', $stage3_history_html, 'F5: the documented retention bound is visible in Import History' );

wp_set_current_user( 0 );

// ===========================================================================
// G. Legacy records (P12/P13)
// ===========================================================================
test_section( 'G. Records with no run identity stay explicitly legacy' );

$stage3_legacy_entry = array(
	'time'    => '2026-11-15 09:00:00',
	'source'  => 'legacy_source',
	'level'   => 'info',
	'message' => 'stage3 legacy entry with no run id',
	'run_id'  => '',
	'context' => array(),
);

assert_equals( Conexao_Import_Log::LEGACY_GROUP, Conexao_Import_Log::entry_group_key( $stage3_legacy_entry ), 'G1: an entry with no run identity is grouped as legacy' );

$stage3_legacy_row = array(
	'time'    => '2026-11-15 09:00:00',
	'source'  => 'legacy_source',
	'status'  => 'success',
);

assert_equals( 'legacy', Conexao_Import_History::entry_group_key( $stage3_legacy_row ), 'G2: a history row with no run identity is grouped as legacy' );

// A legacy row is never given an invented ID: the renderer says the logs are
// unavailable rather than matching by source or timestamp.
$stage3_legacy_index = Conexao_Import_Log::index_entries_by_run( array( $stage3_legacy_entry ) );
assert_equals( 0, count( $stage3_legacy_index ), 'G3: an uncorrelated entry is not indexed under any invented run ID' );

$stage3_legacy_export = Conexao_Import_Log::export_run( 'stage3-does-not-exist' );
assert_contains( '(No retained log entries for this run.', $stage3_legacy_export, 'G3: an unknown run downloads an honest empty document' );

// ===========================================================================
// H. Retention policy at the bound (P13)
// ===========================================================================
test_section( 'H. Retention evicts whole groups, protects active runs, is idempotent and reports pressure' );

/**
 * Build a synthetic log array of N groups with M entries each.
 *
 * @param int    $group_count Number of groups.
 * @param int    $per_group   Entries per group.
 * @param string $prefix      Group key prefix.
 * @param int    $base_time   Base timestamp.
 * @return array Entries, newest first.
 */
function stage3_build_entries( $group_count, $per_group, $prefix, $base_time ) {
	$entries = array();
	// Newest first: the highest group index is the most recent.
	for ( $group = $group_count - 1; $group >= 0; $group-- ) {
		for ( $i = 0; $i < $per_group; $i++ ) {
			$entries[] = array(
				'time'    => gmdate( 'Y-m-d H:i:s', $base_time + ( $group * 3600 ) + $i ),
				'source'  => 'stage3_source_' . $group,
				'level'   => 'info',
				'message' => $prefix . ' g' . $group . ' e' . $i,
				'run_id'  => '',
				'context' => array( 'parent_run_group_id' => $prefix . 'group' . $group ),
			);
		}
	}
	return $entries;
}

// 41 groups × 200 entries = 8,200 entries: just over the 8,000 bound.
$stage3_many = stage3_build_entries( 41, 200, 'stage3ret', $stage3_now - 200000 );

$stage3_retained = Conexao_Import_Log::retain( $stage3_many );

assert_true( count( $stage3_retained ) <= Conexao_Import_Log::MAX_ENTRIES, 'H1: retention brings the store within the documented bound (got ' . count( $stage3_retained ) . ')' );

$stage3_retained_groups = array();
foreach ( $stage3_retained as $entry ) {
	$key = Conexao_Import_Log::entry_group_key( $entry );
	$stage3_retained_groups[ $key ] = isset( $stage3_retained_groups[ $key ] ) ? $stage3_retained_groups[ $key ] + 1 : 1;
}

$stage3_partial_groups = array_filter(
	$stage3_retained_groups,
	static function ( $count ) {
		return 200 !== (int) $count;
	}
);

assert_true( empty( $stage3_partial_groups ), 'H2: no group is evicted partially — every retained group keeps all 200 entries' );
assert_true( ! isset( $stage3_retained_groups['group:stage3retgroup40'] ) || 200 === (int) $stage3_retained_groups['group:stage3retgroup40'], 'H2: the newest group is retained whole' );
assert_true( isset( $stage3_retained_groups['group:stage3retgroup40'] ), 'H2: the newest group is never evicted to make room for itself' );
assert_true( ! isset( $stage3_retained_groups['group:stage3retgroup0'] ), 'H2: the oldest group is the first to go' );

// Idempotence: applying retention again changes nothing.
$stage3_retained_twice = Conexao_Import_Log::retain( $stage3_retained );
assert_equals( count( $stage3_retained ), count( $stage3_retained_twice ), 'H3: retention is idempotent (second pass removes nothing)' );
assert_equals( wp_json_encode( $stage3_retained ), wp_json_encode( $stage3_retained_twice ), 'H3: the second pass leaves the retained set byte-identical' );

// Active-group protection: an unfinished logical run must never be evicted,
// whatever the pressure.
$stage3_active_group = 'stage3retgroup40';
Conexao_Import_History::record_parent(
	$stage3_active_group,
	array( 'found' => 0 ),
	'running',
	'Stage 3 active run'
);

$stage3_retained_active = Conexao_Import_Log::retain( $stage3_many );

$stage3_active_groups = array();
foreach ( $stage3_retained_active as $entry ) {
	$key = Conexao_Import_Log::entry_group_key( $entry );
	$stage3_active_groups[ $key ] = isset( $stage3_active_groups[ $key ] ) ? $stage3_active_groups[ $key ] + 1 : 1;
}

assert_equals( 200, isset( $stage3_active_groups['group:' . $stage3_active_group] ) ? $stage3_active_groups[ 'group:' . $stage3_active_group ] : 0, 'H4: an ACTIVE group is never evicted, and never partially' );
assert_equals( 1, count( Conexao_Import_History::get_active_group_ids() ), 'H4: the active group is discoverable through the history store' );

// Every group active: nothing may be discarded, and the overage is reported.
$stage3_all_active = array();
foreach ( $stage3_many as $stage3_entry ) {
	$stage3_existing_key = Conexao_Import_Log::entry_group_key( $stage3_entry );
	$stage3_all_active[ substr( $stage3_existing_key, strlen( 'group:' ) ) ] = true;
}
$stage3_all_active = array_keys( $stage3_all_active );
foreach ( $stage3_all_active as $stage3_active_id ) {
	if ( $stage3_active_id === $stage3_active_group ) {
		continue;
	}
	Conexao_Import_History::record_parent(
		$stage3_active_id,
		array( 'found' => 0 ),
		'queued',
		'Stage 3 queued run'
	);
}

$stage3_retained_all_active = Conexao_Import_Log::retain( $stage3_many );

assert_equals( count( $stage3_many ), count( $stage3_retained_all_active ), 'H5: when every group is active nothing is discarded, even over the bound' );

// Store-backed pressure report.
update_option( Conexao_Import_Log::option_key(), $stage3_many, false );

$stage3_log_state = Conexao_Import_Log::get_retention_state();

assert_equals( count( $stage3_many ), (int) $stage3_log_state['total'], 'H5: the retention state reports the real stored total' );
assert_equals( true, $stage3_log_state['pressure'], 'H5: exceeding the bound with active runs is reported as pressure, not hidden' );
assert_equals( count( $stage3_many ) - Conexao_Import_Log::MAX_ENTRIES, (int) $stage3_log_state['over_by'], 'H5: the overage is reported numerically' );
assert_true( (int) $stage3_log_state['max_entries'] > 0, 'H5: the bound itself is reported' );

// Release the synthetic active groups and prove the store returns to the bound.
foreach ( array_merge( array( $stage3_active_group ), $stage3_all_active ) as $stage3_release ) {
	Conexao_Import_History::record_parent(
		$stage3_release,
		array( 'found' => 0 ),
		'completed',
		'Stage 3 completed run'
	);
}

$stage3_store_retained = Conexao_Import_Log::retain( Conexao_Import_Log::get_all() );
update_option( Conexao_Import_Log::option_key(), $stage3_store_retained, false );

$stage3_log_state_after = Conexao_Import_Log::get_retention_state();
assert_equals( false, $stage3_log_state_after['pressure'], 'H6: once the groups are terminal the bound is met again' );
assert_true( (int) $stage3_log_state_after['total'] <= Conexao_Import_Log::MAX_ENTRIES, 'H6: the retained total is back within the bound' );

// ===========================================================================
// I. History retention: parent and children are one unit (P13)
// ===========================================================================
test_section( 'I. History retention removes a whole logical run, never a parent or a child alone' );

/**
 * Build a synthetic history array of completed logical groups.
 *
 * @param int    $group_count Groups.
 * @param string $prefix      Group prefix.
 * @param int    $base_time   Base timestamp.
 * @return array Rows, newest first.
 */
function stage3_build_history( $group_count, $prefix, $base_time ) {
	$rows = array();
	for ( $group = $group_count - 1; $group >= 0; $group-- ) {
		$group_id = $prefix . 'g' . $group;
		$time     = gmdate( 'Y-m-d H:i:s', $base_time + ( $group * 3600 ) );
		// Newest first: children first, then the parent, as record_parent()
		// unshifts the parent on top.
		$rows[] = array(
			'time'                => $time,
			'source'              => $prefix . 'src_b',
			'status'              => 'success',
			'run_id'              => $group_id,
			'parent_run_group_id' => $group_id,
			'source_run_id'       => $group_id . '-child-b',
			'record_type'         => 'child',
		);
		$rows[] = array(
			'time'                => $time,
			'source'              => $prefix . 'src_a',
			'status'              => 'success',
			'run_id'              => $group_id,
			'parent_run_group_id' => $group_id,
			'source_run_id'       => $group_id . '-child-a',
			'record_type'         => 'child',
		);
		$rows[] = array(
			'time'                => $time,
			'source'              => 'all',
			'status'              => 'completed',
			'run_id'              => $group_id,
			'parent_run_group_id' => $group_id,
			'record_type'         => 'parent',
		);
	}
	return $rows;
}

$stage3_history_many = stage3_build_history( Conexao_Import_History::MAX_GROUPS + 2, 'stage3hist', $stage3_now - 100000 );

assert_equals( 3 * ( Conexao_Import_History::MAX_GROUPS + 2 ), count( $stage3_history_many ), 'I0: fixture is MAX_GROUPS + 2 logical runs of parent + two children' );

$stage3_history_retained = Conexao_Import_History::retain( $stage3_history_many );

$stage3_history_groups = array();
foreach ( $stage3_history_retained as $stage3_row ) {
	$stage3_gk                      = Conexao_Import_History::entry_group_key( $stage3_row );
	$stage3_history_groups[ $stage3_gk ] = isset( $stage3_history_groups[ $stage3_gk ] ) ? $stage3_history_groups[ $stage3_gk ] + 1 : 1;
}

assert_equals( Conexao_Import_History::MAX_GROUPS, count( $stage3_history_groups ), 'I1: retention keeps exactly the documented number of completed runs' );

$stage3_history_partial = array_filter(
	$stage3_history_groups,
	static function ( $count ) {
		return 3 !== (int) $count;
	}
);

assert_true( empty( $stage3_history_partial ), 'I2: every retained run keeps its parent AND both children (no orphan rows)' );
assert_true( ! isset( $stage3_history_groups['group:stage3histg0'] ), 'I2: the oldest run is evicted as a unit' );
assert_true( ! isset( $stage3_history_groups['group:stage3histg1'] ), 'I2: the second-oldest run is evicted as a unit' );
assert_true( isset( $stage3_history_groups['group:stage3histg' . ( Conexao_Import_History::MAX_GROUPS + 1 )] ), 'I2: the newest run is retained' );

$stage3_history_twice = Conexao_Import_History::retain( $stage3_history_retained );
assert_equals( wp_json_encode( $stage3_history_retained ), wp_json_encode( $stage3_history_twice ), 'I3: history retention is idempotent' );

// An ACTIVE run is protected even when it is the oldest one.
$stage3_history_active = $stage3_history_many;
foreach ( $stage3_history_active as $stage3_index => $stage3_row ) {
	if ( 'group:stage3histg0' === Conexao_Import_History::entry_group_key( $stage3_row ) ) {
		$stage3_history_active[ $stage3_index ]['status'] = 'running';
	}
}

$stage3_history_active_retained = Conexao_Import_History::retain( $stage3_history_active );

$stage3_history_active_groups = array();
foreach ( $stage3_history_active_retained as $stage3_row ) {
	$stage3_history_active_groups[ Conexao_Import_History::entry_group_key( $stage3_row ) ] = true;
}

assert_true( isset( $stage3_history_active_groups['group:stage3histg0'] ), 'I4: an ACTIVE run is never evicted, even when it is the oldest' );
assert_true( count( $stage3_history_active_groups ) >= Conexao_Import_History::MAX_GROUPS, 'I4: protecting the oldest run does not shrink the retained set below the bound' );

test_finish( 'stage3-diagnostics-and-retention' );
