<?php
/**
 * Import error logging.
 *
 * Captures structured log entries with event context for diagnosing real
 * production problems. Logs are stored in a single WordPress option and
 * capped at 500 entries to avoid unbounded growth.
 *
 * Sensitive data (API keys, tokens, passwords) is never logged.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Import_Log {

	const OPTION_KEY = 'conexao_event_import_log';

	/** @var int Maximum number of log entries retained. */
	const MAX_ENTRIES = 500;

	/**
	 * Add a log entry.
	 *
	 * Structured log entry format:
	 *   - time:     MySQL datetime
	 *   - source:   Source slug
	 *   - level:    debug|info|warning|error
	 *   - message:  Human-readable message
	 *   - run_id:   Optional import run ID (groups entries from one run)
	 *   - event_id: Optional Eventbrite / source event ID
	 *   - event_title: Optional event title
	 *   - http_status: Optional HTTP response code
	 *   - context:  Optional array of additional non-sensitive context
	 *
	 * @param string $source_id Source slug.
	 * @param string $level     Level (info|warning|error|debug).
	 * @param string $message   Human-readable message.
	 * @param array  $context   Optional context (event title, URL, run_id, event_id, http_status, etc.).
	 */
	public static function add( $source_id, $level, $message, $context = array() ) {
		// Never log empty messages.
		if ( '' === trim( (string) $message ) ) {
			return;
		}

		// Build the structured entry.
		$entry = array(
			'time'        => current_time( 'mysql' ),
			'source'      => sanitize_key( (string) $source_id ),
			'level'       => self::normalize_level( $level ),
			'message'     => sanitize_text_field( (string) $message ),
			'run_id'      => isset( $context['run_id'] ) ? sanitize_key( (string) $context['run_id'] ) : '',
			'event_id'    => isset( $context['event_id'] ) ? sanitize_text_field( (string) $context['event_id'] ) : '',
			'event_title' => isset( $context['event_title'] ) ? sanitize_text_field( (string) $context['event_title'] ) : '',
			'http_status' => isset( $context['http_status'] ) ? (int) $context['http_status'] : 0,
		);

		// Strip sensitive keys from the context before storing.
		$context = self::sanitize_context( $context );

		// Keep a small amount of additional context (URL, attempt, etc.).
		$keep_keys = array( 'url', 'attempt', 'source_url', 'post_id' );
		$safe_context = array();
		foreach ( $keep_keys as $key ) {
			if ( isset( $context[ $key ] ) ) {
				$value = $context[ $key ];
				$safe_context[ $key ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
			}
		}
		$entry['context'] = $safe_context;

		$log = self::get_all();
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, self::MAX_ENTRIES );

		update_option( self::OPTION_KEY, $log, false );
	}

	/**
	 * Get all log entries (newest first).
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
	 * Get entries for a specific import run.
	 *
	 * @param string $run_id Import run ID.
	 * @param int    $limit  Max entries.
	 * @return array
	 */
	public static function get_for_run( $run_id, $limit = 100 ) {
		$entries = array();
		foreach ( self::get_all() as $entry ) {
			if ( isset( $entry['run_id'] ) && $entry['run_id'] === $run_id ) {
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
			if ( isset( $entry['source'] ) && $entry['source'] === $source_id && 'error' === $entry['level'] ) {
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

	/**
	 * Normalize a log level to a known set.
	 *
	 * @param string $level Raw level.
	 * @return string
	 */
	protected static function normalize_level( $level ) {
		$level = strtolower( trim( (string) $level ) );
		if ( ! in_array( $level, array( 'debug', 'info', 'warning', 'error' ), true ) ) {
			return 'info';
		}
		return $level;
	}

	/**
	 * Remove sensitive keys from a context array.
	 *
	 * @param array $context Raw context.
	 * @return array Sanitized context.
	 */
	protected static function sanitize_context( $context ) {
		if ( ! is_array( $context ) ) {
			return array();
		}

		$sensitive_keys = array(
			'token', 'tokens', 'password', 'pass', 'api_key', 'apikey', 'secret',
			'authorization', 'cookie', 'session', 'private_key', 'client_secret',
			'auth', 'credentials', 'bearer',
		);

		foreach ( $context as $key => $value ) {
			$key_lower = strtolower( (string) $key );
			foreach ( $sensitive_keys as $sensitive ) {
				if ( false !== strpos( $key_lower, $sensitive ) ) {
					unset( $context[ $key ] );
					break;
				}
			}
		}

		return $context;
	}
}