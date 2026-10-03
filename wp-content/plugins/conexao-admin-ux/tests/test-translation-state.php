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


// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$passed = 0;
$failed = 0;


test_prerequisite_hint( 'activate-plugin:conexao-admin-ux' );
test_require( class_exists( 'Conexao_Admin_Ux_Translation_State' ) || ! function_exists( 'pll_save_post_translations' ), 'activate-plugin:conexao-admin-ux', 'test prerequisite is available: class_exists( Conexao_Admin_Ux_Translation_State ) || ! function_exists( pll_save_post_translations )', 'activate the conexao-admin-ux plugin' );
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

$pt = ts_post( 'guide', 'stage32-ts-guide', '[STAGE32-TS] Guia', 'pt' );
assert_true( $pt > 0, 'PT guide fixture created' );

$state = Conexao_Admin_Ux_Translation_State::get_state( $pt );
assert_true( 'missing' === $state['state'], 'untranslated PT record reads missing' );
assert_true( $pt === $state['source_id'] && 0 === $state['translation_id'], 'missing state carries the PT source id and no translation id' );

$en = ts_post( 'guide', 'stage32-ts-guide-en', '[STAGE32-TS] Guide', 'en' );
pll_save_post_translations( array( 'pt' => $pt, 'en' => $en ) );

$state = Conexao_Admin_Ux_Translation_State::get_state( $pt );
assert_true( 'current' === $state['state'] && $en === $state['translation_id'], 'linked EN translation reads current on the PT side' );

$state_en = Conexao_Admin_Ux_Translation_State::get_state( $en );
assert_true( 'current' === $state_en['state'] && $pt === $state_en['source_id'] && $en === $state_en['translation_id'], 'EN record resolves its PT source relationship' );

// Saving the PT source flags the EN translation outdated.
wp_update_post( array( 'ID' => $pt, 'post_content' => 'Conteúdo atualizado.' ) );
assert_true( (bool) get_post_meta( $en, '_translation_outdated', true ), 'saving the PT source flags the EN translation outdated' );
assert_true( 'outdated' === Conexao_Admin_Ux_Translation_State::get_state( $pt )['state'], 'PT side reads outdated' );
assert_true( 'outdated' === Conexao_Admin_Ux_Translation_State::get_state( $en )['state'], 'EN side reads outdated' );
assert_true( '' === (string) get_post_meta( $pt, '_translation_outdated', true ), 'the PT master never carries the flag' );

// Saving the EN translation clears only its own flag.
wp_update_post( array( 'ID' => $en, 'post_content' => 'Updated content.' ) );
assert_true( ! get_post_meta( $en, '_translation_outdated', true ), 'saving the EN translation clears its own flag' );
assert_true( 'current' === Conexao_Admin_Ux_Translation_State::get_state( $pt )['state'], 'state returns to current after the EN save' );

// ---------------------------------------------------------------------------
$revision_id = wp_save_post_revision( $pt );
assert_true( ! get_post_meta( $en, '_translation_outdated', true ), 'saving a PT revision does NOT flag the translation' );

// ---------------------------------------------------------------------------
$standalone = ts_post( 'event', 'stage32-ts-standalone', '[STAGE32-TS] English-native event', 'en' );
$state      = Conexao_Admin_Ux_Translation_State::get_state( $standalone );
assert_true( 'current' === $state['state'] && 0 === $state['source_id'], 'standalone EN record reads current without a PT source' );

// ---------------------------------------------------------------------------
$agency = wp_insert_post( array( 'post_type' => 'recruitment_agency', 'post_title' => '[STAGE32-TS] Agency', 'post_status' => 'publish' ), true );
if ( ! is_wp_error( $agency ) ) {
	$created[] = $agency;
	assert_true( 'not_applicable' === Conexao_Admin_Ux_Translation_State::get_state( $agency )['state'], 'admin-only record type is not applicable' );
}

// ---------------------------------------------------------------------------

$columns = Conexao_Admin_Ux_Translation_State::column( array( 'title' => 'Título' ) );
assert_true( isset( $columns['conexao_translation_state'] ), 'list column registered' );

ob_start();
Conexao_Admin_Ux_Translation_State::render_column( 'conexao_translation_state', $pt );
$cell = (string) ob_get_clean();
assert_true( false !== strpos( $cell, 'Atual' ), 'column shows current state for a current pair' );

wp_update_post( array( 'ID' => $pt, 'post_content' => 'Mudança de conteúdo.' ) );
ob_start();
Conexao_Admin_Ux_Translation_State::render_column( 'conexao_translation_state', $pt );
$cell = (string) ob_get_clean();
assert_true( false !== strpos( $cell, 'Desatualizada' ), 'column shows outdated state after a PT edit' );

$untranslated = ts_post( 'guide', 'stage32-ts-untranslated', '[STAGE32-TS] Sem tradução', 'pt' );
ob_start();
Conexao_Admin_Ux_Translation_State::render_column( 'conexao_translation_state', $untranslated );
$cell = (string) ob_get_clean();
assert_true( false !== strpos( $cell, 'Ausente' ), 'column shows missing state for an untranslated record' );

ob_start();
Conexao_Admin_Ux_Translation_State::render_metabox( get_post( $en ) );
$box = (string) ob_get_clean();
assert_true( false !== strpos( $box, 'Tradução de:' ) && false !== strpos( $box, 'Desatualizada' ), 'EN metabox shows the PT relationship and the state' );

ob_start();
Conexao_Admin_Ux_Translation_State::render_metabox( get_post( $pt ) );
$box = (string) ob_get_clean();
assert_true( false !== strpos( $box, 'Tradução em inglês:' ), 'PT metabox shows the EN state' );

// ---------------------------------------------------------------------------
$registered = get_registered_meta_keys( 'post', 'guide' );
assert_true( ! isset( $registered['_translation_outdated'] ), '_translation_outdated is NOT registered as public/REST meta' );

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
assert_true( array() === $hits, 'no theme/front-end file reads _translation_outdated' );

// ---------------------------------------------------------------------------
foreach ( array_unique( $created ) as $post_id ) {
	wp_delete_post( (int) $post_id, true );
}

test_finish();
