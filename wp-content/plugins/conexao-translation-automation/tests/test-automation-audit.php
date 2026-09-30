<?php
/**
 * Stage 3 audit tests: persistence, required fields, the secret rule, and
 * bounded retention.
 *
 * ## Why this matters
 *
 * Stage 2 returned an audit result and stored nothing. Stage 3 persists it. A
 * persisted audit record is the only evidence an operator will ever have that a
 * run happened, what it saw and why it refused — so its completeness, its
 * refusal to carry secrets, and its BOUND are all safety properties, not
 * housekeeping.
 *
 * @package Conexao_Translation_Automation
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation/conexao-translation-automation.php';

use Conexao_Translation_Automation_Audit as Audit;

test_title( 'conexao-translation-automation — Stage 3 audit persistence' );

Audit::clear();

/**
 * A representative Stage 2 result array.
 *
 * @param array $overrides Keys to replace.
 * @return array
 */
function conexao_s3_result( array $overrides = array() ): array {
	return array_merge(
		array(
			'run_id'             => 'run_s3_audit',
			'mode'               => 'proof',
			'stage'              => 'en-guide',
			'status'             => 'ok',
			'failure'            => '',
			'start_state'        => 'preflight',
			'end_state'          => 'completed',
			'mutation_permitted' => false,
			'mutation_occurred'  => false,
			'environment'        => 'local',
			'lock_status'        => 'acquired',
			'apply_permitted'    => false,
			'approval'           => hash( 'sha256', 'approval' ),
			'dry_run_status'     => 'PASS',
			'digests'            => array(
				'manifest_digest' => hash( 'sha256', 'manifest' ),
				'plan_digest'     => hash( 'sha256', 'plan' ),
				'snapshot_digest' => hash( 'sha256', 'snapshot' ),
				'config_identity' => hash( 'sha256', 'config' ),
			),
			'gate'               => array( 'gate' => 'PASS', 'errors' => 0 ),
		),
		$overrides
	);
}

// ---------------------------------------------------------------------------
// 1. A successful run is persisted with every required field
// ---------------------------------------------------------------------------
test_section( 'A successful run is persisted' );

$record = Audit::build_record(
	array(
		'run_id'          => 'run_s3_ok',
		'trigger'         => Audit::TRIGGER_MANUAL,
		'stage'           => 'en-guide',
		'environment'     => 'local',
		'result'          => conexao_s3_result(),
		'inventory_digest' => hash( 'sha256', 'inventory' ),
		'change_digest'    => hash( 'sha256', 'changes' ),
		'provider_status' => 'not_implemented',
		'started_at'      => gmdate( 'c' ),
	)
);

$trail = Audit::record( $record );

assert_true( ! is_wp_error( $trail ), 'a valid record is persisted' );
assert_equals( 1, count( $trail ), 'the trail holds exactly one record' );

$stored = Audit::latest();

foreach (
	array(
		'run_id',
		'trigger',
		'stage',
		'environment',
		'status',
		'failure',
		'start_state',
		'end_state',
		'source_inventory_digest',
		'change_set_digest',
		'manifest_digest',
		'plan_digest',
		'snapshot_digest',
		'config_identity',
		'approval',
		'lock_status',
		'dry_run_status',
		'provider_status',
		'mutation_permitted',
		'mutation_occurred',
		'recorded_at',
	) as $key
) {
	assert_true( array_key_exists( $key, $stored ), sprintf( 'the persisted record carries "%s"', $key ) );
}

// The values, not just the keys.
assert_equals( 'run_s3_ok', (string) $stored['run_id'], 'the run id round-trips' );
assert_equals( Audit::TRIGGER_MANUAL, (string) $stored['trigger'], 'the trigger kind is recorded' );
assert_equals( 'en-guide', (string) $stored['stage'], 'the stage is recorded' );
assert_equals( 'local', (string) $stored['environment'], 'the environment is recorded' );
assert_equals( hash( 'sha256', 'inventory' ), (string) $stored['source_inventory_digest'], 'the source inventory digest is preserved' );
assert_equals( hash( 'sha256', 'changes' ), (string) $stored['change_set_digest'], 'the change-set digest is preserved' );
assert_equals( hash( 'sha256', 'manifest' ), (string) $stored['manifest_digest'], 'the manifest digest is preserved' );
assert_equals( hash( 'sha256', 'plan' ), (string) $stored['plan_digest'], 'the plan digest is preserved' );
assert_equals( hash( 'sha256', 'snapshot' ), (string) $stored['snapshot_digest'], 'the snapshot digest is preserved' );
assert_equals( 'acquired', (string) $stored['lock_status'], 'the lock status is preserved' );
assert_equals( 'PASS', (string) $stored['dry_run_status'], 'the dry-run verdict is preserved' );
assert_equals( 'PASS', (string) $stored['gate']['gate'], 'the engine gate verdict is preserved' );
assert_true( false === $stored['mutation_permitted'], 'mutation_permitted is preserved as false' );
assert_true( false === $stored['mutation_occurred'], 'mutation_occurred is preserved as false' );
assert_equals( 'not_implemented', (string) $stored['provider_status'], 'the provider status is recorded as not_implemented' );

// ---------------------------------------------------------------------------
// 2. A failed run is persisted just as durably
// ---------------------------------------------------------------------------
test_section( 'A failed run is persisted' );

// NOTE: this suite deliberately avoids `$passed` / `$failed` as variable
// names. `tests/lib/assertions.php` keeps its counters in those globals, and a
// suite-scope `$failed = ...` at file scope silently REPLACES the counter with
// an array, and the next failing assertion then dies with "Cannot increment
// array" instead of reporting itself.
$failed_run = Audit::build_record(
	array(
		'run_id'      => 'run_s3_failed',
		'trigger'     => Audit::TRIGGER_HOOK,
		'stage'       => 'en-page',
		'environment' => 'local',
		'result'      => conexao_s3_result(
			array(
				'run_id'         => 'run_s3_failed',
				'status'         => 'failed',
				'failure'        => 'source_state_unusable',
				'end_state'      => 'failed-closed',
				'gate'           => array(),
				'dry_run_status' => 'not_run',
			)
		),
	)
);

$trail = Audit::record( $failed_run );

assert_true( ! is_wp_error( $trail ), 'a failed run is persisted' );
assert_equals( 2, count( $trail ), 'the trail now holds two records' );

$stored = Audit::latest();
assert_equals( 'run_s3_failed', (string) $stored['run_id'], 'the failed run id is recorded' );
assert_equals( 'failed', (string) $stored['status'], 'the failed run records its status' );
assert_equals( 'source_state_unusable', (string) $stored['failure'], 'the failure category is recorded' );
assert_equals( 'failed-closed', (string) $stored['end_state'], 'the failed run records that it failed closed' );
assert_equals( 'not_run', (string) $stored['dry_run_status'], 'a run that never reached the engine records not_run' );
assert_equals( Audit::TRIGGER_HOOK, (string) $stored['trigger'], 'the trigger kind of the failed run is recorded' );

// Successful and failed runs are retained IDENTICALLY. Pruning a failure to
// make room for a success would hide the interesting case.
assert_true( count( $trail ) === 2, 'both a successful and a failed record are retained together' );
assert_equals( 'ok', (string) $trail[0]['status'], 'the earlier successful record is still present alongside the failure' );

// ---------------------------------------------------------------------------
// 3. Secrets are refused on the WRITE PATH
// ---------------------------------------------------------------------------
test_section( 'Secrets are refused on the persistence path' );

foreach ( array( 'application_password', 'api_key', 'auth_token', 'nonce', 'cookie', 'secret' ) as $key ) {
	$leaky = Audit::build_record(
		array(
			'run_id'  => 'run_s3_leak',
			'trigger' => Audit::TRIGGER_MANUAL,
			'stage'   => 'en-guide',
			'result'  => conexao_s3_result(),
		)
	);

	$leaky[ $key ] = 'harmless-looking-value';

	$outcome = Audit::record( $leaky );

	assert_true(
		is_wp_error( $outcome ),
		sprintf( 'a record carrying the credential-shaped key "%s" is REFUSED', $key )
	);
}

// A credential-SHAPED value is refused even under an innocent key, because the
// application-password shape is unmistakable.
$leaky              = Audit::build_record( array( 'run_id' => 'r', 'stage' => 'en-guide', 'result' => conexao_s3_result() ) );
$leaky['detail']    = 'abcd efgh ijkl mnop';

assert_true( is_wp_error( Audit::record( $leaky ) ), 'a credential-shaped VALUE is refused even under a benign key' );

// Nothing leaked: the trail is unchanged by any of the refusals.
assert_equals( 2, count( Audit::read() ), 'no refused record was persisted' );

// The check is the existing Stage 2 helper, still on the persistence path.
assert_true(
	! is_wp_error( Conexao_Translation_Automation_Result::assert_no_secrets( Audit::build_record( array( 'run_id' => 'r', 'stage' => 'en-guide', 'result' => conexao_s3_result() ) ) ) ),
	'a clean audit record passes the secret assertion'
);

// A storage write failure is surfaced, not swallowed.
Audit::set_writer( static function () { return false; } );
assert_true( is_wp_error( Audit::record( $record ) ), 'a storage write failure is reported as an error' );
Audit::set_writer( null );

// ---------------------------------------------------------------------------
// 4. Corruption is handled safely
// ---------------------------------------------------------------------------
test_section( 'Corrupt audit state is handled safely' );

update_option( Audit::OPTION, 'not a trail', false );

assert_equals( array(), Audit::read(), 'a corrupt trail reads as empty rather than throwing' );
assert_true( ! Audit::read_state()['ok'], 'the corrupt trail is REPORTED as not ok' );
assert_true( '' !== (string) Audit::read_state()['reason'], 'the corruption is explained' );

// A corrupt trail does not stop a new run from recording.
$recovered = Audit::record( $record );
assert_true( ! is_wp_error( $recovered ), 'a run still records after a corrupt trail is found' );
assert_equals( 1, count( $recovered ), 'the recovered trail holds only the new record' );

// Individual malformed entries are dropped, not fatal.
update_option( Audit::OPTION, array( 'not-an-entry', array( 'run_id' => 'run_ok' ) ), false );
$trail = Audit::read();
assert_equals( 1, count( $trail ), 'a malformed entry is dropped rather than crashing the read' );
assert_equals( 'run_ok', (string) $trail[0]['run_id'], 'the valid entry survives' );

// ---------------------------------------------------------------------------
// 5. Retention is bounded
// ---------------------------------------------------------------------------
test_section( 'Retention is bounded' );

assert_true( Audit::RETENTION > 0, 'the retention limit is a positive number' );
assert_true( Audit::RETENTION <= 100, sprintf( 'the retention limit (%d) keeps the option row small', Audit::RETENTION ) );

// Writing far more than the limit keeps exactly the newest N.
for ( $i = 0; $i < Audit::RETENTION + 15; $i++ ) {
	Audit::record(
		Audit::build_record(
			array(
				'run_id'  => 'run_bulk_' . $i,
				'trigger' => Audit::TRIGGER_MANUAL,
				'stage'   => 'en-guide',
				'result'  => conexao_s3_result( array( 'run_id' => 'run_bulk_' . $i ) ),
			)
		)
	);
}

$trail = Audit::read();

assert_equals(
	Audit::RETENTION,
	count( $trail ),
	sprintf( 'the trail is bounded at exactly %d records however many runs occur', Audit::RETENTION )
);

// The OLDEST records are the ones dropped, and the NEWEST survive.
assert_true(
	false === strpos( (string) wp_json_encode( $trail ), '"run_bulk_0"' ),
	'the oldest record is pruned first'
);
assert_equals(
	'run_bulk_' . ( Audit::RETENTION + 14 ),
	(string) $trail[ count( $trail ) - 1 ]['run_id'],
	'the newest record is the one retained'
);

// The bound survives a round-trip through storage.
assert_equals( Audit::RETENTION, count( Audit::read() ), 'the bound holds across a storage round-trip' );

// `prune()` is pure and bounded on its own.
$many = array();
for ( $i = 0; $i < 100; $i++ ) {
	$many[] = array( 'run_id' => 'p' . $i );
}

assert_equals( Audit::RETENTION, count( Audit::prune( $many ) ), 'prune() bounds an oversized trail' );
assert_equals( 3, count( Audit::prune( array( array(), array(), array() ) ) ), 'prune() leaves a trail within the limit untouched' );

// ---------------------------------------------------------------------------
// 6. The trail is inspectable without leaking
// ---------------------------------------------------------------------------
test_section( 'The trail is inspectable' );

$latest = Audit::latest();

assert_true( ! empty( $latest ), 'the latest record is readable' );
assert_true( isset( $latest['run_id'] ), 'the latest record is identifiable by run id' );

// No record in the whole trail carries a credential-shaped key.
$flat = (string) wp_json_encode( Audit::read() );

foreach ( array( 'password', 'api_key', 'auth_token', 'secret' ) as $needle ) {
	assert_true( false === stripos( $flat, $needle ), sprintf( 'no retained record mentions "%s"', $needle ) );
}

Audit::clear();
assert_equals( array(), Audit::read(), 'the trail can be cleared by an operator' );

test_finish( 'stage 3 audit persistence' );
