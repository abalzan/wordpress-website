<?php
/**
 * Lazer Export / Import admin interface.
 *
 * Adds "Export Lazer", "Import Lazer" and "Manutenção" pages under the Lazer
 * menu, handling the ZIP export download, the ZIP import with dry-run preview,
 * and the legacy-data cleanup operation.
 *
 * @package Conexao_Lazer_Migration
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Lazer_Transfer_Admin {

	/** @var Conexao_Lazer_Exporter */
	protected $exporter;

	/** @var Conexao_Lazer_Importer */
	protected $importer;

	/** @var Conexao_Lazer_Maintenance */
	protected $maintenance;

	public function __construct() {
		$this->exporter    = new Conexao_Lazer_Exporter();
		$this->importer    = new Conexao_Lazer_Importer();
		$this->maintenance = new Conexao_Lazer_Maintenance();

		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_post_conexao_export_lazer', array( $this, 'handle_export' ) );
		add_action( 'admin_post_conexao_import_lazer', array( $this, 'handle_import' ) );
		add_action( 'admin_post_conexao_import_lazer_preview', array( $this, 'handle_import_preview' ) );
		add_action( 'admin_post_conexao_lazer_cleanup', array( $this, 'handle_cleanup' ) );
	}

	public function register_admin_menu() {
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

		add_submenu_page(
			$parent,
			__( 'Manutenção Lazer', 'conexao-leisure-migration' ),
			__( 'Manutenção', 'conexao-leisure-migration' ),
			'manage_options',
			'conexao-lazer-maintenance',
			array( $this, 'render_maintenance_page' )
		);
	}

	public function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export Lazer data.', 'conexao-leisure-migration' ) );
		}

		check_admin_referer( 'conexao_export_lazer', 'conexao_export_nonce' );

		$this->exporter->download();
	}

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
			'conexao_lazer_images_reuse' => $preview['images_reused'],
			'conexao_lazer_images_missing' => $preview['images_missing'],
			'conexao_lazer_tax_created' => $preview['taxonomies_created'],
			'conexao_lazer_tax_matched' => $preview['taxonomies_matched'],
		);

		$errors = isset( $preview['errors'] ) && is_array( $preview['errors'] ) ? array_slice( $preview['errors'], 0, 20 ) : array();
		if ( ! empty( $errors ) ) {
			$args['conexao_lazer_import_errors'] = rawurlencode( wp_json_encode( $errors ) );
		}

		wp_safe_redirect( add_query_arg( $args, $redirect ) );
		exit;
	}

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

		$file     = $_FILES['conexao_lazer_import_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
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
			'conexao_lazer_images_reuse' => $stats['images_reused'],
			'conexao_lazer_images_missing' => $stats['images_missing'],
			'conexao_lazer_tax_created' => $stats['taxonomies_created'],
			'conexao_lazer_tax_matched' => $stats['taxonomies_matched'],
		);

		$errors = isset( $stats['errors'] ) && is_array( $stats['errors'] ) ? array_slice( $stats['errors'], 0, 20 ) : array();
		if ( ! empty( $errors ) ) {
			$args['conexao_lazer_import_errors'] = rawurlencode( wp_json_encode( $errors ) );
		}

		if ( ! empty( $stats['verification'] ) && is_array( $stats['verification'] ) ) {
			$args['conexao_lazer_verify_total']    = $stats['verification']['leisure_total'];
			$args['conexao_lazer_verify_pub']      = $stats['verification']['leisure_published'];
			$args['conexao_lazer_verify_thumbs']   = $stats['verification']['with_thumbnail'];
			$args['conexao_lazer_verify_uuid']     = $stats['verification']['with_uuid'];
			$args['conexao_lazer_verify_official'] = $stats['verification']['official_website'];
			$args['conexao_lazer_verify_discover'] = $stats['verification']['discover_ireland'];
		}

		wp_safe_redirect( add_query_arg( $args, $redirect ) );
		exit;
	}

	public function handle_cleanup() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to run Lazer maintenance.', 'conexao-leisure-migration' ) );
		}

		check_admin_referer( 'conexao_lazer_cleanup', 'conexao_lazer_cleanup_nonce' );

		$redirect = admin_url( 'admin.php?page=conexao-lazer-maintenance' );

		$confirm = isset( $_POST['conexao_lazer_cleanup_confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['conexao_lazer_cleanup_confirm'] ) ) : '';
		if ( 'CLEANUP' !== $confirm ) {
			wp_safe_redirect( add_query_arg( 'conexao_lazer_cleanup_error', 'confirm', $redirect ) );
			exit;
		}

		$result = $this->maintenance->run_cleanup();

		$args = array(
			'conexao_lazer_cleanup_done' => 1,
			'conexao_lazer_cleanup_urls' => $result['removed_external_urls'],
			'conexao_lazer_cleanup_status' => $result['removed_external_status'],
			'conexao_lazer_cleanup_items' => $result['affected_items'],
		);

		wp_safe_redirect( add_query_arg( $args, $redirect ) );
		exit;
	}

	public function render_export_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$count = $this->exporter->count_items();
		?>
		<div class="wrap conexao-lazer-transfer">
			<h1><?php esc_html_e( 'Exportar Lazer', 'conexao-leisure-migration' ); ?></h1>
			<p><?php esc_html_e( 'Export all /lazer/ locations from this installation into a self-contained ZIP package. The package includes the actual image files from the Media Library, so the production website can import them as local images without depending on Wikimedia Commons or any other external host.', 'conexao-leisure-migration' ); ?></p>

			<div class="conexao-lazer-card" style="background:#fff;padding:20px;border:1px solid #ccd0d4;margin-top:12px;max-width:720px;">
				<h2><?php esc_html_e( 'Available locations', 'conexao-leisure-migration' ); ?></h2>
				<p style="font-size:16px;">
					<strong><?php echo esc_html( number_format_i18n( $count ) ); ?></strong>
					<?php echo esc_html( _n( 'Lazer location ready to export', 'Lazer locations ready to export', $count, 'conexao-leisure-migration' ) ); ?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="conexao_export_lazer">
					<?php wp_nonce_field( 'conexao_export_lazer', 'conexao_export_nonce' ); ?>
					<?php submit_button( __( 'Download Export Package (ZIP)', 'conexao-leisure-migration' ), 'primary', 'submit', false ); ?>
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
					<li><?php esc_html_e( 'The actual image files from the Media Library', 'conexao-leisure-migration' ); ?></li>
					<li><?php esc_html_e( 'Image metadata — alt text, source, author, license, attribution', 'conexao-leisure-migration' ); ?></li>
					<li><?php esc_html_e( 'A stable unique ID per item and per image (so re-imports never duplicate)', 'conexao-leisure-migration' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}

	public function render_import_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$error = isset( $_GET['conexao_lazer_import_error'] ) ? sanitize_key( wp_unslash( $_GET['conexao_lazer_import_error'] ) ) : '';

		$is_preview = isset( $_GET['conexao_lazer_preview'] );
		$is_result  = isset( $_GET['conexao_lazer_result'] );

		$found         = isset( $_GET['conexao_lazer_found'] ) ? (int) $_GET['conexao_lazer_found'] : 0;
		$created       = isset( $_GET['conexao_lazer_created'] ) ? (int) $_GET['conexao_lazer_created'] : 0;
		$updated       = isset( $_GET['conexao_lazer_updated'] ) ? (int) $_GET['conexao_lazer_updated'] : 0;
		$skipped       = isset( $_GET['conexao_lazer_skipped'] ) ? (int) $_GET['conexao_lazer_skipped'] : 0;
		$failed        = isset( $_GET['conexao_lazer_failed'] ) ? (int) $_GET['conexao_lazer_failed'] : 0;
		$images        = isset( $_GET['conexao_lazer_images'] ) ? (int) $_GET['conexao_lazer_images'] : 0;
		$images_reuse  = isset( $_GET['conexao_lazer_images_reuse'] ) ? (int) $_GET['conexao_lazer_images_reuse'] : 0;
		$images_missing = isset( $_GET['conexao_lazer_images_missing'] ) ? (int) $_GET['conexao_lazer_images_missing'] : 0;
		$tax_created   = isset( $_GET['conexao_lazer_tax_created'] ) ? (int) $_GET['conexao_lazer_tax_created'] : 0;
		$tax_matched   = isset( $_GET['conexao_lazer_tax_matched'] ) ? (int) $_GET['conexao_lazer_tax_matched'] : 0;

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
			<p><?php esc_html_e( 'Upload a Lazer export package (ZIP) to import /lazer/ locations into this installation. The importer validates the package, shows a preview, and only writes data after you confirm. Images are imported as local Media Library attachments.', 'conexao-leisure-migration' ); ?></p>

			<?php if ( $error ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><strong><?php esc_html_e( 'Import error', 'conexao-leisure-migration' ); ?></strong></p>
					<p>
						<?php
						if ( 'no_file' === $error ) {
							esc_html_e( 'Please choose a ZIP export package to upload.', 'conexao-leisure-migration' );
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
						<li><?php echo esc_html( sprintf( __( 'Lazer items found in file: %d', 'conexao-leisure-migration' ), $found ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'New items to create: %d', 'conexao-leisure-migration' ), $created ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Existing items to update: %d', 'conexao-leisure-migration' ), $updated ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Items skipped: %d', 'conexao-leisure-migration' ), $skipped ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Items failed: %d', 'conexao-leisure-migration' ), $failed ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Images to import: %d', 'conexao-leisure-migration' ), $images ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Images to reuse: %d', 'conexao-leisure-migration' ), $images_reuse ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Images missing: %d', 'conexao-leisure-migration' ), $images_missing ) ); ?></li>
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
				<h2><?php esc_html_e( 'Upload Export Package', 'conexao-leisure-migration' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="<?php echo $is_preview ? 'conexao_import_lazer' : 'conexao_import_lazer_preview'; ?>">
					<?php wp_nonce_field( 'conexao_import_lazer', 'conexao_import_nonce' ); ?>

					<p>
						<label for="conexao_lazer_import_file"><strong><?php esc_html_e( 'Export package (.zip)', 'conexao-leisure-migration' ); ?></strong></label><br>
						<input type="file" name="conexao_lazer_import_file" id="conexao_lazer_import_file" accept=".zip,application/zip" required>
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
					<li><?php esc_html_e( 'The ZIP package is validated before any data is written.', 'conexao-leisure-migration' ); ?></li>
					<li><?php esc_html_e( 'Only /lazer/ (leisure) content is affected — events, guides, jobs, courses, blog posts, pages and supporters are untouched.', 'conexao-leisure-migration' ); ?></li>
					<li><?php esc_html_e( 'Existing locations are matched by stable ID, slug or title, and updated or skipped.', 'conexao-leisure-migration' ); ?></li>
					<li><?php esc_html_e( 'Image files from the package are imported into the WordPress Media Library as local attachments.', 'conexao-leisure-migration' ); ?></li>
					<li><?php esc_html_e( 'Existing images are reused (no duplicates) via stable image identifiers.', 'conexao-leisure-migration' ); ?></li>
					<li><?php esc_html_e( 'Wikimedia Commons source/author/license/attribution metadata is preserved as reference only.', 'conexao-leisure-migration' ); ?></li>
					<li><?php esc_html_e( 'Taxonomies are matched by name; missing terms are created (no duplicates).', 'conexao-leisure-migration' ); ?></li>
					<li><?php esc_html_e( 'Official Website and Discover Ireland URLs are kept separate and independent.', 'conexao-leisure-migration' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}

	public function render_maintenance_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$cleanup_done  = isset( $_GET['conexao_lazer_cleanup_done'] );
		$cleanup_error = isset( $_GET['conexao_lazer_cleanup_error'] ) ? sanitize_key( wp_unslash( $_GET['conexao_lazer_cleanup_error'] ) ) : '';
		$cleanup_urls  = isset( $_GET['conexao_lazer_cleanup_urls'] ) ? (int) $_GET['conexao_lazer_cleanup_urls'] : 0;
		$cleanup_status = isset( $_GET['conexao_lazer_cleanup_status'] ) ? (int) $_GET['conexao_lazer_cleanup_status'] : 0;
		$cleanup_items = isset( $_GET['conexao_lazer_cleanup_items'] ) ? (int) $_GET['conexao_lazer_cleanup_items'] : 0;

		$preview = $this->maintenance->preview_cleanup();
		?>
		<div class="wrap conexao-lazer-transfer">
			<h1><?php esc_html_e( 'Manutenção Lazer', 'conexao-leisure-migration' ); ?></h1>
			<p><?php esc_html_e( 'Clean up legacy data from the old external-image architecture. This operation is conservative: it only removes data that belongs to the old external-hotlink delivery model and never deletes valid Media Library images, attribution/license metadata, or images used by other content.', 'conexao-leisure-migration' ); ?></p>

			<?php if ( $cleanup_done ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><strong><?php esc_html_e( 'Cleanup complete', 'conexao-leisure-migration' ); ?></strong></p>
					<p>
						<?php echo esc_html( sprintf( __( 'Removed %d external image URL(s) and updated %d external status(es) across %d item(s).', 'conexao-leisure-migration' ), $cleanup_urls, $cleanup_status, $cleanup_items ) ); ?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( 'confirm' === $cleanup_error ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><strong><?php esc_html_e( 'Cleanup cancelled', 'conexao-leisure-migration' ); ?></strong></p>
					<p><?php esc_html_e( 'You must type CLEANUP to confirm the operation.', 'conexao-leisure-migration' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="conexao-lazer-card" style="background:#fff;padding:20px;border:1px solid #ccd0d4;margin-top:12px;max-width:720px;">
				<h2><?php esc_html_e( 'Legacy data preview', 'conexao-leisure-migration' ); ?></h2>
				<ul style="list-style:disc;padding-left:20px;line-height:1.8;">
					<li><?php echo esc_html( sprintf( __( 'Legacy external image URLs: %d', 'conexao-leisure-migration' ), $preview['legacy_external_urls'] ) ); ?></li>
					<li><?php echo esc_html( sprintf( __( 'Items with "external" image status: %d', 'conexao-leisure-migration' ), $preview['legacy_external_status'] ) ); ?></li>
					<li><?php echo esc_html( sprintf( __( 'Affected Lazer items: %d', 'conexao-leisure-migration' ), $preview['affected_items'] ) ); ?></li>
				</ul>

				<?php if ( ! empty( $preview['legacy_meta_keys'] ) ) : ?>
					<h3><?php esc_html_e( 'Legacy meta keys in database', 'conexao-leisure-migration' ); ?></h3>
					<ul style="list-style:disc;padding-left:20px;">
						<?php foreach ( $preview['legacy_meta_keys'] as $key => $count ) : ?>
							<li><code><?php echo esc_html( $key ); ?></code>: <?php echo esc_html( $count ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php if ( $preview['affected_items'] > 0 ) : ?>
					<h3><?php esc_html_e( 'Affected items', 'conexao-leisure-migration' ); ?></h3>
					<table class="widefat striped" style="max-width:100%;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Title', 'conexao-leisure-migration' ); ?></th>
								<th><?php esc_html_e( 'External URL', 'conexao-leisure-migration' ); ?></th>
								<th><?php esc_html_e( 'Status', 'conexao-leisure-migration' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( array_slice( $preview['items'], 0, 20 ) as $item ) : ?>
								<tr>
									<td><?php echo esc_html( $item['title'] ); ?></td>
									<td><?php echo $item['external_url'] ? esc_url( $item['external_url'] ) : '—'; ?></td>
									<td><?php echo esc_html( $item['status'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php if ( count( $preview['items'] ) > 20 ) : ?>
						<p class="description"><?php echo esc_html( sprintf( __( 'Showing first 20 of %d items.', 'conexao-leisure-migration' ), count( $preview['items'] ) ) ); ?></p>
					<?php endif; ?>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;">
						<input type="hidden" name="action" value="conexao_lazer_cleanup">
						<?php wp_nonce_field( 'conexao_lazer_cleanup', 'conexao_lazer_cleanup_nonce' ); ?>
						<p>
							<label for="conexao_lazer_cleanup_confirm"><strong><?php esc_html_e( 'Type CLEANUP to confirm', 'conexao-leisure-migration' ); ?></strong></label><br>
							<input type="text" name="conexao_lazer_cleanup_confirm" id="conexao_lazer_cleanup_confirm" required style="max-width:200px;">
						</p>
						<?php submit_button( __( 'Run Cleanup', 'conexao-leisure-migration' ), 'secondary', 'submit', false ); ?>
					</form>
				<?php else : ?>
					<p style="color:#46b450;font-weight:bold;"><?php esc_html_e( 'No legacy external-image data found. Nothing to clean up.', 'conexao-leisure-migration' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}