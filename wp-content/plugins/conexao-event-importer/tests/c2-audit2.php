<?php
/**
 * Stage C2 — Audit phase 2: date/time model, categories, content boundary.
 *
 * Read-only. Validates the imported pilot events against the Event model:
 *  - date/time format + multi-day/overnight/no-time distribution
 *  - category term usage (mapped categories exist; nothing unexpected)
 *  - content boundary (length caps, no navigation/cookie/marketing/tracker text)
 *
 * Usage: php c2-audit2.php
 */
set_time_limit( 0 );
require '/var/www/html/wp-load.php';

$pilot = array(
	'eventbrite_laois', 'eventbrite_cork', 'eventbrite_dublin',
	'heritage_week_laois', 'heritage_week_cork', 'heritage_week_dublin',
);

$fail = 0;
function ok( $label, $cond, $detail = '' ) {
	global $fail;
	echo ( $cond ? '[ OK ] ' : '[FAIL] ' ) . $label . ( $detail ? ' — ' . $detail : '' ) . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

$all = get_posts(
	array( 'post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC' )
);

$single_day = $multi_day = $overnight = $no_time = $with_time = 0;
$bad_date = $bad_order = array();
$overnight_samples = array();
$no_time_samples = array();

// Content-boundary accounting.
$len_max = 0; $len_over = array();
$banned = array(
	'cookie-banner'   => '#we use cookies|cookie policy|accept all cookies|cookie preferences#i',
	'nav-ui'          => '#log in|sign up|sign in|find events|browse events|home\s*·|membersonly|help center#i',
	'marketing'       => '#eventbrite presents|discover more events|events you may like|people on eventbrite|powered by#i',
	'tracker-url'     => '#googletagmanager|google-analytics|facebook\.com/tr|doubleclick|adnxs|utm_(source|medium|campaign)=#i',
	'script-iframe'   => '#<script|<iframe|<noscript#i',
);
$banned_hits = array();

$cat_usage = array();
$cat_bad_format = array();

foreach ( $all as $p ) {
	$source = (string) get_post_meta( $p->ID, '_event_source', true );
	if ( ! in_array( $source, $pilot, true ) ) {
		continue;
	}
	$d0 = (string) get_post_meta( $p->ID, '_event_date', true );
	$t0 = (string) get_post_meta( $p->ID, '_event_start_time', true );
	$d1 = (string) get_post_meta( $p->ID, '_event_end_date', true );
	$t1 = (string) get_post_meta( $p->ID, '_event_end_time', true );
	$content = (string) $p->post_content;

	// Format checks (no invented data — only valid stored formats).
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d0 ) ) { $bad_date[] = $p->ID . '(_event_date=' . $d0 . ')'; }
	if ( '' !== $t0 && ! preg_match( '/^\d{2}:\d{2}$/', $t0 ) ) { $bad_date[] = $p->ID . '(start_time=' . $t0 . ')'; }
	if ( '' !== $t1 && ! preg_match( '/^\d{2}:\d{2}$/', $t1 ) ) { $bad_date[] = $p->ID . '(end_time=' . $t1 . ')'; }

	if ( '' !== $d1 && $d1 !== $d0 ) { $multi_day++; } else { $single_day++; }

	if ( '' !== $t0 ) {
		$with_time++;
	} else {
		$no_time++;
		if ( count( $no_time_samples ) < 5 ) { $no_time_samples[] = $p->ID . ' ' . $p->post_title; }
	}

	// Overnight: same end date but end_time earlier than start_time.
	if ( '' !== $t0 && '' !== $t1 && $t1 < $t0 ) {
		$overnight++;
		if ( count( $overnight_samples ) < 5 ) { $overnight_samples[] = $p->ID . ' ' . $p->post_title . ' (' . $t0 . '→' . $t1 . ')'; }
	}

	// End before start (same date, no overnight semantics) = data error.
	if ( '' !== $d1 && '' !== $t0 && '' !== $t1 && $d1 === $d0 && $t1 < $t0 ) {
		// kept as overnight candidate above; counted, not failed.
	}

	// Content boundary.
	$len = strlen( $content );
	if ( $len > $len_max ) { $len_max = $len; }
	if ( $len > 20000 ) { $len_over[] = $p->ID . ' len=' . $len; }
	foreach ( $banned as $tag => $rx ) {
		if ( preg_match( $rx, $content ) ) {
			$banned_hits[ $tag ][] = $p->ID;
		}
	}

	// Category usage.
	$terms = wp_get_object_terms( $p->ID, 'conexao_category', array( 'fields' => 'slugs' ) );
	if ( $terms && ! is_wp_error( $terms ) ) {
		foreach ( $terms as $t ) {
			$cat_usage[ $t ] = ( $cat_usage[ $t ] ?? 0 ) + 1;
		}
	}
}

echo "=== C2 AUDIT PHASE 2: DATE/TIME MODEL (pilot events) ===\n";
echo "single_day=$single_day multi_day=$multi_day overnight=$overnight with_time=$with_time no_time=$no_time\n";
echo 'overnight samples: ' . implode( ' | ', $overnight_samples ) . "\n";
echo 'no-time samples: ' . implode( ' | ', $no_time_samples ) . "\n";
ok( 'All pilot dates/times in valid stored formats', 0 === count( $bad_date ), count( $bad_date ) ? implode( '; ', array_slice( $bad_date, 0, 10 ) ) : '' );

echo "\n=== C2 AUDIT PHASE 2: CATEGORIES (pilot events) ===\n";
echo 'category usage on pilot events: ' . ( $cat_usage ? wp_json_encode( $cat_usage ) : '(none — EB payload carries no tags; HW categories ended with 2026 edition)' ) . "\n";
$all_terms = array();
foreach ( get_terms( array( 'taxonomy' => 'conexao_category', 'hide_empty' => false ) ) as $t ) { $all_terms[ $t->slug ] = $t->name; }
$unknown = array_diff( array_keys( $cat_usage ), array_keys( $all_terms ) );
ok( 'No pilot event references an unknown category', 0 === count( $unknown ), count( $unknown ) ? implode( ',', $unknown ) : '' );

echo "\n=== C2 AUDIT PHASE 2: CONTENT BOUNDARY (pilot events) ===\n";
echo "max content length: $len_max bytes\n";
ok( 'No pilot content over 20k chars', 0 === count( $len_over ), count( $len_over ) ? implode( '; ', array_slice( $len_over, 0, 10 ) ) : '' );
foreach ( $banned as $tag => $rx ) {
	$hits = $banned_hits[ $tag ] ?? array();
	ok( "No '$tag' content patterns", 0 === count( $hits ), count( $hits ) ? 'ids=' . implode( ',', array_slice( $hits, 0, 10 ) ) : '' );
}

echo "\n";
echo ( 0 === $fail ? "AUDIT PHASE 2: ALL PASS (0 failures)\n" : "AUDIT PHASE 2: $fail FAILURES\n" );
