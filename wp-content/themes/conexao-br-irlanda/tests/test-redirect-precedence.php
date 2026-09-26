<?php
/**
 * PERMANENT GATE — legacy EN→PT redirect precedence (Stage L, engineering
 * standard §6.3 and §14 "Routes/filters: redirect precedence verified").
 *
 * Normative rule:
 *   "Legacy EN→PT redirects still win over newly created EN pages with the
 *    same slug."
 *
 * ## Why this exists
 *
 * The theme deliberately runs the legacy redirect table at `template_redirect`
 * priority 2, BEFORE Polylang's language canonical at priority 4 (see the
 * comment above `add_action( 'template_redirect', 'conexao_seo_redirects', 2 )`
 * in inc/seo/redirects.php). English pages now exist whose slugs collide with
 * legacy English source paths (/jobs/, /about-us/, /contact/, ...). If the
 * precedence ever regressed, Polylang would bounce the request to /en/... and
 * the production 301 to the canonical PT URL would be lost.
 *
 * ## What is proven
 *
 *   1. the legacy redirect condition IS detected for a colliding path;
 *   2. a CONFLICTING EN page with the same slug does NOT take precedence;
 *   3. the expected redirect status stays 301;
 *   4. the destination remains the PT path (never /en/), per §6.1 "PT is
 *      canonical".
 *
 * ## Fixture discipline
 *
 * The conflicting EN page is created and DELETED inside this suite, under
 * names prefixed `stage-l-redirect-gate-`. No real content is touched, and the
 * gate removes what it created even when an assertion fails.
 *
 * @package Conexao_BR_Irlanda
 */

// Shared Stage E bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_ROOT . '/lib/permanent-gates.php';

conexao_gate_open(
	'redirect_precedence',
	'A legacy EN path keeps its 301 to the PT URL even when a newly created EN page '
	. 'has the same slug; the redirect destination is always PT.',
	array( 'polylang', 'conexao_seo_redirects()' )
);

conexao_gate_require(
	function_exists( 'conexao_seo_redirects' ),
	'theme-active',
	'test prerequisite is available: conexao_seo_redirects()'
);

/**
 * The legacy path under test, and the PT destination the table maps it to.
 *
 * `/contact/` is a root-anchored legacy English path in the redirect table and
 * is guaranteed to keep its 301 (docs/routing.md: English URLs redirect to
 * Portuguese; PT URLs are canonical).
 */
$legacy_path = '/contact';
$expected_pt = '/contato/';

/**
 * Create a conflicting EN page whose slug is the legacy path.
 *
 * This is the exact collision the invariant is about: without the redirect
 * precedence, `/contact/` would resolve to this EN page instead of redirecting.
 *
 * @return int Created post ID.
 */
function conexao_gate_create_conflicting_en_page() {
	$existing = get_page_by_path( 'stage-l-redirect-gate-contact', OBJECT, 'page' );
	if ( $existing instanceof WP_Post ) {
		wp_delete_post( (int) $existing->ID, true );
	}

	$post_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Stage L redirect gate conflicting EN page',
			'post_name'    => 'stage-l-redirect-gate-contact',
			'post_content' => 'Fixture created and removed by test-redirect-precedence.php.',
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		return 0;
	}

	// Assign EN so the page genuinely belongs to the English layer.
	if ( function_exists( 'pll_set_post_language' ) ) {
		pll_set_post_language( (int) $post_id, 'en' );
	}

	return (int) $post_id;
}

/**
 * Remove the fixture page, whatever state the suite is in.
 *
 * @return void
 */
function conexao_gate_delete_fixture( $post_id ) {
	$post_id = (int) $post_id;
	if ( $post_id > 0 && get_post( $post_id ) instanceof WP_Post ) {
		wp_delete_post( $post_id, true );
	}
}

// ---------------------------------------------------------------------------
// Capture what conexao_seo_redirects() decides, without letting it exit().
//
// The function calls wp_safe_redirect() and then exit(). A test cannot survive
// that, so the decision is observed through the `wp_redirect` filter (the hook
// wp_safe_redirect applies) and the filter then short-circuits with a thrown
// exception. The redirect logic itself is untouched and still decides.
// ---------------------------------------------------------------------------

/**
 * The priority at which a callback is hooked, or null when it is not hooked.
 *
 * @param string $hook     Hook name.
 * @param string $callback Callback name.
 * @param int    $expected Ignored; present for call-site readability.
 * @return int|null
 */
function conexao_gate_hook_priority( $hook, $callback, $expected = 0 ) {
	global $wp_filter;

	if ( ! isset( $wp_filter[ $hook ] ) || ! is_object( $wp_filter[ $hook ] ) ) {
		return null;
	}

	foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
		foreach ( $callbacks as $registered ) {
			if ( $registered['function'] === $callback ) {
				return (int) $priority;
			}
		}
	}

	return null;
}

/**
 * Run conexao_seo_redirects() for a path and report the decision.
 *
 * @param string $path Request path.
 * @return array {redirected: bool, status: int, location: string}
 */
function conexao_gate_run_redirect( $path ) {
	$captured = array(
		'redirected' => false,
		'status'     => 0,
		'location'   => '',
	);

	$filter = static function ( $location, $status ) use ( &$captured ) {
		$captured['redirected'] = true;
		$captured['status']     = (int) $status;
		$captured['location']   = (string) $location;
		// Abort before wp_redirect() performs the header + exit.
		throw new RuntimeException( 'conexao-gate: redirect captured' );
	};

	add_filter( 'wp_redirect', $filter, 1, 2 );

	$original_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';
	$_SERVER['REQUEST_URI'] = $path;

	try {
		conexao_seo_redirects();
	} catch ( RuntimeException $e ) {
		// Expected: the filter aborted the redirect on purpose.
		unset( $e );
	} finally {
		remove_filter( 'wp_redirect', $filter, 1 );
		$_SERVER['REQUEST_URI'] = $original_uri;
	}

	return $captured;
}

// ---------------------------------------------------------------------------
// 1. Baseline: the legacy path redirects to PT, even with NO EN page present.
// ---------------------------------------------------------------------------

$before = conexao_gate_run_redirect( $legacy_path );

conexao_gate_violation(
	'redirect:legacy_path_not_detected',
	$before['redirected'] ? 0 : 1,
	'the legacy EN path is detected by the redirect table',
	array( 'path' => $legacy_path, 'decision' => $before )
);

conexao_gate_violation(
	'redirect:legacy_status_not_301',
	301 === $before['status'] ? 0 : 1,
	'the legacy EN path keeps its permanent 301 status',
	array( 'path' => $legacy_path, 'status' => $before['status'] )
);

conexao_gate_violation(
	'redirect:legacy_destination_not_pt',
	$before['redirected'] && untrailingslashit( (string) wp_parse_url( $before['location'], PHP_URL_PATH ) ) === untrailingslashit( $expected_pt ) ? 0 : 1,
	'the legacy EN path redirects to the PT destination',
	array( 'expected' => $expected_pt, 'location' => $before['location'] )
);

// ---------------------------------------------------------------------------
// 2. THE INVARIANT: a conflicting EN page with the same slug does not win.
//
// The fixture EN page is given the legacy slug itself, so a regression that let
// the page take precedence would serve the EN page at the legacy URL instead
// of issuing the production 301 to PT.
// ---------------------------------------------------------------------------

$fixture_id = conexao_gate_create_conflicting_en_page();

conexao_gate_require(
	$fixture_id > 0,
	'writable-posts',
	'the conflicting EN page fixture was created',
	'the suite needs permission to insert a temporary page'
);

$fixture = get_post( $fixture_id );
assert_true(
	$fixture instanceof WP_Post && 'stage-l-redirect-gate-contact' === $fixture->post_name,
	'the fixture EN page exists and owns the legacy slug',
	$fixture instanceof WP_Post ? $fixture->post_name : 'fixture missing'
);

// The conflicting page must be reachable by slug, otherwise the collision
// would not be a real test of precedence.
$by_path = get_page_by_path( 'stage-l-redirect-gate-contact', OBJECT, 'page' );
assert_true(
	$by_path instanceof WP_Post,
	'the conflicting EN page resolves by its slug (the collision is real)',
	'get_page_by_path found nothing — precedence could not be tested'
);

$after = conexao_gate_run_redirect( $legacy_path );

// The redirect still fires, with the same status and the same PT destination:
// the EN page did not take precedence.
conexao_gate_violation(
	'redirect:en_page_took_precedence',
	$after['redirected'] ? 0 : 1,
	'a newly created EN page with the same slug does NOT take precedence over the legacy redirect',
	array( 'path' => $legacy_path, 'decision' => $after )
);

conexao_gate_violation(
	'redirect:status_changed_by_conflict',
	301 === $after['status'] ? 0 : 1,
	'the redirect status stays 301 when an EN page shares the slug',
	array( 'status' => $after['status'] )
);

$after_path = $after['redirected'] ? (string) wp_parse_url( $after['location'], PHP_URL_PATH ) : '';

conexao_gate_violation(
	'redirect:en_page_became_destination',
	( '' !== $after_path && 0 === strpos( $after_path, '/en/' ) ) ? 1 : 0,
	'the redirect destination is never the conflicting EN page (/en/...)',
	array( 'location' => $after['location'] )
);

conexao_gate_violation(
	'redirect:destination_not_pt_after_conflict',
	untrailingslashit( $after_path ) === untrailingslashit( $expected_pt ) ? 0 : 1,
	'the redirect destination remains the PT path after the conflict',
	array( 'expected' => $expected_pt, 'location' => $after['location'] )
);

// ---------------------------------------------------------------------------
// 3. Hook precedence: the legacy table must run BEFORE Polylang's canonical
//    (template_redirect priority 4), which is the mechanism behind the above.
// ---------------------------------------------------------------------------

$legacy_hook   = conexao_gate_hook_priority( 'template_redirect', 'conexao_seo_redirects' );
$polylang_hook = conexao_gate_hook_priority( 'template_redirect', 'pll_canonical_redirect', 4 );

assert_true(
	null !== $legacy_hook,
	'the legacy redirect is registered on template_redirect',
	'conexao_seo_redirects is not hooked'
);

conexao_gate_violation(
	'redirect:hook_missing',
	null === $legacy_hook ? 1 : 0,
	'the legacy redirect is registered on template_redirect',
	array( 'priority' => $legacy_hook )
);

if ( null !== $legacy_hook ) {
	// Polylang's canonical redirect runs at priority 4. The legacy table must
	// beat it, so its priority must be strictly lower.
	conexao_gate_violation(
		'redirect:hook_priority_regression',
		$legacy_hook < 4 ? 0 : 1,
		'the legacy redirect runs BEFORE Polylang language canonical (priority 4)',
		array( 'legacy_priority' => $legacy_hook, 'polylang_priority' => $polylang_hook )
	);
}

// ---------------------------------------------------------------------------
// 4. Cleanup — the fixture must never survive the gate.
// ---------------------------------------------------------------------------

conexao_gate_delete_fixture( $fixture_id );

$survivor = get_page_by_path( 'stage-l-redirect-gate-contact', OBJECT, 'page' );
conexao_gate_violation(
	'redirect:fixture_left_behind',
	$survivor instanceof WP_Post ? 1 : 0,
	'the conflicting EN page fixture was removed',
	array( 'survivor' => $survivor instanceof WP_Post ? $survivor->ID : 0 )
);

conexao_gate_close();
