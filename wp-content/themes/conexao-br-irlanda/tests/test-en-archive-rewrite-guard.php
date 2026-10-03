<?php
/**
 * Tests for the `/en/` post-type archive rewrite self-heal.
 *
 * ## The regression this locks down
 *
 * `/en/guias/`, `/en/eventos/` and `/en/apoiadores/` all returned HTTP 404 while
 * their Portuguese counterparts served 200. The repository's routing code was
 * provably correct and byte-identical to the verified baseline `b47d098`, where
 * the same three URLs served 200 — so the fault was the PERSISTED rule set, not
 * the registration code.
 *
 * Polylang builds the `/en/<archive>/` rules by filtering the PER-POST-TYPE
 * `{$post_type}_rewrite_rules` sets, and attaches those filters on `wp_loaded`
 * at priority 9. Any `flush_rewrite_rules()` that runs BEFORE that point
 * persists a rule set with no `(en)/` archive rules at all: the Portuguese
 * archives keep working and every English archive 404s.
 *
 * `conexao_polylang_rewrite_rules_ensure()` (inc/i18n/guard.php) repairs that
 * state. These assertions prove the guard's contract WITHOUT ever mutating the
 * live option: the rule detection runs read-only against a synthetic rule set,
 * so the suite is safe to run anywhere.
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

/**
 * Does this rule set carry BOTH the language-prefixed archive variant and the
 * default-language (lang=) variant for a post type's archive?
 *
 * This mirrors the detection inside
 * conexao_polylang_rewrite_rules_ensure() exactly. It is factored out here so
 * the suite can run the SAME predicate against a synthetic rule set (the
 * reported 404 state) and against the live option, without ever writing the
 * option — the suite must be safe to run on any environment.
 *
 * @param array  $rules     Rule set to inspect.
 * @param string $post_type Post type name.
 * @return array{0:bool,1:bool} [ has_language_variant, has_default_variant ].
 */
function rr_has_archive_variants( array $rules, $post_type ) {
	$object = get_post_type_object( $post_type );

	if ( ! $object ) {
		return array( false, false );
	}

	$slug = is_array( $object->rewrite ) && ! empty( $object->rewrite['slug'] )
		? $object->rewrite['slug']
		: $post_type;

	$has_language_variant = false;
	$has_default_variant  = false;

	foreach ( $rules as $regex => $query ) {
		$regex = (string) $regex;
		$query = (string) $query;

		if ( false === strpos( $regex, '/' . $slug ) ) {
			continue;
		}

		if ( false === strpos( $query, 'post_type=' . $post_type ) ) {
			continue;
		}

		if ( false !== strpos( $regex, ')/' ) ) {
			$has_language_variant = true;
		}

		if ( false !== strpos( $query, 'lang=' ) ) {
			$has_default_variant = true;
		}
	}

	return array( $has_language_variant, $has_default_variant );
}

// Data prerequisite: this suite is about Polylang's language layer, so an active
// bilingual Polylang with a pretty permalink structure is required.
test_require(
	conexao_polylang_active(),
	'polylang-pt-en',
	'Polylang is active with its public API available',
	'docker compose up -d && php scripts/run-polylang-setup.php'
);

test_require(
	'' !== (string) get_option( 'permalink_structure' ),
	'pretty-permalinks',
	'a pretty permalink structure is configured (the rule set is meaningful)',
	'Settings -> Permalinks'
);

test_title( 'EN archive post types are derived from the declared translation policy' );

$archive_types = conexao_polylang_language_archive_post_types();

assert_true(
	is_array( $archive_types ),
	'conexao_polylang_language_archive_post_types() returns an array'
);

// Derived, never hand-maintained: exactly the translated post types that have a
// public archive. The `job` CPT is deliberately absent because its archive is
// disabled (has_archive = false) — the jobs landing is a page.
foreach ( array( 'guide', 'event', 'sponsor' ) as $expected ) {
	assert_true(
		in_array( $expected, $archive_types, true ),
		sprintf( 'the %s archive is protected by the rewrite self-heal', $expected )
	);
}

$job_object = get_post_type_object( 'job' );

assert_true(
	! empty( $job_object ) && empty( $job_object->has_archive ),
	'the job CPT still has no archive (its /empregos/ landing is a page)'
);
assert_true(
	! in_array( 'job', $archive_types, true ),
	'a post type without a public archive is not expected to have an /en/ archive rule'
);

test_section( 'The guard is registered after Polylang prepares its rewrite filters' );

// Polylang's prepare step is `wp_loaded` priority 9. The guard must run strictly
// after it, otherwise a regenerated rule set would again lack the EN rules.
$guard_priority = has_action( 'wp_loaded', 'conexao_polylang_rewrite_rules_ensure', 20 );

assert_true(
	false !== $guard_priority,
	'conexao_polylang_rewrite_rules_ensure() is hooked on wp_loaded'
);
assert_true(
	20 > 9,
	"the guard priority (20) is strictly after Polylang's rewrite prepare step (9)"
);

test_section( 'A complete rule set is detected as complete (no repair needed)' );

$complete = array(
	'guias/?$'           => 'index.php?lang=pt&post_type=guide',
	'(en)/guias/?$'      => 'index.php?lang=$matches[1]&post_type=guide',
	'eventos/?$'         => 'index.php?lang=pt&post_type=event',
	'(en)/eventos/?$'    => 'index.php?lang=$matches[1]&post_type=event',
	'apoiadores/?$'      => 'index.php?lang=pt&post_type=sponsor',
	'(en)/apoiadores/?$' => 'index.php?lang=$matches[1]&post_type=sponsor',
);

$incomplete = array();

foreach ( array( 'guide', 'event', 'sponsor' ) as $post_type ) {
	list( $has_language, $has_default ) = rr_has_archive_variants( $complete, $post_type );

	if ( ! ( $has_language && $has_default ) ) {
		$incomplete[] = $post_type;
	}
}

assert_true(
	empty( $incomplete ),
	'a rule set carrying both the (en)/ variant and the lang= PT variant is treated as complete',
	'incomplete for: ' . implode( ', ', $incomplete )
);

test_section( 'The reported 404 state is detected as incomplete (repair required)' );

// This is the exact shape of the persisted rule set that produced the three
// 404s: every Portuguese archive rule is present and marked `lang=pt`, and not
// one `(en)/` archive rule exists.
$stale = array(
	'guias/?$'      => 'index.php?lang=pt&post_type=guide',
	'eventos/?$'    => 'index.php?lang=pt&post_type=event',
	'apoiadores/?$' => 'index.php?lang=pt&post_type=sponsor',
	'cursos/?$'     => 'index.php?lang=pt&post_type=course_provider',
	'lazer/?$'      => 'index.php?lang=pt&post_type=leisure',
);

$detected = array();

foreach ( array( 'guide', 'event', 'sponsor' ) as $post_type ) {
	list( $has_language ) = rr_has_archive_variants( $stale, $post_type );

	if ( ! $has_language ) {
		$detected[] = $post_type;
	}
}

assert_true(
	3 === count( $detected ),
	'every EN archive missing from the rule set is detected (3 expected)',
	'detected: ' . implode( ', ', $detected )
);

foreach ( array( 'guide' => 'guias', 'event' => 'eventos', 'sponsor' => 'apoiadores' ) as $post_type => $slug ) {
	assert_true(
		in_array( $post_type, $detected, true ),
		sprintf( 'the stale rule set is detected as missing the /en/%s/ archive rule', $slug )
	);
}

test_section( 'The live persisted rule set is complete (no live 404 state)' );

// Anti-vacuity: the assertions above exercise synthetic arrays. This one reads
// the REAL option, so the suite fails if the live site is in the 404 state.
$live_rules = get_option( 'rewrite_rules' );

test_require(
	is_array( $live_rules ) && ! empty( $live_rules ),
	'flushed-rewrite-rules',
	'the live rewrite_rules option is a populated array',
	'Visit the site once so WordPress flushes the rule set'
);

$live_missing = array();

foreach ( $archive_types as $post_type ) {
	$object = get_post_type_object( $post_type );
	$slug   = is_array( $object->rewrite ) && ! empty( $object->rewrite['slug'] ) ? $object->rewrite['slug'] : $post_type;

	list( $has_language ) = rr_has_archive_variants( $live_rules, $post_type );

	if ( ! $has_language ) {
		$live_missing[] = $slug;
	}
}

assert_true(
	empty( $live_missing ),
	'every bilingual archive has its live /en/ rewrite rule persisted',
	'missing: ' . implode( ', ', $live_missing )
);

test_finish( 'test-en-archive-rewrite-guard' );
