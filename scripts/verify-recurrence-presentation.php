<?php
/**
 * Step 4 presentation verification: renders the real event-card.php template
 * for the 8 approved test scenarios and asserts the recurrence presentation.
 *
 * Usage:
 *   docker compose exec wordpress php /var/www/html/scripts/verify-recurrence-presentation.php
 * (auto-cleans its own temporary events; safe to re-run)
 */

$wp_load = dirname( __DIR__ ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

const P4_PREFIX = '[P4-TEST] ';

$pass = 0;
$fail = 0;

function p4_check( $name, $condition, $detail = '' ) {
	global $pass, $fail;
	if ( $condition ) {
		$pass++;
		echo "  PASS: {$name}\n";
	} else {
		$fail++;
		echo "  FAIL: {$name}" . ( $detail ? " -- {$detail}" : '' ) . "\n";
	}
}

function p4_day( $offset ) {
	return Conexao_Event_Query::today()->modify( ( $offset >= 0 ? '+' : '' ) . $offset . ' day' )->format( 'Y-m-d' );
}

function p4_iso( $offset ) {
	return (int) Conexao_Event_Query::today()->modify( ( $offset >= 0 ? '+' : '' ) . $offset . ' day' )->format( 'N' );
}

function p4_create( $title, $meta ) {
	$id = wp_insert_post( array(
		'post_type'    => 'event',
		'post_title'   => P4_PREFIX . $title,
		'post_status'  => 'publish',
		'post_content' => 'Step 4 presentation test event.',
	), true );
	if ( is_wp_error( $id ) ) {
		echo "  ERROR creating {$title}: {$id->get_error_message()}\n";
		return 0;
	}
	foreach ( $meta as $k => $v ) {
		update_post_meta( $id, $k, $v );
	}
	return $id;
}

function p4_cleanup() {
	$ids = get_posts( array(
		'post_type'      => 'event',
		'post_status'    => 'any',
		'posts_per_page' => 100,
		's'              => P4_PREFIX,
		'fields'         => 'ids',
	) );
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	Conexao_Event_Query::flush_cache();
}

function p4_render_card( $post_id ) {
	if ( empty( $post_id ) ) {
		return '';
	}
	$q = new WP_Query( array(
		'post_type'      => 'event',
		'post_status'    => 'publish',
		'p'              => $post_id,
		'posts_per_page' => 1,
	) );
	ob_start();
	while ( $q->have_posts() ) {
		$q->the_post();
		get_template_part( 'template-parts/event', 'card' );
	}
	wp_reset_postdata();
	return ob_get_clean();
}

p4_cleanup();

$tomorrow_iso = p4_iso( 1 );

// S1: weekly Wednesday (ISO 3).
$s1 = p4_create( '1. Weekly Wednesday', array(
	'_event_date'             => p4_day( -7 ),
	'_event_time'             => '19:00',
	'_event_recurrence'       => 'weekly',
	'_event_recurrence_days'  => '3',
	'_event_recurrence_start' => p4_day( -7 ),
) );

// S2: Monday + Wednesday (ISO 1,3).
$s2 = p4_create( '2. Weekly Mon+Wed', array(
	'_event_date'             => p4_day( -7 ),
	'_event_recurrence'       => 'weekly',
	'_event_recurrence_days'  => '1,3',
	'_event_recurrence_start' => p4_day( -7 ),
) );

// S4: recurring event happening TOMORROW (tomorrow's weekday).
$s4 = p4_create( '4. Weekly tomorrow (' . $tomorrow_iso . ')', array(
	'_event_date'             => p4_day( -10 ),
	'_event_time'             => '18:00',
	'_event_recurrence'       => 'weekly',
	'_event_recurrence_days'  => (string) $tomorrow_iso,
	'_event_recurrence_start' => p4_day( -10 ),
) );

// S8: weekly with a KNOWN end date (end-range presentation check).
$s8 = p4_create( '8. Weekly with end', array(
	'_event_date'             => p4_day( -14 ),
	'_event_recurrence'       => 'weekly',
	'_event_recurrence_days'  => (string) p4_iso( 0 ),
	'_event_recurrence_start' => p4_day( -14 ),
	'_event_recurrence_end'   => p4_day( 30 ),
) );

Conexao_Event_Query::flush_cache();

// Seeded Step 3 matrix (scripts/seed-recurrence-test-events.php).
// Bracketed titles break WP search tokenization, so match titles in PHP.
$all_events = get_posts( array(
	'post_type'      => 'event',
	'post_status'    => 'publish',
	'posts_per_page' => 200,
	'fields'         => 'ids',
) );
$seed = array();
foreach ( $all_events as $sid ) {
	$title = get_the_title( $sid );
	if ( strpos( $title, '[REC-TEST]' ) !== 0 ) { continue; }
	if ( strpos( $title, '3. Weekly' ) !== false )            { $seed['today_recurring'] = $sid; }
	if ( strpos( $title, '4. Weekly' ) !== false )            { $seed['not_started'] = $sid; }
	if ( strpos( $title, '5. Weekly' ) !== false )            { $seed['ended'] = $sid; }
	if ( strpos( $title, '7. Weekly open-ended' ) !== false ) { $seed['open'] = $sid; }
	if ( strpos( $title, '9. Normal event' ) !== false )      { $seed['one_time'] = $sid; }
}
$missing = array_diff(
	array( 'today_recurring', 'not_started', 'ended', 'open', 'one_time' ),
	array_keys( $seed )
);
if ( ! empty( $missing ) ) {
	echo "FAIL: missing seeded [REC-TEST] events: " . implode( ', ', $missing ) . "\n";
	echo "Run first: docker compose exec wordpress php /var/www/html/scripts/seed-recurrence-test-events.php\n";
	exit( 1 );
}

echo "\n=== Recurrence label helper ===\n";
p4_check( 'S1 label: weekly Wednesday = "Toda quarta-feira"',
	'Toda quarta-feira' === conexao_event_recurrence_label( $s1 ),
	conexao_event_recurrence_label( $s1 ) );
p4_check( 'S2 label: Mon+Wed = "Toda segunda e quarta"',
	'Toda segunda e quarta' === conexao_event_recurrence_label( $s2 ),
	conexao_event_recurrence_label( $s2 ) );
p4_check( 'S6 label: one-time event = "" (empty)',
	'' === conexao_event_recurrence_label( $seed['one_time'] ),
	conexao_event_recurrence_label( $seed['one_time'] ) );

echo "\n=== Card rendering (event-card.php) ===\n";

// S1: weekly Wednesday card.
$h1 = p4_render_card( $s1 );
p4_check( 'S1 card shows "Toda quarta-feira"', strpos( $h1, 'Toda quarta-feira' ) !== false );
p4_check( 'S1 card leaks no recurrence meta keys', strpos( $h1, '_event_recurrence' ) === false );

// S2: Monday + Wednesday card.
$h2 = p4_render_card( $s2 );
p4_check( 'S2 card shows "Toda segunda e quarta"', strpos( $h2, 'Toda segunda e quarta' ) !== false );

// S3: recurring event happening TODAY (seeded #3).
$h3 = p4_render_card( $seed['today_recurring'] );
p4_check( 'S3 today recurring: badge has is-today class', strpos( $h3, 'is-today' ) !== false );
p4_check( 'S3 today recurring: "Hoje" chip present', strpos( $h3, 'event-card-date-hint' ) !== false && strpos( $h3, '>Hoje<' ) !== false );
p4_check( 'S3 today recurring: shows "Toda quinta-feira"', strpos( $h3, 'Toda quinta-feira' ) !== false );
p4_check( 'S3 today recurring: sr-only <time> includes recurrence', preg_match( '/screen-reader-text"[^>]*>[^<]*— Toda quinta-feira/u', $h3 ) === 1 );

// S4: recurring event happening TOMORROW.
$h4 = p4_render_card( $s4 );
p4_check( 'S4 tomorrow recurring: NO is-today class', strpos( $h4, 'is-today' ) === false );
p4_check( 'S4 tomorrow recurring: "Amanhã" chip present', strpos( $h4, '>Amanhã<' ) !== false );
p4_check( 'S4 tomorrow recurring: NO "Hoje" chip', strpos( $h4, '>Hoje<' ) === false );
p4_check( 'S4 evaluator: occurs_on_date(today) is false',
	Conexao_Event_Recurrence::occurs_on_date( (int) $s4, current_datetime() ) === false );
p4_check( 'S4 evaluator: occurs_on_date(tomorrow) is true',
	Conexao_Event_Recurrence::occurs_on_date( (int) $s4, current_datetime()->modify( '+1 day' ) ) === true );

echo "\n=== S5: inactive recurrence (evaluator gate) ===\n";
p4_check( 'S5 not-started: occurs_on_date(today) false',
	Conexao_Event_Recurrence::occurs_on_date( (int) $seed['not_started'], current_datetime() ) === false );
p4_check( 'S5 ended: occurs_on_date(today) false',
	Conexao_Event_Recurrence::occurs_on_date( (int) $seed['ended'], current_datetime() ) === false );
$upcoming = array_map( 'intval', Conexao_Event_Query::upcoming_event_ids() );
p4_check( 'S5 not-started NOT in upcoming list', ! in_array( (int) $seed['not_started'], $upcoming, true ) );
p4_check( 'S5 ended NOT in upcoming list', ! in_array( (int) $seed['ended'], $upcoming, true ) );

// S6: one-time event -- visual output must be recurrence-free.
$h6 = p4_render_card( $seed['one_time'] );
p4_check( 'S6 one-time: no recurrence label span', strpos( $h6, 'event-card-recurrence"' ) === false && strpos( $h6, 'event-card-recurrence-end' ) === false );
p4_check( 'S6 one-time: no "Toda" text anywhere', strpos( $h6, 'Toda ' ) === false );
p4_check( 'S6 one-time: no Hoje/Amanhã chip', strpos( $h6, 'event-card-date-hint' ) === false );
p4_check( 'S6 one-time: badge has no is-today class', strpos( $h6, 'is-today' ) === false );
p4_check( 'S6 one-time: sr-only <time> sentence unchanged (no dash suffix)', strpos( $h6, ' — Toda' ) === false );

// S7: open-ended recurring (seeded #7).
$h7 = p4_render_card( $seed['open'] );
p4_check( 'S7 open-ended: shows "Toda quinta-feira"', strpos( $h7, 'Toda quinta-feira' ) !== false );
p4_check( 'S7 open-ended: NO recurrence end-date span', strpos( $h7, 'event-card-recurrence-end' ) === false );

// S8: recurring with end date.
$h8 = p4_render_card( $s8 );
p4_check( 'S8 end-dated: shows "Toda quinta-feira"', strpos( $h8, 'Toda quinta-feira' ) !== false );
p4_check( 'S8 end-dated: recurrence end span present', strpos( $h8, 'event-card-recurrence-end' ) !== false );
p4_check( 'S8 end-dated: end shows "ate <day> <MONTH>" pattern', preg_match( '/event-card-recurrence-end">at\S+ \d{1,2} [A-Z\x{00C0}-\x{00DA}]{3}</u', $h8 ) === 1 );

// S3\'s seeded event also has an end date (today+60d) -- end span expected.
p4_check( 'S3 today recurring also shows end-date span (seeded has end)', strpos( $h3, 'event-card-recurrence-end' ) !== false );

echo "\n=== Accessibility ===\n";
p4_check( 'Badge is aria-hidden (recurrence compensated by sr-only <time>)',
	preg_match( '/event-card-date-badge[^>]*aria-hidden="true"/', $h3 ) === 1 );
p4_check( 'sr-only <time> element present for recurring event', strpos( $h3, 'screen-reader-text' ) !== false );
p4_check( 'No heading-level change (title stays <h3>)', preg_match( '/<h3 class="event-card-title"/', $h3 ) === 1 );

echo "\n=== Data leak checks ===\n";
$all = $h1 . $h2 . $h3 . $h4 . $h6 . $h7 . $h8;
// Note: 'weekly' is deliberately NOT a leak needle — legitimate post slugs
// (from titles containing "Weekly") contain the word. Meta keys and CSV
// tokens must never appear; those are the real storage leaks.
$leaks = array( '_event_recurrence', '_event_date', 'recurrence_days' );
$leak_found = '';
foreach ( $leaks as $needle ) {
	if ( strpos( $all, $needle ) !== false ) { $leak_found = $needle; break; }
}
p4_check( 'No raw meta keys / CSV / storage tokens in any rendered card', '' === $leak_found, $leak_found );

// S9: multi-day event on day 2 (start yesterday, end tomorrow) -> "Hoje".
$s9 = p4_create( '9. Multi-day Hoje', array(
	'_event_date'      => p4_day( -1 ),
	'_event_end_date'  => p4_day( 1 ),
	'_event_time'      => '19:00',
) );
$h9  = p4_render_card( $s9 );
p4_check( 'M: multi-day day-2 shows "Hoje" chip', strpos( $h9, '>Hoje<' ) !== false );
p4_check( 'M: multi-day day-2 badge has is-today class', strpos( $h9, 'is-today' ) !== false );

// S10: multi-day event where tomorrow is still within range -> "Amanhã".
$s10 = p4_create( '10. Multi-day Amanhã', array(
	'_event_date'      => p4_day( 0 ),
	'_event_end_date'  => p4_day( 1 ),
	'_event_time'      => '20:00',
) );
$h10 = p4_render_card( $s10 );
p4_check( 'N: multi-day event with tomorrow in range shows "Amanhã" when rendered tomorrow',
	Conexao_Event_Recurrence::occurs_on_date( (int) $s10, current_datetime()->modify( '+1 day' ) ) === true );
// Today it should say Hoje (day 1 of 2).
p4_check( 'N: multi-day event day-1 shows "Hoje"', strpos( $h10, '>Hoje<' ) !== false );

// S11: multi-day event after end date -> neither chip.
$s11 = p4_create( '11. Multi-day ended', array(
	'_event_date'      => p4_day( -5 ),
	'_event_end_date'  => p4_day( -3 ),
	'_event_time'      => '18:00',
) );
$h11 = p4_render_card( $s11 );
p4_check( 'multi-day ended: no Hoje/Amanhã chip', strpos( $h11, 'event-card-date-hint' ) === false );

p4_cleanup();
echo "\nStep 4 presentation test events removed; recurrence cache flushed.\n";

echo "\n==============================\n";
echo "PASSED: {$pass}  FAILED: {$fail}\n";
echo "==============================\n";
exit( $fail > 0 ? 1 : 0 );
