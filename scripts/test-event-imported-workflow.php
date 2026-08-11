<?php
/**
 * Test the imported Event workflow with the Admin UX.
 *
 * Verifies: Import → Review → Edit → Publish → Update → Archive
 *
 * Usage: docker compose exec wordpress php /var/www/html/scripts/test-event-imported-workflow.php
 */

$wp_load = '/var/www/html/wp-load.php';
if ( ! file_exists( $wp_load ) ) {
	fwrite( STDERR, "wp-load.php not found. Run inside the WordPress container.\n" );
	exit( 1 );
}

require_once $wp_load;

echo "=== Imported Event Workflow Test ===\n\n";

// Set a current user so current_user_can() works from CLI.
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( empty( $admin ) ) {
	$admin_id = wp_create_user( 'testadmin', 'testpass123', 'testadmin@example.com' );
	$user     = new WP_User( $admin_id );
	$user->set_role( 'administrator' );
	wp_set_current_user( $admin_id );
} else {
	wp_set_current_user( $admin[0]->ID );
}
echo "Current user: " . wp_get_current_user()->user_login . "\n\n";

$pass = 0;
$fail = 0;

function check( $label, $condition ) {
	global $pass, $fail;
	if ( $condition ) {
		$pass++;
		echo "  ✓ {$label}\n";
	} else {
		$fail++;
		echo "  ✗ {$label}\n";
	}
}

// 1. SIMULATE IMPORT: create an event as the importer would
// (with source, source_id, imported flag, needs_review)
$post_id = wp_insert_post(
	array(
		'post_type'    => 'event',
		'post_status'  => 'publish', // Importer creates as publish but with _event_status meta
		'post_title'   => 'Importado: Festival de Verão em Portlaoise',
		'post_content' => '<p>Evento importado da fonte para teste.</p>',
	)
);
check( 'Import creates event', is_numeric( $post_id ) && $post_id > 0 );

// Import metadata (mimics the importer's save_event_meta)
update_post_meta( $post_id, '_event_date', '2026-09-01' );
update_post_meta( $post_id, '_event_start_time', '12:00' );
update_post_meta( $post_id, '_event_end_time', '18:00' );
update_post_meta( $post_id, '_event_time', '12:00 — 18:00' );
update_post_meta( $post_id, '_event_location', 'Portlaoise Town Square' );
update_post_meta( $post_id, '_event_venue', 'Portlaoise Town Square' );
update_post_meta( $post_id, '_event_town', 'Portlaoise' );
update_post_meta( $post_id, '_event_county', 'Laois' );
update_post_meta( $post_id, '_event_source', 'laois_tourism' );
update_post_meta( $post_id, '_event_source_id', 'imported-event-001' );
update_post_meta( $post_id, '_event_source_url', 'https://laoistourism.ie/events/imported-event-001' );
update_post_meta( $post_id, '_event_url', 'https://laoistourism.ie/events/imported-event-001' );
update_post_meta( $post_id, '_event_imported', '1' );
update_post_meta( $post_id, '_event_import_date', current_time( 'mysql' ) );
update_post_meta( $post_id, '_event_last_checked', current_time( 'mysql' ) );
echo "\n";

// 2. IMPORTED → NEEDS REVIEW
// Importer checks normalizer: if location couldn't be determined → needs_review
Conexao_Event_Status::set_status( $post_id, Conexao_Event_Status::NEEDS_REVIEW );
update_post_meta( $post_id, '_event_review_note', 'Localização não identificada' );
$status = Conexao_Admin_Ux_Actions::get_status( $post_id, 'event' );
check( 'Imported event is needs_review', Conexao_Event_Status::NEEDS_REVIEW === $status );

// 3. Review note visible
$note = get_post_meta( $post_id, '_event_review_note', true );
check( 'Review note saved', 'Localização não identificada' === $note );

// 4. Source data preserved
$source = get_post_meta( $post_id, '_event_source', true );
check( 'Source preserved', 'laois_tourism' === $source );

// 5. Import date preserved
$import_date = get_post_meta( $post_id, '_event_import_date', true );
check( 'Import date preserved', ! empty( $import_date ) );

// 6. EDIT (admin fixes location)
update_post_meta( $post_id, '_event_venue', 'Portlaoise Town Square (Corrigido)' );
delete_post_meta( $post_id, '_event_review_note' );

// 7. PUBLISH (admin reviews and approves)
Conexao_Admin_Ux_Actions::set_status( $post_id, 'event', 'published' );
$status = Conexao_Admin_Ux_Actions::get_status( $post_id, 'event' );
$post   = get_post( $post_id );
check( 'Publish imported event', 'published' === $status && 'publish' === $post->post_status );
check( 'Review note cleared on publish', '' === get_post_meta( $post_id, '_event_review_note', true ) );

// 8. Check importer status reader is consistent
$importer_status = Conexao_Event_Status::get_status( $post_id );
check( 'Importer status consistent', 'published' === $importer_status );

// 9. UPDATE (edit after publish)
wp_update_post(
	array(
		'ID'         => $post_id,
		'post_title' => 'Importado: Festival de Verão em Portlaoise (Atualizado)',
	)
);
update_post_meta( $post_id, '_event_price', '€5' );
$post = get_post( $post_id );
check( 'Update imported event', 'Importado: Festival de Verão em Portlaoise (Atualizado)' === $post->post_title );
check( 'Update imported meta', '€5' === get_post_meta( $post_id, '_event_price', true ) );

// 10. DUPLICATE imported event clears external ID
$dup_id = Conexao_Admin_Ux_Actions::duplicate( $post_id, 'event' );
check( 'Duplicate imported event', is_numeric( $dup_id ) );
if ( is_numeric( $dup_id ) ) {
	check( 'Duplicate clears source ID', '' === get_post_meta( $dup_id, '_event_source_id', true ) );
	check( 'Duplicate is draft', 'draft' === get_post_status( $dup_id ) );
	// Clean up duplicate
	wp_delete_post( $dup_id, true );
}

// 11. SOURCE NOT FOUND (event disappears from source)
Conexao_Event_Status::set_status( $post_id, Conexao_Event_Status::SOURCE_NOT_FOUND );
update_post_meta( $post_id, '_event_review_note', 'Evento não encontrado na fonte na última importação.' );
$status = Conexao_Admin_Ux_Actions::get_status( $post_id, 'event' );
check( 'Source not found status', 'source_not_found' === $status );

// 12. ARCHIVE (admin decides to archive)
Conexao_Admin_Ux_Actions::archive( $post_id, 'event' );
$status = Conexao_Admin_Ux_Actions::get_status( $post_id, 'event' );
$post   = get_post( $post_id );
check( 'Archive imported event', 'archived' === $status && 'draft' === $post->post_status );

// Clean up
wp_delete_post( $post_id, true );

echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );