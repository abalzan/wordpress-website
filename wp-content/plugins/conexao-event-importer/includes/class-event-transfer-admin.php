<?php
/**
 * Event Export / Import admin interface.
 *
 * Adds "Export Events" and "Import Events" pages under the Event Import menu,
 * handling the export download and the import file upload.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Event_Transfer_Admin {

	/** @var Conexao_Event_Export */
	protected $exporter;

	/** @var Conexao_Event_Import */
	protected $importer;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->exporter = new Conexao_Event_Export();
		$this->importer = new Conexao_Event_Import();

		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'intercept_oversized_post' ) );
		add_action( 'admin_post_conexao_export_events', array( $this, 'handle_export' ) );
		add_action( 'admin_post_conexao_import_events', array( $this, 'handle_import' ) );
	}

	/**
	 * Register the Export / Import submenu pages.
	 */
	public function register_admin_menu() {
		add_submenu_page(
			'conexao-event-import',
			__( 'Export Events', 'conexao-event-importer' ),
			__( 'Export Events', 'conexao-event-importer' ),
			'manage_options',
			'conexao-event-export',
			array( $this, 'render_export_page' )
		);

		add_submenu_page(
			'conexao-event-import',
			__( 'Import Events', 'conexao-event-importer' ),
			__( 'Import Events', 'conexao-event-importer' ),
			'manage_options',
			'conexao-event-import-file',
			array( $this, 'render_import_page' )
		);
	}

	/**
	 * Handle the export download request.
	 */
	public function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export events.', 'conexao-event-importer' ) );
		}

		check_admin_referer( 'conexao_export_events', 'conexao_export_nonce' );

		$this->exporter->download();
	}

	/**
	 * Handle the import file upload request.
	 */
	public function handle_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to import events.', 'conexao-event-importer' ) );
		}

		check_admin_referer( 'conexao_import_events', 'conexao_import_nonce' );

		$redirect = admin_url( 'admin.php?page=conexao-event-import-file' );

		if ( empty( $_FILES['conexao_import_file'] ) ) {
			wp_safe_redirect( add_query_arg( 'conexao_import_error', 'no_file', $redirect ) );
			exit;
		}

		$file = $_FILES['conexao_import_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$upload_error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_OK;
		if ( UPLOAD_ERR_INI_SIZE === $upload_error || UPLOAD_ERR_FORM_SIZE === $upload_error ) {
			wp_safe_redirect( add_query_arg( 'conexao_import_error', 'too_large', $redirect ) );
			exit;
		}

		$strategy = isset( $_POST['conexao_duplicate_strategy'] ) ? sanitize_key( wp_unslash( $_POST['conexao_duplicate_strategy'] ) ) : 'update';
		if ( ! in_array( $strategy, array( 'update', 'skip' ), true ) ) {
			$strategy = 'update';
		}

		$stats = $this->importer->import_file( $file, array( 'duplicate_strategy' => $strategy ) );

		$args = array(
			'conexao_imported' => $stats['imported'],
			'conexao_updated'  => $stats['updated'],
			'conexao_skipped'  => $stats['skipped'],
			'conexao_failed'   => $stats['failed'],
		);

		if ( ! empty( $stats['errors'] ) ) {
			$args['conexao_import_errors'] = rawurlencode( wp_json_encode( array_slice( $stats['errors'], 0, 20 ) ) );
		}

		wp_safe_redirect( add_query_arg( $args, $redirect ) );
		exit;
	}

	/**
	 * Effective maximum upload size: the lower of upload_max_filesize and
	 * post_max_size, in bytes.
	 *
	 * @return int
	 */
	protected function get_max_upload_bytes() {
		return min(
			wp_convert_hr_to_bytes( (string) ini_get( 'upload_max_filesize' ) ),
			wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) )
		);
	}

	/**
	 * Detect POST requests that PHP rejected because the body exceeded
	 * post_max_size. In that scenario PHP empties $_POST and $_FILES, so the
	 * nonce check and the regular handler never run and the user would be
	 * silently bounced away with no feedback. Only requests originating from
	 * this import screen are intercepted.
	 */
	public function intercept_oversized_post() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}

		if ( ! empty( $_POST ) || ! empty( $_FILES ) ) {
			return;
		}

		$content_length = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
		if ( $content_length <= 0 ) {
			return;
		}

		$post_max = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );
		if ( $post_max <= 0 || $content_length <= $post_max ) {
			return;
		}

		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		if ( '' === $referer || false === strpos( $referer, 'page=conexao-event-import-file' ) ) {
			return;
		}

		wp_safe_redirect(
			add_query_arg(
				'conexao_import_error',
				'post_max_size',
				admin_url( 'admin.php?page=conexao-event-import-file' )
			)
		);
		exit;
	}

	/**
	 * Render the Export Events admin page.
	 */
	public function render_export_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$count = $this->exporter->count_events();
		?>
		<div class="wrap conexao-event-transfer">
			<h1><?php esc_html_e( 'Export Events', 'conexao-event-importer' ); ?></h1>
			<p><?php esc_html_e( 'Export all events from this installation into a portable JSON file. You can then import this file into another WordPress installation running the same event structure.', 'conexao-event-importer' ); ?></p>

			<div class="conexao-transfer-card">
				<h2><?php esc_html_e( 'Available Events', 'conexao-event-importer' ); ?></h2>
				<p class="conexao-transfer-count">
					<strong><?php echo esc_html( number_format_i18n( $count ) ); ?></strong>
					<?php echo esc_html( _n( 'event ready to export', 'events ready to export', $count, 'conexao-event-importer' ) ); ?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="conexao_export_events">
					<?php wp_nonce_field( 'conexao_export_events', 'conexao_export_nonce' ); ?>
					<?php submit_button( __( 'Download Export File', 'conexao-event-importer' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<div class="conexao-transfer-card">
				<h2><?php esc_html_e( 'What is exported', 'conexao-event-importer' ); ?></h2>
				<ul class="conexao-transfer-list">
					<li><?php esc_html_e( 'Event title, description, excerpt, status, and slug', 'conexao-event-importer' ); ?></li>
					<li><?php esc_html_e( 'Event dates and times (start/end)', 'conexao-event-importer' ); ?></li>
					<li><?php esc_html_e( 'Location, venue, address, and county', 'conexao-event-importer' ); ?></li>
					<li><?php esc_html_e( 'Categories, counties, tags, and towns', 'conexao-event-importer' ); ?></li>
					<li><?php esc_html_e( 'External URLs, organizer, price, and registration info', 'conexao-event-importer' ); ?></li>
					<li><?php esc_html_e( 'Featured image references (downloaded on import)', 'conexao-event-importer' ); ?></li>
					<li><?php esc_html_e( 'Event status (published, draft, needs review, etc.)', 'conexao-event-importer' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Import Events admin page.
	 */
	public function render_import_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$imported = isset( $_GET['conexao_imported'] ) ? (int) $_GET['conexao_imported'] : null;
		$updated  = isset( $_GET['conexao_updated'] ) ? (int) $_GET['conexao_updated'] : null;
		$skipped  = isset( $_GET['conexao_skipped'] ) ? (int) $_GET['conexao_skipped'] : null;
		$failed   = isset( $_GET['conexao_failed'] ) ? (int) $_GET['conexao_failed'] : null;
		$errors   = isset( $_GET['conexao_import_errors'] ) ? json_decode( rawurldecode( wp_unslash( $_GET['conexao_import_errors'] ) ), true ) : array();
		$error    = isset( $_GET['conexao_import_error'] ) ? sanitize_key( wp_unslash( $_GET['conexao_import_error'] ) ) : '';

		$max_upload_bytes = $this->get_max_upload_bytes();

		$has_result = null !== $imported;
		?>
		<div class="wrap conexao-event-transfer">
			<h1><?php esc_html_e( 'Import Events', 'conexao-event-importer' ); ?></h1>
			<p><?php esc_html_e( 'Upload an event export file (JSON) to import events into this installation.', 'conexao-event-importer' ); ?></p>

			<?php if ( $error ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><strong><?php esc_html_e( 'Import error', 'conexao-event-importer' ); ?></strong></p>
					<p>
						<?php
						if ( 'no_file' === $error ) {
							esc_html_e( 'Please choose an event export file (.json) to upload.', 'conexao-event-importer' );
						} elseif ( 'too_large' === $error ) {
							echo esc_html( sprintf(
								/* translators: %s: maximum upload size, e.g. "64 MB" */
								__( 'The selected file exceeds the maximum upload size (%s). Increase the PHP upload_max_filesize / post_max_size limits, or split the export file.', 'conexao-event-importer' ),
								size_format( $max_upload_bytes )
							) );
						} elseif ( 'post_max_size' === $error ) {
							echo esc_html( sprintf(
								/* translators: %s: maximum POST body size, e.g. "64 MB" */
								__( 'The upload was rejected by the server because it exceeded the POST size limit (%s). Increase the PHP post_max_size limit, or split the export file.', 'conexao-event-importer' ),
								size_format( $max_upload_bytes )
							) );
						} else {
							esc_html_e( 'The file could not be uploaded.', 'conexao-event-importer' );
						}
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $has_result ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><strong><?php esc_html_e( 'Import complete', 'conexao-event-importer' ); ?></strong></p>
					<ul class="conexao-import-stats">
						<li><?php echo esc_html( sprintf( __( 'Imported: %d', 'conexao-event-importer' ), $imported ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Updated: %d', 'conexao-event-importer' ), $updated ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Skipped: %d', 'conexao-event-importer' ), $skipped ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Failed: %d', 'conexao-event-importer' ), $failed ) ); ?></li>
					</ul>
				</div>

				<?php if ( ! empty( $errors ) && is_array( $errors ) ) : ?>
					<div class="notice notice-error is-dismissible">
						<p><strong><?php esc_html_e( 'Errors', 'conexao-event-importer' ); ?></strong></p>
						<ul class="conexao-import-errors">
							<?php foreach ( $errors as $error_message ) : ?>
								<li><?php echo esc_html( $error_message ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<div class="conexao-transfer-card">
				<h2><?php esc_html_e( 'Upload Export File', 'conexao-event-importer' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="conexao_import_events">
					<?php wp_nonce_field( 'conexao_import_events', 'conexao_import_nonce' ); ?>

					<p>
						<label for="conexao_import_file"><strong><?php esc_html_e( 'Export file (.json)', 'conexao-event-importer' ); ?></strong></label><br>
						<input type="file" name="conexao_import_file" id="conexao_import_file" accept=".json,application/json" required>
						<span class="description"><?php echo esc_html( sprintf( __( 'Maximum upload size: %s.', 'conexao-event-importer' ), size_format( $max_upload_bytes ) ) ); ?></span>
					</p>

					<p>
						<label for="conexao_duplicate_strategy"><strong><?php esc_html_e( 'If an event already exists', 'conexao-event-importer' ); ?></strong></label><br>
						<select name="conexao_duplicate_strategy" id="conexao_duplicate_strategy">
							<option value="update"><?php esc_html_e( 'Update the existing event', 'conexao-event-importer' ); ?></option>
							<option value="skip"><?php esc_html_e( 'Skip the existing event', 'conexao-event-importer' ); ?></option>
						</select>
						<span class="description"><?php esc_html_e( 'Events are matched by their unique export ID, source ID, source URL, or title + date.', 'conexao-event-importer' ); ?></span>
					</p>

					<?php submit_button( __( 'Import Events', 'conexao-event-importer' ), 'primary', 'submit', false ); ?>
				</form>
				<script>
				(function () {
					var input = document.getElementById('conexao_import_file');
					var form = input ? input.closest('form') : null;
					if (!input || !form) { return; }
					form.addEventListener('submit', function (event) {
						var maxBytes = <?php echo (int) $max_upload_bytes; ?>;
						if (input.files && input.files.length && input.files[0].size > maxBytes) {
							event.preventDefault();
							window.alert(<?php echo wp_json_encode( sprintf( __( 'The selected file is larger than the maximum upload size (%s). Choose a smaller export file or increase the PHP upload limits.', 'conexao-event-importer' ), size_format( $max_upload_bytes ) ) ); ?>);
						}
					});
				})();
				</script>
			</div>

			<div class="conexao-transfer-card">
				<h2><?php esc_html_e( 'What happens on import', 'conexao-event-importer' ); ?></h2>
				<ul class="conexao-transfer-list">
					<li><?php esc_html_e( 'The file is validated before any data is written.', 'conexao-event-importer' ); ?></li>
					<li><?php esc_html_e( 'Event posts are created with their metadata and taxonomies.', 'conexao-event-importer' ); ?></li>
					<li><?php esc_html_e( 'Featured images are downloaded into the Media Library when possible.', 'conexao-event-importer' ); ?></li>
					<li><?php esc_html_e( 'Existing events are updated or skipped based on your selection.', 'conexao-event-importer' ); ?></li>
					<li><?php esc_html_e( 'Importing the same file twice will not create duplicates.', 'conexao-event-importer' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}
}
