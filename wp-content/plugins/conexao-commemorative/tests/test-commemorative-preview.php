<?php
/**
 * Commemorative preview suite (r10 §5.4, §9.3).
 *
 * Proves the CONJUNCTIVE authorisation with REAL capability checks:
 *   logged in AND `manage_options` AND `edit_post` on the record AND a valid
 *   nonce for that `event_key`. Every failure is silent (no error oracle).
 *
 * Also proves: the link carries the stable `event_key` (never a slug) plus a
 * unique cache-busting token; an honoured preview bypasses caches
 * (`DONOTCACHEPAGE`); published-but-disabled, draft and out-of-window events are
 * previewable; and a preview changes NOTHING persisted.
 *
 * @package Conexao_Commemorative
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$GLOBALS['ce_prev_posts'] = array();

/**
 * Create a temporary event.
 *
 * @param string $key  Event key.
 * @param array  $args Meta overrides.
 * @return int
 */
function ce_prev_create( $key, array $args = array() ) {
	$status = isset( $args['status'] ) ? $args['status'] : 'publish';

	$post_id = wp_insert_post(
		array(
			'post_type'   => 'commemorative_event',
			'post_status' => $status,
			'post_title'  => '[TEST] Preview ' . $key,
		)
	);

	update_post_meta( $post_id, '_ce_event_key', $key );
	update_post_meta( $post_id, '_ce_rule', isset( $args['rule'] ) ? $args['rule'] : 'fixed_annual' );
	update_post_meta( $post_id, '_ce_date', isset( $args['date'] ) ? $args['date'] : '12-24' );
	update_post_meta( $post_id, '_ce_lead_in_days', '15' );
	update_post_meta( $post_id, '_ce_priority', '10' );
	update_post_meta( $post_id, '_ce_enabled', isset( $args['enabled'] ) ? $args['enabled'] : '1' );
	update_post_meta( $post_id, '_ce_presentation', 'christmas' );

	$GLOBALS['ce_prev_posts'][] = (int) $post_id;
	Conexao_Commemorative_Repository::reset_cache();

	return (int) $post_id;
}

/**
 * Simulate a front-end preview request.
 *
 * @param string $event_key Key.
 * @param string $nonce     Nonce ('' = omit).
 * @return array|null
 */
function ce_prev_request( $event_key, $nonce ) {
	$_GET = array( 'conexao_preview' => $event_key );

	if ( '' !== $nonce ) {
		$_GET['_wpnonce'] = $nonce;
	}

	Conexao_Commemorative_Preview::reset();
	Conexao_Commemorative_Repository::reset_cache();

	$result = Conexao_Commemorative_Preview::requested();

	$_GET = array();
	Conexao_Commemorative_Preview::reset();

	return $result;
}

/**
 * An administrator ID.
 *
 * @return int
 */
function ce_prev_admin() {
	$admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);

	return empty( $admins ) ? 0 : (int) $admins[0];
}

test_section( 'The preview link carries the event key, a nonce and a cache-busting token' );

$ce_prev_admin_id = ce_prev_admin();
assert_true( $ce_prev_admin_id > 0, 'an administrator account exists locally' );

wp_set_current_user( $ce_prev_admin_id );

$ce_prev_event = ce_prev_create( 'preview_target' );
$ce_prev_link  = Conexao_Commemorative_Preview::link( $ce_prev_event );

assert_contains( 'conexao_preview=preview_target', $ce_prev_link, 'the link carries the stable event_key' );
assert_contains( '_wpnonce=', $ce_prev_link, 'the link carries a nonce' );
assert_contains( '_ce_ts=', $ce_prev_link, 'the link carries a unique cache-busting token' );

test_section( 'An unauthenticated visitor cannot preview' );

wp_set_current_user( 0 );

$ce_prev_nonce = wp_create_nonce( 'conexao_commemorative_preview|preview_target' );
assert_true( null === ce_prev_request( 'preview_target', $ce_prev_nonce ), 'a logged-out request is ignored even with a nonce' );

test_section( 'An administrator with a valid nonce previews a published event' );

wp_set_current_user( $ce_prev_admin_id );

$ce_prev_nonce  = wp_create_nonce( 'conexao_commemorative_preview|preview_target' );
$ce_prev_result = ce_prev_request( 'preview_target', $ce_prev_nonce );

assert_true( is_array( $ce_prev_result ), 'an authorised administrator previews the event' );
assert_equals( 'preview_target', $ce_prev_result['event_key'], 'the previewed record is the requested one' );

test_section( 'A bad nonce and an unknown key are ignored' );

wp_set_current_user( $ce_prev_admin_id );

assert_true( null === ce_prev_request( 'preview_target', 'not-a-valid-nonce' ), 'an invalid nonce is ignored silently' );
assert_true( null === ce_prev_request( 'no_such_event_key', $ce_prev_nonce ), 'an unknown event_key is ignored silently' );

test_section( 'manage_options alone is not enough: edit_post is required' );

$ce_prev_limited_user = wp_insert_user(
	array(
		'user_login' => 'ce_preview_limited_' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password( 20 ),
		'user_email' => 'ce-preview-limited-' . wp_generate_password( 6, false ) . '@example.invalid',
		'role'       => 'administrator',
	)
);

assert_true( ! is_wp_error( $ce_prev_limited_user ), 'a second administrator was created for the negative proof' );

$ce_prev_limited_id = (int) $ce_prev_limited_user;

add_filter(
	'map_meta_cap',
	static function ( $caps, $cap, $user_id, $args ) use ( $ce_prev_limited_id, $ce_prev_event ) {
		if ( 'edit_post' === $cap && (int) $user_id === $ce_prev_limited_id && isset( $args[0] ) && (int) $args[0] === $ce_prev_event ) {
			return array( 'do_not_allow' );
		}

		return $caps;
	},
	10,
	4
);

wp_set_current_user( $ce_prev_limited_id );

$ce_prev_nonce = wp_create_nonce( 'conexao_commemorative_preview|preview_target' );
assert_true( null === ce_prev_request( 'preview_target', $ce_prev_nonce ), 'a user who cannot edit the record cannot preview it, even with manage_options' );

test_section( 'Draft, disabled and out-of-window events are previewable by an editor' );

wp_set_current_user( $ce_prev_admin_id );

ce_prev_create( 'preview_draft', array( 'status' => 'draft' ) );
assert_true( is_array( ce_prev_request( 'preview_draft', wp_create_nonce( 'conexao_commemorative_preview|preview_draft' ) ) ), 'a draft event is previewable' );

ce_prev_create( 'preview_disabled', array( 'enabled' => '0' ) );
assert_true( is_array( ce_prev_request( 'preview_disabled', wp_create_nonce( 'conexao_commemorative_preview|preview_disabled' ) ) ), 'a published but disabled event is previewable' );

ce_prev_create( 'preview_far_away', array( 'date' => '07-04' ) );
assert_true( is_array( ce_prev_request( 'preview_far_away', wp_create_nonce( 'conexao_commemorative_preview|preview_far_away' ) ) ), 'an event outside its window is previewable' );

test_section( 'An honoured preview bypasses caches' );

wp_set_current_user( $ce_prev_admin_id );

$_GET = array(
	'conexao_preview' => 'preview_target',
	'_wpnonce'        => wp_create_nonce( 'conexao_commemorative_preview|preview_target' ),
);
Conexao_Commemorative_Preview::reset();

Conexao_Commemorative_Preview::bypass_caches();

assert_true( defined( 'DONOTCACHEPAGE' ), 'an honoured preview defines DONOTCACHEPAGE' );
assert_true( defined( 'DONOTCDN' ), 'an honoured preview defines DONOTCDN' );

$_GET = array();
Conexao_Commemorative_Preview::reset();

test_section( 'A preview changes nothing persisted' );

$ce_prev_before = get_post_meta( $ce_prev_event );

wp_set_current_user( $ce_prev_admin_id );
ce_prev_request( 'preview_target', wp_create_nonce( 'conexao_commemorative_preview|preview_target' ) );

$ce_prev_after = get_post_meta( $ce_prev_event );

assert_equals( $ce_prev_before, $ce_prev_after, 'the stored record is byte-identical before and after a preview' );
assert_equals( 'publish', get_post_status( $ce_prev_event ), 'the preview never changes the post status' );

test_section( 'Cleanup' );

wp_set_current_user( $ce_prev_admin_id );

foreach ( $GLOBALS['ce_prev_posts'] as $ce_prev_post_id ) {
	wp_delete_post( $ce_prev_post_id, true );
}

if ( $ce_prev_limited_id > 0 ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $ce_prev_limited_id );
}

Conexao_Commemorative_Repository::reset_cache();

$ce_prev_remaining = get_posts(
	array(
		'post_type'      => 'commemorative_event',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'lang'           => '',
		's'              => '[TEST] Preview',
	)
);

assert_true( empty( $ce_prev_remaining ), 'no temporary commemorative record remains after the suite' );
assert_true( false === get_user_by( 'id', $ce_prev_limited_id ), 'the temporary administrator was deleted' );

test_finish( 'commemorative-preview' );

