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
		// Cache/boundary lifecycle: always loaded (front end, admin, REST, cron).
		Conexao_Commemorative_Cache::boot();

		// Preview: authorise early, then actively bypass caches.
		add_action( 'init', array( 'Conexao_Commemorative_Preview', 'bypass_caches' ), 1 );

		if ( is_admin() ) {
			Conexao_Commemorative_Admin::boot();
		}
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
