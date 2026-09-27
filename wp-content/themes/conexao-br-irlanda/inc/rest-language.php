<?php
/**
 * Conexão BR Irlanda — bilingual REST contract (Stage 4.1).
 *
 * Implements the approved English-support architecture on the existing WordPress
 * REST API surface so the Flutter app can consume Portuguese (default) and
 * English content safely. Polylang Free sets the REST *language context* from
 * the `lang` parameter but deliberately does NOT filter post collections by
 * language and exposes no translation relationships (see
 * CONEXAO_BR_ENGLISH_STAGE_3_3_REPORT.md §26 item 1). This module closes that
 * gap WITHOUT changing the content architecture:
 *
 *  - Request model: `?lang=pt` / `?lang=en` on the existing endpoints.
 *    Omitting `lang` preserves the exact pre-Stage-4.1 behaviour (no language
 *    filtering, no response change beyond the additive `conexao_language`
 *    field), so existing Portuguese consumers never have to add `lang=pt`.
 *  - Invalid `lang` values are rejected with HTTP 400 `conexao_rest_invalid_lang`
 *    instead of Polylang's silent fall-back to the default language — a mixed
 *    or ambiguous language response is never produced.
 *  - Collection membership mirrors the front-end language policy:
 *      PT (`lang=pt`): records whose language is Portuguese.
 *      EN (`lang=en`): real EN translations; for B2 post types (event, leisure,
 *      sponsor, course_provider, job) untranslated PT records are included as
 *      explicit fallbacks; a PT master that HAS a published EN translation is
 *      replaced by it (never duplicated); B1-only types (guide, post) expose
 *      real EN translations only; hidden events never appear.
 *  - Every record carries a `conexao_language` object:
 *      { lang, is_fallback, translations: { <lang>: { id, url } } }
 *    which lets the client distinguish (1) a real PT record, (2) a real EN
 *    translation, (3) a B2 PT fallback served under EN and (4) a
 *    source-inherited EN record with no PT counterpart — without scraping HTML.
 *  - The existing `link` field stays aligned with the WordPress canonical
 *    rules: real EN record → EN permalink (self-canonical); B2 fallback → PT
 *    permalink (the canonical of untranslated data); source-inherited EN → EN
 *    permalink (self). No second routing system is implemented here.
 *  - Event hard gates: `_event_status` stays authoritative. The public
 *    collection gate (event runtime, `pre_get_posts`) already covers REST
 *    collections; this module extends the SAME rule to REST *detail* requests
 *    so expired/rejected/source_not_found events answer 404 exactly like the
 *    front-end singles do. No REST request creates or mutates event records.
 *  - Taxonomy contract: translated taxonomies (conexao_category, conexao_tag,
 *    category, post_tag) are filtered to the requested language (Polylang's
 *    own REST term filtering, kept); the shared location taxonomies
 *    (conexao_county, conexao_town) deliberately return the same shared terms
 *    for every language — no duplicated/suffixed location terms are created.
 *  - Caching: this module introduces NO new response cache. The only reused
 *    cache is `conexao_b2_translation_replaced_pt_ids()` (language-independent
 *    identity data, invalidated on save/delete by the theme's existing hooks),
 *    so PT and EN responses can never share a cache key and warming one
 *    language cannot poison the other.
 *
 * Rollback: every hook is guarded by `conexao_polylang_active()`; with Polylang
 * inactive the module registers nothing and the REST API behaves exactly as
 * before Stage 4.1.
 *
 * @package conexao-br-irlanda
 */

defined( 'ABSPATH' ) || exit;

/**
 * Post types covered by the bilingual REST contract.
 *
 * @return string[]
 */
function conexao_rest_language_post_types(): array {
	return array( 'event', 'leisure', 'guide', 'post', 'job', 'course_provider', 'sponsor' );
}

/**
 * REST base for a post type (core renames post → posts).
 *
 * @param string $post_type Post type name.
 * @return string
 */
function conexao_rest_language_post_type_rest_base( string $post_type ): string {
	return 'post' === $post_type ? 'posts' : $post_type;
}

/**
 * Translated taxonomies (language-filtered term collections).
 *
 * @return string[]
 */
function conexao_rest_language_translated_taxonomies(): array {
	return array( 'conexao_category', 'conexao_tag', 'category', 'post_tag' );
}

/**
 * Shared location taxonomies (one shared term set for every language).
 *
 * @return string[]
 */
function conexao_rest_language_shared_taxonomies(): array {
	return array( 'conexao_county', 'conexao_town' );
}

/**
 * REST base for a taxonomy (core renames category/post_tag).
 *
 * @param string $taxonomy Taxonomy name.
 * @return string
 */
function conexao_rest_language_taxonomy_rest_base( string $taxonomy ): string {
	$map = array(
		'category' => 'categories',
		'post_tag' => 'tags',
	);

	return $map[ $taxonomy ] ?? $taxonomy;
}

/**
 * The validated `lang` request parameter, or '' when absent.
 *
 * Reads the raw parameter only — validation happens in
 * conexao_rest_language_validate_request(). Languages are discovered from
 * Polylang (never hard-coded), so the accepted values always match the site
 * configuration (currently pt + en).
 *
 * @param WP_REST_Request $request REST request.
 * @return string
 */
function conexao_rest_requested_lang( WP_REST_Request $request ): string {
	$lang = $request->get_param( 'lang' );

	if ( null === $lang || '' === $lang ) {
		return '';
	}

	return is_string( $lang ) ? sanitize_key( $lang ) : '';
}

/**
 * Supported language slugs (Polylang-configured).
 *
 * @return string[]
 */
function conexao_rest_supported_languages(): array {
	if ( ! conexao_polylang_active() ) {
		return array();
	}

	$slugs = pll_languages_list( array( 'fields' => 'slug' ) );

	return is_array( $slugs ) ? array_values( array_map( 'strval', $slugs ) ) : array();
}

/**
 * Does a REST route belong to the bilingual contract surface?
 *
 * Covers the seven content collections (+ details), the six taxonomy
 * collections (+ details) and the search route. Everything else (media,
 * pages, Polylang's own routes, core meta routes) is intentionally out of
 * scope for Stage 4.1.
 *
 * @param string $route REST route, e.g. /wp/v2/event/73.
 * @return bool
 */
function conexao_rest_language_route_supported( string $route ): bool {
	$route = '/' . ltrim( $route, '/' );

	if ( preg_match( '#^/wp/v2/search(?:/|$)#', $route ) ) {
		return true;
	}

	foreach ( conexao_rest_language_post_types() as $post_type ) {
		$base = preg_quote( conexao_rest_language_post_type_rest_base( $post_type ), '#' );
		if ( preg_match( '#^/wp/v2/' . $base . '(?:/\d+)?$#', $route ) ) {
			return true;
		}
	}

	foreach ( array_merge( conexao_rest_language_translated_taxonomies(), conexao_rest_language_shared_taxonomies() ) as $taxonomy ) {
		$base = preg_quote( conexao_rest_language_taxonomy_rest_base( $taxonomy ), '#' );
		if ( preg_match( '#^/wp/v2/' . $base . '(?:/\d+)?$#', $route ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Reject invalid `lang` values with a deterministic 400.
 *
 * Polylang Free's own REST handler silently falls back to the default
 * language for an unknown `lang`, which would produce an ambiguous
 * Portuguese response to a client that asked for something else. The contract
 * fails loudly instead. Runs before the controller callback so no query is
 * executed for an invalid request.
 *
 * @param mixed           $response Current response (passed through).
 * @param array           $handler  Route handler.
 * @param WP_REST_Request $request  REST request.
 * @return mixed
 */
function conexao_rest_language_validate_request( $response, $handler, $request ) {
	if ( ! $request instanceof WP_REST_Request || ! conexao_rest_language_route_supported( (string) $request->get_route() ) ) {
		return $response;
	}

	$raw = $request->get_param( 'lang' );
	if ( null === $raw || '' === $raw ) {
		return $response; // No language requested: pre-Stage-4.1 behaviour.
	}

	$lang = is_string( $raw ) ? sanitize_key( $raw ) : '';
	if ( '' === $lang || ! in_array( $lang, conexao_rest_supported_languages(), true ) ) {
		return new WP_Error(
			'conexao_rest_invalid_lang',
			sprintf(
				/* translators: %s: comma-separated list of supported language slugs. */
				__( 'Invalid lang parameter. Supported values: %s.', 'conexao-br-irlanda' ),
				implode( ', ', conexao_rest_supported_languages() )
			),
			array(
				'status'    => 400,
				'lang'      => is_scalar( $raw ) ? (string) $raw : '',
				'supported' => conexao_rest_supported_languages(),
			)
		);
	}

	return $response;
}
add_filter( 'rest_request_before_callbacks', 'conexao_rest_language_validate_request', 5, 3 );

/**
 * Language taxonomy tax_query clause for a set of language slugs.
 *
 * Polylang stores each post's language as a term of the (non-public)
 * `language` taxonomy; querying it directly is deterministic and does not
 * depend on Polylang's front-end query filters, which are not loaded for
 * REST requests.
 *
 * @param string[] $langs Language slugs.
 * @return array
 */
function conexao_rest_language_tax_clause( array $langs ): array {
	return array(
		'taxonomy' => 'language',
		'field'    => 'slug',
		'terms'    => array_values( $langs ),
	);
}

/**
 * Append a clause to an existing WP_Query tax_query (AND semantics).
 *
 * @param mixed $existing Existing tax_query arg (may be empty).
 * @param array $clause   Clause to append.
 * @return array
 */
function conexao_rest_language_merge_tax_query( $existing, array $clause ): array {
	$tax_query = is_array( $existing ) ? $existing : array();
	$tax_query[] = $clause;
	if ( count( $tax_query ) > 1 && ! isset( $tax_query['relation'] ) ) {
		$tax_query['relation'] = 'AND';
	}

	return $tax_query;
}

/**
 * Apply the bilingual collection rule to a posts collection query.
 *
 * Hooked on `rest_{$post_type}_query` for every contract post type.
 *
 * @param array           $args    WP_Query args prepared by the controller.
 * @param WP_REST_Request $request REST request.
 * @return array
 */
function conexao_rest_language_filter_posts_query( $args, $request ) {
	if ( ! conexao_polylang_active() || ! $request instanceof WP_REST_Request ) {
		return $args;
	}

	$lang = conexao_rest_requested_lang( $request );
	if ( '' === $lang ) {
		return $args; // Default: unchanged pre-Stage-4.1 behaviour.
	}

	$default = conexao_default_language_slug();
	if ( '' === $default ) {
		$default = 'pt';
	}

	// Resolve the post type from the route (the filter name is per-type but
	// the args do not always carry post_type yet; posts use the 'posts' base).
	$route     = (string) $request->get_route();
	$post_type = '';
	foreach ( conexao_rest_language_post_types() as $candidate ) {
		$base = preg_quote( conexao_rest_language_post_type_rest_base( $candidate ), '#' );
		if ( preg_match( '#^/wp/v2/' . $base . '(?:/|$)#', $route ) ) {
			$post_type = $candidate;
			break;
		}
	}

	if ( $lang === $default ) {
		// PT collection: records whose language is the default language.
		$args['tax_query'] = conexao_rest_language_merge_tax_query( $args['tax_query'] ?? array(), conexao_rest_language_tax_clause( array( $default ) ) );
		return $args;
	}

	// Secondary language (EN).
	if ( '' !== $post_type && function_exists( 'conexao_is_b2_post_type' ) && conexao_is_b2_post_type( $post_type ) ) {
		// B2: EN records + untranslated PT records; PT masters with a
		// published EN translation are replaced (never duplicated).
		$args['tax_query'] = conexao_rest_language_merge_tax_query( $args['tax_query'] ?? array(), conexao_rest_language_tax_clause( array( $lang, $default ) ) );

		$replaced = conexao_b2_translation_replaced_pt_ids( array( $post_type ) );
		if ( ! empty( $replaced ) ) {
			// WP_Query ignores post__not_in when post__in is set, so a caller
			// supplied `include` list is filtered directly instead.
			if ( ! empty( $args['post__in'] ) && is_array( $args['post__in'] ) ) {
				$args['post__in'] = array_values( array_diff( array_map( 'intval', $args['post__in'] ), $replaced ) );
				if ( empty( $args['post__in'] ) ) {
					$args['post__in'] = array( 0 ); // Deterministic empty set.
				}
			} else {
				$existing_not_in    = ! empty( $args['post__not_in'] ) && is_array( $args['post__not_in'] ) ? $args['post__not_in'] : array();
				$args['post__not_in'] = array_map( 'intval', array_merge( $existing_not_in, $replaced ) );
			}
		}
	} else {
		// B1 (guide, post): real translations only, no fallback.
		$args['tax_query'] = conexao_rest_language_merge_tax_query( $args['tax_query'] ?? array(), conexao_rest_language_tax_clause( array( $lang ) ) );
	}

	return $args;
}

/**
 * Apply the bilingual rule to the /wp/v2/search post handler query.
 *
 * The search controller runs one WP_Query per searched subtype; each query
 * carries its post type, so the per-type B1/B2 rule applies exactly as it
 * does for the dedicated collections. The event `_event_status` gate keeps
 * applying through the runtime's own `pre_get_posts` hook.
 *
 * @param array           $query_args WP_Query args.
 * @param WP_REST_Request $request    REST request.
 * @return array
 */
function conexao_rest_language_filter_search_query( $query_args, $request ) {
	if ( ! conexao_polylang_active() || ! $request instanceof WP_REST_Request ) {
		return $query_args;
	}

	$lang = conexao_rest_requested_lang( $request );
	if ( '' === $lang ) {
		return $query_args;
	}

	$default = conexao_default_language_slug();
	if ( '' === $default ) {
		$default = 'pt';
	}

	$post_types = (array) ( $query_args['post_type'] ?? array() );
	$is_b2      = false;
	foreach ( $post_types as $type ) {
		if ( function_exists( 'conexao_is_b2_post_type' ) && conexao_is_b2_post_type( (string) $type ) ) {
			$is_b2 = true;
			break;
		}
	}

	if ( $lang === $default ) {
		$query_args['tax_query'] = conexao_rest_language_merge_tax_query( $query_args['tax_query'] ?? array(), conexao_rest_language_tax_clause( array( $default ) ) );
		return $query_args;
	}

	if ( $is_b2 ) {
		$query_args['tax_query'] = conexao_rest_language_merge_tax_query( $query_args['tax_query'] ?? array(), conexao_rest_language_tax_clause( array( $lang, $default ) ) );
		$replaced = conexao_b2_translation_replaced_pt_ids( array_map( 'strval', $post_types ) );
		if ( ! empty( $replaced ) ) {
			$existing_not_in            = ! empty( $query_args['post__not_in'] ) && is_array( $query_args['post__not_in'] ) ? $query_args['post__not_in'] : array();
			$query_args['post__not_in'] = array_map( 'intval', array_merge( $existing_not_in, $replaced ) );
		}
	} else {
		$query_args['tax_query'] = conexao_rest_language_merge_tax_query( $query_args['tax_query'] ?? array(), conexao_rest_language_tax_clause( array( $lang ) ) );
	}

	return $query_args;
}
add_filter( 'rest_post_search_query', 'conexao_rest_language_filter_search_query', 10, 2 );

/**
 * Build the `conexao_language` payload for a post record.
 *
 * @param int    $post_id        Post ID.
 * @param string $requested_lang Validated requested language ('' when absent).
 * @return array{lang:string,is_fallback:bool,translations:array<string,array{id:int,url:string}>}
 */
function conexao_rest_language_post_payload( int $post_id, string $requested_lang ): array {
	$default = conexao_default_language_slug();
	if ( '' === $default ) {
		$default = 'pt';
	}

	$record_lang = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $post_id, 'slug' ) : false;
	if ( ! is_string( $record_lang ) || '' === $record_lang ) {
		$record_lang = $default; // Unassigned records are default-language records.
	}

	$translations = array();
	if ( function_exists( 'pll_get_post_translations' ) ) {
		foreach ( (array) pll_get_post_translations( $post_id ) as $slug => $translated_id ) {
			$translated_id = (int) $translated_id;
			if ( $translated_id === $post_id ) {
				continue;
			}
			$url = get_permalink( $translated_id );
			$translations[ (string) $slug ] = array(
				'id'  => $translated_id,
				'url' => is_string( $url ) ? $url : '',
			);
		}
	}

	return array(
		'lang'         => $record_lang,
		'is_fallback'  => '' !== $requested_lang && $requested_lang !== $record_lang,
		'translations' => $translations,
	);
}

/**
 * Build the `conexao_language` payload for a term record.
 *
 * Shared location terms (county/town) carry no language: `lang` is null and
 * `translations` is empty — the same term serves every language.
 *
 * @param int $term_id Term ID.
 * @return array{lang:string|null,translations:array<string,array{id:int,url:string}>}
 */
function conexao_rest_language_term_payload( int $term_id ): array {
	$lang = function_exists( 'pll_get_term_language' ) ? pll_get_term_language( $term_id, 'slug' ) : false;

	$translations = array();
	if ( function_exists( 'pll_get_term_translations' ) ) {
		foreach ( (array) pll_get_term_translations( $term_id ) as $slug => $translated_id ) {
			$translated_id = (int) $translated_id;
			if ( $translated_id === $term_id ) {
				continue;
			}
			$link = get_term_link( $translated_id );
			$translations[ (string) $slug ] = array(
				'id'  => $translated_id,
				'url' => is_wp_error( $link ) ? '' : (string) $link,
			);
		}
	}

	return array(
		'lang'         => is_string( $lang ) && '' !== $lang ? $lang : null,
		'translations' => $translations,
	);
}

/**
 * Register the `conexao_language` field on the contract surface.
 *
 * Posts (7 types), terms (6 taxonomies) and /wp/v2/search results (object
 * type 'search-result'). The field is read-only and respects the standard
 * `_fields` filtering.
 *
 * @return void
 */
function conexao_rest_language_register_fields(): void {
	if ( ! conexao_polylang_active() ) {
		return;
	}

	foreach ( conexao_rest_language_post_types() as $post_type ) {
		register_rest_field(
			$post_type,
			'conexao_language',
			array(
				'get_callback' => static function ( $prepared, $field, $request ) {
					$post_id = is_array( $prepared ) && isset( $prepared['id'] ) ? (int) $prepared['id'] : 0;
					if ( $post_id <= 0 ) {
						return null;
					}
					$requested = $request instanceof WP_REST_Request ? conexao_rest_requested_lang( $request ) : '';

					return conexao_rest_language_post_payload( $post_id, $requested );
				},
				'schema'       => array(
					'description' => __( 'Language contract metadata: record language, B2 fallback state and linked translations.', 'conexao-br-irlanda' ),
					'type'        => 'object',
					'context'     => array( 'view', 'embed' ),
					'readonly'    => true,
					'properties'  => array(
						'lang'         => array( 'type' => 'string', 'description' => __( 'The record language slug (pt, en).', 'conexao-br-irlanda' ) ),
						'is_fallback'  => array( 'type' => 'boolean', 'description' => __( 'True when the record is served as a B2 fallback under a requested language different from its own.', 'conexao-br-irlanda' ) ),
						'translations' => array( 'type' => 'object', 'description' => __( 'Linked translations keyed by language slug: { id, url }.', 'conexao-br-irlanda' ) ),
					),
				),
			)
		);
	}

	foreach ( array_merge( conexao_rest_language_translated_taxonomies(), conexao_rest_language_shared_taxonomies() ) as $taxonomy ) {
		register_rest_field(
			$taxonomy,
			'conexao_language',
			array(
				'get_callback' => static function ( $prepared ) {
					$term_id = is_array( $prepared ) && isset( $prepared['id'] ) ? (int) $prepared['id'] : 0;

					return $term_id > 0 ? conexao_rest_language_term_payload( $term_id ) : null;
				},
				'schema'       => array(
					'description' => __( 'Language contract metadata: term language (null for shared location terms) and linked translations.', 'conexao-br-irlanda' ),
					'type'        => 'object',
					'context'     => array( 'view', 'embed' ),
					'readonly'    => true,
					'properties'  => array(
						'lang'         => array( 'type' => array( 'string', 'null' ) ),
						'translations' => array( 'type' => 'object' ),
					),
				),
			)
		);
	}

	// /wp/v2/search results (object type 'search-result', the schema title
	// of the search controller): injected through the standard
	// additional-fields pipeline, which runs identically over HTTP and for
	// in-process dispatches. Clients must never infer language from
	// title/body text.
	register_rest_field(
		'search-result',
		'conexao_language',
		array(
			'get_callback' => static function ( $prepared, $field_name, $request ) {
				if ( ! is_array( $prepared ) || ! isset( $prepared['id'], $prepared['type'] ) ) {
					return null;
				}
				$requested = $request instanceof WP_REST_Request ? conexao_rest_requested_lang( $request ) : '';

				if ( 'post' === $prepared['type'] ) {
					return conexao_rest_language_post_payload( (int) $prepared['id'], $requested );
				}
				if ( 'term' === $prepared['type'] ) {
					return conexao_rest_language_term_payload( (int) $prepared['id'] );
				}

				return null;
			},
			'schema'       => array(
				'description' => __( 'Language contract metadata for the search result (post or term language and translations).', 'conexao-br-irlanda' ),
				'type'        => array( 'object', 'null' ),
				'context'     => array( 'view', 'embed' ),
				'readonly'    => true,
			),
		)
	);
}
add_action( 'rest_api_init', 'conexao_rest_language_register_fields' );

/**
 * Add `lang` to the documented collection params of the contract post types.
 *
 * Discoverability only — the parameter is read directly from the request by
 * the query filters above, and validation lives in
 * conexao_rest_language_validate_request().
 *
 * @param array $params Collection params.
 * @return array
 */
function conexao_rest_language_collection_params( $params ) {
	$params['lang'] = array(
		'description' => __( 'Content language: pt (default) or en. Omitting it preserves the legacy unfiltered behaviour.', 'conexao-br-irlanda' ),
		'type'        => 'string',
		'enum'        => conexao_rest_supported_languages(),
	);

	return $params;
}
/**
 * Enforce the detail-endpoint language rules and the event status gate.
 *
 * Runs before the controller callback:
 *  1. Hidden events (`_event_status` = expired/rejected/source_not_found)
 *     answer the same 404 the front-end singles produce — the public REST
 *     surface must never weaken the event visibility gate. Editorial users
 *     (edit_post capability) keep full access.
 *  2. `lang` equal to the record language → normal 200.
 *  3. `lang=en` on a PT record of a B2 type without an EN translation → 200
 *     with `conexao_language.is_fallback = true` (the B2 contract).
 *  4. Any other language mismatch (B1 content under EN, EN record under PT,
 *     PT master that HAS an EN translation requested under EN) → 404
 *     `conexao_rest_language_unavailable` with the available translations in
 *     `data.translations`, so the client can recover deterministically.
 *  5. No `lang` → unchanged behaviour (any record answers 200).
 *
 * @param mixed           $response Current response (passed through).
 * @param array           $handler  Route handler.
 * @param WP_REST_Request $request  REST request.
 * @return mixed
 */
function conexao_rest_language_detail_gate( $response, $handler, $request ) {
	if ( ! conexao_polylang_active() || ! $request instanceof WP_REST_Request ) {
		return $response;
	}

	$route = (string) $request->get_route();
	if ( ! preg_match( '#^/wp/v2/([a-z_]+)/(\d+)$#', $route, $m ) ) {
		return $response; // Collection or non-post route.
	}

	// Map the REST base back to the post type (posts → post).
	$post_type = $m[1];
	$bases     = array();
	foreach ( conexao_rest_language_post_types() as $candidate ) {
		$bases[ conexao_rest_language_post_type_rest_base( $candidate ) ] = $candidate;
	}
	if ( isset( $bases[ $post_type ] ) ) {
		$post_type = $bases[ $post_type ];
	}
	$post_id = (int) $m[2];
	if ( ! in_array( $post_type, conexao_rest_language_post_types(), true ) ) {
		return $response;
	}

	// 1. Event status gate (public requests only). The front-end single
	// answers 404 for hidden events; the REST detail must match. The core
	// error code/message is reused so a hidden event is indistinguishable
	// from a nonexistent one (no existence leak).
	if ( 'event' === $post_type && ! current_user_can( 'edit_post', $post_id ) ) {
		$status = get_post_meta( $post_id, '_event_status', true );
		if ( '' !== $status && 'publish' === get_post_status( $post_id ) && in_array( $status, array( 'expired', 'rejected', 'source_not_found' ), true ) ) {
			return new WP_Error(
				'rest_post_invalid_id',
				__( 'Invalid post ID.', 'conexao-br-irlanda' ),
				array( 'status' => 404 )
			);
		}
	}

	$lang = conexao_rest_requested_lang( $request );
	if ( '' === $lang ) {
		return $response; // No language requested: unchanged behaviour.
	}

	$record_lang = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $post_id, 'slug' ) : false;
	$default     = conexao_default_language_slug();
	if ( '' === $default ) {
		$default = 'pt';
	}
	if ( ! is_string( $record_lang ) || '' === $record_lang ) {
		$record_lang = $default;
	}

	if ( $record_lang === $lang ) {
		return $response; // 2. Correct language.
	}

	// 3. B2 fallback: PT record of a B2 type requested under EN, with no EN
	// translation, renders as a fallback (200 + is_fallback).
	if ( $lang !== $default && $record_lang === $default && conexao_is_b2_post_type( $post_type ) ) {
		$has_translation = function_exists( 'pll_get_post' ) ? (int) pll_get_post( $post_id, $lang ) : 0;
		if ( $has_translation > 0 && $has_translation !== $post_id ) {
			// The PT master is replaced by its EN translation: point at it.
			return conexao_rest_language_unavailable_error( $post_id, $record_lang, $lang );
		}

		return $response; // B2 render: 200, is_fallback=true via the field.
	}

	// 4. Everything else: not available in the requested language.
	return conexao_rest_language_unavailable_error( $post_id, $record_lang, $lang );
}
add_filter( 'rest_request_before_callbacks', 'conexao_rest_language_detail_gate', 10, 3 );

/**
 * Deterministic 404 for a record that is not available in the requested
 * language. Carries the record language and the linked translations so the
 * client can recover without scraping HTML.
 *
 * @param int    $post_id        Post ID.
 * @param string $record_lang    The record language.
 * @param string $requested_lang The requested language.
 * @return WP_Error
 */
function conexao_rest_language_unavailable_error( int $post_id, string $record_lang, string $requested_lang ): WP_Error {
	$payload = conexao_rest_language_post_payload( $post_id, $requested_lang );

	return new WP_Error(
		'conexao_rest_language_unavailable',
		sprintf(
			/* translators: 1: requested language slug, 2: record language slug. */
			__( 'This record is not available in the requested language (%1$s); record language: %2$s.', 'conexao-br-irlanda' ),
			$requested_lang,
			$record_lang
		),
		array(
			'status'       => 404,
			'lang'         => $record_lang,
			'requested'    => $requested_lang,
			'translations' => $payload['translations'],
		)
	);
}


/**
 * Register the per-type collection query filters.
 *
 * `rest_{$post_type}_query` is the core-designed extension point for
 * collection queries; hooking it keeps the language rule inside the existing
 * REST layer (no second routing system, no new endpoints).
 *
 * @return void
 */
function conexao_rest_language_register_query_filters(): void {
	foreach ( conexao_rest_language_post_types() as $post_type ) {
		add_filter( "rest_{$post_type}_query", 'conexao_rest_language_filter_posts_query', 10, 2 );
		add_filter( "rest_{$post_type}_collection_params", 'conexao_rest_language_collection_params' );
	}
}
add_action( 'rest_api_init', 'conexao_rest_language_register_query_filters', 5 );


