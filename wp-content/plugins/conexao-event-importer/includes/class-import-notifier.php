<?php
/**
 * Import failure notifications.
 *
 * Sends email alerts when an automated import run finishes with errors, and
 * when a source is auto-disabled. Keeps humans informed without requiring
 * them to watch the wp-admin dashboard.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Import_Notifier {

	/**
	 * Notify about a finished automated run.
	 *
	 * Only sends when the run did NOT finish cleanly and failure alerts are
	 * enabled in the settings. Success runs stay silent to avoid noise.
	 *
	 * @param array  $combined Combined run result (see Conexao_Event_Importer_Engine::run_all()).
	 * @param string $trigger  What started the run: cron|rest|cli|manual.
	 */
	public function notify_run_finished( $combined, $trigger = 'cron' ) {
		if ( empty( $combined ) || ! is_array( $combined ) ) {
			return;
		}

		if ( ! Conexao_Import_Settings::get( 'notify_on_failure', true ) ) {
			return;
		}

		$status = isset( $combined['status'] ) ? $combined['status'] : 'success';
		if ( in_array( $status, array( 'success' ), true ) ) {
			return; // Clean run — no email.
		}

		$to      = Conexao_Import_Settings::get_notify_email();
		if ( ! $to || ! is_email( $to ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: 1: site name, 2: run status */
			__( '[%1$s] Event import %2$s', 'conexao-event-importer' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			$this->status_label( $status )
		);

		$body = $this->build_run_body( $combined, $trigger );

		wp_mail( $to, $subject, $body );
	}

	/**
	 * Notify that a source was automatically disabled.
	 *
	 * @param string $source_id   Source slug.
	 * @param string $source_name Source display name.
	 * @param int    $failures    Consecutive failure count.
	 * @param string $reason      Failure reason.
	 */
	public function notify_auto_disabled( $source_id, $source_name, $failures, $reason ) {
		$to = Conexao_Import_Settings::get_notify_email();
		if ( ! $to || ! is_email( $to ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: 1: site name, 2: source name */
			__( '[%1$s] Event source "%2$s" was auto-disabled', 'conexao-event-importer' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			$source_name
		);

		$lines   = array();
		$lines[] = sprintf(
			/* translators: 1: source name, 2: number of consecutive failures */
			__( 'The event source "%1$s" failed %2$d imports in a row and has been deactivated automatically.', 'conexao-event-importer' ),
			$source_name,
			$failures
		);
		$lines[] = '';
		$lines[] = __( 'Last error:', 'conexao-event-importer' ) . ' ' . $reason;
		$lines[] = '';
		$lines[] = __( 'Re-enable it from Event Import → Event Sources once the problem is fixed. The health counter resets on the next successful import.', 'conexao-event-importer' );
		$lines[] = admin_url( 'admin.php?page=conexao-event-sources' );

		wp_mail( $to, $subject, implode( "
", $lines ) );
	}

	/**
	 * Build the plain-text body for a finished-run notification.
	 *
	 * @param array  $combined Combined run result.
	 * @param string $trigger  Run trigger.
	 * @return string
	 */
	protected function build_run_body( $combined, $trigger ) {
		$status = isset( $combined['status'] ) ? $combined['status'] : 'success';

		$lines   = array();
		$lines[] = sprintf(
			/* translators: 1: status label, 2: trigger, 3: time */
			__( 'An automated event import finished with status "%1$s" (trigger: %2$s) at %3$s.', 'conexao-event-importer' ),
			$this->status_label( $status ),
			$trigger,
			current_time( 'mysql' )
		);
		$lines[] = '';

		// Per-source summary.
		if ( ! empty( $combined['sources'] ) && is_array( $combined['sources'] ) ) {
			$lines[] = __( 'Per-source results:', 'conexao-event-importer' );
			foreach ( $combined['sources'] as $source_id => $result ) {
				$name    = isset( $result['source_name'] ) ? $result['source_name'] : $source_id;
				$rstatus = isset( $result['status'] ) ? $result['status'] : 'unknown';
				$created = isset( $result['created'] ) ? (int) $result['created'] : 0;
				$updated = isset( $result['updated'] ) ? (int) $result['updated'] : 0;
				$failed  = isset( $result['failed'] ) ? (int) $result['failed'] : 0;

				$lines[] = sprintf(
					'- %s: %s (%d created, %d updated, %d failed)',
					$name,
					$this->status_label( $rstatus ),
					$created,
					$updated,
					$failed
				);
			}
			$lines[] = '';
		}

		// Fatal errors.
		if ( ! empty( $combined['fatal_errors'] ) && is_array( $combined['fatal_errors'] ) ) {
			$lines[] = __( 'Fatal errors:', 'conexao-event-importer' );
			foreach ( array_slice( $combined['fatal_errors'], 0, 10 ) as $error ) {
				$message = isset( $error['message'] ) ? $error['message'] : '';
				if ( $message ) {
					$lines[] = '- ' . $message;
				}
			}
			$lines[] = '';
		}

		// Failed events sample.
		$failed_events = array();
		if ( ! empty( $combined['event_results'] ) && is_array( $combined['event_results'] ) ) {
			foreach ( $combined['event_results'] as $event ) {
				if ( isset( $event['outcome'] ) && 'failed' === $event['outcome'] ) {
					$failed_events[] = $event;
				}
			}
		}
		if ( ! empty( $failed_events ) ) {
			$lines[] = sprintf(
				/* translators: %d: number of failed events */
				_n( '%d event failed to import:', '%d events failed to import:', count( $failed_events ), 'conexao-event-importer' ),
				count( $failed_events )
			);
			foreach ( array_slice( $failed_events, 0, 15 ) as $event ) {
				$title   = isset( $event['title'] ) ? $event['title'] : '';
				$message = isset( $event['message'] ) ? $event['message'] : '';
				$lines[] = '- ' . $title . ( $message ? ' — ' . $message : '' );
			}
			if ( count( $failed_events ) > 15 ) {
				$lines[] = sprintf(
					/* translators: %d: remaining count */
					__( '…and %d more (see the Import History page).', 'conexao-event-importer' ),
					count( $failed_events ) - 15
				);
			}
			$lines[] = '';
		}

		$lines[] = __( 'Full details:', 'conexao-event-importer' );
		$lines[] = admin_url( 'admin.php?page=conexao-event-import' );

		return implode( "
", $lines );
	}

	/**
	 * Human-readable label for a run status.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	protected function status_label( $status ) {
		$labels = array(
			'success' => __( 'success', 'conexao-event-importer' ),
			'warning' => __( 'completed with warnings', 'conexao-event-importer' ),
			'partial' => __( 'completed with errors', 'conexao-event-importer' ),
			'failed'  => __( 'FAILED', 'conexao-event-importer' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}
}