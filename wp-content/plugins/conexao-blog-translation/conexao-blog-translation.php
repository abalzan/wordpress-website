<?php
/**
 * Conexão BR Irlanda — EN Blog Translation (Stage 5).
 *
 * Turns the Blog from the approved B2 fallback (`/en/blog/` rendering the
 * Portuguese posts under the English URL) into a REAL English translation:
 *
 *   1. the posts page (`page_for_posts`) gets a linked English translation, so
 *      `/en/blog/` is a genuine English archive (own EN posts page record);
 *   2. every eligible public Portuguese `post` gets exactly ONE linked English
 *      translation, authored in includes/translation-map.php;
 *   3. the `category` terms used by those posts get linked English terms;
 *   4. internal links between Blog posts are resolved through the Polylang
 *      relationship (never by string replacement);
 *   5. the Portuguese originals are verified unchanged after the run.
 *
 * Why a plugin (not a REST script)? Production is WordPress.com (no WP-CLI),
 * and Polylang Free 3.8.9 cannot write the `translations` relationship through
 * the public REST API (the same constraint documented for
 * conexao-page-translation). The established content-migration pattern in this
 * project is an admin-screen importer; this plugin follows it exactly and is
 * safe to deactivate after the rollout (it has no front-end behaviour).
 *
 * @package Conexao_Blog_Translation
 */

defined( 'ABSPATH' ) || exit;

define( 'CONEXAO_BLOG_TRANSLATION_FILE', __FILE__ );
define( 'CONEXAO_BLOG_TRANSLATION_DIR', plugin_dir_path( __FILE__ ) );

require_once CONEXAO_BLOG_TRANSLATION_DIR . 'includes/translation-map.php';
require_once CONEXAO_BLOG_TRANSLATION_DIR . 'includes/apply.php';
require_once CONEXAO_BLOG_TRANSLATION_DIR . 'includes/audit.php';

/**
 * Admin screen: Tools → EN Blog Translations.
 */
final class Conexao_Blog_Translation_Admin {

	const CAP       = 'manage_options';
	const PAGE_SLUG = 'conexao-blog-translation';
	const ACTION    = 'conexao_blog_translation_run';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_run' ) );
	}

	public static function register_menu(): void {
		add_management_page(
			'EN Blog Translations',
			'EN Blog Translations',
			self::CAP,
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Execute the run (preview or apply) and redirect back with the report.
	 */
	public static function handle_run(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'forbidden' );
		}
		check_admin_referer( self::ACTION, '_conexao_bt_nonce' );

		$mode = isset( $_POST['mode'] ) && 'apply' === $_POST['mode'] ? 'apply' : 'preview';

		$report = conexao_blog_translation_run( array( 'dry_run' => ( 'apply' !== $mode ) ) );
		set_transient( 'conexao_blog_translation_report', array( 'mode' => $mode, 'report' => $report ), 10 * MINUTE_IN_SECONDS );

		wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE_SLUG . '&ran=' . $mode ) );
		exit;
	}
	/**
	 * Render the current state, the completeness audit and the last report.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'forbidden' );
		}

		$polylang = function_exists( 'pll_get_post' );
		$report   = get_transient( 'conexao_blog_translation_report' );
		if ( $report ) {
			delete_transient( 'conexao_blog_translation_report' );
		}

		echo '<div class="wrap"><h1>EN Blog Translations</h1>';

		if ( ! $polylang ) {
			echo '<div class="notice notice-error"><p><strong>Polylang is not active.</strong> Deploy the English language layer first (docs/routing.md § English), then run this migration.</p></div></div>';
			return;
		}

		if ( $report ) {
			self::render_report( $report['mode'], $report['report'] );
		}

		$audit = conexao_blog_translation_audit();
		echo '<h2>Completeness audit</h2>';
		echo '<table class="widefat striped"><tbody>';
		foreach ( $audit['counts'] as $label => $value ) {
			printf( '<tr><th style="width:360px">%s</th><td>%s</td></tr>', esc_html( (string) $label ), esc_html( (string) $value ) );
		}
		printf(
			'<tr><th>Gate: eligible public PT posts missing EN</th><td><strong style="color:%s">%d</strong></td></tr>',
			$audit['pass'] ? '#008a20' : '#b32d2e',
			(int) $audit['counts']['eligible public PT posts missing EN']
		);
		echo '</tbody></table>';

		if ( ! empty( $audit['missing'] ) ) {
			echo '<h3>Public PT posts still missing an EN translation</h3><ul>';
			foreach ( $audit['missing'] as $row ) {
				printf( '<li><code>%s</code> (#%d %s)</li>', esc_html( (string) $row['slug'] ), (int) $row['id'], esc_html( (string) $row['title'] ) );
			}
			echo '</ul>';
		}

		if ( ! empty( $audit['en_without_pt'] ) ) {
			echo '<h3>EN posts with no PT sibling (must be 0)</h3><ul>';
			foreach ( $audit['en_without_pt'] as $row ) {
				printf( '<li><code>%s</code> (#%d)</li>', esc_html( (string) $row['slug'] ), (int) $row['id'] );
			}
			echo '</ul>';
		}

		echo '<h2>Run the migration</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION, '_conexao_bt_nonce' );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<p><button class="button" name="mode" value="preview">Preview (dry run)</button> ';
		echo '<button class="button button-primary" name="mode" value="apply">Apply</button></p>';
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Render a run report.
	 *
	 * @param string $mode   'preview' or 'apply'.
	 * @param array  $report Report from conexao_blog_translation_run().
	 */
	private static function render_report( string $mode, array $report ): void {
		$s = $report['summary'];
		printf(
			'<div class="notice notice-%s"><p><strong>%s</strong> — posts created %d, updated %d, skipped %d, errors %d; category terms created %d, linked %d; internal links localized %d; posts page: %s; PT sources changed: %d.</p></div>',
			$s['errors'] ? 'error' : 'success',
			esc_html( 'apply' === $mode ? 'Applied' : 'Preview (dry run)' ),
			(int) $s['created'],
			(int) $s['updated'],
			(int) $s['skipped'],
			(int) $s['errors'],
			(int) $s['terms_created'],
			(int) $s['terms_linked'],
			(int) $s['links_localized'],
			esc_html( (string) $s['posts_page'] ),
			(int) $s['pt_changed']
		);

		echo '<table class="widefat striped"><thead><tr><th>PT slug</th><th>PT</th><th>EN</th><th>EN URL</th><th>Action</th><th>Message</th></tr></thead><tbody>';
		foreach ( $report['rows'] as $row ) {
			printf(
				'<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( (string) $row['pt_slug'] ),
				$row['pt_id'] ? '#' . (int) $row['pt_id'] : '—',
				$row['en_id'] ? '#' . (int) $row['en_id'] : '—',
				$row['en_url'] ? esc_html( (string) $row['en_url'] ) : '—',
				esc_html( (string) $row['action'] ),
				esc_html( (string) $row['message'] )
			);
		}
		echo '</tbody></table>';
	}
}

Conexao_Blog_Translation_Admin::init();
