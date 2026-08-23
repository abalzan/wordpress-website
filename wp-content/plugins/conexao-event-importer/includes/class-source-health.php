<?php
/**
 * Source health tracking.
 *
 * Tracks consecutive import failures per source, detects stale sources
 * (no successful run within their expected frequency window) and can
 * automatically disable permanently broken sources.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Source_Health {

	const OPTION_KEY = 'conexao_event_source_health';

	/**
	 * Get the full health map.
	 *
	 * @return array source_id => health array.
	 */
	public static function get_all() {
		$health = get_option( self::OPTION_KEY, array() );
		return is_array( $health ) ? $health : array();
	}

	/**
	 * Get the health record for one source.
	 *
	 * @param string $source_id Source slug.
	 * @return array{consecutive_failures:int,last_success:string,last_failure:string,disabled_at:string,disable_reason:string}
	 */
	public static function get( $source_id ) {
		$all = self::get_all();
		if ( isset( $all[ $source_id ] ) && is_array( $all[ $source_id ] ) ) {
			return wp_parse_args(
				$all[ $source_id ],
				array(
					'consecutive_failures' => 0,
					'last_success'         => '',
					'last_failure'         => '',
					'disabled_at'          => '',
					'disable_reason'       => '',
				)
			);
		}

		return array(
			'consecutive_failures' => 0,
			'last_success'         => '',
			'last_failure'         => '',
			'disabled_at'          => '',
			'disable_reason'       => '',
		);
	}

	/**
	 * Record a successful (or partially successful) run for a source.
	 *
	 * Resets the consecutive failure counter.
	 *
	 * @param string $source_id Source slug.
	 */
	public static function record_success( $source_id ) {
		$health           = self::get_all();
		$existing         = isset( $health[ $source_id ] ) && is_array( $health[ $source_id ] ) ? $health[ $source_id ] : array();
		$existing['consecutive_failures'] = 0;
		$existing['last_success']         = current_time( 'mysql' );
		$health[ $source_id ]             = $existing;

		update_option( self::OPTION_KEY, $health, false );
	}

	/**
	 * Record a failed run for a source.
	 *
	 * Increments the consecutive failure counter and auto-disables the source
	 * when the configured threshold is reached.
	 *
	 * @param string $source_id Source slug.
	 * @param string $reason    Human-readable failure reason.
	 * @return bool True when the source was auto-disabled by this call.
	 */
	public static function record_failure( $source_id, $reason = '' ) {
		$health   = self::get_all();
		$existing = isset( $health[ $source_id ] ) && is_array( $health[ $source_id ] ) ? $health[ $source_id ] : array();

		$existing['consecutive_failures'] = isset( $existing['consecutive_failures'] ) ? (int) $existing['consecutive_failures'] + 1 : 1;
		$existing['last_failure']         = current_time( 'mysql' );

		$disabled = false;
		$threshold = (int) Conexao_Import_Settings::get( 'auto_disable_after_failures', 5 );

		if ( $threshold > 0 && $existing['consecutive_failures'] >= $threshold ) {
			$disabled = self::disable_source( $source_id, $reason );
			if ( $disabled ) {
				$existing['disabled_at']    = current_time( 'mysql' );
				$existing['disable_reason'] = sprintf(
					/* translators: 1: number of failures, 2: reason */
					__( 'Auto-disabled after %1$d consecutive failed imports. Last error: %2$s', 'conexao-event-importer' ),
					$existing['consecutive_failures'],
					$reason
				);
			}
		}

		$health[ $source_id ] = $existing;
		update_option( self::OPTION_KEY, $health, false );

		return $disabled;
	}

	/**
	 * Deactivate a source and record why.
	 *
	 * @param string $source_id Source slug.
	 * @param string $reason    Human-readable reason.
	 * @return bool True when the source was deactivated now.
	 */
	protected static function disable_source( $source_id, $reason = '' ) {
		if ( ! class_exists( 'Conexao_Event_Sources' ) ) {
			return false;
		}

		$sources = new Conexao_Event_Sources();
		$source  = $sources->get( $source_id );
		if ( ! $source || 'inactive' === $source['status'] ) {
			return false;
		}

		$sources->set_status( $source_id, 'inactive' );

		Conexao_Import_Log::add(
			$source_id,
			'error',
			sprintf(
				/* translators: 1: source name, 2: reason */
				__( 'Source "%1$s" was automatically disabled. Reason: %2$s', 'conexao-event-importer' ),
				isset( $source['name'] ) ? $source['name'] : $source_id,
				$reason
			),
			array( 'run_id' => apply_filters( 'conexao_event_importer_current_run_id', '' ) )
		);

		return true;
	}

	/**
	 * Clear the health record for a source (e.g. after manual re-enable).
	 *
	 * @param string $source_id Source slug.
	 */
	public static function reset( $source_id ) {
		$health = self::get_all();
		if ( isset( $health[ $source_id ] ) ) {
			unset( $health[ $source_id ] );
			update_option( self::OPTION_KEY, $health, false );
		}
	}

	/**
	 * Determine whether a source has not had a successful run within its
	 * expected frequency window.
	 *
	 * @param array $source Source config (needs id, import_frequency).
	 * @return bool True when the source looks stale.
	 */
	public static function is_stale( $source ) {
		if ( empty( $source['id'] ) || 'active' !== ( isset( $source['status'] ) ? $source['status'] : '' ) ) {
			return false;
		}

		$frequency = isset( $source['import_frequency'] ) ? $source['import_frequency'] : 'weekly';
		$interval  = ( 'daily' === $frequency ) ? DAY_IN_SECONDS : WEEK_IN_SECONDS;

		// Allow a small grace period before declaring staleness.
		$cutoff = time() - ( $interval + HOUR_IN_SECONDS );

		$record = self::get( $source['id'] );
		$last_success = ! empty( $record['last_success'] ) ? $record['last_success'] : '';

		// Fall back to the legacy last_import field when no health data exists yet.
		if ( '' === $last_success && ! empty( $source['last_import'] ) && 'error' !== ( isset( $source['last_import_status'] ) ? $source['last_import_status'] : '' ) ) {
			$last_success = $source['last_import'];
		}

		if ( '' === $last_success ) {
			// Never succeeded — only stale if it was checked at least once long ago.
			$last_checked = isset( $source['last_checked'] ) ? $source['last_checked'] : '';
			if ( '' === $last_checked ) {
				return false; // Never ran; give it a chance.
			}
			$checked_ts = self::parse_mysql( $last_checked );
			return $checked_ts > 0 && $checked_ts < $cutoff;
		}

		$success_ts = self::parse_mysql( $last_success );
		return $success_ts > 0 && $success_ts < $cutoff;
	}

	/**
	 * Parse a site-local MySQL datetime into a Unix timestamp.
	 *
	 * @param string $mysql MySQL datetime (site timezone).
	 * @return int Unix timestamp or 0 on failure.
	 */
	protected static function parse_mysql( $mysql ) {
		try {
			$dt = DateTime::createFromFormat( 'Y-m-d H:i:s', (string) $mysql, wp_timezone() );
			return $dt ? $dt->getTimestamp() : 0;
		} catch ( Exception $e ) {
			return 0;
		}
	}
}