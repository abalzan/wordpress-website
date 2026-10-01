<?php
/**
 * Plugin Name: Conexão BR Irlanda — Translation Automation
 * Description: PERMANENT production plugin for the automatic PT→EN translation program. Stage 1 establishes the invocation boundary ONLY: a thin orchestrator that validates its invocation context, resolves the EXISTING shared translation engine (`conexao-translation-rollout`), and delegates to that engine in a non-mutating PROOF mode, returning a structured result. It contains NO translation logic, NO provider, NO cron, NO change detection, NO locking and NO second engine: `Conexao_Translation_Rollout_Engine` remains the sole mutation authority. APPLY mode is recognised but hard-disabled and fails closed. No HTTP, REST or admin entry point exists in this version, so no unauthenticated caller can reach the engine.
 * Version: 0.2.0
 * Requires PHP: 7.4
 * Requires Plugins: conexao-translation-rollout
 * Text Domain: conexao-translation-automation
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

define( 'CONEXAO_TRANSLATION_AUTOMATION_FILE', __FILE__ );
define( 'CONEXAO_TRANSLATION_AUTOMATION_DIR', plugin_dir_path( __FILE__ ) );
define( 'CONEXAO_TRANSLATION_AUTOMATION_VERSION', '0.2.0' );

require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-result.php';
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-lock.php';
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-apply-gate.php';
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-environment.php';
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-orchestrator.php';

// --- Stage 3: change detection, provider boundary, trigger, audit. -------
//
// Load order matters: the digest and state classes are dependencies of the
// inventory, which is a dependency of the change detector, which the trigger
// uses. Each file only declares behaviour; nothing here performs a write at
// load time.
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-digest.php';
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-source-state.php';
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-inventory.php';
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-change-detector.php';
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-provider.php';
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-provider-result.php';

// --- Stage 4: the provider implementation and the translation-plan adapter. --
//
// Load order: the config boundary is a dependency of the provider, the provider
// of the plan, and the plan of the adapter. Nothing here is reachable at load
// time: there is still no HTTP surface, no cron and no automatic call, so a
// page view cannot trigger a provider request.
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-provider-config.php';
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-provider-openai.php';
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-translation-plan.php';
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-plan-adapter.php';
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-audit.php';
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-hooks.php';
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-trigger.php';

/**
 * The plugin's public surface.
 *
 * There is deliberately NO HTTP surface: no `admin_menu`, no `admin_post_*`,
 * no `rest_api_init` and no `__return_true` permission callback, so no
 * unauthenticated caller can reach the engine, the detector or the trigger.
 *
 * There is also NO cron. The trigger model is explicit invocation plus the
 * hook marker, so nothing fires unattended. WordPress.com pv-cron reliability
 * is therefore not on the critical path — see
 * `Conexao_Translation_Automation_Trigger`.
 *
 * The one side effect of loading this plugin is `Hooks::register()`, which
 * attaches the wake-up listeners. Those listeners may ONLY set the wake-up
 * marker; they perform no translation, no content write and no engine call.
 */
Conexao_Translation_Automation_Hooks::register();
