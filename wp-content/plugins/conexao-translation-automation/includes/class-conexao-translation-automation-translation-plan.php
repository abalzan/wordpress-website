<?php
/**
 * The translation plan: a validated provider result, made reviewable (Stage 4).
 *
 * ## What a plan IS, and what it is emphatically NOT
 *
 * A plan is a REVIEWABLE DESCRIPTION of what the existing engine would do if a
 * human approved it. It is:
 *
 *   - pure data, built once, never mutated in place;
 *   - stamped `REVIEW_REQUIRED` at construction, with no code path that stamps
 *     anything else in Stage 4;
 *   - free of credentials, raw provider payloads and internal infrastructure.
 *
 * It is NOT a manifest, a plan executor, an apply, a WordPress object, or a
 * second engine. The plan names a target operation; the ENGINE decides create
 * vs update vs skip vs conflict, because that decision depends on live WordPress
 * state the plan cannot see.
 *
 * ## `structurally acceptable` is not `publication approved`
 *
 * The Stage 3 validator proved the SHAPE and IDENTITY of a provider answer. It
 * said nothing about whether the English is good, and this class does not
 * pretend otherwise. So every row carries an explicit review status, and the
 * only status this stage can produce is `REVIEW_REQUIRED`. A future approval
 * step is a separate, deliberate stage.
 *
 * ## Why a digest travels on every row
 *
 * A plan row is bound to the PT state it was generated from. If PT changes
 * afterwards, the plan describes a source that no longer exists — so the plan
 * carries the source digest, and the adapter re-checks it before the engine
 * ever sees the row.
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

/**
 * One reviewable translation plan.
 */
final class Conexao_Translation_Automation_Translation_Plan {

	/**
	 * The review state of every plan built in Stage 4.
	 *
	 * There is no `APPROVED` constant here on purpose: approval is not a thing
	 * this stage can do, and a constant that exists but is never set is an
	 * invitation to set it later without thinking.
	 *
	 * @var string
	 */
	const REVIEW_REQUIRED = 'REVIEW_REQUIRED';

	/**
	 * The quality boundary, stated as data.
	 *
	 * Recorded on every row so no downstream reader can mistake a validated
	 * provider response for a publication decision.
	 *
	 * @var string
	 */
	const QUALITY_STATUS = 'human_review_required';

	/**
	 * Target operations a plan row may name.
	 *
	 * These are DESCRIPTIONS of intent, resolved by the engine. The plan never
	 * decides them from live state it cannot read.
	 *
	 * @var string
	 */
	const OP_CREATE_EN   = 'create_en_record';
	const OP_UPDATE_EN   = 'update_existing_en';
	const OP_RECONCILE   = 'repair_or_reconcile';
	const OP_FIELD_WRITE = 'write_owned_en_field';

	/**
	 * The plan rows.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $rows = array();

	/**
	 * Run-scoped facts, safe to print.
	 *
	 * @var array<string,mixed>
	 */
	private $meta = array();

	/**
	 * Begin a plan for one run.
	 *
	 * @param array $meta Run facts: run_id, stage, environment, provider, model.
	 */
	public function __construct( array $meta = array() ) {
		$config = Conexao_Translation_Automation_Provider_Config::configuration();

		$this->meta = array(
			'run_id'         => (string) ( $meta['run_id'] ?? '' ),
			'stage'          => (string) ( $meta['stage'] ?? '' ),
			'environment'    => (string) ( $meta['environment'] ?? '' ),
			'provider'       => (string) ( $meta['provider'] ?? $config['provider'] ),
			'model'          => (string) ( $meta['model'] ?? $config['model'] ),
			'review_status'  => self::REVIEW_REQUIRED,
			'quality_status' => self::QUALITY_STATUS,
		);
	}

	/**
	 * Add one row from a VALIDATED provider result.
	 *
	 * @param array $validated Output of `Provider_Result::validate()`, ok = true.
	 * @param array $context   The request context the result was validated against.
	 * @return array|WP_Error The stored row, or WP_Error on a defect.
	 */
	public function add_validated( array $validated, array $context ) {
		if ( empty( $validated['ok'] ) ) {
			return new WP_Error(
				'conexao_automation_plan_not_validated',
				'Only a validated provider result may become a plan row.'
			);
		}

		$translations = (array) ( $validated['translations'] ?? array() );
		$mode         = (string) ( $context['mode'] ?? '' );

		if ( ! in_array( $mode, array( 'b1', 'b2' ), true ) ) {
			return new WP_Error(
				'conexao_automation_plan_unknown_mode',
				sprintf( 'Stage mode "%s" is neither b1 nor b2.', $mode )
			);
		}

		$row = array(
			// Portable identity. Never a local post ID as identity — the pt_id is
			// reported for an operator, not used to look anything up.
			'source_id'           => (string) ( $context['identity'] ?? '' ),
			'source_title'        => (string) ( $context['source_title'] ?? '' ),
			'stage'               => (string) ( $context['stage'] ?? '' ),
			'mode'                => $mode,
			'post_type'           => (string) ( $context['post_type'] ?? '' ),

			// The operation, described. The engine resolves it against live state.
			'operation'           => $this->operation_for( $mode, (string) ( $context['change'] ?? '' ) ),

			// What would change: the exact field map the engine would receive.
			'changed_fields'      => array_keys( $translations ),
			'translations'        => $translations,

			// Binding, reviewable identity.
			'source_digest'       => (string) ( $validated['meta']['source_digest'] ?? '' ),
			'result_digest'       => (string) ( $validated['meta']['result_digest'] ?? '' ),
			'request_identity'    => (string) ( $validated['meta']['request_identity'] ?? '' ),
			'provider'            => (string) ( $validated['meta']['provider'] ?? '' ),
			'model'               => (string) ( $validated['meta']['model'] ?? '' ),
			'provider_request_id' => (string) ( $validated['meta']['request_id'] ?? '' ),

			// The two states, both explicit and both machine-readable.
			'validation_status'   => 'validated',
			'review_status'       => self::REVIEW_REQUIRED,
			'quality_status'      => self::QUALITY_STATUS,
		);

		$this->rows[] = $row;

		return $row;
	}

	/**
	 * The operation a row describes.
	 *
	 * Derived from the Stage 3 change type where one is available, so the plan
	 * does not re-derive lifecycle semantics from live state. A B2 stage always
	 * writes a field on the SAME PT record, never a second identity — which is
	 * the B2 model's defining property, so it is stated here, not inferred later.
	 *
	 * @param string $mode   b1 or b2.
	 * @param string $change Stage 3 change type, when known.
	 * @return string One of the OP_* constants.
	 */
	public static function operation_for( string $mode, string $change ): string {
		if ( 'b2' === $mode ) {
			return self::OP_FIELD_WRITE;
		}

		if ( 'modified' === $change ) {
			return self::OP_UPDATE_EN;
		}

		if ( 'restored' === $change ) {
			return self::OP_RECONCILE;
		}

		return self::OP_CREATE_EN;
	}

	/**
	 * The rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function rows(): array {
		return $this->rows;
	}

	/**
	 * The rows for one stage, keyed by portable identity.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function rows_by_identity(): array {
		$indexed = array();

		foreach ( $this->rows as $row ) {
			$indexed[ (string) $row['source_id'] ] = $row;
		}

		return $indexed;
	}

	/**
	 * The run-scoped facts.
	 *
	 * @return array<string,mixed>
	 */
	public function meta(): array {
		return $this->meta;
	}

	/**
	 * How many rows the plan holds.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->rows );
	}

	/**
	 * The plan's machine-readable summary.
	 *
	 * Deliberately carries NO translated content: a summary is what goes in a
	 * report or a commit, and full bodies belong in a reviewable artifact the
	 * operator opens, not in a log line that gets pasted into a chat.
	 *
	 * @return array<string,mixed>
	 */
	public function summary(): array {
		$fields = array();

		foreach ( $this->rows as $row ) {
			foreach ( (array) $row['changed_fields'] as $field ) {
				$fields[ (string) $field ] = true;
			}
		}

		return array(
			'run_id'         => (string) $this->meta['run_id'],
			'stage'          => (string) $this->meta['stage'],
			'environment'    => (string) $this->meta['environment'],
			'provider'       => (string) $this->meta['provider'],
			'model'          => (string) $this->meta['model'],
			'records'        => count( $this->rows ),
			'changed_fields' => array_keys( $fields ),
			'review_status'  => self::REVIEW_REQUIRED,
			'quality_status' => self::QUALITY_STATUS,
		);
	}

	/**
	 * The review artifact: the plan, in a shape an operator can actually review.
	 *
	 * Includes the translated field values, because a reviewer cannot judge
	 * English from field NAMES. It excludes the credential, the authorization
	 * header, the raw provider envelope and the request body — the reviewer needs
	 * the ANSWER, not the transport.
	 *
	 * @return array<string,mixed>
	 */
	public function to_review_artifact(): array {
		$clean = Conexao_Translation_Automation_Result::assert_no_secrets(
			array(
				'summary' => $this->summary(),
				'rows'    => $this->rows,
			)
		);

		// A plan that cannot be shown to a human is not reviewable, and a plan
		// carrying a credential must never be written anywhere at all. So a
		// secret-shaped plan is REFUSED, not sanitised into something reviewable.
		return is_wp_error( $clean )
			? array( 'error' => 'refused: the plan carries secret-shaped material and was not rendered' )
			: $clean;
	}
}
