<?php
/**
 * Tests for the Lazer multi-select filter dropdowns (Tipo + Características).
 *
 * Verifies:
 *  - conexao_leisure_multi_slugs(): param normalization (sanitize, lowercase,
 *    trim, de-duplicate, order preserved, empty values dropped).
 *  - conexao_leisure_query_slugs(): reading multi-select params from $_GET.
 *  - conexao_leisure_filter_url(): shared URL builder (empty dimensions
 *    omitted, no pagina param, comma join).
 *  - The leisure branch of conexao_content_archive_query(): OR within a
 *    multi-select dimension (tax_query IN), AND between dimensions, single
 *    slugs unchanged, invalid slugs ignored safely.
 *  - Template markup: group-of-links filter options, active states,
 *    "Todos"/"Todas" as a per-dimension reset, cross-dimension preservation,
 *    multi-select trigger labels, per-value chips, mobile checkbox groups
 *    with nameless section-reset checkboxes.
 *
 * Creates temporary terms (unique test slugs) and temporary leisure posts
 * and deletes both at the end; never modifies existing records/terms.
 *
 * Usage (from the project root):
 *   docker compose exec wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-leisure-multiselect-filters.php
 */

// --- Bootstrap WordPress (plugins + theme option). ---

// Load the active theme so the helpers under test are defined (the active
// theme is not auto-loaded by wp-load.php in a CLI context).
// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$theme_functions = get_template_directory() . '/functions.php';
if ( file_exists( $theme_functions ) ) {
	require_once $theme_functions;
}

$passed        = 0;
$failed        = 0;
$created_posts = array();
$created_terms = array();

/** Get or create a temporary term; returns the slug. */
function ms_test_term( $name, $slug, $taxonomy ) {
	global $created_terms;
	$existing = term_exists( $slug, $taxonomy );
	if ( $existing && ! is_wp_error( $existing ) ) {
		return $slug;
	}
	$inserted = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
	if ( is_wp_error( $inserted ) ) {
		exit( 1 );
	}
	$created_terms[ $taxonomy ][] = (int) $inserted['term_id'];
	return $slug;
}

/** Create a temporary published leisure post with the given taxonomy slugs. */
function ms_test_leisure( $title, $categories, $attributes, $county ) {
	global $created_posts;
	$post_id = wp_insert_post(
		array(
			'post_type'    => 'leisure',
			'post_title'   => '[MULTISELECT TEST] ' . $title,
			'post_status'  => 'publish',
			'post_content' => 'test',
		)
	);
	if ( is_wp_error( $post_id ) || ! $post_id ) {
		exit( 1 );
	}
	$created_posts[] = (int) $post_id;
	if ( $county ) {
		wp_set_object_terms( $post_id, array( $county ), 'conexao_county', false );
	}
	if ( $categories ) {
		wp_set_object_terms( $post_id, $categories, 'conexao_category', false );
	}
	if ( $attributes ) {
		wp_set_object_terms( $post_id, $attributes, 'conexao_leisure_attribute', false );
	}
	return (int) $post_id;
}

/**
 * Run the leisure main-query filtering exactly like the archive would: the
 * new query is swapped in as the "main query" (is_main_query compares
 * against $GLOBALS['wp_the_query']), $_GET is set, then pre_get_posts runs
 * inside WP_Query::query() — the real hook path, no reimplementation.
 */
function ms_test_query( array $get_params ) {
	$tmp_get                 = $_GET;
	$_GET                    = $get_params;
	$saved_wp_the_query      = $GLOBALS['wp_the_query'];
	$q                       = new WP_Query();
	$GLOBALS['wp_the_query'] = $q;
	$q->query(
		array(
			'post_type'      => 'leisure',
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);
	$GLOBALS['wp_the_query'] = $saved_wp_the_query;
	$_GET                    = $tmp_get;
	return $q;
}

/** Render the filters template part with the given $_GET and return the HTML. */
function ms_test_render_filters( array $get_params ) {
	$tmp_get = $_GET;
	$_GET    = $get_params;
	ob_start();
	include get_template_directory() . '/template-parts/leisure-filters.php';
	$html = ob_get_clean();
	$_GET = $tmp_get;
	return $html;
}

// ---------------------------------------------------------------------------
// Fixture: temporary terms + three leisure posts.
//   A: natureza / exterior       / cavan
//   B: cultura  / familias       / cavan
//   C: natureza / estacionamento / dublin
// ---------------------------------------------------------------------------
test_section( 'Fixture' );

$c_cult     = ms_test_term( 'MS Cultura', 'ms-cultura', 'conexao_category' );
$c_natureza = ms_test_term( 'MS Natureza', 'ms-natureza', 'conexao_category' );
$a_exterior = ms_test_term( 'MS Exterior', 'ms-exterior', 'conexao_leisure_attribute' );
$a_familias = ms_test_term( 'MS Famílias', 'ms-familias', 'conexao_leisure_attribute' );
$a_estac    = ms_test_term( 'MS Estacionamento', 'ms-estacionamento', 'conexao_leisure_attribute' );
$co_cavan   = ms_test_term( 'MS Cavan', 'ms-cavan', 'conexao_county' );
$co_dublin  = ms_test_term( 'MS Dublin', 'ms-dublin', 'conexao_county' );

$post_a = ms_test_leisure( 'A', array( $c_natureza ), array( $a_exterior ), $co_cavan );
$post_b = ms_test_leisure( 'B', array( $c_cult ), array( $a_familias ), $co_cavan );
$post_c = ms_test_leisure( 'C', array( $c_natureza ), array( $a_estac ), $co_dublin );

assert_true( get_post( $post_a ) instanceof WP_Post && get_post( $post_b ) instanceof WP_Post && get_post( $post_c ) instanceof WP_Post, 'temporary leisure posts created' );

// ---------------------------------------------------------------------------
// 1. conexao_leisure_multi_slugs — normalization
// ---------------------------------------------------------------------------
test_section( 'Normalization (conexao_leisure_multi_slugs)' );

$r = conexao_leisure_multi_slugs( 'exterior,familias' );
assert_true( $r === array( 'exterior', 'familias' ), 'comma-separated string parsed in order' );

$r = conexao_leisure_multi_slugs( ' exterior , familias ,' );
assert_true( $r === array( 'exterior', 'familias' ), 'whitespace and trailing commas stripped' );

$r = conexao_leisure_multi_slugs( 'exterior,familias,exterior' );
assert_true( $r === array( 'exterior', 'familias' ), 'duplicate slugs removed, selection order kept' );

$r = conexao_leisure_multi_slugs( ',,exterior,' );
assert_true( $r === array( 'exterior' ), 'empty values dropped' );

$r = conexao_leisure_multi_slugs( 'Famílias,ESTACIONAMENTO' );
assert_true( $r === array( 'familias', 'estacionamento' ), 'values lowercased/canonicalized (accent + case)' );

$r = conexao_leisure_multi_slugs( array( 'exterior', 'familias' ) );
assert_true( $r === array( 'exterior', 'familias' ), 'array input (mobile checkbox groups) parsed' );

$r = conexao_leisure_multi_slugs( array( 'exterior', '', 'exterior' ) );
assert_true( $r === array( 'exterior' ), 'array input deduplicated and emptied' );

assert_true( conexao_leisure_multi_slugs( '' ) === array(), 'empty string yields empty list' );
assert_true( conexao_leisure_multi_slugs( '   ' ) === array(), 'whitespace-only string yields empty list' );

// ---------------------------------------------------------------------------
// 2. conexao_leisure_query_slugs — reading from $_GET
// ---------------------------------------------------------------------------
test_section( 'Query param reading (conexao_leisure_query_slugs)' );

$_GET = array( 'categoria' => 'ms-natureza,ms-cultura' );
assert_true( conexao_leisure_query_slugs( 'categoria' ) === array( 'ms-natureza', 'ms-cultura' ), 'comma-separated param read from $_GET' );

$_GET = array( 'atributo' => array( 'ms-exterior', 'ms-familias' ) );
assert_true( conexao_leisure_query_slugs( 'atributo' ) === array( 'ms-exterior', 'ms-familias' ), 'array param (mobile form) read from $_GET' );

$_GET = array();
assert_true( conexao_leisure_query_slugs( 'categoria' ) === array(), 'missing param yields empty list' );

// ---------------------------------------------------------------------------
// 3. conexao_leisure_filter_url — URL builder
// ---------------------------------------------------------------------------
test_section( 'URL builder (conexao_leisure_filter_url)' );

$base = get_post_type_archive_link( 'leisure' );
if ( ! $base ) {
	$base = home_url( '/lazer/' );
}

$url = conexao_leisure_filter_url( array( 'county' => 'ms-cavan', 'categoria' => array( 'ms-natureza', 'ms-cultura' ), 'atributo' => array( 'ms-exterior', 'ms-familias' ) ), $base );
assert_true( false !== strpos( $url, 'county=ms-cavan' ), 'URL keeps the county dimension' );
assert_true( false !== strpos( $url, 'categoria=ms-natureza%2Cms-cultura' ) || false !== strpos( $url, 'categoria=ms-natureza,ms-cultura' ), 'URL joins multi-select slugs with commas' );
assert_true( false !== strpos( $url, 'atributo=ms-exterior%2Cms-familias' ) || false !== strpos( $url, 'atributo=ms-exterior,ms-familias' ), 'URL keeps the attribute dimension' );
assert_true( false === strpos( $url, 'pagina=' ), 'URL never carries a pagina param (filter change resets to page 1)' );

$url = conexao_leisure_filter_url( array( 'county' => '', 'categoria' => array(), 'atributo' => array( 'ms-exterior' ) ), $base );
assert_true( false === strpos( $url, 'county=' ) && false === strpos( $url, 'categoria=' ) && false !== strpos( $url, 'atributo=ms-exterior' ), 'empty dimensions omitted from the URL' );

assert_true( conexao_leisure_filter_url( array( 'county' => '', 'categoria' => array(), 'atributo' => array() ), $base ) === $base, 'all-empty state yields the plain archive URL' );

// ---------------------------------------------------------------------------
// 4. Backend query: OR within a dimension, AND between dimensions
// ---------------------------------------------------------------------------
test_section( 'Backend query (pre_get_posts leisure branch)' );

// Single category — legacy URL semantics unchanged.
$q = ms_test_query( array( 'categoria' => 'ms-natureza' ) );
assert_true( array( $post_a, $post_c ) === wp_list_pluck( $q->posts, 'ID' ), 'single ?categoria= returns exactly the matching posts (legacy URLs unchanged)' );

// Two categories — OR within the dimension.
$q = ms_test_query( array( 'categoria' => 'ms-natureza,ms-cultura' ) );
assert_true( array( $post_a, $post_b, $post_c ) === wp_list_pluck( $q->posts, 'ID' ), '?categoria=a,b is interpreted as a OR b' );

// Selection order in the URL does not change the OR result.
$q = ms_test_query( array( 'categoria' => 'ms-cultura,ms-natureza' ) );
assert_true( array( $post_a, $post_b, $post_c ) === wp_list_pluck( $q->posts, 'ID' ), 'selection order in the URL does not change the OR result' );

// Array form (mobile checkbox groups).
$q = ms_test_query( array( 'categoria' => array( 'ms-natureza', 'ms-cultura' ) ) );
assert_true( array( $post_a, $post_b, $post_c ) === wp_list_pluck( $q->posts, 'ID' ), 'categoria[] array form is interpreted as OR' );

// Attributes: OR within the dimension.
$q = ms_test_query( array( 'atributo' => 'ms-exterior,ms-familias' ) );
assert_true( array( $post_a, $post_b ) === wp_list_pluck( $q->posts, 'ID' ), '?atributo=a,b is interpreted as a OR b' );

// County + multi-select Tipo: AND between dimensions.
$q = ms_test_query( array( 'county' => 'ms-cavan', 'categoria' => 'ms-natureza,ms-cultura' ) );
assert_true( array( $post_a, $post_b ) === wp_list_pluck( $q->posts, 'ID' ), 'county AND (categoria OR categoria)' );

// Category + attributes: AND between dimensions.
$q = ms_test_query( array( 'categoria' => 'ms-natureza', 'atributo' => 'ms-exterior,ms-familias' ) );
assert_true( array( $post_a ) === wp_list_pluck( $q->posts, 'ID' ), 'categoria AND (atributo OR atributo)' );

// All three dimensions.
$q = ms_test_query( array( 'county' => 'ms-cavan', 'categoria' => 'ms-natureza,ms-cultura', 'atributo' => 'ms-exterior,ms-familias' ) );
assert_true( array( $post_a, $post_b ) === wp_list_pluck( $q->posts, 'ID' ), 'county AND (categoria OR ...) AND (atributo OR ...)' );

// Invalid slugs: ignored safely within an OR list.
$q = ms_test_query( array( 'categoria' => 'ms-naoexiste,ms-natureza' ) );
assert_true( array( $post_a, $post_c ) === wp_list_pluck( $q->posts, 'ID' ), 'invalid slugs inside an OR list are ignored without breaking valid ones' );

$q = ms_test_query( array( 'categoria' => 'ms-naoexiste' ) );
assert_true( 0 === $q->found_posts, 'an all-invalid slug list matches nothing (no misleading results)' );

// Normalization happens at the query boundary too.
$q = ms_test_query( array( 'atributo' => 'MS-Exterior,ms-familias,ms-exterior' ) );
assert_true( array( $post_a, $post_b ) === wp_list_pluck( $q->posts, 'ID' ), 'query params are canonicalized (case + duplicates) before querying' );

// tax_query structure: AND relation, IN operator per multi-select dimension.
// Stage 2: Polylang adds its own `language` group to every front-end query, so
// the filter dimensions are asserted separately from the language group.
$q = ms_test_query( array( 'county' => 'ms-cavan', 'categoria' => 'ms-natureza,ms-cultura', 'atributo' => 'ms-exterior,ms-familias' ) );
$tax_query = $q->get( 'tax_query' );
assert_true( is_array( $tax_query ) && 'AND' === ( $tax_query['relation'] ?? '' ), 'tax_query uses relation AND between dimensions' );
$groups = array_values( array_filter( $tax_query, 'is_array' ) );
$language_groups = array_values(
	array_filter(
		$groups,
		static function ( $group ) {
			return 'language' === ( $group['taxonomy'] ?? '' );
		}
	)
);
$groups = array_values(
	array_filter(
		$groups,
		static function ( $group ) {
			return 'language' !== ( $group['taxonomy'] ?? '' );
		}
	)
);
assert_true( 3 === count( $groups ), 'tax_query contains one group per active dimension' );
if ( function_exists( 'pll_current_language' ) ) {
	assert_true( 1 === count( $language_groups ), 'the language layer adds exactly one language group to filtered leisure queries' );
}

$cat_group = null;
foreach ( $groups as $group ) {
	if ( 'conexao_category' === $group['taxonomy'] ) {
		$cat_group = $group;
	}
}
assert_true( null !== $cat_group && 'IN' === $cat_group['operator'] && array( 'ms-natureza', 'ms-cultura' ) === $cat_group['terms'], 'categoria group is tax_query IN over both slugs (no raw SQL)' );

// No filters: the plain archive keeps returning everything.
$q = ms_test_query( array() );
assert_true( $q->found_posts >= 3, 'unfiltered archive returns all published leisure posts (incl. seeded content)' );

// ---------------------------------------------------------------------------
// 5. Template markup — desktop dropdowns
// ---------------------------------------------------------------------------
test_section( 'Desktop markup: multi-select active states' );

// State: county=cavan, categoria=natureza+cultura, atributo=exterior+familias
// (with a duplicated slug to prove URL normalization).
$html = ms_test_render_filters( array(
	'county'    => 'ms-cavan',
	'categoria' => 'ms-natureza,ms-cultura',
	'atributo'  => 'ms-exterior,ms-familias,ms-exterior',
) );

// The multi-select dimensions are groups of hyperlinks, NOT listboxes:
// aria-multiselectable is only valid on a listbox, so it is gone. The
// multi-select behaviour itself is proven by the toggle URLs below.
assert_true( false === strpos( $html, 'aria-multiselectable' ), 'no orphan aria-multiselectable (only valid on a listbox)' );
assert_true( false === strpos( $html, 'role="listbox"' ), 'the multi-select groups are not advertised as listboxes' );
assert_true( false === strpos( $html, 'role="option"' ), 'no invalid role="option" on any multi-select option link' );

// The selected Tipo option is aria-current="true" and its link REMOVES that
// slug (toggle semantics) while keeping the other selections.
$natureza_toggle = preg_match( '/aria-current="true"[^>]*href="[^"]*categoria=ms-cultura[^"]*"/', $html )
	|| preg_match( '/href="[^"]*categoria=ms-cultura[^"]*"[^>]*aria-current="true"/', $html );
assert_true( (bool) $natureza_toggle, 'selected Tipo option: aria-current="true", link removes only that slug' );

// All URL assertions below run against a decoded copy of the markup:
// esc_url() escapes ampersands (&#038;) and commas may appear raw or as %2C
// depending on where add_query_arg rebuilt the query string — the filter
// semantics are identical in both forms.
$plain = rawurldecode( html_entity_decode( $html, ENT_QUOTES | ENT_HTML5 ) );

// Tipo reset (Todos): drops ONLY categoria, preserving county + deduped atributo.
assert_true( (bool) preg_match( '/href="' . preg_quote( $base, '/' ) . '\?county=ms-cavan&atributo=ms-exterior,ms-familias"/', $plain ), 'Tipo reset (Todos) drops ONLY categoria, preserving county + atributo (deduped)' );

// Toggle link of a selected category preserves county + atributo.
assert_true( (bool) preg_match( '/href="[^"]*county=ms-cavan[^\"]*categoria=ms-cultura[^\"]*atributo=ms-exterior,ms-familias[^"]*"/', $plain ), 'clicking a selected Tipo option removes only that slug, preserving the rest' );

// "Todas" reset for Características.
assert_true( (bool) preg_match( '/href="' . preg_quote( $base, '/' ) . '\?county=ms-cavan&categoria=ms-natureza,ms-cultura"/', $plain ), 'Características reset (Todas) drops ONLY atributo, preserving county + categoria' );

// County dropdown preserves the multi-select dimensions.
assert_true( (bool) preg_match( '/href="[^"]*county=ms-dublin[^\"]*categoria=ms-natureza,ms-cultura[^\"]*atributo=ms-exterior,ms-familias[^"]*"/', $plain ), 'switching Localização preserves categoria + atributo' );

assert_true( (bool) preg_match( '/href="' . preg_quote( $base, '/' ) . '\?categoria=ms-natureza,ms-cultura&atributo=ms-exterior,ms-familias"/', $plain )
	|| (bool) preg_match( '/href="' . preg_quote( $base, '/' ) . '\?atributo=ms-exterior,ms-familias&categoria=ms-natureza,ms-cultura"/', $plain ), 'Localização reset (Todos) preserves categoria + atributo' );

// Chips: one chip per selected value.
assert_true( false !== strpos( $html, 'Remover filtro: MS Natureza' ), 'chip rendered for each selected Tipo value' );
assert_true( false !== strpos( $html, 'Remover filtro: MS Cultura' ), 'second selected Tipo value has its own chip' );
assert_true( false !== strpos( $html, 'Remover filtro: MS Exterior' ), 'selected Características values render chips' );
assert_true( false !== strpos( $html, 'Remover filtro: MS Famílias' ), 'second selected Características value has its own chip' );

// No pagina anywhere.
assert_true( false === strpos( $html, 'pagina=' ), 'no filter URL carries a pagina param' );

// State: nothing selected — the three group reset options are the only
// active options, triggers show the group names.
$html = ms_test_render_filters( array() );
assert_true( 3 === substr_count( $html, 'aria-current="true"' ), 'exactly the three group reset options are active with no filters' );
assert_true( false !== strpos( $html, '>Tipo</span>' ), 'Tipo trigger shows the group name when nothing is selected' );
assert_true( false !== strpos( $html, '>Características</span>' ), 'Características trigger shows the group name when nothing is selected' );
assert_true( false === strpos( $html, 'selecionados' ), 'no count label when nothing is selected' );

// State: one Tipo selection — trigger shows the option label; within the
// Tipo listbox only that option (not Todos) is active.
$html = ms_test_render_filters( array( 'categoria' => 'ms-natureza' ) );
assert_true( false !== strpos( $html, '>MS Natureza</span>' ), 'single Tipo selection shows its label on the trigger' );
preg_match( '/leisure-category-panel.*?(<\/div>\s*<\/div>\s*<\/div>)/s', $html, $tipo_region );
if ( $tipo_region ) {
	assert_true( 1 === substr_count( $tipo_region[0], 'aria-current="true"' ), 'with one Tipo selected, only that option (not Todos) is active' );
}

// ---------------------------------------------------------------------------
// 6. Template markup — mobile sheet
// ---------------------------------------------------------------------------
test_section( 'Mobile markup: checkbox groups + section resets' );

$html = ms_test_render_filters( array(
	'county'    => 'ms-cavan',
	'categoria' => 'ms-natureza,ms-cultura',
	'atributo'  => 'ms-exterior',
) );

assert_true( false === strpos( $html, 'type="radio" name="categoria"' ), 'mobile Tipo no longer uses single-select radios' );
assert_true( false !== strpos( $html, 'name="categoria[]"' ), 'mobile Tipo uses a multi-select checkbox group (categoria[])' );
assert_true( false !== strpos( $html, 'name="atributo[]"' ), 'mobile Características keeps its checkbox group (atributo[])' );
assert_true( 2 === substr_count( $html, 'data-filter-clear' ), 'each multi-select section has a nameless reset checkbox (Todos/Todas)' );
assert_true( false !== strpos( $html, 'type="radio" name="county"' ), 'mobile Localização keeps its single-select radios (semantics unchanged)' );

// Checked states match the URL.
preg_match_all( '/<input class="leisure-filter-checkbox" type="checkbox" name="categoria\[\]" value="([^"]+)"\s+checked=\'checked\'/', $html, $cat_checked );
assert_true( array( 'ms-cultura', 'ms-natureza' ) === $cat_checked[1], 'mobile Tipo checkboxes are checked exactly for the selected slugs (term render order)' );

// With selections in both sections, neither reset checkbox is checked.
preg_match_all( '/<input class="leisure-filter-checkbox" type="checkbox" data-filter-clear(\s+checked=\'checked\')?\s*>/', $html, $clear_states );
assert_true( 2 === count( $clear_states[0] ) && 0 === count( array_filter( $clear_states[1] ) ), 'with selections present in both sections, neither Todos nor Todas is checked' );

// With nothing selected, both section resets are checked.
$html = ms_test_render_filters( array() );
preg_match_all( '/<input class="leisure-filter-checkbox" type="checkbox" data-filter-clear(\s+checked=\'checked\')?\s*>/', $html, $clear_states );
assert_true( 2 === count( array_filter( $clear_states[1] ) ), 'with nothing selected, both section resets are checked' );

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------
foreach ( $created_posts as $post_id ) {
	wp_delete_post( $post_id, true );
}
foreach ( $created_terms as $taxonomy => $term_ids ) {
	foreach ( $term_ids as $term_id ) {
		wp_delete_term( $term_id, $taxonomy );
	}
}

$leftover = get_posts(
	array(
		'post_type'   => 'leisure',
		'post_status' => 'any',
		's'           => '[MULTISELECT TEST]',
		'numberposts' => 5,
		'fields'      => 'ids',
	)
);
assert_true( empty( $leftover ), 'no temporary test posts remain' );

test_finish();
