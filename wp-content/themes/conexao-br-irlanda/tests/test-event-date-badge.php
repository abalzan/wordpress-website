<?php
/**
 * Event-card date badge simplification (audit T1.9) regression suite.
 *
 * Pins the UI/UX improvement 1 contract implemented on 2026-10-19:
 *
 *  A. The date BADGE carries ONLY the day number and the localized
 *     abbreviated month. The weekday, the "Hoje"/"Amanhã" hint, the
 *     recurrence label, the cross-month range chip and the series-end chip
 *     were all removed from the badge (they stacked four typographic
 *     treatments for one date).
 *
 *  B. ONE compact date/time metadata line carries the date, the clock time,
 *     the recurrence label and the series-end chip, separated by middot
 *     separators.
 *
 *  C. The location row is unchanged (T1.8): exactly one location row, and
 *     the county is still rendered beside the town.
 *
 *  D. LOCALIZATION: the month abbreviation follows the active UI locale
 *     (PT "OUT", EN "Oct"). This closes a real defect: the badge used to
 *     print the Portuguese abbreviation on /en/eventos/ while the rest of
 *     the card was translated. The series-end chip is translatable too.
 *
 *  E. The ACCESSIBLE date is untouched: the badge stays aria-hidden and the
 *     machine-readable <time datetime> node is still rendered. This is the
 *     concern that blocked T1.9 in the previous convergence phase, so it is
 *     asserted directly rather than assumed.
 *
 *  F. MULTI-DAY events keep their duration: the range stays visible, so a
 *     multi-day event can never be mistaken for a single-day one.
 *
 *  Read-only against the SITE's data: it creates nothing, mutates nothing
 * and contacts nothing. The markup assertions run against the theme SOURCE
 * (the contract), and the rendered-markup assertions run against the real
 * archive only when the site actually holds events (otherwise they are
 * skipped, never vacuously passed).
 *
 * @package Conexao_BR_Irlanda
 */

// Shared Stage E bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

echo "Event-card date badge (audit T1.9)\n";
echo str_repeat( '-', 72 ) . "\n";

/**
 * Read a theme source file once.
 *
 * @param string $rel Path relative to the theme root.
 * @return string
 */
function conexao_event_badge_source( $rel ) {
	static $cache = array();
	if ( ! isset( $cache[ $rel ] ) ) {
		$cache[ $rel ] = (string) file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}
	return $cache[ $rel ];
}

$event_card = conexao_event_badge_source( 'template-parts/event-card.php' );
$main_css   = conexao_event_badge_source( 'assets/css/main.css' );
$dark_css   = conexao_event_badge_source( 'assets/css/dark-mode.css' );

// ---------------------------------------------------------------------------
// A — the badge carries only the day and the month.
// ---------------------------------------------------------------------------

// The badge block must contain exactly the day and the month spans.
assert_true(
	1 === preg_match(
		'#<div class="event-card-date-badge[^"]*" aria-hidden="true">\s*<span class="event-card-date-day">.*?</span>\s*<span class="event-card-date-month">.*?</span>\s*</div>#s',
		$event_card
	),
	'A the badge contains exactly the day and the month spans, nothing else'
);

// The weekday / hint / range treatments must be GONE from the markup.
assert_true(
	false === strpos( $event_card, 'event-card-date-weekday' ),
	'A the badge no longer renders a weekday treatment'
);

assert_true(
	false === strpos( $event_card, 'event-card-date-hint' ),
	'A the badge no longer renders a "Hoje"/"Amanhã" hint chip'
);

assert_true(
	false === strpos( $event_card, 'event-card-date-range' ),
	'A the badge no longer renders a separate cross-month range chip'
);

assert_true(
	false === strpos( $event_card, "'Hoje'" ) && false === strpos( $event_card, "'Amanhã'" ),
	'A the "Hoje"/"Amanhã" strings are no longer produced by the template'
);

// ---------------------------------------------------------------------------
// B — one compact date/time metadata line.
// ---------------------------------------------------------------------------

assert_true(
	1 === substr_count( $event_card, 'event-card-detail--datetime' ),
	'B the card renders exactly ONE date/time metadata line'
);

assert_true(
	1 === substr_count( $event_card, 'event-card-date-text' ),
	'B the metadata line renders the date exactly once'
);

assert_true(
	1 === substr_count( $event_card, 'event-card-time' ),
	'B the metadata line renders the clock time exactly once'
);

// The separator is a CSS ::after glued to each non-last value, so it can
// never dangle (no markup separator exists any more).
assert_true(
	false === strpos( $event_card, 'event-card-datetime-sep' ),
	'B no markup separator remains (the separator is glued to its value)'
);

assert_true(
	1 === preg_match(
		'/\.events-page \.event-card-detail--datetime span:not\(:last-child\)::after\s*\{\s*content:\s*\'·\'/',
		$main_css
	),
	'B the separator is rendered as a CSS ::after on every non-last value'
);

// The recurrence chips moved INTO the line: still present, but no longer
// inside the badge.
assert_true(
	strpos( $event_card, 'event-card-recurrence' ) !== false
		&& strpos( $event_card, 'event-card-recurrence-end' ) !== false,
	'B the recurrence and series-end chips are still rendered (moved, not dropped)'
);

$badge_start = strpos( $event_card, 'event-card-date-badge' );
$badge_end   = strpos( $event_card, '</div>', (int) $badge_start );
$badge_block = substr( $event_card, (int) $badge_start, (int) $badge_end - (int) $badge_start );

assert_true(
	false === strpos( $badge_block, 'event-card-recurrence' ),
	'B the recurrence chip is not inside the badge any more'
);

assert_true(
	false === strpos( $badge_block, 'event-card-time' ),
	'B the clock time is not inside the badge any more'
);

// ---------------------------------------------------------------------------
// C — the location row is unchanged (T1.8 contract).
// ---------------------------------------------------------------------------

assert_true(
	1 === substr_count( $event_card, 'event-card-detail--location' ),
	'C the card renders exactly ONE location row'
);

assert_true(
	strpos( $event_card, "implode( ' · ', \$event_location_parts )" ) !== false,
	'C the location row still renders the town and the county together'
);

// ---------------------------------------------------------------------------
// D — localization: the month follows the active UI locale.
// ---------------------------------------------------------------------------

assert_true(
	strpos( $event_card, '$month_short_en' ) !== false
		&& strpos( $event_card, '$month_short_pt' ) !== false,
	'D the template carries a PT and an EN month-abbreviation map'
);

// The map is selected from the active locale: PT keeps the uppercase
// JAN-DEZ set, every other locale gets the English set.
assert_true(
	1 === preg_match(
		"/\\\$month_short\\s*=\\s*\\(\\s*0\\s*===\\s*strpos\\(\\s*\\\$event_card_locale\\s*,\\s*'pt'\\s*\\)\\s*\\)\\s*\\?\\s*\\\$month_short_pt\\s*:\\s*\\\$month_short_en\\s*;/",
		$event_card
	),
	'D the month map is selected from the active locale (PT vs EN)'
);

assert_true(
	strpos( $event_card, 'conexao_current_locale()' ) !== false,
	'D the locale is read through the shared conexao_current_locale() helper'
);

assert_true(
	strpos( $event_card, "__( 'até %1\$d %2\$s', 'conexao-br-irlanda' )" ) !== false,
	'D the series-end chip is a translatable string, not a hard-coded Portuguese literal'
);

// ---------------------------------------------------------------------------
// E — the accessible date is untouched (the T1.9 blocker).
// ---------------------------------------------------------------------------

assert_true(
	1 === preg_match( '/event-card-date-badge[^"]*" aria-hidden="true"/', $event_card ),
	'E the badge stays aria-hidden (purely decorative)'
);

assert_true(
	strpos( $event_card, 'event-card-date-iso screen-reader-text' ) !== false,
	'E the machine-readable <time> node is still rendered'
);

assert_true(
	strpos( $event_card, 'datetime="<?php echo esc_attr( $date_iso ); ?>"' ) !== false,
	'E the <time> node still carries the machine-readable datetime attribute'
);

assert_true(
	strpos( $event_card, '$sr_label' ) !== false && strpos( $event_card, '$date_iso' ) !== false,
	'E the accessible date sentence and the ISO date are still computed'
);

// ---------------------------------------------------------------------------
// F — multi-day events keep their duration.
// ---------------------------------------------------------------------------

assert_true(
	strpos( $event_card, '$line_date' ) !== false,
	'F the metadata line carries a computed date/range text'
);

assert_true(
	strpos( $event_card, '$show_range' ) !== false
		&& strpos( $event_card, '$event_end_date' ) !== false,
	'F the multi-day range is still derived from the stored end date'
);

assert_true(
	1 === preg_match(
		"/\\\$line_date\\s*=\\s*sprintf\\(\\s*'%s %s – %d %s'/u",
		$event_card
	),
	'F a cross-month range is rendered in full on the metadata line'
);

// ---------------------------------------------------------------------------
// G — the styling uses the existing design tokens (no arbitrary sizes).
// ---------------------------------------------------------------------------

assert_true(
	strpos( $main_css, '.events-page .event-card-detail--datetime' ) !== false,
	'G the metadata line has its own scoped style'
);

assert_true(
	1 === preg_match(
		'/\.events-page \.event-card-detail--datetime \{[^}]*font-size:\s*var\(--text-sm\);/',
		$main_css
	),
	'G the metadata line uses the existing --text-sm token (no arbitrary font size)'
);

assert_true(
	strpos( $main_css, '.events-page .event-card-date-text' ) !== false,
	'G the date text has its own scoped style'
);

assert_true(
	strpos( $dark_css, '.event-card-detail--datetime' ) !== false
		&& strpos( $dark_css, '.event-card-date-text' ) !== false,
	'G the dark theme mirrors the metadata line colours'
);

// ---------------------------------------------------------------------------
// H — the rendered archive agrees with the contract. Skipped (never
// vacuously passed) when the install holds no published events.
// ---------------------------------------------------------------------------

$rendered_ids = get_posts(
	array(
		'post_type'      => 'event',
		'post_status'    => 'publish',
		'posts_per_page' => 5,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	)
);

if ( empty( $rendered_ids ) ) {
	echo "  (no published events on this install - rendered-markup checks skipped)\n";
} else {
	foreach ( $rendered_ids as $rendered_id ) {
		$GLOBALS['post'] = get_post( $rendered_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $GLOBALS['post'] );

		ob_start();
		get_template_part( 'template-parts/event', 'card' );
		$card_html = (string) ob_get_clean();

		wp_reset_postdata();

		if ( '' === trim( $card_html ) ) {
			continue;
		}

		// The badge carries the day and the month, and nothing else.
		$badge = '';
		if ( preg_match( '#<div class="event-card-date-badge[^"]*"[^>]*>(.*?)</div>#s', $card_html, $badge_match ) ) {
			$badge = $badge_match[1];
		}

		// An event with no displayable date has no date to present: the line
		// and the <time> node are legitimately absent (verified, not assumed).
		// The template uses conexao_event_display_date() (recurrence-aware),
		// so the test reads the same helper rather than raw meta.
		$event_has_date = '' !== trim( (string) conexao_event_display_date( $rendered_id ) );

		assert_true(
			'' !== $badge
				&& false === strpos( $badge, 'event-card-date-weekday' )
				&& false === strpos( $badge, 'event-card-date-hint' )
				&& false === strpos( $badge, 'event-card-recurrence' )
				&& false === strpos( $badge, 'event-card-time' ),
			sprintf( 'H the rendered badge for event %d holds the day and month only', $rendered_id )
		);

		assert_true(
			false !== strpos( $badge, 'event-card-date-day' )
				&& false !== strpos( $badge, 'event-card-date-month' ),
			sprintf( 'H the rendered badge for event %d shows a day and a month', $rendered_id )
		);

		// The metadata line is present exactly once WHEN the event has a date.
		assert_true(
			$event_has_date
				? ( 1 === substr_count( $card_html, 'event-card-detail--datetime' ) )
				: ( 0 === substr_count( $card_html, 'event-card-detail--datetime' ) ),
			sprintf( 'H the rendered card for event %d shows exactly one date/time line iff it has a date', $rendered_id )
		);

		// The accessible date is still present (same conditionality).
		assert_true(
			$event_has_date
				? ( false !== strpos( $card_html, 'event-card-date-iso screen-reader-text' ) )
				: ( false === strpos( $card_html, 'event-card-date-iso screen-reader-text' ) ),
			sprintf( 'H the rendered card for event %d keeps its accessible <time> date iff it has a date', $rendered_id )
		);

		// The location row is present at most once.
		assert_true(
			substr_count( $card_html, 'event-card-detail--location' ) <= 1,
			sprintf( 'H the rendered card for event %d shows at most one location row', $rendered_id )
		);

		// No empty / dangling separator: every non-last value carries the
		// CSS ::after separator, so the count of separators must equal
		// values - 1, and no value may be empty.
		$line_block = '';
		if ( preg_match( '#<span class="event-card-detail event-card-detail--datetime">(.*?)</span>\s*</div>#s', $card_html, $line_match ) ) {
			$line_block = $line_match[1];
		}

		if ( '' !== $line_block ) {
			$value_names = array( 'event-card-date-text', 'event-card-time', 'event-card-recurrence', 'event-card-recurrence-end' );
			$value_count = 0;
			$empty_value = false;
			foreach ( $value_names as $value_name ) {
				if ( preg_match_all( '#' . preg_quote( $value_name, '#' ) . '"[^>]*>([^<]*)<#s', $line_block, $value_matches ) ) {
					foreach ( $value_matches[1] as $value_text ) {
						++$value_count;
						if ( '' === trim( $value_text ) ) {
							$empty_value = true;
						}
					}
				}
			}

			assert_true(
				$value_count >= 1,
				sprintf( 'H the rendered card for event %d has at least one date/time value', $rendered_id )
			);

			assert_true(
				false === $empty_value,
				sprintf( 'H the rendered card for event %d has no empty value in the date/time line', $rendered_id )
			);

			// No markup separator may exist at all (CSS-glued).
			assert_true(
				false === strpos( $line_block, 'event-card-datetime-sep' ),
				sprintf( 'H the rendered card for event %d has no dangling markup separator', $rendered_id )
			);
		}
	}
}

test_finish( 'event-date-badge' );
