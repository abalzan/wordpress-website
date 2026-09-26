<?php
/**
 * Plugin Name: Conexão BR Irlanda — EN Job Translation (Stage 6)
 * Description: One-shot, auditable migration that turns the Job CPT from the approved B2 fallback into a real English translation: exactly one linked EN translation per eligible public Portuguese `job` record (human-authored content in includes/translation-map.php), Polylang relationships verified in both directions, structured `_job_*` meta copied verbatim, shared featured media, and a completeness audit whose gate is 'eligible public PT jobs missing EN = 0'. The Jobs landing Page is NOT touched (Stage 4.5 owns it): the plugin only verifies the existing PT↔EN page link. Portuguese originals are never modified. Admin screen (Tools → EN Job Translations) with dry-run preview; no frontend behaviour, safe to deactivate after the rollout.
 * Version: 1.0.0
 * Requires PHP: 7.4
 * Text Domain: conexao-job-translation
 *
 * @package Conexao_Job_Translation
 */

/**
 * Conexão BR Irlanda — EN Job Translation (Stage 6).
 *
 * Turns the Job CPT from the approved B2 fallback (`/en/empregos/{slug}/`
 * rendering the Portuguese record under the English URL + notice) into a REAL
 * English translation:
 *
 *   1. every eligible public Portuguese `job` gets exactly ONE linked English
 *      translation, authored in includes/translation-map.php;
 *   2. the Jobs landing Page itself is out of scope — Stage 4.5 already
 *      created the EN `/en/jobs/` Page; this plugin only VERIFIES the pair;
 *   3. structured job meta (`_job_company`, `_job_location`, `_job_salary`,
 *      `_job_employment_type`, `_job_expiration_date`, `_job_application_url`,
 *      `_job_closing_date`, `_job_source`, `_job_status`, …) is copied
 *      verbatim — proper names, URLs, dates and controlled values are never
 *      translated; the featured image is shared (media_support=false);
 *   4. the Polylang relationship is verified from BOTH directions;
 *   5. the Portuguese originals are verified unchanged after the run.
 *
 * Why a plugin (not a REST script)? Production is WordPress.com (no WP-CLI),
 * and Polylang Free 3.8.9 cannot write the `translations` relationship through
 * the public REST API (the same constraint documented for
 * conexao-blog-translation / conexao-page-translation). The established
 * content-migration pattern in this project is an admin-screen importer; this
 * plugin follows it exactly and is safe to deactivate after the rollout (it
 * has no front-end behaviour).
 *
 * @package Conexao_Job_Translation
 */

defined( 'ABSPATH' ) || exit;

define( 'CONEXAO_JOB_TRANSLATION_FILE', __FILE__ );
define( 'CONEXAO_JOB_TRANSLATION_DIR', plugin_dir_path( __FILE__ ) );

// Stage H (shared rollout engine): this plugin is now a DATA + CONFIG
// consumer. The authored English translations live in includes/translation-map.php,
// the job-specific field mapping in includes/stage-fields.php, and the
// declarative stage config in includes/stage-config.php. Every lifecycle step
// (inventory, manifest validation, dry-run plan, snapshot, apply, verify,
// numeric gate) is owned by conexao-translation-rollout.
require_once CONEXAO_JOB_TRANSLATION_DIR . 'includes/translation-map.php';
require_once CONEXAO_JOB_TRANSLATION_DIR . 'includes/stage-fields.php';
require_once CONEXAO_JOB_TRANSLATION_DIR . 'includes/stage-config.php';

/**
 * Register this stage with the shared engine.
 *
 * Deferred to `plugins_loaded` so the registration does not depend on the
 * activation order WordPress happened to write into `active_plugins` (the
 * engine plugin may be listed after this one). Registration is a pure
 * in-memory declaration: it performs zero writes, and the engine never writes
 * on plugin bootstrap or activation.
 */
function conexao_job_translation_register_stage(): void {
	if ( class_exists( 'Conexao_Translation_Rollout_Engine' ) ) {
		Conexao_Translation_Rollout_Engine::register_stage( conexao_job_translation_engine_config() );
	}
}
add_action( 'plugins_loaded', 'conexao_job_translation_register_stage' );
