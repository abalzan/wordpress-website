<?php
/**
 * Plugin Name: Conexão BR Irlanda Data Model
 * Description: Content types, shared taxonomies, and editorial fields for the Conexão BR Irlanda portal.
 * Version: 1.3.0
 * Text Domain: conexao-data-model
 *
 * @package Conexao_BR_Irlanda_Data_Model
 */

defined( 'ABSPATH' ) || exit;

define( 'CONEXAO_DATA_MODEL_FILE', __FILE__ );
define( 'CONEXAO_DATA_MODEL_DIR', plugin_dir_path( __FILE__ ) );

require_once CONEXAO_DATA_MODEL_DIR . 'includes/class-relationships.php';
require_once CONEXAO_DATA_MODEL_DIR . 'includes/class-meta.php';

final class Conexao_Data_Model {

	const VERSION = '1.3.0';

	/** @var Conexao_Data_Model|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_content_types' ), 0 );
		add_action( 'init', array( $this, 'register_taxonomies' ), 0 );
		add_action( 'init', array( $this, 'register_provider_meta' ), 0 );
		add_action( 'init', array( $this, 'register_leisure_meta' ), 0 );
	}

	public function register_content_types() {
		$post_types = array(
			// Portuguese slugs are the canonical URLs for the portal.
			// English slugs redirect to these for backward compatibility.
			'guide'           => array( 'plural' => 'Guias Práticos', 'singular' => 'Guia Prático', 'slug' => 'guias', 'icon' => 'dashicons-book-alt' ),
			'event'           => array( 'plural' => 'Eventos', 'singular' => 'Evento', 'slug' => 'eventos', 'icon' => 'dashicons-calendar-alt' ),
			'job'             => array( 'plural' => 'Empregos', 'singular' => 'Vaga de Emprego', 'slug' => 'empregos', 'icon' => 'dashicons-portfolio' ),
			'sponsor'         => array( 'plural' => 'Apoiadores', 'singular' => 'Apoiador', 'slug' => 'apoiadores', 'icon' => 'dashicons-heart' ),
			'course_provider' => array( 'plural' => 'Cursos', 'singular' => 'Provedor de Cursos', 'slug' => 'cursos', 'icon' => 'dashicons-welcome-learn-more' ),
			'leisure'         => array( 'plural' => 'Lazer e Turismo', 'singular' => 'Local de Lazer', 'slug' => 'lazer', 'icon' => 'dashicons-palmtree' ),
		);

		foreach ( $post_types as $post_type => $type ) {
			// course_provider is a curated directory with a public archive at /cursos/.
			// Individual course pages are not used; each provider links to an external website.
			$is_provider      = ( 'course_provider' === $post_type );
			$has_archive      = $type['slug'];

			register_post_type(
				$post_type,
				array(
					'labels' => array(
						'name'          => $type['plural'],
						'singular_name' => $type['singular'],
						'add_new_item'  => 'Adicionar ' . $type['singular'],
						'edit_item'     => 'Editar ' . $type['singular'],
						'new_item'      => 'Novo ' . $type['singular'],
						'view_item'     => 'Ver ' . $type['singular'],
						'search_items'  => 'Buscar ' . $type['plural'],
						'not_found'     => 'Nenhum ' . $type['singular'] . ' encontrado',
						'not_found_in_trash' => 'Nenhum ' . $type['singular'] . ' encontrado na lixeira',
						'all_items'     => 'Todos os ' . $type['plural'],
						'archives'      => $type['plural'],
					),
					// All post types are publicly queryable to support their archives.
					'public'             => true,
					'show_ui'            => true,
					'show_in_menu'       => true,
					'show_in_rest'       => true,
					'has_archive'        => $has_archive,
					'rewrite'            => array( 'slug' => $type['slug'], 'with_front' => false ),
					'menu_icon'          => $type['icon'],
					'supports'           => $is_provider
						? array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'custom-fields' )
						: array( 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions', 'page-attributes', 'custom-fields' ),
					'publicly_queryable' => true,
				)
			);
		}
	}

	/**
	 * Register meta fields for the course provider directory entries.
	 */
	public function register_provider_meta() {
		$meta = array(
			'_provider_logo'     => 'integer', // Media attachment ID.
			'_provider_category' => 'string',
			'_provider_location' => 'string',
			'_provider_url'      => 'string',
			'_provider_status'   => 'string',
			'_provider_order'    => 'integer',
		);

		foreach ( $meta as $key => $type ) {
			register_post_meta(
				'course_provider',
				$key,
				array(
					'single'       => true,
					'type'         => $type,
					'show_in_rest' => true,
				)
			);
		}
	}

	/**
	 * Register meta fields for leisure / tourism directory entries.
	 *
	 * Each field is optional; empty values are simply not rendered. Fields map
	 * to the Data Fields spec for the /lazer/ directory.
	 */
	public function register_leisure_meta() {
		$meta = array(
			'_leisure_county'        => 'string', // County (also stored as conexao_county term).
			'_leisure_town'          => 'string', // Town / city.
			'_leisure_address'       => 'string', // Street address.
			'_leisure_website'       => 'string', // Legacy official website URL field.
			'_leisure_official_website' => 'string', // Official website URL (primary external destination).
			'_leisure_discover_ireland' => 'string', // Discover Ireland reference URL (fallback external destination).
			'_leisure_map_url'       => 'string', // Google Maps / location URL.
			'_leisure_feature'       => 'boolean', // Featured destination.
			'_leisure_free'          => 'string', // Gratuito / Pago.
			'_leisure_family'        => 'boolean', // Adequado para famílias.
			'_leisure_accessibility' => 'boolean', // Acessibilidade.
			'_leisure_pet_friendly'  => 'boolean', // Pet friendly.
			'_leisure_indoor'        => 'boolean', // Interior.
			'_leisure_outdoor'       => 'boolean', // Exterior.
			'_leisure_parking'       => 'boolean', // Estacionamento.
			'_leisure_booking'       => 'boolean', // Necessita reserva.
			'_leisure_duration'      => 'string', // Duração recomendada.
			'_leisure_best_time'     => 'string', // Melhor época para visitar.

			// --- Leisure image / source fields ---
			// The location's main image. Always a WordPress Media Library
			// attachment ID (`_leisure_image_attachment_id`), which yields a
			// responsive local image (srcset/width/height) and is set as the
			// post thumbnail by the Admin UX editor.
			'_leisure_image_attachment_id' => 'integer', // Local Media Library attachment ID.
			// Image source label (e.g. "Discover Ireland", "Site oficial",
			// "Biblioteca de mídia", "Wikimedia Commons", "Enviada").
			'_leisure_image_source'        => 'string',
			// The source page URL where the image / more info can be found
			// (e.g. the Wikimedia Commons file page). Used for attribution.
			'_leisure_image_source_url'    => 'string',
			// Photographer / author of the image (from Commons extmetadata).
			'_leisure_image_author'        => 'string',
			// License short name (e.g. "CC BY-SA 4.0", "Public Domain").
			'_leisure_image_license'       => 'string',
			// Optional attribution text (photographer / licence) when required.
			'_leisure_image_attribution'   => 'string',
			// Accessibility alt text for the image (e.g. "Cliffs of Moher, County Clare").
			'_leisure_image_alt_text'      => 'string',
			// Image state: 'none' (no image), 'pending' (awaiting a properly
			// licensed image), 'local' (WordPress Media Library). Defaults to 'none'.
			'_leisure_image_status'        => 'string',
		);

		foreach ( $meta as $key => $type ) {
			register_post_meta(
				'leisure',
				$key,
				array(
					'single'       => true,
					'type'         => $type,
					'show_in_rest' => true,
				)
			);
		}
	}

	public function register_taxonomies() {
		$content_types = array( 'guide', 'event', 'job', 'sponsor', 'course_provider', 'leisure' );

		register_taxonomy(
			'conexao_category',
			$content_types,
			array(
				'labels'            => array( 'name' => 'Categories', 'singular_name' => 'Category' ),
				'public'            => true,
				'hierarchical'      => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => 'categories', 'with_front' => false ),
			)
		);

		register_taxonomy(
			'conexao_county',
			$content_types,
			array(
				'labels'            => array( 'name' => 'Counties', 'singular_name' => 'County' ),
				'public'            => true,
				'hierarchical'      => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => 'counties', 'with_front' => false ),
			)
		);

		register_taxonomy(
			'conexao_tag',
			array( 'guide', 'event', 'job', 'sponsor', 'course_provider', 'leisure' ),
			array(
				'labels'            => array( 'name' => 'Tags', 'singular_name' => 'Tag' ),
				'public'            => true,
				'hierarchical'      => false,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => 'tags', 'with_front' => false ),
			)
		);
	}

	public static function activate() {
		$plugin = self::instance();
		$plugin->register_content_types();
		$plugin->register_taxonomies();
		$plugin->register_provider_meta();
		Conexao_Data_Model_Relationships::seed_terms();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}
}

Conexao_Data_Model::instance();

register_activation_hook( __FILE__, array( 'Conexao_Data_Model', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Conexao_Data_Model', 'deactivate' ) );