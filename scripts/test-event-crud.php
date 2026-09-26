<?php
/**
 * Test the full Event CRUD workflow through the Admin UX actions.
 *
 * Verifies: Create → Draft → Edit → Publish → Update → Duplicate → Archive → Delete
 *
 * Usage: docker compose exec wordpress php /var/www/html/scripts/test-event-crud.php
 */

require_once __DIR__ . '/lib/bootstrap.php';
conexao_script_load_wordpress();
require_once __DIR__ . '/lib/bootstrap.php';
conexao_script_load_wordpress();echo "=== Event CRUD Workflow Test ===\n\n";

// Set a current user so current_user_can() works from CLI.
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( empty( $admin ) ) {
	// Create an admin if none exists.
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

// 1. CREATE (as draft)
$post_id = wp_insert_post(
	array(
		'post_type'    => 'event',
		'post_status'  => 'draft',
		'post_title'   => 'Teste CRUD Evento',
		'post_content' => '<p>Conteúdo de teste para o workflow CRUD.</p>',
	)
);
check( 'Create event (draft)', is_numeric( $post_id ) && $post_id > 0 );

// 2. Set status to draft via Admin UX
Conexao_Admin_Ux_Actions::set_status( $post_id, 'event', 'draft' );
$status = Conexao_Admin_Ux_Actions::get_status( $post_id, 'event' );
check( 'Status is draft', 'draft' === $status );

// 3. Save meta fields
update_post_meta( $post_id, '_event_date', '2026-08-20' );
update_post_meta( $post_id, '_event_start_time', '10:00' );
update_post_meta( $post_id, '_event_end_time', '13:00' );
update_post_meta( $post_id, '_event_venue', 'Portlaoise Community Centre' );
update_post_meta( $post_id, '_event_town', 'Portlaoise' );
update_post_meta( $post_id, '_event_county', 'Laois' );
update_post_meta( $post_id, '_event_organizer', 'Test Organizer' );
update_post_meta( $post_id, '_event_price', 'Grátis' );
update_post_meta( $post_id, '_event_url', 'https://example.com/event' );
check( 'Save event meta', '2026-08-20' === get_post_meta( $post_id, '_event_date', true ) );

// 4. Sync legacy meta (theme compatibility)
Conexao_Admin_Ux_Fields::sync_legacy_event_meta(
	$post_id,
	array(
		'conexao_fields' => array(
			'event_start_time' => '10:00',
			'event_end_time'   => '13:00',
			'event_venue'      => 'Portlaoise Community Centre',
			'event_town'       => 'Portlaoise',
			'event_county'     => 'Laois',
		),
	)
);
$legacy_time = get_post_meta( $post_id, '_event_time', true );
$legacy_loc  = get_post_meta( $post_id, '_event_location', true );
check( 'Legacy _event_time synced', '10:00 — 13:00' === $legacy_time );
check( 'Legacy _event_location synced', 'Portlaoise Community Centre' === $legacy_loc );

// 5. County taxonomy synced
$county_terms = wp_get_object_terms( $post_id, 'conexao_county', array( 'fields' => 'names' ) );
check( 'County taxonomy synced', ! is_wp_error( $county_terms ) && in_array( 'Laois', $county_terms, true ) );

// 6. Town taxonomy synced
$town_terms = wp_get_object_terms( $post_id, 'conexao_town', array( 'fields' => 'names' ) );
check( 'Town taxonomy synced', ! is_wp_error( $town_terms ) && in_array( 'Portlaoise', $town_terms, true ) );

// 7. PUBLISH
Conexao_Admin_Ux_Actions::set_status( $post_id, 'event', 'published' );
$status = Conexao_Admin_Ux_Actions::get_status( $post_id, 'event' );
$post   = get_post( $post_id );
check( 'Publish event', 'published' === $status && 'publish' === $post->post_status );

// 8. UPDATE (edit + save)
wp_update_post(
	array(
		'ID'         => $post_id,
		'post_title' => 'Teste CRUD Evento (Atualizado)',
	)
);
update_post_meta( $post_id, '_event_price', '€10' );
$post = get_post( $post_id );
check( 'Update event title', 'Teste CRUD Evento (Atualizado)' === $post->post_title );
check( 'Update event meta', '€10' === get_post_meta( $post_id, '_event_price', true ) );

// 9. DUPLICATE
$dup_id = Conexao_Admin_Ux_Actions::duplicate( $post_id, 'event' );
check( 'Duplicate event', is_numeric( $dup_id ) && $dup_id > 0 && $dup_id !== $post_id );

if ( is_numeric( $dup_id ) ) {
	$dup_post = get_post( $dup_id );
	check( 'Duplicate is draft', 'draft' === $dup_post->post_status );
	check( 'Duplicate has (Cópia) suffix', false !== strpos( $dup_post->post_title, '(Cópia)' ) );
	check( 'Duplicate copied content', $dup_post->post_content === $post->post_content );
	check( 'Duplicate copied date', '2026-08-20' === get_post_meta( $dup_id, '_event_date', true ) );
	check( 'Duplicate cleared source ID', '' === get_post_meta( $dup_id, '_event_source_id', true ) );
	check( 'Duplicate status is draft', 'draft' === Conexao_Admin_Ux_Actions::get_status( $dup_id, 'event' ) );
}

// 10. ARCHIVE
Conexao_Admin_Ux_Actions::archive( $post_id, 'event' );
$status = Conexao_Admin_Ux_Actions::get_status( $post_id, 'event' );
$post   = get_post( $post_id );
check( 'Archive event', 'archived' === $status && 'draft' === $post->post_status );

// 11. DELETE
$deleted = Conexao_Admin_Ux_Actions::delete( $post_id, 'event' );
check( 'Delete event', $deleted && null === get_post( $post_id ) );

// Clean up duplicate
if ( is_numeric( $dup_id ) ) {
	wp_delete_post( $dup_id, true );
}

echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );