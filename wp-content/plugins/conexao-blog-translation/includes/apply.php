<?php
/**
 * Stage 5 — Blog EN translation apply engine.
 *
 * Shared by the admin importer (Tools → EN Blog Translations) and the WP-CLI
 * runner (scripts/run-blog-translation.php).
 *
 * Contract:
 *   - the Portuguese originals are NEVER modified. The only Portuguese writes
 *     ever performed are Polylang linkage backfills (a record with no language
 *     yet gets the default language so it can be linked) — every PT post is
 *     snapshotted before the run and compared after it;
 *   - exactly ONE linked English translation per Portuguese post;
 *   - idempotent: re-running updates the EN record it owns instead of creating
 *     a second one, and never touches an EN record that is not in the manifest;
 *   - the Polylang relationship is verified from BOTH directions before a row
 *     is reported as successful;
 *   - internal links are resolved through the Polylang relationship of the
 *     destination post (pll_get_post), never by replacing strings;
 *   - a real EN posts page is created and linked so `/en/blog/` stops being a
 *     B2 fallback (the theme retires the fallback automatically once the
 *     posts page has a linked EN translation — see inc/i18n/fallback.php).
 *
 * @package Conexao_Blog_Translation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable identity + content snapshot of a post (PT regression gate).
 *
 * @param int $post_id Post ID.
 * @return array
 */
function conexao_blog_translation_snapshot_post( int $post_id ): array {
	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post ) {
		return array();
	}

	return array(
		'post_name'      => $post->post_name,
		'post_title'     => $post->post_title,
		'post_content'   => $post->post_content,
		'post_excerpt'   => $post->post_excerpt,
		'post_status'    => $post->post_status,
		'post_date'      => $post->post_date,
		'post_author'    => (int) $post->post_author,
		'thumbnail'      => (int) get_post_thumbnail_id( $post_id ),
		'categories'     => wp_get_post_terms( $post_id, 'category', array( 'fields' => 'ids' ) ),
		'tags'           => wp_get_post_terms( $post_id, 'post_tag', array( 'fields' => 'ids' ) ),
		'language'       => function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $post_id, 'slug' ) : '',
		'meta_desc'      => (string) get_post_meta( $post_id, 'conexao_meta_description', true ),
	);
}

/**
 * Both-directions check of a Polylang post relationship.
 *
 * @param int $pt_id Portuguese post ID.
 * @param int $en_id English post ID.
 * @return bool
 */
function conexao_blog_translation_pair_ok( int $pt_id, int $en_id ): bool {
	if ( $pt_id <= 0 || $en_id <= 0 || $pt_id === $en_id ) {
		return false;
	}

	return (int) pll_get_post( $pt_id, 'en' ) === $en_id
		&& (int) pll_get_post( $en_id, 'pt' ) === $pt_id;
}

/**
 * Force a shared post_name for the EN posts page (`blog` in both languages).
 *
 * Polylang Free has no shared-slug support: the EN posts page intentionally
 * reuses the Portuguese `blog` slug (the approved `/blog/` → `/en/blog/`
 * shapes are language prefixes of ONE canonical path, not translated slugs).
 *
 * @param string $slug          Slug proposed by wp_unique_post_slug().
 * @param int    $post_id       Post ID.
 * @param string $post_status   Post status.
 * @param string $post_type     Post type.
 * @param int    $post_parent   Post parent.
 * @param string $original_slug Original slug.
 * @return string
 */
function conexao_blog_translation_shared_slug( $slug, $post_id, $post_status, $post_type, $post_parent, $original_slug ) {
	$desired = get_transient( 'conexao_blog_translation_shared_slug' );

	if ( ! $desired || 'page' !== $post_type || $original_slug !== $desired ) {
		return $slug;
	}

	return $desired;
}

/**
 * Resolve the Polylang translation of a category term, creating the linked EN
 * term from the manifest when it does not exist yet.
 *
 * @param array $term_map PT slug => array( 'name', 'slug', 'description' ).
 * @param bool  $dry_run  Report only.
 * @param array $summary  Run summary (by reference).
 * @param array $rows     Report rows (by reference).
 * @return array<int,array{pt:int,en:int}> PT term ID => EN term ID map.
 */
function conexao_blog_translation_ensure_terms( array $term_map, bool $dry_run, array &$summary, array &$rows ): array {
	$pairs = array();

	foreach ( $term_map as $pt_slug => $en ) {
		$row = array(
			'pt_slug' => (string) $pt_slug,
			'action'  => 'skipped',
			'message' => '',
			'en_id'   => 0,
			'en_slug' => (string) $en['slug'],
		);

		$pt_term = get_term_by( 'slug', (string) $pt_slug, 'category' );

		if ( ! $pt_term ) {
			$row['message'] = 'PT category term not found in this site — nothing to translate';
			++$summary['terms_skipped'];
			$rows[] = $row;
			continue;
		}

		$pt_term_id = (int) $pt_term->term_id;

		if ( function_exists( 'pll_get_term_language' ) && ! pll_get_term_language( $pt_term_id ) ) {
			if ( ! $dry_run ) {
				pll_set_term_language( $pt_term_id, 'pt' );
			}
			$row['message'] = 'PT term language backfilled; ';
		}

		$existing_en = function_exists( 'pll_get_term' ) ? (int) pll_get_term( $pt_term_id, 'en' ) : 0;
		$en_term_id  = $existing_en;

		if ( $en_term_id <= 0 ) {
			$collision = get_term_by( 'slug', (string) $en['slug'], 'category' );

			if ( $collision && (int) $collision->term_id !== $pt_term_id ) {
				$row['action']  = 'error';
				$row['message'] = 'EN slug already used by another category term';
				++$summary['errors'];
				$rows[] = $row;
				continue;
			}

			if ( $dry_run ) {
				$row['action']  = 'would-create';
				$row['message'] = 'EN category term would be created and linked';
				++$summary['terms_created'];
				$rows[] = $row;
				continue;
			}

			$created = wp_insert_term(
				(string) $en['name'],
				'category',
				array(
					'slug'        => (string) $en['slug'],
					'description' => isset( $en['description'] ) ? (string) $en['description'] : '',
				)
			);

			if ( is_wp_error( $created ) ) {
				$row['action']  = 'error';
				$row['message'] = 'term create failed: ' . $created->get_error_message();
				++$summary['errors'];
				$rows[] = $row;
				continue;
			}

			$en_term_id = (int) $created['term_id'];
			pll_set_term_language( $en_term_id, 'en' );
			++$summary['terms_created'];
			$row['action']  = 'created';
			$row['message'] = 'EN category term created';
		} else {
			$row['action']  = 'exists';
			$row['message'] = 'EN category term already linked';
		}

		if ( $dry_run ) {
			$pairs[ $pt_term_id ] = $en_term_id;
			$rows[]               = $row;
			continue;
		}

		pll_save_term_translations( array( 'pt' => $pt_term_id, 'en' => $en_term_id ) );

		$check_pt = function_exists( 'pll_get_term' ) ? (int) pll_get_term( $en_term_id, 'pt' ) : 0;
		if ( $check_pt !== $pt_term_id ) {
			$row['action']  = 'error';
			$row['message'] = 'term translation link verification failed';
			++$summary['errors'];
			$rows[] = $row;
			continue;
		}

		$pairs[ $pt_term_id ] = $en_term_id;
		$row['en_id']         = $en_term_id;
		$row['message']       = trim( $row['message'] . ' — linked both ways' );
		++$summary['terms_linked'];
		$rows[] = $row;
	}

	return $pairs;
}

/**
 * Ensure the English translation of the Blog posts page exists and is linked.
 *
 * The posts page is NOT a post: it is the `page_for_posts` page record, so the
 * EN archive needs a real, linked EN Page carrying the same canonical path
 * (`/en/blog/`). Without it `/en/blog/` has nothing to serve and the theme
 * keeps the B2 fallback in force.
 *
 * @param array $page   EN copy from the manifest ( title, content, meta_desc ).
 * @param bool  $dry_run Report only.
 * @param array $summary Run summary (by reference).
 * @param array $rows    Report rows (by reference).
 * @return array{pt_id:int,en_id:int,status:string}
 */
function conexao_blog_translation_ensure_posts_page( array $page, bool $dry_run, array &$summary, array &$rows ): array {
	$out    = array( 'pt_id' => 0, 'en_id' => 0, 'status' => 'missing' );
	$pt_id  = (int) get_option( 'page_for_posts' );
	$out['pt_id'] = $pt_id;

	if ( $pt_id <= 0 ) {
		$summary['posts_page'] = 'no posts page configured';
		return $out;
	}

	$en_id = (int) pll_get_post( $pt_id, 'en' );

	if ( $en_id <= 0 ) {
		if ( $dry_run ) {
			$summary['posts_page'] = 'would-create';
			return $out;
		}

		// The EN posts page intentionally reuses the canonical `blog` path, so the
	// shared-slug filter must be active for the insert AND for every later
	// update of that page's slug (otherwise wp_unique_post_slug() renames it
	// to blog-2 the first time this migration is re-run).
	set_transient( 'conexao_blog_translation_shared_slug', (string) $page['slug'], 5 * MINUTE_IN_SECONDS );
		add_filter( 'wp_unique_post_slug', 'conexao_blog_translation_shared_slug', 10, 6 );

		$en_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_name'    => (string) $page['slug'],
				'post_title'   => (string) $page['title'],
				'post_content' => (string) $page['content'],
				'post_status'  => 'publish',
				'post_parent'  => 0,
				'menu_order'   => (int) get_post_field( 'menu_order', $pt_id ),
			),
			true
		);

		if ( is_wp_error( $en_id ) ) {
			$summary['posts_page'] = 'error: ' . $en_id->get_error_message();
			++$summary['errors'];
			return $out;
		}

		pll_set_post_language( (int) $en_id, 'en' );
		if ( ! pll_get_post_language( $pt_id ) ) {
			pll_set_post_language( $pt_id, 'pt' );
		}
		pll_save_post_translations( array( 'pt' => $pt_id, 'en' => (int) $en_id ) );

		$summary['posts_page'] = 'created';
		++$summary['posts_page_changes'];
	} else {
		$summary['posts_page'] = 'exists';
	}

	$en_id = (int) $en_id;

	if ( ! $dry_run ) {
		if ( ! empty( $page['meta_desc'] ) ) {
			update_post_meta( $en_id, 'conexao_meta_description', (string) $page['meta_desc'] );
		}
		$thumb = get_post_thumbnail_id( $pt_id );
		if ( $thumb ) {
			set_post_thumbnail( $en_id, $thumb );
		}
	}

	// Repair a drifted slug (a previous run, an editor, or a duplicate-slug
	// fallback) so the English archive always lives at the declared path.
	$en_page = get_post( $en_id );

	if ( $en_page instanceof WP_Post && $en_page->post_name !== (string) $page['slug'] ) {
		wp_update_post( array( 'ID' => $en_id, 'post_name' => (string) $page['slug'] ) );
		$summary['posts_page'] = 'slug-repaired';
	}

	remove_filter( 'wp_unique_post_slug', 'conexao_blog_translation_shared_slug', 10 );
	delete_transient( 'conexao_blog_translation_shared_slug' );

	// The posts page is a routing object: its per-language URL and the rewrite
	// rules that resolve it are derived from the Polylang language data, which is
	// cached. Refresh both so /en/blog/ is routable immediately after the run
	// (same refresh Polylang performs when the option itself changes).
	if ( function_exists( 'PLL' ) && PLL() && isset( PLL()->model ) ) {
		PLL()->model->clean_languages_cache();
		pll_languages_list();
	}
	flush_rewrite_rules( false );

	if ( ! conexao_blog_translation_pair_ok( $pt_id, $en_id ) ) {
		$summary['posts_page'] = 'error: translation link verification failed';
		++$summary['errors'];
		return array( 'pt_id' => $pt_id, 'en_id' => $en_id, 'status' => 'error' );
	}

	$rows[] = array(
		'pt_slug' => 'blog (posts page)',
		'action'  => $summary['posts_page'],
		'message' => sprintf( 'posts page #%d linked to EN posts page #%d (%s)', $pt_id, $en_id, get_permalink( $en_id ) ),
		'pt_id'   => $pt_id,
		'en_id'   => $en_id,
		'en_url'  => (string) get_permalink( $en_id ),
		'links'   => array(),
	);

	return array( 'pt_id' => $pt_id, 'en_id' => $en_id, 'status' => $summary['posts_page'] );
}

/**
 * Run the Blog EN translation migration.
 *
 * @param array $args { @type bool $dry_run Preview only (no writes at all). }
 * @return array Report: summary, rows, terms, excluded, audit.
 */
function conexao_blog_translation_run( array $args = array() ): array {
	$dry_run  = ! empty( $args['dry_run'] );
	$manifest = conexao_blog_translation_manifest();

	$summary = array(
		'created'             => 0,
		'updated'             => 0,
		'skipped'             => 0,
		'errors'              => 0,
		'terms_created'       => 0,
		'terms_linked'        => 0,
		'terms_skipped'       => 0,
		'links_localized'     => 0,
		'posts_page'          => 'unchanged',
		'posts_page_changes'  => 0,
		'pt_changed'          => 0,
	);

	$rows      = array();
	$term_rows = array();

	if ( ! conexao_polylang_active() ) {
		$summary['errors']++;
		$rows[] = array( 'pt_slug' => '—', 'action' => 'error', 'message' => 'Polylang is not active — the English layer must exist first.', 'pt_id' => 0, 'en_id' => 0, 'en_url' => '' );

		return array( 'summary' => $summary, 'rows' => $rows, 'terms' => array(), 'excluded' => array(), 'audit' => array() );
	}

	// PT checkpoint BEFORE the run (Phase 19 regression gate).
	$pt_before = array();
	foreach ( array_keys( $manifest['posts'] ) as $pt_slug ) {
		$pt_post = get_page_by_path( (string) $pt_slug, OBJECT, 'post' );
		if ( $pt_post instanceof WP_Post ) {
			$pt_before[ $pt_slug ] = conexao_blog_translation_snapshot_post( (int) $pt_post->ID );
		}
	}

	$term_pairs = conexao_blog_translation_ensure_terms( $manifest['terms'], $dry_run, $summary, $term_rows );

	conexao_blog_translation_ensure_posts_page( $manifest['posts_page'], $dry_run, $summary, $rows );

	// Pass 1 — create/update every EN post (content included, links untouched).
	$en_ids = array();

	foreach ( $manifest['posts'] as $pt_slug => $en ) {
		$row = array(
			'pt_slug' => (string) $pt_slug,
			'pt_id'   => 0,
			'en_id'   => 0,
			'en_url'  => '',
			'action'  => 'skipped',
			'message' => '',
			'links'   => array(),
		);

		$pt_post = get_page_by_path( (string) $pt_slug, OBJECT, 'post' );

		if ( ! $pt_post instanceof WP_Post ) {
			$row['message'] = 'PT post not present in this site — skipped (nothing to translate here)';
			++$summary['skipped'];
			$rows[] = $row;
			continue;
		}

		$pt_id        = (int) $pt_post->ID;
		$row['pt_id'] = $pt_id;

		if ( 'post' !== $pt_post->post_type ) {
			$row['action']  = 'error';
			$row['message'] = sprintf( 'PT source is a %s, not a post', $pt_post->post_type );
			++$summary['errors'];
			$rows[] = $row;
			continue;
		}

		if ( ! pll_get_post_language( $pt_id ) ) {
			if ( ! $dry_run ) {
				pll_set_post_language( $pt_id, 'pt' ); // linkage backfill only.
			}
		}

		$pt_lang = (string) pll_get_post_language( $pt_id, 'slug' );

		if ( '' !== $pt_lang && 'pt' !== $pt_lang ) {
			$row['action']  = 'error';
			$row['message'] = sprintf( 'PT source is in language "%s" — refusing to link an EN translation to non-Portuguese content', $pt_lang );
			++$summary['errors'];
			$rows[] = $row;
			continue;
		}

		$en_id       = (int) pll_get_post( $pt_id, 'en' );
		$existing    = $en_id > 0;
		$resolved_links = array();
		$en_content     = conexao_blog_translation_localize_links( (string) $en['en_content'], (array) $en['link_map'], $resolved_links );
		$row['links']   = $resolved_links;

		if ( $dry_run ) {
			$row['action']  = $existing ? 'would-update' : 'would-create';
			$row['en_id']   = $en_id;
			$row['en_url']  = $existing ? (string) get_permalink( $en_id ) : '';
			$row['message'] = $existing ? 'linked EN translation already exists — would refresh content' : 'would create + link EN translation';
			$existing ? $summary['updated']++ : $summary['created']++;
			$summary['links_localized'] += count( $resolved_links );
			$rows[] = $row;
			continue;
		}

		$postarr = array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'post_name'      => (string) $en['en_slug'],
			'post_title'     => (string) $en['en_title'],
			'post_content'   => $en_content,
			'post_excerpt'   => (string) $en['en_excerpt'],
			'post_author'    => (int) $pt_post->post_author,
			'post_date'      => $pt_post->post_date,
			'post_date_gmt'  => $pt_post->post_date_gmt,
			'comment_status' => $pt_post->comment_status,
			'ping_status'    => $pt_post->ping_status,
		);

		if ( $existing ) {
			$postarr['ID'] = $en_id;
			$en_id         = (int) wp_update_post( $postarr, true );
		} else {
			$en_id = (int) wp_insert_post( $postarr, true );
		}

		if ( is_wp_error( $en_id ) || $en_id <= 0 ) {
			$row['action']  = 'error';
			$row['message'] = 'post write failed: ' . ( is_wp_error( $en_id ) ? $en_id->get_error_message() : 'unknown error' );
			++$summary['errors'];
			$rows[] = $row;
			continue;
		}

		pll_set_post_language( $en_id, 'en' );
		pll_save_post_translations( array( 'pt' => $pt_id, 'en' => $en_id ) );

		if ( ! conexao_blog_translation_pair_ok( $pt_id, $en_id ) ) {
			$row['action']  = 'error';
			$row['message'] = sprintf( 'translation link verification failed (pt%d→en%d, en%d→pt%d)', $pt_id, (int) pll_get_post( $pt_id, 'en' ), $en_id, (int) pll_get_post( $en_id, 'pt' ) );
			++$summary['errors'];
			$rows[] = $row;
			continue;
		}

		// Featured image + taxonomy + SEO fields (shared media: same attachment).
		$thumb = (int) get_post_thumbnail_id( $pt_id );
		if ( $thumb ) {
			set_post_thumbnail( $en_id, $thumb );
		} else {
			delete_post_thumbnail( $en_id );
		}

		$en_term_ids = array();
		foreach ( wp_get_post_terms( $pt_id, 'category', array( 'fields' => 'ids' ) ) as $pt_term_id ) {
			if ( isset( $term_pairs[ (int) $pt_term_id ] ) ) {
				$en_term_ids[] = (int) $term_pairs[ (int) $pt_term_id ];
			}
		}
		if ( ! empty( $en_term_ids ) ) {
			wp_set_post_terms( $en_id, $en_term_ids, 'category', false );
		}

		if ( ! empty( $en['en_meta_description'] ) ) {
			update_post_meta( $en_id, 'conexao_meta_description', (string) $en['en_meta_description'] );
		}

		$en_ids[ $pt_slug ] = array( 'pt' => $pt_id, 'en' => $en_id );

		$row['action']  = $existing ? 'updated' : 'created';
		$row['en_id']   = $en_id;
		$row['en_url']  = (string) get_permalink( $en_id );
		$row['message'] = $existing ? 'linked EN translation refreshed' : 'linked EN translation created';
		$existing ? $summary['updated']++ : $summary['created']++;
		$summary['links_localized'] += count( $resolved_links );
		$rows[] = $row;
	}

	// Pass 2 — internal links between Blog posts. Runs AFTER pass 1 so every EN
	// sibling exists and the Polylang relationship can be resolved for real.
	if ( ! $dry_run ) {
		foreach ( $manifest['posts'] as $pt_slug => $en ) {
			$link_map = (array) $en['link_map'];

			if ( empty( $link_map ) || empty( $en_ids[ $pt_slug ] ) ) {
				continue;
			}

			$en_id  = (int) $en_ids[ $pt_slug ]['en'];
			$before = (string) get_post_field( 'post_content', $en_id );
			$resolved = array();
			$after    = conexao_blog_translation_localize_links( $before, $link_map, $resolved );

			if ( $after !== $before ) {
				wp_update_post( array( 'ID' => $en_id, 'post_content' => $after ) );
				$summary['links_localized'] += count( $resolved );

				foreach ( $rows as $index => $row ) {
					if ( (int) $row['en_id'] === $en_id ) {
						$rows[ $index ]['links'] = array_merge( (array) $row['links'], $resolved );
						$rows[ $index ]['message'] .= sprintf( '; %d internal link(s) localized', count( $resolved ) );
					}
				}
			}
		}
	}

	// Phase 19 gate — the Portuguese originals must be byte-identical after the
	// run (only a Polylang language backfill is allowed, and only when the
	// record had no language at all).
	foreach ( $pt_before as $pt_slug => $before ) {
		$pt_post = get_page_by_path( (string) $pt_slug, OBJECT, 'post' );

		if ( ! $pt_post instanceof WP_Post ) {
			++$summary['pt_changed'];
			$rows[] = array( 'pt_slug' => (string) $pt_slug, 'action' => 'error', 'message' => 'PT SOURCE MISSING AFTER RUN', 'pt_id' => 0, 'en_id' => 0, 'en_url' => '', 'links' => array() );
			++$summary['errors'];
			continue;
		}

		$after = conexao_blog_translation_snapshot_post( (int) $pt_post->ID );

		// A language backfill ('' → 'pt') is the only permitted difference.
		if ( '' === $before['language'] ) {
			$before['language'] = $after['language'];
		}

		if ( $after !== $before ) {
			++$summary['pt_changed'];
			++$summary['errors'];
			$rows[] = array( 'pt_slug' => (string) $pt_slug, 'action' => 'error', 'message' => 'PT SOURCE CHANGED — regression gate tripped', 'pt_id' => (int) $pt_post->ID, 'en_id' => 0, 'en_url' => '', 'links' => array() );
		}
	}

	return array(
		'summary'  => $summary,
		'rows'     => $rows,
		'terms'    => $term_rows,
		'excluded' => $manifest['excluded'],
		'audit'    => conexao_blog_translation_audit(),
	);
}

/**
 * Localize internal Blog links inside an EN translation.
 *
 * Uses the manifest link map (source href → PT post slug) and resolves the
 * destination through the REAL Polylang relationship: the EN link points at the
 * linked EN translation of the destination post. Nothing is string-replaced and
 * no URL is invented: a rule whose destination has no published EN translation
 * leaves the source href untouched (it stays the valid existing destination).
 *
 * @param string $content    EN content HTML.
 * @param array  $link_map   List of array( from, to_pt_post_slug ).
 * @param array  $resolved   Resolved href map (by reference, for reporting).
 * @return string
 */
function conexao_blog_translation_localize_links( string $content, array $link_map, array &$resolved = array() ): string {
	foreach ( $link_map as $rule ) {
		$from = isset( $rule['from'] ) ? (string) $rule['from'] : '';
		$slug = isset( $rule['to_pt_post_slug'] ) ? (string) $rule['to_pt_post_slug'] : '';

		if ( '' === $from || '' === $slug ) {
			continue;
		}

		$target = get_page_by_path( $slug, OBJECT, 'post' );

		if ( ! $target instanceof WP_Post ) {
			continue;
		}

		$en_target = (int) pll_get_post( (int) $target->ID, 'en' );

		if ( $en_target <= 0 || 'publish' !== get_post_status( $en_target ) ) {
			continue;
		}

		$permalink = (string) get_permalink( $en_target );

		if ( '' === $permalink ) {
			continue;
		}

		$needle   = 'href="' . $from . '"';
		$replaced = 'href="' . esc_url( $permalink ) . '"';

		if ( false === strpos( $content, $needle ) ) {
			continue;
		}

		$content              = str_replace( $needle, $replaced, $content );
		$resolved[ $from ]    = $permalink;
	}

	return $content;
}
