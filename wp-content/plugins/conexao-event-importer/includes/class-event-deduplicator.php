<?php
/**
 * Event duplicate detection.
 *
 * Prevents the same event from being imported multiple times by comparing
 * title, date, time, town, venue, organizer, and source event ID.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Event_Deduplicator {

	/**
	 * Find an existing event that matches the normalized event data.
	 *
	 * @param array $normalized Normalized event data (see Conexao_Event_Normalizer).
	 * @return int Post ID of the matching event, or 0 if none found.
	 */
	public function find( $normalized ) {
		// 1. Strongest match: source event ID.
		if ( ! empty( $normalized['source_id'] ) && ! empty( $normalized['source'] ) ) {
			$by_source = $this->find_by_source( $normalized['source'], $normalized['source_id'] );
			if ( $by_source ) {
				return $by_source;
			}
		}

		// 2. Source URL match.
		if ( ! empty( $normalized['source_url'] ) ) {
			$by_url = $this->find_by_url( $normalized['source_url'] );
			if ( $by_url ) {
				return $by_url;
			}
		}

		// 3. Content-based match: title + date + time + town + venue + organizer.
		return $this->find_by_content( $normalized );
	}

	/**
	 * Find an existing event by its source ID.
	 *
	 * @param string $source    Source slug.
	 * @param string $source_id Source event ID.
	 * @return int Post ID or 0.
	 */
	protected function find_by_source( $source, $source_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'event',
				'posts_per_page' => 10,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_event_source',
						'value' => $source,
					),
					array(
						'key'   => '_event_source_id',
						'value' => $source_id,
					),
				),
			)
		);

		return $this->pick_import_language( $query->posts );
	}

	/**
	 * Find an event by its original source URL.
	 *
	 * @param string $url Source URL.
	 * @return int Post ID or 0.
	 */
	protected function find_by_url( $url ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'event',
				'posts_per_page' => 10,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'   => '_event_url',
						'value' => $url,
					),
					array(
						'key'   => '_event_source_url',
						'value' => $url,
					),
				),
			)
		);

		return $this->pick_import_language( $query->posts );
	}

	/**
	 * Pick the record that belongs to the importer's language.
	 *
	 * A Polylang translation carries the same identity meta as its Portuguese
	 * master, so identity matching alone can return two records. The import
	 * target is always the record in the import language (or a record without
	 * a language, e.g. a legacy row). A record in another language is only
	 * used as a last resort, so the caller can detect and report the conflict
	 * instead of silently creating a duplicate identity.
	 *
	 * @param int[] $ids Candidate post IDs (query order).
	 * @return int Post ID or 0.
	 */
	protected function pick_import_language( array $ids ) {
		$ids = array_map( 'intval', $ids );

		if ( empty( $ids ) ) {
			return 0;
		}

		$language = class_exists( 'Conexao_Event_Importer_Language_Guard' )
			? Conexao_Event_Importer_Language_Guard::import_language()
			: '';

		if ( '' === $language || ! function_exists( 'pll_get_post_language' ) ) {
			return (int) reset( $ids );
		}

		$foreign = 0;

		foreach ( $ids as $id ) {
			if ( $this->is_import_language( $id ) ) {
				return $id;
			}

			if ( ! $foreign ) {
				$foreign = $id;
			}
		}

		return $foreign;
	}

	/**
	 * Is this candidate a valid import target language-wise?
	 *
	 * @param int $post_id Candidate post ID.
	 * @return bool True for the importer's language and for unassigned rows.
	 */
	protected function is_import_language( $post_id ) {
		$language = class_exists( 'Conexao_Event_Importer_Language_Guard' )
			? Conexao_Event_Importer_Language_Guard::import_language()
			: '';

		if ( '' === $language || ! function_exists( 'pll_get_post_language' ) ) {
			return true;
		}

		$post_language = pll_get_post_language( (int) $post_id, 'slug' );

		return ! is_string( $post_language ) || '' === $post_language || $post_language === $language;
	}

	/**
	 * Find an event by its content (title + date + time + town + venue + organizer).
	 *
	 * @param array $normalized Normalized event data.
	 * @return int Post ID or 0.
	 */
	protected function find_by_content( $normalized ) {
		if ( empty( $normalized['title'] ) || empty( $normalized['start_date'] ) ) {
			return 0;
		}

		$title = sanitize_title( $normalized['title'] );

		$query = new WP_Query(
			array(
				'post_type'      => 'event',
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				's'              => $normalized['title'],
			)
		);

		$fallback = 0;

		foreach ( $query->posts as $post_id ) {
			$existing_title = sanitize_title( get_the_title( $post_id ) );
			if ( $existing_title !== $title ) {
				continue;
			}

			$date = get_post_meta( $post_id, '_event_date', true );
			if ( $date !== $normalized['start_date'] ) {
				continue;
			}

			$time = get_post_meta( $post_id, '_event_start_time', true );
			if ( empty( $time ) ) {
				$time = get_post_meta( $post_id, '_event_time', true );
				// Legacy _event_time may be a range; take the first part.
				$time = trim( explode( '—', $time )[0] );
			}
			if ( ! empty( $normalized['start_time'] ) && ! empty( $time ) && $time !== $normalized['start_time'] ) {
				continue;
			}

			$venue = get_post_meta( $post_id, '_event_venue', true );
			if ( empty( $venue ) ) {
				$venue = get_post_meta( $post_id, '_event_location', true );
			}
			if ( ! empty( $normalized['venue'] ) && ! empty( $venue ) && strtolower( $venue ) !== strtolower( $normalized['venue'] ) ) {
				continue;
			}

			$organizer = get_post_meta( $post_id, '_event_organizer', true );
			if ( ! empty( $normalized['organizer'] ) && ! empty( $organizer ) && strtolower( $organizer ) !== strtolower( $normalized['organizer'] ) ) {
				continue;
			}

			// Language preference: a translation of the same source content is
			// not the import target — remember it only as a last resort.
			if ( $this->is_import_language( (int) $post_id ) ) {
				return (int) $post_id;
			}

			if ( ! $fallback ) {
				$fallback = (int) $post_id;
			}
		}

		return $fallback;
	}
}