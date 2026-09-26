<?php
/**
 * EN primary-navigation LANGUAGE-CONTEXT logic test — runnable WITHOUT WordPress.
 *
 * Companion to tests/test-nav-language-context.php (live WP/DB) and to
 * scripts/nav-regression-http-verify.py (running server).
 *
 * The regression fixed in CONEXAO_BR_EN_NAV_LANGUAGE_CONTEXT_FIX_REPORT.md was a
 * render-time URL-resolution bug: the EN "Jobs" item resolved to the Portuguese
 * /empregos/ because the Jobs section was modelled as a CPT archive while the
 * `job` CPT is registered with has_archive = false, so the archive URL was empty
 * and the code fell back to the Portuguese path. Nothing about that bug needs a
 * database to reproduce — it is pure URL resolution.
 *
 * This script EXTRACTS the real navigation functions from inc/navigation.php
 * and inc/i18n/urls.php (Stage F modules cut from the former functions.php and
 * inc/polylang.php monoliths) with token_get_all, and executes them unmodified
 * against a small
 * stubbed WordPress/Polylang layer emulating a bilingual site (PT + EN Polylang
 * directory mode) with:
 *   - the `empregos` PT page linked to the `jobs` EN translation (/en/jobs/),
 *   - CPT archives for guide/event/leisure/sponsor/course_provider,
 *   - `job` has_archive = false (the root cause),
 *   - the posts page (`page_for_posts`) serving /blog/ with no EN translation
 *     (approved B2: the EN Blog URL is /en/blog/, no redirect to /blog/).
 *
 * Usage (from the project root):
 *   php wp-content/themes/conexao-br-irlanda/tests/test-nav-language-context-logic.php
 *
 * Read-only. No WordPress, no database, no network.
 *
 * @package conexao-br-irlanda
 */

// This suite is deliberately STANDALONE (no WordPress, no database, no
// network): it parses theme source with token_get_all() and provides its own
// WordPress function stubs below. It therefore does NOT load tests/bootstrap.php
// — doing so would redeclare those stubs. It still uses the shared assertion
// API via the library alone, which is the only permitted exception to the
// "one bootstrap" rule (engineering standard §8.2 lists this suite as the
// standalone/static layer).
define( 'CONEXAO_TESTS_ROOT', dirname( __DIR__, 4 ) . '/tests' );
require_once CONEXAO_TESTS_ROOT . '/lib/assertions.php';

error_reporting( E_ALL );

$GLOBALS['conexao_test_passed'] = 0;
$GLOBALS['conexao_test_failed'] = 0;

/**
 * Extract every TOP-LEVEL named function definition from a PHP source file.
 *
 * @param string $source PHP source.
 * @return array<string,string> name => code.
 */
function conexao_test_extract_functions( string $source ): array {
	$tokens = token_get_all( $source );
	$count  = count( $tokens );
	$funcs  = array();
	$depth  = 0;

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( is_array( $token ) && T_FUNCTION === $token[0] && 0 === $depth ) {
			$j = $i + 1;
			while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
				++$j;
			}
			if ( ! is_array( $tokens[ $j ] ) || T_STRING !== $tokens[ $j ][0] ) {
				continue; // anonymous closure
			}
			$name = $tokens[ $j ][1];

			$k       = $j;
			$brace   = 0;
			$started = false;
			$end     = null;
			for ( ; $k < $count; $k++ ) {
				$c = is_array( $tokens[ $k ] ) ? $tokens[ $k ][1] : $tokens[ $k ];
				if ( '{' === $c ) {
					$started = true;
					++$brace;
				} elseif ( '}' === $c ) {
					--$brace;
					if ( $started && 0 === $brace ) {
						$end = $k;
						break;
					}
				}
			}
			if ( null === $end ) {
				continue;
			}

			$code = '';
			for ( $x = $i; $x <= $end; $x++ ) {
				$code .= is_array( $tokens[ $x ] ) ? $tokens[ $x ][1] : $tokens[ $x ];
			}
			$funcs[ $name ] = $code;
			$i              = $end;
			continue;
		}

		$c = is_array( $token ) ? $token[1] : $token;
		if ( '{' === $c ) {
			++$depth;
		} elseif ( '}' === $c ) {
			--$depth;
		}
	}

	return $funcs;
}

$theme_dir = dirname( __DIR__ );
$extracted = array();
// Stage F: the navigation language-context logic now lives in the focused
// modules cut from the old functions.php / inc/polylang.php monoliths. The
// functions under test are still extracted from the real theme SOURCE with
// token_get_all and executed unmodified - only the file paths changed.
//
//   inc/navigation.php  -> conexao_primary_nav_sections, conexao_bind_section_object,
//                          conexao_modify_primary_nav_items
//   inc/i18n/*          -> the whole language-policy set the nav code calls
//                          (conexao_lang_url, conexao_language_archive_url,
//                          conexao_current_language_slug,
//                          conexao_default_language_slug, conexao_lang_term,
//                          conexao_lang_cache_key, conexao_polylang_active, ...)
//   inc/queries.php     -> conexao_get_guides_archive_url
//
// inc/i18n/ is included as a set because its modules call each other (urls ->
// locale -> guard), and the stubs below only cover WordPress/Polylang. Only
// functions (never the module-level add_filter/add_action calls) are
// extracted, so no hook is registered by this suite.
$nav_sources = array(
	$theme_dir . '/inc/navigation.php',
	$theme_dir . '/inc/i18n/guard.php',
	$theme_dir . '/inc/i18n/locale.php',
	$theme_dir . '/inc/i18n/urls.php',
	$theme_dir . '/inc/i18n/terms.php',
	$theme_dir . '/inc/i18n/fallback.php',
	$theme_dir . '/inc/i18n/hreflang.php',
	$theme_dir . '/inc/i18n/switcher.php',
	$theme_dir . '/inc/queries.php',
);
foreach ( $nav_sources as $source ) {
	$raw = file_get_contents( $source );
	if ( false === $raw ) {
		fwrite( STDERR, "Cannot read {$source}\n" );
		exit( 2 );
	}
	foreach ( conexao_test_extract_functions( $raw ) as $name => $code ) {
		$extracted[ $name ] = $code;
	}
}

if ( ! isset( $extracted['conexao_primary_nav_sections'], $extracted['conexao_bind_section_object'], $extracted['conexao_lang_url'] ) ) {
	fwrite( STDERR, "Failed to extract the navigation functions.\n" );
	exit( 2 );
}

// ---------------------------------------------------------------------------
// Stubbed WordPress + Polylang layer and the bilingual site model.
// ---------------------------------------------------------------------------
define( 'OBJECT', 1 );

$GLOBALS['conexao_test_lang']  = 'pt';
$GLOBALS['conexao_test_base']  = 'http://example.test';
$GLOBALS['conexao_test_homes'] = array(
	'pt' => 'http://example.test/',
	'en' => 'http://example.test/en/',
);

// PT page => EN linked translation. Mirrors scripts/stage32-translate-pages.php.
$GLOBALS['conexao_test_pages'] = array(
	'inicio'    => array( 'id' => 10,  'url' => 'http://example.test/',           'en' => 1290, 'en_url' => 'http://example.test/en/' ),
	'empregos'  => array( 'id' => 113, 'url' => 'http://example.test/empregos/',  'en' => 1130, 'en_url' => 'http://example.test/en/jobs/' ),
	'contato'   => array( 'id' => 20,  'url' => 'http://example.test/contato/',   'en' => 200,  'en_url' => 'http://example.test/en/contact/' ),
	'sobre-nos' => array( 'id' => 21,  'url' => 'http://example.test/sobre-nos/', 'en' => 210,  'en_url' => 'http://example.test/en/about-us/' ),
	'irlanda'   => array( 'id' => 30,  'url' => 'http://example.test/irlanda/',   'en' => 0,    'en_url' => '' ), // B2, no linked EN page.
	// Posts page (page_for_posts). The native WordPress posts archive /blog/
	// is served by this page; it has no EN translation, so the EN Blog URL is
	// the language home + its path (/en/blog/) — the approved B2 destination.
	'blog'      => array( 'id' => 50,  'url' => 'http://example.test/blog/',      'en' => 0,    'en_url' => '' ),
);

$GLOBALS['conexao_test_post_types'] = array(
	'guide'           => array( 'has_archive' => 'guias',      'slug' => 'guias' ),
	'event'           => array( 'has_archive' => 'eventos',    'slug' => 'eventos' ),
	'leisure'         => array( 'has_archive' => 'lazer',      'slug' => 'lazer' ),
	'sponsor'         => array( 'has_archive' => 'apoiadores', 'slug' => 'apoiadores' ),
	'course_provider' => array( 'has_archive' => 'cursos',     'slug' => 'cursos' ),
	// ROOT CAUSE of the bug: the job CPT has no archive; /empregos/ is a page.
	'job'             => array( 'has_archive' => false,        'slug' => 'empregos' ),
	'post'            => array( 'has_archive' => false,        'slug' => 'blog' ),
);

function pll_current_language( $field = '' ) {
	$slug = $GLOBALS['conexao_test_lang'];
	return 'slug' === $field ? $slug : ( 'pt' === $slug ? 'pt_BR' : 'en_US' );
}
function pll_default_language( $field = '' ) {
	return 'slug' === $field ? 'pt' : 'pt_BR';
}
function pll_home_url( $lang = '' ) {
	$lang = '' === $lang ? $GLOBALS['conexao_test_lang'] : $lang;
	return $GLOBALS['conexao_test_homes'][ $lang ] ?? $GLOBALS['conexao_test_homes']['pt'];
}
function pll_languages_list( $args = array() ) {
	return array( 'pt', 'en' );
}
function pll_get_post( $id, $lang = '' ) {
	$id   = (int) $id;
	$lang = '' === $lang ? $GLOBALS['conexao_test_lang'] : $lang;
	foreach ( $GLOBALS['conexao_test_pages'] as $page ) {
		if ( 'en' === $lang && (int) $page['id'] === $id && $page['en'] ) {
			return (int) $page['en'];
		}
		if ( 'pt' === $lang && (int) $page['en'] === $id ) {
			return (int) $page['id'];
		}
	}
	return false;
}
function home_url( $path = '' ) {
	$path = (string) $path;
	if ( '' === $path || '/' === $path ) {
		// Polylang rewrites the BARE home URL to the current language home.
		return pll_home_url( $GLOBALS['conexao_test_lang'] );
	}
	return $GLOBALS['conexao_test_base'] . '/' . ltrim( $path, '/' );
}
function get_page_by_path( $path, $output = OBJECT, $type = 'page' ) {
	$path = trim( (string) $path, '/' );
	if ( isset( $GLOBALS['conexao_test_pages'][ $path ] ) ) {
		return (object) array( 'ID' => $GLOBALS['conexao_test_pages'][ $path ]['id'], 'post_parent' => 0 );
	}
	return null;
}

function get_permalink( $id ) {
	$id = (int) $id;
	foreach ( $GLOBALS['conexao_test_pages'] as $page ) {
		if ( (int) $page['id'] === $id ) {
			return $page['url'];
		}
		if ( $page['en'] && (int) $page['en'] === $id ) {
			return $page['en_url'];
		}
	}
	return false;
}
function get_post_status( $id ) {
	return 'publish';
}
function get_post_type_archive_link( $post_type ) {
	$pt = $GLOBALS['conexao_test_post_types'][ $post_type ] ?? null;
	if ( ! $pt || empty( $pt['has_archive'] ) ) {
		return false;
	}
	return $GLOBALS['conexao_test_base'] . '/' . $pt['slug'] . '/';
}
function get_post_type_object( $post_type ) {
	$pt = $GLOBALS['conexao_test_post_types'][ $post_type ] ?? null;
	if ( ! $pt ) {
		return null;
	}
	return (object) array(
		'name'        => $post_type,
		'has_archive' => $pt['has_archive'],
		'rewrite'     => array( 'slug' => $pt['slug'] ),
	);
}
function get_post_types( $args = array(), $output = 'names' ) {
	$out = array();
	foreach ( $GLOBALS['conexao_test_post_types'] as $name => $pt ) {
		$out[ $name ] = get_post_type_object( $name );
	}
	return $out;
}
function _wp_menu_item_classes_by_context( &$items ) {
	return $items; // WordPress menu-context logic — irrelevant to URL resolution.
}
function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}
function __( $text, $domain = '' ) {
	return $text;
}
function sanitize_text_field( $text ) {
	return trim( (string) $text );
}
function wp_unslash( $text ) {
	return $text;
}
function untrailingslashit( $string ) {
	return rtrim( (string) $string, '/' );
}
function trailingslashit( $string ) {
	return untrailingslashit( $string ) . '/';
}
function get_option( $name, $default = false ) {
	if ( 'page_for_posts' === $name ) {
		return 50; // the stub 'blog' page (page_for_posts).
	}
	return $default;
}
function is_home() {
	return false;
}
function is_singular() {
	return false;
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

// ---------------------------------------------------------------------------
// Load the REAL navigation functions.
// ---------------------------------------------------------------------------
$generated = "<?php\n" . implode( "\n\n", array_values( $extracted ) );
$tmp       = tempnam( sys_get_temp_dir(), 'conexao_nav_' ) . '.php';
file_put_contents( $tmp, $generated );
require $tmp;
unlink( $tmp );

/**
 * Build the EN "Main Menu" stored items (mirror of scripts/create-en-primary-menu.php):
 * custom links to the canonical PT paths + page-object items, English titles.
 *
 * @return array<int,object>
 */
function conexao_test_pt_menu_items(): array {
	$items = array();
	foreach ( array(
		array( 'Home', 'http://example.test/' ),
		array( 'Apoiadores', 'http://example.test/apoiadores/' ),
		array( 'Guias', 'http://example.test/guias/' ),
		array( 'Eventos', 'http://example.test/eventos/' ),
		array( 'Cursos', 'http://example.test/cursos/' ),
		array( 'Empregos', 'http://example.test/empregos/' ),
		array( 'Blog', 'http://example.test/blog/' ),
	) as $spec ) {
		$items[] = (object) array(
			'ID' => 0, 'db_id' => 0, 'menu_item_parent' => 0, 'object_id' => 0,
			'object' => 'custom', 'post_parent' => 0, 'type' => 'custom',
			'type_label' => 'Custom Link', 'title' => $spec[0], 'url' => $spec[1],
			'classes' => array( 'menu-item', 'menu-item-type-custom', 'menu-item-object-custom' ),
			'attr_title' => '', 'target' => '', 'xfn' => '', 'description' => '', 'menu_order' => 0,
		);
	}
	foreach ( array( array( 'Sobre Nós', 21 ), array( 'Contato', 20 ) ) as $spec ) {
		$items[] = (object) array(
			'ID' => 0, 'db_id' => 0, 'menu_item_parent' => 0, 'object_id' => $spec[1],
			'object' => 'page', 'post_parent' => 0, 'type' => 'post_type',
			'type_label' => 'Page', 'title' => $spec[0], 'url' => get_permalink( $spec[1] ),
			'classes' => array( 'menu-item', 'menu-item-type-post_type', 'menu-item-object-page' ),
			'attr_title' => '', 'target' => '', 'xfn' => '', 'description' => '', 'menu_order' => 0,
		);
	}
	return $items;
}

function conexao_test_en_menu_items(): array {
	$items = array();
	foreach ( array(
		array( 'Home', 'http://example.test/' ),
		array( 'Sponsors', 'http://example.test/apoiadores/' ),
		array( 'Guides', 'http://example.test/guias/' ),
		array( 'Events', 'http://example.test/eventos/' ),
		array( 'Courses', 'http://example.test/cursos/' ),
		array( 'Leisure & Tourism', 'http://example.test/lazer/' ),
		array( 'Jobs', 'http://example.test/empregos/' ),
		array( 'Blog', 'http://example.test/blog/' ),
	) as $spec ) {
		$items[] = (object) array(
			'ID'               => 0,
			'db_id'            => 0,
			'menu_item_parent' => 0,
			'object_id'        => 0,
			'object'           => 'custom',
			'post_parent'      => 0,
			'type'             => 'custom',
			'type_label'       => 'Custom Link',
			'title'            => $spec[0],
			'url'              => $spec[1],
			'classes'          => array( 'menu-item', 'menu-item-type-custom', 'menu-item-object-custom' ),
			'attr_title'       => '',
			'target'           => '',
			'xfn'              => '',
			'description'      => '',
			'menu_order'       => 0,
		);
	}
	// About Us + Contact are page-object items in the stored menu (About is removed
	// at render by conexao_modify_primary_nav_items()).
	foreach ( array( array( 'About Us', 21 ), array( 'Contact', 20 ) ) as $spec ) {
		$items[] = (object) array(
			'ID'               => 0,
			'db_id'            => 0,
			'menu_item_parent' => 0,
			'object_id'        => $spec[1],
			'object'           => 'page',
			'post_parent'      => 0,
			'type'             => 'post_type',
			'type_label'       => 'Page',
			'title'            => $spec[0],
			'url'              => get_permalink( $spec[1] ),
			'classes'          => array( 'menu-item', 'menu-item-type-post_type', 'menu-item-object-page' ),
			'attr_title'       => '',
			'target'           => '',
			'xfn'              => '',
			'description'      => '',
			'menu_order'       => 0,
		);
	}
	return $items;
}

/**
 * Render the primary nav items for a language, mirroring the theme's registered
 * render-time filter chain (override_guides 10, modify 20, normalize 25).
 *
 * @param string $lang  'pt' or 'en'.
 * @param string $path  Request path (for active states).
 * @return array<string,object> title => item.
 */
function conexao_test_render_nav( string $lang, string $path = '/' ): array {
	$GLOBALS['conexao_test_lang'] = $lang;
	$_SERVER['REQUEST_URI']       = $path;

	$args = (object) array( 'theme_location' => 'primary' );
	$items = ( 'pt' === $lang ) ? conexao_test_pt_menu_items() : conexao_test_en_menu_items();
	$items = conexao_override_guides_menu_links( $items, $args );
	$items = conexao_modify_primary_nav_items( $items, $args );
	$items = conexao_normalize_primary_nav_sections( $items, $args );

	$by_title = array();
	foreach ( $items as $item ) {
		$by_title[ trim( (string) $item->title ) ] = $item;
	}
	return $by_title;
}



$BASE = 'http://example.test';
$EN   = $BASE . '/en/';
$PT   = $BASE . '/';

// ---------------------------------------------------------------------------
/// A. EN navigation URL audit — every EN primary-nav destination.
// ---------------------------------------------------------------------------

$en = conexao_test_render_nav( 'en', '/en/' );

// title => expected final URL. Blog is a B2 destination: /en/blog/ renders
// PT content under the EN URL (no redirect), so the EN nav Blog item resolves
// to /en/blog/ — not the PT /blog/ URL.
$expected_en = array(
	'Home'             => $EN,
	'Sponsors'         => $EN . 'apoiadores/',
	'Guides'           => $EN . 'guias/',
	'Events'           => $EN . 'eventos/',
	'Courses'          => $EN . 'cursos/',
	'Leisure & Tourism'=> $EN . 'lazer/',
	'Jobs'             => $EN . 'jobs/',
	'Blog'             => $EN . 'blog/',
	'Contact'          => $EN . 'contact/',
);

assert_true( 9 === count( $en ), 'A1 EN nav renders the canonical nine items', 'got ' . count( $en ) . ': ' . implode( ', ', array_keys( $en ) ) );

foreach ( $expected_en as $title => $expected ) {
	$actual = isset( $en[ $title ] ) ? untrailingslashit( (string) $en[ $title ]->url ) : '<missing>';
	assert_true( untrailingslashit( $expected ) === $actual, "A2 \"{$title}\" -> {$expected}", "got {$actual}" );
}

assert_true( ! isset( $en['About Us'] ), 'A3 "About Us" is removed from the rendered EN nav (render-time rule)');
assert_true( 0 === count( array_filter( $en, static function ( $i ) { return '' === trim( (string) $i->url ); } ) ), 'A4 no English nav item is empty');

// ---------------------------------------------------------------------------
/// B. Jobs specifically.
// ---------------------------------------------------------------------------

$jobs = isset( $en['Jobs'] ) ? $en['Jobs'] : null;
assert_true( $jobs && untrailingslashit( $jobs->url ) === untrailingslashit( $BASE . '/en/jobs/' ), 'B1 EN "Jobs" resolves to the linked EN translation /en/jobs/', $jobs ? $jobs->url : 'missing' );
assert_true( $jobs && untrailingslashit( $jobs->url ) !== untrailingslashit( $BASE . '/empregos/' ), 'B2 EN "Jobs" no longer resolves to the Portuguese /empregos/');
assert_true( 'page' === conexao_primary_nav_sections()['empregos']['type'] && 'empregos' === conexao_primary_nav_sections()['empregos']['path'], 'B3 the Jobs section spec is page-backed (path=empregos), not a CPT archive');
assert_true( false === ( get_post_type_object( 'job' )->has_archive ), 'B4 the `job` CPT still has no archive (root cause remains unchanged)');

// ---------------------------------------------------------------------------
/// C. Blog specifically — B2 destination (PT content under EN URL, no redirect).
// ---------------------------------------------------------------------------

$blog = isset( $en['Blog'] ) ? $en['Blog'] : null;
assert_true( $blog && untrailingslashit( $blog->url ) === untrailingslashit( $BASE . '/en/blog/' ), 'C1 EN "Blog" resolves to the B2 /en/blog/ URL (PT content under EN shell)');
assert_true( ! in_array( 'Blog', conexao_test_en_items_outside_en(), true ), 'C2 Blog does NOT leave the /en/ context (B2 renders under /en/blog/)');

// ---------------------------------------------------------------------------
/// D. Translated items stay in EN.
// ---------------------------------------------------------------------------

foreach ( array( 'Home', 'Sponsors', 'Guides', 'Events', 'Courses', 'Leisure & Tourism', 'Contact' ) as $title ) {
	$url = isset( $en[ $title ] ) ? (string) $en[ $title ]->url : '';
	assert_true( 0 === strpos( untrailingslashit( $url ), untrailingslashit( $EN ) ) || untrailingslashit( $url ) === untrailingslashit( $EN ), "D1 \"{$title}\" is an EN-context destination", $url );
}

// ---------------------------------------------------------------------------
/// E. PT regression — PT navigation unchanged.
// ---------------------------------------------------------------------------

$pt = conexao_test_render_nav( 'pt', '/' );
$expected_pt = array(
	'Início'            => $PT,
	'Apoiadores'        => $PT . 'apoiadores/',
	'Guias'             => $PT . 'guias/',
	'Eventos'           => $PT . 'eventos/',
	'Cursos'            => $PT . 'cursos/',
	'Lazer e turismo'   => $PT . 'lazer/',
	'Empregos'          => $PT . 'empregos/',
	'Blog'              => $PT . 'blog/',
	'Contato'           => $PT . 'contato/',
);
foreach ( $expected_pt as $title => $expected ) {
	$actual = isset( $pt[ $title ] ) ? untrailingslashit( (string) $pt[ $title ]->url ) : '<missing>';
	assert_true( untrailingslashit( $expected ) === $actual, "E1 PT \"{$title}\" -> {$expected}", "got {$actual}" );
}
assert_true( isset( $pt['Empregos'] ) && untrailingslashit( $pt['Empregos']->url ) === untrailingslashit( $BASE . '/empregos/' ), 'E2 PT "Empregos" still points at the PT page /empregos/');


/**
 * Titles of EN primary-nav items whose final resolved URL is NOT inside /en/.
 * This is the LANGUAGE-LEAKAGE detector (Phase 13): it must return exactly the
 * documented B1 exception(s) — nothing more.
 *
 * @return string[]
 */
function conexao_test_en_items_outside_en(): array {
	$items   = conexao_test_render_nav( 'en', '/en/' );
	$en_home = untrailingslashit( pll_home_url( 'en' ) );
	$out     = array();
	foreach ( $items as $title => $item ) {
		$url = untrailingslashit( (string) $item->url );
		if ( $url !== $en_home && 0 !== strpos( $url, $en_home . '/' ) ) {
			$out[] = $title;
		}
	}
	sort( $out );
	return $out;
}

// ---------------------------------------------------------------------------
/// F. Active states still work.
// ---------------------------------------------------------------------------

function conexao_test_is_active( array $items, string $title, string $class ): bool {
	return isset( $items[ $title ] ) && in_array( $class, (array) $items[ $title ]->classes, true );
}

$active_en_jobs = conexao_test_render_nav( 'en', '/en/jobs/' );
assert_true( conexao_test_is_active( $active_en_jobs, 'Jobs', 'current-menu-item' ), 'F1 EN /en/jobs/ marks "Jobs" as current-menu-item');
assert_true( ! conexao_test_is_active( $active_en_jobs, 'Blog', 'current-menu-item' ) && ! conexao_test_is_active( $active_en_jobs, 'Guides', 'current-menu-item' ), 'F2 EN /en/jobs/ does NOT mark "Blog" or "Guides" as active');

$active_en_guides = conexao_test_render_nav( 'en', '/en/guias/' );
assert_true( conexao_test_is_active( $active_en_guides, 'Guides', 'current-menu-item' ), 'F3 EN /en/guias/ marks "Guides" as current-menu-item');

$active_en_home = conexao_test_render_nav( 'en', '/en/' );
assert_true( conexao_test_is_active( $active_en_home, 'Home', 'current-menu-item' ), 'F4 EN /en/ marks "Home" as current-menu-item');

$active_pt_jobs = conexao_test_render_nav( 'pt', '/empregos/' );
assert_true( conexao_test_is_active( $active_pt_jobs, 'Empregos', 'current-menu-item' ), 'F5 PT /empregos/ marks "Empregos" as current-menu-item');

$active_pt_blog = conexao_test_render_nav( 'pt', '/blog/' );
assert_true( conexao_test_is_active( $active_pt_blog, 'Blog', 'current-menu-item' ), 'F6 /blog/ marks "Blog" as current-menu-item');

// ---------------------------------------------------------------------------
/// G. Structural guards (no regression to the previous header fix).
// ---------------------------------------------------------------------------

$header_src = (string) file_get_contents( $theme_dir . '/header.php' );
assert_true( false === strpos( $header_src, 'wp_page_menu' ), 'G1 header.php does NOT use wp_page_menu as the wp_nav_menu fallback');
assert_true( 2 === substr_count( $header_src, "'fallback_cb'    => 'conexao_safe_nav_menu_fallback'," ), 'G2 BOTH wp_nav_menu() calls (desktop + mobile) use the safe empty fallback');
assert_true( false === strpos( $header_src, '/en/empregos/' ) && false === strpos( $header_src, '/en/blog/' ), 'G3 header.php contains NO hard-coded /en/empregos/ or /en/blog/ URL');
assert_true( false === strpos( $header_src, "'/empregos/" ) && false === strpos( $header_src, "'/blog/" ), 'G4 header.php contains NO hard-coded /empregos/ or /blog/ navigation URL');
assert_true( 2 === substr_count( $header_src, "'theme_location'   => 'primary'," ) || 2 === substr_count( $header_src, "'theme_location' => 'primary'," ), 'G5 desktop and mobile render the SAME primary theme location', 'shared menu location -> mobile gets the same corrected destinations' );

// ---------------------------------------------------------------------------
/// H. Language-leakage allowlist (Phase 13).
// ---------------------------------------------------------------------------

$leaks = conexao_test_en_items_outside_en();
assert_true( array() === $leaks, 'H1 NO EN nav item leaves the /en/ context (Blog is a B2 destination at /en/blog/)', 'got ' . implode( ', ', $leaks ) );
assert_true( ! in_array( 'Jobs', $leaks, true ), 'H2 Jobs is NOT in the leakage list');
assert_true( array() === $leaks, 'H3 no EN nav item leaks to PT', 'got ' . implode( ', ', $leaks ) );

test_finish();
