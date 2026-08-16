<?php
/**
 * Event cleanup admin interface.
 *
 * Adds a "Cleanup" page under the Event Importer menu. The page shows the
 * cleanup status (last run, next scheduled run, counts) and provides a
 * "Run Cleanup Now" button for manual execution.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Event_Cleanup_Admin {

	/**
	 * @var Conexao_Event_Cleanup
	 */
	protected $cleanup;

	/**
	 * Constructor.
	 *
	 * @param Conexao_Event_Cleanup $cleanup Cleanup engine.
	 */
	public function __construct( Conexao_Event_Cleanup $cleanup ) {
		$this->cleanup = $cleanup;

		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_post_conexao_run_cleanup', array( $this, 'handle_run_cleanup' ) );
	}

	/**
	 * Register the "Cleanup" submenu page under the Event Importer menu.
	 */
	public function register_admin_menu() {
		add_submenu_page(
			'conexao-event-import',
			__( 'Event Cleanup', 'conexao-event-importer' ),
			__( 'Cleanup', 'conexao-event-importer' ),
			'manage_options',
			'conexao-event-cleanup',
			array( $this, 'render_cleanup_page' )
		);
	}

	/**
	 * Handle the "Run Cleanup Now" request.
	 */
	public function handle_run_cleanup() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to run the event cleanup.', 'conexao-event-importer' ) );
		}

		check_admin_referer( 'conexao_run_cleanup', 'conexao_cleanup_nonce' );

		$result = $this->cleanup->run_cleanup( 'manual' );

		$redirect = admin_url( 'admin.php?page=conexao-event-cleanup' );

		$args = array(
			'conexao_cleanup_events_found'   => $result['events_found'],
			'conexao_cleanup_events_deleted' => $result['events_deleted'],
			'conexao_cleanup_images_deleted' => $result['images_deleted'],
			'conexao_cleanup_images_preserved' => $result['images_preserved'],
			'conexao_cleanup_errors'         => count( $result['errors'] ),
		);

		wp_safe_redirect( add_query_arg( $args, $redirect ) );
		exit;
	}

	/**
	 * Render the Cleanup admin page.
	 */
	public function render_cleanup_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status = $this->cleanup->get_status();
		$next   = $this->cleanup->get_next_scheduled();
		$history = $this->cleanup->get_history();

		// Read the result of a manual run from the query args.
		$run_result = null;
		if ( isset( $_GET['conexao_cleanup_events_found'] ) ) {
			$run_result = array(
				'events_found'     => (int) $_GET['conexao_cleanup_events_found'],
				'events_deleted'   => (int) $_GET['conexao_cleanup_events_deleted'],
				'images_deleted'   => (int) $_GET['conexao_cleanup_images_deleted'],
				'images_preserved' => (int) $_GET['conexao_cleanup_images_preserved'],
				'errors'           => (int) $_GET['conexao_cleanup_errors'],
			);
		}

		$last_time = ! empty( $status['time'] ) ? $status['time'] : '';
		$last_trigger = ! empty( $status['trigger'] ) ? $status['trigger'] : '';
		?>
		<div class="wrap conexao-event-cleanup">
			<h1><?php esc_html_e( 'Event Cleanup', 'conexao-event-importer' ); ?></h1>
			<p><?php esc_html_e( 'Automatically deletes past events and their exclusively-owned images. The cleanup runs once per week and is conservative — it only deletes media that was imported by the event importer and is no longer used anywhere else.', 'conexao-event-importer' ); ?></p>

			<?php if ( $run_result ) : ?>
				<div class="notice <?php echo $run_result['errors'] > 0 ? 'notice-warning' : 'notice-success'; ?> is-dismissible">
					<p>
						<strong><?php esc_html_e( 'Cleanup complete', 'conexao-event-importer' ); ?></strong>
					</p>
					<ul style="list-style: disc; padding-left: 20px; margin: 8px 0 0;">
						<li><?php echo esc_html( sprintf( __( 'Events found: %d', 'conexao-event-importer' ), $run_result['events_found'] ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Events deleted: %d', 'conexao-event-importer' ), $run_result['events_deleted'] ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Images deleted: %d', 'conexao-event-importer' ), $run_result['images_deleted'] ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Images preserved: %d', 'conexao-event-importer' ), $run_result['images_preserved'] ) ); ?></li>
						<?php if ( $run_result['errors'] > 0 ) : ?>
							<li><?php echo esc_html( sprintf( __( 'Errors: %d', 'conexao-event-importer' ), $run_result['errors'] ) ); ?></li>
						<?php endif; ?>
					</ul>
				</div>
			<?php endif; ?>

			<div class="conexao-import-summary">
				<div class="conexao-import-stat">
					<span class="conexao-import-stat-label"><?php esc_html_e( 'Last cleanup', 'conexao-event-importer' ); ?></span>
					<span class="conexao-import-stat-value">
						<?php echo $last_time ? esc_html( $last_time ) : '&mdash;'; ?>
						<?php if ( $last_trigger ) : ?>
							<small style="display:block; font-size:12px; color:#646970; font-weight:400;">
								<?php echo 'scheduled' === $last_trigger ? esc_html__( 'Scheduled run', 'conexao-event-importer' ) : esc_html__( 'Manual run', 'conexao-event-importer' ); ?>
							</small>
						<?php endif; ?>
					</span>
				</div>

				<div class="conexao-import-stat">
					<span class="conexao-import-stat-label"><?php esc_html_e( 'Next scheduled cleanup', 'conexao-event-importer' ); ?></span>
					<span class="conexao-import-stat-value">
						<?php
						if ( $next ) {
							$next_dt = new DateTime( '@' . $next );
							$next_dt->setTimezone( wp_timezone() );
							echo esc_html( $next_dt->format( 'Y-m-d H:i' ) );
						} else {
							echo esc_html__( 'Not scheduled', 'conexao-event-importer' );
						}
						?>
					</span>
				</div>

				<div class="conexao-import-stat">
					<span class="conexao-import-stat-label"><?php esc_html_e( 'Past events found (last run)', 'conexao-event-importer' ); ?></span>
					<span class="conexao-import-stat-value">
						<?php echo isset( $status['events_found'] ) ? esc_html( (int) $status['events_found'] ) : '&mdash;'; ?>
					</span>
				</div>

				<div class="conexao-import-stat">
					<span class="conexao-import-stat-label"><?php esc_html_e( 'Events deleted (last run)', 'conexao-event-importer' ); ?></span>
					<span class="conexao-import-stat-value">
						<?php echo isset( $status['events_deleted'] ) ? esc_html( (int) $status['events_deleted'] ) : '&mdash;'; ?>
					</span>
				</div>

				<div class="conexao-import-stat">
					<span class="conexao-import-stat-label"><?php esc_html_e( 'Images deleted (last run)', 'conexao-event-importer' ); ?></span>
					<span class="conexao-import-stat-value">
						<?php echo isset( $status['images_deleted'] ) ? esc_html( (int) $status['images_deleted'] ) : '&mdash;'; ?>
					</span>
				</div>

				<div class="conexao-import-stat">
					<span class="conexao-import-stat-label"><?php esc_html_e( 'Images preserved (last run)', 'conexao-event-importer' ); ?></span>
					<span class="conexao-import-stat-value">
						<?php echo isset( $status['images_preserved'] ) ? esc_html( (int) $status['images_preserved'] ) : '&mdash;'; ?>
					</span>
				</div>
			</div>

			<?php if ( ! empty( $status['errors'] ) && is_array( $status['errors'] ) ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><strong><?php esc_html_e( 'Errors from the last cleanup run', 'conexao-event-importer' ); ?></strong></p>
					<ul style="list-style: disc; padding-left: 20px; margin: 8px 0 0;">
						<?php foreach ( array_slice( $status['errors'], 0, 20 ) as $error ) : ?>
							<li><?php echo esc_html( $error ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<div class="conexao-event-transfer">
				<div class="conexao-transfer-card">
					<h2><?php esc_html_e( 'Run Cleanup Now', 'conexao-event-importer' ); ?></h2>
					<p><?php esc_html_e( 'Runs the same safe cleanup logic as the scheduled weekly job. Past events are deleted, and their importer-created images are removed only when they are no longer used anywhere else.', 'conexao-event-importer' ); ?></p>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="conexao_run_cleanup">
						<?php wp_nonce_field( 'conexao_run_cleanup', 'conexao_cleanup_nonce' ); ?>
						<?php submit_button( __( 'Run Cleanup Now', 'conexao-event-importer' ), 'primary', 'submit', false ); ?>
					</form>
				</div>

				<div class="conexao-transfer-card">
					<h2><?php esc_html_e( 'What happens', 'conexao-event-importer' ); ?></h2>
					<ul class="conexao-transfer-list">
						<li><?php esc_html_e( 'Events whose end date/time has passed are deleted.', 'conexao-event-importer' ); ?></li>
						<li><?php esc_html_e( 'Events without an end date use their event date as the fallback.', 'conexao-event-importer' ); ?></li>
						<li><?php esc_html_e( 'Only images imported by the event importer are considered for deletion.', 'conexao-event-importer' ); ?></li>
						<li><?php esc_html_e( 'Images still used by another event, page, post, or content are preserved.', 'conexao-event-importer' ); ?></li>
						<li><?php esc_html_e( 'Manually uploaded media unrelated to the event is never deleted.', 'conexao-event-importer' ); ?></li>
						<li><?php esc_html_e( 'If there is any uncertainty, the image is kept.', 'conexao-event-importer' ); ?></li>
					</ul>
				</div>
			</div>

			<?php if ( ! empty( $history ) ) : ?>
				<h2><?php esc_html_e( 'Cleanup History', 'conexao-event-importer' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time', 'conexao-event-importer' ); ?></th>
							<th><?php esc_html_e( 'Trigger', 'conexao-event-importer' ); ?></th>
							<th><?php esc_html_e( 'Events Found', 'conexao-event-importer' ); ?></th>
							<th><?php esc_html_e( 'Events Deleted', 'conexao-event-importer' ); ?></th>
							<th><?php esc_html_e( 'Images Evaluated', 'conexao-event-importer' ); ?></th>
							<th><?php esc_html_e( 'Images Deleted', 'conexao-event-importer' ); ?></th>
							<th><?php esc_html_e( 'Images Preserved', 'conexao-event-importer' ); ?></th>
							<th><?php esc_html_e( 'Errors', 'conexao-event-importer' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_slice( $history, 0, 20 ) as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( $entry['time'] ); ?></td>
								<td>
									<?php echo 'scheduled' === $entry['trigger'] ? esc_html__( 'Scheduled', 'conexao-event-importer' ) : esc_html__( 'Manual', 'conexao-event-importer' ); ?>
								</td>
								<td><?php echo esc_html( (int) $entry['events_found'] ); ?></td>
								<td><?php echo esc_html( (int) $entry['events_deleted'] ); ?></td>
								<td><?php echo esc_html( (int) $entry['images_evaluated'] ); ?></td>
								<td><?php echo esc_html( (int) $entry['images_deleted'] ); ?></td>
								<td><?php echo esc_html( (int) $entry['images_preserved'] ); ?></td>
								<td><?php echo esc_html( (int) $entry['error_count'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}