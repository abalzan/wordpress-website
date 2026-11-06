<?php
/**
 * Commemorative event records: reading, normalisation and language resolution.
 *
 * The CPT is the ONLY source of truth (§8.2). There is no runtime fallback to
 * a built-in list: a brand-new install has zero commemorative events until an
 * administrator creates one (or the one-time seed migration runs).
 *
 * Shared configuration is read from the canonical Portuguese record whichever
 * language is rendering; only the user-visible copy is resolved per language
 * (§3.6). Locale is read only through `conexao_current_locale()` when the theme
 * provides it.
 *
 * @package Conexao_Commemorative
 */

defined( 'ABSPATH' ) || exit;

/**
 * Event record access.
 */
final class Conexao_Commemorative_Repository {

	/**
	 * Per-request record cache.
	 *
	 * @var array<int,array>|null
	 */
	private static $cache = null;

	/**
	 * Drop the per-request cache (tests and writes call this explicitly).
	 *
	 * @return void
	 */
	public static function reset_cache() {
		self::$cache = null;
	}

	/**
	 * Every commemorative record, normalised, in a deterministic order.
	 *
	 * @param array $args Query overrides.
	 * @return array<int,array>
	 */
	public static function all( array $args = array() ) {
		$defaults = array(
			'post_type'        => Conexao_Data_Model_Commemorative::POST_TYPE,
			'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
			'posts_per_page'   => -1,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'no_found_rows'    => true,
			// Read EVERY language, so the canonical record is visible no matter
			// which language the current request is in. `suppress_filters` is
			// deliberate: a language filter on this internal list would make the
			// engine see only the current language's records and silently lose
			// the canonical configuration (§3.6).
			'lang'             => '',
			'suppress_filters' => true,
		);

		$posts = get_posts( array_merge( $defaults, $args ) );

		$out = array();

		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$out[] = self::normalise( $post );
		}

		return $out;
	}

	/**
	 * Published records only — the scheduling participants.
	 *
	 * One record per logical event: translations are collapsed onto the
	 * canonical Portuguese record, so an event can never render twice.
	 *
	 * @return array<int,array>
	 */
	public static function scheduled() {
		if ( is_array( self::$cache ) ) {
			return self::$cache;
		}

		$events = array();
		$seen   = array();

		foreach ( self::all( array( 'post_status' => 'publish' ) ) as $event ) {
			$canonical = self::canonical_id( $event['id'] );

			if ( isset( $seen[ $canonical ] ) ) {
				continue;
			}

			// Only the canonical-language record participates: shared config and
			// identity live there (§3.6).
			if ( '' !== self::language_of( $canonical ) && ! self::is_canonical_language( $canonical ) ) {
				continue;
			}

			$seen[ $canonical ]    = true;
			$event['canonical_id'] = $canonical;
			$event['language']     = self::language_of( $canonical );
			$events[]              = $event;
		}

		// Deterministic order: `_ce_event_key` ascending, then ID.
		usort(
			$events,
			static function ( $a, $b ) {
				$key = strcmp( $a['event_key'], $b['event_key'] );

				return 0 !== $key ? $key : ( $a['id'] <=> $b['id'] );
			}
		);

		self::$cache = $events;

		return self::$cache;
	}

	/**
	 * Normalise a post into the engine's event array.
	 *
	 * Every value passes through the data model's sanitizers, so a corrupted
	 * row can never produce an eligible event.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	public static function normalise( WP_Post $post ) {
		$id = (int) $post->ID;
		$dm = 'Conexao_Data_Model_Commemorative';

		return array(
			'id'              => $id,
			'event_key'       => (string) get_post_meta( $id, '_ce_event_key', true ),
			'title'           => (string) $post->post_title,
			'status'          => (string) $post->post_status,
			'enabled'         => '0' !== (string) get_post_meta( $id, '_ce_enabled', true ),
			'priority'        => (int) $dm::sanitize_priority( get_post_meta( $id, '_ce_priority', true ) ),
			'presentation'    => $dm::sanitize_presentation( get_post_meta( $id, '_ce_presentation', true ) ),
			'rule'            => $dm::sanitize_rule( get_post_meta( $id, '_ce_rule', true ) ),
			'date'            => $dm::sanitize_month_day( get_post_meta( $id, '_ce_date', true ) ),
			'variable'        => $dm::sanitize_variable( get_post_meta( $id, '_ce_variable', true ) ),
			'one_off_date'    => $dm::sanitize_iso_date( get_post_meta( $id, '_ce_one_off_date', true ) ),
			'window_start'    => $dm::sanitize_month_day( get_post_meta( $id, '_ce_window_start', true ) ),
			'window_end'      => $dm::sanitize_month_day( get_post_meta( $id, '_ce_window_end', true ) ),
			'lead_in_days'    => (int) $dm::sanitize_lead_in_days( get_post_meta( $id, '_ce_lead_in_days', true ) ),
			'year_precedence' => $dm::decode_year_precedence( get_post_meta( $id, '_ce_year_precedence', true ) ),
			'description'     => (string) get_post_meta( $id, '_ce_description', true ),
			'canonical_id'    => self::canonical_id( $id ),
			'language'        => self::language_of( $id ),
		);
	}

	/**
	 * Find one record by post ID.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function find( $post_id ) {
		$post = get_post( (int) $post_id );

		if ( ! $post instanceof WP_Post || Conexao_Data_Model_Commemorative::POST_TYPE !== $post->post_type ) {
			return null;
		}

		return self::normalise( $post );
	}

	/**
	 * Find the canonical record for a stable `event_key`.
	 *
	 * @param string $event_key Stable logical identity.
	 * @return array|null
	 */
	public static function find_by_event_key( $event_key ) {
		$event_key = Conexao_Data_Model_Commemorative::sanitize_event_key( $event_key );

		if ( '' === $event_key ) {
			return null;
		}

		$posts = get_posts(
			array(
				'post_type'        => Conexao_Data_Model_Commemorative::POST_TYPE,
				'post_status'      => 'any',
				'posts_per_page'   => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'lang'             => '',
				// Same reason as all(): the language filter must not hide the
				// canonical record this lookup is looking for.
				'suppress_filters' => true,
				'meta_key'         => '_ce_event_key',
				'meta_value'       => $event_key,
			)
		);

		foreach ( $posts as $post ) {
			if ( self::is_canonical_language( $post->ID ) ) {
				return self::normalise( $post );
			}
		}

		return isset( $posts[0] ) ? self::normalise( $posts[0] ) : null;
	}

	/**
	 * The canonical (default-language) record for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function canonical_id( $post_id ) {
		$post_id = (int) $post_id;

		if ( $post_id <= 0 || ! function_exists( 'pll_get_post' ) || ! function_exists( 'pll_default_language' ) ) {
			return $post_id;
		}

		$default   = pll_default_language( 'slug' );
		$canonical = $default ? pll_get_post( $post_id, $default ) : 0;

		return $canonical ? (int) $canonical : $post_id;
	}

	/**
	 * The Polylang language slug of a record ('' when Polylang is inactive).
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function language_of( $post_id ) {
		if ( ! class_exists( 'Conexao_Data_Model_Commemorative' ) ) {
			return '';
		}

		return Conexao_Data_Model_Commemorative::language_of( $post_id );
	}

	/**
	 * Whether a record is in the canonical (default) language.
	 *
	 * Delegates to the data model, which owns the shared/translated split: the
	 * engine must agree with the editor and the Polylang filter about which
	 * record is the single owner.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_canonical_language( $post_id ) {
		if ( ! class_exists( 'Conexao_Data_Model_Commemorative' ) ) {
			return true;
		}

		return Conexao_Data_Model_Commemorative::is_canonical_language( $post_id );
	}

	/**
	 * The record to read user-visible copy from, for the current locale.
	 *
	 * @param array $event Canonical event.
	 * @return int Post ID to read the translated content from.
	 */
	public static function localised_post_id( array $event ) {
		$canonical = isset( $event['canonical_id'] ) ? (int) $event['canonical_id'] : (int) $event['id'];
		$slug      = self::language_slug_for_locale( self::current_locale() );

		if ( '' === $slug || ! function_exists( 'pll_get_post' ) ) {
			return $canonical;
		}

		$translated = pll_get_post( $canonical, $slug );

		return $translated ? (int) $translated : $canonical;
	}

	/**
	 * The current site locale, read only through the theme helper when present.
	 *
	 * @return string
	 */
	public static function current_locale() {
		if ( function_exists( 'conexao_current_locale' ) ) {
			return (string) conexao_current_locale();
		}

		return (string) get_locale();
	}

	/**
	 * Map a locale (e.g. `en_US`) onto a Polylang language slug.
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	private static function language_slug_for_locale( $locale ) {
		if ( function_exists( 'PLL' ) && function_exists( 'pll_languages_list' ) && PLL() ) {
			$language = PLL()->model->get_language( $locale );

			if ( $language && ! empty( $language->slug ) ) {
				return (string) $language->slug;
			}
		}

		$short = strtolower( substr( (string) $locale, 0, 2 ) );

		return in_array( $short, array( 'pt', 'en' ), true ) ? $short : '';
	}

	/**
	 * User-visible copy for the current locale, with a canonical → PT fallback.
	 *
	 * §3.6: the fallback is canonical → PT, noticed, and never a second render.
	 *
	 * @param array $event Canonical event.
	 * @return array{title:string,heading:string,subheading:string,cta_label:string,cta_url:string,post_id:int,fallback:bool}
	 */
	public static function content( array $event ) {
		$canonical_id = isset( $event['canonical_id'] ) ? (int) $event['canonical_id'] : (int) $event['id'];
		$post_id      = self::localised_post_id( $event );
		$post         = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			$post    = get_post( $canonical_id );
			$post_id = $canonical_id;
		}

		$fallback = $post_id === $canonical_id && self::language_slug_for_locale( self::current_locale() ) !== self::language_of( $canonical_id );

		return array(
			'title'      => $post instanceof WP_Post ? (string) $post->post_title : (string) $event['title'],
			'heading'    => (string) get_post_meta( $post_id, '_ce_heading', true ),
			'subheading' => (string) get_post_meta( $post_id, '_ce_subheading', true ),
			'cta_label'  => (string) get_post_meta( $post_id, '_ce_cta_label', true ),
			'cta_url'    => (string) get_post_meta( $post_id, '_ce_cta_url', true ),
			'post_id'    => (int) $post_id,
			'fallback'   => $fallback,
		);
	}
}
