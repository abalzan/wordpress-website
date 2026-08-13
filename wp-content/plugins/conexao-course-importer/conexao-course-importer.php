<?php
/**
 * Plugin Name: Conexão BR Irlanda — Course Importer
 * Description: Automated Courses aggregation from external sources. Imports, normalizes, deduplicates and syncs courses into the central WordPress Courses database.
 * Version: 1.0.0
 * Text Domain: conexao-course-importer
 *
 * @package Conexao_Course_Importer
 */

defined( 'ABSPATH' ) || exit;

define( 'CONEXAO_COURSE_IMPORTER_FILE', __FILE__ );
define( 'CONEXAO_COURSE_IMPORTER_VERSION', '1.0.0' );
define( 'CONEXAO_COURSE_IMPORTER_DIR', plugin_dir_path( __FILE__ ) );
define( 'CONEXAO_COURSE_IMPORTER_URL', plugin_dir_url( __FILE__ ) );

require_once CONEXAO_COURSE_IMPORTER_DIR . 'includes/class-course-status.php';
require_once CONEXAO_COURSE_IMPORTER_DIR . 'includes/class-course-location.php';
require_once CONEXAO_COURSE_IMPORTER_DIR . 'includes/class-course-normalizer.php';
require_once CONEXAO_COURSE_IMPORTER_DIR . 'includes/class-course-deduplicator.php';
require_once CONEXAO_COURSE_IMPORTER_DIR . 'includes/class-course-image-handler.php';
require_once CONEXAO_COURSE_IMPORTER_DIR . 'includes/class-course-sources.php';
require_once CONEXAO_COURSE_IMPORTER_DIR . 'includes/sources/abstract-class-course-source.php';
require_once CONEXAO_COURSE_IMPORTER_DIR . 'includes/sources/class-course-website-source.php';
require_once CONEXAO_COURSE_IMPORTER_DIR . 'includes/sources/class-leo-laois-source.php';
require_once CONEXAO_COURSE_IMPORTER_DIR . 'includes/class-course-importer.php';
require_once CONEXAO_COURSE_IMPORTER_DIR . 'includes/class-course-import-scheduler.php';
require_once CONEXAO_COURSE_IMPORTER_DIR . 'includes/class-course-import-dashboard.php';

final class Conexao_Course_Importer {

	/** @var Conexao_Course_Importer|null */
	private static $instance = null;

	/** @var Conexao_Course_Sources */
	public $sources;

	/** @var Conexao_Course_Importer_Engine */
	public $importer;

	/** @var Conexao_Course_Import_Scheduler */
	public $scheduler;

	/** @var Conexao_Course_Import_Dashboard */
	public $dashboard;

	/** @var Conexao_Course_Location */
	public $location;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->sources   = new Conexao_Course_Sources();
		$this->location  = new Conexao_Course_Location();
		$this->importer  = new Conexao_Course_Importer_Engine( $this->sources, $this->location );
		$this->scheduler = new Conexao_Course_Import_Scheduler( $this->importer );
		$this->dashboard = new Conexao_Course_Import_Dashboard( $this->sources, $this->importer );

		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'init', array( $this, 'register_town_taxonomy' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_public_course_queries' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );

		// Admin list columns + status filter for the course post type.
		add_filter( 'manage_course_posts_columns', array( $this, 'course_admin_columns' ) );
		add_action( 'manage_course_posts_custom_column', array( $this, 'course_admin_column_content' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'course_status_filter_dropdown' ) );
		add_filter( 'parse_query', array( $this, 'course_status_filter_query' ) );

		// Course status meta box on the course editor.
		add_action( 'add_meta_boxes', array( $this, 'add_course_status_meta_box' ) );
		add_action( 'save_post_course', array( $this, 'save_course_status_meta_box' ) );

		// Ensure cron is scheduled.
		add_action( 'admin_init', array( $this->scheduler, 'maybe_schedule' ) );
	}

	/**
	 * Register course meta fields used by the importer.
	 */
	public function register_meta() {
		$string_meta = array(
			'_course_date',
			'_course_time',
			'_course_start_time',
			'_course_end_date',
			'_course_end_time',
			'_course_duration',
			'_course_location',
			'_course_venue',
			'_course_address',
			'_course_town',
			'_course_county',
			'_course_url',
			'_course_source_url',
			'_course_banner',
			'_course_source',
			'_course_source_id',
			'_course_organizer',
			'_course_price',
			'_course_currency',
			'_course_booking_url',
			'_course_category',
			'_course_type',
			'_course_delivery_mode',
			'_course_import_date',
			'_course_last_checked',
			'_course_status',
			'_course_imported',
			'_course_review_note',
		);

		$int_meta = array(
			'_course_banner_attachment_id',
		);

		foreach ( $string_meta as $key ) {
			register_post_meta(
				'course',
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
				'course',
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
	 * Register the Town/City taxonomy for course location hierarchy.
	 */
	public function register_town_taxonomy() {
		// Only register if not already registered by the event importer.
		if ( ! taxonomy_exists( 'conexao_town' ) ) {
			register_taxonomy(
				'conexao_town',
				array( 'course' ),
				array(
					'labels'            => array(
						'name'          => __( 'Cidades', 'conexao-course-importer' ),
						'singular_name' => __( 'Cidade', 'conexao-course-importer' ),
					),
					'public'            => true,
					'hierarchical'      => true,
					'show_in_rest'      => true,
					'show_admin_column' => true,
					'rewrite'           => array( 'slug' => 'towns', 'with_front' => false ),
				)
			);
		} else {
			// Register the taxonomy for the course post type as well.
			register_taxonomy_for_object_type( 'conexao_town', 'course' );
		}
	}

	/**
	 * Public course queries should only show "published" or legacy courses.
	 */
	public function filter_public_course_queries( $query ) {
		if ( is_admin() ) {
			return;
		}

		$post_types = $query->get( 'post_type' );
		if ( is_array( $post_types ) ) {
			if ( ! in_array( 'course', $post_types, true ) ) {
				return;
			}
		} elseif ( 'course' !== $post_types && ! $query->is_post_type_archive( 'course' ) && ! $query->is_tax( array( 'conexao_county', 'conexao_town', 'conexao_category' ) ) ) {
			return;
		}

		$existing = $query->get( 'meta_query' );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$existing[] = array(
			'relation' => 'OR',
			array(
				'key'     => '_course_status',
				'value'   => 'published',
				'compare' => '=',
			),
			array(
				'key'     => '_course_status',
				'compare' => 'NOT EXISTS',
			),
		);

		$query->set( 'meta_query', $existing );
	}

	/**
	 * Admin assets for the course import dashboard / sources screens.
	 */
	public function admin_assets( $hook ) {
		$is_course_screen = 'edit.php' === $hook && isset( $_GET['post_type'] ) && 'course' === $_GET['post_type'];
		if ( false === strpos( $hook, 'conexao-courses' ) && false === strpos( $hook, 'conexao-course-import' ) && ! $is_course_screen ) {
			return;
		}
		wp_enqueue_style( 'conexao-course-importer-admin', CONEXAO_COURSE_IMPORTER_URL . 'assets/admin.css', array(), CONEXAO_COURSE_IMPORTER_VERSION );
	}

	/**
	 * Add status + source columns to the Courses admin list.
	 */
	public function course_admin_columns( $columns ) {
		$columns['conexao_course_status'] = __( 'Status', 'conexao-course-importer' );
		$columns['conexao_course_source'] = __( 'Source', 'conexao-course-importer' );
		return $columns;
	}

	/**
	 * Render the status + source columns on the Courses admin list.
	 */
	public function course_admin_column_content( $column, $post_id ) {
		if ( 'conexao_course_status' === $column ) {
			$status = Conexao_Course_Status::get_status( $post_id );
			$labels = Conexao_Course_Status::get_statuses();
			$label  = isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
			echo '<span class="conexao-status-badge conexao-status-badge--' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
		}

		if ( 'conexao_course_source' === $column ) {
			$source = get_post_meta( $post_id, '_course_source', true );
			if ( $source ) {
				$sources = $this->sources->get_all();
				$name    = isset( $sources[ $source ]['name'] ) ? $sources[ $source ]['name'] : $source;
				echo '<span class="conexao-source-label">' . esc_html( $name ) . '</span>';
			} else {
				echo '<span class="conexao-source-label">' . esc_html__( 'Manual', 'conexao-course-importer' ) . '</span>';
			}
		}
	}

	/**
	 * Render a status filter dropdown on the Courses admin list.
	 */
	public function course_status_filter_dropdown( $post_type ) {
		if ( 'course' !== $post_type ) {
			return;
		}

		$current = isset( $_GET['course_status'] ) ? sanitize_text_field( wp_unslash( $_GET['course_status'] ) ) : '';
		$labels  = Conexao_Course_Status::get_statuses();
		?>
		<select name="course_status">
			<option value=""><?php esc_html_e( 'Todos os status', 'conexao-course-importer' ); ?></option>
			<?php foreach ( $labels as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current, $key ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Apply the status filter to the Courses admin list query.
	 */
	public function course_status_filter_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$post_type = $query->get( 'post_type' );
		if ( 'course' !== $post_type ) {
			return;
		}

		$status = isset( $_GET['course_status'] ) ? sanitize_text_field( wp_unslash( $_GET['course_status'] ) ) : '';
		if ( empty( $status ) ) {
			return;
		}

		$meta_query = $query->get( 'meta_query' );
		if ( ! is_array( $meta_query ) ) {
			$meta_query = array();
		}

		$meta_query[] = array(
			'key'   => '_course_status',
			'value' => $status,
		);

		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Add the course status meta box to the course editor.
	 */
	public function add_course_status_meta_box( $post_type ) {
		if ( 'course' !== $post_type ) {
			return;
		}
		add_meta_box(
			'conexao_course_status_box',
			__( 'Status do Curso', 'conexao-course-importer' ),
			array( $this, 'render_course_status_meta_box' ),
			'course',
			'side',
			'high'
		);
	}

	/**
	 * Render the course status meta box.
	 */
	public function render_course_status_meta_box( $post ) {
		wp_nonce_field( 'conexao_course_status_meta', 'conexao_course_status_nonce' );

		$current = Conexao_Course_Status::get_status( $post->ID );
		$labels  = Conexao_Course_Status::get_statuses();
		$source  = get_post_meta( $post->ID, '_course_source', true );
		$note    = get_post_meta( $post->ID, '_course_review_note', true );

		?>
		<p>
			<label for="conexao_course_status"><strong><?php esc_html_e( 'Course status', 'conexao-course-importer' ); ?></strong></label>
			<select id="conexao_course_status" name="conexao_course_status" style="width:100%; margin-top:6px;">
				<?php foreach ( $labels as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current, $key ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<?php if ( $source ) : ?>
			<p style="margin-top:10px;">
				<strong><?php esc_html_e( 'Source', 'conexao-course-importer' ); ?>:</strong>
				<?php echo esc_html( $source ); ?>
			</p>
		<?php endif; ?>

		<?php if ( $note ) : ?>
			<p style="margin-top:10px;">
				<strong><?php esc_html_e( 'Review note', 'conexao-course-importer' ); ?>:</strong><br>
				<em><?php echo esc_html( $note ); ?></em>
			</p>
		<?php endif; ?>

		<p class="description" style="margin-top:10px;">
			<?php esc_html_e( 'Set to "Published" to show this course on the public site.', 'conexao-course-importer' ); ?>
		</p>
		<?php
	}

	/**
	 * Save the course status from the meta box.
	 */
	public function save_course_status_meta_box( $post_id ) {
		if ( ! isset( $_POST['conexao_course_status_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['conexao_course_status_nonce'] ) ), 'conexao_course_status_meta' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['conexao_course_status'] ) ) {
			$status = sanitize_text_field( wp_unslash( $_POST['conexao_course_status'] ) );
			Conexao_Course_Status::set_status( $post_id, $status );

			if ( Conexao_Course_Status::PUBLISHED === $status ) {
				delete_post_meta( $post_id, '_course_review_note' );
			}
		}
	}

	public static function activate() {
		require_once CONEXAO_COURSE_IMPORTER_DIR . 'includes/class-course-sources.php';
		$sources = new Conexao_Course_Sources();
		$sources->seed_defaults();

		require_once CONEXAO_COURSE_IMPORTER_DIR . 'includes/class-course-import-scheduler.php';
		$importer  = null;
		$scheduler = new Conexao_Course_Import_Scheduler( $importer );
		$scheduler->schedule();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		require_once CONEXAO_COURSE_IMPORTER_DIR . 'includes/class-course-import-scheduler.php';
		Conexao_Course_Import_Scheduler::clear_schedule();
		flush_rewrite_rules();
	}
}

Conexao_Course_Importer::instance();

register_activation_hook( __FILE__, array( 'Conexao_Course_Importer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Conexao_Course_Importer', 'deactivate' ) );