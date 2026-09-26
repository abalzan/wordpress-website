<?php
/**
 * Shared rollout admin screen (Tools -> Translation Rollouts).
 *
 * Controlled rollout tool, not a content-management UI:
 *   - every write path requires `manage_options` AND a valid nonce;
 *   - the stage can only be one of the stages registered with the engine;
 *   - Preview (dry run) is always available before Apply;
 *   - no writes happen on GET, on page load, or without an explicit action.
 *
 * @package Conexao_Translation_Rollout
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared rollout admin screen.
 */
final class Conexao_Translation_Rollout_Admin {

	/**
	 * Capability required for every rollout action.
	 */
	const CAP = 'manage_options';

	/**
	 * Admin page slug.
	 */
	const PAGE_SLUG = 'conexao-translation-rollout';

	/**
	 * The admin-post.php action name for a rollout run.
	 */
	const ACTION = 'conexao_translation_rollout_run';

	/**
	 * Nonce field name used on the rollout form.
	 */
	const NONCE = '_conexao_rollout_nonce';

	/**
	 * Register the admin hooks. Registration performs no writes.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_run' ) );
	}

	/**
	 * Add the Tools -> Translation Rollouts page.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_management_page(
			'Translation Rollouts',
			'Translation Rollouts',
			self::CAP,
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Execute one rollout action (preview, apply or remove).
	 *
	 * Capability and nonce are both verified before any write.
	 *
	 * @return void
	 */
	public static function handle_run(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'forbidden', '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION, self::NONCE );

		$stage    = isset( $_POST['stage'] ) ? sanitize_key( (string) $_POST['stage'] ) : '';
		$mode_raw = isset( $_POST['mode'] ) ? sanitize_key( (string) $_POST['mode'] ) : 'preview';
		$mode     = 'apply' === $mode_raw ? 'apply' : ( 'remove' === $mode_raw ? 'remove' : 'preview' );
		$config   = Conexao_Translation_Rollout_Engine::get_stage( $stage );

		if ( ! is_array( $config ) || empty( $config['run_callback'] ) || ! is_callable( $config['run_callback'] ) ) {
			wp_die( 'unknown stage', '', array( 'response' => 400 ) );
		}

		$report = call_user_func(
			$config['run_callback'],
			array(
				'dry_run' => 'preview' === $mode,
				'mode'    => 'remove' === $mode ? 'remove' : 'run',
			)
		);

		set_transient(
			'conexao_rollout_report_' . get_current_user_id(),
			array(
				'mode'   => $mode,
				'stage'  => $stage,
				'report' => $report,
			),
			300
		);

		$redirect = add_query_arg(
			array(
				'page' => self::PAGE_SLUG,
				'ran'  => 1,
			),
			admin_url( 'tools.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Render the screen. Read-only: no content is written here.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'forbidden', '', array( 'response' => 403 ) );
		}

		echo '<div class="wrap"><h1>Translation Rollouts</h1>';

		$stages = Conexao_Translation_Rollout_Engine::registered_stages();

		if ( array() === $stages ) {
			echo '<p>No rollout stages are registered.</p></div>';
			return;
		}

		$report = null;

		if ( isset( $_GET['ran'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag; the write path is nonce-protected.
			$report = get_transient( 'conexao_rollout_report_' . get_current_user_id() );
			delete_transient( 'conexao_rollout_report_' . get_current_user_id() );
		}

		if ( is_array( $report ) && isset( $report['report']['summary'] ) ) {
			self::render_report( (string) $report['mode'], (string) $report['stage'], $report['report'] );
		}

		echo '<h2>Run a stage</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION, self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<p><label>Stage <select name="stage">';

		foreach ( $stages as $slug ) {
			printf( '<option value="%s">%s</option>', esc_attr( $slug ), esc_html( $slug ) );
		}

		echo '</select></label></p>';
		echo '<p><button class="button" name="mode" value="preview">Preview (dry run)</button> ';
		echo '<button class="button button-primary" name="mode" value="apply">Apply</button> ';
		echo '<button class="button" name="mode" value="remove" onclick="return confirm(\'Remove the EN records this stage owns? The PT originals are never touched.\')">Remove (rollback)</button></p>';
		echo '</form></div>';
	}

	/**
	 * Render the report of the last run, including the numeric gate.
	 *
	 * @param string $mode   preview|apply|remove.
	 * @param string $stage  Stage identifier.
	 * @param array  $report Engine report.
	 * @return void
	 */
	private static function render_report( string $mode, string $stage, array $report ): void {
		$summary = $report['summary'];
		$gate    = isset( $report['gate'] ) ? $report['gate'] : array();
		$label   = 'apply' === $mode ? 'applied' : ( 'remove' === $mode ? 'removed' : 'preview' );

		printf(
			'<div class="notice notice-%s"><p><strong>%s %s</strong> — created %d, updated %d, skipped %d, removed %d, errors %d, conflicts %d, PT changed %d; gate %s (missing %d of %d eligible).</p></div>',
			$summary['errors'] ? 'error' : 'success',
			esc_html( $stage ),
			esc_html( $label ),
			(int) $summary['created'],
			(int) $summary['updated'],
			(int) $summary['skipped'],
			(int) $summary['removed'],
			(int) $summary['errors'],
			(int) $summary['conflicts'],
			(int) $summary['pt_changed'],
			esc_html( isset( $gate['gate'] ) ? (string) $gate['gate'] : 'n/a' ),
			(int) ( isset( $gate['missing_en'] ) ? $gate['missing_en'] : 0 ),
			(int) ( isset( $gate['eligible_public_pt'] ) ? $gate['eligible_public_pt'] : 0 )
		);

		echo '<table class="widefat striped"><thead><tr><th>Stable key</th><th>PT</th><th>EN</th><th>Action</th><th>Message</th></tr></thead><tbody>';

		foreach ( (array) $report['rows'] as $row ) {
			printf(
				'<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( (string) $row['stable_key'] ),
				$row['pt_id'] ? '#' . (int) $row['pt_id'] : '—',
				$row['en_id'] ? '#' . (int) $row['en_id'] : '—',
				esc_html( (string) $row['action'] ),
				esc_html( (string) $row['message'] )
			);
		}

		echo '</tbody></table>';
	}
}
