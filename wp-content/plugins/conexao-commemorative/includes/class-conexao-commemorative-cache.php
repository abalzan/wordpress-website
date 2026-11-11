<?php
/**
 * Commemorative cache invalidation and activation-boundary scheduling.
 *
 * Request-time eligibility stays the authority; this class exists ONLY so a
 * cached page cannot outlive a window boundary (§4.6):
 *
 *   - ONE single scheduled event is armed at the earliest upcoming boundary;
 *   - a cheap per-request due check is the fallback when WP-Cron did not run
 *     (the honest Option B path — no exact-activation claim is made);
 *   - the purge is TARGETED: only this system's own transients and object-cache
 *     group, plus the documented `conexao_commemorative_purge_caches` action.
 *     It NEVER calls `wp_cache_flush()` and NEVER `delete_expired_transients()`;
 *   - after every purge the NEXT boundary is re-armed, so the chain is never
 *     left unarmed;
 *   - a non-blocking lock guarantees at most one purge per boundary.
 *
 * Recalculation always happens AFTER persistence: `save_post` at priority 999,
 * plus `added/updated/deleted_post_meta`, `transition_post_status` and
 * `deleted_post` (which fires after the record is gone — never
 * `before_delete_post`). Changes limited to `_ce_priority` or
 * `_ce_year_precedence` invalidate the cache but do NOT re-arm the boundary;
 * `_ce_enabled` and every scheduling key DO re-arm, because they change which
 * events participate in scheduling.
 *
 * @package Conexao_Commemorative
 */

defined( 'ABSPATH' ) || exit;

/**
 * Targeted invalidation and boundary scheduling.
 */
final class Conexao_Commemorative_Cache {

	/**
	 * The single scheduled hook.
	 */
	const HOOK = 'conexao_commemorative_boundary';

	/**
	 * Option holding the one armed boundary (used by the due check and admin).
	 */
	const NEXT_OPTION = 'conexao_commemorative_next_boundary';

	/**
	 * Option holding the last purge result (shown in wp-admin).
	 */
	const LAST_OPTION = 'conexao_commemorative_last_purge';

	/**
	 * Best-effort lock option for the per-request due check.
	 */
	const LOCK_OPTION = 'conexao_commemorative_boundary_lock';

	/**
	 * How long the lock may live before another request may claim it.
	 */
	const LOCK_TTL = 60;

	/**
	 * Object-cache group owned by this plugin.
	 */
	const CACHE_GROUP = 'conexao_commemorative';

	/**
	 * Transient keys owned by this plugin (flushed in EVERY language).
	 *
	 * @var string[]
	 */
	const TRANSIENT_KEYS = array( 'conexao_commemorative_active', 'conexao_commemorative_evaluated' );

	/**
	 * Meta keys whose change invalidates the cache but does NOT re-arm.
	 *
	 * They only affect ordering among already-eligible events, never the date
	 * schedule (§4.6 point 5, §5.7, §9.3).
	 *
	 * @var string[]
	 */
	const ORDER_ONLY_KEYS = array( '_ce_priority', '_ce_year_precedence' );

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( self::HOOK, array( __CLASS__, 'handle_scheduled_boundary' ) );

		// The per-request fallback: cheap, and the ONLY path that can recover
		// when a fully cached site never executes the scheduled event.
		add_action( 'init', array( __CLASS__, 'purge_due_on_request' ), 20 );

		// Recalculation after persistence only.
		add_action( 'save_post_' . Conexao_Data_Model_Commemorative::POST_TYPE, array( __CLASS__, 'on_save' ), 999, 3 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta_added_updated' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta_added_updated' ), 10, 4 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'on_meta_deleted' ), 10, 4 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 10, 3 );
		add_action( 'deleted_post', array( __CLASS__, 'on_deleted' ), 10, 2 );

		add_action( 'admin_post_conexao_commemorative_purge', array( __CLASS__, 'handle_admin_purge' ) );
	}

	/**
	 * Targeted invalidation. Never a global flush.
	 *
	 * @param string $reason  Machine-readable reason.
	 * @param array  $context Extra context for the host action.
	 * @return array The recorded result.
	 */
	public static function invalidate( $reason, array $context = array() ) {
		$result = array(
			'reason'       => (string) $reason,
			'time'         => time(),
			'languages'    => self::languages(),
			'transients'   => 0,
			'object_cache' => false,
			'action_fired' => false,
		);

		foreach ( self::TRANSIENT_KEYS as $key ) {
			$result['transients'] += self::flush_transient_key( $key );
		}

		if ( function_exists( 'wp_cache_flush_group' ) ) {
			$result['object_cache'] = (bool) wp_cache_flush_group( self::CACHE_GROUP );
		}

		/**
		 * Host/CDN purge hook.
		 *
		 * WordPress.com's page cache cannot be configured from this repository;
		 * an integration (or a maintainer's host action) purges it here.
		 *
		 * @param string $reason  Why the purge ran.
		 * @param array  $context Extra context.
		 */
		do_action( 'conexao_commemorative_purge_caches', $result['reason'], $context );
		$result['action_fired'] = true;

		update_option( self::LAST_OPTION, $result, false );

		return $result;
	}

	/**
	 * Delete one transient key in EVERY language (plus the bare key).
	 *
	 * Mirrors the theme's `conexao_flush_language_cache()` contract without
	 * depending on the theme.
	 *
	 * @param string $key Base transient key.
	 * @return int Number of keys deleted.
	 */
	private static function flush_transient_key( $key ) {
		$deleted = delete_transient( $key ) ? 1 : 0;

		foreach ( self::language_suffixes() as $suffix ) {
			if ( delete_transient( $key . $suffix ) ) {
				++$deleted;
			}
		}

		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $key, self::CACHE_GROUP );
			foreach ( self::language_suffixes() as $suffix ) {
				wp_cache_delete( $key . $suffix, self::CACHE_GROUP );
			}
		}

		return $deleted;
	}

	/**
	 * Language slugs used for cache-key scoping.
	 *
	 * @return string[]
	 */
	public static function languages() {
		if ( function_exists( 'pll_languages_list' ) ) {
			$slugs = (array) pll_languages_list( array( 'fields' => 'slug' ) );
			if ( ! empty( $slugs ) ) {
				return array_values( array_map( 'strval', $slugs ) );
			}
		}

		return array( '' );
	}

	/**
	 * Language suffixes (`''` plus `_en`, `_pt`, …).
	 *
	 * @return string[]
	 */
	private static function language_suffixes() {
		$suffixes = array();

		foreach ( self::languages() as $slug ) {
			if ( '' !== $slug ) {
				$suffixes[] = '_' . $slug;
			}
		}

		return array_values( array_unique( $suffixes ) );
	}

	/**
	 * The earliest upcoming boundary across the scheduling participants.
	 *
	 * @param DateTimeInterface|null $now Injected clock.
	 * @return array{timestamp:int,datetime:string,event_key:string,type:string}|null
	 */
	public static function next_boundary( $now = null ) {
		$earliest = null;

		foreach ( Conexao_Commemorative_Repository::scheduled() as $event ) {
			if ( ! $event['enabled'] ) {
				continue;
			}

			foreach ( Conexao_Commemorative_Schedule::upcoming_boundaries( $event, $now ) as $boundary ) {
				if ( null === $earliest || $boundary['timestamp'] < $earliest['timestamp'] ) {
					$earliest = array(
						'timestamp' => (int) $boundary['timestamp'],
						'datetime'  => $boundary['datetime']->format( DATE_ATOM ),
						'event_key' => (string) $event['event_key'],
						'type'      => (string) $boundary['type'],
					);
				}
			}
		}

		return $earliest;
	}

	/**
	 * Arm (or clear) the ONE scheduled boundary. Never stacks.
	 *
	 * @param DateTimeInterface|null $now Injected clock.
	 * @return array|null The armed boundary.
	 */
	public static function arm( $now = null ) {
		self::clear();

		$boundary = self::next_boundary( $now );

		if ( ! $boundary ) {
			delete_option( self::NEXT_OPTION );

			return null;
		}

		// Scheduled WITHOUT arguments so `wp_clear_scheduled_hook()` can always
		// clear it. (WordPress only clears entries whose args match; passing args
		// here would make `clear()` silently ineffective and let boundaries
		// stack.
		wp_schedule_single_event( $boundary['timestamp'], self::HOOK );
		update_option( self::NEXT_OPTION, $boundary, false );

		return $boundary;
	}

	/**
	 * Remove the armed boundary.
	 *
	 * @return void
	 */
	public static function clear() {
		wp_clear_scheduled_hook( self::HOOK );
		delete_option( self::NEXT_OPTION );
	}

	/**
	 * The armed boundary as stored (used by the admin list).
	 *
	 * @return array|null
	 */
	public static function armed_boundary() {
		$value = get_option( self::NEXT_OPTION, null );

		return is_array( $value ) ? $value : null;
	}

	/**
	 * The last purge result (used by the admin list).
	 *
	 * @return array|null
	 */
	public static function last_result() {
		$value = get_option( self::LAST_OPTION, null );

		return is_array( $value ) ? $value : null;
	}

	/**
	 * The scheduled callback: purge, then re-arm the next boundary.
	 *
	 * @return void
	 */
	public static function handle_scheduled_boundary() {
		self::invalidate( 'boundary' );
		self::arm();
	}

	/**
	 * `init` wrapper for the due-boundary check (an action callback must not return a value).
	 *
	 * @return void
	 */
	public static function purge_due_on_request() {
		self::maybe_purge_due();
	}

	/**
	 * Per-request fallback: purge when the stored boundary is due.
	 *
	 * Runs on every PHP request (Option B): a missed scheduled event self-heals
	 * at the first request after the boundary. Returns whether a purge ran.
	 *
	 * @param DateTimeInterface|null $now Injected clock.
	 * @return bool
	 */
	public static function maybe_purge_due( $now = null ) {
		if ( function_exists( 'wp_installing' ) && wp_installing() ) {
			return false;
		}

		$boundary = self::armed_boundary();

		if ( ! $boundary || empty( $boundary['timestamp'] ) ) {
			return false;
		}

		$clock = $now instanceof DateTimeInterface ? $now->getTimestamp() : time();

		if ( (int) $boundary['timestamp'] > $clock ) {
			return false;
		}

		if ( ! self::acquire_lock() ) {
			return false;
		}

		try {
			self::invalidate( 'boundary_due', $boundary );
			self::arm( $now );
		} finally {
			self::release_lock();
		}

		return true;
	}

	/**
	 * Whether a meta key only affects ordering (no re-arm).
	 *
	 * @param string $meta_key Meta key.
	 * @return bool
	 */
	public static function is_order_only_key( $meta_key ) {
		return in_array( (string) $meta_key, self::ORDER_ONLY_KEYS, true );
	}

	/**
	 * Whether a meta key participates in this system at all.
	 *
	 * @param string $meta_key Meta key.
	 * @return bool
	 */
	public static function is_commemorative_key( $meta_key ) {
		return 0 === strpos( (string) $meta_key, '_ce_' );
	}

	/**
	 * `save_post_commemorative_event` at priority 999: after every other writer.
	 *
	 * The recalculated boundary therefore always reflects the PERSISTED meta,
	 * never the pre-save configuration (§4.6 point 5, §9.6).
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post object.
	 * @param bool         $update  Whether this is an update.
	 * @return void
	 */
	public static function on_save( $post_id, $post = null, $update = false ) {
		unset( $post, $update );

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( Conexao_Data_Model_Commemorative::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		// Drop the per-request record cache so arm() reads the persisted rows.
		Conexao_Commemorative_Repository::reset_cache();

		self::invalidate( 'event_saved', array( 'post_id' => (int) $post_id ) );
		self::arm();
	}

	/**
	 * `added_post_meta` / `updated_post_meta`: fires AFTER the value is stored.
	 *
	 * @param int    $meta_id    Meta row ID.
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return void
	 */
	public static function on_meta_added_updated( $meta_id, $post_id, $meta_key, $meta_value = null ) {
		unset( $meta_id, $meta_value );

		self::handle_meta_change( $post_id, $meta_key, 'meta_changed' );
	}

	/**
	 * `deleted_post_meta`: fires AFTER the value is removed (field clearing).
	 *
	 * @param int[]  $meta_ids   Deleted meta row IDs.
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return void
	 */
	public static function on_meta_deleted( $meta_ids, $post_id, $meta_key, $meta_value = null ) {
		unset( $meta_ids, $meta_value );

		self::handle_meta_change( $post_id, $meta_key, 'meta_deleted' );
	}

	/**
	 * Shared meta-change handling: invalidate always, re-arm unless ordering-only.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @param string $reason   Machine-readable reason.
	 * @return void
	 */
	private static function handle_meta_change( $post_id, $meta_key, $reason ) {
		if ( ! self::is_commemorative_key( $meta_key ) ) {
			return;
		}

		if ( Conexao_Data_Model_Commemorative::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		Conexao_Commemorative_Repository::reset_cache();

		self::invalidate(
			$reason,
			array(
				'post_id'  => (int) $post_id,
				'meta_key' => (string) $meta_key,
			)
		);

		// `_ce_priority` / `_ce_year_precedence` change ordering only: they never
		// re-arm. `_ce_enabled` and every scheduling key DO re-arm (§4.6).
		if ( self::is_order_only_key( $meta_key ) ) {
			return;
		}

		self::arm();
	}

	/**
	 * `transition_post_status`: publish, unpublish and trash lifecycle changes.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post object.
	 * @return void
	 */
	public static function on_transition( $new_status, $old_status, $post ) {
		if ( Conexao_Data_Model_Commemorative::POST_TYPE !== $post->post_type ) {
			return;
		}

		if ( $new_status === $old_status ) {
			return;
		}

		Conexao_Commemorative_Repository::reset_cache();

		self::invalidate(
			'status_transition',
			array(
				'post_id'    => (int) $post->ID,
				'old_status' => (string) $old_status,
				'new_status' => (string) $new_status,
			)
		);

		self::arm();
	}

	/**
	 * `deleted_post`: permanent deletion, AFTER the record is gone.
	 *
	 * Deliberately not `before_delete_post`: that hook fires before the metadata
	 * is removed, so it would recalculate from the record about to disappear.
	 * Trash/unpublish is handled by `transition_post_status`, kept separate.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    The deleted post object (supplied by core).
	 * @return void
	 */
	public static function on_deleted( $post_id, $post = null ) {
		if ( ! $post ) {
			return;
		}

		if ( Conexao_Data_Model_Commemorative::POST_TYPE !== $post->post_type ) {
			return;
		}

		Conexao_Commemorative_Repository::reset_cache();

		self::invalidate( 'deleted', array( 'post_id' => (int) $post_id ) );
		self::arm();
	}

	/**
	 * The wp-admin "Purge commemorative caches" action.
	 *
	 * Capability + nonce are both required (production has no CLI, so this is
	 * the supported operational path).
	 *
	 * @return void
	 */
	public static function handle_admin_purge() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Você não tem permissão para purgar os caches comemorativos.', 'conexao-commemorative' ), 403 );
		}

		check_admin_referer( 'conexao_commemorative_purge' );

		self::invalidate( 'admin' );
		self::arm();

		wp_safe_redirect(
			add_query_arg(
				array( 'conexao_commemorative_purged' => '1' ),
				admin_url( 'edit.php?post_type=' . Conexao_Data_Model_Commemorative::POST_TYPE )
			)
		);
		exit;
	}

	/**
	 * Acquire the non-blocking boundary lock.
	 *
	 * Object-cache `add()` when a persistent cache exists; otherwise a
	 * short-lived option that expires on its own, so a crashed request cannot
	 * wedge the boundary.
	 *
	 * @return bool
	 */
	private static function acquire_lock() {
		$now = time();

		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			return (bool) wp_cache_add( 'boundary_lock', $now, self::CACHE_GROUP, self::LOCK_TTL );
		}

		$existing = (int) get_option( self::LOCK_OPTION, 0 );

		if ( $existing > 0 && $existing > $now - self::LOCK_TTL ) {
			return false;
		}

		if ( $existing > 0 ) {
			delete_option( self::LOCK_OPTION );
		}

		return (bool) add_option( self::LOCK_OPTION, $now, '', false );
	}

	/**
	 * Release the boundary lock.
	 *
	 * @return void
	 */
	private static function release_lock() {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( 'boundary_lock', self::CACHE_GROUP );
		}

		delete_option( self::LOCK_OPTION );
	}
}
