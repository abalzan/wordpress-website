<?php
/**
 * Plugin Name: Conexão BR Irlanda — Event Importer (Local Tools)
 * Description: Local-only event import/export tooling. Fetches, normalizes, deduplicates, and imports events from configured external sources into the local WordPress database. Event images are downloaded into the Media Library locally, then the complete event data (with embedded images) is exported to JSON for import into the production WordPress.com site. Production-critical event runtime behavior (meta/taxonomy registration, _event_status, public query filtering, status admin UI) lives in the separate "Conexão BR Irlanda — Event Runtime" plugin.
 * Version: 1.7.1
 * Requires Plugins: conexao-data-model, conexao-event-runtime
 * Text Domain: conexao-event-importer
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

define( 'CONEXAO_EVENT_IMPORTER_FILE', __FILE__ );
define( 'CONEXAO_EVENT_IMPORTER_VERSION', '1.7.1' );
define( 'CONEXAO_EVENT_IMPORTER_DIR', plugin_dir_path( __FILE__ ) );
define( 'CONEXAO_EVENT_IMPORTER_URL', plugin_dir_url( __FILE__ ) );

require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-source-fetch-exception.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-import-settings.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-source-health.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-import-log.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-import-log-admin.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-import-history.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-import-result.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-address.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-jsonld-location.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-location.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-address.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-jsonld-location.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-normalizer.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-date-filter.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-deduplicator.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-sources.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/sources/abstract-class-source.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/sources/class-icalendar-source.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/sources/class-laois-tourism-source.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/sources/class-heritage-week-source.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/sources/class-ivvcc-source.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/sources/class-motorsport-ireland-source.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/sources/class-mondello-park-source.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-eventbrite-client.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-eventbrite-parser.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-eventbrite-normalizer.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-county-registry.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/sources/class-eventbrite-source.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-image-handler.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-importer.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-import-dashboard.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-export.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-import.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-transfer-admin.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-multi-import.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-image-sync-admin.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-cleanup.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-cleanup-admin.php';

// Stage 2: language guard for imported events (Polylang-aware, no-op without
// Polylang). Must load before the importers run.
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-language-guard.php';
Conexao_Event_Importer_Language_Guard::init();

// WP-CLI commands (self-guarding: only registers when WP_CLI is defined).
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-import-cli.php';

// ---------------------------------------------------------------------------
// Runtime dependency guard.
//
// The "Conexão BR Irlanda — Event Runtime" plugin owns the production-critical
// event behavior: event meta registration, the conexao_town taxonomy, the
// _event_status gate on public queries, and the event status admin UI. This
// tooling plugin depends on it (declared via the "Requires Plugins" header)
// and must never be booted without it — the dependency direction is strictly
// Importer Tools → Runtime, never the reverse.
//
// The status class is also loaded directly from the runtime plugin directory
// when possible, so WP-CLI/test contexts that require this file directly keep
// working even before WordPress has loaded the active plugins.
// ---------------------------------------------------------------------------
if ( ! class_exists( 'Conexao_Event_Status' ) ) {
	$conexao_runtime_status = WP_PLUGIN_DIR . '/conexao-event-runtime/includes/class-event-status.php';
	if ( file_exists( $conexao_runtime_status ) ) {
		require_once $conexao_runtime_status;
	}
	unset( $conexao_runtime_status );
}

if ( ! class_exists( 'Conexao_Event_Status' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'Conexão BR Irlanda — Event Importer (Local Tools) requires the "Conexão BR Irlanda — Event Runtime" plugin to be installed and active.', 'conexao-event-importer' );
			echo '</p></div>';
		}
	);

	// Do not boot any import tooling without the runtime plugin active.
	return;
}

final class Conexao_Event_Importer {

	/** @var Conexao_Event_Importer|null */
	private static $instance = null;

	/** @var Conexao_Event_Sources */
	public $sources;

	/** @var Conexao_Event_Importer_Engine */
	public $importer;

	/** @var Conexao_Import_Dashboard */
	public $dashboard;

	/** @var Conexao_Event_Location */
	public $location;

	/** @var Conexao_Event_Transfer_Admin */
	public $transfer;

	/** @var Conexao_Event_Image_Sync_Admin */
	public $image_sync;

	/** @var Conexao_Event_Cleanup */
	public $cleanup;

	/** @var Conexao_Event_Cleanup_Admin */
	public $cleanup_admin;

	/** @var Conexao_Import_Log_Admin */
	public $log_admin;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->sources   = new Conexao_Event_Sources();
		$this->location  = new Conexao_Event_Location();
		$this->importer  = new Conexao_Event_Importer_Engine( $this->sources, $this->location );
		$this->dashboard = new Conexao_Import_Dashboard( $this->sources, $this->importer );
		$this->transfer  = new Conexao_Event_Transfer_Admin();
		$this->image_sync = new Conexao_Event_Image_Sync_Admin();
		$this->cleanup   = new Conexao_Event_Cleanup();
		$this->cleanup_admin = new Conexao_Event_Cleanup_Admin( $this->cleanup );

		// Import Logs admin screen + secure download / clear actions.
		$this->log_admin = new Conexao_Import_Log_Admin();

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );

		// Settings page under the Event Import menu (late priority so the
		// parent menu from Conexao_Event_Sources exists first).
		$settings = new Conexao_Import_Settings();
		add_action( 'admin_menu', array( $settings, 'register_admin_menu' ), 20 );

		// Allow webcal:// protocol in iCalendar source URLs.
		add_filter( 'kses_allowed_protocols', array( $this, 'allow_webcal_protocol' ) );
	}

	/**
	 * Add webcal to the list of allowed URL protocols.
	 *
	 * This allows WordPress to accept webcal:// URLs in forms and content
	 * without stripping the protocol.
	 *
	 * @param array $protocols List of allowed protocols.
	 * @return array
	 */
	public function allow_webcal_protocol( $protocols ) {
		if ( ! in_array( 'webcal', $protocols, true ) ) {
			$protocols[] = 'webcal';
		}
		return $protocols;
	}

	/**
	 * Admin assets for the import dashboard / sources screens.
	 *
	 * Event-list screen assets (status badges) are owned by the Event
	 * Runtime plugin; only import tooling screens are covered here.
	 */
	public function admin_assets( $hook ) {
		$is_import_screen = false !== strpos( $hook, 'conexao-events' ) || false !== strpos( $hook, 'conexao-event-import' ) || false !== strpos( $hook, 'conexao-event-export' ) || false !== strpos( $hook, 'conexao-event-cleanup' ) || false !== strpos( $hook, 'conexao-import-log' );
		if ( ! $is_import_screen ) {
			return;
		}
		wp_enqueue_style( 'conexao-event-importer-admin', CONEXAO_EVENT_IMPORTER_URL . 'assets/admin.css', array(), CONEXAO_EVENT_IMPORTER_VERSION );

		wp_enqueue_script( 'conexao-event-importer-admin', CONEXAO_EVENT_IMPORTER_URL . 'assets/admin.js', array(), CONEXAO_EVENT_IMPORTER_VERSION, true );
		wp_localize_script(
			'conexao-event-importer-admin',
			'conexaoEventImporter',
			array(
				'ajaxUrl'   => admin_url( 'admin-post.php' ),
				'i18n'      => array(
					'importing'          => __( 'Importing… please wait. Do not close this page.', 'conexao-event-importer' ),
					'importingFile'      => __( 'Importing file %1$d of %2$d…', 'conexao-event-importer' ),
					'fileSuccess'        => __( 'File %d: completed successfully.', 'conexao-event-importer' ),
					'fileFailed'         => __( 'File %d: failed.', 'conexao-event-importer' ),
					'importComplete'     => __( 'Import completed successfully.', 'conexao-event-importer' ),
					'importStopped'      => __( 'Import stopped after a failure.', 'conexao-event-importer' ),
					'allFilesProcessed'  => __( 'All files processed.', 'conexao-event-importer' ),
					'retrying'           => __( 'Retrying file %d…', 'conexao-event-importer' ),
					'cleanupError'       => __( 'Could not clean up temporary files.', 'conexao-event-importer' ),
					'networkError'       => __( 'Network error. Please check your connection and retry.', 'conexao-event-importer' ),
					'serverError'        => __( 'Server error. Please check the logs.', 'conexao-event-importer' ),
				),
				'nonces'    => array(
					'importFile' => wp_create_nonce( 'conexao_import_events_multi_file' ),
					'cleanup'    => wp_create_nonce( 'conexao_import_events_multi_cleanup' ),
				),
			)
		);
	}

	/**
	 * Activate: seed default sources + flush rewrite rules.
	 * No cron is scheduled — importing is manual and local-only.
	 */
	public static function activate() {
		require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-sources.php';
		$sources = new Conexao_Event_Sources();
		$sources->seed_defaults();

		flush_rewrite_rules();
	}

	/**
	 * Deactivate: flush rewrite rules.
	 * No cron to clear — importing is manual and local-only.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}

Conexao_Event_Importer::instance();

register_activation_hook( __FILE__, array( 'Conexao_Event_Importer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Conexao_Event_Importer', 'deactivate' ) );
/**
 * Load the plugin textdomain (Stage 1 i18n foundation).
 *
 * Translation files live in this plugin's languages/ directory. This does
 * not touch importer matching, export JSON or identity fields — gettext
 * wrapping only, no behavior change.
 */
function conexao_event_importer_load_textdomain() {
	load_plugin_textdomain(
		'conexao-event-importer',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}
add_action( 'init', 'conexao_event_importer_load_textdomain' );
