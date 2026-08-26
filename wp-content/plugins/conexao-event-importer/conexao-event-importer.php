<?php
/**
 * Plugin Name: Conexão BR Irlanda — Event Importer
 * Description: Local-only event importer. Fetches, normalizes, deduplicates, and imports events from configured external sources into the local WordPress database. Event images are downloaded into the Media Library locally, then the complete event data (with embedded images) is exported to JSON for import into the production WordPress.com site.
 * Version: 1.4.0
 * Text Domain: conexao-event-importer
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

define( 'CONEXAO_EVENT_IMPORTER_FILE', __FILE__ );
define( 'CONEXAO_EVENT_IMPORTER_VERSION', '1.4.0' );
define( 'CONEXAO_EVENT_IMPORTER_DIR', plugin_dir_path( __FILE__ ) );
define( 'CONEXAO_EVENT_IMPORTER_URL', plugin_dir_url( __FILE__ ) );

require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-status.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-source-fetch-exception.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-import-settings.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-source-health.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-import-log.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-import-log-admin.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-import-history.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-import-result.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-location.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-normalizer.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-date-filter.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-deduplicator.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-sources.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/sources/abstract-class-source.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/sources/class-icalendar-source.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/sources/class-laois-tourism-source.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/sources/class-heritage-week-source.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-eventbrite-client.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-eventbrite-parser.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-eventbrite-normalizer.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/sources/class-eventbrite-source.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-image-handler.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-importer.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-import-dashboard.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-export.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-import.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-transfer-admin.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-image-sync-admin.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-cleanup.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-cleanup-admin.php';

// WP-CLI commands (self-guarding: only registers when WP_CLI is defined).
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-import-cli.php';

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

		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'init', array( $this, 'register_town_taxonomy' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_public_event_queries' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );

		// Settings page under the Event Import menu (late priority so the
		// parent menu from Conexao_Event_Sources exists first).
		$settings = new Conexao_Import_Settings();
		add_action( 'admin_menu', array( $settings, 'register_admin_menu' ), 20 );

		// Admin list columns + status filter for the event post type.
		add_filter( 'manage_event_posts_columns', array( $this, 'event_admin_columns' ) );
		add_action( 'manage_event_posts_custom_column', array( $this, 'event_admin_column_content' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'event_status_filter_dropdown' ) );
		add_filter( 'parse_query', array( $this, 'event_status_filter_query' ) );

		// Event status meta box on the event editor, so admins can manage the status.
		add_action( 'add_meta_boxes', array( $this, 'add_event_status_meta_box' ) );
		add_action( 'save_post_event', array( $this, 'save_event_status_meta_box' ) );

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
	 * Register event meta fields used by the importer.
	 */
	public function register_meta() {
		$string_meta = array(
			'_event_date',
			'_event_time',
			'_event_start_time',
			'_event_end_date',
			'_event_end_time',
			'_event_location',
			'_event_venue',
			'_event_address',
			'_event_url',
			'_event_source_url',
			'_event_banner',
			'_event_registration',
			'_event_cta',
			'_event_source',
			'_event_source_id',
			'_event_organizer',
			'_event_price',
			'_event_import_date',
			'_event_last_checked',
			'_event_status',
			'_event_imported',
		);

		$int_meta = array(
			'_event_banner_attachment_id',
		);

		foreach ( $string_meta as $key ) {
			register_post_meta(
				'event',
				$key,
				array(
					'single'       => true,
					'type'         => 'string',
					'show_in_rest' => true,
				)
			);
		}

		foreach ( $int_meta as $key ) {
			register_post_meta(
				'event',
				$key,
				array(
					'single'       => true,
					'type'         => 'integer',
					'show_in_rest' => true,
				)
			);
		}
	}

	/**
	 * Register the Town/City taxonomy used for location hierarchy.
	 */
	public function register_town_taxonomy() {
		register_taxonomy(
			'conexao_town',
			array( 'event' ),
			array(
				'labels'            => array(
					'name'          => __( 'Cidades', 'conexao-event-importer' ),
					'singular_name' => __( 'Cidade', 'conexao-event-importer' ),
				),
				'public'            => true,
				'hierarchical'      => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => 'towns', 'with_front' => false ),
			)
		);
	}

	/**
	 * Public event queries should only show "published" or legacy events
	 * (events without an explicit status, i.e. manually created ones).
	 *
	 * Applies to ALL event queries on the frontend (main archive query AND the
	 * secondary WP_Query calls used by the homepage hero widget and the
	 * "Próximos Eventos" section). This keeps draft / expired /
	 * source-not-found / rejected imported events out of public pages.
	 */
	public function filter_public_event_queries( $query ) {
		if ( is_admin() ) {
			return;
		}

		$post_types = $query->get( 'post_type' );
		if ( is_array( $post_types ) ) {
			if ( ! in_array( 'event', $post_types, true ) ) {
				return;
			}
		} elseif ( 'event' !== $post_types && ! $query->is_post_type_archive( 'event' ) && ! $query->is_tax( array( 'conexao_county', 'conexao_town', 'conexao_category' ) ) ) {
			return;
		}

		$status_clause = array(
			'relation' => 'OR',
			array(
				'key'     => '_event_status',
				'value'   => 'published',
				'compare' => '=',
			),
			array(
				'key'     => '_event_status',
				'compare' => 'NOT EXISTS',
			),
		);

		$existing = $query->get( 'meta_query' );
		if ( ! is_array( $existing ) || empty( $existing ) ) {
			$query->set( 'meta_query', array( $status_clause ) );
			return;
		}

		// CRITICAL: the query may already carry its own meta_query with a
		// top-level relation (e.g. the deduplicator's OR clauses). Appending
		// the status constraint flat would inherit that relation and turn
		// "(url match) OR (published)" — matching every published event.
		// Nesting the original clauses as a group guarantees the status
		// constraint is ANDed with them.
		$inner          = $existing;
		$inner_relation = 'AND';
		if ( isset( $inner['relation'] ) ) {
			$inner_relation = strtoupper( (string) $inner['relation'] );
			unset( $inner['relation'] );
		}
		$inner = array_values( $inner );

		$query->set(
			'meta_query',
			array(
				'relation' => 'AND',
				array_merge( array( 'relation' => $inner_relation ), $inner ),
				$status_clause,
			)
		);
	}

	/**
	 * Admin assets for the import dashboard / sources screens.
	 */
	public function admin_assets( $hook ) {
		$is_event_screen = 'edit.php' === $hook && isset( $_GET['post_type'] ) && 'event' === $_GET['post_type'];
		$is_import_screen = false !== strpos( $hook, 'conexao-events' ) || false !== strpos( $hook, 'conexao-event-import' ) || false !== strpos( $hook, 'conexao-event-export' ) || false !== strpos( $hook, 'conexao-event-cleanup' ) || false !== strpos( $hook, 'conexao-import-log' );
		if ( ! $is_import_screen && ! $is_event_screen ) {
			return;
		}
		wp_enqueue_style( 'conexao-event-importer-admin', CONEXAO_EVENT_IMPORTER_URL . 'assets/admin.css', array(), CONEXAO_EVENT_IMPORTER_VERSION );

		if ( $is_import_screen ) {
			wp_enqueue_script( 'conexao-event-importer-admin', CONEXAO_EVENT_IMPORTER_URL . 'assets/admin.js', array(), CONEXAO_EVENT_IMPORTER_VERSION, true );
			wp_localize_script(
				'conexao-event-importer-admin',
				'conexaoEventImporter',
				array(
					'i18n' => array(
						'importing' => __( 'Importing… please wait. Do not close this page.', 'conexao-event-importer' ),
					),
				)
			);
		}
	}

	/**
	 * Add status + source columns to the Events admin list.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function event_admin_columns( $columns ) {
		$columns['conexao_event_status'] = __( 'Status', 'conexao-event-importer' );
		$columns['conexao_event_source'] = __( 'Source', 'conexao-event-importer' );
		return $columns;
	}

	/**
	 * Render the status + source columns on the Events admin list.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public function event_admin_column_content( $column, $post_id ) {
		if ( 'conexao_event_status' === $column ) {
			$status = Conexao_Event_Status::get_status( $post_id );
			$labels = Conexao_Event_Status::get_statuses();
			$label  = isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
			echo '<span class="conexao-status-badge conexao-status-badge--' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
		}

		if ( 'conexao_event_source' === $column ) {
			$source = get_post_meta( $post_id, '_event_source', true );
			if ( $source ) {
				$sources = $this->sources->get_all();
				$name    = isset( $sources[ $source ]['name'] ) ? $sources[ $source ]['name'] : $source;
				echo '<span class="conexao-source-label">' . esc_html( $name ) . '</span>';
			} else {
				echo '<span class="conexao-source-label">' . esc_html__( 'Manual', 'conexao-event-importer' ) . '</span>';
			}
		}
	}

	/**
	 * Render a status filter dropdown on the Events admin list.
	 *
	 * @param string $post_type Current post type.
	 */
	public function event_status_filter_dropdown( $post_type ) {
		if ( 'event' !== $post_type ) {
			return;
		}

		$current = isset( $_GET['event_status'] ) ? sanitize_text_field( wp_unslash( $_GET['event_status'] ) ) : '';
		$labels  = Conexao_Event_Status::get_statuses();
		?>
		<select name="event_status">
			<option value=""><?php esc_html_e( 'Todos os status', 'conexao-event-importer' ); ?></option>
			<?php foreach ( $labels as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current, $key ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Apply the status filter to the Events admin list query.
	 *
	 * @param WP_Query $query The query object.
	 */
	public function event_status_filter_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$post_type = $query->get( 'post_type' );
		if ( 'event' !== $post_type ) {
			return;
		}

		$status = isset( $_GET['event_status'] ) ? sanitize_text_field( wp_unslash( $_GET['event_status'] ) ) : '';
		if ( empty( $status ) ) {
			return;
		}

		$meta_query = $query->get( 'meta_query' );
		if ( ! is_array( $meta_query ) ) {
			$meta_query = array();
		}

		$meta_query[] = array(
			'key'   => '_event_status',
			'value' => $status,
		);

		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Add the event status meta box to the event editor.
	 *
	 * @param string $post_type Current post type.
	 */
	public function add_event_status_meta_box( $post_type ) {
		if ( 'event' !== $post_type ) {
			return;
		}
		add_meta_box(
			'conexao_event_status_box',
			__( 'Status do Evento', 'conexao-event-importer' ),
			array( $this, 'render_event_status_meta_box' ),
			'event',
			'side',
			'high'
		);
	}

	/**
	 * Render the event status meta box.
	 *
	 * @param WP_Post $post The post object.
	 */
	public function render_event_status_meta_box( $post ) {
		wp_nonce_field( 'conexao_event_status_meta', 'conexao_event_status_nonce' );

		$current = Conexao_Event_Status::get_status( $post->ID );
		$labels  = Conexao_Event_Status::get_statuses();
		$source  = get_post_meta( $post->ID, '_event_source', true );

		?>
		<p>
			<label for="conexao_event_status"><strong><?php esc_html_e( 'Event status', 'conexao-event-importer' ); ?></strong></label>
			<select id="conexao_event_status" name="conexao_event_status" style="width:100%; margin-top:6px;">
				<?php foreach ( $labels as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current, $key ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<?php if ( $source ) : ?>
			<p style="margin-top:10px;">
				<strong><?php esc_html_e( 'Source', 'conexao-event-importer' ); ?>:</strong>
				<?php echo esc_html( $source ); ?>
			</p>
		<?php endif; ?>

		<p class="description" style="margin-top:10px;">
			<?php esc_html_e( 'Set to "Published" to show this event on the public site.', 'conexao-event-importer' ); ?>
		</p>
		<?php
	}

	/**
	 * Save the event status from the meta box.
	 *
	 * @param int $post_id Post ID.
	 */
	public function save_event_status_meta_box( $post_id ) {
		if ( ! isset( $_POST['conexao_event_status_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['conexao_event_status_nonce'] ) ), 'conexao_event_status_meta' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['conexao_event_status'] ) ) {
			$status = sanitize_text_field( wp_unslash( $_POST['conexao_event_status'] ) );
			Conexao_Event_Status::set_status( $post_id, $status );
		}
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