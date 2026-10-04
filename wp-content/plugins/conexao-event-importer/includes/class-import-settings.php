<?php
/**
 * Importer settings storage + admin page.
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

	/**
	 * Register the Settings submenu under the Event Import menu.
	 *
	 * Hooked late (priority 20) so the parent menu from
	 * Conexao_Event_Sources::register_admin_menu() exists first.
	 */
	public function register_admin_menu() {
		add_submenu_page(
			'conexao-event-import',
			__( 'Import Settings', 'conexao-event-importer' ),
			__( 'Settings', 'conexao-event-importer' ),
			'manage_options',
			'conexao-import-settings',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the settings page.
	 *
	 * G7: the "Eventbrite API token" field, its POST handler and the nonce-gated
	 * save form were removed with the dead token path. There is now nothing to
	 * configure here: the importer is manual and local-only, and Eventbrite
	 * sources need no credential.
	 *
	 * The screen is RETAINED rather than deleted because it is the documented
	 * landing page for importer configuration and because `cleanup_scope` still
	 * reads from this option. It now states the truth instead of offering a
	 * field that never did anything.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap conexao-import-settings">
			<h1><?php esc_html_e( 'Import Settings', 'conexao-event-importer' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Event Importer is a manual, local-only tool. Click “Import Events Now” on the Dashboard to fetch events from the configured sources.', 'conexao-event-importer' ); ?>
			</p>

			<h2><?php esc_html_e( 'Nothing to configure', 'conexao-event-importer' ); ?></h2>
			<p>
				<?php esc_html_e( 'The importer has no settings to save. In particular there is NO Eventbrite API token: Eventbrite sources are read from the public county discovery pages over plain HTML, with no account, no OAuth token and no Authorization header.', 'conexao-event-importer' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'An Eventbrite API credential is not a prerequisite for importing, and configuring one changes nothing. The official Eventbrite API cannot list a county\'s events (its public search endpoint is retired and its organization discovery is per-user), so it was measured as unsuitable and the unused token setting was retired. If Eventbrite later changes those external capabilities, revisit that decision before adding a credential.', 'conexao-event-importer' ); ?>
			</p>
		</div>
		<?php
	}
}