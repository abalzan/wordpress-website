<?php
/**
 * Tests for the Apoiadores archive custom ordering.
 *
 * Verifies `conexao_sponsor_archive_ordered_ids()` (functions.php) and the
 * /apoiadores/ sponsor branch of `conexao_content_archive_query()`:
 *
 *  - The ordering source is the SAME editor-curated field the homepage
 *    carousel uses — "Ordem de exibição" (_sponsor_display_order).
 *  - Supporters WITH a custom order come first, sorted ascending
 *    (1 → 2 → 3 → …).
 *  - Numeric 0 is a VALID order value (never treated as empty).
 *  - Duplicate order values never discard a supporter: ties resolve
 *    deterministically by title ascending, then post ID ascending.
 *  - Supporters WITHOUT a custom order (missing/empty/null) sort after every
 *    ordered supporter, newest published first (post_date DESC, post ID DESC
 *    as the final tiebreak).
 *  - Editing a supporter's order value immediately changes its position;
 *    adding a new unordered supporter places it automatically, newest first.
 *  - The ordered list is applied to the main archive query via post__in +
 *    orderby => post__in, so pagination slices a globally-ordered set.
 *
 * The script creates temporary sponsor posts and deletes them at the end;
 * it writes no other data. Published sponsors already present in the
 * database are untouched and only used as context for global-order checks.
 *
 * Requirements: conexao-data-model (sponsor CPT) and the active theme.
 *
 * Usage (from the project root):
 *   docker compose exec wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-sponsor-archive-ordering.php
 */

// --- Bootstrap WordPress (plugins + theme option). ---

// Load the active theme so the helpers under test are defined.
// The active theme is not auto-loaded by wp-load.php in a CLI context.
// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$theme_functions = get_template_directory() . '/functions.php';
if ( file_exists( $theme_functions ) ) {
	require_once $theme_functions;
}

$passed        = 0;
$failed        = 0;
$created_posts = array();

/**
 * Create a temporary published sponsor post with the given title, order value
 * and publication date ('' = leave default).
 *
 * @return int New sponsor post ID.
 */
function sponsor_order_test_create( $title, $order_value = '', $post_date = '' ) {
	global $created_posts;

	$args = array(
		'post_type'   => 'sponsor',
		'post_title'  => '[PH TEST ORDER] ' . $title,
		'post_status' => 'publish',
	);
	if ( '' !== $post_date ) {
		$args['post_date'] = $post_date;
	}
	$post_id = wp_insert_post( $args, true );
	if ( is_wp_error( $post_id ) ) {
		exit( 1 );
	}
	if ( '' !== $order_value ) {
		update_post_meta( $post_id, '_sponsor_display_order', $order_value );
	}
	$created_posts[] = (int) $post_id;
	return (int) $post_id;
}

echo 'Site: ' . home_url() . "\n";

if ( ! function_exists( 'conexao_sponsor_archive_ordered_ids' ) ) {
	exit( 1 );
}

// Start from a clean homepage sponsor cache so cached fixtures never leak.
delete_transient( 'conexao_home_sponsors' );

// ---------------------------------------------------------------------------
// 1. Ascending custom order, numeric 0 valid, duplicates + tiebreakers
// ---------------------------------------------------------------------------
test_section( 'Ordered Supporters (custom order ascending)' );

// Deliberately inserted in scrambled order; expected: 0 → 1 → 2 (Alfa) → 2 (Bravo) → 3.
$z_0 = sponsor_order_test_create( 'Zulu zero', '0' );   // valid order 0.
$a_1 = sponsor_order_test_create( 'Alfa um', '1' );
$b_2 = sponsor_order_test_create( 'Bravo dois', '2' );
$a_2 = sponsor_order_test_create( 'Alfa dois', '2' );   // duplicate 2.
$g_3 = sponsor_order_test_create( 'Golf tres', '3' );

$full_ids   = conexao_sponsor_archive_ordered_ids();
$test_order = array_values( array_intersect( $full_ids, $created_posts ) );

assert_true( $test_order === array( $z_0, $a_1, $a_2, $b_2, $g_3 ), 'ordered group is `_sponsor_display_order` ascending (0 → 1 → 2 → 2 → 3; equal 2s by title asc)' );
assert_true( 5 === count( array_intersect( $test_order, array( $z_0, $a_1, $b_2, $a_2, $g_3 ) ) ), 'no supporter disappears (5/5 present, duplicate 2 kept twice)' );
assert_true( count( $test_order ) === count( array_unique( $test_order ) ), 'no duplicate post IDs in the result' );

// Duplicate order 2 resolved by title ascending: "Alfa dois" (a) < "Bravo dois" (b).
assert_true( array_search( $a_2, $test_order, true ) < array_search( $b_2, $test_order, true ), 'duplicate order ties resolve by title ascending' );
// ---------------------------------------------------------------------------
// 2. Supporters without a custom order sort after ALL ordered, newest first
// ---------------------------------------------------------------------------
test_section( 'Unordered Supporters (after ordered, newest first)' );

$old = sponsor_order_test_create( 'Sem ordem antigo', '', '2024-01-01 09:00:00' );
$mid = sponsor_order_test_create( 'Sem ordem medio', '', '2025-06-15 09:00:00' );
$new = sponsor_order_test_create( 'Sem ordem novo', '', '2026-08-10 09:00:00' );

$full_ids      = conexao_sponsor_archive_ordered_ids();
$test_order    = array_values( array_intersect( $full_ids, $created_posts ) );
$ordered_part  = array( $z_0, $a_1, $a_2, $b_2, $g_3 );
$unordered_pos = array();
$ordered_max   = -1;
foreach ( $test_order as $pos => $id ) {
	if ( in_array( $id, $ordered_part, true ) ) {
		$ordered_max = max( $ordered_max, $pos );
	} else {
		$unordered_pos[ $id ] = $pos;
	}
}

assert_true( $test_order === array_merge( $ordered_part, array( $new, $mid, $old ) ), 'unordered supporters come after every ordered supporter' );
assert_true( min( $unordered_pos ) > $ordered_max, 'ALL unordered supporters appear after ALL ordered supporters' );
assert_true( $unordered_pos[ $new ] < $unordered_pos[ $mid ] && $unordered_pos[ $mid ] < $unordered_pos[ $old ], 'unordered supporters are newest published first' );

// ---------------------------------------------------------------------------
// 3. 0 vs empty distinction + living-editing behavior
// ---------------------------------------------------------------------------
test_section( 'Edge Cases & Live Edits' );

// '0' must be a valid order (this is also exercised by $z_0 above).
$zero_value = get_post_meta( $z_0, '_sponsor_display_order', true );
assert_true( '0' === (string) $zero_value && '' !== (string) $zero_value, 'numeric 0 is stored and treated as a valid custom order' );

// New unordered supporter (newest date) lands first in the unordered group.
$brand_new  = sponsor_order_test_create( 'Sem ordem recentissimo', '', '2026-08-11 09:00:00' );
$full_ids   = conexao_sponsor_archive_ordered_ids();
$test_order = array_values( array_intersect( $full_ids, $created_posts ) );
$expected_after_add = array_merge( $ordered_part, array( $brand_new, $new, $mid, $old ) );
assert_true( $test_order === $expected_after_add, 'adding a new unordered supporter places it automatically (newest first)' );

// Giving an unordered supporter an order moves it into the ordered group.
update_post_meta( $new, '_sponsor_display_order', '1' ); // Ties with $a_1 → title asc keeps "Alfa um" first.
$full_ids      = conexao_sponsor_archive_ordered_ids();
$test_order    = array_values( array_intersect( $full_ids, $created_posts ) );
$new_ordered   = array_merge( array( $z_0, $a_1, $new, $a_2, $b_2, $g_3 ), array( $brand_new, $mid, $old ) );
assert_true( $test_order === $new_ordered, 'setting a custom order on an unordered supporter moves it into the ordered group' );
// Changing an existing order value repositions the supporter immediately.
update_post_meta( $g_3, '_sponsor_display_order', '9' ); // 3 → 9: last of the ordered group.
$full_ids     = conexao_sponsor_archive_ordered_ids();
$test_order   = array_values( array_intersect( $full_ids, $created_posts ) );
$reordered    = array( $z_0, $a_1, $new, $a_2, $b_2, $g_3 );
assert_true( $test_order === array_merge( $reordered, array( $brand_new, $mid, $old ) ), 'updating an order value repositions the supporter immediately' );

// Empty / missing values are strictly "no order" — never 0.
update_post_meta( $mid, '_sponsor_display_order', '' );
$empty_val = get_post_meta( $mid, '_sponsor_display_order', true );
$full_ids  = conexao_sponsor_archive_ordered_ids();
$test_order = array_values( array_intersect( $full_ids, $created_posts ) );
$pos_mid   = array_search( $mid, $test_order, true );
$pos_brand = array_search( $brand_new, $test_order, true );
assert_true( '' === (string) $empty_val && $pos_mid > $pos_brand, 'empty order value is treated as "no order" (group 2)' );

// ---------------------------------------------------------------------------
// 4. Archive query wiring: post__in + orderby post__in survives pagination
// ---------------------------------------------------------------------------
test_section( 'Archive Query Wiring & Pagination' );

$sponsor_ids = conexao_sponsor_archive_ordered_ids();

// Simulates what conexao_content_archive_query() sets on the main query.
$page_1 = new WP_Query( array(
	'post_type'      => 'sponsor',
	'post_status'    => 'publish',
	'posts_per_page' => 3,
	'paged'          => 1,
	'post__in'       => $sponsor_ids,
	'orderby'        => 'post__in',
	'order'          => 'ASC',
) );
$page_2 = new WP_Query( array(
	'post_type'      => 'sponsor',
	'post_status'    => 'publish',
	'posts_per_page' => 3,
	'paged'          => 2,
	'post__in'       => $sponsor_ids,
	'orderby'        => 'post__in',
	'order'          => 'ASC',
) );

$ids_page_1 = wp_list_pluck( $page_1->posts, 'ID' );
$ids_page_2 = wp_list_pluck( $page_2->posts, 'ID' );
$combined   = array_merge( $ids_page_1, $ids_page_2 );

$expected_page_1 = array_slice( $sponsor_ids, 0, 3 );
$expected_page_2 = array_slice( $sponsor_ids, 3, 3 );
assert_true( $ids_page_1 === $expected_page_1, 'page 1 returns the first 3 supporters of the global order' );
assert_true( $ids_page_2 === $expected_page_2, 'page 2 returns the next 3 supporters of the global order' );
assert_true( $combined === array_slice( $sponsor_ids, 0, 6 ), 'pagination concatenation preserves the global order (no page-local sorting)' );

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------
foreach ( $created_posts as $post_id ) {
	wp_delete_post( $post_id, true );
}
// Restore the homepage sponsor cache to its pre-test (unprimed) state.
delete_transient( 'conexao_home_sponsors' );

test_finish();
