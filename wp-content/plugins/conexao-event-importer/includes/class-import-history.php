<?php
/**
 * Import history storage and retrieval.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Import_History {

	const OPTION_KEY = 'conexao_event_import_history';

	/**
	 * Record a completed import run.
	 *
	 * @param string $source_id  Source slug.
	 * @param array  $stats      Stats: found, new, updated, duplicates, needs_review, errors.
	 * @param string $status     success|error|partial.
	 * @param string $message    Optional summary message.
	 */
	public static function record( $source_id, $stats, $status = 'success', $message = '' ) {
		$history = self::get_all();
		$entry   = array(
			'time'          => current_time( 'mysql' ),
			'source'        => $source_id,
			'found'         => isset( $stats['found'] ) ? (int) $stats['found'] : 0,
			'new'           => isset( $stats['new'] ) ? (int) $stats['new'] : 0,
			'updated'       => isset( $stats['updated'] ) ? (int) $stats['updated'] : 0,
			'duplicates'    => isset( $stats['duplicates'] ) ? (int) $stats['duplicates'] : 0,
			'needs_review'  => isset( $stats['needs_review'] ) ? (int) $stats['needs_review'] : 0,
			'errors'        => isset( $stats['errors'] ) ? (int) $stats['errors'] : 0,
			'status'        => $status,
			'message'       => $message,
		);

		array_unshift( $history, $entry );
		// Keep the last 100 runs.
		$history = array_slice( $history, 0, 100 );

		update_option( self::OPTION_KEY, $history, false );

		return $entry;
	}

	/**
	 * Get the full import history (newest first).
	 *
	 * @return array
	 */
	public static function get_all() {
		$history = get_option( self::OPTION_KEY, array() );
		return is_array( $history ) ? $history : array();
	}

	/**
	 * Get history for a specific source.
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
	 * Get the last run for a source.
	 *
	 * @param string $source_id Source slug.
	 * @return array|null
	 */
	public static function get_last( $source_id ) {
		foreach ( self::get_all() as $entry ) {
			if ( $entry['source'] === $source_id ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Get the timestamp of the last successful import for a source.
	 *
	 * @param string $source_id Source slug.
	 * @return string MySQL datetime or empty string.
	 */
	public static function get_last_success_time( $source_id ) {
		foreach ( self::get_all() as $entry ) {
			if ( $entry['source'] === $source_id && 'success' === $entry['status'] ) {
				return $entry['time'];
			}
		}
		return '';
	}

	/**
	 * Clear the history.
	 */
	public static function clear() {
		update_option( self::OPTION_KEY, array(), false );
	}
}