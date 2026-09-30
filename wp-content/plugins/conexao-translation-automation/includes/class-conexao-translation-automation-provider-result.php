<?php
/**
 * The validated provider result: the trust boundary.
 *
 * ## `unknown response -> hard record failure`
 *
 * This class implements that rule and nothing softer. There is no "probably
 * fine" branch, no default status, no coercion of a near-miss shape into a
 * success, and no partial acceptance. Every one of the following is a HARD
 * failure of the record:
 *
 *   - a missing translated field;
 *   - a field of the wrong type (a non-string where a string is required);
 *   - a missing source identity;
 *   - a source digest that does not match the one requested;
 *   - an unexpected target language;
 *   - an extra, unsupported key — especially anything that reads like a
 *     mutation instruction (`wp_insert_post`, `update_post_meta`,
 *     `delete_post`, `post_status`, `trash`): a provider that tries to describe
 *     a WRITE is not a translation provider, and honouring such a key would be
 *     an injection;
 *   - malformed JSON, or a non-array response;
 *   - a partial response (some requested fields missing);
 *   - a status outside the four known ones.
 *
 * ## What validation deliberately does NOT do
 *
 * It does not judge translation QUALITY. Judging English is not a mechanical
 * check, and pretending otherwise would be worse than not checking: a
 * "quality gate" that only measures string length is theatre. Quality review is
 * the human operator's step between the dry-run plan and the approval, which is
 * exactly where Stage 2 already put it.
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

/**
 * One validated (or refused) provider result.
 */
final class Conexao_Translation_Automation_Provider_Result {

	/**
	 * Result statuses.
	 *
	 * @var string
	 */
	const STATUS_SUCCESS           = 'success';
	const STATUS_RETRYABLE_FAILURE = 'retryable_failure';
	const STATUS_PERMANENT_FAILURE = 'permanent_failure';

	/**
	 * The outcome code for anything this class refuses.
	 *
	 * @var string
	 */
	const FAILURE_UNKNOWN_RESPONSE = 'unknown_response';

	/**
	 * Response keys that are permitted. Anything else is refused.
	 *
	 * @var array<int,string>
	 */
	const ALLOWED_KEYS = array(
		'status',
		'provider',
		'model',
		'request_id',
		'source_identity',
		'source_digest',
		'target_lang',
		'translations',
		'result_digest',
		'usage',
	);

	/**
	 * Keys that would be a mutation instruction if honoured.
	 *
	 * Named explicitly rather than pattern-matched, so the refusal message can
	 * say exactly what was attempted.
	 *
	 * @var array<int,string>
	 */
	const FORBIDDEN_KEYS = array(
		'wp_insert_post',
		'wp_update_post',
		'wp_delete_post',
		'update_post_meta',
		'delete_post_meta',
		'wp_insert_term',
		'wp_update_term',
		'pll_set_post_language',
		'pll_save_post_translations',
		'post_status',
		'trash',
		'untrash',
		'delete',
		'mode',
		'dry_run',
	);

	/**
	 * Build the translation context a request is made with.
	 *
	 * Everything a validator needs to prove an answer belongs to this request is
	 * here, and nothing secret is: the run id identifies a RUN, not an actor.
	 *
	 * @param array $args {
	 *     The request facts. Everything but `fields` is identity.
	 *
	 *     @type string $stage        Stage identifier.
	 *     @type string $identity     Portable PT stable key.
	 *     @type string $source_digest The PT source digest being translated.
	 *     @type string $post_type    Source post type.
	 *     @type string $run_id       Correlation id for this run.
	 *     @type array  $fields       The PT field names requested.
	 *     @type string $source_lang  Source language (always 'pt').
	 *     @type string $target_lang  Target language (always 'en').
	 *     @type string $mode         'b1' or 'b2'.
	 * }
	 * @return array|WP_Error The context, or a reason it could not be built.
	 */
	public static function context( array $args ) {
		foreach ( array( 'stage', 'identity', 'source_digest', 'post_type', 'run_id' ) as $key ) {
			if ( ! isset( $args[ $key ] ) || '' === trim( (string) $args[ $key ] ) ) {
				return new WP_Error(
					'conexao_automation_provider_context',
					sprintf( 'the translation context is missing a non-empty "%s".', $key )
				);
			}
		}

		$digest = (string) $args['source_digest'];

		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $digest ) ) {
			return new WP_Error(
				'conexao_automation_provider_context',
				'the translation context carries an impossible source digest.'
			);
		}

		$fields = isset( $args['fields'] ) ? (array) $args['fields'] : array();

		if ( array() === $fields ) {
			return new WP_Error(
				'conexao_automation_provider_context',
				'the translation context requests no fields.'
			);
		}

		$context = array(
			'stage'         => (string) $args['stage'],
			'identity'      => (string) $args['identity'],
			'source_digest' => $digest,
			'source_lang'   => isset( $args['source_lang'] ) ? (string) $args['source_lang'] : 'pt',
			'target_lang'   => isset( $args['target_lang'] ) ? (string) $args['target_lang'] : 'en',
			'fields'        => array_values( array_unique( array_map( 'strval', $fields ) ) ),
			'post_type'     => (string) $args['post_type'],
			'mode'          => isset( $args['mode'] ) ? (string) $args['mode'] : '',
			'run_id'        => (string) $args['run_id'],
		);

		$context['request_identity'] = self::request_identity( $context );

		return $context;
	}

	/**
	 * The deterministic request identity.
	 *
	 * Two requests with the same source state and the same translation
	 * configuration are the SAME logical translation target. That is what lets
	 * the layer recognise "this source has not changed" and avoid an unnecessary
	 * WordPress mutation, without requiring the provider to be deterministic in
	 * its wording.
	 *
	 * The run id and the identity of this call are EXCLUDED from the digest, so
	 * the identity is a property of the work, not of the moment it was asked
	 * for.
	 *
	 * @param array $context A context from `context()`.
	 * @return string
	 */
	public static function request_identity( array $context ): string {
		unset( $context['run_id'], $context['request_identity'] );

		ksort( $context );

		return Conexao_Translation_Automation_Digest::digest( $context );
	}

	/**
	 * Validate an UNTRUSTED provider response against the context it answers.
	 *
	 * The only successful return is a `success` result whose every requested
	 * field is present, a string, and whose identity, digest and target
	 * language all match. Everything else is refused.
	 *
	 * @param mixed $response The raw provider response.
	 * @param array $context  The context the request was made with.
	 * @return array {
	 *     @type bool   $ok        True only for a valid, complete success.
	 *     @type string $status    One of the STATUS_* constants, or
	 *                              FAILURE_UNKNOWN_RESPONSE.
	 *     @type string $reason    Explanation, always populated when not ok.
	 *     @type array  $translations Validated translations (empty when not ok).
	 *     @type array  $meta      Provider/model/request/result identity.
	 * }
	 */
	public static function validate( $response, array $context ): array {
		// --- Shape. A non-array, or a WP_Error, is not a response. -------
		if ( is_wp_error( $response ) ) {
			return self::refused(
				sprintf( 'the provider returned an error: %s', $response->get_error_message() )
			);
		}

		if ( ! is_array( $response ) ) {
			return self::refused(
				sprintf(
					'the provider returned a %s, not a response object; malformed JSON is not a translation',
					gettype( $response )
				)
			);
		}

		if ( array() === $response ) {
			return self::refused( 'the provider returned an empty response.' );
		}

		// --- Unknown keys, especially mutation instructions. -------------
		foreach ( array_keys( $response ) as $key ) {
			$key = (string) $key;

			if ( in_array( $key, self::FORBIDDEN_KEYS, true ) ) {
				return self::refused(
					sprintf(
						'the provider response carries "%s", which is a WordPress mutation instruction; a translation provider may not describe a write.',
						$key
					)
				);
			}

			if ( ! in_array( $key, self::ALLOWED_KEYS, true ) ) {
				return self::refused(
					sprintf( 'the provider response carries the unsupported key "%s".', $key )
				);
			}
		}

		// --- Status. An unknown status is a hard failure, never a default.
		$status = isset( $response['status'] ) ? strtolower( trim( (string) $response['status'] ) ) : '';

		if ( ! in_array( $status, self::known_statuses(), true ) ) {
			return self::refused(
				sprintf(
					'the provider returned the status "%s", which is not one of %s.',
					'' === $status ? '(absent)' : $status,
					implode( ', ', self::known_statuses() )
				),
				self::STATUS_RETRYABLE_FAILURE === $status ? self::STATUS_RETRYABLE_FAILURE : ''
			);
		}

		// A declared failure is a legitimate answer. It carries no
		// translations and is reported as such; it is never coerced into a
		// partial success.
		if ( self::STATUS_SUCCESS !== $status ) {
			return array(
				'ok'           => false,
				'status'       => $status,
				'reason'       => 'the provider reported a failure for this request',
				'translations' => array(),
				'meta'         => self::meta( $response, $context ),
			);
		}

		// --- Identity. A result that cannot be tied to its request is junk.
		if ( ! isset( $response['source_identity'] ) || '' === trim( (string) $response['source_identity'] ) ) {
			return self::refused( 'the provider response carries no source identity.' );
		}

		if ( (string) ( $context['identity'] ?? '' ) !== (string) $response['source_identity'] ) {
			return self::refused(
				sprintf(
					'the provider answered for source identity "%s" but "%s" was requested.',
					(string) $response['source_identity'],
					(string) ( $context['identity'] ?? '' )
				)
			);
		}

		if ( ! isset( $response['source_digest'] )
			|| (string) ( $context['source_digest'] ?? '' ) !== (string) $response['source_digest'] ) {
			return self::refused(
				'the provider response source digest does not match the digest that was requested.'
			);
		}

		if ( ! isset( $response['target_lang'] )
			|| (string) ( $context['target_lang'] ?? '' ) !== (string) $response['target_lang'] ) {
			return self::refused(
				sprintf(
					'the provider returned target language "%s", but "%s" was requested.',
					isset( $response['target_lang'] ) ? (string) $response['target_lang'] : '(absent)',
					(string) ( $context['target_lang'] ?? '' )
				)
			);
		}

		foreach ( array( 'provider', 'model', 'request_id' ) as $key ) {
			if ( ! isset( $response[ $key ] ) || '' === trim( (string) $response[ $key ] ) ) {
				return self::refused(
					sprintf( 'the provider response carries no %s.', str_replace( '_', ' ', $key ) )
				);
			}
		}

		return self::validate_translations( $response, $context );
	}

	/**
	 * Validate the `translations` payload of a success response.
	 *
	 * Strictly: every requested field present, every value a string, no
	 * unexpected field. A PARTIAL response is refused as a whole — accepting the
	 * fields that happened to arrive would publish a record whose other fields
	 * silently kept their previous, now-stale, English.
	 *
	 * @param array $response The provider response.
	 * @param array $context  The request context.
	 * @return array
	 */
	private static function validate_translations( array $response, array $context ): array {
		if ( ! isset( $response['translations'] ) || ! is_array( $response['translations'] ) ) {
			return self::refused( 'the provider response carries no translations map.' );
		}

		$translations = $response['translations'];
		$requested    = (array) ( $context['fields'] ?? array() );

		foreach ( $requested as $field ) {
			if ( ! array_key_exists( $field, $translations ) ) {
				return self::refused(
					sprintf( 'the provider response is partial: the requested field "%s" is missing.', $field )
				);
			}

			if ( ! is_string( $translations[ $field ] ) ) {
				return self::refused(
					sprintf(
						'the provider returned a %s for the field "%s"; a translation must be a string.',
						gettype( $translations[ $field ] ),
						$field
					)
				);
			}
		}

		foreach ( array_keys( $translations ) as $field ) {
			if ( ! in_array( (string) $field, $requested, true ) ) {
				return self::refused(
					sprintf( 'the provider returned the unrequested field "%s".', (string) $field )
				);
			}
		}

		$clean = array();

		foreach ( $requested as $field ) {
			$clean[ $field ] = (string) $translations[ $field ];
		}

		$meta = self::meta( $response, $context );

		// The result digest is computed HERE, by this class, from the validated
		// translations — never taken from the provider. A provider cannot
		// assert its own digest, so two runs of an unchanged source can be
		// compared honestly.
		$meta['result_digest'] = Conexao_Translation_Automation_Digest::digest(
			array(
				'request_identity' => (string) ( $context['request_identity'] ?? '' ),
				'translations'     => $clean,
			)
		);

		if ( isset( $response['result_digest'] ) && (string) $response['result_digest'] !== $meta['result_digest'] ) {
			return self::refused(
				'the provider asserted a result digest that does not match its own translations.'
			);
		}

		return array(
			'ok'           => true,
			'status'       => self::STATUS_SUCCESS,
			'reason'       => '',
			'translations' => $clean,
			'meta'         => $meta,
		);
	}

	/**
	 * The statuses a provider may declare.
	 *
	 * @return array<int,string>
	 */
	public static function known_statuses(): array {
		return array(
			self::STATUS_SUCCESS,
			self::STATUS_RETRYABLE_FAILURE,
			self::STATUS_PERMANENT_FAILURE,
		);
	}

	/**
	 * The provider/model/request identity carried on a result.
	 *
	 * Never carries usage credentials: only opaque, non-secret identifiers.
	 *
	 * @param array $response The provider response.
	 * @param array $context  The request context.
	 * @return array<string,string>
	 */
	private static function meta( array $response, array $context ): array {
		return array(
			'provider'         => isset( $response['provider'] ) ? (string) $response['provider'] : '',
			'model'            => isset( $response['model'] ) ? (string) $response['model'] : '',
			'request_id'       => isset( $response['request_id'] ) ? (string) $response['request_id'] : '',
			'source_digest'    => (string) ( $context['source_digest'] ?? '' ),
			'request_identity' => (string) ( $context['request_identity'] ?? '' ),
			'result_digest'    => '',
		);
	}

	/**
	 * A refusal: unknown response, no translations, no partial credit.
	 *
	 * @param string $reason Explanation.
	 * @param string $status Optional status to preserve instead of the default.
	 * @return array
	 */
	private static function refused( string $reason, string $status = '' ): array {
		return array(
			'ok'           => false,
			// The distinguishing rule: an unrecognised answer is a HARD record
			// failure, never a retryable "maybe later".
			'status'       => '' === $status ? self::FAILURE_UNKNOWN_RESPONSE : $status,
			'reason'       => $reason,
			'translations' => array(),
			'meta'         => array(),
		);
	}
}
