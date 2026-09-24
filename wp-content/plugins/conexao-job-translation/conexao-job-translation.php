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

require_once CONEXAO_JOB_TRANSLATION_DIR . 'includes/translation-map.php';
require_once CONEXAO_JOB_TRANSLATION_DIR . 'includes/apply.php';
require_once CONEXAO_JOB_TRANSLATION_DIR . 'includes/audit.php';

/**
 * Admin screen: Tools → EN Job Translations.
 */
final class Conexao_Job_Translation_Admin {

	const CAP       = 'manage_options';
	const PAGE_SLUG = 'conexao-job-translation';
	const ACTION    = 'conexao_job_translation_run';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_run' ) );
	}

	public static function register_menu(): void {
		add_management_page(
			'EN Job Translations',
			'EN Job Translations',
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
		check_admin_referer( self::ACTION, '_conexao_jt_nonce' );

		$mode = isset( $_POST['mode'] ) && 'apply' === $_POST['mode'] ? 'apply' : 'preview';

		$report = conexao_job_translation_run( array( 'dry_run' => 'apply' !== $mode ) );

		set_transient(
			'conexao_job_translation_report_' . get_current_user_id(),
			array(
				'mode'   => $mode,
				'report' => $report,
			),
			5 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Render the admin screen: audit table + dry-run/apply controls.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'forbidden' );
		}

		$report = get_transient( 'conexao_job_translation_report_' . get_current_user_id() );
		if ( $report ) {
			delete_transient( 'conexao_job_translation_report_' . get_current_user_id() );
		}

		echo '<div class="wrap"><h1>EN Job Translations (Stage 6)</h1>';

		if ( ! function_exists( 'pll_languages_list' ) ) {
			echo '<div class="notice notice-error"><p><strong>Polylang is not active.</strong> Deploy the English language layer first (docs/routing.md § English), then run this migration.</p></div></div>';
			return;
		}

		if ( $report ) {
			self::render_report( $report['mode'], $report['report'] );
		}

		$audit = conexao_job_translation_audit();
		echo '<h2>Completeness audit</h2>';
		echo '<table class="widefat striped"><tbody>';
		foreach ( $audit['counts'] as $label => $value ) {
			printf( '<tr><th style="width:360px">%s</th><td>%s</td></tr>', esc_html( (string) $label ), esc_html( (string) $value ) );
		}
		printf(
			'<tr><th>Gate: eligible public PT jobs missing EN</th><td><strong style="color:%s">%d</strong></td></tr>',
			$audit['pass'] ? '#008a20' : '#b32d2e',
			(int) $audit['counts']['eligible public PT jobs missing EN']
		);
		echo '</tbody></table>';

		if ( ! empty( $audit['missing'] ) ) {
			echo '<h3>Public PT jobs still missing an EN translation</h3><ul>';
			foreach ( $audit['missing'] as $row ) {
				printf( '<li><code>%s</code> (#%d %s)</li>', esc_html( (string) $row['slug'] ), (int) $row['id'], esc_html( (string) $row['title'] ) );
			}
			echo '</ul>';
		}

		if ( ! empty( $audit['en_without_pt'] ) ) {
			echo '<h3>EN jobs with no PT sibling (must be 0)</h3><ul>';
			foreach ( $audit['en_without_pt'] as $row ) {
				printf( '<li><code>%s</code> (#%d)</li>', esc_html( (string) $row['slug'] ), (int) $row['id'] );
			}
			echo '</ul>';
		}

		echo '<h2>Run the migration</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION, '_conexao_jt_nonce' );
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
	 * @param array  $report Report from conexao_job_translation_run().
	 */
	private static function render_report( string $mode, array $report ): void {
		$s = $report['summary'];
		printf(
			'<div class="notice notice-%s"><p><strong>%s</strong> — jobs created %d, updated %d, skipped %d, errors %d; meta fields copied %d; jobs page: %s; PT sources changed: %d.</p></div>',
			$s['errors'] ? 'error' : 'success',
			esc_html( 'apply' === $mode ? 'Applied' : 'Preview (dry run)' ),
			(int) $s['created'],
			(int) $s['updated'],
			(int) $s['skipped'],
			(int) $s['errors'],
			(int) $s['meta_copied'],
			esc_html( (string) $s['jobs_page'] ),
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

Conexao_Job_Translation_Admin::init();

