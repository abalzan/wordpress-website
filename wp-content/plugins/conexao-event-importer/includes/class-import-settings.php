<?php
/**
 * Importer settings storage + admin page.
 *
 * Storing only the Eventbrite API token. Import execution is manual and
 * local-only — there are no scheduling, notification, REST, or auto-disable
 * settings to configure.
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
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			// Official Eventbrite API token (personal OAuth token). When set,
			// eventbrite-type sources use the official API instead of HTML
			// scraping — required on hosts whose IPs Eventbrite blocks (405).
			'eventbrite_api_token' => '',
		);
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
	 * @param array $new_settings Settings to merge.
	 * @return bool
	 */
	public static function update( $new_settings ) {
		if ( ! is_array( $new_settings ) ) {
			return false;
		}

		$current = self::all();

		if ( array_key_exists( 'eventbrite_api_token', $new_settings ) && is_string( $new_settings['eventbrite_api_token'] ) ) {
			// OAuth tokens are alphanumeric with underscores/hyphens.
			$token = preg_replace( '/[^A-Za-z0-9_\-]/', '', $new_settings['eventbrite_api_token'] );
			$current['eventbrite_api_token'] = substr( $token, 0, 128 );
		}

		self::$cache = $current;
		return update_option( self::OPTION_KEY, $current, false );
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
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['conexao_settings_action'] ) && 'save' === sanitize_text_field( wp_unslash( $_POST['conexao_settings_action'] ) )
			&& check_admin_referer( 'conexao_import_settings', 'conexao_import_settings_nonce' ) ) {

			self::update(
				array(
					'eventbrite_api_token' => isset( $_POST['eventbrite_api_token'] )
						? (string) wp_unslash( $_POST['eventbrite_api_token'] )
						: '',
				)
			);
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'conexao-event-importer' ) . '</p></div>';
		}

		$settings = self::all();
		?>
		<div class="wrap conexao-import-settings">
			<h1><?php esc_html_e( 'Import Settings', 'conexao-event-importer' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Event Importer is a manual, local-only tool. Click “Import Events Now” on the Dashboard to fetch events from the configured sources.', 'conexao-event-importer' ); ?>
			</p>

			<form method="post">
				<?php wp_nonce_field( 'conexao_import_settings', 'conexao_import_settings_nonce' ); ?>
				<input type="hidden" name="conexao_settings_action" value="save">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="eventbrite_api_token"><?php esc_html_e( 'Eventbrite API token', 'conexao-event-importer' ); ?></label></th>
						<td>
							<input type="password" id="eventbrite_api_token" name="eventbrite_api_token" class="regular-text" value="<?php echo esc_attr( $settings['eventbrite_api_token'] ); ?>" autocomplete="off">
							<p class="description"><?php esc_html_e( 'Leave empty to keep HTML-scraping mode (works on residential connections only). On hosts whose IPs Eventbrite blocks (e.g. WordPress.com), set a personal OAuth token from developers.eventbrite.com.', 'conexao-event-importer' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Settings', 'conexao-event-importer' ) ); ?>
			</form>
		</div>
		<?php
	}
}