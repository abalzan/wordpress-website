<?php
/**
 * The audit store: Stage 2's structured result, now persisted (Stage 3).
 *
 * ## Why `wp_options` and not a logging plugin
 *
 * Production is WordPress.com with no SSH, no WP-CLI and no filesystem, so an
 * external log file is impossible and an external service is both a new
 * dependency and a place secrets could leak. A third-party logging plugin
 * would add a vendor, a schema and a second retention policy to a program whose
 * whole point is that it has ONE of each. The options table is already proven
 * by Stage 2's lock, needs no schema migration, and keeps the audit trail inside
 * the same backup and access-control boundary as the content it describes.
 *
 * ## What is recorded
 *
 * Exactly Stage 2's `Conexao_Translation_Automation_Result::to_array()` — the
 * contract already defined and already tested — plus the Stage 3 fields
 * (trigger, inventory digest, change-set digest, provider status). No parallel
 * log format is invented.
 *
 * | Recorded | Why |
 * |---|---|
 * | `run_id` | correlates every row of one run |
 * | `trigger` | manual / hook / scheduled, so an operator can tell who asked |
 * | `stage` | which stage's records were in scope |
 * | `environment` | a staging approval is not a production approval |
 * | `start_state` / `end_state` / `status` | how far the run actually got |
 * | `source_inventory_digest` | the PT state this run compared against |
 * | `change_set_digest` | the exact change set that was derived |
 * | `manifest/plan/snapshot digests`, `gate`, `dry_run_status` | the engine's own verdict, verbatim |
 * | `lock_status` | whether the run held the site-wide lock |
 * | `provider_status` | `not_implemented` until a provider exists |
 * | `mutation_permitted` / `mutation_occurred` | the two flags that matter most |
 * | `failure` | the fail-closed category |
 *
 * ## Retention (explicit and bounded)
 *
 * | Rule | Value | Reasoning |
 * |---|---|---|
 * | records kept | **20** (`AUDIT_RETENTION`) | enough to cover a review cycle without unbounded growth in a single option row |
 * | pruning order | oldest first | chronological review is what an operator does |
 * | successful vs failed | **treated identically** | a failure record is at least as useful as a success record; pruning failures to make room would hide the interesting case |
 * | compaction | none | records are already small and fixed-shape; a compaction step would add a way to lose information |
 * | what is never retained | the engine's full report body, provider payloads, and any credential | `assert_no_secrets()` runs on the write path, and only digests are persisted |
 *
 * ## No secrets, enforced not documented
 *
 * `Conexao_Translation_Automation_Result::assert_no_secrets()` is called on the
 * record BEFORE every write. A record carrying a credential-shaped key or value
 * is refused, and the refusal is the run's failure category — so a run that
 * tried to record a secret FAILS rather than leaking.
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The persistent, bounded audit trail.
 */
final class Conexao_Translation_Automation_Audit {

	/**
	 * The options row holding the audit trail.
	 *
	 * @var string
	 */
	const OPTION = 'conexao_translation_automation_audit';

	/**
	 * The maximum number of retained records.
	 *
	 * @var int
	 */
	const RETENTION = 20;

	/**
	 * Trigger kinds.
	 *
	 * Every kind here is produced by a HUMAN-REQUESTED run. Stage 17 removed
	 * `hook` and `scheduled` along with the wake-up hook that produced them:
	 * there is no longer any code path that can record a run nobody asked
	 * for, so the vocabulary itself no longer admits one.
	 *
	 * @var string
	 */
	const TRIGGER_MANUAL    = 'manual';
	const TRIGGER_BOOTSTRAP = 'bootstrap';

	/**
	 * The Stage 6 protected production proof trigger.
	 *
	 * Recorded so an operator reading the trail can tell an interactive
	 * admin-screen invocation from any other kind, without the trail having
	 * to retain who pressed the button.
	 *
	 * @var string
	 */
	const TRIGGER_ADMIN_PROOF = 'admin_proof';

	/**
	 * Write the record. Replaced by tests to inject a storage failure.
	 *
	 * @var callable|null
	 */
	private static $writer = null;

	/**
	 * Replace the writer. Test support; null restores the real one.
	 *
	 * @param callable|null $writer Callable receiving the pruned record list.
	 * @return void
	 */
	public static function set_writer( $writer ): void {
		self::$writer = $writer;
	}

	/**
	 * Build the audit record for one run.
	 *
	 * Pure. Takes Stage 2's result plus the Stage 3 facts and returns the
	 * record; `record()` is what persists it. Splitting them keeps the record
	 * shape testable without touching storage.
	 *
	 * @param array $args {
	 *     Run facts. Every key is optional; absent keys become empty.
	 *
	 *     @type string $run_id      Run identifier.
	 *     @type string $trigger     One of the TRIGGER_* constants.
	 *     @type string $stage       Stage in scope.
	 *     @type string $environment Environment label.
	 *     @type string $result      `Conexao_Translation_Automation_Result::to_array()`.
	 *     @type string $inventory_digest Current PT source inventory digest.
	 *     @type string $change_digest    Digest of the derived change set.
	 *     @type string $provider_status  Provider status, or 'not_implemented'.
	 *     @type int    $manual_rows      Rows needing human intervention.
	 *     @type string $started_at       ISO start timestamp.
	 * }
	 * @return array
	 */
	public static function build_record( array $args ): array {
		$result  = isset( $args['result'] ) && is_array( $args['result'] ) ? $args['result'] : array();
		$digests = isset( $result['digests'] ) && is_array( $result['digests'] ) ? $result['digests'] : array();

		return array(
			'v'                        => 1,
			'run_id'                   => (string) ( $args['run_id'] ?? ( $result['run_id'] ?? '' ) ),
			'plugin'                   => 'conexao-translation-automation',
			'plugin_version'           => defined( 'CONEXAO_TRANSLATION_AUTOMATION_VERSION' )
				? CONEXAO_TRANSLATION_AUTOMATION_VERSION
				: '0.0.0',
			'trigger'                  => (string) ( $args['trigger'] ?? '' ),
			'stage'                    => (string) ( $args['stage'] ?? ( $result['stage'] ?? '' ) ),
			'environment'              => (string) ( $args['environment'] ?? ( $result['environment'] ?? '' ) ),
			'status'                   => (string) ( $result['status'] ?? 'failed' ),
			'failure'                  => (string) ( $result['failure'] ?? '' ),
			'start_state'              => (string) ( $result['start_state'] ?? '' ),
			'end_state'                => (string) ( $result['end_state'] ?? '' ),

			// The Stage 3 identities.
			'source_inventory_digest'  => (string) ( $args['inventory_digest'] ?? '' ),
			'change_set_digest'        => (string) ( $args['change_digest'] ?? '' ),
			'manual_intervention_rows' => (int) ( $args['manual_rows'] ?? 0 ),

			// Stage 2's engine identities, verbatim and never re-interpreted.
			'manifest_digest'          => (string) ( $digests['manifest_digest'] ?? '' ),
			'plan_digest'              => (string) ( $digests['plan_digest'] ?? '' ),
			'snapshot_digest'          => (string) ( $digests['snapshot_digest'] ?? '' ),
			'config_identity'          => (string) ( $digests['config_identity'] ?? '' ),
			'approval'                 => (string) ( $result['approval'] ?? '' ),

			// The gate and the locks.
			'lock_status'              => (string) ( $result['lock_status'] ?? '' ),
			'dry_run_status'           => (string) ( $result['dry_run_status'] ?? 'not_run' ),
			'gate'                     => isset( $result['gate'] ) && is_array( $result['gate'] ) ? $result['gate'] : array(),
			'apply_permitted'          => ! empty( $result['apply_permitted'] ),

			// The two flags that decide whether anything changed.
			'mutation_permitted'       => ! empty( $result['mutation_permitted'] ),
			'mutation_occurred'        => ! empty( $result['mutation_occurred'] ),

			'provider_status'          => (string) ( $args['provider_status'] ?? 'not_implemented' ),

			'started_at'               => (string) ( $args['started_at'] ?? '' ),
			'recorded_at'              => gmdate( 'c' ),
		);
	}

	/**
	 * Persist one audit record, enforcing retention and the secret rule.
	 *
	 * @param array $record Record from `build_record()`.
	 * @return array|WP_Error The pruned trail, or a reason the record was refused.
	 */
	public static function record( array $record ) {
		// The secret rule is enforced on the WRITE PATH, not merely documented:
		// a record that would leak a credential is refused, and the refusal is
		// visible to the caller instead of being silently dropped.
		$clean = Conexao_Translation_Automation_Result::assert_no_secrets( $record );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$trail   = self::read();
		$trail[] = $clean;
		$trail   = self::prune( $trail );

		if ( is_callable( self::$writer ) ) {
			if ( true !== call_user_func( self::$writer, $trail ) ) {
				return new WP_Error(
					'conexao_automation_audit_write_failed',
					'the audit writer refused the trail.'
				);
			}

			return $trail;
		}

		// autoload = false: an audit trail is read by an operator screen, never
		// by the front end of every request.
		$ok = update_option( self::OPTION, $trail, false );

		if ( false === $ok && self::OPTION !== (string) get_option( self::OPTION, '' ) ) {
			return new WP_Error(
				'conexao_automation_audit_write_failed',
				'the audit trail could not be persisted.'
			);
		}

		return $trail;
	}

	/**
	 * Read the audit trail, tolerating corruption.
	 *
	 * A corrupt trail reads as EMPTY rather than as an error, because an
	 * unreadable log must not be able to stop a run. It is still surfaced:
	 * `read_state()` reports it so an operator can see the trail needs
	 * attention, and the run that discovers it records that fact.
	 *
	 * @return array<int,array>
	 */
	public static function read(): array {
		$raw = get_option( self::OPTION, array() );

		if ( is_array( $raw ) ) {
			return array_values(
				array_filter(
					$raw,
					static function ( $entry ): bool {
						return is_array( $entry ) && isset( $entry['run_id'] );
					}
				)
			);
		}

		$unserialised = maybe_unserialize( $raw );

		return is_array( $unserialised ) ? array_values( $unserialised ) : array();
	}

	/**
	 * The health of the stored trail.
	 *
	 * @return array{ok:bool,reason:string,count:int}
	 */
	public static function read_state(): array {
		$raw = get_option( self::OPTION, null );

		if ( null === $raw ) {
			return array(
				'ok'     => true,
				'reason' => 'no audit trail has been written yet',
				'count'  => 0,
			);
		}

		if ( ! is_array( $raw ) && ! is_array( maybe_unserialize( $raw ) ) ) {
			return array(
				'ok'     => false,
				'reason' => 'the stored audit trail is unreadable and was ignored',
				'count'  => 0,
			);
		}

		return array(
			'ok'     => true,
			'reason' => '',
			'count'  => count( self::read() ),
		);
	}

	/**
	 * Bound the trail.
	 *
	 * Oldest first, successful and failed runs treated identically. The bound is
	 * what stops a long-lived production site accumulating an unbounded option
	 * row.
	 *
	 * @param array $trail Records in chronological order.
	 * @return array
	 */
	public static function prune( array $trail ): array {
		$limit = self::RETENTION;

		if ( count( $trail ) <= $limit ) {
			return $trail;
		}

		return array_slice( $trail, -$limit );
	}

	/**
	 * The most recent record, or an empty array.
	 *
	 * @return array
	 */
	public static function latest(): array {
		$trail = self::read();

		return array() === $trail ? array() : (array) $trail[ count( $trail ) - 1 ];
	}

	/**
	 * Forget the trail. Test support and the documented operator reset.
	 *
	 * @return void
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}
}
