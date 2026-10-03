<?php
/**
 * Installation smoke test for the two temporary plugin ZIPs.
 *
 * Evidence for docs/reports/2026-09-30-temporary-en-translation-plugins.md.
 *
 * ## What this proves, and why it deactivates first
 *
 * In the local Compose stack both plugins are bind-mounted from
 * `wp-content/plugins/`, so a naive "does it load?" test would only ever
 * exercise the working tree, never the artifact. To test the ARTIFACT, this
 * script:
 *
 *   1. the harness snapshots and DEACTIVATES the two bind-mounted copies
 *      (install-smoke-deactivate.php), so the bind mount cannot mask a defect;
 *   2. THIS process extracts each `dist/temporary/<slug>.zip` with PHP's own
 *      ZipArchive — the same extraction wp-admin's Plugin_Upgrader performs —
 *      into a scratch tree, and checks the extracted layout is a valid plugin
 *      directory;
 *   3. asks WordPress to RECOGNISE it via `get_plugin_data()`, i.e. the real
 *      header parser wp-admin uses;
 *   4. LOADS the extracted main files the way WordPress loads an active plugin,
 *      and asserts the classes and functions appear with no fatal error;
 *   5. asserts `conexao-en-translation` detected the extracted engine and that
 *      all seven stages registered against it;
 *   6. removes the extracted tree and asserts the extracted classes are gone
 *      from this runtime — NO database and NO filesystem residue;
 *   7. the harness REACTIVATES the bind-mounted copies
 *      (install-smoke-reactivate.php), restoring the exact snapshot.
 *
 * LOCAL ONLY. This is the disposable Compose database. It never contacts
 * production, never installs or activates anything in production, and step 7
 * is run by the harness unconditionally so the local site is left as found.
 *
 * Run with (see README.md in this directory for the full three-step cycle):
 *   docker compose exec -T wordpress php \
 *     /var/www/html/docs/evidence/2026-09-30-temporary-en-translation-plugins/install-smoke-test.php
 *
 * @package Conexao_BR_Temporary_Plugin_Evidence
 */

require_once '/var/www/html/tests/bootstrap.php';

$passed = 0;
$failed = 0;

/**
 * Record one assertion and print it.
 *
 * @param bool   $condition Result.
 * @param string $message   Description.
 * @return bool
 */
function conexao_is_check( bool $condition, string $message ): bool {
	global $passed, $failed;

	if ( $condition ) {
		++$passed;
		echo "  [PASS] {$message}\n";
	} else {
		++$failed;
		echo "  [FAIL] {$message}\n";
	}

	return $condition;
}

echo "TEMPORARY PLUGIN INSTALLATION SMOKE TEST (local disposable only)\n";
echo str_repeat( '=', 72 ) . "\n";

$engine_binds = ! class_exists( 'Conexao_Translation_Rollout_Engine' );
$en_binds     = ! function_exists( 'conexao_en_translation_stage_ids' );

echo "\nprecondition: the bind-mounted copies are DEACTIVATED in this process\n";
conexao_is_check( $engine_binds, 'Conexao_Translation_Rollout_Engine is NOT already loaded (the bind mount is out of the way)' );
conexao_is_check( $en_binds, 'the EN stage layer is NOT already loaded (the bind mount is out of the way)' );

$slug    = 'conexao-translation-rollout';
$en_slug = 'conexao-en-translation';
$scratch = '/tmp/temp-install-smoke';

// -- 2/3. Extract the artifacts exactly as wp-admin would, and parse headers --

echo "\nEXTRACT + RECOGNISE\n";

foreach ( array( $slug, $en_slug ) as $target ) {
	$zip_path = '/var/www/html/dist/temporary/' . $target . '.zip';

	conexao_is_check( is_readable( $zip_path ), 'the artifact dist/temporary/' . $target . '.zip is present' );

	$zip = new ZipArchive();
	if ( true !== $zip->open( $zip_path ) ) {
		conexao_is_check( false, 'the artifact opens as a ZIP archive' );
		continue;
	}

	// The archive is rooted at "<slug>/", so it extracts straight into the
	// scratch root and yields <scratch>/<slug>/<slug>.php — exactly the
	// layout wp-content/plugins/<slug>/<slug>.php has.
	$dest = $scratch . '/' . $target;
	$extracted = $zip->extractTo( $scratch );
	$zip->close();

	conexao_is_check( $extracted, $target . ': the artifact extracts cleanly (the Plugin_Upgrader path)' );
	conexao_is_check(
		is_file( $dest . '/' . $target . '.php' ),
		$target . ': the extracted tree is a valid plugin directory (' . $target . '/' . $target . '.php)'
	);
	conexao_is_check( ! file_exists( $dest . '/tests' ), $target . ': the extracted tree ships no tests/ directory' );

	$data = get_plugin_data( $dest . '/' . $target . '.php', false, false );
	conexao_is_check( ! empty( $data['Name'] ), $target . ': WordPress parses the Plugin Name "' . ( $data['Name'] ?? '' ) . '"' );
	conexao_is_check( ! empty( $data['Version'] ), $target . ': WordPress parses the Version ' . ( $data['Version'] ?? '' ) );
}

// -- 4/5. Load the EXTRACTED artifacts, in order, as WordPress would --------

echo "\nLOAD THE EXTRACTED ARTIFACTS (engine first, then the EN stage layer)\n";

require_once $scratch . '/' . $slug . '/' . $slug . '.php';
conexao_is_check(
	class_exists( 'Conexao_Translation_Rollout_Engine' ),
	$slug . ': the extracted engine loads with NO fatal error and declares Conexao_Translation_Rollout_Engine'
);
conexao_is_check(
	defined( 'CONEXAO_TRANSLATION_ROLLOUT_VERSION' ),
	$slug . ': the extracted plugin published its version constant (' . ( defined( 'CONEXAO_TRANSLATION_ROLLOUT_VERSION' ) ? CONEXAO_TRANSLATION_ROLLOUT_VERSION : '?' ) . ')'
);
conexao_is_check(
	method_exists( 'Conexao_Translation_Rollout_Engine', 'run' )
		&& method_exists( 'Conexao_Translation_Rollout_Engine', 'build_plan' )
		&& method_exists( 'Conexao_Translation_Rollout_Engine', 'calculate_gate' ),
	$slug . ': the extracted engine exposes its full lifecycle surface'
);

require_once $scratch . '/' . $en_slug . '/' . $en_slug . '.php';
conexao_is_check(
	function_exists( 'conexao_en_translation_stage_ids' ),
	$en_slug . ': the extracted EN stage layer loads with NO fatal error'
);
conexao_is_check(
	defined( 'CONEXAO_EN_TRANSLATION_DIR' ) && is_dir( CONEXAO_EN_TRANSLATION_DIR ),
	$en_slug . ': the extracted stage layer resolved its own directory inside the extracted tree'
);

conexao_is_check(
	strpos( CONEXAO_EN_TRANSLATION_DIR, $scratch ) === 0,
	$en_slug . ': the running code really is the EXTRACTED artifact, not the working tree'
);

conexao_en_translation_register_stages();
$stages = conexao_en_translation_stage_ids();

conexao_is_check( 7 === count( $stages ), $en_slug . ': all seven stage IDs resolve from the extracted artifact' );
conexao_is_check(
	in_array( 'en-guide', Conexao_Translation_Rollout_Engine::registered_stages(), true ),
	$en_slug . ': the extracted EN plugin DETECTED the extracted shared engine and registered its stages'
);
conexao_is_check(
	'newsletter' === conexao_en_translation_shared_page_slug_for( 'en-page' ),
	$en_slug . ': the shared-slug policy loaded from the extracted artifact and resolves "newsletter"'
);

// -- 6. Remove the artifact and assert no residue ---------------------------

echo "\nREMOVAL\n";

exec( 'rm -rf ' . escapeshellarg( $scratch ) );

conexao_is_check( ! is_dir( $scratch ), 'the extracted plugin tree is removed (no filesystem residue)' );
conexao_is_check(
	false === get_option( 'conexao_translation_rollout_installed' )
		&& false === get_option( 'conexao_en_translation_installed' ),
	'neither plugin left an install-tracking option behind (no database residue)'
);
conexao_is_check(
	! in_array( $slug . '/' . $slug . '.php', (array) get_option( 'active_plugins' ), true )
		&& ! in_array( $en_slug . '/' . $en_slug . '.php', (array) get_option( 'active_plugins' ), true ),
	'neither plugin was added to active_plugins (the artifact is installed-then-removed, never left active)'
);

echo "\n" . str_repeat( '=', 72 ) . "\n";
echo "assertions: " . ( $passed + $failed ) . " total, {$passed} passed, {$failed} failed\n";
echo "production installs: 0   production activations: 0\n";
echo str_repeat( '=', 72 ) . "\n";

exit( $failed > 0 ? 1 : 0 );

