<?php
/**
 * Coordinate resolver × shared `_leisure_export_uuid` (HARD GATE).
 *
 * `_leisure_export_uuid` identifies the ATTRACTION, not the WordPress post: a
 * Portuguese master and its linked Polylang English translation deliberately
 * share one value (see test-language-uuid.php and
 * Conexao_Lazer_Importer::pick_import_language()).
 *
 * `scripts/apply-leisure-coordinates.php` therefore cannot resolve on the UUID
 * alone. This suite proves, against its own throwaway fixture pair, that:
 *
 *   1. a shared UUID is disambiguated by the manifest entry's SLUG, so the two
 *      entries resolve to two DIFFERENT posts (never the same post twice);
 *   2. an entry that names the shared UUID with no slug and no language FAILS
 *      SAFELY — non-zero exit, `uuid_ambiguous` reported, NOTHING written;
 *   3. the apply is idempotent: a second run reports zero changes;
 *   4. no UUID value, translation relationship or `_leisure_coord_verified` flag
 *      is altered by the tool.
 *
 * The fixtures are prefixed "[LCR]" and deleted at the end.
 *
 * Usage (from the project root):
 *   docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-leisure-migration/tests/test-leisure-coordinate-resolver.php
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

test_prerequisite_hint( 'polylang' );
test_require( function_exists( 'pll_set_post_language' ), 'polylang', 'test prerequisite is available: function_exists( pll_set_post_language )', 'activate the Polylang plugin' );
test_prerequisite_hint( 'activate-plugin:conexao-leisure-migration' );
test_require( class_exists( 'Conexao_Lazer_Exporter' ), 'activate-plugin:conexao-leisure-migration', 'test prerequisite is available: class_exists( Conexao_Lazer_Exporter )', 'activate the conexao-leisure-migration plugin' );

$repo_root     = dirname( CONEXAO_TESTS_ROOT );
$apply_script  = $repo_root . '/scripts/apply-leisure-coordinates.php';
test_require( is_file( $apply_script ), 'scripts', 'the coordinate apply script exists: scripts/apply-leisure-coordinates.php', 'restore scripts/apply-leisure-coordinates.php' );

/**
 * Run the apply script as a subprocess and return its output plus exit code.
 *
 * @param string $manifest_path Absolute manifest path.
 * @param bool   $apply         True to apply, false for a dry run.
 * @return array{lines:string[],exit:int}
 */
function lcr_run( $manifest_path, $apply = false ) {
	$cmd = 'php ' . escapeshellarg( dirname( CONEXAO_TESTS_ROOT ) . '/scripts/apply-leisure-coordinates.php' )
		. ' --manifest=' . escapeshellarg( $manifest_path )
		. ( $apply ? ' --apply --confirm-production' : ' --dry-run' )
		. ' 2>&1';

	$lines = array();
	$exit  = 0;
	exec( $cmd, $lines, $exit );

	return array( 'lines' => $lines, 'exit' => (int) $exit );
}

/**
 * Extract the `summary:` key=value line from a run.
 *
 * @param string[] $lines Output lines.
 * @return array<string,string>
 */
function lcr_summary( array $lines ) {
	foreach ( $lines as $line ) {
		if ( 0 !== strpos( $line, 'summary: ' ) ) {
			continue;
		}
		$out = array();
		foreach ( explode( ', ', substr( $line, strlen( 'summary: ' ) ) ) as $pair ) {
			$kv = explode( '=', $pair, 2 );
			if ( 2 === count( $kv ) ) {
				$out[ $kv[0] ] = $kv[1];
			}
		}
		return $out;
	}

	return array();
}

/**
 * Write a manifest to a temp file and return its path.
 *
 * @param array $entries Manifest entries.
 * @return string
 */
function lcr_manifest( array $entries ) {
	$path = tempnam( sys_get_temp_dir(), 'lcr-manifest-' );
	file_put_contents( $path, wp_json_encode( $entries ) );
	return $path;
}

/**
 * How many leisure records carry a UUID.
 *
 * @param string $uuid Export UUID.
 * @return int
 */
function lcr_uuid_owner_count( $uuid ) {
	global $wpdb;

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} m
			 JOIN {$wpdb->posts} p ON p.ID = m.post_id
			 WHERE m.meta_key = %s AND m.meta_value = %s AND p.post_type = 'leisure'",
			Conexao_Lazer_Exporter::UUID_META_KEY,
			$uuid
		)
	);
}

/**
 * Whether a leisure record currently stores a coordinate pair (1 or 0).
 *
 * @param int $post_id Post ID.
 * @return int
 */
function lcr_coordinate_status_of( $post_id ) {
	$lat = (string) get_post_meta( (int) $post_id, Conexao_Data_Model_Meta::LEISURE_LAT_KEY, true );
	$lng = (string) get_post_meta( (int) $post_id, Conexao_Data_Model_Meta::LEISURE_LNG_KEY, true );

	return ( '' !== $lat || '' !== $lng ) ? 1 : 0;
}

$shared_uuid = 'lcr-coordinate-resolver-0001';
$created     = array();
$manifests   = array();
$pt_id       = 0;
$en_id       = 0;

try {
	// --- Fixture pair sharing ONE UUID (the documented identity contract). ---
	$pt_id = wp_insert_post(
		array(
			'post_type'    => 'leisure',
			'post_title'   => '[LCR] Resolver fixture',
			'post_name'    => 'lcr-resolver-fixture',
			'post_content' => 'Resolver fixture.',
			'post_status'  => 'publish',
		),
		true
	);
	assert_true( ! is_wp_error( $pt_id ), 'created the PT resolver fixture' );
	if ( is_wp_error( $pt_id ) ) {
		test_finish();
	}
	$created[] = (int) $pt_id;
	update_post_meta( $pt_id, Conexao_Lazer_Exporter::UUID_META_KEY, $shared_uuid );

	$en_id = wp_insert_post(
		array(
			'post_type'    => 'leisure',
			'post_title'   => '[LCR] Resolver fixture (EN)',
			'post_name'    => 'lcr-resolver-fixture-en',
			'post_content' => 'Resolver fixture (EN).',
			'post_status'  => 'publish',
		),
		true
	);
	assert_true( ! is_wp_error( $en_id ), 'created the EN resolver fixture' );
	if ( is_wp_error( $en_id ) ) {
		test_finish();
	}
	$created[] = (int) $en_id;
	pll_set_post_language( (int) $en_id, 'en' );
	pll_save_post_translations( array( 'pt' => (int) $pt_id, 'en' => (int) $en_id ) );
	update_post_meta( $en_id, Conexao_Lazer_Exporter::UUID_META_KEY, $shared_uuid );

	assert_true( 2 === lcr_uuid_owner_count( $shared_uuid ), 'the fixture pair shares ONE UUID across two records' );

	// --- 1. Slug disambiguation: two entries, one UUID, two different posts. ---
	$path = lcr_manifest(
		array(
			array(
				'uuid'                => $shared_uuid,
				'slug'                => 'lcr-resolver-fixture',
				'lang'                => 'pt',
				'title'               => '[LCR] Resolver fixture',
				'proposed_latitude'   => '53.100001',
				'proposed_longitude'  => '-6.100001',
				'confidence'          => 'HIGH',
				'action'              => 'POPULATE',
			),
			array(
				'uuid'                => $shared_uuid,
				'slug'                => 'lcr-resolver-fixture-en',
				'lang'                => 'en',
				'title'               => '[LCR] Resolver fixture (EN)',
				'proposed_latitude'   => '53.200002',
				'proposed_longitude'  => '-6.200002',
				'confidence'          => 'HIGH',
				'action'              => 'POPULATE',
			),
		)
	);
	$manifests[] = $path;

	$run     = lcr_run( $path );
	$summary = lcr_summary( $run['lines'] );

	assert_true( 0 === $run['exit'], 'the shared-UUID dry run exits 0' );
	assert_equals( '2', $summary['planned'] ?? '', 'both shared-UUID entries are planned as writes' );
	assert_equals( '2', $summary['match_stable_id_slug'] ?? '', 'both entries were disambiguated by slug (stable-id+slug)' );
	assert_equals( '0', $summary['unresolved'] ?? '', 'no shared-UUID entry is left unresolved' );
	assert_true( 0 === lcr_coordinate_status_of( (int) $pt_id ), 'the dry run wrote nothing to the PT fixture' );
	assert_true( 0 === lcr_coordinate_status_of( (int) $en_id ), 'the dry run wrote nothing to the EN fixture' );

	// --- 2. Ambiguity: the UUID with no slug and no language must fail safely. ---
	$ambiguous_path = lcr_manifest(
		array(
			array(
				'uuid'               => $shared_uuid,
				'title'              => '[LCR] Ambiguous entry',
				'proposed_latitude'  => '53.300003',
				'proposed_longitude' => '-6.300003',
				'confidence'         => 'HIGH',
				'action'             => 'POPULATE',
			),
		)
	);
	$manifests[] = $ambiguous_path;

	$amb_run  = lcr_run( $ambiguous_path );
	$amb_text = implode( "\n", $amb_run['lines'] );

	assert_true( 0 !== $amb_run['exit'], 'an ambiguous shared-UUID entry fails the run (non-zero exit)' );
	assert_true( false !== strpos( $amb_text, 'uuid_ambiguous' ), 'the failure names the ambiguity (uuid_ambiguous)' );
	assert_true( 0 === lcr_coordinate_status_of( (int) $pt_id ), 'the ambiguous run wrote nothing to the PT fixture' );
	assert_true( 0 === lcr_coordinate_status_of( (int) $en_id ), 'the ambiguous run wrote nothing to the EN fixture' );

	// --- 3. Apply, then idempotence. ----------------------------------------
	$apply = lcr_run( $path, true );
	$sum   = lcr_summary( $apply['lines'] );

	assert_true( 0 === $apply['exit'], 'the apply exits 0' );
	assert_equals( '2', $sum['written'] ?? '', 'the apply wrote both fixtures' );
	assert_equals( '0', $sum['conflicts'] ?? '', 'no conflict was reported' );

	// The apply ran in a separate process, so this process's meta cache is stale.
	clean_post_cache( (int) $pt_id );
	clean_post_cache( (int) $en_id );

	assert_equals( '53.100001', (string) get_post_meta( $pt_id, Conexao_Data_Model_Meta::LEISURE_LAT_KEY, true ), 'the PT fixture holds its own latitude' );
	assert_equals( '53.200002', (string) get_post_meta( $en_id, Conexao_Data_Model_Meta::LEISURE_LAT_KEY, true ), 'the EN fixture holds its own latitude (not the PT value)' );

	$second      = lcr_run( $path, true );
	$second_sum  = lcr_summary( $second['lines'] );
	assert_true( 0 === $second['exit'], 'the second apply exits 0' );
	assert_equals( '0', $second_sum['written'] ?? '', 'the second apply writes nothing (idempotent)' );
	assert_equals( '2', $second_sum['unchanged'] ?? '', 'the second apply reports both records unchanged' );
	// --- 4. Identity invariants are untouched. ------------------------------
	assert_equals( $shared_uuid, (string) get_post_meta( $pt_id, Conexao_Lazer_Exporter::UUID_META_KEY, true ), 'the PT fixture UUID is unchanged' );
	assert_equals( $shared_uuid, (string) get_post_meta( $en_id, Conexao_Lazer_Exporter::UUID_META_KEY, true ), 'the EN fixture UUID is unchanged (still shared, never regenerated)' );

	$translations = pll_get_post_translations( (int) $pt_id );
	assert_true( isset( $translations['pt'], $translations['en'] ) && (int) $translations['pt'] === (int) $pt_id && (int) $translations['en'] === (int) $en_id, 'the PT/EN translation relationship is unchanged' );

	assert_true( '' === (string) get_post_meta( $pt_id, Conexao_Data_Model_Meta::LEISURE_COORD_VERIFIED_KEY, true ), 'the PT fixture verification flag was not set' );
	assert_true( '' === (string) get_post_meta( $en_id, Conexao_Data_Model_Meta::LEISURE_COORD_VERIFIED_KEY, true ), 'the EN fixture verification flag was not set' );
} finally {
	foreach ( array_unique( $created ) as $post_id ) {
		wp_delete_post( (int) $post_id, true );
	}
	foreach ( $manifests as $manifest ) {
		if ( is_file( $manifest ) ) {
			unlink( $manifest );
		}
	}
}

test_finish();
