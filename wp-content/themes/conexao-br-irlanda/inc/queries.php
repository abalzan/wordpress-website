<?php
/**
 * Shared query and term-resolution helpers
 *
 * One concern: read helpers that resolve archive data for templates and
 * filter bars - guide/event/provider taxonomy term resolution, filter term
 * ids, related posts, popular posts and latest blog posts. No caching and no
 * hook registration of its own beyond what each helper needs at call time.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Widen a secondary post query for a B2 content type on an English request.
 *
 * B2 = "Portuguese content served under an English URL" (see
 * conexao_b2_post_types()). The B2 archives are already language-widened on
 * the MAIN query: conexao_content_archive_query() sets `lang` to `en,pt` for
 * the events archive, and conexao_b2_archive_widen_query() does the same for
 * the remaining B2 archives. Polylang's is_already_filtered() then skips its
 * own narrowing, so the archive lists the PT records that have no EN
 * translation.
 *
 * A SECONDARY query issued by a filter helper (get_posts() inside
 * conexao_get_terms_for_post_type() / conexao_get_event_towns()) does not
 * inherit that widening, because conexao_b2_archive_widen_query() is scoped to
 * $query->is_main_query(). On /en/eventos/ Polylang therefore narrowed those
 * helper queries to English only. Events are 100% PT records (the B2 model —
 * there are no EN event records at all), so the helper queries returned ZERO
 * posts, every term list came back empty, and template-parts/event-filters.php
 * hit its `if ( ! $has_county && ! $has_town && ! $has_category ) return;`
 * guard and rendered nothing at all. The archive itself kept showing its
 * events, which is exactly the reported symptom: a correct EN Events archive
 * with no filter UI.
 *
 * This helper applies the SAME `lang => en,pt` widening the main query already
 * uses, so a filter bar describes exactly the records its own archive lists.
 * It is the shared boundary every B2 filter helper goes through — there is no
 * English special case downstream and no duplicated component.
 *
 * Deliberately NOT applied to:
 *  - non-B2 post types (guides, posts, pages keep the B1 302 policy, and a
 *    filter bar must never advertise records the archive refuses to list);
 *  - a caller that already set `lang` itself (its explicit scope wins);
 *  - requests that are not English (a PT request is already correct).
 *
 * The returned term objects are NOT re-mapped to the current language: the
 * shared `conexao_county` / `conexao_town` taxonomies are deliberately
 * language-neutral (one term, both languages — see the guard in
 * inc/i18n/guard.php), so the same term name and the same `?county=` /
 * `?cidade=` slug are correct in both languages by design. Nothing here
 * creates, renames or re-tags a term.
 *
 * @param array  $args      Query arguments to widen.
 * @param string $post_type Post type the query targets.
 * @return array Query arguments, unchanged when no widening applies.
 */
function conexao_b2_widen_query_args( array $args, $post_type ) {
	if ( ! empty( $args['lang'] ) ) {
		return $args;
	}

	if ( ! function_exists( 'conexao_polylang_active' ) || ! conexao_polylang_active() ) {
		return $args;
	}

	if ( ! function_exists( 'conexao_requested_language_slug' ) || 'en' !== conexao_requested_language_slug() ) {
		return $args;
	}

	if ( ! function_exists( 'conexao_is_b2_post_type' ) || ! conexao_is_b2_post_type( $post_type ) ) {
		return $args;
	}

	$args['lang'] = 'en,pt';

	return $args;
}

function conexao_get_guides_archive_url() {
	$archive_link = get_post_type_archive_link( 'guide' );

	if ( $archive_link ) {
		return $archive_link;
	}

	return home_url( '/guias/' );
}

/**
 * Resolve the canonical Guide category URL for a Quick Access card.
 *
 * The homepage Quick Access cards should funnel straight into the existing
 * /guias/ archive filter (the same tax_query used by the filter bar), rather
 * than linking to static placeholder pages. The ?categoria= parameter must use
 * the real conexao_category term slug — which is NOT always the same as the
 * visible card label (e.g. card "Moradia" -> term name "Moradia e Aluguel"
 * with slug "moradia"; card "Finanças" -> term name "Consumidor e Finanças"
 * with slug "financas").
 *
 * Resolution order (first match wins):
 *  1. If $identifier is an existing conexao_category term slug, use it as-is.
 *  2. If $identifier matches an existing term by name (case-insensitive),
 *     use that term's slug.
 *  3. Fall back to a manual label -> slug mapping ($fallback_slug) so the
 *     card still works even before terms are seeded. The manual slug is only
 *     used when the term is not resolvable via taxonomy, and is itself
 *     validated: if it doesn't exist we fall through to the plain archive.
 *
 * STAGE 3.3 — language behaviour. The identifiers are canonical Portuguese
 * slugs, but Polylang filters term lookups by language, so the lookup is
 * widened (`conexao_find_term_across_languages()`) and the term is then
 * resolved to the current language (`conexao_lang_term()`):
 *
 *  - Portuguese request → the term is returned unchanged: the URL is
 *    byte-identical to the pre-Stage-3.3 output.
 *  - English request with a LINKED English term that actually carries
 *    published English guides → the English slug on the English archive
 *    (`/en/guias/?categoria=documents`) — a real English destination.
 *  - English request without one → the plain English archive (never a
 *    Portuguese slug under `/en/`, never an invented term URL).
 *
 * @param string $identifier   Card identifier (label or slug).
 * @param string $fallback_slug Optional known term slug to try when the term
 *                              cannot be resolved from taxonomy data.
 * @return string Fully-qualified URL, or the plain /guias/ archive when no
 *                matching category exists.
 */
function conexao_get_guide_category_url( $identifier, $fallback_slug = '' ) {
	$archive_url = conexao_get_guides_archive_url();
	$identifier  = trim( (string) $identifier );

	if ( '' === $identifier || '/' === $identifier ) {
		return $archive_url;
	}

	$candidates = array();

	// 1. The identifier is itself a term slug.
	$candidates[] = sanitize_title( $identifier );

	// 2. A manual fallback slug, if provided.
	if ( '' !== $fallback_slug ) {
		$candidates[] = sanitize_title( $fallback_slug );
	}

	$term = null;

	foreach ( $candidates as $candidate_slug ) {
		if ( '' === $candidate_slug ) {
			continue;
		}

		$found = get_term_by( 'slug', $candidate_slug, 'conexao_category' );
		if ( ! $found || is_wp_error( $found ) ) {
			// Stage 3.3: the slug may belong to ANOTHER language (the card
			// definitions keep canonical Portuguese slugs).
			$found = conexao_find_term_across_languages( $candidate_slug, 'conexao_category' );
		}

		if ( $found && ! is_wp_error( $found ) ) {
			$term = $found;
			break;
		}
	}

	// 3. Match by term name (case-insensitive) — covers labels like
	//    "Moradia e Aluguel" being passed directly, or accented labels.
	if ( ! $term ) {
		$terms = get_terms( array(
			'taxonomy'   => 'conexao_category',
			'hide_empty' => false,
		) );

		if ( ! is_wp_error( $terms ) ) {
			$needle = mb_strtolower( $identifier );
			foreach ( $terms as $candidate_term ) {
				if ( mb_strtolower( $candidate_term->name ) === $needle ) {
					$term = $candidate_term;
					break;
				}
			}
		}
	}

	if ( $term ) {
		// Stage 3.3: resolve to the current language. Portuguese requests get
		// the term back unchanged; English requests get the linked English
		// term only when it carries published English guides, otherwise null
		// (the plain language archive below).
		$language_term = conexao_lang_term( $term, 'guide' );

		if ( $language_term instanceof WP_Term ) {
			return add_query_arg( 'categoria', $language_term->slug, $archive_url );
		}
	}

	return $archive_url;
}

/**
 * Resolve a `?categoria=` slug for the Guide archive to a term ID in the
 * CURRENT language (STAGE 9).
 *
 * `conexao_category` is a Polylang-translated taxonomy, so one concept has a
 * Portuguese term and a linked English term, each with its own slug. The Guide
 * filter bar links with the current language's slugs, but a shared or legacy
 * URL (and the homepage Quick Access cards, which keep canonical Portuguese
 * identifiers) can carry the other language's slug. Polylang scopes term
 * lookups to the current language, so a plain `get_term_by()` would report the
 * foreign slug as unknown and Polylang's front-end canonical would redirect the
 * request to the Portuguese archive.
 *
 * Resolution (never a string replacement — always the real relationship):
 *   1. a term with that slug in the current language wins;
 *   2. otherwise the term is looked up across languages and mapped to its
 *      counterpart in the current language through `pll_get_term()`;
 *   3. otherwise 0, and the caller forces an empty result set so an unknown
 *      filter can never show misleading content.
 *
 * @param string $slug Raw `?categoria=` value (unsanitised).
 * @return int Term ID in the current language, or 0 when unknown.
 */
function conexao_guide_category_filter_term_id( $slug ) {
	$slug = sanitize_title( (string) $slug );

	if ( '' === $slug ) {
		return 0;
	}

	// 1. Current-language term.
	$current = get_term_by( 'slug', $slug, 'conexao_category' );

	if ( $current instanceof WP_Term ) {
		return (int) $current->term_id;
	}

	// 2. Term in any language, mapped through the Polylang relationship.
	if ( ! function_exists( 'conexao_find_term_across_languages' ) || ! function_exists( 'pll_get_term' ) || ! conexao_polylang_active() ) {
		return 0;
	}

	$foreign = conexao_find_term_across_languages( $slug, 'conexao_category' );

	if ( ! $foreign instanceof WP_Term ) {
		return 0;
	}

	$current_slug = function_exists( 'conexao_current_language_slug' ) ? conexao_current_language_slug() : '';
	$counterpart  = $current_slug ? (int) pll_get_term( (int) $foreign->term_id, $current_slug ) : 0;

	if ( $counterpart > 0 ) {
		return $counterpart;
	}

	// A term whose concept has no counterpart in this language keeps the
	// caller on the empty result set (never the foreign term itself).
	return 0;
}

/**
 * Get taxonomy terms that are actually used by a specific post type.
 *
 * WordPress' get_terms() counts posts across every post type that shares a
 * taxonomy. On a site where conexao_category is shared by guides, events,
 * jobs, sponsors and course providers, that means a category used only by
 * guides would appear in the events filter bar.
 *
 * This helper restricts the result to terms that have at least one published
 * post of the given post type, so each archive only shows filters that are
 * relevant to its own content.
 *
 * The underlying get_posts() call respects pre_get_posts hooks, so editorial
 * status filters (e.g. the event-importer's _event_status = published filter)
 * are applied automatically — terms only appear when they have visible content.
 *
 * Results are cached in the object cache for 5 minutes.
 *
 * @param string $taxonomy  Taxonomy slug (e.g. conexao_category).
 * @param string $post_type Post type slug (e.g. event, guide).
 * @return WP_Term[] Array of term objects, empty when none match.
 */
function conexao_get_terms_for_post_type( $taxonomy, $post_type, $extra_args = array() ) {
	$cache_key = conexao_lang_cache_key( 'conexao_terms_' . $taxonomy . '_' . $post_type );
	$cache_key .= empty( $extra_args ) ? '' : '_' . md5( wp_json_encode( $extra_args ) );
	$cached    = wp_cache_get( $cache_key, 'conexao_filters' );

	if ( false !== $cached ) {
		return $cached;
	}

	$post_ids = get_posts( conexao_b2_widen_query_args( array_merge( array(
		'post_type'              => $post_type,
		'post_status'            => 'publish',
		'posts_per_page'         => -1,
		'fields'                 => 'ids',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	), $extra_args ), $post_type ) );

	if ( empty( $post_ids ) ) {
		wp_cache_set( $cache_key, array(), 'conexao_filters', 300 );
		return array();
	}

	$terms = wp_get_object_terms( $post_ids, $taxonomy, array(
		'orderby' => 'name',
		'order'   => 'ASC',
	) );

	if ( is_wp_error( $terms ) ) {
		$terms = array();
	}

	wp_cache_set( $cache_key, $terms, 'conexao_filters', 300 );
	return $terms;
}

/**
 * Get town/city terms for the Events archive, optionally scoped to a county.
 *
 * County → Town scoping: when a county slug is provided, only towns attached
 * to at least one published event in that county are returned (via a single
 * get_posts ID query + wp_get_object_terms, same cost model as
 * conexao_get_terms_for_post_type()). Without a county, all towns used by
 * published events are returned. Empty counties/towns never appear.
 *
 * Results are cached in the object cache for 5 minutes.
 *
 * @param string $county_slug Optional county slug to scope towns to.
 * @return WP_Term[] Town terms ordered by name ASC.
 */
function conexao_get_event_towns( $county_slug = '' ) {
	$county_slug = sanitize_title( $county_slug );
	$cache_key   = conexao_lang_cache_key( 'conexao_event_towns' . ( '' !== $county_slug ? '_' . $county_slug : '' ) );
	$cached      = wp_cache_get( $cache_key, 'conexao_filters' );

	if ( false !== $cached ) {
		return $cached;
	}

	$args = array(
		'post_type'              => 'event',
		'post_status'            => 'publish',
		'posts_per_page'         => -1,
		'fields'                 => 'ids',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	);

	if ( '' !== $county_slug ) {
		$county_term = get_term_by( 'slug', $county_slug, 'conexao_county' );
		if ( ! $county_term || is_wp_error( $county_term ) ) {
			wp_cache_set( $cache_key, array(), 'conexao_filters', 300 );
			return array();
		}
		$args['tax_query'] = array(
			array(
				'taxonomy' => 'conexao_county',
				'field'    => 'slug',
				'terms'    => $county_slug,
			),
		);
	}

	$post_ids = get_posts( conexao_b2_widen_query_args( $args, 'event' ) );

	if ( empty( $post_ids ) ) {
		wp_cache_set( $cache_key, array(), 'conexao_filters', 300 );
		return array();
	}

	$terms = wp_get_object_terms(
		$post_ids,
		'conexao_town',
		array(
			'orderby' => 'name',
			'order'   => 'ASC',
		)
	);

	if ( is_wp_error( $terms ) ) {
		$terms = array();
	}

	wp_cache_set( $cache_key, $terms, 'conexao_filters', 300 );
	return $terms;
}

/**
 * Build an Events archive filter URL preserving the full filter state.
 *
 * Single source of truth for /eventos/ filter links (Localização county,
 * Cidade town, Categoria category). Empty dimensions are omitted, the page
 * cursor is always reset, and every combination stays shareable/refresh-safe.
 * Invalid slugs pass through untouched — the query layer ignores unknown
 * slugs gracefully (zero results rather than an error).
 *
 * @param array  $filters Filter state: 'county', 'cidade', 'categoria' slugs.
 * @param string $base    Optional base URL (defaults to the event archive).
 * @return string Filter URL.
 */
function conexao_event_filter_url( array $filters, $base = '' ) {
	if ( '' === $base ) {
		$base = get_post_type_archive_link( 'event' );
	}

	$url = remove_query_arg( array( 'county', 'cidade', 'categoria', 'paged', 'pagina' ), $base );

	if ( ! empty( $filters['county'] ) ) {
		$url = add_query_arg( 'county', sanitize_title( $filters['county'] ), $url );
	}
	if ( ! empty( $filters['cidade'] ) ) {
		$url = add_query_arg( 'cidade', sanitize_title( $filters['cidade'] ), $url );
	}
	if ( ! empty( $filters['categoria'] ) ) {
		$url = add_query_arg( 'categoria', sanitize_title( $filters['categoria'] ), $url );
	}

	return $url;
}

/**
 * Get the distinct provider categories that have at least one published
 * course provider.
 *
 * Course providers categorize via the _provider_category post meta (a string
 * label such as "Educação"), not via a taxonomy. This helper dynamically
 * discovers which categories are actually in use so the Cursos filter bar
 * never shows empty or irrelevant categories.
 *
 * Results are cached in the object cache for 5 minutes.
 *
 * @return array Array of associative arrays with 'name' and 'slug' keys.
 */
function conexao_get_provider_categories() {
	$cache_key = conexao_lang_cache_key( 'conexao_provider_categories' );
	$cached    = wp_cache_get( $cache_key, 'conexao_filters' );

	if ( false !== $cached ) {
		return $cached;
	}

	$providers = get_posts( array(
		'post_type'              => 'course_provider',
		'post_status'            => 'publish',
		'posts_per_page'         => -1,
		'meta_query'             => array(
			array(
				'key'     => '_provider_status',
				'value'   => 'published',
				'compare' => '=',
			),
		),
		'no_found_rows'          => true,
		'update_post_meta_cache' => true,
		'update_post_term_cache' => false,
	) );

	if ( empty( $providers ) ) {
		wp_cache_set( $cache_key, array(), 'conexao_filters', 300 );
		return array();
	}

	$seen   = array();
	$result = array();

	foreach ( $providers as $provider ) {
		$category = get_post_meta( $provider->ID, '_provider_category', true );

		if ( $category && ! isset( $seen[ $category ] ) ) {
			$seen[ $category ] = true;
			$result[] = array(
				'name' => $category,
				'slug' => sanitize_title( $category ),
			);
		}
	}

	usort( $result, function( $a, $b ) {
		return strcasecmp( $a['name'], $b['name'] );
	} );

	wp_cache_set( $cache_key, $result, 'conexao_filters', 300 );
	return $result;
}

function conexao_related_posts() {
	$categories = wp_get_post_categories( get_the_ID() );
	if ( empty( $categories ) ) return;

	$related = new WP_Query( array(
		'category__in'        => $categories,
		'post__not_in'        => array( get_the_ID() ),
		'posts_per_page'      => 3,
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	) );

	if ( $related->have_posts() ) : ?>
		<div class="related-posts">
			<h3 class="related-posts-title"><?php esc_html_e( 'Posts Relacionados', 'conexao-br-irlanda' ); ?></h3>
			<div class="related-posts-grid">
				<?php while ( $related->have_posts() ) : $related->the_post(); ?>
					<article class="related-post-card">
						<?php if ( has_post_thumbnail() ) : ?>
							<a href="<?php the_permalink(); ?>" class="related-post-thumb"><?php the_post_thumbnail( 'medium', array( 'loading' => 'lazy' ) ); ?></a>
						<?php endif; ?>
						<div class="related-post-content">
							<h4 class="related-post-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h4>
							<span class="related-post-date"><?php echo esc_html( get_the_date() ); ?></span>
						</div>
					</article>
				<?php endwhile; ?>
			</div>
		</div>
	<?php endif;
	wp_reset_postdata();
}

/**
 * "Mais Lidos" (most read) query.
 *
 * The homepage previously ordered by `comment_count`, which requires a full
 * table scan on wp_posts and becomes expensive as the portal grows. Views are
 * recorded server-side by inc/post-views.php into the `_conexao_view_count`
 * post meta (one increment per front-end content page view).
 *
 * Content scope: ONLY Blog (`post`) and Guias (`guide`). This is an
 * informational-content ranking — events, jobs, apoiadores, cursos and lazer
 * are never included, even when they have higher view counts. The scope is
 * read from conexao_view_count_post_types() (inc/post-views.php) so the
 * counting gate and this ranking can never drift apart.
 *
 * Architecture:
 *   post ID → _conexao_view_count (meta, written by inc/post-views.php)
 *   → ORDER BY meta_value_num DESC
 *
 * Posts with view counts sort first (meta_value_num DESC); content never
 * visited yet falls back to "recent content" (date DESC) so the homepage
 * keeps working without an expensive query. Results are transient-cached for
 * 5 minutes and invalidated on save/delete (see conexao_homepage_cache_invalidate()).
 *
 * @param int $limit Number of items to return (default 5).
 * @return array List of post IDs, most read first.
 */
function conexao_popular_posts( $limit = 5 ) {
	$limit     = max( 1, absint( $limit ) );
	$cache_key = conexao_lang_cache_key( 'conexao_home_popular' );
	$scopes    = conexao_view_count_post_types();

	$cached = get_transient( $cache_key );
	if ( false !== $cached ) {
		return array_slice( $cached, 0, $limit );
	}

	// Preferred: order by the cached view-count meta when it exists.
	// This is intentionally a single indexed meta query, not a JOIN over
	// comment counts. It returns nothing measurable until the meta is set,
	// so we fall through to the lightweight recent-content query below.
	$by_views = new WP_Query( array(
		'post_type'              => $scopes,
		'posts_per_page'         => $limit,
		'meta_key'               => '_conexao_view_count',
		'orderby'                => 'meta_value_num',
		'order'                  => 'DESC',
		'ignore_sticky_posts'    => true,
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	) );

	$ids = array();
	if ( $by_views->have_posts() ) {
		$ids = wp_list_pluck( $by_views->posts, 'ID' );
	}

	// Fallback: recent content (lightweight, no ORDER BY comment_count).
	if ( empty( $ids ) ) {
		$recent = new WP_Query( array(
			'post_type'              => $scopes,
			'posts_per_page'         => $limit,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );
		if ( $recent->have_posts() ) {
			$ids = wp_list_pluck( $recent->posts, 'ID' );
		}
	}

	set_transient( $cache_key, $ids, 300 );
	return array_slice( $ids, 0, $limit );
}

/**
 * Latest Blog posts for the homepage "Ultimas Novidades" section.
 *
 * Answers "what's new?" and is intentionally distinct from
 * conexao_popular_posts() ("Mais Lidos" = what's popular): this
 * ranks strictly by publication date, newest first, and sources
 * Blog posts (post) ONLY — no Guias, Eventos, Cursos, Lazer,
 * Empregos or Apoiadores.
 *
 * Architecture mirrors conexao_popular_posts(): a single bounded
 * query stores only the post IDs in a transient (5 minutes,
 * invalidated on save/delete via conexao_homepage_cache_invalidate()),
 * so publishing a new post makes it appear without a manual cache
 * purge. The rendering query in front-page.php re-fetches by ID
 * (post__in) so cards keep the full responsive-image treatment.
 *
 * @param int $limit Number of posts to return (default 3).
 * @return array List of post IDs, newest first.
 */
function conexao_latest_blog_posts( $limit = 3 ) {
	$limit     = max( 1, absint( $limit ) );
	$cache_key = conexao_lang_cache_key( 'conexao_home_latest' );

	$cached = get_transient( $cache_key );
	if ( false !== $cached ) {
		return array_slice( $cached, 0, $limit );
	}

	$query = new WP_Query( array(
		'post_type'              => 'post',
		'post_status'            => 'publish',
		'posts_per_page'         => $limit,
		'orderby'                => 'date',
		'order'                  => 'DESC',
		'ignore_sticky_posts'    => true,
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	) );

	$ids = array();
	if ( $query->have_posts() ) {
		$ids = wp_list_pluck( $query->posts, 'ID' );
	}

	set_transient( $cache_key, $ids, 300 );
	return array_slice( $ids, 0, $limit );
}

/**
 * Resolve an Apoiador's canonical image attachment ID.
 *
 * Data model (see docs/content-model.md):
 *
 *   _sponsor_image → single portrait "Imagem do Apoiador" used at every
 *                    breakpoint (desktop carousel, mobile carousel, detail
 *                    page).
 *
 * Backward compatibility with records created before the consolidation — no
 * administrator action is ever required for existing Apoiadores to keep
 * working:
 *
 *   _sponsor_image → _sponsor_mobile_image → _sponsor_desktop_image →
 *   legacy _sponsor_logo → featured image
 *
 * All values are Media Library attachment IDs. URL-only legacy values cannot
 * identify an attachment reliably and are ignored here; the admin editor
 * migrates them into attachment IDs on the next save.
 *
 * Only real image attachments qualify; anything else resolves to the next
 * candidate so a deleted attachment can never render as a broken image.
 *
 * @param int $sponsor_id Sponsor post ID.
 * @return int Attachment ID (0 = none).
 */
