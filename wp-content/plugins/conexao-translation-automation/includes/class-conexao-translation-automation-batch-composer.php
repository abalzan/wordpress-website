<?php
/**
 * The batch composition surface and the expansion gate (Stage 10).
 *
 * ## Composition is a separate, explicit act
 *
 * `compose()` builds ONE batch from a reviewed plan and STORES it in
 * `REVIEW_REQUIRED`. It never approves it, never executes it, and never
 * composes a second one in the same call. There is no
 * `compose_all_batches()` and no `next_batch()`: a plan with fifty rows
 * becomes fifty STORED, UNAPPROVED batches that a human must visit one at a
 * time.
 *
 * ## Why "stored but unapproved" is the right end state
 *
 * The alternative — executing straight from the plan — is exactly the
 * "trigger fired -> automatically consume entire inventory" behaviour that
 * must remain impossible. Storing the partition makes the boundaries visible
 * and auditable (batch 1, batch 2, batch 3) without creating any path from
 * "a plan exists" to "content changed".
 *
 * ## The expansion gate
 *
 * `Expansion::evaluate()` answers one question: does the evidence from the
 * PRECEDING batch support a larger one? It requires a verified predecessor,
 * zero unexpected PT mutations, zero unexpected EN mutations, a successful
 * route verification where relevant, successful idempotence, complete audit,
 * a provider error rate within bounds, and no unresolved verification failure.
 *
 * And then it says NOTHING about whether the next batch may run. It returns
 * `eligible`, and eligibility is not authorisation. The next batch still needs
 * its own review and its own approval, and the level is a number a human
 * types. Nothing in this file, or anywhere else, promotes a level on its own.
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The batch composition surface.
 */
final class Conexao_Translation_Automation_Batch_Composer {

	/**
	 * Failure categories.
	 *
	 * @var string
	 */
	const FAILURE_UNAUTHORIZED     = 'unauthorized';
	const FAILURE_TOO_MANY_BATCHES = 'too_many_batches_for_one_invocation';
	const FAILURE_TOO_MANY_STAGES  = 'too_many_stages_for_one_run';
	const FAILURE_MISSING_PLAN     = 'missing_plan';
	const FAILURE_MISSING_BINDING  = 'missing_binding';

	/**
	 * Compose ONE batch and store it in `REVIEW_REQUIRED`.
	 *
	 * @param array $context {
	 *     Composition request.
	 *
	 *     @type array  $plan        Plan rows keyed by portable identity.
	 *     @type int    $batch_size  Partition width.
	 *     @type int    $batch_index Which partition to compose, 0-based.
	 *     @type array  $binding     run_id, environment, stage, digests and
	 *                               provider identity.
	 *     @type bool   $authorized  The caller's own authorisation decision.
	 *     @type string $capability  Must equal 'manage_options'.
	 * }
	 * @return array|WP_Error The stored batch record, or a failure.
	 */
	public static function compose( array $context ) {
		$authorized = ! empty( $context['authorized'] ) && true === $context['authorized'];
		$capability = isset( $context['capability'] ) ? trim( (string) $context['capability'] ) : '';

		if ( ! $authorized || 'manage_options' !== $capability ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_UNAUTHORIZED,
				'The caller is not authorised to compose a batch.'
			);
		}

		// One call composes ONE batch. A caller asking for more is refused
		// rather than served batch by batch, so there is no "keep going" path.
		$count = Conexao_Translation_Automation_Batch_Limits::assert_batch_count( 1 );

		if ( is_wp_error( $count ) ) {
			return $count;
		}

		// The stage is read from the BINDING, which is where the composer
		// requires it, so the stage count is always computed over the same
		// value the batch will actually carry.
		$bound_stage = isset( $context['binding']['stage'] ) ? trim( (string) $context['binding']['stage'] ) : '';

		$stages = Conexao_Translation_Automation_Batch_Limits::assert_stage_count( $bound_stage );

		if ( is_wp_error( $stages ) ) {
			return $stages;
		}

		$plan = isset( $context['plan'] ) && is_array( $context['plan'] ) ? $context['plan'] : array();

		if ( array() === $plan ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_MISSING_PLAN,
				'A batch needs a plan; there is nothing to compose from.'
			);
		}

		$binding = isset( $context['binding'] ) && is_array( $context['binding'] ) ? $context['binding'] : array();

		foreach ( array( 'run_id', 'environment', 'stage', 'change_set_digest', 'plan_digest', 'provider_identity' ) as $key ) {
			if ( '' === (string) ( $binding[ $key ] ?? '' ) ) {
				return new WP_Error(
					'conexao_automation_' . self::FAILURE_MISSING_BINDING,
					sprintf( 'The batch binding has no "%s"; an unbound batch cannot be reviewed.', $key )
				);
			}
		}

		$batch_size = isset( $context['batch_size'] ) ? (int) $context['batch_size'] : 0;
		$level      = isset( $context['level'] ) ? (string) $context['level'] : '';

		if ( '' === $level ) {
			$level = Conexao_Translation_Automation_Batch_Limits::level_for_size( $batch_size );
		}

		$size = Conexao_Translation_Automation_Batch_Limits::assert_batch_size( $batch_size, $level );

		if ( is_wp_error( $size ) ) {
			return $size;
		}

		$definition = Conexao_Translation_Automation_Batch_Limits::level_definition( $level );

		if ( is_wp_error( $definition ) ) {
			return $definition;
		}

		$batch = Conexao_Translation_Automation_Batch::build(
			array(
				'plan'        => $plan,
				'batch_size'  => $batch_size,
				'batch_index' => isset( $context['batch_index'] ) ? (int) $context['batch_index'] : 0,
				'binding'     => array_merge(
					$binding,
					array(
						'level'            => $level,
						'operation_budget' => (int) $definition['operation_budget'],
						'provider_budget'  => (int) $definition['provider_budget'],
					)
				),
			)
		);

		if ( is_wp_error( $batch ) ) {
			return $batch;
		}

		$record = $batch->with_state( Conexao_Translation_Automation_Batch::REVIEW_REQUIRED );

		$saved = Conexao_Translation_Automation_Batch_State::save( $record );

		return is_wp_error( $saved ) ? $saved : $record;
	}

	/**
	 * The whole partition, as a PREVIEW. Read-only, approves nothing.
	 *
	 * This is how an operator sees batch 1, batch 2 and batch 3 before visiting
	 * any of them, and how the test suite proves the partition is
	 * reproducible.
	 *
	 * @param array $plan       Plan rows.
	 * @param int   $batch_size Partition width.
	 * @return array{batches:array<int,array<int,string>>,count:int,ordered:array<int,string>}
	 */
	public static function preview( array $plan, int $batch_size ): array {
		$ordered = Conexao_Translation_Automation_Batch::stable_order( $plan );
		$batches = Conexao_Translation_Automation_Batch::partition_preview( $plan, $batch_size );

		return array(
			'ordered' => $ordered,
			'batches' => $batches,
			'count'   => Conexao_Translation_Automation_Batch::partition_count( $plan, $batch_size ),
		);
	}
}
