<?php
/**
 * Import error logging.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Import_Log {

	const OPTION_KEY = 'conexao_event_import_log';

	/**
	 * Add a log entry.
	 *
	 * @param string $source_id Source slug.
	 * @param string $level     Level (info|warning|error).
	 * @param string $message   Human-readable message.
	 * @param array  $context   Optional context (event title, URL, etc.).
	 */
	public static function add( $source_id, $level, $message, $context = array() ) {
		$log   = self::get_all();
		$entry = array(
			'time'    => current_time( 'mysql' ),
			'source'  => $source_id,
			'level'   => $level,
			'message' => $message,
			'context' => $context,
		);

		array_unshift( $log, $entry );
		// Keep the log reasonable (last 200 entries).
		$log = array_slice( $log, 0, 200 );

		update_option( self::OPTION_KEY, $log, false );
	}

	/**
	 * Get all log entries.
	 *
	 * @return array
	 */
	public static function get_all() {
		$log = get_option( self::OPTION_KEY, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Get entries for a specific source.
	 *
	 * @param string $source_id Source slug.
	 * @param int    $limit     Max entries.
	 * @return array
	 */
	public static function get_for_source( $source_id, $limit = 20 ) {
		$entries = array();
		foreach ( self::get_all() as $entry ) {
			if ( $entry['source'] === $source_id ) {
				$entries[] = $entry;
			}
			if ( count( $entries ) >= $limit ) {
				break;
			}
		}
		return $entries;
	}

	/**
	 * Get the most recent error message for a source.
	 *
	 * @param string $source_id Source slug.
	 * @return string
	 */
	public static function get_last_error( $source_id ) {
		foreach ( self::get_all() as $entry ) {
			if ( $entry['source'] === $source_id && 'error' === $entry['level'] ) {
				return $entry['message'];
			}
		}
		return '';
	}

	/**
	 * Clear the log.
	 */
	public static function clear() {
		update_option( self::OPTION_KEY, array(), false );
	}
}