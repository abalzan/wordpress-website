<?php
/**
 * Plugin Name: Conexão BR Irlanda — Event Importer
 * Description: Automated Events aggregation from external sources (Laois Tourism, Laois County Council, and extensible to more). Imports, normalizes, deduplicates and syncs events into the central WordPress Events database.
 * Version: 1.0.0
 * Text Domain: conexao-event-importer
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

define( 'CONEXAO_EVENT_IMPORTER_FILE', __FILE__ );
define( 'CONEXAO_EVENT_IMPORTER_VERSION', '1.0.0' );
define( 'CONEXAO_EVENT_IMPORTER_DIR', plugin_dir_path( __FILE__ ) );
define( 'CONEXAO_EVENT_IMPORTER_URL', plugin_dir_url( __FILE__ ) );

require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-status.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-import-log.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-import-history.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-location.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-normalizer.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-deduplicator.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-sources.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/sources/abstract-class-source.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/sources/class-icalendar-source.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/sources/class-laois-tourism-source.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/sources/class-laois-council-source.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-event-importer.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-import-scheduler.php';
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-import-dashboard.php';

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

		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'init', array( $this, 'register_town_taxonomy' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_public_event_queries' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );

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

		// Allow webcal:// protocol in URLs.
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
			'_event_review_note',
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

		$existing = $query->get( 'meta_query' );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$existing[] = array(
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

		$query->set( 'meta_query', $existing );
	}

	/**
	 * Admin assets for the import dashboard / sources screens.
	 */
	public function admin_assets( $hook ) {
		$is_event_screen = 'edit.php' === $hook && isset( $_GET['post_type'] ) && 'event' === $_GET['post_type'];
		if ( false === strpos( $hook, 'conexao-events' ) && false === strpos( $hook, 'conexao-event-import' ) && ! $is_event_screen ) {
			return;
		}
		wp_enqueue_style( 'conexao-event-importer-admin', CONEXAO_EVENT_IMPORTER_URL . 'assets/admin.css', array(), CONEXAO_EVENT_IMPORTER_VERSION );
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
		flush_rewrite_rules();
	}

	public static function deactivate() {
		require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-import-scheduler.php';
		Conexao_Import_Scheduler::clear_schedule();
		flush_rewrite_rules();
	}
}

Conexao_Event_Importer::instance();

register_activation_hook( __FILE__, array( 'Conexao_Event_Importer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Conexao_Event_Importer', 'deactivate' ) );