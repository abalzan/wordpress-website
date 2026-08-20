<?php
/**
 * Lazer Export / Import admin interface.
 *
 * Adds "Export Lazer" and "Import Lazer" pages under the Lazer menu, handling
 * the export download and the import file upload with a dry-run preview.
 *
 * @package Conexao_Lazer_Migration
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Lazer_Transfer_Admin {

	/** @var Conexao_Lazer_Exporter */
	protected $exporter;

	/** @var Conexao_Lazer_Importer */
	protected $importer;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->exporter = new Conexao_Lazer_Exporter();
		$this->importer = new Conexao_Lazer_Importer();

		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_post_conexao_export_lazer', array( $this, 'handle_export' ) );
		add_action( 'admin_post_conexao_import_lazer', array( $this, 'handle_import' ) );
		add_action( 'admin_post_conexao_import_lazer_preview', array( $this, 'handle_import_preview' ) );
	}

	/**
	 * Register the Export / Import submenu pages under the Lazer menu.
	 */
	public function register_admin_menu() {
		// The leisure CPT menu slug is "edit.php?post_type=leisure".
		$parent = 'edit.php?post_type=leisure';

		add_submenu_page(
			$parent,
			__( 'Exportar Lazer', 'conexao-leisure-migration' ),
			__( 'Exportar Lazer', 'conexao-leisure-migration' ),
			'manage_options',
			'conexao-lazer-export',
			array( $this, 'render_export_page' )
		);

		add_submenu_page(
			$parent,
			__( 'Importar Lazer', 'conexao-leisure-migration' ),
			__( 'Importar Lazer', 'conexao-leisure-migration' ),
			'manage_options',
			'conexao-lazer-import',
			array( $this, 'render_import_page' )
		);
	}

	/**
	 * Handle the export download request.
	 */
	public function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export Lazer data.', 'conexao-leisure-migration' ) );
		}

		check_admin_referer( 'conexao_export_lazer', 'conexao_export_nonce' );

		$this->exporter->download();
	}

	/**
	 * Handle the import dry-run/preview request.
	 */
	public function handle_import_preview() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to import Lazer data.', 'conexao-leisure-migration' ) );
		}

		check_admin_referer( 'conexao_import_lazer', 'conexao_import_nonce' );

		$redirect = admin_url( 'admin.php?page=conexao-lazer-import' );

		if ( empty( $_FILES['conexao_lazer_import_file'] ) || empty( $_FILES['conexao_lazer_import_file']['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'conexao_lazer_import_error', 'no_file', $redirect ) );
			exit;
		}

		$file = $_FILES['conexao_lazer_import_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$preview = $this->importer->import_file( $file, array( 'dry_run' => true ) );

		$args = array(
			'conexao_lazer_preview'     => 1,
			'conexao_lazer_found'       => $preview['found'],
			'conexao_lazer_created'     => $preview['created'],
			'conexao_lazer_updated'     => $preview['updated'],
			'conexao_lazer_skipped'     => $preview['skipped'],
			'conexao_lazer_failed'      => $preview['failed'],
			'conexao_lazer_images'      => $preview['images_imported'],
			'conexao_lazer_tax_created' => $preview['taxonomies_created'],
			'conexao_lazer_tax_matched' => $preview['taxonomies_matched'],
		);

		wp_safe_redirect( add_query_arg( $args, $redirect ) );
		exit;
	}

	/**
	 * Handle the import request (after dry-run confirmation).
	 */
	public function handle_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to import Lazer data.', 'conexao-leisure-migration' ) );
		}

		check_admin_referer( 'conexao_import_lazer', 'conexao_import_nonce' );

		$redirect = admin_url( 'admin.php?page=conexao-lazer-import' );

		if ( empty( $_FILES['conexao_lazer_import_file'] ) || empty( $_FILES['conexao_lazer_import_file']['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'conexao_lazer_import_error', 'no_file', $redirect ) );
			exit;
		}

		$file    = $_FILES['conexao_lazer_import_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$strategy = isset( $_POST['conexao_lazer_duplicate_strategy'] ) ? sanitize_key( wp_unslash( $_POST['conexao_lazer_duplicate_strategy'] ) ) : 'update';
		if ( ! in_array( $strategy, array( 'update', 'skip' ), true ) ) {
			$strategy = 'update';
		}

		$stats = $this->importer->import_file(
			$file,
			array(
				'dry_run' => false,
				'update'  => ( 'update' === $strategy ),
			)
		);

		$args = array(
			'conexao_lazer_result'      => 1,
			'conexao_lazer_found'       => $stats['found'],
			'conexao_lazer_created'     => $stats['created'],
			'conexao_lazer_updated'     => $stats['updated'],
			'conexao_lazer_skipped'     => $stats['skipped'],
			'conexao_lazer_failed'      => $stats['failed'],
			'conexao_lazer_images'      => $stats['images_imported'],
			'conexao_lazer_tax_created' => $stats['taxonomies_created'],
			'conexao_lazer_tax_matched' => $stats['taxonomies_matched'],
		);

		$errors = isset( $stats['errors'] ) && is_array( $stats['errors'] ) ? array_slice( $stats['errors'], 0, 20 ) : array();
		if ( ! empty( $errors ) ) {
			$args['conexao_lazer_import_errors'] = rawurlencode( wp_json_encode( $errors ) );
		}

		if ( ! empty( $stats['verification'] ) && is_array( $stats['verification'] ) ) {
			$args['conexao_lazer_verify_total']   = $stats['verification']['leisure_total'];
			$args['conexao_lazer_verify_pub']     = $stats['verification']['leisure_published'];
			$args['conexao_lazer_verify_thumbs']  = $stats['verification']['with_thumbnail'];
			$args['conexao_lazer_verify_uuid']    = $stats['verification']['with_uuid'];
			$args['conexao_lazer_verify_official'] = $stats['verification']['official_website'];
			$args['conexao_lazer_verify_discover'] = $stats['verification']['discover_ireland'];
		}

		wp_safe_redirect( add_query_arg( $args, $redirect ) );
		exit;
	}

	/**
	 * Render the Export Lazer admin page.
	 */
	public function render_export_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$count = $this->exporter->count_items();
		?>
		<div class="wrap conexao-lazer-transfer">
			<h1><?php esc_html_e( 'Exportar Lazer', 'conexao-leisure-migration' ); ?></h1>
			<p><?php esc_html_e( 'Export all /lazer/ locations from this installation into a portable JSON file. You can then import this file into the production website (or another WordPress installation) with the same or a compatible Lazer structure.', 'conexao-leisure-migration' ); ?></p>

			<div class="conexao-lazer-card" style="background:#fff;padding:20px;border:1px solid #ccd0d4;margin-top:12px;max-width:720px;">
				<h2><?php esc_html_e( 'Available locations', 'conexao-leisure-migration' ); ?></h2>
				<p style="font-size:16px;">
					<strong><?php echo esc_html( number_format_i18n( $count ) ); ?></strong>
					<?php echo esc_html( _n( 'Lazer location ready to export', 'Lazer locations ready to export', $count, 'conexao-leisure-migration' ) ); ?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="conexao_export_lazer">
					<?php wp_nonce_field( 'conexao_export_lazer', 'conexao_export_nonce' ); ?>
					<?php submit_button( __( 'Download Export File', 'conexao-leisure-migration' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<div class="conexao-lazer-card" style="background:#fff;padding:20px;border:1px solid #ccd0d4;margin-top:12px;max-width:720px;">
				<h2><?php esc_html_e( 'What is exported', 'conexao-leisure-migration' ); ?></h2>
				<ul style="list-style:disc;padding-left:20px;line-height:1.7;">
					<li><?php esc_html_e( 'Title, full description, short description, status, and slug', 'conexao-leisure-migration' ); ?></li>
					<li><?php esc_html_e( 'County, town, and address', 'conexao-leisure-migration' ); ?></li>
					<li><?php esc_html_e( 'Official website URL and Discover Ireland URL (kept separate)', 'conexao-leisure-migration' ); ?></li>
					<li><?php esc_html_e( 'Featured / priority flag and all useful attributes', 'conexao-leisure-migration' ); ?></li>
					<li><?php esc_html_e( 'Categories, counties, and tags', 'conexao-leisure-migration' ); ?></li>
					<li><?php esc_html_e( 'Featured image reference (downloadable local image URL)', 'conexao-leisure-migration' ); ?></li>
					<li><?php esc_html_e( 'Wikimedia Commons image info — source URL, author, license, attribution', 'conexao-leisure-migration' ); ?></li>
					<li><?php esc_html_e( 'A stable unique ID per item (so re-imports never duplicate)', 'conexao-leisure-migration' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Import Lazer admin page (with dry-run preview + results).
	 */
	public function render_import_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$error = isset( $_GET['conexao_lazer_import_error'] ) ? sanitize_key( wp_unslash( $_GET['conexao_lazer_import_error'] ) ) : '';

		// Result / preview state.
		$is_preview = isset( $_GET['conexao_lazer_preview'] );
		$is_result  = isset( $_GET['conexao_lazer_result'] );

		$found       = isset( $_GET['conexao_lazer_found'] ) ? (int) $_GET['conexao_lazer_found'] : 0;
		$created     = isset( $_GET['conexao_lazer_created'] ) ? (int) $_GET['conexao_lazer_created'] : 0;
		$updated     = isset( $_GET['conexao_lazer_updated'] ) ? (int) $_GET['conexao_lazer_updated'] : 0;
		$skipped     = isset( $_GET['conexao_lazer_skipped'] ) ? (int) $_GET['conexao_lazer_skipped'] : 0;
		$failed      = isset( $_GET['conexao_lazer_failed'] ) ? (int) $_GET['conexao_lazer_failed'] : 0;
		$images      = isset( $_GET['conexao_lazer_images'] ) ? (int) $_GET['conexao_lazer_images'] : 0;
		$tax_created = isset( $_GET['conexao_lazer_tax_created'] ) ? (int) $_GET['conexao_lazer_tax_created'] : 0;
		$tax_matched = isset( $_GET['conexao_lazer_tax_matched'] ) ? (int) $_GET['conexao_lazer_tax_matched'] : 0;

		$errors = isset( $_GET['conexao_lazer_import_errors'] ) ? json_decode( rawurldecode( wp_unslash( $_GET['conexao_lazer_import_errors'] ) ), true ) : array();
		if ( ! is_array( $errors ) ) {
			$errors = array();
		}

		$verify_total    = isset( $_GET['conexao_lazer_verify_total'] ) ? (int) $_GET['conexao_lazer_verify_total'] : 0;
		$verify_pub      = isset( $_GET['conexao_lazer_verify_pub'] ) ? (int) $_GET['conexao_lazer_verify_pub'] : 0;
		$verify_thumbs   = isset( $_GET['conexao_lazer_verify_thumbs'] ) ? (int) $_GET['conexao_lazer_verify_thumbs'] : 0;
		$verify_uuid     = isset( $_GET['conexao_lazer_verify_uuid'] ) ? (int) $_GET['conexao_lazer_verify_uuid'] : 0;
		$verify_official = isset( $_GET['conexao_lazer_verify_official'] ) ? (int) $_GET['conexao_lazer_verify_official'] : 0;
		$verify_discover = isset( $_GET['conexao_lazer_verify_discover'] ) ? (int) $_GET['conexao_lazer_verify_discover'] : 0;
		?>
		<div class="wrap conexao-lazer-transfer">
			<h1><?php esc_html_e( 'Importar Lazer', 'conexao-leisure-migration' ); ?></h1>
			<p><?php esc_html_e( 'Upload a Lazer export file (JSON) to import /lazer/ locations into this installation. The importer validates the file, shows a preview, and only writes data after you confirm.', 'conexao-leisure-migration' ); ?></p>

			<?php if ( $error ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><strong><?php esc_html_e( 'Import error', 'conexao-leisure-migration' ); ?></strong></p>
					<p>
						<?php
						if ( 'no_file' === $error ) {
							esc_html_e( 'Please choose a JSON export file to upload.', 'conexao-leisure-migration' );
						} else {
							esc_html_e( 'The file could not be imported.', 'conexao-leisure-migration' );
						}
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $is_preview ) : ?>
				<div class="notice notice-info is-dismissible">
					<p><strong><?php esc_html_e( 'Import preview (dry run)', 'conexao-leisure-migration' ); ?></strong></p>
					<p><?php esc_html_e( 'Nothing was written yet. Review the numbers below and confirm to run the real import.', 'conexao-leisure-migration' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $is_result ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><strong><?php esc_html_e( 'Import complete', 'conexao-leisure-migration' ); ?></strong></p>
				</div>
			<?php endif; ?>

			<?php if ( $is_preview || $is_result ) : ?>
				<div class="conexao-lazer-card" style="background:#fff;padding:20px;border:1px solid #ccd0d4;margin-top:12px;max-width:720px;">
					<h2><?php echo $is_preview ? esc_html__( 'Preview', 'conexao-leisure-migration' ) : esc_html__( 'Result', 'conexao-leisure-migration' ); ?></h2>
					<ul style="list-style:disc;padding-left:20px;line-height:1.8;">
						<li><?php echo esc_html( sprintf( __( 'Items found in file: %d', 'conexao-leisure-migration' ), $found ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'New items to create: %d', 'conexao-leisure-migration' ), $created ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Existing items to update: %d', 'conexao-leisure-migration' ), $updated ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Items skipped: %d', 'conexao-leisure-migration' ), $skipped ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Items failed: %d', 'conexao-leisure-migration' ), $failed ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Images to import: %d', 'conexao-leisure-migration' ), $images ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Taxonomy terms to create: %d', 'conexao-leisure-migration' ), $tax_created ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Taxonomy terms matched: %d', 'conexao-leisure-migration' ), $tax_matched ) ); ?></li>
					</ul>

					<?php if ( ! empty( $errors ) && is_array( $errors ) ) : ?>
						<div class="notice notice-error is-dismissible">
							<p><strong><?php esc_html_e( 'Errors', 'conexao-leisure-migration' ); ?></strong></p>
							<ul style="list-style:disc;padding-left:20px;">
								<?php foreach ( $errors as $err ) : ?>
									<li><?php echo esc_html( $err ); ?></li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>

					<?php if ( $is_result && $verify_total > 0 ) : ?>
						<h3><?php esc_html_e( 'Post-import verification', 'conexao-leisure-migration' ); ?></h3>
						<ul style="list-style:disc;padding-left:20px;line-height:1.8;">
							<li><?php echo esc_html( sprintf( __( 'Total Lazer locations on site: %d', 'conexao-leisure-migration' ), $verify_total ) ); ?></li>
							<li><?php echo esc_html( sprintf( __( 'Published: %d', 'conexao-leisure-migration' ), $verify_pub ) ); ?></li>
							<li><?php echo esc_html( sprintf( __( 'With featured image: %d', 'conexao-leisure-migration' ), $verify_thumbs ) ); ?></li>
							<li><?php echo esc_html( sprintf( __( 'With stable ID: %d', 'conexao-leisure-migration' ), $verify_uuid ) ); ?></li>
							<li><?php echo esc_html( sprintf( __( 'With Official Website URL: %d', 'conexao-leisure-migration' ), $verify_official ) ); ?></li>
							<li><?php echo esc_html( sprintf( __( 'With Discover Ireland URL: %d', 'conexao-leisure-migration' ), $verify_discover ) ); ?></li>
						</ul>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="conexao-lazer-card" style="background:#fff;padding:20px;border:1px solid #ccd0d4;margin-top:12px;max-width:720px;">
				<h2><?php esc_html_e( 'Upload Export File', 'conexao-leisure-migration' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="<?php echo $is_preview ? 'conexao_import_lazer' : 'conexao_import_lazer_preview'; ?>">
					<?php wp_nonce_field( 'conexao_import_lazer', 'conexao_import_nonce' ); ?>

					<p>
						<label for="conexao_lazer_import_file"><strong><?php esc_html_e( 'Export file (.json)', 'conexao-leisure-migration' ); ?></strong></label><br>
						<input type="file" name="conexao_lazer_import_file" id="conexao_lazer_import_file" accept=".json,application/json" required>
					</p>

					<p>
						<label for="conexao_lazer_duplicate_strategy"><strong><?php esc_html_e( 'If a location already exists', 'conexao-leisure-migration' ); ?></strong></label><br>
						<select name="conexao_lazer_duplicate_strategy" id="conexao_lazer_duplicate_strategy">
							<option value="update"><?php esc_html_e( 'Update the existing location', 'conexao-leisure-migration' ); ?></option>
							<option value="skip"><?php esc_html_e( 'Skip the existing location', 'conexao-leisure-migration' ); ?></option>
						</select>
						<span class="description"><?php esc_html_e( 'Locations are matched by stable unique ID, then slug, then title.', 'conexao-leisure-migration' ); ?></span>
					</p>

					<?php if ( $is_preview ) : ?>
						<p style="color:#b32d2e;font-weight:bold;">
							<?php esc_html_e( 'Confirm: this will write data to the site.', 'conexao-leisure-migration' ); ?>
						</p>
						<?php submit_button( __( 'Confirm & Import Now', 'conexao-leisure-migration' ), 'primary', 'submit', false ); ?>
					<?php else : ?>
						<?php submit_button( __( 'Preview Import (Dry Run)', 'conexao-leisure-migration' ), 'primary', 'submit', false ); ?>
					<?php endif; ?>
				</form>
			</div>

			<div class="conexao-lazer-card" style="background:#fff;padding:20px;border:1px solid #ccd0d4;margin-top:12px;max-width:720px;">
				<h2><?php esc_html_e( 'What happens on import', 'conexao-leisure-migration' ); ?></h2>
				<ul style="list-style:disc;padding-left:20px;line-height:1.7;">
					<li><?php esc_html_e( 'The file is validated before any data is written.', 'conexao-leisure-migration' ); ?></li>
					<li><?php esc_html_e( 'Only /lazer/ (leisure) content is affected — events, guides, jobs, courses, blog posts, pages and supporters are untouched.', 'conexao-leisure-migration' ); ?></li>
					<li><?php esc_html_e( 'Existing locations are matched by stable ID, slug or title, and updated or skipped.', 'conexao-leisure-migration' ); ?></li>
					<li><?php esc_html_e( 'Local images are downloaded into the Media Library. Localhost URLs are skipped (the production site cannot reach them).', 'conexao-leisure-migration' ); ?></li>
					<li><?php esc_html_e( 'Wikimedia Commons source/author/license/attribution metadata is preserved but the image is not re-downloaded.', 'conexao-leisure-migration' ); ?></li>
					<li><?php esc_html_e( 'Taxonomies are matched by name; missing terms are created (no duplicates).', 'conexao-leisure-migration' ); ?></li>
					<li><?php esc_html_e( 'Official Website and Discover Ireland URLs are kept separate and independent.', 'conexao-leisure-migration' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}
}