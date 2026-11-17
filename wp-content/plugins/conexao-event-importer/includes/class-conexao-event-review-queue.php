<?php
/**
 * Read-only "Needs review" queue for the Event Sources area (Stage 4).
 *
 * ## What this is
 *
 * A read-only admin view that surfaces published event posts missing defined
 * critical fields, so an operator can see at a glance which events need
 * attention. It is a VISIBILITY feature, not a data-repair tool: it never
 * changes a post, postmeta, taxonomy assignment, status or source
 * configuration.
 *
 * ## Population
 *
 * The queue is "published event posts at query time": `post_type = event`,
 * `post_status = publish`, excluding revisions, attachments, trash and
 * auto-drafts (WordPress's `WP_Query` with `post_type => event` and
 * `post_status => publish` already excludes those by construction). The
 * `_event_status` meta gate is NOT applied here: the queue is an editorial
 * completeness view over published posts, not the public visibility gate, so
 * a post hidden via `_event_status` is still shown (its completeness is what
 * is being reviewed). This is documented in the evidence file.
 *
 * ## Fixture handling
 *
 * There is no reliable, explicit fixture marker on event posts. The CI
 * fixture seeder writes a `_event_export_uuid` of the form
 * `ci-fixture-0000-0000-4000-8000-00000000000N` on only two records, and the
 * remaining fixture events carry no marker at all. Per the task rules, fixture
 * identity must NOT be inferred from title, date, source name or post ID
 * alone. The queue therefore includes all published event posts and discloses
 * that counts may include CI fixture events (no reliable fixture marker is
 * available). This is the honest choice: a partial marker would silently
 * exclude real events.
 *
 * ## Critical fields (first version)
 *
 * - `_thumbnail_id` — featured image missing (no valid assigned thumbnail).
 * - `_event_venue`  — venue missing/blank.
 * - `conexao_county` — no assigned term in the taxonomy.
 *
 * `conexao_category` is intentionally OMITTED while P8 is deferred (no
 * approved vocabulary): a visible "N events with no category" row would be
 * misread as an actionable queue when it cannot be resolved until the
 * vocabulary is approved. A single note states that category review is
 * pending vocabulary approval.
 *
 * ## Taxonomy query semantics
 *
 * - `conexao_county` is hierarchical. "Assigned" means the post has at least
 *   one term in the `conexao_county` taxonomy (parent OR child). A post with
 *   only a child term is considered to "have a county" (the child implies its
 *   parent). Placeholder/unknown terms, if any exist, count as assigned
 *   (documented decision: the queue measures presence, not correctness).
 * - Multiple terms: a post with more than one county term is "has a term"
 *   (satisfied), not "multiple" — the queue measures presence.
 * - Deleted terms: WordPress removes the term relationship when a term is
 *   deleted, so an orphaned relationship cannot exist; a post whose only term
 *   was deleted correctly shows as "missing".
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read-only needs-review queue for published events.
 */
class Conexao_Event_Review_Queue {

	const EVENT_POST_TYPE = 'event';
	const COUNTY_TAXONOMY = 'conexao_county';
	const PER_PAGE        = 20;

	/**
	 * The critical fields surfaced by the first version of the queue.
	 *
	 * `conexao_category` is deliberately absent while P8 is deferred.
	 *
	 * @return array<string,array{label:string,kind:string}>
	 */
	public static function fields() {
		return array(
			'thumbnail' => array(
				'label' => __( 'Featured image missing', 'conexao-event-importer' ),
				'kind'  => 'meta',
			),
			'venue'     => array(
				'label' => __( 'Venue missing', 'conexao-event-importer' ),
				'kind'  => 'meta',
			),
			'county'    => array(
				'label' => __( 'County not assigned', 'conexao-event-importer' ),
				'kind'  => 'taxonomy',
			),
		);
	}

	/**
	 * Whether a post ID is in the review population (published event post).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_in_population( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		return self::EVENT_POST_TYPE === $post->post_type && 'publish' === $post->post_status;
	}

	/**
	 * Whether a post has a valid assigned featured image.
	 *
	 * A "valid assigned thumbnail" means the `_thumbnail_id` meta is set to a
	 * non-empty integer that references an existing `attachment` post which is
	 * not in trash. This follows the site's existing convention (the
	 * `_thumbnail_id` meta is the standard WordPress featured-image key).
	 *
	 * @param int $post_id Post ID.
	 * @return bool True when a valid featured image is assigned.
	 */
	public static function has_valid_thumbnail( $post_id ) {
		$thumb_id = (int) get_post_meta( (int) $post_id, '_thumbnail_id', true );
		if ( $thumb_id <= 0 ) {
			return false;
		}
		$attachment = get_post( $thumb_id );
		if ( ! $attachment instanceof WP_Post ) {
			return false;
		}
		if ( 'attachment' !== $attachment->post_type ) {
			return false;
		}
		if ( 'trash' === $attachment->post_status ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether a post has a non-blank `_event_venue`.
	 *
	 * "Blank" means the meta key is missing, an empty string, or whitespace
	 * only. A venue of `"0"` is treated as present (it is a non-empty string;
	 * the venue contract stores names, never `"0"`, but the check is
	 * string-based and does not special-case it).
	 *
	 * @param int $post_id Post ID.
	 * @return bool True when a non-blank venue is stored.
	 */
	public static function has_venue( $post_id ) {
		$venue = (string) get_post_meta( (int) $post_id, '_event_venue', true );
		return '' !== trim( $venue );
	}

	/**
	 * Whether a post has at least one term in the county taxonomy.
	 *
	 * A post with any `conexao_county` term (parent or child, single or
	 * multiple) is considered to "have a county". See the class docblock for
	 * the full taxonomy semantics.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True when at least one county term is assigned.
	 */
	public static function has_county( $post_id ) {
		$terms = wp_get_object_terms( (int) $post_id, self::COUNTY_TAXONOMY, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) ) {
			return false;
		}
		return ! empty( $terms );
	}

	/**
	 * Whether a post is missing a given field.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   Field key (thumbnail|venue|county).
	 * @return bool True when the field is missing.
	 */
	public static function is_missing( $post_id, $field ) {
		switch ( $field ) {
			case 'thumbnail':
				return ! self::has_valid_thumbnail( $post_id );
			case 'venue':
				return ! self::has_venue( $post_id );
			case 'county':
				return ! self::has_county( $post_id );
		}
		return false;
	}

	/**
	 * Query the published event post IDs in the review population.
	 *
	 * Returns post IDs ordered by post_date DESC, then ID DESC, for a bounded
	 * page. The caller applies the per-field filter in PHP over this
	 * population so the aggregate count and the drill-in list always share
	 * the exact same population (no divergent query paths).
	 *
	 * @param int $page    1-based page number.
	 * @param int $per_page Page size.
	 * @return array{ids:int[],total:int}
	 */
	public static function query_population( $page, $per_page ) {
		$page     = max( 1, (int) $page );
		$per_page = max( 1, (int) $per_page );

		$query = new WP_Query(
			array(
				'post_type'           => self::EVENT_POST_TYPE,
				'post_status'         => 'publish',
				'posts_per_page'      => $per_page,
				'paged'               => $page,
				'orderby'             => 'date ID',
				'order'               => 'DESC',
				'fields'              => 'ids',
				'no_found_rows'       => false,
				'ignore_sticky_posts' => true,
			)
		);

		return array(
			'ids'   => array_map( 'intval', $query->posts ),
			'total' => (int) $query->found_posts,
		);
	}

	/**
	 * Filter a list of post IDs to those missing a given field.
	 *
	 * @param int[]  $post_ids Post IDs.
	 * @param string $field    Field key.
	 * @return int[] Post IDs missing the field.
	 */
	public static function filter_missing( $post_ids, $field ) {
		$out = array();
		foreach ( (array) $post_ids as $post_id ) {
			if ( self::is_missing( (int) $post_id, $field ) ) {
				$out[] = (int) $post_id;
			}
		}
		return $out;
	}

	/**
	 * Compute per-field counts over the whole population.
	 *
	 * Each field is counted independently over the full published-event
	 * population; counts for different fields MAY overlap and are not
	 * additive. A single set of batched SQL queries keeps the admin page
	 * bounded regardless of population size:
	 *
	 * - `_event_venue` blank: posts whose `_event_venue` meta is missing or
	 *   whitespace-only (a LEFT JOIN + TRIM check).
	 * - `conexao_county` missing: posts with no row in `wp_term_relationships`
	 *   for the county taxonomy (a LEFT JOIN + NULL check).
	 * - `_thumbnail_id` missing: posts whose `_thumbnail_id` is missing, not a
	 *   positive integer, or references a non-existent / trashed / non-attachment
	 *   post (a LEFT JOIN against `wp_posts`).
	 *
	 * @return array<string,int> Field key → count.
	 */
	public static function field_counts() {
		global $wpdb;

		$counts = array(
			'thumbnail' => 0,
			'venue'     => 0,
			'county'    => 0,
		);

		/*
		 * The count queries must share the EXACT same population as
		 * query_population() (WP_Query). The event runtime's pre_get_posts
		 * hook and Polylang's language filter narrow every WP_Query to
		 * `_event_status = published` + the current language. Direct SQL that
		 * does not replicate those filters diverges from the drill-in list
		 * (D2 defect). Rather than duplicating every filter in SQL, the
		 * population is resolved once through the same WP_Query path and the
		 * per-field counts are computed over that exact set of IDs.
		 *
		 * A single WP_Query with posts_per_page => -1 returns the full
		 * population in one query, avoiding the per-page overhead of
		 * iterating query_population() page by page.
		 */
		$population_query = new WP_Query(
			array(
				'post_type'           => self::EVENT_POST_TYPE,
				'post_status'         => 'publish',
				'posts_per_page'      => -1,
				'fields'              => 'ids',
				'no_found_rows'       => false,
				'ignore_sticky_posts' => true,
				'orderby'             => 'date ID',
				'order'               => 'DESC',
			)
		);

		$ids = array_map( 'intval', $population_query->posts );

		if ( empty( $ids ) ) {
			return $counts;
		}

		// Count per-field over the resolved population using a single
		// batched SQL query per field (bounded by the population size).
		$id_placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders from count($ids), safe.
		$counts['venue'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} v ON ( p.ID = v.post_id AND v.meta_key = '_event_venue' )
				 WHERE p.ID IN ( {$id_placeholders} )
				   AND ( v.meta_value IS NULL OR TRIM(v.meta_value) = '' )",
				$ids
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders from count($ids), safe.
		$counts['county'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->term_relationships} tr ON ( p.ID = tr.object_id )
				 LEFT JOIN {$wpdb->term_taxonomy} tt ON ( tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = %s )
				 WHERE p.ID IN ( {$id_placeholders} )
				   AND tt.term_taxonomy_id IS NULL",
				array_merge( array( self::COUNTY_TAXONOMY ), $ids )
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders from count($ids), safe.
		$counts['thumbnail'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} t ON ( p.ID = t.post_id AND t.meta_key = '_thumbnail_id' )
				 LEFT JOIN {$wpdb->posts} a ON ( a.ID = CAST(t.meta_value AS UNSIGNED) AND a.post_type = 'attachment' AND a.post_status <> 'trash' )
				 WHERE p.ID IN ( {$id_placeholders} )
				   AND ( t.meta_value IS NULL OR t.meta_value = '' OR t.meta_value = '0' OR a.ID IS NULL )",
				$ids
			)
		);

		return $counts;
	}
}
