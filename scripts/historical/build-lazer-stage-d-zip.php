<?php
/**
 * Lazer expansion - Stage D: build the scoped 13-record deployment ZIP.
 *
 * Full-dataset exports are NOT used: a 245-item package would rewrite every
 * leisure record on production. This scoped package touches at most the 13
 * frozen slugs via the importer's UUID -> slug -> title matching.
 *
 * Image safety: 12 NEW records carry no image (Stage C decision C).
 * Knocknarea image_data is stripped so production performs a pure
 * metadata/text update and never touches attachments.
 *
 * Writes NOTHING to the DB. Output: ZIP path = last .zip argv.
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'WP_USE_THEMES', false );
	$dir = dirname( __FILE__ );
	while ( $dir !== dirname( $dir ) ) {
		if ( file_exists( $dir . '/wp-load.php' ) ) { require_once $dir . '/wp-load.php'; break; }
		$dir = dirname( $dir );
	}
	if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Unable to locate wp-load.php\n" ); exit( 1 ); }
}
if ( ! class_exists( 'Conexao_Lazer_Exporter' ) || ! class_exists( 'Conexao_Lazer_Importer' ) ) {
	fwrite( STDERR, "ERROR: conexao-leisure-migration plugin is not active.\n" ); exit( 1 );
}
$args = isset( $argv ) ? array_values( (array) $argv ) : array();
$dest = '/tmp/lazer-stage-d-13-records.zip';
foreach ( $args as $a ) { if ( is_string( $a ) && '.zip' === substr( $a, -4 ) ) { $dest = $a; } }
if ( '' === $dest ) { fwrite( STDERR, "Usage: wp eval-file build-lazer-stage-d-zip.php --allow-root -- /tmp/out.zip\n" ); exit( 1 ); }
$frozen_slugs = array( 'chester-beatty','forty-foot','derrigimlagh','joyce-tower-museum','the-model','doagh-famine-village','dursey-island','old-head-of-kinsale','lough-muckno-leisure-park','jfk-arboretum','cavan-cathedral','national-design-craft-gallery','queen-maeves-trail-knocknarea' );
$exporter = new Conexao_Lazer_Exporter();
$full = $exporter->build_export();
$by_slug = array();
foreach ( $full['items'] as $item ) { $by_slug[ $item['post']['slug'] ] = $item; }
$missing = array_diff( $frozen_slugs, array_keys( $by_slug ) );
if ( $missing ) { fwrite( STDERR, 'ERROR: frozen slugs missing: ' . implode( ', ', $missing ) . "\n" ); exit( 1 ); }
$violations = array();
foreach ( $frozen_slugs as $slug ) {
	if ( 'queen-maeves-trail-knocknarea' === $slug ) { continue; }
	$fn = isset( $by_slug[$slug]['image_data']['filename'] ) ? $by_slug[$slug]['image_data']['filename'] : '';
	if ( '' !== $fn ) { $violations[] = $slug . ' carries image ' . $fn; }
}
if ( $violations ) { fwrite( STDERR, "ERROR: image violations:\n  - " . implode( "\n  - ", $violations ) . "\n" ); exit( 1 ); }
$items = array();
foreach ( $frozen_slugs as $slug ) {
	$item = $by_slug[ $slug ];
	if ( 'queen-maeves-trail-knocknarea' === $slug ) {
		$item['image_data']['filename'] = ''; $item['image_data']['id'] = '';
		$item['image_data']['md5'] = ''; $item['image']['filename'] = '';
		$item['image']['id'] = ''; $item['image']['md5'] = '';
		unset( $item['meta']['_leisure_image_attachment_id'] );
	}
	$items[] = $item;
}
$payload = array(
	'manifest' => array( 'format' => Conexao_Lazer_Exporter::FORMAT, 'version' => Conexao_Lazer_Exporter::FORMAT_VERSION, 'post_type' => 'leisure', 'exported_at' => current_time( 'c' ), 'source_url' => home_url(), 'item_count' => count( $items ), 'packaged' => true, 'package' => array( 'images_dir' => 'images', 'data_file' => 'data.json' ), 'stage' => 'lazer-expansion-stage-d scoped 13-record set' ),
	'items' => $items,
);
$tmp_dir = wp_tempnam( 'conexao-lazer-stage-d' );
if ( file_exists( $tmp_dir ) ) { @unlink( $tmp_dir ); }
if ( ! wp_mkdir_p( $tmp_dir ) || ! wp_mkdir_p( $tmp_dir . '/images' ) ) { fwrite( STDERR, "ERROR: tmp dir\n" ); exit( 1 ); }
file_put_contents( $tmp_dir . '/data.json', wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
$zip = new ZipArchive();
$res = $zip->open( $dest, ZipArchive::CREATE | ZipArchive::OVERWRITE );
if ( true !== $res ) { fwrite( STDERR, "ERROR: zip at {$dest}\n" ); exit( 1 ); }
$zip->addFile( $tmp_dir . '/data.json', 'lazer-export/data.json' );
$zip->close();
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tmp_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $f ) { $f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() ); }
@rmdir( $tmp_dir );
$importer = new Conexao_Lazer_Importer();
$v = $importer->import_file( $dest, array( 'dry_run' => true ) );
echo 'Scoped Stage D package: ' . $dest . "\n";
echo '  Payload items : ' . count( $items ) . "\n";
echo '  ZIP size      : ' . filesize( $dest ) . " bytes\n";
echo '  Dry-run found : ' . $v['found'] . "\n";
echo '  Dry-run new   : ' . $v['created'] . "\n";
echo '  Dry-run update: ' . $v['updated'] . "\n";
echo '  Errors        : ' . count( $v['errors'] ) . "\n";
foreach ( $v['errors'] as $e ) { echo '    - ' . $e . "\n"; }
if ( 13 !== (int) $v['found'] || $v['errors'] ) { fwrite( STDERR, "ERROR: package validation failed.\n" ); exit( 1 ); }
echo "STAGE-D PACKAGE OK\n";
