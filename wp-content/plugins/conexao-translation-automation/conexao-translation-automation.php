<?php
/**
 * Plugin Name: Conexão BR Irlanda — Translation Automation
 * Description: PERMANENT production plugin for the automatic PT→EN translation program. Stage 1 establishes the invocation boundary ONLY: a thin orchestrator that validates its invocation context, resolves the EXISTING shared translation engine (`conexao-translation-rollout`), and delegates to that engine in a non-mutating PROOF mode, returning a structured result. It contains NO translation logic, NO provider, NO cron, NO change detection, NO locking and NO second engine: `Conexao_Translation_Rollout_Engine` remains the sole mutation authority. APPLY mode is recognised but hard-disabled and fails closed. No HTTP, REST or admin entry point exists in this version, so no unauthenticated caller can reach the engine.
 * Version: 0.1.0
 * Requires PHP: 7.4
 * Requires Plugins: conexao-translation-rollout
 * Text Domain: conexao-translation-automation
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

define( 'CONEXAO_TRANSLATION_AUTOMATION_FILE', __FILE__ );
define( 'CONEXAO_TRANSLATION_AUTOMATION_DIR', plugin_dir_path( __FILE__ ) );
define( 'CONEXAO_TRANSLATION_AUTOMATION_VERSION', '0.1.0' );

require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-result.php';
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-orchestrator.php';

/**
 * The plugin's public surface: ONE entry point, the orchestrator.
 *
 * There is deliberately NO hook registration here — no `admin_menu`,
 * `admin_post_*`, `rest_api_init` and no WP-Cron. Stage 1 ships an
 * authorisation-gated internal API only, so there is no HTTP surface an
 * unauthenticated caller could reach and no scheduled path that could fire
 * unattended. Later stages add the trigger, never a second engine.
 */
