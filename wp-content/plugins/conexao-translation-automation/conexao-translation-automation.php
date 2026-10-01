<?php
/**
 * Plugin Name: Conexão BR Irlanda — Translation Automation
 * Description: PERMANENT production plugin for the automatic PT→EN translation program. A thin orchestrator validates its invocation context, resolves the EXISTING shared translation engine (`conexao-translation-rollout`) and delegates to it, returning a structured result. Stage 6 adds the PROTECTED production entry point: one authenticated (`admin_post_`), POST-only, `manage_options` + nonce gated Tools screen (Tools → Translation Automation) that can ONLY request MODE_PROOF (dry run). It contains NO translation logic, NO apply mode, NO public REST route, NO anonymous AJAX, NO cron and NO second engine: `Conexao_Translation_Rollout_Engine` remains the sole mutation authority, and the provider credential is read from the environment only, never from `wp_options`.
 * Version: 0.3.0
 * Requires PHP: 7.4
 * Requires Plugins: conexao-translation-rollout
 * Text Domain: conexao-translation-automation
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

define( 'CONEXAO_TRANSLATION_AUTOMATION_FILE', __FILE__ );
define( 'CONEXAO_TRANSLATION_AUTOMATION_DIR', plugin_dir_path( __FILE__ ) );
define( 'CONEXAO_TRANSLATION_AUTOMATION_VERSION', '0.3.0' );

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

// --- Stage 6: the protected production proof entry point. -----------------
//
// Load order: the admin trigger wraps the Stage 3 trigger and reads the
// Stage 1 orchestrator's allowlist, so all three must be declared first.
// Registering it adds ONE authenticated, POST-only admin endpoint and the
// Tools screen that carries its nonce. It exposes no REST route, no
// `admin_post_nopriv_*`, no AJAX handler and no cron.
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-admin-trigger.php';

/**
 * The plugin's public surface.
 *
 * There is NO public surface: no `rest_api_init` route, no
 * `admin_post_nopriv_*`, no `wp_ajax_*` and no `__return_true` permission
 * callback, so no unauthenticated caller can reach the engine, the detector
 * or the trigger.
 *
 * The ONE HTTP surface added in Stage 6 is
 * `Conexao_Translation_Automation_Admin_Trigger`, which is
 * `admin_post_{ACTION}` — authenticated by construction — and additionally
 * requires `manage_options` and a valid nonce, accepts POST only, and can
 * only ever request MODE_PROOF.
 *
 * There is also NO cron. The trigger model is explicit invocation plus the
 * hook marker, so nothing fires unattended. WordPress.com pv-cron reliability
 * is therefore not on the critical path — see
 * `Conexao_Translation_Automation_Trigger`.
 *
 * The side effects of loading this plugin are `Hooks::register()` and
 * `Admin_Trigger::register()`. The hook listeners may ONLY set the wake-up
 * marker; they perform no translation, no content write and no engine call.
 */
Conexao_Translation_Automation_Hooks::register();
Conexao_Translation_Automation_Admin_Trigger::register();
