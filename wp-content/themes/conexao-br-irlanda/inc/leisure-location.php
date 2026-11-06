<?php
/**
 * Lazer location services — read-only server endpoints.
 *
 * Two public, READ-ONLY REST capabilities back the optional proximity
 * ordering on /lazer/ (and /en/lazer/):
 *
 *   GET /conexao/v1/leisure-coordinates
 *       Every published leisure attraction matching the ACTIVE archive
 *       query (language + county/category/attribute filters), in the
 *       original archive order, with its coordinates or nulls. Attractions
 *       WITHOUT coordinates are included — they are never dropped.
 *
 *   GET /conexao/v1/leisure-cards?ids=1,2,3
 *       The server-rendered leisure cards for a bounded, ordered ID list,
 *       returned in EXACTLY the requested order. Reuses
 *       template-parts/leisure-card.php — there is no second card renderer.
 *
 * Security boundaries (deliberate, see docs/content-model.md):
 *   - Both are public and read-only; they accept NO visitor coordinates and
 *     store nothing.
 *   - Only PUBLISHED `leisure` posts are ever returned; drafts, private
 *     posts and other post types can never appear.
 *   - `ids` must be positive integers, de-duplicated, within a bounded batch
 *     size, and must belong to the current matching set — an arbitrary ID
 *     cannot be used to render an out-of-scope record.
 *   - Editorial coordinate provenance (source / verification) is NEVER
 *     returned.
 *
 * Query reuse: the active filter state is interpreted by the SAME
 * `conexao_leisure_archive_tax_query()` helper the main archive query uses,
 * and the B2 language scope mirrors the archive's own B2 widening, so a
 * filtered archive and its dataset can never disagree.
 *
 * @package Conexao_BR_Irlanda
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST namespace for the Lazer location endpoints.
 *
 * @return string
 */
function conexao_leisure_location_namespace() {
	return 'conexao/v1';
}

/**
 * Maximum number of IDs a single card-fetch request may ask for.
 *
 * @return int
 */
function conexao_leisure_location_max_batch() {
	return 50;
}

/**
 * Resolve the language scope for a location request.
 *
 * The page passes its own language explicitly; the value is validated against
 * the languages Polylang actually knows, so an unknown slug can never widen
 * the query. Without Polylang there is no language layer and '' is returned.
 *
 * @param WP_REST_Request $request Request.
 * @return string Validated language slug, or ''.
 */
function conexao_leisure_location_request_lang( WP_REST_Request $request ) {
	if ( ! function_exists( 'conexao_polylang_active' ) || ! conexao_polylang_active() ) {
		return '';
	}

	$requested = $request->get_param( 'lang' );
	$requested = is_string( $requested ) ? sanitize_key( $requested ) : '';

	$available = array();
	if ( function_exists( 'pll_languages_list' ) ) {
		// `pll_languages_list( array( 'fields' => 'slug' ) )` is declared to
		// return an array of slugs, so the `(array)` cast is a no-op in
		// practice and only guards against an unexpected return. An
		// `is_array()` check here is provably always true and would need a
		// new `phpstan-baseline.neon` entry, which §1.6 forbids.
		$available = array_map( 'strval', (array) pll_languages_list( array( 'fields' => 'slug' ) ) );
	}

	if ( '' !== $requested && in_array( $requested, $available, true ) ) {
		return $requested;
	}

	$current = function_exists( 'conexao_current_language_slug' ) ? conexao_current_language_slug() : '';
	if ( '' !== $current ) {
		return $current;
	}

	$default = function_exists( 'conexao_default_language_slug' ) ? conexao_default_language_slug() : '';

	return '' !== $default ? $default : '';
}

/**
 * WP_Query arguments for the matching attraction set.
 *
 * Mirrors the main leisure archive query: published `leisure` posts, the
 * shared filter clause, the default archive ordering (date, newest first)
 * and the B2 language scope. `posts_per_page => -1` returns the COMPLETE
 * matching catalogue so proximity sorting can be global.
 *
 * @param array $params Filter + language state.
 * @return array
 */
function conexao_leisure_location_query_args( array $params ) {
	$args = array(
		'post_type'              => 'leisure',
		'post_status'            => 'publish',
		'posts_per_page'         => -1,
		'fields'                 => 'ids',
		'orderby'                => 'date',
		'order'                  => 'DESC',
		'ignore_sticky_posts'    => true,
		'no_found_rows'          => true,
		// Prime the meta cache: the coordinate dataset reads two meta keys
		// per attraction, so one primed query beats one query per post.
		'update_post_meta_cache' => true,
		'update_post_term_cache' => false,
	);

	$tax_query = conexao_leisure_archive_tax_query( $params );
	if ( ! empty( $tax_query ) ) {
		$args['tax_query'] = $tax_query;
	}

	$lang = isset( $params['lang'] ) ? (string) $params['lang'] : '';

	if ( function_exists( 'conexao_polylang_active' ) && conexao_polylang_active() && '' !== $lang ) {
		$default = function_exists( 'conexao_default_language_slug' ) ? conexao_default_language_slug() : '';
		if ( '' === $default ) {
			$default = 'pt';
		}

		if ( $lang === $default ) {
			$args['lang'] = $default;
		} elseif ( function_exists( 'conexao_is_b2_post_type' ) && conexao_is_b2_post_type( 'leisure' ) ) {
			// B2 fallback: EN records + untranslated PT records, with PT
			// masters that have a published EN translation replaced (never
			// duplicated) — the archive's own widening rule.
			$args['lang'] = $lang . ',' . $default;

			$replaced = function_exists( 'conexao_b2_translation_replaced_pt_ids' )
				? conexao_b2_translation_replaced_pt_ids( array( 'leisure' ) )
				: array();

			if ( ! empty( $replaced ) ) {
				$args['post__not_in'] = array_map( 'intval', $replaced );
			}
		} else {
			$args['lang'] = $lang;
		}
	}

	return $args;
}

/**
 * The ordered IDs of every attraction matching the given state.
 *
 * @param array $params Filter + language state.
 * @return int[] Attraction IDs in archive order.
 */
function conexao_leisure_location_matching_ids( array $params ) {
	$query = new WP_Query( conexao_leisure_location_query_args( $params ) );

	return array_map( 'intval', (array) $query->posts );
}

/**
 * Parse and validate a comma-separated ID list.
 *
 * @param mixed $raw Raw `ids` parameter.
 * @return int[]|WP_Error Ordered, de-duplicated positive IDs, or an error.
 */
function conexao_leisure_location_parse_ids( $raw ) {
	$parts = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
	$ids   = array();

	foreach ( $parts as $part ) {
		$part = trim( (string) $part );
		if ( '' === $part ) {
			continue;
		}
		if ( ! ctype_digit( $part ) ) {
			return new WP_Error(
				'conexao_leisure_location_invalid_ids',
				__( 'Invalid attraction ID.', 'conexao-br-irlanda' ),
				array( 'status' => 400 )
			);
		}
		$id = (int) $part;
		if ( $id <= 0 ) {
			return new WP_Error(
				'conexao_leisure_location_invalid_ids',
				__( 'Invalid attraction ID.', 'conexao-br-irlanda' ),
				array( 'status' => 400 )
			);
		}
		if ( ! in_array( $id, $ids, true ) ) {
			$ids[] = $id;
		}
	}

	return $ids;
}

/**
 * REST `validate_callback` for the `ids` parameter.
 *
 * @param mixed $value Raw value.
 * @return true|WP_Error
 */
function conexao_leisure_location_validate_ids_param( $value ) {
	$parsed = conexao_leisure_location_parse_ids( $value );
	if ( is_wp_error( $parsed ) ) {
		return $parsed;
	}
	if ( count( $parsed ) > conexao_leisure_location_max_batch() ) {
		return new WP_Error(
			'conexao_leisure_location_batch_too_large',
			__( 'Too many attractions requested in a single request.', 'conexao-br-irlanda' ),
			array( 'status' => 400 )
		);
	}
	return true;
}

/**
 * Render leisure cards for an ordered ID list.
 *
 * Reuses the archive card template (no second renderer). `setup_postdata()`
 * gives the template the same loop context it has on the archive; the global
 * post is restored afterwards.
 *
 * @param int[] $ids Ordered attraction IDs.
 * @return string Escaped card HTML.
 */
function conexao_leisure_location_render_cards( array $ids ) {
	if ( empty( $ids ) ) {
		return '';
	}

	$saved_post = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
	$html       = '';

	foreach ( $ids as $id ) {
		$post_object = get_post( (int) $id );
		if ( ! $post_object instanceof WP_Post || 'leisure' !== $post_object->post_type || 'publish' !== $post_object->post_status ) {
			continue;
		}

		// The template reads the global post (the_ID()/the_title()/
		// get_permalink()), and setup_postdata() does NOT set $GLOBALS['post']
		// on its own — the loop sets it before calling. In this function scope
		// it must therefore be set explicitly.
		$GLOBALS['post'] = $post_object; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- temporary loop context for the card template.
		setup_postdata( $post_object );

		ob_start();
		get_template_part( 'template-parts/leisure', 'card' );
		$html .= ob_get_clean();
	}
	$GLOBALS['post'] = $saved_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the caller's loop context.
	wp_reset_postdata();

	return $html;
}

/**
 * Shared REST arguments (filter state + language).
 *
 * The values are normalised inside `conexao_leisure_archive_tax_query()`
 * (the single filter interpreter), so no second sanitisation layer is added
 * here and an unknown slug can only ever produce "no match".
 *
 * @return array
 */
function conexao_leisure_location_shared_args() {
	return array(
		'county'    => array(
			'type'     => 'string',
			'required' => false,
		),
		'categoria' => array(
			'type'     => 'string',
			'required' => false,
		),
		'atributo'  => array(
			'type'     => 'string',
			'required' => false,
		),
		'lang'      => array(
			'type'     => 'string',
			'required' => false,
		),
	);
}

/**
 * Coordinate dataset handler.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function conexao_leisure_location_coordinates_handler( WP_REST_Request $request ) {
	$lang   = conexao_leisure_location_request_lang( $request );
	$params = array(
		'county'    => (string) $request->get_param( 'county' ),
		'categoria' => $request->get_param( 'categoria' ),
		'atributo'  => $request->get_param( 'atributo' ),
		'lang'      => $lang,
	);

	$ids   = conexao_leisure_location_matching_ids( $params );
	$items = array();
	$index = 0;

	foreach ( $ids as $id ) {
		$coordinates = class_exists( 'Conexao_Data_Model_Meta' )
			? Conexao_Data_Model_Meta::get_coordinates( $id )
			: null;

		$items[] = array(
			'id'    => (int) $id,
			'lat'   => $coordinates ? $coordinates['lat'] : null,
			'lng'   => $coordinates ? $coordinates['lng'] : null,
			'index' => $index,
		);
		++$index;
	}

	return new WP_REST_Response(
		array(
			'lang'  => $lang,
			'count' => count( $items ),
			'items' => $items,
		),
		200
	);
}

/**
 * Ordered card-fetch handler.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function conexao_leisure_location_cards_handler( WP_REST_Request $request ) {
	$requested = conexao_leisure_location_parse_ids( $request->get_param( 'ids' ) );
	if ( is_wp_error( $requested ) ) {
		return $requested;
	}
	if ( count( $requested ) > conexao_leisure_location_max_batch() ) {
		return new WP_Error(
			'conexao_leisure_location_batch_too_large',
			__( 'Too many attractions requested in a single request.', 'conexao-br-irlanda' ),
			array( 'status' => 400 )
		);
	}

	$lang   = conexao_leisure_location_request_lang( $request );
	$params = array(
		'county'    => (string) $request->get_param( 'county' ),
		'categoria' => $request->get_param( 'categoria' ),
		'atributo'  => $request->get_param( 'atributo' ),
		'lang'      => $lang,
	);

	$matching = conexao_leisure_location_matching_ids( $params );
	$in_scope = array_fill_keys( $matching, true );

	// Keep ONLY requested IDs that belong to the current matching set, and
	// keep them in the CALLER-REQUESTED order — never the query's order.
	$ordered = array();
	foreach ( $requested as $id ) {
		if ( isset( $in_scope[ $id ] ) ) {
			$ordered[] = $id;
		}
	}

	return new WP_REST_Response(
		array(
			'ids'   => $ordered,
			'count' => count( $ordered ),
			'html'  => conexao_leisure_location_render_cards( $ordered ),
		),
		200
	);
}

/**
 * Register the two read-only routes.
 *
 * @return void
 */
function conexao_leisure_location_register_routes() {
	register_rest_route(
		conexao_leisure_location_namespace(),
		'/leisure-coordinates',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'conexao_leisure_location_coordinates_handler',
			'permission_callback' => '__return_true',
			'args'                => conexao_leisure_location_shared_args(),
		)
	);

	register_rest_route(
		conexao_leisure_location_namespace(),
		'/leisure-cards',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'conexao_leisure_location_cards_handler',
			'permission_callback' => '__return_true',
			'args'                => array_merge(
				conexao_leisure_location_shared_args(),
				array(
					'ids' => array(
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => 'conexao_leisure_location_validate_ids_param',
					),
				)
			),
		)
	);
}
add_action( 'rest_api_init', 'conexao_leisure_location_register_routes' );

/**
 * JavaScript configuration payload for the Lazer location feature.
 *
 * Injected only on the leisure archive (see inc/assets.php). The visitor's
 * coordinates are NEVER part of this payload — they only ever exist in the
 * browser, after the visitor grants permission.
 *
 * @return array
 */
function conexao_leisure_location_js_config() {
	$locale = function_exists( 'conexao_current_locale' ) ? conexao_current_locale() : get_locale();
	$lang   = function_exists( 'conexao_current_language_slug' ) ? conexao_current_language_slug() : '';

	return array(
		'coordinatesUrl' => rest_url( conexao_leisure_location_namespace() . '/leisure-coordinates' ),
		'cardsUrl'       => rest_url( conexao_leisure_location_namespace() . '/leisure-cards' ),
		'lang'           => $lang,
		'locale'         => str_replace( '_', '-', (string) $locale ),
		'maxBatch'       => conexao_leisure_location_max_batch(),
	);
}

/**
 * Mark the leisure archive body so the CSS/JS scope is explicit.
 *
 * @param string[] $classes Body classes.
 * @return string[]
 */
function conexao_leisure_location_body_class( $classes ) {
	if ( function_exists( 'is_post_type_archive' ) && is_post_type_archive( 'leisure' ) ) {
		$classes[] = 'conexao-leisure-location';
	}
	return $classes;
}
add_filter( 'body_class', 'conexao_leisure_location_body_class' );
