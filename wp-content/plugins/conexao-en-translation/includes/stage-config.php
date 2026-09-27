<?php
/**
 * Stage M declarative configuration for the shared translation-rollout engine.
 *
 * One stage per B1 post type. This file contains the WordPress-bound adapter
 * and the stage declaration only; the engine owns the orchestration.
 *
 * @package Conexao_EN_Translation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The WordPress-bound primitives the engine orchestrates.
 *
 * @param string $post_type Post type.
 * @return array<string,callable>
 */
function conexao_en_translation_engine_adapter( string $post_type ): array {
	return array(
		'find_pt'        => static function ( string $stable_key ) use ( $post_type ) {
			$post = get_page_by_path( $stable_key, OBJECT, $post_type );

			if ( ! $post instanceof WP_Post ) {
				return null;
			}

			return array(
				'id'     => (int) $post->ID,
				'status' => (string) $post->post_status,
			);
		},
		'find_en_for_pt' => static function ( int $pt_id, string $expected_en_slug = '' ) {
			$absent = array(
				'en_id'           => 0,
				'en_status'       => 'absent',
				'pair_ok'         => false,
				'en_slug_matches' => true,
			);

			if ( ! function_exists( 'pll_get_post' ) ) {
				return $absent;
			}

			$en_id = (int) pll_get_post( $pt_id, 'en' );

			if ( $en_id <= 0 || $en_id === $pt_id ) {
				return $absent;
			}

			$actual = (string) get_post_field( 'post_name', $en_id );

			return array(
				'en_id'           => $en_id,
				'en_status'       => (string) get_post_status( $en_id ),
				'pair_ok'         => conexao_en_translation_pair_ok( $pt_id, $en_id ),
				'en_slug_matches' => ( '' === $expected_en_slug || $actual === $expected_en_slug ),
			);
		},
		'slug_collision' => static function ( string $en_slug, int $pt_id ) use ( $post_type ) {
			if ( '' === $en_slug ) {
				return true;
			}

			$hit = get_page_by_path( $en_slug, OBJECT, $post_type );

			if ( ! $hit instanceof WP_Post ) {
				return false;
			}

			// Re-using the PT record's own slug is not a clash.
			if ( (int) $hit->ID === $pt_id ) {
				return false;
			}

			// The record that holds the slug is the EN translation this PT
			// record is already (or should be) paired with. That is the
			// existing translation, not a clash, and it is what makes a
			// re-run idempotent.
			if ( (int) pll_get_post( $hit->ID, 'pt' ) === $pt_id ) {
				return false;
			}

			// ANY other record holding the slug makes the EN URL ambiguous.
			// This is deliberately stricter than "is it linked at all": a
			// second page on a live slug is a duplicate identity in the URL
			// space, which the standard forbids just as firmly as a duplicate
			// database record.
			return true;
		},
		'create_en'      => static function ( int $pt_id, array $row ) use ( $post_type ) {
			$pt = get_post( $pt_id );

			if ( ! $pt instanceof WP_Post ) {
				return new WP_Error( 'conexao_en_missing_pt', 'PT record missing.' );
			}

			$en_id = wp_insert_post(
				array(
					'post_type'     => $post_type,
					'post_name'     => (string) $row['en_slug'],
					'post_title'    => (string) $row['en_title'],
					'post_content'  => (string) $row['en_content'],
					'post_excerpt'  => (string) $row['en_excerpt'],
					'post_status'   => 'publish',
					'post_date'     => $pt->post_date,
					'post_date_gmt' => $pt->post_date_gmt,
					'post_author'   => (int) $pt->post_author,
					'menu_order'    => (int) $pt->menu_order,
				),
				true
			);

			return is_wp_error( $en_id ) ? $en_id : (int) $en_id;
		},
		'repair_en'      => static function ( int $pt_id, int $en_id, array $row ): bool {
			$update = array( 'ID' => $en_id );
			$en     = get_post( $en_id );

			if ( $en instanceof WP_Post && $en->post_name !== (string) $row['en_slug'] ) {
				$update['post_name'] = (string) $row['en_slug'];
			}
			if ( $en instanceof WP_Post && $en->post_title !== (string) $row['en_title'] ) {
				$update['post_title'] = (string) $row['en_title'];
			}
			if ( $en instanceof WP_Post && $en->post_content !== (string) $row['en_content'] ) {
				$update['post_content'] = (string) $row['en_content'];
			}
			if ( $en instanceof WP_Post && $en->post_excerpt !== (string) $row['en_excerpt'] ) {
				$update['post_excerpt'] = (string) $row['en_excerpt'];
			}

			if ( count( $update ) > 1 ) {
				wp_update_post( $update );
			}

			return true;
		},
		'link_pair'      => static function ( int $pt_id, int $en_id ): bool {
			if ( ! function_exists( 'pll_set_post_language' ) ) {
				return false;
			}

			pll_set_post_language( $en_id, 'en' );

			if ( function_exists( 'pll_get_post_language' ) && ! pll_get_post_language( $pt_id ) ) {
				pll_set_post_language( $pt_id, 'pt' );
			}

			if ( function_exists( 'pll_save_post_translations' ) ) {
				pll_save_post_translations(
					array(
						'pt' => $pt_id,
						'en' => $en_id,
					)
				);
			}

			return true;
		},
		'pair_ok'        => static function ( int $pt_id, int $en_id ): bool {
			return conexao_en_translation_pair_ok( $pt_id, $en_id );
		},
		'remove_en'      => static function ( int $en_id ): bool {
			return (bool) wp_delete_post( $en_id, true );
		},
	);
}

/**
 * The declarative stage configuration for one post type.
 *
 * @param string $post_type Post type.
 * @return array<string,mixed>
 */
function conexao_en_translation_engine_config( string $post_type ): array {
	return array(
		'stage'                   => 'en-' . $post_type,
		'source_post_type'        => $post_type,
		'source_lang'             => 'pt',
		'target_lang'             => 'en',
		'manifest_callback'       => static function () use ( $post_type ) {
			return conexao_en_translation_manifest_for( $post_type );
		},
		'snapshot_callback'       => 'conexao_en_translation_snapshot',
		'build_en_args_callback'  => static function () use ( $post_type ) {
			return conexao_en_translation_manifest_for( $post_type );
		},
		'copy_fields_callback'    => 'conexao_en_translation_copy_fields',
		'verify_landing_callback' => null,
		'extra_gate_callback'     => null,
		// Removal is safe and is the documented rollback: it deletes only the EN
		// records this manifest owns, and never touches a PT original.
		'allow_remove'            => true,
		'run_callback'            => static function ( array $args = array() ) use ( $post_type ) {
			return Conexao_Translation_Rollout_Engine::run(
				conexao_en_translation_engine_config( $post_type ),
				conexao_en_translation_engine_adapter( $post_type ),
				$args
			);
		},
	);
}

/*
 * -------------------------------------------------------------------------
 * STAGE O — the Blog POSTS PAGE stage (`en-blog-page`).
 *
 * One record, one stage, the SAME shared engine and the SAME WordPress-bound
 * primitives as the guide/page/post stages above. It is a separate stage for
 * two reasons, both verified at the starting SHA:
 *
 *   1. SCOPE. The `page` stage already plans two unrelated slug repairs
 *      (`jobs-2`, `newsletter`: created before the shared-slug hook existed,
 *      so they drifted to `jobs-2-2` / `newsletter-2`). Adding the `blog` row
 *      to that manifest and applying it would perform those two writes too.
 *      Stage O owns the Blog page and nothing else.
 *   2. SHARED SLUG. The EN posts page must live at the SAME path as the PT one
 *      (`/en/blog/`), so it reuses the `blog` post_name. WordPress makes page
 *      slugs unique per tree, so a plain `wp_insert_post()` would silently
 *      rename it to `blog-2` and break the route. The filter below keeps the
 *      authored slug for this one record only.
 *
 * It is NOT a second translation engine: orchestration, planning, counting,
 * snapshot comparison, the numeric gate and rollback all still come from
 * `Conexao_Translation_Rollout_Engine::run()`.
 * ----------------------------------------------------------------------
 */

/**
 * The slug this stage must keep shared between the PT and EN records.
 *
 * @return string PT/EN shared slug, or '' when the manifest declares none.
 */
function conexao_en_translation_blog_page_shared_slug(): string {
	foreach ( conexao_en_translation_blog_page_manifest()['records'] as $stable_key => $row ) {
		if ( (string) $row['en_slug'] === (string) $stable_key ) {
			return (string) $stable_key;
		}
	}

	return '';
}

/**
 * Keep the authored shared slug instead of WordPress' uniquified `-2` variant.
 *
 * Scoped by a transient holding the ONE slug this stage may share, so it can
 * never affect an unrelated page, a post, an upload or any other stage. This
 * is the minimum necessary to keep `/en/blog/` routable; the same problem is
 * why the pre-existing `newsletter` EN record drifted to `newsletter-2`, which
 * Stage O deliberately does not repair.
 *
 * @param string $slug          Slug proposed by wp_unique_post_slug().
 * @param int    $post_id       Post ID.
 * @param string $post_status   Post status.
 * @param string $post_type     Post type.
 * @param int    $post_parent   Post parent.
 * @param string $original_slug Original slug.
 * @return string
 */
function conexao_en_translation_blog_page_shared_slug_filter( $slug, $post_id, $post_status, $post_type, $post_parent, $original_slug ) {
	$desired = get_transient( 'conexao_en_translation_blog_page_shared_slug' );

	if ( ! $desired || 'page' !== $post_type || (string) $original_slug !== (string) $desired ) {
		return $slug;
	}

	return (string) $desired;
}

/**
 * Arm the shared-slug filter for the duration of one write.
 *
 * ## Why the scope is a whole callback, not a single `wp_insert_post()`
 *
 * `wp_unique_post_slug()` does not run once per apply. `create_en` inserts the
 * EN page (slug uniquified against the PT `blog`), and then `copy_fields` calls
 * `wp_update_post()` on that same record, which runs the uniquifier AGAIN with
 * the slug it already has — and by then the PT `blog` is a sibling in the same
 * page tree, so core would rename the EN record to `blog-2` and silently
 * break `/en/blog/`.
 *
 * Scoping the filter to a single write is therefore not enough: it must cover
 * every write the apply performs. The narrowest correct scope is the stage's
 * own `run_callback`, which is exactly "the apply of this one record" and
 * nothing else. A dry-run performs zero writes, so arming it there is inert.
 *
 * @param callable $write The work to perform while the filter is active.
 * @param mixed    $args  Optional second argument forwarded to `$write`.
 * @return mixed Whatever `$write` returns.
 */
function conexao_en_translation_blog_page_with_shared_slug( callable $write, $args = null ) {
	$desired = conexao_en_translation_blog_page_shared_slug();

	if ( '' === $desired ) {
		return $write( $args );
	}

	set_transient( 'conexao_en_translation_blog_page_shared_slug', $desired, 5 * MINUTE_IN_SECONDS );
	add_filter( 'wp_unique_post_slug', 'conexao_en_translation_blog_page_shared_slug_filter', 10, 6 );

	try {
		return $write( $args );
	} finally {
		remove_filter( 'wp_unique_post_slug', 'conexao_en_translation_blog_page_shared_slug_filter', 10 );
		delete_transient( 'conexao_en_translation_blog_page_shared_slug' );
	}
}

/**
 * The WordPress-bound primitives for the Blog posts page stage.
 *
 * Deliberately the SAME semantics as `conexao_en_translation_engine_adapter()`
 * — reuse over reinvention (engineering standard §2). No primitive is
 * rewritten here: the shared-slug filter is armed once around the whole apply
 * by `conexao_en_translation_blog_page_config()` instead, so every write the
 * engine performs (insert, then the `copy_fields` update) is covered.
 *
 * The ONE addition is `find_en_for_pt`. See that closure for why a shared slug
 * needs a stage-level duplicate check that the generic adapter cannot express.
 *
 * @return array<string,callable>
 */
function conexao_en_translation_blog_page_adapter(): array {
	$adapter = conexao_en_translation_engine_adapter( 'page' );

	$generic_find_en = $adapter['find_en_for_pt'];

	$adapter['find_en_for_pt'] = static function ( int $pt_id, string $expected_en_slug = '' ) use ( $generic_find_en ) {
		$found = call_user_func( $generic_find_en, $pt_id, $expected_en_slug );

		// A correct, linked EN translation is all this stage ever wants.
		if ( ! empty( $found['en_id'] ) && ! empty( $found['pair_ok'] ) ) {
			return $found;
		}

		// SHARED-SLUG DUPLICATE GUARD.
		//
		// The EN posts page intentionally reuses the PT `blog` post_name, so
		// `get_page_by_path('blog')` in the generic `slug_collision` resolves to
		// the PT record itself and the generic check always reports "no clash".
		// That means a SECOND, unlinked page already sitting on the shared slug
		// would be invisible to the generic guard, and the engine would happily
		// create a duplicate EN identity beside it — the exact fork the standard
		// forbids (§0.2 "English is a layer, never a fork").
		//
		// So the stage checks the shared slug explicitly: if any OTHER page
		// record already holds the EN post_name this stage must create, and it
		// is not the linked EN translation, the record is reported as PRESENT
		// but NOT pair_ok, which the engine classifies as a hard conflict
		// ("EN record exists but the pair link is broken") instead of a create.
		if ( '' !== $expected_en_slug && function_exists( 'pll_get_post' ) ) {
			$candidates = get_posts(
				array(
					'post_type'      => 'page',
					'post_status'    => 'any',
					'name'           => $expected_en_slug,
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'lang'           => '',
				)
			);

			foreach ( (array) $candidates as $candidate_id ) {
				$candidate_id = (int) $candidate_id;

				if ( $candidate_id === $pt_id || (int) ( $found['en_id'] ?? 0 ) === $candidate_id ) {
					continue;
				}

				// A record already linked back to this PT page IS the translation.
				if ( (int) pll_get_post( $candidate_id, 'pt' ) === $pt_id ) {
					continue;
				}

				return array(
					'en_id'           => $candidate_id,
					'en_status'       => (string) get_post_status( $candidate_id ),
					'pair_ok'         => false,
					'en_slug_matches' => false,
					'duplicate'       => true,
				);
			}
		}

		return $found;
	};

	return $adapter;
}

/**
 * Refresh the routing caches that depend on the posts-page translation set.
 *
 * The Blog posts page is a routing object, not an ordinary page: Polylang
 * derives each language's `page_for_posts` from the translation set and caches
 * that mapping per language. Creating or removing the EN translation changes the
 * answer while the cache still holds the previous one, which leaves `/en/blog/`
 * answering 302 -> `/blog/` even though the EN page exists, is published and is
 * linked in both directions.
 *
 * This performs a pure cache invalidation — no record, option, term or rewrite
 * rule is written — and mirrors the refresh Polylang itself performs when the
 * static-pages options change. It is a no-op when Polylang is absent.
 *
 * @return void
 */
function conexao_en_translation_blog_page_refresh_routing_cache(): void {
	// Polylang caches each language's `page_for_posts` mapping, so that cache is
	// dropped whenever Polylang is reachable. The callable check (rather than a
	// bare method call) keeps this correct across Polylang versions and keeps
	// the guard a no-op when Polylang is absent.
	if ( function_exists( 'PLL' ) && function_exists( 'pll_languages_list' ) ) {
		$model = PLL()->model;

		if ( is_callable( array( $model, 'clean_languages_cache' ) ) ) {
			$model->clean_languages_cache();
		}

		pll_languages_list();
	}
}

/**
 * The declarative stage configuration for the Blog posts page.
 *
 * @return array<string,mixed>
 */
function conexao_en_translation_blog_page_config(): array {
	$config = conexao_en_translation_engine_config( 'page' );

	$config['stage']                  = 'en-blog-page';
	$config['manifest_callback']      = 'conexao_en_translation_blog_page_manifest';
	$config['build_en_args_callback'] = 'conexao_en_translation_blog_page_manifest';
	$config['run_callback']           = static function ( array $args = array() ) {
		$result = conexao_en_translation_blog_page_with_shared_slug(
			static function ( array $run_args ) {
				return Conexao_Translation_Rollout_Engine::run(
					conexao_en_translation_blog_page_config(),
					conexao_en_translation_blog_page_adapter(),
					$run_args
				);
			},
			$args
		);

		// The posts page is a ROUTING object: Polylang resolves each language's
		// `page_for_posts` from the translation set and CACHES that mapping per
		// language. Creating or removing the EN translation therefore changes the
		// answer while the cache still holds the old one, which leaves `/en/blog/`
		// answering 302 -> `/blog/` even though the EN page exists and is linked.
		// Refreshing the cache is the same thing Polylang itself does when the
		// option changes, and it is a pure cache invalidation: no record, option
		// or rewrite rule is written.
		conexao_en_translation_blog_page_refresh_routing_cache();

		return $result;
	};

	return $config;
}

/**
 * The stage identifiers this plugin registers with the shared engine.
 *
 * The three B1 post types, the Stage O Blog posts page and the Stage 7 Leisure
 * card-description stage. The runner reads this list, so there is still exactly
 * ONE place that knows which stages exist and exactly ONE runner.
 *
 * @return string[] Stage identifiers.
 */
function conexao_en_translation_stage_ids(): array {
	$ids = array();

	foreach ( conexao_en_translation_post_types() as $post_type ) {
		$ids[] = 'en-' . $post_type;
	}

	$ids[] = 'en-blog-page';
	$ids[] = 'en-leisure-description';

	return $ids;
}
