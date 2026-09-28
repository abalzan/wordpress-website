<?php
/**
 * Plugin Name: Conexao Translation Rollout Engine
 * Description: Shared, reusable translation-rollout engine implementing the content-change contract (inventory, manifest, dry-run plan, snapshot, apply, verify + numeric gate, rollback/remove) for one-shot EN translation stages. Stages register a small declarative config plus a versioned authored data manifest; the engine owns all orchestration. A stage whose records are filed under a TRANSLATED taxonomy may additionally declare the optional taxonomy_callback / taxonomy_gate_callback keys, so the engine still owns the term-creation step instead of each stage re-implementing a lifecycle. No frontend behaviour.
 * Version: 1.1.0
 * Requires PHP: 8.0
 * Text Domain: conexao-translation-rollout
 *
 * @package Conexao_Translation_Rollout
 */

defined( 'ABSPATH' ) || exit;

define( 'CONEXAO_TRANSLATION_ROLLOUT_FILE', __FILE__ );
define( 'CONEXAO_TRANSLATION_ROLLOUT_DIR', plugin_dir_path( __FILE__ ) );
define( 'CONEXAO_TRANSLATION_ROLLOUT_VERSION', '1.1.0' );

require_once CONEXAO_TRANSLATION_ROLLOUT_DIR . 'includes/class-conexao-translation-rollout-engine.php';
require_once CONEXAO_TRANSLATION_ROLLOUT_DIR . 'includes/class-conexao-translation-rollout-admin.php';

Conexao_Translation_Rollout_Admin::init();
