<?php
/**
 * Plugin Name: Conexão BR Irlanda — Lazer Migration
 * Description: Export and import the /lazer/ (leisure) dataset as a self-contained ZIP package containing data.json and the actual image files from the Media Library. Imports into another installation with dry-run preview, dedupe by stable UUID + image ID, Media Library attachment creation, legacy-data cleanup, and post-import verification. Production images are always local — no dependency on Wikimedia Commons for delivery.
 * Version: 2.0.0
 * Text Domain: conexao-leisure-migration
 *
 * @package Conexao_Lazer_Migration
 */

defined( 'ABSPATH' ) || exit;

define( 'CONEXAO_LAZER_MIGRATION_FILE', __FILE__ );
define( 'CONEXAO_LAZER_MIGRATION_DIR', plugin_dir_path( __FILE__ ) );

require_once CONEXAO_LAZER_MIGRATION_DIR . 'includes/class-leisure-exporter.php';
require_once CONEXAO_LAZER_MIGRATION_DIR . 'includes/class-leisure-importer.php';
require_once CONEXAO_LAZER_MIGRATION_DIR . 'includes/class-leisure-maintenance.php';
require_once CONEXAO_LAZER_MIGRATION_DIR . 'includes/class-leisure-transfer-admin.php';

final class Conexao_Lazer_Migration {

	const VERSION = '2.0.0';

	/** @var Conexao_Lazer_Migration|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		// Register/verify the structure on init so the rewrite API is available
		// and, when conexao-data-model is active, it has already registered the
		// leisure CPT + taxonomies (so these helpers no-op on production).
		add_action( 'init', array( $this, 'maybe_register_helpers' ), 20 );

		add_action( 'plugins_loaded', array( $this, 'load_ui' ) );
	}

	/**
	 * Boot the admin UI + importer/exporter.
	 *
	 * The importer is deliberately additive: it only ever reads/writes leisure
	 * posts, leisure taxonomies (matched by name), and Media Library
	 * attachments created for leisure images. It never touches events, guides,
	 * jobs, courses, blog posts, pages, supporters, or unrelated media.
	 */
	public function load_ui() {
		new Conexao_Lazer_Transfer_Admin();
	}

	/**
	 * Register the leisure CPT + taxonomies if the data-model plugin is absent.
	 *
	 * This keeps the importer usable on a production install that does not yet
	 * have the Conexão data model active: the importer provisions the expected
	 * structure (mirroring conexao-data-model) so imported data is not written
	 * into an inconsistent schema. When the data-model plugin exists it already
	 * registers these, so we do nothing.
	 */
	public function maybe_register_helpers() {
		if ( post_type_exists( 'leisure' ) ) {
			return;
		}

		register_post_type(
			'leisure',
			array(
				'labels'          => array(
					'name'          => 'Lazer e Turismo',
					'singular_name' => 'Local de Lazer',
					'add_new_item'  => 'Adicionar Local de Lazer',
					'edit_item'     => 'Editar Local de Lazer',
					'new_item'      => 'Novo Local de Lazer',
					'view_item'     => 'Ver Local de Lazer',
					'search_items'  => 'Buscar Locais de Lazer',
					'not_found'     => 'Nenhum Local de Lazer encontrado',
					'all_items'     => 'Todos os Locais de Lazer',
				),
				'public'             => true,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_rest'       => true,
				'has_archive'        => 'lazer',
				'rewrite'            => array( 'slug' => 'lazer', 'with_front' => false ),
				'menu_icon'          => 'dashicons-palmtree',
				'supports'           => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions', 'page-attributes', 'custom-fields' ),
				'publicly_queryable' => true,
			)
		);

		$this->register_taxonomy_if_missing( 'conexao_category', true );
		$this->register_taxonomy_if_missing( 'conexao_county', true );
		$this->register_taxonomy_if_missing( 'conexao_tag', false );

		flush_rewrite_rules();
	}

	/**
	 * Register a shared taxonomy if it does not exist yet.
	 *
	 * @param string $taxonomy      Taxonomy slug.
	 * @param bool   $hierarchical  Whether it is hierarchical.
	 */
	protected function register_taxonomy_if_missing( $taxonomy, $hierarchical ) {
		if ( taxonomy_exists( $taxonomy ) ) {
			return;
		}

		register_taxonomy(
			$taxonomy,
			array( 'guide', 'event', 'job', 'sponsor', 'course_provider', 'leisure' ),
			array(
				'labels'            => array(
					'name'          => ucfirst( $taxonomy === 'conexao_county' ? 'Counties' : ( $taxonomy === 'conexao_tag' ? 'Tags' : 'Categories' ) ),
					'singular_name' => ucfirst( $taxonomy === 'conexao_county' ? 'County' : ( $taxonomy === 'conexao_tag' ? 'Tag' : 'Category' ) ),
				),
				'public'            => true,
				'hierarchical'      => $hierarchical,
				'show_in_rest'      => true,
				'show_admin_column' => true,
			)
		);
	}
}

Conexao_Lazer_Migration::instance();