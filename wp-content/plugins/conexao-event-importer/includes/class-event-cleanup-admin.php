<?php
/**
 * Event cleanup admin interface.
 *
 * Adds a "Cleanup" page under the Event Importer menu. The page shows the
 * cleanup status (last run, counts) and provides a "Run Cleanup Now" button
 * for manual execution.
 *
 * Cleanup is manual-only — there is no scheduled/cron-based cleanup.
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
	 * @var Conexao_Event_Cleanup_Run
	 */
	protected $runner;

	/**
	 * Constructor.
	 *
	 * @param Conexao_Event_Cleanup $cleanup Cleanup engine.
	 * @param Conexao_Event_Cleanup_Run $runner Batch runner.
	 */
	public function __construct( Conexao_Event_Cleanup $cleanup, Conexao_Event_Cleanup_Run $runner ) {
		$this->cleanup = $cleanup;
		$this->runner  = $runner;

		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_post_conexao_run_cleanup', array( $this, 'handle_run_cleanup' ) );
		add_action( 'admin_post_conexao_continue_cleanup', array( $this, 'handle_continue_cleanup' ) );
		add_action( 'admin_post_conexao_cancel_cleanup', array( $this, 'handle_cancel_cleanup' ) );
		add_action( 'admin_post_conexao_mark_attachment_reviewed', array( $this, 'handle_mark_attachment_reviewed' ) );
		add_action( 'admin_post_conexao_retry_failed_event', array( $this, 'handle_retry_failed_event' ) );
		add_action( 'admin_post_conexao_dismiss_failed_event', array( $this, 'handle_dismiss_failed_event' ) );
		add_action( 'admin_post_conexao_reenable_failed_event', array( $this, 'handle_reenable_failed_event' ) );
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

		$result = $this->runner->start();

		$redirect = admin_url( 'admin.php?page=conexao-event-cleanup' );

		$args = array(
			'conexao_cleanup_status' => $result['status'],
			'conexao_cleanup_reason' => $result['reason'],
		);

		wp_safe_redirect( add_query_arg( $args, $redirect ) );
		exit;
	}

	/**
	 * Handle the "Continue" request.
	 */
	public function handle_continue_cleanup() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to continue the event cleanup.', 'conexao-event-importer' ) );
		}

		check_admin_referer( 'conexao_continue_cleanup', 'conexao_continue_nonce' );

		$result = $this->runner->start();

		$redirect = admin_url( 'admin.php?page=conexao-event-cleanup' );

		$args = array(
			'conexao_cleanup_status' => $result['status'],
			'conexao_cleanup_reason' => $result['reason'],
		);

		wp_safe_redirect( add_query_arg( $args, $redirect ) );
		exit;
	}

	/**
	 * Handle the "Cancel" request.
	 */
	public function handle_cancel_cleanup() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to cancel the event cleanup.', 'conexao-event-importer' ) );
		}

		check_admin_referer( 'conexao_cancel_cleanup', 'conexao_cancel_nonce' );

		$result = $this->runner->cancel();

		$redirect = admin_url( 'admin.php?page=conexao-event-cleanup' );

		$args = array(
			'conexao_cleanup_status' => $result['ok'] ? 'cancelled' : 'cancel_failed',
			'conexao_cleanup_reason' => $result['reason'],
		);

		wp_safe_redirect( add_query_arg( $args, $redirect ) );
		exit;
	}

	/**
	 * Handle "Mark as reviewed" for an attachment.
	 */
	public function handle_mark_attachment_reviewed() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to mark attachments as reviewed.', 'conexao-event-importer' ) );
		}

		check_admin_referer( 'conexao_mark_attachment_reviewed', 'conexao_attachment_review_nonce' );

		$attachment_id = isset( $_POST['attachment_id'] ) ? (int) $_POST['attachment_id'] : 0;

		if ( $attachment_id <= 0 ) {
			wp_die( esc_html__( 'Invalid attachment ID.', 'conexao-event-importer' ) );
		}

		$result = Conexao_Event_Cleanup_Attachment_Review::mark_reviewed(
			$attachment_id,
			wp_get_current_user()->user_login,
			time()
		);

		$redirect = admin_url( 'admin.php?page=conexao-event-cleanup' );

		$args = array(
			'conexao_attachment_reviewed' => $result['ok'] ? '1' : '0',
			'conexao_attachment_reason'   => $result['reason'],
		);

		wp_safe_redirect( add_query_arg( $args, $redirect ) );
		exit;
	}

	/**
	 * Handle "Retry" for a failed event (from failed_events or failed_events_final).
	 */
	public function handle_retry_failed_event() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to retry failed events.', 'conexao-event-importer' ) );
		}

		check_admin_referer( 'conexao_retry_failed_event', 'conexao_retry_nonce' );

		$event_id = isset( $_POST['event_id'] ) ? (int) $_POST['event_id'] : 0;

		if ( $event_id <= 0 ) {
			wp_die( esc_html__( 'Invalid event ID.', 'conexao-event-importer' ) );
		}

		$run_state = $this->runner->read_run_state();

		$failure_record = null;
		$source         = '';

		// Check failed_events_final first (exhausted retries).
		if ( isset( $run_state['failed_events_final'][ $event_id ] ) ) {
			$failure_record = $run_state['failed_events_final'][ $event_id ];
			unset( $run_state['failed_events_final'][ $event_id ] );
			$source = 'final';
		}
		// Check failed_events (initial failures).
		elseif ( isset( $run_state['failed_events'][ $event_id ] ) ) {
			$failure_record = $run_state['failed_events'][ $event_id ];
			unset( $run_state['failed_events'][ $event_id ] );
			$source = 'initial';
		}

		if ( $failure_record ) {
			// Reset attempts for retry.
			$failure_record['attempts']        = 0;
			$failure_record['last_reason']     = '';
			$failure_record['last_attempt_at'] = 0;
			$failure_record['first_failed_at'] = time();

			// Add to retry queue (persistent, survives run completion).
			$retry_queue = isset( $run_state['retry_queue'] ) ? $run_state['retry_queue'] : array();
			$retry_queue[ $event_id ] = array(
				'event_id'       => $event_id,
				'added_at'       => time(),
				'last_retry_at'  => 0,
				'retry_reason'   => '',
				'attempts'       => 0,
				'first_failed_at'=> $failure_record['first_failed_at'] ?? time(),
				'status'         => 'pending',
			);
			$run_state['retry_queue'] = $retry_queue;

			$this->runner->write_run_state( $run_state );
		}

		$redirect = admin_url( 'admin.php?page=conexao-event-cleanup' );

		wp_safe_redirect( add_query_arg( array( 'conexao_event_retried' => '1' ), $redirect ) );
		exit;
	}

	/**
	 * Handle "Dismiss" for a failed event.
	 */
	public function handle_dismiss_failed_event() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to dismiss failed events.', 'conexao-event-importer' ) );
		}

		check_admin_referer( 'conexao_dismiss_failed_event', 'conexao_dismiss_nonce' );

		$event_id = isset( $_POST['event_id'] ) ? (int) $_POST['event_id'] : 0;

		if ( $event_id <= 0 ) {
			wp_die( esc_html__( 'Invalid event ID.', 'conexao-event-importer' ) );
		}

		$run_state = $this->runner->read_run_state();

		if ( isset( $run_state['failed_events_final'][ $event_id ] ) ) {
			$run_state['failed_events_final'][ $event_id ]['status'] = 'dismissed';
			$run_state['failed_events_final'][ $event_id ]['resolved_at'] = time();
			$run_state['failed_events_final'][ $event_id ]['resolved_by'] = wp_get_current_user()->user_login;
			$this->runner->write_run_state( $run_state );
		}

		$redirect = admin_url( 'admin.php?page=conexao-event-cleanup' );

		wp_safe_redirect( add_query_arg( array( 'conexao_event_dismissed' => '1' ), $redirect ) );
		exit;
	}

	/**
	 * Handle "Re-enable" for a dismissed failed event.
	 */
	public function handle_reenable_failed_event() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to re-enable failed events.', 'conexao-event-importer' ) );
		}

		check_admin_referer( 'conexao_reenable_failed_event', 'conexao_reenable_nonce' );

		$event_id = isset( $_POST['event_id'] ) ? (int) $_POST['event_id'] : 0;

		if ( $event_id <= 0 ) {
			wp_die( esc_html__( 'Invalid event ID.', 'conexao-event-importer' ) );
		}

		$run_state = $this->runner->read_run_state();

		if ( isset( $run_state['failed_events_final'][ $event_id ] ) ) {
			$entry = $run_state['failed_events_final'][ $event_id ];
			unset( $run_state['failed_events_final'][ $event_id ] );
			$run_state['failed_events'][ $event_id ] = array(
				'attempts'        => 0,
				'last_reason'     => $entry['last_reason'],
				'last_attempt_at' => 0,
				'first_failed_at' => time(),
			);
			$this->runner->write_run_state( $run_state );
		}

		$redirect = admin_url( 'admin.php?page=conexao-event-cleanup' );

		wp_safe_redirect( add_query_arg( array( 'conexao_event_reenabled' => '1' ), $redirect ) );
		exit;
	}

	/**
	 * Render the Cleanup admin page.
	 */
	public function render_cleanup_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status  = $this->cleanup->get_status();
		$history = $this->cleanup->get_history();
		$run_state = $this->runner->read_run_state();

		// Read the result of a manual run from the query args.
		$run_result = null;
		if ( isset( $_GET['conexao_cleanup_status'] ) ) {
			$run_result = array(
				'status' => sanitize_text_field( wp_unslash( $_GET['conexao_cleanup_status'] ) ),
				'reason' => isset( $_GET['conexao_cleanup_reason'] ) ? sanitize_text_field( wp_unslash( $_GET['conexao_cleanup_reason'] ) ) : '',
			);
		}

		$last_time = ! empty( $status['time'] ) ? $status['time'] : '';
		?>
		<div class="wrap conexao-event-cleanup">
			<h1><?php esc_html_e( 'Event Cleanup', 'conexao-event-importer' ); ?></h1>
			<p><?php esc_html_e( 'Manually deletes past events. Attachments are preserved by default and recorded for manual review — automatic attachment deletion is disabled to prevent accidental deletion of shared images.', 'conexao-event-importer' ); ?></p>

			<?php if ( $run_result ) : ?>
				<div class="notice <?php echo in_array( $run_result['status'], array( 'completed_with_errors', 'failed', 'paused_capacity', 'paused_capacity_review' ), true ) ? 'notice-warning' : 'notice-success'; ?> is-dismissible">
					<p>
						<strong><?php esc_html_e( 'Cleanup status', 'conexao-event-importer' ); ?></strong>
					</p>
					<p><?php echo esc_html( sprintf( __( 'Status: %s', 'conexao-event-importer' ), $run_result['status'] ) ); ?></p>
					<?php if ( ! empty( $run_result['reason'] ) ) : ?>
						<p><?php echo esc_html( sprintf( __( 'Reason: %s', 'conexao-event-importer' ), $run_result['reason'] ) ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $run_state ) ) : ?>
				<div class="conexao-import-summary">
					<div class="conexao-import-stat">
						<span class="conexao-import-stat-label"><?php esc_html_e( 'Run status', 'conexao-event-importer' ); ?></span>
						<span class="conexao-import-stat-value"><?php echo esc_html( $run_state['status'] ); ?></span>
					</div>
					<div class="conexao-import-stat">
						<span class="conexao-import-stat-label"><?php esc_html_e( 'Events deleted', 'conexao-event-importer' ); ?></span>
						<span class="conexao-import-stat-value"><?php echo esc_html( (int) $run_state['events_deleted'] ); ?></span>
					</div>
					<div class="conexao-import-stat">
						<span class="conexao-import-stat-label"><?php esc_html_e( 'Images preserved', 'conexao-event-importer' ); ?></span>
						<span class="conexao-import-stat-value"><?php echo esc_html( (int) $run_state['images_preserved'] ); ?></span>
					</div>
					<div class="conexao-import-stat">
						<span class="conexao-import-stat-label"><?php esc_html_e( 'Cursor', 'conexao-event-importer' ); ?></span>
						<span class="conexao-import-stat-value"><?php echo esc_html( (int) $run_state['cursor_id'] ); ?></span>
					</div>
				</div>

				<?php if ( ! empty( $run_state['failed_events_final'] ) ) : ?>
					<div class="notice notice-warning is-dismissible">
						<p><strong><?php esc_html_e( 'Failed events (exhausted retries)', 'conexao-event-importer' ); ?></strong></p>
						<ul style="list-style: disc; padding-left: 20px; margin: 8px 0 0;">
							<?php foreach ( $run_state['failed_events_final'] as $event_id => $entry ) : ?>
								<li>
									<?php echo esc_html( sprintf( __( 'Event #%d: %s (attempts: %d)', 'conexao-event-importer' ), $event_id, $entry['last_reason'], $entry['attempts'] ) ); ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline;">
										<input type="hidden" name="action" value="conexao_retry_failed_event">
										<input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>">
										<?php wp_nonce_field( 'conexao_retry_failed_event', 'conexao_retry_nonce' ); ?>
										<button type="submit" class="button button-small"><?php esc_html_e( 'Retry', 'conexao-event-importer' ); ?></button>
									</form>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline;">
										<input type="hidden" name="action" value="conexao_dismiss_failed_event">
										<input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>">
										<?php wp_nonce_field( 'conexao_dismiss_failed_event', 'conexao_dismiss_nonce' ); ?>
										<button type="submit" class="button button-small"><?php esc_html_e( 'Dismiss', 'conexao-event-importer' ); ?></button>
									</form>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $run_state['failed_events'] ) ) : ?>
					<div class="notice notice-warning is-dismissible">
						<p><strong><?php esc_html_e( 'Failed events (retryable)', 'conexao-event-importer' ); ?></strong></p>
						<ul style="list-style: disc; padding-left: 20px; margin: 8px 0 0;">
							<?php foreach ( $run_state['failed_events'] as $event_id => $entry ) : ?>
								<li>
									<?php echo esc_html( sprintf( __( 'Event #%d: %s (attempts: %d)', 'conexao-event-importer' ), $event_id, $entry['last_reason'], $entry['attempts'] ) ); ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline;">
										<input type="hidden" name="action" value="conexao_retry_failed_event">
										<input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>">
										<?php wp_nonce_field( 'conexao_retry_failed_event', 'conexao_retry_nonce' ); ?>
										<button type="submit" class="button button-small"><?php esc_html_e( 'Retry', 'conexao-event-importer' ); ?></button>
									</form>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline;">
										<input type="hidden" name="action" value="conexao_dismiss_failed_event">
										<input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>">
										<?php wp_nonce_field( 'conexao_dismiss_failed_event', 'conexao_dismiss_nonce' ); ?>
										<button type="submit" class="button button-small"><?php esc_html_e( 'Dismiss', 'conexao-event-importer' ); ?></button>
									</form>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $run_state['retry_queue'] ) ) : ?>
					$pending_retries = array_filter( $run_state['retry_queue'], function ( $e ) { return 'pending' === ( $e['status'] ?? '' ); } );
					if ( ! empty( $pending_retries ) ) :
				?>
					<div class="notice notice-info is-dismissible">
						<p><strong><?php esc_html_e( 'Events queued for retry', 'conexao-event-importer' ); ?></strong></p>
						<p><?php esc_html_e( 'These events will be retried on the next cleanup run.', 'conexao-event-importer' ); ?></p>
						<ul style="list-style: disc; padding-left: 20px; margin: 8px 0 0;">
							<?php foreach ( $pending_retries as $event_id => $entry ) : ?>
								<li>
									<?php echo esc_html( sprintf( __( 'Event #%d (attempts: %d)', 'conexao-event-importer' ), $event_id, $entry['attempts'] ?? 0 ) ); ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; endif; ?>

				<?php
				$review_queue = Conexao_Event_Cleanup_Attachment_Review::read();
				if ( ! empty( $review_queue['data']['entries'] ) ) :
					$pending = array_filter( $review_queue['data']['entries'], function ( $e ) { return 'pending' === $e['status']; } );
					if ( ! empty( $pending ) ) :
				?>
					<div class="notice notice-info is-dismissible">
						<p><strong><?php esc_html_e( 'Attachments preserved for manual review', 'conexao-event-importer' ); ?></strong></p>
						<p><?php esc_html_e( 'These attachments were preserved (not deleted) after their event was deleted. Review them in the Media Library if needed.', 'conexao-event-importer' ); ?></p>
						<ul style="list-style: disc; padding-left: 20px; margin: 8px 0 0;">
							<?php foreach ( $pending as $attachment_id => $entry ) : ?>
								<li>
									<?php
									$mime_type      = isset( $entry['mime_type'] ) ? $entry['mime_type'] : 'unknown';
									$classification = isset( $entry['classification'] ) ? $entry['classification'] : 'unknown';
									$source         = isset( $entry['source'] ) ? $entry['source'] : 'banner';
									$label          = sprintf(
										__( 'Attachment #%d (from event #%d, %s, %s): %s', 'conexao-event-importer' ),
										$attachment_id,
										$entry['event_id'],
										$classification,
										$source,
										$mime_type
									);
									echo esc_html( $label );
									?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline;">
										<input type="hidden" name="action" value="conexao_mark_attachment_reviewed">
										<input type="hidden" name="attachment_id" value="<?php echo esc_attr( $attachment_id ); ?>">
										<?php wp_nonce_field( 'conexao_mark_attachment_reviewed', 'conexao_attachment_review_nonce' ); ?>
										<button type="submit" class="button button-small"><?php esc_html_e( 'Mark as reviewed', 'conexao-event-importer' ); ?></button>
									</form>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<?php if ( in_array( $run_state['status'], array( 'running', 'paused_capacity', 'paused_capacity_review' ), true ) ) : ?>
					<div class="conexao-event-transfer">
						<div class="conexao-transfer-card">
							<h2><?php esc_html_e( 'Continue Cleanup', 'conexao-event-importer' ); ?></h2>
							<p><?php esc_html_e( 'Process the next batch of events.', 'conexao-event-importer' ); ?></p>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="conexao_continue_cleanup">
								<?php wp_nonce_field( 'conexao_continue_cleanup', 'conexao_continue_nonce' ); ?>
								<?php submit_button( __( 'Continue', 'conexao-event-importer' ), 'primary', 'submit', false ); ?>
							</form>
						</div>
						<div class="conexao-transfer-card">
							<h2><?php esc_html_e( 'Cancel Cleanup', 'conexao-event-importer' ); ?></h2>
							<p><?php esc_html_e( 'Stop the current run. Pending attachment reviews are preserved.', 'conexao-event-importer' ); ?></p>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="conexao_cancel_cleanup">
								<?php wp_nonce_field( 'conexao_cancel_cleanup', 'conexao_cancel_nonce' ); ?>
								<?php submit_button( __( 'Cancel', 'conexao-event-importer' ), 'secondary', 'submit', false ); ?>
							</form>
						</div>
					</div>
				<?php else : ?>
					<div class="conexao-event-transfer">
						<div class="conexao-transfer-card">
							<h2><?php esc_html_e( 'Run Cleanup Now', 'conexao-event-importer' ); ?></h2>
							<p><?php esc_html_e( 'Deletes past events. Attachments are preserved by default and recorded for manual review.', 'conexao-event-importer' ); ?></p>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="conexao_run_cleanup">
								<?php wp_nonce_field( 'conexao_run_cleanup', 'conexao_cleanup_nonce' ); ?>
								<?php submit_button( __( 'Run Cleanup Now', 'conexao-event-importer' ), 'primary', 'submit', false ); ?>
							</form>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<div class="conexao-import-summary">
				<div class="conexao-import-stat">
					<span class="conexao-import-stat-label"><?php esc_html_e( 'Last cleanup', 'conexao-event-importer' ); ?></span>
					<span class="conexao-import-stat-value">
						<?php echo $last_time ? esc_html( $last_time ) : '&mdash;'; ?>
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
					<p><?php esc_html_e( 'Deletes past events and their exclusively-owned images. Events whose end date/time has passed are removed; images imported by the event importer are deleted only when they are no longer used by any other content.', 'conexao-event-importer' ); ?></p>

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
						<li><?php esc_html_e( 'Events without an end date are skipped (manual review territory).', 'conexao-event-importer' ); ?></li>
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