<?php
/**
 * Plugin Name: Conexão BR Irlanda Data Model
 * Description: Content types, shared taxonomies, and editorial fields for the Conexão BR Irlanda portal.
 * Version: 1.2.0
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

	const VERSION = '1.2.0';

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
	}

	public function register_content_types() {
		$post_types = array(
			'guide'           => array( 'plural' => 'Guias Práticos', 'singular' => 'Guia Prático', 'slug' => 'guides', 'icon' => 'dashicons-book-alt' ),
			'event'           => array( 'plural' => 'Eventos', 'singular' => 'Evento', 'slug' => 'events', 'icon' => 'dashicons-calendar-alt' ),
			'course'          => array( 'plural' => 'Cursos', 'singular' => 'Curso', 'slug' => 'courses', 'icon' => 'dashicons-welcome-learn-more' ),
			'job'             => array( 'plural' => 'Empregos', 'singular' => 'Vaga de Emprego', 'slug' => 'jobs', 'icon' => 'dashicons-portfolio' ),
			'sponsor'         => array( 'plural' => 'Apoiadores', 'singular' => 'Apoiador', 'slug' => 'apoiadores', 'icon' => 'dashicons-heart' ),
			'course_provider' => array( 'plural' => 'Cursos', 'singular' => 'Provedor de Cursos', 'slug' => 'provedores-de-cursos', 'icon' => 'dashicons-welcome-learn-more' ),
		);

		foreach ( $post_types as $post_type => $type ) {
			$is_provider = ( 'course_provider' === $post_type );

			// The Cursos page is a WordPress page at /courses/. The legacy
			// "course" CPT must NOT own an archive at /courses/ or it would
			// shadow the page. Individual imported courses (if any) remain
			// reachable via their singular permalinks only.
			$has_archive = $type['slug'];
			if ( 'course' === $post_type || $is_provider ) {
				$has_archive = false;
			}

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
					// Providers are curated directory entries that link directly to
					// an external website. They do not need a public single page, so
					// the post type is admin-managed only.
					'public'             => ! $is_provider,
					'show_ui'            => true,
					'show_in_menu'       => true,
					'show_in_rest'       => true,
					'has_archive'        => $has_archive,
					'rewrite'            => array( 'slug' => $type['slug'], 'with_front' => false ),
					'menu_icon'          => $type['icon'],
					'supports'           => $is_provider
						? array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'custom-fields' )
						: array( 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions', 'page-attributes', 'custom-fields' ),
					'publicly_queryable' => ! $is_provider,
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

	public function register_taxonomies() {
		$content_types = array( 'guide', 'event', 'course', 'job', 'sponsor' );

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
			array( 'guide', 'event', 'course', 'sponsor' ),
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