<?php
/**
 * Signed, capability-gated, request-scoped administrator preview (state B).
 *
 * The preview link carries the stable `event_key` (never a translated slug), so
 * it works in both languages. It is honoured ONLY when ALL hold (§5.4):
 *
 *   is_user_logged_in()
 *   AND current_user_can( 'manage_options' )
 *   AND current_user_can( 'edit_post', $canonical_id )
 *   AND wp_verify_nonce( …, self::NONCE_ACTION . '|' . $event_key )
 *   AND the event_key names an existing event
 *
 * Any failure is silent (no error oracle). A preview changes NOTHING persisted,
 * works for published/disabled/draft/out-of-window events, and actively bypasses
 * page caches instead of merely asking not to be cached.
 *
 * @package Conexao_Commemorative
 */

defined( 'ABSPATH' ) || exit;

/**
 * Preview authorisation and cache bypass.
 */
final class Conexao_Commemorative_Preview {

	/**
	 * The public query parameter.
	 */
	const QUERY_VAR = 'conexao_preview';

	/**
	 * Nonce action prefix.
	 */
	const NONCE_ACTION = 'conexao_commemorative_preview';

	/**
	 * Per-request resolved preview (null = not requested, false = refused).
	 *
	 * @var array|false|null
	 */
	private static $resolved = null;

	/**
	 * The nonce action for one event key.
	 *
	 * @param string $event_key Stable logical identity.
	 * @return string
	 */
	public static function nonce_action( $event_key ) {
		return self::NONCE_ACTION . '|' . (string) $event_key;
	}

	/**
	 * Mint the signed, cache-busted preview link for a record.
	 *
	 * @param int       $post_id   Record ID (any language).
	 * @param string    $base_url  Base URL (defaults to the homepage).
	 * @param bool|null $cache_bust Append a unique token (default true).
	 * @return string
	 */
	public static function link( $post_id, $base_url = '', $cache_bust = null ) {
		$event = Conexao_Commemorative_Repository::find( $post_id );

		if ( ! $event || '' === $event['event_key'] ) {
			return '';
		}

		$url = '' !== $base_url ? $base_url : home_url( '/' );

		$args = array(
			self::QUERY_VAR => $event['event_key'],
			'_wpnonce'      => wp_create_nonce( self::nonce_action( $event['event_key'] ) ),
		);

		if ( false !== $cache_bust ) {
			// Unique token: no shared cache may satisfy a different request with
			// a previewed page (§5.4).
			$args['_ce_ts'] = (string) time();
		}

		return add_query_arg( $args, $url );
	}

	/**
	 * The authorised preview event for this request, or null.
	 *
	 * @return array|null
	 */
	public static function requested() {
		if ( null !== self::$resolved ) {
			return false === self::$resolved ? null : self::$resolved;
		}

		self::$resolved = false;

		if ( is_admin() ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below against the per-key action.
		$raw_key = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';

		if ( '' === $raw_key ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		// Conjunctive authorisation. Every failure is silent.
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) || '' === $nonce ) {
			return null;
		}

		$event = Conexao_Commemorative_Repository::find_by_event_key( $raw_key );

		if ( ! $event ) {
			return null;
		}

		$canonical = isset( $event['canonical_id'] ) ? (int) $event['canonical_id'] : (int) $event['id'];

		if ( ! current_user_can( 'edit_post', $canonical ) ) {
			return null;
		}

		if ( ! wp_verify_nonce( $nonce, self::nonce_action( $event['event_key'] ) ) ) {
			return null;
		}

		self::$resolved = $event;

		return $event;
	}

	/**
	 * Whether an authorised preview is active for this request.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return null !== self::requested();
	}

	/**
	 * Actively bypass every in-repo cache layer for an authorised preview.
	 *
	 * `DONOTCACHEPAGE` plus `Cache-Control: no-store, private` plus
	 * `nocache_headers()`. Host/CDN caches cannot be configured from this
	 * repository: that limitation is recorded, and the preview stays a
	 * logged-in-only capability.
	 *
	 * @return void
	 */
	public static function bypass_caches() {
		if ( ! self::is_active() ) {
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		if ( ! defined( 'DONOTCDN' ) ) {
			define( 'DONOTCDN', true );
		}

		nocache_headers();

		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-store, private, max-age=0' );
			header( 'Pragma: no-cache' );
			header( 'X-Conexao-Commemorative-Preview: 1' );
		}
	}

	/**
	 * Reset the per-request memo (tests switch users between assertions).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$resolved = null;
	}
}
