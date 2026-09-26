<?php
/**
 * Tests for the Apoiador multi-contact feature ("Contatos" repeater).
 *
 * Covers:
 *  1. Sanitizer rules per contact type (URLs, WhatsApp formats, e-mail,
 *     unsafe protocols, scheme-less domains).
 *  2. Validation messages for invalid rows.
 *  3. First-save flow through the admin UX save path: create Apoiador,
 *     add 3 contacts in one submission, reload → all 3 still exist.
 *  4. Edit / remove / reorder on a second save.
 *  5. Removing all contacts clears the meta.
 *  6. Backwards compatibility: legacy _sponsor_link-only records keep
 *     working and are never touched by the contacts system.
 *  7. Export carries the contacts collection (format 1.1.0).
 *  8. Import restores contacts; legacy payloads without "contacts"
 *     leave existing contacts untouched.
 *
 * Usage:
 *   docker compose cp scripts/test-sponsor-contacts.php wordpress:/tmp/
 *   docker compose exec wordpress php /tmp/test-sponsor-contacts.php
 *
 * @package Conexao_Admin_Ux
 */

// Allow running via WP-CLI, plain PHP CLI, or browser. The shared bootstrap
// loads WordPress only when it is not already present (WP-CLI), so the
// WP_CLI fast path is preserved exactly.
$is_cli = ( defined( 'WP_CLI' ) && WP_CLI ) || 'cli' === php_sapi_name();

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	require_once __DIR__ . '/lib/bootstrap.php';
	conexao_script_load_wordpress();
}

if ( ! $is_cli && ! current_user_can( 'manage_options' ) ) {
	die( 'Unauthorized access' );
}

$passes = 0;
$failures = array();

/**
 * Assert helper.
 *
 * @param bool   $condition Condition.
 * @param string $message   Test description.
 */
function conexao_contacts_check( $condition, $message ) {
	global $passes, $failures;
	if ( $condition ) {
		$passes++;
		echo "  [PASS] {$message}\n";
	} else {
		$failures[] = $message;
		echo "  [FAIL] {$message}\n";
	}
}

echo "== 1. Sanitizer rules ==\n";

$sanitized = Conexao_Data_Model_Contacts::sanitize_rows( array(
	array( 'type' => 'website',   'url' => 'https://example.com/' ),
	array( 'type' => 'instagram', 'url' => 'instagram.com/foo' ),          // Scheme-less → https:// prefixed.
	array( 'type' => 'facebook',  'url' => 'http://facebook.com/bar' ),    // http preserved.
	array( 'type' => 'whatsapp',  'url' => '+353 87 123 4567' ),           // Bare number → wa.me.
	array( 'type' => 'whatsapp',  'url' => 'https://wa.me/353871234567' ), // Full link kept as-is.
	array( 'type' => 'whatsapp',  'url' => 'https://chat.whatsapp.com/AbC123' ),
	array( 'type' => 'email',     'url' => 'Foo@Example.com' ),            // → mailto: (case preserved, as sanitize_email does).
	array( 'type' => 'tiktok',    'url' => 'javascript:alert(1)' ),        // Unsafe protocol dropped.
	array( 'type' => 'linkedin',  'url' => '' ),                           // Empty dropped.
	array( 'type' => '',          'url' => 'https://example.org/x' ),      // Unknown type → outro.
) );

conexao_contacts_check( 8 === count( $sanitized ), 'Sanitizer keeps exactly the 8 valid rows (got ' . count( $sanitized ) . ')' );
conexao_contacts_check( isset( $sanitized[0] ) && 'https://example.com/' === $sanitized[0]['url'], 'Row 1: https URL preserved verbatim' );
conexao_contacts_check( isset( $sanitized[1] ) && 'https://instagram.com/foo' === $sanitized[1]['url'], 'Row 2: scheme-less domain gets https:// prefix' );
conexao_contacts_check( isset( $sanitized[2] ) && 'http://facebook.com/bar' === $sanitized[2]['url'], 'Row 3: valid http URL preserved' );
conexao_contacts_check( isset( $sanitized[3] ) && 'https://wa.me/353871234567' === $sanitized[3]['url'], 'Row 4: bare WhatsApp number normalized to wa.me' );
conexao_contacts_check( isset( $sanitized[4] ) && 'https://wa.me/353871234567' === $sanitized[4]['url'], 'Row 5: full wa.me link kept unchanged' );
conexao_contacts_check( isset( $sanitized[5] ) && 'https://chat.whatsapp.com/AbC123' === $sanitized[5]['url'], 'Row 6: chat.whatsapp.com invite link accepted' );
conexao_contacts_check( isset( $sanitized[6] ) && 'mailto:Foo@Example.com' === $sanitized[6]['url'] && 'email' === $sanitized[6]['type'], 'Row 7: e-mail stored as mailto:' );
conexao_contacts_check( isset( $sanitized[7] ) && 'outro' === $sanitized[7]['type'], 'Row 8: unknown type coerced to outro' );

echo "\n== 2. Validation messages ==\n";

$errors = Conexao_Data_Model_Contacts::validate_submission( array(
	array( 'type' => 'instagram', 'url' => 'javascript:x' ),
	array( 'type' => 'email',     'url' => 'not-an-email' ),
	array( 'type' => 'website',   'url' => '' ), // Empty rows never error.
) );

conexao_contacts_check( 2 === count( $errors ), 'Two invalid rows produce two errors (got ' . count( $errors ) . ')' );
conexao_contacts_check( ! empty( $errors[0] ) && false !== strpos( $errors[0], 'Instagram' ), 'Error names the row type (Instagram)' );
conexao_contacts_check( ! empty( $errors[1] ) && false !== strpos( $errors[1], 'e-mail' ), 'E-mail error mentions e-mail rule' );

echo "\n== 3. First-save flow (admin UX save path) ==\n";

$sponsor_id = wp_insert_post( array(
	'post_type'    => 'sponsor',
	'post_title'   => 'TESTE Contatos Primeiro Save',
	'post_status'  => 'draft',
	'post_content' => '',
) );
conexao_contacts_check( (bool) $sponsor_id && ! is_wp_error( $sponsor_id ), 'Test sponsor created (#' . (int) $sponsor_id . ')' );

$config = Conexao_Admin_Ux_Config::get( 'sponsor' );
conexao_contacts_check( is_array( $config ), 'Sponsor config loads' );

// Simulate ONE submission of a brand-new Apoiador carrying 3 contacts.
$first_save_data = array(
	'conexao_fields' => array(
		'sponsor_name'      => 'TESTE Contatos Primeiro Save',
		'sponsor_link'      => 'https://site-oficial.example',
		'sponsor_contacts'  => array(
			array( 'type' => 'website',   'url' => 'https://site-oficial.example' ),
			array( 'type' => 'instagram', 'url' => 'https://instagram.com/apoiador' ),
			array( 'type' => 'whatsapp',  'url' => '353871234567' ),
		),
	),
);

Conexao_Admin_Ux_Fields::save( $sponsor_id, $config, $first_save_data );

$stored = get_post_meta( $sponsor_id, '_sponsor_contacts', true );
conexao_contacts_check( is_array( $stored ) && 3 === count( $stored ), 'First save persists all 3 contacts (got ' . ( is_array( $stored ) ? count( $stored ) : 'non-array' ) . ')' );
conexao_contacts_check(
	is_array( $stored )
	&& 'website' === $stored[0]['type'] && 'https://site-oficial.example' === $stored[0]['url']
	&& 'instagram' === $stored[1]['type'] && 'https://instagram.com/apoiador' === $stored[1]['url']
	&& 'whatsapp' === $stored[2]['type'] && 'https://wa.me/353871234567' === $stored[2]['url'],
	'Reload: all 3 contacts present, sanitized and in submission order'
);
conexao_contacts_check( 'https://site-oficial.example' === get_post_meta( $sponsor_id, '_sponsor_link', true ), 'Canonical _sponsor_link saved alongside contacts' );

// Validation on the same payload must be clean.
conexao_contacts_check( array() === Conexao_Admin_Ux_Fields::validate( $config, $first_save_data ), 'Valid first-save payload passes editor validation' );

echo "\n== 4. Edit / remove / reorder (second save) ==\n";

$second_save_data = array(
	'conexao_fields' => array(
		'sponsor_name'     => 'TESTE Contatos Primeiro Save',
		'sponsor_contacts' => array(
			// Instagram edited, website removed, whatsapp moved to front,
			// facebook added at the end.
			array( 'type' => 'whatsapp',  'url' => 'https://api.whatsapp.com/send?phone=353871234567' ),
			array( 'type' => 'instagram', 'url' => 'https://instagram.com/novo-perfil' ),
			array( 'type' => 'facebook',  'url' => 'facebook.com/apoiador' ),
		),
	),
);

Conexao_Admin_Ux_Fields::save( $sponsor_id, $config, $second_save_data );

$updated = Conexao_Data_Model_Contacts::get( $sponsor_id );
conexao_contacts_check( 3 === count( $updated ), 'Second save keeps 3 rows after edit/remove/add (got ' . count( $updated ) . ')' );
conexao_contacts_check(
	isset( $updated[0] ) && 'whatsapp' === $updated[0]['type'] && 'https://api.whatsapp.com/send?phone=353871234567' === $updated[0]['url'],
	'Reorder: whatsapp row is first with its full-format link preserved'
);
conexao_contacts_check( isset( $updated[1] ) && 'https://instagram.com/novo-perfil' === $updated[1]['url'], 'Edit: instagram URL updated' );
conexao_contacts_check( isset( $updated[2] ) && 'facebook' === $updated[2]['type'] && 'https://facebook.com/apoiador' === $updated[2]['url'], 'Add: scheme-less facebook row appended as https' );

echo "\n== 5. Clearing all contacts ==\n";

Conexao_Admin_Ux_Fields::save( $sponsor_id, $config, array(
	'conexao_fields' => array(
		'sponsor_name'     => 'TESTE Contatos Primeiro Save',
		'sponsor_contacts' => array(),
	),
) );

conexao_contacts_check( '' === get_post_meta( $sponsor_id, '_sponsor_contacts', true ), 'Empty repeater deletes the meta entirely' );

echo "\n== 6. Backwards compatibility ==\n";

update_post_meta( $sponsor_id, '_sponsor_link', 'https://legado.example' );
conexao_contacts_check( array() === Conexao_Data_Model_Contacts::get( $sponsor_id ), 'Legacy record without contacts returns empty list' );

Conexao_Admin_Ux_Fields::save( $sponsor_id, $config, array(
	'conexao_fields' => array(
		'sponsor_name'     => 'TESTE Contatos Primeiro Save',
		'sponsor_link'     => 'https://legado.example',
		'sponsor_contacts' => array(
			array( 'type' => 'linkedin', 'url' => 'https://linkedin.com/company/exemplo' ),
		),
	),
) );

conexao_contacts_check( 'https://legado.example' === get_post_meta( $sponsor_id, '_sponsor_link', true ), '_sponsor_link untouched by contacts saves' );
conexao_contacts_check( 1 === count( Conexao_Data_Model_Contacts::get( $sponsor_id ) ), 'New contact coexists with legacy link' );

echo "\n== 7. Export carries contacts ==\n";

// The migration plugin may be inactive in the local environment under
// test; its classes are plain definitions, so requiring them directly
// keeps the suite hermetic without changing plugin activation state.
if ( ! class_exists( 'Conexao_Sponsor_Exporter' ) ) {
	require_once WP_PLUGIN_DIR . '/conexao-sponsor-migration/includes/class-sponsor-exporter.php';
}
if ( ! class_exists( 'Conexao_Sponsor_Importer' ) ) {
	require_once WP_PLUGIN_DIR . '/conexao-sponsor-migration/includes/class-sponsor-importer.php';
}

$exporter = new Conexao_Sponsor_Exporter();
$payload  = $exporter->build_export();

conexao_contacts_check( '1.1.0' === $payload['manifest']['version'], 'Export manifest version bumped to 1.1.0' );

$exported_entry = null;
foreach ( $payload['sponsors'] as $entry ) {
	if ( 'TESTE Contatos Primeiro Save' === $entry['post']['title'] ) {
		$exported_entry = $entry;
		break;
	}
}
conexao_contacts_check( null !== $exported_entry, 'Test sponsor found in export' );
conexao_contacts_check(
	null !== $exported_entry && isset( $exported_entry['contacts'] ) && 1 === count( $exported_entry['contacts'] )
	&& 'linkedin' === $exported_entry['contacts'][0]['type'],
	'Export includes the structured contacts list'
);

echo "\n== 8. Import restores contacts ==\n";

$import_uuid = 'test-uuid-contacts-0000-000000000001';
$import_payload = array(
	'manifest' => array(
		'format'        => Conexao_Sponsor_Exporter::FORMAT,
		'version'       => '1.1.0',
		'exported_at'   => current_time( 'c' ),
		'source_url'    => 'https://example.org',
		'sponsor_count' => 1,
	),
	'sponsors' => array(
		array(
			'uuid'       => $import_uuid,
			'post'       => array(
				'title'    => 'TESTE Import Contatos',
				'content'  => '',
				'excerpt'  => '',
				'status'   => 'draft',
				'slug'     => 'teste-import-contatos',
				'date'     => current_time( 'mysql' ),
				'modified' => current_time( 'mysql' ),
			),
			'meta'       => array( '_sponsor_link' => 'https://importado.example' ),
			'taxonomies' => array( 'conexao_category' => array(), 'conexao_county' => array() ),
			'contacts'   => array(
				array( 'type' => 'tiktok',   'url' => 'https://tiktok.com/@exemplo' ),
				array( 'type' => 'email',    'url' => 'contato@exemplo.com' ),
				array( 'type' => 'outro',    'url' => 'https://exemplo.com/link' ),
			),
			'images'     => array(
				'desktop'     => array( 'id' => '', 'url' => '', 'alt' => '', 'filename' => '', 'mime_type' => '', 'data_base64' => '' ),
				'mobile'      => array( 'id' => '', 'url' => '', 'alt' => '', 'filename' => '', 'mime_type' => '', 'data_base64' => '' ),
				'legacy_logo' => array( 'id' => '', 'url' => '', 'alt' => '', 'filename' => '', 'mime_type' => '', 'data_base64' => '' ),
			),
		),
	),
);

$tmp_file = tempnam( sys_get_temp_dir(), 'conexao-contacts-test-' ) . '.json';
file_put_contents( $tmp_file, wp_json_encode( $import_payload ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

$importer = new Conexao_Sponsor_Importer();
$stats    = $importer->import_file( $tmp_file, array( 'duplicate_strategy' => 'update' ) );

conexao_contacts_check( 1 === $stats['imported'] && empty( $stats['errors'] ), 'Import created the sponsor without errors (' . implode( '; ', $stats['errors'] ) . ')' );

$imported = get_posts( array(
	'post_type'      => 'sponsor',
	'post_status'    => 'any',
	'title'          => 'TESTE Import Contatos',
	'posts_per_page' => 1,
	'fields'         => 'all',
) );
conexao_contacts_check( ! empty( $imported ), 'Imported sponsor found by title' );

if ( ! empty( $imported ) ) {
	$imported_id   = $imported[0]->ID;
	$imported_rows = Conexao_Data_Model_Contacts::get( $imported_id );

	conexao_contacts_check( 3 === count( $imported_rows ), 'Import restored all 3 contacts (got ' . count( $imported_rows ) . ')' );
	conexao_contacts_check(
		isset( $imported_rows[1] ) && 'mailto:contato@exemplo.com' === $imported_rows[1]['url'],
		'Import sanitizes e-mail rows identically to the editor'
	);
	conexao_contacts_check( 'https://importado.example' === get_post_meta( $imported_id, '_sponsor_link', true ), 'Imported canonical link intact' );

	// Legacy payload WITHOUT the "contacts" key must leave existing contacts untouched.
	$legacy_payload = $import_payload;
	unset( $legacy_payload['sponsors'][0]['contacts'] );
	file_put_contents( $tmp_file, wp_json_encode( $legacy_payload ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

	$legacy_stats = $importer->import_file( $tmp_file, array( 'duplicate_strategy' => 'update' ) );
	conexao_contacts_check( 1 === $legacy_stats['updated'], 'Legacy payload matched and updated the same sponsor' );
	conexao_contacts_check( 3 === count( Conexao_Data_Model_Contacts::get( $imported_id ) ), 'Legacy re-import did NOT destroy existing contacts' );

	wp_delete_post( $imported_id, true );
}

unlink( $tmp_file );

// Cleanup test posts.
wp_delete_post( $sponsor_id, true );

echo "\n==============================\n";
echo "Results: {$passes} passed, " . count( $failures ) . " failed\n";
if ( ! empty( $failures ) ) {
	echo "Failures:\n";
	foreach ( $failures as $failure ) {
		echo " - {$failure}\n";
	}
	exit( 1 );
}
echo "All sponsor contacts tests passed.\n";