<?php
/**
 * Importer settings storage.
 *
 * ## There is no Eventbrite API credential (G7)
 *
 * This class used to store an `eventbrite_api_token` personal OAuth token and
 * documented it as selecting an "official API" fetch mode. NO CODE PATH EVER
 * READ IT: `Conexao_Source_Eventbrite::fetch_events()` has always called
 * `fetch_via_scrape()` unconditionally, and the v3 Events Search endpoint the
 * token was for returns 404 (retired by Eventbrite).
 *
 * G6 measured that the official API cannot enumerate a county listing anyway —
 * `/v3/events/search/` is 404 and organization discovery is per-user, so a
 * county's events (Cork: 36 distinct organizers, Dublin: 74, Carlow: 51 in 60)
 * span organizers you do not own. See
 * `docs/reports/2026-10-04-eventbrite-api-feasibility.md` (verdict: NOT VIABLE)
 * and `docs/reports/2026-10-04-eventbrite-api-retirement.md`.
 *
 * The token was therefore a dead secret-storage path: it looked like a
 * prerequisite, was never read, and invited a credential nobody needed. G7
 * removed it. Do not reintroduce a token setting without first re-running the
 * G6 discovery measurement.
 *
 * The class itself is RETAINED: `Conexao_Event_Cleanup` reads the
 * `cleanup_scope` setting from this same option, so the storage surface is
 * still live. `cleanup_scope` remains the one configurable setting.
 *
 * Import execution is manual and local-only — there are no scheduling,
 * notification, REST, or auto-disable settings to configure.
 *
 * The former Settings admin page (`admin.php?page=conexao-import-settings`)
 * was retired: no submenu, no page callback, no hidden page. The old URL
 * redirects to the Event Import dashboard for `manage_options` users.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Import_Settings {

	const OPTION_KEY = 'conexao_event_importer_settings';

	/** @var array|null Cached settings. */
	protected static $cache = null;

	/**
	 * Get the default settings.
	 *
	 * G7: the `eventbrite_api_token` default was removed with the dead token
	 * path. `cleanup_scope` is read with an explicit fallback by its consumer,
	 * so it needs no default entry either.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array();
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION_KEY, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::get_defaults() );
		}
		return self::$cache;
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when the key is unknown.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$settings = self::all();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Update settings (partial merge).
	 *
	 * G7: the `eventbrite_api_token` sanitizing branch was removed with the dead
	 * token path. Stale keys already persisted by an older build (including
	 * `eventbrite_api_token`) are intentionally PRESERVED verbatim rather than
	 * stripped: they are inert — no code path reads them — and this plugin
	 * never runs a destructive option-rewrite migration. Keeping them makes the
	 * stored option auditable; a maintainer who wants them gone can delete the
	 * key by hand. Removing the CONSUMER is what makes stale state harmless.
	 *
	 * @param array $new_settings Settings to merge.
	 * @return bool
	 */
	public static function update( $new_settings ) {
		if ( ! is_array( $new_settings ) ) {
			return false;
		}

		self::$cache = array_merge( self::all(), $new_settings );

		return update_option( self::OPTION_KEY, self::$cache, false );
	}

}