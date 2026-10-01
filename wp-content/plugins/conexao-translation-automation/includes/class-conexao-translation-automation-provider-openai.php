<?php
/**
 * The OpenAI translation provider: the first real implementation (Stage 4).
 *
 * ## Why this provider, on this repository's requirements
 *
 * | Requirement | How this provider meets it |
 * |---|---|
 * | structured field translation | `response_format: json_object` makes the model RETURN the field map the Stage 3 validator demands, instead of prose that must be scraped |
 * | content length | a 128k-token context against bodies measured in the low thousands of tokens, with `MAX_REQUEST_BYTES` as the hard ceiling |
 * | HTML preservation | the model is instructed to preserve tags and Gutenberg block comments verbatim; see `HTML_POLICY` |
 * | deterministic request identity | computed from the Stage 3 digest, never from a vendor field — a provider that echoes nothing back is still bindable |
 * | API authentication | `Authorization: Bearer`, from the environment only |
 * | error classification | the HTTP status is a contract: 429/5xx retryable, other 4xx permanent, anything unrecognised unknown |
 *
 * ## The three things this class must never do
 *
 *   1. **Never write to WordPress.** It returns an array. No `wp_insert_post`,
 *      no `update_post_meta`, no `pll_*`. A structural test reads this file and
 *      proves it.
 *   2. **Never declare its own answer valid.** It returns the raw provider
 *      response in the Stage 3 contract shape; the Stage 3 VALIDATOR judges it.
 *      The validator is deliberately not reachable from here.
 *   3. **Never let its output bypass the engine.** What comes back is DATA. The
 *      only route to content is the existing engine lifecycle, behind the
 *      Stage 2 F7 chain.
 *
 * ## Transport injection
 *
 * The HTTP call goes through `dispatch()`, which prefers a replaceable static
 * transport. That is what lets the entire contract suite — timeouts, 429s,
 * malformed JSON, truncated bodies, credential absence — run deterministically
 * with no network, while the real `wp_remote_post()` remains the production
 * path.
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The concrete provider.
 */
final class Conexao_Translation_Automation_Provider_OpenAI implements Conexao_Translation_Automation_Provider_Interface {

	/**
	 * Failure categories, matching the Stage 3 result statuses.
	 *
	 * A closed vocabulary, so an unclassifiable outcome is a hard failure rather
	 * than a guess.
	 *
	 * @var string
	 */
	const CATEGORY_RETRYABLE = 'retryable_failure';
	const CATEGORY_PERMANENT = 'permanent_failure';
	const CATEGORY_INVALID   = 'invalid_response';
	const CATEGORY_UNKNOWN   = 'unknown_error';

	/**
	 * HTTP statuses that are safe to retry.
	 *
	 * Rate limiting and upstream unavailability are the provider's own contract
	 * for "try again"; a 400 or a 401 is not, because the same request fails
	 * identically and retrying it spends money to learn nothing.
	 *
	 * @var array<int,int>
	 */
	const RETRYABLE_STATUSES = array( 408, 409, 425, 429, 500, 502, 503, 504 );

	/**
	 * The exact HTML policy, because "preserve the HTML" is only useful when it
	 * is unambiguous.
	 *
	 * | Preserved VERBATIM | Translatable |
	 * |---|---|
	 * HTML tags and their attributes | the text nodes between them |
	 * Gutenberg block comments (`<!-- wp:paragraph -->`) | the words inside the block |
	 * URLs inside `href`/`src` | link anchor text |
	 * HTML entities as written | nothing — an entity is syntax |
	 *
	 * The block comments matter most here: this repository's PT bodies are
	 * Gutenberg-serialised, and a translation that drops a block comment does not
	 * merely read worse, it fails to open in the editor. That is a structural
	 * property of the output, not a matter of English taste, which is why the
	 * instruction states it as a rule.
	 *
	 * @var string
	 */
	const HTML_POLICY = 'Preserve all HTML tags, their attributes and their order exactly. '
		. 'Preserve every Gutenberg block comment such as <!-- wp:paragraph --> or <!-- /wp:paragraph --> exactly. '
		. 'Never translate, shorten, re-order or invent a tag, an attribute, a URL inside href or src, or an HTML entity. '
		. 'Translate only the human-readable text between the tags.';

	/**
	 * Replaceable transport. Test support; null restores the real HTTP call.
	 *
	 * @var callable|null
	 */
	private static $transport = null;

	/**
	 * Replace the HTTP transport. Test support.
	 *
	 * @param callable|null $transport Callable receiving ( url, args, api_key )
	 *                                  and returning a WP_Error or an array
	 *                                  with `code` and `body`.
	 * @return void
	 */
	public static function set_transport( $transport ): void {
		self::$transport = $transport;
	}

	/**
	 * The provider identifier recorded in every result.
	 *
	 * @return string
	 */
	public static function provider_id(): string {
		return Conexao_Translation_Automation_Provider_Config::PROVIDER_ID;
	}

	/**
	 * The model identifier recorded in every result.
	 *
	 * @return string
	 */
	public static function model_id(): string {
		return Conexao_Translation_Automation_Provider_Config::MODEL_ID;
	}

	/**
	 * The instruction preamble. Carries the language pair and the field list, so
	 * the provider is never asked for something the validator did not request.
	 *
	 * @param array $context Translation context.
	 * @return string
	 */
	private static function instruction( array $context ): string {
		$fields = array();

		foreach ( (array) ( $context['fields'] ?? array() ) as $field ) {
			$fields[] = (string) $field;
		}

		return sprintf(
			'You are a professional translator for a Brazilian community portal in Ireland. '
			. 'Translate the Portuguese source values into natural English for a Brazilian audience in Ireland. '
			. '%s '
			. 'Return ONLY a JSON object with exactly these keys, each mapped to the translated string: %s. '
			. 'Return no commentary, no markdown fence and no extra key. '
			. 'Do not add, remove or merge fields. Do not invent a slug, a taxonomy term or a WordPress field.',
			self::HTML_POLICY,
			implode( ', ', $fields )
		);
	}

	/**
	 * Assemble the provider request from the translation-relevant projection.
	 *
	 * NOT from the raw WordPress record. The payload is the Stage 3 projection,
	 * which by construction already excludes the EN meta key a B2 stage owns, the
	 * shared county/town taxonomies, lock metadata, audit ids, run ids and every
	 * language-neutral field. This method narrows it further to the REQUESTED
	 * fields and nothing else, so a field nobody asked about cannot reach the
	 * provider even by accident.
	 *
	 * @param array $payload PT source fields.
	 * @param array $context Translation context.
	 * @return array|WP_Error The request array, or WP_Error.
	 */
	public static function build_request( array $payload, array $context ) {
		$requested = array();

		foreach ( (array) ( $context['fields'] ?? array() ) as $field ) {
			$requested[] = (string) $field;
		}

		if ( array() === $requested ) {
			return new WP_Error(
				'conexao_automation_provider_no_fields',
				'The translation context requested no fields; refusing to send an ambiguous request.'
			);
		}

		$source = array();

		foreach ( $requested as $field ) {
			// An absent field is a caller defect, not an empty translation: the
			// validator refuses a missing field anyway, so failing here keeps
			// the failure legible instead of paying for it.
			if ( ! array_key_exists( $field, $payload ) ) {
				return new WP_Error(
					'conexao_automation_provider_field_absent',
					sprintf( 'The projection carries no field "%s".', $field )
				);
			}

			$source[ $field ] = (string) $payload[ $field ];
		}

		$config = Conexao_Translation_Automation_Provider_Config::configuration();

		return array(
			'model'           => (string) $config['model'],
			'temperature'     => 0,
			'response_format' => array( 'type' => 'json_object' ),
			'messages'        => array(
				array(
					'role'    => 'system',
					'content' => self::instruction( $context ),
				),
				array(
					'role'    => 'user',
					// The context is echoed as BINDING metadata, not as content
					// to translate. It is what makes an answer tieable to this
					// exact PT source state: digest, stage, run and request
					// identity all travel in the request, so a result can be
					// checked against the request that produced it.
					'content' => (string) wp_json_encode(
						array(
							'run_id'           => (string) ( $context['run_id'] ?? '' ),
							'stage'            => (string) ( $context['stage'] ?? '' ),
							'source_identity'  => (string) ( $context['identity'] ?? '' ),
							'source_digest'    => (string) ( $context['source_digest'] ?? '' ),
							'source_lang'      => (string) ( $context['source_lang'] ?? '' ),
							'target_lang'      => (string) ( $context['target_lang'] ?? '' ),
							'post_type'        => (string) ( $context['post_type'] ?? '' ),
							'request_identity' => (string) ( $context['request_identity'] ?? '' ),
							'fields'           => $requested,
							'source'           => $source,
						)
					),
				),
			),
		);
	}

	/**
	 * Translate one source payload, with bounded retries.
	 *
	 * Returns a RAW provider response in the Stage 3 contract shape. It is never
	 * validated here: the Stage 3 validator is the trust boundary, and a
	 * provider that graded its own homework would make that boundary decorative.
	 *
	 * @param array $payload The PT source fields to translate.
	 * @param array $context The translation context.
	 * @return array|WP_Error Raw response for the validator, or WP_Error.
	 */
	public static function translate( array $payload, array $context ) {
		$config     = Conexao_Translation_Automation_Provider_Config::configuration();
		$credential = Conexao_Translation_Automation_Provider_Config::credentials();

		if ( is_wp_error( $credential ) ) {
			// Fail closed. A missing credential is never a request with an
			// empty bearer, and never a skip that looks like a success.
			return $credential;
		}

		$request = self::build_request( $payload, $context );

		if ( is_wp_error( $request ) ) {
			return $request;
		}

		$limit = Conexao_Translation_Automation_Provider_Config::assert_request_within_limits( $request );

		if ( is_wp_error( $limit ) ) {
			return $limit;
		}

		return self::send_with_retries( $request, $context, (string) $credential, (int) $config['max_attempts'] );
	}

	/**
	 * Issue the request, retrying only what the provider's contract calls safe.
	 *
	 * Bounded by construction: `max_attempts` is a ceiling, the backoff is
	 * exponential with a cap, and nothing here can loop indefinitely. A record
	 * that exhausts its attempts is reported as the LAST failure category, not
	 * as a success and not as a partial translation.
	 *
	 * @param array  $request      The assembled request.
	 * @param array  $context      Translation context.
	 * @param string $credential   The bearer credential.
	 * @param int    $max_attempts Attempt ceiling.
	 * @return array|WP_Error
	 */
	private static function send_with_retries( array $request, array $context, string $credential, int $max_attempts ) {
		$config = Conexao_Translation_Automation_Provider_Config::configuration();
		$last   = null;

		for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
			$response = self::dispatch( $request, $credential, (int) $config['timeout'] );

			if ( is_wp_error( $response ) ) {
				$last = $response;

				// A transport failure (DNS, TLS, timeout) is provider
				// availability, which is retryable. Authentication is not.
				if ( ! self::is_retryable_transport_error( $response ) ) {
					return $response;
				}
			} else {
				$category = self::classify_status( (int) $response['code'] );

				if ( self::CATEGORY_RETRYABLE !== $category ) {
					// Success, permanent, invalid or unknown: all terminal. An
					// unknown status is a HARD failure, never a retry — guessing
					// that an unrecognised status is "probably fine" is how a
					// wrong answer reaches the engine.
					return self::finish( $response, $context, $category );
				}

				$last = $category;
			}

			if ( $attempt < $max_attempts ) {
				self::backoff( $attempt, (int) $config['backoff_base_ms'], (int) $config['backoff_max_ms'] );
			}
		}

		return self::exhausted( $last );
	}

	/**
	 * One HTTP attempt, through the injectable transport.
	 *
	 * The credential is passed as an argument and used to build the header. It
	 * is never stored in a static, never logged, and never included in a
	 * returned array or error message.
	 *
	 * @param array  $request    The assembled request.
	 * @param string $credential The bearer credential.
	 * @param int    $timeout    Timeout in seconds.
	 * @return array|WP_Error
	 */
	private static function dispatch( array $request, string $credential, int $timeout ) {
		$config = Conexao_Translation_Automation_Provider_Config::configuration();
		$args   = array(
			'method'  => 'POST',
			'timeout' => $timeout,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $credential,
			),
			'body'    => (string) wp_json_encode( $request ),
		);

		if ( is_callable( self::$transport ) ) {
			return self::normalise(
				call_user_func( self::$transport, (string) $config['endpoint'], $args, $credential )
			);
		}

		return self::normalise( wp_remote_post( (string) $config['endpoint'], $args ) );
	}

	/**
	 * Normalise whatever the transport returned into code + body, or WP_Error.
	 *
	 * @param mixed $response Transport return value.
	 * @return array|WP_Error
	 */
	private static function normalise( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! is_array( $response ) || ! isset( $response['code'] ) ) {
			return new WP_Error(
				'conexao_automation_provider_bad_transport',
				'The provider transport returned neither a response nor an error.'
			);
		}

		return array(
			'code' => (int) $response['code'],
			'body' => isset( $response['body'] ) ? (string) $response['body'] : '',
		);
	}

	/**
	 * Classify an HTTP status into a Stage 3 failure category.
	 *
	 * A status in neither list is UNKNOWN, and unknown is a hard failure.
	 *
	 * @param int $status HTTP status code.
	 * @return string One of the CATEGORY_* constants, or '' on success.
	 */
	public static function classify_status( int $status ): string {
		if ( $status >= 200 && $status < 300 ) {
			return '';
		}

		if ( in_array( $status, self::RETRYABLE_STATUSES, true ) ) {
			return self::CATEGORY_RETRYABLE;
		}

		if ( $status >= 400 && $status < 500 ) {
			// 401 and 403 are the credential cases. The provider's contract does
			// not classify re-authentication as safe to retry, so this repository
			// does not retry them either: a wrong key stays wrong.
			return self::CATEGORY_PERMANENT;
		}

		return self::CATEGORY_UNKNOWN;
	}

	/**
	 * Is a transport-level error worth retrying?
	 *
	 * @param WP_Error $error Transport error.
	 * @return bool
	 */
	private static function is_retryable_transport_error( WP_Error $error ): bool {
		$retryable = array(
			'http_request_failed',
			'http_request_timeout',
			'http_request_exceeded_redirects',
		);

		return in_array( (string) $error->get_error_code(), $retryable, true );
	}

	/**
	 * Wait before the next attempt: exponential, capped, skipped on the last.
	 *
	 * @param int $attempt Current attempt number, 1-based.
	 * @param int $base    Base backoff in milliseconds.
	 * @param int $cap     Ceiling in milliseconds.
	 * @return void
	 */
	private static function backoff( int $attempt, int $base, int $cap ): void {
		$wait = min( $base * ( 2 ** max( 0, $attempt - 1 ) ), $cap );

		if ( $wait > 0 ) {
			usleep( $wait * 1000 );
		}
	}

	/**
	 * Attempts exhausted. A hard failure carrying the last category.
	 *
	 * The underlying outcome is named in the message so an operator can tell a
	 * rate limit from a timeout from a bad key without re-running, while the
	 * ERROR CODE stays the single "attempts exhausted" category: at this point
	 * several different outcomes produced it, and collapsing them into one
	 * reportable code is what lets a caller handle it uniformly.
	 *
	 * @param mixed $last Last category or error.
	 * @return WP_Error
	 */
	private static function exhausted( $last ): WP_Error {
		$outcome = 'transport failure';

		if ( is_wp_error( $last ) ) {
			$outcome = sprintf( '%s: %s', (string) $last->get_error_code(), (string) $last->get_error_message() );
		} elseif ( is_string( $last ) ) {
			$outcome = $last;
		}

		return new WP_Error(
			'conexao_automation_provider_attempts_exhausted',
			sprintf(
				'The provider request exhausted its bounded attempts (last outcome: %s). No translation plan is produced.',
				$outcome
			)
		);
	}

	/**
	 * Turn a terminal HTTP outcome into a Stage 3 contract response.
	 *
	 * ## The rule: this method may SHAPE, never JUDGE
	 *
	 * It extracts the model's JSON and repackages it with the request's own
	 * identity. It does not decide whether the translations are complete,
	 * correct, or English — that is `Provider_Result`'s job, and this class never
	 * calls it. The echoed identity comes from the CONTEXT, not from the
	 * provider: if the provider answered about a different source, the validator
	 * still compares the two and refuses.
	 *
	 * @param array  $response Normalised code + body.
	 * @param array  $context  Translation context.
	 * @param string $category Failure category, '' on success.
	 * @return array
	 */
	private static function finish( array $response, array $context, string $category ): array {
		if ( '' !== $category ) {
			// A failure carries NO translations. There is no partial acceptance:
			// a record either has a validated translation or has none.
			return self::envelope( $category, $context, array() );
		}

		$decoded = self::decode( (string) $response['body'] );

		if ( is_wp_error( $decoded ) ) {
			// A 2xx that is not a usable payload is an INVALID RESPONSE, not a
			// success with missing fields and not a retry.
			return self::envelope( self::CATEGORY_INVALID, $context, array() );
		}

		$translations = self::extract_translations( $decoded );

		if ( is_wp_error( $translations ) ) {
			return self::envelope( self::CATEGORY_INVALID, $context, array() );
		}

		return self::envelope( 'success', $context, $translations, self::response_id( $decoded ) );
	}

	/**
	 * Build a contract response, echoing the identity from the CONTEXT.
	 *
	 * @param string $status       Result status.
	 * @param array  $context      Translation context.
	 * @param array  $translations The field map, possibly empty.
	 * @param string $request_id   The provider's opaque response id.
	 * @return array
	 */
	private static function envelope( string $status, array $context, array $translations, string $request_id = '' ): array {
		return array(
			'status'          => $status,
			'provider'        => self::provider_id(),
			'model'           => self::model_id(),
			'request_id'      => $request_id,
			// ECHOED FROM THE CONTEXT, never taken from the provider.
			'source_identity' => (string) ( $context['identity'] ?? '' ),
			'source_digest'   => (string) ( $context['source_digest'] ?? '' ),
			'target_lang'     => (string) ( $context['target_lang'] ?? '' ),
			'translations'    => $translations,
		);
	}

	/**
	 * Decode the provider envelope, failing closed on anything unexpected.
	 *
	 * The body is decoded but NEVER included in a returned error: an upstream
	 * error page can echo the request, and the request carried the source text.
	 *
	 * @param string $body Raw response body.
	 * @return array|WP_Error
	 */
	private static function decode( string $body ) {
		$trimmed = trim( $body );

		if ( '' === $trimmed ) {
			return new WP_Error(
				'conexao_automation_provider_empty_body',
				'The provider returned an empty body.'
			);
		}

		$decoded = json_decode( $trimmed, true );

		if ( ! is_array( $decoded ) ) {
			// Covers malformed JSON AND a truncated body: a truncated document
			// is not valid JSON, so it lands here and is never half-parsed.
			return new WP_Error(
				'conexao_automation_provider_malformed_body',
				'The provider response was not a JSON object.'
			);
		}

		if ( ! isset( $decoded['choices'] ) || ! is_array( $decoded['choices'] ) || array() === $decoded['choices'] ) {
			return new WP_Error(
				'conexao_automation_provider_no_choices',
				'The provider response carried no choices array.'
			);
		}

		return $decoded;
	}

	/**
	 * Pull the field map out of the first choice's message content.
	 *
	 * Returns the map UNVALIDATED. Every judgement about it belongs to the
	 * Stage 3 validator.
	 *
	 * @param array $decoded Decoded envelope.
	 * @return array|WP_Error
	 */
	private static function extract_translations( array $decoded ) {
		$choice  = $decoded['choices'][0];
		$content = isset( $choice['message']['content'] ) ? (string) $choice['message']['content'] : '';

		// Tolerate a fenced block, then require a JSON object. Anything else is
		// invalid rather than best-effort parsed.
		$content = (string) preg_replace( '/^```(?:json)?\s*/i', '', trim( $content ) );
		$content = (string) preg_replace( '/\s*```$/', '', $content );

		$parsed = json_decode( $content, true );

		if ( ! is_array( $parsed ) ) {
			return new WP_Error(
				'conexao_automation_provider_content_not_json',
				'The provider message content was not a JSON object.'
			);
		}

		return $parsed;
	}

	/**
	 * The provider's opaque response id, or '' when it carries none.
	 *
	 * @param array $decoded Decoded envelope.
	 * @return string
	 */
	private static function response_id( array $decoded ): string {
		return isset( $decoded['id'] ) && is_scalar( $decoded['id'] ) ? (string) $decoded['id'] : '';
	}
}
