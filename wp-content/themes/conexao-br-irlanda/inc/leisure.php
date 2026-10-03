<?php
/**
 * Lazer record data and presentation helpers
 *
 * Lazer (leisure) attribute normalisation, map URL, authoritative external
 * links, related destinations/events and the hero image helper. Record-level
 * helpers for single-leisure.php and the archive cards.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalize the mutually exclusive environment attributes (Ambiente).
 *
 * `Interior + exterior` (slug `interior-exterior`) is a combined/derived
 * state: when it applies, the individual `Interior` and `Exterior`
 * attributes are redundant for frontend presentation and must never render
 * alongside it. This is the single canonical resolution rule — every
 * surface (archive card, single page, future cards) renders whatever this
 * returns, so the card and the page can never disagree.
 *
 * The input may mix structured taxonomy names and legacy-checkbox fallback
 * names; normalization runs AFTER both sources are merged, so redundant
 * environment signals are removed regardless of where they came from. The
 * underlying taxonomy assignments are never modified — this is display
 * normalization only. Taxonomy filtering semantics are unchanged.
 *
 * @param array<string,string> $attr_names Map of attribute slug => display name.
 * @return array<string,string> Normalized map of attribute slug => display name.
 */
function conexao_leisure_normalize_environment_attributes( $attr_names ) {
	if ( isset( $attr_names['interior-exterior'] ) ) {
		unset( $attr_names['interior'], $attr_names['exterior'] );
	}

	return $attr_names;
}

/**
 * Resolve the display set of practical characteristics for a leisure record.
 *
 * Phase 3C — a single shared source for the practical-attribute resolution
 * that used to be duplicated between the single page and the archive card
 * (and any future surface). Primary source is the structured
 * `conexao_leisure_attribute` taxonomy; legacy checkbox meta is the fallback
 * during the transition. Both are merged without ever rendering the same
 * attribute twice. Never renders empty labels — only what is actually stored.
 *
 * The merged set is passed through
 * conexao_leisure_normalize_environment_attributes() so mutually exclusive
 * environment attributes (Interior / Exterior / Interior + exterior) are
 * canonical before any rendering.
 *
 * @param int $post_id Leisure post ID.
 * @return array<string,string> Map of attribute slug => display name.
 */
function conexao_leisure_attributes( $post_id = 0 ) {
	$post_id = $post_id ? (int) $post_id : get_the_ID();

	$attr_names = array();

	$attr_terms = get_the_terms( $post_id, 'conexao_leisure_attribute' );
	if ( $attr_terms && ! is_wp_error( $attr_terms ) ) {
		foreach ( $attr_terms as $term ) {
			$attr_names[ sanitize_title( $term->name ) ] = $term->name;
		}
	}

	// Legacy fallback — only add names not already present from the taxonomy.
	$legacy_attr_map = array(
		'_leisure_family'        => array( 'familias', 'Famílias' ),
		'_leisure_outdoor'       => array( 'exterior', 'Exterior' ),
		'_leisure_indoor'        => array( 'interior', 'Interior' ),
		'_leisure_booking'       => array( 'necessita-reserva', 'Necessita reserva' ),
		'_leisure_accessibility' => array( 'acessivel', 'Acessível' ),
		'_leisure_pet_friendly'  => array( 'pet-friendly', 'Pet friendly' ),
		'_leisure_parking'       => array( 'estacionamento', 'Estacionamento' ),
	);
	foreach ( $legacy_attr_map as $meta_key => $mapping ) {
		if ( '1' === (string) get_post_meta( $post_id, $meta_key, true ) && ! isset( $attr_names[ $mapping[0] ] ) ) {
			$attr_names[ $mapping[0] ] = $mapping[1];
		}
	}

	$legacy_free = get_post_meta( $post_id, '_leisure_free', true );
	if ( '' !== (string) $legacy_free ) {
		$free_lower = mb_strtolower( (string) $legacy_free, 'UTF-8' );
		$is_free    = ( '1' === (string) $legacy_free )
			|| false !== strpos( $free_lower, 'gratuit' )
			|| false !== strpos( $free_lower, 'free' )
			|| false !== strpos( $free_lower, 'grátis' );
		if ( $is_free && ! isset( $attr_names['gratuito'] ) ) {
			$attr_names['gratuito'] = 'Gratuito';
		}
	}

	// Canonical environment normalization: when `Interior + exterior`
	// applies, the individual Interior/Exterior attributes never render.
	return conexao_leisure_normalize_environment_attributes( $attr_names );
}

/**
 * Resolve the best available map URL for a leisure location.
 *
 * Phase 3B — powers the "Ver localização no mapa" link on the individual
 * Lazer page so an internal record can always answer "Onde fica?".
 *
 * Priority:
 * 1. `_leisure_map_url` — existing canonical map link. Existing data is never
 *    replaced by a generated URL.
 * 2. A deterministic Google Maps search URL derived at render time from the
 *    verified address (`_leisure_address`, plus town/county when available).
 * 3. Fallback: a deterministic Google Maps search URL derived from
 *    title + town + county. Only generated when at least one location signal
 *    exists beyond the title — a bare title is never enough.
 *
 * The derived URL uses the official Google Maps URL scheme
 * (https://www.google.com/maps/search/?api=1&query=...): no API key, no
 * geocoding, no remote requests, no map embed — identical data always
 * produces the identical URL, and it is generated at render time.
 *
 * @param int $post_id Leisure post ID.
 * @return string Map URL, or '' when no sufficient location data exists.
 */
function conexao_leisure_map_url( $post_id = 0 ) {
	$post_id = $post_id ? (int) $post_id : get_the_ID();

	if ( ! $post_id || 'leisure' !== get_post_type( $post_id ) ) {
		return '';
	}

	// 1. Existing canonical map data always wins.
	$stored = trim( (string) get_post_meta( $post_id, '_leisure_map_url', true ) );
	if ( $stored && preg_match( '#^https?://#i', $stored ) && esc_url_raw( $stored ) === $stored ) {
		return $stored;
	}

	$address = trim( (string) get_post_meta( $post_id, '_leisure_address', true ) );
	$town    = trim( (string) get_post_meta( $post_id, '_leisure_town', true ) );

	$county_name = '';
	$counties    = get_the_terms( $post_id, 'conexao_county' );
	if ( $counties && ! is_wp_error( $counties ) ) {
		$county_name = trim( $counties[0]->name );
	}

	// 2. Verified address present — search on the address (plus town/county).
	if ( '' !== $address ) {
		$query_parts = array_values( array_filter( array( $address, $town, $county_name ) ) );
	} else {
		// 3. Fallback: title + town + county. Requires at least one location
		// signal beyond the title; otherwise no map link is generated.
		if ( '' === $town && '' === $county_name ) {
			return '';
		}
		$query_parts = array_values( array_filter( array( get_the_title( $post_id ), $town, $county_name ) ) );
	}

	if ( empty( $query_parts ) ) {
		return '';
	}

	$query_parts[] = 'Ireland';

	return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( implode( ', ', $query_parts ) );
}

/**
 * Display-only authoritative information links for an internal leisure page.
 *
 * Phase 3B — separates "which authoritative links should be displayed?"
 * (this function) from "should the page redirect externally?"
 * (conexao_leisure_external_url(), inc/seo.php). An internal record may
 * surface an Official Website / Discover Ireland reference (or the Phase 2
 * practical-verification source) without the page itself becoming an
 * external redirect. Records classified for the external redirect never
 * render a page at all and never yield display links here, so the presence
 * of a display link can never influence the redirect classification.
 *
 * Priority: Official Website ('Site oficial'), Discover Ireland
 * ('Ver no Discover Ireland'), then the practical-verification source URL
 * ('Mais informações') when it is not already listed. URLs are validated the
 * same way as redirect candidates (absolute http(s) only) and deduplicated.
 *
 * @param int $post_id Leisure post ID.
 * @return array[] List of array( 'url' => string, 'label' => string ).
 */
function conexao_leisure_authoritative_links( $post_id = 0 ) {
	$post_id = $post_id ? (int) $post_id : get_the_ID();

	if ( ! $post_id || 'leisure' !== get_post_type( $post_id ) ) {
		return array();
	}

	// Only internal pages display authoritative links. The canonical external
	// classification always wins: if the record redirects, nothing is shown.
	if ( function_exists( 'conexao_leisure_external_url' ) && conexao_leisure_external_url( $post_id ) ) {
		return array();
	}

	$links     = array();
	$seen_urls = array();

	$candidates = array(
		array( (string) get_post_meta( $post_id, '_leisure_official_website', true ), __( 'Site oficial', 'conexao-br-irlanda' ) ),
		array( (string) get_post_meta( $post_id, '_leisure_discover_ireland', true ), __( 'Ver no Discover Ireland', 'conexao-br-irlanda' ) ),
		array( (string) get_post_meta( $post_id, '_leisure_practical_source_url', true ), __( 'Mais informações', 'conexao-br-irlanda' ) ),
	);

	foreach ( $candidates as $candidate ) {
		$url   = trim( $candidate[0] );
		$label = $candidate[1];

		if ( '' === $url || isset( $seen_urls[ $url ] ) ) {
			continue;
		}

		// Defence in depth: only ever link to an absolute http(s) URL.
		if ( ! preg_match( '#^https?://#i', $url ) || esc_url_raw( $url ) !== $url ) {
			continue;
		}

		$seen_urls[ $url ] = true;
		$links[]           = array(
			'url'   => $url,
			'label' => $label,
		);
	}

	return $links;
}

/**
 * Select related internal leisure destinations for a single Lazer page.

/**
 * Select related internal leisure destinations for a single Lazer page.
 *
 * Phase 3A — the related section is an internal navigation hub: a related
 * destination is eligible only when it does NOT qualify for the existing
 * external redirect, i.e. conexao_leisure_external_url() (inc/seo.php, the
 * canonical classification also used by the template_redirect hook and the
 * archive cards) returns '' for it. Records with an Official Website or
 * Discover Ireland URL are therefore never recommended here.
 *
 * Matching strategy (reliable signals confirmed by the Phase 3 audit):
 * 1. Up to 3 eligible internal destinations in the same county.
 * 2. If fewer than 3 exist, supplement with same-category eligible
 *    internal destinations. Duplicates are avoided and the current post is
 *    always excluded. No keyword matching, no geographical proximity, no
 *    manual relationships.
 *
 * Efficiency: at most two lightweight WP_Query calls (the category query
 * only runs when the county query left the set short), both with
 * no_found_rows and default post-meta caching so the external-URL check
 * reads from the meta cache instead of issuing extra queries.
 *
 * @param int $leisure_id Current leisure post ID.
 * @return WP_Post[] Up to 3 related internal destination posts (may be empty).
 */
function conexao_leisure_related_internal_destinations( $leisure_id ) {
	$leisure_id = absint( $leisure_id );
	if ( ! $leisure_id || 'leisure' !== get_post_type( $leisure_id ) ) {
		return array();
	}

	$limit = 3;
	$found = array();

	// Match batches, most specific signal first: same county, then same category.
	$batches = array();
	$counties = get_the_terms( $leisure_id, 'conexao_county' );
	if ( $counties && ! is_wp_error( $counties ) ) {
		$batches[] = array(
			'taxonomy' => 'conexao_county',
			'field'    => 'term_id',
			'terms'    => wp_list_pluck( $counties, 'term_id' ),
		);
	}
	$categories = get_the_terms( $leisure_id, 'conexao_category' );
	if ( $categories && ! is_wp_error( $categories ) ) {
		$batches[] = array(
			'taxonomy' => 'conexao_category',
			'field'    => 'term_id',
			'terms'    => wp_list_pluck( $categories, 'term_id' ),
		);
	}

	// Enough candidates to survive batches where many records are externally
	// redirected; the eligibility check itself is cache-only (no extra SQL).
	$candidates_per_batch = 20;

	foreach ( $batches as $batch ) {
		if ( count( $found ) >= $limit ) {
			break;
		}

		$query = new WP_Query(
			array(
				'post_type'           => 'leisure',
				'post_status'         => 'publish',
				'post__not_in'        => array_merge( array( $leisure_id ), $found ),
				'posts_per_page'      => $candidates_per_batch,
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'tax_query'           => array( $batch ),
			)
		);

		foreach ( $query->posts as $candidate ) {
			if ( count( $found ) >= $limit ) {
				break;
			}

			// Genuinely internal only: skip records with an external
			// destination (they redirect off-site). Canonical classification.
			if ( function_exists( 'conexao_leisure_external_url' ) && conexao_leisure_external_url( $candidate->ID ) ) {
				continue;
			}

			$found[] = $candidate->ID;
		}
	}

	if ( empty( $found ) ) {
		return array();
	}

	return array_map( 'get_post', $found );
}

/**
 * Related upcoming events for a leisure destination (Phase 3C).
 *
 * The county (`conexao_county`) is the ONLY reliable relationship between a
 * leisure destination and an event in this context — we must never match on
 * category, keywords, title similarity, free text or geographic distance.
 *
 * This intersects the SHARED, cached upcoming-event list from the existing
 * event runtime (`Conexao_Event_Query::upcoming_events()` via
 * `conexao_event_upcoming_ids()`) with the destination's county term. It does
 * NOT run a second event-query system and does NOT create a per-page
 * transient: the event runtime's date-keyed cache is the single source, and
 * this narrows it with one `post__in` + `tax_query` query. `orderby =>
 * post__in` preserves the runtime's existing occurrence ordering (next
 * occurrence ascending, recurring events using their own next-occurrence
 * logic, multi-day events using their active-date logic), and each event
 * appears exactly once.
 *
 * Only genuinely internal destinations can reach the single page (external
 * records redirect before template rendering). This helper still guards on
 * the canonical `conexao_leisure_external_url()` classification so a
 * defensive call can never surface events for a record that redirects away.
 *
 * @param int $leisure_id Leisure post ID.
 * @return WP_Post[] Up to 3 same-county upcoming events (runtime order), or
 *                   an empty array when none qualify.
 */
function conexao_leisure_related_events( $leisure_id ) {
	$leisure_id = absint( $leisure_id );
	if ( ! $leisure_id || 'leisure' !== get_post_type( $leisure_id ) ) {
		return array();
	}

	// Only internal pages get the section (defensive; see docs above).
	if ( function_exists( 'conexao_leisure_external_url' ) && conexao_leisure_external_url( $leisure_id ) ) {
		return array();
	}

	// County is the only reliable relationship.
	$counties = get_the_terms( $leisure_id, 'conexao_county' );
	if ( ! $counties || is_wp_error( $counties ) || empty( $counties ) ) {
		return array();
	}

	// Consume the existing cached upcoming-event list (runtime ordered).
	$upcoming_ids = conexao_event_upcoming_ids();
	if ( ! is_array( $upcoming_ids ) || empty( $upcoming_ids ) ) {
		return array();
	}

	// One filtered subset query over the SAME cached IDs: no full re-query of
	// every event, no second occurrence evaluation, no per-county transient.
	$query = new WP_Query(
		array(
			'post_type'              => 'event',
			'post_status'            => 'publish',
			'post__in'               => $upcoming_ids, // Already _event_status gated + occurrence ordered by the runtime.
			'orderby'                => 'post__in',
			'order'                  => 'ASC',
			'posts_per_page'         => 3,
			'tax_query'              => array(
				array(
					'taxonomy' => 'conexao_county',
					'field'    => 'term_id',
					'terms'    => wp_list_pluck( $counties, 'term_id' ),
				),
			),
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	return $query->posts; // post__in order preserves the runtime ordering.
}

/**
 * Build the responsive hero image for the internal leisure single page.
 *
 * Phase 3C — performance: the previous hero used the 1200&times;600 hard crop
 * directly with eager loading and NO srcset, so every viewport downloaded the
 * full 1200px image. A hard-cropped size produces no proportional rungs, so
 * WordPress emits no srcset for it (this is why the old
 * `get_the_post_thumbnail( ..., 'conexao-hero', ... )` had none).
 *
 * Fix (same pattern as `conexao_sponsor_carousel_image()`): request a smaller
 * PROPORTIONAL size as the `src` so the WordPress-generated `srcset` keeps the
 * low rungs (768/1024/1536/2048), then let the `sizes` attribute pick the
 * right candidate per viewport — mobile downloads ~768–1024px instead of
 * 1200px. The hero keeps a constant 2:1 visual box via `object-fit: cover`,
 * so any served candidate fills the same frame (see leisure.css). This stays
 * LCP: the hero is the real above-the-fold image, so it keeps `loading="eager"`
 * with `fetchpriority="high"` and `decoding="async"`. No JS image library.
 *
 * Attribution/alt behaviour is untouched: alt comes from `$alt` exactly as the
 * single page resolved it before.
 *
 * @param int    $leisure_id Leisure post ID.
 * @param string $alt        Alt text (already resolved with title fallback).
 * @return string Responsive <img> HTML, or '' when the post has no image.
 */
function conexao_leisure_hero_image( $leisure_id, $alt = '' ) {
	$attachment_id = get_post_thumbnail_id( $leisure_id );
	if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
		return '';
	}

	// Base the ladder on a proportional size so the srcset includes the small
	// rungs; `sizes` below picks per viewport (medium_large = 768px).
	$size = 'medium_large';
	$src  = wp_get_attachment_image_url( $attachment_id, $size );
	if ( ! $src ) {
		// No proportional derivative (unlikely for imported/uploaded images);
		// fall back to the canonical hero size as before.
		$size = 'conexao-hero';
		$src  = wp_get_attachment_image_url( $attachment_id, $size );
		if ( ! $src ) {
			return '';
		}
	}

	return wp_get_attachment_image(
		$attachment_id,
		$size,
		false,
		array(
			'class'         => 'leisure-single-hero-img',
			'loading'       => 'eager',
			'decoding'      => 'async',
			'fetchpriority' => 'high',
			'alt'           => $alt,
			// The hero spans the content column (site container is 1200px).
			// Desktop needs ~1200 CSS px (more at high DPR), mobile ~92vw.
			'sizes'         => '(min-width: 1200px) 1200px, (min-width: 769px) 96vw, 92vw',
		)
	);
}
