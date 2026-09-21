<?php
/**
 * Stage 3.2 — editorial translation-state indicator tests.
 *
 * Verifies the minimal admin indicator built on the existing Admin UX
 * architecture:
 *
 *  - state resolution: missing / current / outdated / not_applicable, from
 *    actual Polylang links + the `_translation_outdated` flag;
 *  - the documented sync rule: saving the PT source flags linked EN
 *    translations outdated; saving the EN translation clears its own flag;
 *    a newly created translation starts clean;
 *  - the EN record shows its relationship to the PT source;
 *  - the PT source itself never carries the flag;
 *  - revisions/autosaves never change the state;
 *  - the flag is never registered for REST exposure (admin-only surface).
 *
 * Fixtures are prefixed [STAGE32-TS] and deleted at the end.
 *
 * Usage (from the project root):
 *   php wp-content/plugins/conexao-admin-ux/tests/test-translation-state.php
 */

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

$passed = 0;
$failed = 0;

function ts_assert( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		echo "  PASS: {$message}\n";
	} else {
		$failed++;
		echo "  FAIL: {$message}\n";
	}
}

echo "== Stage 3.2 — translation state indicator ==\n";

if ( ! class_exists( 'Conexao_Admin_Ux_Translation_State' ) || ! function_exists( 'pll_save_post_translations' ) ) {
	echo "  SKIP: module or Polylang unavailable.\n";
	exit( 0 );
}

$created = array();

function ts_post( $post_type, $slug, $title, $lang ) {
	global $created;
	$id = wp_insert_post(
		array(
			'post_type'    => $post_type,
			'post_name'    => $slug,
			'post_title'   => $title,
			'post_content' => 'Translation-state fixture.',
			'post_status'  => 'publish',
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		echo '  FAIL: fixture insert failed: ' . $id->get_error_message() . "\n";
		return 0;
	}
	pll_set_post_language( $id, $lang );
	$created[] = $id;
	return (int) $id;
}

// ---------------------------------------------------------------------------
echo "\n-- missing → current → outdated → current --\n";

$pt = ts_post( 'guide', 'stage32-ts-guide', '[STAGE32-TS] Guia', 'pt' );
ts_assert( $pt > 0, 'PT guide fixture created' );

$state = Conexao_Admin_Ux_Translation_State::get_state( $pt );
ts_assert( 'missing' === $state['state'], 'untranslated PT record reads missing' );
ts_assert( $pt === $state['source_id'] && 0 === $state['translation_id'], 'missing state carries the PT source id and no translation id' );

$en = ts_post( 'guide', 'stage32-ts-guide-en', '[STAGE32-TS] Guide', 'en' );
pll_save_post_translations( array( 'pt' => $pt, 'en' => $en ) );

$state = Conexao_Admin_Ux_Translation_State::get_state( $pt );
ts_assert( 'current' === $state['state'] && $en === $state['translation_id'], 'linked EN translation reads current on the PT side' );

$state_en = Conexao_Admin_Ux_Translation_State::get_state( $en );
ts_assert( 'current' === $state_en['state'] && $pt === $state_en['source_id'] && $en === $state_en['translation_id'], 'EN record resolves its PT source relationship' );

// Saving the PT source flags the EN translation outdated.
wp_update_post( array( 'ID' => $pt, 'post_content' => 'Conteúdo atualizado.' ) );
ts_assert( (bool) get_post_meta( $en, '_translation_outdated', true ), 'saving the PT source flags the EN translation outdated' );
ts_assert( 'outdated' === Conexao_Admin_Ux_Translation_State::get_state( $pt )['state'], 'PT side reads outdated' );
ts_assert( 'outdated' === Conexao_Admin_Ux_Translation_State::get_state( $en )['state'], 'EN side reads outdated' );
ts_assert( '' === (string) get_post_meta( $pt, '_translation_outdated', true ), 'the PT master never carries the flag' );

// Saving the EN translation clears only its own flag.
wp_update_post( array( 'ID' => $en, 'post_content' => 'Updated content.' ) );
ts_assert( ! get_post_meta( $en, '_translation_outdated', true ), 'saving the EN translation clears its own flag' );
ts_assert( 'current' === Conexao_Admin_Ux_Translation_State::get_state( $pt )['state'], 'state returns to current after the EN save' );

// ---------------------------------------------------------------------------
echo "\n-- revision/autosave isolation --\n";
$revision_id = wp_save_post_revision( $pt );
ts_assert( ! get_post_meta( $en, '_translation_outdated', true ), 'saving a PT revision does NOT flag the translation' );

// ---------------------------------------------------------------------------
echo "\n-- source-inherited (standalone EN) record --\n";
$standalone = ts_post( 'event', 'stage32-ts-standalone', '[STAGE32-TS] English-native event', 'en' );
$state      = Conexao_Admin_Ux_Translation_State::get_state( $standalone );
ts_assert( 'current' === $state['state'] && 0 === $state['source_id'], 'standalone EN record reads current without a PT source' );

// ---------------------------------------------------------------------------
echo "\n-- not applicable --\n";
$agency = wp_insert_post( array( 'post_type' => 'recruitment_agency', 'post_title' => '[STAGE32-TS] Agency', 'post_status' => 'publish' ), true );
if ( ! is_wp_error( $agency ) ) {
	$created[] = $agency;
	ts_assert( 'not_applicable' === Conexao_Admin_Ux_Translation_State::get_state( $agency )['state'], 'admin-only record type is not applicable' );
}

// ---------------------------------------------------------------------------
echo "\n-- list column + metabox rendering --\n";

$columns = Conexao_Admin_Ux_Translation_State::column( array( 'title' => 'Título' ) );
ts_assert( isset( $columns['conexao_translation_state'] ), 'list column registered' );

ob_start();
Conexao_Admin_Ux_Translation_State::render_column( 'conexao_translation_state', $pt );
$cell = (string) ob_get_clean();
ts_assert( false !== strpos( $cell, 'Atual' ), 'column shows current state for a current pair' );

wp_update_post( array( 'ID' => $pt, 'post_content' => 'Mudança de conteúdo.' ) );
ob_start();
Conexao_Admin_Ux_Translation_State::render_column( 'conexao_translation_state', $pt );
$cell = (string) ob_get_clean();
ts_assert( false !== strpos( $cell, 'Desatualizada' ), 'column shows outdated state after a PT edit' );

$untranslated = ts_post( 'guide', 'stage32-ts-untranslated', '[STAGE32-TS] Sem tradução', 'pt' );
ob_start();
Conexao_Admin_Ux_Translation_State::render_column( 'conexao_translation_state', $untranslated );
$cell = (string) ob_get_clean();
ts_assert( false !== strpos( $cell, 'Ausente' ), 'column shows missing state for an untranslated record' );

ob_start();
Conexao_Admin_Ux_Translation_State::render_metabox( get_post( $en ) );
$box = (string) ob_get_clean();
ts_assert( false !== strpos( $box, 'Tradução de:' ) && false !== strpos( $box, 'Desatualizada' ), 'EN metabox shows the PT relationship and the state' );

ob_start();
Conexao_Admin_Ux_Translation_State::render_metabox( get_post( $pt ) );
$box = (string) ob_get_clean();
ts_assert( false !== strpos( $box, 'Tradução em inglês:' ), 'PT metabox shows the EN state' );

// ---------------------------------------------------------------------------
echo "\n-- public non-exposure --\n";
$registered = get_registered_meta_keys( 'post', 'guide' );
ts_assert( ! isset( $registered['_translation_outdated'] ), '_translation_outdated is NOT registered as public/REST meta' );

// No front-end consumer may exist: the meta key must appear only inside the
// admin-ux plugin code.
$hits = array();
$scan = array(
	'theme'  => dirname( __DIR__ ) . '/../../themes/conexao-br-irlanda',
);
foreach ( $scan as $label => $dir ) {
	$dir = realpath( $dir );
	if ( ! $dir ) {
		continue;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( 'php' !== $file->getExtension() ) {
			continue;
		}
		if ( false !== strpos( (string) file_get_contents( $file->getPathname() ), '_translation_outdated' ) ) {
			$hits[] = $file->getPathname();
		}
	}
}
ts_assert( array() === $hits, 'no theme/front-end file reads _translation_outdated' );

// ---------------------------------------------------------------------------
foreach ( array_unique( $created ) as $post_id ) {
	wp_delete_post( (int) $post_id, true );
}

echo "\ntranslation state: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
