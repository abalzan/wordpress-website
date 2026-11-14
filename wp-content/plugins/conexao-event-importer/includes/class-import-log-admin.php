<?php
/**
 * Import Log actions: secure download, clear, and the retired screen's redirect.
 *
 * The separate "Import Logs" admin page (`admin.php?page=conexao-import-log`)
 * is retired: run-level log inspection now lives inline in the Import History
 * table on the Event Import dashboard, which is the one screen an operator
 * needs. What this class keeps — and must keep — is everything that page did
 * that is NOT a second rendering of the log:
 *
 *   - the authenticated, capability-checked, nonce-protected download handlers
 *     (whole log and single run), which the dashboard's per-row actions reuse;
 *   - the capability-checked, nonce-protected Clear Logs handler;
 *   - a redirect from the retired URL to the dashboard for administrators.
 *
 * The underlying logging system (`Conexao_Import_Log`), its storage option,
 * buffering, retention, pruning and sanitisation are untouched by the
 * retirement: only the second SCREEN is gone.
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

	/**
	 * Capability required to view/manage import logs.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Slug of the retired Import Logs screen.
	 */
	const RETIRED_PAGE = 'conexao-import-log';

	/**
	 * Wire up the download/clear handlers and the retired-page redirect.
	 *
	 * No admin menu is registered: the screen is retired.
	 *
	 * @return void
	 */
	public function __construct() {
		// Secure download + clear handlers. These outlive the retired screen:
		// the dashboard's per-row "Download" action posts to the same
		// protected endpoints rather than inventing a second one.
		add_action( 'admin_post_conexao_download_log', array( $this, 'handle_download_log' ) );
		add_action( 'admin_post_conexao_download_run_log', array( $this, 'handle_download_run_log' ) );
		add_action( 'admin_post_conexao_clear_log', array( $this, 'handle_clear_log' ) );

		// The retired screen is no longer registered as a submenu or a page, so
		// a bookmarked `admin.php?page=conexao-import-log` dies with WordPress's
		// standard refusal. `admin_page_access_denied` is the one lifecycle
		// point that still runs for an unregistered slug (wp-admin/menu.php
		// fires it immediately before refusing), which makes it the place to
		// turn that refusal into a hop to the dashboard.
		add_action( 'admin_page_access_denied', array( $this, 'maybe_redirect_retired_log_page' ) );
	}

	/**
	 * The URL an administrator visiting the RETIRED Import Logs URL should be
	 * sent to, or '' when the request must not be redirected.
	 *
	 * Deliberately a pure decision — capability + requested page slug → URL or
	 * `''`, with no output and no exit — so the regression suite can prove both
	 * directions without having to fake an `exit`.
	 *
	 * @return string
	 */
	public function retired_log_redirect_url() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing for a GET that changes nothing.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( self::RETIRED_PAGE !== $page ) {
			return '';
		}

		// Only the operator who could have read the old screen is redirected;
		// anyone else keeps WordPress's own refusal.
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return '';
		}

		return admin_url( 'admin.php?page=conexao-event-import' );
	}

	/**
	 * Redirect the retired Import Logs URL to the dashboard.
	 *
	 * @return void
	 */
	public function maybe_redirect_retired_log_page() {
		$target = $this->retired_log_redirect_url();

		if ( '' === $target ) {
			return;
		}

		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * The nonce-protected URL that downloads one import run's log.
	 *
	 * The single source of truth for that URL: the retired screen used it, and
	 * the dashboard's per-row "Download" action uses it now. Reusing the
	 * existing `conexao_download_run_log` action keeps the capability check,
	 * the per-run nonce and the filename sanitisation in exactly one place.
	 *
	 * @param string $run_id Import run ID.
	 * @return string
	 */
	public static function run_log_download_url( $run_id ) {
		$run_id = sanitize_key( (string) $run_id );

		return wp_nonce_url(
			admin_url( 'admin-post.php?action=conexao_download_run_log&run=' . rawurlencode( $run_id ) ),
			'conexao_download_run_log_' . $run_id
		);
	}

	/**
	 * The nonce-protected URL that downloads the whole import log.
	 *
	 * @return string
	 */
	public static function log_download_all_url() {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=conexao_download_log' ),
			'conexao_download_log'
		);
	}

	/**
	 * The nonce-protected URL that clears the whole import log.
	 *
	 * Destructive: every caller must gate it behind an explicit confirmation.
	 *
	 * @return string
	 */
	public static function clear_log_url() {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=conexao_clear_log' ),
			'conexao_clear_log'
		);
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

		// The clear action is triggered from the Import History section of the
		// Event Import dashboard, so the operator lands back there — the retired
		// `conexao-import-log` slug is no longer a registered page.
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'conexao-event-import',
					'logs_cleared' => '1',
				),
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
