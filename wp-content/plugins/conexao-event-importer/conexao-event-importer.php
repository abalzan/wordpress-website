<?php
/**
 * Plugin Name: Conexão BR Irlanda — Event Importer
 * Description: Automated Events aggregation from external sources (Laois Tourism, Laois County Council, Local Enterprise Office — Laois, and extensible to more). Imports, normalizes, deduplicates and syncs events into the central WordPress Events database.
 * Version: 1.2.0
 * Text Domain: conexao-event-importer
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

define( 'CONEXAO_EVENT_IMPORTER_FILE', __FILE__ );
define( 'CONEXAO_EVENT_IMPORTER_VERSION', '1.2.0' );
define( 'CONEXAO_EVENT_IMPORTER_DIR', plugin_dir_path( __FILE__ ) );
define( 'CONEXAO_EVENT_IMPORTER_URL', plugin_dir_url( __FILE__ ) );

require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-status.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-source-fetch-exception.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-import-settings.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-source-health.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-import-notifier.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-import-log.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-import-log-admin.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-import-history.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-import-result.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-location.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-normalizer.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-deduplicator.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-sources.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/sources/abstract-class-source.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/sources/class-icalendar-source.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/sources/class-laois-tourism-source.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/sources/class-heritage-week-source.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/sources/class-laois-council-source.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/sources/class-leo-laois-source.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-eventbrite-client.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-eventbrite-parser.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-eventbrite-normalizer.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/sources/class-eventbrite-source.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-image-handler.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-importer.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-import-scheduler.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-import-dashboard.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-export.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-import.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-transfer-admin.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-image-sync-admin.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-cleanup.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-cleanup-admin.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-image-sync-scheduler.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-import-rest.php';

// WP-CLI commands (self-guarding: only registers when WP_CLI is defined).
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-import-cli.php';

final class Conexao_Event_Importer {

	/** @var Conexao_Event_Importer|null */
	private static $instance = null;

	/** @var Conexao_Event_Sources */
	public $sources;

	/** @var Conexao_Event_Importer_Engine */
	public $importer;

	/** @var Conexao_Import_Scheduler */
	public $scheduler;

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

	/** @var Conexao_Event_Image_Sync_Scheduler */
	public $image_sync_scheduler;

	/** @var Conexao_Import_Rest */
	public $rest;

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
		$this->scheduler = new Conexao_Import_Scheduler( $this->importer );
		$this->dashboard = new Conexao_Import_Dashboard( $this->sources, $this->importer );
		$this->transfer  = new Conexao_Event_Transfer_Admin();
		$this->image_sync = new Conexao_Event_Image_Sync_Admin();
		$this->cleanup   = new Conexao_Event_Cleanup();
		$this->cleanup_admin = new Conexao_Event_Cleanup_Admin( $this->cleanup );

		// Import Logs admin screen + secure download / clear actions.
		$this->log_admin = new Conexao_Import_Log_Admin();

		// Nightly external-image sweeper (automation).
		$this->image_sync_scheduler = new Conexao_Event_Image_Sync_Scheduler();

		// Token-protected REST endpoints: /conexao-events/v1/run and /status.
		$this->rest = new Conexao_Import_Rest( $this->scheduler );

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

		// Event status meta box on the event editor, so admins can publish/review events.
		add_action( 'add_meta_boxes', array( $this, 'add_event_status_meta_box' ) );
		add_action( 'save_post_event', array( $this, 'save_event_status_meta_box' ) );

		// Ensure cron is scheduled.
		add_action( 'admin_init', array( $this->scheduler, 'maybe_schedule' ) );

		// Ensure the cleanup cron is scheduled (idempotent).
		add_action( 'admin_init', array( $this->cleanup, 'maybe_schedule' ) );

		// Ensure the nightly image-sync sweeper cron is scheduled (idempotent).
		add_action( 'admin_init', array( $this->image_sync_scheduler, 'maybe_schedule' ) );

		// Health banner: surface importer problems to admins without digging
		// through logs (failing/stale sources, stuck runs).
		add_action( 'admin_notices', array( $this, 'render_health_banner' ) );

		// Allow webcal:// protocol in URLs.
		add_filter( 'kses_allowed_protocols', array( $this, 'allow_webcal_protocol' ) );
	}

	/**
	 * Render a persistent health banner on Event Import screens when problems
	 * are detected: failing or stale sources, or a run that appears stuck.
	 *
	 * Only shown to users who can manage options, only on importer screens,
	 * so it never nags the rest of wp-admin.
	 */
	public function render_health_banner() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$hook   = $screen ? (string) $screen->id : '';

		$is_relevant = false !== strpos( $hook, 'conexao-event' )
			|| false !== strpos( $hook, 'conexao-import' )
			|| 'edit-event' === $hook;

		if ( ! $is_relevant ) {
			return;
		}

		$problems = array();

		// Stuck run detection.
		if ( $this->scheduler->is_locked() && ! $this->scheduler->is_running() ) {
			$problems[] = __( 'An import lock is held but no run is active — it may be stuck. Use "wp conexao-events unlock" or Event Import → Settings to recover.', 'conexao-event-importer' );
		}

		// Failing / stale sources.
		foreach ( $this->sources->get_all() as $source ) {
			$health = Conexao_Source_Health::get( $source['id'] );
			$name   = isset( $source['name'] ) ? $source['name'] : $source['id'];

			if ( ! empty( $health['disabled_at'] ) && 'inactive' === ( isset( $source['status'] ) ? $source['status'] : '' ) ) {
				$problems[] = sprintf(
					/* translators: 1: source name, 2: reason */
					__( '"%1$s" was auto-disabled: %2$s', 'conexao-event-importer' ),
					$name,
					$health['disable_reason']
				);
			} elseif ( (int) $health['consecutive_failures'] > 0 && 'active' === ( isset( $source['status'] ) ? $source['status'] : '' ) ) {
				$problems[] = sprintf(
					/* translators: 1: source name, 2: failure count */
					__( '"%1$s" has %2$d consecutive failed import(s).', 'conexao-event-importer' ),
					$name,
					(int) $health['consecutive_failures']
				);
			} elseif ( Conexao_Source_Health::is_stale( $source ) ) {
				$problems[] = sprintf(
					/* translators: %s: source name */
					__( '"%s" has not had a successful import within its expected frequency window.', 'conexao-event-importer' ),
					$name
				);
			}
		}

		if ( empty( $problems ) ) {
			return;
		}

		?>
		<div class="notice notice-warning">
			<p><strong><?php esc_html_e( 'Event Importer health:', 'conexao-event-importer' ); ?></strong></p>
			<ul style="margin-left:18px; list-style:disc;">
				<?php foreach ( array_slice( $problems, 0, 8 ) as $problem ) : ?>
					<li><?php echo esc_html( $problem ); ?></li>
				<?php endforeach; ?>
			</ul>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=conexao-import-settings' ) ); ?>">
					<?php esc_html_e( 'Open Import Settings', 'conexao-event-importer' ); ?>
				</a>
				&nbsp;·&nbsp;
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=conexao-import-log' ) ); ?>">
					<?php esc_html_e( 'View Import Logs', 'conexao-event-importer' ); ?>
				</a>
			</p>
		</div>
		<?php
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
			'_event_review_note',
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
	 * "Próximos Eventos" section). This keeps draft / needs-review / expired /
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
		$note    = get_post_meta( $post->ID, '_event_review_note', true );

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

		<?php if ( $note ) : ?>
			<p style="margin-top:10px;">
				<strong><?php esc_html_e( 'Review note', 'conexao-event-importer' ); ?>:</strong><br>
				<em><?php echo esc_html( $note ); ?></em>
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

			// If set to published, clear the review note.
			if ( Conexao_Event_Status::PUBLISHED === $status ) {
				delete_post_meta( $post_id, '_event_review_note' );
			}
		}
	}

	public static function activate() {
		require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-sources.php';
		$sources = new Conexao_Event_Sources();
		$sources->seed_defaults();

		require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-import-scheduler.php';
		$importer = null;
		$scheduler = new Conexao_Import_Scheduler( $importer );
		$scheduler->schedule();

		require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-cleanup.php';
		$cleanup = new Conexao_Event_Cleanup();
		$cleanup->schedule();

		require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-image-sync-scheduler.php';
		$image_sync_scheduler = new Conexao_Event_Image_Sync_Scheduler();
		$image_sync_scheduler->maybe_schedule();

		flush_rewrite_rules();
	}

	public static function deactivate() {
		require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-import-scheduler.php';
		Conexao_Import_Scheduler::clear_schedule();

		require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-cleanup.php';
		Conexao_Event_Cleanup::clear_schedule();

		require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-image-sync-scheduler.php';
		Conexao_Event_Image_Sync_Scheduler::clear_schedule();

		flush_rewrite_rules();
	}
}

Conexao_Event_Importer::instance();

register_activation_hook( __FILE__, array( 'Conexao_Event_Importer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Conexao_Event_Importer', 'deactivate' ) );