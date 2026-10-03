<?php
/**
 * The deterministic source-state digest (Stage 3 control B5).
 *
 * ## Inventory is the source of truth; hooks are only wake-up hints
 *
 * This class defines WHAT a translation-relevant PT change IS. It is the only
 * definition in the repository: the inventory the change detector compares, the
 * request identity the provider is given and the persisted source state all
 * hash through `digest()` here, so there is exactly one canonical form.
 *
 * ## The field list is DERIVED, not assumed
 *
 * It is NOT a generic "hash the post row" and NOT a guess from WordPress
 * conventions. Each stage profile below is pinned to the real registered stage
 * configuration and is cross-checked against it at runtime
 * (`assert_profile_matches_config()`): a stage whose `source_post_type`,
 * `source_lang` or `target_lang` disagrees with its profile FAILS CLOSED
 * rather than being silently digested under the wrong field list.
 *
 * | Profile | Stages | PT fields in the digest |
 * |---|---|---|
 * | `b1` | `en-guide`, `en-page`, `en-post`, `en-blog-page`, `en-jobs-page` | `post_name`, `post_title`, `post_content`, `post_excerpt`, `conexao_meta_description`, plus the **translated** taxonomies `conexao_category`/`conexao_tag` (by SLUG) |
 * | `b2` | `en-leisure-description`, `en-course-provider-description` | `post_name`, `post_title`, `post_excerpt` |
 *
 * The b1 list is exactly the PT values the authored EN row mirrors
 * (`en_slug`/`en_title`/`en_content`/`en_excerpt`/`en_meta_description`) plus
 * the translated terms the EN record is filed under. The b2 list is the field
 * the B2 stage's OWN drift guard compares against the authored `pt_source`.
 *
 * ## What is deliberately EXCLUDED, and why
 *
 * Each exclusion is repository evidence, not preference.
 *
 * | Excluded | Evidence |
 * |---|---|
 * | the EN meta key a B2 stage owns (`_leisure_excerpt_en`, `_provider_excerpt_en`) | the stage's own snapshot omits it deliberately — including it would make every apply report PT drift, and every generated EN value would feed back into the PT source digest |
 * | `conexao_county` / `conexao_town` | SHARED proper-name taxonomies (`stage-config.php:126-133`): the same physical term serves both languages, so a change there changes no translation |
 * | `post_date`, `post_author`, `menu_order`, featured image | language-neutral values the engine COPIES; they are not translation inputs and must not re-trigger translation |
 * | modified timestamps, audit ids, lock state, run ids | volatile or internal; they would make the digest change on every run |
 * | EN records entirely | `language` is part of the payload, so an EN record is refused before hashing |
 *
 * ## Canonical serialization, defined BEFORE hashing
 *
 * 1. Build a payload with a FIXED key order (the profile's own field order).
 * 2. Every scalar is cast to a string; every value is exactly what WordPress
 *    returned, never a normalised or prettified variant of it.
 * 3. `canonical()` recursively sorts MAP keys so key order cannot change the
 *    digest, while LISTS keep their order and taxonomy slug lists are sorted.
 * 4. `hash( 'sha256', wp_json_encode( canonical( $payload ) ) )`.
 *
 * Identical PT source state therefore always yields an identical digest, in any
 * process, on any machine, in any key order.
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The canonical serialization and the source-state digest.
 */
final class Conexao_Translation_Automation_Digest {

	/**
	 * Schema version of the projection itself. Bump it when a profile's field
	 * list changes, so a persisted digest produced by an older field list can
	 * never be silently compared against a newer one.
	 *
	 * @var int
	 */
	const PROJECTION_VERSION = 1;

	/**
	 * The explicit stage profiles (Stage 0 control S6 applied to detection).
	 *
	 * Deliberately an explicit table rather than "whatever is registered" or an
	 * inference from post-type naming: a stage that is not described here
	 * cannot be digested at all, so adding a registered stage can never
	 * silently become detectable, and renaming a post type can never silently
	 * change which fields are hashed.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function stage_profiles(): array {
		return array(
			// --- B1: a real, linked EN record exists. ---------------------
			'en-guide'                       => self::profile( 'b1', 'guide', 'conexao_category', 'conexao_tag' ),
			'en-page'                        => self::profile( 'b1', 'page', 'conexao_category', 'conexao_tag' ),
			'en-post'                        => self::profile( 'b1', 'post', 'conexao_category', 'conexao_tag' ),
			'en-blog-page'                   => self::profile( 'b1', 'page', 'conexao_category', 'conexao_tag' ),
			'en-jobs-page'                   => self::profile( 'b1', 'page', 'conexao_category', 'conexao_tag' ),

			// --- B2: PT stays canonical; EN is a translated FIELD. ----------
			// No taxonomy: a B2 stage writes one field on the PT record and
			// mints no slug, so no term assignment is a translation input.
			'en-leisure-description'         => self::profile( 'b2', 'leisure' ),
			'en-course-provider-description' => self::profile( 'b2', 'course_provider' ),
		);
	}

	/**
	 * The EN meta key a B2 stage owns, per stage.
	 *
	 * Recorded so the projection can PROVE the key was excluded rather than
	 * merely claim it, and so a test can assert the exclusion is real.
	 *
	 * @return array<string,string>
	 */
	public static function b2_owned_meta_keys(): array {
		return array(
			'en-leisure-description'         => '_leisure_excerpt_en',
			'en-course-provider-description' => '_provider_excerpt_en',
		);
	}

	/**
	 * The stage ids that are B2 (translated field on the same PT record).
	 *
	 * @return array<int,string>
	 */
	public static function b2_stages(): array {
		return array_keys( self::b2_owned_meta_keys() );
	}

	/**
	 * Is this stage a B2 stage?
	 *
	 * @param string $stage Stage identifier.
	 * @return bool
	 */
	public static function is_b2( string $stage ): bool {
		return in_array( $stage, self::b2_stages(), true );
	}

	/**
	 * The profile for one stage, or null when the stage has none.
	 *
	 * @param string $stage Stage identifier.
	 * @return array<string,mixed>|null
	 */
	public static function profile_for( string $stage ) {
		$profiles = self::stage_profiles();

		return isset( $profiles[ $stage ] ) ? $profiles[ $stage ] : null;
	}

	/**
	 * A deterministic digest of an arbitrary structure.
	 *
	 * Public because the change set, the provider request identity and the
	 * audit record all hash through this one function, so there is exactly one
	 * canonical form in the repository.
	 *
	 * @param mixed $value Structure to digest.
	 * @return string 64 lowercase hex characters.
	 */
	public static function digest( $value ): string {
		return hash( 'sha256', (string) wp_json_encode( self::canonical( $value ) ) );
	}

	/**
	 * Recursively sort map keys; leave list order alone.
	 *
	 * A list keeps its order because order is meaningful data (a sequence of
	 * plan rows, a digest-bearing field list), and normalising it away would
	 * make two genuinely different structures hash identically.
	 *
	 * @param mixed $value Structure to canonicalise.
	 * @return mixed
	 */
	public static function canonical( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$out = array();

		foreach ( $value as $key => $item ) {
			$out[ $key ] = self::canonical( $item );
		}

		if ( ! self::is_list( $out ) ) {
			ksort( $out );
		}

		return $out;
	}

	/**
	 * Is this array a zero-indexed list?
	 *
	 * @param array $value Candidate.
	 * @return bool
	 */
	private static function is_list( array $value ): bool {
		if ( array() === $value ) {
			return true;
		}

		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Build one stage profile.
	 *
	 * @param string $mode             `b1` or `b2`.
	 * @param string $source_post_type Expected source post type.
	 * @param string ...$taxonomies    Translated taxonomies to include (b1 only).
	 * @return array<string,mixed>
	 */
	private static function profile( string $mode, string $source_post_type, string ...$taxonomies ): array {
		$profile = array(
			'mode'             => $mode,
			'source_post_type' => $source_post_type,
			'projection'       => self::PROJECTION_VERSION,
			// The EN field values this profile's stage OWNS. Never digested:
			// they are generated output, and hashing them would make the PT
			// source digest depend on a translation.
			'excluded_fields'  => self::b2_owned_meta_keys(),
			'fields'           => array(),
			'taxonomies'       => array(),
		);

		if ( 'b1' === $mode ) {
			$profile['fields'] = array(
				'post_name',
				'post_title',
				'post_content',
				'post_excerpt',
				'conexao_meta_description',
			);
		} else {
			// A B2 stage translates ONE field of the PT record, and the stage's
			// own drift guard proves which: `post_excerpt` is compared against
			// the authored `pt_source`. `post_name` is the portable identity and
			// `post_title` is the authored context carried in every B2 row.
			$profile['fields'] = array(
				'post_name',
				'post_title',
				'post_excerpt',
			);
		}

		foreach ( $taxonomies as $taxonomy ) {
			$profile['taxonomies'][] = $taxonomy;
		}

		return $profile;
	}

	/**
	 * Assert a stage's REGISTERED configuration agrees with its profile.
	 *
	 * This is what makes the derivation evidence-bound rather than aspirational.
	 * A stage whose live config points at a different post type or a different
	 * language pair fails closed, because digesting it under the wrong field
	 * list would silently desynchronise the persisted state.
	 *
	 * @param array $config Stage configuration from the engine registry.
	 * @return true|WP_Error
	 */
	public static function assert_profile_matches_config( array $config ) {
		$stage = isset( $config['stage'] ) ? (string) $config['stage'] : '';

		if ( '' === $stage ) {
			return new WP_Error(
				'conexao_automation_digest_no_stage',
				'Stage configuration declares no stage identifier.'
			);
		}

		$profile = self::profile_for( $stage );

		if ( null === $profile ) {
			return new WP_Error(
				'conexao_automation_digest_no_profile',
				sprintf( 'Stage "%s" has no source-state projection profile.', $stage )
			);
		}

		if ( (string) ( $config['source_post_type'] ?? '' ) !== (string) $profile['source_post_type'] ) {
			return new WP_Error(
				'conexao_automation_digest_profile_mismatch',
				sprintf(
					'Stage "%s" is registered with source_post_type "%s" but its projection expects "%s".',
					$stage,
					(string) ( $config['source_post_type'] ?? '' ),
					(string) $profile['source_post_type']
				)
			);
		}

		// A program whose source is not PT, or whose target is not EN, is not
		// the contract this projection was derived from.
		if ( 'pt' !== (string) ( $config['source_lang'] ?? '' ) || 'en' !== (string) ( $config['target_lang'] ?? '' ) ) {
			return new WP_Error(
				'conexao_automation_digest_profile_mismatch',
				sprintf(
					'Stage "%s" is registered as %s->%s, but every projection in this program is pt->en.',
					$stage,
					(string) ( $config['source_lang'] ?? '' ),
					(string) ( $config['target_lang'] ?? '' )
				)
			);
		}

		return true;
	}

	/**
	 * Build the translation-relevant payload for one live PT record.
	 *
	 * READ-ONLY. Reads exactly the profile's fields and translated taxonomies
	 * and nothing else, so a change to an excluded field provably cannot move
	 * the digest.
	 *
	 * @param string $stage     Stage identifier.
	 * @param int    $pt_id     PT post ID.
	 * @param array  $meta_read Optional pre-read meta accessor (tests).
	 * @return array{ok:bool,payload:array,reason:string}
	 */
	public static function read_payload( string $stage, int $pt_id, array $meta_read = array() ): array {
		$profile = self::profile_for( $stage );

		if ( null === $profile ) {
			return self::refused( sprintf( 'Stage "%s" has no source-state projection profile.', $stage ) );
		}

		if ( $pt_id <= 0 ) {
			return self::refused( 'PT record id must be a positive integer.' );
		}

		$post = get_post( $pt_id );

		if ( ! $post instanceof WP_Post ) {
			return self::refused( sprintf( 'PT record %d is absent.', $pt_id ) );
		}

		$read_meta = isset( $meta_read['get'] ) && is_callable( $meta_read['get'] )
			? $meta_read['get']
			: static function ( int $id, string $key ): string {
				return (string) get_post_meta( $id, $key, true );
			};

		$payload = array(
			'stage'     => $stage,
			'mode'      => (string) $profile['mode'],
			'post_type' => (string) $post->post_type,
			'language'  => self::record_language( $pt_id ),
			'fields'    => array(),
			'terms'     => array(),
		);

		foreach ( $profile['fields'] as $field ) {
			$payload['fields'][ $field ] = 'conexao_meta_description' === $field
				? (string) call_user_func( $read_meta, $pt_id, $field )
				: (string) $post->{$field};
		}

		// Translated taxonomies are hashed by SLUG, never by term ID: a term ID
		// is an environment-local number and is not portable identity
		// (engineering standard 0.4), while the slug is the stable concept key.
		foreach ( $profile['taxonomies'] as $taxonomy ) {
			$terms = get_the_terms( $pt_id, $taxonomy );
			$slugs = array();

			if ( is_array( $terms ) && array() !== $terms ) {
				foreach ( $terms as $term ) {
					$slugs[] = (string) $term->slug;
				}
			}

			// Sorted, because two identical term SETS must digest identically
			// regardless of the order WordPress happened to return them in.
			sort( $slugs );

			$payload['terms'][ $taxonomy ] = $slugs;
		}

		return array(
			'ok'      => true,
			'payload' => $payload,
			'reason'  => '',
		);
	}

	/**
	 * The digest of one live PT record's translation-relevant state.
	 *
	 * @param string $stage     Stage identifier.
	 * @param int    $pt_id     PT post ID.
	 * @param array  $meta_read Optional pre-read meta accessor (tests).
	 * @return array{ok:bool,digest:string,payload:array,reason:string}
	 */
	public static function digest_record( string $stage, int $pt_id, array $meta_read = array() ): array {
		$read = self::read_payload( $stage, $pt_id, $meta_read );

		if ( empty( $read['ok'] ) ) {
			return self::refused( (string) $read['reason'] );
		}

		return array(
			'ok'      => true,
			'digest'  => self::digest( $read['payload'] ),
			'payload' => (array) $read['payload'],
			'reason'  => '',
		);
	}

	/**
	 * Is this live record a PT record this stage may digest?
	 *
	 * An EN record, an unknown-language record and a record of the wrong post
	 * type are all REFUSED. That refusal is what keeps an EN-only change out of
	 * the change set: an EN edit never reaches the PT source digest at all,
	 * rather than being digested and then compared to discover it was equal.
	 *
	 * @param string $stage Stage identifier.
	 * @param int    $pt_id Post ID.
	 * @return true|WP_Error
	 */
	public static function assert_digestable( string $stage, int $pt_id ) {
		$profile = self::profile_for( $stage );

		if ( null === $profile ) {
			return new WP_Error(
				'conexao_automation_digest_no_profile',
				sprintf( 'Stage "%s" has no source-state projection profile.', $stage )
			);
		}

		$post = get_post( $pt_id );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'conexao_automation_digest_absent_record',
				sprintf( 'Record %d is absent.', $pt_id )
			);
		}

		if ( (string) $post->post_type !== (string) $profile['source_post_type'] ) {
			return new WP_Error(
				'conexao_automation_digest_wrong_post_type',
				sprintf(
					'Record %d is a "%s", not the "%s" that stage "%s" digests.',
					$pt_id,
					(string) $post->post_type,
					(string) $profile['source_post_type'],
					$stage
				)
			);
		}

		$language = self::record_language( $pt_id );

		if ( 'pt' !== $language ) {
			return new WP_Error(
				'conexao_automation_digest_not_pt',
				sprintf(
					'Record %d is language "%s"; only a provable "pt" record is a translation source.',
					$pt_id,
					'' === $language ? '(unassignable)' : $language
				)
			);
		}

		return true;
	}

	/**
	 * The record's Polylang language slug.
	 *
	 * A record whose language cannot be established is reported as '' rather
	 * than defaulted to 'pt', so an unfiltered query can never silently
	 * contribute an EN record to a PT source digest.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function record_language( int $post_id ): string {
		if ( ! function_exists( 'pll_get_post_language' ) ) {
			// Polylang absent: an unassignable record cannot be proven PT.
			return '';
		}

		$language = pll_get_post_language( $post_id, 'slug' );

		return is_string( $language ) ? $language : '';
	}

	/**
	 * A refusal with the same shape as a successful read, so a caller can
	 * handle one without first checking which kind it received.
	 *
	 * @param string $reason Explanation.
	 * @return array{ok:bool,digest:string,payload:array,reason:string}
	 */
	private static function refused( string $reason ): array {
		return array(
			'ok'      => false,
			'digest'  => '',
			'payload' => array(),
			'reason'  => $reason,
		);
	}
}
