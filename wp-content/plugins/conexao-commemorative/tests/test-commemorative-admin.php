<?php
/**
 * Commemorative administration suite (r10 §5.2, §5.7, §9.3).
 *
 * Proves the wp-admin contract with REAL calls:
 *   - the admin UX config declares the commemorative list columns and sections,
 *     and the list renderer asks the ENGINE for their values;
 *   - the "Janela calculada" column shows the NEXT occurrence (never the current
 *     calendar year);
 *   - the annual-precedence control writes `_ce_year_precedence[year]` only for
 *     the selected year, removes ONLY that year when cleared, sanitises and
 *     truncates the note, and refuses a year outside the validated range;
 *   - an invalid date submission (`02-29`) is rejected with a clear warning and
 *     never stored;
 *   - a nonce or capability failure writes nothing.
 *
 * @package Conexao_Commemorative
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$GLOBALS['ce_admin_posts'] = array();

/**
 * Create a temporary event and return its ID.
 *
 * @param string $key  Event key.
 * @param array  $meta Extra meta.
 * @return int
 */
function ce_admin_create( $key, array $meta = array() ) {
	$post_id = wp_insert_post(
		array(
			'post_type'   => 'commemorative_event',
			'post_status' => 'publish',
			'post_title'  => '[TEST] Admin ' . $key,
		)
	);

	update_post_meta( $post_id, '_ce_event_key', $key );
	update_post_meta( $post_id, '_ce_rule', 'fixed_annual' );
	update_post_meta( $post_id, '_ce_date', '12-24' );
	update_post_meta( $post_id, '_ce_lead_in_days', '15' );
	update_post_meta( $post_id, '_ce_priority', '10' );
	update_post_meta( $post_id, '_ce_enabled', '1' );
	update_post_meta( $post_id, '_ce_presentation', 'christmas' );

	foreach ( $meta as $key_name => $value ) {
		update_post_meta( $post_id, $key_name, $value );
	}

	$GLOBALS['ce_admin_posts'][] = (int) $post_id;
	Conexao_Commemorative_Repository::reset_cache();

	return (int) $post_id;
}

/**
 * Switch to an administrator and return the ID.
 *
 * @return int
 */
function ce_admin_login() {
	$admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);

	if ( empty( $admins ) ) {
		return 0;
	}

	wp_set_current_user( (int) $admins[0] );

	return (int) $admins[0];
}

/**
 * Submit the precedence control.
 *
 * @param int    $post_id   Post ID.
 * @param string $year      Occurrence year.
 * @param bool   $preferred Whether the box is checked.
 * @param string $note      Note.
 * @param bool   $valid_nonce Whether to send a valid nonce.
 * @return void
 */
function ce_admin_submit_precedence( $post_id, $year, $preferred, $note, $valid_nonce = true ) {
	$_POST = array(
		'conexao_commemorative_precedence_nonce' => $valid_nonce ? wp_create_nonce( 'conexao_commemorative_precedence' ) : 'invalid',
		'ce_precedence_year'                     => (string) $year,
		'ce_precedence_preferred'                => $preferred ? '1' : '',
		'ce_precedence_note'                     => (string) $note,
	);

	Conexao_Commemorative_Admin::save_precedence( $post_id );

	$_POST = array();
	Conexao_Commemorative_Repository::reset_cache();
}

test_section( 'The admin UX config declares the commemorative list and editor' );

$ce_admin_config = Conexao_Admin_Ux_Config::get( 'commemorative_event' );

assert_true( is_array( $ce_admin_config ), 'the admin UX has a config entry for the commemorative event type' );
assert_true( in_array( 'commemorative_event', Conexao_Admin_Ux_Config::SUPPORTED_TYPES, true ), 'the post type is in SUPPORTED_TYPES (the list + editor are wired)' );

$ce_admin_columns = isset( $ce_admin_config['columns'] ) ? $ce_admin_config['columns'] : array();
assert_set( $ce_admin_columns, 'state', 'the list declares the Estado column' );
assert_set( $ce_admin_columns, 'window', 'the list declares the Janela calculada column' );
assert_set( $ce_admin_columns, 'eligible', 'the list declares the Elegível agora? column' );
assert_set( $ce_admin_columns, 'reason', 'the list declares the Motivo column' );
assert_set( $ce_admin_columns, 'priority', 'the list declares the Prioridade column' );
assert_equals( 'commemorative_window', $ce_admin_columns['window']['render'], 'the window column is rendered by the engine' );

$ce_admin_sections = isset( $ce_admin_config['sections'] ) ? $ce_admin_config['sections'] : array();
assert_set( $ce_admin_sections, 'informacao', 'the editor has the Informação section' );
assert_set( $ce_admin_sections, 'agendamento', 'the editor has the Agendamento section' );
assert_set( $ce_admin_sections, 'apresentacao', 'the editor has the Apresentação section' );

test_section( 'The engine answers the list columns (next occurrence, never the calendar year)' );

$ce_admin_admin = ce_admin_login();
assert_true( $ce_admin_admin > 0, 'an administrator account exists locally' );

// A one-off far in the future keeps these assertions independent of the run date.
$ce_admin_future = ce_admin_create(
	'admin_future_event',
	array(
		'_ce_rule'       => 'one_off',
		'_ce_date'       => '',
		'_ce_one_off_date' => '2099-01-01',
		'_ce_lead_in_days' => '15',
	)
);

$ce_admin_window_html = Conexao_Commemorative_Admin::render_column( null, 'commemorative_window', $ce_admin_future, 'commemorative_event', array() );
assert_contains( '2098-12-17 → 2099-01-01', (string) $ce_admin_window_html, 'the window column shows the next occurrence window' );
assert_contains( 'ano de ocorrência 2099', (string) $ce_admin_window_html, 'the window column shows the OCCURRENCE year' );

$ce_admin_priority_html = Conexao_Commemorative_Admin::render_column( null, 'commemorative_priority', $ce_admin_future, 'commemorative_event', array() );
assert_contains( '10', (string) $ce_admin_priority_html, 'the priority column shows the global priority' );

$ce_admin_other_type = Conexao_Commemorative_Admin::render_column( null, 'commemorative_window', $ce_admin_future, 'event', array() );
assert_true( null === $ce_admin_other_type, 'the engine ignores columns for other post types' );

test_section( 'The admin list filter and the diagnostics meta box render' );

// A CLI run is not an admin screen, so the admin component must be booted
// explicitly before its hooks exist.
Conexao_Commemorative_Admin::boot();

$ce_admin_filtered = apply_filters( 'conexao_admin_ux_render_column', null, 'commemorative_window', $ce_admin_future, 'commemorative_event', array() );
assert_true( null !== $ce_admin_filtered, 'the admin UX column filter is wired to the engine' );
assert_contains( '2099', (string) $ce_admin_filtered, 'the filtered column carries the computed window' );

ob_start();
Conexao_Commemorative_Admin::render_meta_box( get_post( $ce_admin_future ) );
$ce_admin_meta_box = (string) ob_get_clean();

assert_contains( 'Janela calculada', $ce_admin_meta_box, 'the diagnostics meta box renders the computed window' );
assert_contains( 'Preferir este evento', $ce_admin_meta_box, 'the diagnostics meta box renders the annual-precedence control' );
assert_contains( 'Simulação de data', $ce_admin_meta_box, 'the diagnostics meta box renders the read-only date simulation' );
assert_contains( 'conexao_commemorative_precedence_nonce', $ce_admin_meta_box, 'the precedence control carries its own nonce' );


test_section( 'The annual-precedence control writes only the selected year' );

$ce_admin_event = ce_admin_create(
	'admin_precedence_event',
	array( '_ce_year_precedence' => array( '2026' => array( 'preferred' => true, 'note' => 'Existing 2026 note' ) ) )
);

$ce_admin_year = (string) ( (int) current_time( 'Y' ) + 1 );

ce_admin_submit_precedence( $ce_admin_event, $ce_admin_year, true, 'Prefer for the overlap.' );

$ce_admin_map = Conexao_Data_Model_Commemorative::decode_year_precedence( get_post_meta( $ce_admin_event, '_ce_year_precedence', true ) );

assert_set( $ce_admin_map, $ce_admin_year, 'the selected occurrence year is stored' );
assert_equals( true, $ce_admin_map[ $ce_admin_year ]['preferred'], 'the checked box stores preferred = true' );
assert_equals( 'Prefer for the overlap.', $ce_admin_map[ $ce_admin_year ]['note'], 'the note is stored' );
assert_set( $ce_admin_map, '2026', 'a preference for another year is left untouched' );
assert_equals( 'Existing 2026 note', $ce_admin_map['2026']['note'], 'the other year keeps its own note' );

test_section( 'Clearing the box removes ONLY that year' );

ce_admin_submit_precedence( $ce_admin_event, $ce_admin_year, false, '' );

$ce_admin_cleared = Conexao_Data_Model_Commemorative::decode_year_precedence( get_post_meta( $ce_admin_event, '_ce_year_precedence', true ) );

assert_not_set( $ce_admin_cleared, $ce_admin_year, 'clearing removes the selected year' );
assert_set( $ce_admin_cleared, '2026', 'clearing leaves the other year in place' );

test_section( 'The note is sanitised and truncated to 200 characters' );

ce_admin_submit_precedence( $ce_admin_event, $ce_admin_year, true, str_repeat( 'a', 260 ) . '<script>alert(1)</script>' );

$ce_admin_truncated = Conexao_Data_Model_Commemorative::decode_year_precedence( get_post_meta( $ce_admin_event, '_ce_year_precedence', true ) );

assert_equals( 200, strlen( $ce_admin_truncated[ $ce_admin_year ]['note'] ), 'the note is truncated to 200 characters' );
assert_not_contains( '<script>', $ce_admin_truncated[ $ce_admin_year ]['note'], 'the note cannot carry markup' );

test_section( 'A year outside Y-2..Y+5 is refused with a warning' );

delete_option( 'conexao_commemorative_warnings_' . $ce_admin_event );
update_post_meta( $ce_admin_event, '_ce_year_precedence', array() );

ce_admin_submit_precedence( $ce_admin_event, '1999', true, 'Ancient preference' );

$ce_admin_invalid_map = Conexao_Data_Model_Commemorative::decode_year_precedence( get_post_meta( $ce_admin_event, '_ce_year_precedence', true ) );
$ce_admin_warnings    = get_option( 'conexao_commemorative_warnings_' . $ce_admin_event, array() );

assert_not_set( $ce_admin_invalid_map, '1999', 'an out-of-range year is never stored' );
assert_true( ! empty( $ce_admin_warnings ), 'an out-of-range year produces a clear admin warning' );
assert_contains( 'fora do intervalo', implode( ' ', (array) $ce_admin_warnings ), 'the warning explains the accepted range' );

delete_option( 'conexao_commemorative_warnings_' . $ce_admin_event );

test_section( 'A rejected date (02-29) leaves the stored value empty and warns' );

$_POST = array(
	'conexao_commemorative_precedence_nonce' => wp_create_nonce( 'conexao_commemorative_precedence' ),
	'ce_precedence_year'                     => $ce_admin_year,
	'conexao_fields'                         => array( 'ce_date' => '02-29' ),
);

Conexao_Commemorative_Admin::save_precedence( $ce_admin_event );
$_POST = array();

$ce_admin_date_warnings = get_option( 'conexao_commemorative_warnings_' . $ce_admin_event, array() );
assert_true( ! empty( $ce_admin_date_warnings ), 'a rejected 02-29 submission produces an admin warning' );
assert_contains( '29 de fevereiro', implode( ' ', (array) $ce_admin_date_warnings ), 'the warning explains why 02-29 is rejected' );
update_post_meta( $ce_admin_event, '_ce_date', '02-29' );
assert_equals( '', get_post_meta( $ce_admin_event, '_ce_date', true ), 'the write path stores an empty date instead of coercing 02-29' );
assert_equals( '', Conexao_Data_Model_Commemorative::sanitize_month_day( '02-29' ), 'the sanitiser rejects 02-29 outright' );

delete_option( 'conexao_commemorative_warnings_' . $ce_admin_event );

test_section( 'A nonce failure writes nothing' );

update_post_meta( $ce_admin_event, '_ce_year_precedence', array() );

ce_admin_submit_precedence( $ce_admin_event, $ce_admin_year, true, 'Should not be stored', false );

$ce_admin_nonce_map = Conexao_Data_Model_Commemorative::decode_year_precedence( get_post_meta( $ce_admin_event, '_ce_year_precedence', true ) );
assert_true( empty( $ce_admin_nonce_map ), 'an invalid nonce writes nothing' );

test_section( 'A capability failure writes nothing' );

wp_set_current_user( 0 );
ce_admin_submit_precedence( $ce_admin_event, $ce_admin_year, true, 'Should not be stored either' );

$ce_admin_cap_map = Conexao_Data_Model_Commemorative::decode_year_precedence( get_post_meta( $ce_admin_event, '_ce_year_precedence', true ) );
assert_true( empty( $ce_admin_cap_map ), 'a caller without edit_post writes nothing' );

test_section( 'Cleanup' );

wp_set_current_user( $ce_admin_admin );

foreach ( $GLOBALS['ce_admin_posts'] as $ce_admin_post_id ) {
	delete_option( 'conexao_commemorative_warnings_' . $ce_admin_post_id );
	wp_delete_post( $ce_admin_post_id, true );
}

Conexao_Commemorative_Repository::reset_cache();

$ce_admin_remaining = get_posts(
	array(
		'post_type'      => 'commemorative_event',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'lang'           => '',
		's'              => '[TEST] Admin',
	)
);

assert_true( empty( $ce_admin_remaining ), 'no temporary commemorative record remains after the suite' );

test_finish( 'commemorative-admin' );

