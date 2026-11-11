<?php
/**
 * Commemorative landing-page content modules (theme-owned).
 *
 * R10 §7.5/§7.13: a landing page is composed from reusable modules that REUSE
 * existing records — events, guides, places and articles — instead of duplicating
 * them into new posts. This file contains only the small query/reshape helpers;
 * the markup lives in `template-parts/commemorative/modules/`.
 *
 * Rules honoured here:
 *   - No date arithmetic. Upcoming events come from the shared event engine
 *     (`Conexao_Event_Query`), never from a second implementation.
 *   - Portable identifiers only: modules address records by SLUG (and resolve the
 *     current language through Polylang), never by local post ID.
 *   - Bilingual: a record with no translation falls back to the canonical PT
 *     record (the documented B2 policy), never to an empty module.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve one published record by post type + slug, in the current language.
 *
 * @param string $post_type Post type.
 * @param string $slug      Canonical (default-language) slug.
 * @return WP_Post|null
 */
function conexao_commemorative_resolve_record( $post_type, $slug ) {
	$slug = (string) $slug;

	if ( '' === $slug || ! post_type_exists( (string) $post_type ) ) {
		return null;
	}

	$found = get_posts(
		array(
			'post_type'        => (string) $post_type,
			'post_status'      => 'publish',
			'name'             => $slug,
			'numberposts'      => 1,
			'suppress_filters' => true,
		)
	);

	if ( empty( $found ) ) {
		return null;
	}

	$post_id = (int) $found[0]->ID;

	if ( function_exists( 'pll_get_post' ) && function_exists( 'pll_current_language' ) ) {
		$translated = (int) pll_get_post( $post_id, (string) pll_current_language( 'slug' ) );

		if ( $translated > 0 ) {
			$candidate = get_post( $translated );

			if ( $candidate instanceof WP_Post && 'publish' === $candidate->post_status ) {
				$post_id = $translated;
			}
		}
	}

	$post = get_post( $post_id );

	return $post instanceof WP_Post ? $post : null;
}

/**
 * A normalised module item from one record.
 *
 * @param WP_Post $post Record.
 * @param string  $type Short type label.
 * @param string  $meta Optional meta line.
 * @return array
 */
function conexao_commemorative_record_item( WP_Post $post, $type, $meta = '' ) {
	$excerpt = has_excerpt( $post )
		? get_the_excerpt( $post )
		: wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), 26, '…' );

	return array(
		'id'      => (int) $post->ID,
		'title'   => get_the_title( $post ),
		'url'     => (string) get_permalink( $post ),
		'summary' => (string) $excerpt,
		'type'    => (string) $type,
		'meta'    => (string) $meta,
	);
}

/**
 * Build a module from a list of slug/type pairs.
 *
 * @param array $args {
 *     The module definition.
 *
 *     @type string $id      Module id (used for the heading anchor).
 *     @type string $title   Module heading, always present.
 *     @type string $intro   Optional intro sentence.
 *     @type array  $records List of arrays: {type, slug, label?, meta?}.
 * }
 * @return array
 */
function conexao_commemorative_module_from_records( array $args ) {
	$items   = array();
	$records = isset( $args['records'] ) && is_array( $args['records'] ) ? $args['records'] : array();

	foreach ( $records as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}

		$type = isset( $entry['type'] ) ? (string) $entry['type'] : 'post';
		$slug = isset( $entry['slug'] ) ? (string) $entry['slug'] : '';
		$post = conexao_commemorative_resolve_record( $type, $slug );

		if ( ! $post ) {
			continue;
		}

		$items[] = conexao_commemorative_record_item(
			$post,
			isset( $entry['label'] ) ? (string) $entry['label'] : $type,
			isset( $entry['meta'] ) ? (string) $entry['meta'] : ''
		);
	}

	return array(
		'id'    => isset( $args['id'] ) ? (string) $args['id'] : '',
		'title' => isset( $args['title'] ) ? (string) $args['title'] : '',
		'intro' => isset( $args['intro'] ) ? (string) $args['intro'] : '',
		'items' => $items,
	);
}

/**
 * Build the "upcoming events" module from the shared event engine.
 *
 * @param array $args {
 *     The module definition.
 *
 *     @type string   $id       Module id.
 *     @type string   $title    Module heading, always present.
 *     @type string   $intro    Optional intro.
 *     @type string   $label    Optional type label for each item; the caller supplies a
 *                              localised value so a package is never forced to render the
 *                              default label in the wrong language.
 *     @type string[] $keywords Case-insensitive title keywords used as an
 *                              EDITORIAL filter (never a date rule).
 *     @type int      $limit    Maximum items.
 * }
 * @return array
 */
function conexao_commemorative_module_upcoming_events( array $args ) {
	$empty = array(
		'id'    => isset( $args['id'] ) ? (string) $args['id'] : '',
		'title' => isset( $args['title'] ) ? (string) $args['title'] : '',
		'intro' => isset( $args['intro'] ) ? (string) $args['intro'] : '',
		'items' => array(),
	);

	if ( ! class_exists( 'Conexao_Event_Query' ) ) {
		return $empty;
	}

	$items    = array();
	$limit    = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 6;
	$keywords = isset( $args['keywords'] ) && is_array( $args['keywords'] ) ? $args['keywords'] : array();
	$label    = isset( $args['label'] ) && '' !== (string) $args['label'] ? (string) $args['label'] : __( 'Evento', 'conexao-br-irlanda' );
	$upcoming = Conexao_Event_Query::upcoming_events();

	foreach ( $upcoming as $event_id => $date ) {
		$post = get_post( (int) $event_id );

		if ( ! $post instanceof WP_Post ) {
			continue;
		}

		if ( $keywords ) {
			$haystack = strtolower( (string) $post->post_title );
			$match    = false;

			foreach ( $keywords as $keyword ) {
				$keyword = strtolower( (string) $keyword );

				if ( '' !== $keyword && false !== strpos( $haystack, $keyword ) ) {
					$match = true;
					break;
				}
			}

			if ( ! $match ) {
				continue;
			}
		}

		$items[] = conexao_commemorative_record_item(
			$post,
			$label,
			(string) $date
		);

		if ( count( $items ) >= $limit ) {
			break;
		}
	}

	return array(
		'id'    => $empty['id'],
		'title' => $empty['title'],
		'intro' => $empty['intro'],
		'items' => $items,
	);
}
