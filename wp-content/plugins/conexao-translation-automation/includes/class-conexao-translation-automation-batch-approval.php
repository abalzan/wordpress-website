<?php
/**
 * Batch approval: one explicit, digest-bound human decision (Stage 10).
 *
 * ## Approval is for ONE batch, and it is a digest
 *
 * There is deliberately no `approve_batch_type` and no
 * `approve_current_queue`. Both were rejected because each of them makes an
 * approval survive a change in what is being approved: a "batch type"
 * approval would still be valid when the records inside it are swapped, and a
 * "current queue" approval would still be valid when the queue moves. So an
 * approval here is the digest of ONE concrete batch, and the executor
 * recomputes that digest from the stored composition and requires an exact
 * match before it executes anything.
 *
 * ## What the digest binds
 *
 * | Bound | Why it is in the digest |
 * |---|---|
 * | `batch_id` + `batch_digest` | the exact batch, including its partition position |
 * | `operation_set_digest` | the exact records, operation kinds, PT source digests and provider result digests |
 * | `source/change-set digest` | the PT state the review compared against |
 * | `plan_digest` | the exact create/update/skip/conflict decisions |
 * | `operation_count` | the exact mutation count |
 * | `operation_budget` / `provider_budget` | the exact limits |
 * | `environment` | a staging approval is not a production approval |
 * | `stage`, `run_id` | the same records under a different stage or run are a different decision |
 * | `provider_identity` | a different model is a different decision |
 *
 * Changing ANY of them produces a different approval digest, and
 * `verify()` refuses. That is what makes partial approval impossible: there is
 * no such thing as "mostly approved".
 *
 * ## Two human actions, never conflated
 *
 * `record_review()` is the human saying "I have read this exact batch". It
 * produces the REVIEW digest. `record_approval()` is the human authorising that
 * reviewed batch to execute, and it additionally binds the review digest. So
 * an approval can never exist for a batch nobody reviewed, and a review can
 * never authorise execution on its own.
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The batch approval boundary.
 */
final class Conexao_Translation_Automation_Batch_Approval {

	/**
	 * Failure categories this class can return.
	 *
	 * @var string
	 */
	const FAILURE_NOT_REVIEWED       = 'batch_not_reviewed';
	const FAILURE_REVIEW_MISMATCH    = 'batch_review_mismatch';
	const FAILURE_APPROVAL_MISSING   = 'batch_approval_missing';
	const FAILURE_APPROVAL_MISMATCH  = 'batch_approval_mismatch';
	const FAILURE_NOT_APPROVED_STATE = 'batch_not_in_approved_state';
	const FAILURE_UNAUTHORIZED       = 'unauthorized';

	/**
	 * The capability a caller must hold for either human action.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * The facts an approval is computed over.
	 *
	 * Extracted from a batch and its stored record so the digest is built from
	 * ONE place, whether it is being created or being verified.
	 *
	 * @param array $record A stored batch record.
	 * @return array<string,mixed>
	 */
	public static function binding( array $record ): array {
		return array(
			'batch_id'              => (string) ( $record['batch_id'] ?? '' ),
			'batch_digest'          => (string) ( $record['batch_digest'] ?? '' ),
			'operation_set_digest'  => (string) ( $record['operation_set_digest'] ?? '' ),
			'stage'                 => (string) ( $record['stage'] ?? '' ),
			'environment'           => (string) ( $record['environment'] ?? '' ),
			'run_id'                => (string) ( $record['run_id'] ?? '' ),
			'level'                 => (string) ( $record['level'] ?? '' ),
			'change_set_digest'     => (string) ( $record['change_set_digest'] ?? '' ),
			'plan_digest'           => (string) ( $record['plan_digest'] ?? '' ),
			'provider_identity'     => (string) ( $record['provider_identity'] ?? '' ),
			'operations'            => (int) ( $record['operations'] ?? 0 ),
			'operation_budget'      => (int) ( $record['operation_budget'] ?? 0 ),
			'provider_budget'       => (int) ( $record['provider_budget'] ?? 0 ),
			'provider_request_cost' => (int) ( $record['provider_request_cost'] ?? 0 ),
		);
	}

	/**
	 * The review digest for a batch: "a human read exactly this".
	 *
	 * @param array $record A stored batch record.
	 * @return string
	 */
	public static function review_digest( array $record ): string {
		return Conexao_Translation_Automation_Apply_Gate::digest(
			array_merge( self::binding( $record ), array( 'action' => 'review' ) )
		);
	}

	/**
	 * The approval digest for a batch: "a human authorised exactly this".
	 *
	 * @param array $record A stored batch record.
	 * @return string
	 */
	public static function approval_digest( array $record ): string {
		return Conexao_Translation_Automation_Apply_Gate::digest(
			array_merge(
				self::binding( $record ),
				array(
					'action'        => 'approve_for_batch',
					'review_digest' => self::review_digest( $record ),
				)
			)
		);
	}

	/**
	 * The human review action: read this exact batch.
	 *
	 * Transitions `REVIEW_REQUIRED -> REVIEWED` and stores the review digest
	 * ON the record, so the approval can be bound to it and so a later
	 * composition change makes the stored review stale rather than silently
	 * carrying over.
	 *
	 * @param string $batch_id Batch identifier.
	 * @param array  $context {
	 *     Authorisation context.
	 *
	 *     @type bool   $authorized The caller's own authorisation decision.
	 *     @type string $capability Must equal self::CAPABILITY.
	 * }
	 * @return array|WP_Error The updated record, or a failure.
	 */
	public static function record_review( string $batch_id, array $context ) {
		$denied = self::authorise( $context );

		if ( null !== $denied ) {
			return $denied;
		}

		$record = Conexao_Translation_Automation_Batch_State::get( $batch_id );

		if ( null === $record ) {
			return new WP_Error(
				'conexao_automation_unknown_batch',
				sprintf( 'No stored batch "%s".', $batch_id )
			);
		}

		$moved = Conexao_Translation_Automation_Batch_State::transition(
			$batch_id,
			Conexao_Translation_Automation_Batch::REVIEWED
		);

		if ( is_wp_error( $moved ) ) {
			return $moved;
		}

		$moved['review_digest']     = self::review_digest( $moved );
		$moved['reviewed_at']       = gmdate( 'c' );
		$moved['review_authorized'] = true;

		$saved = Conexao_Translation_Automation_Batch_State::save( $moved );

		// The RECORD is the return value, not the storage verdict: the caller
		// needs the batch id and the state it now carries, and a bare `true`
		// would force every caller to read the store back.
		return is_wp_error( $saved ) ? $saved : $moved;
	}

	/**
	 * The human approval action: authorise this exact reviewed batch.
	 *
	 * Transitions `REVIEWED -> APPROVED_FOR_BATCH` and stores the approval
	 * digest. It refuses when the batch was never reviewed, and it refuses
	 * when the stored review digest no longer matches the batch's CURRENT
	 * composition, which is the "the record set changed after review" case.
	 *
	 * @param string $batch_id Batch identifier.
	 * @param array  $context Authorisation context, as `record_review()`.
	 * @return array|WP_Error The updated record, or a failure.
	 */
	public static function record_approval( string $batch_id, array $context ) {
		$denied = self::authorise( $context );

		if ( null !== $denied ) {
			return $denied;
		}

		$record = Conexao_Translation_Automation_Batch_State::get( $batch_id );

		if ( null === $record ) {
			return new WP_Error(
				'conexao_automation_unknown_batch',
				sprintf( 'No stored batch "%s".', $batch_id )
			);
		}

		if ( empty( $record['review_digest'] ) ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_NOT_REVIEWED,
				'This batch has never been reviewed, so it cannot be approved.'
			);
		}

		if ( ! hash_equals( (string) $record['review_digest'], self::review_digest( $record ) ) ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_REVIEW_MISMATCH,
				'The batch changed after it was reviewed; the review no longer describes it.'
			);
		}

		$moved = Conexao_Translation_Automation_Batch_State::transition(
			$batch_id,
			Conexao_Translation_Automation_Batch::APPROVED_FOR_BATCH
		);

		if ( is_wp_error( $moved ) ) {
			return $moved;
		}

		$moved['approval_digest_recorded'] = self::approval_digest( $moved );
		$moved['approved_at']              = gmdate( 'c' );
		$moved['approval_authorized']      = true;

		$saved = Conexao_Translation_Automation_Batch_State::save( $moved );

		return is_wp_error( $saved ) ? $saved : $moved;
	}

	/**
	 * Verify that a batch may execute right now.
	 *
	 * The executor calls this before every operation, not once at the start.
	 * So a batch whose composition, digests, limits, environment or state moved
	 * at any point stops at the next safe boundary rather than continuing on a
	 * stale authorisation.
	 *
	 * @param array $record The CURRENT stored record.
	 * @return true|WP_Error
	 */
	public static function verify( array $record ) {
		$state = (string) ( $record['state'] ?? '' );

		if ( Conexao_Translation_Automation_Batch::APPROVED_FOR_BATCH !== $state
			&& Conexao_Translation_Automation_Batch::EXECUTING !== $state ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_NOT_APPROVED_STATE,
				sprintf( 'A batch in state %s may not execute.', '' === $state ? '(none)' : $state )
			);
		}

		// RECOMPUTE the composition's own digests and require them to equal the
		// ones the record carries. Trusting the stored digest strings alone
		// would be exactly the substitution this gate exists to prevent: a
		// caller editing `identities` or `operation_detail` in place would
		// leave every stored digest untouched.
		$recomposed = Conexao_Translation_Automation_Batch::from_record( $record );

		if ( is_wp_error( $recomposed ) ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_APPROVAL_MISMATCH,
				sprintf(
					'The batch as presented does not hash to its own recorded digests: %s',
					(string) $recomposed->get_error_message()
				)
			);
		}

		if ( empty( $record['review_digest'] ) || ! hash_equals( (string) $record['review_digest'], self::review_digest( $record ) ) ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_REVIEW_MISMATCH,
				'The recorded review does not describe the batch as it now stands.'
			);
		}

		$recorded = (string) ( $record['approval_digest_recorded'] ?? '' );

		if ( '' === $recorded ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_APPROVAL_MISSING,
				'No batch approval was recorded for this batch.'
			);
		}

		// hash_equals: constant-time, so the comparison leaks nothing by timing.
		if ( ! hash_equals( $recorded, self::approval_digest( $record ) ) ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_APPROVAL_MISMATCH,
				'The recorded batch approval does not match the batch as it now stands; no mutation.'
			);
		}

		return true;
	}

	/**
	 * Authorisation. Returns null when authorised, or a WP_Error when not.
	 *
	 * A caller asserting a WIDER capability is refused rather than downgraded,
	 * so the failure is visible instead of silent.
	 *
	 * @param array $context Authorisation context.
	 * @return WP_Error|null
	 */
	private static function authorise( array $context ): ?WP_Error {
		if ( empty( $context['authorized'] ) || true !== $context['authorized'] ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_UNAUTHORIZED,
				'The caller is not authorised to act on a batch.'
			);
		}

		$capability = isset( $context['capability'] ) ? trim( (string) $context['capability'] ) : '';

		if ( self::CAPABILITY !== $capability ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_UNAUTHORIZED,
				'The capability assertion does not match the required capability.'
			);
		}

		return null;
	}
}
