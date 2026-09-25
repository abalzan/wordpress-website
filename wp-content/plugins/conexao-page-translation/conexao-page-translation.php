<?php
/**
 * Plugin Name: Conexão BR Irlanda — EN Page Translation (Stage 4.5)
 * Description: One-shot, auditable migration that creates the linked English translation of every eligible public WordPress Page (Polylang). Human-authored content ships in includes/translation-map.php; the importer runs in dependency order, resolves internal links through the Polylang relationship, copies template/featured media/passthrough meta, verifies every link both ways, and proves the PT originals are unchanged afterwards. Admin screen with dry-run preview. No cron, no frontend effect, safe to deactivate after the rollout.
 * Version: 1.0.0
 * Text Domain: conexao-page-translation
 *
 * Why a plugin (not a REST script)? Production is WordPress.com (no WP-CLI)
 * and Polylang Free 3.8.9 cannot write the `translations` relationship over
 * the public REST API (verified against the 3.8.9 sources: `lang` is honoured
 * on write, `translations` is admin-form only). The established production
 * content-migration pattern in this project is an admin-screen importer
 * (conexao-leisure-migration, conexao-sponsor-migration); this plugin follows
 * it exactly.
 *
 * @package Conexao_Page_Translation
 */

defined( 'ABSPATH' ) || exit;

define( 'CONEXAO_PAGE_TRANSLATION_FILE', __FILE__ );
define( 'CONEXAO_PAGE_TRANSLATION_DIR', plugin_dir_path( __FILE__ ) );

require_once CONEXAO_PAGE_TRANSLATION_DIR . 'includes/translation-map.php';
require_once CONEXAO_PAGE_TRANSLATION_DIR . 'includes/apply.php';

/**
 * Admin screen: Tools → EN Page Translations.
 */
final class Conexao_Page_Translation_Admin {

	const CAP       = 'manage_options';
	const PAGE_SLUG = 'conexao-page-translation';
	const ACTION    = 'conexao_page_translation_run';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_run' ) );
	}

	public static function register_menu(): void {
		add_management_page(
			'EN Page Translations (Stage 4.5)',
			'EN Page Translations',
			self::CAP,
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Execute the run (preview, apply or refresh) and redirect back with the report.
	 */
	public static function handle_run(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'forbidden' );
		}
		check_admin_referer( self::ACTION, '_conexao_pt_nonce' );

		$mode = isset( $_POST['mode'] ) && 'apply' === $_POST['mode'] ? 'apply' : ( isset( $_POST['mode'] ) && 'refresh' === $_POST['mode'] ? 'refresh' : 'preview' );

		$report = conexao_page_translation_run(
			array(
				'dry_run'         => ( 'apply' !== $mode && 'refresh' !== $mode ),
				// 'refresh' re-applies the human-authored manifest to EN pages
				// that already exist (e.g. a page whose EN copy was authored
				// after its first creation). The PT gate still runs.
				'update_existing' => ( 'refresh' === $mode ),
			)
		);
		set_transient( 'conexao_page_translation_report', array( 'mode' => $mode, 'report' => $report ), 10 * MINUTE_IN_SECONDS );

		wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE_SLUG . '&ran=' . $mode ) );
		exit;
	}


	/**
	 * Render the current translation state + the last report.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'forbidden' );
		}

		$polylang = function_exists( 'pll_get_post' );
		$map      = conexao_page_translation_map();
		$report   = get_transient( 'conexao_page_translation_report' );
		if ( $report ) {
			delete_transient( 'conexao_page_translation_report' );
		}

		echo '<div class="wrap"><h1>EN Page Translations (Stage 4.5)</h1>';

		if ( ! $polylang ) {
			echo '<div class="notice notice-error"><p><strong>Polylang is not active.</strong> Deploy the English layer first (see docs/english-stage43-production-deployment.md), then run this migration.</p></div></div>';
			return;
		}

		if ( $report ) {
			self::render_report( $report['mode'], $report['report'] );
		}

		echo '<h2>Current state</h2>';
		echo '<table class="widefat striped"><thead><tr>'
			. '<th>PT slug</th><th>PT page</th><th>EN slug (planned)</th><th>EN status</th><th>EN page</th>'
			. '</tr></thead><tbody>';

		foreach ( $map as $pt_slug => $en ) {
			$pt_page = get_page_by_path( $pt_slug, OBJECT, 'page' );
			$pt_cell = $pt_page
				? sprintf( '#%d — %s', $pt_page->ID, esc_html( $pt_page->post_title ) )
				: '<strong style="color:#b32d2e">MISSING</strong>';
			$en_id   = $pt_page ? (int) pll_get_post( (int) $pt_page->ID, 'en' ) : 0;
			$en_cell = '—';
			$en_stat = '<em>not created</em>';
			if ( $en_id ) {
				$en_cell = sprintf( '#%d — %s', $en_id, esc_html( get_post_field( 'post_title', $en_id ) ) );
				$en_stat = '<strong style="color:#008a20">exists</strong>';
			}
			printf(
				'<tr><td><code>%s</code></td><td>%s</td><td><code>%s</code></td><td>%s</td><td>%s</td></tr>',
				esc_html( $pt_slug ),
				$pt_cell, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above.
				esc_html( $en['en_slug'] ),
				$en_stat, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
				$en_cell  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above.
			);
		}
		echo '</tbody></table>';

		echo '<h2>Run</h2>';
		echo '<p>Creation order: front page → top-level pages → legal/utility. Idempotent: existing EN translations are skipped. The Portuguese originals are never modified — a PT checkpoint is verified after the run.</p>';
		echo '<p><strong>Refresh</strong> re-applies the authored English copy in the manifest to EN pages that already exist (use it when the manifest copy is corrected or completed after a first run — e.g. the Jobs landing page). It never creates a second translation, never touches the Portuguese page, and the PT checkpoint still runs.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:1em">';
		wp_nonce_field( self::ACTION, '_conexao_pt_nonce' );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="mode" value="preview">';
		submit_button( 'Preview (dry run — writes nothing)', 'secondary', 'submit', false );
		echo '</form> ';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:1em" onsubmit="return confirm(\'Create the EN translations now? Portuguese pages are never modified.\');">';
		wp_nonce_field( self::ACTION, '_conexao_pt_nonce' );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="mode" value="apply">';
		submit_button( 'Apply (create EN translations)', 'primary', 'submit', false );
		echo '</form> ';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Re-apply the authored English content from the manifest to the EN pages that already exist?\\n\\nPortuguese pages are never modified.\');">';
		wp_nonce_field( self::ACTION, '_conexao_pt_nonce' );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="mode" value="refresh">';
		submit_button( 'Refresh existing EN pages (re-apply the manifest copy)', 'secondary', 'submit', false );
		echo '</form>';

		echo '</div>';
	}


	/**
	 * Render a run report.
	 *
	 * @param string $mode   preview|apply.
	 * @param array  $report conexao_page_translation_run() result.
	 */
	private static function render_report( string $mode, array $report ): void {
		$s     = $report['summary'];
		$class = $s['errors'] > 0 ? 'notice-error' : ( 'apply' === $mode ? 'notice-success' : 'notice-info' );
		printf(
			'<div class="notice %s"><p><strong>%s</strong>: created %d, updated %d, already existed %d, errors %d, internal links localised %d, PT pages changed %d.</p></div>',
			esc_attr( $class ),
			esc_html( 'preview' === $mode ? 'PREVIEW (nothing written)' : 'APPLY' ),
			(int) $s['created'],
			(int) $s['updated'],
			(int) $s['exists'],
			(int) $s['errors'],
			(int) $s['links_localized'],
			(int) $s['pt_changed']
		);
		echo '<table class="widefat striped"><thead><tr><th>PT slug</th><th>EN slug</th><th>action</th><th>EN page</th><th>message</th></tr></thead><tbody>';
		foreach ( $report['rows'] as $row ) {
			printf(
				'<tr><td><code>%s</code></td><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $row['slug'] ),
				esc_html( $row['en_slug'] ?? '' ),
				esc_html( $row['action'] ),
				esc_html( $row['en_id'] ? '#' . $row['en_id'] . ' ' . ( $row['en_url'] ?? '' ) : '' ),
				esc_html( $row['message'] . ( ! empty( $row['links'] ) ? ' | links: ' . wp_json_encode( $row['links'] ) : '' ) )
			);
		}
		echo '</tbody></table>';
	}
}

add_action( 'plugins_loaded', array( 'Conexao_Page_Translation_Admin', 'init' ) );

