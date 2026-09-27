<?php
/**
 * Archive query shaping for the CPT archives
 *
 * conexao_content_archive_query(): the pre_get_posts layer that applies the
 * per-post-type archive filters (county/city/category/environment and the
 * Blog category filter) with the language-scoped ordering rules.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function conexao_content_archive_query( $query ) {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}

	/*
	 * Eventos: upcoming events ordered by date, with town/category filters.
	 *
	 * Recurrence-aware path: when the event runtime's Conexao_Event_Query
	 * helper is available, the main query is fed the pre-computed ordered ID
	 * list (one-time events dated today or later + weekly series with an
	 * occurrence in the current local 7-day window, sorted by next
	 * occurrence) via post__in + orderby => post__in. The query remains a
	 * single main WP_Query over real event posts, so pagination
	 * (/eventos/page/N/ + load-more), the _event_status gate and the
	 * taxonomy filters below keep working unchanged, and a recurring event
	 * appears exactly once (it is ONE post — no occurrence posts exist).
	 * When the helper is unavailable (runtime plugin inactive), the legacy
	 * date-meta query below runs byte-for-byte as before.
	 */
	if ( $query->is_post_type_archive( 'event' ) ) {
		$upcoming_ids = conexao_event_upcoming_ids();

		if ( is_array( $upcoming_ids ) ) {
			// STAGE 3.1 — B2 fallback: widen the language scope to EN+PT so
			// Polylang's is_already_filtered() skips its own narrowing. The
			// ID list itself is already language-curated (EN records + B2 PT
			// records with no EN translation, never hidden statuses).
			if ( function_exists( 'conexao_polylang_active' ) && conexao_polylang_active() && 'en' === conexao_requested_language_slug() ) {
				$query->set( 'lang', 'en,pt' );
			}

			// post__in => array() is ambiguous in WP_Query; array( 0 )
			// deterministically yields "no upcoming events".
			if ( empty( $upcoming_ids ) ) {
				$upcoming_ids = array( 0 );
			}
			$query->set( 'post__in', $upcoming_ids );
			$query->set( 'orderby', 'post__in' );
			$query->set( 'order', 'ASC' );
		} else {
			// Legacy path (event runtime inactive): date-meta ordering only.
			$query->set( 'meta_key', '_event_date' );
			$query->set( 'meta_value', current_time( 'Y-m-d' ) );
			$query->set( 'meta_compare', '>=' );
			$query->set( 'meta_type', 'DATE' );
			$query->set( 'orderby', 'meta_value' );
			$query->set( 'order', 'ASC' );
		}

		$tax_query = $query->get( 'tax_query' );
		if ( ! is_array( $tax_query ) ) {
			$tax_query = array();
		}

		// County filter via ?county=slug (e.g., "laois") — same ?county= convention as /lazer/.
		$county = isset( $_GET['county'] ) ? sanitize_title( wp_unslash( $_GET['county'] ) ) : '';
		if ( $county ) {
			$county_term = get_term_by( 'slug', $county, 'conexao_county' );
			if ( $county_term && ! is_wp_error( $county_term ) ) {
				$tax_query[] = array(
					'taxonomy' => 'conexao_county',
					'field'    => 'slug',
					'terms'    => $county,
				);
			} else {
				// Unknown county slug — force no results so an invalid filter
				// never produces misleading content.
				$tax_query[] = array(
					'taxonomy' => 'conexao_county',
					'field'    => 'slug',
					'terms'    => '__conexao_no_such_county__',
				);
			}
		}

		// Town/city filter via ?cidade=slug
		$town = isset( $_GET['cidade'] ) ? sanitize_title( wp_unslash( $_GET['cidade'] ) ) : '';
		if ( $town ) {
			$town_term = get_term_by( 'slug', $town, 'conexao_town' );
			if ( $town_term && ! is_wp_error( $town_term ) ) {
				$tax_query[] = array(
					'taxonomy' => 'conexao_town',
					'field'    => 'slug',
					'terms'    => $town,
				);
			} else {
				// Unknown town slug — force no results gracefully.
				$tax_query[] = array(
					'taxonomy' => 'conexao_town',
					'field'    => 'slug',
					'terms'    => '__conexao_no_such_town__',
				);
			}
		}

		// Category filter via ?categoria=slug (e.g., "treinamento")
		$category = isset( $_GET['categoria'] ) ? sanitize_title( wp_unslash( $_GET['categoria'] ) ) : '';
		if ( $category ) {
			/*
			 * STAGE 3.2 — resolve the slug language-neutrally. Polylang
			 * language-scopes plain get_term_by() to the current language, so a
			 * PT-slug filter on the EN (B2) archive would resolve as "unknown
			 * slug" and silently return zero records. The /lazer/ handler
			 * already resolves slugs neutrally (its tax query matches either
			 * language's term); align the events archive so PT-slug filters
			 * keep matching the PT fallback records in the EN context exactly
			 * as they do in Portuguese. EN-slug filters keep matching the EN
			 * records — the two slug families stay distinct by design.
			 */
			$category_term = get_terms(
				array(
					'taxonomy'   => 'conexao_category',
					'slug'       => $category,
					'lang'       => '',
					'hide_empty' => false,
					'number'     => 1,
				)
			);
			$category_term = ( ! is_wp_error( $category_term ) && ! empty( $category_term ) ) ? $category_term[0] : false;
			if ( $category_term && ! is_wp_error( $category_term ) ) {
				$tax_query[] = array(
					'taxonomy' => 'conexao_category',
					'field'    => 'slug',
					'terms'    => $category,
				);
			} else {
				// Unknown category slug — force no results gracefully.
				$tax_query[] = array(
					'taxonomy' => 'conexao_category',
					'field'    => 'slug',
					'terms'    => '__conexao_no_such_category__',
				);
			}
		}

		if ( ! empty( $tax_query ) ) {
			$query->set( 'tax_query', $tax_query );
		}
	}

	/*
	 * Cursos: published providers, ordered by display order, with an
	 * optional ?categoria= filter that maps to the _provider_category meta.
	 */
	if ( $query->is_post_type_archive( 'course_provider' ) ) {
		$meta_query = $query->get( 'meta_query' );
		if ( ! is_array( $meta_query ) ) {
			$meta_query = array();
		}

		// Only show published providers on the public archive.
		$meta_query[] = array(
			'key'     => '_provider_status',
			'value'   => 'published',
			'compare' => '=',
		);

		// Category filter via ?categoria=<slug>.
		// Provider categories are stored as meta values (human-readable labels),
		// so the slug is resolved back to the label before querying.
		$category_slug = isset( $_GET['categoria'] ) ? sanitize_title( wp_unslash( $_GET['categoria'] ) ) : '';
		if ( $category_slug ) {
			$provider_categories = conexao_get_provider_categories();
			$matched_name        = '';

			foreach ( $provider_categories as $cat ) {
				if ( $cat['slug'] === $category_slug ) {
					$matched_name = $cat['name'];
					break;
				}
			}

			if ( $matched_name ) {
				$meta_query[] = array(
					'key'     => '_provider_category',
					'value'   => $matched_name,
					'compare' => '=',
				);
			} else {
				// No matching category — force no results so an irrelevant
				// filter never produces misleading content.
				$meta_query[] = array(
					'key'     => '_provider_category',
					'value'   => '__conexao_no_such_category__',
					'compare' => '=',
				);
			}
		}

		$query->set( 'meta_query', $meta_query );

		// Order by display order, then title.
		$query->set( 'meta_key', '_provider_order' );
		$query->set( 'orderby', 'meta_value_num title' );
		$query->set( 'order', 'ASC' );
	}

	/*
	 * Lazer: optional ?county= (conexao_county) and ?categoria=
	 * (conexao_category taxonomy) filters, combinable.
	 */
	if ( $query->is_post_type_archive( 'leisure' ) ) {
		$tax_query = $query->get( 'tax_query' );
		if ( ! is_array( $tax_query ) ) {
			$tax_query = array();
		}

		$county = isset( $_GET['county'] ) ? sanitize_title( wp_unslash( $_GET['county'] ) ) : '';
		// Tipo and Características are multi-select dimensions: each may
		// arrive as a comma-separated string (desktop hyperlink dropdowns)
		// or as an array of slugs (mobile checkbox groups submit categoria[]
		// / atributo[]). Both shapes are normalized by the shared helper —
		// sanitized, de-duplicated, selection order preserved.
		$category_slugs  = conexao_leisure_query_slugs( 'categoria' );
		$attribute_slugs = conexao_leisure_query_slugs( 'atributo' );

		if ( $county ) {
			$tax_query[] = array(
				'taxonomy' => 'conexao_county',
				'field'    => 'slug',
				'terms'    => $county,
			);
		}

		// Tipo: OR within the dimension (Natureza OR Cultura), AND with every
		// other dimension. A single-slug URL (?categoria=natureza) produces
		// exactly the same query as before — IN with one term is identical
		// to the previous single-term match. Unknown slugs simply never
		// match, so they are ignored safely without raw SQL.
		if ( ! empty( $category_slugs ) ) {
			$tax_query[] = array(
				'taxonomy' => 'conexao_category',
				'field'    => 'slug',
				'terms'    => $category_slugs,
				'operator' => 'IN',
			);
		}

		// Características: OR within the dimension, AND with county/category
		// groups.
		if ( taxonomy_exists( 'conexao_leisure_attribute' ) && ! empty( $attribute_slugs ) ) {
			$tax_query[] = array(
				'taxonomy' => 'conexao_leisure_attribute',
				'field'    => 'slug',
				'terms'    => $attribute_slugs,
				'operator' => 'IN',
			);
		}

		if ( ! empty( $tax_query ) ) {
			if ( count( $tax_query ) > 1 ) {
				$tax_query['relation'] = 'AND';
			}
			$query->set( 'tax_query', $tax_query );
		}
	}

	/*
	 * Guias: optional ?categoria= filter via the conexao_category taxonomy.
	 */
	if ( $query->is_post_type_archive( 'guide' ) ) {
		$tax_query = $query->get( 'tax_query' );
		if ( ! is_array( $tax_query ) ) {
			$tax_query = array();
		}

		/*
		 * STAGE 9 — language-neutral ?categoria= resolution.
		 *
		 * `guide` is a Polylang-translated post type with a translated
		 * `conexao_category` taxonomy, so each concept has a PT term and a
		 * linked EN term with its own slug. Polylang scopes term lookups (and
		 * the front-end language canonical) to the current language, so a
		 * Portuguese slug on `/en/guias/?categoria=saude` used to be treated as
		 * "content that only exists in Portuguese" and the request was answered
		 * with a 302 to `/guias/?categoria=saude` instead of filtering the English
		 * archive.
		 *
		 * Resolution order (mirrors the events archive rule, see
		 * conexao_content_archive_query()):
		 *   1. the slug as given (an EN term keeps matching exactly as before);
		 *   2. the term that exists in another language, mapped to its
		 *      counterpart in the current language through the REAL Polylang
		 *      relationship (never a string replacement);
		 *   3. an unknown slug forces no results, so a bogus filter can never
		 *      produce misleading content.
		 *
		 * Portuguese requests are unchanged: the PT slug is term #1, so the
		 * tax_query is byte-identical to the previous behaviour.
		 */
		$category = isset( $_GET['categoria'] ) ? sanitize_title( wp_unslash( $_GET['categoria'] ) ) : '';
		if ( $category ) {
			$resolved_category = conexao_guide_category_filter_term_id( $category );

			if ( $resolved_category > 0 ) {
				$tax_query[] = array(
					'taxonomy' => 'conexao_category',
					'field'    => 'term_id',
					'terms'    => $resolved_category,
				);
			} else {
				// Unknown slug — force no results gracefully.
				$tax_query[] = array(
					'taxonomy' => 'conexao_category',
					'field'    => 'slug',
					'terms'    => '__conexao_no_such_guide_category__',
				);
			}
		}

		if ( ! empty( $tax_query ) ) {
			$query->set( 'tax_query', $tax_query );
		}
	}

	/*
	 * Blog (/blog/): optional ?categoria= filter via the native `category`
	 * taxonomy. Blog category links point at the Blog archive itself
	 * (/blog/?categoria=slug — see conexao_blog_category_filter_url())
	 * instead of the default /category/{slug}/ archive, so the same
	 * main-query filtering pattern used by Guias above applies here. The
	 * native category taxonomy and its archives remain fully intact for
	 * the rest of WordPress (wp-admin, feeds, direct /category/ URLs).
	 *
	 * The current Blog query is reused as-is (no extra query); pagination
	 * links carry the query string, so the filter is preserved on every
	 * page — including the infinite-scroll enhancement, which fetches the
	 * real /page/N/ URLs rendered by the pagination component.
	 */
	if ( $query->is_home() ) {
		$category = isset( $_GET['categoria'] ) ? sanitize_title( wp_unslash( $_GET['categoria'] ) ) : '';
		if ( $category ) {
			$query->set( 'category_name', $category );

			// WordPress prepends sticky posts on the posts page even when
			// they do not match the category filter — a filtered view must
			// never leak off-category sticky posts to the top.
			$query->set( 'ignore_sticky_posts', true );
		}
	}

	/*
	 * Apoiadores: /apoiadores/ follows the SAME editor-curated ordering as
	 * the homepage carousel — "Ordem de exibição" (_sponsor_display_order)
	 * ascending for supporters with a value, then supporters without a value
	 * newest published first (see conexao_sponsor_archive_ordered_ids()).
	 *
	 * A pre-computed ordered post-ID list is applied via post__in +
	 * orderby => post__in — the same mechanism the events archive uses — so
	 * ordering happens BEFORE pagination slices the set. Every /apoiadores
	 * page (and the load-more/pagination links) keeps this fixed global order.
	 */
	if ( $query->is_post_type_archive( 'sponsor' ) ) {
		$sponsor_ids = conexao_sponsor_archive_ordered_ids();

		// post__in => array() is ambiguous in WP_Query; array( 0 )
		// deterministically yields "no supporters".
		if ( empty( $sponsor_ids ) ) {
			$sponsor_ids = array( 0 );
		}

		$query->set( 'post__in', $sponsor_ids );
		$query->set( 'orderby', 'post__in' );
		$query->set( 'order', 'ASC' );
	}
}
add_action( 'pre_get_posts', 'conexao_content_archive_query', 20 );

/**
 * STAGE 3.1 — B2 archive widening (generic post types).
 *
 * Polylang filters front-end queries by language. For B2 archives under /en/
 * (leisure, sponsor, course_provider, job) the PT records with no EN
 * translation must remain visible. The robust mechanism: set the query's
 * `lang` var to BOTH languages. Polylang's is_already_filtered() then sees
 * an explicit language scope and skips its own narrowing, while the query
 * layer resolves `lang=en,pt` to a tax_query matching either language.
 *
 * Events are handled inside conexao_content_archive_query() itself (their
 * post__in ID list is already language-curated); this hook covers the
 * remaining B2 types on any archive/search context. Guides and the remaining
 * pages keep the B1 302 policy (never widened); the static posts page (Blog)
 * is handled separately by conexao_b2_posts_page_pre_query().
 *
 * @param WP_Query $query Query object.
 * @return void
 */
function conexao_b2_archive_widen_query( $query ) {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}

	if ( ! function_exists( 'conexao_polylang_active' ) || ! conexao_polylang_active() ) {
		return;
	}

	if ( 'en' !== conexao_requested_language_slug() ) {
		return;
	}

	// Resolve the effective post type(s): bare archives expose the type via
	// the query string (query['post_type']) rather than the query var.
	$types = array();
	$var   = $query->get( 'post_type' );
	if ( is_array( $var ) ) {
		$types = $var;
	} elseif ( is_string( $var ) && '' !== $var ) {
		$types = array( $var );
	} elseif ( isset( $query->query['post_type'] ) ) {
		$types = is_array( $query->query['post_type'] ) ? $query->query['post_type'] : array( $query->query['post_type'] );
	}

	// Bare post-type archives carry no post_type var at all (e.g. /en/lazer/
	// resolves to post_type=leisure only via the rewrite rule): fall back to
	// the queried object.
	if ( empty( $types ) && $query->is_post_type_archive() ) {
		$archive_types = $query->get( 'post_type' );
		if ( is_string( $archive_types ) && '' !== $archive_types ) {
			$types = array( $archive_types );
		} else {
			$queried = get_queried_object();
			if ( $queried instanceof WP_Post_Type && ! empty( $queried->name ) ) {
				$types = array( $queried->name );
			}
		}
	}

	// post_type=any / empty search queries are out of scope (handled by the
	// search-widening rule, not the archive rule).
	$widen = false;
	foreach ( $types as $type ) {
		if ( function_exists( 'conexao_is_b2_post_type' ) && conexao_is_b2_post_type( (string) $type ) ) {
			$widen = true;
			break;
		}
	}

	if ( ! $widen ) {
		return;
	}

	$query->set( 'lang', 'en,pt' );

	// STAGE 3.2 — never show a PT record next to its own EN translation:
	// a PT master with a published EN translation is replaced by it (the
	// events archive curates the same rule inside Conexao_Event_Query).
	if ( function_exists( 'conexao_b2_translation_replaced_pt_ids' ) ) {
		$replaced = conexao_b2_translation_replaced_pt_ids( $types );
		if ( ! empty( $replaced ) ) {
			// WP_Query ignores post__not_in whenever post__in is set (the two
			// clauses are mutually exclusive in core), so curated ID lists (the
			// sponsor archive's post__in) are filtered directly instead.
			$existing_in = $query->get( 'post__in' );
			if ( is_array( $existing_in ) && ! empty( $existing_in ) ) {
				$query->set( 'post__in', array_values( array_diff( array_map( 'intval', $existing_in ), $replaced ) ) );
			}
			$existing_not_in = $query->get( 'post__not_in' );
			$existing_not_in = is_array( $existing_not_in ) ? $existing_not_in : array();
			$query->set( 'post__not_in', array_map( 'intval', array_merge( $existing_not_in, $replaced ) ) );
		}
	}
}
add_action( 'pre_get_posts', 'conexao_b2_archive_widen_query', 30 );
