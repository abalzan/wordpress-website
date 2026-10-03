<?php
/**
 * run-job-translation.php — EN Job translation runner (local/staging driver).
 *
 * Purpose: run the `job` stage of the shared translation rollout engine
 * (conexao-translation-rollout) against the local/staging WordPress.
 *
 * Safety: local-write. Dry-run is the default and performs ZERO writes.
 *
 * Scope:
 *   Creates/updates English translation records for the job stage declared by
 *   conexao_job_translation_engine_config() only.
 *   Does not modify Portuguese source records (the engine's pt_drift gate
 *   asserts this and is reported in the summary).
 *   Does not touch any other post type, taxonomy or option.
 *
 * The lifecycle itself is owned by the shared engine (Stage H); this script
 * owns no orchestration and adds no behaviour of its own.
 *
 * Usage (local Docker, from the project root):
 *   docker compose exec -T wordpress wp eval-file scripts/run-job-translation.php --allow-root -- --dry-run
 *   docker compose exec -T wordpress wp eval-file scripts/run-job-translation.php --allow-root -- --dry-run --json
 *   docker compose exec -T wordpress wp eval-file scripts/run-job-translation.php --allow-root -- --apply
 *   docker compose exec -T wordpress wp eval-file scripts/run-job-translation.php --allow-root -- --help
 *
 * Legacy tokens `dry-run` and `json` (without dashes) are still accepted
 * because WP-CLI's eval-file forwards bare tokens; they emit a deprecation
 * notice. Use the dashed form.
 *
 * LOCAL / STAGING ONLY — never point this at production (the WordPress.com
 * deployment uses the shared admin screen, Tools → Translation Rollouts; see
 * docs/plugins/conexao-translation-rollout.md).
 *
 * @package Conexao_Job_Translation
 *
 * HISTORICAL — NOT SUPPORTED TOOLING (Stage 19).
 *
 * The rollout plugin this script drove was removed from the active repository
 * in Stage 19, because its authored translation data had already been
 * consolidated into the shared translation stages of `conexao-en-translation`.
 * This script therefore CANNOT run: the functions it calls no longer exist.
 * It is kept only for provenance, alongside `scripts/historical/stage5-blog-inventory.php`
 * and the Stage 5/6/7/9 reports that describe the architecture as it was.
 * The supported manual translation workflow is the operator runbook
 * (`docs/translation-manual-operator-runbook.md`) and the seven stages of
 * `conexao-en-translation`. Historical provenance is also recoverable from
 * Git history at the commit that removed the retired plugins.
 *
 */

require_once __DIR__ . '/../lib/bootstrap.php';

// WP-CLI's eval-file forwards remaining tokens positionally, so the legacy
// bare tokens are mapped onto the standard flags before booting.
$conexao_legacy_tokens = isset( $args ) ? (array) $args : array();
$conexao_legacy_note   = false;
foreach ( $conexao_legacy_tokens as $conexao_i => $conexao_token ) {
	if ( 'dry-run' === $conexao_token ) {
		$conexao_legacy_tokens[ $conexao_i ] = '--dry-run';
		$conexao_legacy_note                = true;
	} elseif ( 'json' === $conexao_token ) {
		$conexao_legacy_tokens[ $conexao_i ] = '--json';
		$conexao_legacy_note                = true;
	}
}
if ( $conexao_legacy_note ) {
	fwrite( STDERR, "DEPRECATED: bare 'dry-run'/'json' tokens; use --dry-run / --json.\n" );
}
$args = $conexao_legacy_tokens;

$ctx = conexao_script_boot(
	array(
		'script'              => 'run-job-translation.php',
		'purpose'             => 'Run the shared rollout engine for the EN Job translation stage.',
		'scope'               => "Creates/updates English translation records for the job stage only.\n"
			. 'Does not modify Portuguese source records. Does not touch other post types, taxonomies or options.',
		'safety'              => 'local-write; dry-run is the default and performs zero writes.',
		'safety_level'        => 'local-write',
		'target_description'  => 'The local/staging WordPress this script is executed against.',
		'modes_description'   => '--dry-run plans and reports only. --apply performs the writes.',
		'arguments'           => array( '(no script-specific arguments)' ),
		'writes'              => true,
		'production_capable'  => false,
		'json'                => true,
		'environment'         => array(
			'CONEXAO_SITE_URL  Optional target override; defaults to the loaded install.',
		),
	)
);

$dry_run = ( 'apply' !== $ctx['mode'] );

if ( ! class_exists( 'Conexao_Translation_Rollout_Engine' ) || ! function_exists( 'conexao_job_translation_engine_config' ) ) {
	conexao_script_fail( 'the shared engine (conexao-translation-rollout) and the job stage (conexao-job-translation) must both be active.' );
}

$config  = conexao_job_translation_engine_config();
$adapter = conexao_job_translation_engine_adapter();
$report  = Conexao_Translation_Rollout_Engine::run( $config, $adapter, array( 'dry_run' => $dry_run ) );

if ( is_wp_error( $report ) ) {
	conexao_script_fail( $report->get_error_message() );
}

if ( $ctx['json'] ) {
	echo wp_json_encode(
		array(
			'script'  => $ctx['script'],
			'target'  => $ctx['target_url'],
			'mode'    => $ctx['mode'],
			'stage'   => $config['stage'],
			'summary' => $report['summary'],
			'plan'    => $report['plan'],
			'rows'    => $report['rows'],
			'verify'  => $report['verify'],
			'gate'    => $report['gate'],
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	), "\n";
} else {
	$s = $report['summary'];
	$g = $report['gate'];

	echo '== EN Job translation (shared engine) — ' . $ctx['mode'] . "\n";
	echo "jobs: created {$s['created']}, updated {$s['updated']}, skipped {$s['skipped']}, errors {$s['errors']}\n";
	echo "meta fields copied: {$s['meta_copied']}\n";
	echo "match strategy: stable-id {$s['match']['stable-id']}, slug fallback {$s['match']['slug']}, title fallback {$s['match']['title']}\n";
	echo "PT sources changed (must be 0): {$s['pt_changed']}\n";

	foreach ( $report['rows'] as $row ) {
		printf(
			"  %-40s → %-12s %s\n",
			(string) $row['stable_key'],
			(string) $row['action'],
			(string) $row['message']
		);
	}

	echo "\n-- numeric gate --\n";
	printf( "  %-42s %d\n", 'eligible public PT jobs', $g['eligible_public_pt'] );
	printf( "  %-42s %d\n", 'with EN translation', $g['with_en'] );
	printf( "  %-42s %d\n", 'missing EN (gate condition)', $g['missing_en'] );
	printf( "  %-42s %d\n", 'conflicts', $g['conflicts'] );
	printf( "  %-42s %d\n", 'PT drift', $g['pt_drift'] );
	printf( "  %-42s %d\n", 'extra gate failures', $g['extra_failures'] );
	echo '  GATE: ' . $g['gate'] . "\n";

	conexao_script_summary(
		$ctx,
		array(
			'create'    => (int) $s['created'],
			'update'    => (int) $s['updated'],
			'skip'      => (int) $s['skipped'],
			'conflicts' => (int) $g['conflicts'],
			'errors'    => (int) $s['errors'],
			'gate'      => (string) $g['gate'],
			'pt_changed' => (int) $s['pt_changed'],
		)
	);
}
