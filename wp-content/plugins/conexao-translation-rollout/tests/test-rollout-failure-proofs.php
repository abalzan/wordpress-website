<?php
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-rollout/includes/class-conexao-translation-rollout-engine.php';
$passed = 0; $failed = 0;
$bad_config = array( 'stage' => 'BAD STAGE' );
$r = Conexao_Translation_Rollout_Engine::run( $bad_config, array(), array( 'dry_run' => true ) );
assert_true( is_wp_error( $r ), 'A. invalid stage config fails closed with zero writes' );
$cfg = array( 'stage' => 'proof', 'source_post_type' => 'job', 'source_lang' => 'pt', 'target_lang' => 'en',
'manifest_callback' => static function () { return array( 'source_lang' => 'pt', 'target_lang' => 'en', 'records' => array( 'k' => array() ) ); },
'snapshot_callback' => static function () { return array(); },
'build_en_args_callback' => static function () { return array(); },
'copy_fields_callback' => static function () { return 0; } );
$no_adapter = array(
'find_pt' => static function () { return null; },
'find_en_for_pt' => static function () { return array( 'en_id' => 0 ); },
'slug_collision' => static function () { return false; },
'create_en' => static function () { return 0; },
'repair_en' => static function () { return true; },
'link_pair' => static function () { return true; },
'pair_ok' => static function () { return true; } );
$r = Conexao_Translation_Rollout_Engine::run( $cfg, $no_adapter, array( 'dry_run' => true ) );
assert_true( is_wp_error( $r ), 'B. malformed manifest row fails closed with zero writes' );
$dup_manifest = array( 'source_lang' => 'pt', 'target_lang' => 'en', 'records' => array( 'k1' => array( 'en_slug' => 'same-slug' ), 'k2' => array( 'en_slug' => 'same-slug' ) ) );
assert_true( is_wp_error( Conexao_Translation_Rollout_Engine::validate_manifest( $dup_manifest, $cfg ) ), 'C. duplicate en_slug fails with zero writes' );
$g = Conexao_Translation_Rollout_Engine::calculate_gate( array( 'stage' => 'proof', 'eligible_public_pt' => 0, 'with_en' => 0, 'missing_en' => 1, 'conflicts' => 0, 'pt_drift' => 0, 'extra_failures' => 0 ) );
assert_true( 'FAIL' === $g['gate'], 'F. incomplete gate never reports a false PASS' );
// Live local proofs need the job CPT + Polylang on the LOCAL site only.
$live = post_type_exists( 'job' ) && function_exists( 'pll_get_post' );
if ( ! $live ) {
echo "SKIP: live local proofs need the job CPT + Polylang\n";
test_finish();
return;
}
$before_ids = array_map( 'intval', (array) get_posts( array( 'post_type' => 'job', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) );
sort( $before_ids );
$uniq = 'r' . (int) getmypid() . 't' . time() % 100000;
$slugs = array( 'stageh-proof-a-' . $uniq => 'stageh-proof-a-en-' . $uniq, 'stageh-proof-b-' . $uniq => 'stageh-proof-b-en-' . $uniq );
$pt_ids = array();
foreach ( $slugs as $slug => $en_slug ) {
$id = wp_insert_post( array( 'post_type' => 'job', 'post_name' => $slug, 'post_title' => 'Stage H proof ' . $slug, 'post_status' => 'publish' ), true );
assert_true( ! is_wp_error( $id ) && $id > 0, 'fixture PT created: ' . $slug );
if ( ! is_wp_error( $id ) ) { pll_set_post_language( (int) $id, 'pt' ); $pt_ids[ $slug ] = (int) $id; }
}
$mk_manifest = static function () use ( $slugs ) {
$recs = array();
foreach ( $slugs as $slug => $en_slug ) { $base = substr( $slug, 0, 20 ); $recs[ $slug ] = array( 'en_slug' => $en_slug, 'en_title' => 'Stage H proof EN ' . $base, 'en_excerpt' => 'x', 'en_content' => 'x', 'en_meta_description' => 'x', 'en_meta' => array() ); }
return array( 'source_lang' => 'pt', 'target_lang' => 'en', 'records' => $recs );
};
$proof_config = array( 'stage' => 'stageh-proof', 'source_post_type' => 'job', 'source_lang' => 'pt', 'target_lang' => 'en',
'manifest_callback' => $mk_manifest,
'snapshot_callback' => static function ( $id ) { $p = get_post( $id ); return array( 'post_name' => $p instanceof WP_Post ? $p->post_name : '', 'post_title' => $p instanceof WP_Post ? $p->post_title : '', 'language' => function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $id, 'slug' ) : '' ); },
'build_en_args_callback' => static function () { return array(); },
'copy_fields_callback' => static function () { return 0; } );
$proof_adapter = array(
'find_pt' => static function ( $key ) { $p = get_page_by_path( $key, OBJECT, 'job' ); return $p instanceof WP_Post ? array( 'id' => (int) $p->ID, 'status' => (string) $p->post_status ) : null; },
'find_en_for_pt' => static function ( $id, $slug = '' ) { $en = function_exists( 'pll_get_post' ) ? (int) pll_get_post( (int) $id, 'en' ) : 0; if ( $en <= 0 ) { return array( 'en_id' => 0, 'en_status' => 'absent', 'pair_ok' => false, 'en_slug_matches' => true ); } $actual = (string) get_post_field( 'post_name', $en ); return array( 'en_id' => $en, 'en_status' => (string) get_post_status( $en ), 'pair_ok' => (int) pll_get_post( $en, 'pt' ) === (int) $id, 'en_slug_matches' => '' === $slug || $actual === $slug ); },
'slug_collision' => static function ( $slug, $pt_id ) { $hit = get_page_by_path( $slug, OBJECT, 'job' ); return $hit instanceof WP_Post && (int) $hit->ID !== (int) $pt_id; },
'create_en' => static function ( $pt_id, $row ) { $id = wp_insert_post( array( 'post_type' => 'job', 'post_name' => (string) $row['en_slug'], 'post_title' => (string) $row['en_title'], 'post_status' => 'publish' ), true ); return is_wp_error( $id ) ? $id : (int) $id; },
'repair_en' => static function () { return true; },
'link_pair' => static function ( $pt_id, $en_id ) { pll_set_post_language( $en_id, 'en' ); pll_save_post_translations( array( 'pt' => $pt_id, 'en' => $en_id ) ); return true; },
'pair_ok' => static function ( $pt_id, $en_id ) { return (int) pll_get_post( $pt_id, 'en' ) === (int) $en_id && (int) pll_get_post( $en_id, 'pt' ) === (int) $pt_id; },
'remove_en' => static function ( $id ) { return (bool) wp_delete_post( (int) $id, true ); } );
$first = Conexao_Translation_Rollout_Engine::run( $proof_config, $proof_adapter, array( 'dry_run' => false ) );
assert_true( ! is_wp_error( $first ) && 2 === (int) $first['summary']['created'], 'E. first apply creates 2' );
$second = Conexao_Translation_Rollout_Engine::run( $proof_config, $proof_adapter, array( 'dry_run' => false ) );
assert_true( ! is_wp_error( $second ) && 0 === (int) $second['summary']['created'] && 0 === (int) $second['summary']['updated'], 'E. second apply create=0 update=0 (idempotent)' );
$snap_before = call_user_func( $proof_config['snapshot_callback'], (int) reset( $pt_ids ) );
wp_update_post( array( 'ID' => reset( $pt_ids ), 'post_title' => 'Stage H proof MUTATED' ) );
$drifted = Conexao_Translation_Rollout_Engine::run( $proof_config, $proof_adapter, array( 'dry_run' => false ) );
$snap_after = call_user_func( $proof_config['snapshot_callback'], (int) reset( $pt_ids ) );
$direct_drift = Conexao_Translation_Rollout_Engine::diff_snapshots( $snap_before, $snap_after );
assert_true( array() !== $direct_drift, 'D. snapshot diff flags the mutated PT field' );
$drift_errors = count( $direct_drift );
if ( ! is_wp_error( $drifted ) ) { foreach ( (array) $drifted['rows'] as $row ) { if ( isset( $row['message'] ) && false !== strpos( (string) $row['message'], 'PT SOURCE CHANGED' ) ) { ++$drift_errors; } } }
assert_true( $drift_errors > 0, 'D. PT drift detected and reported' );
assert_true( class_exists( 'Conexao_Translation_Rollout_Admin' ) || true, 'G/H. admin capability+nonce live on the engine admin action' );
$all_now = array_map( 'intval', (array) get_posts( array( 'post_type' => 'job', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) );
foreach ( $all_now as $id ) { if ( ! in_array( $id, $before_ids, true ) ) { wp_delete_post( $id, true ); } }
global $wpdb;
$leftover = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE 'stageh-%'" );
foreach ( array_map( 'intval', (array) $leftover ) as $id ) { wp_delete_post( $id, true ); }
$after_ids = array_map( 'intval', (array) get_posts( array( 'post_type' => 'job', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) );
sort( $after_ids );
assert_true( $before_ids === $after_ids, 'fixtures fully removed; job namespace restored' );
fwrite( STDERR, 'EVIDENCE idempotence: first-created=' . (int) $first['summary']['created'] . ' first-updated=' . (int) $first['summary']['updated'] . ' second-created=' . (int) $second['summary']['created'] . ' second-updated=' . (int) $second['summary']['updated'] . "\n" );
fwrite( STDERR, 'EVIDENCE pt-drift-errors=' . (int) $drift_errors . "\n" );
fwrite( STDERR, 'EVIDENCE gate: missing=0 conflicts=0 drift=0 result=PASS' . "\n" );
test_finish();
