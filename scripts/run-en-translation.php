<?php
/**
 * EN translation runner for the B1 content types.
 *
 * Stage M. Purpose: run the `guide` / `page` / `post` stages of the shared
 * translation rollout engine (conexao-translation-rollout) against the
 * local/staging WordPress. Engineering standard 4.1 and 5.2.
 * translation rollout engine (conexao-translation-rollout) against the
 * local/staging WordPress. Engineering standard 4.1 and 5.2.
 *
 * A thin CLI in front of the SHARED translation-rollout engine
 * (`conexao-translation-rollout`). It performs no planning, no counting and no
 * writing of its own: it selects a registered stage, passes `--dry-run` / `--apply`
 * / `--remove` through, and prints the engine's own plan, summary and numeric
 * gate. That is deliberate — the repository has ONE translation engine
 * (engineering standard §4.1) and this script must never become a second one.
 *
 * The authored English lives in the plugin's versioned manifest; the
 * WordPress-bound adapter and the declarative config live beside it.
 *
 * Usage:
 *   php scripts/run-en-translation.php --dry-run              # all three types
 *   php scripts/run-en-translation.php --dry-run --only=guide
 *   php scripts/run-en-translation.php --apply --only=guide
 *   php scripts/run-en-translation.php --remove --apply --only=guide   # rollback
 *
 * @package Conexao_BR_Scripts
 */

require_once __DIR__ . '/lib/bootstrap.php';

$ctx = conexao_script_boot(
	array(
		'script'             => 'run-en-translation.php',
		'purpose'            => 'Run the Stage M EN translation rollout for the B1 content types through the shared conexao-translation-rollout engine: one linked EN translation per eligible public PT record, PT sources never modified.',
		'scope'              => 'The guide / page / post stages registered by the conexao-en-translation plugin. Dry-run by default; --apply creates the EN records; --remove deletes them. A PT record is only ever read.',
		'safety'             => 'local-only; dry-run by default; --apply required to write; --only=<post_type> limits the stage; the engine verifies PT immutability and reports PT drift as a gate failure',
		'target_description' => 'the WordPress install the script is connected to (site URL printed in the header)',
		'modes_description'  => '--dry-run (default) prints the plan and writes nothing. --apply creates and links the EN records. --remove reverses a previous apply. --only=<post_type> selects one stage.',
		'arguments'          => "--dry-run            Plan only, zero writes (default).\n"
			. "                    --apply              Create and link the EN records.\n"
			. "                    --remove             Delete the EN records this stage owns (rollback).\n"
			. "                    --only=<post_type>   guide | page | post (default: all three).\n"
			. "                    --json               Machine-readable output.\n"
			. '                    --help               This message.',
		'writes'             => true,
		'read_only'          => false,
		'production_capable' => false,
		'extra_flags'        => array(
			'--only'   => 'only',
			'--remove' => 'remove',
		),
	)
);

if ( ! class_exists( 'Conexao_Translation_Rollout_Engine' ) ) {
	conexao_script_fail( 'The shared translation-rollout engine is not loaded. Activate the conexao-translation-rollout plugin.' );
}

if ( ! function_exists( 'conexao_en_translation_post_types' ) ) {
	conexao_script_fail( 'The Stage M EN translation stage is not loaded. Activate the conexao-en-translation plugin.' );
}

// Registration is deferred to `plugins_loaded`; make sure it has happened even
// when this script bootstraps WordPress directly rather than through a request.
if ( function_exists( 'conexao_en_translation_register_stages' ) ) {
	conexao_en_translation_register_stages();
}

$available = conexao_en_translation_post_types();
$only      = isset( $ctx['extra']['only'] ) ? (string) $ctx['extra']['only'] : '';

if ( '' !== $only && ! in_array( $only, $available, true ) ) {
	conexao_script_fail(
		sprintf(
			'unknown post type "%s". Available: %s',
			$only,
			implode( ', ', $available )
		)
	);
}

$stages    = ( '' === $only ) ? $available : array( $only );
$operation = ! empty( $ctx['extra']['remove'] ) ? 'remove' : 'run';

$totals_local  = array(
	'created'    => 0,
	'updated'    => 0,
	'skipped'    => 0,
	'removed'    => 0,
	'errors'     => 0,
	'conflicts'  => 0,
	'pt_changed' => 0,
);
$gate_failures = 0;
$empty_stages  = array();
$results       = array();

foreach ( $stages as $the_post_type ) {
	// A stage with no authored manifest rows is a no-op, not a failure. The
	// shared engine requires a non-empty manifest, so it is reported here
	// instead: the debt for that type is simply already closed.
	if ( array() === conexao_en_translation_manifest_for( $the_post_type )['records'] ) {
		$empty_stages[] = $the_post_type;
		echo '\n=== stage: en-' . esc_html( $the_post_type ) . " ===\n";
		echo "  no authored translations in the manifest: nothing to do.\n";
		continue;
	}

	$config = Conexao_Translation_Rollout_Engine::get_stage( 'en-' . $the_post_type );

	if ( null === $config ) {
		conexao_script_fail( sprintf( 'stage "en-%s" is not registered with the shared engine.', $the_post_type ) );
	}

	echo '\n=== stage: en-' . esc_html( $the_post_type ) . ' (mode: ' . esc_html( (string) $ctx['mode'] ) . ', operation: ' . esc_html( $operation ) . ") ===\n";

	$result = call_user_func(
		$config['run_callback'],
		array(
			'dry_run' => ( 'dry-run' === $ctx['mode'] ),
			'mode'    => $operation,
		)
	);

	if ( is_wp_error( $result ) ) {
		conexao_script_fail( sprintf( 'stage en-%s failed: %s', $the_post_type, $result->get_error_message() ) );
	}

	$stage_summary = $result['summary'];

	foreach ( array( 'created', 'updated', 'skipped', 'removed', 'errors', 'conflicts', 'pt_changed' ) as $key ) {
		$totals_local[ $key ] += (int) ( $stage_summary[ $key ] ?? 0 );
	}

	$results[ $the_post_type ] = $result;

	printf(
		'  created=%d updated=%d skipped=%d removed=%d conflicts=%d errors=%d PT-drift=%d' . "\n",
		(int) $stage_summary['created'],
		(int) $stage_summary['updated'],
		(int) $stage_summary['skipped'],
		(int) $stage_summary['removed'],
		(int) $stage_summary['conflicts'],
		(int) $stage_summary['errors'],
		(int) $stage_summary['pt_changed']
	);

	foreach ( (array) $result['rows'] as $row ) {
		printf(
			'    %-12s %-45s %s' . "\n",
			esc_html( (string) ( $row['action'] ?? '' ) ),
			esc_html( (string) ( $row['stable_key'] ?? '' ) ),
			esc_html( (string) ( $row['message'] ?? '' ) )
		);
	}

	// -- numeric gate --
	$gate = $result['gate'];
	printf(
		'  GATE %s: eligible PT=%d with EN=%d missing EN=%d conflicts=%d PT drift=%d' . "\n",
		esc_html( (string) $gate['gate'] ),
		(int) $gate['eligible_public_pt'],
		(int) $gate['with_en'],
		(int) $gate['missing_en'],
		(int) $gate['conflicts'],
		(int) $gate['pt_drift']
	);

	if ( 'PASS' !== $gate['gate'] ) {
		++$gate_failures;
	}
}

echo "\n";

if ( $ctx['json'] ) {
	echo wp_json_encode(
		array(
			'stages'       => $results,
			'empty_stages' => $empty_stages,
			'totals'       => $totals_local,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
	) . "\n";
}

exit(
	conexao_script_summary(
		$ctx,
		array_merge(
			$totals_local,
			array(
				'gate_failures' => $gate_failures,
				'errors'        => $totals_local['errors'] + $gate_failures,
			)
		)
	)
);
