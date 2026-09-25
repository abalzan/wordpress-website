<?php
/**
 * Stage 7 — `_leisure_excerpt_en` round-trip through the leisure migration.
 *
 * The Stage 7 English card-description layer is portable through the existing
 * approved leisure export/import ZIP, not through a second data pipeline.
 * This test pins that contract:
 *
 *  - the exporter's meta list carries `_leisure_excerpt_en`;
 *  - a real `export_item()` call emits the value for a record that has it,
 *    and omits it for a record that does not;
 *  - the importer's allowed-meta list carries the key, its sanitizer stores
 *    plain text (textarea: tags stripped, entities preserved) and an empty
 *    value DELETES the meta;
 *  - the Portuguese fields of the record are not touched by either path.
 *
 * Uses a temporary leisure record only, deleted at the end.
 *
 * Usage (from the WordPress root):
 *   php wp-content/plugins/conexao-leisure-migration/tests/test-excerpt-en-roundtrip.php
 *
 * @package Conexao_Lazer_Migration
 */

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

if ( ! defined( 'ABSPATH' ) ) {
	foreach ( array(
		dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php',
		'/var/www/html/wp-load.php',
	) as $candidate ) {
		if ( file_exists( $candidate ) ) {
			require_once $candidate;
			break;
		}
	}
}

if ( ! class_exists( 'Conexao_Lazer_Exporter' ) || ! class_exists( 'Conexao_Lazer_Importer' ) ) {
	echo "FATAL: activate conexao-leisure-migration first.\n";
	exit( 1 );
}

$passed = 0;
$failed = 0;

function s7m_assert( $condition, $message, $detail = '' ) {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		echo "  PASS: {$message}\n";
		return;
	}
	$failed++;
	echo "  FAIL: {$message}" . ( '' !== $detail ? " — {$detail}" : '' ) . "\n";
}

$meta_key = '_leisure_excerpt_en';

// --- 1. Key lists -----------------------------------------------------------------

$exporter_reflection = new ReflectionClass( 'Conexao_Lazer_Exporter' );
$exporter            = $exporter_reflection->newInstanceWithoutConstructor();

$export_keys_prop = $exporter_reflection->getProperty( 'export_meta_keys' );
$export_keys_prop->setAccessible( true );
s7m_assert(
	in_array( $meta_key, (array) $export_keys_prop->getValue( $exporter ), true ),
	'exporter meta list carries ' . $meta_key
);

$importer_reflection = new ReflectionClass( 'Conexao_Lazer_Importer' );
$importer            = $importer_reflection->newInstanceWithoutConstructor();
$allowed_prop        = $importer_reflection->getProperty( 'allowed_meta_keys' );
$allowed_prop->setAccessible( true );
s7m_assert(
	in_array( $meta_key, (array) $allowed_prop->getValue( $importer ), true ),
	'importer allowed-meta list carries ' . $meta_key
);

// --- 2. Real export_item() round-trip ---------------------------------------------

$post_id = wp_insert_post(
	array(
		'post_type'    => 'leisure',
		'post_status'  => 'publish',
		'post_title'   => 'Stage 7 Round-trip Probe',
		'post_name'    => 'stage7-roundtrip-probe',
		'post_excerpt' => 'Descrição portuguesa original.',
		'post_content' => 'Conteúdo original.',
	),
	true
);

if ( is_wp_error( $post_id ) || ! $post_id ) {
	echo "FATAL: could not create the probe record.\n";
	exit( 1 );
}

update_post_meta( $post_id, $meta_key, 'Authored English description for the round-trip probe.' );

$export_item = $exporter_reflection->getMethod( 'export_item' );
$export_item->setAccessible( true );
$item = $export_item->invoke( $exporter, get_post( $post_id ), 1 );

s7m_assert(
	isset( $item['meta'][ $meta_key ] ) && 'Authored English description for the round-trip probe.' === $item['meta'][ $meta_key ],
	'export_item() emits the authored EN description'
);
s7m_assert(
	'Descrição portuguesa original.' === $item['post']['excerpt'],
	'export_item() keeps the Portuguese excerpt untouched'
);

delete_post_meta( $post_id, $meta_key );
$item = $export_item->invoke( $exporter, get_post( $post_id ), 2 );
s7m_assert(
	! isset( $item['meta'][ $meta_key ] ),
	'records without an EN description export no empty value'
);

// --- 3. Import sanitization -------------------------------------------------------

$save_meta = $importer_reflection->getMethod( 'save_item_meta' );
$save_meta->setAccessible( true );

$save_meta->invoke(
	$importer,
	$post_id,
	array( 'meta' => array( $meta_key => 'Text with <script>evil()</script> tags and a & entity. ' ) )
);
$stored = (string) get_post_meta( $post_id, $meta_key, true );
s7m_assert( '' !== $stored, 'import stores a non-empty authored description' );
s7m_assert( false === strpos( $stored, '<script>' ), 'import strips HTML tags from the description', $stored );
s7m_assert( false !== strpos( $stored, '&' ), 'import preserves text entities', $stored );

$save_meta->invoke( $importer, $post_id, array( 'meta' => array( $meta_key => '' ) ) );
s7m_assert( '' === (string) get_post_meta( $post_id, $meta_key, true ), 'import of an empty value deletes the meta' );

// --- 4. Cleanup -------------------------------------------------------------------

wp_delete_post( $post_id, true );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
