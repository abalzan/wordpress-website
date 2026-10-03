<?php
/**
 * Plugin Name: Conexão BR Irlanda — Translation Automation
 * Description: MANUAL, OPERATOR-TRIGGERED control plane for PT→EN translation. Stage 17 retires permanent automatic translation: this plugin no longer registers any wake-up hook, schedules anything, or listens for any inbound request, so nothing runs unless a human asks for it. What remains is the protected manual entry point — one authenticated (`admin_post_`), POST-only, `manage_options` + nonce gated Tools screen (Tools → Translation Automation) that can ONLY request MODE_PROOF (dry run) — plus the Stage 11 MODEL A TRUE SUBSET EXECUTION controls, where an approved batch carries an explicit operation scope that reaches the shared engine, so one engine run covers exactly the approved subset and `approved scope == executed scope` is proved in both directions against the engine's own plan. Stage 11's batch-control capability remains DECLARED in the control-plane registry and deliberately NOT COMMISSIONED (Stage 11 §30). It contains NO translation logic, NO second engine, NO public REST route, NO anonymous AJAX, NO webhook and NO cron: `Conexao_Translation_Rollout_Engine` remains the sole mutation authority, and the provider credential is read from the process environment only, never from `wp_options`, so no permanent provider credential has to be configured in WordPress.com.
 * Version: 0.6.0
 * Requires PHP: 7.4
 * Requires Plugins: conexao-translation-rollout
 * Text Domain: conexao-translation-automation
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

define( 'CONEXAO_TRANSLATION_AUTOMATION_FILE', __FILE__ );
define( 'CONEXAO_TRANSLATION_AUTOMATION_DIR', plugin_dir_path( __FILE__ ) );
define( 'CONEXAO_TRANSLATION_AUTOMATION_VERSION', '0.6.0' );

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

// --- Stage 4: the provider implementation. ---------------------------------
//
// Load order: the provider depends on the config boundary, and the plan depends
// on the provider. Nothing here is reachable at load time: there is no HTTP
// surface, no cron, no wake-up hook and no automatic call, so a page view cannot
// trigger a provider request.
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-provider-openai.php';
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-translation-plan.php';
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-plan-adapter.php';
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-audit.php';
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-trigger.php';

// --- Stage 6: the protected production proof entry point. -----------------
//
// Load order: the admin trigger wraps the Stage 3 trigger and reads the
// Stage 1 orchestrator's allowlist, so all three must be declared first.
// Registering it adds ONE authenticated, POST-only admin endpoint and the
// Tools screen that carries its nonce. It exposes no REST route, no
// `admin_post_nopriv_*`, no AJAX handler and no cron.
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-admin-trigger.php';

// --- Stage 10: bounded multi-record execution controls. --------------------
//
// Load order matters: the limits are a dependency of the batch, the batch of
// the state store, the store of the approval boundary, and the approval
// boundary of the executor. Each file only declares behaviour; nothing here
// registers a hook, an endpoint or a write at load time.
//
// The batch layer is a SAFETY LAYER AROUND the existing per-operation safety,
// not a replacement for it: the executor calls the EXISTING orchestrator once
// per operation, so the lock, the environment guard, the F7 dry-run, the
// digest-bound approval, the snapshot, the apply and the engine's verification
// all still run, unchanged, for every single record. The engine remains the
// sole mutation authority and its core file is untouched.
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-batch-limits.php';
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-batch.php';
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-batch-state.php';
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-emergency-stop.php';
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-batch-approval.php';
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-batch-executor.php';
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-batch-composer.php';
require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-batch-expansion.php';

require_once CONEXAO_TRANSLATION_AUTOMATION_DIR . 'includes/class-conexao-translation-automation-batch-control.php';

// Stage 11 §30: the batch-control capability is DECLARED, NOT COMMISSIONED.
// `Batch_Control::register()` is deliberately NOT called here, so this plugin
// registers no batch endpoint and no batch admin screen. Commissioning it is a
// deliberate, separately reviewed production action (Stage 12 prerequisites),
// not something this plugin grants itself.
//
// Conexao_Translation_Automation_Batch_Control::register();

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
 * There is also NO cron. The trigger model is EXPLICIT INVOCATION ONLY, so
 * nothing fires unattended. WordPress.com pv-cron reliability is therefore not
 * on the critical path — see `Conexao_Translation_Automation_Trigger`.
 *
 * The side effects of loading this plugin are `Admin_Trigger::register()`,
 * which adds one authenticated admin endpoint and its Tools screen. No
 * lifecycle hook, no scheduler and no inbound listener is registered at all,
 * so no PT change can wake this plugin up by itself.
 */
Conexao_Translation_Automation_Admin_Trigger::register();
