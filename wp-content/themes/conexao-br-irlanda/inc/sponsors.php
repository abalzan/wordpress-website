<?php
/**
 * Sponsor imagery, ordering and contact-channel helpers
 *
 * Apoiadores (sponsor) data helpers: featured image/carousel resolution,
 * the featured and archive-ordered ID lists, sponsor contact rows and the
 * shared contact label/dedupe/icon presentation helpers.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function conexao_sponsor_image_id( $sponsor_id ) {
	$candidates = array(
		absint( get_post_meta( $sponsor_id, '_sponsor_image', true ) ),
		absint( get_post_meta( $sponsor_id, '_sponsor_mobile_image', true ) ),
		absint( get_post_meta( $sponsor_id, '_sponsor_desktop_image', true ) ),
	);

	$legacy = get_post_meta( $sponsor_id, '_sponsor_logo', true );
	if ( $legacy && ctype_digit( (string) $legacy ) ) {
		$candidates[] = absint( $legacy );
	}

	$candidates[] = absint( get_post_thumbnail_id( $sponsor_id ) );

	foreach ( $candidates as $candidate_id ) {
		if ( $candidate_id && wp_attachment_is_image( $candidate_id ) ) {
			return $candidate_id;
		}
	}

	return 0;
}

/**
 * Build the responsive image markup for an Apoiador carousel slide.
 *
 * ONE canonical portrait asset ("Imagem do Apoiador") serves every
 * breakpoint — desktop and mobile share the same artwork and the same visual
 * portrait treatment; there is no responsive source switching between
 * different Apoiador images. WordPress still serves appropriately sized
 * derivatives via srcset/sizes so artwork stays crisp at the enlarged display
 * scale without downloading oversized files.
 *
 * Alt text comes from the attachment's alt field and falls back to the
 * sponsor name, so screen readers always announce the supporter exactly once.
 *
 * width/height attributes carry truthful intrinsic metadata of the served
 * size only; CSS fully determines the rendered box, so they never stretch or
 * resize anything (same model as the hero background picture).
 *
 * @param int    $sponsor_id    Sponsor post ID.
 * @param string $sponsor_title Sponsor name (alt-text fallback).
 * @return string Image HTML, or '' when the sponsor has no usable image.
 */
function conexao_sponsor_carousel_image( $sponsor_id, $sponsor_title = '', $eager = false ) {
	$attachment_id = conexao_sponsor_image_id( $sponsor_id );

	if ( ! $attachment_id ) {
		return '';
	}

	// Request the proportional 'medium' (300px) derivative as the src so the
	// srcset ladder keeps EVERY candidate ≥300px — including the dedicated
	// 512px 'conexao-sponsor-tile' derivative — and lets `sizes` below pick
	// the right one per viewport. (wp_get_attachment_image_srcset() excludes
	// candidates smaller than the requested size, so requesting the 512px
	// tile directly would drop the 300px rung.)
	$size = 'medium';
	$src  = wp_get_attachment_image_url( $attachment_id, $size );
	if ( ! $src ) {
		return '';
	}

	// Meaningful alt text with a sponsor-name fallback.
	$alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
	if ( '' === $alt ) {
		$alt = $sponsor_title;
	}

	$dimensions = wp_get_attachment_image_src( $attachment_id, $size );
	$srcset     = wp_get_attachment_image_srcset( $attachment_id, $size );

	$html = '<img src="' . esc_url( $src ) . '"';
	if ( $srcset ) {
		$html .= ' srcset="' . esc_attr( $srcset ) . '"';
	}
	// Actual tile width hint: on desktop the Hero's right column is ~30vw;
	// on mobile the portrait tile is roughly 46% of the content width. With
	// the 512px 'conexao-sponsor-tile' derivative registered, this keeps the
	// browser on the small candidate instead of the 768/960px originals —
	// the tile never renders wider than ~350 CSS px, so DPR-2 needs at most
	// ~700 device px and 512 is the closest adequate candidate.
	$html .= ' sizes="(min-width: 769px) 30vw, 46vw"';
	if ( $dimensions ) {
		$html .= ' width="' . esc_attr( (int) $dimensions[1] ) . '"'
			. ' height="' . esc_attr( (int) $dimensions[2] ) . '"';
	}
	// Above-the-fold usage (homepage Hero carousel, first slide) loads
	// eagerly; every other usage stays lazy. The Hero renders its first
	// slide immediately on page load, so lazy-loading it only delays
	// paint of visible content — no bandwidth is ever saved.
	$loading_attr = $eager ? 'loading="eager" decoding="async"' : 'loading="lazy" decoding="async"';

	$html .= ' alt="' . esc_attr( $alt ) . '" ' . $loading_attr . ' />';

	return $html;
}

/**
 * Featured Apoiadores for the homepage carousel.
 *
 * Fully data-driven from the EXISTING sponsor post type and its editorial
 * fields — no new content model and no duplicated supporter data:
 *
 *   - "Apoiador em destaque" (_sponsor_featured = 1) decides WHAT appears.
 *   - "Ordem de exibição"   (_sponsor_display_order) decides WHERE it appears.
 *
 * Editing a supporter in wp-admin therefore updates the homepage carousel
 * automatically; no code change is ever required.
 *
 * Ordering is deterministic so the carousel never shuffles between loads:
 *   1. _sponsor_display_order ascending (numeric; supporters without a
 *      value sort last so they are never hidden just because the field
 *      was left blank),
 *   2. title ascending as tiebreaker for equal order values.
 *
 * Results are transient-cached under "conexao_home_sponsors" — the exact
 * key conexao_homepage_cache_invalidate() already deletes whenever any
 * sponsor is saved, updated or deleted — so admin edits appear on the
 * homepage immediately (with a 5-minute safety-net expiration).
 *
 * @return array[] List of normalized sponsor card data arrays.
 */
function conexao_get_featured_sponsors() {
	$cache_key = conexao_lang_cache_key( 'conexao_home_sponsors' );
	$cached    = get_transient( $cache_key );

	if ( false !== $cached && is_array( $cached ) ) {
		return $cached;
	}

	$query_args = array(
		'post_type'      => 'sponsor',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'no_found_rows'  => true,
		'meta_query'     => array(
			array(
				'key'     => '_sponsor_featured',
				'value'   => '1',
				'compare' => '=',
			),
		),
	);

	// STAGE 7.x — B2 sponsor selection for the English homepage. The hero is
	// a secondary query, so it does not inherit the archive's language scope.
	// On an EN request, use the approved B2 set: published EN sponsors plus
	// published PT sponsors that have no published EN translation. A PT
	// master with an EN translation is replaced by that translation, never
	// shown twice. This mirrors conexao_b2_archive_widen_query() and keeps
	// the original PT query byte-for-byte unchanged.
	if ( function_exists( 'conexao_polylang_active' ) && conexao_polylang_active() && function_exists( 'conexao_requested_language_slug' ) && 'en' === conexao_requested_language_slug() ) {
		$query_args['lang'] = 'en,pt';

		if ( function_exists( 'conexao_b2_translation_replaced_pt_ids' ) ) {
			$replaced_pt_ids = conexao_b2_translation_replaced_pt_ids( array( 'sponsor' ) );
			if ( ! empty( $replaced_pt_ids ) ) {
				$query_args['post__not_in'] = array_map( 'intval', $replaced_pt_ids );
			}
		}
	}

	$query = new WP_Query( $query_args );

	$sponsors = array();

	if ( $query->have_posts() ) {
		foreach ( $query->posts as $sponsor_index => $sponsor_post ) {
			$sponsor_id = $sponsor_post->ID;
			$link       = get_post_meta( $sponsor_id, '_sponsor_link', true );

			$terms    = get_the_terms( $sponsor_id, 'conexao_category' );
			$category = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : '';

			$counties = get_the_terms( $sponsor_id, 'conexao_county' );
			$county   = ( $counties && ! is_wp_error( $counties ) ) ? $counties[0]->name : '';

			// Mirror get_the_excerpt(): manual excerpt wins, otherwise derive
			// one from the content. Trimmed to the same 12 words the homepage
			// card has always displayed.
			$excerpt = trim( (string) $sponsor_post->post_excerpt );
			if ( '' === $excerpt ) {
				$excerpt = wp_strip_all_tags( $sponsor_post->post_content );
			}

			$sponsors[] = array(
				'title'         => $sponsor_post->post_title,
				'permalink'     => get_permalink( $sponsor_id ),
				'url'           => $link,
				'category'      => $category,
				'county'        => $county,
				'description'   => wp_trim_words( $excerpt, 12, '...' ),
				// Responsive artwork: a <picture> that serves the portrait
				// Imagem Mobile at ≤768px and the landscape Imagem Desktop
				// above it (see conexao_sponsor_carousel_image()). Requested
				// at the "large" registered size (falls back to the original
				// file when smaller) with srcset candidates so logos stay
				// crisp at the enlarged display scale. CSS object-fit:contain
				// still preserves every asset's natural proportions inside
				// its breakpoint-specific tile frame.
				// The Hero renders slide 0 as soon as the page paints, so
				// the first image loads eagerly; the rest stay lazy.
				'image'         => conexao_sponsor_carousel_image( $sponsor_id, $sponsor_post->post_title, 0 === $sponsor_index ),
				'display_order' => get_post_meta( $sponsor_id, '_sponsor_display_order', true ),
			);
		}
		wp_reset_postdata();
	}

	// Deterministic ordering: Ordem de exibição ascending (numeric), ties
	// broken by title ascending.
	usort( $sponsors, function( $a, $b ) {
		$order_a = ( '' !== (string) $a['display_order'] ) ? (int) $a['display_order'] : PHP_INT_MAX;
		$order_b = ( '' !== (string) $b['display_order'] ) ? (int) $b['display_order'] : PHP_INT_MAX;

		if ( $order_a === $order_b ) {
			return strcasecmp( $a['title'], $b['title'] );
		}

		return ( $order_a < $order_b ) ? -1 : 1;
	} );

	set_transient( $cache_key, $sponsors, 300 );

	return $sponsors;
}

/**
 * Ordered list of published Apoiador post IDs for the /apoiadores/ archive.
 *
 * Follows the SAME editor-curated ordering source as the homepage carousel —
 * the existing "Ordem de exibição" field (`_sponsor_display_order`) — so the
 * archive and the homepage can never disagree about the position of an
 * explicitly ordered supporter:
 *
 *   1. Apoiadores WITH a custom order come first, sorted by that order
 *      ascending (1 → 2 → 3 → …). The value is cast to an integer exactly
 *      like the homepage carousel (`conexao_get_featured_sponsors()`), so
 *      numeric 0 is a VALID order — never treated as "no order" — and a
 *      non-numeric edit degrades to 0 the same way. Duplicate order values
 *      never discard a supporter: ties are broken by title ascending — the
 *      same deterministic secondary sort the homepage carousel already uses
 *      for equal order values — then by post ID ascending for full
 *      determinism (identical titles).
 *   2. Apoiadores WITHOUT a custom order (missing, empty, null) sort after
 *      every ordered supporter, newest published first (post_date DESC), with
 *      post ID DESC as the final stable tiebreak for equal publication dates.
 *
 * "Has a custom order" is decided by the exact same emptiness check the
 * homepage carousel performs (`'' !== (string) $order`), so both surfaces
 * always agree on whether a supporter belongs to the ordered group.
 *
 * No caching: the supporter count is small and a per-request read makes a
 * freshly saved "Ordem de exibição" visible on the very next archive load.
 *
 * The main query consumes this list via post__in + orderby => post__in (the
 * same mechanism the events archive uses), so ordering happens BEFORE
 * pagination slices the set — every /apoiadores page keeps this global order.
 *
 * @return int[] Ordered published sponsor post IDs.
 */
function conexao_sponsor_archive_ordered_ids() {
	$sponsor_query_args = array(
		'post_type'              => 'sponsor',
		'post_status'            => 'publish',
		'posts_per_page'         => -1,
		'no_found_rows'          => true,
		'orderby'                => 'ID',
		'order'                  => 'ASC',
		'update_post_meta_cache' => true,
		'update_post_term_cache' => false,
	);
	// STAGE 3.1 — B2 fallback: secondary queries do not inherit the
	// front-end language context, so on EN requests include both languages.
	// The archive main query then intersects this ordered list with the
	// language-curated set (EN records + B2 PT records).
	if ( function_exists( 'conexao_polylang_active' ) && conexao_polylang_active() && function_exists( 'conexao_requested_language_slug' ) && 'en' === conexao_requested_language_slug() ) {
		$sponsor_query_args['lang'] = 'en,pt';
	}
	$sponsors = get_posts( $sponsor_query_args );

	if ( empty( $sponsors ) ) {
		return array();
	}

	$ordered   = array();
	$unordered = array();

	foreach ( $sponsors as $sponsor ) {
		$item = array(
			'ID'        => (int) $sponsor->ID,
			'title'     => $sponsor->post_title,
			'order'     => get_post_meta( $sponsor->ID, '_sponsor_display_order', true ),
			'post_date' => $sponsor->post_date,
		);

		// Same emptiness check as the homepage carousel: only an empty /
		// missing value means "no custom order". Numeric 0 stays valid.
		if ( '' !== (string) $item['order'] ) {
			$ordered[] = $item;
		} else {
			$unordered[] = $item;
		}
	}

	// Group 1 — custom order ascending; ties by title ascending (the same
	// deterministic secondary sort the homepage carousel uses), then by post
	// ID ascending so duplicate order values can never hide a supporter.
	usort( $ordered, function( $a, $b ) {
		$order_a = (int) $a['order'];
		$order_b = (int) $b['order'];

		if ( $order_a === $order_b ) {
			$title_cmp = strcasecmp( $a['title'], $b['title'] );
			if ( 0 !== $title_cmp ) {
				return $title_cmp;
			}
			return $a['ID'] <=> $b['ID'];
		}

		return ( $order_a < $order_b ) ? -1 : 1;
	} );

	// Group 2 — no custom order: newest published first, post ID DESC as the
	// final stable tiebreak for identical publication dates.
	usort( $unordered, function( $a, $b ) {
		if ( $a['post_date'] !== $b['post_date'] ) {
			return strcmp( $b['post_date'], $a['post_date'] );
		}
		return $b['ID'] <=> $a['ID'];
	} );

	return array_merge(
		wp_list_pluck( $ordered, 'ID' ),
		wp_list_pluck( $unordered, 'ID' )
	);
}

/**
 * Normalize the contact rows displayed on an Apoiador detail page.
 *
 * Merges the TWO existing contact sources into one ordered display list
 * without duplicating data or changing how anything is stored:
 *
 *   1. `_sponsor_link`     — the canonical/official website. Rendered FIRST
 *      so long-standing records that only have this field keep working
 *      unchanged (backwards compatibility).
 *   2. `_sponsor_contacts` — the structured repeater rows managed by the
 *      admin editor (see Conexao_Data_Model_Contacts), rendered in their
 *      stored (admin-curated) order.
 *
 * A Website-type repeater row pointing at the SAME address as
 * `_sponsor_link` is skipped, so one URL never renders twice. Malformed
 * rows are dropped defensively. Every returned row is directly usable as
 * a link href (mailto: included) and carries an `external` flag telling
 * the template whether the link leaves the site (target="_blank").
 *
 * @param int $post_id Sponsor post ID.
 * @return array<int,array{type:string,url:string,label:string,external:bool}>
 */
function conexao_sponsor_contact_rows( $post_id ) {
	$post_id = absint( $post_id );
	if ( ! $post_id ) {
		return array();
	}

	$website = get_post_meta( $post_id, '_sponsor_link', true );
	$stored  = class_exists( 'Conexao_Data_Model_Contacts' )
		? Conexao_Data_Model_Contacts::get( $post_id )
		: array();

	$rows = array();
	$seen = array();

	// The official website leads the list (backwards compatibility:
	// an Apoiador with only `_sponsor_link` still gets a valid,
	// complete-looking contact section).
	if ( $website ) {
		$rows[] = array(
			'type'     => 'website',
			'url'      => $website,
			'label'    => __( 'Website', 'conexao-br-irlanda' ),
			'external' => true,
		);
		$seen[ conexao_contact_dedupe_key( $website ) ] = true;
	}

	foreach ( $stored as $row ) {
		$type = isset( $row['type'] ) ? sanitize_key( $row['type'] ) : '';
		$url  = isset( $row['url'] ) ? trim( (string) $row['url'] ) : '';

		if ( '' === $type || '' === $url ) {
			continue;
		}

		$key = conexao_contact_dedupe_key( $url );
		if ( isset( $seen[ $key ] ) ) {
			continue;
		}
		$seen[ $key ] = true;

		$rows[] = array(
			'type'     => $type,
			'url'      => $url,
			'label'    => conexao_contact_label( $type, $url ),
			// mailto: links stay in the same tab; everything else leaves
			// the site and is flagged for target="_blank" treatment.
			'external' => 0 !== stripos( $url, 'mailto:' ),
		);
	}

	return $rows;
}

/**
 * Human-readable label for a contact row on the frontend.
 *
 * Platform names come from the data model's canonical type labels when the
 * plugin is active (with a static fallback so the theme degrades gracefully
 * on its own). An "Outro" row has no meaningful platform name, so the
 * destination hostname is shown instead — informative, yet never a raw URL.
 *
 * @param string $type Contact type slug.
 * @param string $url  Stored contact value (http(s)/mailto).
 * @return string
 */
function conexao_contact_label( $type, $url ) {
	if ( class_exists( 'Conexao_Data_Model_Contacts' ) ) {
		$label = Conexao_Data_Model_Contacts::type_label( $type );
	} else {
		$labels = array(
			'website'   => 'Website',
			'instagram' => 'Instagram',
			'facebook'  => 'Facebook',
			'whatsapp'  => 'WhatsApp',
			'linkedin'  => 'LinkedIn',
			'tiktok'    => 'TikTok',
			'email'     => 'E-mail',
			'outro'     => 'Outro',
		);
		$label = isset( $labels[ $type ] ) ? $labels[ $type ] : 'Outro';
	}

	if ( 'outro' === $type ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( $host ) {
			$label = preg_replace( '/^www\./i', '', $host );
		}
	}

	return $label;
}

/**
 * Scheme/host-normalized key used to avoid rendering the same contact
 * URL twice (e.g. `_sponsor_link` vs an identical Website repeater row).
 *
 * @param string $url Contact URL.
 * @return string
 */
function conexao_contact_dedupe_key( $url ) {
	$key = strtolower( trim( (string) $url ) );
	$key = preg_replace( '#^[a-z][a-z0-9+.\-]*://#i', '', $key );
	$key = preg_replace( '#^www\.#i', '', $key );

	return untrailingslashit( $key );
}

/**
 * Inline SVG icon for a contact type.
 *
 * Reuses the exact brand icon paths already used across the theme
 * (footer/header social links, share buttons) — no new icon library.
 * Brand marks (Instagram/Facebook/WhatsApp/LinkedIn/TikTok) are
 * fill-based; utility glyphs (globe/envelope/link) follow the theme's
 * stroke-based icon style. Output is static, escaped-safe markup.
 *
 * @param string $type Contact type slug.
 * @return string SVG markup (aria-hidden).
 */
function conexao_contact_icon( $type ) {
	switch ( $type ) {
		case 'instagram':
			return '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>';
		case 'facebook':
			return '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>';
		case 'whatsapp':
			return '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>';
		case 'linkedin':
			return '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>';
		case 'tiktok':
			return '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg>';
		case 'email':
			return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>';
		case 'outro':
			return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>';
		case 'website':
		default:
			return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>';
	}
}

/**
 * Customizer settings
 */
