<?php
/**
 * Plugin Name: Conexão BR Irlanda Data Model
 * Description: Content types, shared taxonomies, and editorial fields for the Conexão BR Irlanda portal.
 * Version: 1.6.1
 * Text Domain: conexao-data-model
 *
 * @package Conexao_BR_Irlanda_Data_Model
 */

defined( 'ABSPATH' ) || exit;

define( 'CONEXAO_DATA_MODEL_FILE', __FILE__ );
define( 'CONEXAO_DATA_MODEL_DIR', plugin_dir_path( __FILE__ ) );

require_once CONEXAO_DATA_MODEL_DIR . 'includes/class-relationships.php';
require_once CONEXAO_DATA_MODEL_DIR . 'includes/class-meta.php';
require_once CONEXAO_DATA_MODEL_DIR . 'includes/class-contacts.php';
require_once CONEXAO_DATA_MODEL_DIR . 'includes/class-agency.php';

final class Conexao_Data_Model {

	const VERSION = '1.6.1';

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
		add_action( 'init', array( $this, 'register_sponsor_contacts_meta' ), 0 );
		add_action( 'init', array( $this, 'register_agency_meta' ), 0 );
		add_action( 'init', array( $this, 'register_permit_employer_meta' ), 0 );
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
			// Recruitment agencies directory — wp-admin only (see the loop below).
			// Records render exclusively inside the /empregos/ landing section.
			'recruitment_agency' => array( 'plural' => 'Agências de Recrutamento', 'singular' => 'Agência de Recrutamento', 'slug' => 'agencias-de-recrutamento', 'icon' => 'dashicons-networking' ),
			// Employment-permit employers directory — wp-admin only, same
			// container pattern as recruitment_agency. Employers are a
			// DIFFERENT entity from recruitment agencies and are never
			// modelled as one; they render in the "Empresas com histórico de
			// Employment Permits" section of /empregos/.
			'permit_employer' => array( 'plural' => 'Empregadores — Employment Permits', 'singular' => 'Empregador (Employment Permits)', 'slug' => 'empregadores-employment-permits', 'icon' => 'dashicons-building' ),
		);

		foreach ( $post_types as $post_type => $type ) {
			// course_provider is a curated directory with a public archive at /cursos/.
			// Individual course pages are not used; each provider links to an external website.
			$is_provider = ( 'course_provider' === $post_type );
			// /empregos/ is the Jobs landing page (page-empregos.php). Disable the job
			// CPT *archive* so that URL belongs to the static page, while the CPT's
			// `rewrite` slug stays `empregos` so individual job posts (e.g.
			// /empregos/oportunidades/) keep their existing permalinks untouched.
			$has_archive = ( 'job' === $post_type ) ? false : $type['slug'];

			// recruitment_agency and permit_employer are wp-admin-only
			// directories: records render exclusively inside the /empregos/
			// landing section (theme modules inc/recruitment-agencies.php +
			// inc/permit-employers.php and their template parts). They have
			// no public archive, no single URL, no rewrite rule and no
			// search presence — they exist to store the curated directory
			// data in a reusable, admin-editable structure. In wp-admin both
			// appear as a submenu of the existing Empregos menu.
			//
			// permit_employer is NOT an agency record: employment-permit
			// employers are separate entities (companies/organisations with
			// verified historical permit evidence in the official DETE
			// statistics), never recruitment agencies.
			$is_admin_only = in_array( $post_type, array( 'recruitment_agency', 'permit_employer' ), true );
			$is_public     = ! $is_admin_only;
			$show_in_menu  = $is_admin_only ? 'edit.php?post_type=job' : true;

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
						'all_items'     => 'Todas as ' . $type['plural'],
						'archives'      => $type['plural'],
					),
					// All post types are publicly queryable to support their archives
					// — except recruitment_agency/permit_employer (see above),
					// which are admin-only.
					'public'             => $is_public,
					'show_ui'            => true,
					'show_in_menu'       => $show_in_menu,
					'show_in_rest'       => true,
					'publicly_queryable' => $is_public,
					'has_archive'        => $has_archive,
					'rewrite'            => $is_admin_only ? false : array( 'slug' => $type['slug'], 'with_front' => false ),
					'menu_icon'          => $type['icon'],
					'supports'           => $is_provider
						? array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'custom-fields' )
						: ( $is_admin_only
							? array( 'title', 'editor', 'excerpt', 'revisions', 'custom-fields' )
							: array( 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions', 'page-attributes', 'custom-fields' ) ),
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
	 * Register meta fields for the recruitment-agency directory entries.
	 *
	 * These back the "Agências de recrutamento" section on /empregos/ and are
	 * edited through the Conexão Admin UX (Empregos → Agências de Recrutamento).
	 * Every field is optional except the agency name/website, which the admin-ux
	 * config marks as required.
	 */
	public function register_agency_meta() {
		$meta = array(
			'_agency_website'      => 'string',  // Official website URL (external destination).
			'_agency_phone'        => 'string',  // Main phone number for candidates.
			'_agency_location'     => 'string',  // Main location / coverage.
			'_agency_job_types'    => 'string',  // Main types of work relevant to the audience.
			'_agency_temporary'    => 'boolean', // Offers temporary work.
			'_agency_permanent'    => 'boolean', // Offers permanent work.
			'_agency_order'        => 'integer', // Display priority (lower first).
			'_agency_last_checked' => 'string',  // Date the agency information was last verified.
			'_agency_wrc_licence'  => 'string',  // Workplace Relations Commission licence reference.
			'_agency_status'       => 'string',  // Custom publishing status (draft/needs_review/published/archived).
		);

		foreach ( $meta as $key => $type ) {
			register_post_meta(
				'recruitment_agency',
				$key,
				array(
					'single'       => true,
					'type'         => $type,
					'show_in_rest' => true,
					// All keys are protected (underscore-prefixed). Without an
					// explicit auth_callback, register_meta() defaults to
					// __return_false for protected keys, which makes every
					// REST write fail with 403 rest_cannot_update — even for
					// administrators. Allow exactly what the wp-admin editor
					// allows: users who can edit the agency record.
					'auth_callback' => function ( $allowed, $meta_key, $object_id ) {
						return current_user_can( 'edit_post', $object_id );
					},
				)
			);
		}

		// Internal maintenance notes — editorial-only, never rendered on the
		// public site. Kept out of the REST API and gated behind edit_post so
		// the notes stay an admin-only maintenance surface.
		register_post_meta(
			'recruitment_agency',
			'_agency_notes',
			array(
				'single'            => true,
				'type'              => 'string',
				'show_in_rest'      => false,
				'auth_callback'     => function ( $allowed, $meta_key, $object_id ) {
					return current_user_can( 'edit_post', $object_id );
				},
			)
		);
	}

	/**
	 * Register meta fields for the employment-permit employers directory.
	 *
	 * These back the "Empresas com histórico de Employment Permits" section
	 * on /empregos/ and are edited through the Conexão Admin UX (Empregos →
	 * Empregadores — Employment Permits). Every field is optional except
	 * the employer name/website, which the admin-ux config marks required.
	 *
	 * Semantics (do not weaken):
	 * - `_employer_permit_status = 'verified'` means VERIFIED HISTORICAL
	 *   permit evidence in the official DETE "Permits issued to companies"
	 *   statistics — never "currently sponsoring".
	 * - 'unverified' renders as a normal employer entry with NO permit
	 *   indicator (e.g. Kepak while its DETE legal entity is being matched).
	 * - 'exception' carries the same historical evidence plus the employer's
	 *   own current-position statement (e.g. Nua Healthcare: not currently
	 *   recruiting internationally / not sponsoring GEPs) and renders as a
	 *   normal permit-history card in the unified directory; the compact
	 *   safety/permit notice is the single frontend explanation.
	 * - `_employer_evidence_source` and `_employer_evidence_years` stay in
	 *   the admin data; the frontend shows a single shared source note.
	 */
	public function register_permit_employer_meta() {
		$meta = array(
			'_employer_official_website'  => 'string', // Official public-facing website URL.
			'_employer_careers_url'       => 'string', // Careers/jobs page URL (when one exists).
			'_employer_sector'            => 'string', // Sector label shown on the card.
			'_employer_roles'             => 'string', // Relevant role types (comma-separated free text).
			'_employer_location'          => 'string', // Location / coverage.
			'_employer_permit_status'     => 'string', // verified | unverified | exception.
			'_employer_evidence_source'   => 'string', // Where the permit evidence comes from (admin data).
			'_employer_evidence_years'    => 'string', // Evidence years, e.g. "2023–2025" (shown on the card).
			'_employer_last_checked'      => 'string', // Date the record was last verified.
			'_employer_status'            => 'string', // Custom publishing status (draft/needs_review/published/archived).
		);

		foreach ( $meta as $key => $type ) {
			register_post_meta(
				'permit_employer',
				$key,
				array(
					'single'        => true,
					'type'          => $type,
					'show_in_rest'  => true,
					// Same auth pattern as the agency meta: protected keys
					// default to __return_false in REST without an explicit
					// auth_callback, breaking REST seeding (see
					// register_agency_meta() for the full explanation).
					'auth_callback' => function ( $allowed, $meta_key, $object_id ) {
						return current_user_can( 'edit_post', $object_id );
					},
				)
			);
		}

		// Internal maintenance notes — editorial-only, never rendered on the
		// public site (e.g. pending DETE legal-entity matching for Kepak).
		register_post_meta(
			'permit_employer',
			'_employer_notes',
			array(
				'single'        => true,
				'type'          => 'string',
				'show_in_rest'  => false,
				'auth_callback' => function ( $allowed, $meta_key, $object_id ) {
					return current_user_can( 'edit_post', $object_id );
				},
			)
		);
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
			'_leisure_internal_page' => 'boolean', // Phase 3B — keep the internal page; official/discover URLs become display-only links.
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

			// Phase 2 — practical-information fields (optional, free-text).
			'_leisure_practical_notes'       => 'string', // Observações práticas for visitors.
			'_leisure_practical_source_url'  => 'string', // Source used to verify the practical notes.
			'_leisure_practical_last_checked' => 'string', // Date the practical info was last verified.
		);

		foreach ( $meta as $key => $type ) {
			register_post_meta(
				'leisure',
				$key,
				array(
					'single'       => true,
					'type'         => $type,
					'show_in_rest' => true,
					// All keys are protected (underscore-prefixed). Without an
					// explicit auth_callback, register_meta() defaults to
					// __return_false for protected keys, which makes every
					// REST write fail with 403 rest_cannot_update — even for
					// administrators. Allow exactly what the wp-admin editor
					// allows: users who can edit the leisure record.
					// Same pattern as register_agency_meta() /
					// register_permit_employer_meta(); required so the
					// production image + attribution refresh can run through
					// the REST API (scripts/update-lazer-images-rest.py).
					'auth_callback' => function ( $allowed, $meta_key, $object_id ) {
						return current_user_can( 'edit_post', $object_id );
					},
				)
			);
		}
	}

	/**
	 * Register the structured Apoiador contacts meta (`_sponsor_contacts`).
	 *
	 * A single array meta holding the ordered contact rows managed by the
	 * admin editor's repeater (see Conexao_Data_Model_Contacts). The
	 * sanitize callback is defense-in-depth for REST/meta API writes — the
	 * admin editor and importer already sanitize explicitly through
	 * Conexao_Data_Model_Contacts::sanitize_rows() before saving.
	 *
	 * show_in_rest is disabled: array meta without a REST schema cannot be
	 * represented in the REST response, and no consumer reads this field
	 * through the REST API.
	 */
	public function register_sponsor_contacts_meta() {
		register_post_meta(
			'sponsor',
			Conexao_Data_Model_Contacts::META_KEY,
			array(
				'single'            => true,
				'type'              => 'array',
				'show_in_rest'      => false,
				'sanitize_callback' => array( 'Conexao_Data_Model_Contacts', 'sanitize_rows' ),
				'auth_callback'     => function ( $allowed, $meta_key, $object_id ) {
					return current_user_can( 'edit_post', $object_id );
				},
			)
		);
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

		// Structured practical/profile characteristics for leisure destinations
		// only. Non-hierarchical so it behaves like a controlled tag list. Distinct
		// from conexao_category (which holds experience/category concepts) and from
		// conexao_county (location).
		register_taxonomy(
			'conexao_leisure_attribute',
			array( 'leisure' ),
			array(
				'labels'            => array( 'name' => 'Características', 'singular_name' => 'Característica' ),
				'public'            => false,
				'hierarchical'      => false,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => false,
			)
		);
	}

	public static function activate() {
		$plugin = self::instance();
		$plugin->register_content_types();
		$plugin->register_taxonomies();
		$plugin->register_provider_meta();
		$plugin->register_agency_meta();
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
/**
 * Load the plugin textdomain (Stage 1 i18n foundation).
 *
 * Translation files live in this plugin's languages/ directory. Admin-only
 * strings may be catalogued here even though English admin support is
 * deferred to a later stage. No functionality changes.
 */
function conexao_data_model_load_textdomain() {
	load_plugin_textdomain(
		'conexao-data-model',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}
add_action( 'init', 'conexao_data_model_load_textdomain' );
