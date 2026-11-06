<?php
/**
 * PERMANENT GATE (g) — the Polylang single-owner invariant is ENFORCED.
 *
 * Invariant (r10 §3.6, §9.5): the canonical Portuguese `commemorative_event`
 * record is the sole owner of the shared scheduling/presentation `_ce_*`
 * configuration. The English translation carries its translated copy plus
 * `_ce_event_key` (identity) and NOTHING else from the shared set. Three layers
 * enforce it, and this gate proves all three plus the resulting data state:
 *
 *   1. **Copy** — `pll_copy_post_metas` no longer carries the shared keys, so
 *      creating a translation cannot duplicate the configuration.
 *   2. **Editor** — the shared fields render READ-ONLY (no input emitted) on a
 *      non-canonical record.
 *   3. **Save** — `save()` persists only `Fields::writable_field_keys()` and the
 *      annual-preference save refuses a non-canonical record before writing, so
 *      a crafted POST cannot install a second owner.
 *
 * ## Why this gate does not pass vacuously
 *
 * The migration suite's split assertion only runs when a translation exists, so
 * a fresh database could satisfy it without proving anything. This gate:
 *
 *   - requires a real canonical record AND its linked translation (a missing
 *     prerequisite is a FAILURE, never a pass);
 *   - exercises the copy filter, the editor and the save guard directly, so the
 *     mechanism is proved even if the data were already correct by accident;
 *   - carries INJECTED NEGATIVE PROOFS: the same copy check is run over a key
 *     list that still contains a shared key and MUST flag it, the same renderer
 *     must still emit an input when the field is not locked, and the canonical
 *     record must stay editable.
 *
 * Read-only: no record, option or meta is created, modified or deleted.
 *
 * @package Conexao_BR_Irlanda
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_ROOT . '/lib/permanent-gates.php';

conexao_gate_open(
	'commemorative_translation_ownership',
	'The canonical PT commemorative record is the only owner of the shared _ce_* configuration: Polylang copies none of it, the editor renders it read-only on the translation, and the save paths refuse to persist it there.',
	array( 'theme-active', 'conexao-data-model + conexao-admin-ux + conexao-commemorative active', 'polylang active', 'a commemorative record with its linked translation' )
);

conexao_gate_require(
	class_exists( 'Conexao_Data_Model_Commemorative' )
		&& class_exists( 'Conexao_Admin_Ux_Fields' )
		&& class_exists( 'Conexao_Admin_Ux_Editor' )
		&& class_exists( 'Conexao_Commemorative' ),
	'activate conexao-data-model, conexao-admin-ux and conexao-commemorative',
	'the gate prerequisite is available: the data model, the admin UX and the engine'
);

conexao_gate_require(
	function_exists( 'pll_default_language' ) && function_exists( 'pll_get_post' ),
	'activate polylang',
	'the gate prerequisite is available: Polylang (the split only exists in a bilingual install)'
);

$ce_gate_shared     = Conexao_Data_Model_Commemorative::shared_meta_keys();
$ce_gate_translated = Conexao_Data_Model_Commemorative::translated_meta_keys();
$ce_gate_identity   = Conexao_Data_Model_Commemorative::identity_meta_keys();

/**
 * Find one canonical commemorative record and its linked translation.
 *
 * @return array{canonical:int,translation:int}
 */
function conexao_gate_ownership_pair() {
	$ids = get_posts(
		array(
			'post_type'        => Conexao_Data_Model_Commemorative::POST_TYPE,
			'post_status'      => 'any',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'suppress_filters' => true,
			'orderby'          => 'ID',
			'order'            => 'ASC',
		)
	);

	foreach ( $ids as $id ) {
		$id = (int) $id;

		if ( ! Conexao_Data_Model_Commemorative::is_canonical_language( $id ) ) {
			continue;
		}

		foreach ( pll_get_post_translations( $id ) as $translation_id ) {
			$translation_id = (int) $translation_id;

			if ( $translation_id > 0
				&& $translation_id !== $id
				&& ! Conexao_Data_Model_Commemorative::is_canonical_language( $translation_id )
			) {
				return array(
					'canonical'   => $id,
					'translation' => $translation_id,
				);
			}
		}
	}

	return array(
		'canonical'   => 0,
		'translation' => 0,
	);
}

/**
 * The `_ce_*` meta keys physically present on one record.
 *
 * @param int $post_id Post ID.
 * @return string[]
 */
function conexao_gate_ce_meta_keys( $post_id ) {
	global $wpdb;

	$keys = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only gate inspection.
		$wpdb->prepare(
			"SELECT meta_key FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
			(int) $post_id,
			$wpdb->esc_like( '_ce_' ) . '%'
		)
	);

	return array_values( array_unique( array_map( 'strval', (array) $keys ) ) );
}

/**
 * The gate's copy check: which shared keys survived a Polylang copy list.
 *
 * @param string[] $copied Copied meta keys.
 * @param string[] $shared Shared (canonical-only) keys.
 * @return string[] Shared keys still present.
 */
function conexao_gate_ownership_leaks( array $copied, array $shared ) {
	return array_values( array_intersect( $shared, $copied ) );
}

/**
 * Extract one function body from a PHP source file.
 *
 * @param string $file     Absolute path.
 * @param string $function Function name.
 * @return string
 */
function conexao_gate_function_body( $file, $function ) {
	$source = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local source inspection.

	$start = strpos( $source, 'function ' . $function . '(' );

	if ( false === $start ) {
		return '';
	}

	$rest = substr( $source, $start );
	$end  = strpos( $rest, "\n\t/**" );

	return false === $end ? $rest : substr( $rest, 0, $end );
}

$ce_gate_pair = conexao_gate_ownership_pair();

conexao_gate_require(
	$ce_gate_pair['canonical'] > 0 && $ce_gate_pair['translation'] > 0,
	'seed-commemorative-events',
	'the gate prerequisite is available: a canonical commemorative record with a linked EN translation',
	'run: docker compose exec -T wordpress php /var/www/html/scripts/seed-commemorative-events.php --apply'
);

$ce_gate_pt = $ce_gate_pair['canonical'];
$ce_gate_en = $ce_gate_pair['translation'];

test_section( 'Layer 1 — Polylang does not copy the shared configuration' );

conexao_gate_violation(
	'copy:filter_not_registered',
	has_filter( 'pll_copy_post_metas' ) ? 0 : 1,
	'the shared keys are excluded through the documented `pll_copy_post_metas` filter'
);

// A real copy list: every `_ce_*` key plus two unrelated Polylang keys.
$ce_gate_keys = array_merge(
	$ce_gate_shared,
	$ce_gate_translated,
	$ce_gate_identity,
	array( '_wp_page_template', '_thumbnail_id' )
);

$ce_gate_copied = apply_filters( 'pll_copy_post_metas', $ce_gate_keys, false, $ce_gate_pt, 0, 'en' );
$ce_gate_copied = is_array( $ce_gate_copied ) ? $ce_gate_copied : array();

$ce_gate_leaks = conexao_gate_ownership_leaks( $ce_gate_copied, $ce_gate_shared );

conexao_gate_violation(
	'copy:shared_key_copied',
	count( $ce_gate_leaks ),
	'a commemorative copy list carries no shared scheduling/presentation key',
	array( 'leaks' => $ce_gate_leaks )
);

// No OVER-exclusion: translated copy, identity and unrelated keys must survive.
$ce_gate_over = array_values(
	array_diff( Conexao_Data_Model_Commemorative::strip_shared_meta_keys( $ce_gate_keys ), $ce_gate_copied )
);

conexao_gate_violation(
	'copy:over_excluded',
	count( $ce_gate_over ),
	'only the shared keys are excluded — translated copy, identity and unrelated meta are still copied',
	array( 'over_excluded' => $ce_gate_over )
);

// Scope proof: an ordinary page keeps its full list (the filter is not global).
$ce_gate_page = get_page_by_path( 'documentos', OBJECT, 'page' );

conexao_gate_require(
	$ce_gate_page instanceof WP_Post,
	'seed-pages',
	'the gate prerequisite is available: a normal page for the copy-scope proof'
);

if ( $ce_gate_page instanceof WP_Post ) {
	$ce_gate_page_copied = apply_filters( 'pll_copy_post_metas', $ce_gate_keys, false, (int) $ce_gate_page->ID, 0, 'en' );

	conexao_gate_violation(
		'copy:unrelated_post_type_changed',
		( is_array( $ce_gate_page_copied ) && $ce_gate_page_copied === $ce_gate_keys ) ? 0 : 1,
		'a non-commemorative post type keeps its copy list unchanged',
		array( 'page' => (int) $ce_gate_page->ID )
	);
}

test_section( 'Injected negative proof: the copy check detects a leaked shared key' );

$ce_gate_mutant = conexao_gate_ownership_leaks(
	array_merge( $ce_gate_keys, array( '_ce_enabled' ) ),
	$ce_gate_shared
);

conexao_gate_violation(
	'gate:copy_leak_not_detected',
	count( $ce_gate_mutant ) > 0 ? 0 : 1,
	'the copy check flags a list that DOES still carry a shared key',
	array( 'detected' => $ce_gate_mutant )
);

test_section( 'Layer 2 — the translation editor is read-only for shared fields' );

assert_true( Conexao_Admin_Ux_Editor::is_locked_translation( $ce_gate_en ), 'the EN record is recognised as a non-canonical translation' );
assert_true( ! Conexao_Admin_Ux_Editor::is_locked_translation( $ce_gate_pt ), 'the PT record is NOT locked (a legitimate canonical edit stays possible)' );

$ce_gate_config = Conexao_Admin_Ux_Config::get( Conexao_Data_Model_Commemorative::POST_TYPE );
$ce_gate_fields = $ce_gate_config['sections']['informacao']['fields'];

$ce_gate_en_fields = Conexao_Admin_Ux_Editor::lock_shared_fields( $ce_gate_fields, $ce_gate_en );
$ce_gate_pt_fields = Conexao_Admin_Ux_Editor::lock_shared_fields( $ce_gate_fields, $ce_gate_pt );

$ce_gate_editable_shared = array();

foreach ( $ce_gate_en_fields as $ce_gate_field ) {
	if ( ! empty( $ce_gate_field['key'] )
		&& Conexao_Data_Model_Commemorative::is_shared_meta_key( $ce_gate_field['key'] )
		&& empty( $ce_gate_field['readonly'] )
	) {
		$ce_gate_editable_shared[] = $ce_gate_field['key'];
	}
}

conexao_gate_violation(
	'editor:shared_field_editable',
	count( $ce_gate_editable_shared ),
	'every shared field is read-only on the translation',
	array( 'editable' => $ce_gate_editable_shared )
);

$ce_gate_locked_translated = array();

foreach ( $ce_gate_en_fields as $ce_gate_field ) {
	if ( ! empty( $ce_gate_field['key'] )
		&& in_array( $ce_gate_field['key'], $ce_gate_translated, true )
		&& ! empty( $ce_gate_field['readonly'] )
	) {
		$ce_gate_locked_translated[] = $ce_gate_field['key'];
	}
}

conexao_gate_violation(
	'editor:translated_field_locked',
	count( $ce_gate_locked_translated ),
	'the translated copy stays editable on the translation (only the shared set is locked)',
	array( 'locked' => $ce_gate_locked_translated )
);


// The rendered control must not be an input the browser could post back.
$ce_gate_shared_field = null;

foreach ( $ce_gate_en_fields as $ce_gate_field ) {
	if ( isset( $ce_gate_field['key'] ) && '_ce_enabled' === $ce_gate_field['key'] ) {
		$ce_gate_shared_field = $ce_gate_field;
	}
}

assert_true( is_array( $ce_gate_shared_field ), 'the shared `_ce_enabled` control exists in the editor config' );

if ( is_array( $ce_gate_shared_field ) ) {
	$ce_gate_html = Conexao_Admin_Ux_Fields::render(
		$ce_gate_shared_field,
		get_post_meta( $ce_gate_en, '_ce_enabled', true ),
		$ce_gate_en
	);

	conexao_gate_violation(
		'editor:shared_field_renders_input',
		false !== strpos( $ce_gate_html, 'name="conexao_fields[ce_enabled]"' ) ? 1 : 0,
		'the read-only control emits NO submittable input',
		array( 'html_bytes' => strlen( $ce_gate_html ) )
	);

	assert_true( false !== strpos( $ce_gate_html, 'conexao-readonly-value' ), 'the read-only control displays the shared value' );

	// Injected negative proof: the same renderer still emits an input unlocked.
	$ce_gate_unlocked_field = $ce_gate_shared_field;
	unset( $ce_gate_unlocked_field['readonly'] );

	$ce_gate_pt_html = Conexao_Admin_Ux_Fields::render( $ce_gate_unlocked_field, '1', $ce_gate_pt );

	conexao_gate_violation(
		'gate:locked_render_check_is_vacuous',
		false !== strpos( $ce_gate_pt_html, 'name="conexao_fields[ce_enabled]"' ) ? 0 : 1,
		'the lock check can tell a locked control apart from an editable one'
	);
}

// Negative proof: the canonical record keeps its editable field set.
$ce_gate_pt_locked = 0;

foreach ( $ce_gate_pt_fields as $ce_gate_field ) {
	if ( ! empty( $ce_gate_field['readonly'] ) ) {
		++$ce_gate_pt_locked;
	}
}

conexao_gate_violation(
	'editor:canonical_field_locked',
	$ce_gate_pt_locked,
	'the canonical PT record has no read-only field',
	array( 'locked' => $ce_gate_pt_locked )
);

test_section( 'Layer 3 — the save paths refuse shared configuration on a translation' );

$ce_gate_en_writable = Conexao_Admin_Ux_Fields::writable_field_keys( $ce_gate_en, $ce_gate_config );
$ce_gate_pt_writable = Conexao_Admin_Ux_Fields::writable_field_keys( $ce_gate_pt, $ce_gate_config );

$ce_gate_en_shared = array_values( array_intersect( $ce_gate_en_writable, $ce_gate_shared ) );

conexao_gate_violation(
	'save:shared_key_writable_on_translation',
	count( $ce_gate_en_shared ),
	'a translation may persist NO shared key (a crafted POST is dropped, not hidden)',
	array( 'writable_shared' => $ce_gate_en_shared )
);

$ce_gate_pt_missing = array_values(
	array_diff(
		array_intersect( $ce_gate_shared, array_column( Conexao_Admin_Ux_Fields::collect_fields( $ce_gate_config ), 'key' ) ),
		$ce_gate_pt_writable
	)
);

conexao_gate_violation(
	'save:canonical_shared_key_not_writable',
	count( $ce_gate_pt_missing ),
	'the canonical record may still persist every shared key the editor owns',
	array( 'missing' => $ce_gate_pt_missing )
);

foreach ( array_merge( $ce_gate_translated, $ce_gate_identity ) as $ce_gate_allowed ) {
	assert_true(
		in_array( $ce_gate_allowed, $ce_gate_en_writable, true ),
		'the translation may still persist `' . $ce_gate_allowed . '`'
	);
}

// The guard must be WIRED INTO the save paths, not merely available.
$ce_gate_fields_body = conexao_gate_function_body(
	dirname( __DIR__, 4 ) . '/wp-content/plugins/conexao-admin-ux/includes/class-fields.php',
	'save'
);

conexao_gate_violation(
	'save:editor_guard_not_wired',
	( '' !== $ce_gate_fields_body && false !== strpos( $ce_gate_fields_body, 'writable_field_keys(' ) ) ? 0 : 1,
	'the editor save path persists only the writable field list',
	array( 'body_bytes' => strlen( $ce_gate_fields_body ) )
);

$ce_gate_precedence_body = conexao_gate_function_body(
	dirname( __DIR__, 4 ) . '/wp-content/plugins/conexao-commemorative/includes/class-conexao-commemorative-admin.php',
	'save_precedence'
);

$ce_gate_guard_at = strpos( $ce_gate_precedence_body, 'can_write_shared_meta(' );
$ce_gate_write_at = strpos( $ce_gate_precedence_body, 'update_post_meta(' );

conexao_gate_violation(
	'save:precedence_guard_not_wired',
	( false !== $ce_gate_guard_at && ( false === $ce_gate_write_at || $ce_gate_guard_at < $ce_gate_write_at ) ) ? 0 : 1,
	'the annual-preference save refuses a non-canonical record BEFORE writing',
	array( 'guard_at' => false === $ce_gate_guard_at ? -1 : $ce_gate_guard_at )
);

test_section( 'The stored data matches the invariant' );

$ce_gate_pt_keys = conexao_gate_ce_meta_keys( $ce_gate_pt );
$ce_gate_en_keys = conexao_gate_ce_meta_keys( $ce_gate_en );

$ce_gate_pt_shared_rows = array_values( array_intersect( $ce_gate_pt_keys, $ce_gate_shared ) );

conexao_gate_violation(
	'data:canonical_missing_shared_rows',
	0 === count( $ce_gate_pt_shared_rows ) ? 1 : 0,
	'the canonical record actually carries the shared configuration',
	array( 'shared_rows' => count( $ce_gate_pt_shared_rows ) )
);

$ce_gate_en_shared_rows = array_values( array_intersect( $ce_gate_en_keys, $ce_gate_shared ) );

conexao_gate_violation(
	'data:translation_carries_shared_rows',
	count( $ce_gate_en_shared_rows ),
	'the translation carries no shared `_ce_*` row',
	array( 'rows' => $ce_gate_en_shared_rows )
);

assert_true( in_array( '_ce_event_key', $ce_gate_en_keys, true ), 'the translation carries `_ce_event_key` (identity is not shared configuration)' );

conexao_gate_close();
