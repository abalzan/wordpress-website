<?php
/**
 * The Republic of Ireland county master list — the ONLY county source of truth.
 *
 * Why this class exists
 * ---------------------
 * `conexao_county` used to be both a taxonomy and a de-facto vocabulary: every
 * dropdown listed "whatever terms exist", and every write path (importer, admin
 * editor, transfer import, wp-admin, REST) could insert an arbitrary value. A
 * county value that arrived as an identifier instead of a name therefore became
 * a term whose *name* was the number (the production 6724/6726/… leak), and a
 * source value of "Antrim" could add a 27th county.
 *
 * This class fixes the vocabulary at the code level:
 *
 *   LAYER 1 — `COUNTIES` below is the single, fixed, alphabetical list.
 *   LAYER 2 — the term guards registered by `register_guards()` reject any
 *             county that is not on the list, for every entry point.
 *   LAYER 3 — front-end filters must render `all()` (see
 *             conexao_get_county_filter_terms() in the theme), never `get_terms()`.
 *
 * Rules that must not be relaxed:
 *   - 26 counties of the Republic of Ireland only. No Northern Ireland counties.
 *   - Nothing is derived from terms, imports, submissions or user input.
 *   - A numeric value is NEVER a county (ids are not vocabulary).
 *   - Adding a county is a code change to `COUNTIES` and nothing else.
 *
 * @package Conexao_BR_Irlanda_Data_Model
 */

defined( 'ABSPATH' ) || exit;

final class Conexao_Data_Model_Counties {

	/**
	 * The shared, language-neutral county taxonomy.
	 */
	const TAXONOMY = 'conexao_county';

	/**
	 * THE county master list — 26 counties, alphabetical by display name.
	 *
	 * Key = canonical slug (identical to the taxonomy term slug).
	 * Value = canonical display name.
	 *
	 * NEVER add a Northern Ireland county here. NEVER add a numeric key.
	 *
	 * @var array<string, string>
	 */
	const COUNTIES = array(
		'carlow'    => 'Carlow',
		'cavan'     => 'Cavan',
		'clare'     => 'Clare',
		'cork'      => 'Cork',
		'donegal'   => 'Donegal',
		'dublin'    => 'Dublin',
		'galway'    => 'Galway',
		'kerry'     => 'Kerry',
		'kildare'   => 'Kildare',
		'kilkenny'  => 'Kilkenny',
		'laois'     => 'Laois',
		'leitrim'   => 'Leitrim',
		'limerick'  => 'Limerick',
		'longford'  => 'Longford',
		'louth'     => 'Louth',
		'mayo'      => 'Mayo',
		'meath'     => 'Meath',
		'monaghan'  => 'Monaghan',
		'offaly'    => 'Offaly',
		'roscommon' => 'Roscommon',
		'sligo'     => 'Sligo',
		'tipperary' => 'Tipperary',
		'waterford' => 'Waterford',
		'westmeath' => 'Westmeath',
		'wexford'   => 'Wexford',
		'wicklow'   => 'Wicklow',
	);

	/**
	 * Post meta keys whose value is a county.
	 *
	 * Kept in one place so the meta guard and the editor validation cannot drift.
	 *
	 * @var string[]
	 */
	const META_KEYS = array( '_event_county', '_leisure_county', '_guide_county' );

	/**
	 * Register the Layer 2 guards.
	 *
	 * Filters are registered at plugin load (not on `init`) so activation,
	 * seeding, imports and REST all run inside the guard from the first write.
	 *
	 * @return void
	 */
	public static function register_guards() {
		add_filter( 'pre_insert_term', array( __CLASS__, 'guard_insert_term' ), 10, 2 );
		add_filter( 'wp_insert_term_data', array( __CLASS__, 'canonical_insert_data' ), 10, 3 );
		add_filter( 'wp_update_term_data', array( __CLASS__, 'guard_update_data' ), 10, 4 );
		add_filter( 'add_post_metadata', array( __CLASS__, 'guard_post_meta' ), 10, 5 );
		add_filter( 'update_post_metadata', array( __CLASS__, 'guard_post_meta' ), 10, 5 );
	}

	/**
	 * The complete master list, alphabetical.
	 *
	 * @return array<string, string> Slug => canonical name.
	 */
	public static function all() {
		return self::COUNTIES;
	}

	/**
	 * Canonical display names, alphabetical.
	 *
	 * @return string[]
	 */
	public static function names() {
		return array_values( self::COUNTIES );
	}

	/**
	 * Canonical slugs, alphabetical by display name.
	 *
	 * @return string[]
	 */
	public static function slugs() {
		return array_keys( self::COUNTIES );
	}

	/**
	 * Whether a slug is one of the 26 approved counties.
	 *
	 * @param mixed $slug Candidate slug.
	 * @return bool
	 */
	public static function is_valid_slug( $slug ) {
		return is_string( $slug ) && isset( self::COUNTIES[ $slug ] );
	}

	/**
	 * Whether a submitted value is an approved county (any accepted spelling).
	 *
	 * @param mixed $value Candidate value (slug, name, "Co. Name", "Name, Ireland").
	 * @return bool
	 */
	public static function is_valid_value( $value ) {
		return '' !== self::normalize( $value );
	}

	/**
	 * Canonical name for a slug, or '' when the slug is not approved.
	 *
	 * @param mixed $slug Canonical slug.
	 * @return string
	 */
	public static function name_for( $slug ) {
		return self::is_valid_slug( $slug ) ? self::COUNTIES[ $slug ] : '';
	}

	/**
	 * Resolve any accepted spelling to a canonical county slug.
	 *
	 * Accepted: "laois", "Laois", "LAOIS", " Co. Laois ", "County Laois",
	 * "Laois, Ireland", "Laois Ireland", "Laois County".
	 *
	 * Rejected (returns ''): "6724", any integer, "", "Belfast", "Antrim",
	 * "Ireland County 27", "Cork City", anything not on the master list.
	 *
	 * @param mixed $value Raw value.
	 * @return string Canonical slug, or '' when not an approved county.
	 */
	public static function normalize( $value ) {
		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		// A bare identifier is never a county name. Checked before any
		// normalisation so 6724 can never accidentally match a county.
		if ( preg_match( '/^[0-9]+$/', $value ) ) {
			return '';
		}

		$value = remove_accents( $value );

		// Drop a trailing country, then the common county markers.
		$value = (string) preg_replace( '/,?\s*ireland$/i', '', $value );
		$value = (string) preg_replace( '/,?\s*county$/i', '', $value );
		$value = (string) preg_replace( '/^(?:co(?:unty)?\.?)\s+/i', '', $value );
		$value = trim( $value, " \t\n\r\0\x0B,.-" );

		if ( '' === $value || preg_match( '/^[0-9]+$/', $value ) ) {
			return '';
		}

		$slug = sanitize_title( $value );
		if ( isset( self::COUNTIES[ $slug ] ) ) {
			return $slug;
		}

		// Accept the display name verbatim (handles any future multi-word name).
		$lower = strtolower( $value );
		foreach ( self::COUNTIES as $county_slug => $county_name ) {
			if ( strtolower( $county_name ) === $lower ) {
				return $county_slug;
			}
		}

		return '';
	}

	/**
	 * Canonical display name for any accepted spelling, or '' when invalid.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function canonical_name( $value ) {
		return self::name_for( self::normalize( $value ) );
	}

	/**
	 * Term id of the canonical term for a county slug (0 when it does not exist).
	 *
	 * Resolution order: canonical slug first, then canonical display name, so a
	 * term that was previously created with a non-canonical slug (e.g.
	 * `laois-2`) is still found instead of being duplicated.
	 *
	 * @param mixed $slug Canonical county slug.
	 * @return int
	 */
	public static function term_id( $slug ) {
		if ( ! self::is_valid_slug( $slug ) || ! taxonomy_exists( self::TAXONOMY ) ) {
			return 0;
		}

		$term = get_term_by( 'slug', $slug, self::TAXONOMY );
		if ( $term instanceof WP_Term ) {
			return (int) $term->term_id;
		}

		$term = get_term_by( 'name', self::COUNTIES[ $slug ], self::TAXONOMY );
		return $term instanceof WP_Term ? (int) $term->term_id : 0;
	}

	/**
	 * Get or create the canonical term for a county slug.
	 *
	 * Returns 0 (and never creates anything) for an invalid county. The only
	 * write this method can perform is the creation of a term that is already
	 * on the master list.
	 *
	 * @param mixed $slug Canonical county slug.
	 * @return int Term id, or 0 when invalid/unavailable.
	 */
	public static function ensure_term( $slug ) {
		if ( ! self::is_valid_slug( $slug ) || ! taxonomy_exists( self::TAXONOMY ) ) {
			return 0;
		}

		$existing = self::term_id( $slug );
		if ( $existing > 0 ) {
			return $existing;
		}

		$created = wp_insert_term(
			self::COUNTIES[ $slug ],
			self::TAXONOMY,
			array( 'slug' => $slug )
		);

		if ( is_wp_error( $created ) ) {
			self::log_rejection( 'ensure_term', $slug, $created->get_error_message() );
			return 0;
		}

		return isset( $created['term_id'] ) ? (int) $created['term_id'] : 0;
	}

	/**
	 * Ensure all 26 canonical terms exist (activation / seed path).
	 *
	 * @return int[] Created or existing term ids, keyed by slug.
	 */
	public static function ensure_all_terms() {
		$ids = array();
		foreach ( self::slugs() as $slug ) {
			$term_id = self::ensure_term( $slug );
			if ( $term_id > 0 ) {
				$ids[ $slug ] = $term_id;
			}
		}
		return $ids;
	}

	/**
	 * Whether a term is a canonical county term (slug AND name agree).
	 *
	 * @param WP_Term $term Term object.
	 * @return bool
	 */
	public static function is_canonical_term( $term ) {
		if ( ! $term instanceof WP_Term || self::TAXONOMY !== $term->taxonomy ) {
			return false;
		}

		return self::is_valid_slug( $term->slug )
			&& self::normalize( $term->name ) === $term->slug;
	}

	/**
	 * County terms that are NOT on the master list.
	 *
	 * These are the terms the public filter must never show and the repair
	 * screen offers to re-assign/remove.
	 *
	 * @return WP_Term[]
	 */
	public static function invalid_terms() {
		$terms = self::all_terms();
		if ( empty( $terms ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$terms,
				static function ( $term ) {
					return ! self::is_valid_slug( $term->slug );
				}
			)
		);
	}

	/**
	 * Canonical-slug county terms whose display name drifted from the master list.
	 *
	 * @return WP_Term[]
	 */
	public static function misnamed_terms() {
		$terms = self::all_terms();
		if ( empty( $terms ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$terms,
				static function ( $term ) {
					return self::is_valid_slug( $term->slug )
						&& self::normalize( $term->name ) === $term->slug
						&& $term->name !== self::COUNTIES[ $term->slug ];
				}
			)
		);
	}

	/**
	 * Every term in the county taxonomy (any count, any language).
	 *
	 * @return WP_Term[]
	 */
	private static function all_terms() {
		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		return ( is_wp_error( $terms ) || empty( $terms ) ) ? array() : $terms;
	}

	/**
	 * Restore a county term's canonical name and slug.
	 *
	 * @param int $term_id County term id.
	 * @return bool True when the term is canonical afterwards.
	 */
	public static function canonicalize_term( $term_id ) {
		$term = get_term( (int) $term_id, self::TAXONOMY );
		if ( ! $term instanceof WP_Term ) {
			return false;
		}

		$slug = self::normalize( $term->slug );
		if ( '' === $slug ) {
			$slug = self::normalize( $term->name );
		}
		if ( '' === $slug ) {
			return false;
		}

		if ( $term->name === self::COUNTIES[ $slug ] && $term->slug === $slug ) {
			return true;
		}

		$updated = wp_update_term(
			$term->term_id,
			self::TAXONOMY,
			array(
				'name' => self::COUNTIES[ $slug ],
				'slug' => $slug,
			)
		);

		return ! is_wp_error( $updated );
	}

	// -----------------------------------------------------------------------
	// Layer 2 — term guards (fire for every taxonomy write path).
	// -----------------------------------------------------------------------

	/**
	 * Reject the creation of a county term that is not on the master list.
	 *
	 * Runs for `wp_insert_term()` and therefore for `wp_set_object_terms()`
	 * (which creates missing terms), the REST terms controller, the wp-admin
	 * term screens, every importer and any other code path.
	 *
	 * @param string|WP_Error $term     Term name.
	 * @param string          $taxonomy Taxonomy slug.
	 * @return string|WP_Error
	 */
	public static function guard_insert_term( $term, $taxonomy ) {
		if ( self::TAXONOMY !== $taxonomy || is_wp_error( $term ) ) {
			return $term;
		}

		if ( '' !== self::normalize( $term ) ) {
			return $term;
		}

		self::log_rejection( 'pre_insert_term', $term, '' );

		return new WP_Error(
			'conexao_county_not_approved',
			sprintf(
				/* translators: %s: rejected county value. */
				__( '"%s" não é um dos 26 condados aprovados da República da Irlanda. Use a lista de condados.', 'conexao-data-model' ),
				is_scalar( $term ) ? (string) $term : ''
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * Force the canonical name and slug on insert.
	 *
	 * A valid but loosely written value ("Co. Laois", "LAOIS") can therefore
	 * never create a duplicate or a differently formatted term.
	 *
	 * @param array  $data     Term data (name, slug, term_group).
	 * @param string $taxonomy Taxonomy slug.
	 * @param array  $args     Insert arguments.
	 * @return array
	 */
	public static function canonical_insert_data( $data, $taxonomy, $args ) {
		unset( $args );

		if ( self::TAXONOMY !== $taxonomy || ! is_array( $data ) ) {
			return $data;
		}

		$slug = self::normalize( isset( $data['name'] ) ? $data['name'] : '' );
		if ( '' === $slug && isset( $data['slug'] ) ) {
			$slug = self::normalize( $data['slug'] );
		}
		if ( '' === $slug ) {
			return $data;
		}

		$data['name'] = self::COUNTIES[ $slug ];
		$data['slug'] = $slug;

		return $data;
	}

	/**
	 * Refuse to rename a canonical county term to anything off the master list.
	 *
	 * A rename to a different approved county is left to `wp_update_term()`
	 * (which rejects the resulting duplicate slug); a rename to junk keeps the
	 * current identity instead of corrupting the vocabulary.
	 *
	 * @param array  $data     Term data (name, slug, term_group, parent).
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy slug.
	 * @param array  $args     Update arguments.
	 * @return array
	 */
	public static function guard_update_data( $data, $term_id, $taxonomy, $args ) {
		unset( $args );

		if ( self::TAXONOMY !== $taxonomy || ! is_array( $data ) ) {
			return $data;
		}

		$slug = self::normalize( isset( $data['name'] ) ? $data['name'] : '' );
		if ( '' === $slug && isset( $data['slug'] ) ) {
			$slug = self::normalize( $data['slug'] );
		}

		if ( '' !== $slug ) {
			$data['name'] = self::COUNTIES[ $slug ];
			$data['slug'] = $slug;
			return $data;
		}

		$existing = get_term( (int) $term_id, self::TAXONOMY );
		if ( $existing instanceof WP_Term ) {
			self::log_rejection( 'wp_update_term_data', isset( $data['name'] ) ? $data['name'] : '', 'rename blocked' );
			$data['name'] = $existing->name;
			$data['slug'] = $existing->slug;
		}

		return $data;
	}

	/**
	 * Reject an invalid county value written to county post meta.
	 *
	 * Returning a non-null value short-circuits `update_metadata()`; `false`
	 * means "the write did not happen", so an invalid value can never replace a
	 * valid one (and a REST write fails instead of silently corrupting).
	 *
	 * @param null|bool $check      Short-circuit value.
	 * @param int       $object_id  Post id.
	 * @param string    $meta_key   Meta key.
	 * @param mixed     $meta_value Submitted value.
	 * @param mixed     $prev_value Previous value (update only).
	 * @return null|bool
	 */
	public static function guard_post_meta( $check, $object_id, $meta_key, $meta_value, $prev_value = null ) {
		unset( $object_id, $prev_value );

		if ( null !== $check || ! in_array( $meta_key, self::META_KEYS, true ) ) {
			return $check;
		}

		// Empty values are legitimate: they mean "no county assigned".
		if ( '' === $meta_value || null === $meta_value ) {
			return $check;
		}

		if ( '' !== self::normalize( $meta_value ) ) {
			return $check;
		}

		self::log_rejection( $meta_key, $meta_value, 'meta write rejected' );

		return false;
	}

	/**
	 * Single, quiet audit trail for rejected county values.
	 *
	 * Nothing is stored in the database: production has no log the operator can
	 * read, and the repair screen is the review surface.
	 *
	 * @param string $context Where the rejection happened.
	 * @param mixed  $value   Rejected value.
	 * @param string $detail  Extra detail.
	 * @return void
	 */
	private static function log_rejection( $context, $value, $detail ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$value = is_scalar( $value ) ? (string) $value : gettype( $value );

		error_log(
			sprintf(
				'[conexao-county] rejected %s = %s (%s)',
				$context,
				$value,
				'' === $detail ? 'not on the 26-county master list' : $detail
			)
		);
	}
}

