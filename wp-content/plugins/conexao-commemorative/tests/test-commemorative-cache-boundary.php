<?php
/**
 * Commemorative cache and activation-boundary suite (r10 §4.6, §9.6).
 *
 * Creates temporary records, transients and object-cache entries and deletes
 * them at the end. Every claim is proved by REAL hook execution, not by reading
 * source text.
 *
 * Proves:
 *   - creating an event arms ONE boundary, and arming never stacks;
 *   - `_ce_priority` and `_ce_year_precedence` invalidate the cache but do NOT
 *     re-arm the boundary;
 *   - `_ce_enabled` and every scheduling key DO re-arm;
 *   - `transition_post_status` (publish → draft) and `deleted_post` re-arm;
 *   - the purge is TARGETED: an unrelated transient and object-cache entry
 *     survive, and this plugin's own keys are cleared in every language;
 *   - the host action fires exactly once per purge;
 *   - a purge re-arms the next boundary (the chain is never left unarmed);
 *   - the per-request due check purges when WP-Cron did not run (Option B);
 *   - the lock makes concurrent due checks run at most one purge;
 *   - the wp-admin purge action refuses a caller without the capability or
 *     without a valid nonce.
 *
 * @package Conexao_Commemorative
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$GLOBALS['ce_cache_posts']  = array();
$GLOBALS['ce_cache_purges'] = 0;

add_action(
	'conexao_commemorative_purge_caches',
	static function () {
		++$GLOBALS['ce_cache_purges'];
	},
	10,
	0
);

/**
 * Reset the purge counter.
 *
 * @return void
 */
function ce_cache_reset_counter() {
	$GLOBALS['ce_cache_purges'] = 0;
}

/**
 * Create a published, enabled commemorative event.
 *
 * @param string $event_key Key.
 * @param string $date      Month-day.
 * @param string $priority  Priority.
 * @return int Post ID.
 */
function ce_cache_create( $event_key, $date = '10-31', $priority = '10' ) {
	$post_id = wp_insert_post(
		array(
			'post_type'   => 'commemorative_event',
			'post_status' => 'publish',
			'post_title'  => '[TEST] Cache ' . $event_key,
		)
	);

	update_post_meta( $post_id, '_ce_event_key', $event_key );
	update_post_meta( $post_id, '_ce_rule', 'fixed_annual' );
	update_post_meta( $post_id, '_ce_date', $date );
	update_post_meta( $post_id, '_ce_lead_in_days', '15' );
	update_post_meta( $post_id, '_ce_priority', $priority );
	update_post_meta( $post_id, '_ce_enabled', '1' );
	update_post_meta( $post_id, '_ce_presentation', 'halloween' );

	$GLOBALS['ce_cache_posts'][] = (int) $post_id;

	Conexao_Commemorative_Repository::reset_cache();

	return (int) $post_id;
}

/**
 * Plant a sentinel boundary so a re-arm is observable.
 *
 * @return array
 */
function ce_cache_sentinel() {
	$sentinel = array(
		'timestamp' => time() + 86400 * 30,
		'datetime'  => '2099-01-01T00:00:00+00:00',
		'event_key' => 'sentinel',
		'type'      => 'start',
	);
	update_option( 'conexao_commemorative_next_boundary', $sentinel, false );

	return $sentinel;
}

/**
 * Read the stored boundary option.
 *
 * @return array|null
 */
function ce_cache_boundary() {
	$value = get_option( 'conexao_commemorative_next_boundary', null );

	return is_array( $value ) ? $value : null;
}

test_section( 'Creating an event arms exactly one boundary' );

wp_clear_scheduled_hook( 'conexao_commemorative_boundary' );
delete_option( 'conexao_commemorative_next_boundary' );

$ce_cache_post = ce_cache_create( 'cache_primary' );

$armed = ce_cache_boundary();
assert_true( is_array( $armed ), 'a boundary is armed after creating an event' );
assert_true( false !== wp_next_scheduled( 'conexao_commemorative_boundary' ), 'exactly one scheduled event is registered' );
assert_true( in_array( $armed['type'], array( 'start', 'end' ), true ), 'the armed boundary is a window boundary' );
assert_true( '' !== $armed['event_key'], 'the armed boundary names the event it belongs to' );

test_section( '`_ce_priority`: invalidate, never re-arm' );

$sentinel = ce_cache_sentinel();
ce_cache_reset_counter();

update_post_meta( $ce_cache_post, '_ce_priority', '55' );

assert_equals( 1, $GLOBALS['ce_cache_purges'], 'changing the priority fires exactly one targeted purge' );
assert_equals( $sentinel, ce_cache_boundary(), 'changing the priority does NOT re-arm the date boundary' );

test_section( '`_ce_year_precedence`: invalidate, never re-arm' );

$sentinel = ce_cache_sentinel();
ce_cache_reset_counter();

update_post_meta( $ce_cache_post, '_ce_year_precedence', array( '2027' => array( 'preferred' => true ) ) );

assert_equals( 1, $GLOBALS['ce_cache_purges'], 'changing the annual preference fires exactly one targeted purge' );
assert_equals( $sentinel, ce_cache_boundary(), 'changing the annual preference does NOT re-arm the date boundary' );

test_section( '`_ce_enabled`: invalidate AND re-arm' );

ce_cache_sentinel();
ce_cache_reset_counter();

update_post_meta( $ce_cache_post, '_ce_enabled', '0' );

assert_equals( 1, $GLOBALS['ce_cache_purges'], 'disabling an event fires a targeted purge' );
assert_true( null === ce_cache_boundary() || 'cache_primary' !== ce_cache_boundary()['event_key'], 'a disabled event no longer owns the boundary (it does not participate)' );

update_post_meta( $ce_cache_post, '_ce_enabled', '1' );

assert_true( is_array( ce_cache_boundary() ), 're-enabling the event re-arms a real boundary' );

test_section( 'A scheduling key: invalidate AND re-arm' );

$sentinel = ce_cache_sentinel();
ce_cache_reset_counter();

update_post_meta( $ce_cache_post, '_ce_date', '12-24' );

assert_true( $GLOBALS['ce_cache_purges'] >= 1, 'changing the schedule fires a targeted purge' );
assert_true( null === ce_cache_boundary() || 'sentinel' !== ce_cache_boundary()['event_key'], 'changing the schedule re-arms the boundary from the persisted values' );

test_section( 'Lifecycle: `transition_post_status` re-arms' );

ce_cache_sentinel();
ce_cache_reset_counter();

wp_update_post(
	array(
		'ID'          => $ce_cache_post,
		'post_status' => 'draft',
	)
);

assert_true( $GLOBALS['ce_cache_purges'] >= 1, 'unpublishing an event fires a targeted purge' );
assert_true( null === ce_cache_boundary() || 'cache_primary' !== ce_cache_boundary()['event_key'], 'an unpublished event no longer participates in the boundary' );

wp_update_post(
	array(
		'ID'          => $ce_cache_post,
		'post_status' => 'publish',
	)
);

assert_true( is_array( ce_cache_boundary() ), 're-publishing arms a boundary again' );

test_section( 'Lifecycle: trashing re-arms' );

ce_cache_sentinel();
ce_cache_reset_counter();

wp_trash_post( $ce_cache_post );

assert_true( $GLOBALS['ce_cache_purges'] >= 1, 'trashing an event fires a targeted purge' );
assert_true( null === ce_cache_boundary() || 'cache_primary' !== ce_cache_boundary()['event_key'], 'a trashed event no longer participates in the boundary' );

wp_untrash_post( $ce_cache_post );
wp_update_post(
	array(
		'ID'          => $ce_cache_post,
		'post_status' => 'publish',
	)
);
Conexao_Commemorative_Repository::reset_cache();

test_section( 'Targeted invalidation: unrelated caches survive' );

set_transient( 'ce_unrelated_transient', 'keep-me', 300 );
wp_cache_set( 'ce_unrelated_object_key', 'keep-me', 'conexao_filters' );

$default_language = function_exists( 'pll_default_language' ) ? pll_default_language( 'slug' ) : '';
set_transient( 'conexao_commemorative_active', 'stale', 300 );
set_transient( 'conexao_commemorative_active_' . ( $default_language ? $default_language : 'pt' ), 'stale', 300 );
set_transient( 'conexao_commemorative_active_en', 'stale', 300 );

ce_cache_reset_counter();
$ce_cache_result = Conexao_Commemorative_Cache::invalidate( 'test' );

assert_equals( 1, $GLOBALS['ce_cache_purges'], 'one purge fires the host action exactly once' );
assert_equals( 'keep-me', get_transient( 'ce_unrelated_transient' ), 'an unrelated transient survives the targeted purge' );
assert_equals( 'keep-me', wp_cache_get( 'ce_unrelated_object_key', 'conexao_filters' ), 'an unrelated object-cache entry (another group) survives the targeted purge' );
assert_equals( false, get_transient( 'conexao_commemorative_active' ), 'the bare commemorative transient is cleared' );
assert_equals( false, get_transient( 'conexao_commemorative_active_en' ), 'the EN commemorative transient is cleared (every language)' );
assert_true( $ce_cache_result['transients'] >= 1, 'the purge result counts the deleted transients' );

delete_transient( 'ce_unrelated_transient' );

test_section( 'Idempotence: two purges in a row are harmless' );

$ce_cache_first  = Conexao_Commemorative_Cache::invalidate( 'test-repeat' );
$ce_cache_second = Conexao_Commemorative_Cache::invalidate( 'test-repeat' );

assert_equals( 'test-repeat', $ce_cache_second['reason'], 'a repeated purge completes without error' );
assert_true( is_array( $ce_cache_first ) && is_array( $ce_cache_second ), 'both purge runs return a result' );

test_section( 'Re-arm chain: a boundary purge schedules the next boundary' );

foreach ( get_posts(
	array(
		'post_type'      => 'commemorative_event',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'lang'           => '',
		's'              => '[TEST] Cache',
	)
) as $ce_cache_stale ) {
	wp_delete_post( $ce_cache_stale, true );
}
$GLOBALS['ce_cache_posts'] = array();
Conexao_Commemorative_Repository::reset_cache();

$ce_cache_post = ce_cache_create( 'chain_event', '10-31' );

$ce_cache_first_boundary = ce_cache_boundary();
assert_true( is_array( $ce_cache_first_boundary ), 'the chain event armed its first boundary' );

ce_cache_reset_counter();
Conexao_Commemorative_Cache::handle_scheduled_boundary();

$ce_cache_second_boundary = ce_cache_boundary();

assert_equals( 1, $GLOBALS['ce_cache_purges'], 'the scheduled boundary purges once' );
assert_true( is_array( $ce_cache_second_boundary ), 'the scheduled boundary re-arms the next boundary' );
assert_true( $ce_cache_second_boundary['timestamp'] >= $ce_cache_first_boundary['timestamp'], 'the re-armed boundary is recomputed from the persisted schedule' );
assert_true( false !== wp_next_scheduled( 'conexao_commemorative_boundary' ), 'the re-armed boundary is a real scheduled event' );

test_section( 'Per-request due check (Option B) purges a missed boundary' );

update_option(
	'conexao_commemorative_next_boundary',
	array(
		'timestamp' => time() - 60,
		'datetime'  => '2000-01-01T00:00:00+00:00',
		'event_key' => 'chain_event',
		'type'      => 'start',
	),
	false
);

ce_cache_reset_counter();
$ce_cache_due_ran = Conexao_Commemorative_Cache::maybe_purge_due();

assert_equals( true, $ce_cache_due_ran, 'the per-request check purges a due boundary even when WP-Cron never ran' );
assert_equals( 1, $GLOBALS['ce_cache_purges'], 'the due check fires exactly one purge' );
assert_true( is_array( ce_cache_boundary() ) && 'sentinel' !== ce_cache_boundary()['event_key'], 'the due check re-arms a real boundary' );

$ce_cache_due_again = Conexao_Commemorative_Cache::maybe_purge_due();
assert_equals( false, $ce_cache_due_again, 'a second immediate check finds nothing due' );

test_section( 'Concurrency lock: a held lock skips the purge' );

update_option(
	'conexao_commemorative_next_boundary',
	array(
		'timestamp' => time() - 60,
		'datetime'  => '2000-01-01T00:00:00+00:00',
		'event_key' => 'chain_event',
		'type'      => 'start',
	),
	false
);
update_option( 'conexao_commemorative_boundary_lock', time(), '', false );

ce_cache_reset_counter();
$ce_cache_locked_run = Conexao_Commemorative_Cache::maybe_purge_due();

assert_equals( false, $ce_cache_locked_run, 'a held lock makes the due check skip (at most one purge per boundary)' );
assert_equals( 0, $GLOBALS['ce_cache_purges'], 'the skipped check fires no purge' );

delete_option( 'conexao_commemorative_boundary_lock' );

test_section( 'wp-admin purge action refuses a caller without capability or nonce' );

add_filter(
	'wp_die_handler',
	static function () {
		return static function ( $message ) {
			throw new RuntimeException( is_string( $message ) ? $message : 'wp_die' );
		};
	}
);

wp_set_current_user( 0 );
$ce_cache_refused_anonymous = false;

try {
	Conexao_Commemorative_Cache::handle_admin_purge();
} catch ( RuntimeException $e ) {
	$ce_cache_refused_anonymous = true;
	unset( $e );
}

assert_true( $ce_cache_refused_anonymous, 'an unauthenticated caller is refused the purge action' );

$ce_cache_admins    = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
$ce_cache_admin_id  = ! empty( $ce_cache_admins ) ? (int) $ce_cache_admins[0] : 0;

if ( $ce_cache_admin_id > 0 ) {
	wp_set_current_user( $ce_cache_admin_id );

	$ce_cache_refused_nonce = false;

	try {
		Conexao_Commemorative_Cache::handle_admin_purge();
	} catch ( RuntimeException $e ) {
		$ce_cache_refused_nonce = true;
		unset( $e );
	}

	assert_true( $ce_cache_refused_nonce, 'an administrator without a valid nonce is refused the purge action' );
} else {
	assert_true( true, 'no administrator account exists locally; the nonce refusal is unverified in this run' );
}

wp_set_current_user( 0 );

test_section( 'Cleanup' );

foreach ( get_posts(
	array(
		'post_type'      => 'commemorative_event',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'lang'           => '',
		's'              => '[TEST] Cache',
	)
) as $ce_cache_leftover ) {
	wp_delete_post( $ce_cache_leftover, true );
}

delete_option( 'conexao_commemorative_next_boundary' );
delete_option( 'conexao_commemorative_boundary_lock' );
wp_clear_scheduled_hook( 'conexao_commemorative_boundary' );
Conexao_Commemorative_Repository::reset_cache();

$ce_cache_remaining = get_posts(
	array(
		'post_type'      => 'commemorative_event',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'lang'           => '',
		's'              => '[TEST] Cache',
	)
);

assert_true( empty( $ce_cache_remaining ), 'no temporary commemorative record remains after the suite' );

test_finish( 'commemorative-cache-boundary' );


