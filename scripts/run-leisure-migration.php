<?php
/**
 * CLI helper: export or import the /lazer/ (leisure) dataset.
 *
 * Requires the Conexão Lazer Migration plugin (conexao-leisure-migration).
 *
 * Usage:
 *   # Export all leisure items to a ZIP package.
 *   wp eval-file scripts/run-leisure-migration.php --allow-root -- export /path/out.zip
 *
 *   # Preview (dry run) an import — no data is written.
 *   wp eval-file scripts/run-leisure-migration.php --allow-root -- preview /path/in.zip
 *
 *   # Import (after reviewing the preview).
 *   wp eval-file scripts/run-leisure-migration.php --allow-root -- import /path/in.zip
 *
 *   # Import, skipping items that already exist.
 *   wp eval-file scripts/run-leisure-migration.php --allow-root -- import-skip /path/in.zip
 *
 *   # Verify current leisure state (counts, taxonomies, featured images).
 *   wp eval-file scripts/run-leisure-migration.php --allow-root -- verify
 *
 * The importer only affects the 'leisure' post type, its taxonomies, and the
 * Media Library attachments it creates for leisure images.
 */

require_once __DIR__ . '/lib/bootstrap.php';
conexao_script_load_wordpress();

if ( ! class_exists( 'Conexao_Lazer_Exporter' ) || ! class_exists( 'Conexao_Lazer_Importer' ) ) {
	fwrite( STDERR, "ERROR: The Conexão Lazer Migration plugin is not active.\n" );
	fwrite( STDERR, "Activate wp-content/plugins/conexao-leisure-migration first.\n" );
	exit( 1 );
}

$args = isset( $argv ) ? $argv : array();
// wp eval-file passes the script path first; drop it.
array_shift( $args );
// Everything after "--" is our subcommand + path.
if ( isset( $args[0] ) && '--' === $args[0] ) {
	array_shift( $args );
}

$command = isset( $args[0] ) ? $args[0] : 'help';
array_shift( $args );
$path = isset( $args[0] ) ? $args[0] : '';

$exporter = new Conexao_Lazer_Exporter();
$importer = new Conexao_Lazer_Importer();

function conexao_lazer_cli_line( $label, $value ) {
	$label = str_pad( $label, 28 );
	fwrite( STDOUT, "  $label: $value\n" );
}

switch ( $command ) {
	case 'export':
		if ( ! $path ) {
			$path = 'lazer-export-' . gmdate( 'Y-m-d' ) . '.zip';
		}
		$package = $exporter->build_package( $path );
		if ( is_wp_error( $package ) ) {
			fwrite( STDERR, "ERROR: " . $package->get_error_message() . "\n" );
			exit( 1 );
		}
		fwrite( STDOUT, "Exported Lazer package to:\n  $path\n" );
		fwrite( STDOUT, '  Format: ' . Conexao_Lazer_Exporter::FORMAT . ' v' . Conexao_Lazer_Exporter::FORMAT_VERSION . "\n" );
		fwrite( STDOUT, '  Source: ' . home_url() . "\n" );
		break;

	case 'preview':
	case 'dry-run':
		if ( ! $path ) {
			fwrite( STDERR, "ERROR: preview requires a ZIP file path.\n" );
			exit( 1 );
		}
		$stats = $importer->import_file( $path, array( 'dry_run' => true ) );
		if ( ! empty( $stats['errors'] ) ) {
			fwrite( STDERR, "ERROR(s):\n" );
			foreach ( $stats['errors'] as $e ) {
				fwrite( STDERR, "  - $e\n" );
			}
			exit( 1 );
		}
		fwrite( STDOUT, "Preview (dry run) — nothing written.\n" );
		conexao_lazer_cli_line( 'Items found', $stats['found'] );
		conexao_lazer_cli_line( 'New items', $stats['created'] );
		conexao_lazer_cli_line( 'To update', $stats['updated'] );
		conexao_lazer_cli_line( 'To skip', $stats['skipped'] );
		conexao_lazer_cli_line( 'Failed', $stats['failed'] );
		conexao_lazer_cli_line( 'Images to import', $stats['images_imported'] );
		conexao_lazer_cli_line( 'Images to reuse', $stats['images_reused'] );
		conexao_lazer_cli_line( 'Images missing', $stats['images_missing'] );
		conexao_lazer_cli_line( 'Tax terms to create', $stats['taxonomies_created'] );
		conexao_lazer_cli_line( 'Tax terms matched', $stats['taxonomies_matched'] );
		break;

	case 'import':
	case 'import-update':
		if ( ! $path ) {
			fwrite( STDERR, "ERROR: import requires a ZIP file path.\n" );
			exit( 1 );
		}
		conexao_lazer_cli_import( $importer, $path, true );
		break;

	case 'import-skip':
		if ( ! $path ) {
			fwrite( STDERR, "ERROR: import-skip requires a ZIP file path.\n" );
			exit( 1 );
		}
		conexao_lazer_cli_import( $importer, $path, false );
		break;

	case 'verify':
		$count    = $exporter->count_items();
		$verified = $importer->verify_import( $count );
		fwrite( STDOUT, "Verification of /lazer/ state:\n" );
		conexao_lazer_cli_line( 'Leisure total', $verified['leisure_total'] );
		conexao_lazer_cli_line( 'Published', $verified['leisure_published'] );
		conexao_lazer_cli_line( 'Draft', $verified['leisure_draft'] );
		conexao_lazer_cli_line( 'With featured image', $verified['with_thumbnail'] );
		conexao_lazer_cli_line( 'With stable ID', $verified['with_uuid'] );
		conexao_lazer_cli_line( 'Official website set', $verified['official_website'] );
		conexao_lazer_cli_line( 'Discover Ireland set', $verified['discover_ireland'] );
		conexao_lazer_cli_line( 'Category terms', $verified['category_terms'] );
		conexao_lazer_cli_line( 'County terms', $verified['county_terms'] );
		conexao_lazer_cli_line( 'Tag terms', $verified['tag_terms'] );
		fwrite( STDOUT, '  ' . 'Expected (from manifest)' . ': ' . $verified['expected'] . "\n" );
		break;

	case 'help':
	default:
		fwrite( STDOUT, "Conexão Lazer Migration CLI\n" );
		fwrite( STDOUT, "Usage: wp eval-file scripts/run-leisure-migration.php --allow-root -- <command> [path]\n" );
		fwrite( STDOUT, "  export [path]        Export all leisure items to a ZIP package.\n" );
		fwrite( STDOUT, "  preview [path]       Dry-run import preview (writes nothing).\n" );
		fwrite( STDOUT, "  import [path]        Import, updating existing items.\n" );
		fwrite( STDOUT, "  import-skip [path]   Import, skipping existing items.\n" );
		fwrite( STDOUT, "  verify               Verify current /lazer/ state.\n" );
		break;
}

/**
 * Run an import from the CLI and print a human-readable report.
 *
 * @param Conexao_Lazer_Importer $importer Importer instance.
 * @param string                 $path     ZIP file path.
 * @param bool                   $update   Whether to update existing items.
 */
function conexao_lazer_cli_import( $importer, $path, $update ) {
	$stats = $importer->import_file( $path, array( 'dry_run' => false, 'update' => $update ) );

	fwrite( STDOUT, "Import report:\n" );
	conexao_lazer_cli_line( 'Items found', $stats['found'] );
	conexao_lazer_cli_line( 'Created', $stats['created'] );
	conexao_lazer_cli_line( 'Updated', $stats['updated'] );
	conexao_lazer_cli_line( 'Skipped', $stats['skipped'] );
	conexao_lazer_cli_line( 'Failed', $stats['failed'] );
	conexao_lazer_cli_line( 'Images imported', $stats['images_imported'] );
	conexao_lazer_cli_line( 'Images reused', $stats['images_reused'] );
	conexao_lazer_cli_line( 'Images missing', $stats['images_missing'] );
	conexao_lazer_cli_line( 'Tax terms created', $stats['taxonomies_created'] );
	conexao_lazer_cli_line( 'Tax terms matched', $stats['taxonomies_matched'] );

	if ( ! empty( $stats['verification'] ) ) {
		$v = $stats['verification'];
		fwrite( STDOUT, "Post-import verification:\n" );
		conexao_lazer_cli_line( 'Leisure total', $v['leisure_total'] );
		conexao_lazer_cli_line( 'Published', $v['leisure_published'] );
		conexao_lazer_cli_line( 'With featured image', $v['with_thumbnail'] );
		conexao_lazer_cli_line( 'With stable ID', $v['with_uuid'] );
		conexao_lazer_cli_line( 'Official website set', $v['official_website'] );
		conexao_lazer_cli_line( 'Discover Ireland set', $v['discover_ireland'] );
	}

	if ( ! empty( $stats['errors'] ) ) {
		fwrite( STDERR, "Errors:\n" );
		foreach ( $stats['errors'] as $e ) {
			fwrite( STDERR, "  - $e\n" );
		}
	}
}