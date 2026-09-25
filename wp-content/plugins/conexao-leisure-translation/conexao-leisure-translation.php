<?php
/**
 * Plugin Name: Conexão BR Irlanda — EN Leisure Descriptions (Stage 7)
 * Description: One-shot, auditable rollout of the Stage 7 Leisure card-description translation layer: writes the human-authored English description (`_leisure_excerpt_en` post meta, data/stage7-leisure-descriptions.json) onto the EXISTING Portuguese `leisure` records — matched by slug, guarded by a PT-drift check that refuses to apply when the Portuguese excerpt no longer matches the authored source. It never creates posts, never touches `_leisure_uuid` / `_leisure_export_uuid`, post_excerpt, content, title, taxonomies or the language assignment (the theme renders the field; see inc/polylang.php conexao_leisure_card_excerpt()). Admin screen (Tools → EN Leisure Descriptions) with dry-run preview and a Remove rollback; no frontend behaviour, safe to deactivate after the rollout.
 * Version: 1.0.0
 * Requires PHP: 7.4
 * Text Domain: conexao-leisure-translation
 *
 * @package Conexao_Leisure_Translation
 */

/**
 * Conexão BR Irlanda — EN Leisure Descriptions (Stage 7).
 *
 * Scope (Stage 7): translate the card description rendered by
 * .leisure-card-excerpt on the Leisure archive. The English layer is a
 * description-level translation on the SAME records:
 *
 *   1. every published Portuguese `leisure` record gets ONE authored English
 *      description, stored in `_leisure_excerpt_en` post meta — no linked EN
 *      leisure posts, no duplicate records, no identity changes (Stage 7
 *      scope control; Leisure EN *records* remain out of scope);
 *   2. matching is by slug (the canonical URL identity; unique across the
 *      289 published records, measured in the Stage 7 inventory), with the
 *      title verified against the manifest;
 *   3. a PT-drift guard refuses to apply a translation whose Portuguese
 *      source (post_excerpt) no longer equals the text the translation was
 *      authored against — stale translations can never silently land;
 *   4. `_leisure_uuid` / `_leisure_export_uuid` are read before/after for the
 *      audit trail and are NEVER written;
 *   5. the Portuguese records are verified unchanged after the run
 *      (post_excerpt / post_title / post_content / uuid fields);
 *   6. a Remove mode deletes only the `_leisure_excerpt_en` values (rollback:
 *      the approved B2 fallback re-engages automatically).
 *
 * The theme owns the rendering: `conexao_leisure_card_excerpt()`
 * (theme inc/polylang.php) reads `_leisure_excerpt_en` on EN requests and
 * falls back to the exact existing Portuguese pipeline otherwise.
 *
 * @package Conexao_Leisure_Translation
 */

defined( 'ABSPATH' ) || exit;

define( 'CONEXAO_LEISURE_TRANSLATION_FILE', __FILE__ );
define( 'CONEXAO_LEISURE_TRANSLATION_DIR', plugin_dir_path( __FILE__ ) );
define( 'CONEXAO_LEISURE_TRANSLATION_META', '_leisure_excerpt_en' );

require_once CONEXAO_LEISURE_TRANSLATION_DIR . 'includes/apply.php';
require_once CONEXAO_LEISURE_TRANSLATION_DIR . 'includes/audit.php';

/**
 * Admin screen: Tools → EN Leisure Descriptions.
 */
final class Conexao_Leisure_Translation_Admin {

	const CAP       = 'manage_options';
	const PAGE_SLUG = 'conexao-leisure-translation';
	const ACTION    = 'conexao_leisure_translation_run';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_run' ) );
	}

	public static function register_menu(): void {
		add_management_page(
			'EN Leisure Descriptions',
			'EN Leisure Descriptions',
			self::CAP,
			self::PAGE_SLUG,
			array( 'Conexao_Leisure_Translation_Screen', 'render' )
		);
	}

	/**
	 * Execute the run (preview / apply / remove) and redirect back.
	 */
	public static function handle_run(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		check_admin_referer( self::ACTION, '_conexao_lt_nonce' );

		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		if ( ! in_array( $mode, array( 'preview', 'apply', 'remove' ), true ) ) {
			wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE_SLUG ) );
			exit;
		}

		$report = conexao_leisure_translation_run( $mode );

		set_transient(
			'conexao_lt_report',
			array( 'mode' => $mode, 'report' => $report ),
			5 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE_SLUG ) );
		exit;
	}
}

/**
 * Render the screen.
 */
final class Conexao_Leisure_Translation_Screen {

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$report = get_transient( 'conexao_lt_report' );
		delete_transient( 'conexao_lt_report' );

		echo '<div class="wrap">';
		echo '<h1>EN Leisure Descriptions (Stage 7)</h1>';
		echo '<p>Writes the human-authored English card descriptions (<code>' . esc_html( CONEXAO_LEISURE_TRANSLATION_META ) . '</code>) onto the existing Portuguese leisure records. Never creates posts, never touches UUIDs or the Portuguese content. The theme renders the field on <code>/en/lazer/</code>; records without a translation keep the approved B2 fallback.</p>';

		if ( ! conexao_leisure_translation_manifest() ) {
			echo '<div class="notice notice-error"><p><strong>Manifest missing.</strong> data/stage7-leisure-descriptions.json was not found inside the plugin.</p></div></div>';
			return;
		}

		if ( $report ) {
			self::render_report( $report['mode'], $report['report'] );
		}

		$audit = conexao_leisure_translation_audit();
		echo '<h2>Completeness audit</h2>';
		echo '<table class="widefat striped"><tbody>';
		foreach ( $audit['counts'] as $label => $value ) {
			printf( '<tr><th style="width:420px">%s</th><td>%s</td></tr>', esc_html( (string) $label ), esc_html( (string) $value ) );
		}
		printf(
			'<tr><th>Gate: published PT leisure records missing EN description</th><td><strong style="color:%s">%d</strong></td></tr>',
			$audit['pass'] ? '#008a20' : '#b32d2e',
			(int) $audit['counts']['published PT leisure records missing EN description']
		);
		echo '</tbody></table>';

		if ( ! empty( $audit['missing'] ) ) {
			echo '<h3>Published PT leisure records still missing an EN description</h3><ul style="max-height:300px;overflow:auto">';
			foreach ( $audit['missing'] as $row ) {
				printf( '<li><code>%s</code> (#%d %s)</li>', esc_html( (string) $row['slug'] ), (int) $row['id'], esc_html( (string) $row['title'] ) );
			}
			echo '</ul>';
		}

		echo '<h2>Run the rollout</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( Conexao_Leisure_Translation_Admin::ACTION, '_conexao_lt_nonce' );
		echo '<input type="hidden" name="action" value="' . esc_attr( Conexao_Leisure_Translation_Admin::ACTION ) . '" />';
		echo '<p><button class="button" name="mode" value="preview">Preview (dry run)</button> ';
		echo '<button class="button button-primary" name="mode" value="apply">Apply</button> ';
		echo '<button class="button" name="mode" value="remove" onclick="return confirm(\'Remove every EN description written by this stage? The approved B2 fallback re-engages automatically.\')">Remove (rollback)</button></p>';
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Render a run report.
	 *
	 * @param string $mode   'preview', 'apply' or 'remove'.
	 * @param array  $report Report from conexao_leisure_translation_run().
	 */
	public static function render_report( string $mode, array $report ): void {
		$s = $report['summary'];
		printf(
			'<div class="notice notice-%s"><p><strong>%s</strong> — entries %d, applied %d, skipped-identical %d, refused %d, errors %d; PT sources changed: %d; UUID fields changed: %d.</p></div>',
			$s['errors'] ? 'error' : 'success',
			esc_html( 'apply' === $mode ? 'Applied' : ( 'remove' === $mode ? 'Removed' : 'Preview (dry run)' ) ),
			(int) $s['entries'],
			(int) $s['applied'],
			(int) $s['skipped_identical'],
			(int) $s['refused'],
			(int) $s['errors'],
			(int) $s['pt_changed'],
			(int) $s['uuid_changed']
		);

		echo '<table class="widefat striped"><thead><tr><th>Slug</th><th>ID</th><th>Action</th><th>Message</th></tr></thead><tbody style="max-height:400px;overflow:auto;display:block">';
		foreach ( $report['rows'] as $row ) {
			printf(
				'<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( (string) $row['slug'] ),
				$row['id'] ? '#' . (int) $row['id'] : '—',
				esc_html( (string) $row['action'] ),
				esc_html( (string) $row['message'] )
			);
		}
		echo '</tbody></table>';
	}
}


Conexao_Leisure_Translation_Admin::init();

