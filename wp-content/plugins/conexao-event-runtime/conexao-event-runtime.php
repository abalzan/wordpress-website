<?php
/**
 * Plugin Name: Conexão BR Irlanda — Event Runtime
 * Description: Production event runtime. Registers event metadata and the Town/City taxonomy, owns the _event_status visibility gate for public event queries, and provides the event status admin UI. Contains no import/export tooling — see Conexão BR Irlanda Event Importer (local-only).
 * Version: 1.2.1
 * Requires Plugins: conexao-data-model
 * Text Domain: conexao-event-runtime
 *
 * @package Conexao_Event_Runtime
 */

defined( 'ABSPATH' ) || exit;

define( 'CONEXAO_EVENT_RUNTIME_FILE', __FILE__ );
define( 'CONEXAO_EVENT_RUNTIME_VERSION', '1.2.1' );
define( 'CONEXAO_EVENT_RUNTIME_DIR', plugin_dir_path( __FILE__ ) );
define( 'CONEXAO_EVENT_RUNTIME_URL', plugin_dir_url( __FILE__ ) );

require_once CONEXAO_EVENT_RUNTIME_DIR . 'includes/class-event-status.php';
require_once CONEXAO_EVENT_RUNTIME_DIR . 'includes/class-event-recurrence.php';
require_once CONEXAO_EVENT_RUNTIME_DIR . 'includes/class-event-query.php';
require_once CONEXAO_EVENT_RUNTIME_DIR . 'includes/class-source-language.php';

final class Conexao_Event_Runtime {

	/** @var Conexao_Event_Runtime|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'init', array( $this, 'register_town_taxonomy' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_public_event_queries' ) );

		// Admin list columns + status filter for the event post type.
		add_filter( 'manage_event_posts_columns', array( $this, 'event_admin_columns' ) );
		add_action( 'manage_event_posts_custom_column', array( $this, 'event_admin_column_content' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'event_status_filter_dropdown' ) );
		add_filter( 'parse_query', array( $this, 'event_status_filter_query' ) );

		// Event status meta box on the event editor. (Admin UX removes this
		// box on supported types and renders its own sectioned status UI; the
		// registration must stay so the removal keeps working.)
		add_action( 'add_meta_boxes', array( $this, 'add_event_status_meta_box' ) );
		add_action( 'save_post_event', array( $this, 'save_event_status_meta_box' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
	}

	/**
	 * Register event meta fields used by the event runtime.
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
			'_event_map_url',
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
			'_event_recurrence',
			'_event_recurrence_days',
			'_event_recurrence_start',
			'_event_recurrence_end',
			'_event_imported',
			'_event_export_uuid',
			// Stage 3.2 — source-language classification (see
			// Conexao_Event_Source_Language): explicit source signal only,
			// never inferred from content text. Allowed: pt|en|other.
			'_event_source_language',
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
					// All keys are protected (underscore-prefixed). Without an
					// explicit auth_callback, register_meta() defaults to
					// __return_false for protected keys, so every REST write
					// fails with 403 rest_cannot_update — even for
					// administrators (same convention as the recruitment-agency
					// meta in the data-model plugin). Allow exactly what the
					// wp-admin editor allows: users who can edit the event.
					'auth_callback' => function ( $allowed, $meta_key, $object_id ) {
						return current_user_can( 'edit_post', $object_id );
					},
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
					// See the string-meta loop above for why the auth_callback
					// is required on protected meta.
					'auth_callback' => function ( $allowed, $meta_key, $object_id ) {
						return current_user_can( 'edit_post', $object_id );
					},
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
					'name'          => __( 'Cidades', 'conexao-event-runtime' ),
					'singular_name' => __( 'Cidade', 'conexao-event-runtime' ),
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
	 *
	 * The gate is a *frontend* gate only. Two non-public contexts return
	 * immediately without constraining the query:
	 *
	 *   1. `wp-admin` — admin lists / the editor must show every status.
	 *   2. WP-CLI (`defined('WP_CLI') && WP_CLI`) — the local importer and its
	 *      tooling run from the command line (e.g. `wp conexao-events import`).
	 *      Those internal queries MUST be able to see `source_not_found`
	 *      events so deduplication can match a reappearing event and restore
	 *      it instead of re-creating a duplicate. WP-CLI is the smallest safe
	 *      boundary: real public web requests never run under WP-CLI.
	 *      See docs/importers/events-expansion-stage-c2-report.md §5.
	 */
	public function filter_public_event_queries( $query ) {
		if ( is_admin() ) {
			return;
		}

		// Internal importer/tooling (WP-CLI) must query hidden statuses
		// (e.g. source_not_found) for deduplication and reappearing-event
		// restoration. This does NOT affect public frontend requests.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
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
	 * Admin assets for the event list screen (status badge styles).
	 *
	 * Import-dashboard assets remain in the Event Importer plugin; this only
	 * covers the runtime-owned status/source column rendering.
	 *
	 * @param string $hook Current admin hook.
	 */
	public function admin_assets( $hook ) {
		$is_event_screen = 'edit.php' === $hook && isset( $_GET['post_type'] ) && 'event' === $_GET['post_type'];
		if ( ! $is_event_screen ) {
			return;
		}
		wp_enqueue_style( 'conexao-event-runtime-admin', CONEXAO_EVENT_RUNTIME_URL . 'assets/admin.css', array(), CONEXAO_EVENT_RUNTIME_VERSION );
	}

	/**
	 * Add status + source columns to the Events admin list.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function event_admin_columns( $columns ) {
		$columns['conexao_event_status'] = __( 'Status', 'conexao-event-runtime' );
		$columns['conexao_event_source'] = __( 'Source', 'conexao-event-runtime' );
		return $columns;
	}

	/**
	 * Render the status + source columns on the Events admin list.
	 *
	 * The source-name lookup is best effort: the source registry lives in the
	 * local-only Event Importer plugin, so when it is inactive the raw source
	 * key is shown instead.
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
				$name = $source;
				if ( class_exists( 'Conexao_Event_Sources' ) ) {
					$sources = ( new Conexao_Event_Sources() )->get_all();
					if ( isset( $sources[ $source ]['name'] ) ) {
						$name = $sources[ $source ]['name'];
					}
				}
				echo '<span class="conexao-source-label">' . esc_html( $name ) . '</span>';
			} else {
				echo '<span class="conexao-source-label">' . esc_html__( 'Manual', 'conexao-event-runtime' ) . '</span>';
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
			<option value=""><?php esc_html_e( 'Todos os status', 'conexao-event-runtime' ); ?></option>
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
			__( 'Status do Evento', 'conexao-event-runtime' ),
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
			<label for="conexao_event_status"><strong><?php esc_html_e( 'Event status', 'conexao-event-runtime' ); ?></strong></label>
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
				<strong><?php esc_html_e( 'Source', 'conexao-event-runtime' ); ?>:</strong>
				<?php echo esc_html( $source ); ?>
			</p>
		<?php endif; ?>

		<p class="description" style="margin-top:10px;">
			<?php esc_html_e( 'Set to "Published" to show this event on the public site.', 'conexao-event-runtime' ); ?>
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
	 * Activate: register event runtime structures + flush rewrite rules.
	 *
	 * No cron is scheduled, no imports run, and no data is modified — existing
	 * event posts, meta, terms and attachments are left untouched.
	 */
	public static function activate() {
		$plugin = self::instance();
		$plugin->register_meta();
		$plugin->register_town_taxonomy();
		flush_rewrite_rules();
	}

	/**
	 * Deactivate: flush rewrite rules.
	 *
	 * Event data is preserved. Note: deactivating this plugin on production
	 * removes the public `_event_status` gate — it must stay active (see the
	 * production activation plan in docs/plugins/conexao-event-runtime.md).
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}

Conexao_Event_Runtime::instance();

register_activation_hook( __FILE__, array( 'Conexao_Event_Runtime', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Conexao_Event_Runtime', 'deactivate' ) );

/**
 * Load the plugin textdomain (Stage 1 i18n foundation).
 *
 * Translation files live in this plugin's languages/ directory. This does
 * not touch the event status gate, the recurrence evaluator or any event
 * identity logic — gettext wrapping only, no behavior change.
 */
function conexao_event_runtime_load_textdomain() {
	load_plugin_textdomain(
		'conexao-event-runtime',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}
add_action( 'init', 'conexao_event_runtime_load_textdomain' );
