<?php
/**
 * Admin UX — editor save-flow regression suite (QA pass).
 *
 * Simulates the real wp-admin save flow: $_POST is populated as the classic
 * post.php form would submit it, then wp_update_post() is called (mirroring
 * core's edit_post()) so pre_post_update + save_post_{type} fire exactly
 * like production. Covered areas:
 *
 *   0. Empty-content guard bypass (first-save root cause)
 *   1. Guia first save
 *   2. Guia update (preview-vs-update / stale-virtual-field bug)
 *   3. Apoiador first save (contacts repeater + image + thumbnail)
 *   4. Apoiador second update
 *   5. Recursion guard (self::$saving)
 *   6. Nonce + capability gates
 *   7. Validation feedback (no silent data loss)
 *   8. Meta fields: event / job / leisure / course_provider
 *   9. Autosave must not persist or overwrite
 *
 * Usage: docker compose exec wordpress php /tmp/test-admin-ux-save-regression.php
 */

define( 'WP_ADMIN', true );

require_once __DIR__ . '/lib/bootstrap.php';
conexao_script_load_wordpress();

// Admin UX components boot on admin_init, which never fires outside a real
// wp-admin request — boot them explicitly so save_post_{type} hooks register
// exactly as they do in wp-admin.
Conexao_Admin_Ux::instance()->init_components();

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admin[0]->ID );
printf( "Current user: %s\n\n", wp_get_current_user()->user_login );

$pass = 0;
$fail = 0;
$created_posts = array();
$attachment_id = 0;

function check( $label, $condition, $detail = '' ) {
	global $pass, $fail;
	if ( $condition ) {
		$pass++;
		echo "  OK  {$label}\n";
	} else {
		$fail++;
		echo "  FAIL  {$label}" . ( $detail ? " — {$detail}" : '' ) . "\n";
	}
}

function section( $title ) {
	echo "\n=== {$title} ===\n";
}

/**
 * In production each save is a separate HTTP request, so the editor's static
 * per-request state (pre-save snapshot + recursion guard) starts empty. The
 * CLI harness emulates a fresh request by resetting that state.
 */
function reset_editor_request_state() {
	$ref = new ReflectionClass( 'Conexao_Admin_Ux_Editor' );
	foreach ( array( 'pre_update', 'saving' ) as $prop ) {
		if ( $ref->hasProperty( $prop ) ) {
			$p = $ref->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( null, array() );
		}
	}
}

function editor_nonce() {
	return wp_create_nonce( 'conexao_admin_ux_save' );
}

function reset_post() {
	$_POST = array( 'action' => 'editpost' );
}

/**
 * Simulate the classic post.php flow: core's edit_post() runs wp_update_post()
 * first (firing pre_post_update + save_post_{type}); the Admin UX editor save
 * handler runs inside that hook with the same $_POST the form submitted.
 */
function submit_like_admin_form( $post_id, $core_overrides = array() ) {
	return wp_update_post( array_merge( array( 'ID' => $post_id ), $core_overrides ) );
}

function fresh_auto_draft( $post_type ) {
	global $created_posts;
	// Mirrors core's get_default_post_to_edit(): the title is non-empty
	// ('Auto Draft') so the insert passes the empty-content guard exactly
	// like a real wp-admin "Add New" request.
	$id = wp_insert_post(
		array(
			'post_type'    => $post_type,
			'post_status'  => 'auto-draft',
			'post_title'   => 'Auto Draft',
			'post_content' => '',
		)
	);
	if ( is_numeric( $id ) ) {
		$created_posts[] = (int) $id;
	}
	return (int) $id;
}

// A real uploaded image is required: set_post_thumbnail() refuses
// attachments that fail wp_attachment_is_image() (no file metadata).
$upload = wp_upload_bits(
	'qa-regression-image.png',
	null,
	base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' )
);
$attachment_id = wp_insert_attachment(
	array(
		'post_mime_type' => 'image/png',
		'post_title'     => 'QA regression image',
		'post_status'    => 'inherit',
	),
	$upload['file']
);
require_once ABSPATH . 'wp-admin/includes/image.php';
wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );

section( '0. EMPTY-CONTENT GUARD BYPASS' );
$guide = fresh_auto_draft( 'guide' );
check( 'auto-draft guide created', $guide > 0 );

$guard_postarr = array(
	'ID'           => $guide,
	'post_type'    => 'guide',
	'post_title'   => '',
	'post_content' => '',
	'post_excerpt' => '',
);
reset_post();
$_POST['conexao_admin_ux_nonce'] = editor_nonce();
$result = apply_filters( 'wp_insert_post_empty_content', true, $guard_postarr );
check( 'guard bypassed for editor submission (valid nonce, action=editpost)', false === $result );

reset_post();
$result_no_nonce = apply_filters( 'wp_insert_post_empty_content', true, $guard_postarr );
check( 'guard KEPT without editor nonce', true === $result_no_nonce );

section( '1. GUIA FIRST SAVE (historical: first save loses everything)' );
reset_editor_request_state();
reset_post();
$_POST['conexao_admin_ux_nonce'] = editor_nonce();
$_POST['conexao_fields'] = array(
	'guide_title'       => 'Guia QA — primeiro salvamento',
	'guide_content'     => '<p>Conteúdo QA do primeiro salvamento.</p>',
	'guide_useful_links' => "https://example.com/qa\nhttps://example.com/qa2",
	'guide_source'      => 'QA Suite',
	'guide_featured_image' => '',
	'guide_county'      => '',
	'guide_town'        => '',
	'guide_url'         => '',
);
$_POST['conexao_publish_action'] = 'publish';
// Core form submits NO post_title/content keys on a brand-new post
// (virtual fields only) — exactly the historical data-loss scenario.
// The hidden post_status field submits 'draft' for a new post.
submit_like_admin_form( $guide, array( 'post_status' => 'draft' ) );

$post = get_post( $guide );
check( 'title persisted on FIRST save', 'Guia QA — primeiro salvamento' === $post->post_title, $post->post_title );
check( 'content persisted on FIRST save', false !== strpos( $post->post_content, 'primeiro salvamento' ), $post->post_content );
check( 'meta _guide_useful_links persisted', false !== strpos( (string) get_post_meta( $guide, '_guide_useful_links', true ), 'example.com/qa2' ) );
check( 'meta _guide_source persisted', 'QA Suite' === get_post_meta( $guide, '_guide_source', true ) );
check( 'custom status published', 'published' === Conexao_Admin_Ux_Actions::get_status( $guide, 'guide' ) );
check( 'wp status publish', 'publish' === $post->post_status );

section( '2. GUIA UPDATE (preview-vs-update bug)' );

// 2a. User edits the sectioned editor's virtual content field while core's
// #postdivrich (still in the form, CSS-hidden) submits its STALE page-load
// value. The virtual field must win.
reset_editor_request_state();
reset_post();
$_POST['conexao_admin_ux_nonce'] = editor_nonce();
$_POST['conexao_fields'] = array(
	'guide_title'          => 'Guia QA — primeiro salvamento',
	'guide_content'        => '<p>Conteúdo QA versão 2 — editado no editor estruturado.</p>',
	'guide_useful_links'   => "https://example.com/qa\nhttps://example.com/qa2",
	'guide_source'         => 'QA Suite',
	'guide_featured_image' => '',
	'guide_county'         => '',
	'guide_town'           => '',
	'guide_url'            => '',
);
$_POST['conexao_publish_action'] = 'publish';
submit_like_admin_form( $guide, array( 'post_status' => 'publish', 'post_content' => '<p>Conteúdo QA do primeiro salvamento.</p>' ) );
$post = get_post( $guide );
check( 'sectioned editor edit wins over stale core content field', false !== strpos( $post->post_content, 'versão 2' ), $post->post_content );

// 2b. THE historical "preview works, update reverts" bug: the user edits in
// the core rich editor (core's edit_post() writes it BEFORE save_post fires)
// while the virtual field submits its stale page-load value. The core-saved
// edit must survive — the virtual field must NOT overwrite it.
reset_editor_request_state();
reset_post();
$_POST['conexao_admin_ux_nonce'] = editor_nonce();
$_POST['conexao_fields'] = array(
	'guide_title'          => 'Guia QA — primeiro salvamento',
	'guide_content'        => '<p>Conteúdo QA versão 2 — editado no editor estruturado.</p>',
	'guide_useful_links'   => "https://example.com/qa\nhttps://example.com/qa2",
	'guide_source'         => 'QA Suite',
	'guide_featured_image' => '',
	'guide_county'         => '',
	'guide_town'           => '',
	'guide_url'            => '',
);
$_POST['conexao_publish_action'] = 'publish';
submit_like_admin_form( $guide, array( 'post_status' => 'publish', 'post_content' => '<p>Edição feita no editor principal (preview mostrou isto).</p>' ) );
$post = get_post( $guide );
check( 'core editor edit is NOT reverted by stale virtual field (update == preview)', false !== strpos( $post->post_content, 'editor principal' ), $post->post_content );

// 2c. Second Update still persists new values (recursion guard released,
// snapshot refreshed per request).
reset_editor_request_state();
reset_post();
$_POST['conexao_admin_ux_nonce'] = editor_nonce();
$_POST['conexao_fields'] = array(
	'guide_title'          => 'Guia QA — título atualizado',
	'guide_content'        => '<p>Conteúdo QA versão 3.</p>',
	'guide_useful_links'   => "https://example.com/qa\nhttps://example.com/qa2",
	'guide_source'         => 'QA Suite v3',
	'guide_featured_image' => '',
	'guide_county'         => '',
	'guide_town'           => '',
	'guide_url'            => '',
);
$_POST['conexao_publish_action'] = 'publish';
submit_like_admin_form( $guide, array( 'post_status' => 'publish' ) );
$post = get_post( $guide );
check( 'title updated on second Update', 'Guia QA — título atualizado' === $post->post_title, $post->post_title );
check( 'content updated on second Update', false !== strpos( $post->post_content, 'versão 3' ), $post->post_content );
check( 'meta updated on second Update', 'QA Suite v3' === get_post_meta( $guide, '_guide_source', true ) );
section( '3. APOIADOR FIRST SAVE (historical regression area)' );
$sponsor = fresh_auto_draft( 'sponsor' );
reset_editor_request_state();
reset_post();
$_POST['conexao_admin_ux_nonce'] = editor_nonce();
$_POST['conexao_fields'] = array(
	'sponsor_name'        => 'Apoiador QA',
	'sponsor_category'    => '',
	'sponsor_type'        => '',
	'sponsor_description' => 'Descrição QA do apoiador — primeiro salvamento.',
	'sponsor_image'       => (string) $attachment_id,
	'sponsor_link'        => 'https://example.com/apoiador',
	'sponsor_contacts'    => array(
		array( 'type' => 'instagram', 'url' => 'https://instagram.com/qaapoiador' ),
		array( 'type' => 'email', 'url' => 'qa@example.com' ),
	),
	'sponsor_featured'    => '1',
	'sponsor_display_order' => '7',
);
$_POST['conexao_publish_action'] = 'publish';
submit_like_admin_form( $sponsor, array( 'post_status' => 'draft' ) );

$post = get_post( $sponsor );
check( 'name (title) persisted on FIRST save', 'Apoiador QA' === $post->post_title, $post->post_title );
check( 'description → post_content persisted', false !== strpos( $post->post_content, 'primeiro salvamento' ), $post->post_content );
check( 'description → post_excerpt persisted', false !== strpos( (string) $post->post_excerpt, 'primeiro salvamento' ), $post->post_excerpt );
check( '_sponsor_image persisted as attachment ID', (int) get_post_meta( $sponsor, '_sponsor_image', true ) === $attachment_id );
check( '_sponsor_link persisted', 'https://example.com/apoiador' === get_post_meta( $sponsor, '_sponsor_link', true ) );
$contacts = get_post_meta( $sponsor, '_sponsor_contacts', true );
check( '_sponsor_contacts repeater persisted (2 rows, in order)', is_array( $contacts ) && 2 === count( $contacts ) && 'instagram' === $contacts[0]['type'] && 'email' === $contacts[1]['type'], wp_json_encode( $contacts ) );
check( 'checkbox _sponsor_featured persisted', '1' === (string) get_post_meta( $sponsor, '_sponsor_featured', true ) );
check( 'number _sponsor_display_order persisted', '7' === (string) get_post_meta( $sponsor, '_sponsor_display_order', true ) );
check( 'featured image synced to attachment', (int) get_post_thumbnail_id( $sponsor ) === $attachment_id, (string) get_post_thumbnail_id( $sponsor ) );
check( 'status published', 'published' === Conexao_Admin_Ux_Actions::get_status( $sponsor, 'sponsor' ) );

section( '4. APOIADOR SECOND UPDATE' );
reset_editor_request_state();
reset_post();
$_POST['conexao_admin_ux_nonce'] = editor_nonce();
$_POST['conexao_fields'] = array(
	'sponsor_name'        => 'Apoiador QA',
	'sponsor_category'    => '',
	'sponsor_type'        => '',
	'sponsor_description' => 'Descrição QA atualizada no segundo Update.',
	'sponsor_image'       => (string) $attachment_id,
	'sponsor_link'        => 'https://example.com/apoiador-v2',
	'sponsor_contacts'    => array(
		array( 'type' => 'email', 'url' => 'novo@example.com' ),
		array( 'type' => 'instagram', 'url' => 'https://instagram.com/qaapoiador' ),
		array( 'type' => 'whatsapp', 'url' => 'https://wa.me/353123456789' ),
	),
	'sponsor_featured'    => '',
	'sponsor_display_order' => '9',
);
$_POST['conexao_publish_action'] = 'publish';
submit_like_admin_form( $sponsor, array( 'post_status' => 'publish' ) );

$post = get_post( $sponsor );
check( 'description updated', false !== strpos( $post->post_content, 'segundo Update' ), $post->post_content );
check( '_sponsor_link updated', 'https://example.com/apoiador-v2' === get_post_meta( $sponsor, '_sponsor_link', true ) );
$contacts = get_post_meta( $sponsor, '_sponsor_contacts', true );
check( 'contacts updated (3 rows, reordered)', is_array( $contacts ) && 3 === count( $contacts ) && 'email' === $contacts[0]['type'] && 'whatsapp' === $contacts[2]['type'], wp_json_encode( $contacts ) );
check( 'checkbox cleared on update', empty( get_post_meta( $sponsor, '_sponsor_featured', true ) ) );
check( 'number updated', '9' === (string) get_post_meta( $sponsor, '_sponsor_display_order', true ) );
check( 'featured image kept after update', (int) get_post_thumbnail_id( $sponsor ) === $attachment_id );

section( '5. RECURSION GUARD (self::$saving)' );
reset_editor_request_state();
reset_post();
$_POST['conexao_admin_ux_nonce'] = editor_nonce();
$_POST['conexao_fields'] = array(
	'guide_title'          => 'Guia QA — título atualizado',
	'guide_content'        => '<p>Conteúdo QA versão 3.</p>',
	'guide_useful_links'   => 'https://example.com/qa',
	'guide_source'         => 'QA guard-' . uniqid(),
	'guide_featured_image' => '',
	'guide_county'         => '',
	'guide_town'           => '',
	'guide_url'            => '',
);
$_POST['conexao_publish_action'] = 'publish';
$save_fires = 0;
$meta_writes = 0;
$counter = function () use ( &$save_fires ) {
	$save_fires++;
};
add_action( 'save_post_guide', $counter, 5, 2 );
$meta_counter = function ( $meta_id, $object_id, $meta_key ) use ( &$meta_writes ) {
	if ( '_guide_source' === $meta_key ) {
		$meta_writes++;
	}
};
add_action( 'added_post_meta', $meta_counter, 10, 3 );
add_action( 'updated_post_meta', $meta_counter, 10, 3 );
submit_like_admin_form( $guide, array( 'post_status' => 'publish' ) );
remove_action( 'save_post_guide', $counter, 5 );
remove_action( 'added_post_meta', $meta_counter, 10 );
remove_action( 'updated_post_meta', $meta_counter, 10 );
check( 'save_post_guide fired exactly twice (initial + guarded re-fire), no recursion', 2 === $save_fires, "fires={$save_fires}" );
check( 'field saver ran exactly once (no duplicate saves)', 1 === $meta_writes, "writes={$meta_writes}" );
check( 'final content correct after guarded flow', false !== strpos( get_post( $guide )->post_content, 'versão 3' ) );
section( '6. NONCE + CAPABILITY GATES' );
$editor_guide = new Conexao_Admin_Ux_Editor( 'guide' );
reset_post();
$_POST['conexao_admin_ux_nonce'] = 'invalid-nonce-value';
$_POST['conexao_fields'] = array(
	'guide_title'   => 'HACKED',
	'guide_content' => '<p>HACKED</p>',
);
$editor_guide->save( $guide, get_post( $guide ) );
check( 'invalid nonce: save() bails, title unchanged', 'Guia QA — título atualizado' === get_post( $guide )->post_title );

reset_post();
$_POST['conexao_fields'] = array(
	'guide_title'   => 'HACKED',
	'guide_content' => '<p>HACKED</p>',
);
$editor_guide->save( $guide, get_post( $guide ) );
check( 'missing nonce: save() bails', 'Guia QA — título atualizado' === get_post( $guide )->post_title );

// Non-admin user with a VALID nonce must still be blocked by edit_post.
$sub_id = wp_create_user( 'qa_nocap_user', wp_generate_password( 20 ), 'qa-nocap@example.com' );
$sub    = new WP_User( $sub_id );
$sub->set_role( 'subscriber' );
wp_set_current_user( $sub_id );
reset_post();
$_POST['conexao_admin_ux_nonce'] = wp_create_nonce( 'conexao_admin_ux_save' );
$_POST['conexao_fields'] = array(
	'guide_title'   => 'HACKED',
	'guide_content' => '<p>HACKED</p>',
);
$editor_guide->save( $guide, get_post( $guide ) );
wp_set_current_user( $admin[0]->ID );
check( 'subscriber (valid nonce): save() bails via edit_post cap', 'Guia QA — título atualizado' === get_post( $guide )->post_title );

section( '7. VALIDATION FEEDBACK (no silent data loss)' );
$guide_bad = fresh_auto_draft( 'guide' );
reset_editor_request_state();
reset_post();
$_POST['conexao_admin_ux_nonce'] = editor_nonce();
$_POST['conexao_fields'] = array(
	'guide_title'          => '', // required, intentionally empty
	'guide_content'        => '<p>Conteúdo sem título.</p>',
	'guide_useful_links'   => '',
	'guide_source'         => '',
	'guide_featured_image' => '',
	'guide_county'         => '',
	'guide_town'           => '',
	'guide_url'            => '',
);
$_POST['conexao_publish_action'] = 'publish';
submit_like_admin_form( $guide_bad, array( 'post_status' => 'draft' ) );
$errors = get_option( 'conexao_admin_ux_errors_' . $guide_bad );
check( 'validation errors stored for the admin notice', is_array( $errors ) && ! empty( $errors ), wp_json_encode( $errors ) );
check( 'post NOT published when validation fails', 'draft' === get_post_field( 'post_status', $guide_bad ), get_post_field( 'post_status', $guide_bad ) );
check( 'custom status held at draft', 'draft' === Conexao_Admin_Ux_Actions::get_status( $guide_bad, 'guide' ) );
check( 'content preserved (draft kept, not discarded)', false !== strpos( get_post_field( 'post_content', $guide_bad ), 'sem título' ) );
section( '8. META FIELDS — EVENTO / EMPREGO / LAZER / CURSOS' );

// Evento (virtual title/content + legacy meta sync + taxonomies).
$event = fresh_auto_draft( 'event' );
reset_editor_request_state();
reset_post();
$_POST['conexao_admin_ux_nonce'] = editor_nonce();
$_POST['conexao_fields'] = array(
	'event_title'       => 'Evento QA',
	'event_description' => '<p>Descrição do evento QA.</p>',
	'event_date'        => '2026-08-20',
	'event_start_time'  => '10:00',
	'event_end_time'    => '12:00',
	'event_venue'       => 'QA Hall',
	'event_town'        => 'Portlaoise',
	'event_county'      => 'Laois',
	'event_banner'      => '',
);
$_POST['conexao_publish_action'] = 'publish';
submit_like_admin_form( $event, array( 'post_status' => 'publish' ) );
check( 'event: title persisted', 'Evento QA' === get_post_field( 'post_title', $event ) );
check( 'event: legacy _event_time synced', '10:00 — 12:00' === get_post_meta( $event, '_event_time', true ), (string) get_post_meta( $event, '_event_time', true ) );
check( 'event: legacy _event_location synced (venue wins)', 'QA Hall' === get_post_meta( $event, '_event_location', true ) );
$event_county = wp_get_object_terms( $event, 'conexao_county', array( 'fields' => 'names' ) );
check( 'event: county taxonomy synced', ! is_wp_error( $event_county ) && ! empty( $event_county ) && 'Laois' === $event_county[0], wp_json_encode( $event_county ) );
$event_town = wp_get_object_terms( $event, 'conexao_town', array( 'fields' => 'names' ) );
check( 'event: town taxonomy synced', ! is_wp_error( $event_town ) && ! empty( $event_town ) );

// Emprego.
$job = fresh_auto_draft( 'job' );
reset_editor_request_state();
reset_post();
$_POST['conexao_admin_ux_nonce'] = editor_nonce();
$_POST['conexao_fields'] = array(
	'job_title'           => 'Vaga QA',
	'job_company'         => 'QA Ltd',
	'job_description'     => '<p>Descrição da vaga QA.</p>',
	'job_requirements'    => 'Requisitos QA',
	'job_location'        => 'Dublin',
	'job_salary'          => '€35.000',
	'job_employment_type' => '',
	'job_closing_date'    => '',
	'job_application_url' => 'https://example.com/apply',
	'job_source'          => 'QA',
);
$_POST['conexao_publish_action'] = 'publish';
submit_like_admin_form( $job, array( 'post_status' => 'publish' ) );
check( 'job: title persisted', 'Vaga QA' === get_post_field( 'post_title', $job ) );
check( 'job: company persisted', 'QA Ltd' === get_post_meta( $job, '_job_company', true ) );
check( 'job: location persisted', 'Dublin' === get_post_meta( $job, '_job_location', true ) );
check( 'job: salary persisted', '€35.000' === get_post_meta( $job, '_job_salary', true ) );
check( 'job: url sanitized + persisted', 'https://example.com/apply' === get_post_meta( $job, '_job_application_url', true ) );
// Lazer (county meta → shared taxonomy sync).
$leisure = fresh_auto_draft( 'leisure' );
reset_editor_request_state();
reset_post();
$_POST['conexao_admin_ux_nonce'] = editor_nonce();
$_POST['conexao_fields'] = array(
	'leisure_name'                => 'Local QA',
	'leisure_short_description'   => 'Resumo QA',
	'leisure_description'         => '<p>Descrição completa QA.</p>',
	'leisure_image_attachment_id' => '',
	'leisure_county'              => 'Clare',
	'leisure_town'                => '',
	'leisure_address'             => '',
	'leisure_official_website'    => '',
	'leisure_discover_ireland'    => '',
	'leisure_map_url'             => '',
	'leisure_category'            => '',
);
$_POST['conexao_publish_action'] = 'publish';
submit_like_admin_form( $leisure, array( 'post_status' => 'publish' ) );
check( 'leisure: name persisted', 'Local QA' === get_post_field( 'post_title', $leisure ) );
check( 'leisure: county meta persisted', 'Clare' === get_post_meta( $leisure, '_leisure_county', true ) );
$leisure_county = wp_get_object_terms( $leisure, 'conexao_county', array( 'fields' => 'names' ) );
check( 'leisure: county taxonomy synced for /lazer/ filter', ! is_wp_error( $leisure_county ) && ! empty( $leisure_county ) && 'Clare' === $leisure_county[0] );

// Cursos (course provider).
$provider = fresh_auto_draft( 'course_provider' );
reset_editor_request_state();
reset_post();
$_POST['conexao_admin_ux_nonce'] = editor_nonce();
$_POST['conexao_fields'] = array(
	'provider_name'        => 'Escola QA',
	'provider_description' => 'Descrição da escola QA.',
	'provider_logo'        => '',
);
$_POST['conexao_publish_action'] = 'publish';
submit_like_admin_form( $provider, array( 'post_status' => 'publish' ) );
check( 'provider: name persisted', 'Escola QA' === get_post_field( 'post_title', $provider ) );
check( 'provider: description → excerpt persisted', false !== strpos( (string) get_post_field( 'post_excerpt', $provider ), 'escola QA' ) );
section( '9. AUTOSAVE MUST NOT PERSIST OR OVERWRITE' );
define( 'DOING_AUTOSAVE', true );
reset_editor_request_state();
reset_post();
$_POST['conexao_admin_ux_nonce'] = editor_nonce();
$_POST['conexao_fields'] = array(
	'guide_title'          => 'Título de AUTOSAVE (não pode vencer)',
	'guide_content'        => '<p>Conteúdo de AUTOSAVE.</p>',
	'guide_useful_links'   => 'https://autosave.example.com',
	'guide_source'         => 'AUTOSAVE',
	'guide_featured_image' => '',
	'guide_county'         => '',
	'guide_town'           => '',
	'guide_url'            => '',
);
$editor_guide->save( $guide, get_post( $guide ) );
check( 'autosave: save() bails, manual title intact', 'Guia QA — título atualizado' === get_post( $guide )->post_title, get_post( $guide )->post_title );
check( 'autosave: save() bails, manual content intact', false === strpos( get_post( $guide )->post_content, 'AUTOSAVE' ) );
$result_autosave = apply_filters( 'wp_insert_post_empty_content', true, $guard_postarr );
check( 'autosave: empty-content guard NOT bypassed', true === $result_autosave );

section( 'CLEANUP + SUMMARY' );
foreach ( $created_posts as $pid ) {
	if ( (int) $pid === (int) $sub_id ) {
		continue;
	}
	wp_delete_post( $pid, true );
}
if ( function_exists( 'wp_delete_user' ) ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $sub_id );
}
wp_delete_attachment( $attachment_id, true );
delete_option( 'conexao_admin_ux_errors_' . $guide_bad );
delete_option( 'conexao_admin_ux_notice_' . $guide );
delete_option( 'conexao_admin_ux_notice_' . $sponsor );

printf( "\nRESULT: %d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );






