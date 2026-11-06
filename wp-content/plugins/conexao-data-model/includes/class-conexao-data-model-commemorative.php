<?php
/**
 * Commemorative event data model: field schema, sanitizers and defaults.
 *
 * This class is DECLARATIVE. It owns the `commemorative_event` record shape
 * (the `_ce_*` meta keys, their defaults and their sanitisation), and nothing
 * else: it contains no scheduling, eligibility, cache or rendering logic.
 * The engine (`conexao-commemorative`) reads these fields; the admin UX
 * (`conexao-admin-ux`) edits them.
 *
 * Source of truth:
 * `docs/reports/2026-11-03-commemorative-events-architecture-proposal-r10-revised.md`
 * §3.2 (fields), §3.3 (enabled vs post status), §3.5 (validation), §3.6
 * (Polylang shared-vs-translated split) and §5.7 / §4.3 (occurrence years).
 *
 * @package Conexao_BR_Irlanda_Data_Model
 */

defined( 'ABSPATH' ) || exit;

/**
 * Commemorative event schema and sanitisation.
 */
final class Conexao_Data_Model_Commemorative {

	/**
	 * The admin-only configuration container post type.
	 */
	const POST_TYPE = 'commemorative_event';

	/**
	 * The four supported scheduling rules (§4.1).
	 *
	 * @var string[]
	 */
	const RULES = array( 'fixed_annual', 'variable_annual', 'one_off', 'window' );

	/**
	 * Supported `variable_annual` keys.
	 *
	 * @var string[]
	 */
	const VARIABLE_RULES = array( 'easter' );

	/**
	 * Scheduling / presentation meta that is SHARED across the language pair.
	 *
	 * Stored ONLY on the canonical Portuguese record (§3.6). The EN record must
	 * not carry any of these keys; the permanent gate enforces the split.
	 *
	 * @var string[]
	 */
	const SHARED_META_KEYS = array(
		'_ce_description',
		'_ce_enabled',
		'_ce_priority',
		'_ce_year_precedence',
		'_ce_presentation',
		'_ce_rule',
		'_ce_date',
		'_ce_variable',
		'_ce_one_off_date',
		'_ce_window_start',
		'_ce_window_end',
		'_ce_lead_in_days',
	);

	/**
	 * Translated content meta (§3.6): user-visible copy, per language.
	 *
	 * @var string[]
	 */
	const TRANSLATED_META_KEYS = array( '_ce_heading', '_ce_subheading', '_ce_cta_label', '_ce_cta_url' );

	/**
	 * The stable logical identity, carried by BOTH language records.
	 *
	 * @var string[]
	 */
	const IDENTITY_META_KEYS = array( '_ce_event_key' );

	/**
	 * Default meta values (§3.2).
	 *
	 * @return array<string,string>
	 */
	public static function defaults() {
		return array(
			'_ce_event_key'       => '',
			'_ce_description'     => '',
			'_ce_enabled'         => '1',
			'_ce_priority'        => '10',
			'_ce_year_precedence' => '{}',
			'_ce_presentation'    => '',
			'_ce_rule'            => 'fixed_annual',
			'_ce_date'            => '',
			'_ce_variable'        => 'easter',
			'_ce_one_off_date'    => '',
			'_ce_window_start'    => '',
			'_ce_window_end'      => '',
			'_ce_lead_in_days'    => '15',
			'_ce_heading'         => '',
			'_ce_subheading'      => '',
			'_ce_cta_label'       => '',
			'_ce_cta_url'         => '',
		);
	}

	/**
	 * Register every `_ce_*` meta key on the CPT.
	 *
	 * Every field is registered with a `sanitize_callback`, so every write path
	 * (admin editor, REST, importer, CLI migration) is sanitised (§3.5).
	 *
	 * `_ce_*` meta is deliberately NOT exposed through REST: the CPT itself is
	 * REST-enabled so the editor plumbing keeps working, but the
	 * scheduling/private meta must not leak (§3.7).
	 *
	 * @return void
	 */
	public static function register_meta() {
		$auth = static function ( $allowed, $meta_key, $object_id ) {
			return current_user_can( 'edit_post', $object_id );
		};

		$all = array_merge(
			self::SHARED_META_KEYS,
			self::TRANSLATED_META_KEYS,
			self::IDENTITY_META_KEYS
		);

		$sanitizers = array(
			'_ce_event_key'       => array( __CLASS__, 'sanitize_event_key' ),
			'_ce_description'     => 'sanitize_textarea_field',
			'_ce_enabled'         => array( __CLASS__, 'sanitize_bool' ),
			'_ce_priority'        => array( __CLASS__, 'sanitize_priority' ),
			'_ce_year_precedence' => array( __CLASS__, 'sanitize_year_precedence' ),
			'_ce_presentation'    => array( __CLASS__, 'sanitize_presentation' ),
			'_ce_rule'            => array( __CLASS__, 'sanitize_rule' ),
			'_ce_date'            => array( __CLASS__, 'sanitize_month_day' ),
			'_ce_variable'        => array( __CLASS__, 'sanitize_variable' ),
			'_ce_one_off_date'    => array( __CLASS__, 'sanitize_iso_date' ),
			'_ce_window_start'    => array( __CLASS__, 'sanitize_month_day' ),
			'_ce_window_end'      => array( __CLASS__, 'sanitize_month_day' ),
			'_ce_lead_in_days'    => array( __CLASS__, 'sanitize_lead_in_days' ),
			'_ce_heading'         => 'sanitize_text_field',
			'_ce_subheading'      => 'sanitize_text_field',
			'_ce_cta_label'       => 'sanitize_text_field',
			'_ce_cta_url'         => 'esc_url_raw',
		);

		$numeric  = array( '_ce_enabled', '_ce_priority', '_ce_lead_in_days' );
		$defaults = self::defaults();

		foreach ( $all as $key ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				array(
					'single'            => true,
					'type'              => in_array( $key, $numeric, true ) ? 'integer' : 'string',
					'default'           => $defaults[ $key ] ?? '',
					'show_in_rest'      => false,
					'sanitize_callback' => $sanitizers[ $key ],
					'auth_callback'     => $auth,
				)
			);
		}
	}

	/**
	 * Sanitize the stable logical identity.
	 *
	 * `[a-z0-9_]+` only (§3.2). An invalid submission stores an empty string so
	 * the record can never be resolved, rather than silently coercing a value.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_event_key( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = strtolower( trim( (string) $value ) );

		return preg_match( '/^[a-z0-9_]+$/', $value ) ? $value : '';
	}

	/**
	 * Sanitize a boolean switch to the canonical `'1'` / `'0'` pair.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_bool( $value ) {
		if ( is_string( $value ) ) {
			$value = strtolower( $value );
			if ( in_array( $value, array( '', '0', 'false', 'no', 'off' ), true ) ) {
				return '0';
			}
		}

		return $value ? '1' : '0';
	}

	/**
	 * Sanitize the global priority: clamped to `0..100` (§3.5).
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_priority( $value ) {
		$int = is_numeric( $value ) ? (int) $value : 10;
		$int = max( 0, min( 100, $int ) );

		return (string) $int;
	}

	/**
	 * Sanitize the lead-in days: clamped to `0..120` (§3.5).
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_lead_in_days( $value ) {
		$int = is_numeric( $value ) ? (int) $value : 15;
		$int = max( 0, min( 120, $int ) );

		return (string) $int;
	}

	/**
	 * Sanitize a `MM-DD` month-day.
	 *
	 * The value must round-trip through `DateTime::createFromFormat( '!m-d-Y' )`
	 * exactly; a mismatch stores `''` (never a coerced date). `02-29` is
	 * rejected because it silently never fires in non-leap years (§4.5, W15).
	 *
	 * @param mixed $value Raw value.
	 * @return string Canonical `MM-DD`, or `''`.
	 */
	public static function sanitize_month_day( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = trim( (string) $value );
		if ( ! preg_match( '/^(\d{1,2})-(\d{1,2})$/', $value, $matches ) ) {
			return '';
		}

		$month = (int) $matches[1];
		$day   = (int) $matches[2];

		if ( $month < 1 || $month > 12 || $day < 1 || $day > 31 ) {
			return '';
		}

		// Leap-day occurrences never fire in ordinary years: reject explicitly.
		if ( 2 === $month && 29 === $day ) {
			return '';
		}

		// Validate against a real leap year so 02-30 / 04-31 are rejected while
		// every legitimate day is accepted.
		$candidate = sprintf( '%02d-%02d-2024', $month, $day );
		$parsed    = DateTime::createFromFormat( '!m-d-Y', $candidate, new DateTimeZone( 'UTC' ) );

		if ( ! $parsed || $parsed->format( 'm-d' ) !== sprintf( '%02d-%02d', $month, $day ) ) {
			return '';
		}

		return sprintf( '%02d-%02d', $month, $day );
	}

	/**
	 * Sanitize a `Y-m-d` calendar date.
	 *
	 * @param mixed $value Raw value.
	 * @return string Canonical `Y-m-d`, or `''`.
	 */
	public static function sanitize_iso_date( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = trim( (string) $value );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return '';
		}

		$parsed = DateTime::createFromFormat( '!Y-m-d', $value, new DateTimeZone( 'UTC' ) );

		return ( $parsed && $parsed->format( 'Y-m-d' ) === $value ) ? $value : '';
	}

	/**
	 * Sanitize the scheduling rule against the supported set (§4.1).
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_rule( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';

		return in_array( $value, self::RULES, true ) ? $value : 'fixed_annual';
	}

	/**
	 * Sanitize the variable-annual key against the supported set.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_variable( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';

		return in_array( $value, self::VARIABLE_RULES, true ) ? $value : 'easter';
	}

	/**
	 * Sanitize the experience-package slug.
	 *
	 * Validated against the LIVE theme-owned registry when one is available
	 * (§3.5). An empty registry means the registry is not loaded in this
	 * context, so the value is accepted here and treated as non-displayable at
	 * read time instead — defence in depth, never a silent wrong render.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_presentation( $value ) {
		$value = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';

		if ( '' === $value || ! preg_match( '/^[a-z0-9_]+$/', $value ) ) {
			return '';
		}

		$packages = self::registered_presentation_slugs();

		if ( empty( $packages ) ) {
			return $value;
		}

		return in_array( $value, $packages, true ) ? $value : '';
	}

	/**
	 * The registered experience-package slugs, from the theme-owned filter.
	 *
	 * @return string[]
	 */
	public static function registered_presentation_slugs() {
		$packages = apply_filters( 'conexao_commemorative_presentations', array() );

		if ( ! is_array( $packages ) ) {
			return array();
		}

		$slugs = array_map( 'strval', array_keys( $packages ) );

		return array_values(
			array_filter(
				$slugs,
				static function ( $slug ) {
					return '' !== $slug;
				}
			)
		);
	}

	/**
	 * Sanitize the manual per-occurrence-year preference map (§5.7).
	 *
	 * Accepted shape: `occurrence_year => { preferred?: bool, note?: string }`.
	 * Years outside `Y-2 .. Y+5` are dropped; `note` is sanitised and truncated
	 * to 200 characters; invalid JSON is treated as `{}` so the global priority
	 * applies.
	 *
	 * @param mixed $value Raw value (array or JSON string).
	 * @return string Canonical JSON.
	 */
	public static function sanitize_year_precedence( $value ) {
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			$value   = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! is_array( $value ) ) {
			return '{}';
		}

		$clean = array();

		foreach ( $value as $year => $entry ) {
			$year = (string) $year;

			if ( ! self::is_valid_occurrence_year( $year ) || ! is_array( $entry ) ) {
				continue;
			}

			$note = isset( $entry['note'] ) && is_scalar( $entry['note'] )
				? sanitize_text_field( (string) $entry['note'] )
				: '';

			if ( function_exists( 'mb_substr' ) ) {
				$note = mb_substr( $note, 0, 200 );
			} else {
				$note = substr( $note, 0, 200 );
			}

			$row = array( 'preferred' => ! empty( $entry['preferred'] ) );

			if ( '' !== $note ) {
				$row['note'] = $note;
			}

			$clean[ $year ] = $row;
		}

		if ( empty( $clean ) ) {
			return '{}';
		}

		ksort( $clean );

		$encoded = wp_json_encode( $clean );

		return is_string( $encoded ) ? $encoded : '{}';
	}

	/**
	 * Decode a stored `_ce_year_precedence` value.
	 *
	 * Invalid JSON decodes to an empty map, which means "no manual preference".
	 *
	 * @param mixed $value Stored value (JSON string or array).
	 * @return array<array-key,array{preferred:bool,note:string}>
	 */
	public static function decode_year_precedence( $value ) {
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			$value   = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$map = array();

		foreach ( $value as $year => $entry ) {
			$year = (string) $year;

			if ( ! preg_match( '/^\d{4}$/', $year ) || ! is_array( $entry ) ) {
				continue;
			}

			$map[ $year ] = array(
				'preferred' => ! empty( $entry['preferred'] ),
				'note'      => isset( $entry['note'] ) && is_scalar( $entry['note'] ) ? (string) $entry['note'] : '',
			);
		}

		return $map;
	}

	/**
	 * The validated occurrence-year window: `Y-2 .. Y+5` (§3.5, §5.7).
	 *
	 * @param int|null $site_year Current site year; defaults to the site clock.
	 * @return int[]
	 */
	public static function allowed_years( $site_year = null ) {
		if ( null === $site_year ) {
			$site_year = (int) current_time( 'Y' );
		}

		$site_year = (int) $site_year;

		return range( $site_year - 2, $site_year + 5 );
	}

	/**
	 * Whether a year key is inside the validated range.
	 *
	 * @param mixed    $year      Candidate year.
	 * @param int|null $site_year Current site year.
	 * @return bool
	 */
	public static function is_valid_occurrence_year( $year, $site_year = null ) {
		$year = is_scalar( $year ) ? (string) $year : '';

		if ( ! preg_match( '/^\d{4}$/', $year ) ) {
			return false;
		}

		return in_array( (int) $year, self::allowed_years( $site_year ), true );
	}
}
