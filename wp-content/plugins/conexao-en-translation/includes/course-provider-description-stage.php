<?php
/**
 * The `en-course-provider-description` stage for the shared rollout engine.
 *
 * The English layer for the Cursos archive is a DESCRIPTION-LEVEL translation on
 * the SAME Portuguese records — the strategy the `wp-translation-rollout` skill
 * sanctions as "an authored EN field on the same record (the Lazer card
 * description precedent, `_leisure_excerpt_en`)". `course_provider` is a
 * documented **B2** directory type (`conexao_b2_post_types()`), exactly like
 * `leisure`, so this stage creates no EN `course_provider` post, no second
 * identity, no term and no slug. It writes exactly one post-meta field,
 * `_provider_excerpt_en`.
 *
 * This file contains the WordPress-bound adapter and the declarative stage
 * configuration ONLY. The lifecycle — inventory, manifest validation, dry-run
 * plan, snapshot, apply, PT-drift guard, verify, numeric gate, remove — belongs
 * to `Conexao_Translation_Rollout_Engine` and is not re-implemented here. The
 * engine file itself is used unmodified, including its additive `stage_conflict`
 * state key, which this stage uses to refuse a stale translation.
 *
 * How the engine's record-oriented vocabulary maps onto a
 * field-on-the-same-record strategy:
 *
 *   - `find_pt()`        the published PT `course_provider` record for the
 *                        manifest slug.
 *   - `find_en_for_pt()` reports the record as "already translated" (en_id =
 *                        pt_id, pair_ok = true) ONLY when a non-empty
 *                        `_provider_excerpt_en` is stored; otherwise absent, so
 *                        the engine plans a create. A changed PT `post_excerpt`
 *                        is reported as a `stage_conflict` so a stale
 *                        translation is never silently written.
 *   - `create_en()`      writes `_provider_excerpt_en` and returns the PT id.
 *   - `repair_en()`      rewrites the field when the stored English differs.
 *   - `link_pair()`      a deliberate no-op — there is no second identity to
 *                        link, so there is no Polylang pair to create.
 *   - `pair_ok()`        asserts the stored value equals the authored English.
 *   - `remove_en()`      deletes the field (rollback; B2 fallback re-engages).
 *   - `slug_collision()` always false — this stage never mints a slug, so no
 *                        URL can collide.
 *
 * PT-drift protection reuses the Leisure normaliser unchanged: HTML entities
 * decoded, whitespace collapsed and typographic punctuation folded, so an
 * entity-encoding artifact or a curly-vs-straight apostrophe is not mistaken for
 * content drift, while any real change to the Portuguese words is refused as a
 * hard conflict that fails the numeric gate.
 *
 * @package Conexao_EN_Translation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The post meta key that carries the authored English card description.
 *
 * The theme reads it through `conexao_provider_card_excerpt()`
 * (theme `inc/i18n/fallback.php`). It follows the `_leisure_excerpt_en`
 * convention within this post type's own `_provider_*` meta namespace.
 */
define( 'CONEXAO_EN_COURSE_PROVIDER_DESCRIPTION_META', '_provider_excerpt_en' );

/**
 * Normalise a string for the PT-drift comparison.
 *
 * Deliberately the same narrow normalisation the Leisure description stage uses:
 * HTML entities, runs of whitespace and typographic punctuation only. It does
 * NOT fold case, accents or word characters, so a real edit to the Portuguese
 * description still compares as different and is refused.
 *
 * @param string $value Raw value.
 * @return string Normalised value.
 */
function conexao_en_translation_course_provider_normalize( string $value ): string {
	$value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );

	$value = strtr(
		$value,
		array(
			"\xE2\x80\x99" => "'",  // right single quotation mark
			"\xE2\x80\x98" => "'",  // left single quotation mark
			"\xE2\x80\x9B" => "'",  // single high-reversed-9 quotation mark
			"\xE2\x80\x9C" => '"',  // left double quotation mark
			"\xE2\x80\x9D" => '"',  // right double quotation mark
			"\xE2\x80\x93" => '-',  // en dash
			"\xE2\x80\x94" => '-',  // em dash
			"\xE2\x80\xA6" => '...', // horizontal ellipsis
			"\xC2\xA0"     => ' ',  // no-break space
		)
	);

	$value = preg_replace( '/\s+/u', ' ', $value );

	return trim( (string) $value );
}


/**
 * The manifest rows for the stage, keyed by PT slug.
 *
 * Every row carries the authored Portuguese source it was translated from, so
 * the adapter can refuse a stale translation, plus the authored English the
 * apply writes.
 *
 * @return array{source_lang:string,target_lang:string,records:array<string,array<string,string>>}
 */
function conexao_en_translation_course_provider_description_manifest(): array {
	$rows = function_exists( 'conexao_en_translation_course_provider_description_data_v1' )
		? conexao_en_translation_course_provider_description_data_v1()
		: array();

	$records = array();

	foreach ( $rows as $slug => $row ) {
		$en = isset( $row['en_description'] ) ? trim( (string) $row['en_description'] ) : '';

		// An English description is the entire payload of this stage. A row
		// without one is not a translation and is never sent to the engine,
		// which would otherwise fail closed on the missing required key.
		if ( '' === $en ) {
			continue;
		}

		$records[ (string) $slug ] = array(
			// This stage mints NO url: the English description lives on the same
			// record, so the engine's identity key is the authored PT slug
			// unchanged. Supplying it keeps the engine's duplicate-key
			// validation meaningful instead of bypassing a check.
			'en_slug'        => (string) $slug,
			'pt_source'      => isset( $row['pt_source'] ) ? (string) $row['pt_source'] : '',
			'pt_title'       => isset( $row['pt_title'] ) ? (string) $row['pt_title'] : '',
			'en_description' => $en,
		);
	}

	return array(
		'source_lang' => 'pt',
		'target_lang' => 'en',
		'records'     => $records,
	);
}

/**
 * Find the published PT `course_provider` record for a manifest slug.
 *
 * The lookup is language-unfiltered (`lang` => '' and `suppress_filters`): the
 * records are Portuguese and Polylang would otherwise scope the query away from
 * them, which is exactly the class of bug the engine's identity rules forbid.
 *
 * @param string $slug PT slug (the portable stable key).
 * @return array{id:int,status:string}|null
 */
function conexao_en_translation_course_provider_find_pt( string $slug ) {
	$posts = get_posts(
		array(
			'post_type'        => 'course_provider',
			'name'             => $slug,
			'post_status'      => 'publish',
			'numberposts'      => 1,
			'no_found_rows'    => true,
			'lang'             => '',
			'suppress_filters' => true,
		)
	);

	if ( empty( $posts ) || ! $posts[0] instanceof WP_Post ) {
		return null;
	}

	return array(
		'id'     => (int) $posts[0]->ID,
		'status' => (string) $posts[0]->post_status,
	);
}

/**
 * Report whether the record already carries the authored English description.
 *
 * The engine reads `en_id`/`pair_ok` to decide between create, update and skip,
 * so this maps "the English field is populated" onto "a translation exists". A
 * record whose stored English differs from the authored row is reported as
 * repairable, which is what puts it in the engine's `update` category.
 *
 * @param int    $pt_id           PT post ID.
 * @param string $expected_en     The authored English for this row.
 * @param string $expected_source The authored Portuguese source for this row.
 * @return array{en_id:int,en_status:string,pair_ok:bool,en_slug_matches:bool,stage_conflict:string}
 */
function conexao_en_translation_course_provider_find_en_for_pt( int $pt_id, string $expected_en = '', string $expected_source = '' ) {
	$absent = array(
		'en_id'           => 0,
		'en_status'       => 'absent',
		'pair_ok'         => false,
		'en_slug_matches' => true,
		'stage_conflict'  => '',
	);

	if ( $pt_id <= 0 ) {
		return $absent;
	}

	$post = get_post( $pt_id );

	if ( ! $post instanceof WP_Post ) {
		return $absent;
	}

	// PT-drift guard. The English was authored against a specific Portuguese
	// description. If that description has since changed, the translation is
	// stale and must never silently land: the row is declared a hard conflict,
	// so the engine writes nothing for it and the numeric gate fails.
	if ( '' !== $expected_source
		&& conexao_en_translation_course_provider_normalize( (string) $post->post_excerpt )
			!== conexao_en_translation_course_provider_normalize( $expected_source ) ) {
		$absent['stage_conflict'] = 'PT source changed since the English was authored — re-author the description (refusing to write a stale translation)';

		return $absent;
	}

	$stored = trim( (string) get_post_meta( $pt_id, CONEXAO_EN_COURSE_PROVIDER_DESCRIPTION_META, true ) );

	if ( '' === $stored ) {
		return $absent;
	}

	return array(
		'en_id'           => $pt_id,
		'en_status'       => 'publish',
		'pair_ok'         => true,
		'en_slug_matches' => ( '' === $expected_en ) ? true : ( $stored === $expected_en ),
		'stage_conflict'  => '',
	);
}

/**
 * Snapshot the PT fields this stage must never change.
 *
 * `_provider_excerpt_en` is deliberately EXCLUDED: it is the field this stage
 * owns, so including it would make every apply report PT drift. Everything that
 * defines the Portuguese record — identity, content, taxonomy assignment, media
 * and the provider's own meta — IS included, and the engine re-compares this
 * exact snapshot after the last write.
 *
 * @param int $post_id PT post ID.
 * @return array<string,mixed>
 */
function conexao_en_translation_course_provider_snapshot( int $post_id ): array {
	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post ) {
		return array();
	}

	$terms = array();
	foreach ( array( 'conexao_category', 'conexao_county', 'conexao_tag', 'conexao_town' ) as $taxonomy ) {
		$ids = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
		$terms[ $taxonomy ] = is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
	}

	$meta = array();
	foreach ( array( '_provider_logo', '_provider_category', '_provider_location', '_provider_url', '_provider_status', '_provider_order', 'conexao_meta_description' ) as $key ) {
		$meta[ $key ] = (string) get_post_meta( $post_id, $key, true );
	}

	return array(
		'post_name'    => (string) $post->post_name,
		'post_title'   => (string) $post->post_title,
		'post_content' => (string) $post->post_content,
		'post_excerpt' => (string) $post->post_excerpt,
		'post_status'  => (string) $post->post_status,
		'post_date'    => (string) $post->post_date,
		'post_author'  => (int) $post->post_author,
		'menu_order'   => (int) $post->menu_order,
		'thumbnail'    => (int) get_post_thumbnail_id( $post_id ),
		'terms'        => $terms,
		'meta'         => $meta,
		'language'     => function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $post_id, 'slug' ) : '',
	);
}

/**
 * Look up the authored manifest row for a stable key.
 *
 * @param string $slug PT slug.
 * @return array<string,string>
 */
function conexao_en_translation_course_provider_row_for_slug( string $slug ): array {
	$manifest = conexao_en_translation_course_provider_description_manifest();

	return isset( $manifest['records'][ $slug ] ) ? $manifest['records'][ $slug ] : array();
}

/**
 * Look up the authored manifest row that belongs to a live record.
 *
 * @param int $post_id PT post ID.
 * @return array<string,string>
 */
function conexao_en_translation_course_provider_row_for_post( int $post_id ): array {
	$slug = (string) get_post_field( 'post_name', $post_id );

	return '' === $slug ? array() : conexao_en_translation_course_provider_row_for_slug( $slug );
}

/**
 * Write the authored English description onto the existing PT record.
 *
 * The ONLY write this stage performs. It creates no post, touches no post row,
 * no taxonomy and no other meta key.
 *
 * @param int   $pt_id PT post ID.
 * @param array $row   Manifest row.
 * @return int|true|WP_Error Post ID on success, true when the value was already
 *                          correct, WP_Error when the write is impossible.
 */
function conexao_en_translation_course_provider_write( int $pt_id, array $row ) {
	$description = isset( $row['en_description'] ) ? trim( (string) $row['en_description'] ) : '';

	if ( '' === $description || $pt_id <= 0 ) {
		return new WP_Error( 'conexao_course_provider_no_description', 'The manifest row carries no English description.' );
	}

	$stored = trim( (string) get_post_meta( $pt_id, CONEXAO_EN_COURSE_PROVIDER_DESCRIPTION_META, true ) );

	// Idempotent by construction: an already-correct record is not rewritten.
	if ( $stored === $description ) {
		return true;
	}

	$result = update_post_meta( $pt_id, CONEXAO_EN_COURSE_PROVIDER_DESCRIPTION_META, $description );

	if ( false === $result && $stored !== $description ) {
		return new WP_Error( 'conexao_course_provider_write_failed', 'update_post_meta() did not store the English description.' );
	}

	return $pt_id;
}

/**
 * The snapshot callback the engine captures before the first write.
 *
 * @param int $post_id PT post ID.
 * @return array<string,mixed>
 */
function conexao_en_translation_course_provider_snapshot_callback( int $post_id ): array {
	return conexao_en_translation_course_provider_snapshot( $post_id );
}

/**
 * The manifest rows the engine validates.

/**
 * The declarative stage configuration.
 *
 * No lifecycle code lives here: the engine owns the plan, the writes, the
 * PT-drift guard, the gate and the remove traversal.
 *
 * @return array<string,mixed>
 */
function conexao_en_translation_course_provider_description_config(): array {
	return array(
		'stage'            => 'en-course-provider-description',
		'source_post_type' => 'course_provider',
		'source_lang'      => 'pt',
		'target_lang'      => 'en',
		// Removing the field is a first-class rollback: it deletes only
		// `_provider_excerpt_en` and re-asserts PT immutability, and the approved
		// B2 fallback re-engages automatically. The EN layer here is a single
		// reproducible field, so remove is safe to claim — the same justification
		// the Stage 7 Leisure description stage records.
		'allow_remove'     => true,

		'manifest_callback'      => 'conexao_en_translation_course_provider_manifest_callback',
		'snapshot_callback'      => 'conexao_en_translation_course_provider_snapshot_callback',
		'build_en_args_callback' => static function ( array $row ) {
			// This stage performs no insert, so there are no insert arguments.
			// The callback exists because the engine's config contract requires
			// it; returning the row keeps the contract honest about what would
			// be written if an insert ever existed.
			unset( $row );

			return array();
		},
		'copy_fields_callback'   => static function ( int $pt_id, int $en_id, array $row ) {
			// The description IS the whole payload: `create_en`/`repair_en`
			// already wrote it. There are no shared fields to copy, because
			// there is no second record to copy them onto.
			unset( $pt_id, $en_id, $row );

			return 0;
		},

		'run_callback'           => static function ( array $args = array() ) {
			return Conexao_Translation_Rollout_Engine::run(
				conexao_en_translation_course_provider_description_config(),
				conexao_en_translation_course_provider_description_adapter(),
				$args
			);
		},
	);
}

/**
 * The WordPress-bound primitives the engine orchestrates for this stage.
 *
 * @return array<string,callable>
 */
function conexao_en_translation_course_provider_description_adapter(): array {
	return array(
		'find_pt'        => static function ( string $stable_key ) {
			return conexao_en_translation_course_provider_find_pt( $stable_key );
		},
		'find_en_for_pt' => static function ( int $pt_id, string $expected_slug = '' ) {
			$row = conexao_en_translation_course_provider_row_for_slug( $expected_slug );

			return conexao_en_translation_course_provider_find_en_for_pt(
				$pt_id,
				isset( $row['en_description'] ) ? (string) $row['en_description'] : '',
				isset( $row['pt_source'] ) ? (string) $row['pt_source'] : ''
			);
		},
		// This stage never mints a slug, so no url can collide. Reported as a
		// real boolean rather than omitted, so the engine's check still runs.
		'slug_collision' => static function ( string $en_slug, int $pt_id ) {
			unset( $en_slug, $pt_id );

			return false;
		},
		'create_en'      => static function ( int $pt_id, array $row ) {
			return conexao_en_translation_course_provider_write( $pt_id, $row );
		},
		'repair_en'      => static function ( int $pt_id, int $en_id, array $row ) {
			// The "EN record" and the PT record are the same record for this
			// strategy, so the repair is the same single-field write.
			return conexao_en_translation_course_provider_write( $pt_id, $row );
		},
		'link_pair'      => static function ( int $pt_id, int $en_id ) {
			// Deliberate no-op, and deliberately not a stub: this stage creates
			// NO second identity, so there is no Polylang pair to create. The
			// single-record English layer is what preserves "one identity".
			unset( $pt_id, $en_id );

			return true;
		},
		'pair_ok'        => static function ( int $pt_id, int $en_id ) {
			$row = conexao_en_translation_course_provider_row_for_post( $pt_id );

			if ( empty( $row ) ) {
				return false;
			}

			$stored = trim( (string) get_post_meta( $pt_id, CONEXAO_EN_COURSE_PROVIDER_DESCRIPTION_META, true ) );

			return '' !== $stored && $stored === (string) $row['en_description'];
		},
		'remove_en'      => static function ( int $en_id ) {
			// For this strategy en_id IS the pt_id. Deleting the field is the
			// documented rollback: /en/cursos/ returns to the B2 fallback.
			return (bool) delete_post_meta( $en_id, CONEXAO_EN_COURSE_PROVIDER_DESCRIPTION_META );
		},
	);
}

/**
 * The manifest rows the engine validates.
 *
 * @return array{source_lang:string,target_lang:string,records:array<string,array<string,string>>}
 */
function conexao_en_translation_course_provider_manifest_callback(): array {
	return conexao_en_translation_course_provider_description_manifest();
}
