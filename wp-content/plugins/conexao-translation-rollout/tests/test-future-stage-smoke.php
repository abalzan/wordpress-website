<?php
/**
 * Stage H — future-rollout smoke proof (Phase 17).
 *
 * Architecture proof, NOT a production rollout: a synthetic stage registers
 * itself with the shared engine, supplies a versioned data manifest and a
 * small config, and receives the common inventory / plan / snapshot / apply /
 * verify / gate behaviour without copying any orchestration.
 *
 * No new post type, no production content, no production identifiers.
 *
 * @package Conexao_Translation_Rollout
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-rollout/includes/class-conexao-translation-rollout-engine.php';

$passed = 0;
$failed = 0;

// A synthetic stage: a versioned data manifest plus a small config. Nothing
// else — no apply.php, no audit.php, no admin class.
$future_manifest = array(
	'source_lang' => 'pt',
	'target_lang' => 'en',
	'records'     => array(
		'future-record-1' => array( 'en_slug' => 'future-record-1-en', 'en_title' => 'Future record 1' ),
		'future-record-2' => array( 'en_slug' => 'future-record-2-en', 'en_title' => 'Future record 2' ),
	),
);

$store = array(
	'future-record-1' => array(
		'pt'  => array( 'id' => 11, 'status' => 'publish' ),
		'en'  => array( 'en_id' => 0, 'en_status' => 'absent', 'pair_ok' => false, 'en_slug_matches' => true ),
		'snap' => array( 'title' => 'PT 1', 'language' => 'pt' ),
	),
	'future-record-2' => array(
		'pt'  => array( 'id' => 12, 'status' => 'draft' ),
		'en'  => array( 'en_id' => 0, 'en_status' => 'absent', 'pair_ok' => false, 'en_slug_matches' => true ),
		'snap' => array( 'title' => 'PT 2', 'language' => 'pt' ),
	),
);

$future_config = array(
	'stage'                  => 'future-proof',
	'source_post_type'       => 'future_proof_type',
	'source_lang'            => 'pt',
	'target_lang'            => 'en',
	'manifest_callback'      => static function () use ( $future_manifest ) {
		return $future_manifest;
	},
	'snapshot_callback'      => static function ( $id ) use ( &$store ) {
		foreach ( $store as $row ) {
			if ( isset( $row['pt'] ) && (int) $row['pt']['id'] === (int) $id ) {
				return $row['snap'];
			}
		}
		return array();
	},
	'build_en_args_callback' => static function () {
		return array();
	},
	'copy_fields_callback'   => static function () {
		return 0;
	},
);

$future_adapter = array(
	'find_pt'        => static function ( $key ) use ( &$store ) {
		return isset( $store[ $key ]['pt'] ) ? $store[ $key ]['pt'] : null;
	},
	'find_en_for_pt' => static function ( $id ) use ( &$store ) {
		foreach ( $store as $row ) {
			if ( isset( $row['pt'] ) && (int) $row['pt']['id'] === (int) $id ) {
				return $row['en'];
			}
		}
		return array( 'en_id' => 0, 'en_status' => 'absent', 'pair_ok' => false, 'en_slug_matches' => true );
	},
	'slug_collision' => static function () {
		return false;
	},
	'create_en'      => static function ( $pt_id, $row ) use ( &$store ) {
		foreach ( $store as $key => $item ) {
			if ( isset( $item['pt'] ) && (int) $item['pt']['id'] === (int) $pt_id ) {
				$store[ $key ]['en'] = array( 'en_id' => 1000 + (int) $pt_id, 'en_status' => 'publish', 'pair_ok' => true, 'en_slug_matches' => true );
				return 1000 + (int) $pt_id;
			}
		}
		return 0;
	},
	'repair_en'      => static function () {
		return true;
	},
	'link_pair'      => static function () {
		return true;
	},
	'pair_ok'        => static function () {
		return true;
	},
);

// 1. The stage registers itself.
Conexao_Translation_Rollout_Engine::reset_stages();

assert_true( true === Conexao_Translation_Rollout_Engine::register_stage( $future_config ), 'future stage registers with the shared engine' );
assert_true( in_array( 'future-proof', Conexao_Translation_Rollout_Engine::registered_stages(), true ), 'registered stage is selectable by the admin screen' );

// 2. It receives the common dry-run plan (create for the published record,
//    skip for the ineligible draft) with zero writes.
$dry = Conexao_Translation_Rollout_Engine::run( $future_config, $future_adapter, array( 'dry_run' => true ) );

assert_true( ! is_wp_error( $dry ), 'future stage dry-run completes' );
assert_true( 1 === count( $dry['plan']['create'] ) && 1 === count( $dry['plan']['skip'] ), 'future stage plan: 1 create (published) + 1 skip (ineligible draft)' );
assert_true( 0 === (int) $store['future-record-1']['en']['en_id'], 'future stage dry-run performed zero writes' );
// Both manifest records resolve through the authored stable key (the ineligible
// draft is resolved and then excluded by eligibility, never by matching).
assert_true( 2 === (int) $dry['summary']['match']['stable-id'], 'future stage resolved both records through the portable stable key' );
assert_true( 0 === (int) $dry['summary']['match']['slug'] && 0 === (int) $dry['summary']['match']['title'], 'no silent slug/title fallback matching' );

// 3. Apply, then the common idempotent re-run.
$first = Conexao_Translation_Rollout_Engine::run( $future_config, $future_adapter, array( 'dry_run' => false ) );

assert_true( ! is_wp_error( $first ) && 1 === (int) $first['summary']['created'], 'future stage apply creates 1' );

$second = Conexao_Translation_Rollout_Engine::run( $future_config, $future_adapter, array( 'dry_run' => false ) );

assert_true( ! is_wp_error( $second ) && 0 === (int) $second['summary']['created'] && 0 === (int) $second['summary']['updated'], 'future stage re-apply: create=0 update=0' );

// 4. Verify + numeric gate.
assert_true( 1 === (int) $second['gate']['eligible_public_pt'], 'gate counts only eligible public PT records' );
assert_true( 1 === (int) $second['gate']['with_en'] && 0 === (int) $second['gate']['missing_en'], 'gate reports with_en and missing_en numerically' );
assert_true( 'PASS' === $second['gate']['gate'], 'future stage gate PASSES on the shared engine' );

// 5. Remove is refused unless the stage declares it safe.
$no_remove = Conexao_Translation_Rollout_Engine::run( $future_config, $future_adapter, array( 'dry_run' => false, 'mode' => 'remove' ) );

assert_true( is_wp_error( $no_remove ), 'remove is refused for a stage that does not declare allow_remove' );

test_finish();
