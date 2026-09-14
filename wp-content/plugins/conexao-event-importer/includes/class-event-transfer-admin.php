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

	/** @var Conexao_Event_Multi_Import */
	protected $multi_import;

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
		add_action( 'admin_post_conexao_export_events_multipart', array( $this, 'handle_export_multipart' ) );
		add_action( 'admin_post_conexao_download_export_part', array( $this, 'handle_download_part' ) );
		$this->multi_import = new Conexao_Event_Multi_Import();
		add_action( 'admin_post_conexao_import_events_multi', array( $this, 'handle_import_multi' ) );
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
	 * Handle the multi-file import request.
	 *
	 * Validates the upload, runs the multi-file orchestrator, and redirects
	 * back to the import page with a serialized batch report in the query
	 * string so the results can be displayed.
	 */
	public function handle_import_multi() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to import events.', 'conexao-event-importer' ) );
		}

		check_admin_referer( 'conexao_import_events_multi', 'conexao_import_multi_nonce' );

		$redirect = admin_url( 'admin.php?page=conexao-event-import-file' );

		if ( empty( $_FILES['conexao_import_files'] ) ) {
			wp_safe_redirect( add_query_arg( 'conexao_multi_error', 'no_files', $redirect ) );
			exit;
		}

		$strategy = isset( $_POST['conexao_multi_duplicate_strategy'] )
			? sanitize_key( wp_unslash( $_POST['conexao_multi_duplicate_strategy'] ) )
			: 'update';
		if ( ! in_array( $strategy, array( 'update', 'skip' ), true ) ) {
			$strategy = 'update';
		}

		set_time_limit( 0 );

		$report = $this->multi_import->run_batch( $_FILES['conexao_import_files'], array( 'duplicate_strategy' => $strategy ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$summary = array(
			'status'    => $report['status'],
			'files'     => isset( $report['totals']['files'] ) ? (int) $report['totals']['files'] : 0,
			'ok'        => isset( $report['totals']['files_success'] ) ? (int) $report['totals']['files_success'] : 0,
			'failed'    => isset( $report['totals']['files_failed'] ) ? (int) $report['totals']['files_failed'] : 0,
			'skipped'   => isset( $report['totals']['files_not_processed'] ) ? (int) $report['totals']['files_not_processed'] : 0,
			'imported'  => isset( $report['totals']['imported'] ) ? (int) $report['totals']['imported'] : 0,
			'updated'   => isset( $report['totals']['updated'] ) ? (int) $report['totals']['updated'] : 0,
			'events'    => isset( $report['totals']['discovered'] ) ? (int) $report['totals']['discovered'] : 0,
			'media'     => isset( $report['totals']['media_discovered'] ) ? (int) $report['totals']['media_discovered'] : 0,
			'duration'  => isset( $report['duration'] ) ? $report['duration'] : 0,
			'peak_mem'  => isset( $report['peak_memory_bytes'] ) ? (int) $report['peak_memory_bytes'] : 0,
		);

		$args = array(
			'conexao_multi_result' => rawurlencode( wp_json_encode( $summary ) ),
		);

		if ( ! empty( $report['totals']['errors'] ) ) {
			$args['conexao_multi_errors'] = rawurlencode( wp_json_encode( array_slice( $report['totals']['errors'], 0, 25 ) ) );
		}

		if ( ! empty( $report['manifest_error'] ) ) {
			$args['conexao_multi_error'] = 'manifest';
		} elseif ( 'no_files' === $report['status'] ) {
			$args['conexao_multi_error'] = 'no_files';
		}

		wp_safe_redirect( add_query_arg( $args, $redirect ) );
		exit;
	}
	/**
	 * Handle the multipart export request.
	 *
	 * Generates the parts into a dedicated run directory under the uploads
	 * folder, then redirects back to the export page, which lists the parts
	 * with secure download links. A failed part produces a clear error
	 * identifying how many parts were generated before the failure.
	 */
	public function handle_export_multipart() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export events.', 'conexao-event-importer' ) );
		}

		check_admin_referer( 'conexao_export_multipart', 'conexao_multipart_nonce' );

		$redirect = admin_url( 'admin.php?page=conexao-event-export' );

		$part_size = isset( $_POST['conexao_part_size'] )
			? absint( wp_unslash( $_POST['conexao_part_size'] ) )
			: Conexao_Event_Export::DEFAULT_PART_SIZE;

		if ( $part_size < Conexao_Event_Export::MIN_PART_SIZE || $part_size > Conexao_Event_Export::MAX_PART_SIZE ) {
			wp_safe_redirect( add_query_arg( 'conexao_multipart_error', 'part_size', $redirect ) );
			exit;
		}

		$root = $this->get_export_runs_root();
		$run  = 'stage-d-rollout-' . gmdate( 'Ymd-His' );
		$dir  = $root . '/' . $run;
		$n    = 2;
		while ( file_exists( $dir ) ) { // Never overwrite unrelated exports.
			$run = 'stage-d-rollout-' . gmdate( 'Ymd-His' ) . '-' . $n;
			$dir = $root . '/' . $run;
			$n++;
		}

		$result = $this->exporter->export_multipart( $dir, $part_size );

		if ( is_wp_error( $result ) ) {
			$message = sprintf(
				/* translators: %s: failure reason */
				__( 'Event export failed: %s', 'conexao-event-importer' ),
				$result->get_error_message()
			);

			$data = $result->get_error_data();
			if ( is_array( $data ) && isset( $data['completed_parts'] ) && is_array( $data['completed_parts'] ) ) {
				$completed = count( $data['completed_parts'] );
				$message   = sprintf(
					/* translators: %d: number of parts generated before the failure */
					_n(
						'%d part was generated before the failure.',
						'%d parts were generated before the failure.',
						$completed,
						'conexao-event-importer'
					),
					$completed
				) . ' ' . $message;
			}

			wp_die( esc_html( $message ) );
		}

		wp_safe_redirect( add_query_arg( 'conexao_multipart_run', rawurlencode( basename( $dir ) ), $redirect ) );
		exit;
	}

	/**
	 * Stream one generated export part (or the multipart manifest) to the
	 * browser as a download.
	 */
	public function handle_download_part() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to download export files.', 'conexao-event-importer' ) );
		}

		check_admin_referer( 'conexao_download_export_part', 'conexao_download_nonce' );

		$run  = isset( $_GET['run'] ) ? sanitize_text_field( wp_unslash( $_GET['run'] ) ) : '';
		$file = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';

		if ( ! $this->validate_run_name( $run ) || ! $this->validate_part_filename( $file ) ) {
			wp_die( esc_html__( 'Invalid export download request.', 'conexao-event-importer' ) );
		}

		$base = realpath( $this->get_export_runs_root() );
		if ( false === $base ) {
			wp_die( esc_html__( 'Export directory not found.', 'conexao-event-importer' ) );
		}

		$path = realpath( $base . '/' . $run . '/' . $file );
		if ( false === $path || ! is_file( $path ) || 0 !== strpos( $path, $base . '/' ) ) {
			wp_die( esc_html__( 'Export file not found.', 'conexao-event-importer' ) );
		}

		$size = filesize( $path );
		if ( false === $size || $size <= 0 ) {
			wp_die( esc_html__( 'Export file is empty.', 'conexao-event-importer' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $file . '"' );
		header( 'Content-Length: ' . $size );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Streams the file to the output buffer in chunks.
		exit;
	}

	/**
	 * Root directory for multipart export runs (under the uploads folder).
	 *
	 * @return string
	 */
	protected function get_export_runs_root() {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'conexao-event-export';
	}

	/**
	 * Validate a multipart run directory name.
	 *
	 * @param string $run Run directory basename.
	 * @return bool
	 */
	protected function validate_run_name( $run ) {
		return is_string( $run ) && 1 === preg_match( '/^stage-d-rollout-\d{8}-\d{6}(-\d+)?$/', $run );
	}

	/**
	 * Validate a part/manifest file name.
	 *
	 * @param string $file File basename.
	 * @return bool
	 */
	protected function validate_part_filename( $file ) {
		return is_string( $file ) && 1 === preg_match( '/^stage-d-rollout-(part-\d{2}|manifest)\.json$/', $file );
	}

	/**
	 * Nonce-protected download URL for a generated part.
	 *
	 * @param string $run  Run directory basename.
	 * @param string $file Part or manifest file basename.
	 * @return string
	 */
	protected function part_download_url( $run, $file ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'conexao_download_export_part',
					'run'    => $run,
					'file'   => $file,
				),
				admin_url( 'admin-post.php' )
			),
			'conexao_download_export_part',
			'conexao_download_nonce'
		);
	}

	/**
	 * Render the multipart export result card: run totals, per-part table,
	 * SHA-256 checksums, and secure download links.
	 */
	protected function render_multipart_result() {
		if ( ! isset( $_GET['conexao_multipart_run'] ) ) {
			return;
		}

		$run = sanitize_text_field( wp_unslash( $_GET['conexao_multipart_run'] ) );
		if ( ! $this->validate_run_name( $run ) ) {
			return;
		}

		$manifest_path = $this->get_export_runs_root() . '/' . $run . '/stage-d-rollout-manifest.json';
		$manifest      = is_readable( $manifest_path ) ? json_decode( (string) file_get_contents( $manifest_path ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local export artifact.

		if ( ! is_array( $manifest ) || empty( $manifest['parts'] ) ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p><strong><?php esc_html_e( 'Multipart export', 'conexao-event-importer' ); ?></strong> —
					<?php esc_html_e( 'The manifest could not be read. The generated parts may still be on disk.', 'conexao-event-importer' ); ?></p>
			</div>
			<?php
			return;
		}

		$pass = isset( $manifest['validation_result'] ) && 'PASS' === $manifest['validation_result'];
		?>
		<div class="conexao-transfer-card">
			<h2>
				<?php esc_html_e( 'Multipart export ready', 'conexao-event-importer' ); ?>
				<span class="conexao-multipart-badge <?php echo $pass ? 'conexao-multipart-pass' : 'conexao-multipart-fail'; ?>">
					<?php echo esc_html( $pass ? __( 'Validated', 'conexao-event-importer' ) : __( 'Validation failed', 'conexao-event-importer' ) ); ?>
				</span>
			</h2>
			<p>
				<?php
				echo esc_html( sprintf(
					/* translators: 1: run name, 2: part count, 3: event count, 4: image count */
					__( 'Run: %1$s — %2$d parts, %3$d events, %4$d embedded images.', 'conexao-event-importer' ),
					$run,
					(int) $manifest['part_count'],
					(int) $manifest['total_event_count'],
					(int) $manifest['total_image_count']
				) );
				?>
			</p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Part', 'conexao-event-importer' ); ?></th>
						<th><?php esc_html_e( 'Events', 'conexao-event-importer' ); ?></th>
						<th><?php esc_html_e( 'Images', 'conexao-event-importer' ); ?></th>
						<th><?php esc_html_e( 'Size', 'conexao-event-importer' ); ?></th>
						<th><?php esc_html_e( 'SHA-256 (first 12)', 'conexao-event-importer' ); ?></th>
						<th><?php esc_html_e( 'Download', 'conexao-event-importer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $manifest['parts'] as $part ) : ?>
						<tr>
							<td><?php echo esc_html( sprintf( '%02d', (int) $part['part_number'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $part['event_count'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $part['image_count'] ) ); ?></td>
							<td><?php echo esc_html( size_format( isset( $part['bytes'] ) ? (int) $part['bytes'] : 0 ) ); ?></td>
							<td><code><?php echo esc_html( substr( (string) $part['sha256'], 0, 12 ) ); ?></code></td>
							<td>
								<a href="<?php echo esc_url( $this->part_download_url( $run, (string) $part['filename'] ) ); ?>">
									<?php echo esc_html( (string) $part['filename'] ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<td><strong><?php esc_html_e( 'Manifest', 'conexao-event-importer' ); ?></strong></td>
						<td colspan="4"><code>stage-d-rollout-manifest.json</code></td>
						<td>
							<a href="<?php echo esc_url( $this->part_download_url( $run, 'stage-d-rollout-manifest.json' ) ); ?>">
								<?php esc_html_e( 'Download manifest', 'conexao-event-importer' ); ?>
							</a>
						</td>
					</tr>
				</tbody>
			</table>
			<p class="description">
				<?php esc_html_e( 'Import each part in order (part 01 first) on the destination site using the Import Events screen. Every part is an independent, complete export file.', 'conexao-event-importer' ); ?>
			</p>
		</div>
		<?php
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

			<?php if ( isset( $_GET['conexao_multipart_error'] ) && 'part_size' === sanitize_key( wp_unslash( $_GET['conexao_multipart_error'] ) ) ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html( sprintf( __( 'Invalid part size: choose a value between %1$d and %2$d.', 'conexao-event-importer' ), Conexao_Event_Export::MIN_PART_SIZE, Conexao_Event_Export::MAX_PART_SIZE ) ); ?></p>
				</div>
			<?php endif; ?>

			<?php $this->render_multipart_result(); ?>

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
				<h2><?php esc_html_e( 'Export in parts', 'conexao-event-importer' ); ?></h2>
				<p><?php esc_html_e( 'Generate several independent export files, each containing a bounded subset of events. Every part is a complete, importable export file; parts never overlap, and together they cover every selected event exactly once. A validation manifest is generated alongside the parts.', 'conexao-event-importer' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="conexao_export_events_multipart">
					<?php wp_nonce_field( 'conexao_export_multipart', 'conexao_multipart_nonce' ); ?>
					<p>
						<label for="conexao_part_size"><strong><?php esc_html_e( 'Part size (events per file)', 'conexao-event-importer' ); ?></strong></label><br>
						<input type="number" id="conexao_part_size" name="conexao_part_size" min="<?php echo (int) Conexao_Event_Export::MIN_PART_SIZE; ?>" max="<?php echo (int) Conexao_Event_Export::MAX_PART_SIZE; ?>" step="1" value="<?php echo (int) Conexao_Event_Export::DEFAULT_PART_SIZE; ?>" required>
						<span class="description"><?php echo esc_html( sprintf( __( 'Allowed range: %1$d–%2$d events per part. Generation can take a minute or two; the page reloads with the results and download links.', 'conexao-event-importer' ), Conexao_Event_Export::MIN_PART_SIZE, Conexao_Event_Export::MAX_PART_SIZE ) ); ?></span>
					</p>
					<?php submit_button( __( 'Export in parts', 'conexao-event-importer' ), 'secondary', 'submit', false ); ?>
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
					<li><?php esc_html_e( 'Event status (published, draft, expired, etc.)', 'conexao-event-importer' ); ?></li>
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
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" data-conexao-import-form>
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

		<?php $this->render_multi_import_result(); ?>

		<div class="conexao-transfer-card">
			<h2><?php esc_html_e( 'Import Multiple Export Files', 'conexao-event-importer' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Import several export files sequentially (for example, a multipart export). Each file is processed independently, one at a time, so large imports stay within the PHP memory limit.', 'conexao-event-importer' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" data-conexao-import-form>
				<input type="hidden" name="action" value="conexao_import_events_multi">
				<input type="hidden" name="MAX_FILE_SIZE" value="<?php echo (int) $max_upload_bytes; ?>">
				<?php wp_nonce_field( 'conexao_import_events_multi', 'conexao_import_multi_nonce' ); ?>

				<p>
					<label for="conexao_import_files"><strong><?php esc_html_e( 'Export files (.json)', 'conexao-event-importer' ); ?></strong></label><br>
					<input type="file" name="conexao_import_files[]" id="conexao_import_files" accept=".json,application/json" multiple required>
					<span class="description"><?php esc_html_e( 'Select one or more export files. If you have a multipart manifest, include it and the files will be imported in the correct order.', 'conexao-event-importer' ); ?></span>
				</p>

				<p>
					<label for="conexao_multi_duplicate_strategy"><strong><?php esc_html_e( 'If an event already exists', 'conexao-event-importer' ); ?></strong></label><br>
					<select name="conexao_multi_duplicate_strategy" id="conexao_multi_duplicate_strategy">
						<option value="update"><?php esc_html_e( 'Update the existing event', 'conexao-event-importer' ); ?></option>
						<option value="skip"><?php esc_html_e( 'Skip the existing event', 'conexao-event-importer' ); ?></option>
					</select>
				</p>

				<?php submit_button( __( 'Import Multiple Files', 'conexao-event-importer' ), 'primary', 'submit', false ); ?>
			</form>
		</div>
		</div>
		<?php
	}

	/**
	 * Render the multi-file import result card, if a batch was just run.
	 */
	protected function render_multi_import_result() {
		if ( ! isset( $_GET['conexao_multi_result'] ) ) {
			return;
		}

		$raw   = wp_unslash( $_GET['conexao_multi_result'] );
		$json  = rawurldecode( $raw );
		$summary = json_decode( $json, true );

		if ( ! is_array( $summary ) ) {
			return;
		}

		$errors = array();
		if ( isset( $_GET['conexao_multi_errors'] ) ) {
			$err_raw  = wp_unslash( $_GET['conexao_multi_errors'] );
			$err_json = rawurldecode( $err_raw );
			$errors   = json_decode( $err_json, true );
			if ( ! is_array( $errors ) ) {
				$errors = array();
			}
		}

		$status  = isset( $summary['status'] ) ? $summary['status'] : 'unknown';
		$is_ok   = in_array( $status, array( 'complete' ), true );
		$is_stop = ( 'stopped' === $status );

		$notice_class = $is_ok ? 'notice-success' : ( $is_stop ? 'notice-warning' : 'notice-error' );
		?>
		<div class="notice <?php echo esc_attr( $notice_class ); ?> is-dismissible">
			<p><strong><?php esc_html_e( 'Multi-file import', 'conexao-event-importer' ); ?></strong>
			<?php
			if ( $is_stop ) {
				esc_html_e( ' — stopped after a failure. Earlier parts remain imported.', 'conexao-event-importer' );
			} elseif ( 'manifest_error' === $status ) {
				esc_html_e( ' — manifest validation failed.', 'conexao-event-importer' );
			} elseif ( 'no_files' === $status ) {
				esc_html_e( ' — no files uploaded.', 'conexao-event-importer' );
			}
			?>
			</p>
			<ul class="conexao-import-stats">
				<li><?php echo esc_html( sprintf( __( 'Files processed: %d', 'conexao-event-importer' ), isset( $summary['files'] ) ? (int) $summary['files'] : 0 ) ); ?></li>
				<li><?php echo esc_html( sprintf( __( 'Successful: %d', 'conexao-event-importer' ), isset( $summary['ok'] ) ? (int) $summary['ok'] : 0 ) ); ?></li>
				<li><?php echo esc_html( sprintf( __( 'Failed: %d', 'conexao-event-importer' ), isset( $summary['failed'] ) ? (int) $summary['failed'] : 0 ) ); ?></li>
				<li><?php echo esc_html( sprintf( __( 'Not processed: %d', 'conexao-event-importer' ), isset( $summary['skipped'] ) ? (int) $summary['skipped'] : 0 ) ); ?></li>
				<li><?php echo esc_html( sprintf( __( 'Events discovered: %d', 'conexao-event-importer' ), isset( $summary['events'] ) ? (int) $summary['events'] : 0 ) ); ?></li>
				<li><?php echo esc_html( sprintf( __( 'Events created: %d', 'conexao-event-importer' ), isset( $summary['imported'] ) ? (int) $summary['imported'] : 0 ) ); ?></li>
				<li><?php echo esc_html( sprintf( __( 'Events updated: %d', 'conexao-event-importer' ), isset( $summary['updated'] ) ? (int) $summary['updated'] : 0 ) ); ?></li>
				<li><?php echo esc_html( sprintf( __( 'Images: %d', 'conexao-event-importer' ), isset( $summary['media'] ) ? (int) $summary['media'] : 0 ) ); ?></li>
				<li><?php echo esc_html( sprintf( __( 'Total duration: %ss', 'conexao-event-importer' ), isset( $summary['duration'] ) ? $summary['duration'] : 0 ) ); ?></li>
				<li><?php echo esc_html( sprintf( __( 'Peak memory: %s', 'conexao-event-importer' ), size_format( isset( $summary['peak_mem'] ) ? (int) $summary['peak_mem'] : 0 ) ) ); ?></li>
			</ul>
		</div>
		<?php
		if ( ! empty( $errors ) ) :
		?>
		<div class="notice notice-error is-dismissible">
			<p><strong><?php esc_html_e( 'Errors', 'conexao-event-importer' ); ?></strong></p>
			<ul class="conexao-import-errors">
				<?php foreach ( $errors as $error_message ) : ?>
					<li><?php echo esc_html( $error_message ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
		endif;
	}
}
