<?php
/**
 * CI FIXTURE DATASET — the Laois Tourism ICS import, in miniature.
 *
 * Stage 20 shipped the ICS occurrence-date engine and a matching HTTP
 * acceptance suite, but nothing ever IMPORTED the feed into the CI site:
 * `bootstrap-ci-fixtures.php` builds events only from `ci-fixture-events.php`.
 * The acceptance suite therefore ran against records that existed only in the
 * database of whoever had executed the Stage 20 evidence runner by hand, and
 * the three ICS events were missing from a fresh CI site (archive listing
 * absent, detail page 404).
 *
 * This file closes that gap WITHOUT introducing a second event system: it
 * emits a real iCalendar document and the orchestrator feeds it to the REAL
 * importer engine under the REAL `laois_tourism` source id, through the same
 * `ics_content` slot wp-admin uses when an operator uploads an ICS file. The
 * parser, the source-scoped collapsed-series adapter, the recurrence
 * persistence, the runtime evaluator and the theme card are all the
 * production ones — nothing here re-implements any of them.
 *
 * ── Why the dates are RELATIVE ──────────────────────────────────────────
 *
 * Exactly the reason `ci-fixture-events.php` uses relative dates: the event
 * runtime only lists events that occur inside a "today .. today+7" window, so
 * a fixed calendar date would silently empty /eventos/ once it expired and the
 * suite would rot into a permanent red. One "today" is captured here and every
 * date is derived from it, so a run is internally consistent and two runs on
 * the same day are byte-identical.
 *
 * The offsets are also chosen so the series is already UNDERWAY: its first
 * occurrence is in the past, so the badge the archive renders is provably the
 * NEXT occurrence and not merely the series start — which is precisely the
 * regression the pre-fix DTSTART..DTEND range interpretation produced.
 *
 * ── The three shapes (deliberately different) ────────────────────────────
 *
 *  1. A collapsed weekly series: a TIMED VEVENT spanning whole weeks with the
 *     same weekday on both ends and no RRULE — the convention the Laois
 *     publisher actually uses, recognised structurally by
 *     Conexao_Laois_Tourism_Series.
 *  2. A genuine multi-day timed event: three days, so the weekday ends differ
 *     and the day span is not a whole number of weeks. It must stay a plain
 *     one-time event covering its whole span, never a weekly series.
 *  3. A VALUE=DATE all-day range, which the adapter excludes outright and
 *     which keeps genuine all-day range semantics.
 *
 * No ATTACH line is emitted on purpose: the importer would otherwise try to
 * sideload a remote banner over the network, and the CI job is offline.
 *
 * @package Conexao_BR_Scripts
 */

defined( 'ABSPATH' ) || defined( 'CONEXAO_SCRIPTS_BOOTSTRAP' ) || exit;

/**
 * Calendar date `offset` days from the single captured "today".
 *
 * Pure calendar arithmetic in UTC, so a DST transition inside the window can
 * never shift a derived date by an hour.
 *
 * @param string $today  Site-local Y-m-d.
 * @param int    $offset Signed day offset.
 * @return string Y-m-d.
 */
function conexao_ci_fixture_offset_date( string $today, int $offset ): string {
	return gmdate( 'Y-m-d', strtotime( $today . ' 00:00:00 UTC' ) + ( $offset * DAY_IN_SECONDS ) );
}

/**
 * The three ICS events the deterministic CI site imports, and the occurrence
 * facts the bootstrap audit asserts against them.
 *
 * @return array{ics:string,today:string,events:array<string,array<string,mixed>>}
 */
function conexao_ci_fixture_laois_ics(): array {
	$today = current_time( 'Y-m-d' );

	/*
	 * Offsets, and why each one is what it is.
	 *
	 * series  : started 5 days ago, four weekly occurrences 7 days apart, so
	 *           the window [today, today+7] contains exactly one occurrence
	 *           (today+2) and that occurrence is NOT the first one. The
	 *           detector sees a 21-day span (a whole number of weeks) with
	 *           the weekday preserved and a one-hour session remainder.
	 * multiday: runs today+1 .. today+3. Two days apart, so the weekday ends
	 *           differ and the span is not a multiple of seven.
	 * allday  : a VALUE=DATE range today+3 .. today+6, which the adapter
	 *           excludes by rule 1 (all-day) before any other test runs.
	 */
	$definitions = array(
		'series'   => array(
			'uid'          => 'ci-laois-collapsed-weekly-series@conexaobr.invalid',
			'summary'      => 'Evento CI serie semanal (Laois)',
			'description'  => 'Serie semanal de fixture deterministica, criada pela arvore de fixtures da CI a partir de um documento iCalendar real importado pelo motor de eventos. Nao e um evento real.',
			'location'     => 'Portlaoise, County Laois',
			'start_offset' => -5,
			'end_offset'   => 16,
			'start_time'   => '14:00',
			'end_time'     => '15:00',
			'kind'         => 'series',
		),
		'multiday' => array(
			'uid'          => 'ci-laois-multi-day@conexaobr.invalid',
			'summary'      => 'Evento CI multiday (Laois)',
			'description'  => 'Evento de varios dias de fixture deterministica, importado pelo motor de eventos a partir de um documento iCalendar real. Nao e um evento real.',
			'location'     => 'Portlaoise, County Laois',
			'start_offset' => 1,
			'end_offset'   => 3,
			'start_time'   => '19:30',
			'end_time'     => '22:00',
			'kind'         => 'multiday',
		),
		'allday'   => array(
			'uid'          => 'ci-laois-all-day@conexaobr.invalid',
			'summary'      => 'Evento CI all-day (Laois)',
			'description'  => 'Exposicao de dia inteiro de fixture deterministica, importada pelo motor de eventos a partir de um documento iCalendar real. Nao e um evento real.',
			'location'     => 'Portlaoise, County Laois',
			'start_offset' => 3,
			'end_offset'   => 6,
			'start_time'   => '',
			'end_time'     => '',
			'kind'         => 'allday',
		),
	);

	$stamp  = gmdate( 'Ymd', strtotime( $today . ' 00:00:00 UTC' ) ) . 'T120000Z';
	$lines  = array(
		'BEGIN:VCALENDAR',
		'VERSION:2.0',
		// Deliberately NOT the publisher's PRODID: this is a CI fixture
		// document, and the engine keys the collapsed-series convention off
		// the SOURCE ID, never off the document's identity.
		'PRODID:-//Conexao BR Ireland - deterministic CI fixture//NONSGML v1.0//EN',
		'CALSCALE:GREGORIAN',
		'METHOD:PUBLISH',
		'X-WR-CALNAME:Conexao BR Ireland CI fixture (Laois shape)',
	);
	$events = array();

	foreach ( $definitions as $key => $definition ) {
		$start   = conexao_ci_fixture_offset_date( $today, (int) $definition['start_offset'] );
		$end     = conexao_ci_fixture_offset_date( $today, (int) $definition['end_offset'] );
		$all_day = ( 'allday' === $definition['kind'] );

		$lines[] = 'BEGIN:VEVENT';
		$lines[] = 'UID:' . $definition['uid'];
		$lines[] = 'DTSTAMP:' . $stamp;
		$lines[] = 'CREATED:' . $stamp;
		$lines[] = 'LAST-MODIFIED:' . $stamp;

		if ( $all_day ) {
			// RFC 5545 VALUE=DATE. The importer stores DTEND verbatim as the
			// range end; no time component, so the event is genuinely all-day.
			$lines[] = 'DTSTART;VALUE=DATE:' . str_replace( '-', '', $start );
			$lines[] = 'DTEND;VALUE=DATE:' . str_replace( '-', '', $end );
		} else {
			// No RRULE anywhere: the collapsed weekly encoding is the whole
			// point of this fixture.
			$lines[] = 'DTSTART;TZID=Europe/Dublin:' . str_replace( '-', '', $start ) . 'T' . str_replace( ':', '', $definition['start_time'] ) . '00';
			$lines[] = 'DTEND;TZID=Europe/Dublin:' . str_replace( '-', '', $end ) . 'T' . str_replace( ':', '', $definition['end_time'] ) . '00';
		}

		$lines[] = 'SUMMARY:' . $definition['summary'];
		$lines[] = 'DESCRIPTION:' . $definition['description'];
		$lines[] = 'LOCATION:' . $definition['location'];
		$lines[] = 'URL:https://example.invalid/conexao-ci/laois/' . $key;
		$lines[] = 'CATEGORIES:Cultura';
		$lines[] = 'END:VEVENT';

		$expected = array(
			'kind'       => $definition['kind'],
			'title'      => $definition['summary'],
			'slug'       => sanitize_title( $definition['summary'] ),
			'date'       => $start,
			'end_date'   => $end,
			'recurrence' => 'series' === $definition['kind'] ? 'weekly' : '',
		);

		if ( 'series' === $definition['kind'] ) {
			/*
			 * The four weekly occurrence dates, derived from the same offsets
			 * the ICS was written with. Seven-day steps from the series start
			 * reach exactly the DTEND date, which is what makes the collapsed
			 * encoding detectable in the first place.
			 */
			$occurrences = array();
			for ( $week = 0; $week <= 3; $week++ ) {
				$occurrences[] = conexao_ci_fixture_offset_date( $start, $week * 7 );
			}
			$expected['occurrences']    = $occurrences;
			$expected['recurrence_end'] = end( $occurrences );
			$expected['next_occurrence'] = $occurrences[1];
		}

		$events[ $key ] = $expected;
	}

	$lines[] = 'END:VCALENDAR';

	return array(
		'ics'    => implode( "\r\n", $lines ) . "\r\n",
		'today'  => $today,
		'events' => $events,
	);
}
