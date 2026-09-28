<?php
/**
 * Event display dates and recurrence presentation
 *
 * Upcoming event ID resolution, display-date formatting and the recurring
 * event presentation layer (label, weekday names, day lists, next end date).
 * Recurrence data itself is owned by conexao-event-runtime.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ordered ID list of currently active/upcoming public events.

/**
 * Ordered ID list of currently active/upcoming public events.
 *
 * Thin theme-side wrapper over Conexao_Event_Query (conexao-event-runtime
 * plugin): one SQL candidate query, one batch meta load, exact PHP recurrence
 * evaluation, sorted by next occurrence, date-keyed transient cache. Shared
 * by the /eventos/ archive, the hero widget, the homepage events section,
 * the 404 page and the landing-page events section.
 *
 * Returns null when the event runtime plugin (and therefore the recurrence
 * query helper) is not available, so every caller can fall back to the
 * legacy date-meta query path unchanged.
 *
 * @return int[]|null Ordered event post IDs, or null when unavailable.
 */
function conexao_event_upcoming_ids() {
	if ( ! class_exists( 'Conexao_Event_Query' ) ) {
		return null;
	}

	$ids = Conexao_Event_Query::upcoming_event_ids();
	return is_array( $ids ) ? $ids : null;
}

/**
 * The date an event card should display.
 *
 * Recurring events show their NEXT occurrence (from the shared, cached
 * upcoming-events map) instead of their stored `_event_date`, which is only
 * the series start. One-time events (and any event not in the current
 * window) keep the stored `_event_date` — existing behavior is untouched.
 *
 * @param int $event_id Event post ID.
 * @return string Y-m-d date string (may be empty when the event has none).
 */
function conexao_event_display_date( $event_id ) {
	$date = get_post_meta( (int) $event_id, '_event_date', true );

	if ( class_exists( 'Conexao_Event_Query' ) ) {
		$next = Conexao_Event_Query::next_occurrence_date( (int) $event_id );
		if ( $next ) {
			$date = $next;
			}
	}

	return $date;
}

/**
 * Whether an event is a weekly recurring event.
 *
 * Wraps the runtime class so the template never reads recurrence meta or
 * duplicate any evaluator logic. Returns false when the event runtime
 * plugin is inactive (graceful degradation to one-time behavior).
 *
 * @param int $event_id Event post ID.
 * @return bool
 */
function conexao_event_is_recurring( $event_id ): bool {
	if ( ! class_exists( 'Conexao_Event_Recurrence' ) ) {
		return false;
	}
	return Conexao_Event_Recurrence::TYPE_WEEKLY ===
		Conexao_Event_Recurrence::recurrence_type( (int) $event_id );
}

/**
 * Concise recurrence label for a recurring event, in the active UI language.
 *
 * Portuguese (the site's source language) renders exactly as before:
 * "Toda quarta-feira" (single day) or "Toda segunda e quarta" (multiple
 * days). Non-PT locales (Stage 2's en_US catalog) get their own natural
 * wording, e.g. "Every Wednesday" / "Every Monday and Wednesday", via the
 * theme's translation catalogs. Returns an empty string for one-time /
 * non-weekly / invalid events.
 *
 * ISO weekday numbers (1 = Monday … 7 = Sunday) are mapped to localized
 * full weekday names; raw numbers and CSV storage are never surfaced. The
 * underlying recurrence model is untouched — ISO day codes, recurrence meta
 * and storage are presentation-agnostic; only presentation is
 * language-aware here.
 *
 * @param int $event_id Event post ID.
 * @return string Empty when the event is not recurring.
 */
function conexao_event_recurrence_label( $event_id ): string {
	if ( ! conexao_event_is_recurring( $event_id ) ) {
		return '';
	}

	$days = Conexao_Event_Recurrence::recurrence_days( (int) $event_id );
	if ( empty( $days ) ) {
		return '';
	}

	return conexao_recurrence_present_days( $days );
}

/**
 * Localized full weekday name for an ISO weekday code (1 = Monday … 7 = Sunday).
 *
 * Presentation only. The source strings are the Portuguese full names (the
 * site's source language); translation catalogs map them per locale.
 *
 * @param int $iso_day ISO weekday code.
 * @return string Weekday name, or '' for an invalid code.
 */
function conexao_recurrence_weekday_name( $iso_day ): string {
	$names = array(
		1 => __( 'segunda-feira', 'conexao-br-irlanda' ),
		2 => __( 'terça-feira', 'conexao-br-irlanda' ),
		3 => __( 'quarta-feira', 'conexao-br-irlanda' ),
		4 => __( 'quinta-feira', 'conexao-br-irlanda' ),
		5 => __( 'sexta-feira', 'conexao-br-irlanda' ),
		6 => __( 'sábado', 'conexao-br-irlanda' ),
		7 => __( 'domingo', 'conexao-br-irlanda' ),
	);

	return isset( $names[ $iso_day ] ) ? $names[ $iso_day ] : '';
}

/**
 * Join two or more localized list items with the active language's glue.
 *
 * Portuguese: "segunda e quarta" / "segunda, quarta e sexta".
 * English (via catalog): "Monday and Wednesday" / "Monday, Wednesday and Friday"
 * — the same two patterns translate naturally because the last item is
 * always passed separately.
 *
 * @param array $items Non-empty list of localized names (at least 1).
 * @return string The joined list.
 */
function conexao_recurrence_list_join( array $items ): string {
	if ( 1 === count( $items ) ) {
		return $items[0];
	}

	if ( 2 === count( $items ) ) {
		/* translators: %1$s and %2$s are weekday names, e.g. "segunda" and "quarta". */
		return sprintf( __( '%1$s e %2$s', 'conexao-br-irlanda' ), $items[0], $items[1] );
	}

	$last = array_pop( $items );
	/* translators: %1$s is a comma-separated list of weekday names; %2$s is the final weekday name. */
	return sprintf( __( '%1$s e %2$s', 'conexao-br-irlanda' ), implode( ', ', $items ), $last );
}

/**
 * Present recurrence ISO weekday codes as a localized label.
 *
 * Language-aware presentation mechanism (Stage 1 i18n foundation):
 *
 *  - PT locales keep the site's exact current grammar: single day
 *    "Toda quarta-feira"; multiple days drop the "-feira" suffix
 *    ("Toda segunda e quarta", "Toda segunda, quarta e sexta") — a
 *    Portuguese-specific rule, so it only runs on the PT path.
 *  - Any other locale uses its translated weekday names and list glue with
 *    no "-feira" handling (a no-op for English "Monday" anyway, but the
 *    language-specific rule is deliberately kept out of the generic path).
 *
 * The recurrence model (ISO codes 1–7, meta keys, CSV storage) is never
 * touched and never leaks into the output.
 *
 * @param array $iso_days Non-empty list of ISO weekday codes (1–7).
 * @return string The recurrence label, or '' when no valid day is given.
 */
function conexao_recurrence_present_days( array $iso_days ): string {
	$names = array();
	foreach ( $iso_days as $iso_day ) {
		$name = conexao_recurrence_weekday_name( $iso_day );
		if ( '' !== $name ) {
			$names[] = $name;
		}
	}

	if ( empty( $names ) ) {
		return '';
	}

	if ( 0 === strpos( conexao_current_locale(), 'pt' ) && count( $names ) > 1 ) {
		/*
		 * Portuguese grammar: when listing multiple days the "-feira"
		 * suffix is dropped ("segunda", not "segunda-feira"). "sábado" and
		 * "domingo" have no suffix and pass through unchanged. This
		 * language-specific rule only runs on the PT path; non-PT locales
		 * join their translated names directly with no suffix handling.
		 */
		$names = array_map(
			static function ( $name ) {
				return preg_replace( '/-feira$/u', '', $name );
			},
			$names
		);
	}

	/* translators: %s is a weekday name or a list of weekday names, e.g. "quarta-feira" or "segunda, quarta e sexta". */
	return sprintf( __( 'Toda %s', 'conexao-br-irlanda' ), conexao_recurrence_list_join( $names ) );
}

/**
 * The recurrence end date (Y-m-d) for a recurring event.
 *
 * Returns null for one-time events, non-weekly recurrence, open-ended
 * series, or invalid dates. The runtime class performs the validation.
 *
 * @param int $event_id Event post ID.
 * @return string|null Validated Y-m-d string, or null when open-ended.
 */
function conexao_event_recurrence_end( $event_id ): ?string {
	if ( ! conexao_event_is_recurring( $event_id ) ) {
		return null;
	}
	return Conexao_Event_Recurrence::recurrence_end( (int) $event_id );
}
