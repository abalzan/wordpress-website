<?php
/**
 * Plugin Name: Conexão BR Irlanda — Commemorative Events
 * Description: Theme-independent commemorative event engine: clock-injected window resolution (fixed / variable / one-off / explicit window, year-boundary aware), occurrence-year identity, priority and manual annual precedence, one-active-event resolution with reasons, signed administrator preview, and targeted activation-boundary cache invalidation.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Requires Plugins: conexao-data-model
 * Text Domain: conexao-commemorative
 *
 * @package Conexao_Commemorative
 */

defined( 'ABSPATH' ) || exit;

define( 'CONEXAO_COMMEMORATIVE_FILE', __FILE__ );
define( 'CONEXAO_COMMEMORATIVE_VERSION', '1.0.0' );
define( 'CONEXAO_COMMEMORATIVE_DIR', plugin_dir_path( __FILE__ ) );

require_once CONEXAO_COMMEMORATIVE_DIR . 'includes/class-conexao-commemorative-schedule.php';
require_once CONEXAO_COMMEMORATIVE_DIR . 'includes/class-conexao-commemorative-event.php';
require_once CONEXAO_COMMEMORATIVE_DIR . 'includes/class-conexao-commemorative-repository.php';
require_once CONEXAO_COMMEMORATIVE_DIR . 'includes/class-conexao-commemorative-resolver.php';
require_once CONEXAO_COMMEMORATIVE_DIR . 'includes/class-conexao-commemorative-cache.php';
require_once CONEXAO_COMMEMORATIVE_DIR . 'includes/class-conexao-commemorative-preview.php';
require_once CONEXAO_COMMEMORATIVE_DIR . 'includes/class-conexao-commemorative-admin.php';
require_once CONEXAO_COMMEMORATIVE_DIR . 'includes/class-conexao-commemorative-migration.php';
require_once CONEXAO_COMMEMORATIVE_DIR . 'includes/functions.php';
require_once CONEXAO_COMMEMORATIVE_DIR . 'includes/class-conexao-commemorative.php';

Conexao_Commemorative::instance();

register_activation_hook( __FILE__, array( 'Conexao_Commemorative', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Conexao_Commemorative', 'deactivate' ) );
