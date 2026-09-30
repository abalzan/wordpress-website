<?php
/**
 * Site-wide, atomic, fail-closed translation run lock (Stage 0 blocker B3,
 * Stage 2 controls C1-C6).
 *
 * ## Why this class exists
 *
 * `conexao-translation-automation` is a permanent, always-loaded production
 * component. Before any apply can ever be reachable, two runs must be
 * incapable of overlapping. The lock is SITE-WIDE, not per-stage: two
 * different stages translating simultaneously would race on the same PT
 * records, the same EN slugs and the same Polylang relationships, so a
 * per-stage lock would not actually serialise anything.
 *
 * The lock is a boundary concern (who may run, and when), so it lives in the
 * automation plugin. It is emphatically NOT a second translation engine and it
 * never touches content: it writes exactly one row in `wp_options` and deletes
 * it again.
 *
 * ## Why `add_option()` was REJECTED as the primitive
 *
 * WordPress's `add_option()` is not an atomic "create if absent" for two
 * separate reasons, both observed in `wp-includes/option.php`:
 *
 *   1. **Check-then-insert is a TOCTOU race.** `add_option()` calls
 *      `get_option()` first and returns `false` when the option exists. Two
 *      concurrent callers can both observe "absent" and both proceed.
 *   2. **The INSERT is `ON DUPLICATE KEY UPDATE`.** The loser's INSERT
 *      therefore *succeeds* and OVERWRITES the winner's value, and the loser
 *      gets a truthy result back. A lock built on this does not merely fail to
 *      lock; it silently destroys the lock another run is holding.
 *
 * Using it would make "acquisition fails closed" unprovable, so it is not used
 * anywhere in this file.
 *
 * ## Why `wp_cache_add()` was REJECTED as the primitive
 *
 * `wp_cache_add()` is only atomic across processes when a PERSISTENT object
 * cache is installed, and it is not durable. With the default non-persistent
 * cache the key vanishes when the request ends, so the "lock" would evaporate
 * while the run was still executing. This repository does not assume persistent
 * object-cache semantics without evidence, so the lock must not depend on it.
 *
 * ## The primitive actually used
 *
 * A plain conditional `INSERT` through `$wpdb`, relying on the UNIQUE key on
 * `wp_options.option_name` (`UNIQUE KEY option_name`) that every WordPress and
 * WordPress.com install carries. The database, not PHP, decides the winner:
 *
 *   - insert succeeds -> this run created the lock and owns it;
 *   - duplicate key   -> the INSERT fails atomically, the run is LOCKED.
 *
 * There is no read-then-write window, so two simultaneous acquirers cannot both
 * win and neither can clobber the other. This is compatible with WordPress.com
 * (InnoDB, no SSH, no WP-CLI, no filesystem) because it needs nothing beyond
 * the options table WordPress already requires.
 *
 * Every mutation is also a COMPARE-AND-SWAP on the exact stored bytes, so a
 * late or racing operation can never act on a row it did not create:
 *
 *   - reclaim : `UPDATE ... WHERE option_name = ? AND option_value = <exact old>`
 *   - release : `DELETE ... WHERE option_name = ? AND option_value = <exact own>`
 *
 * ## The lock contract
 *
 * | Concern | Rule |
 * |---|---|
 * | Ownership | Every acquisition carries a unique `run_id`, propagated into the audit record. |
 * | Record shape | `{ v, run_id, acquired_at, ttl, stage, environment }`. Strictly validated on read. |
 * | Stale policy | Reclaimable ONLY when the record parses, declares a supported version and a positive TTL, and `now - acquired_at > ttl`. Bounded and explicit. |
 * | Malformed state | NEVER reclaimed, never overwritten, never deleted. Fails closed so a human can inspect it. |
 * | Release | Only the owning run. A value-scoped `DELETE` makes this enforced by the database. |
 * | Held lock | Any second run gets `locked` and performs no mutation. Nothing is queued. |
 * | Audit | Acquisition, refusal, reclaim and release all return a structured, secret-free event. |
 *
 * The clock is injectable (`now`) purely so the stale-boundary tests are
 * deterministic instead of sleeping. Production callers never pass it.
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

/**
 * Site-wide translation run lock.
 */
final class Conexao_Translation_Automation_Lock {
	/**
		 * Option name. One site-wide lock, deliberately NOT suffixed per stage.
		 *
		 * @var string
		 */
	const OPTION = 'conexao_translation_automation_lock';

	/**
	 * Record schema version. A record carrying any other version is treated as
	 * malformed rather than guessed at.
	 *
	 * @var int
	 */
	const SCHEMA_VERSION = 1;

	/**
	 * Default time-to-live in seconds.
	 *
	 * Bounded on purpose: an unbounded or absent TTL could never be reclaimed,
	 * and a very short one would reclaim a lock out from under a legitimately
	 * slow run. A dry run over the full EN manifest is the longest legitimate
	 * hold, so 15 minutes leaves headroom without being open-ended.
	 *
	 * @var int
	 */
	const DEFAULT_TTL = 900;

	/**
	 * Upper bound accepted on a stored TTL. A record claiming a longer life is
	 * treated as malformed: only this plugin writes the TTL, so a larger value
	 * means this plugin did not write the record.
	 *
	 * @var int
	 */
	const MAX_TTL = 86400;

	/**
	 * Outcome codes for acquisition. Every acquisition returns exactly one.
	 *
	 * @var string
	 */
	const ACQUIRED    = 'acquired';
	const LOCKED      = 'locked';
	const RECLAIMED   = 'reclaimed_stale';
	const MALFORMED   = 'malformed';
	const UNAVAILABLE = 'unavailable';

	/**
	 * Outcome codes for release.
	 *
	 * @var string
	 */
	const RELEASED            = 'released';
	const NOT_OWNER           = 'not_owner';
	const NOT_HELD            = 'not_held';
	const UNAVAILABLE_RELEASE = 'unavailable';

	/**
	 * Try to become the single holder of the site-wide lock.
	 *
	 * Never throws and never partially succeeds: every path returns a result
	 * array whose `outcome` is one of the ACQUIRED/LOCKED/RECLAIMED/MALFORMED/
	 * UNAVAILABLE constants. `held` is the ONLY value that means "you may
	 * proceed"; anything else must stop the run before the engine is touched.
	 *
	 * @param array $args {
	 *     Unique run identifier (required), the stage and environment being
	 *     attempted (recorded for audit), an optional lock lifetime in seconds
	 *     (defaults to self::DEFAULT_TTL), and an optional clock override that
	 *     only the deterministic stale-boundary tests pass.
	 *
	 *     @type string $run_id      Unique run identifier. Required.
	 *     @type string $stage       Stage being attempted, recorded for audit.
	 *     @type string $environment Environment label, recorded for audit.
	 *     @type int    $ttl         Lock lifetime in seconds.
	 *     @type int    $now         Clock override for deterministic tests.
	 * }
	 * @return array {
	 *     @type bool   $held    True only when this run owns the lock.
	 *     @type string $outcome Outcome code.
	 *     @type string $reason  Human-readable, secret-free explanation.
	 *     @type array  $record  The lock record as stored (empty when unheld).
	 *     @type array  $event   Structured audit event.
	 * }
	 */
	public static function acquire( array $args ): array {
		$run_id = isset( $args['run_id'] ) ? sanitize_key( (string) $args['run_id'] ) : '';
		$ttl    = isset( $args['ttl'] ) ? (int) $args['ttl'] : self::DEFAULT_TTL;
		$now    = isset( $args['now'] ) ? (int) $args['now'] : time();

		if ( '' === $run_id ) {
			return self::refusal( self::UNAVAILABLE, 'no run id was supplied' );
		}

		if ( $ttl <= 0 || $ttl > self::MAX_TTL ) {
			return self::refusal( self::UNAVAILABLE, 'refusing an out-of-range lock ttl' );
		}

		$record = array(
			'v'           => self::SCHEMA_VERSION,
			'run_id'      => $run_id,
			'acquired_at' => $now,
			'ttl'         => $ttl,
			'stage'       => isset( $args['stage'] ) ? sanitize_key( (string) $args['stage'] ) : '',
			'environment' => isset( $args['environment'] ) ? sanitize_key( (string) $args['environment'] ) : '',
		);

		$encoded = (string) wp_json_encode( $record );

		// --- 1. The atomic create. The UNIQUE key decides the winner. ------
		$inserted = self::insert_lock( $encoded );

		if ( true === $inserted ) {
			return self::success( self::ACQUIRED, 'site-wide lock acquired', $record );
		}

		if ( null === $inserted ) {
			// The insert did not fail for the duplicate-key reason, so the
			// storage layer itself is unavailable. Fail closed: an unknown
			// storage state must never be read as "no lock held".
			return self::refusal( self::UNAVAILABLE, 'lock storage is unavailable; refusing to run unlocked' );
		}

		// --- 2. The lock exists. Read it and decide, never assume. ----------
		$raw = self::read_lock();

		if ( null === $raw ) {
			return self::refusal( self::UNAVAILABLE, 'lock state could not be read; refusing to run unlocked' );
		}

		$existing = self::decode( $raw );

		if ( null === $existing ) {
			// Malformed state is NEVER overwritten. A human must look at it.
			return self::refusal(
				self::MALFORMED,
				'the existing lock state is malformed; refusing to overwrite it'
			);
		}

		// --- 3. A live lock held by another run is respected, always. ------
		if ( $existing['run_id'] === $run_id ) {
			// Same run re-entering: it already owns the lock. Reported as held
			// without rewriting the record, so ownership stays unambiguous.
			return self::success( self::ACQUIRED, 'this run already holds the site-wide lock', $existing );
		}

		$age = $now - (int) $existing['acquired_at'];

		if ( $age <= (int) $existing['ttl'] ) {
			return self::failure(
				self::LOCKED,
				sprintf(
					'another run holds the site-wide lock (age %ds of %ds)',
					max( 0, $age ),
					(int) $existing['ttl']
				),
				$existing
			);
		}

		// --- 4. Stale, and only stale: reclaim by compare-and-swap. --------
		//
		// The UPDATE only matches the exact bytes we read. If another process
		// released or rewrote the lock in the meantime, zero rows change and
		// this run is LOCKED rather than stealing a live lock.
		if ( true !== self::swap_lock( $raw, $encoded ) ) {
			return self::failure(
				self::LOCKED,
				'lost the stale-lock reclaim race; another run won it',
				$existing
			);
		}

		// The reclaim event names BOTH runs, so a stale reclamation is fully
		// auditable: who lost the lock, who took it, and after how long.
		return array(
			'held'    => true,
			'outcome' => self::RECLAIMED,
			'reason'  => sprintf(
				'reclaimed a stale lock from run %s (age %ds exceeded ttl %ds)',
				$existing['run_id'],
				$age,
				(int) $existing['ttl']
			),
			'record'  => $record,
			'event'   => array(
				'outcome'         => self::RECLAIMED,
				'reason'          => sprintf(
					'reclaimed stale lock from run %s after %ds',
					$existing['run_id'],
					$age
				),
				'run_id'          => $run_id,
				'stage'           => $record['stage'],
				'acquired_at'     => $now,
				'ttl'             => $ttl,
				'previous_run_id' => $existing['run_id'],
			),
		);
	}

	/**
	 * Release the lock, but only if this run is the owner.
	 *
	 * The DELETE is scoped to the exact stored bytes, so ownership is enforced
	 * by the database rather than by a read that could go stale. A run can
	 * never release another run's lock.
	 *
	 * @param string $run_id Run identifier attempting the release.
	 * @return array {
	 *     @type bool   $released True only when this run's lock was removed.
	 *     @type string $outcome  Outcome code.
	 *     @type string $reason   Human-readable explanation.
	 *     @type array  $event    Structured audit event.
	 * }
	 */
	public static function release( string $run_id ): array {
		$run_id = sanitize_key( $run_id );

		if ( '' === $run_id ) {
			return self::release_refusal( self::UNAVAILABLE_RELEASE, 'no run id was supplied' );
		}

		$raw = self::read_lock();

		if ( null === $raw ) {
			return self::release_refusal( self::NOT_HELD, 'no lock is held, or its state could not be read' );
		}

		$existing = self::decode( $raw );

		if ( null === $existing ) {
			// Fail closed and, crucially, do NOT delete a record this class
			// cannot understand. Deleting it would be exactly the silent
			// unlock the malformed rule exists to prevent.
			return self::release_refusal( self::UNAVAILABLE_RELEASE, 'lock state is malformed; refusing to delete it' );
		}

		if ( $existing['run_id'] !== $run_id ) {
			return array(
				'released' => false,
				'outcome'  => self::NOT_OWNER,
				'reason'   => sprintf(
					'run %s does not own the lock held by run %s',
					$run_id,
					$existing['run_id']
				),
				'event'    => self::event( $existing, self::NOT_OWNER, 'release refused: not the lock owner' ),
			);
		}

		if ( true !== self::delete_lock( $raw ) ) {
			return self::release_refusal( self::UNAVAILABLE_RELEASE, 'the lock could not be released; refusing to report success' );
		}

		return array(
			'released' => true,
			'outcome'  => self::RELEASED,
			'reason'   => 'lock released by its owner',
			'event'    => self::event( $existing, self::RELEASED, 'lock released by its owner' ),
		);
	}

	/**
	 * Read the current lock record without acquiring or releasing anything.
	 *
	 * Read-only by construction: used by verification tooling and tests.
	 *
	 * @return array|null Decoded record, or null when unheld/malformed/unreadable.
	 */
	public static function inspect() {
		$raw = self::read_lock();

		if ( null === $raw ) {
			return null;
		}

		return self::decode( $raw );
	}

	/**
	 * Is a lock currently held by anyone?
	 *
	 * @return bool
	 */
	public static function is_held(): bool {
		return null !== self::read_lock();
	}

	/**
	 * Test and teardown support: remove the lock row regardless of ownership.
	 *
	 * NOT part of the runtime contract and never called by the orchestrator.
	 * It exists so a test can guarantee a clean slate and so a malformed record
	 * can be cleared deliberately by an operator.
	 *
	 * @return void
	 */
	public static function force_clear(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test/teardown support; bypasses the object cache deliberately.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) );

		self::flush_option_cache();
	}

	// -----------------------------------------------------------------------
	// Storage primitives
	// -----------------------------------------------------------------------

	/**
	 * Atomically create the lock row.
	 *
	 * @param string $encoded JSON-encoded lock record.
	 * @return bool|null True on success, false on duplicate key, null on any other failure.
	 */
	private static function insert_lock( string $encoded ) {
		global $wpdb;

		// `autoload = no`: the lock is read on every translation run, never on
		// every page load, so it must not bloat the alloptions cache.
		//
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->options is a core table name; every value is prepared.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )",
				self::OPTION,
				$encoded
			)
		);

		if ( false === $affected ) {
			// A hard failure (table missing, connection lost). Distinct from a
			// duplicate key, which INSERT IGNORE reports as 0 affected rows.
			return null;
		}

		if ( 1 !== (int) $affected ) {
			// 0 rows affected == the UNIQUE key rejected us: someone holds it.
			return false;
		}

		self::flush_option_cache();

		return true;
	}

	/**
	 * Read the raw stored lock value.
	 *
	 * Reads the table directly rather than through `get_option()` so the value
	 * observed is the committed row, not a possibly-stale cache entry. A lock
	 * decision must never be made from a cached value another process has
	 * already replaced.
	 *
	 * @return string|null Raw value, or null when unheld or unreadable.
	 */
	private static function read_lock() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- authoritative read; must bypass the object cache.
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				self::OPTION
			)
		);

		if ( null === $value || '' === $value ) {
			return null;
		}

		return (string) $value;
	}
	/**
	 * Compare-and-swap the lock row.
	 *
	 * @param string $expected    Exact current stored value.
	 * @param string $replacement New value.
	 * @return bool True when this call performed the swap.
	 */
	private static function swap_lock( string $expected, string $replacement ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-swap must bypass the object cache.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$replacement,
				self::OPTION,
				$expected
			)
		);

		if ( false === $affected ) {
			return false;
		}

		self::flush_option_cache();

		return 1 === (int) $affected;
	}

	/**
	 * Compare-and-delete the lock row.
	 *
	 * @param string $expected Exact current stored value.
	 * @return bool True when this call removed the row.
	 */
	private static function delete_lock( string $expected ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-delete must bypass the object cache.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::OPTION,
				$expected
			)
		);

		if ( false === $affected ) {
			return false;
		}

		self::flush_option_cache();

		return 1 === (int) $affected;
	}

	/**
	 * Invalidate the option caches after a direct-table mutation.
	 *
	 * `add_option()`/`update_option()` normally do this. This class writes
	 * through `$wpdb` precisely because `add_option()` is not atomic, so it
	 * inherits that responsibility — otherwise every later `get_option()` in
	 * the same request would return a value that no longer exists.
	 *
	 * @return void
	 */
	private static function flush_option_cache(): void {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	// -----------------------------------------------------------------------
	// Validation and audit
	// -----------------------------------------------------------------------

	/**
	 * Strictly decode and validate a stored lock value.
	 *
	 * Strictness is the whole point: anything unexpected yields null, which the
	 * callers translate into "fail closed" rather than into a guess.
	 *
	 * @param string $raw Raw stored value.
	 * @return array|null Normalised record, or null when malformed.
	 */
	private static function decode( string $raw ) {
		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return null;
		}

		foreach ( array( 'v', 'run_id', 'acquired_at', 'ttl' ) as $key ) {
			if ( ! array_key_exists( $key, $decoded ) ) {
				return null;
			}
		}

		if ( self::SCHEMA_VERSION !== (int) $decoded['v'] ) {
			return null;
		}

		$run_id = (string) $decoded['run_id'];

		if ( '' === $run_id || ! preg_match( '/^[a-z0-9_-]+$/', $run_id ) ) {
			return null;
		}

		$acquired = (int) $decoded['acquired_at'];
		$ttl      = (int) $decoded['ttl'];

		// A zero/absent timestamp would make every record look infinitely old
		// and therefore silently reclaimable, so it is malformed.
		if ( $acquired <= 0 ) {
			return null;
		}

		if ( $ttl <= 0 || $ttl > self::MAX_TTL ) {
			return null;
		}

		return array(
			'v'           => self::SCHEMA_VERSION,
			'run_id'      => $run_id,
			'acquired_at' => $acquired,
			'ttl'         => $ttl,
			'stage'       => isset( $decoded['stage'] ) ? (string) $decoded['stage'] : '',
			'environment' => isset( $decoded['environment'] ) ? (string) $decoded['environment'] : '',
		);
	}
	/**
	 * A successful acquisition: this run owns the lock.
	 *
	 * @param string $outcome Outcome code.
	 * @param string $reason  Explanation.
	 * @param array  $record  The lock record now owned by this run.
	 * @return array
	 */
	private static function success( string $outcome, string $reason, array $record ): array {
		return array(
			'held'    => true,
			'outcome' => $outcome,
			'reason'  => $reason,
			'record'  => $record,
			'event'   => self::event( $record, $outcome, $reason ),
		);
	}

	/**
	 * A failed acquisition that holds no lock, but where a readable record
	 * explains who does hold it.
	 *
	 * @param string $outcome Outcome code.
	 * @param string $reason  Explanation.
	 * @param array  $record  The blocking record.
	 * @return array
	 */
	private static function failure( string $outcome, string $reason, array $record ): array {
		return array(
			'held'    => false,
			'outcome' => $outcome,
			'reason'  => $reason,
			'record'  => $record,
			'event'   => self::event( $record, $outcome, $reason ),
		);
	}

	/**
	 * A failed acquisition with no readable blocking record.
	 *
	 * @param string $outcome Outcome code.
	 * @param string $reason  Explanation.
	 * @return array
	 */
	private static function refusal( string $outcome, string $reason ): array {
		return array(
			'held'    => false,
			'outcome' => $outcome,
			'reason'  => $reason,
			'record'  => array(),
			'event'   => self::event( array(), $outcome, $reason ),
		);
	}

	/**
	 * A failed release.
	 *
	 * @param string $outcome Outcome code.
	 * @param string $reason  Explanation.
	 * @return array
	 */
	private static function release_refusal( string $outcome, string $reason ): array {
		return array(
			'released' => false,
			'outcome'  => $outcome,
			'reason'   => $reason,
			'event'    => self::event( array(), $outcome, $reason ),
		);
	}

	/**
	 * Build a structured, secret-free audit event.
	 *
	 * Carries no credential, no cookie, no authorisation header and no request
	 * body. It records WHO (a run id), WHAT (an outcome) and WHY (a reason),
	 * which is what the audit boundary requires.
	 *
	 * @param array  $record  Lock record involved (may be empty).
	 * @param string $outcome Outcome code.
	 * @param string $reason  Explanation.
	 * @return array
	 */
	private static function event( array $record, string $outcome, string $reason ): array {
		return array(
			'outcome'     => $outcome,
			'reason'      => $reason,
			'run_id'      => isset( $record['run_id'] ) ? (string) $record['run_id'] : '',
			'stage'       => isset( $record['stage'] ) ? (string) $record['stage'] : '',
			'acquired_at' => isset( $record['acquired_at'] ) ? (int) $record['acquired_at'] : 0,
			'ttl'         => isset( $record['ttl'] ) ? (int) $record['ttl'] : 0,
		);
	}
}
