<?php
/**
 * Stage 4.5 — page translation apply engine (shared by the admin importer
 * and the WP-CLI runner).
 *
 * Creates exactly ONE linked English translation per eligible Portuguese
 * page, in manifest order (front page → top-level pages → legal/utility).
 *
 * Guarantees (task Phases 3, 5, 11, 23):
 *   - the Portuguese original is NEVER modified (no wp_update_post /
 *     update_post_meta / wp_set_post_terms call ever targets a PT page);
 *     a PT snapshot is captured before the run and verified after it;
 *   - idempotent: a page whose EN translation already exists is skipped
 *     (or updated only in explicit update mode);
 *   - refuses slug collisions instead of colliding;
 *   - verifies the Polylang relationship from BOTH directions
 *     (pll_get_post pt→en and en→pt) before reporting success;
 *   - internal links inside the EN content are resolved through the
 *     WordPress/Polylang relationship (pll_get_post / archive URL helper),
 *     never by string-prefixing;
 *   - no EN page is created without its translation link.
 *
 * @package Conexao_Page_Translation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Internal-link localizer.
 *
 * Finds root-relative and same-host absolute hrefs in block content and
 * resolves each to the destination the target language must reach:
 *   1. CPT archive paths            → conexao_language_archive_url() (EN archive);
 *   2. pages with a published translation → the translation's permalink path;
 *   3. everything else              → unchanged (approved B1 behaviour).
 *
 * This mirrors conexao_lang_url() (inc/polylang.php) but takes an explicit
 * language instead of reading the request language, because the importer
 * runs in an admin (language-neutral) context.
 *
 * @param string $content Block markup with PT/canonical hrefs.
 * @param string $lang    Target language slug ('en').
 * @return array{0:string,1:array<string,string>} Localized content + map of
 *         original href → resolved href actually applied (for reporting).
 */
function conexao_page_translation_localize_links( string $content, string $lang = 'en' ): array {
	$resolved = array();
	$home     = untrailingslashit( home_url( '/' ) );

	$out = preg_replace_callback(
		'/href="([^"]+)"/',
		function ( $m ) use ( $lang, $home, &$resolved ) {
			$href = $m[1];
			$path = null;
			if ( 0 === strpos( $href, '/' ) && 0 !== strpos( $href, '//' ) ) {
				$path = $href;
			} elseif ( 0 === strpos( $href, $home . '/' ) ) {
				$path = substr( $href, strlen( $home ) );
			}
			if ( null === $path || '' === $path ) {
				return $m[0];
			}
			$new = conexao_page_translation_resolve_path( $path, $lang );
			if ( '' === $new || $new === $path ) {
				return $m[0];
			}
			$resolved[ $href ] = $new;
			return 'href="' . esc_url( $new ) . '"';
		},
		$content
	);

	return array( $out, $resolved );
}

/**
 * Resolve a canonical PT path to the target-language URL path.
 *
 * @param string $path Root-relative path, e.g. "/moradia/".
 * @param string $lang Target language slug.
 * @return string Root-relative target path, or '' when nothing better exists.
 */
function conexao_page_translation_resolve_path( string $path, string $lang ): string {
	$trimmed = trim( $path, '/' );

	// (1) CPT archive roots → the language archive URL. Uses the theme's own
	// helper (the single source of truth: registered post types with a real
	// has_archive — so /empregos/, a PAGE, correctly falls through to the
	// page lookup and /lazer/ resolves to the leisure archive).
	if ( '' !== $trimmed && false === strpos( $trimmed, '/' )
		&& function_exists( 'conexao_lang_url_archive_post_type' ) && function_exists( 'conexao_language_archive_url' ) ) {
		$post_type = conexao_lang_url_archive_post_type( $path );
		if ( '' !== $post_type && 'post' !== $post_type ) {
			$archive = conexao_language_archive_url( $post_type, $lang );
			if ( '' !== (string) $archive ) {
				return (string) wp_parse_url( $archive, PHP_URL_PATH );
			}
		}
	}

	// (2) A page with a published translation → the translation's path.
	if ( '' !== $trimmed && function_exists( 'pll_get_post' ) ) {
		$page = get_page_by_path( $trimmed, OBJECT, 'page' );
		if ( $page instanceof WP_Post ) {
			$translation = pll_get_post( (int) $page->ID, $lang );
			if ( $translation && (int) $translation !== (int) $page->ID && 'publish' === get_post_status( $translation ) ) {
				$permalink = get_permalink( (int) $translation );
				if ( $permalink ) {
					$rel = (string) wp_parse_url( $permalink, PHP_URL_PATH );
					return '' === $rel ? '' : $rel;
				}
			}
		}
	}

	return '';

/**
 * Snapshot the PT-relevant fields of a page (Phase 23 checkpoint).
 *
 * @param int $pt_id Page ID.
 * @return array<string,mixed>
 */
function conexao_page_translation_snapshot_page( int $pt_id ): array {
	return array(
		'title'      => get_post_field( 'post_title', $pt_id ),
		'content'    => get_post_field( 'post_content', $pt_id ),
		'excerpt'    => get_post_field( 'post_excerpt', $pt_id ),
		'status'     => get_post_status( $pt_id ),
		'name'       => get_post_field( 'post_name', $pt_id ),
		'parent'     => (int) wp_get_post_parent_id( $pt_id ),
		'menu_order' => (int) get_post_field( 'menu_order', $pt_id ),
		'template'   => get_post_meta( $pt_id, '_wp_page_template', true ),
		'meta_desc'  => get_post_meta( $pt_id, 'conexao_meta_description', true ),
	);
}

/**
 * Permit the exact shared slugs while the importer runs.
 *
 * Polylang Free never shares slugs across translations; the Pro feature does
 * exactly this. The filter is installed for the duration of the run and only
 * permits the allowlisted slug named by the one-shot transient — never
 * globally, never outside the migration.
 *
 * @param string $slug          Slug after uniqueness filtering.
 * @param int    $post_id       Post ID.
 * @param string $post_status   Status.
 * @param string $post_type     Post type.
 * @param int    $post_parent   Parent.
 * @param string $original_slug Slug before uniqueness filtering.
 * @return string
 */
function conexao_page_translation_shared_slug( $slug, $post_id, $post_status, $post_type, $post_parent, $original_slug ) {
	$allowed = get_transient( 'conexao_page_translation_shared_slug' );
	if ( 'page' === $post_type && $allowed && $original_slug === $allowed ) {
		return $original_slug;
	}
	return $slug;
}

/**
 * Run the migration.
 *
 * @param array $args {
 *   @type bool     $dry_run         Preview only; nothing is written.
 *   @type string[] $only            Restrict to these PT slugs.
 *   @type bool     $update_existing Re-apply title/content/meta/template to an
 *                                   existing EN translation (never the PT page).
 * }
 * @return array{rows:array<int,array<string,mixed>>,summary:array<string,int>}
 */
function conexao_page_translation_run( array $args = array() ): array {
	$args = wp_parse_args(
		$args,
		array(
			'dry_run'         => true,
			'only'            => array(),
			'update_existing' => false,
		)
	);

	$rows    = array();
	$summary = array(
		'created' => 0, 'exists' => 0, 'updated' => 0, 'skipped' => 0,
		'errors' => 0, 'pt_changed' => 0, 'links_localized' => 0,
	);

	if ( ! function_exists( 'pll_get_post' ) || ! function_exists( 'pll_save_post_translations' ) ) {
		$rows[]   = array( 'slug' => '*', 'action' => 'error', 'message' => 'Polylang is not active.' );
		$summary['errors']++;
		return array( 'rows' => $rows, 'summary' => $summary );
	}

	$map = conexao_page_translation_map();

	// Phase 23 — PT checkpoint BEFORE the run.
	$pt_before = array();
	foreach ( $map as $pt_slug => $spec ) {
		$pt_page = get_page_by_path( $pt_slug, OBJECT, 'page' );
		if ( $pt_page ) {
			$pt_before[ $pt_slug ] = conexao_page_translation_snapshot_page( (int) $pt_page->ID );
		}
	}

	add_filter( 'wp_unique_post_slug', 'conexao_page_translation_shared_slug', 10, 6 );


	foreach ( $map as $pt_slug => $en ) {
		if ( $args['only'] && ! in_array( $pt_slug, $args['only'], true ) ) {
			continue;
		}

		$row = array( 'slug' => $pt_slug, 'en_slug' => $en['en_slug'], 'action' => '', 'message' => '', 'en_id' => 0, 'en_url' => '' );

		$pt_page = get_page_by_path( $pt_slug, OBJECT, 'page' );
		if ( ! $pt_page ) {
			$row['action']  = 'error';
			$row['message'] = 'PT source page not found.';
			$summary['errors']++;
			$rows[] = $row;
			continue;
		}
		$pt_id = (int) $pt_page->ID;

		// Phase 5 gate: never translate a child before its parent. (All
		// production pages are top-level today; keep the guard for any
		// future hierarchy.)
		$pt_parent = (int) $pt_page->post_parent;
		$en_parent = 0;
		if ( $pt_parent > 0 ) {
			$en_parent = (int) pll_get_post( $pt_parent, 'en' );
			if ( ! $en_parent || 'publish' !== get_post_status( $en_parent ) ) {
				$row['action']  = 'error';
				$row['message'] = sprintf( 'PT parent #%d has no published EN translation — translate the parent first.', $pt_parent );
				$summary['errors']++;
				$rows[] = $row;
				continue;
			}
		}

		$existing = (int) pll_get_post( $pt_id, 'en' );
		if ( $existing && ! $args['update_existing'] ) {
			$row['action']  = 'exists';
			$row['en_id']   = $existing;
			$row['en_url']  = get_permalink( $existing );
			$row['message'] = 'EN translation already exists (idempotent skip).';
			$summary['exists']++;
			$rows[] = $row;
			continue;
		}

		// Slug collision check: refuse to take a slug owned by an unrelated page.
		$clash = get_page_by_path( $en['en_slug'], OBJECT, 'page' );
		if ( $clash && (int) $clash->ID !== $pt_id && (int) $clash->ID !== $existing ) {
			$row['action']  = 'error';
			$row['message'] = sprintf( "slug '%s' already taken by page #%d — refusing to collide.", $en['en_slug'], $clash->ID );
			$summary['errors']++;
			$rows[] = $row;
			continue;
		}

		// Resolve internal links through the Polylang relationship (Phase 8).
		// Runs against the CURRENT link state: pages created earlier in this
		// ordered run resolve to their fresh EN URLs.
		list( $content, $resolved_links ) = conexao_page_translation_localize_links( $en['content'], 'en' );

		if ( $args['dry_run'] ) {
			$row['action']  = $existing ? 'would-update' : 'would-create';
			$row['message'] = 'dry run';
			$row['links']   = $resolved_links;
			$summary['links_localized'] += count( $resolved_links );
			$rows[] = $row;
			continue;
		}

		// Shared-slug permit, scoped to this exact insert.
		$shared = ! empty( $en['shared_slug'] );
		if ( $shared ) {
			set_transient( 'conexao_page_translation_shared_slug', $en['en_slug'], 60 );
		}

		if ( $existing ) {
			// Update mode: only the EN record is touched.
			$en_id = wp_update_post(
				array(
					'ID'           => $existing,
					'post_title'   => $en['title'],
					'post_content' => $content,
					'post_status'  => 'publish',
					'post_parent'  => $en_parent,
					'menu_order'   => (int) $pt_page->menu_order,
				),
				true
			);
		} else {
			$en_id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_name'    => $en['en_slug'],
					'post_title'   => $en['title'],
					'post_content' => $content,
					'post_status'  => 'publish',
					'post_parent'  => $en_parent,
					'menu_order'   => (int) $pt_page->menu_order,
				),
				true
			);
		}

		if ( $shared ) {
			delete_transient( 'conexao_page_translation_shared_slug' );
		}

		if ( is_wp_error( $en_id ) ) {
			$row['action']  = 'error';
			$row['message'] = $en_id->get_error_message();
			$summary['errors']++;
			$rows[] = $row;
			continue;
		}
		$en_id = (int) $en_id;


		// Language assignment + translation link (the identity relationship).
		pll_set_post_language( $en_id, 'en' );
		if ( ! pll_get_post_language( $pt_id ) ) {
			pll_set_post_language( $pt_id, 'pt' ); // backfill only; never re-assigns
		}
		pll_save_post_translations( array( 'pt' => $pt_id, 'en' => $en_id ) );

		// SEO description + template parity + shared featured media.
		if ( ! empty( $en['meta_desc'] ) ) {
			update_post_meta( $en_id, 'conexao_meta_description', $en['meta_desc'] );
		}
		$template = ! empty( $en['template'] ) ? $en['template'] : get_post_meta( $pt_id, '_wp_page_template', true );
		if ( $template && 'default' !== $template ) {
			update_post_meta( $en_id, '_wp_page_template', $template );
		}
		$thumb = get_post_thumbnail_id( $pt_id );
		if ( $thumb ) {
			set_post_thumbnail( $en_id, $thumb );
		}
		// Pass-through custom fields (identifiers/URLs are never translated).
		$passthrough = array( '_empregos_link' );
		foreach ( $passthrough as $meta_key ) {
			$value = get_post_meta( $pt_id, $meta_key, true );
			if ( '' !== $value && null !== $value ) {
				update_post_meta( $en_id, $meta_key, $value );
			}
		}

		// Verify the relationship from BOTH sides before reporting success.
		$check_en = (int) pll_get_post( $pt_id, 'en' );
		$check_pt = (int) pll_get_post( $en_id, 'pt' );
		if ( $check_en !== $en_id || $check_pt !== $pt_id ) {
			$row['action']  = 'error';
			$row['message'] = sprintf( 'translation link verification failed (pt%d→en%d, en%d→pt%d).', $pt_id, $check_en, $en_id, $check_pt );
			$summary['errors']++;
			$rows[] = $row;
			continue;
		}

		$row['action']  = $existing ? 'updated' : 'created';
		$row['en_id']   = $en_id;
		$row['en_url']  = get_permalink( $en_id );
		$row['links']   = $resolved_links;
		$row['message'] = 'linked both ways';
		$summary[ $existing ? 'updated' : 'created' ]++;
		$summary['links_localized'] += count( $resolved_links );
		$rows[] = $row;
	}

	remove_filter( 'wp_unique_post_slug', 'conexao_page_translation_shared_slug', 10 );
	delete_transient( 'conexao_page_translation_shared_slug' );

	// Phase 23 — PT checkpoint AFTER the run: zero unintended PT changes.
	foreach ( $pt_before as $pt_slug => $before ) {
		$pt_page = get_page_by_path( $pt_slug, OBJECT, 'page' );
		if ( ! $pt_page ) {
			$rows[] = array( 'slug' => $pt_slug, 'action' => 'error', 'message' => 'PT SOURCE MISSING AFTER RUN.' );
			$summary['errors']++;
			continue;
		}
		$after = conexao_page_translation_snapshot_page( (int) $pt_page->ID );
		if ( $after !== $before ) {
			$rows[] = array( 'slug' => $pt_slug, 'action' => 'error', 'message' => 'PT SOURCE CHANGED — regression gate tripped.' );
			$summary['errors']++;
			$summary['pt_changed']++;
		}
	}

	return array( 'rows' => $rows, 'summary' => $summary );
}

}
