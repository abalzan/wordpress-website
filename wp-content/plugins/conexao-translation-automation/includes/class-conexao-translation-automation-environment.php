<?php
/**
 * The production/environment guard for the apply-capable path.
 *
 * ## Why this exists
 *
 * `if ( $is_production ) { apply(); }` is not a guard, it is a hazard. It
 * makes the DANGEROUS branch the one that runs automatically, and it treats an
 * absent, misspelled or contradictory environment label as "not production",
 * which silently downgrades the decision.
 *
 * This class inverts that. Apply is refused unless the caller affirmatively
 * declares BOTH:
 *
 *   1. the environment it believes it is running in, AND
 *   2. an explicit production authorisation.
 *
 * and unless that declaration AGREES with what WordPress itself reports.
 *
 * ## The rules
 *
 * | Situation | Outcome |
 * |---|---|
 * | `environment` absent or not a known value | `environment_unknown` — fail closed |
 * | caller says `local`/`test`, WordPress says `production` | `environment_mismatch` — fail closed |
 * | caller says `production`, WordPress says `local`/`test` | `environment_mismatch` — fail closed |
 * | `environment` says non-production but `production_authorized` is true | `environment_contradictory` — fail closed |
 * | `environment` says production but `production_authorized` is not true | `production_not_authorized` — fail closed |
 * | everything agrees and is non-production | permitted for LOCAL testing only |
 * | everything agrees and is production with authorisation | permitted, still gated by F7 |
 *
 * Two independent signals must agree. The caller's declaration alone is never
 * enough, because a bug or a spoofed context key must not be able to talk the
 * plugin into believing it is somewhere it is not.
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The environment guard.
 */
final class Conexao_Translation_Automation_Environment {

	/**
	 * Environments this plugin recognises.
	 *
	 * @var array
	 */
	const KNOWN = array( 'local', 'test', 'staging', 'production' );

	/**
	 * The WordPress-side signal, overridable so the guard is testable.
	 *
	 * @var callable|null
	 */
	private static $site_detector = null;

	/**
	 * Test support: replace the site environment detector.
	 *
	 * @param callable|null $detector Callable returning a string.
	 * @return void
	 */
	public static function set_site_detector( $detector ): void {
		self::$site_detector = $detector;
	}

	/**
	 * Decide whether an apply may be attempted in this environment.
	 *
	 * @param array $context Invocation context.
	 * @return array {
	 *     @type bool   $permitted True only when the environment is unambiguous.
	 *     @type string $failure   Empty when permitted, else the failure category.
	 *     @type string $reason    Explanation.
	 *     @type string $environment The agreed environment label.
	 *     @type bool   $production  True only for an agreed production environment.
	 * }
	 */
	public static function evaluate( array $context ): array {
		$declared = isset( $context['environment'] ) ? trim( (string) $context['environment'] ) : '';
		$site     = self::site_environment();

		// --- 1. An absent or unknown label is never treated as "not prod" --
		if ( '' === $declared || ! in_array( $declared, self::KNOWN, true ) ) {
			return self::deny(
				'environment_unknown',
				sprintf( 'no recognised environment was declared (got "%s")', $declared ),
				$declared
			);
		}

		if ( '' === $site || ! in_array( $site, self::KNOWN, true ) ) {
			return self::deny(
				'environment_unknown',
				sprintf( 'WordPress reported an unrecognised environment ("%s")', $site ),
				$declared
			);
		}

		// --- 2. The two independent signals must agree. --------------------
		if ( $declared !== $site ) {
			return self::deny(
				'environment_mismatch',
				sprintf( 'the caller declared "%s" but this site is "%s"', $declared, $site ),
				$declared
			);
		}

		$authorized = isset( $context['production_authorized'] ) && true === $context['production_authorized'];
		$is_prod    = ( 'production' === $declared );

		// --- 3. Production requires explicit, positive authorisation. -----
		if ( $is_prod && ! $authorized ) {
			return self::deny(
				'production_not_authorized',
				'a production apply requires explicit production authorisation',
				$declared
			);
		}

		// --- 4. Authorisation for a non-production run is contradictory. ---
		if ( ! $is_prod && $authorized ) {
			return self::deny(
				'environment_contradictory',
				sprintf( 'production authorisation was asserted in a "%s" environment', $declared ),
				$declared
			);
		}

		return array(
			'permitted'   => true,
			'failure'     => '',
			'reason'      => $is_prod
				? 'production environment confirmed and explicitly authorised'
				: sprintf( 'non-production environment "%s" confirmed', $declared ),
			'environment' => $declared,
			'production'  => $is_prod,
		);
	}

	/**
	 * The environment WordPress itself reports.
	 *
	 * @return string
	 */
	public static function site_environment(): string {
		if ( is_callable( self::$site_detector ) ) {
			return trim( (string) call_user_func( self::$site_detector ) );
		}

		// WordPress's own answer, which reflects WP_ENVIRONMENT_TYPE or the
		// hosting platform's default. Used as the independent second signal.
		return trim( (string) wp_get_environment_type() );
	}

	/**
	 * A denial verdict.
	 *
	 * @param string $failure     Failure category.
	 * @param string $reason      Explanation.
	 * @param string $environment The declared environment, for the audit record.
	 * @return array
	 */
	private static function deny( string $failure, string $reason, string $environment ): array {
		return array(
			'permitted'   => false,
			'failure'     => $failure,
			'reason'      => $reason,
			'environment' => $environment,
			'production'  => false,
		);
	}
}
