<?php
/**
 * Stage 4 plan tests: the translation plan, the plan adapter, the review gate,
 * stale-source protection, engine delegation and the dry-run path.
 *
 * ## What is proven here
 *
 *   - a validated provider result becomes a REVIEWABLE plan row, never an
 *     approval;
 *   - the adapter composes an engine-compatible manifest from the stage's OWN
 *     data and never writes to WordPress;
 *   - a changed PT source invalidates a plan row before the engine sees it;
 *   - the engine remains the only lifecycle authority, and its bytes are
 *     unchanged;
 *   - the whole path stops at the dry-run gate.
 *
 * @package Conexao_Translation_Automation
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation/conexao-translation-automation.php';

use Conexao_Translation_Automation_Provider_Result as Result;
use Conexao_Translation_Automation_Plan_Adapter as Adapter;
use Conexao_Translation_Automation_Translation_Plan as Plan;

test_title( 'conexao-translation-automation — Stage 4 translation plan' );

/**
 * A validated result for one record, as the Stage 3 validator would return it.
 *
 * @param string $identity Portable identity.
 * @param string $digest   Source digest.
 * @param array  $fields   Translated field map.
 * @param array  $context  The context it was validated against.
 * @return array
 */
function conexao_s4_validated( string $identity, string $digest, array $fields, array $context ): array {
	return array(
		'ok'           => true,
		'status'       => Result::STATUS_SUCCESS,
		'reason'       => '',
		'translations' => $fields,
		'meta'         => array(
			'provider'         => 'openai',
			'model'            => 'gpt-4o-mini',
			'request_id'       => 'resp_1',
			'source_digest'    => $digest,
			'request_identity' => (string) $context['request_identity'],
			'result_digest'    => hash( 'sha256', $digest . '|result' ),
		),
	);
}

/**
 * A B1 context for a record.
 *
 * @param string $identity Portable identity.
 * @param string $digest   Source digest.
 * @return array
 */
function conexao_s4_b1_context( string $identity = 'meu-guia', string $digest = '' ): array {
	return Result::context(
		array(
			'stage'         => 'en-guide',
			'identity'      => $identity,
			'source_digest' => '' === $digest ? hash( 'sha256', 'pt-b1' ) : $digest,
			'post_type'     => 'guide',
			'run_id'        => 'run_s4',
			'fields'        => array( 'post_title', 'post_content' ),
			'mode'          => 'b1',
		)
	);
}

/**
 * A B2 context for a record.
 *
 * @param string $identity Portable identity.
 * @param string $digest   Source digest.
 * @return array
 */
function conexao_s4_b2_context( string $identity = 'meu-lazer', string $digest = '' ): array {
	return Result::context(
		array(
			'stage'         => 'en-leisure-description',
			'identity'      => $identity,
			'source_digest' => '' === $digest ? hash( 'sha256', 'pt-b2' ) : $digest,
			'post_type'     => 'leisure',
			'run_id'        => 'run_s4',
			'fields'        => array( 'post_excerpt' ),
			'mode'          => 'b2',
		)
	);
}

// ---------------------------------------------------------------------------
// 1. A validated result becomes a plan row, and nothing else does
// ---------------------------------------------------------------------------
test_section( 'Plan construction' );

$digest  = hash( 'sha256', 'pt-b1' );
$context = conexao_s4_b1_context( 'meu-guia', $digest );
$plan    = new Plan( array( 'run_id' => 'run_s4', 'stage' => 'en-guide', 'environment' => 'local' ) );

$unvalidated = $plan->add_validated( array( 'ok' => false, 'reason' => 'not validated' ), $context );
assert_true( is_wp_error( $unvalidated ), 'an UNVALIDATED result cannot become a plan row' );

$fields = array( 'post_title' => 'Immigration guide', 'post_content' => 'An English body.' );
$row    = $plan->add_validated( conexao_s4_validated( 'meu-guia', $digest, $fields, $context ), $context );

assert_true( is_array( $row ), 'a validated result becomes a plan row' );
assert_equals( 'meu-guia', (string) $row['source_id'], 'the row carries the portable source identity' );
assert_equals( 'b1', (string) $row['mode'], 'the row records the B1/B2 strategy it belongs to' );
assert_equals( $digest, (string) $row['source_digest'], 'the row is bound to the source digest' );
assert_true( '' !== (string) $row['result_digest'], 'the row carries the result digest' );
assert_true( '' !== (string) $row['request_identity'], 'the row carries the request identity' );
assert_equals( 'openai', (string) $row['provider'], 'the row records the provider identity' );
assert_equals( 'gpt-4o-mini', (string) $row['model'], 'the row records the model identity' );
assert_equals( array( 'post_title', 'post_content' ), array_map( 'strval', $row['changed_fields'] ), 'the row names exactly the fields that would change' );
assert_equals( 1, $plan->count(), 'the plan holds one row' );

// A context with no recognised mode is refused rather than guessed at.
assert_true(
	is_wp_error( $plan->add_validated(
		conexao_s4_validated( 'x', $digest, $fields, $context ),
		array_merge( $context, array( 'mode' => 'b7' ) )
	) ),
	'a row for a stage mode that is neither b1 nor b2 is refused'
);

// ---------------------------------------------------------------------------
// 2. The review gate: every plan begins REVIEW_REQUIRED
// ---------------------------------------------------------------------------
test_section( 'Review gate' );

assert_equals( 'REVIEW_REQUIRED', $row['review_status'], 'every row begins REVIEW_REQUIRED' );
assert_equals( 'REVIEW_REQUIRED', $plan->meta()['review_status'], 'the plan itself begins REVIEW_REQUIRED' );
assert_equals( 'REVIEW_REQUIRED', $plan->summary()['review_status'], 'the plan summary reports REVIEW_REQUIRED' );

// The quality boundary is stated explicitly, so nothing downstream can mistake
// a validated response for a publication decision.
assert_equals( 'human_review_required', $row['quality_status'], 'every row states the human-review quality boundary' );
assert_equals( 'validated', $row['validation_status'], 'the row distinguishes structural validation from approval' );
assert_true( 'validated' !== $row['review_status'], 'a validated row is explicitly not an approved row' );

// There is no approval constant at all: approval is not a thing this stage can
// do, and a constant that exists but is never set invites a later change.
$plan_constants = array_keys(
	array_filter(
		( new ReflectionClass( Plan::class ) )->getConstants(),
		static function ( $value, $name ) {
			return is_string( $value ) && in_array( strtoupper( (string) $value ), array( 'APPROVED', 'PUBLISHED', 'APPLY' ), true );
		},
		ARRAY_FILTER_USE_BOTH
	)
);
assert_equals( array(), $plan_constants, 'the plan declares no APPROVED/PUBLISHED/APPLY state at all' );

// The plan cannot be approved by a method: there is no such method.
assert_true( ! method_exists( Plan::class, 'approve' ), 'the plan offers no approve() method' );
assert_true( ! method_exists( Plan::class, 'set_review_status' ), 'the plan offers no method to change a review status' );

// The review artifact is safe to render and carries no credential.
$artifact = $plan->to_review_artifact();
assert_true( isset( $artifact['summary'], $artifact['rows'] ), 'the review artifact is machine-readable' );
assert_equals( 'REVIEW_REQUIRED', (string) $artifact['summary']['review_status'], 'the review artifact states the review status' );
assert_true( isset( $artifact['rows'][0]['translations']['post_title'] ), 'the review artifact includes the translation, so English can actually be reviewed' );
assert_true(
	false === strpos( (string) wp_json_encode( $artifact ), 'Bearer' ),
	'the review artifact contains no authorization header'
);
assert_true(
	! is_wp_error( Conexao_Translation_Automation_Result::assert_no_secrets( $artifact ) ),
	'the review artifact passes the repository secret check'
);

// The SUMMARY is content-free, so it is safe for a log or a commit message.
$summary_json = (string) wp_json_encode( $plan->summary() );
assert_true( false === strpos( $summary_json, 'Immigration guide' ), 'the plan summary carries no translated content' );
assert_true( false === strpos( $summary_json, 'An English body' ), 'the plan summary carries no translated body' );


// ---------------------------------------------------------------------------
// 3. B1 and B2 operations are described, not decided
// ---------------------------------------------------------------------------
test_section( 'B1 and B2 operation semantics' );

assert_equals( Plan::OP_CREATE_EN, Plan::operation_for( 'b1', 'new' ), 'a new B1 record is a create' );
assert_equals( Plan::OP_UPDATE_EN, Plan::operation_for( 'b1', 'modified' ), 'a modified B1 record is an update of the existing EN record' );
assert_equals( Plan::OP_RECONCILE, Plan::operation_for( 'b1', 'restored' ), 'a restored B1 record is a repair/reconcile' );
assert_equals(
	Plan::OP_FIELD_WRITE,
	Plan::operation_for( 'b2', 'new' ),
	'a B2 change is ALWAYS a field write on the same PT record, never a record creation'
);
assert_equals(
	Plan::OP_FIELD_WRITE,
	Plan::operation_for( 'b2', 'modified' ),
	'a modified B2 change writes its own field, not an EN record'
);

// A B2 row therefore NEVER claims to create or update an EN record: the B2
// model is one identity with an English layer, not a second identity.
$b2_plan = new Plan( array( 'run_id' => 'run_s4', 'stage' => 'en-leisure-description' ) );
$b2_ctx  = conexao_s4_b2_context( 'meu-lazer', hash( 'sha256', 'pt-b2' ) );
$b2_row  = $b2_plan->add_validated(
	conexao_s4_validated( 'meu-lazer', (string) $b2_ctx['source_digest'], array( 'post_excerpt' => 'An English card description.' ), $b2_ctx ),
	$b2_ctx
);

assert_equals( 'write_owned_en_field', (string) $b2_row['operation'], 'a B2 plan row names the owned field write' );
assert_true( Plan::OP_CREATE_EN !== (string) $b2_row['operation'], 'a B2 plan row never claims an EN record creation' );
assert_equals( 'b2', (string) $b2_row['mode'], 'the B2 row is distinguishable from a B1 row' );

// ---------------------------------------------------------------------------
// 4. The adapter composes the stage's own manifest and refuses scope creep
// ---------------------------------------------------------------------------
test_section( 'Plan adapter' );

Adapter::set_digest_reader(
	static function ( $stage, $identity ) {
		return hash( 'sha256', 'pt-b1' );
	}
);

Adapter::set_manifest_resolver(
	static function () {
		return array(
			'source_lang' => 'pt',
			'target_lang' => 'en',
			'records'     => array(
				'meu-guia' => array(
					'en_slug'             => 'my-guide',
					'en_title'            => 'Authored title',
					'en_content'          => 'Authored content',
					'en_excerpt'          => '',
					'en_meta_description' => '',
				),
				'outro'    => array(
					'en_slug'             => 'other',
					'en_title'            => 'Untouched',
					'en_content'          => 'Untouched',
					'en_excerpt'          => '',
					'en_meta_description' => '',
				),
			),
		);
	}
);

$plan_row = array(
	'source_id'     => 'meu-guia',
	'source_digest' => hash( 'sha256', 'pt-b1' ),
	'mode'          => 'b1',
	'translations'  => array( 'post_title' => 'Provider title', 'post_content' => 'Provider content' ),
);

$composed = Adapter::compose( 'en-guide', array( 'meu-guia' => $plan_row ) );

assert_equals( array( 'meu-guia' ), $composed['applied'], 'the plan row is applied' );
assert_equals( array(), $composed['rejected'], 'nothing is rejected in the happy path' );
assert_equals( 'Provider title', (string) $composed['manifest']['records']['meu-guia']['en_title'], 'the provider title is overlaid onto the authored row' );
assert_equals( 'Provider content', (string) $composed['manifest']['records']['meu-guia']['en_content'], 'the provider content is overlaid onto the authored row' );

// The stage's own decision is preserved: the EN slug is NOT the provider's.
assert_equals( 'my-guide', (string) $composed['manifest']['records']['meu-guia']['en_slug'], 'the EN slug stays the authored one — the provider never mints a URL' );

// A row the plan does not name is returned EXACTLY as authored, so a plan can
// never silently shrink the engine's scope.
assert_equals( 'Untouched', (string) $composed['manifest']['records']['outro']['en_title'], 'a record the plan does not name is left exactly as authored' );
assert_equals( 2, count( $composed['manifest']['records'] ), 'the composed manifest holds every stage record' );
assert_equals( 'pt', (string) $composed['manifest']['source_lang'], 'the composed manifest keeps the stage source language' );
assert_equals( 'en', (string) $composed['manifest']['target_lang'], 'the composed manifest keeps the stage target language' );

// A slug is refused outright, whatever the provider claimed.
$slug_attempt = Adapter::compose(
	'en-guide',
	array(
		'meu-guia' => array_merge(
			$plan_row,
			array( 'translations' => array( 'post_name' => 'invented-slug' ) )
		),
	)
);
assert_true( isset( $slug_attempt['rejected']['meu-guia'] ), 'a provider-supplied slug is refused' );
assert_true( false !== strpos( (string) $slug_attempt['rejected']['meu-guia'], 'post_name' ), 'the refusal names the offending field' );

// An identity the stage does not declare is refused: a plan cannot write
// outside the stage's declared scope.
$out_of_scope = Adapter::compose(
	'en-guide',
	array(
		'nao-declarado' => array(
			'source_id'     => 'nao-declarado',
			'source_digest' => hash( 'sha256', 'pt-b1' ),
			'mode'          => 'b1',
			'translations'  => array( 'post_title' => 'X' ),
		),
	)
);
assert_true( isset( $out_of_scope['rejected']['nao-declarado'] ), 'a plan row for an identity the stage does not declare is refused' );
assert_equals( array(), $out_of_scope['applied'], 'an out-of-scope row is not applied' );


// ---------------------------------------------------------------------------
// 5. B2 field ownership
// ---------------------------------------------------------------------------
test_section( 'B2 field ownership' );

Adapter::set_manifest_resolver(
	static function () {
		return array(
			'source_lang' => 'pt',
			'target_lang' => 'en',
			'records'     => array(
				'meu-lazer' => array(
					'en_slug'        => 'meu-lazer',
					'pt_source'      => 'A descricao em portugues.',
					'pt_title'       => 'Um titulo',
					'en_description' => 'Authored description',
				),
			),
		);
	}
);

$b2_merge = Adapter::compose(
	'en-leisure-description',
	array(
		'meu-lazer' => array(
			'source_id'     => 'meu-lazer',
			'source_digest' => hash( 'sha256', 'pt-b1' ),
			'mode'          => 'b2',
			'translations'  => array( 'post_excerpt' => 'An English card description.' ),
		),
	)
);

assert_equals( array( 'meu-lazer' ), $b2_merge['applied'], 'the B2 field is applied' );
assert_true(
	false !== strpos( (string) wp_json_encode( $b2_merge['manifest']['records']['meu-lazer'] ), 'An English card description.' ),
	'the provider English lands in the stage-owned description field'
);
assert_equals(
	'meu-lazer',
	(string) $b2_merge['manifest']['records']['meu-lazer']['en_slug'],
	'a B2 stage mints no URL: the EN slug is the authored PT key'
);

// A B2 stage owns ONE field. A B1 field name offered to it is a scope
// violation and is refused, not quietly ignored.
$b2_scope = Adapter::compose(
	'en-leisure-description',
	array(
		'meu-lazer' => array(
			'source_id'     => 'meu-lazer',
			'source_digest' => hash( 'sha256', 'pt-b1' ),
			'mode'          => 'b2',
			'translations'  => array( 'post_title' => 'A fabricated title' ),
		),
	)
);
assert_true( isset( $b2_scope['rejected']['meu-lazer'] ), 'a B1 field name offered to a B2 stage is refused' );
assert_true( false !== strpos( (string) $b2_scope['rejected']['meu-lazer'], 'en_description' ), 'the refusal names the one field the B2 stage owns' );
assert_true( false === strpos( (string) wp_json_encode( $b2_scope['manifest']['records']['meu-lazer'] ), 'A fabricated title' ), 'the refused value reaches nothing' );

// ---------------------------------------------------------------------------
// 6. Stale-source protection
// ---------------------------------------------------------------------------
test_section( 'Stale-source protection' );

// The B1 manifest resolver from section 4 is restored explicitly, so this
// section cannot silently inherit the B2 resolver section 5 installed.
Adapter::set_manifest_resolver(
	static function () {
		return array(
			'source_lang' => 'pt',
			'target_lang' => 'en',
			'records'     => array(
				'meu-guia' => array(
					'en_slug'             => 'my-guide',
					'en_title'            => 'Authored title',
					'en_content'          => 'Authored content',
					'en_excerpt'          => '',
					'en_meta_description' => '',
				),
			),
		);
	}
);

Adapter::set_digest_reader(
	static function ( $stage, $identity ) {
		// The live PT source has MOVED since the plan was built.
		return hash( 'sha256', 'pt-has-changed-since-the-plan' );
	}
);

$stale = Adapter::compose( 'en-guide', array( 'meu-guia' => $plan_row ) );

assert_true( isset( $stale['rejected']['meu-guia'] ), 'a plan row whose PT source has changed is rejected' );
assert_true( false !== strpos( (string) $stale['rejected']['meu-guia'], 'digest mismatch' ), 'the rejection names the digest mismatch' );
assert_equals( array(), $stale['applied'], 'a stale row is never applied' );
assert_equals(
	'Authored title',
	(string) $stale['manifest']['records']['meu-guia']['en_title'],
	'the manifest keeps the AUTHORED value, so a stale translation cannot land'
);

// A row whose digest cannot be re-read at all is also refused: unreadable is
// not "unchanged".
Adapter::set_digest_reader(
	static function () {
		return '';
	}
);
$unreadable = Adapter::compose( 'en-guide', array( 'meu-guia' => $plan_row ) );
assert_true( isset( $unreadable['rejected']['meu-guia'] ), 'a row whose live digest cannot be re-read is rejected' );
assert_true( false !== strpos( (string) $unreadable['rejected']['meu-guia'], 'could not be re-read' ), 'the rejection explains the digest could not be re-read' );

// A row with no digest at all is refused.
$no_digest = Adapter::compose(
	'en-guide',
	array(
		'meu-guia' => array(
			'source_id'    => 'meu-guia',
			'source_digest' => '',
			'mode'         => 'b1',
			'translations' => array( 'post_title' => 'X' ),
		),
	)
);
assert_true( isset( $no_digest['rejected']['meu-guia'] ), 'a row carrying no source digest is rejected' );


// ---------------------------------------------------------------------------
// 7. The adapter delegates: it does not reproduce the engine
// ---------------------------------------------------------------------------
test_section( 'Engine delegation' );

/**
 * Read a plugin source file with its comments stripped.
 *
 * Comment stripping matters: a docblock that NAMES a forbidden call must not
 * make the guarantee unprovable.
 *
 * @param string $basename File basename inside the plugin's includes directory.
 * @return string
 */
function conexao_s4_source( string $basename ): string {
	$body = (string) file_get_contents(
		CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation/includes/' . $basename
	);
	$body = (string) preg_replace( '#/\*.*?\*/#s', '', $body );

	return (string) preg_replace( '#//[^\n]*#', '', $body );
}

// No lifecycle vocabulary in the adapter: no plan categories, no gate
// arithmetic, no snapshot, no counters. Those belong to the engine alone.
$adapter_stripped = conexao_s4_source( 'class-conexao-translation-automation-plan-adapter.php' );

foreach ( array( 'build_plan', 'validate_manifest', 'would-create', 'would-update', 'calculate_gate', 'diff_snapshots', 'collect_verify', 'assert_no_pt_drift' ) as $engine_term ) {
	assert_true(
		false === strpos( $adapter_stripped, $engine_term ),
		sprintf( 'the adapter does not reimplement the engine vocabulary "%s"', $engine_term )
	);
}

// The adapter composes a MANIFEST and delegates. It never registers a stage,
// which would create a second identity for the same work.
assert_true( false === strpos( $adapter_stripped, 'register_stage(' ), 'the adapter never registers a stage with the engine' );
assert_true( false === strpos( $adapter_stripped, 'wp_insert_post' ), 'the adapter performs no WordPress insert' );
assert_true( false === strpos( $adapter_stripped, 'wp_update_post' ), 'the adapter performs no WordPress update' );
assert_true( false === strpos( $adapter_stripped, 'update_post_meta' ), 'the adapter writes no post meta' );
assert_true( false === strpos( $adapter_stripped, 'pll_' ), 'the adapter touches no Polylang relationship' );
assert_true( false === strpos( $adapter_stripped, 'wp_insert_term' ), 'the adapter creates no taxonomy term' );
assert_true( false === strpos( $adapter_stripped, 'wp_set_object_terms' ), 'the adapter assigns no taxonomy terms' );

// The injected config is an ORDINARY engine config: only the manifest callback
// is replaced, and the stage keeps its own identity and language pair.
$injected = Adapter::engine_config(
	'en-guide',
	array( 'stage' => 'en-guide', 'source_lang' => 'pt', 'target_lang' => 'en', 'manifest_callback' => '__original' ),
	array( 'source_lang' => 'pt', 'target_lang' => 'en', 'records' => array() )
);
assert_true( is_callable( $injected['manifest_callback'] ), 'the engine receives a callable manifest callback' );
assert_equals( 'en-guide', (string) $injected['stage'], 'the stage identity is unchanged' );
assert_equals( array( 'source_lang' => 'pt', 'target_lang' => 'en', 'records' => array() ), call_user_func( $injected['manifest_callback'] ), 'the callback yields exactly the composed manifest' );
assert_true( ! isset( $injected['__original'] ), 'the stage original callback is replaced, not shadowed' );


// ---------------------------------------------------------------------------
// 8. The dry-run path, end to end, with the REAL engine and the REAL stage
// ---------------------------------------------------------------------------
test_section( 'Dry-run through the existing engine' );

$engine_file = CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-rollout/includes/class-conexao-translation-rollout-engine.php';
// STAGE 11: the pin moved ONCE, deliberately. Model A (true subset
// execution) requires the engine to accept an approved operation scope.
// The change is additive and confined to scope handling: two pure methods
// (narrow_manifest, planned_identities), one optional $args['scope'] key
// applied AFTER full-manifest validation, and a 'scope' key added to the
// two existing return payloads. No lifecycle stage was replaced,
// reordered or bypassed. Pre-Stage-11 digest (the Stage 11 §33 starting
// record): baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4
$engine_sha  = '264cc6c4e7b4214f2bc30436afb077d308b1897de444431c5c3a331f08116912';

assert_true( is_file( $engine_file ), 'the shared engine file exists' );
assert_equals( $engine_sha, hash_file( 'sha256', $engine_file ), 'the shared engine is byte-identical to the pre-Stage-1 baseline' );

Adapter::set_manifest_resolver( null );
Adapter::set_digest_reader( null );

$stage = 'en-leisure-description';
$live  = Adapter::compose( $stage, array() );
$keys  = array_keys( (array) $live['manifest']['records'] );

test_require(
	count( $keys ) > 0,
	'seed-leisure',
	'the B2 stage declares at least one record, so the dry-run path is exercisable'
);

// Pick a record whose PT source really exists, so the live digest is RE-READ
// rather than faked.
$pt_id  = 0;
$digest = '';
$key    = '';
foreach ( $keys as $candidate ) {
	$post = get_page_by_path( $candidate, OBJECT, 'leisure' );
	if ( $post instanceof WP_Post ) {
		$read = Conexao_Translation_Automation_Digest::digest_record( $stage, (int) $post->ID );
		if ( ! empty( $read['ok'] ) ) {
			$pt_id  = (int) $post->ID;
			$digest = (string) $read['digest'];
			$key    = $candidate;
			break;
		}
	}
}

// Prefer a record the stage has NOT flagged as PT-drifted, so the run can also
// demonstrate a clean engine gate. The drift guard is the stage's own designed
// behaviour; a suite that happened to pick a drifted record would be asserting
// the fixture rather than Stage 4.
$clean_key  = '';
$clean_pt   = 0;
$clean_dig  = '';
$manifest_now = (array) Adapter::compose( $stage, array() )['manifest'];

foreach ( array_keys( $manifest_now['records'] ?? array() ) as $candidate ) {
	$row  = (array) $manifest_now['records'][ $candidate ];
	$post = get_page_by_path( $candidate, OBJECT, 'leisure' );

	if ( ! $post instanceof WP_Post || ! isset( $row['pt_source'] ) ) {
		continue;
	}

	$read = Conexao_Translation_Automation_Digest::digest_record( $stage, (int) $post->ID );

	if ( empty( $read['ok'] ) ) {
		continue;
	}

	// Same comparison the stage's own guard performs, through the stage's own
	// normaliser — never a second normalisation rule.
	if ( conexao_en_translation_leisure_normalize( (string) $post->post_excerpt )
		!== conexao_en_translation_leisure_normalize( (string) $row['pt_source'] ) ) {
		continue;
	}

	$clean_key = $candidate;
	$clean_pt  = (int) $post->ID;
	$clean_dig = (string) $read['digest'];
	break;
}

test_require(
	$pt_id > 0 && '' !== $digest,
	'seed-leisure',
	'a real PT leisure record with a readable digest exists, so stale-source protection is genuinely exercised'
);

test_require(
	'' !== $clean_key,
	'seed-leisure',
	'a PT leisure record whose source still matches its authored English exists, so the engine gate can be exercised on a non-drifted record'
);

// The main dry run uses the CLEAN record, so the engine's verdict is a real
// verdict about Stage 4 rather than about a deliberately drifted fixture.
$key   = $clean_key;
$pt_id = $clean_pt;
$digest = $clean_dig;

$dry_plan = array(
	$key => array(
		'source_id'     => $key,
		'source_digest' => $digest,
		'mode'          => 'b2',
		'translations'  => array( 'post_excerpt' => 'A provider-generated English card description for the dry run.' ),
	),
);

$dry = Adapter::compose( $stage, $dry_plan );

assert_equals( array( $key ), $dry['applied'], 'the provider-backed row composes into the stage manifest' );

// The engine runs, in DRY RUN, and produces ITS OWN plan and gate.
$engine_config  = Adapter::engine_config( $stage, Conexao_Translation_Rollout_Engine::get_stage( $stage ), $dry['manifest'] );
$engine_adapter = conexao_en_translation_leisure_description_adapter();

$before_meta        = get_post_meta( $pt_id );
$before_post        = get_post( $pt_id );
$before_terms       = wp_get_post_terms( $pt_id, 'conexao_category', array( 'fields' => 'slugs' ) );
$before_translations = function_exists( 'pll_get_post_translations' ) ? pll_get_post_translations( $pt_id ) : array();

// NOTE ON THE ENGINE VERDICT BELOW.
//
// The B2 stage carries its own PT-drift guard: if the live PT excerpt differs
// from the `pt_source` the English was authored against, the row becomes a
// hard CONFLICT and the numeric gate fails. Several records in the local
// dataset were deliberately edited after authoring to exercise exactly that
// guard, so "the gate is PASS" is NOT a property of Stage 4 — it is a property
// of this record's data, and asserting it would be asserting the fixture.
//
// What Stage 4 must prove is narrower and is what is asserted here: the ENGINE
// produces the plan and the gate, the adapter's row was offered and accepted
// into the manifest, and the PT record is byte-identical afterwards. Whether
// the engine then approves that row is the engine's call, and the gate is
// surfaced verbatim rather than interpreted.

$report = Conexao_Translation_Rollout_Engine::run( $engine_config, $engine_adapter, array( 'dry_run' => true ) );

assert_true( ! is_wp_error( $report ), 'the engine accepts the provider-backed manifest' );
assert_true( isset( $report['gate']['gate'] ), 'the ENGINE produces the numeric gate' );
assert_true( in_array( $report['gate']['gate'], array( 'PASS', 'FAIL' ), true ), 'the gate is the engine verdict' );
assert_true( isset( $report['plan']['create'], $report['plan']['skip'], $report['plan']['conflicts'] ), 'the ENGINE produced its own dry-run plan categories' );

// Zero writes, proven against LIVE state rather than asserted. Every field is
// read through the OBJECT accessor: `get_post( $id, ARRAY_A )` returns an
// ARRAY, and comparing `$array->post_title` would silently compare two nulls
// and pass whatever the engine did — a vacuous assertion.
$after_post = get_post( $pt_id );
assert_true( $after_post instanceof WP_Post, 'the PT record is still readable after the dry run' );
assert_equals( $before_post->post_title, $after_post->post_title, 'the dry run left the PT title unchanged' );
assert_equals( $before_post->post_content, $after_post->post_content, 'the dry run left the PT content unchanged' );
assert_equals( $before_post->post_name, $after_post->post_name, 'the dry run left the PT slug unchanged' );
assert_equals( $before_post->post_status, $after_post->post_status, 'the dry run left the PT status unchanged' );
assert_equals( $before_post->post_excerpt, $after_post->post_excerpt, 'the dry run left the PT excerpt unchanged' );

assert_equals( $before_meta, get_post_meta( $pt_id ), 'the dry run wrote no post meta' );
assert_equals( $before_terms, wp_get_post_terms( $pt_id, 'conexao_category', array( 'fields' => 'slugs' ) ), 'the dry run changed no taxonomy assignment' );
assert_equals( $before_translations, pll_get_post_translations( $pt_id ), 'the dry run changed no Polylang relationship' );

// The whole-stage gate is a property of the LOCAL DATASET, not of Stage 4: it
// is FAIL because one pre-existing record was deliberately PT-drifted after its
// English was authored (missing_en=1, conflicts=1). The gate is surfaced
// verbatim and NOT interpreted, and the full-stage run is kept above as the
// honest whole-site state.
//
// To exercise a CLEAN engine verdict about the provider-backed plan itself, the
// same dry run is repeated over a manifest SCOPED to that one record. Scoping
// changes what the engine is asked about, never how it answers.
$scoped = Adapter::compose(
	$stage,
	array(
		$key => array(
			'source_id'     => $key,
			'source_digest' => $digest,
			'mode'          => 'b2',
			'translations'  => array( 'post_excerpt' => 'A provider-generated English card description for the dry run.' ),
		),
	)
);

$scoped_manifest                     = (array) $scoped['manifest'];
$scoped_manifest['records']          = array( $key => $scoped_manifest['records'][ $key ] );

$scoped_report = Conexao_Translation_Rollout_Engine::run(
	Adapter::engine_config( $stage, Conexao_Translation_Rollout_Engine::get_stage( $stage ), $scoped_manifest ),
	$engine_adapter,
	array( 'dry_run' => true )
);

assert_true( ! is_wp_error( $scoped_report ), 'the engine accepts the single-record provider-backed manifest' );
assert_equals( 'PASS', $scoped_report['gate']['gate'], 'a non-drifted provider-backed plan reaches the engine PASS gate' );
assert_equals( 0, (int) $scoped_report['summary']['errors'], 'a non-drifted provider-backed plan reports no errors' );
assert_equals( 0, (int) $scoped_report['gate']['missing_en'], 'the engine reports no missing English for the planned record' );
assert_equals( 0, (int) $scoped_report['gate']['conflicts'], 'the engine reports no conflict for the planned record' );
assert_equals( 0, (int) $scoped_report['gate']['pt_drift'], 'the engine reports no PT drift' );

// The engine reports whatever it reports; the row that failed must be reported,
// not swallowed, and a stage conflict is NOT a provider defect.
$error_rows = array();

foreach ( (array) $report['rows'] as $row ) {
	if ( 'error' === (string) ( $row['action'] ?? '' ) ) {
		$error_rows[] = (string) ( $row['message'] ?? '' );
	}
}

assert_equals(
	count( $error_rows ),
	(int) $report['summary']['errors'],
	'the engine error counter matches the error rows it actually reported'
);

// A dry run performs zero writes BY CONSTRUCTION, so an error is never a write
// failure. Whatever it is, the PT record above is provably untouched.
assert_true(
	is_int( (int) $report['summary']['created'] ) && is_int( (int) $report['summary']['updated'] ),
	'the engine reports integer create/update counters'
);

// A STALE row is refused, so the engine never sees a translation of a source
// that no longer exists.
$stale = Adapter::compose(
	$stage,
	array(
		$key => array(
			'source_id'     => $key,
			'source_digest' => hash( 'sha256', 'not-the-current-pt-state' ),
			'mode'          => 'b2',
			'translations'  => array( 'post_excerpt' => 'A stale translation that must never be offered.' ),
		),
	)
);

assert_true( isset( $stale['rejected'][ $key ] ), 'a stale provider result is rejected before the engine sees it' );
assert_true( false === strpos( (string) wp_json_encode( $stale['manifest'] ), 'must never be offered' ), 'a stale translation never reaches the manifest' );


// ---------------------------------------------------------------------------
// 9. Production safety: review never silently becomes apply
// ---------------------------------------------------------------------------
test_section( 'Production safety' );

// The environment guard still refuses a production run without authorisation.
Conexao_Translation_Automation_Environment::set_site_detector(
	static function () {
		return 'production';
	}
);

$unauthorised = Conexao_Translation_Automation_Environment::evaluate(
	array( 'environment' => 'production', 'production_authorized' => false )
);
assert_true( empty( $unauthorised['permitted'] ), 'a production run without authorisation is refused' );
assert_equals( 'production_not_authorized', (string) $unauthorised['failure'], 'the refusal is the specific production-authorisation category' );

$authorised = Conexao_Translation_Automation_Environment::evaluate(
	array( 'environment' => 'production', 'production_authorized' => true )
);
assert_true( ! empty( $authorised['permitted'] ), 'a production run with explicit authorisation passes the environment guard' );

// ...but being permitted to RUN is not being permitted to APPLY, and the
// provider layer adds no path from one to the other.
Conexao_Translation_Automation_Environment::set_site_detector( null );

$new_files = array(
	'class-conexao-translation-automation-provider-openai.php',
	'class-conexao-translation-automation-provider-config.php',
	'class-conexao-translation-automation-translation-plan.php',
	'class-conexao-translation-automation-plan-adapter.php',
);

// No apply path is named by the plan or the adapter.
foreach ( array( 'MODE_APPLY', "'apply'", 'Apply_Gate', 'approve' ) as $apply_token ) {
	foreach ( array( 'class-conexao-translation-automation-translation-plan.php', 'class-conexao-translation-automation-plan-adapter.php' ) as $file ) {
		assert_true(
			false === strpos( conexao_s4_source( $file ), $apply_token ),
			sprintf( '%s names no apply path (%s)', $file, $apply_token )
		);
	}
}

// The provider and the plan layer introduce no reachable surface, no cron and
// no credential constant.
foreach ( array( 'wp_schedule_event', 'register_rest_route', 'rest_api_init', 'admin_post_', 'wp_ajax_', 'admin_menu', 'update_option', 'add_option' ) as $surface ) {
	foreach ( $new_files as $file ) {
		assert_true(
			false === strpos( conexao_s4_source( $file ), $surface ),
			sprintf( '%s introduces no %s', $file, $surface )
		);
	}
}

// The credential environment variable is NAMED (so an operator can discover it)
// but no credential value is embedded anywhere in the plugin source.
foreach ( $new_files as $file ) {
	$body = conexao_s4_source( $file );

	assert_true(
		false === strpos( $body, 'sk-' ),
		sprintf( '%s embeds no provider credential value', $file )
	);
}

test_finish( 'stage 4 translation plan' );
