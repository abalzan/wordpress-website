<?php
/**
 * Stage 2 — Event identity gate (Polylang translation linking).
 *
 * HARD GATE. Proves that a linked English translation of an imported event can
 * never become a competing import target for the same production source
 * identity, and that the importer still resolves the correct underlying record.
 *
 * What is exercised (real code, no mocks):
 *   1. The importer creates the Portuguese event (its own upsert path).
 *   2. Re-running the importer on the same source identity resolves the same
 *      record and reports "unchanged" (identity is stable).
 *   3. An English translation is created and linked with Polylang, carrying the
 *      same language-neutral identity meta as an editor/importer would produce.
 *   4. `Conexao_Event_Deduplicator::find()` still resolves the PORTUGUESE
 *      record — the naive "newest first" lookup is asserted too, to document
 *      the failure mode the language guard prevents.
 *   5. The importer refuses to write to the translation (skip + log) instead of
 *      overwriting it and instead of creating a third record for the identity.
 *   6. Identity meta is identical on both records; the translation link stays
 *      intact; no duplicate identity row exists in the import language.
 *
 * Fixtures are prefixed "[STAGE2-ID]" and deleted at the end.
 *
 * Usage (from the project root):
 *   docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/test-language-identity.php
 */

$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';

// Polylang resolves the request language from the request context; CLI test
// runs have none, so provide a deterministic default (no /en/ prefix → pt_BR).
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

$passed  = 0;
$failed  = 0;
$created = array();

function id_assert( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		echo "  PASS: {$message}\n";
	} else {
		$failed++;
		echo "  FAIL: {$message}\n";
	}
}

echo "== Stage 2 — Event identity gate ==\n";

if ( ! function_exists( 'pll_set_post_language' ) ) {
	echo "  SKIP: Polylang is not active in this environment.\n";
	exit( 0 );
}

if ( ! class_exists( 'Conexao_Event_Importer' ) ) {
	echo "  SKIP: the event importer plugin is not active in this environment.\n";
	exit( 0 );
}

$normalized = array(
	'source'       => 'stage2_identity_test',
	'source_id'    => 'stage2-id-0001',
	'title'        => '[STAGE2-ID] Harbour Festival',
	'description'  => 'Identity gate fixture.',
	'start_date'   => gmdate( 'Y-m-d', strtotime( '+10 days' ) ),
	'start_time'   => '19:30',
	'end_date'     => '',
	'end_time'     => '',
	'source_url'   => 'https://example.test/stage2-id-0001',
	'venue'        => 'Test Venue',
	'event_location' => 'Test Venue, Dublin',
	'event_time'   => '19:30',
	'organizer'    => 'Test Organizer',
	'banner'       => '',
	'address'      => '',
	'price'        => '',
	'registration' => '',
	'county'       => '',
	'town'         => '',
	'category'     => '',
);

// --- 1. The importer creates the Portuguese master. ----------------------
$engine = new Conexao_Event_Importer_Engine( new Conexao_Event_Sources(), new Conexao_Event_Location() );
$upsert = new ReflectionMethod( 'Conexao_Event_Importer_Engine', 'upsert_event' );
$upsert->setAccessible( true );

$result = $upsert->invoke( $engine, $normalized, 0 );
$pt_id  = isset( $result['post_id'] ) ? (int) $result['post_id'] : 0;
if ( $pt_id ) {
	$created[] = $pt_id;
}

id_assert( $pt_id > 0, 'importer created the Portuguese event' );
id_assert( 'created' === $result['action'], "importer reported 'created' (got '{$result['action']}')" );
id_assert( 'pt' === pll_get_post_language( $pt_id, 'slug' ), 'imported event is assigned to pt_BR' );

// The export tooling assigns the cross-instance UUID (the importer never
// invents one), so model an event that has been exported at least once.
$export_uuid = 'stage2-identity-uuid-0001';
update_post_meta( $pt_id, '_event_export_uuid', $export_uuid );

// --- 2. Idempotent re-import resolves the same record. -------------------
$deduplicator = new Conexao_Event_Deduplicator();
$found        = $deduplicator->find( $normalized );
id_assert( $pt_id === $found, "deduplication resolves the Portuguese record (got {$found})" );

$result2 = $upsert->invoke( $engine, $normalized, $found );
id_assert( 'unchanged' === $result2['action'], "re-import reports 'unchanged' (got '{$result2['action']}')" );

// --- 3. Linked English translation with language-neutral identity meta. --
$en_id = wp_insert_post(
	array(
		'post_type'    => 'event',
		'post_title'   => 'Harbour Festival',
		'post_content' => 'English translation fixture.',
		'post_status'  => 'publish',
	),
	true
);

if ( is_wp_error( $en_id ) ) {
	$failed++;
	echo '  FAIL: could not create the English translation fixture: ' . $en_id->get_error_message() . "\n";
} else {
	$created[] = $en_id;
	pll_set_post_language( $en_id, 'en' );
	pll_save_post_translations(
		array(
			'pt' => $pt_id,
			'en' => (int) $en_id,
		)
	);

	$identity_keys = array( '_event_source', '_event_source_id', '_event_export_uuid', '_event_url', '_event_date', '_event_start_time', '_event_status' );
	foreach ( $identity_keys as $meta_key ) {
		update_post_meta( $en_id, $meta_key, get_post_meta( $pt_id, $meta_key, true ) );
	}

	$translations = pll_get_post_translations( $pt_id );
	id_assert( isset( $translations['pt'], $translations['en'] ), 'translation group links pt + en' );
	id_assert( (int) $translations['pt'] === $pt_id, 'translation group keeps the Portuguese master' );
	id_assert( (int) $translations['en'] === (int) $en_id, 'translation group keeps the English translation' );
	id_assert( 'en' === pll_get_post_language( $en_id, 'slug' ), 'translation is assigned to en' );

	// --- 4. Deduplication must still resolve the Portuguese record. ------
	// `lang => ''` asks Polylang for ALL languages, which is exactly what a
	// language-blind lookup would see (the importer runs in the admin/CLI
	// context, where Polylang would otherwise pre-filter by the admin language).
	$naive    = new WP_Query(
		array(
			'post_type'      => 'event',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'lang'           => '',
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'   => '_event_source',
					'value' => $normalized['source'],
				),
				array(
					'key'   => '_event_source_id',
					'value' => $normalized['source_id'],
				),
			),
		)
	);
	$naive_id = $naive->posts ? (int) $naive->posts[0] : 0;

	echo "  note: naive newest-first identity lookup returns #{$naive_id} (translation #{$en_id}, master #{$pt_id})\n";

	$resolved = $deduplicator->find( $normalized );
	id_assert( $pt_id === $resolved, "deduplication resolves the Portuguese master, not the translation (got {$resolved})" );

	// --- 5. The importer refuses to write to the translation. -----------
	id_assert( Conexao_Event_Importer_Language_Guard::is_import_target( $pt_id ), 'Portuguese master is a valid import target' );
	id_assert( ! Conexao_Event_Importer_Language_Guard::is_import_target( (int) $en_id ), 'English translation is NOT an import target' );

	$en_title_before   = get_post_field( 'post_title', $en_id );
	$en_content_before = get_post_field( 'post_content', $en_id );

	$guarded = $upsert->invoke( $engine, $normalized, (int) $en_id );
	id_assert( 'skipped' === $guarded['action'], "importer skipped the translation (got '{$guarded['action']}')" );
	id_assert( ! empty( $guarded['reason'] ), 'skip carries a reason for the import report' );
	id_assert( $en_title_before === get_post_field( 'post_title', $en_id ), 'translation title untouched by the import attempt' );
	id_assert( $en_content_before === get_post_field( 'post_content', $en_id ), 'translation content untouched by the import attempt' );

	// --- 6. Identity integrity. -----------------------------------------
	// Polylang filters WP_Query by language, so the counts are asserted per
	// language plus once across all languages (`lang => ''`). This is the
	// invariant that matters: ONE production identity per language, and the
	// translation is a linked sibling — never a second identity.
	$count_identity = static function ( $lang ) use ( $normalized ) {
		return count(
			get_posts(
				array(
					'post_type'      => 'event',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'lang'           => $lang,
					'meta_query'     => array(
						'relation' => 'AND',
						array(
							'key'   => '_event_source',
							'value' => $normalized['source'],
						),
						array(
							'key'   => '_event_source_id',
							'value' => $normalized['source_id'],
						),
					),
				)
			)
		);
	};

	id_assert( 1 === $count_identity( 'pt' ), 'the identity resolves to exactly ONE record in the import language' );
	id_assert( 1 === $count_identity( 'en' ), 'the identity has exactly one English translation (none orphaned)' );
	id_assert( 2 === $count_identity( '' ), 'across all languages the identity exists exactly twice: master + translation' );

	foreach ( $identity_keys as $meta_key ) {
		id_assert(
			get_post_meta( $pt_id, $meta_key, true ) === get_post_meta( $en_id, $meta_key, true ),
			"identity meta is language-neutral: {$meta_key}"
		);
	}

	id_assert(
		$export_uuid === (string) get_post_meta( $en_id, '_event_export_uuid', true ),
		'the cross-instance export UUID is shared verbatim with the translation (no new UUID invented)'
	);
}

// --- Cleanup -------------------------------------------------------------
foreach ( array_unique( $created ) as $post_id ) {
	wp_delete_post( (int) $post_id, true );
}

echo "\nevent identity gate: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
