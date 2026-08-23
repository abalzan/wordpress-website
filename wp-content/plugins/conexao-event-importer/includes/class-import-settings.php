<?php
/**
 * Importer settings storage + admin page.
 *
 * Centralizes the automation configuration for the event importer:
 * failure notifications, auto-disable threshold, cleanup scope, REST token
 * and the background image-sync batch size.
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
			// '' means "fall back to admin_email".
			'notify_email'                => '',
			// Send an email when a scheduled/automated run finishes with failures.
			'notify_on_failure'           => true,
			// Auto-disable a source after N consecutive failed runs. 0 disables the feature.
			'auto_disable_after_failures' => 5,
			// Which past events the weekly cleanup may delete: 'all' or 'imported_only'.
			'cleanup_scope'               => 'all',
			// Secret token for the REST trigger/status endpoints (generated lazily).
			'rest_token'                  => '',
			// Official Eventbrite API token (personal OAuth token). When set,
			// eventbrite-type sources use the official API instead of HTML
			// scraping — required on hosts whose IPs Eventbrite blocks (405).
			'eventbrite_api_token'        => '',
			// How many external-banner events the nightly image-sync sweeper processes.
			'image_sync_batch'            => 25,
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

		// Sanitize known keys.
		if ( array_key_exists( 'notify_email', $new_settings ) ) {
			$email = trim( (string) $new_settings['notify_email'] );
			$current['notify_email'] = $email ? sanitize_email( $email ) : '';
		}
		if ( array_key_exists( 'notify_on_failure', $new_settings ) ) {
			$current['notify_on_failure'] = (bool) $new_settings['notify_on_failure'];
		}
		if ( array_key_exists( 'auto_disable_after_failures', $new_settings ) ) {
			$current['auto_disable_after_failures'] = max( 0, absint( $new_settings['auto_disable_after_failures'] ) );
		}
		if ( array_key_exists( 'cleanup_scope', $new_settings ) ) {
			$scope                = ( 'imported_only' === $new_settings['cleanup_scope'] ) ? 'imported_only' : 'all';
			$current['cleanup_scope'] = $scope;
		}
		if ( array_key_exists( 'image_sync_batch', $new_settings ) ) {
			$current['image_sync_batch'] = min( 200, max( 1, absint( $new_settings['image_sync_batch'] ) ) );
		}
		if ( array_key_exists( 'rest_token', $new_settings ) && is_string( $new_settings['rest_token'] ) ) {
			$token              = preg_replace( '/[^A-Za-z0-9]/', '', $new_settings['rest_token'] );
			$current['rest_token'] = substr( $token, 0, 64 );
		}
		if ( array_key_exists( 'eventbrite_api_token', $new_settings ) && is_string( $new_settings['eventbrite_api_token'] ) ) {
			// OAuth tokens are alphanumeric with underscores/hyphens.
			$token = preg_replace( '/[^A-Za-z0-9_\-]/', '', $new_settings['eventbrite_api_token'] );
			$current['eventbrite_api_token'] = substr( $token, 0, 128 );
		}

		self::$cache = $current;
		return update_option( self::OPTION_KEY, $current, false );
	}

	/**
	 * Get the notification email address (falls back to the admin email).
	 *
	 * @return string
	 */
	public static function get_notify_email() {
		$email = trim( (string) self::get( 'notify_email', '' ) );
		if ( $email && is_email( $email ) ) {
			return $email;
		}
		return get_option( 'admin_email', '' );
	}

	/**
	 * Get (and lazily generate) the REST access token.
	 *
	 * @return string
	 */
	public static function get_rest_token() {
		$token = (string) self::get( 'rest_token', '' );
		if ( '' === $token ) {
			$token = wp_generate_password( 40, false, false );
			self::update( array( 'rest_token' => $token ) );
		}
		return $token;
	}

	/**
	 * Regenerate the REST token.
	 *
	 * @return string The new token.
	 */
	public static function regenerate_rest_token() {
		$token = wp_generate_password( 40, false, false );
		self::update( array( 'rest_token' => $token ) );
		return $token;
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

		// Handle regeneration before rendering so the new token shows.
		if ( isset( $_POST['conexao_settings_action'] ) && 'regenerate_token' === sanitize_text_field( wp_unslash( $_POST['conexao_settings_action'] ) )
			&& check_admin_referer( 'conexao_import_settings', 'conexao_import_settings_nonce' ) ) {
			self::regenerate_rest_token();
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'A new API token was generated.', 'conexao-event-importer' ) . '</p></div>';
		}

		if ( isset( $_POST['conexao_settings_action'] ) && 'save' === sanitize_text_field( wp_unslash( $_POST['conexao_settings_action'] ) )
			&& check_admin_referer( 'conexao_import_settings', 'conexao_import_settings_nonce' ) ) {

			$notify_email = isset( $_POST['notify_email'] ) ? sanitize_text_field( wp_unslash( $_POST['notify_email'] ) ) : '';
			self::update(
				array(
					'notify_email'                => $notify_email,
					'notify_on_failure'           => ! empty( $_POST['notify_on_failure'] ),
					'auto_disable_after_failures' => isset( $_POST['auto_disable_after_failures'] ) ? absint( $_POST['auto_disable_after_failures'] ) : 0,
					'cleanup_scope'               => isset( $_POST['cleanup_scope'] ) ? sanitize_key( wp_unslash( $_POST['cleanup_scope'] ) ) : 'all',
					'image_sync_batch'            => isset( $_POST['image_sync_batch'] ) ? absint( $_POST['image_sync_batch'] ) : 25,
				)
			);
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'conexao-event-importer' ) . '</p></div>';
		}

		$settings   = self::all();
		$token      = self::get_rest_token();
		$rest_url   = rest_url( 'conexao-events/v1/run' );
		$status_url = rest_url( 'conexao-events/v1/status' );
		?>
		<div class="wrap conexao-import-settings">
			<h1><?php esc_html_e( 'Import Settings', 'conexao-event-importer' ); ?></h1>
			<p><?php esc_html_e( 'Automation settings for the event importer: notifications, source health, cleanup policy and external triggers.', 'conexao-event-importer' ); ?></p>

			<form method="post">
				<?php wp_nonce_field( 'conexao_import_settings', 'conexao_import_settings_nonce' ); ?>
				<input type="hidden" name="conexao_settings_action" value="save">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="notify_email"><?php esc_html_e( 'Notification email', 'conexao-event-importer' ); ?></label></th>
						<td>
							<input type="email" id="notify_email" name="notify_email" class="regular-text" value="<?php echo esc_attr( $settings['notify_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
							<p class="description"><?php esc_html_e( 'Address that receives import failure alerts. Leave empty to use the site admin email.', 'conexao-event-importer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Failure alerts', 'conexao-event-importer' ); ?></th>
						<td>
							<label for="notify_on_failure">
								<input type="checkbox" id="notify_on_failure" name="notify_on_failure" <?php checked( ! empty( $settings['notify_on_failure'] ) ); ?>>
								<?php esc_html_e( 'Email me when an automated import finishes with errors', 'conexao-event-importer' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="auto_disable_after_failures"><?php esc_html_e( 'Auto-disable broken sources', 'conexao-event-importer' ); ?></label></th>
						<td>
							<input type="number" id="auto_disable_after_failures" name="auto_disable_after_failures" min="0" max="50" class="small-text" value="<?php echo esc_attr( $settings['auto_disable_after_failures'] ); ?>">
							<p class="description"><?php esc_html_e( 'Automatically deactivate a source after this many consecutive failed imports (0 = never disable). You will be notified when it happens.', 'conexao-event-importer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cleanup_scope"><?php esc_html_e( 'Cleanup scope', 'conexao-event-importer' ); ?></label></th>
						<td>
							<select id="cleanup_scope" name="cleanup_scope">
								<option value="all" <?php selected( $settings['cleanup_scope'], 'all' ); ?>><?php esc_html_e( 'All past events (including manually created)', 'conexao-event-importer' ); ?></option>
								<option value="imported_only" <?php selected( $settings['cleanup_scope'], 'imported_only' ); ?>><?php esc_html_e( 'Only imported events (protect manual events)', 'conexao-event-importer' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'The weekly cleanup deletes past events and their orphaned images. Choose whether manually created events may be deleted too.', 'conexao-event-importer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="image_sync_batch"><?php esc_html_e( 'Nightly image sync batch', 'conexao-event-importer' ); ?></label></th>
						<td>
							<input type="number" id="image_sync_batch" name="image_sync_batch" min="1" max="200" class="small-text" value="<?php echo esc_attr( $settings['image_sync_batch'] ); ?>">
							<p class="description"><?php esc_html_e( 'How many events with external banner images are localized per night by the automated sweeper.', 'conexao-event-importer' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Settings', 'conexao-event-importer' ) ); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Eventbrite API', 'conexao-event-importer' ); ?></h2>
			<p>
				<?php esc_html_e( 'Some hosts (e.g. WordPress.com) are blocked by the Eventbrite website (HTTP 405), so scraping fails there. Create a free personal OAuth token at developers.eventbrite.com and paste it below to switch Eventbrite sources to the official API.', 'conexao-event-importer' ); ?>
			</p>
			<form method="post">
				<?php wp_nonce_field( 'conexao_import_settings', 'conexao_import_settings_nonce' ); ?>
				<input type="hidden" name="conexao_settings_action" value="save">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="eventbrite_api_token"><?php esc_html_e( 'Eventbrite API token', 'conexao-event-importer' ); ?></label></th>
						<td>
							<input type="password" id="eventbrite_api_token" name="eventbrite_api_token" class="regular-text" value="<?php echo esc_attr( $settings['eventbrite_api_token'] ); ?>" autocomplete="off">
							<p class="description"><?php esc_html_e( 'Leave empty to keep HTML-scraping mode (works on residential connections only).', 'conexao-event-importer' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Eventbrite Token', 'conexao-event-importer' ), 'secondary' ); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'External trigger (API)', 'conexao-event-importer' ); ?></h2>
			<p><?php esc_html_e( 'Trigger imports or check the importer health from outside WordPress (system cron, uptime monitors, CI). Requests must include the secret token below as ?token=… or an X-Conexao-Token header.', 'conexao-event-importer' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Trigger URL', 'conexao-event-importer' ); ?></th>
					<td><code><?php echo esc_html( $rest_url ); ?>?token={token}</code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Health status URL', 'conexao-event-importer' ); ?></th>
					<td><code><?php echo esc_html( $status_url ); ?>?token={token}</code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Token', 'conexao-event-importer' ); ?></th>
					<td>
						<code style="user-select:all;"><?php echo esc_html( $token ); ?></code>
						<form method="post" style="margin-top:8px;">
							<?php wp_nonce_field( 'conexao_import_settings', 'conexao_import_settings_nonce' ); ?>
							<input type="hidden" name="conexao_settings_action" value="regenerate_token">
							<button type="submit" class="button"><?php esc_html_e( 'Regenerate token', 'conexao-event-importer' ); ?></button>
						</form>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}
}