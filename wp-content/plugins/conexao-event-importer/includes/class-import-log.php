<?php
/**
 * Import error logging.
 *
 * Captures structured log entries with event context for diagnosing real
 * production problems. Logs are stored in a single WordPress option and
 * bounded at MAX_ENTRIES entries.
 *
 * ## Stage 3 (P13) — the bound is group-aware, not a blind slice
 *
 * Before Stage 3 the store was trimmed with `array_slice( $log, 0, 2000 )`,
 * which drops the OLDEST entries regardless of which run they belong to. A
 * logical Run All writes its logs one source at a time, so the beginning of a
 * run (the parent row, the first sources' entries) could be evicted while the
 * later sources were still executing: the run was then impossible to
 * reconstruct. Raising the number would only postpone that.
 *
 * `retain()` now treats a logical run as the retention unit, using Stage 1's
 * existing identity model (the log entry's `context.parent_run_group_id`,
 * falling back to its `run_id`). A group is retained or evicted whole — never
 * partially — and a group that is still active is never evicted at all. See
 * `retain()` for the exact policy and `get_retention_state()` for the
 * measurable bound and the pressure report.
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
	 * Prefix of the per-process test store.
	 *
	 * Only used when the shared test harness is loaded (`CONEXAO_TESTS_ROOT`)
	 * and has established a per-process suffix. It is never equal to
	 * `OPTION_KEY`, so a test row can never be mistaken for the operator's log.
	 *
	 * @var string
	 */
	const TEST_OPTION_PREFIX = 'conexao_event_import_log__test__';

	/**
	 * Resolve the option name this store reads from and writes to.
	 *
	 * Production (no test harness loaded) resolves `OPTION_KEY` exactly as
	 * before. A test process resolves its own per-process row
	 * (`TEST_OPTION_PREFIX . suffix`).
	 *
	 * If the harness marker is present but the suffix is missing or malformed,
	 * this THROWS instead of falling back to `OPTION_KEY`: silently writing the
	 * operator's log from a test process is the defect this seam exists to
	 * remove, and `update_option()` cannot report a refusal to its caller.
	 *
	 * @throws RuntimeException When the harness is loaded without a valid suffix.
	 * @return string Option name.
	 */
	public static function option_key() {
		if ( ! defined( 'CONEXAO_TESTS_ROOT' ) && ! defined( 'CONEXAO_TEST_OPTION_SUFFIX' ) ) {
			return self::OPTION_KEY;
		}

		$suffix = self::test_option_suffix();

		if ( '' === $suffix ) {
			throw new RuntimeException( 'conexao: operator-store isolation was not established for the import log store.' );
		}

		return self::TEST_OPTION_PREFIX . $suffix;
	}

	/**
	 * Validated per-process test suffix, or '' when absent/malformed.
	 *
	 * @return string
	 */
	protected static function test_option_suffix() {
		if ( ! defined( 'CONEXAO_TEST_OPTION_SUFFIX' ) ) {
			return '';
		}

		$suffix = strtolower( (string) CONEXAO_TEST_OPTION_SUFFIX );

		return preg_match( '/^p[1-9][0-9]{0,9}_[a-f0-9]{16}$/', $suffix ) ? $suffix : '';
	}

	/**
	 * Maximum number of log entries retained.
	 *
	 * Stage 3 (P13) raised this from 2,000 to 8,000 because the measured
	 * workload does not fit in 2,000: the live store on 2026-11-15 held 2,000
	 * entries spanning only 23 hours, at a measured average of 468 bytes and
	 * 11 entries per logical run, with single runs up to 81 entries. A Run All
	 * over the 154 registered sources therefore produces roughly 1,700–3,900
	 * entries — one logical run did not fit inside the old cap at all.
	 *
	 * The number alone is NOT the fix; `retain()` adds group integrity and
	 * active-run protection on top of it. The bound is expressed here, in
	 * entries, because that is what the store physically holds; at the measured
	 * 468 bytes/entry the worst case is ≈3.7 MB in one non-autoloaded option
	 * row, read only by the admin history screen and by a log flush.
	 *
	 * @var int
	 */
	const MAX_ENTRIES = 8000;

	/**
	 * Context keys that survive sanitisation into the stored entry.
	 *
	 * Stage 3 (P12): a continuation lifecycle entry is only useful if it can be
	 * traced to the run that produced it. The continuation scheduler already
	 * passes `run_id`, `parent_run_group_id`, `source_run_id`,
	 * `continuation_id`, `cause`, `attempts` and the position fields, but the
	 * allowlist below silently discarded every one of them except `run_id` —
	 * which is why the 2026-11-15 live store held 159 continuation lifecycle
	 * entries with only `reason`, `reason_code` and `cooldown_seconds` left.
	 *
	 * These keys are identifiers, state words and small integers. None is
	 * derived from request content, and `sanitize_context()` still strips
	 * anything matching a secret name first.
	 *
	 * @var string[]
	 */
	const CORRELATION_CONTEXT_KEYS = array(
		'source_run_id',
		'parent_run_group_id',
		'run_group_id',
		'continuation_id',
		'lifecycle',
		'state',
		'cause',
		'run_at',
		'attempts',
		'stalled',
		'last_page',
		'page_count',
		'next_eligible',
		'phase',
	);

	/**
	 * Group key used for entries that carry no run identity at all.
	 *
	 * Legacy entries written before run correlation existed cannot be attached
	 * to any run, and inventing one would be worse than admitting the gap. They
	 * are retained as an explicitly unattributed bucket and evicted
	 * individually, oldest first, before any real group is touched.
	 *
	 * @var string
	 */
	const LEGACY_GROUP = 'legacy';


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
		//
		// G5D: an operator must be able to tell a rate-limited source from a
		// generically unavailable one, so the rate-limit classification fields
		// are part of the allowlist too. Without them the reason for stopping is
		// written to the entry and then discarded.
		$keep_keys = array_merge(
			array(
				'url',
				'attempt',
				'source_url',
				'post_id',
				'reason',
				'reason_code',
				'page',
				'cooldown_seconds',
				'retry_after',
				'retry_after_present',
				'retry_after_capped',
				'rate_limit_hits',
				'rate_limit_streak',
				'skipped',
				'duration',
				'content_type',
				'body_preview',
				// A 405 WAF refusal retains ONLY this bounded, allowlisted
				// action token (captcha|challenge|block|allow|unknown) so the
				// WAF classification is provable from the log. It is never a
				// cookie, token, session identifier or body.
				'waf_action',
			),
			// Stage 3 (P12): run/continuation correlation and the diagnostic
			// state a continuation lifecycle entry exists to explain.
			self::CORRELATION_CONTEXT_KEYS
		);
		$safe_context = array();
		foreach ( $keep_keys as $key ) {
			if ( ! isset( $context[ $key ] ) ) {
				continue;
			}
			$value = $context[ $key ];
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			// Identifiers are keys; everything else is a small state word or a
			// bounded integer. `sanitize_key()` would mangle `source-<uuid>`
			// only by lowercasing it, which is already how the engine writes
			// those IDs, but text is the safer general case and preserves them
			// byte for byte.
			$safe_context[ $key ] = sanitize_text_field( (string) $value );
		}
		$entry['context'] = array_filter(
			$safe_context,
			static function ( $value ) {
				return '' !== (string) $value;
			}
		);

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
	 * Prepend entries (newest first) to the stored log, then apply retention.
	 *
	 * @param array[] $entries Structured entries, newest first.
	 */
	protected static function persist_entries( $entries ) {
		if ( empty( $entries ) ) {
			return;
		}

		$log = self::get_all();
		$log = array_merge( $entries, $log );
		$log = self::retain( $log );

		update_option( self::option_key(), $log, false );
	}

	/**
	 * The logical-run key one entry belongs to.
	 *
	 * Stage 1 publishes the logical run identity in two places, and this reads
	 * them in precedence order:
	 *
	 *   1. `context.parent_run_group_id` — the most precise, and the one a
	 *      continuation lifecycle entry carries;
	 *   2. `run_id` — the history row's run ID, which for a child source run is
	 *      the parent group (see `run_source()`), so it is the same identity for
	 *      engine-written entries;
	 *   3. `LEGACY_GROUP` — no identity at all.
	 *
	 * No identifier is derived from a timestamp or a source slug: an entry that
	 * cannot be attributed stays unattributed.
	 *
	 * @param array $entry Stored log entry.
	 * @return string Group key.
	 */
	public static function entry_group_key( array $entry ) {
		$context = isset( $entry['context'] ) && is_array( $entry['context'] ) ? $entry['context'] : array();

		foreach ( array( 'parent_run_group_id', 'run_group_id' ) as $key ) {
			if ( isset( $context[ $key ] ) && '' !== trim( (string) $context[ $key ] ) ) {
				return 'group:' . trim( (string) $context[ $key ] );
			}
		}

		$run_id = isset( $entry['run_id'] ) ? trim( (string) $entry['run_id'] ) : '';

		if ( '' !== $run_id ) {
			return 'group:' . $run_id;
		}

		return self::LEGACY_GROUP;
	}

	/**
	 * Apply the retention bound to a log array.
	 *
	 * ## Policy (Stage 3 / P13)
	 *
	 * * **Bound.** `MAX_ENTRIES` entries — the physical size of the store, and
	 *   the only thing that can force an eviction.
	 * * **Unit.** A whole logical group: every entry sharing the same
	 *   `entry_group_key()`. A group is never split, so the beginning of a
	 *   retained run is never lost while its own later entries survive.
	 * * **Order.** Oldest group first, by the group's oldest parseable
	 *   timestamp; ties fall back to last activity and then to the key, which
	 *   keeps the result deterministic.
	 * * **Protection.** A group that is still ACTIVE is never evicted, at any
	 *   pressure. "Active" comes from `active_group_keys()`, which reads the
	 *   existing history and continuation stores — never a guess.
	 * * **Legacy entries.** Entries with no run identity cannot be rebuilt into
	 *   a run, so they are evicted individually, oldest first, and only after
	 *   every eligible whole group has gone.
	 * * **Capacity pressure.** If the protected groups alone exceed the bound,
	 *   nothing protected is discarded. The store keeps them and
	 *   `get_retention_state()` reports `pressure => true` with the overage, so
	 *   the admin screen can say the bound was exceeded and why.
	 *
	 * Idempotent: applied to an array that already satisfies the policy it
	 * returns an equal array.
	 *
	 * @param array $log Entries, newest first.
	 * @return array Retained entries, newest first.
	 */
	public static function retain( array $log ) {
		$total = count( $log );

		if ( $total <= self::MAX_ENTRIES ) {
			return $log;
		}

		$protected = self::active_group_keys();
		$groups    = array();

		foreach ( $log as $index => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$key = self::entry_group_key( $entry );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'count'   => 0,
					'oldest'  => PHP_INT_MAX,
					'last'    => 0,
					'oldest_ix' => 0,
					'ix'      => array(),
				);
			}
			++$groups[ $key ]['count'];
			$groups[ $key ]['ix'][] = $index;
			$stamp                  = isset( $entry['time'] ) ? (int) strtotime( (string) $entry['time'] ) : 0;
			if ( $stamp > 0 ) {
				$groups[ $key ]['oldest'] = min( $groups[ $key ]['oldest'], $stamp );
				$groups[ $key ]['last']   = max( $groups[ $key ]['last'], $stamp );
			}
			// The store is newest-first, so a HIGHER index is OLDER. This is the
			// deterministic tie-break when entries share a timestamp (a Run All
			// writes many entries inside the same second): without it, the
			// "oldest group first" order collapses and the newest run could be
			// evicted instead of the oldest.
			$groups[ $key ]['oldest_ix'] = max( (int) $groups[ $key ]['oldest_ix'], (int) $index );
		}

		$evictable = array();
		foreach ( $groups as $key => $info ) {
			if ( self::LEGACY_GROUP === $key || isset( $protected[ $key ] ) ) {
				continue;
			}
			$evictable[ $key ] = $info;
		}

		uksort(
			$evictable,
			static function ( $a, $b ) use ( $evictable ) {
				$left  = $evictable[ $a ];
				$right = $evictable[ $b ];
				if ( $left['oldest'] !== $right['oldest'] ) {
					return $left['oldest'] <=> $right['oldest'];
				}
				if ( $left['last'] !== $right['last'] ) {
					return $left['last'] <=> $right['last'];
				}
				// Equal timestamps: the larger index is the older group.
				if ( $left['oldest_ix'] !== $right['oldest_ix'] ) {
					return $right['oldest_ix'] <=> $left['oldest_ix'];
				}
				return strcmp( (string) $a, (string) $b );
			}
		);

		$dropped   = array();
		$remaining = $total;
		$keys      = array_keys( $evictable );
		$newest    = count( $keys ) - 1;

		foreach ( $keys as $position => $key ) {
			if ( $remaining <= self::MAX_ENTRIES ) {
				break;
			}
			// The newest completed group is never evicted to make room for
			// itself: a single run larger than the bound must remain readable
			// rather than vanish the moment it finishes. Its overage is then
			// reported as pressure instead.
			if ( $position === $newest ) {
				break;
			}
			foreach ( $evictable[ $key ]['ix'] as $index ) {
				$dropped[ $index ] = true;
			}
			$remaining -= (int) $evictable[ $key ]['count'];
		}

		if ( $remaining > self::MAX_ENTRIES && isset( $groups[ self::LEGACY_GROUP ] ) ) {
			$legacy_indexes = $groups[ self::LEGACY_GROUP ]['ix'];
			$over           = $remaining - self::MAX_ENTRIES;
			// The store is newest-first, so the tail of the legacy bucket is its
			// oldest material.
			foreach ( array_slice( $legacy_indexes, max( 0, count( $legacy_indexes ) - $over ) ) as $index ) {
				$dropped[ $index ] = true;
			}
		}

		$retained = array();
		foreach ( $log as $index => $entry ) {
			if ( isset( $dropped[ $index ] ) ) {
				continue;
			}
			$retained[] = $entry;
		}

		return $retained;
	}

	/**
	 * Group keys that must never be evicted, because their run is still live.
	 *
	 * Reads the two existing stores — never a new one:
	 *
	 *   * `Conexao_Import_History` — a group whose parent record is missing or
	 *     not terminal is active;
	 *   * `Conexao_Import_Continuation` — a group with a `scheduled` or
	 *     `running` continuation is active.
	 *
	 * A store that cannot be read protects nothing, which is the pre-Stage-3
	 * behaviour and never "everything is protected".
	 *
	 * @return array<string,bool> Group keys, including the `group:` prefix.
	 */
	public static function active_group_keys() {
		$active = array();

		if ( class_exists( 'Conexao_Import_History' ) ) {
			foreach ( Conexao_Import_History::get_active_group_ids() as $group_id ) {
				$active[ 'group:' . $group_id ] = true;
			}
		}

		if ( class_exists( 'Conexao_Import_Continuation' ) ) {
			foreach ( Conexao_Import_Continuation::get_active_group_ids() as $group_id ) {
				$active[ 'group:' . $group_id ] = true;
			}
		}

		return $active;
	}

	/**
	 * The measurable state of the retention bound.
	 *
	 * Derived from the stored contents on every call: nothing is cached in a
	 * new option, so there is no second store to keep in sync and no state that
	 * can go stale.
	 *
	 * @return array{max_entries:int,total:int,groups:int,active_groups:int,active_entries:int,legacy_entries:int,pressure:bool,over_by:int}
	 */
	public static function get_retention_state() {
		$total  = 0;
		$legacy = 0;
		$groups = array();

		foreach ( self::get_all() as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			++$total;
			$key = self::entry_group_key( $entry );
			if ( self::LEGACY_GROUP === $key ) {
				++$legacy;
				continue;
			}
			$groups[ $key ] = isset( $groups[ $key ] ) ? (int) $groups[ $key ] + 1 : 1;
		}

		$protected      = self::active_group_keys();
		$active_groups  = 0;
		$active_entries = 0;
		foreach ( $groups as $key => $count ) {
			if ( isset( $protected[ $key ] ) ) {
				++$active_groups;
				$active_entries += (int) $count;
			}
		}

		// Pressure means the bound could NOT be met: either the protected groups
		// alone exceed it, or retention had to keep more than the bound (a single
		// run larger than MAX_ENTRIES is retained whole and reported here).
		$over = max(
			0,
			$total - self::MAX_ENTRIES,
			$active_entries - self::MAX_ENTRIES
		);

		return array(
			'max_entries'    => self::MAX_ENTRIES,
			'total'          => $total,
			'groups'         => count( $groups ),
			'active_groups'  => $active_groups,
			'active_entries' => $active_entries,
			'legacy_entries' => $legacy,
			'pressure'       => ( $over > 0 ),
			'over_by'        => $over,
		);
	}

	/**
	 * Get all log entries (newest first).
	 *
	 * @return array
	 */
	public static function get_all() {
		$log = get_option( self::option_key(), array() );
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
	 * Group log entries by the import run that produced them.
	 *
	 * The Import History table shows up to 100 rows and the log store retains
	 * up to 2,000 entries. Calling get_for_run() once per row would rescan the
	 * whole store once per row, so the dashboard builds this index ONCE per
	 * render and reads each row's group out of it.
	 *
	 * Entries with no run ID are deliberately NOT grouped: a run-less entry
	 * cannot be attributed to any historical run, and inventing an association
	 * from the source slug or a loose timestamp match is exactly the mistake
	 * this index exists to prevent.
	 *
	 * Stage 3 (P12): an entry is indexed under its `run_id` AND under the
	 * structured `context.parent_run_group_id` when the two differ. That is what
	 * makes a continuation lifecycle entry reachable from the logical Run All
	 * row even when the entry was written outside a child run (a schedule, a
	 * clear, a re-arm). An entry is never added twice to the same group.
	 *
	 * @param array $entries Log entries, newest first (as stored).
	 * @return array<string, array[]> Run ID => entries, each group newest first.
	 */
	public static function index_entries_by_run( array $entries ) {
		$index = array();

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$run_id = isset( $entry['run_id'] ) ? trim( (string) $entry['run_id'] ) : '';

			$context = isset( $entry['context'] ) && is_array( $entry['context'] ) ? $entry['context'] : array();
			$group   = '';
			foreach ( array( 'parent_run_group_id', 'run_group_id' ) as $key ) {
				if ( isset( $context[ $key ] ) && '' !== trim( (string) $context[ $key ] ) ) {
					$group = trim( (string) $context[ $key ] );
					break;
				}
			}

			if ( '' !== $run_id ) {
				$index[ $run_id ][] = $entry;
			}

			if ( '' !== $group && $group !== $run_id ) {
				$index[ $group ][] = $entry;
			}
		}

		return $index;
	}

	/**
	 * Read one run's entries out of an index built by index_entries_by_run().
	 *
	 * Returns an empty array for an unknown run, which is the honest answer for
	 * a history record whose run was never logged, or whose entries have since
	 * been pruned by retention.
	 *
	 * @param array  $index  Run ID => entries.
	 * @param string $run_id Run ID.
	 * @return array Entries for that run, newest first.
	 */
	public static function entries_for_run_index( array $index, $run_id ) {
		$run_id = trim( (string) $run_id );

		if ( '' === $run_id || ! isset( $index[ $run_id ] ) || ! is_array( $index[ $run_id ] ) ) {
			return array();
		}

		return $index[ $run_id ];
	}

	/**
	 * Get every retained entry belonging to one logical run group.
	 *
	 * A child source run logs with `run_id` = the parent group (see
	 * `run_source()`), so `get_for_run( $group )` already returns the parent's
	 * and every child's entries. This also matches on the structured
	 * `context.parent_run_group_id`, so an entry written by a path that only set
	 * the context is not lost, and it has NO limit: a download must contain the
	 * whole retained run.
	 *
	 * @param string $group_id Parent run-group identifier.
	 * @return array Entries, newest first (as stored).
	 */
	public static function get_for_group( $group_id ) {
		$group   = trim( (string) $group_id );
		$entries = array();

		if ( '' === $group ) {
			return $entries;
		}

		foreach ( self::get_all() as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$run_id  = isset( $entry['run_id'] ) ? trim( (string) $entry['run_id'] ) : '';
			$context = isset( $entry['context'] ) && is_array( $entry['context'] ) ? $entry['context'] : array();
			$ctx_id  = '';
			foreach ( array( 'parent_run_group_id', 'run_group_id' ) as $key ) {
				if ( isset( $context[ $key ] ) && '' !== trim( (string) $context[ $key ] ) ) {
					$ctx_id = trim( (string) $context[ $key ] );
					break;
				}
			}

			if ( $run_id === $group || $ctx_id === $group ) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * Order entries oldest-first, deterministically.
	 *
	 * A downloaded log is read top-down, so it must advance in time. Entries
	 * with an unparseable or missing timestamp keep their stored relative order
	 * at the END of the document rather than being scattered through it.
	 *
	 * @param array $entries Entries, newest first.
	 * @return array Entries, oldest first.
	 */
	public static function chronological( array $entries ) {
		$keyed = array();
		foreach ( array_values( $entries ) as $position => $entry ) {
			$stamp   = ( is_array( $entry ) && isset( $entry['time'] ) ) ? (int) strtotime( (string) $entry['time'] ) : 0;
			$keyed[] = array(
				'stamp'    => $stamp > 0 ? $stamp : PHP_INT_MAX,
				'position' => $position,
				'entry'    => $entry,
			);
		}

		usort(
			$keyed,
			static function ( $a, $b ) {
				if ( $a['stamp'] !== $b['stamp'] ) {
					return $a['stamp'] <=> $b['stamp'];
				}
				return $b['position'] <=> $a['position'];
			}
		);

		return array_map(
			static function ( $row ) {
				return $row['entry'];
			},
			$keyed
		);
	}

	/**
	 * Number of entries a group has, and the bounded preview an admin may see.
	 *
	 * The inline panel is deliberately bounded so one huge run cannot stall the
	 * page, but a bounded panel must never be presented as the whole log. This
	 * returns the numbers the renderer needs to say so.
	 *
	 * @param array $entries       All retained entries for the group, newest first.
	 * @param int   $preview_limit Maximum entries to return.
	 * @return array{shown:int,total:int,truncated:bool,preview:array}
	 */
	public static function preview( array $entries, $preview_limit = 50 ) {
		$limit = max( 1, (int) $preview_limit );
		$total = count( $entries );

		return array(
			'shown'     => min( $limit, $total ),
			'total'     => $total,
			'truncated' => $total > $limit,
			'preview'   => array_slice( $entries, 0, $limit ),
		);
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
		update_option( self::option_key(), array(), false );
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
			'url'              => 'URL',
			'attempt'          => 'Attempt',
			'post_id'          => 'Post ID',
			'source_url'       => 'Source URL',
			'duration'         => 'Duration',
			'content_type'     => 'Content-Type',
			'body_preview'     => 'Body',
			'retry_after'      => 'Retry-After',
			'rate_limit_hits'  => 'RateLimitHits',
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
	 * Build a plain-text .log document for a single logical import run.
	 *
	 * Stage 3 (P13): the download is the FULL retained logical run — parent and
	 * every child source run — in chronological order, with a manifest header
	 * that names the parent group, the child runs and sources present, the
	 * retained entry count and any retention bound in effect. Nothing is capped
	 * at the inline preview size.
	 *
	 * @param string $run_id Parent run-group identifier (or a legacy run ID).
	 * @return string
	 */
	public static function export_run( $run_id ) {
		$run_id  = trim( (string) $run_id );
		$entries = self::get_for_group( $run_id );

		$lines   = array();
		$lines[] = '# Event Importer Log — logical import run ' . $run_id;
		$lines[] = '# Generated: ' . current_time( 'mysql' );
		$lines[] = '# Ordering: chronological (oldest first)';
		$lines[] = '# Retained entries: ' . count( $entries );

		if ( empty( $entries ) ) {
			$lines[] = '';
			$lines[] = '(No retained log entries for this run. They may never have been written, or they may have been evicted by retention.)';
			return implode( "\n", $lines ) . "\n";
		}

		// Manifest: the child runs and sources actually present in the export.
		$sources   = array();
		$child_ids = array();
		$legacy    = 0;
		foreach ( $entries as $entry ) {
			$source = isset( $entry['source'] ) ? (string) $entry['source'] : '';
			if ( '' !== $source ) {
				$sources[ $source ] = true;
			}
			$context = isset( $entry['context'] ) && is_array( $entry['context'] ) ? $entry['context'] : array();
			if ( ! empty( $context['source_run_id'] ) ) {
				$child_ids[ (string) $context['source_run_id'] ] = true;
			}
			if ( '' === trim( (string) ( isset( $entry['run_id'] ) ? $entry['run_id'] : '' ) ) ) {
				++$legacy;
			}
		}

		$lines[] = '# Sources present: ' . ( empty( $sources ) ? '(none)' : implode( ', ', array_keys( $sources ) ) );
		$lines[] = '# Child source runs present: ' . ( empty( $child_ids ) ? '(none recorded)' : implode( ', ', array_keys( $child_ids ) ) );
		if ( $legacy > 0 ) {
			$lines[] = '# Legacy entries with no run correlation: ' . $legacy;
		}
		$state = self::get_retention_state();
		$lines[] = '# Log retention bound: ' . (int) $state['max_entries'] . ' entries; currently ' . (int) $state['total'];
		if ( ! empty( $state['pressure'] ) ) {
			$lines[] = '# WARNING: the active-run retention bound is exceeded by ' . (int) $state['over_by'] . ' entries; nothing from an active run was discarded.';
		}
		$lines[] = '';

		$lines[] = rtrim( self::format_entries( self::chronological( $entries ) ), "\n" );

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Purge log entries older than a given age limit, regardless of cap.
	 *
	 * ## Status after Stage 3 (P13): retained, still NOT wired to production
	 *
	 * This method had no caller before Stage 3 and still has none. It is kept
	 * because it is the documented age policy, and auditing it was required:
	 * the naive form could delete the beginning of a run that is still
	 * executing. It is now group-safe — an entry belonging to an ACTIVE group is
	 * never removed, whatever its age — so if a future stage does wire an age
	 * prune it cannot split a live run.
	 *
	 * The enforced bound is `retain()`, applied on every write, because a
	 * count-based bound is what actually stops the store growing; age alone
	 * cannot. Wiring an age prune as well would be a second, unsynchronised
	 * eviction policy, so it is deliberately left uncalled and documented here
	 * rather than activated without authorisation.
	 *
	 * @return int Number of entries removed.
	 */
	public static function prune_older_than_90_days() {
		// Flush buffered entries first so retention also applies to them.
		self::flush();

		$log      = self::get_all();
		$cutoff   = time() - 90 * DAY_IN_SECONDS;
		$protected = self::active_group_keys();
		$filtered = array();
		$removed  = 0;

		foreach ( $log as $entry ) {
			$time = isset( $entry['time'] ) ? strtotime( (string) $entry['time'] ) : 0;
			if ( $time > 0 && $time < $cutoff ) {
				$key = is_array( $entry ) ? self::entry_group_key( $entry ) : self::LEGACY_GROUP;
				if ( ! isset( $protected[ $key ] ) ) {
					++$removed;
					continue;
				}
			}
			$filtered[] = $entry;
		}

		if ( $removed > 0 ) {
			update_option( self::option_key(), $filtered, false );
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
