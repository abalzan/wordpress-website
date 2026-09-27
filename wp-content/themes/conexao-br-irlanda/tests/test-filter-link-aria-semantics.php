<?php
/**
 * Regression suite: filter link ARIA semantics (no invalid role="option").
 *
 * The desktop filter dropdowns used to render every option as
 * `<a role="option" aria-selected="…">` inside a `role="listbox"` panel
 * opened by an `aria-haspopup="listbox"` trigger. That is invalid: role="option"
 * overrides the native link role of an <a href>, and the widget never
 * implemented the listbox keyboard contract it advertised.
 *
 * The options are real navigational hyperlinks, so the correct semantics are a
 * disclosure button (aria-expanded + aria-controls) over a named group of
 * links, with the active option marked aria-current="true" — the same
 * convention already used by guide-filters.php / course-filters.php.
 *
 * Verifies, for all three filter widgets (leisure / event / employment):
 *  - the filter options are still <a> elements with their real hrefs;
 *  - role="option", role="listbox", aria-selected, aria-multiselectable and
 *    aria-haspopup="listbox" are ABSENT from the rendered markup;
 *  - the active option carries aria-current="true" and inactive ones do not;
 *  - the visible state (is-active class + checkmark) is unchanged;
 *  - filter URLs and query parameters are unchanged by the markup correction;
 *  - the PT/EN URL helpers still target the right language prefix (B1 for
 *    /empregos/ is a routing concern and is not touched here).
 *
 * Creates temporary terms/posts under the unique 'a11y-' prefix and deletes
 * them at the end; never modifies existing records or production terms.
 *
 * Usage (from the project root):
 *   docker compose exec wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-filter-link-aria-semantics.php
 *
 * @package Conexao_BR_Irlanda
 */

// --- Bootstrap WordPress (plugins + theme option). ---

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

/** Get or create a temporary term owned by this test; returns the slug. */
function a11y_test_term( $name, $slug, $taxonomy ) {
	global $created_terms;
	$existing = term_exists( $slug, $taxonomy );
	if ( $existing && ! is_wp_error( $existing ) ) {
		$created_terms[ $taxonomy ][] = (int) $existing['term_id'];
		return $slug;
	}
	$inserted = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
	if ( is_wp_error( $inserted ) ) {
		exit( 1 );
	}
	$created_terms[ $taxonomy ][] = (int) $inserted['term_id'];
	return $slug;
}

/** Create a temporary published post and track it for cleanup. */
function a11y_test_post( $title, $slug, $post_type, $terms = array() ) {
	global $created_posts;
	$id = wp_insert_post(
		array(
			'post_title'  => $title,
			'post_name'   => $slug,
			'post_type'   => $post_type,
			'post_status' => 'publish',
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		exit( 1 );
	}
	$created_posts[] = (int) $id;
	foreach ( $terms as $taxonomy => $slugs ) {
		wp_set_object_terms( $id, (array) $slugs, $taxonomy, false );
	}
	return (int) $id;
}

/** Render a filter template part with a given $_GET state. */
function a11y_test_render( $template, array $get_params ) {
	$tmp_get = $_GET;
	$_GET    = $get_params;
	ob_start();
	include get_template_directory() . '/template-parts/' . $template;
	$html = ob_get_clean();
	$_GET = $tmp_get;
	return $html;
}

/**
 * Assert the corrected ARIA contract on a rendered filter widget.
 *
 * Positive AND negative semantics, so the gate fails both if the fix is
 * reverted and if the widget stops rendering options at all.
 *
 * @param string $html   Rendered markup.
 * @param string $prefix Option-link CSS class prefix (e.g. 'leisure').
 * @param string $label  Human label for the failure message.
 * @return void

 */
function a11y_assert_option_semantics( $html, $prefix, $label ) {
	$link_class = $prefix . '-dropdown-link';

	assert_true(
		false !== strpos( $html, 'class="' . $link_class ),
		$label . ': option links are still rendered as <a> with the existing CSS class'
	);
	assert_true(
		0 === substr_count( $html, 'role="option"' ),
		$label . ': no invalid role="option" on any filter link'
	);
	assert_true(
		0 === substr_count( $html, 'role="listbox"' ),
		$label . ': the option container is no longer a listbox (links are not options)'
	);
	assert_true(
		false === strpos( $html, 'aria-selected' ),
		$label . ': no orphan aria-selected (invalid on role="link")'
	);
	assert_true(
		false === strpos( $html, 'aria-multiselectable' ),
		$label . ': no orphan aria-multiselectable (only valid on a listbox)'
	);
	assert_true(
		false === strpos( $html, 'aria-haspopup="listbox"' ),
		$label . ': the trigger no longer advertises a listbox popup'
	);

	// The disclosure relationship itself must survive the correction.
	assert_true(
		false !== strpos( $html, 'aria-expanded="false"' ),
		$label . ': the dropdown trigger keeps aria-expanded (disclosure contract)'
	);
	assert_true(
		false !== strpos( $html, 'aria-controls=' ),
		$label . ': the dropdown trigger keeps aria-controls pointing at its panel'
	);

	// Every option link must still be a real hyperlink.
	preg_match_all( '/<a class="' . preg_quote( $link_class, '/' ) . '[^>]*>/', $html, $m );
	$option_tags = $m[0];
	assert_true(
		count( $option_tags ) > 0,
		$label . ': at least one option link rendered (the gate is not vacuous)'
	);

	$without_href = 0;
	$with_current = 0;
	$bad_current  = 0;
	$malformed    = 0;
	foreach ( $option_tags as $tag ) {
		if ( false === strpos( $tag, 'href=' ) ) {
			++$without_href;
		}
		// The opening tag must be closed. In PHP a short echo tag on its own
		// does NOT emit a literal ">" into the output, so an option whose
		// attribute list ends with only the short tag silently loses its ">"
		// and the browser swallows the rest of the tag as attributes.
		if ( ! preg_match( '/>\s*$/', $tag ) ) {
			++$malformed;
		}
		if ( false !== strpos( $tag, 'aria-current="true"' ) ) {
			++$with_current;
			if ( false === strpos( $tag, 'is-active' ) ) {
				++$bad_current;
			}
		}
	}
	assert_true(
		0 === $malformed,
		$label . ': every option <a> opening tag is properly closed with a literal ">"'
	);
	assert_true(
		0 === $without_href,
		$label . ': every option link keeps a real href (navigation is never replaced by JS)'
	);
	assert_true(
		0 === $bad_current,
		$label . ': aria-current="true" only ever appears together with the is-active state'
	);

	return array(
		'total' => count( $option_tags ),
		'current' => $with_current,
	);
}


// ---------------------------------------------------------------------------
// Fixtures: temporary terms and posts so every widget has real options.
// ---------------------------------------------------------------------------

$t_county = a11y_test_term( 'A11y County', 'a11y-county', 'conexao_county' );
$t_town   = a11y_test_term( 'A11y Town', 'a11y-town', 'conexao_town' );
$t_cat    = a11y_test_term( 'A11y Category', 'a11y-category', 'conexao_category' );
$t_cat2   = a11y_test_term( 'A11y Category Two', 'a11y-category-two', 'conexao_category' );
$t_attr   = a11y_test_term( 'A11y Attribute', 'a11y-attribute', 'conexao_leisure_attribute' );

a11y_test_post(
	'A11y leisure item',
	'a11y-leisure-item',
	'leisure',
	array(
		'conexao_county'           => array( $t_county ),
		'conexao_category'         => array( $t_cat ),
		'conexao_leisure_attribute' => array( $t_attr ),
	)
);
a11y_test_post(
	'A11y event',
	'a11y-event',
	'event',
	array(
		'conexao_county'   => array( $t_county ),
		'conexao_town'     => array( $t_town ),
		'conexao_category' => array( $t_cat, $t_cat2 ),
	)
);

/**
 * Delete every fixture this suite created.
 *
 * Idempotent and also registered as a shutdown function, so an assertion
 * failure or a fatal error can never leave test content behind.
 *
 * @return void
 */
function a11y_test_cleanup() {
	global $created_posts, $created_terms;

	foreach ( $created_posts as $post_id ) {
		wp_delete_post( $post_id, true );
	}
	$created_posts = array();

	foreach ( $created_terms as $taxonomy => $ids ) {
		foreach ( $ids as $term_id ) {
			wp_delete_term( $term_id, $taxonomy );
		}
	}
	$created_terms = array();
}

register_shutdown_function( 'a11y_test_cleanup' );

// ---------------------------------------------------------------------------
// 1. Leisure filters (/lazer/).
// ---------------------------------------------------------------------------

test_section( 'Leisure filters: options are links, not listbox options' );

$html = a11y_test_render( 'leisure-filters.php', array() );
$info = a11y_assert_option_semantics( $html, 'leisure', 'leisure' );
assert_true( false !== strpos( $html, 'county=' . $t_county ), 'leisure: county options still rendered from real terms' );
assert_true( false !== strpos( $html, 'categoria=' . $t_cat ), 'leisure: category options still rendered' );
assert_true( false !== strpos( $html, 'atributo=' . $t_attr ), 'leisure: attribute options still rendered' );
assert_true( false === strpos( $html, 'Limpar filtros' ), 'leisure: the clear-filters action stays hidden while no filter is active' );

// No filter active: only the three group reset options are current.
assert_true( 3 === $info['current'], 'leisure: with no filter active exactly the 3 reset options are aria-current (got ' . $info['current'] . ')' );

$html = a11y_test_render(
	'leisure-filters.php',
	array(
		'county'    => $t_county,
		'categoria' => $t_cat,
		'atributo'  => $t_attr,
	)
);
$info = a11y_assert_option_semantics( $html, 'leisure', 'leisure active' );
assert_true( 3 === $info['current'], 'leisure: each of the 3 active dimensions is exactly one aria-current link (got ' . $info['current'] . ')' );
assert_true( false !== strpos( $html, 'Filtros ativos' ), 'leisure: active-filter chips still rendered when filters are applied' );
assert_true( false !== strpos( $html, 'leisure-toolbar-clear' ), 'leisure: the clear-filters action reappears once a filter is active' );

// Multi-select toggling must still produce the remove-one-slug URLs.
$html = a11y_test_render( 'leisure-filters.php', array( 'categoria' => $t_cat ) );
assert_true(
	false !== strpos( $html, 'Remover filtro' ),
	'leisure: a selected multi-select value still renders a removable chip'
);

// The URL builder is untouched by the markup correction.
$url = conexao_leisure_filter_url( array( 'county' => $t_county, 'categoria' => array( $t_cat ) ) );
assert_true(
	false !== strpos( $url, 'county=' . $t_county ) && false !== strpos( $url, 'categoria=' . $t_cat ),
	'leisure: filter URL still carries both dimensions (query contract unchanged)'
);
assert_true( false === strpos( $url, 'paged=' ), 'leisure: filter URL still resets the page cursor' );

// ---------------------------------------------------------------------------
// 2. Event filters (/eventos/).
// ---------------------------------------------------------------------------

test_section( 'Event filters: options are links, not listbox options' );

$html = a11y_test_render( 'event-filters.php', array() );
$info = a11y_assert_option_semantics( $html, 'event-filters', 'events' );
assert_true( false !== strpos( $html, 'county=' . $t_county ), 'events: county options still rendered' );
assert_true( false !== strpos( $html, 'categoria=' . $t_cat ), 'events: category options still rendered' );
assert_true( false !== strpos( $html, 'data-event-filters' ), 'events: filter widget root still rendered' );
assert_true( false !== strpos( $html, 'role="status"' ), 'events: the result-count live region (role="status") is untouched' );

// The client-side option search + scoping hooks must survive.
assert_true( false !== strpos( $html, 'data-option-item' ), 'events: option items keep their data-option-item JS hook' );
assert_true( false !== strpos( $html, 'data-dropdown-trigger' ), 'events: dropdown triggers keep their data-dropdown-trigger JS hook' );

$html = a11y_test_render( 'event-filters.php', array( 'county' => $t_county ) );
$info = a11y_assert_option_semantics( $html, 'event-filters', 'events active' );
assert_true( $info['current'] >= 1, 'events: the selected county renders as aria-current' );
assert_true( false !== strpos( $html, 'Filtros ativos' ), 'events: active-filter chips still rendered when a filter is applied' );

$url = conexao_event_filter_url( array( 'county' => $t_county, 'cidade' => $t_town ) );
assert_true(
	false !== strpos( $url, 'county=' . $t_county ) && false !== strpos( $url, 'cidade=' . $t_town ),
	'events: filter URL still AND-combines county + cidade (query contract unchanged)'
);

// ---------------------------------------------------------------------------
// 3. Employment filters (/empregos/).
// ---------------------------------------------------------------------------

test_section( 'Employment filters: options are links, not listbox options' );

// The employment widget is a template part that reads page-level query state.
$html = a11y_test_render( 'employment-opportunities.php', array() );

$info = a11y_assert_option_semantics( $html, 'agency-filters', 'employment' );
assert_true( false !== strpos( $html, '?tipo=agency' ), 'employment: tipo option URLs unchanged' );
assert_true( false !== strpos( $html, 'empregos-opportunities-title' ), 'employment: the #empregos-opportunities-title anchor is preserved on filter URLs' );
assert_true( false !== strpos( $html, 'Oportunidades de emprego' ), 'employment: the directory heading is unchanged' );

$html = a11y_test_render( 'employment-opportunities.php', array( 'tipo' => 'agency' ) );
$info = a11y_assert_option_semantics( $html, 'agency-filters', 'employment active' );
assert_true( 1 === $info['current'], 'employment: exactly the selected tipo is aria-current (got ' . $info['current'] . ')' );
assert_true( false !== strpos( $html, 'Filtros ativos' ), 'employment: active-filter chip row still rendered' );


test_section( 'Cross-widget invariants' );

foreach ( array( 'leisure-filters.php' => 'leisure', 'event-filters.php' => 'event-filters' ) as $tpl => $prefix ) {
	$html = a11y_test_render( $tpl, array() );
	// The option groups keep an accessible name even without the listbox role.
	preg_match_all( '/<div class="' . preg_quote( $prefix, '/' ) . '-dropdown-list"[^>]*>/', $html, $m );
	assert_true( count( $m[0] ) > 0, $prefix . ': the named option group is still rendered' );
	$unnamed = 0;
	foreach ( $m[0] as $tag ) {
		if ( false === strpos( $tag, 'aria-label=' ) ) {
			++$unnamed;
		}
	}
	assert_true( 0 === $unnamed, $prefix . ': every option group keeps its aria-label (not lost with the listbox role)' );
}

// Clean up before asserting, so the leftover check is meaningful.
a11y_test_cleanup();

$leftover = get_posts(
	array(
		'post_type'   => array( 'leisure', 'event' ),
		'post_status' => 'any',
		's'           => 'A11y',
		'numberposts' => 5,
		'fields'      => 'ids',
	)
);
assert_true( empty( $leftover ), 'no temporary test posts remain after cleanup' );

$leftover_terms = array();
foreach ( array( 'conexao_county', 'conexao_town', 'conexao_category', 'conexao_leisure_attribute' ) as $tax ) {
	$found = get_terms(
		array(
			'taxonomy'   => $tax,
			'hide_empty' => false,
			'slug'       => array( 'a11y-county', 'a11y-town', 'a11y-category', 'a11y-category-two', 'a11y-attribute' ),
		)
	);
	if ( ! is_wp_error( $found ) ) {
		$leftover_terms = array_merge( $leftover_terms, $found );
	}
}
assert_true( empty( $leftover_terms ), 'no temporary test terms remain after cleanup' );

test_finish();
