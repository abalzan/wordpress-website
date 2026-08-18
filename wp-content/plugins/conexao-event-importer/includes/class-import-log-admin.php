<?php
/**
 * Import Log admin screen + secure download / clear actions.
 *
 * Provides a WordPress admin page under the Event Importer menu where an
 * authenticated administrator can review, download, and clear the Event
 * Importer logs.
 *
 * Downloads are served through a secure, authenticated WordPress admin
 * endpoint (admin-post.php) protected by capability checks, nonce
 * verification, and parameter validation. No public log file URL is exposed;
 * administrators never need filesystem/SSH/SFTP access on WordPress.com.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Import_Log_Admin {

	/** @var string Capability required to view/manage import logs. */
	const CAPABILITY = 'manage_options';

	/**
	 * Constructor. Wires up the admin menu + admin-post handlers.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );

		// Secure download + clear handlers.
		add_action( 'admin_post_conexao_download_log', array( $this, 'handle_download_log' ) );
		add_action( 'admin_post_conexao_download_run_log', array( $this, 'handle_download_run_log' ) );
		add_action( 'admin_post_conexao_clear_log', array( $this, 'handle_clear_log' ) );
	}

	/**
	 * Register the "Import Logs" submenu page under the Event Importer menu.
	 */
	public function register_admin_menu() {
		add_submenu_page(
			'conexao-event-import',
			__( 'Import Logs', 'conexao-event-importer' ),
			__( 'Import Logs', 'conexao-event-importer' ),
			self::CAPABILITY,
			'conexao-import-log',
			array( $this, 'render_logs_page' )
		);
	}

	/**
	 * Render the Import Logs admin page.
	 */
	public function render_logs_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view the import logs.', 'conexao-event-importer' ) );
		}

		// Prune old entries opportunistically (retention).
		Conexao_Import_Log::prune_older_than_90_days();

		$entries = Conexao_Import_Log::get_all();
		$run_ids = Conexao_Import_Log::get_run_ids( 25 );

		// Determine which run (if any) to expand for on-screen review.
		$selected_run = isset( $_GET['run'] ) ? sanitize_key( wp_unslash( $_GET['run'] ) ) : '';
		if ( $selected_run && ! in_array( $selected_run, $run_ids, true ) ) {
			$selected_run = '';
		}

		$download_all_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=conexao_download_log' ),
			'conexao_download_log'
		);

		$clear_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=conexao_clear_log' ),
			'conexao_clear_log'
		);

		?>
		<div class="wrap conexao-import-logs">
			<h1><?php esc_html_e( 'Import Logs', 'conexao-event-importer' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Review and download logs from previous Eventbrite and event-source imports. Logs help diagnose failed imports, API errors, and network problems.', 'conexao-event-importer' ); ?>
			</p>

			<div class="conexao-import-log-actions">
				<a class="button button-primary" href="<?php echo esc_url( $download_all_url ); ?>">
					<?php esc_html_e( 'Download Import Logs (.log)', 'conexao-event-importer' ); ?>
				</a>
				<a class="button button-link-delete" href="<?php echo esc_url( $clear_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'This will permanently delete ALL Event Importer logs. This cannot be undone. Continue?', 'conexao-event-importer' ) ); ?>');">
					<?php esc_html_e( 'Clear Logs', 'conexao-event-importer' ); ?>
				</a>
			</div>

			<?php if ( empty( $entries ) ) : ?>
				<p><?php esc_html_e( 'No log entries yet. Run an import to generate logs.', 'conexao-event-importer' ); ?></p>
			<?php else : ?>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of log entries retained */
							__( '%d log entries are retained (newest first). Old entries are pruned automatically after 90 days.', 'conexao-event-importer' ),
							count( $entries )
						)
					);
					?>
				</p>

				<?php if ( ! empty( $run_ids ) ) : ?>
					<h2><?php esc_html_e( 'Import Runs', 'conexao-event-importer' ); ?></h2>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Run ID', 'conexao-event-importer' ); ?></th>
								<th><?php esc_html_e( 'Entries', 'conexao-event-importer' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'conexao-event-importer' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $run_ids as $run_id ) : ?>
								<?php
								$run_entries = Conexao_Import_Log::get_for_run( $run_id, 1000 );
								$run_count   = count( $run_entries );
								$download_run_url = wp_nonce_url(
									admin_url( 'admin-post.php?action=conexao_download_run_log&run=' . rawurlencode( $run_id ) ),
									'conexao_download_run_log_' . $run_id
								);
								?>
								<tr>
									<td>
										<strong><?php echo esc_html( $run_id ); ?></strong>
									</td>
									<td><?php echo esc_html( $run_count ); ?></td>
									<td>
										<a class="button" href="<?php echo esc_url( $download_run_url ); ?>">
											<?php esc_html_e( 'Download This Run', 'conexao-event-importer' ); ?>
										</a>
										<?php if ( $selected_run === $run_id ) : ?>
											<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=conexao-import-log' ) ); ?>">
												<?php esc_html_e( 'Collapse', 'conexao-event-importer' ); ?>
											</a>
										<?php else : ?>
											<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=conexao-import-log&run=' . rawurlencode( $run_id ) ) ); ?>">
												<?php esc_html_e( 'View', 'conexao-event-importer' ); ?>
											</a>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<?php
				// Show the latest entries (or the selected run's entries) inline.
				$display_entries = ( $selected_run ) ? array_slice( Conexao_Import_Log::get_for_run( $selected_run, 1000 ), 0, 200 ) : array_slice( $entries, 0, 200 );
				?>
				<h2>
					<?php
					if ( $selected_run ) {
						echo esc_html( sprintf(
							/* translators: %s: run ID */
							__( 'Log entries for run %s', 'conexao-event-importer' ),
							$selected_run
						) );
					} else {
						esc_html_e( 'Latest log entries', 'conexao-event-importer' );
					}
					?>
				</h2>
				<div class="conexao-log-preview">
					<pre><?php echo esc_html( Conexao_Import_Log::format_entries( $display_entries ) ); ?></pre>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle the "Download All" action.
	 *
	 * Serves the full Event Importer log as a plain-text .log file through the
	 * authenticated admin-post.php endpoint. Requires manage_options + nonce.
	 */
	public function handle_download_log() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to download the import logs.', 'conexao-event-importer' ) );
		}

		check_admin_referer( 'conexao_download_log' );

		$content = Conexao_Import_Log::export_all();
		$filename = 'event-importer-log-' . gmdate( 'Ymd-His' ) . '.log';

		$this->send_download( $content, $filename );
	}

	/**
	 * Handle the "Download This Run" action.
	 *
	 * Serves only the log entries for a single import run. Requires
	 * manage_options + a per-run nonce + a validated run ID.
	 */
	public function handle_download_run_log() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to download the import logs.', 'conexao-event-importer' ) );
		}

		$run_id = isset( $_GET['run'] ) ? sanitize_key( wp_unslash( $_GET['run'] ) ) : '';
		if ( '' === $run_id ) {
			wp_die( esc_html__( 'Invalid import run.', 'conexao-event-importer' ) );
		}

		check_admin_referer( 'conexao_download_run_log_' . $run_id );

		$content  = Conexao_Import_Log::export_run( $run_id );
		$filename = 'event-importer-run-' . $run_id . '.log';

		// Keep the filename filesystem-safe.
		$filename = preg_replace( '/[^a-zA-Z0-9\-_\.]/', '-', $filename );

		$this->send_download( $content, $filename );
	}

	/**
	 * Handle the "Clear Logs" action.
	 *
	 * Permanently empties the Event Importer log option. Requires
	 * manage_options + nonce. Only Event Importer logs are removed; unrelated
	 * WordPress system logs are never touched.
	 */
	public function handle_clear_log() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to clear the import logs.', 'conexao-event-importer' ) );
		}

		check_admin_referer( 'conexao_clear_log' );

		Conexao_Import_Log::clear();

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => 'conexao-import-log', 'cleared' => '1' ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Stream file content to the browser with download headers.
	 *
	 * @param string $content  File content.
	 * @param string $filename Download filename.
	 */
	protected function send_download( $content, $filename ) {
		// Send the file as a plain-text download. No public URL, no file on disk.
		nocache_headers();

		header( 'Content-Type: text/plain; charset=' . get_option( 'blog_charset', 'UTF-8' ) );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . strlen( $content ) );
		header( 'X-Content-Type-Options: nosniff' );

		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text log, already sanitized at entry.
		exit;
	}
}