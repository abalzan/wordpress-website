<?php
/**
 * Commemorative resolution suite (architecture proposal r10 §6, §9.2, §9.3).
 *
 * Creates temporary `commemorative_event` records and deletes them at the end.
 * Every assertion calls the REAL engine with an injected clock; nothing depends
 * on the real date and nothing writes to an existing record.
 *
 * @package Conexao_Commemorative
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$ce_res_posts = array();

/**
 * Create a temporary commemorative event.
 *
 * @param array $args Overrides.
 * @return int Post ID.
 */
function ce_res_create( array $args ) {
	$defaults = array(
		'event_key'    => 'event_' . wp_generate_password( 6, false ),
		'rule'         => 'fixed_annual',
		'date'         => '10-31',
		'lead_in_days' => '15',
		'priority'     => '10',
		'enabled'      => '1',
		'presentation' => 'halloween',
		'status'       => 'publish',
		'precedence'   => array(),
	);

	$args = array_merge( $defaults, $args );

	$post_id = wp_insert_post(
		array(
			'post_type'   => 'commemorative_event',
			'post_status' => $args['status'],
			'post_title'  => '[TEST] Commemorative ' . $args['event_key'],
		)
	);

	if ( is_wp_error( $post_id ) || ! $post_id ) {
		return 0;
	}

	update_post_meta( $post_id, '_ce_event_key', $args['event_key'] );
	update_post_meta( $post_id, '_ce_rule', $args['rule'] );
	update_post_meta( $post_id, '_ce_date', $args['date'] );
	update_post_meta( $post_id, '_ce_lead_in_days', $args['lead_in_days'] );
	update_post_meta( $post_id, '_ce_priority', $args['priority'] );
	update_post_meta( $post_id, '_ce_enabled', $args['enabled'] );
	update_post_meta( $post_id, '_ce_presentation', $args['presentation'] );

	if ( ! empty( $args['precedence'] ) ) {
		update_post_meta( $post_id, '_ce_year_precedence', $args['precedence'] );
	}

	$GLOBALS['ce_res_posts'][] = (int) $post_id;

	Conexao_Commemorative_Repository::reset_cache();

	return (int) $post_id;
}

/**
 * Resolve at a fixed Dublin instant.
 *
 * @param string $instant `Y-m-d H:i:s`.
 * @return array
 */
function ce_res_clock( $instant ) {
	return new DateTimeImmutable( $instant, new DateTimeZone( 'Europe/Dublin' ) );
}

/**
 * Resolve at a fixed Dublin instant.
 *
 * @param string $instant `Y-m-d H:i:s`.
 * @return array
 */
function ce_res_active( $instant ) {
	return Conexao_Commemorative_Resolver::active( ce_res_clock( $instant ) );
}

/**
 * Delete every temporary record created by this suite and reset the cache.
 *
 * @return void
 */
function ce_res_reset() {
	foreach ( $GLOBALS['ce_res_posts'] as $post_id ) {
		wp_delete_post( $post_id, true );
	}

	$GLOBALS['ce_res_posts'] = array();
	Conexao_Commemorative_Repository::reset_cache();
}

/**
 * Whether a specific record is the selected one.
 *
 * The suite never assumes an empty database: other committed events may be
 * eligible at the injected instant, so "not selected" is asserted per record.
 *
 * @param array  $resolution Resolution array.
 * @param string $event_key  Event key.
 * @return bool
 */
function ce_res_selected( array $resolution, $event_key ) {
	return isset( $resolution['event']['event']['event_key'] ) && $event_key === $resolution['event']['event']['event_key'];
}

test_section( 'Priority decides between two eligible events' );

ce_res_create(
	array(
		'event_key' => 'low_priority_event',
		'priority'  => '20',
	)
);
ce_res_create(
	array(
		'event_key' => 'high_priority_event',
		'priority'  => '40',
	)
);

$resolution = ce_res_active( '2026-10-20 12:00:00' );

assert_true( is_array( $resolution['event'] ), 'an eligible event is selected' );
assert_equals( 'high_priority_event', $resolution['event']['event']['event_key'], 'the higher global priority wins' );
assert_true( count( $resolution['superseded'] ) >= 1, 'the lower-priority event is recorded as superseded' );
$ce_res_superseded_keys = array_map( static function ( $row ) { return $row['event']['event_key']; }, $resolution['superseded'] );
assert_true( in_array( 'low_priority_event', $ce_res_superseded_keys, true ), 'the lower-priority event is recorded as superseded' );
assert_contains( 'superseded by "high_priority_event"', $resolution['superseded'][0]['reason'], 'the superseded reason names the winner' );

test_section( 'Equal priority: `_ce_event_key` ascending wins (never array order)' );

ce_res_reset();

ce_res_create(
	array(
		'event_key' => 'zzz_later_key',
		'priority'  => '50',
	)
);
ce_res_create(
	array(
		'event_key' => 'aaa_earlier_key',
		'priority'  => '50',
	)
);

$tie = ce_res_active( '2026-10-20 12:00:00' );
assert_equals( 'aaa_earlier_key', $tie['event']['event']['event_key'], 'equal priority resolves on the event key, ascending' );

test_section( 'Manual annual precedence (r10 §5.7)' );

ce_res_reset();

// 2026 is the occurrence year of the Halloween window resolved at 2026-10-20.
ce_res_create(
	array(
		'event_key'  => 'preferred_low',
		'priority'   => '10',
		'precedence' => array( '2026' => array( 'preferred' => true, 'note' => 'Prefer this one in 2026.' ) ),
	)
);
ce_res_create(
	array(
		'event_key' => 'global_high',
		'priority'  => '90',
	)
);

$preferred_winner = ce_res_active( '2026-10-20 12:00:00' );
assert_equals( 'preferred_low', $preferred_winner['event']['event']['event_key'], 'a preferred event beats a higher global priority' );
assert_contains( 'manual preference', $preferred_winner['superseded'][0]['reason'], 'the superseded reason records that manual preference decided it' );

$next_year_resolution = ce_res_active( '2027-10-20 12:00:00' );
assert_equals( 'global_high', $next_year_resolution['event']['event']['event_key'], 'the 2026 preference does not apply to the 2027 occurrence year' );

test_section( 'Two events preferred for the same year: priority, then key' );

ce_res_reset();

ce_res_create(
	array(
		'event_key'  => 'preferred_alpha',
		'priority'   => '30',
		'precedence' => array( '2026' => array( 'preferred' => true ) ),
	)
);
ce_res_create(
	array(
		'event_key'  => 'preferred_beta',
		'priority'   => '30',
		'precedence' => array( '2026' => array( 'preferred' => true ) ),
	)
);

$conflict = ce_res_active( '2026-10-20 12:00:00' );
assert_equals( 'preferred_alpha', $conflict['event']['event']['event_key'], 'equal preference + equal priority resolves on the key' );
assert_set( $conflict['preferred_conflicts'], '2026', 'the resolution reports the preferred-flag conflict for 2026' );
assert_equals( array( 'preferred_alpha', 'preferred_beta' ), $conflict['preferred_conflicts']['2026'], 'the conflict lists both marked keys, sorted' );

$admin_conflicts = Conexao_Commemorative_Admin::preferred_flag_conflicts();
assert_set( $admin_conflicts, '2026', 'the admin warning counts the same conflict (flags only)' );

test_section( 'Preference cannot resurrect an ineligible event' );

ce_res_reset();

ce_res_create(
	array(
		'event_key'  => 'preferred_but_disabled',
		'priority'   => '99',
		'enabled'    => '0',
		'precedence' => array( '2026' => array( 'preferred' => true ) ),
	)
);

$disabled_resolution = ce_res_active( '2026-10-20 12:00:00' );
assert_true( ! ce_res_selected( $disabled_resolution, 'preferred_but_disabled' ), 'a preferred but disabled event is never selected' );

$ce_res_draft = ce_res_create(
	array(
		'event_key'  => 'preferred_but_draft',
		'priority'   => '99',
		'status'     => 'draft',
		'precedence' => array( '2026' => array( 'preferred' => true ) ),
	)
);

$draft_resolution = ce_res_active( '2026-10-20 12:00:00' );
assert_true( ! ce_res_selected( $draft_resolution, 'preferred_but_draft' ), 'a preferred but unpublished event is never selected' );

$draft_overview = conexao_commemorative_admin_overview( $ce_res_draft, ce_res_clock( '2026-10-20 12:00:00' ) );
assert_equals( Conexao_Commemorative_Resolver::REASON_DRAFT, $draft_overview['reason'], 'the admin overview explains the draft state' );

$ce_res_outside = ce_res_create(
	array(
		'event_key'  => 'preferred_but_outside',
		'priority'   => '99',
		'date'       => '12-24',
		'precedence' => array( '2026' => array( 'preferred' => true ) ),
	)
);

$outside_resolution = ce_res_active( '2026-10-20 12:00:00' );
assert_true( ! ce_res_selected( $outside_resolution, 'preferred_but_outside' ), 'a preferred event outside its window is never selected' );

$outside_overview = conexao_commemorative_admin_overview( $ce_res_outside, ce_res_clock( '2026-10-20 12:00:00' ) );
assert_equals( 2026, $outside_overview['next_year'], 'the admin overview reports the next occurrence year' );
assert_equals( '2026-12-09 → 2026-12-24', $outside_overview['window_label'], 'the admin overview reports the next computed window (lead-in applied)' );

test_section( 'Unknown experience package is never displayed' );

ce_res_reset();

$ce_res_unknown = ce_res_create(
	array(
		'event_key'    => 'unknown_package_event',
		'presentation' => 'a_package_the_theme_does_not_register',
	)
);

$unknown_resolution = ce_res_active( '2026-10-20 12:00:00' );
assert_true( ! ce_res_selected( $unknown_resolution, 'unknown_package_event' ), 'an unregistered package is never selected' );

$unknown_overview = conexao_commemorative_admin_overview( $ce_res_unknown, ce_res_clock( '2026-10-20 12:00:00' ) );
assert_equals( Conexao_Commemorative_Resolver::REASON_UNKNOWN_PACKAGE, $unknown_overview['reason'], 'the admin overview explains the unknown package' );

test_section( 'A record without an identity is never selected' );

ce_res_reset();

$ce_res_no_key = ce_res_create(
	array(
		'event_key' => '',
	)
);

$no_identity = ce_res_active( '2026-10-20 12:00:00' );
assert_true( ! ce_res_selected( $no_identity, '' ), 'a record without `_ce_event_key` is never selected' );

$no_identity_overview = conexao_commemorative_admin_overview( $ce_res_no_key, ce_res_clock( '2026-10-20 12:00:00' ) );
assert_equals( Conexao_Commemorative_Resolver::REASON_NO_IDENTITY, $no_identity_overview['reason'], 'the admin overview explains the missing identity' );

test_section( 'New Year preference follows the occurrence year across midnight' );

ce_res_reset();

ce_res_create(
	array(
		'event_key'    => 'new_year_preferred',
		'rule'         => 'window',
		'date'         => '',
		'lead_in_days' => '0',
		'priority'     => '5',
		'presentation' => 'new_year',
		'precedence'   => array( '2027' => array( 'preferred' => true ) ),
	)
);
ce_res_create(
	array(
		'event_key'    => 'new_year_unmarked',
		'rule'         => 'window',
		'date'         => '',
		'lead_in_days' => '0',
		'priority'     => '80',
		'presentation' => 'new_year',
	)
);

// The window fields are not part of ce_res_create's defaults: set them explicitly.
foreach ( $GLOBALS['ce_res_posts'] as $ce_res_id ) {
	update_post_meta( $ce_res_id, '_ce_window_start', '12-26' );
	update_post_meta( $ce_res_id, '_ce_window_end', '01-01' );
}
Conexao_Commemorative_Repository::reset_cache();

$after_midnight = ce_res_active( '2027-01-01 00:30:00' );
assert_equals( 'new_year_preferred', $after_midnight['event']['event']['event_key'], 'the 2027 preference applies after midnight on 1 January (occurrence year 2027)' );
assert_equals( 2027, $after_midnight['event']['occurrence_year'], 'the resolved occurrence year is 2027 after midnight' );

$before_window = ce_res_active( '2026-12-01 12:00:00' );
assert_true( null === $before_window['event'], 'outside the window nothing is displayed, preference or not' );

test_section( 'Cleanup' );

ce_res_reset();

$leftovers = get_posts(
	array(
		'post_type'      => 'commemorative_event',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'lang'           => '',
		's'              => '[TEST] Commemorative',
	)
);

assert_true( empty( $leftovers ), 'no temporary commemorative record remains after the suite' );

test_finish( 'commemorative-resolution' );
