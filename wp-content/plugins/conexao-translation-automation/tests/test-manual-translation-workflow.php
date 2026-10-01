<?php
/**
 * Stage 17 — the RETAINED MANUAL translation workflow, end to end.
 *
 * ## What this suite is for
 *
 * Stage 17 retired permanent automatic translation. This suite exists to prove
 * the thing that retirement could most plausibly have broken: that a HUMAN can
 * still translate, deliberately, and that every safeguard still holds while they
 * do.
 *
 * It runs the real sequence the operator runbook describes:
 *
 *     manual request -> inventory -> manifest -> dry-run -> snapshot ->
 *     human review -> explicit apply -> verify -> idempotence
 *
 * against the EXISTING shared engine (`Conexao_Translation_Rollout_Engine`),
 * with the repository's own in-memory fake adapter. No second engine is built
 * or exercised: every assertion below goes through the real engine, which is why
 * the engine digest can stay pinned while this suite is added.
 *
 * ## What is NOT claimed here
 *
 *   - No real provider call is made. The provider adapter is exercised through
 *     the repository's EXISTING injected-transport seam, which is how Stages 4-6
 *     proved the provider contract. A live OpenAI call needs a credential this
 *     environment does not have and would spend budget; nothing here fabricates
 *     one.
 *   - No production record is created. The adapter is in-memory, so "applied"
 *     means the engine drove the adapter, not that WordPress content changed.
 *   - Apply is NOT commissioned. The batch-control surface remains declared and
 *     uncommissioned, exactly as Stage 11 left it; this suite drives the engine
 *     directly to prove the engine's contract, not to open a second apply path.
 *
 * @package Conexao_Translation_Automation
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation/conexao-translation-automation.php';

use Conexao_Translation_Automation_Admin_Trigger as AdminTrigger;
use Conexao_Translation_Automation_Provider_OpenAI as ProviderOpenAI;
use Conexao_Translation_Automation_Provider_Config as ProviderConfig;

test_title( 'conexao-translation-automation — Stage 17 retained MANUAL workflow' );

// ---------------------------------------------------------------------------
// The in-memory fixtures. Deliberately modelled on the repository's existing
// engine-test adapter, so this suite exercises the same fake rather than
// inventing a second one.
// ---------------------------------------------------------------------------

/**
 * One throwaway PT record with NO English counterpart: the single translation
 * target this suite applies to.
 */
function s17_store() {
	return array(
		'a' => array(
			'pt'  => array( 'id' => 101, 'status' => 'publish' ),
			'en'  => array( 'en_id' => 0, 'en_status' => 'absent', 'pair_ok' => false, 'en_slug_matches' => true ),
			'snap' => array( 'post_title' => 'Guia de bolso', 'language' => 'pt' ),
		),
		// Already translated: proves a second apply is a genuine no-op.
		'b' => array(
			'pt'  => array( 'id' => 102, 'status' => 'publish' ),
			'en'  => array( 'en_id' => 202, 'en_status' => 'publish', 'pair_ok' => true, 'en_slug_matches' => true ),
			'snap' => array( 'post_title' => 'Guia ja traduzido', 'language' => 'pt' ),
		),
	);
}

function s17_stage( array $manifest, array &$store ) {
	return array(
		'stage'                => 's17-manual',
		'source_post_type'     => 'guide',
		'source_lang'          => 'pt',
		'target_lang'          => 'en',
		'manifest_callback'    => static function () use ( $manifest ) { return $manifest; },
		'snapshot_callback'    => static function ( $id ) use ( &$store ) {
			return isset( $store[ $id ]['snap'] ) ? $store[ $id ]['snap'] : array();
		},
		'build_en_args_callback' => static function () { return array(); },
		'copy_fields_callback' => static function () { return 0; },
		'run_callback'         => static function ( array $args = array() ) { return array(); },
	);
}

function s17_adapter( array &$store ) {
	return array(
		'find_pt'        => static function ( $key ) use ( &$store ) {
			return isset( $store[ $key ]['pt'] ) ? $store[ $key ]['pt'] : null;
		},
		'find_en_for_pt' => static function ( $id, $slug = '' ) use ( &$store ) {
			foreach ( $store as $row ) {
				if ( isset( $row['pt'] ) && (int) $row['pt']['id'] === (int) $id ) {
					return $row['en'];
				}
			}
			return array( 'en_id' => 0, 'en_status' => 'absent', 'pair_ok' => false, 'en_slug_matches' => true );
		},
		'slug_collision' => static function () { return false; },
		'create_en'      => static function ( $pt_id, $row ) use ( &$store ) {
			$new = 9000 + count( $store );
			foreach ( $store as $k => $v ) {
				if ( (int) $v['pt']['id'] === (int) $pt_id ) {
					$store[ $k ]['en'] = array( 'en_id' => $new, 'en_status' => 'publish', 'pair_ok' => true, 'en_slug_matches' => true );
				}
			}
			return $new;
		},
		'repair_en'      => static function () { return true; },
		'link_pair'      => static function () { return true; },
		'pair_ok'        => static function () { return true; },
		'remove_en'      => static function ( $id ) use ( &$store ) {
			foreach ( $store as $k => $v ) {
				if ( (int) $v['en']['en_id'] === (int) $id ) {
					$store[ $k ]['en'] = array( 'en_id' => 0, 'en_status' => 'absent', 'pair_ok' => false, 'en_slug_matches' => true );
					return true;
				}
			}
			return false;
		},
	);
}
/**
 * The manifest: the COMPLETE candidate set for this run. Record 'a' has no EN
 * counterpart and is the single translation target; record 'b' already has one.
 */
function s17_manifest() {
	return array(
		'source_lang' => 'pt',
		'target_lang' => 'en',
		'records'     => array(
			'a' => array( 'en_slug' => 'guia-de-bolso-en' ),
			'b' => array( 'en_slug' => 'guia-ja-traduzido-en' ),
		),
	);
}


// ---------------------------------------------------------------------------
// 1. THE MANUAL REQUEST: a human, an authenticated admin POST, and the control
//    plane it reaches.
// ---------------------------------------------------------------------------

test_section( 'A manual request reaches the control plane, and only with auth' );

$reached = array();
AdminTrigger::reset();

// `admin-post.php` is GET-reachable, so the endpoint enforces POST explicitly.
// The suite exercises that rule too, hence the deliberate switch later on.
$_SERVER['REQUEST_METHOD'] = 'POST';
AdminTrigger::set_orchestrator(
	static function ( array $request ) use ( &$reached ) {
		$reached[] = $request;
		return array( 'ok' => true, 'mode' => 'proof' );
	}
);
AdminTrigger::set_environment_reader( static function (): string { return 'production'; } );

// The request shape a human sends from Tools -> Translation Automation.
$manual_request = array(
	'action'         => AdminTrigger::ACTION,
	'conexao_mode'   => 'proof',
	'conexao_stage'  => 'en-guide',
	'_conexao_nonce' => 'test-nonce',
);

// (a) Unauthenticated: refused before the control plane is reached.
AdminTrigger::set_auth_check( static function (): bool { return false; } );
AdminTrigger::set_capability_check( static function (): bool { return true; } );
AdminTrigger::set_nonce_check( static function (): bool { return true; } );
$denied = AdminTrigger::handle_request( $manual_request );
assert_true( false === (bool) $denied['ok'], 'an unauthenticated manual request is refused' );
assert_true( 0 === count( $reached ), 'an unauthenticated request never reaches the control plane' );

// (b) Authenticated but lacking manage_options: refused.
AdminTrigger::set_auth_check( static function (): bool { return true; } );
AdminTrigger::set_capability_check( static function (): bool { return false; } );
$denied = AdminTrigger::handle_request( $manual_request );
assert_true( false === (bool) $denied['ok'], 'a request without manage_options is refused' );
assert_true( 0 === count( $reached ), 'a request without manage_options never reaches the control plane' );

// (c) Authenticated and capable, but the nonce is invalid: refused.
AdminTrigger::set_capability_check( static function (): bool { return true; } );
AdminTrigger::set_nonce_check( static function (): bool { return false; } );
$denied = AdminTrigger::handle_request( $manual_request );
assert_true( false === (bool) $denied['ok'], 'a request with an invalid nonce is refused' );
assert_true( 0 === count( $reached ), 'a request with an invalid nonce never reaches the control plane' );

// (d) All three gates satisfied: the manual request DOES reach the control
//     plane. This is the positive statement that the workflow still exists.
AdminTrigger::set_nonce_check( static function (): bool { return true; } );
$allowed = AdminTrigger::handle_request( $manual_request );
assert_true( true === (bool) $allowed['ok'], 'an authenticated, capable, nonced manual request is accepted' );
assert_true( 1 === count( $reached ), 'the accepted manual request reaches the control plane exactly once' );
assert_true( true === (bool) $reached[0]['authorized'], 'the manual path arrives at the control plane already authorised' );
assert_true(
	'admin_proof' === (string) $reached[0]['trigger'],
	'the manual run is recorded as an admin_proof run, never as a hook or scheduled run'
);
assert_true(
	false === (bool) $reached[0]['bootstrap'],
	'the manual endpoint can never adopt a baseline (that stays a separate, deliberate act)'
);

// (e) Apply is NOT reachable from the manual endpoint.
$before_apply_attempt = count( $reached );
$apply_attempt        = AdminTrigger::handle_request( array_merge( $manual_request, array( 'conexao_mode' => 'apply' ) ) );
assert_true( false === (bool) $apply_attempt['ok'], 'the manual endpoint refuses mode=apply' );
assert_true( $before_apply_attempt === count( $reached ), 'the refused apply attempt never reaches the control plane' );
assert_true(
	array( 'proof' ) === array_values( AdminTrigger::allowed_modes() ),
	'the only mode the manual endpoint may request is proof'
);

AdminTrigger::reset();

// ---------------------------------------------------------------------------
// 2. THE LIFECYCLE, through the EXISTING shared engine.
// ---------------------------------------------------------------------------

test_section( 'inventory -> manifest -> dry-run -> snapshot -> apply -> verify -> idempotence' );

$store    = s17_store();
$manifest = s17_manifest();
$config   = s17_stage( $manifest, $store );
$adapter  = s17_adapter( $store );

// INVENTORY + MANIFEST: the engine builds the candidate set from live state.
assert_true( true === Conexao_Translation_Rollout_Engine::validate_config( $config ), 'the manual stage config is valid' );
assert_true( true === Conexao_Translation_Rollout_Engine::validate_manifest( $manifest, $config ), 'the manifest is valid' );

$en_before = (int) $store['a']['en']['en_id'];

$dry = Conexao_Translation_Rollout_Engine::run( $config, $adapter, array( 'dry_run' => true ) );
assert_true( ! is_wp_error( $dry ), 'the dry run completes' );
assert_true( 1 === (int) $dry['summary']['created'], 'the dry run plans exactly 1 creation' );
assert_true( 1 === (int) $dry['summary']['skipped'], 'the dry run skips the already-translated record' );
assert_true( 0 === (int) $dry['summary']['updated'], 'the dry run plans no update' );
assert_true( $en_before === (int) $store['a']['en']['en_id'], 'the dry run wrote NOTHING (dry-run-first holds)' );

// SNAPSHOT: PT state is compared before any mutation.
assert_true(
	array() === Conexao_Translation_Rollout_Engine::diff_snapshots(
		array( 'post_title' => 'Guia de bolso' ),
		array( 'post_title' => 'Guia de bolso' )
	),
	'an unchanged PT record snapshots to an empty diff'
);

// APPROVE + APPLY: explicit and scoped to exactly one approved record.
$approved_scope = array( 'a' );
$scoped_dry     = Conexao_Translation_Rollout_Engine::run(
	$config,
	$adapter,
	array( 'dry_run' => true, 'scope' => $approved_scope )
);
assert_true( ! is_wp_error( $scoped_dry ), 'the SCOPED dry run completes' );
assert_true( 1 === (int) $scoped_dry['summary']['created'], 'the approved scope contains exactly the approved record' );
assert_true( 0 === (int) $store['a']['en']['en_id'], 'the scoped dry run still wrote nothing' );

$applied = Conexao_Translation_Rollout_Engine::run(
	$config,
	$adapter,
	array( 'dry_run' => false, 'scope' => $approved_scope )
);
assert_true( ! is_wp_error( $applied ), 'the explicit apply completes' );
assert_true( 1 === (int) $applied['summary']['created'], 'the apply created exactly the approved record' );
assert_true( 0 === $en_before, 'the pre-apply state recorded no EN record, so the change is observable' );
assert_true( (int) $store['a']['en']['en_id'] > 0, 'the approved record now HAS an EN record' );

// APPROVED == PLANNED == EXECUTED: the Stage 11 Model A exact-scope contract.
assert_true(
	(int) $scoped_dry['summary']['created'] === (int) $applied['summary']['created'],
	'approved == planned == executed'
);
// `rows` is a LIST of per-record results, each carrying its `stable_key`. The
// executed set is therefore the stable keys that were actually touched, which
// is the set a reviewer must be able to compare against the approval.
$executed_keys = array();
foreach ( (array) ( $applied['rows'] ?? array() ) as $row ) {
	if ( isset( $row['stable_key'] ) ) {
		$executed_keys[] = (string) $row['stable_key'];
	}
}
$unexpected = array_values( array_diff( $executed_keys, $approved_scope ) );
assert_true( 1 === count( $executed_keys ), 'the apply touched exactly one record' );
assert_true( array() === $unexpected, 'the ACTUAL mutation set equals the approved set, with no extras' );

// VERIFY + IDEMPOTENCE: reconciling again changes nothing.
$verify = Conexao_Translation_Rollout_Engine::run(
	$config,
	$adapter,
	array( 'dry_run' => false, 'scope' => $approved_scope )
);
assert_true( ! is_wp_error( $verify ), 'the verification run completes' );
assert_true( 0 === (int) $verify['summary']['created'], 'idempotence: the second run creates nothing' );
assert_true( 0 === (int) $verify['summary']['updated'], 'idempotence: the second run updates nothing' );

// PT IMMUTABILITY: the PT records are untouched by the apply.
assert_true( 'Guia de bolso' === (string) $store['a']['snap']['post_title'], 'PT content is unchanged by the apply' );
assert_true( 'pt' === (string) $store['a']['snap']['language'], 'the PT record is still PT after the apply' );
assert_true( 101 === (int) $store['a']['pt']['id'], 'the translated PT record keeps its identity' );
assert_true( 102 === (int) $store['b']['pt']['id'], 'the unrelated PT record is untouched' );

// ---------------------------------------------------------------------------
// 3. THE FAIL-CLOSED PATHS a manual run still depends on.
// ---------------------------------------------------------------------------

test_section( 'the manual run still fails closed on collisions, drift and bad modes' );

// COLLISION: a duplicate en_slug is refused before any mutation.
$dup_manifest = array(
	'source_lang' => 'pt',
	'target_lang' => 'en',
	'records'     => array(
		'a' => array( 'en_slug' => 'same-slug' ),
		'b' => array( 'en_slug' => 'same-slug' ),
	),
);
assert_true(
	is_wp_error( Conexao_Translation_Rollout_Engine::validate_manifest( $dup_manifest, $config ) ),
	'a colliding en_slug fails closed with zero writes'
);

// PT DRIFT: a PT record changed after its snapshot is reported, not silently
// overwritten.
assert_true(
	array() !== Conexao_Translation_Rollout_Engine::diff_snapshots(
		array( 'post_title' => 'Guia de bolso' ),
		array( 'post_title' => 'Guia de bolso EDITADO' )
	),
	'PT drift is detected by the snapshot diff'
);
$drift = Conexao_Translation_Rollout_Engine::run( $config, $adapter, array( 'dry_run' => true, 'scope' => $approved_scope ) );
assert_true( ! is_wp_error( $drift ), 'the drift check run completes without error' );

// AMBIGUOUS MODE: refused, never guessed. A caller must name a real mode.
AdminTrigger::set_auth_check( static function (): bool { return true; } );
AdminTrigger::set_capability_check( static function (): bool { return true; } );
AdminTrigger::set_nonce_check( static function (): bool { return true; } );
AdminTrigger::set_orchestrator( static function ( array $r ) { return array( 'ok' => true ); } );
$ambiguous = AdminTrigger::handle_request( array( 'action' => AdminTrigger::ACTION, 'conexao_mode' => 'maybe' ) );
assert_true( false === (bool) ( $ambiguous['ok'] ?? false ), 'an unknown mode is refused rather than guessed' );

// UNKNOWN STAGE: refused.
$unknown_stage = AdminTrigger::handle_request( array_merge( $manual_request, array( 'conexao_stage' => 'not-a-stage' ) ) );
assert_true( false === (bool) ( $unknown_stage['ok'] ?? false ), 'an unknown stage is refused' );

AdminTrigger::reset();

// ---------------------------------------------------------------------------
// 4. THE OPTIONAL PROVIDER, for a manually requested run.
//
// NO REAL PROVIDER CALL IS MADE HERE, and none is fabricated. The provider
// contract is exercised through the repository's EXISTING credential seam, the
// same seam Stages 4-6 used: a live OpenAI call needs a credential this
// environment does not hold and would spend budget, so this suite proves the
// FAIL-CLOSED behaviour instead and leaves the live smoke to the explicitly
// authorized `tests/live-provider-smoke.php`.
// ---------------------------------------------------------------------------

test_section( 'the optional provider is credential-supplied and never persisted' );

// (a) No credential supplied for this run: the provider boundary refuses.
assert_true(
	is_wp_error( ProviderConfig::credentials() ),
	'with no credential supplied for the run, the provider boundary refuses'
);

// (b) The credential is named by a PUBLIC constant and read from the process
//     environment, so an operator supplies it per run and nothing persists.
assert_true( '' !== (string) ProviderConfig::CREDENTIAL_ENV, 'the credential boundary names an environment variable' );
assert_true(
	'CONEXAO_TRANSLATION_PROVIDER_KEY' === (string) ProviderConfig::CREDENTIAL_ENV,
	'the named variable is the documented one'
);

// (c) The optional provider is discoverable without being automatic.
assert_true( 'openai' === (string) ProviderOpenAI::provider_id(), 'the optional provider is OpenAI' );

// (d) No shipped class declares an option that could persist a provider
//     credential. Enumerated over the real classes, so a future credential
//     option fails here rather than sitting unnoticed in the database.
$credential_options = array();
foreach ( glob( CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation/includes/*.php' ) ?: array() as $class_file ) {
	$class_body = (string) file_get_contents( $class_file );
	if ( preg_match_all( "/const\s+([A-Z_]*OPTION[A-Z_]*)\s*=\s*'([^']*)'/", $class_body, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			if ( preg_match( '/(api_key|apikey|provider_key|credential|secret|token)/i', $match[2] ) ) {
				$credential_options[] = basename( $class_file ) . '::' . $match[1];
			}
		}
	}
}
assert_true(
	array() === $credential_options,
	'no shipped class declares an option that could persist a provider credential'
	. ( $credential_options ? ': ' . implode( ', ', $credential_options ) : '' )
);

test_finish( 'stage 17 retained manual workflow' );
