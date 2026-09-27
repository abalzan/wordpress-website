<?php
/**
 * PERMANENT GATE — language-scoped caching, runtime half (Stage L,
 * engineering standard §6.1 and §6.3).
 *
 * Normative rules:
 *   - "Cache keys are language-scoped (`conexao_lang_cache_key()`) and
 *      invalidation clears all languages" (§6.1).
 *   - "Language-scoped caches: no unscoped `conexao_*` transient/object-cache
 *      key" (§6.3).
 *
 * ## Two halves, one invariant
 *
 *   A. STATIC  — `tests/scripts/verify-cache-key-scoping.py` scans the actual
 *      repository source for unscoped `conexao_*` cache keys. It is a token
 *      scan, not a grep, so comments, docs and already-scoped calls do not
 *      produce false positives.
 *   B. RUNTIME — this file proves the mechanism actually behaves correctly at
 *      runtime: PT and EN keys differ, writes do not collide, and
 *      `conexao_flush_language_cache()` clears every language variant.
 *
 * The static half is a separate suite because it needs no WordPress; the
 * runtime half needs a real WordPress. Both are permanent and both are
 * discovered by the default runner.
 *
 * This file CHANGES NO CACHING BEHAVIOUR. It only observes it.
 *
 * @package Conexao_BR_Irlanda
 */

// Shared Stage E bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_ROOT . '/lib/permanent-gates.php';

conexao_gate_open(
	'cache_scoping',
	'PT and EN must never share a conexao_* cache entry: language-scoped keys differ per language '
	. 'and invalidation clears every language variant.',
	array( 'polylang', 'conexao_lang_cache_key() + conexao_flush_language_cache()' )
);

conexao_gate_require(
	function_exists( 'conexao_lang_cache_key' ),
	'theme-active',
	'test prerequisite is available: conexao_lang_cache_key()'
);
conexao_gate_require(
	function_exists( 'conexao_polylang_active' ) && conexao_polylang_active(),
	'polylang',
	'test prerequisite is available: Polylang is active with pt + en'
);

$languages = (array) pll_languages_list( array( 'fields' => 'slug' ) );
conexao_gate_require(
	count( array_intersect( array( 'pt', 'en' ), $languages ) ) === 2,
	'polylang',
	'test prerequisite is available: both pt and en languages are configured',
	'configure the pt and en languages in Polylang'
);

echo "\n  languages: " . implode( ', ', $languages ) . "\n";

// ---------------------------------------------------------------------------
// 1. conexao_lang_cache_key() produces a DIFFERENT key per language.
//
// The language context is switched through Polylang's own language objects
// (`PLL()->curlang`, which pll_current_language() reads), not by faking a
// global: conexao_language_suffix() reads conexao_current_language_slug(),
// which is the real request-language path. The prior value is always restored,
// so the gate leaves no state behind.
// ---------------------------------------------------------------------------

$probe_key = 'conexao_gate_probe';
$keys      = array();
$original  = PLL()->curlang;

/** @var WP_Syntex\Polylang\Model\Language $language */
foreach ( PLL()->model->languages->get_list() as $language ) {
	$slug = (string) $language->get_prop( 'slug' );

	PLL()->curlang = $language;
	$keys[ $slug ] = conexao_lang_cache_key( $probe_key );
}
PLL()->curlang = $original;

echo '  generated keys: ' . wp_json_encode( $keys ) . "\n";

conexao_gate_violation(
	'cache:lang_cache_key_not_language_scoped',
	count( array_unique( $keys ) ) === count( $keys ) ? 0 : 1,
	'conexao_lang_cache_key() returns a distinct key for every language',
	array( 'keys' => $keys )
);

// Every key must still start with the base key (a scoped key is the base key
// plus a language suffix, never a different key space).
$not_prefixed = array();
foreach ( $keys as $slug => $key ) {
	if ( 0 !== strpos( (string) $key, $probe_key ) ) {
		$not_prefixed[] = "{$slug}={$key}";
	}
}
conexao_gate_violation(
	'cache:lang_cache_key_base_changed',
	count( $not_prefixed ),
	'conexao_lang_cache_key() keeps the base key as a prefix',
	array( 'keys' => $not_prefixed )
);

// ---------------------------------------------------------------------------
// 2. Representative PT and EN entries do not COLLIDE at runtime.
//
// A transient is written under the PT-scoped key and read back under the
// EN-scoped key. If the scoping works, the EN read must NOT see the PT value.
// This is the behavioural half of the invariant: it proves two languages can
// never serve each other's cached data.
// ---------------------------------------------------------------------------

$runtime_base = 'conexao_gate_runtime_probe';

/** Build a slug => language-object map; PLL()->curlang holds the object. */
$language_objects = array();
foreach ( PLL()->model->languages->get_list() as $language ) {
	$language_objects[ (string) $language->get_prop( 'slug' ) ] = $language;
}

$write_key = null;
$read_key  = null;

PLL()->curlang = $language_objects['pt'];
$write_key = conexao_lang_cache_key( $runtime_base );
set_transient( $write_key, 'pt-value', 60 );

PLL()->curlang = $language_objects['en'];
$read_key = conexao_lang_cache_key( $runtime_base );
$seen     = get_transient( $read_key );

PLL()->curlang = $original;

$collided = ( 'pt-value' === $seen );

conexao_gate_violation(
	'cache:pt_en_transient_collision',
	$collided ? 1 : 0,
	'an EN-scoped read never returns the PT-scoped cached value',
	array( 'pt_key' => $write_key, 'en_key' => $read_key, 'en_saw' => $seen )
);

// The PT value must still be readable under its OWN key (the probe proves the
// cache works, not that the write silently failed).
$pt_still_there = get_transient( $write_key );
conexao_gate_violation(
	'cache:probe_write_lost',
	'pt-value' === $pt_still_there ? 0 : 1,
	'the PT-scoped entry is readable under its own key',
	array( 'pt_key' => $write_key, 'value' => $pt_still_there )
);

// ---------------------------------------------------------------------------
// 3. Invalidation clears EVERY language variant (§6.1: "invalidation clears
//    all languages"). Each language variant is seeded, then one flush call must
//    clear all of them.
// ---------------------------------------------------------------------------

$flush_base = 'conexao_gate_flush_probe';
$seeded     = array();

foreach ( $languages as $slug ) {
	PLL()->curlang = $language_objects[ $slug ];
	$seeded[ $slug ] = conexao_lang_cache_key( $flush_base );
	set_transient( $seeded[ $slug ], $slug . '-value', 60 );
}
PLL()->curlang = $original;

// The un-suffixed key is seeded too: conexao_flush_language_cache() documents
// that it clears it as well, covering pre-Polylang write paths.
set_transient( $flush_base, 'base-value', 60 );

conexao_flush_language_cache( $flush_base );

$survivors = array();
foreach ( $seeded as $slug => $key ) {
	if ( false !== get_transient( $key ) ) {
		$survivors[] = "{$slug}:{$key}";
	}
}
if ( false !== get_transient( $flush_base ) ) {
	$survivors[] = "base:{$flush_base}";
}
PLL()->curlang = $original;

conexao_gate_violation(
	'cache:flush_left_language_variants',
	count( $survivors ),
	'conexao_flush_language_cache() clears every language variant and the base key',
	array( 'survivors' => $survivors, 'seeded' => $seeded )
);

// ---------------------------------------------------------------------------
// 4. Cleanup: the gate must leave no cache entry behind.
// ---------------------------------------------------------------------------

delete_transient( $write_key );
delete_transient( $read_key );
foreach ( $seeded as $key ) {
	delete_transient( $key );
}
delete_transient( $flush_base );

$leftovers = array();
foreach ( array_merge( array( $write_key, $read_key, $flush_base ), array_values( $seeded ) ) as $key ) {
	if ( false !== get_transient( $key ) ) {
		$leftovers[] = $key;
	}
}

conexao_gate_violation(
	'cache:probe_entries_left_behind',
	count( $leftovers ),
	'the gate removed every transient it created',
	array( 'leftovers' => $leftovers )
);

conexao_gate_close();
