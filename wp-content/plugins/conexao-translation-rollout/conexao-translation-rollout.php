<?php
/**
 * Plugin Name: Conexao Translation Rollout Engine
 * Description: Shared, reusable translation-rollout engine implementing the content-change contract (inventory, manifest, dry-run plan, snapshot, apply, verify + numeric gate, rollback/remove) for one-shot EN translation stages. Stages register a small declarative config plus a versioned authored data manifest; the engine owns all orchestration. No frontend behaviour.
 * Version: 1.0.0
 * Requires PHP: 8.0
 * Text Domain: conexao-translation-rollout
 *
 * @package Conexao_Translation_Rollout
 */

defined( 'ABSPATH' ) || exit;

define( 'CONEXAO_TRANSLATION_ROLLOUT_FILE', __FILE__ );
define( 'CONEXAO_TRANSLATION_ROLLOUT_DIR', plugin_dir_path( __FILE__ ) );
define( 'CONEXAO_TRANSLATION_ROLLOUT_VERSION', '1.0.0' );

require_once CONEXAO_TRANSLATION_ROLLOUT_DIR . 'includes/class-conexao-translation-rollout-engine.php';
require_once CONEXAO_TRANSLATION_ROLLOUT_DIR . 'includes/class-conexao-translation-rollout-admin.php';

Conexao_Translation_Rollout_Admin::init();
