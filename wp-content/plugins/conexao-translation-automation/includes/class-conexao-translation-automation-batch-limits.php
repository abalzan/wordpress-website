<?php
/**
 * Bounded rollout levels and their hard ceilings (Stage 10).
 *
 * ## Why this file exists
 *
 * Stage 9 established that the next implementation need is not another trigger
 * or another provider mechanism, but the CONTROLS required to expand safely
 * from one proven canary to small explicitly bounded batches. Every one of
 * those controls is a number. This file is where the numbers live, so there is
 * exactly one place to read, to audit and to change them.
 *
 * ## The numbers, and where they come from
 *
 * | Constant | Value | Reasoning |
 * |---|---|---|
 * | `MAX_PRODUCTION_BATCHES_PER_INVOCATION` | **1** | A trigger invocation must never progress to a second batch. After one batch the system STOPS and waits for a new human invocation. |
 * | `MAX_STAGES_PER_RUN` | **1** | A batch is single-stage, so the operation budget bounds one record set rather than a sum over unrelated ones. |
 * | `SERVER_CEILING_BATCH_RECORDS` | **5** | The hard server-side ceiling. A caller may request FEWER; it may never raise this. `batch_size=100000` is a refusal, not a clamp. |
 * | `MAX_RECORDS_PER_STAGE` | **5** | Repository evidence: Stage 5/7 shipped a one-record canary and the provider boundary allows 50 requests per run. Five is three orders below "translate the backlog" and two above the canary. |
 * | `MAX_OPERATIONS_PER_BATCH` | **5** | The mutation budget: one plan row is one planned operation, so this is a second, independent bound on the same work. |
 * | `MAX_PROVIDER_REQUESTS_PER_BATCH` | **20** | Independent of the operation budget, so a small batch with retries cannot become an unbounded bill. It is `SERVER_CEILING_BATCH_RECORDS * MAX_ATTEMPTS_PER_RECORD * 2`, so a maximum-size batch always fits under it. |
 * | `MAX_RETRIES_PER_RECORD` | **1** | Two attempts per record, deliberately below the provider's own `MAX_ATTEMPTS` of 4. The batch is the tighter bound. |
 * | `MAX_TOTAL_PROVIDER_CALLS` | **20** | Initial requests, retries and re-requests after a stale-source rejection, all in one number. Still well under the provider's own `MAX_REQUESTS_PER_RUN` of 50. |
 * | `MAX_EXECUTION_SECONDS` | **300** | Half the provider's own 600 s run budget, and well inside the 900 s lock TTL so a stopped batch can still release its lock. |
 *
 * ## Why the ceiling refuses rather than clamps
 *
 * A clamp is silent: `batch_size=100000` becomes 5 and the run proceeds, and
 * the audit trail then says 5, so the record no longer shows what the caller
 * actually asked for. A refusal is visible, fails closed, and is recorded. So
 * `assert_batch_size()` returns a failure category rather than a value.
 *
 * ## The levels are operational states, not judgements
 *
 * `LEVEL_0_CANARY`, `LEVEL_1_SMALL_BATCH` and `LEVEL_2_LARGER_BATCH` are names
 * of bounded execution states. They carry no claim that one is safe or
 * approved. Every level is reachable only through a new explicit human
 * decision; nothing here infers a level from a success.
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The bounded rollout levels and their ceilings.
 */
final class Conexao_Translation_Automation_Batch_Limits {

	/**
	 * How many batches ONE invocation may execute.
	 *
	 * Exactly 1, and not configurable. There is deliberately no filter, option
	 * or request field that can raise it: autonomous progression is not a
	 * capability this system has.
	 *
	 * @var int
	 */
	const MAX_PRODUCTION_BATCHES_PER_INVOCATION = 1;

	/**
	 * How many stages one run may cover.
	 *
	 * @var int
	 */
	const MAX_STAGES_PER_RUN = 1;

	/**
	 * The server-side batch-size ceiling. A caller may go lower, never higher.
	 *
	 * @var int
	 */
	const SERVER_CEILING_BATCH_RECORDS = 5;

	/**
	 * The maximum records one stage may contribute to a batch.
	 *
	 * @var int
	 */
	const MAX_RECORDS_PER_STAGE = 5;

	/**
	 * The hard mutation budget: planned operations per batch.
	 *
	 * @var int
	 */
	const MAX_OPERATIONS_PER_BATCH = 5;

	/**
	 * The independent provider-request budget.
	 *
	 * @var int
	 */
	const MAX_PROVIDER_REQUESTS_PER_BATCH = 20;

	/**
	 * Retries permitted for ONE record, on top of its first attempt.
	 *
	 * @var int
	 */
	const MAX_RETRIES_PER_RECORD = 1;

	/**
	 * Total attempts for one record. Never configured separately.
	 *
	 * @var int
	 */
	const MAX_ATTEMPTS_PER_RECORD = 2;

	/**
	 * Total provider calls a batch may make, of any kind.
	 *
	 * @var int
	 */
	const MAX_TOTAL_PROVIDER_CALLS = 20;

	/**
	 * The wall-clock budget for one batch, in seconds.
	 *
	 * @var int
	 */
	const MAX_EXECUTION_SECONDS = 300;

	/**
	 * Rollout level identifiers. Operational states, not judgements.
	 *
	 * @var string
	 */
	const LEVEL_0_CANARY      = 'LEVEL_0_CANARY';
	const LEVEL_1_SMALL_BATCH = 'LEVEL_1_SMALL_BATCH';
	const LEVEL_2_LARGER      = 'LEVEL_2_LARGER_BATCH';

	/**
	 * Failure categories this class can return.
	 *
	 * @var string
	 */
	const FAILURE_UNKNOWN_LEVEL            = 'unknown_batch_level';
	const FAILURE_BATCH_SIZE_NOT_INT       = 'batch_size_not_an_integer';
	const FAILURE_BATCH_SIZE_OUT_OF_RANGE  = 'batch_size_out_of_range';
	const FAILURE_BATCH_SIZE_ABOVE_CEILING = 'batch_size_above_server_ceiling';
	const FAILURE_BUDGET_ABOVE_CEILING     = 'budget_above_server_ceiling';
	const FAILURE_TOO_MANY_BATCHES         = 'too_many_batches_for_one_invocation';
	const FAILURE_TOO_MANY_STAGES          = 'too_many_stages_for_one_run';
	const FAILURE_TOO_MANY_OPERATIONS      = 'planned_operations_exceed_budget';
	const FAILURE_TOO_MANY_REQUESTS        = 'planned_provider_requests_exceed_budget';

	/**
	 * The bounded rollout levels, each with its own explicit limits.
	 *
	 * A level may be TIGHTER than the server ceiling and never looser. The
	 * clamping happens in `level_definition()`, not here, so the declared
	 * numbers stay readable and the clamp stays auditable.
	 *
	 * @return array<string,array<string,int|string>>
	 */
	public static function levels(): array {
		return array(
			self::LEVEL_0_CANARY      => array(
				'label'            => self::LEVEL_0_CANARY,
				'batch_records'    => 1,
				'operation_budget' => 1,
				'provider_budget'  => 4,
			),
			self::LEVEL_1_SMALL_BATCH => array(
				'label'            => self::LEVEL_1_SMALL_BATCH,
				'batch_records'    => 3,
				'operation_budget' => 3,
				'provider_budget'  => 12,
			),
			self::LEVEL_2_LARGER      => array(
				'label'            => self::LEVEL_2_LARGER,
				'batch_records'    => self::SERVER_CEILING_BATCH_RECORDS,
				'operation_budget' => self::MAX_OPERATIONS_PER_BATCH,
				'provider_budget'  => 20,
			),
		);
	}

	/**
	 * The level identifiers, in ascending order of size.
	 *
	 * @return array<int,string>
	 */
	public static function level_ids(): array {
		return array_keys( self::levels() );
	}

	/**
	 * Is this a known level?
	 *
	 * @param string $level Candidate level.
	 * @return bool
	 */
	public static function is_level( string $level ): bool {
		return array_key_exists( $level, self::levels() );
	}

	/**
	 * One level's definition, with its limits already clamped to the server
	 * ceiling so no level can ever be looser than the ceiling.
	 *
	 * @param string $level Level identifier.
	 * @return array<string,int|string>|WP_Error
	 */
	public static function level_definition( string $level ) {
		if ( ! self::is_level( $level ) ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_UNKNOWN_LEVEL,
				sprintf( 'No such rollout level: "%s".', $level )
			);
		}

		$definition = self::levels()[ $level ];

		$definition['batch_records']    = min( (int) $definition['batch_records'], self::SERVER_CEILING_BATCH_RECORDS );
		$definition['operation_budget'] = min( (int) $definition['operation_budget'], self::MAX_OPERATIONS_PER_BATCH );
		$definition['provider_budget']  = min( (int) $definition['provider_budget'], self::MAX_TOTAL_PROVIDER_CALLS );

		return $definition;
	}

	/**
	 * The highest level a given batch size belongs to.
	 *
	 * Used only to RECORD a level on an already-approved batch. It never
	 * selects or promotes one: the caller must already have named the level,
	 * and `assert_batch_size()` still refuses anything above the ceiling.
	 *
	 * @param int $batch_size Requested batch size.
	 * @return string
	 */
	public static function level_for_size( int $batch_size ): string {
		// The levels are declared in ASCENDING order, so the FIRST level that
		// accommodates the size is the smallest one that does. Returning the
		// LAST match would hand a one-record canary the largest level, which
		// would then set its budget from the ceiling.
		foreach ( self::levels() as $id => $definition ) {
			if ( $batch_size <= min( (int) $definition['batch_records'], self::SERVER_CEILING_BATCH_RECORDS ) ) {
				return $id;
			}
		}

		// A size above every level is only reachable through an explicit level
		// the caller names, and `assert_batch_size()` will refuse it there.
		return (string) array_key_last( self::levels() );
	}

	/**
	 * Validate a caller-supplied batch size against the server ceiling.
	 *
	 * Refuses rather than clamps, so the audit trail always shows what was
	 * actually requested. See the class docblock for why.
	 *
	 * @param mixed  $requested Caller-supplied size.
	 * @param string $level     The level the caller named.
	 * @return true|WP_Error
	 */
	public static function assert_batch_size( $requested, string $level ) {
		$definition = self::level_definition( $level );

		if ( is_wp_error( $definition ) ) {
			return $definition;
		}

		$size = self::whole_number( $requested );

		if ( is_wp_error( $size ) ) {
			return $size;
		}

		if ( $size < 1 ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_BATCH_SIZE_OUT_OF_RANGE,
				'The batch size must be at least 1.'
			);
		}

		// The order matters: an ABOVE-CEILING request is reported as such, not
		// as merely "over the level", so the audit names the real problem.
		if ( $size > self::SERVER_CEILING_BATCH_RECORDS ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_BATCH_SIZE_ABOVE_CEILING,
				sprintf(
					'The batch size %d is above the server ceiling of %d; the caller cannot raise it.',
					$size,
					self::SERVER_CEILING_BATCH_RECORDS
				)
			);
		}

		if ( $size > (int) $definition['batch_records'] ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_BATCH_SIZE_OUT_OF_RANGE,
				sprintf(
					'The batch size %d exceeds level %s, which allows %d record(s).',
					$size,
					$level,
					(int) $definition['batch_records']
				)
			);
		}

		return true;
	}

	/**
	 * Coerce a candidate whole number, or refuse.
	 *
	 * A numeric string is accepted, because an admin form posts strings. A
	 * float, a boolean, an array, an object or `null` is not a count at all,
	 * and silently coercing one to an integer is how `batch_size=true` becomes
	 * a batch of one.
	 *
	 * @param mixed $value Candidate.
	 * @return int|WP_Error
	 */
	public static function whole_number( $value ) {
		if ( is_int( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) && preg_match( '/^[0-9]+$/', trim( $value ) ) ) {
			return (int) trim( $value );
		}

		return new WP_Error(
			'conexao_automation_' . self::FAILURE_BATCH_SIZE_NOT_INT,
			'The value must be a whole number.'
		);
	}

	/**
	 * Assert that one invocation may not carry more than one batch.
	 *
	 * @param mixed $requested Caller-supplied batch count.
	 * @return true|WP_Error
	 */
	public static function assert_batch_count( $requested ) {
		$count = self::whole_number( $requested );

		if ( is_wp_error( $count ) ) {
			return $count;
		}

		if ( $count > self::MAX_PRODUCTION_BATCHES_PER_INVOCATION ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_TOO_MANY_BATCHES,
				sprintf(
					'One invocation may execute at most %d batch; %d were requested. Autonomous progression is not a capability this system has.',
					self::MAX_PRODUCTION_BATCHES_PER_INVOCATION,
					$count
				)
			);
		}

		if ( $count < 1 ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_BATCH_SIZE_OUT_OF_RANGE,
				'At least one batch must be named.'
			);
		}

		return true;
	}

	/**
	 * Assert the stage count for one run.
	 *
	 * Accepts a stage identifier, a list of identifiers, or a plain count, so
	 * the caller never has to convert a name into a number itself.
	 *
	 * @param mixed $requested Caller-supplied stage, array of stages, or count.
	 * @return true|WP_Error
	 */
	public static function assert_stage_count( $requested ) {
		if ( is_array( $requested ) ) {
			$count = count( $requested );
		} elseif ( is_int( $requested ) ) {
			$count = $requested;
		} elseif ( is_string( $requested ) && '' === trim( $requested ) ) {
			$count = 0;
		} else {
			// A non-empty string is ONE stage identifier. Coercing it with
			// (int) would make every real stage name count as zero.
			$count = 1;
		}

		if ( $count > self::MAX_STAGES_PER_RUN ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_TOO_MANY_STAGES,
				sprintf( 'One run may cover at most %d stage; %d were named.', self::MAX_STAGES_PER_RUN, $count )
			);
		}

		if ( $count < 1 ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_TOO_MANY_STAGES,
				'A run must name exactly one stage.'
			);
		}

		return true;
	}

	/**
	 * Validate the full budget set a batch will be executed under.
	 *
	 * Fails CLOSED, before any apply, when the plan needs more operations or
	 * more provider requests than the approved budget allows. No partial
	 * execution may begin.
	 *
	 * @param array $args Invocation facts, described below.
	 * @return true|WP_Error
	 */
	public static function assert_budget( array $args ) {
		$operations = isset( $args['operations'] ) ? (int) $args['operations'] : 0;
		$op_budget  = isset( $args['operation_budget'] ) ? (int) $args['operation_budget'] : 0;
		$requests   = isset( $args['provider_requests'] ) ? (int) $args['provider_requests'] : 0;
		$pr_budget  = isset( $args['provider_budget'] ) ? (int) $args['provider_budget'] : 0;

		// A budget may never exceed the server ceiling, whatever the caller
		// asked for. This is the "impossible for the caller to raise
		// arbitrarily" requirement, enforced on the budget as well as the size.
		if ( $op_budget < 1 || $op_budget > self::MAX_OPERATIONS_PER_BATCH ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_BUDGET_ABOVE_CEILING,
				sprintf( 'An operation budget must be between 1 and %d; got %d.', self::MAX_OPERATIONS_PER_BATCH, $op_budget )
			);
		}

		if ( $pr_budget < 1 || $pr_budget > self::MAX_TOTAL_PROVIDER_CALLS ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_BUDGET_ABOVE_CEILING,
				sprintf( 'A provider budget must be between 1 and %d; got %d.', self::MAX_TOTAL_PROVIDER_CALLS, $pr_budget )
			);
		}

		if ( $operations < 1 ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_TOO_MANY_OPERATIONS,
				'A batch must plan at least one operation.'
			);
		}

		if ( $operations > $op_budget ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_TOO_MANY_OPERATIONS,
				sprintf(
					'The plan needs %d mutation operation(s) but the approved budget is %d; NO PARTIAL EXECUTION.',
					$operations,
					$op_budget
				)
			);
		}

		if ( $requests > $pr_budget ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_TOO_MANY_REQUESTS,
				sprintf(
					'The plan needs %d provider request(s) but the approved budget is %d; NO PARTIAL EXECUTION.',
					$requests,
					$pr_budget
				)
			);
		}

		return true;
	}

	/**
	 * The exact worst-case provider-request cost of a batch.
	 *
	 * The budget must account for initial requests, retries AND re-requests
	 * after a stale-source rejection. So this counts, per record, the worst
	 * case: every attempt is used, and then a full second round is issued
	 * because the first result was rejected as stale. That is
	 * `MAX_ATTEMPTS_PER_RECORD * 2` per record, and it is deliberately
	 * pessimistic: a batch that fits under this number cannot exceed it.
	 *
	 * @param int $records Records in the batch.
	 * @return int
	 */
	public static function worst_case_provider_requests( int $records ): int {
		return $records * self::MAX_ATTEMPTS_PER_RECORD * 2;
	}

	/**
	 * The complete, printable limit set. Safe for an audit record and a report.
	 *
	 * @return array<string,int>
	 */
	public static function configuration(): array {
		return array(
			'max_batches_per_invocation' => self::MAX_PRODUCTION_BATCHES_PER_INVOCATION,
			'max_stages_per_run'         => self::MAX_STAGES_PER_RUN,
			'server_ceiling_records'     => self::SERVER_CEILING_BATCH_RECORDS,
			'max_records_per_stage'      => self::MAX_RECORDS_PER_STAGE,
			'max_operations_per_batch'   => self::MAX_OPERATIONS_PER_BATCH,
			'max_provider_requests'      => self::MAX_PROVIDER_REQUESTS_PER_BATCH,
			'max_retries_per_record'     => self::MAX_RETRIES_PER_RECORD,
			'max_attempts_per_record'    => self::MAX_ATTEMPTS_PER_RECORD,
			'max_total_provider_calls'   => self::MAX_TOTAL_PROVIDER_CALLS,
			'max_execution_seconds'      => self::MAX_EXECUTION_SECONDS,
		);
	}
}
