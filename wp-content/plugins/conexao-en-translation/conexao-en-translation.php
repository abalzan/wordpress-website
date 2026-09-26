<?php
/**
 * Plugin Name: Conexão BR Irlanda — EN Translation (Stage M)
 * Description: Closes the pre-existing EN translation-completeness debt for the three B1 content types (`guide`, `page`, `post`) left by earlier stages: one linked EN translation per eligible public PT record, authored in includes/translation-map.php, Polylang relationships verified in both directions, PT sources never modified. A DATA + CONFIG consumer of the shared `conexao-translation-rollout` engine, which owns the whole content-change contract (inventory, manifest validation, dry-run plan, snapshot, apply, verify, numeric gate, remove). No frontend behaviour; the admin screen is a dry-run preview. Safe to deactivate after the rollout.
 * Version: 1.0.0
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
 * Stage M (EN completeness) — a DATA + CONFIG consumer of the shared engine.
 *
 * The authored English translations live in includes/translation-map.php, the
 * per-post-type field mapping in includes/stage-fields.php, and the declarative
 * stage configuration in includes/stage-config.php. Every lifecycle step is
 * owned by conexao-translation-rollout: this plugin never reimplements the
 * content-change contract and never becomes a second translation engine.
 */
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
