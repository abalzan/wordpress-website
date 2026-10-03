<?php
/**
 * The expansion gate: evidence, never authorisation (Stage 10).
 *
 * ## What this class decides
 *
 * Given the record of a completed batch, is there EVIDENCE that a larger batch
 * is defensible? That is a question about the past, and this class answers
 * only that.
 *
 * ## What this class deliberately does NOT decide
 *
 * Whether the next batch may run. It cannot. It returns `eligible: true` or
 * `eligible: false` plus the list of unmet requirements, and then it stops.
 *
 * There is no code path anywhere in this plugin that turns eligibility into
 * execution. The next batch must be composed, reviewed and approved by a human,
 * and the level is a number a human supplies. That is what makes "canary passed
 * -> use maximum batch" and "batch verified -> start the next batch"
 * structurally impossible rather than merely discouraged.
 *
 * ## The required evidence
 *
 * | Requirement | Why it is required |
 * |---|---|
 * | the previous batch is `VERIFIED` | an unverified predecessor proves nothing |
 * | zero unexpected PT mutations | PT is canonical; an EN change that moved PT is a policy breach |
 * | zero unexpected EN mutations | a mutation nobody approved is the whole failure this program exists to prevent |
 * | route verification succeeded where relevant | an EN record that 404s is not a translation |
 * | idempotence succeeded | a re-run must be a no-op, or the engine is not safe to repeat |
 * | audit complete | an unrecorded batch cannot be reviewed by anyone |
 * | provider error rate within bounds | a high error rate means the next batch will fail the same way |
 * | no unresolved verification failure | an unresolved failure is not evidence of anything |
 *
 * Every requirement is reported individually, so a refusal names WHICH piece of
 * evidence is missing rather than a generic "not eligible".
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The expansion evidence gate.
 */
final class Conexao_Translation_Automation_Batch_Expansion {

	/**
	 * The provider error-rate ceiling, as a fraction of attempted requests.
	 *
	 * A quarter. Above that, the next batch would be expected to fail at the
	 * same rate, so the evidence does not support expanding.
	 *
	 * @var float
	 */
	const MAX_PROVIDER_ERROR_RATE = 0.25;

	/**
	 * Evaluate the evidence from a completed batch.
	 *
	 * @param array $previous The predecessor batch's stored record.
	 * @param array $evidence Invocation facts, described below.
	 * @return array {
	 *     @type bool  $eligible   True only when EVERY requirement holds.
	 *     @type array $requirements name => bool, for the audit.
	 *     @type array $unmet       The names of the unmet requirements.
	 *     @type float $error_rate  The observed provider error rate.
	 * }
	 */
	public static function evaluate( array $previous, array $evidence ): array {
		$state = (string) ( $previous['state'] ?? '' );

		$pt          = isset( $evidence['unexpected_pt_mutations'] ) ? (int) $evidence['unexpected_pt_mutations'] : -1;
		$en          = isset( $evidence['unexpected_en_mutations'] ) ? (int) $evidence['unexpected_en_mutations'] : -1;
		$routes      = ! empty( $evidence['route_verification_ok'] );
		$idempotence = ! empty( $evidence['idempotence_ok'] );
		$audit       = ! empty( $evidence['audit_complete'] );
		$attempts    = isset( $evidence['provider_attempts'] ) ? (int) $evidence['provider_attempts'] : 0;
		$errors      = isset( $evidence['provider_errors'] ) ? (int) $evidence['provider_errors'] : 0;
		$unresolved  = isset( $evidence['unresolved_verification_failures'] ) ? (int) $evidence['unresolved_verification_failures'] : 0;

		$error_rate = $attempts > 0 ? ( $errors / max( 1, $attempts ) ) : 0.0;

		$requirements = array(
			// A missing count reads as -1 above, so an absent measurement can
			// never be mistaken for a zero.
			'previous_batch_verified'            => Conexao_Translation_Automation_Batch::VERIFIED === $state,
			'zero_unexpected_pt_mutations'       => 0 === $pt,
			'zero_unexpected_en_mutations'       => 0 === $en,
			'route_verification_ok'              => $routes,
			'idempotence_ok'                     => $idempotence,
			'audit_complete'                     => $audit,
			'provider_error_rate_ok'             => $error_rate <= self::MAX_PROVIDER_ERROR_RATE,
			'no_unresolved_verification_failure' => 0 === $unresolved,
		);

		$unmet = array();

		foreach ( $requirements as $name => $ok ) {
			if ( true !== $ok ) {
				$unmet[] = $name;
			}
		}

		return array(
			'eligible'     => array() === $unmet,
			'requirements' => $requirements,
			'unmet'        => $unmet,
			'error_rate'   => $error_rate,
		);
	}

	/**
	 * The next level up from a level, or the same level at the ceiling.
	 *
	 * Returns a NUMBER a human may then type. It does not set anything, does
	 * not create a batch and does not authorise anything.
	 *
	 * @param string $level The current level.
	 * @return string The next level identifier, or '' at the ceiling.
	 */
	public static function next_level( string $level ): string {
		$ids = Conexao_Translation_Automation_Batch_Limits::level_ids();

		foreach ( $ids as $index => $id ) {
			if ( $id === $level && isset( $ids[ $index + 1 ] ) ) {
				return $ids[ $index + 1 ];
			}
		}

		return '';
	}
}
