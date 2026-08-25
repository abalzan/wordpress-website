<?php
/**
 * Source health tracking.
 *
 * Tracks consecutive import failures per source so administrators can see
 * at a glance which sources are failing. This is for *informational*
 * purposes only — sources are never automatically disabled.
 *
 * Importing is a manual, local-only operation. An administrator reviews
 * failures here and decides whether to fix a source or deactivate it.
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
	 * @return array{consecutive_failures:int,last_success:string,last_failure:string,total_failures:int}
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
					'total_failures'       => 0,
				)
			);
		}

		return array(
			'consecutive_failures' => 0,
			'last_success'         => '',
			'last_failure'         => '',
			'total_failures'       => 0,
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
	 * Increments the consecutive failure counter. Sources are NOT
	 * auto-disabled — the administrator decides whether to deactivate.
	 *
	 * @param string $source_id Source slug.
	 * @param string $reason    Human-readable failure reason.
	 */
	public static function record_failure( $source_id, $reason = '' ) {
		$health   = self::get_all();
		$existing = isset( $health[ $source_id ] ) && is_array( $health[ $source_id ] ) ? $health[ $source_id ] : array();

		$existing['consecutive_failures'] = isset( $existing['consecutive_failures'] ) ? (int) $existing['consecutive_failures'] + 1 : 1;
		$existing['last_failure']         = current_time( 'mysql' );
		$existing['total_failures']       = isset( $existing['total_failures'] ) ? (int) $existing['total_failures'] + 1 : 1;

		$health[ $source_id ] = $existing;
		update_option( self::OPTION_KEY, $health, false );

		if ( $reason ) {
			Conexao_Import_Log::add(
				$source_id,
				'error',
				sprintf(
					/* translators: %s: failure reason */
					__( 'Import failure recorded: %s', 'conexao-event-importer' ),
					$reason
				)
			);
		}
	}

	/**
	 * Clear the health record for a source.
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
}