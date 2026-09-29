<?php
/**
 * Plugin Name: Conexão BR Irlanda — Admin UX
 * Description: Reusable, professional CMS admin experience for Eventos, Notícias, Guias, Empregos and Apoiadores. Replaces generic meta boxes with structured sections, clear statuses, bulk actions, duplicate/archive workflows and dashboard summaries.
 * Version: 1.0.7
 * Text Domain: conexao-admin-ux
 * Requires at least: 6.4
 * Requires PHP: 8.0
 *
 * @package Conexao_Admin_Ux
 */

defined( 'ABSPATH' ) || exit;

define( 'CONEXAO_ADMIN_UX_FILE', __FILE__ );
// Asset cache-busting version: MUST be bumped whenever any file under
// assets/ changes, otherwise browsers keep serving the stale cached
// admin.js/admin.css and new UI behaviors silently stop working.
define( 'CONEXAO_ADMIN_UX_VERSION', '1.0.7' );
define( 'CONEXAO_ADMIN_UX_DIR', plugin_dir_path( __FILE__ ) );
define( 'CONEXAO_ADMIN_UX_URL', plugin_dir_url( __FILE__ ) );

require_once CONEXAO_ADMIN_UX_DIR . 'includes/class-config.php';
require_once CONEXAO_ADMIN_UX_DIR . 'includes/class-fields.php';
require_once CONEXAO_ADMIN_UX_DIR . 'includes/class-actions.php';
require_once CONEXAO_ADMIN_UX_DIR . 'includes/class-list.php';
require_once CONEXAO_ADMIN_UX_DIR . 'includes/class-editor.php';
require_once CONEXAO_ADMIN_UX_DIR . 'includes/class-admin.php';
require_once CONEXAO_ADMIN_UX_DIR . 'includes/class-leisure-image-admin.php';
require_once CONEXAO_ADMIN_UX_DIR . 'includes/class-wikimedia-client.php';
require_once CONEXAO_ADMIN_UX_DIR . 'includes/class-translation-state.php';

Conexao_Admin_Ux::instance();

// Stage 3.2 — editorial translation-state indicator (admin-only; full no-op
// when Polylang is inactive). Loaded for every request so the save_post sync
// also covers CLI/importer/REST writes, which are editing events too.
add_action( 'plugins_loaded', array( 'Conexao_Admin_Ux_Translation_State', 'init' ), 20 );

register_activation_hook( __FILE__, array( 'Conexao_Admin_Ux', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Conexao_Admin_Ux', 'deactivate' ) );
/**
 * Load the plugin textdomain (Stage 1 i18n foundation).
 *
 * Translation files live in this plugin's languages/ directory. Admin-only
 * strings may be catalogued here even though English admin support is
 * deferred to a later stage. No functionality changes and no rewrite of the
 * admin UX.
 */
function conexao_admin_ux_load_textdomain() {
	load_plugin_textdomain(
		'conexao-admin-ux',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}
add_action( 'init', 'conexao_admin_ux_load_textdomain' );
