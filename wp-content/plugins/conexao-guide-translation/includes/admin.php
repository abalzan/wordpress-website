<?php
// Stage 9: admin screen Tools -> EN Guide Translations.
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Conexao_Guide_Translation_Admin {
	const CAP = 'manage_options';
	const PAGE_SLUG = 'conexao-guide-translation';
	const ACTION = 'conexao_guide_translation_run';
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_run' ) );
	}
	public static function register_menu(): void {
		add_management_page( 'EN Guide Translations', 'EN Guide Translations', self::CAP, self::PAGE_SLUG, array( __CLASS__, 'render' ) );
	}
	public static function handle_run(): void {
		if ( ! current_user_can( self::CAP ) ) { wp_die( 'forbidden' ); }
		check_admin_referer( self::ACTION, '_conexao_gt_nonce' );
		$mode = isset( $_POST['mode'] ) && 'apply' === $_POST['mode'] ? 'apply' : 'preview';
		$report = conexao_guide_translation_run( array( 'dry_run' => 'apply' !== $mode ) );
		set_transient( 'conexao_guide_translation_report_' . get_current_user_id(), array( 'mode' => $mode, 'report' => $report ), 300 );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'ran' => 1 ), admin_url( 'tools.php' ) ) );
		exit;
	}
	public static function render(): void {
		echo '<div class="wrap"><h1>EN Guide Translations</h1>';
		if ( ! function_exists( 'pll_languages_list' ) ) {
			echo '<div class="notice notice-error"><p><strong>Polylang is not active.</strong></p></div></div>';
			return;
		}
		$report = null;
		if ( isset( $_GET['ran'] ) ) {
			$report = get_transient( 'conexao_guide_translation_report_' . get_current_user_id() );
			delete_transient( 'conexao_guide_translation_report_' . get_current_user_id() );
		}
		if ( $report ) { self::render_report( $report['mode'], $report['report'] ); }
		$audit = conexao_guide_translation_audit();
		echo '<h2>Completeness audit</h2><table class="widefat striped"><tbody>';
		foreach ( $audit['counts'] as $label => $value ) {
			printf( '<tr><th style="width:360px">%s</th><td>%s</td></tr>', esc_html( (string) $label ), esc_html( (string) $value ) );
		}
		printf( '<tr><th>Gate</th><td><strong style="color:%s">%d missing</strong></td></tr>', $audit['pass'] ? '#008a20' : '#b32d2e', (int) $audit['counts']['eligible public PT guides missing EN'] );
		echo '</tbody></table>';
		if ( ! empty( $audit['missing'] ) ) {
			echo '<h3>Missing EN</h3><ul>';
			foreach ( $audit['missing'] as $row ) { printf( '<li><code>%s</code> (#%d %s)</li>', esc_html( $row['slug'] ), (int) $row['id'], esc_html( $row['title'] ) ); }
			echo '</ul>';
		}
		echo '<h2>Run</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION, '_conexao_gt_nonce' );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<p><button class="button" name="mode" value="preview">Preview (dry run)</button> <button class="button button-primary" name="mode" value="apply">Apply</button></p></form></div>';
	}
	private static function render_report( string $mode, array $report ): void {
		$s = $report['summary'];
		printf( '<div class="notice notice-%s"><p><strong>%s</strong> — created %d, updated %d, skipped %d, errors %d; terms created %d, linked %d; links %d; PT changed: %d.</p></div>', $s['errors'] ? 'error' : 'success', esc_html( 'apply' === $mode ? 'Applied' : 'Preview' ), (int) $s['created'], (int) $s['updated'], (int) $s['skipped'], (int) $s['errors'], (int) $s['terms_created'], (int) $s['terms_linked'], (int) $s['links_localized'], (int) $s['pt_changed'] );
		echo '<table class="widefat striped"><thead><tr><th>PT slug</th><th>PT</th><th>EN</th><th>URL</th><th>Action</th><th>Msg</th></tr></thead><tbody>';
		foreach ( $report['rows'] as $row ) {
			printf( '<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( (string) $row['pt_slug'] ), $row['pt_id'] ? '#' . (int) $row['pt_id'] : '—', $row['en_id'] ? '#' . (int) $row['en_id'] : '—', $row['en_url'] ? esc_html( (string) $row['en_url'] ) : '—', esc_html( (string) $row['action'] ), esc_html( (string) $row['message'] ) );
		}
		echo '</tbody></table>';
	}
}
