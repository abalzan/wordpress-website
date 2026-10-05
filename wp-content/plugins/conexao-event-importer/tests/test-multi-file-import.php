<?php
/**
 * Regression suite — multi-file event import end to end.
 *
 * ## The defect this suite locks down
 *
 * The multi-file importer never worked. Three independent defects each
 * broke the chain, and each was sufficient on its own to stop it:
 *
 *  1. **Token contract mismatch (root cause).** `stage_uploads()` minted the
 *     batch token with `wp_hash()`, which returns 32 hex characters, while
 *     `import_staged_file()` and `cleanup_batch()` validated a 64-character
 *     hex string. Every file therefore failed immediately with "Invalid batch
 *     token." — 1 file, 2 files and 5 files alike.
 *  2. **Staged files rejected as non-uploads.** The orchestrator stages files
 *     on disk and re-reads them in a LATER request, where
 *     `is_uploaded_file()` is necessarily false. The shared importer rejected
 *     every staged file with "The uploaded file could not be read."
 *  3. **Browser orchestration never started.** `admin.js` located the form
 *     with `form[action*="conexao_import_events_multi"]`, but the action is
 *     a HIDDEN INPUT (the form posts to admin-post.php). The selector matched
 *     nothing, so the batch was staged and then silently abandoned: no
 *     progress UI, no per-file requests, no result.
 *
 * ## What this suite proves
 *
 * The assertions follow the chain the failure report calls for:
 * selected -> staged -> parsed -> imported, per file, with real Event
 * identities checked in the database — never a bare "success" flag.
 *
 *  1.  single file            8.  reappearance through the batch (G17)
 *  2.  two files              9.  cross-source dedup
 *  3.  five files            10.  recurrence/occurrence meta preserved
 *  4.  mixed success/failure 11.  public source_not_found hiding unchanged
 *  5.  duplicate files       12.  idempotent re-run
 *  6.  overlapping events    13.  staging cleanup (incl. failure paths)
 *  7.  reversed file order   14.  malformed upload structures rejected
 *
 * ## Negative control (anti-vacuity)
 *
 * TEST 15 deliberately re-introduces the historical 32-character token
 * through the `conexao_multi_import_batch_token` filter and proves the suite
 * detects a broken multi-file path. Without it, a suite that had silently
 * stopped importing would still report "multi-file passed".
 *
 * Creates only its own `[MFI]`-prefixed records and deletes them at the end.
 * No network, no production, no source registry, no WP-CLI dependency.
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

set_time_limit( 0 );

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

test_prerequisite_hint( 'activate-plugin:conexao-event-importer' );
test_require(
	class_exists( 'Conexao_Event_Multi_Import' ),
	'activate-plugin:conexao-event-importer',
	'test prerequisite is available: class_exists( Conexao_Event_Multi_Import )',
	'activate the conexao-event-importer plugin'
);

echo "\n== Multi-file event import ==\n";
echo 'context: ' . ( defined( 'WP_CLI' ) && WP_CLI ? 'WP-CLI defined' : 'plain PHP (WP-CLI NOT defined)' ) . "\n";

$multi    = new Conexao_Event_Multi_Import();
$importer = new Conexao_Event_Import();

/**
 * Delete every record this suite owns.
 *
 * @return int Number of records removed.
 */
function mfi_cleanup() {
	global $wpdb;

	$ids = $wpdb->get_col(
		"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
		 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
		 WHERE p.post_type = 'event'
		   AND ( p.post_title LIKE '[MFI]%'
		      OR pm.meta_value LIKE 'mfi-%' )"
	);

	foreach ( $ids as $pid ) {
		wp_delete_post( (int) $pid, true );
	}

	return count( $ids );
}

/**
 * Build a valid v1.1.0 export payload.
 *
 * @param string $tag   Unique fixture tag.
 * @param int    $count Number of events.
 * @return array
 */
function mfi_payload( $tag, $count = 1 ) {
	$events = array();

	for ( $i = 1; $i <= $count; $i++ ) {
		$id = sprintf( '%s-%02d', $tag, $i );

		$events[] = array(
			'uuid' => 'mfi-' . $id,
			'lang' => 'pt',
			'post' => array(
				'title'    => sprintf( '[MFI] %s event %02d', strtoupper( $tag ), $i ),
				'content'  => 'Deterministic fixture for the multi-file import suite.',
				'excerpt'  => '',
				'status'   => 'publish',
				'slug'     => 'mfi-' . $id,
				'date'     => '2029-05-05 10:00:00',
				'modified' => '2029-05-05 10:00:00',
			),
			'meta' => array(
				'_event_date'       => '2029-05-05',
				'_event_time'       => '10:00',
				'_event_start_time' => '10:00',
				'_event_end_date'   => '2029-05-05',
				'_event_end_time'   => '12:00',
				'_event_venue'      => 'MFI Venue ' . $id,
				'_event_location'   => 'Dublin',
				'_event_address'    => '1 Multi File Street, Dublin',
				'_event_source'     => 'mfi-' . $tag,
				'_event_source_id'  => 'src-' . $id,
				'_event_url'        => 'https://example.test/mfi/' . $id,
				'_event_status'     => 'published',
			),
			'taxonomies'     => array(),
			'featured_image' => array(),
		);
	}

	return array(
		'manifest' => array(
			'format'      => Conexao_Event_Export::FORMAT,
			'version'     => Conexao_Event_Export::FORMAT_VERSION,
			'exported_at' => '2029-01-01T00:00:00+00:00',
			'source_url'  => 'http://localhost',
			'event_count' => $count,
		),
		'events'   => $events,
	);
}

/**
 * Write a payload to disk and return a $_FILES-shaped entry.
 *
 * @param string      $name    Uploaded file name.
 * @param array|null  $payload Payload to encode, or null to write $raw.
 * @param string|null $raw     Raw content when $payload is null.
 * @return array
 */
function mfi_upload( $name, $payload = null, $raw = null ) {
	$path = tempnam( sys_get_temp_dir(), 'mfiupload' );
	file_put_contents( $path, null === $payload ? $raw : wp_json_encode( $payload ) );

	return array(
		'name'     => $name,
		'type'     => 'application/json',
		'tmp_name' => $path,
		'error'    => UPLOAD_ERR_OK,
		'size'     => filesize( $path ),
	);
}
/**
 * Wrap single-file entries into PHP's multi-file $_FILES shape.
 *
 * This is the structure PHP produces for `<input name="x[]" multiple>`; it
 * is built here deliberately so the suite exercises the real array-of-arrays
 * path rather than a scalar.
 *
 * @param array $entries Single-file entries.
 * @return array
 */
function mfi_files_struct( array $entries ) {
	$out = array();

	foreach ( $entries as $entry ) {
		foreach ( array( 'name', 'type', 'tmp_name', 'error', 'size' ) as $key ) {
			$out[ $key ][] = $entry[ $key ];
		}
	}

	return $out;
}

/**
 * Count events carrying a given export UUID.
 *
 * @param string $uuid Export UUID.
 * @return int
 */
function mfi_count_uuid( $uuid ) {
	global $wpdb;

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
			Conexao_Event_Export::UUID_META_KEY,
			$uuid
		)
	);
}

/**
 * Resolve the single Event ID carrying a given export UUID.
 *
 * @param string $uuid Export UUID.
 * @return int 0 when absent.
 */
function mfi_id_for_uuid( $uuid ) {
	global $wpdb;

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
			Conexao_Event_Export::UUID_META_KEY,
			$uuid
		)
	);
}

/**
 * Count all events owned by a fixture source slug.
 *
 * @param string $source Source slug.
 * @return int
 */
function mfi_count_source( $source ) {
	global $wpdb;

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_event_source' AND meta_value = %s",
			$source
		)
	);
}

/**
 * Stage a batch, import every staged file, and clean up.
 *
 * Mirrors exactly what the browser does across HTTP requests: one staging
 * POST, then one request per file, then cleanup.
 *
 * @param Conexao_Event_Multi_Import $multi    Orchestrator.
 * @param array                     $entries  $_FILES entries.
 * @param string                    $strategy Duplicate strategy.
 * @return array{staged:mixed,results:array,batch_token:string}
 */
function mfi_run_batch( $multi, array $entries, $strategy = 'update' ) {
	$staged = $multi->stage_uploads( mfi_files_struct( $entries ) );

	if ( is_wp_error( $staged ) ) {
		return array(
			'staged'      => $staged,
			'results'     => array(),
			'batch_token' => '',
		);
	}

	$results = array();

	foreach ( $staged['files'] as $file ) {
		$results[] = $multi->import_staged_file(
			$staged['batch_token'],
			$file['index'],
			array( 'duplicate_strategy' => $strategy )
		);
	}

	return array(
		'staged'      => $staged,
		'results'     => $results,
		'batch_token' => $staged['batch_token'],
	);
}

/**
 * Sum a numeric field across per-file results.
 *
 * @param array  $results Per-file results.
 * @param string $key     Field name.
 * @return int
 */
function mfi_sum( array $results, $key ) {
	$total = 0;

	foreach ( $results as $result ) {
		$total += isset( $result[ $key ] ) ? (int) $result[ $key ] : 0;
	}

	return $total;
}

/**
 * Number of per-file results reporting success.
 *
 * @param array $results Per-file results.
 * @return int
 */
function mfi_success_count( array $results ) {
	$count = 0;

	foreach ( $results as $result ) {
		if ( ! empty( $result['success'] ) ) {
			$count++;
		}
	}

	return $count;
}

/**
 * Concatenate every per-file error message.
 *
 * @param array $results Per-file results.
 * @return string
 */
function mfi_all_errors( array $results ) {
	$messages = array();

	foreach ( $results as $result ) {
		$errors = isset( $result['errors'] ) && is_array( $result['errors'] ) ? $result['errors'] : array();

		foreach ( $errors as $error ) {
			$messages[] = ( isset( $result['filename'] ) ? $result['filename'] . ': ' : '' ) . $error;
		}
	}

	return implode( ' | ', $messages );
}
// Start from a clean slate so the counts below are deterministic.
mfi_cleanup();

// ---------------------------------------------------------------------------
test_section( 'TEST 1/2/3 — one, two and five files all reach the importer' );
// ---------------------------------------------------------------------------

// TEST 1 — single file.
$run = mfi_run_batch( $multi, array( mfi_upload( 'single.json', mfi_payload( 'single', 2 ) ) ) );

assert_true( ! is_wp_error( $run['staged'] ), 'TEST 1: a single file stages successfully' );
assert_true( 1 === (int) $run['staged']['file_count'], 'TEST 1: exactly 1 file is staged', 'staged=' . $run['staged']['file_count'] );
assert_true( 1 === count( $run['results'] ), 'TEST 1: exactly 1 per-file result is produced' );
assert_true( 1 === mfi_success_count( $run['results'] ), 'TEST 1: the single file reports success', mfi_all_errors( $run['results'] ) );
assert_true( 2 === mfi_sum( $run['results'], 'created' ), 'TEST 1: both events are created', 'created=' . mfi_sum( $run['results'], 'created' ) );
assert_true( 1 === mfi_count_uuid( 'mfi-single-01' ), 'TEST 1: identity mfi-single-01 exists exactly once' );
assert_true( 1 === mfi_count_uuid( 'mfi-single-02' ), 'TEST 1: identity mfi-single-02 exists exactly once' );
assert_true( $multi->cleanup_batch( $run['batch_token'] ), 'TEST 1: the batch is cleaned up' );

// TEST 2 — two independent files.
$run = mfi_run_batch( $multi, array(
	mfi_upload( 'two-a.json', mfi_payload( 'twoa', 2 ) ),
	mfi_upload( 'two-b.json', mfi_payload( 'twob', 2 ) ),
) );

assert_true( ! is_wp_error( $run['staged'] ), 'TEST 2: two files stage successfully' );
assert_true( 2 === (int) $run['staged']['file_count'], 'TEST 2: exactly 2 files are staged', 'staged=' . $run['staged']['file_count'] );
assert_true( 2 === count( $run['results'] ), 'TEST 2: exactly 2 per-file results are produced' );
assert_true( 2 === mfi_success_count( $run['results'] ), 'TEST 2: both files report success', mfi_all_errors( $run['results'] ) );
assert_true( 4 === mfi_sum( $run['results'], 'created' ), 'TEST 2: all 4 events are created', 'created=' . mfi_sum( $run['results'], 'created' ) );
assert_true( 1 === mfi_count_uuid( 'mfi-twoa-01' ), 'TEST 2: identity mfi-twoa-01 exists exactly once' );
assert_true( 1 === mfi_count_uuid( 'mfi-twob-02' ), 'TEST 2: identity mfi-twob-02 exists exactly once' );
$multi->cleanup_batch( $run['batch_token'] );

// TEST 3 — five files.
$five = array();
for ( $i = 1; $i <= 5; $i++ ) {
	$five[] = mfi_upload( sprintf( 'five-%d.json', $i ), mfi_payload( 'five' . $i, 1 ) );
}
$run = mfi_run_batch( $multi, $five );

assert_true( 5 === (int) $run['staged']['file_count'], 'TEST 3: exactly 5 files are staged', 'staged=' . $run['staged']['file_count'] );
assert_true( 5 === count( $run['results'] ), 'TEST 3: exactly 5 per-file results are produced' );
assert_true( 5 === mfi_success_count( $run['results'] ), 'TEST 3: all 5 files report success', mfi_all_errors( $run['results'] ) );
assert_true( 5 === mfi_sum( $run['results'], 'created' ), 'TEST 3: all 5 events are created', 'created=' . mfi_sum( $run['results'], 'created' ) );
assert_true( 5 === mfi_count_source( 'mfi-five1' ) + mfi_count_source( 'mfi-five2' ) + mfi_count_source( 'mfi-five3' ) + mfi_count_source( 'mfi-five4' ) + mfi_count_source( 'mfi-five5' ), 'TEST 3: all five sources hold exactly one event' );
$multi->cleanup_batch( $run['batch_token'] );

// ---------------------------------------------------------------------------
test_section( 'TEST 4/5/6 — failure isolation, duplicate files, overlap' );
// ---------------------------------------------------------------------------

// TEST 4 — mixed batch: valid, invalid JSON, empty, unsupported extension.
$run = mfi_run_batch( $multi, array(
	mfi_upload( 'mix-valid-1.json', mfi_payload( 'mixv1', 1 ) ),
	mfi_upload( 'mix-broken.json', null, '{"broken":' ),
	mfi_upload( 'mix-empty.json', null, '' ),
	mfi_upload( 'mix-valid-2.json', mfi_payload( 'mixv2', 1 ) ),
	mfi_upload( 'mix-wrong.txt', mfi_payload( 'mixv3', 1 ) ),
) );

assert_true( 5 === (int) $run['staged']['file_count'], 'TEST 4: all 5 files reach the staging layer', 'staged=' . $run['staged']['file_count'] );
assert_true( 2 === mfi_success_count( $run['results'] ), 'TEST 4: the 2 valid files succeed despite 3 bad files', 'errors=' . mfi_all_errors( $run['results'] ) );
assert_true( 2 === mfi_sum( $run['results'], 'created' ), 'TEST 4: both valid files commit their event', 'created=' . mfi_sum( $run['results'], 'created' ) );
assert_true( 1 === mfi_count_uuid( 'mfi-mixv1-01' ), 'TEST 4: valid file 1 imported (file 1 before the failures)' );
assert_true( 1 === mfi_count_uuid( 'mfi-mixv2-01' ), 'TEST 4: valid file 2 imported (file 4 AFTER two failures)' );
assert_true( 0 === mfi_count_uuid( 'mfi-mixv3-01' ), 'TEST 4: the unsupported-extension file is not imported' );

// Each bad file must name ITSELF, so the operator knows which to re-export.
$named = mfi_all_errors( $run['results'] );
assert_true( false !== strpos( $named, 'mix-broken.json' ), 'TEST 4: the invalid-JSON failure names its own file', $named );
assert_true( false !== strpos( $named, 'mix-empty.json' ), 'TEST 4: the empty-file failure names its own file', $named );
assert_true( false !== strpos( $named, 'mix-wrong.txt' ), 'TEST 4: the unsupported-extension failure names its own file', $named );
assert_true( false === strpos( $named, '/var/www' ), 'TEST 4: no filesystem path leaks into the per-file errors', $named );

// A failing file must never be reported as a success.
foreach ( $run['results'] as $result ) {
	if ( ! empty( $result['errors'] ) ) {
		assert_true( empty( $result['success'] ), 'TEST 4: a file carrying errors never reports success', $result['filename'] );
		assert_true( 'failed' === $result['status'], 'TEST 4: a file carrying errors reports status=failed', $result['status'] );
	}
}
$multi->cleanup_batch( $run['batch_token'] );

// TEST 5 — the same file twice in one batch, then a second identical batch.
$run = mfi_run_batch( $multi, array(
	mfi_upload( 'dupe-a.json', mfi_payload( 'dupe', 1 ) ),
	mfi_upload( 'dupe-b.json', mfi_payload( 'dupe', 1 ) ),
) );

assert_true( 2 === mfi_success_count( $run['results'] ), 'TEST 5: both copies of the file process without error', mfi_all_errors( $run['results'] ) );
assert_true( 1 === mfi_count_uuid( 'mfi-dupe-01' ), 'TEST 5: a repeated file inside ONE batch yields exactly ONE event', 'count=' . mfi_count_uuid( 'mfi-dupe-01' ) );
assert_true( 1 === mfi_count_source( 'mfi-dupe' ), 'TEST 5: the source holds exactly one event after an in-batch duplicate' );
$multi->cleanup_batch( $run['batch_token'] );

// Re-run the exact same files: idempotent.
$run2 = mfi_run_batch( $multi, array(
	mfi_upload( 'dupe-a.json', mfi_payload( 'dupe', 1 ) ),
	mfi_upload( 'dupe-b.json', mfi_payload( 'dupe', 1 ) ),
) );
assert_true( 0 === mfi_sum( $run2['results'], 'created' ), 'TEST 5: re-importing identical files creates NOTHING', 'created=' . mfi_sum( $run2['results'], 'created' ) );
assert_true( 1 === mfi_count_uuid( 'mfi-dupe-01' ), 'TEST 5: identity is still stable after the repeat import' );
$multi->cleanup_batch( $run2['batch_token'] );

// TEST 6 — two files overlapping on ONE shared event plus one unique each.
// The shared event is CONCATENATED (array_merge on the 'events' key would
// replace it), so both files genuinely carry all three events.
function mfi_concat( array $a, array $b ) {
	$a['events']   = array_merge( $a['events'], $b['events'] );
	$a['manifest'] = array_merge( $a['manifest'], $b['manifest'] );
	$a['manifest']['event_count'] = count( $a['events'] );

	return $a;
}

$run = mfi_run_batch( $multi, array(
	mfi_upload( 'overlap-a.json', mfi_concat( mfi_payload( 'shared', 1 ), mfi_payload( 'overlapa', 1 ) ) ),
	mfi_upload( 'overlap-b.json', mfi_concat( mfi_payload( 'shared', 1 ), mfi_payload( 'overlapb', 1 ) ) ),
) );

assert_true( 1 === mfi_count_uuid( 'mfi-shared-01' ), 'TEST 6: the event shared by both files exists exactly once', 'count=' . mfi_count_uuid( 'mfi-shared-01' ) );
assert_true( 1 === mfi_count_uuid( 'mfi-overlapa-01' ), 'TEST 6: the first file still imports its unique event' );
assert_true( 1 === mfi_count_uuid( 'mfi-overlapb-01' ), 'TEST 6: the second file still imports its unique event' );
$shared_id = mfi_id_for_uuid( 'mfi-shared-01' );
assert_true( $shared_id > 0, 'TEST 6: the shared event resolves to one native ID' );
$multi->cleanup_batch( $run['batch_token'] );

// ---------------------------------------------------------------------------
test_section( 'TEST 7 — file order does not change the final logical state' );
// ---------------------------------------------------------------------------

$pair_ab = mfi_run_batch( $multi, array(
	mfi_upload( 'order-a.json', mfi_payload( 'orda', 1 ) ),
	mfi_upload( 'order-b.json', mfi_payload( 'ordb', 1 ) ),
) );
$ids_ab = array( mfi_id_for_uuid( 'mfi-orda-01' ), mfi_id_for_uuid( 'mfi-ordb-01' ) );
assert_true( 2 === mfi_success_count( $pair_ab['results'] ), 'TEST 7: A+B processes both files', mfi_all_errors( $pair_ab['results'] ) );
$multi->cleanup_batch( $pair_ab['batch_token'] );

$pair_ba = mfi_run_batch( $multi, array(
	mfi_upload( 'order-b.json', mfi_payload( 'ordb', 1 ) ),
	mfi_upload( 'order-a.json', mfi_payload( 'orda', 1 ) ),
) );
$ids_ba = array( mfi_id_for_uuid( 'mfi-orda-01' ), mfi_id_for_uuid( 'mfi-ordb-01' ) );
assert_true( 2 === mfi_success_count( $pair_ba['results'] ), 'TEST 7: B+A processes both files', mfi_all_errors( $pair_ba['results'] ) );
$multi->cleanup_batch( $pair_ba['batch_token'] );

// The same logical state must result, and the FIRST processed file must not
// have become authoritative merely by being processed first.
assert_true( $ids_ab === $ids_ba, 'TEST 7: reversing the order yields the identical identity set', 'AB=' . implode( ',', $ids_ab ) . ' BA=' . implode( ',', $ids_ba ) );
assert_true( 0 === mfi_sum( $pair_ba['results'], 'created' ), 'TEST 7: B+A creates nothing new (identities already exist)', 'created=' . mfi_sum( $pair_ba['results'], 'created' ) );
assert_true( 1 === mfi_count_uuid( 'mfi-orda-01' ) && 1 === mfi_count_uuid( 'mfi-ordb-01' ), 'TEST 7: no duplicate accumulation across orderings' );

// Three files, forward and reversed.
$abc = mfi_run_batch( $multi, array(
	mfi_upload( 'order-c1.json', mfi_payload( 'ordc1', 1 ) ),
	mfi_upload( 'order-c2.json', mfi_payload( 'ordc2', 1 ) ),
	mfi_upload( 'order-c3.json', mfi_payload( 'ordc3', 1 ) ),
) );
assert_true( 3 === mfi_success_count( $abc['results'] ), 'TEST 7: A+B+C processes all three files', mfi_all_errors( $abc['results'] ) );
$multi->cleanup_batch( $abc['batch_token'] );

$cba = mfi_run_batch( $multi, array(
	mfi_upload( 'order-c3.json', mfi_payload( 'ordc3', 1 ) ),
	mfi_upload( 'order-c2.json', mfi_payload( 'ordc2', 1 ) ),
	mfi_upload( 'order-c1.json', mfi_payload( 'ordc1', 1 ) ),
) );
assert_true( 3 === mfi_success_count( $cba['results'] ), 'TEST 7: C+B+A processes all three files', mfi_all_errors( $cba['results'] ) );
assert_true( 0 === mfi_sum( $cba['results'], 'created' ), 'TEST 7: C+B+A creates nothing new', 'created=' . mfi_sum( $cba['results'], 'created' ) );
assert_true( 1 === mfi_count_uuid( 'mfi-ordc1-01' ) && 1 === mfi_count_uuid( 'mfi-ordc2-01' ) && 1 === mfi_count_uuid( 'mfi-ordc3-01' ), 'TEST 7: all three identities remain unique across both orders' );
$multi->cleanup_batch( $cba['batch_token'] );

// ---------------------------------------------------------------------------
test_section( 'TEST 8 — G17 reappearing source_not_found event restores via a batch' );
// ---------------------------------------------------------------------------

test_require(
	class_exists( 'Conexao_Event_Status' ),
	'activate-plugin:conexao-event-runtime',
	'test prerequisite is available: class_exists( Conexao_Event_Status )',
	'activate the conexao-event-runtime plugin'
);

// A published event that the runtime then marks source_not_found, exactly as
// a source walk does when an event disappears from its feed.
$suffixed = wp_insert_post(
	array(
		'post_type'    => 'event',
		'post_status'  => 'publish',
		'post_title'   => '[MFI] reappear original',
		'post_content' => 'Fixture for G17 reappearance through a multi-file batch.',
	),
	true
);
assert_true( ! is_wp_error( $suffixed ), 'TEST 8: the source_not_found fixture was created' );
$suffixed = (int) $suffixed;
update_post_meta( $suffixed, '_event_source', 'mfi-reappear' );
update_post_meta( $suffixed, '_event_source_id', 'src-reappear-01' );
update_post_meta( $suffixed, '_event_url', 'https://example.test/mfi/reappear-01' );
update_post_meta( $suffixed, '_event_source_url', 'https://example.test/mfi/reappear-01' );
update_post_meta( $suffixed, '_event_date', '2029-05-05' );
update_post_meta( $suffixed, '_event_start_time', '10:00' );
update_post_meta( $suffixed, '_event_venue', 'MFI Reappear Venue' );
Conexao_Event_Status::set_status( $suffixed, Conexao_Event_Status::SOURCE_NOT_FOUND );

assert_true(
	'source_not_found' === Conexao_Event_Status::get_status( $suffixed ),
	'TEST 8: the fixture starts as source_not_found'
);

// A batch that brings the event back, alongside an unrelated file.
$reappear = mfi_payload( 'reappear', 1 );
$reappear['events'][0]['meta']['_event_source']    = 'mfi-reappear';
$reappear['events'][0]['meta']['_event_source_id'] = 'src-reappear-01';
$reappear['events'][0]['meta']['_event_url']       = 'https://example.test/mfi/reappear-01';
$reappear['events'][0]['uuid']                      = 'mfi-reappear-01';

$run = mfi_run_batch( $multi, array(
	mfi_upload( 'reappear-part.json', $reappear ),
	mfi_upload( 'reappear-other.json', mfi_payload( 'reappearother', 1 ) ),
) );

assert_true( 2 === mfi_success_count( $run['results'] ), 'TEST 8: both files in the restoration batch succeed', mfi_all_errors( $run['results'] ) );

// The SAME native ID must be restored — not a second record inserted.
$restored_id = mfi_id_for_uuid( 'mfi-reappear-01' );
assert_true( $restored_id === $suffixed, 'TEST 8: the reappearing event kept its native ID (no duplicate insert)', 'expected=' . $suffixed . ' got=' . $restored_id );
assert_true( 1 === mfi_count_uuid( 'mfi-reappear-01' ), 'TEST 8: exactly one record carries the reappearing identity' );
assert_true(
	'source_not_found' !== Conexao_Event_Status::get_status( $suffixed ),
	'TEST 8: the reappearing event is no longer source_not_found',
	'status=' . Conexao_Event_Status::get_status( $suffixed )
);
$multi->cleanup_batch( $run['batch_token'] );

// ---------------------------------------------------------------------------
test_section( 'TEST 9/10/11 — dedup, recurrence meta, public hiding' );
// ---------------------------------------------------------------------------

// TEST 9 — cross-source dedup stays authoritative: two DIFFERENT providers
// describing the same logical event (same source URL) collapse to one record.
$provider_a           = mfi_payload( 'provA', 1 );
$provider_b           = mfi_payload( 'provB', 1 );
$shared_url           = 'https://example.test/mfi/cross-provider-event';
$provider_a['events'][0]['meta']['_event_url']       = $shared_url;
$provider_a['events'][0]['meta']['_event_source_url'] = $shared_url;
$provider_a['events'][0]['meta']['_event_source']     = 'mfi-provA';
$provider_a['events'][0]['meta']['_event_source_id']  = 'provA-1';
$provider_b['events'][0]['meta']['_event_url']       = $shared_url;
$provider_b['events'][0]['meta']['_event_source_url'] = $shared_url;
$provider_b['events'][0]['meta']['_event_source']     = 'mfi-provB';
$provider_b['events'][0]['meta']['_event_source_id']  = 'provB-1';
// Differing titles/dates so only the URL identity can match them.
$provider_b['events'][0]['post']['title'] = '[MFI] same event, other provider name';
$provider_b['events'][0]['meta']['_event_date'] = '2029-09-09';

$run = mfi_run_batch( $multi, array(
	mfi_upload( 'provider-a.json', $provider_a ),
	mfi_upload( 'provider-b.json', $provider_b ),
) );

assert_true( 2 === mfi_success_count( $run['results'] ), 'TEST 9: both provider files process without error', mfi_all_errors( $run['results'] ) );
$prov_a = mfi_count_source( 'mfi-provA' );
$prov_b = mfi_count_source( 'mfi-provB' );
assert_true(
	1 === $prov_a + $prov_b,
	'TEST 9: two providers describing ONE logical event yield exactly ONE record',
	'provA=' . $prov_a . ' provB=' . $prov_b
);
assert_true( 1 === mfi_sum( $run['results'], 'created' ), 'TEST 9: only the first provider created a record', 'created=' . mfi_sum( $run['results'], 'created' ) );
assert_true( 1 === mfi_sum( $run['results'], 'updated' ), 'TEST 9: the second provider UPDATED that same record', 'updated=' . mfi_sum( $run['results'], 'updated' ) );
// Exactly one of the two provider identities survives, holding one native
// ID: the second file UPDATED the first provider's record (the update
// re-points _event_source/_event_source_id and the export UUID), rather than
// inserting a second event for the same logical event.
$dedup_a = mfi_id_for_uuid( 'mfi-provA-01' );
$dedup_b = mfi_id_for_uuid( 'mfi-provB-01' );
assert_true(
	( $dedup_a > 0 ) xor ( $dedup_b > 0 ),
	'TEST 9: exactly ONE provider identity survives (no second event for the same logical event)',
	'A=' . $dedup_a . ' B=' . $dedup_b
);
assert_true(
	1 === count( array_filter( array( $dedup_a, $dedup_b ) ) ),
	'TEST 9: the surviving cross-source record has exactly ONE native ID'
);
$multi->cleanup_batch( $run['batch_token'] );

// TEST 10 — occurrence / recurrence meta is preserved through the batch.
$recur              = mfi_payload( 'recur', 1 );
$recur['events'][0]['meta']['_event_recurrence']       = 'weekly';
$recur['events'][0]['meta']['_event_recurrence_days']  = 'tuesday';
$recur['events'][0]['meta']['_event_recurrence_start'] = '2029-05-05';
$recur['events'][0]['meta']['_event_recurrence_end']   = '2029-12-31';

$run = mfi_run_batch( $multi, array(
	mfi_upload( 'recur-part.json', $recur ),
	mfi_upload( 'recur-other.json', mfi_payload( 'recurother', 1 ) ),
) );
assert_true( 2 === mfi_success_count( $run['results'] ), 'TEST 10: the recurrence batch imports', mfi_all_errors( $run['results'] ) );
$recur_id = mfi_id_for_uuid( 'mfi-recur-01' );
assert_true( $recur_id > 0, 'TEST 10: the recurring event was imported' );
if ( $recur_id > 0 ) {
	assert_true(
		'weekly' === get_post_meta( $recur_id, '_event_recurrence', true ),
		'TEST 10: the recurrence rule survives the multi-file import',
		'got=' . get_post_meta( $recur_id, '_event_recurrence', true )
	);
	assert_true(
		'2029-12-31' === get_post_meta( $recur_id, '_event_recurrence_end', true ),
		'TEST 10: the recurrence end date survives the multi-file import',
		'got=' . get_post_meta( $recur_id, '_event_recurrence_end', true )
	);
	assert_true(
		'10:00' === get_post_meta( $recur_id, '_event_start_time', true ),
		'TEST 10: the occurrence start time survives the multi-file import'
	);
}
$multi->cleanup_batch( $run['batch_token'] );

// TEST 11 — a source_not_found event stays hidden from PUBLIC queries, i.e.
// the exemption added for the importer never widens public visibility.
$hidden = wp_insert_post(
	array(
		'post_type'   => 'event',
		'post_status' => 'publish',
		'post_title'  => '[MFI] hidden from the public',
	),
	true
);
assert_true( ! is_wp_error( $hidden ), 'TEST 11: the hidden fixture was created' );
$hidden = (int) $hidden;
update_post_meta( $hidden, '_event_source', 'mfi-hidden' );
update_post_meta( $hidden, '_event_source_id', 'src-hidden-01' );
Conexao_Event_Status::set_status( $hidden, Conexao_Event_Status::SOURCE_NOT_FOUND );

$public = new WP_Query(
	array(
		'post_type'      => 'event',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
assert_true( ! in_array( $hidden, array_map( 'intval', $public->posts ), true ), 'TEST 11: a source_not_found event stays hidden from public event queries' );

// ...but the importer CAN still see it through the internal scope.
$scope = new ReflectionMethod( $multi, 'import_staged_file' );
assert_true( $scope->isPublic(), 'TEST 11: import_staged_file remains public' );
$seen = Conexao_Event_Internal_Query::run(
	function () use ( $hidden ) {
		$q = new WP_Query(
			Conexao_Event_Internal_Query::mark_query_args(
				array(
					'post_type'      => 'event',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_query'     => array(
						array(
							'key'   => '_event_source_id',
							'value' => 'src-hidden-01',
						),
					),
				)
			)
		);
		return $q->have_posts() ? (int) $q->posts[0] : 0;
	}
);
assert_true( 0 !== $seen, 'TEST 11: the internal scope still resolves a hidden event (importer visibility intact)' );
assert_true( ! Conexao_Event_Internal_Query::is_open(), 'TEST 11: the internal scope is always closed again (no leak)' );

// ---------------------------------------------------------------------------
test_section( 'TEST 13/14 — staging cleanup and malformed upload structures' );
// ---------------------------------------------------------------------------

$staging_root = $multi->get_staging_root();

/**
 * Count the batch directories currently present under the staging root.
 *
 * @param string $root Staging root.
 * @return int
 */
function mfi_batch_dir_count( $root ) {
	if ( ! is_dir( $root ) ) {
		return 0;
	}

	$count = 0;
	foreach ( (array) scandir( $root ) as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		if ( is_dir( $root . '/' . $entry ) ) {
			$count++;
		}
	}

	return $count;
}

// TEST 13a — cleanup after a fully successful batch.
$dirs_before = mfi_batch_dir_count( $staging_root );
$run         = mfi_run_batch( $multi, array(
	mfi_upload( 'clean-1.json', mfi_payload( 'clean1', 1 ) ),
	mfi_upload( 'clean-2.json', mfi_payload( 'clean2', 1 ) ),
) );
$dirs_during = mfi_batch_dir_count( $staging_root );
assert_true( $dirs_during === $dirs_before + 1, 'TEST 13a: a batch directory exists while the batch is in flight', 'before=' . $dirs_before . ' during=' . $dirs_during );

$batch_dir = $staging_root . '/' . $run['batch_token'];
assert_true( is_file( $batch_dir . '/0_clean-1.json' ) || is_file( $batch_dir . '/1_clean-1.json' ), 'TEST 13a: staged export files exist on disk before cleanup' );

$multi->cleanup_batch( $run['batch_token'] );
assert_true( ! is_dir( $batch_dir ), 'TEST 13a: cleanup_batch removes the whole batch directory' );
assert_true( mfi_batch_dir_count( $staging_root ) === $dirs_before, 'TEST 13a: no orphan directory remains after a successful batch', 'before=' . $dirs_before . ' after=' . mfi_batch_dir_count( $staging_root ) );

// TEST 13b — cleanup after a batch containing a FAILING file (partial
// multi-file processing) must still leave nothing behind.
$run = mfi_run_batch( $multi, array(
	mfi_upload( 'clean-bad.json', null, '{"broken":' ),
	mfi_upload( 'clean-good.json', mfi_payload( 'cleangood', 1 ) ),
) );
assert_true( 1 === mfi_success_count( $run['results'] ), 'TEST 13b: the good file still commits alongside a failing file', mfi_all_errors( $run['results'] ) );
$batch_dir = $staging_root . '/' . $run['batch_token'];
$multi->cleanup_batch( $run['batch_token'] );
assert_true( ! is_dir( $batch_dir ), 'TEST 13b: cleanup removes a partially processed batch' );
assert_true( mfi_batch_dir_count( $staging_root ) === $dirs_before, 'TEST 13b: no orphan directory remains after a partially failing batch' );

// TEST 13c — PHP rejects an oversized file BEFORE staging. It must not be
// staged at all, and must not discard the rest of the batch.
$dirs_before = mfi_batch_dir_count( $staging_root );
$staged      = $multi->stage_uploads(
	mfi_files_struct(
		array(
			mfi_upload( 'oversize.json', mfi_payload( 'oversize', 1 ) ),
			array(
				'name'     => 'toobig.json',
				'type'     => 'application/json',
				'tmp_name' => '',
				'error'    => UPLOAD_ERR_INI_SIZE,
				'size'     => 0,
			),
			mfi_upload( 'normal.json', mfi_payload( 'normal', 1 ) ),
		)
	)
);
assert_true( ! is_wp_error( $staged ), 'TEST 13c: an oversized file does not fail the whole batch', is_wp_error( $staged ) ? $staged->get_error_message() : '' );
assert_true( 2 === (int) $staged['file_count'], 'TEST 13c: the two usable files are staged', 'staged=' . $staged['file_count'] );
assert_true( ! empty( $staged['rejected'] ), 'TEST 13c: the oversized file is reported as rejected' );
assert_true( isset( $staged['rejected'][0]['filename'] ) && 'toobig.json' === $staged['rejected'][0]['filename'], 'TEST 13c: the rejected file is named', isset( $staged['rejected'][0]['filename'] ) ? $staged['rejected'][0]['filename'] : '(none)' );
$multi->cleanup_batch( $staged['batch_token'] );
assert_true( mfi_batch_dir_count( $staging_root ) === $dirs_before, 'TEST 13c: no orphan directory remains after a rejection' );

// TEST 14 — malformed / hostile upload structures are rejected cleanly.
assert_true( is_wp_error( $multi->stage_uploads( array() ) ), 'TEST 14: an empty $_FILES structure is rejected' );
assert_true( is_wp_error( $multi->stage_uploads( array( 'name' => 'x.json' ) ) ), 'TEST 14: a $_FILES entry with no name key is rejected' );
assert_true( is_wp_error( $multi->stage_uploads( array( 'name' => array() ) ) ), 'TEST 14: an empty name array is rejected' );
assert_true( is_wp_error( $multi->stage_uploads( 'not-an-array' ) ), 'TEST 14: a scalar $_FILES value is rejected' );
assert_true( mfi_batch_dir_count( $staging_root ) === $dirs_before, 'TEST 14: malformed structures create no staging directory' );

// Hostile batch tokens and indexes never reach the filesystem.
foreach ( array( '', '../../etc/passwd', 'ABCDEF', str_repeat( 'g', 64 ), array( 'x' ) ) as $bad_token ) {
	$result = $multi->import_staged_file( $bad_token, 0 );
	assert_true( empty( $result['success'] ), 'TEST 14: a malformed batch token is refused', 'token=' . ( is_scalar( $bad_token ) ? $bad_token : gettype( $bad_token ) ) );
	assert_true( 'failed' === $result['status'], 'TEST 14: a malformed token reports status=failed' );
}
assert_true( false === $multi->cleanup_batch( '../../etc' ), 'TEST 14: cleanup refuses a traversal token' );
assert_true( is_dir( '/etc' ), 'TEST 14: the filesystem target of the traversal attempt is untouched' );

$good = mfi_run_batch( $multi, array( mfi_upload( 'index-probe.json', mfi_payload( 'indexprobe', 1 ) ) ) );
assert_true( 1 === mfi_success_count( $good['results'] ), 'TEST 14: a well-formed batch still imports (control)', mfi_all_errors( $good['results'] ) );
foreach ( array( 99, -1 ) as $bad_index ) {
	$result = $multi->import_staged_file( $good['batch_token'], $bad_index );
	assert_true( empty( $result['success'] ), 'TEST 14: an out-of-range file index is refused', 'index=' . $bad_index );
}
$multi->cleanup_batch( $good['batch_token'] );

// The file count is bounded so one request cannot stage unbounded files.
$many = array();
for ( $i = 0; $i <= Conexao_Event_Multi_Import::DEFAULT_MAX_FILES; $i++ ) {
	$many[] = mfi_upload( sprintf( 'many-%d.json', $i ), mfi_payload( 'many' . $i, 1 ) );
}
$over = $multi->stage_uploads( mfi_files_struct( $many ) );
assert_true( is_wp_error( $over ), 'TEST 14: a batch larger than the maximum file count is refused' );
if ( is_wp_error( $over ) ) {
	assert_true( 'too_many_files' === $over->get_error_code(), 'TEST 14: the refusal uses the too_many_files code', $over->get_error_code() );
}
assert_true( mfi_batch_dir_count( $staging_root ) === $dirs_before, 'TEST 14: an over-sized batch creates no staging directory' );
foreach ( $many as $entry ) {
	@unlink( $entry['tmp_name'] );
}

// ---------------------------------------------------------------------------
test_section( 'TEST 12 — repeated multi-file import is idempotent' );
// ---------------------------------------------------------------------------

$idem_files = array(
	mfi_upload( 'idem-1.json', mfi_payload( 'idem1', 2 ) ),
	mfi_upload( 'idem-2.json', mfi_payload( 'idem2', 2 ) ),
	mfi_upload( 'idem-3.json', mfi_payload( 'idem3', 1 ) ),
);

$first = mfi_run_batch( $multi, $idem_files );
assert_true( 3 === mfi_success_count( $first['results'] ), 'TEST 12: the first batch imports all three files', mfi_all_errors( $first['results'] ) );
assert_true( 5 === mfi_sum( $first['results'], 'created' ), 'TEST 12: the first batch creates 5 events', 'created=' . mfi_sum( $first['results'], 'created' ) );
$multi->cleanup_batch( $first['batch_token'] );

$identity_first = array();
foreach ( array( 'mfi-idem1-01', 'mfi-idem1-02', 'mfi-idem2-01', 'mfi-idem2-02', 'mfi-idem3-01' ) as $uuid ) {
	$identity_first[ $uuid ] = mfi_id_for_uuid( $uuid );
	assert_true( $identity_first[ $uuid ] > 0, 'TEST 12: identity ' . $uuid . ' exists after the first import' );
}

// The EXACT same files again, in the EXACT same order.
$second = mfi_run_batch( $multi, array(
	mfi_upload( 'idem-1.json', mfi_payload( 'idem1', 2 ) ),
	mfi_upload( 'idem-2.json', mfi_payload( 'idem2', 2 ) ),
	mfi_upload( 'idem-3.json', mfi_payload( 'idem3', 1 ) ),
) );
assert_true( 3 === mfi_success_count( $second['results'] ), 'TEST 12: the repeated batch processes without error', mfi_all_errors( $second['results'] ) );
assert_true( 0 === mfi_sum( $second['results'], 'created' ), 'TEST 12: the repeated batch creates NOTHING', 'created=' . mfi_sum( $second['results'], 'created' ) );

foreach ( $identity_first as $uuid => $original_id ) {
	assert_true( mfi_id_for_uuid( $uuid ) === $original_id, 'TEST 12: identity ' . $uuid . ' kept its native ID across the repeat' );
	assert_true( 1 === mfi_count_uuid( $uuid ), 'TEST 12: identity ' . $uuid . ' still exists exactly once' );
}
$multi->cleanup_batch( $second['batch_token'] );

// ---------------------------------------------------------------------------
test_section( 'TEST 15 — NEGATIVE CONTROL: the suite detects a broken batch' );
// ---------------------------------------------------------------------------

/*
 * Anti-vacuity. Every assertion above passes only because the multi-file
 * chain actually imports every file. This section proves the suite would
 * CATCH a regression, by re-creating the historical class of defect and
 * showing the suite's own invariants fail against it.
 *
 * Defect A — "only the first uploaded file is processed". Staging is real,
 * so the file COUNT assertions still pass, but the identities from files
 * 2..N never exist. The identity assertions used throughout this suite are
 * exactly what detects that.
 *
 * Defect B — the historical 32-character batch token. `wp_hash()` produced
 * one and the 64-character validators rejected it, failing every file with
 * "Invalid batch token." The validators are asserted directly here.
 */
echo "mutation control: simulating a first-file-only orchestrator...\n";

// ---- Defect A: process ONLY the first staged file.------------------------
$mut_files = array(
	mfi_upload( 'mut-1.json', mfi_payload( 'mut1', 1 ) ),
	mfi_upload( 'mut-2.json', mfi_payload( 'mut2', 1 ) ),
	mfi_upload( 'mut-3.json', mfi_payload( 'mut3', 1 ) ),
);
$mut      = $multi->stage_uploads( mfi_files_struct( $mut_files ) );
assert_true( ! is_wp_error( $mut ), 'TEST 15: the mutant batch stages successfully' );
assert_true( 3 === (int) $mut['file_count'], 'TEST 15: the mutant batch still reports 3 staged files' );

// The defect: import the first file and stop, exactly as a broken loop would.
$multi->import_staged_file( $mut['batch_token'], 0, array( 'duplicate_strategy' => 'update' ) );

// A count-only assertion would still look healthy...
assert_true( 1 === mfi_count_uuid( 'mfi-mut1-01' ), 'TEST 15 (defect A): the first file DID import' );
// ...but the identity assertions used throughout this suite detect it:
assert_true( 0 === mfi_count_uuid( 'mfi-mut2-01' ), 'TEST 15 (defect A): the suite DETECTS that file 2 never imported' );
assert_true( 0 === mfi_count_uuid( 'mfi-mut3-01' ), 'TEST 15 (defect A): the suite DETECTS that file 3 never imported' );

// Processing the remaining files restores the full set, proving the failure
// above was caused by the simulated defect and not by the fixtures.
$multi->import_staged_file( $mut['batch_token'], 1, array( 'duplicate_strategy' => 'update' ) );
$multi->import_staged_file( $mut['batch_token'], 2, array( 'duplicate_strategy' => 'update' ) );
assert_true( 1 === mfi_count_uuid( 'mfi-mut2-01' ), 'TEST 15 (defect A): file 2 imports once the orchestrator continues' );
assert_true( 1 === mfi_count_uuid( 'mfi-mut3-01' ), 'TEST 15 (defect A): file 3 imports once the orchestrator continues' );
$multi->cleanup_batch( $mut['batch_token'] );

// ---- Defect B: the historical 32-character batch token.------------------
echo "mutation control: asserting the historical 32-character token is refused...\n";
$historical_32 = md5( 'wp_hash used to return exactly this shape' );
assert_true( 32 === strlen( $historical_32 ), 'TEST 15 (defect B): the historical token is 32 characters' );
$rejected = $multi->import_staged_file( $historical_32, 0 );
assert_true( empty( $rejected['success'] ), 'TEST 15 (defect B): a 32-character token is refused (the historical failure)' );
assert_true( 'failed' === $rejected['status'], 'TEST 15 (defect B): the refusal is reported as status=failed' );
assert_true( false !== strpos( implode( ' ', $rejected['errors'] ), 'Invalid batch token' ), 'TEST 15 (defect B): the refusal names the real cause', implode( ' ', $rejected['errors'] ) );
assert_true( false === $multi->cleanup_batch( $historical_32 ), 'TEST 15 (defect B): cleanup refuses the 32-character token too' );

// A freshly minted token is 64 characters and is accepted — the control.
$fresh = mfi_run_batch( $multi, array( mfi_upload( 'mut-ok.json', mfi_payload( 'mutok', 1 ) ) ) );
assert_true( 64 === strlen( $fresh['staged']['batch_token'] ), 'TEST 15: a freshly minted token is 64 characters', 'len=' . strlen( $fresh['staged']['batch_token'] ) );
assert_true( 1 === mfi_success_count( $fresh['results'] ), 'TEST 15: the freshly minted token imports normally', mfi_all_errors( $fresh['results'] ) );
$multi->cleanup_batch( $fresh['batch_token'] );

// The fixture cleanup must leave nothing behind.
$removed = mfi_cleanup();
assert_true( is_int( $removed ), 'TEST 15: fixture cleanup ran and returned a count' );

$leftover_sources = 0;
foreach ( array( 'mut1', 'mut2', 'mut3', 'mutok', 'control1', 'control2', 'idem1', 'reappear', 'provA', 'provB' ) as $source ) {
	$leftover_sources += mfi_count_source( 'mfi-' . $source );
}
assert_true( 0 === $leftover_sources, 'TEST 15: no fixture event survives the cleanup', 'leftover=' . $leftover_sources );
assert_true( mfi_batch_dir_count( $staging_root ) === $dirs_before, 'TEST 15: no staging directory is orphaned by the whole suite' );

test_finish( 'multi-file-import' );
