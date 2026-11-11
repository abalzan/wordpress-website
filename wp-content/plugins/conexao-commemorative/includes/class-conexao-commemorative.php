<?php
/**
 * The engine bootstrap class.
 *
 * Kept in its own file (named after the class) so the plugin entry point stays
 * a plain bootstrap: header, constants, requires, activation hooks.
 *
 * @package Conexao_Commemorative
 */

defined( 'ABSPATH' ) || exit;

/**
 * Boots the commemorative engine.
 */
final class Conexao_Commemorative {

	/**
	 * Singleton instance.
	 *
	 * @var Conexao_Commemorative|null
	 */
	private static $instance = null;

	/**
	 * Singleton.
	 *
	 * @return Conexao_Commemorative
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	private function __construct() {
		// WordPress loads active plugins in an order that activation sorts
		// alphabetically — NOT in the registry order. A cross-plugin class
		// reference at file-load time would therefore be fragile, so the engine
		// boots on `plugins_loaded`, when every plugin's classes exist.
		add_action( 'plugins_loaded', array( $this, 'boot_components' ), 0 );

		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Boot the engine components once every plugin is loaded.
	 *
	 * @return void
	 */
	public function boot_components() {
		// r10 §3.6 single-owner invariant, layer 1 (copy): Polylang must never
		// duplicate the shared scheduling/presentation configuration onto a
		// translation. Registered unconditionally — the filter only fires when
		// Polylang is active, and the callback is a no-op for other post types.
		add_filter( 'pll_copy_post_metas', array( $this, 'filter_copied_metas' ), 10, 5 );

		// Cache/boundary lifecycle: always loaded (front end, admin, REST, cron).
		Conexao_Commemorative_Cache::boot();

		// Preview: authorise early, then actively bypass caches.
		add_action( 'init', array( 'Conexao_Commemorative_Preview', 'bypass_caches' ), 1 );

		if ( is_admin() ) {
			Conexao_Commemorative_Admin::boot();
		}
	}

	/**
	 * Exclude the shared `_ce_*` keys from Polylang's post-meta copy/sync.
	 *
	 * Polylang applies `pll_copy_post_metas` to the list of meta keys it is
	 * about to copy (`PLL_Sync_Post_Metas::get_metas_to_copy()` →
	 * `apply_filters( 'pll_copy_post_metas', $keys, $sync, $from, $to, $lang )`),
	 * so the list is an INCLUSION list: removing a key from it is what excludes
	 * that key. The hook is only altered when at least one side of the copy is a
	 * `commemorative_event`; every other post type gets its list untouched.
	 *
	 * @param mixed  $keys Meta keys to copy.
	 * @param bool   $sync Whether this is a sync (true) or a copy (false).
	 * @param int    $from Source post ID.
	 * @param int    $to   Target post ID.
	 * @param string $lang Target language slug.
	 * @return mixed
	 */
	public function filter_copied_metas( $keys, $sync = false, $from = 0, $to = 0, $lang = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- the Polylang filter contract passes five arguments; the exclusion decision only needs the two post IDs.
		if ( ! is_array( $keys ) ) {
			return $keys;
		}

		if ( ! self::is_commemorative_copy( $from, $to ) ) {
			return $keys;
		}

		return Conexao_Data_Model_Commemorative::strip_shared_meta_keys( $keys );
	}

	/**
	 * Whether a Polylang copy/sync involves a commemorative record.
	 *
	 * @param mixed $from Source post ID.
	 * @param mixed $to   Target post ID.
	 * @return bool
	 */
	public static function is_commemorative_copy( $from, $to ) {
		foreach ( array( $from, $to ) as $candidate ) {
			$candidate = (int) $candidate;

			if ( $candidate > 0 && Conexao_Data_Model_Commemorative::POST_TYPE === get_post_type( $candidate ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Load the plugin textdomain.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'conexao-commemorative',
			false,
			dirname( plugin_basename( CONEXAO_COMMEMORATIVE_FILE ) ) . '/languages'
		);
	}

	/**
	 * Activation: arm the boundary from the persisted records.
	 *
	 * @return void
	 */
	public static function activate() {
		Conexao_Commemorative_Repository::reset_cache();
		Conexao_Commemorative_Cache::arm();
	}

	/**
	 * Deactivation: leave no scheduled boundary behind.
	 *
	 * @return void
	 */
	public static function deactivate() {
		Conexao_Commemorative_Cache::clear();
	}
}
