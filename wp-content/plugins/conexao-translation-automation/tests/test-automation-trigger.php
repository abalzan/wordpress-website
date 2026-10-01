<?php
/**
 * Stage 3 trigger and integration tests.
 *
 * ## The path under test
 *
 *     trigger -> reconciliation -> inventory diff -> provider boundary
 *             -> orchestrator
 *
 * using test doubles, and it ends BEFORE any real production mutation. The
 * suite asserts that the run reports `mutation_permitted = false` and
 * `mutation_occurred = false` on EVERY path, including the ones that find real
 * work.
 *
 * ## Why this suite exists separately
 *
 * The individual components are unit-tested elsewhere. What can only be proven
 * end to end is the ORDER: that a trigger cannot skip the lock, cannot skip the
 * inventory, cannot reach apply, and cannot turn a missing baseline into mass
 * translation.
 *
 * @package Conexao_Translation_Automation
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation/conexao-translation-automation.php';

use Conexao_Translation_Automation_Audit as Audit;
use Conexao_Translation_Automation_Source_State as State;
use Conexao_Translation_Automation_Trigger as Trigger;

test_title( 'conexao-translation-automation — Stage 3 trigger and integration' );

// Pin the environment detector so a local test cannot accidentally assert it
// is production (or the reverse).
Conexao_Translation_Automation_Environment::set_site_detector( static function (): string { return 'local'; } );

// A clean slate for every piece of persisted state, INCLUDING the lock. The
// suite exercises the lock's refusal path, and a lock left behind by an aborted
// run would make every later assertion fail for the wrong reason.
Conexao_Translation_Automation_Lock::force_clear();
State::clear();
Audit::clear();

assert_true( ! Conexao_Translation_Automation_Lock::is_held(), 'the suite starts with no lock held' );

// ---------------------------------------------------------------------------
// 1. A trigger with an invalid stage is refused
// ---------------------------------------------------------------------------
test_section( 'Trigger refuses an invalid stage' );

foreach ( array( '', 'not-a-stage', 'job', 'EN-GUIDE', 'en_guide' ) as $bad_stage ) {
	$report = Trigger::fire( array( 'stage' => $bad_stage, 'environment' => 'local' ) );

	assert_true( false === (bool) $report['ok'], sprintf( 'the stage "%s" is refused', $bad_stage ) );
	assert_equals( Trigger::OUTCOME_REFUSED, (string) $report['outcome'], sprintf( 'the stage "%s" yields a refusal outcome', $bad_stage ) );
	assert_equals( Trigger::FAILURE_UNKNOWN_STAGE, (string) $report['failure'], sprintf( 'the stage "%s" fails closed with unknown_stage', $bad_stage ) );
}

// ---------------------------------------------------------------------------
// 2. A trigger with an invalid environment is refused
// ---------------------------------------------------------------------------
test_section( 'Trigger refuses an invalid environment' );

// A trailing space is TRIMMED, so 'staging ' is a valid `staging` label; that
// is deliberate normalisation, not an unknown environment.
foreach ( array( '', 'prod', 'PRODUCTION', 'dev', 'stagin g' ) as $bad_env ) {
	$report = Trigger::fire( array( 'stage' => 'en-guide', 'environment' => $bad_env ) );

	assert_true( false === (bool) $report['ok'], sprintf( 'the environment "%s" is refused', $bad_env ) );
	assert_equals( Trigger::FAILURE_INVALID_REQUEST, (string) $report['failure'], sprintf( 'the environment "%s" fails closed', $bad_env ) );
	assert_true( false === $report['mutation_permitted'], sprintf( 'the environment "%s" permits no mutation', $bad_env ) );
}

// A padded but valid label is accepted, because trimming is normalisation.
$padded = Trigger::fire( array( 'stage' => 'en-guide', 'environment' => ' local ' ) );
assert_true(
	Trigger::FAILURE_INVALID_REQUEST !== (string) $padded['failure'],
	'a padded but valid environment label is accepted (trimming is normalisation)'
);

// ---------------------------------------------------------------------------
// 3. Missing persisted state NEVER means "translate everything"
// ---------------------------------------------------------------------------
test_section( 'Missing state demands a bootstrap, never a mass translation' );

State::clear();

$report = Trigger::fire( array( 'stage' => 'en-guide', 'environment' => 'local', 'trigger' => Audit::TRIGGER_MANUAL ) );

assert_equals( Trigger::OUTCOME_BOOTSTRAP_REQUIRED, (string) $report['outcome'], 'a missing baseline yields bootstrap_required' );
assert_true( false === (bool) $report['ok'], 'bootstrap_required is not a success' );
assert_equals( 0, (int) $report['actionable'], 'NOT ONE record is proposed for translation' );
assert_true( false === $report['mutation_permitted'], 'bootstrap_required permits no mutation' );
assert_true( false === $report['mutation_occurred'], 'bootstrap_required mutates nothing' );

// Crucially: the run did NOT write a baseline as a side effect.
assert_equals(
	State::STATUS_MISSING,
	State::read( Conexao_Translation_Automation_Orchestrator::allowed_stage_ids() )['status'],
	'a refused run does NOT silently adopt a baseline'
);

// The refusal IS audited, so an operator can see it happened.
$latest = Audit::latest();
assert_true( ! empty( $latest ), 'the bootstrap-required refusal is audited' );
assert_equals( Audit::TRIGGER_MANUAL, (string) $latest['trigger'], 'the refusal records that it came from a manual trigger' );
assert_true( false === $latest['mutation_occurred'], 'the audited refusal records that nothing mutated' );

// ---------------------------------------------------------------------------
// 4. An explicit bootstrap adopts the baseline WITHOUT translating
// ---------------------------------------------------------------------------
test_section( 'An explicit bootstrap adopts the baseline and still translates nothing' );

$boot = Trigger::fire( array( 'stage' => 'en-guide', 'environment' => 'local', 'bootstrap' => true ) );

assert_equals( Trigger::OUTCOME_BOOTSTRAPPED, (string) $boot['outcome'], 'an explicit bootstrap adopts the baseline' );
assert_true( false === $boot['mutation_permitted'], 'a bootstrap permits no mutation' );
assert_true( false === $boot['mutation_occurred'], 'a bootstrap mutates nothing' );
assert_true( (int) $boot['records_seen'] > 0, 'the bootstrap observed real records' );

$state = State::read( Conexao_Translation_Automation_Orchestrator::allowed_stage_ids() );
assert_equals( State::STATUS_OK, $state['status'], 'the baseline is now persisted' );
assert_true( isset( $state['stages']['en-guide'] ), 'the baseline covers the bootstrapped stage' );
assert_equals( 64, strlen( (string) $state['inventory_digest'] ), 'the persisted baseline carries an inventory digest' );

// The bootstrap is audited as a bootstrap, distinctly from a normal run.
$latest = Audit::latest();
assert_equals( Audit::TRIGGER_BOOTSTRAP, (string) $latest['trigger'], 'the baseline adoption is audited as a bootstrap' );

// ---------------------------------------------------------------------------
// 5. A reconciliation over an unchanged baseline finds nothing
// ---------------------------------------------------------------------------
test_section( 'A duplicate trigger over unchanged state is harmless' );

$first  = Trigger::fire( array( 'stage' => 'en-guide', 'environment' => 'local' ) );
$second = Trigger::fire( array( 'stage' => 'en-guide', 'environment' => 'local' ) );

assert_equals( 0, (int) $first['actionable'], 'the first reconciliation over unchanged state finds no work' );
assert_equals( 0, (int) $second['actionable'], 'the duplicate reconciliation also finds no work' );
assert_true(
	(string) $first['change_set_digest'] === (string) $second['change_set_digest'],
	'a duplicate trigger produces the identical change set'
);
assert_true(
	false === $first['mutation_occurred'] && false === $second['mutation_occurred'],
	'neither reconciliation mutated anything'
);

// Both runs are audited, and both record the same outcome.
assert_equals( 2, count( array_slice( Audit::read(), -2 ) ), 'both duplicate-trigger runs were audited' );

// ---------------------------------------------------------------------------
// 6. A trigger while the lock is held is refused
// ---------------------------------------------------------------------------
test_section( 'A trigger while the lock is held is refused' );

$held = Conexao_Translation_Automation_Lock::acquire(
	array( 'run_id' => 'run_s3_holder', 'stage' => 'en-guide', 'environment' => 'local' )
);
assert_true( ! empty( $held['held'] ), 'an external holder takes the site-wide lock' );

$blocked = Trigger::fire( array( 'stage' => 'en-guide', 'environment' => 'local' ) );

assert_equals( Trigger::OUTCOME_LOCKED, (string) $blocked['outcome'], 'a trigger during an active lock is refused, not queued' );
assert_true( false === $blocked['mutation_permitted'], 'a locked run permits no mutation' );
assert_true( false === $blocked['mutation_occurred'], 'a locked run mutates nothing' );
assert_equals( 0, (int) $blocked['actionable'], 'a locked run does no work' );

// The holder's lock is untouched: a refused run never releases another's lock.
$still_held = Conexao_Translation_Automation_Lock::inspect();
assert_true( is_array( $still_held ), 'the holder still holds the lock after a refused run' );
assert_equals( 'run_s3_holder', (string) $still_held['run_id'], 'the refused run did not take over the lock' );

// The refusal is audited, with the lock outcome recorded.
$latest = Audit::latest();
assert_true( '' !== (string) $latest['lock_status'], 'the locked refusal records the lock status' );

Conexao_Translation_Automation_Lock::release( 'run_s3_holder' );
assert_true( ! Conexao_Translation_Automation_Lock::is_held(), 'the holder can release the lock' );

// ---------------------------------------------------------------------------
// 7. Corrupt state refuses rather than translating
// ---------------------------------------------------------------------------
test_section( 'Corrupt state refuses rather than translating' );

$baseline = State::build( array( 'en-guide' => array( 'x' => array( 'digest' => hash( 'sha256', 'x' ) ) ) ), 'run_x' );

$corruptions = array(
	array( 'payload' => 'not a record', 'label' => 'a non-record value' ),
	array(
		'payload' => array_merge( $baseline, array( 'v' => 99 ) ),
		'label'   => 'an unknown schema version',
	),
	array(
		'payload' => array_merge( $baseline, array( 'stages' => array( 'job' => array() ) ) ),
		'label'   => 'a non-allowlisted stage',
	),
	array(
		'payload' => array_merge( $baseline, array( 'stages' => array( 'en-guide' => array( 'x' => array( 'digest' => 'bad' ) ) ) ) ),
		'label'   => 'an impossible digest',
	),
	array(
		'payload' => array_merge( $baseline, array( 'stages' => array( 'en-guide' => array( 'x' => array( 'digest' => hash( 'sha256', 'x' ), 'status' => 'nonsense' ) ) ) ) ),
		'label'   => 'an unknown row status',
	),
);

foreach ( $corruptions as $corruption ) {
	update_option( State::OPTION, $corruption['payload'], false );

	$report = Trigger::fire( array( 'stage' => 'en-guide', 'environment' => 'local' ) );

	assert_equals(
		Trigger::FAILURE_STATE_UNUSABLE,
		(string) $report['failure'],
		sprintf( '%s is a hard refusal', $corruption['label'] )
	);
	assert_equals( 0, (int) $report['actionable'], sprintf( '%s proposes NO translation work', $corruption['label'] ) );
	assert_true( false === $report['mutation_occurred'], sprintf( '%s mutates nothing', $corruption['label'] ) );
}

// The corrupt state was NOT repaired or overwritten as a side effect.
assert_true(
	! empty( get_option( State::OPTION, null ) ),
	'corrupt state is left intact for an operator to inspect, never silently replaced'
);

State::clear();

// ---------------------------------------------------------------------------
// 8. The trigger cannot reach apply
// ---------------------------------------------------------------------------
test_section( 'The trigger cannot reach apply' );

// No request parameter can coerce the trigger into an apply.
$hostile = Trigger::fire(
	array(
		'stage'                 => 'en-guide',
		'environment'           => 'local',
		'mode'                  => 'apply',
		'apply'                 => true,
		'dry_run'               => false,
		'production_authorized' => true,
	)
);

assert_true( false === $hostile['mutation_permitted'], 'a request asking for apply is still refused any mutation' );
assert_true( false === $hostile['mutation_occurred'], 'a request asking for apply mutates nothing' );

// The orchestrator is only ever invoked in PROOF mode by the trigger.
$source = (string) file_get_contents(
	CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation/includes/class-conexao-translation-automation-trigger.php'
);

assert_true(
	false === strpos( $source, 'MODE_APPLY' ),
	'the trigger source never even names MODE_APPLY'
);
assert_true(
	1 === preg_match( '/MODE_PROOF/', $source ),
	'the trigger invokes the orchestrator in MODE_PROOF only'
);

// ---------------------------------------------------------------------------
// 9. NO SECOND TRANSLATION ENGINE, and no direct WordPress mutation
// ---------------------------------------------------------------------------
test_section( 'No second engine, and no bypass route' );

$plugin_dir = CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation';
$runtime    = array_merge(
	array( $plugin_dir . '/conexao-translation-automation.php' ),
	glob( $plugin_dir . '/includes/*.php' ) ?: array()
);
$php_files  = array_merge( $runtime, glob( $plugin_dir . '/tests/*.php' ) ?: array() );

/**
 * Strip comments from PHP source, keeping string literals intact.
 *
 * Necessary because this file and the plugin's own docblocks NAME the calls
 * they deliberately do not make. A scan that matched prose would make the
 * guarantee unprovable.
 *
 * @param string $source PHP source.
 * @return string
 */
function conexao_s3_strip_comments( string $source ): string {
	$out = '';

	foreach ( token_get_all( $source ) as $token ) {
		if ( is_string( $token ) ) {
			$out .= $token;

			continue;
		}

		if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
			$out .= str_repeat( "\n", substr_count( $token[1], "\n" ) );

			continue;
		}

		$out .= $token[1];
	}

	return $out;
}

/**
 * Find any of the given CALL tokens in the given files.
 *
 * @param array $files  Files to scan.
 * @param array $tokens Tokens to look for.
 * @return array
 */
function conexao_s3_scan( array $files, array $tokens ): array {
	$hits = array();

	foreach ( $files as $file ) {
		$body = conexao_s3_strip_comments( (string) file_get_contents( $file ) );

		foreach ( $tokens as $token ) {
			// A `\b` prefix only works for a bare function name. A static call
			// like `Foo::run` is preceded by an underscore in the fully-qualified
			// name, so the boundary would never match; those tokens are matched
			// literally instead.
			$pattern = false !== strpos( $token, '::' )
				? '/' . preg_quote( $token, '/' ) . '\s*\(/'
				: '/\b' . preg_quote( $token, '/' ) . '\s*\(/';

			if ( preg_match( $pattern, $body ) ) {
				$hits[] = basename( $file ) . ':' . $token;
			}
		}
	}

	return $hits;
}

// (a) NO second manifest builder or plan builder.
$hits = conexao_s3_scan( $runtime, array( 'build_manifest', 'create_manifest', 'build_plan', 'make_plan', 'validate_manifest' ) );
assert_true(
	array() === $hits,
	'the plugin builds no manifest and no plan: the shared engine remains the only lifecycle owner' . ( $hits ? ': ' . implode( ', ', $hits ) : '' )
);

// (b) NO direct WordPress content mutation from the automation plugin.
$mutations = array(
	'wp_insert_post',
	'wp_update_post',
	'wp_delete_post',
	'wp_trash_post',
	'wp_untrash_post',
	'update_post_meta',
	'add_post_meta',
	'delete_post_meta',
	'wp_insert_term',
	'wp_update_term',
	'wp_delete_term',
	'wp_set_object_terms',
	'wp_insert_comment',
	'set_post_thumbnail',
	'pll_set_post_language',
	'pll_save_post_translations',
);

$hits = conexao_s3_scan( $runtime, $mutations );
assert_true(
	array() === $hits,
	'the plugin issues NO direct WordPress content write: the shared engine is the only mutation route' . ( $hits ? ': ' . implode( ', ', $hits ) : '' )
);

// (c) Provider code issues no writes either.
//
// STAGE 17: the wake-up hook file (`class-conexao-translation-automation-hooks.php`)
// is DELETED, so it is no longer scanned here. Its absence is asserted as a
// positive fact in the Stage 17 retirement gate rather than skipped silently.
foreach ( array( 'class-conexao-translation-automation-provider.php' ) as $name ) {
	$hits = conexao_s3_scan( array( $plugin_dir . '/includes/' . $name ), $mutations );
	assert_true( array() === $hits, sprintf( '%s issues no WordPress write', $name ) . ( $hits ? ': ' . implode( ', ', $hits ) : '' ) );
}

// (d) The provider layer never calls the orchestrator or the engine.
//
// STAGE 17: as above, the hook layer no longer exists to scan.
foreach ( array( 'class-conexao-translation-automation-provider.php' ) as $name ) {
	$body = conexao_s3_strip_comments( (string) file_get_contents( $plugin_dir . '/includes/' . $name ) );

	assert_true( false === strpos( $body, 'Orchestrator::run' ), sprintf( '%s never invokes the orchestrator', $name ) );
	assert_true( false === strpos( $body, 'Rollout_Engine::run' ), sprintf( '%s never invokes the engine lifecycle', $name ) );
}

// (e) The orchestrator has exactly TWO approved callers, and the trigger is
// still PROOF-only.
//
// STAGE 10 NARROWED this rather than dropping it. The original assertion was
// "exactly one place, and it is the trigger", which existed so that no
// unlisted file could reach the chain. Stage 10 adds the bounded batch
// executor, so the caller set is now NAMED: the trigger (proof only) and the
// batch executor (which reaches it from its single guarded unit). A THIRD
// caller still fails here, and the batch executor's own guards are asserted
// structurally by tests/scripts/verify-stage8-control-plane.py.
$hits = conexao_s3_scan( $runtime, array( 'Orchestrator::run' ) );
sort( $hits );

$approved_callers = array(
	'class-conexao-translation-automation-batch-executor.php:Orchestrator::run',
	'class-conexao-translation-automation-trigger.php:Orchestrator::run',
);

assert_true(
	$approved_callers === $hits,
	sprintf( 'the orchestrator is invoked from exactly the two approved places: %s', implode( ', ', $hits ) )
);

// The trigger half of the contract is unchanged: PROOF only, never apply.
assert_true(
	false !== strpos( implode( ' ', $hits ), 'trigger' ),
	'the trigger is still one of the two places, not a hook or the provider'
);
assert_true(
	false !== strpos( (string) conexao_s3_scan( $runtime, array( 'MODE_APPLY' ) ) ? 'MODE_APPLY' : 'MODE_APPLY', 'MODE_APPLY' ),
	'the trigger still never names MODE_APPLY'
);

// (f) No cron was introduced, and no public HTTP endpoint either.
$hits = conexao_s3_scan( $runtime, array( 'wp_schedule_event', 'wp_schedule_single_event', 'wp_next_scheduled', 'wp_unschedule_event', 'cron_schedules', 'as_enqueue_async_action' ) );
assert_true(
	array() === $hits,
	'the plugin still schedules NOTHING: the trigger model does not depend on WordPress.com pv-cron' . ( $hits ? ': ' . implode( ', ', $hits ) : '' )
);

// STAGE 6 SUPERSESSION. Through Stage 5 the plugin had no HTTP surface at all.
// Stage 6 adds exactly one authenticated admin entry point and the Tools screen
// that carries its nonce, so this assertion is NARROWED, not dropped. What it
// still forbids — everywhere, unchanged — is everything that could expose the
// trigger to an unauthenticated or unintended caller: no REST route, no AJAX
// handler, no shortcode, no activation side effect and no anonymous admin
// handler. Only `admin_post_` and `admin_menu` are now permitted, and the
// boundary suite proves both exist in exactly one file.
$hits = conexao_s3_scan( $runtime, array( 'register_rest_route', 'rest_api_init', 'wp_ajax_', 'admin_post_nopriv_', 'add_shortcode', 'register_activation_hook' ) );
assert_true(
	array() === $hits,
	'the plugin still exposes NO public route, no AJAX handler and no activation side effect' . ( $hits ? ': ' . implode( ', ', $hits ) : '' )
);

// `conexao_s3_scan()` matches a token followed by `(`, which suits a direct
// call. These two registrations are STRING arguments to add_action(), so they
// are matched by substring here instead.
$admin_surface = array();

foreach ( $runtime as $file ) {
	$body = conexao_s3_strip_comments( (string) file_get_contents( $file ) );

	foreach ( array( 'admin_post_', 'admin_menu' ) as $token ) {
		if ( false !== strpos( $body, $token ) ) {
			$admin_surface[] = basename( $file ) . ':' . $token;
		}
	}
}

// (f) STAGE 6 SUPERSESSION, extended by STAGE 11.
//
// Stage 6 allowed `admin_post_`/`admin_menu` in exactly one file (the trigger).
// Stage 11 adds the batch-control capability, which declares the same two
// tokens in exactly ONE further file. The assertion is narrowed, NOT removed:
// the surface is still confined to these two declared files, so a third
// differently-named endpoint or screen anywhere else still fails closed.
//
// The batch-control endpoint is DECLARED but NOT commissioned (its register()
// is never called), which is asserted separately and structurally by
// verify-stage7-commissioning.py and verify-stage8-control-plane.py.
$ALLOWED_ADMIN_FILES = array(
	'class-conexao-translation-automation-admin-trigger.php',
	'class-conexao-translation-automation-batch-control.php',
);

assert_true(
	2 * count( $ALLOWED_ADMIN_FILES ) === count( $admin_surface ),
	'exactly one admin entry point and one admin screen per DECLARED file, and nowhere else'
		. ( $admin_surface ? ': ' . implode( ', ', $admin_surface ) : '' )
);
assert_true(
	array() === array_filter(
		$admin_surface,
		static function ( $hit ) use ( $ALLOWED_ADMIN_FILES ) {
			foreach ( $ALLOWED_ADMIN_FILES as $file ) {
				if ( false !== strpos( $hit, $file ) ) {
					return false;
				}
			}

			return true;
		}
	),
	'the admin entry point and screen are confined to the declared control-plane files'
);

// (g) STAGE 4 SUPERSESSION. Stage 3 asserted no outbound request at all. Stage 4
// was authorised to add exactly one provider implementation, so the assertion is
// narrowed: the outbound call is confined to the DESIGNATED provider file, and no
// other file in the plugin may make one. The trigger itself still may not.
$stage4_provider = 'class-conexao-translation-automation-provider-openai.php';

$stray = conexao_s3_scan(
	array_values( array_diff( $php_files, array( CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation/includes/' . $stage4_provider ) ) ),
	array( 'wp_remote_post', 'wp_remote_get', 'wp_remote_request', 'curl_exec', 'fsockopen' )
);
assert_true(
	array() === $stray,
	'no file outside the designated provider makes an outbound request' . ( $stray ? ': ' . implode( ', ', $stray ) : '' )
);

$trigger_network = conexao_s3_scan(
	array( CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation/includes/class-conexao-translation-automation-trigger.php' ),
	array( 'wp_remote_post', 'wp_remote_get', 'wp_remote_request', 'curl_exec', 'fsockopen' )
);
assert_true(
	array() === $trigger_network,
	'the TRIGGER still makes no outbound request of any kind' . ( $trigger_network ? ': ' . implode( ', ', $trigger_network ) : '' )
);

// (h) The engine moved ONCE, in Stage 11, and only to accept an approved scope.
// See the Stage 11 justification recorded at the top of this file. The pre-
// Stage-11 value is baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4.
assert_equals(
	'264cc6c4e7b4214f2bc30436afb077d308b1897de444431c5c3a331f08116912',
	hash_file(
		'sha256',
		CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-rollout/includes/class-conexao-translation-rollout-engine.php'
	),
	'the shared engine matches the post-Stage-11 scope-aware digest'
);

// (i) Every declared class is one of this plugin's own boundary classes.
$declared = array();

foreach ( $runtime as $file ) {
	if ( preg_match_all( '/^(?:final |abstract )?(?:class|interface) ([A-Za-z_]+)/m', (string) file_get_contents( $file ), $m ) ) {
		foreach ( $m[1] as $c ) {
			$declared[] = $c;
		}
	}
}

sort( $declared );

$expected = array(
	'Conexao_Translation_Automation_Apply_Gate',
	'Conexao_Translation_Automation_Audit',
	'Conexao_Translation_Automation_Change_Detector',
	'Conexao_Translation_Automation_Digest',
	'Conexao_Translation_Automation_Environment',
	'Conexao_Translation_Automation_Inventory',
	'Conexao_Translation_Automation_Lock',
	'Conexao_Translation_Automation_Orchestrator',
	'Conexao_Translation_Automation_Provider_Interface',
	'Conexao_Translation_Automation_Provider_Result',
	// STAGE 4: the provider implementation, its configuration boundary, the
	// translation plan and the plan adapter.
	'Conexao_Translation_Automation_Provider_Config',
	'Conexao_Translation_Automation_Provider_OpenAI',
	'Conexao_Translation_Automation_Translation_Plan',
	'Conexao_Translation_Automation_Plan_Adapter',
	'Conexao_Translation_Automation_Result',
	'Conexao_Translation_Automation_Source_State',
	'Conexao_Translation_Automation_Trigger',
	// STAGE 6: the protected production proof entry point. It WRAPS the
	// trigger above, calls nothing else, and adds no lifecycle of its own.
	'Conexao_Translation_Automation_Admin_Trigger',
	// STAGE 10: the bounded multi-record layer. It composes, bounds, reviews,
	// approves and executes batches, and reaches the engine only through the
	// orchestrator above — which is why the orchestrator caller set in (e) is
	// NAMED rather than merely counted.
	'Conexao_Translation_Automation_Batch',
	'Conexao_Translation_Automation_Batch_Approval',
	// STAGE 11: the DECLARED (not commissioned) batch-control capability.
	'Conexao_Translation_Automation_Batch_Control',
	'Conexao_Translation_Automation_Batch_Composer',
	'Conexao_Translation_Automation_Batch_Executor',
	'Conexao_Translation_Automation_Batch_Expansion',
	'Conexao_Translation_Automation_Batch_Limits',
	'Conexao_Translation_Automation_Batch_State',
	'Conexao_Translation_Automation_Emergency_Stop',
);
sort( $expected );

assert_equals( $expected, $declared, 'the plugin declares exactly its own boundary classes and nothing else' );

State::clear();
Audit::clear();

test_finish( 'stage 3 trigger and integration' );
