<?php
/**
 * Batch identity, deterministic partitioning and approval binding (Stage 10).
 *
 * ## What a batch IS
 *
 * A batch is a NAMED, DIGEST-BOUND set of planned operations that a human
 * approved as one unit. It is pure data: an identity, an ordered operation
 * set, a partition position, a budget and a state. It creates nothing, updates
 * nothing and knows nothing about WordPress.
 *
 * ## Determinism, and why it is not optional
 *
 * Given the same inventory, the same plan, the same batch size and the same
 * ordering rules, the partition MUST be reproducible. So the ordering is
 * explicit and stated once, in `stable_order()`: ascending byte order of the
 * portable source identity, via `ksort( ..., SORT_STRING )`. Never database
 * query order, never PHP hash iteration order, never `mt_rand()`, never the
 * clock. A partition that varied between runs would make "batch 1, batch 2,
 * batch 3" meaningless and would let an approval attach to a different set of
 * records than the one that was reviewed.
 *
 * Portable identity is the repository's own convention: the stage manifest's
 * row key, which is the stable slug-like key rather than a local post ID. So
 * the same inventory partitions identically in local and in production.
 *
 * ## The operation count is derived, not declared
 *
 * A record is not one mutation. The plan model distinguishes a B1 creation, a
 * B1 update, a B1 repair/reconcile and a B2 single-field write, and each is
 * one planned mutation operation. `operation_count()` counts ROWS, because a
 * row is the plan model's unit of work, and a B2 row is a single field rather
 * than a record. So the budget is a bound on the engine's own planned work, not
 * on a caller-supplied number.
 *
 * ## The operation-set digest is what makes approval non-substitutable
 *
 * `operation_set_digest()` hashes the ordered identities TOGETHER WITH each
 * identity's source digest, operation kind and result digest. An approval for
 * `A+B+C` therefore cannot execute `A+B+D`, cannot execute `A+B+C` after D's
 * PT source changed, and cannot execute the same three records with a different
 * provider result. Any of those produces a different digest and a refusal.
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

/**
 * One bounded, digest-bound batch of planned operations.
 */
final class Conexao_Translation_Automation_Batch {

	/**
	 * Batch states. The state machine is defined in `Batch_State`; these are
	 * the vocabulary it transitions between.
	 *
	 * @var string
	 */
	const REVIEW_REQUIRED    = 'REVIEW_REQUIRED';
	const REVIEWED           = 'REVIEWED';
	const APPROVED_FOR_BATCH = 'APPROVED_FOR_BATCH';
	const EXECUTING          = 'EXECUTING';
	const VERIFIED           = 'VERIFIED';
	const FAILED             = 'FAILED';
	const STOPPED            = 'STOPPED';
	const ABORTED            = 'ABORTED';
	const EXPIRED            = 'EXPIRED';

	/**
	 * Per-operation statuses, so a resumed batch can tell what already
	 * happened from what merely never started.
	 *
	 * @var string
	 */
	const OP_PENDING             = 'pending';
	const OP_COMPLETED           = 'completed';
	const OP_FAILED              = 'failed';
	const OP_VERIFICATION_FAILED = 'verification_failed';
	const OP_MANUAL_INTERVENTION = 'manual_intervention_required';

	/**
	 * The ordered identities of a batch.
	 *
	 * @var array<int,string>
	 */
	private $identities = array();

	/**
	 * Per-identity operation detail, keyed by identity.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private $operations = array();

	/**
	 * The batch's own facts.
	 *
	 * @var array<string,mixed>
	 */
	private $meta = array();

	/**
	 * Build a batch from a reviewed plan and an explicit partition position.
	 *
	 * @param array $args Invocation facts, described below.
	 * @return self|WP_Error
	 */
	public static function build( array $args ) {
		$plan       = isset( $args['plan'] ) && is_array( $args['plan'] ) ? $args['plan'] : array();
		$batch_size = isset( $args['batch_size'] ) ? (int) $args['batch_size'] : 0;
		$index      = isset( $args['batch_index'] ) ? (int) $args['batch_index'] : 0;
		$binding    = isset( $args['binding'] ) && is_array( $args['binding'] ) ? $args['binding'] : array();

		if ( $batch_size < 1 ) {
			return new WP_Error(
				'conexao_automation_batch_size_invalid',
				'A batch must be built with a batch size of at least 1.'
			);
		}

		$ordered = self::stable_order( $plan );

		if ( array() === $ordered ) {
			return new WP_Error(
				'conexao_automation_batch_empty',
				'The plan carries no rows, so there is nothing to batch.'
			);
		}

		$partitions = array_chunk( $ordered, $batch_size );

		if ( ! array_key_exists( $index, $partitions ) ) {
			return new WP_Error(
				'conexao_automation_batch_index_unknown',
				sprintf( 'Partition %d does not exist; the plan has %d.', $index, count( $partitions ) )
			);
		}

		$batch       = new self();
		$batch->meta = array(
			'stage'             => (string) ( $binding['stage'] ?? '' ),
			'environment'       => (string) ( $binding['environment'] ?? '' ),
			'run_id'            => (string) ( $binding['run_id'] ?? '' ),
			'level'             => (string) ( $binding['level'] ?? '' ),
			'batch_size'        => $batch_size,
			'batch_index'       => $index,
			'batch_count'       => count( $partitions ),
			'change_set_digest' => (string) ( $binding['change_set_digest'] ?? '' ),
			'plan_digest'       => (string) ( $binding['plan_digest'] ?? '' ),
			'approval_digest'   => (string) ( $binding['approval_digest'] ?? '' ),
			'provider_identity' => (string) ( $binding['provider_identity'] ?? '' ),
			'operation_budget'  => (int) ( $binding['operation_budget'] ?? 0 ),
			'provider_budget'   => (int) ( $binding['provider_budget'] ?? 0 ),
			'state'             => self::REVIEW_REQUIRED,
		);

		foreach ( $partitions[ $index ] as $identity ) {
			$batch->identities[]            = $identity;
			$batch->operations[ $identity ] = self::operation_detail( $plan[ $identity ] );
		}

		return $batch;
	}

	/**
	 * The stable, reproducible ordering.
	 *
	 * Ascending byte order of the PORTABLE source identity. `ksort` with
	 * `SORT_STRING` is used rather than `sort()` on values so the order is a
	 * function of the keys, which are the identities, and never of insertion
	 * order, hash-table layout or the clock.
	 *
	 * @param array $plan Plan rows keyed by identity.
	 * @return array<int,string> Ordered identities.
	 */
	public static function stable_order( array $plan ): array {
		$identities = array();

		foreach ( array_keys( $plan ) as $identity ) {
			$identities[] = (string) $identity;
		}

		// SORT_STRING is a byte comparison, so the order is identical in every
		// PHP build, on every machine, for every locale.
		usort(
			$identities,
			static function ( string $left, string $right ): int {
				return strcmp( $left, $right );
			}
		);

		return $identities;
	}

	/**
	 * The planned-operation detail of one plan row.
	 *
	 * Only the fields that DEFINE the work are kept: the portable identity, the
	 * operation kind, the PT source digest it was generated from, and the
	 * provider result digest. The translated text itself is deliberately not
	 * stored here, so the batch record can never become a second copy of the
	 * content and can never carry a provider payload.
	 *
	 * @param mixed $row A plan row.
	 * @return array<string,string>
	 */
	private static function operation_detail( $row ): array {
		$row = is_array( $row ) ? $row : array();

		return array(
			'operation'     => (string) ( $row['operation'] ?? '' ),
			'source_digest' => (string) ( $row['source_digest'] ?? '' ),
			'result_digest' => (string) ( $row['result_digest'] ?? '' ),
		);
	}

	/**
	 * How many partitions the whole plan yields at this batch size.
	 *
	 * Used by the partition preview so an operator can see batch 1, batch 2,
	 * batch 3 before approving any of them.
	 *
	 * @param array $plan       Plan rows.
	 * @param int   $batch_size Partition width.
	 * @return int
	 */
	public static function partition_count( array $plan, int $batch_size ): int {
		if ( $batch_size < 1 ) {
			return 0;
		}

		return count( array_chunk( self::stable_order( $plan ), $batch_size ) );
	}

	/**
	 * The identities of every partition, in order. Read-only, for preview.
	 *
	 * @param array $plan       Plan rows.
	 * @param int   $batch_size Partition width.
	 * @return array<int,array<int,string>>
	 */
	public static function partition_preview( array $plan, int $batch_size ): array {
		if ( $batch_size < 1 ) {
			return array();
		}

		$preview = array();

		foreach ( array_chunk( self::stable_order( $plan ), $batch_size ) as $chunk ) {
			// array_chunk() already returns lists.
			$preview[] = $chunk;
		}

		return $preview;
	}

	/**
	 * The ordered identities in this batch.
	 *
	 * @return array<int,string>
	 */
	public function identities(): array {
		return $this->identities;
	}

	/**
	 * The per-identity operation detail.
	 *
	 * @return array<string,array<string,string>>
	 */
	public function operations(): array {
		return $this->operations;
	}

	/**
	 * The batch's facts.
	 *
	 * @return array<string,mixed>
	 */
	public function meta(): array {
		return $this->meta;
	}

	/**
	 * How many records the batch covers.
	 *
	 * @return int
	 */
	public function record_count(): int {
		return count( $this->identities );
	}

	/**
	 * The planned-operation count.
	 *
	 * One per plan row. A B2 row is a single owned field and a B1 row is one
	 * record create/update/repair, so this is the engine's own unit of work.
	 *
	 * @return int
	 */
	public function operation_count(): int {
		return count( $this->operations );
	}

	/**
	 * The exact worst-case provider-request cost of this batch.
	 *
	 * @return int
	 */
	public function provider_request_cost(): int {
		return Conexao_Translation_Automation_Batch_Limits::worst_case_provider_requests( $this->record_count() );
	}

	/**
	 * The operation kinds this batch contains, for the audit record.
	 *
	 * @return array<string,int> Operation kind => count.
	 */
	public function operation_mix(): array {
		$mix = array();

		foreach ( $this->operations as $detail ) {
			$kind         = (string) ( $detail['operation'] ?? '' );
			$mix[ $kind ] = isset( $mix[ $kind ] ) ? $mix[ $kind ] + 1 : 1;
		}

		ksort( $mix );

		return $mix;
	}

	/**
	 * The operation-set digest: what an approval for THIS batch is bound to.
	 *
	 * It covers, for every identity in order: the identity, the operation kind,
	 * the PT source digest the translation was generated from, and the provider
	 * result digest. So the digest changes when ANY of these change:
	 *
	 *   - the record set changes (`A+B+C` -> `A+B+D`);
	 *   - a record's PT source changes (stale translation);
	 *   - a record's provider result changes;
	 *   - the operation kind changes.
	 *
	 * And each of those is exactly one of the substitutions an approval must
	 * not survive.
	 *
	 * @return string
	 */
	public function operation_set_digest(): string {
		return Conexao_Translation_Automation_Apply_Gate::digest( $this->operations );
	}

	/**
	 * The batch digest: the batch's full identity.
	 *
	 * Binds the run, environment, stage, source/change-set digest, plan digest,
	 * approval digest, provider configuration identity, batch size, operation
	 * budget, provider budget, partition position and the operation-set digest.
	 *
	 * A batch is therefore NOT replayable against a different plan, a
	 * different environment, a different provider configuration or a different
	 * partition: every one of those produces a different digest.
	 *
	 * @return string
	 */
	public function batch_digest(): string {
		return Conexao_Translation_Automation_Apply_Gate::digest(
			array(
				'run_id'            => (string) $this->meta['run_id'],
				'environment'       => (string) $this->meta['environment'],
				'stage'             => (string) $this->meta['stage'],
				'level'             => (string) $this->meta['level'],
				'batch_size'        => (int) $this->meta['batch_size'],
				'batch_index'       => (int) $this->meta['batch_index'],
				'change_set_digest' => (string) $this->meta['change_set_digest'],
				'plan_digest'       => (string) $this->meta['plan_digest'],
				'approval_digest'   => (string) $this->meta['approval_digest'],
				'provider_identity' => (string) $this->meta['provider_identity'],
				'operation_budget'  => (int) $this->meta['operation_budget'],
				'provider_budget'   => (int) $this->meta['provider_budget'],
				'operation_count'   => $this->operation_count(),
				'operation_set'     => $this->operation_set_digest(),
			)
		);
	}

	/**
	 * The batch identifier.
	 *
	 * Derived from the batch digest, so it is stable for one composition and
	 * different for any other. It carries no timestamp and no counter, so two
	 * operators composing the same approved plan see the same id.
	 *
	 * @return string
	 */
	public function batch_id(): string {
		return 'batch_' . substr( $this->batch_digest(), 0, 16 );
	}

	/**
	 * The reviewable, secret-free description of this batch.
	 *
	 * Carries identities and digests, never translated text and never a
	 * provider payload, so it is safe to persist, print and paste into a
	 * report.
	 *
	 * @return array<string,mixed>
	 */
	public function to_record(): array {
		$meta = $this->meta;

		return array(
			'v'                     => 1,
			'batch_id'              => $this->batch_id(),
			'batch_digest'          => $this->batch_digest(),
			'operation_set_digest'  => $this->operation_set_digest(),
			'stage'                 => (string) $meta['stage'],
			'environment'           => (string) $meta['environment'],
			'run_id'                => (string) $meta['run_id'],
			'level'                 => (string) $meta['level'],
			'state'                 => (string) $meta['state'],
			'batch_size'            => (int) $meta['batch_size'],
			'batch_index'           => (int) $meta['batch_index'],
			'batch_count'           => (int) $meta['batch_count'],
			'records'               => $this->record_count(),
			'operations'            => $this->operation_count(),
			'operation_mix'         => $this->operation_mix(),
			'provider_request_cost' => $this->provider_request_cost(),
			'operation_budget'      => (int) $meta['operation_budget'],
			'provider_budget'       => (int) $meta['provider_budget'],
			'change_set_digest'     => (string) $meta['change_set_digest'],
			'plan_digest'           => (string) $meta['plan_digest'],
			'approval_digest'       => (string) $meta['approval_digest'],
			'provider_identity'     => (string) $meta['provider_identity'],
			'identities'            => $this->identities,
			'operation_detail'      => $this->operations,
			'created_at'            => gmdate( 'c' ),
		);
	}

	/**
	 * A batch record reconstructed from its stored form.
	 *
	 * Refuses anything whose recomputed digests do not match what was stored,
	 * so a tampered or truncated record is a hard failure rather than a batch
	 * the executor would happily run.
	 *
	 * @param array $record A stored record from `to_record()`.
	 * @return self|WP_Error
	 */
	public static function from_record( array $record ) {
		foreach ( array( 'batch_id', 'batch_digest', 'operation_set_digest', 'identities', 'operation_detail' ) as $key ) {
			if ( ! isset( $record[ $key ] ) ) {
				return new WP_Error(
					'conexao_automation_batch_record_incomplete',
					sprintf( 'The stored batch record has no "%s".', $key )
				);
			}
		}

		$batch = new self();

		$batch->identities = array_map( 'strval', (array) $record['identities'] );
		$batch->operations = (array) $record['operation_detail'];
		$batch->meta       = array(
			'stage'             => (string) ( $record['stage'] ?? '' ),
			'environment'       => (string) ( $record['environment'] ?? '' ),
			'run_id'            => (string) ( $record['run_id'] ?? '' ),
			'level'             => (string) ( $record['level'] ?? '' ),
			'batch_size'        => (int) ( $record['batch_size'] ?? 0 ),
			'batch_index'       => (int) ( $record['batch_index'] ?? 0 ),
			'batch_count'       => (int) ( $record['batch_count'] ?? 0 ),
			'change_set_digest' => (string) ( $record['change_set_digest'] ?? '' ),
			'plan_digest'       => (string) ( $record['plan_digest'] ?? '' ),
			'approval_digest'   => (string) ( $record['approval_digest'] ?? '' ),
			'provider_identity' => (string) ( $record['provider_identity'] ?? '' ),
			'operation_budget'  => (int) ( $record['operation_budget'] ?? 0 ),
			'provider_budget'   => (int) ( $record['provider_budget'] ?? 0 ),
			'state'             => (string) ( $record['state'] ?? self::REVIEW_REQUIRED ),
		);

		if ( count( $batch->identities ) !== count( $batch->operations ) ) {
			return new WP_Error(
				'conexao_automation_batch_record_inconsistent',
				'The stored batch record lists identities and operation detail of different sizes.'
			);
		}

		if ( ! hash_equals( (string) $record['operation_set_digest'], $batch->operation_set_digest() ) ) {
			return new WP_Error(
				'conexao_automation_batch_record_tampered',
				'The stored operation set does not hash to its recorded digest; refusing to use it.'
			);
		}

		if ( ! hash_equals( (string) $record['batch_digest'], $batch->batch_digest() ) ) {
			return new WP_Error(
				'conexao_automation_batch_record_tampered',
				'The stored batch does not hash to its recorded digest; refusing to use it.'
			);
		}

		if ( ! hash_equals( (string) $record['batch_id'], $batch->batch_id() ) ) {
			return new WP_Error(
				'conexao_automation_batch_record_tampered',
				'The stored batch id does not match its own composition; refusing to use it.'
			);
		}

		return $batch;
	}

	/**
	 * Restate the batch's state on the record, for a state transition.
	 *
	 * The digests are recomputed by `from_record()`, so persisting a new state
	 * never rewrites the composition.
	 *
	 * @param string $state New state.
	 * @return array<string,mixed>
	 */
	public function with_state( string $state ): array {
		$record          = $this->to_record();
		$record['state'] = $state;

		return $record;
	}

	/**
	 * Is this identity a member of this batch?
	 *
	 * The executor asks this before every operation, so an operation that is
	 * not in the approved set cannot be executed even by a bug in the caller.
	 *
	 * @param string $identity Portable source identity.
	 * @return bool
	 */
	public function contains( string $identity ): bool {
		return in_array( $identity, $this->identities, true );
	}
}
