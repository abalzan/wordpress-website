<?php
/**
 * The translation provider boundary (Stage 3 control B4).
 *
 * ## THERE IS NO PROVIDER IMPLEMENTATION
 *
 * This repository contains an INTERFACE and a VALIDATOR. Nothing else. There is
 * no network client, no vendor SDK, no API key handling, no model name and no
 * translation of any kind. A structural test asserts the absence of
 * `wp_remote_*`, `curl_*` and any vendor identifier, so "no provider" is a
 * proven property rather than a promise.
 *
 * ## The two pieces and why there are two
 *
 * `Conexao_Translation_Automation_Provider_Interface` is what a future
 * implementation must satisfy. `Conexao_Translation_Automation_Provider_Result`
 * is what turns an UNTRUSTED provider answer into a trusted one. They are
 * separate because the trust boundary must not live inside the untrusted party:
 * a provider that validated its own response would be asked to grade its own
 * homework.
 *
 * ## The contract
 *
 *     translate( source payload, context ) -> provider result
 *
 * The CONTEXT is what makes a result verifiable at all. It carries the source
 * identity, the stage, the source digest, the language pair, the requested
 * fields, the content type and the run id, so a result can be checked against
 * exactly the request that produced it. The REQUEST IDENTITY is the digest of
 * that context (see `request_identity()`): the same PT source digest + the
 * same stage + the same translation configuration is the SAME logical
 * translation target, which is how the layer recognises that re-requesting an
 * unchanged source would produce no new work and must not cause a WordPress
 * write.
 *
 * Note what the contract deliberately does NOT promise: identical provider
 * WORDING across independent requests. A future provider may be
 * non-deterministic, and requiring byte-identical output would be an
 * unsatisfiable and unnecessary constraint. What is required is a deterministic
 * REQUEST IDENTITY and a deterministic RESULT DIGEST, so the automation layer
 * can tell "nothing changed, do nothing" from "this is a new translation".
 *
 * ## Result statuses
 *
 * | Status | Meaning | Automation reaction |
 * |---|---|---|
 * | `success` | every requested field was returned and validated | eligible to become a translation PLAN |
 * | `retryable_failure` | transient; the same request may be reissued later | no mutation; retriable |
 * | `permanent_failure` | will not succeed on retry | no mutation; needs a human |
 * | anything else | UNKNOWN | **hard record failure** |
 *
 * A provider result is DATA. It is never a mutation, never a plan and never
 * applied. The only route from a validated result to WordPress content is the
 * existing engine lifecycle, and reaching it still requires the Stage 2 chain
 * `lock -> dry-run -> gate -> snapshot -> approval -> apply`.
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The provider contract. Implemented by a future stage, by nobody today.
 */
interface Conexao_Translation_Automation_Provider_Interface {

	/**
	 * The provider identifier recorded in every result.
	 *
	 * @return string Non-empty, non-secret identifier.
	 */
	public static function provider_id(): string;

	/**
	 * The model identifier recorded in every result.
	 *
	 * @return string Non-empty, non-secret identifier.
	 */
	public static function model_id(): string;

	/**
	 * Translate one source payload.
	 *
	 * @param array $payload The PT source fields to translate.
	 * @param array $context The translation context (see the class docblock).
	 * @return array|WP_Error A raw provider response for the validator to judge.
	 */
	public static function translate( array $payload, array $context );
}
