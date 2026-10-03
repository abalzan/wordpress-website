<?php
/**
 * Stage 2 — Lazer identity (UUID) × language.
 *
 * HARD GATE. Verifies that a linked English translation of a Lazer record can
 * never break `_leisure_export_uuid` integrity:
 *
 *  - the UUID stays byte-identical on both records (no new UUID invented);
 *  - no third record can carry the same UUID (one master + one translation);
 *  - the ZIP import matching by UUID resolves the PORTUGUESE master (the
 *    language preference in `find_by_uuid()`), even though the translation is
 *    newer;
 *  - the importer refuses to write to the translation (refuses to overwrite
 *    translated content and refuses to become a competing UUID target);
 *  - the external-resource classification used by the single template and the
 *    sitemap is unchanged for the master;
 *  - the migration language guard assigns the default language to imported
 *    Lazer records and never reassigns a translation.
 *
 * Fixtures are prefixed "[STAGE2-LAZER]" and deleted at the end.
 *
 * Usage (from the project root):
 *   docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-leisure-migration/tests/test-language-uuid.php
 */


// Deterministic request context for Polylang in a CLI process.

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$passed  = 0;
$failed  = 0;
$created = array();


test_prerequisite_hint( 'polylang' );
test_require( function_exists( 'pll_set_post_language' ), 'polylang', 'test prerequisite is available: function_exists( pll_set_post_language )', 'activate the Polylang plugin (docker compose exec wordpress wp plugin activate polylang)' );
test_prerequisite_hint( 'activate-plugin:conexao-leisure-migration' );
test_require( class_exists( 'Conexao_Lazer_Importer' ), 'activate-plugin:conexao-leisure-migration', 'test prerequisite is available: class_exists( Conexao_Lazer_Importer )', 'activate the conexao-leisure-migration plugin' );
$uuid = 'stage2-lazer-uuid-0001';

// The language guard hooks save_post_leisure: creating the record through the
// normal WP API must therefore assign the default language automatically.
$pt_id = wp_insert_post(
	array(
		'post_type'    => 'leisure',
		'post_title'   => '[STAGE2-LAZER] Test Destination',
		'post_content' => 'UUID fixture.',
		'post_status'  => 'publish',
	),
	true
);

if ( is_wp_error( $pt_id ) ) {
	echo '  FAIL: could not create the Lazer fixture: ' . $pt_id->get_error_message() . "\n";
	exit( 1 );
}

$created[] = (int) $pt_id;
update_post_meta( $pt_id, Conexao_Lazer_Exporter::UUID_META_KEY, $uuid );

assert_true( 'pt' === pll_get_post_language( $pt_id, 'slug' ), 'migration language guard assigned pt to the imported Lazer record' );
assert_true( $uuid === (string) get_post_meta( $pt_id, Conexao_Lazer_Exporter::UUID_META_KEY, true ), 'the export UUID is stored unchanged' );

$external_before = conexao_leisure_external_url( $pt_id );

// --- Linked English translation carrying the same UUID. ------------------
$en_id = wp_insert_post(
	array(
		'post_type'    => 'leisure',
		'post_title'   => 'Test Destination',
		'post_content' => 'UUID fixture (EN).',
		'post_status'  => 'publish',
	),
	true
);

if ( is_wp_error( $en_id ) ) {
	echo '  FAIL: could not create the EN translation: ' . $en_id->get_error_message() . "\n";
	exit( 1 );
}

$created[] = (int) $en_id;
pll_set_post_language( (int) $en_id, 'en' );
pll_save_post_translations( array( 'pt' => $pt_id, 'en' => (int) $en_id ) );
update_post_meta( $en_id, Conexao_Lazer_Exporter::UUID_META_KEY, $uuid );

// Saving the translation again must not move it into the default language.
do_action( 'save_post_leisure', (int) $en_id, get_post( $en_id ), true );
assert_true( 'en' === pll_get_post_language( (int) $en_id, 'slug' ), 'language guard never reassigns an existing translation' );

assert_true( $uuid === (string) get_post_meta( (int) $en_id, Conexao_Lazer_Exporter::UUID_META_KEY, true ), 'the translation shares the UUID verbatim (no new UUID)' );

$translations = pll_get_post_translations( $pt_id );
assert_true( isset( $translations['pt'], $translations['en'] ), 'translation group links the PT master and EN translation' );

// --- No duplicate UUID / no competing import target. ---------------------
$importer     = new Conexao_Lazer_Importer();
$find_by_uuid = new ReflectionMethod( 'Conexao_Lazer_Importer', 'find_by_uuid' );
$find_by_uuid->setAccessible( true );

$resolved = $find_by_uuid->invoke( $importer, $uuid );
assert_true( $pt_id === $resolved, "UUID matching resolves the Portuguese master (#{$resolved})" );

$count_uuid = static function ( $lang ) use ( $uuid ) {
	return count(
		get_posts(
			array(
				'post_type'      => 'leisure',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'lang'           => $lang,
				'meta_query'     => array(
					array(
						'key'   => Conexao_Lazer_Exporter::UUID_META_KEY,
						'value' => $uuid,
					),
				),
			)
		)
	);
};

assert_true( 1 === $count_uuid( 'pt' ), 'exactly one record carries the UUID in the import language' );
assert_true( 1 === $count_uuid( 'en' ), 'exactly one English translation carries the UUID (none orphaned)' );
assert_true( 2 === $count_uuid( '' ), 'across all languages the UUID exists exactly twice: master + translation' );

// --- The importer refuses to write to the translation. -------------------
$is_target = new ReflectionMethod( 'Conexao_Lazer_Importer', 'is_import_target' );
$is_target->setAccessible( true );
assert_true( true === $is_target->invoke( $importer, $pt_id ), 'PT master is a valid Lazer import target' );
assert_true( false === $is_target->invoke( $importer, (int) $en_id ), 'EN translation is NOT a Lazer import target' );

$update_item = new ReflectionMethod( 'Conexao_Lazer_Importer', 'update_item' );
$update_item->setAccessible( true );
$stats = array();

$en_content_before = get_post_field( 'post_content', $en_id );
$item              = array(
	'uuid' => $uuid,
	'post' => array(
		'title'   => 'Sobrescrito pelo importador',
		'content' => 'Conteúdo em português.',
		'slug'    => 'sobrescrito-pelo-importador',
		'status'  => 'publish',
	),
);

// `invokeArgs` keeps the by-reference $stats parameter intact.
$result = $update_item->invokeArgs(
	$importer,
	array( (int) $en_id, $item, '', 'Test Destination', &$stats )
);

assert_true( is_wp_error( $result ), 'the Lazer importer refuses to update a translation (returns WP_Error)' );
assert_true( $en_content_before === get_post_field( 'post_content', $en_id ), 'translated Lazer content is untouched by the refused import' );

// --- External-resource classification is unchanged. ----------------------
assert_true( $external_before === conexao_leisure_external_url( $pt_id ), 'external-resource classification unchanged by the language layer' );

// --- Cleanup -------------------------------------------------------------
foreach ( array_unique( $created ) as $post_id ) {
	wp_delete_post( (int) $post_id, true );
}

test_finish();
