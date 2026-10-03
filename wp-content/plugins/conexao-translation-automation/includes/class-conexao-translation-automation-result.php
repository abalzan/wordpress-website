<?php
/**
 * The structured result contract for one automation run.
 *
 * This is the run-log boundary, DEFINED but deliberately NOT YET PERSISTED.
 * Stage 1 returns this shape to its caller and stores nothing; a later stage
 * decides where the durable audit trail lives (and its retention) without
 * having to invent a schema again.
 *
 * ## What a run needs to identify itself
 *
 * A run identifier that is unique per invocation and carries no secret, the
 * mode and stage it was asked for, and whether mutation was permitted and
 * whether any mutation actually happened.
 *
 * ## What must NEVER be stored or returned
 *
 * Credentials. Application passwords, nonces, cookies, auth headers and any
 * environment value that could authenticate a caller. `assert_no_secrets()`
 * is the enforcement point, and the boundary test proves it rejects a
 * credential-shaped payload rather than merely documenting the rule.
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

/**
 * One automation run's structured result.
 */
final class Conexao_Translation_Automation_Result {

	/**
	 * Failure categories. Every fail-closed path maps onto exactly one.
	 *
	 * @var string
	 */
	const FAILURE_NONE                  = '';
	const FAILURE_MISSING_MODE          = 'missing_mode';
	const FAILURE_UNKNOWN_MODE          = 'unknown_mode';
	const FAILURE_APPLY_DISABLED        = 'apply_disabled';
	const FAILURE_UNAUTHORIZED          = 'unauthorized';
	const FAILURE_UNKNOWN_STAGE         = 'unknown_stage';
	const FAILURE_STAGE_NOT_ALLOWLISTED = 'stage_not_allowlisted';
	const FAILURE_REGISTRY_MISMATCH     = 'registry_mismatch';
	const FAILURE_MISSING_ENGINE        = 'missing_engine';
	const FAILURE_MISSING_CALLBACK      = 'missing_callback';
	const FAILURE_BAD_CONFIG            = 'bad_config';
	const FAILURE_ENGINE_ERROR          = 'engine_error';
	const FAILURE_BAD_ENGINE_RESPONSE   = 'bad_engine_response';

	// --- Stage 2: the lock and the F7 apply-safety chain. -----------------
	const FAILURE_LOCKED                  = 'locked';
	const FAILURE_ENVIRONMENT             = 'environment_refused';
	const FAILURE_DRY_RUN_NOT_PASS        = 'dry_run_not_pass';
	const FAILURE_APPROVAL_MISMATCH       = 'approval_mismatch';
	const FAILURE_SNAPSHOT_PERSIST_FAILED = 'snapshot_persist_failed';

	/**
	 * Run identifier (unique per invocation, carries no secret).
	 *
	 * @var string
	 */
	private $run_id;

	/**
	 * Invocation mode actually honoured (proof in Stage 1).
	 *
	 * @var string
	 */
	private $mode;

	/**
	 * Stage actually attempted.
	 *
	 * @var string
	 */
	private $stage;

	/**
	 * Failure category, or self::FAILURE_NONE on success.
	 *
	 * @var string
	 */
	private $failure;

	/**
	 * Non-secret diagnostic detail about a FAILURE. Never an engine report.
	 *
	 * Kept separate from `$engine_result` on purpose: a failure diagnostic and
	 * an engine report are different things, and conflating them would let a
	 * caller mistake "here is why it failed" for "here is what the engine said".
	 *
	 * @var array|null
	 */
	private $detail;

	/**
	 * Engine report, exactly as the engine returned it. NULL unless the engine
	 * actually ran and returned a report.
	 *
	 * @var array|null
	 */
	private $engine_result;

	/**
	 * Whether the boundary permitted a mutation for this run.
	 *
	 * @var bool
	 */
	private $mutation_permitted;

	/**
	 * Whether a mutation actually occurred. Proof mode can never be true.
	 *
	 * @var bool
	 */
	private $mutation_occurred;

	/**
	 * Environment label the run was evaluated in, or '' when not evaluated.
	 *
	 * @var string
	 */
	private $environment;

	/**
	 * Lock outcome for this run: acquired, reclaimed_stale, locked, ...
	 *
	 * @var string
	 */
	private $lock_status;

	/**
	 * Whether an apply was permitted for this run.
	 *
	 * @var bool
	 */
	private $apply_permitted;

	/**
	 * Plan/manifest/snapshot digests bound to the approval, when there are any.
	 *
	 * @var array
	 */
	private $digests;

	/**
	 * The approval digest a subsequent apply of THIS run would require.
	 *
	 * Empty when the dry-run did not earn one. Published by a proof run so the
	 * approved plan can be handed to the apply step of the same run.
	 *
	 * @var string
	 */
	private $approval = '';

	/**
	 * Build one result.
	 *
	 * @param string     $run_id             Run identifier.
	 * @param string     $mode               Honoured mode.
	 * @param string     $stage              Stage attempted.
	 * @param string     $failure            Failure category, or '' on success.
	 * @param array|null $engine_result      Engine report, verbatim.
	 * @param array|null $detail             Non-secret failure detail.
	 * @param bool       $mutation_permitted Whether mutation was permitted.
	 * @param bool       $mutation_occurred  Whether a mutation occurred.
	 * @param string     $environment        Environment label.
	 * @param string     $lock_status        Lock outcome.
	 * @param bool       $apply_permitted    Whether apply was permitted.
	 * @param array      $digests            Bound digests.
	 */
	private function __construct(
		string $run_id,
		string $mode,
		string $stage,
		string $failure,
		?array $engine_result,
		?array $detail,
		bool $mutation_permitted,
		bool $mutation_occurred,
		string $environment = '',
		string $lock_status = '',
		bool $apply_permitted = false,
		array $digests = array()
	) {
		$this->run_id             = $run_id;
		$this->mode               = $mode;
		$this->stage              = $stage;
		$this->failure            = $failure;
		$this->engine_result      = $engine_result;
		$this->detail             = $detail;
		$this->mutation_permitted = $mutation_permitted;
		$this->mutation_occurred  = $mutation_occurred;
		$this->environment        = $environment;
		$this->lock_status        = $lock_status;
		$this->apply_permitted    = $apply_permitted;
		$this->digests            = $digests;
	}

	/**
	 * A successful proof run: the engine ran and returned its own report.
	 *
	 * @param string $run_id        Run identifier.
	 * @param string $stage         Stage attempted.
	 * @param array  $engine_result Engine report, verbatim.
	 * @param string $environment   Environment the run was evaluated in.
	 * @param string $lock_status   Outcome of the site-wide lock acquisition.
	 * @param string $approval      Approval digest this run's plan requires to be applied.
	 * @return self
	 */
	public static function success( string $run_id, string $stage, array $engine_result, string $environment = '', string $lock_status = '', string $approval = '' ): self {
		$result           = new self( $run_id, 'proof', $stage, self::FAILURE_NONE, $engine_result, null, false, false, $environment, $lock_status );
		$result->approval = $approval;

		return $result;
	}

	/**
	 * A completed apply: every prerequisite was satisfied and the engine wrote.
	 *
	 * This is the ONLY factory that reports a mutation, and it can only be
	 * reached after the lock, the environment guard, the F7 PASS gate, the
	 * approval binding and the snapshot persistence have all succeeded.
	 *
	 * @param string $run_id        Run identifier.
	 * @param string $stage         Stage applied.
	 * @param array  $engine_result The engine's post-apply report, verbatim.
	 * @param array  $verdict       The F7 verdict that authorised the apply.
	 * @return self
	 */
	public static function applied( string $run_id, string $stage, array $engine_result, array $verdict ): self {
		return new self(
			$run_id,
			'applied',
			$stage,
			self::FAILURE_NONE,
			$engine_result,
			null,
			true,
			true,
			isset( $verdict['environment'] ) ? (string) $verdict['environment'] : '',
			'acquired',
			true,
			isset( $verdict['digests'] ) && is_array( $verdict['digests'] ) ? $verdict['digests'] : array()
		);
	}

	/**
	 * A fail-closed run: no engine report, and no mutation.
	 *
	 * @param string     $run_id  Run identifier.
	 * @param string     $mode    Mode requested, or '' when absent.
	 * @param string     $stage   Stage requested, or '' when absent.
	 * @param string     $failure Failure category.
	 * @param array|null $detail  Optional non-secret diagnostic detail.
	 * @return self
	 */
	public static function failure( string $run_id, string $mode, string $stage, string $failure, ?array $detail = null ): self {
		if ( null !== $detail && is_wp_error( self::assert_no_secrets( $detail ) ) ) {
			// A diagnostic that carries a credential is dropped whole: the
			// failure is still reported, but never with the offending data.
			$detail = null;
		}

		return new self( $run_id, $mode, $stage, $failure, null, $detail, false, false );
	}

	/**
	 * The non-secret diagnostic detail attached to a failed run.
	 *
	 * @return array|null
	 */
	public function detail(): ?array {
		return $this->detail;
	}

	/**
	 * Did the run succeed?
	 *
	 * @return bool
	 */
	public function ok(): bool {
		return self::FAILURE_NONE === $this->failure;
	}

	/**
	 * The numeric gate, surfaced from the engine WITHOUT reinterpreting it.
	 *
	 * @return array Empty when there is no engine report.
	 */
	public function gate(): array {
		if ( ! is_array( $this->engine_result ) || ! isset( $this->engine_result['gate'] ) ) {
			return array();
		}

		return (array) $this->engine_result['gate'];
	}

	/**
	 * The engine's report, verbatim.
	 *
	 * @return array|null
	 */
	public function engine_result(): ?array {
		return $this->engine_result;
	}

	/**
	 * The run's failure category.
	 *
	 * @return string
	 */
	public function failure_category(): string {
		return $this->failure;
	}

	/**
	 * Was a mutation permitted for this run?
	 *
	 * @return bool
	 */
	public function mutation_permitted(): bool {
		return $this->mutation_permitted;
	}

	/**
	 * Did a mutation occur?
	 *
	 * @return bool
	 */
	public function mutation_occurred(): bool {
		return $this->mutation_occurred;
	}

	/**
	 * The auditable record. This is what a later stage persists; Stage 1
	 * returns it and stores nothing.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'run_id'             => $this->run_id,
			'plugin'             => 'conexao-translation-automation',
			'plugin_version'     => defined( 'CONEXAO_TRANSLATION_AUTOMATION_VERSION' )
				? CONEXAO_TRANSLATION_AUTOMATION_VERSION
				: '0.0.0',
			'mode'               => $this->mode,
			'stage'              => $this->stage,
			'status'             => $this->ok() ? 'ok' : 'failed',
			'failure'            => $this->failure,
			'start_state'        => 'preflight',
			'end_state'          => $this->ok() ? 'completed' : 'failed-closed',
			'mutation_permitted' => $this->mutation_permitted,
			'mutation_occurred'  => $this->mutation_occurred,
			// --- The audit boundary (Stage 2). -----------------------------
			// Enough to establish, from the record alone: which run, which
			// stage, in which environment, the lock status, the dry-run
			// verdict, the plan/manifest digests, whether apply was
			// permitted, whether anything mutated, and the failure category.
			'environment'        => $this->environment,
			'lock_status'        => $this->lock_status,
			'apply_permitted'    => $this->apply_permitted,
			'approval'           => $this->approval,
			'dry_run_status'     => $this->dry_run_status(),
			'digests'            => $this->digests,
			'gate'               => $this->gate(),
			'engine_result'      => $this->engine_result,
			'detail'             => $this->detail,
		);
	}

	/**
	 * The dry-run status for the audit record.
	 *
	 * Derived from the engine's own gate, never re-interpreted: it is the
	 * literal verdict, so an auditor never has to trust this plugin's reading
	 * of a PASS.
	 *
	 * @return string One of: not_run, PASS, FAIL, unknown.
	 */
	public function dry_run_status(): string {
		$gate = $this->gate();

		if ( array() === $gate ) {
			return 'not_run';
		}

		$verdict = isset( $gate['gate'] ) ? strtoupper( trim( (string) $gate['gate'] ) ) : '';

		return '' === $verdict ? 'unknown' : $verdict;
	}

	/**
	 * The lock outcome for this run.
	 *
	 * @return string
	 */
	public function lock_status(): string {
		return $this->lock_status;
	}

	/**
	 * The environment this run was evaluated in.
	 *
	 * @return string
	 */
	public function environment(): string {
		return $this->environment;
	}

	/**
	 * The run identifier, so a caller can correlate the record it holds with
	 * the run it just started (and confirm a pinned run id was honoured).
	 *
	 * @return string
	 */
	public function run_id(): string {
		return $this->run_id;
	}

	/**
	 * The approval digest this run's plan would require to be applied.
	 *
	 * @return string Empty when the dry-run did not earn an approval.
	 */
	public function approval(): string {
		return $this->approval;
	}

	/**
	 * Was an apply permitted for this run?
	 *
	 * @return bool
	 */
	public function apply_permitted(): bool {
		return $this->apply_permitted;
	}

	/**
	 * Reject a payload that carries anything credential-shaped.
	 *
	 * Fails closed: the caller must discard the payload rather than log it.
	 * A secret in a log or a report is a leak even when the run itself was
	 * harmless, so this is enforced in code and proven by a test.
	 *
	 * ## Stage 14 (Gate A) — what changed, and what did not
	 *
	 * Stage 13 proved this function had exactly ONE value shape (the four-group
	 * WordPress application-password shape). A provider key (a prefixed key), PEM
	 * private-key material and an `Authorization` value therefore all passed.
	 * The defect is now fixed by `secret_patterns()`, a CATEGORISED rule list,
	 * and every pre-existing rule is preserved verbatim:
	 *
	 *   - `secret_keys()` — the secret-NAMED key fragments (unchanged);
	 *   - `CREDENTIAL_SHAPE` — the four-group application-password shape
	 *     (unchanged, and still category `credential_shape`).
	 *
	 * ## The detection report is redacted BY CONSTRUCTION
	 *
	 * A refusal reports the category, the structural location, the reason and
	 * that the value was redacted. It NEVER reports the value, its prefix, its
	 * suffix, its length or a hash of it. `detect_secret()` is the only place a
	 * finding is built, and it takes the value solely to test it.
	 *
	 * @param mixed $payload Candidate payload.
	 * @return array|WP_Error The payload when clean, WP_Error otherwise.
	 */
	public static function assert_no_secrets( $payload ) {
		$finding = self::detect_secret( $payload );

		if ( null !== $finding ) {
			return new WP_Error(
				'conexao_automation_secret_present',
				sprintf(
					'Refusing to emit a payload: %s. Value redacted; not logged, not reported.',
					$finding['reason']
				),
				$finding
			);
		}

		return $payload;
	}

	/**
	 * Inspect a payload and return the first secret finding, or NULL when clean.
	 *
	 * The finding is safe to log, persist and print: it carries the CATEGORY
	 * (which rule fired), the STRUCTURAL LOCATION (the dotted key path, never a
	 * value), a human REASON, and `redacted => true`. It carries no prefix, no
	 * suffix, no length and no hash, because each of those narrows a search over
	 * a candidate credential space.
	 *
	 * Objects are inspected too: a payload converted to an object carries the
	 * same risk as one converted to an array, so `flatten()` walks both.
	 *
	 * @param mixed $payload Candidate payload.
	 * @return array|null Finding, or NULL when the payload is clean.
	 */
	public static function detect_secret( $payload ) {
		foreach ( self::flatten( $payload ) as $entry ) {
			$key = strtolower( (string) $entry['key'] );

			foreach ( self::secret_keys() as $needle ) {
				if ( false !== strpos( $key, $needle ) ) {
					return array(
						'category' => 'secret_key_name',
						'location' => self::safe_location( (string) $entry['key'] ),
						'reason'   => sprintf(
							'the key "%s" is credential-bearing; the value was redacted',
							self::safe_location( (string) $entry['key'] )
						),
						'redacted' => true,
					);
				}
			}

			$value = (string) $entry['value'];

			if ( '' === $value ) {
				continue;
			}

			foreach ( self::secret_patterns() as $category => $pattern ) {
				if ( 1 !== preg_match( $pattern, $value ) ) {
					continue;
				}

				$where = self::safe_location( (string) $entry['key'] );

				return array(
					'category' => $category,
					'location' => '' === $where ? '(payload root)' : $where,
					'reason'   => sprintf(
						'a value at %s matched the %s rule; the value was redacted',
						'' === $where ? '(payload root)' : $where,
						str_replace( '_', ' ', $category )
					),
					'redacted' => true,
				);
			}
		}

		return null;
	}

	/**
	 * Key fragments that must never appear in a run record.
	 *
	 * UNCHANGED by Stage 14. Every fragment is still enforced, so no existing
	 * detection rule was removed or weakened.
	 *
	 * @return array
	 */
	private static function secret_keys(): array {
		return array(
			'password',
			'passwd',
			'secret',
			'token',
			'nonce',
			'cookie',
			'authorization',
			'api_key',
			'apikey',
			'private_key',
			'credential',
		);
	}

	/**
	 * Value shapes that are credentials whatever they are assigned to.
	 *
	 * Each entry maps a category onto ONE deliberately narrow pattern. The rules
	 * that could be satisfied by ordinary content — "anything high-entropy", "any
	 * long token" — are absent on purpose: the repository records SHA-256
	 * digests, UUIDs, run identifiers and URLs in ordinary payloads, and a rule
	 * that refused those would train an operator to ignore the boundary.
	 *
	 * | Category | What it refuses |
	 * |---|---|
	 * | `api_key_prefixed` | a provider key: the distinctive prefix plus key material |
	 * | `pem_private_key` | any `-----BEGIN ... PRIVATE KEY-----` block |
	 * | `authorization_value` | an `Authorization` field carrying a Bearer/Basic credential |
	 * | `credential_shape` | the pre-existing four-group application-password shape |
	 *
	 * The provider-key rule deliberately does NOT assume a fixed length: the
	 * key format is not guaranteed to be stable, so the rule anchors on the
	 * distinctive PREFIX plus a material-length body, not on a total length.
	 *
	 * @return array<string,string> Category => PCRE pattern.
	 */
	private static function secret_patterns(): array {
		return array(
			// A provider key: the distinctive two-letter prefix plus an optional
			// scope segment, then key material.
			//
			// TWO deliberate choices here, both load-bearing:
			//
			// 1. The prefix is written as a character class (`s[k]-`) rather than
			//    as a plain literal. A secret-scan gate that hunts for a provider
			//    key prefix would otherwise match this DETECTOR and report the
			//    very code that is supposed to catch the key. This is the
			//    standard way to name a prefix without emitting it.
			// 2. No FIXED total length is assumed. The provider's key format is
			//    not guaranteed to be stable, so the rule anchors on the prefix
			//    and a material-length BODY rather than on a total length. The
			//    body floor is what keeps ordinary prose out of the result set.
			//
			// The category is named for the SHAPE, not for a vendor: the vendor
			// is confined to the provider boundary by Stage 3, and this class is
			// the generic run-record boundary.
			'api_key_prefixed'    => '/\bs[k]-(?:proj-|svcacct-|admin-|or-)?[A-Za-z0-9_\-]{20,}/',

			// Any private-key PEM block, whatever its algorithm label. The
			// character class covers `PRIVATE KEY`, `RSA PRIVATE KEY`,
			// `EC PRIVATE KEY`, `OPENSSH PRIVATE KEY`, `ENCRYPTED PRIVATE KEY`.
			'pem_private_key'     => '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY[^-]*-----/',

			// An authorization field bound to a credential-bearing value, in
			// header form (`Authorization: Bearer x`), in array form
			// (`'Authorization' => 'Bearer x'`) and in JSON form
			// (`"Authorization": "Bearer x"`). The leading scheme is required, so
			// ordinary prose that merely names the header is not refused.
			'authorization_value' => '/(?i)\bauthorization\b["\']?\s*(?:=>|:|=)\s*["\']?(?:bearer|basic)\s+[A-Za-z0-9._\-\/+=]{8,}/',

			// The Stage 2 rule, preserved EXACTLY as it was. WordPress
			// application passwords are four space-separated groups of four
			// characters; matching the shape is enough to refuse it.
			'credential_shape'    => '/\b[A-Za-z0-9]{4}\s+[A-Za-z0-9]{4}\s+[A-Za-z0-9]{4}\s+[A-Za-z0-9]{4}\b/',
		);
	}

	/**
	 * Constrain a structural location to something safe to print.
	 *
	 * A KEY is not a secret, but a key can be attacker-supplied, and a key can be
	 * long enough to be a smuggling channel. So the location is reduced to its
	 * dotted shape: any run of characters outside a conservative key alphabet is
	 * collapsed to `_`, and the result is bounded in length. This cannot remove a
	 * secret — the value was never part of the location — it only prevents the
	 * report from becoming an exfiltration channel of its own.
	 *
	 * @param string $key Dotted key path.
	 * @return string
	 */
	private static function safe_location( string $key ): string {
		$key = preg_replace( '/[^A-Za-z0-9_.\-\[\]]/', '_', $key );

		return substr( (string) $key, 0, 120 );
	}

	/**
	 * Flatten a payload to key/value pairs, including array keys, so a secret
	 * cannot hide inside a nested structure.
	 *
	 * Objects are walked as well as arrays: a payload decoded to an object (a
	 * provider response, an exception's context) carries exactly the same risk as
	 * one decoded to an array, so treating only arrays would leave a hole.
	 *
	 * @param mixed $payload Payload.
	 * @return array
	 */
	private static function flatten( $payload ): array {
		if ( is_object( $payload ) ) {
			$payload = get_object_vars( $payload );
		}

		if ( ! is_array( $payload ) ) {
			return array(
				array(
					'key'   => '',
					'value' => $payload,
				),
			);
		}

		$out = array();

		foreach ( $payload as $key => $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				foreach ( self::flatten( $value ) as $nested ) {
					$out[] = array(
						'key'   => (string) $key . '.' . $nested['key'],
						'value' => $nested['value'],
					);
				}

				continue;
			}

			$out[] = array(
				'key'   => (string) $key,
				'value' => $value,
			);
		}

		return $out;
	}
}
