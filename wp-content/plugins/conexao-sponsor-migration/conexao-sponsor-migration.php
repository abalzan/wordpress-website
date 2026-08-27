<?php
/**
 * Plugin Name: Conexão BR Irlanda — Apoiadores Migration
 * Description: Export and import the Apoiadores (sponsors) dataset as a portable JSON file with the canonical Apoiador image embedded as bytes, so production imports recreate the Media Library attachment locally and assign it to the correct Apoiador. Legacy Desktop/Mobile exports are resolved into the single canonical image. Dedupe by stable UUID + image content hash; dry-run preview; no external requests required.
 * Version: 1.1.0
 * Text Domain: conexao-sponsor-migration
 *
 * @package Conexao_Sponsor_Migration
 */

defined( 'ABSPATH' ) || exit;

define( 'CONEXAO_SPONSOR_MIGRATION_FILE', __FILE__ );
define( 'CONEXAO_SPONSOR_MIGRATION_DIR', plugin_dir_path( __FILE__ ) );

require_once CONEXAO_SPONSOR_MIGRATION_DIR . 'includes/class-sponsor-exporter.php';
require_once CONEXAO_SPONSOR_MIGRATION_DIR . 'includes/class-sponsor-importer.php';
require_once CONEXAO_SPONSOR_MIGRATION_DIR . 'includes/class-sponsor-transfer-admin.php';

final class Conexao_Sponsor_Migration {

	const VERSION = '1.1.0';

	/** @var Conexao_Sponsor_Migration|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		// Register/verify the structure on init so, when conexao-data-model is
		// active, it has already registered the sponsor CPT + taxonomies (and
		// these helpers no-op). On a fresh production install without the data
		// model, the importer provisions the expected structure itself.
		add_action( 'init', array( $this, 'maybe_register_helpers' ), 20 );

		add_action( 'plugins_loaded', array( $this, 'load_ui' ) );
	}

	/**
	 * Boot the admin UI + exporter/importer.
	 *
	 * The transfer is deliberately additive: it only ever reads/writes sponsor
	 * posts, the shared conexao_category/conexao_county taxonomies (matched by
	 * name), and Media Library attachments created for sponsor images. It never
	 * touches events, guides, jobs, courses, leisure, blog posts, pages, or
	 * unrelated media.
	 */
	public function load_ui() {
		new Conexao_Sponsor_Transfer_Admin();
	}

	/**
	 * Register the sponsor CPT + shared taxonomies if they do not exist yet.
	 *
	 * Mirrors conexao-data-model's registration so imported data is never
	 * written into an inconsistent schema on a production install that does
	 * not yet have the Conexão data model active.
	 */
	public function maybe_register_helpers() {
		// The Apoiador contacts model normally ships with conexao-data-model;
		// requiring it directly keeps the structured contact/social links
		// ("Contatos" repeater) exporting and importing correctly even on
		// installs where the data-model plugin is not active. The file only
		// defines a class (no side effects), so this is safe to repeat.
		if ( ! class_exists( 'Conexao_Data_Model_Contacts' )
			&& file_exists( WP_PLUGIN_DIR . '/conexao-data-model/includes/class-contacts.php' ) ) {
			require_once WP_PLUGIN_DIR . '/conexao-data-model/includes/class-contacts.php';
		}

		if ( ! post_type_exists( 'sponsor' ) ) {
			register_post_type(
				'sponsor',
				array(
					'labels'          => array(
						'name'               => 'Apoiadores',
						'singular_name'      => 'Apoiador',
						'add_new_item'       => 'Adicionar Apoiador',
						'edit_item'          => 'Editar Apoiador',
						'new_item'           => 'Novo Apoiador',
						'view_item'          => 'Ver Apoiador',
						'search_items'       => 'Buscar Apoiadores',
						'not_found'          => 'Nenhum Apoiador encontrado',
						'not_found_in_trash' => 'Nenhum Apoiador encontrado na lixeira',
						'all_items'          => 'Todos os Apoiadores',
						'archives'           => 'Apoiadores',
					),
					'public'             => true,
					'show_ui'            => true,
					'show_in_menu'       => true,
					'show_in_rest'       => true,
					'has_archive'        => 'apoiadores',
					'rewrite'            => array( 'slug' => 'apoiadores', 'with_front' => false ),
					'menu_icon'          => 'dashicons-heart',
					'supports'           => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions', 'custom-fields' ),
					'publicly_queryable' => true,
				)
			);
		}

		$this->register_taxonomy_if_missing( 'conexao_category', true );
		$this->register_taxonomy_if_missing( 'conexao_county', true );
	}

	/**
	 * Register a shared taxonomy if it does not exist yet.
	 *
	 * @param string $taxonomy     Taxonomy slug.
	 * @param bool   $hierarchical Whether it is hierarchical.
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
					'name'          => ucfirst( 'conexao_county' === $taxonomy ? 'Counties' : 'Categories' ),
					'singular_name' => ucfirst( 'conexao_county' === $taxonomy ? 'County' : 'Category' ),
				),
				'public'            => true,
				'hierarchical'      => $hierarchical,
				'show_in_rest'      => true,
				'show_admin_column' => true,
			)
		);
	}
}

Conexao_Sponsor_Migration::instance();