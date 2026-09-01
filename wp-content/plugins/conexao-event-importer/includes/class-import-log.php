<?php
/**
 * Import error logging.
 *
 * Captures structured log entries with event context for diagnosing real
 * production problems. Logs are stored in a single WordPress option and
 * capped at 2000 entries to avoid unbounded growth; entries older than
 * 90 days are pruned (see prune_older_than_90_days()).
 *
 * Write amplification: during a managed import run (begin_run() … end_run(),
 * used by the importer engine), entries are buffered in memory and persisted
 * with a small number of bounded read-merge writes instead of rewriting the
 * whole option for every entry. Calls made outside a managed run persist
 * immediately, exactly as before, so no entry is ever silently lost.
 *
 * Sensitive data (API keys, tokens, passwords) is never logged.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Import_Log {

	const OPTION_KEY = 'conexao_event_import_log';

	/**
	 * Maximum number of log entries retained.
	 *
	 * High enough to hold several full import runs even when each event is
	 * logged individually (created/updated/skipped/failed), while still
	 * bounding growth so the option row never becomes unbounded. Older
	 * entries are dropped automatically once the cap is reached; the most
	 * recent entries are always kept.
	 *
	 * @var int
	 */
	const MAX_ENTRIES = 2000;

	/**
	 * Number of buffered entries that trigger an intermediate flush.
	 *
	 * Bounds memory usage for very large runs while keeping the number of
	 * option writes per run tiny (a full run typically writes once).
	 *
	 * @var int
	 */
	const BUFFER_FLUSH_AT = 250;

	/**
	 * Buffered entries awaiting persistence (oldest first).
	 *
	 * @var array[]
	 */
	protected static $buffer = array();

	/**
	 * Nesting depth of managed runs. Only the outermost end_run() flushes,
	 * so run_all() wrapping run_source() yields a single bounded write.
	 *
	 * @var int
	 */
	protected static $run_depth = 0;

	/**
	 * Whether the shutdown safety-net hook has been registered.
	 *
	 * @var bool
	 */
	protected static $shutdown_registered = false;

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

		// Managed run active: buffer the entry and persist it on flush.
		if ( self::$run_depth > 0 ) {
			self::$buffer[] = $entry;

			// Bound memory for very large runs; entries stay ordered because
			// each flush prepends a newer batch in front of the previous one.
			if ( count( self::$buffer ) >= self::BUFFER_FLUSH_AT ) {
				self::flush();
			}
			return;
		}

		// No managed run active: persist immediately (legacy behavior), so
		// ad-hoc callers are never silently lost.
		self::persist_entries( array( $entry ) );
	}

	/**
	 * Start buffering log entries for a managed import run.
	 *
	 * Calls may nest (run_all() wraps run_source()): only the outermost
	 * end_run() persists. A shutdown hook acts as a safety net so buffered
	 * entries survive an abnormal request termination.
	 */
	public static function begin_run() {
		self::$run_depth++;

		if ( 1 === self::$run_depth && ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			add_action( 'shutdown', array( __CLASS__, 'flush' ), 5 );
		}
	}

	/**
	 * Finish a managed import run and persist buffered entries.
	 *
	 * Safe to call more than once (extra calls are no-ops).
	 */
	public static function end_run() {
		if ( self::$run_depth > 0 ) {
			self::$run_depth--;
		}

		if ( 0 === self::$run_depth ) {
			self::flush();
		}
	}

	/**
	 * Persist any buffered entries now (bounded read-merge-write).
	 *
	 * Concurrency: the stored log is re-read immediately before writing so a
	 * flush only prepends this run's entries to the freshest persisted state —
	 * it never overwrites entries persisted by a concurrent run. A single
	 * flush per run (instead of one write per entry) also shrinks the race
	 * window dramatically for this local, manually-triggered tool.
	 */
	public static function flush() {
		if ( empty( self::$buffer ) ) {
			return;
		}

		// Buffer is oldest-first; storage is newest-first.
		$entries = array_reverse( self::$buffer );
		self::$buffer = array();

		self::persist_entries( $entries );
	}

	/**
	 * Prepend entries (newest first) to the stored log, respecting the cap.
	 *
	 * @param array[] $entries Structured entries, newest first.
	 */
	protected static function persist_entries( $entries ) {
		if ( empty( $entries ) ) {
			return;
		}

		$log = self::get_all();
		$log = array_merge( $entries, $log );
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
		// Drop any buffered entries too: the caller asked for a clean slate.
		self::$buffer = array();
		update_option( self::OPTION_KEY, array(), false );
	}

	/**
	 * List distinct import run IDs present in the log (newest first).
	 *
	 * Used by the admin "Import Logs" screen so an administrator can see and
	 * download a specific import run rather than only the whole log.
	 *
	 * @param int $limit Max number of runs to return.
	 * @return array Run IDs, newest first.
	 */
	public static function get_run_ids( $limit = 50 ) {
		$run_ids = array();
		foreach ( self::get_all() as $entry ) {
			$run_id = isset( $entry['run_id'] ) ? trim( (string) $entry['run_id'] ) : '';
			if ( '' === $run_id ) {
				continue;
			}
			if ( ! in_array( $run_id, $run_ids, true ) ) {
				$run_ids[] = $run_id;
			}
			if ( count( $run_ids ) >= $limit ) {
				break;
			}
		}
		return $run_ids;
	}

	/**
	 * Obtain an ordered human-readable representation of a log entry.
	 *
	 * Single-line, newline-free format so each log entry maps to exactly one
	 * line in the downloaded .log/.txt file.
	 *
	 * Example:
	 *   2026-08-18 14:32:01 | INFO    | Import started
	 *   2026-08-18 14:32:03 | CREATED | Event: Example Event | Eventbrite ID: 123456
	 *
	 * Level is left-padded to 7 characters for aligned columns. The operation
	 * column doubles as the outcome for created/updated/skipped/failed entries.
	 *
	 * @param array $entry Structured log entry.
	 * @return string One-line log string.
	 */
	public static function format_entry_line( $entry ) {
		if ( ! is_array( $entry ) ) {
			return '';
		}

		$time      = isset( $entry['time'] ) ? (string) $entry['time'] : '';
		$source    = isset( $entry['source'] ) ? (string) $entry['source'] : '';
		$level     = strtoupper( self::normalize_level( isset( $entry['level'] ) ? $entry['level'] : 'info' ) );
		$message   = isset( $entry['message'] ) ? (string) $entry['message'] : '';
		$event_id  = isset( $entry['event_id'] ) ? (string) $entry['event_id'] : '';
		$event_ttl = isset( $entry['event_title'] ) ? (string) $entry['event_title'] : '';
		$http      = isset( $entry['http_status'] ) ? (int) $entry['http_status'] : 0;

		// Strip newlines/tabs so a single entry stays on one line.
		$strip = function ( $value ) {
			return str_replace( array( "\r", "\n", "\t" ), ' ', (string) $value );
		};

		$level_padded = str_pad( $level, 7 );

		// Start with timestamp + level (align with the example format).
		$line = $time . ' | ' . $level_padded . ' | ';

		// Include source when present (not strictly one of the example columns,
		// but essential to know which feed/API produced the line).
		if ( '' !== $source && 'image_handler' !== $source ) {
			$line .= 'Source: ' . $strip( $source ) . ' | ';
		}

		// Event title + Eventbrite (source) ID.
		if ( '' !== $event_ttl ) {
			$line .= 'Event: ' . $strip( $event_ttl );
			if ( '' !== $event_id ) {
				$line .= ' | Eventbrite ID: ' . $strip( $event_id );
			}
		} elseif ( '' !== $event_id ) {
			$line .= 'Eventbrite ID: ' . $strip( $event_id );
		}

		if ( '' !== $event_ttl || '' !== $event_id ) {
			$line .= ' | ';
		}

		$line .= $strip( $message );

		// Append HTTP status where available.
		if ( $http > 0 ) {
			$line .= ' | HTTP ' . (int) $http;
		}

		// Append a small amount of safe context (URL, attempt, post_id).
		$context = isset( $entry['context'] ) && is_array( $entry['context'] ) ? $entry['context'] : array();
		$context_labels = array(
			'url'        => 'URL',
			'attempt'    => 'Attempt',
			'post_id'    => 'Post ID',
			'source_url' => 'Source URL',
		);
		foreach ( $context_labels as $ctx_key => $ctx_label ) {
			if ( isset( $context[ $ctx_key ] ) && '' !== (string) $context[ $ctx_key ] ) {
				$line .= ' | ' . $ctx_label . ': ' . $strip( $context[ $ctx_key ] );
			}
		}

		return $line;
	}

	/**
	 * Format a collection of log entries as a plain-text block.
	 *
	 * Newest first -> oldest last, matching the on-screen ordering.
	 *
	 * @param array $entries Log entries.
	 * @return string Plain-text log content.
	 */
	public static function format_entries( $entries ) {
		if ( ! is_array( $entries ) || empty( $entries ) ) {
			return '';
		}

		$lines = array();
		foreach ( $entries as $entry ) {
			$line = self::format_entry_line( $entry );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Build a plain-text .log document for the whole log.
	 *
	 * @return string
	 */
	public static function export_all() {
		$lines   = array();
		$lines[] = '# Event Importer Log';
		$lines[] = '# Generated: ' . current_time( 'mysql' );
		$lines[] = '';
		$entries = self::get_all();
		if ( empty( $entries ) ) {
			$lines[] = '(No log entries yet.)';
			return implode( "\n", $lines ) . "\n";
		}
		$lines[] = self::format_entries( $entries );
		return implode( "\n", $lines );
	}

	/**
	 * Build a plain-text .log document for a single import run.
	 *
	 * @param string $run_id Import run ID.
	 * @return string
	 */
	public static function export_run( $run_id ) {
		$run_id  = sanitize_key( (string) $run_id );
		$entries = self::get_for_run( $run_id, 1000 );

		$lines   = array();
		$lines[] = '# Event Importer Log — Import run ' . $run_id;
		$lines[] = '# Generated: ' . current_time( 'mysql' );
		$lines[] = '';

		if ( empty( $entries ) ) {
			$lines[] = '(No log entries for this run.)';
			return implode( "\n", $lines ) . "\n";
		}

		$lines[] = self::format_entries( $entries );
		return implode( "\n", $lines );
	}

	/**
	 * Purge log entries older than a given age limit, regardless of cap.
	 *
	 * Retention is a defence-in-depth complement to MAX_ENTRIES: keep a rolling
	 * window of recent activity while guaranteeing old history does not grow
	 * forever. The maximum age of 90 days keeps enough history to diagnose an
	 * import that failed weeks earlier without unbounded growth.
	 *
	 * @return int Number of entries removed.
	 */
	public static function prune_older_than_90_days() {
		// Flush buffered entries first so retention also applies to them.
		self::flush();

		$log      = self::get_all();
		$cutoff   = time() - 90 * DAY_IN_SECONDS;
		$filtered = array();
		$removed  = 0;

		foreach ( $log as $entry ) {
			$time = isset( $entry['time'] ) ? strtotime( (string) $entry['time'] ) : 0;
			if ( $time > 0 && $time < $cutoff ) {
				$removed++;
				continue;
			}
			$filtered[] = $entry;
		}

		if ( $removed > 0 ) {
			update_option( self::OPTION_KEY, $filtered, false );
		}

		return $removed;
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