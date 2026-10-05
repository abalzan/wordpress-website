<?php
/**
 * Regression suite — multi-file importer browser workflow (submit interception).
 *
 * ## The defect this suite locks down
 *
 * The multi-file importer worked on the server but the admin UI did not. The
 * operator selected several export files, clicked "Import Multiple Files", the
 * page reloaded and nothing appeared to happen.
 *
 * The chain was:
 *
 *   click -> browser default form submission (a DOCUMENT-level multipart POST
 *   to admin-post.php) -> 302 -> full page reload -> only THEN did admin.js
 *   read the batch token from the query string and start the per-file requests.
 *
 * `initImportForms()` bound a submit handler to `form[data-conexao-import-form]`
 * — an attribute BOTH import forms carry — and that handler never called
 * `preventDefault()`. The multi-file form therefore had no interception at all,
 * and every user activation produced a document navigation before the import
 * even started.
 *
 * ## What this suite proves
 *
 * Three layers, all without a browser (this suite runs under the in-process PHP
 * layer, so it is a blocking gate in `./scripts/run-tests.sh`):
 *
 *  1. **Rendered markup contract.** The REAL admin page is rendered and parsed
 *     with DOMXPath. The multi-file form is identified the way the client
 *     identifies it (the hidden `action` input), and its file input, submit
 *     control, enctype, nonce and method are asserted against what the fix
 *     depends on. The previous audit's mistake — assuming the action lived in
 *     the form's `action` attribute — is locked out here.
 *  2. **Client interception contract.** `assets/admin.js` is read and its
 *     critical properties asserted: it binds `submit` (not `click`), it calls
 *     `preventDefault()` as the first statement of the multi-file handler, it
 *     scopes strictly to the multi-file form, and it builds one FormData from
 *     the live form.
 *  3. **Server response contract.** `handle_import_multi()` must return JSON for
 *     the AJAX marker and must keep the native redirect for a normal POST, so
 *     the no-JavaScript fallback still works. The nonce and capability checks
 *     must be unchanged, and must run BEFORE the response mode is decided.
 *
 * ## Negative control (anti-vacuity)
 *
 * TEST 4 removes `preventDefault()` from a COPY of the handler source and
 * proves the contract assertions detect it — the exact mutation that reproduces
 * the reported defect. A test that merely checked "a submit handler exists"
 * would keep passing; this one fails.
 *
 * Read-only: renders a page, reads two source files and asserts. Creates no
 * content, opens no socket, writes nothing.
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

require_once ABSPATH . 'wp-admin/includes/template.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

test_prerequisite_hint( 'activate-plugin:conexao-event-importer' );
test_require(
	class_exists( 'Conexao_Event_Transfer_Admin' ),
	'activate-plugin:conexao-event-importer',
	'test prerequisite is available: class_exists( Conexao_Event_Transfer_Admin )',
	'activate the conexao-event-importer plugin'
);

echo "\n== Multi-file importer browser workflow (submit interception) ==\n";

$plugin_dir = WP_PLUGIN_DIR . '/conexao-event-importer';
$js_path    = $plugin_dir . '/assets/admin.js';
$admin_php  = $plugin_dir . '/includes/class-event-transfer-admin.php';

test_require( is_readable( $js_path ), 'file-exists:admin.js', 'assets/admin.js is readable', 'restore wp-content/plugins/conexao-event-importer/assets/admin.js' );
test_require( is_readable( $admin_php ), 'file-exists:class-event-transfer-admin.php', 'class-event-transfer-admin.php is readable', 'restore the plugin source' );

$js_source    = (string) file_get_contents( $js_path );
$admin_source = (string) file_get_contents( $admin_php );

/**
 * Extract the body of a named JavaScript function from source.
 *
 * A brace-matching scan, so the assertions run against the REAL function body
 * rather than a hand-copied expectation. Returns an empty string when the
 * function cannot be found, which makes every downstream assertion fail rather
 * than pass vacuously.
 *
 * @param string $source JavaScript source.
 * @param string $name   Function name.
 * @return string
 */
function mfi_extract_function( $source, $name ) {
	$needle = 'function ' . $name . '(';
	$start  = strpos( $source, $needle );
	if ( false === $start ) {
		return '';
	}
	$brace = strpos( $source, '{', $start );
	if ( false === $brace ) {
		return '';
	}
	$depth = 0;
	$len   = strlen( $source );
	for ( $i = $brace; $i < $len; $i++ ) {
		if ( '{' === $source[ $i ] ) {
			$depth++;
		} elseif ( '}' === $source[ $i ] ) {
			$depth--;
			if ( 0 === $depth ) {
				return substr( $source, $brace, $i - $brace + 1 );
			}
		}
	}
	return '';
}

/**
 * Strip JavaScript comments from source.
 *
 * The contract must be satisfied by CODE. A comment that merely mentions
 * `preventDefault()` is not an interception, so every check runs against the
 * comment-free source. Without this the negative control would pass vacuously
 * (the mutation removes the call but leaves the comment naming it).
 *
 * @param string $source JavaScript source.
 * @return string
 */
function mfi_strip_comments( $source ) {
	$source = preg_replace( '#/\*.*?\*/#s', '', (string) $source );
	return preg_replace( '#//[^\n]*#', '', (string) $source );
}

/**
 * Assert the interception contract over a handler source string.
 *
 * Shared by TEST 2 (the real handler) and TEST 4 (the mutated copy), so the
 * negative control exercises exactly the same predicate the real assertion
 * uses. Returns a list of violations.
 *
 * @param string $handler Handler function body.
 * @return array
 */
function mfi_interception_violations( $handler ) {
	$violations = array();

	if ( '' === $handler ) {
		return array( 'handler body could not be extracted' );
	}

	$code = mfi_strip_comments( $handler );

	// The first statement must be the interception itself: opening brace,
	// whitespace, then `<receiver>.preventDefault();`. Any early return or
	// exception before it would let the browser fall through to a native
	// submission and reproduce the defect.
	if ( 1 !== preg_match( '/\{\s*[A-Za-z_$][\w$]*\.preventDefault\(\)\s*;/', $code, $m, PREG_OFFSET_CAPTURE ) ) {
		if ( false === strpos( $code, 'preventDefault()' ) ) {
			$violations[] = 'preventDefault() is never called';
		} else {
			$violations[] = 'preventDefault() is not the first executable statement';
		}
		return $violations;
	}

	if ( false === strpos( $code, 'FormData(' ) ) {
		$violations[] = 'no FormData is built from the form';
	}
	if ( false === strpos( $code, 'conexao_multi_response' ) ) {
		$violations[] = 'the AJAX response marker is not sent';
	}

	return $violations;
}

// ---------------------------------------------------------------------------
echo "\n=== TEST 1 — the rendered multi-file form is what the client expects ===\n";

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
test_require( ! empty( $admins ), 'fixture:administrator', 'an administrator account exists for rendering', 'create an administrator in the local install' );

wp_set_current_user( (int) $admins[0]->ID );
$transfer = new Conexao_Event_Transfer_Admin();
ob_start();
$transfer->render_import_page();
$html = ob_get_clean();
wp_set_current_user( 0 );

libxml_use_internal_errors( true );
$dom = new DOMDocument();
$dom->loadHTML( '<?xml encoding="utf-8">' . $html );

$xp      = new DOMXPath( $dom );
$actions = $xp->query( '//form//input[@name="action" and @value="conexao_import_events_multi"]' );

assert_true( 1 === $actions->length, 'rendered page has exactly one multi-file action input' );
assert_true(
	0 === $xp->query( '//form[@action and contains(@action, "conexao_import_events_multi")]' )->length,
	'the action is a hidden INPUT, never a form action attribute (the previous selector assumption)'
);

$form = $actions->item( 0 )->parentNode;

assert_true( 'multipart/form-data' === $form->getAttribute( 'enctype' ), 'the multi-file form is multipart/form-data' );
assert_true( 'post' === strtolower( $form->getAttribute( 'method' ) ), 'the multi-file form method is post' );
assert_true( false !== strpos( $form->getAttribute( 'action' ), 'admin-post.php' ), 'the multi-file form posts to admin-post.php' );
assert_true( $form->hasAttribute( 'data-conexao-import-form' ), 'the multi-file form carries data-conexao-import-form (the attribute the shared handler also matches)' );

$file_inputs = $xp->query( './/input[@type="file"]', $form );
assert_true( 1 === $file_inputs->length, 'the multi-file form has exactly one file input' );
$file_input = $file_inputs->item( 0 );
assert_true( 'conexao_import_files[]' === $file_input->getAttribute( 'name' ), 'the file input is named conexao_import_files[] (array semantics for multiple files)' );
assert_true( $file_input->hasAttribute( 'multiple' ), 'the file input has the multiple attribute' );

$nonce = $xp->query( './/input[@name="conexao_import_multi_nonce"]', $form );
assert_true( 1 === $nonce->length, 'the multi-file form carries the staging nonce input' );
assert_true( '' !== $nonce->item( 0 )->getAttribute( 'value' ), 'the staging nonce input has a value' );

$buttons = $xp->query( './/input[@type="submit"] | .//button[@type="submit"]', $form );
assert_true( 1 === $buttons->length, 'the multi-file form has exactly one submit control' );
$button = $buttons->item( 0 );
assert_true( 'input' === strtolower( $button->tagName ), 'the submit control is the INPUT element WordPress submit_button() renders' );
assert_true( 'submit' === $button->getAttribute( 'type' ), 'the submit control has type="submit" (submit event, not click)' );

// The client must find the form through the hidden action input. The previous
// audit's selector used the form's action ATTRIBUTE, which never matched.
assert_true(
	false !== strpos( $js_source, 'input[name="action"][value="' ),
	'admin.js discriminates the multi-file form by its hidden action input (not by a form action attribute)'
);

// ---------------------------------------------------------------------------
echo "\n=== TEST 2 — the client intercepts submit and preventDefault()s it ===\n";

$handler = mfi_extract_function( $js_source, 'handleMultiFileSubmit' );
assert_true( '' !== $handler, 'handleMultiFileSubmit() exists in assets/admin.js' );

$violations = mfi_interception_violations( $handler );
foreach ( $violations as $violation ) {
	assert_true( false, 'interception contract: ' . $violation );
}
assert_true( array() === $violations, 'the multi-file handler satisfies the interception contract' );

assert_true(
	false !== strpos( $js_source, "document.addEventListener('submit'" ),
	'admin.js binds the form SUBMIT event (covers Enter-key and keyboard activation)'
);
assert_true(
	false === strpos( $handler, '.submit()' ),
	'the handler never calls form.submit() (no programmatic native submission)'
);
assert_true(
	false !== strpos( $js_source, 'bindMultiFileSubmit' ),
	'the submit binding is registered by the plugin on DOMContentLoaded'
);
assert_true(
	false !== strpos( $js_source, 'isMultiFileForm' ),
	'the delegated listener is scoped by isMultiFileForm (other admin forms are unaffected)'
);
assert_true(
	1 === preg_match( '/^\{\s*(?:(?:\/\/[^\n]*|\/\*.*?\*\/)\s*)*event\.preventDefault\(\);/s', $handler ),
	'preventDefault() is the first executable statement of the handler'
);
assert_true(
	false !== strpos( $js_source, "document.querySelectorAll('form[data-conexao-import-form]')" ),
	'the shared handler is still bound to the import forms (single-file import preserved)'
);
assert_true(
	1 === preg_match( '/if \(isMultiFileForm\(form\)\) \{ return; \}/', $js_source ),
	'the shared handler explicitly SKIPS the multi-file form (no double handling)'
);

// ---------------------------------------------------------------------------
echo "\n=== TEST 3 — the server answers JSON or redirects, and nothing else ===\n";

assert_true(
	1 === preg_match( '/public function handle_import_multi\(\s*\)\s*\{/', $admin_source ),
	'handle_import_multi() still exists'
);
assert_true(
	false !== strpos( $admin_source, "isset( \$_POST['conexao_multi_response'] )" ),
	'the staging handler switches on the AJAX response marker'
);
assert_true(
	false !== strpos( $admin_source, '"json" === sanitize_key' ) || false !== strpos( $admin_source, "'json' === sanitize_key" ),
	'the AJAX response marker is validated, not merely present'
);
assert_true(
	false !== strpos( $admin_source, 'wp_safe_redirect' ),
	'the native (no-JavaScript) redirect fallback is preserved'
);
assert_true(
	false !== strpos( $admin_source, "check_admin_referer( 'conexao_import_events_multi'" ),
	'the staging nonce check is unchanged by the response-mode switch'
);
assert_true(
	false !== strpos( $admin_source, "current_user_can( 'manage_options' )" ),
	'the capability check is unchanged by the response-mode switch'
);
assert_true(
	false !== strpos( $admin_source, 'protected function emit_multi_json_error' ),
	'staging failures have a JSON error path for the AJAX flow'
);

// The response-mode switch must happen AFTER the capability + nonce checks, so
// a client cannot change the security path by choosing a response shape.
$cap_at   = strpos( $admin_source, "current_user_can( 'manage_options' )" );
$nonce_at = strpos( $admin_source, "check_admin_referer( 'conexao_import_events_multi'" );
$json_at  = strpos( $admin_source, "isset( \$_POST['conexao_multi_response'] )" );
assert_true(
	false !== $cap_at && false !== $nonce_at && false !== $json_at && $cap_at < $json_at && $nonce_at < $json_at,
	'the AJAX response mode is decided only after the capability and nonce checks'
);

// ---------------------------------------------------------------------------
echo "\n=== TEST 4 — NEGATIVE CONTROL: the suite detects a removed preventDefault() ===\n";

$mutant = str_replace( "\t\tevent.preventDefault();", '', $handler );
$mutant = str_replace( 'event.preventDefault();', '', $mutant );

assert_true( $mutant !== $handler, 'the mutation control actually changed the handler source' );

$mutant_violations = mfi_interception_violations( $mutant );
assert_true( ! empty( $mutant_violations ), 'the suite DETECTS a handler that never calls preventDefault()' );

$found_missing = false;
foreach ( $mutant_violations as $violation ) {
	if ( false !== strpos( $violation, 'never called' ) ) {
		$found_missing = true;
	}
}
assert_true( $found_missing, 'the detected defect names the real cause: preventDefault() is never called' );

// A second mutation: a handler whose preventDefault() runs only AFTER an early
// return (e.g. a "busy" guard placed first) is exactly as broken, because the
// guard path falls through to native submission.
$late = str_replace(
	"\t\tevent.preventDefault();\n\n\t\tif (isMultiFileBusy(form)) { return; }",
	"\t\tif (isMultiFileBusy(form)) { return; }\n\n\t\tevent.preventDefault();",
	$handler
);
assert_true( $late !== $handler, 'the second mutation control changed the handler source' );
assert_true(
	in_array( 'preventDefault() is not the first executable statement', mfi_interception_violations( $late ), true ),
	'the suite DETECTS a preventDefault() that runs after an early return'
);

echo "\nmulti-file-import-ui suite complete\n";

test_finish( 'multi-file-import-ui' );

