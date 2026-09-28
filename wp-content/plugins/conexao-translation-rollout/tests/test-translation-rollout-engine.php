<?php
/**
 * Shared translation-rollout engine contract tests (Stage H).
 *
 * Pure-contract tests: config validation, manifest validation, dry-run plan
 * categories, stable identity, snapshot diff, numeric gate, stage
 * registration, and idempotent apply + PT-drift guard through a fake
 * in-memory adapter (no WordPress writes, no production data).
 *
 * @package Conexao_Translation_Rollout
 */
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-rollout/includes/class-conexao-translation-rollout-engine.php';
$passed = 0;
$failed = 0;
function rollout_fake_stage( array $manifest, array &$store ) {
return array(
'stage' => 'fake', 'source_post_type' => 'fake', 'source_lang' => 'pt', 'target_lang' => 'en',
'manifest_callback' => static function () use ( $manifest ) { return $manifest; },
'snapshot_callback' => static function ( $id ) use ( &$store ) { return isset( $store[ $id ]['snap'] ) ? $store[ $id ]['snap'] : array(); },
'build_en_args_callback' => static function () { return array(); },
'copy_fields_callback' => static function () { return 0; },
'run_callback' => static function ( array $args = array() ) { return array(); },
);
}
function rollout_fake_adapter( array &$store ) {
return array(
'find_pt' => static function ( $key ) use ( &$store ) { return isset( $store[ $key ]['pt'] ) ? $store[ $key ]['pt'] : null; },
'find_en_for_pt' => static function ( $id, $slug = '' ) use ( &$store ) {
foreach ( $store as $row ) { if ( isset( $row['pt'] ) && (int) $row['pt']['id'] === (int) $id ) { return $row['en']; } }
return array( 'en_id' => 0, 'en_status' => 'absent', 'pair_ok' => false, 'en_slug_matches' => true );
},
'slug_collision' => static function () { return false; },
'create_en' => static function ( $pt_id, $row ) use ( &$store ) {
$new = 9000 + count( $store );
foreach ( $store as $k => $v ) { if ( (int) $v['pt']['id'] === (int) $pt_id ) { $store[ $k ]['en'] = array( 'en_id' => $new, 'en_status' => 'publish', 'pair_ok' => true, 'en_slug_matches' => true ); } }
return $new;
},
'repair_en' => static function () { return true; },
'link_pair' => static function () { return true; },
'pair_ok' => static function () { return true; },
'remove_en' => static function ( $id ) use ( &$store ) {
foreach ( $store as $k => $v ) { if ( isset( $v['en']['en_id'] ) && (int) $v['en']['en_id'] === (int) $id ) { $store[ $k ]['en'] = array( 'en_id' => 0, 'en_status' => 'absent', 'pair_ok' => false, 'en_slug_matches' => true ); return true; } }
return false;
},
);
}
$base_manifest = array( 'source_lang' => 'pt', 'target_lang' => 'en', 'records' => array(
'a' => array( 'en_slug' => 'a-en' ), 'b' => array( 'en_slug' => 'b-en' ),
) );
$tmp_store = array();
$base_config = rollout_fake_stage( $base_manifest, $tmp_store );
assert_true( true === Conexao_Translation_Rollout_Engine::validate_config( $base_config ), 'valid stage config passes' );
$bad = $base_config; unset( $bad['manifest_callback'] );
assert_true( is_wp_error( Conexao_Translation_Rollout_Engine::validate_config( $bad ) ), 'missing manifest_callback fails closed' );
$bad2 = $base_config; $bad2['stage'] = 'BAD STAGE';
assert_true( is_wp_error( Conexao_Translation_Rollout_Engine::validate_config( $bad2 ) ), 'bad stage id fails closed' );
assert_true( true === Conexao_Translation_Rollout_Engine::validate_manifest( $base_manifest, $base_config ), 'valid manifest passes' );
$dup = array( 'source_lang' => 'pt', 'target_lang' => 'en', 'records' => array( 'a' => array( 'en_slug' => 'same' ), 'b' => array( 'en_slug' => 'same' ) ) );
$r = Conexao_Translation_Rollout_Engine::validate_manifest( $dup, $base_config );
assert_true( is_wp_error( $r ), 'duplicate en_slug fails with zero writes' );
$malformed = array( 'source_lang' => 'pt', 'target_lang' => 'en', 'records' => array( 'a' => array() ) );
assert_true( is_wp_error( Conexao_Translation_Rollout_Engine::validate_manifest( $malformed, $base_config ) ), 'malformed row fails closed' );
$lang_bad = array( 'source_lang' => 'en', 'target_lang' => 'en', 'records' => array( 'a' => array( 'en_slug' => 'x' ) ) );
assert_true( is_wp_error( Conexao_Translation_Rollout_Engine::validate_manifest( $lang_bad, $base_config ) ), 'language mismatch fails closed' );
$plan = Conexao_Translation_Rollout_Engine::build_plan( $base_manifest, array(
'a' => array( 'pt_id' => 1, 'pt_status' => 'publish', 'en_id' => 0, 'en_status' => 'absent', 'pair_ok' => false, 'en_slug_matches' => true, 'slug_collision' => false ),
'b' => array( 'pt_id' => 2, 'pt_status' => 'publish', 'en_id' => 20, 'en_status' => 'publish', 'pair_ok' => true, 'en_slug_matches' => true, 'slug_collision' => false ),
) );
assert_true( 1 === count( $plan['create'] ) && 1 === count( $plan['skip'] ) && 0 === count( $plan['conflicts'] ), 'plan categories: 1 create + 1 skip' );
$plan2 = Conexao_Translation_Rollout_Engine::build_plan( $base_manifest, array(
'a' => array( 'pt_id' => 0, 'pt_status' => 'absent', 'en_id' => 0, 'en_status' => 'absent', 'pair_ok' => false, 'en_slug_matches' => true, 'slug_collision' => false ),
'b' => array( 'pt_id' => 2, 'pt_status' => 'publish', 'en_id' => 21, 'en_status' => 'publish', 'pair_ok' => false, 'en_slug_matches' => true, 'slug_collision' => false ),
) );
assert_true( 1 === count( $plan2['skip'] ) && 1 === count( $plan2['conflicts'] ), 'absent PT skips; broken pair conflicts' );
$d = Conexao_Translation_Rollout_Engine::diff_snapshots( array( 'a' => 1, 'b' => 2 ), array( 'a' => 1, 'b' => 3 ) );
assert_true( array( 'b' ) === $d, 'snapshot diff detects changed field' );
assert_true( array() === Conexao_Translation_Rollout_Engine::diff_snapshots( array( 'a' => 1 ), array( 'a' => 1 ) ), 'identical snapshots diff empty' );
$g = Conexao_Translation_Rollout_Engine::calculate_gate( array( 'stage' => 'fake', 'eligible_public_pt' => 2, 'with_en' => 2, 'missing_en' => 0, 'conflicts' => 0, 'pt_drift' => 0, 'extra_failures' => 0 ) );
assert_true( 'PASS' === $g['gate'], 'numeric gate PASS when all counters zero' );
$g2 = Conexao_Translation_Rollout_Engine::calculate_gate( array( 'stage' => 'fake', 'eligible_public_pt' => 2, 'with_en' => 1, 'missing_en' => 1, 'conflicts' => 0, 'pt_drift' => 0, 'extra_failures' => 0 ) );
assert_true( 'FAIL' === $g2['gate'], 'missing EN fails the gate (no textual pass)' );
Conexao_Translation_Rollout_Engine::reset_stages();
assert_true( true === Conexao_Translation_Rollout_Engine::register_stage( $base_config ), 'stage registers' );
assert_true( in_array( 'fake', Conexao_Translation_Rollout_Engine::registered_stages(), true ), 'registered stage listed' );
$store = array(
'a' => array( 'pt' => array( 'id' => 1, 'status' => 'publish' ), 'en' => array( 'en_id' => 0, 'en_status' => 'absent', 'pair_ok' => false, 'en_slug_matches' => true ), 'snap' => array( 'post_title' => 'A', 'language' => 'pt' ) ),
'b' => array( 'pt' => array( 'id' => 2, 'status' => 'publish' ), 'en' => array( 'en_id' => 20, 'en_status' => 'publish', 'pair_ok' => true, 'en_slug_matches' => true ), 'snap' => array( 'post_title' => 'B', 'language' => 'pt' ) ),
);
$config = rollout_fake_stage( $base_manifest, $store );
$adapter = rollout_fake_adapter( $store );
$dry = Conexao_Translation_Rollout_Engine::run( $config, $adapter, array( 'dry_run' => true ) );
assert_true( ! is_wp_error( $dry ) && 1 === (int) $dry['summary']['created'] && 1 === (int) $dry['summary']['skipped'], 'dry-run plans 1 create + 1 skip with zero writes' );
assert_true( 0 === (int) $store['a']['en']['en_id'], 'dry-run wrote nothing' );
$first = Conexao_Translation_Rollout_Engine::run( $config, $adapter, array( 'dry_run' => false ) );
assert_true( ! is_wp_error( $first ) && 1 === (int) $first['summary']['created'], 'first apply creates 1' );
$second = Conexao_Translation_Rollout_Engine::run( $config, $adapter, array( 'dry_run' => false ) );
assert_true( ! is_wp_error( $second ) && 0 === (int) $second['summary']['created'] && 0 === (int) $second['summary']['updated'], 'second apply: create=0 update=0 (idempotent)' );
$store['a']['snap']['post_title'] = 'CHANGED';
$drifted = Conexao_Translation_Rollout_Engine::run( $config, $adapter, array( 'dry_run' => true ) );
assert_true( ! is_wp_error( $drifted ), 'drift check run completes' );
$before = array( 'post_title' => 'A', 'language' => 'pt' );
$after = array( 'post_title' => 'CHANGED', 'language' => 'pt' );
assert_true( array() !== Conexao_Translation_Rollout_Engine::diff_snapshots( $before, $after ), 'PT drift detected by snapshot diff' );

/*
 * ---------------------------------------------------------------------------
 * The OPTIONAL taxonomy capability (`taxonomy_callback` / `taxonomy_gate_callback`).
 *
 * Contract: a stage that declares it gets its taxonomy step invoked by the
 * ENGINE, dry-run aware, before the record plan; its failure count is folded
 * into the same numeric gate. A stage that does not declare it is untouched.
 * ---------------------------------------------------------------------------
 */

$tax_calls = array();

// (1) The keys are optional: a stage without them validates and runs unchanged.
assert_true( ! array_key_exists( 'taxonomy_callback', $base_config ), 'a stage declares no taxonomy callback by default' );
assert_true( array() === $dry['summary']['taxonomy'], 'a stage without a taxonomy callback reports an empty taxonomy payload' );

// (2) A non-callable taxonomy key fails closed, like every other optional key.
$bad_tax = $base_config;
$bad_tax['taxonomy_callback'] = 'conexao_not_a_function_at_all';
assert_true( is_wp_error( Conexao_Translation_Rollout_Engine::validate_config( $bad_tax ) ), 'a non-callable taxonomy_callback fails closed' );

$bad_tax_gate = $base_config;
$bad_tax_gate['taxonomy_gate_callback'] = 'conexao_not_a_function_at_all';
assert_true( is_wp_error( Conexao_Translation_Rollout_Engine::validate_config( $bad_tax_gate ) ), 'a non-callable taxonomy_gate_callback fails closed' );

// (3) A stage that DOES declare it: invoked, dry-run aware, and reported.
$tax_store = array(
	'a' => array( 'pt' => array( 'id' => 1, 'status' => 'publish' ), 'en' => array( 'en_id' => 0, 'en_status' => 'absent', 'pair_ok' => false, 'en_slug_matches' => true ), 'snap' => array( 'post_title' => 'A', 'language' => 'pt' ) ),
	'b' => array( 'pt' => array( 'id' => 2, 'status' => 'publish' ), 'en' => array( 'en_id' => 0, 'en_status' => 'absent', 'pair_ok' => false, 'en_slug_matches' => true ), 'snap' => array( 'post_title' => 'B', 'language' => 'pt' ) ),
);
$tax_config = rollout_fake_stage( $base_manifest, $tax_store );
$tax_config['stage']                  = 'fake-taxonomy';
$tax_config['taxonomy_callback']      = static function ( $dry_run, $mode = 'run' ) use ( &$tax_calls ) {
	$tax_calls[] = array( 'dry_run' => (bool) $dry_run, 'mode' => (string) $mode );
	return array( 'taxonomy' => 'fake_tax', 'created' => 0, 'en_terms_planned' => 2 );
};
$tax_config['taxonomy_gate_callback'] = static function () { return 0; };

assert_true( true === Conexao_Translation_Rollout_Engine::validate_config( $tax_config ), 'a stage declaring the taxonomy keys validates' );

$tax_adapter = rollout_fake_adapter( $tax_store );
$tax_dry     = Conexao_Translation_Rollout_Engine::run( $tax_config, $tax_adapter, array( 'dry_run' => true ) );
assert_true( ! is_wp_error( $tax_dry ), 'a taxonomy stage dry-runs' );
assert_true( 1 === count( $tax_calls ) && true === $tax_calls[0]['dry_run'], 'the engine invokes taxonomy_callback with dry_run=true' );
assert_true( 'fake_tax' === ( $tax_dry['summary']['taxonomy']['taxonomy'] ?? '' ), 'the taxonomy payload is reported in the run summary' );
assert_true( 0 === (int) $tax_store['a']['en']['en_id'], 'a taxonomy dry-run still writes no records' );

$tax_apply = Conexao_Translation_Rollout_Engine::run( $tax_config, $tax_adapter, array( 'dry_run' => false ) );
assert_true( ! is_wp_error( $tax_apply ) && 2 === (int) $tax_apply['summary']['created'], 'a taxonomy stage applies its records normally' );
assert_true( 2 === count( $tax_calls ) && false === $tax_calls[1]['dry_run'], 'the engine invokes taxonomy_callback with dry_run=false on apply' );

// (4) A taxonomy FAILURE must fail the shared numeric gate (fail closed).
$tax_store2 = array(
	'a' => array( 'pt' => array( 'id' => 1, 'status' => 'publish' ), 'en' => array( 'en_id' => 0, 'en_status' => 'absent', 'pair_ok' => false, 'en_slug_matches' => true ), 'snap' => array( 'post_title' => 'A', 'language' => 'pt' ) ),
	'b' => array( 'pt' => array( 'id' => 2, 'status' => 'publish' ), 'en' => array( 'en_id' => 0, 'en_status' => 'absent', 'pair_ok' => false, 'en_slug_matches' => true ), 'snap' => array( 'post_title' => 'B', 'language' => 'pt' ) ),
);
$tax_fail_config             = rollout_fake_stage( $base_manifest, $tax_store2 );
$tax_fail_config['stage']                  = 'fake-taxonomy-fail';
$tax_fail_config['taxonomy_callback']      = static function () { return array( 'taxonomy' => 'fake_tax' ); };
$tax_fail_config['taxonomy_gate_callback'] = static function () { return 2; };
$tax_fail = Conexao_Translation_Rollout_Engine::run( $tax_fail_config, rollout_fake_adapter( $tax_store2 ), array( 'dry_run' => true ) );
assert_true( ! is_wp_error( $tax_fail ), 'a failing taxonomy stage still returns a report' );
assert_true( 'FAIL' === $tax_fail['gate']['gate'], 'a taxonomy failure fails the shared numeric gate' );
assert_true( 2 === (int) $tax_fail['gate']['extra_failures'], 'the taxonomy failure count is reported in extra_failures' );
assert_true( 2 === (int) $tax_fail['gate']['missing_en'] && 0 === (int) $tax_fail['gate']['conflicts'] && 0 === (int) $tax_fail['gate']['pt_drift'], 'the taxonomy failure is additive to the record gate and does not fabricate record or PT-drift numbers' );

test_finish();
