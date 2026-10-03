<?php
/**
 * Public event query/candidate helper.
 *
 * Step 3 of the recurring-events design: makes recurring events participate
 * in the existing event surfaces (the /eventos/ archive, the homepage hero
 * widget, the homepage "Próximos Eventos" section, the 404 page and the
 * landing-page events section) WITHOUT creating occurrence posts and without
 * expressing weekday recurrence through WP_Date_Query.
 *
 * Approved strategy:
 *   1. SQL candidate widening  — a single lightweight query that over-selects
 *      candidates: every one-time event dated today or later, plus every
 *      weekly series that could have an occurrence in the upcoming 7-day
 *      window (series started on/before the window end, not ended before
 *      today). Y-m-d strings compare correctly as plain strings, so no MySQL
 *      date functions are needed.
 *   2. Batch meta loading      — update_meta_cache() loads all postmeta for
 *      the candidates in one query; the recurrence evaluator then runs
 *      entirely from the cache (no per-event queries).
 *   3. PHP exact evaluation    — Conexao_Event_Recurrence::next_occurrence()
 *      decides, per candidate, whether a real occurrence exists in the
 *      window and when the next one falls.
 *   4. Ordered ID list         — sorted by next occurrence (ascending, ties
 *      by post ID). Surfaces consume it via post__in + orderby => post__in,
 *      so pagination, taxonomy filters and the _event_status gate keep
 *      operating on real event posts.
 *
 * The ordered list is cached in a transient keyed by the site-local calendar
 * date (no cron, no scheduler): results are stable for the whole local day
 * and the day's rollover naturally rebuilds the set. Event saves flush the
 * current cache window via flush_cache().
 *
 * Weekly semantics: a recurring series enters the result set when it has an
 * occurrence on/after the current local date within the 7-day evaluation
 * window. Because a weekly series repeats at most every 7 days, any ACTIVE
 * series is always caught by the window; a series whose range has ended, or
 * whose start date is still more than 7 days away, is correctly absent today.
 *
 * @package Conexao_Event_Runtime
 */

defined( 'ABSPATH' ) || exit;

final class Conexao_Event_Query {

	const CACHE_KEY_PREFIX   = 'conexao_event_upcoming_';
	const WEEKLY_WINDOW_DAYS = 7;

	/**
	 * Site-local calendar date for "today" (midnight, wp_timezone()).
	 *
	 * @return DateTimeImmutable
	 */
	public static function today() {
		return new DateTimeImmutable( 'today', wp_timezone() );
	}

	/**
	 * Ordered map of currently active/upcoming public event IDs.
	 *
	 * Key = event post ID, value = the next occurrence date (Y-m-d, site
	 * timezone). Ordered by next occurrence ascending (ties by post ID).
	 *
	 * @param DateTimeImmutable|null $from Optional evaluation start. When
	 *                                     provided, the result is NOT
	 *                                     transient-cached (used by tests).
	 * @return array<int,string>
	 */
	public static function upcoming_events( $from = null ) {
		$today = ( null === $from ) ? self::today() : $from->setTimezone( wp_timezone() )->setTime( 0, 0, 0 );

		$cacheable = ( null === $from );
		if ( $cacheable ) {
			$cached = get_transient( self::cache_key( $today ) );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$candidate_ids = self::candidate_event_ids( $today );

		// One batched meta load: the recurrence evaluator reads every
		// _event_* field through get_post_meta(), which now hits the cache.
		if ( ! empty( $candidate_ids ) ) {
			update_meta_cache( 'post', $candidate_ids );
		}

		$events = array();
		foreach ( $candidate_ids as $candidate_id ) {
			// Stage 2: a translated event is its own post record that carries a
			// copy of the identity/scheduling meta, so the language of the
			// record must be respected — otherwise an English translation would
			// surface in Portuguese listings (and vice versa). Records without
			// a language (Polylang inactive, or legacy rows) stay visible.
			if ( ! self::is_in_current_language( (int) $candidate_id ) ) {
				continue;
			}

			$next = Conexao_Event_Recurrence::next_occurrence( (int) $candidate_id, $today );
			if ( null !== $next ) {
				$events[ (int) $candidate_id ] = $next->format( 'Y-m-d' );
			}
		}

		// ksort gives deterministic ID order, then the stable uasort orders
		// by next occurrence while keeping ID order for same-day ties.
		ksort( $events );
		uasort( $events, 'strcmp' );

		if ( $cacheable ) {
			set_transient(
				self::cache_key( $today ),
				$events,
				max( 60, min( DAY_IN_SECONDS, self::seconds_until_local_midnight() ) )
			);
		}

		return $events;
	}

	/**
	 * Ordered list of currently active/upcoming public event IDs.
	 *
	 * @param DateTimeImmutable|null $from Optional evaluation start (no cache).
	 * @return int[]
	 */
	public static function upcoming_event_ids( $from = null ) {
		return array_map( 'intval', array_keys( self::upcoming_events( $from ) ) );
	}

	/**
	 * Next occurrence date for an event within the current window.
	 *
	 * Returns the event's next occurrence (Y-m-d, site timezone) when the
	 * event is part of the current active/upcoming set, or null otherwise.
	 * For one-time events this is simply their stored _event_date.
	 *
	 * @param int $post_id Event post ID.
	 * @return string|null
	 */
	public static function next_occurrence_date( $post_id ) {
		$events = self::upcoming_events();
		if ( isset( $events[ (int) $post_id ] ) ) {
			return $events[ (int) $post_id ];
		}
		return null;
	}

	/**
	 * Flush the date- and language-keyed cache around today (previous day,
	 * today and next day, to cover local-midnight boundary saves). Old keys
	 * expire by themselves at the end of their own calendar day; there is no
	 * cron.
	 *
	 * Stage 2: every language variant is flushed, so saving a Portuguese event
	 * also invalidates the English list (and vice versa).
	 *
	 * @return void
	 */
	public static function flush_cache() {
		$today = self::today();
		foreach ( array( -1, 0, 1 ) as $offset ) {
			$day = $today->modify( ( $offset >= 0 ? '+' : '' ) . $offset . ' day' );

			delete_transient( self::cache_key( $day, '' ) );

			foreach ( self::language_slugs() as $slug ) {
				delete_transient( self::cache_key( $day, $slug ) );
			}
		}
	}

	/**
	 * Current Polylang language slug, or '' when Polylang is inactive.
	 *
	 * Kept local to the runtime so the query helper has no hard dependency on
	 * Polylang — the plugin stays functional (single-language) without it.
	 *
	 * @return string
	 */
	private static function language_slug(): string {
		if ( ! function_exists( 'pll_current_language' ) ) {
			return '';
		}

		$slug = pll_current_language( 'slug' );

		return is_string( $slug ) ? $slug : '';
	}

	/**
	 * All language slugs known to Polylang (used to flush every cache variant).
	 *
	 * @return string[]
	 */
	private static function language_slugs(): array {
		if ( ! function_exists( 'pll_languages_list' ) ) {
			return array();
		}

		$slugs = pll_languages_list( array( 'fields' => 'slug' ) );

		return is_array( $slugs ) ? array_map( 'strval', $slugs ) : array();
	}

	/**
	 * Is this event record part of the current language context?
	 *
	 * STAGE 3.1 — B2 fallback: on EN requests, Portuguese event records with
	 * no EN translation are part of the EN archive (rendered with EN chrome
	 * + notice). Records that DO have an EN translation stay out of the PT
	 * archive's way: only the record matching the current language is kept,
	 * so no event ever appears twice.
	 *
	 * @param int $post_id Event post ID.
	 * @return bool
	 */
	private static function is_in_current_language( int $post_id ): bool {
		$current = self::language_slug();

		if ( '' === $current || ! function_exists( 'pll_get_post_language' ) ) {
			return true;
		}

		$language = pll_get_post_language( $post_id, 'slug' );

		if ( ! is_string( $language ) || '' === $language ) {
			return true;
		}

		if ( $language === $current ) {
			return true;
		}

		// B2 fallback: a PT record with no EN translation belongs to the EN
		// archive. Hidden statuses never surface (the status gate already
		// filtered them from the candidate set, but re-check defensively).
		if ( 'en' === $current && 'pt' === $language && function_exists( 'pll_get_post' ) ) {
			$translated = (int) pll_get_post( $post_id, 'en' );
			if ( 0 === $translated || $translated === $post_id ) {
				$status = get_post_meta( $post_id, '_event_status', true );
				if ( '' === $status || 'published' === $status ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Transient cache key for a local calendar date and language.
	 *
	 * @param DateTimeImmutable $day      Site-local day.
	 * @param string|null       $language Language slug; null = current context.
	 * @return string
	 */
	private static function cache_key( $day, $language = null ) {
		$slug = ( null === $language ) ? self::language_slug() : (string) $language;

		return self::CACHE_KEY_PREFIX . $day->format( 'Ymd' ) . ( '' !== $slug ? '_' . $slug : '' );
	}

	/**
	 * Seconds from now until the next local midnight (cache lifetime).
	 *
	 * @return int
	 */
	private static function seconds_until_local_midnight() {
		$now      = new DateTimeImmutable( 'now', wp_timezone() );
		$midnight = self::today()->modify( '+1 day' );
		return $midnight->getTimestamp() - $now->getTimestamp();
	}

	/**
	 * SQL candidate widening.
	 *
	 * Over-selects in one lightweight query (no per-event recurrence work):
	 *
	 *  - One-time events: `_event_date` on/after the current local date, OR
	 *    `_event_end_date` on/after the current local date (catches multi-day
	 *    events already in progress — started before today but not yet ended).
	 *    Events whose end date is already before today stay excluded.
	 *  - Weekly events: `_event_recurrence = 'weekly'` where the series can
	 *    still occur in the local [today, today+7] window — i.e. not ended
	 *    before today (blank/missing end = open-ended) and started no later
	 *    than the window end (blank/missing start falls back to
	 *    `_event_date` inside the evaluator, so it must be a candidate).
	 *
	 * The public `_event_status` gate (published OR legacy no-status row) is
	 * replicated here, compatible with the runtime's pre_get_posts
	 * meta_query: a `NOT EXISTS` clause does not match an existing empty
	 * meta row, so only a NULL row (no status ever saved) or an explicit
	 * 'published' passes. Exact relevance filtering happens in PHP.
	 *
	 * @param DateTimeImmutable $today Site-local midnight.
	 * @return int[] Candidate event post IDs (unordered, deduplicated).
	 */
	private static function candidate_event_ids( $today ) {
		global $wpdb;

		$today_str  = $today->format( 'Y-m-d' );
		$window_end = $today->modify( '+' . self::WEEKLY_WINDOW_DAYS . ' days' )->format( 'Y-m-d' );

		$sql = "
			SELECT DISTINCT p.ID
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} ed ON ( p.ID = ed.post_id AND ed.meta_key = '_event_date' )
			LEFT JOIN {$wpdb->postmeta} ee ON ( p.ID = ee.post_id AND ee.meta_key = '_event_end_date' )
			LEFT JOIN {$wpdb->postmeta} st ON ( p.ID = st.post_id AND st.meta_key = '_event_status' )
			LEFT JOIN {$wpdb->postmeta} rc ON ( p.ID = rc.post_id AND rc.meta_key = '_event_recurrence' )
			LEFT JOIN {$wpdb->postmeta} rs ON ( p.ID = rs.post_id AND rs.meta_key = '_event_recurrence_start' )
			LEFT JOIN {$wpdb->postmeta} re ON ( p.ID = re.post_id AND re.meta_key = '_event_recurrence_end' )
			WHERE p.post_type = 'event'
			  AND p.post_status = 'publish'
			  AND ( st.meta_value IS NULL OR st.meta_value = 'published' )
			  AND (
			      (
			          rc.meta_value = 'weekly'
			          AND ( re.meta_value IS NULL OR re.meta_value = '' OR re.meta_value >= %s )
			          AND ( rs.meta_value IS NULL OR rs.meta_value = '' OR rs.meta_value <= %s )
			      )
			      OR
			      (
			          ( rc.meta_value IS NULL OR rc.meta_value <> 'weekly' )
			          AND (
			              ed.meta_value >= %s
			              OR ee.meta_value >= %s
			          )
			      )
			  )
		";

		$rows = $wpdb->get_col( $wpdb->prepare( $sql, $window_end, $window_end, $today_str, $today_str ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return array_map( 'intval', (array) $rows );
	}
}
