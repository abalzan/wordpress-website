<?php
/**
 * Stage 3.2 — event export `lang` field contract (HARD-GATE-adjacent).
 *
 * Verifies the additive source-language field in the local Event export JSON:
 *
 *  - English source (explicit `_event_source_language = en`) → "lang": "en".
 *  - Portuguese source (explicit `pt`) → "lang": "pt".
 *  - Unclassified record (meta absent) → "lang": "unknown".
 *  - Legacy record (no language metadata at all) → "lang": "unknown", and
 *    every pre-existing export field is still present and unchanged.
 *  - An English TRANSLATION of an event never becomes a second export row:
 *    the export set is constrained to the import language, so the identity
 *    (uuid / source+source_id) appears exactly once.
 *  - The export never mutates event content or meta (beyond the pre-existing
 *    UUID provisioning on first export, which this test pre-arranges).
 *  - The importer writes `_event_source_language` only from an explicit
 *    normalized signal; the Eventbrite `locale` maps to the classification;
 *    the title text is never used to infer language.
 *
 * Fixtures are prefixed [STAGE32-EXP] and deleted at the end.
 *
 * Usage (from the project root):
 *   php wp-content/plugins/conexao-event-importer/tests/test-export-language.php
 */

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

$passed = 0;
$failed = 0;

function xl_assert( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		echo "  PASS: {$message}\n";
	} else {
		$failed++;
		echo "  FAIL: {$message}\n";
	}
}

echo "== Stage 3.2 — event export language field ==\n";

if ( ! class_exists( 'Conexao_Event_Export' ) ) {
	echo "  SKIP: exporter unavailable.\n";
	exit( 0 );
}
if ( ! class_exists( 'Conexao_Event_Source_Language' ) ) {
	echo "  FAIL: Conexao_Event_Source_Language missing (runtime plugin inactive?)\n";
	exit( 1 );
}

$created = array();

function xl_event( $slug, $title, $meta = array() ) {
	global $created;
	$id = wp_insert_post(
		array(
			'post_type'    => 'event',
			'post_name'    => $slug,
			'post_title'   => $title,
			'post_content' => 'Export language fixture.',
			'post_status'  => 'publish',
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		echo '  FAIL: fixture insert failed: ' . $id->get_error_message() . "\n";
		return 0;
	}
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	$created[] = $id;
	return (int) $id;
}

// ---------------------------------------------------------------------------
// 0. Unit surface of the contract helper.
// ---------------------------------------------------------------------------
echo "\n-- Conexao_Event_Source_Language unit checks --\n";
xl_assert( array( 'pt', 'en', 'other' ) === Conexao_Event_Source_Language::allowed(), 'allowed stored values are exactly pt|en|other' );
xl_assert( 'en' === Conexao_Event_Source_Language::sanitize( ' EN ' ), 'sanitize normalizes case/whitespace' );
xl_assert( '' === Conexao_Event_Source_Language::sanitize( 'english' ), 'sanitize rejects free text' );
xl_assert( '' === Conexao_Event_Source_Language::sanitize( '' ), 'sanitize rejects empty' );
xl_assert( 'unknown' === Conexao_Event_Source_Language::export_value( '' ), 'export_value maps absent to unknown' );
xl_assert( 'unknown' === Conexao_Event_Source_Language::export_value( 'bogus' ), 'export_value maps garbage to unknown' );
xl_assert( 'pt' === Conexao_Event_Source_Language::export_value( 'pt' ), 'export_value passes pt through' );
xl_assert( 'en' === Conexao_Event_Source_Language::from_locale( 'en_IE' ), 'from_locale en_IE → en' );
xl_assert( 'pt' === Conexao_Event_Source_Language::from_locale( 'pt_BR' ), 'from_locale pt_BR → pt' );
xl_assert( 'other' === Conexao_Event_Source_Language::from_locale( 'es_ES' ), 'from_locale es_ES → other' );
xl_assert( '' === Conexao_Event_Source_Language::from_locale( '' ), 'from_locale of empty signal is unclassified' );

// ---------------------------------------------------------------------------
// 1. Fixtures: en source, pt source, unclassified, legacy (no language meta).
// ---------------------------------------------------------------------------
echo "\n-- Fixtures --\n";
$base_meta = array(
	'_event_source'     => 'stage32_export_test',
	'_event_date'       => gmdate( 'Y-m-d', strtotime( '+15 days' ) ),
	'_event_start_time' => '19:00',
	'_event_status'     => 'published',
);

$id_en = xl_event( 'stage32-exp-en', '[STAGE32-EXP] English source', array_merge( $base_meta, array( '_event_source_id' => 'stage32-exp-0001', '_event_source_language' => 'en' ) ) );
$id_pt = xl_event( 'stage32-exp-pt', '[STAGE32-EXP] Fonte em português', array_merge( $base_meta, array( '_event_source_id' => 'stage32-exp-0002', '_event_source_language' => 'pt' ) ) );
$id_un = xl_event( 'stage32-exp-un', '[STAGE32-EXP] Unclassified source', array_merge( $base_meta, array( '_event_source_id' => 'stage32-exp-0003' ) ) );
$id_lg = xl_event( 'stage32-exp-lg', '[STAGE32-EXP] Legacy record', array_merge( $base_meta, array( '_event_source_id' => 'stage32-exp-0004' ) ) );
delete_post_meta( $id_lg, '_event_source_language' ); // simulate a pre-3.2 row

xl_assert( $id_en && $id_pt && $id_un && $id_lg, 'four fixture events created' );

// Snapshot the content/meta of every fixture BEFORE export to prove the
// export mutates nothing.
$before = array();
foreach ( array( $id_en, $id_pt, $id_un, $id_lg ) as $fid ) {
	$before[ $fid ] = array(
		'title'   => get_post_field( 'post_title', $fid ),
		'content' => get_post_field( 'post_content', $fid ),
		'slug'    => get_post_field( 'post_name', $fid ),
		'meta'    => get_post_meta( $fid ),
	);
}

// ---------------------------------------------------------------------------
// 2. Export.
// ---------------------------------------------------------------------------
echo "\n-- Export --\n";
$exporter = new Conexao_Event_Export();
$export   = $exporter->build_export();

xl_assert( isset( $export['manifest'], $export['events'] ) && is_array( $export['events'] ), 'export payload shape (manifest + events) preserved' );

$rows = array();
foreach ( $export['events'] as $row ) {
	$sid = isset( $row['meta']['_event_source_id'] ) ? (string) $row['meta']['_event_source_id'] : '';
	if ( 0 === strpos( $sid, 'stage32-exp-' ) ) {
		$rows[ $sid ] = $row;
	}
}

xl_assert( 4 === count( $rows ), 'exactly the four fixture identities are present in the export' );

xl_assert( isset( $rows['stage32-exp-0001']['lang'] ) && 'en' === $rows['stage32-exp-0001']['lang'], 'English source → lang=en' );
xl_assert( isset( $rows['stage32-exp-0002']['lang'] ) && 'pt' === $rows['stage32-exp-0002']['lang'], 'Portuguese source → lang=pt' );
xl_assert( isset( $rows['stage32-exp-0003']['lang'] ) && 'unknown' === $rows['stage32-exp-0003']['lang'], 'unclassified source → lang=unknown' );
xl_assert( isset( $rows['stage32-exp-0004']['lang'] ) && 'unknown' === $rows['stage32-exp-0004']['lang'], 'legacy record without language metadata → lang=unknown' );

// The classification also round-trips inside the meta bag when classified.
xl_assert( isset( $rows['stage32-exp-0001']['meta']['_event_source_language'] ) && 'en' === $rows['stage32-exp-0001']['meta']['_event_source_language'], 'meta bag carries _event_source_language when classified' );
xl_assert( ! isset( $rows['stage32-exp-0003']['meta']['_event_source_language'] ), 'meta bag omits the key when unclassified (no invented value)' );

// ---------------------------------------------------------------------------
// 3. Backward compatibility: the existing field contract is unchanged.
// ---------------------------------------------------------------------------
echo "\n-- Compatibility --\n";
foreach ( $rows as $sid => $row ) {
	xl_assert(
		isset( $row['uuid'], $row['post']['title'], $row['post']['content'], $row['post']['slug'], $row['meta'], $row['taxonomies'], $row['featured_image'] ),
		"{$sid}: pre-existing top-level fields (uuid/post/meta/taxonomies/featured_image) intact"
	);
	xl_assert(
		isset( $row['meta']['_event_source'], $row['meta']['_event_source_id'], $row['meta']['_event_date'], $row['meta']['_event_status'] ),
		"{$sid}: identity/scheduling meta intact"
	);
}

$uuids = array_map(
	static function ( $row ) {
		return (string) $row['uuid'];
	},
	$rows
);
xl_assert( count( $uuids ) === count( array_unique( $uuids ) ), 'no duplicate uuid across the fixture rows' );

// ---------------------------------------------------------------------------
// 4. A linked EN translation never becomes a second export row.
// ---------------------------------------------------------------------------
echo "\n-- Translation exclusion --\n";
if ( function_exists( 'pll_save_post_translations' ) ) {
	$en_translation = wp_insert_post(
		array(
			'post_type'    => 'event',
			'post_name'    => 'stage32-exp-pt-en',
			'post_title'   => '[STAGE32-EXP] Portuguese source (English)',
			'post_content' => 'Linked translation fixture.',
			'post_status'  => 'publish',
		),
		true
	);
	if ( is_wp_error( $en_translation ) ) {
		xl_assert( false, 'could not create translation fixture' );
	} else {
		$created[] = $en_translation;
		pll_set_post_language( $en_translation, 'en' );
		pll_save_post_translations( array( 'pt' => $id_pt, 'en' => (int) $en_translation ) );
		update_post_meta( $en_translation, '_event_source', 'stage32_export_test' );
		update_post_meta( $en_translation, '_event_source_id', 'stage32-exp-0002' );

		$export2 = $exporter->build_export();
		$count   = 0;
		$en_seen = 0;
		foreach ( $export2['events'] as $row ) {
			if ( isset( $row['meta']['_event_source_id'] ) && 'stage32-exp-0002' === $row['meta']['_event_source_id'] ) {
				$count++;
				if ( false !== strpos( (string) $row['post']['slug'], '-en' ) ) {
					$en_seen++;
				}
			}
		}
		xl_assert( 1 === $count, "identity stage32-exp-0002 appears exactly once in the export (got {$count})" );
		xl_assert( 0 === $en_seen, 'the English translation is never an export row' );
	}
} else {
	echo "  SKIP: Polylang inactive — translation-exclusion check not applicable.\n";
}

// ---------------------------------------------------------------------------
// 5. Export mutates nothing.
// ---------------------------------------------------------------------------
echo "\n-- No mutation --\n";
foreach ( $before as $fid => $snapshot ) {
	xl_assert( $snapshot['title'] === get_post_field( 'post_title', $fid ), "#{$fid} title unchanged by export" );
	xl_assert( $snapshot['content'] === get_post_field( 'post_content', $fid ), "#{$fid} content unchanged by export" );
	xl_assert( $snapshot['slug'] === get_post_field( 'post_name', $fid ), "#{$fid} slug unchanged by export" );
	xl_assert(
		(string) get_post_meta( $fid, '_event_source_language', true ) === ( isset( $snapshot['meta']['_event_source_language'][0] ) ? (string) $snapshot['meta']['_event_source_language'][0] : '' ),
		"#{$fid} _event_source_language unchanged by export"
	);
}

// ---------------------------------------------------------------------------
// 6. Importer-side writes: explicit signal only, never title inference.
// ---------------------------------------------------------------------------
echo "\n-- Importer writes --\n";
if ( class_exists( 'Conexao_Event_Importer_Engine' ) ) {
	$engine = new Conexao_Event_Importer_Engine( new Conexao_Event_Sources(), new Conexao_Event_Location() );
	$upsert = new ReflectionMethod( 'Conexao_Event_Importer_Engine', 'upsert_event' );
	$upsert->setAccessible( true );

	$normalized_base = array(
		'source'         => 'stage32_export_test',
		'title'          => '[STAGE32-EXP] Upsert language write',
		'description'    => 'Fixture.',
		'start_date'     => gmdate( 'Y-m-d', strtotime( '+16 days' ) ),
		'start_time'     => '19:30',
		'end_date'       => '',
		'end_time'       => '',
		'source_url'     => 'https://example.test/stage32-exp-upsert',
		'venue'          => 'Test Venue',
		'event_location' => 'Test Venue, Dublin',
		'event_time'     => '19:30',
		'organizer'      => 'Test Organizer',
		'banner'         => '',
		'address'        => '',
		'price'          => '',
		'registration'   => '',
		'county'         => '',
		'town'           => '',
		'category'       => '',
	);

	// (a) explicit en signal is stored.
	$n1 = array_merge( $normalized_base, array( 'source_id' => 'stage32-exp-0010', 'source_language' => 'en', 'title' => '[STAGE32-EXP] Upsert EN' ) );
	$r1 = $upsert->invoke( $engine, $n1, 0 );
	$e1 = isset( $r1['post_id'] ) ? (int) $r1['post_id'] : 0;
	if ( $e1 ) {
		$created[] = $e1;
	}
	xl_assert( $e1 > 0, 'upsert with explicit en signal created the event' );
	xl_assert( 'en' === get_post_meta( $e1, '_event_source_language', true ), 'explicit en signal stored as _event_source_language=en' );

	// (b) no signal → nothing stored (unknown), even with an English title.
	$n2 = array_merge( $normalized_base, array( 'source_id' => 'stage32-exp-0011', 'title' => '[STAGE32-EXP] Fully English Title Without Signal' ) );
	unset( $n2['source_language'] );
	$r2 = $upsert->invoke( $engine, $n2, 0 );
	$e2 = isset( $r2['post_id'] ) ? (int) $r2['post_id'] : 0;
	if ( $e2 ) {
		$created[] = $e2;
	}
	xl_assert( $e2 > 0, 'upsert without signal created the event' );
	xl_assert( '' === (string) get_post_meta( $e2, '_event_source_language', true ), 'no signal → meta absent (unknown), title never inspected' );

	// (c) invalid signal → nothing stored.
	$n3 = array_merge( $normalized_base, array( 'source_id' => 'stage32-exp-0012', 'source_language' => 'klingon', 'title' => '[STAGE32-EXP] Invalid signal' ) );
	$r3 = $upsert->invoke( $engine, $n3, 0 );
	$e3 = isset( $r3['post_id'] ) ? (int) $r3['post_id'] : 0;
	if ( $e3 ) {
		$created[] = $e3;
	}
	xl_assert( '' === (string) get_post_meta( $e3, '_event_source_language', true ), 'invalid signal rejected, meta absent' );
} else {
	echo "  SKIP: importer engine unavailable.\n";
}

// ---------------------------------------------------------------------------
// 7. Normalizer pass-through + Eventbrite locale signal.
// ---------------------------------------------------------------------------
echo "\n-- Normalizers --\n";
if ( class_exists( 'Conexao_Event_Normalizer' ) ) {
	$normalizer = new Conexao_Event_Normalizer( new Conexao_Event_Location() );
	$out        = $normalizer->normalize(
		array(
			'title'           => 'X',
			'source'          => 'x',
			'source_id'       => 'x-1',
			'url'             => 'https://example.test/x-1',
			'start_date'      => gmdate( 'Y-m-d', strtotime( '+9 days' ) ),
			'location'        => 'Dublin',
			'source_language' => 'en',
		)
	);
	xl_assert( isset( $out['source_language'] ) && 'en' === $out['source_language'], 'main normalizer passes through a valid explicit signal' );

	$out2 = $normalizer->normalize(
		array(
			'title'      => 'Y',
			'source'     => 'x',
			'source_id'  => 'x-2',
			'url'        => 'https://example.test/x-2',
			'start_date' => gmdate( 'Y-m-d', strtotime( '+9 days' ) ),
			'location'   => 'Dublin',
		)
	);
	xl_assert( isset( $out2['source_language'] ) && '' === $out2['source_language'], 'main normalizer defaults to unclassified when no signal exists' );
}

if ( class_exists( 'Conexao_Eventbrite_Normalizer' ) ) {
	$eb   = new Conexao_Eventbrite_Normalizer();
	$raw  = array(
		'id'     => '9999',
		'name'   => 'Locale fixture',
		'url'    => 'https://example.test/e/9999',
		'locale' => 'en_IE',
		'start'  => array( 'utc' => gmdate( 'Y-m-d\TH:i:s', strtotime( '+9 days' ) ) ),
	);
	$ebpt = $raw;
	$ebpt['locale'] = 'pt_BR';
	$ebno = $raw;
	unset( $ebno['locale'] );

	xl_assert( 'en' === $eb->normalize( $raw )['source_language'], 'Eventbrite locale en_IE → en' );
	xl_assert( 'pt' === $eb->normalize( $ebpt )['source_language'], 'Eventbrite locale pt_BR → pt' );
	xl_assert( '' === $eb->normalize( $ebno )['source_language'], 'Eventbrite without locale → unclassified' );
}

// ---------------------------------------------------------------------------
// Cleanup.
// ---------------------------------------------------------------------------
foreach ( array_unique( $created ) as $post_id ) {
	wp_delete_post( (int) $post_id, true );
}

echo "\nexport language field: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
