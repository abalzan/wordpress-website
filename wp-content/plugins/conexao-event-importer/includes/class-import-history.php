<?php
/**
 * Import history storage and retrieval.
 *
 * Stores structured records of completed import runs, including per-event
 * failure details so administrators can see which events failed and why.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Import_History {

	const OPTION_KEY = 'conexao_event_import_history';

	/** @var int Maximum number of history entries retained. */
	const MAX_ENTRIES = 100;

	/**
	 * Record a completed import run.
	 *
	 * @param string $source_id  Source slug.
	 * @param array  $stats      Stats: found, new, updated, unchanged, duplicates, skipped, skipped_past, skipped_invalid_date, needs_review, errors (failed).
	 * @param string $status     success|warning|partial|error|failed.
	 * @param string $message    Optional summary message.
	 * @param array  $extra      Optional extra data: failed_events, fatal_errors, run_id, event_results.
	 * @return array The recorded history entry.
	 */
	public static function record( $source_id, $stats, $status = 'success', $message = '', $extra = array() ) {
		$history = self::get_all();

		$entry = array(
			'time'          => current_time( 'mysql' ),
			'source'        => sanitize_key( (string) $source_id ),
			'found'         => isset( $stats['found'] ) ? (int) $stats['found'] : 0,
			'new'           => isset( $stats['new'] ) ? (int) $stats['new'] : 0,
			'updated'       => isset( $stats['updated'] ) ? (int) $stats['updated'] : 0,
			'unchanged'     => isset( $stats['unchanged'] ) ? (int) $stats['unchanged'] : 0,
			'duplicates'           => isset( $stats['duplicates'] ) ? (int) $stats['duplicates'] : 0,
			'skipped'              => isset( $stats['skipped'] ) ? (int) $stats['skipped'] : 0,
			'skipped_past'         => isset( $stats['skipped_past'] ) ? (int) $stats['skipped_past'] : 0,
			'skipped_invalid_date' => isset( $stats['skipped_invalid_date'] ) ? (int) $stats['skipped_invalid_date'] : 0,
			'needs_review'         => isset( $stats['needs_review'] ) ? (int) $stats['needs_review'] : 0,
			'errors'        => isset( $stats['errors'] ) ? (int) $stats['errors'] : 0,
			'status'        => self::normalize_status( $status ),
			'message'       => sanitize_text_field( (string) $message ),
		);

		// Include optional extra data (bounded to avoid huge options rows).
		if ( ! empty( $extra['run_id'] ) ) {
			$entry['run_id'] = sanitize_key( (string) $extra['run_id'] );
		}
		if ( ! empty( $extra['fatal_errors'] ) && is_array( $extra['fatal_errors'] ) ) {
			$entry['fatal_errors'] = array_slice( $extra['fatal_errors'], 0, 20 );
		}
		if ( ! empty( $extra['failed_events'] ) && is_array( $extra['failed_events'] ) ) {
			$entry['failed_events'] = array_slice( $extra['failed_events'], 0, 100 );
		}

		array_unshift( $history, $entry );
		$history = array_slice( $history, 0, self::MAX_ENTRIES );

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
			if ( isset( $entry['source'] ) && $entry['source'] === $source_id ) {
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
			if ( isset( $entry['source'] ) && $entry['source'] === $source_id ) {
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
			if ( isset( $entry['source'] ) && $entry['source'] === $source_id && 'success' === $entry['status'] ) {
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

	/**
	 * Normalize a status string.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	protected static function normalize_status( $status ) {
		$status = strtolower( trim( (string) $status ) );
		$valid  = array( 'success', 'warning', 'partial', 'failed', 'error' );
		return in_array( $status, $valid, true ) ? $status : 'success';
	}
}