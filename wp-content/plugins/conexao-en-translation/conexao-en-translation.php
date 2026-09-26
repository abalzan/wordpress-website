<?php
/**
 * Plugin Name: Conexão BR Irlanda — EN Translation (Stage M / Stage N)
 * Description: Closes the EN translation-completeness debt for the B1 content types (`guide`, `page`, `post`): one linked EN translation per eligible public PT record. The Stage M guide/page rows and the Stage N Blog rows are authored as versioned data in includes/manifest-data.php and includes/blog-translation-data.php, and both are applied through the shared `conexao-translation-rollout` engine, which owns the whole content-change contract (inventory, manifest validation, dry-run plan, snapshot, apply, verify, numeric gate, remove). Polylang relationships are verified in both directions and PT sources are never modified. A DATA + CONFIG consumer of that engine. No frontend behaviour; the admin screen is a dry-run preview. Safe to deactivate after the rollout.
 * Version: 1.1.0
 * Requires PHP: 7.4
 * Requires Plugins: conexao-translation-rollout
 * Text Domain: conexao-en-translation
 *
 * @package Conexao_EN_Translation
 */

defined( 'ABSPATH' ) || exit;

define( 'CONEXAO_EN_TRANSLATION_FILE', __FILE__ );
define( 'CONEXAO_EN_TRANSLATION_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Stage M + Stage N (EN completeness) — a DATA + CONFIG consumer of the shared
 * engine.
 *
 * The authored English translations live in includes/translation-map.php (the
 * per-post-type field mapping) and in the versioned data files
 * includes/manifest-data.php (Stage M: guide + page + the first Blog batch) and
 * includes/blog-translation-data.php (Stage N: the remaining Blog rows). Every
 * lifecycle step is owned by conexao-translation-rollout: this plugin never
 * reimplements the content-change contract and never becomes a second
 * translation engine.
 */
require_once CONEXAO_EN_TRANSLATION_DIR . 'includes/manifest-data.php';
require_once CONEXAO_EN_TRANSLATION_DIR . 'includes/blog-translation-data.php';
require_once CONEXAO_EN_TRANSLATION_DIR . 'includes/translation-map.php';
require_once CONEXAO_EN_TRANSLATION_DIR . 'includes/stage-fields.php';
require_once CONEXAO_EN_TRANSLATION_DIR . 'includes/stage-config.php';

/**
 * Register the three Stage M stages with the shared engine.
 *
 * Deferred to `plugins_loaded` so registration does not depend on the activation
 * order WordPress happened to write into `active_plugins`. Registration is a
 * pure in-memory declaration: it performs zero writes, and the engine never
 * writes on plugin bootstrap or activation.
 */
function conexao_en_translation_register_stages(): void {
	if ( ! class_exists( 'Conexao_Translation_Rollout_Engine' ) ) {
		return;
	}

	foreach ( conexao_en_translation_post_types() as $post_type ) {
		Conexao_Translation_Rollout_Engine::register_stage( conexao_en_translation_engine_config( $post_type ) );
	}
}
add_action( 'plugins_loaded', 'conexao_en_translation_register_stages' );
