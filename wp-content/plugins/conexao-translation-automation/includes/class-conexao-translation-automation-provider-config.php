<?php
/**
 * The provider configuration boundary (Stage 4 control B4/B5).
 *
 * ## Two halves, and only one of them is a secret
 *
 * | Half | Where it lives | Why |
 * |---|---|---|
 * | NON-SECRET configuration | this file, as code constants | the endpoint, the model, the timeout and the ceilings are FACTS about the service, and a fact that can be changed by editing a database row is not a policy |
 * | the CREDENTIAL | the process environment ONLY | Stage 0 control S7: a credential in `wp_options` is in the database, in every backup, in every replica and in every export |
 *
 * So `configuration()` returns something safe to print, store in an audit
 * record and paste into a report. `credentials()` returns the secret and is
 * consumed by exactly one caller — the transport — never by an audit writer, a
 * report builder or a test assertion.
 *
 * ## Fail closed, in every direction
 *
 *   - an unknown provider id is REFUSED. There is no default provider, because a
 *     silent default means an unconfigured site quietly translating through
 *     whatever the code happened to reach for;
 *   - a missing credential is REFUSED, not defaulted to an empty string;
 *   - an unknown configuration KEY is REFUSED. A typo in a policy name must not
 *     fall back to a default ceiling — an unbounded retry or input size is a
 *     bill, not a bug.
 *
 * ## Nothing here is persisted
 *
 * No `update_option`, no `add_option`, no constant holding a secret, no
 * `wp_remote_*` call. `credentials()` is the ONLY method in the plugin that
 * returns secret material, and a structural test proves no other consumer
 * exists.
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The provider registry: non-secret configuration plus the env-only credential.
 */
final class Conexao_Translation_Automation_Provider_Config {

	/** The one supported provider. A second is a documented choice, not a fallback. @var string */
	const PROVIDER_ID = 'openai';

	/** The model. Accepts structured JSON, fits the largest PT body, solid PT->EN. @var string */
	const MODEL_ID = 'gpt-4o-mini';

	/** A constant, not an option, so a database row cannot repoint production. @var string */
	const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

	/** Named in source, never given a value. @var string */
	const CREDENTIAL_ENV = 'CONEXAO_TRANSLATION_PROVIDER_KEY';

	/** Per-request timeout, in seconds. @var int */
	const TIMEOUT = 60;

	/**
	 * Maximum attempts for ONE record, including the first.
	 *
	 * Four attempts is three retries: enough for a transient 429 or one
	 * connection reset, not enough to spend a minute on a broken record.
	 *
	 * @var int
	 */
	const MAX_ATTEMPTS = 4;

	/** Base backoff in milliseconds, doubled per attempt. @var int */
	const BACKOFF_BASE_MS = 500;

	/** Backoff ceiling, so a bounded attempt count cannot wait forever. @var int */
	const BACKOFF_MAX_MS = 4000;

	/**
	 * The largest request body this repository will assemble, in bytes.
	 *
	 * A request above this is a malformed inventory state or a runaway, and is
	 * refused rather than sent.
	 *
	 * @var int
	 */
	const MAX_REQUEST_BYTES = 240000;

	/**
	 * The maximum number of provider requests one run may issue.
	 *
	 * The cost guard: a run that would exceed it STOPS and reports, rather than
	 * billing without limit or expanding scope to "just the rest".
	 *
	 * @var int
	 */
	const MAX_REQUESTS_PER_RUN = 50;

	/** The total wall-clock budget for one run's provider work, in seconds. @var int */
	const MAX_RUN_SECONDS = 600;

	/** The supported source language. @var string */
	const SOURCE_LANG = 'pt';

	/** The target language this provider produces. @var string */
	const TARGET_LANG = 'en';

	/**
	 * The non-secret configuration. Safe to persist, log and print.
	 *
	 * @return array<string,string|int>
	 */
	public static function configuration(): array {
		return array(
			'provider'          => self::PROVIDER_ID,
			'model'             => self::MODEL_ID,
			'endpoint'          => self::ENDPOINT,
			'source_lang'       => self::SOURCE_LANG,
			'target_lang'       => self::TARGET_LANG,
			'timeout'           => self::TIMEOUT,
			'max_attempts'      => self::MAX_ATTEMPTS,
			'backoff_base_ms'   => self::BACKOFF_BASE_MS,
			'backoff_max_ms'    => self::BACKOFF_MAX_MS,
			'max_request_bytes' => self::MAX_REQUEST_BYTES,
			'max_requests_run'  => self::MAX_REQUESTS_PER_RUN,
			'max_run_seconds'   => self::MAX_RUN_SECONDS,
			// The NAME of the variable, so an operator can discover the
			// requirement without this file ever holding its value.
			'required_env_var'  => self::CREDENTIAL_ENV,
		);
	}



	/**
	 * Is a configuration key one this boundary recognises?
	 *
	 * A closed vocabulary, checked rather than assumed, so an unknown key is a
	 * hard refusal instead of a silently ignored default.
	 *
	 * @param string $key Candidate configuration key.
	 * @return bool
	 */
	public static function is_known_key( string $key ): bool {
		return array_key_exists( $key, self::configuration() );
	}

	/**
	 * Refuse a configuration carrying a key this boundary does not define.
	 *
	 * @param array $config Candidate configuration.
	 * @return true|WP_Error
	 */
	public static function assert_known_keys( array $config ) {
		foreach ( array_keys( $config ) as $key ) {
			if ( ! self::is_known_key( (string) $key ) ) {
				return new WP_Error(
					'conexao_automation_provider_unknown_config',
					sprintf(
						'Configuration key "%s" is not defined by the provider boundary; refusing to fall back to a default.',
						(string) $key
					)
				);
			}
		}

		return true;
	}

	/**
	 * The configuration identity that participates in the request identity.
	 *
	 * Deliberately EXCLUDES anything volatile: no timestamp, no run id, no
	 * attempt counter. The request identity must be stable for an unchanged
	 * source, and a clock would break exactly that.
	 *
	 * @return array<string,string>
	 */
	public static function identity(): array {
		return array(
			'provider'    => self::PROVIDER_ID,
			'model'       => self::MODEL_ID,
			'source_lang' => self::SOURCE_LANG,
			'target_lang' => self::TARGET_LANG,
		);
	}

	/**
	 * The provider's identity token, for the Stage 3 request identity.
	 *
	 * @return string
	 */
	public static function identity_token(): string {
		return Conexao_Translation_Automation_Digest::digest( self::identity() );
	}

	/**
	 * Resolve the credential from the environment.
	 *
	 * The ONLY place in the repository that reads a secret, and the only method
	 * that returns one. It is never persisted, never echoed, and never passed to
	 * `assert_no_secrets()` — a secret is not something to *check* for absence,
	 * it is something never to hand to the checker at all.
	 *
	 * @return string|WP_Error The credential, or WP_Error when it is unusable.
	 */
	public static function credentials() {
		$env = getenv( self::CREDENTIAL_ENV );

		if ( ! is_string( $env ) || '' === trim( $env ) ) {
			return new WP_Error(
				'conexao_automation_provider_no_credential',
				sprintf(
					'The provider credential is not set in the environment. Export %s before running a provider-backed plan.',
					self::CREDENTIAL_ENV
				)
			);
		}

		return trim( $env );
	}

	/**
	 * Is a credential available? A boolean, so a caller can SKIP a live test
	 * cleanly without reading a secret it does not need.
	 *
	 * @return bool
	 */
	public static function credentials_available(): bool {
		return ! is_wp_error( self::credentials() );
	}

	/**
	 * Validate an assembled request against the non-secret ceilings.
	 *
	 * Fails closed on an oversized body, so a malformed inventory cannot become
	 * an unbounded provider bill.
	 *
	 * @param array $request The request array about to be encoded.
	 * @return true|WP_Error
	 */
	public static function assert_request_within_limits( array $request ) {
		$encoded = wp_json_encode( $request );

		if ( ! is_string( $encoded ) ) {
			return new WP_Error(
				'conexao_automation_provider_unencodable_request',
				'The provider request could not be encoded as JSON.'
			);
		}

		$bytes = strlen( $encoded );

		if ( $bytes > self::MAX_REQUEST_BYTES ) {
			return new WP_Error(
				'conexao_automation_provider_request_too_large',
				sprintf(
					'The assembled provider request is %d bytes, above the %d-byte ceiling; refusing to send it.',
					$bytes,
					self::MAX_REQUEST_BYTES
				)
			);
		}

		return true;
	}
}
