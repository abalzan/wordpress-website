<?php
/**
 * Stage 20 — homepage hero preload fallback regression suite.
 *
 * The trade-off this suite locks down: the homepage `<head>` emits TWO hero
 * preloads (header.php, front page only). The desktop one keeps
 * `media="(min-width: 769px)"` and the authoritative two-candidate
 * `imagesrcset` ladder; only its plain `href` fallback points at the existing
 * 1600w derivative instead of the 2057w master.
 *
 * WHY: Firefox's speculative loader can take the plain `href` fallback even
 * when the `media` condition does NOT match, so a mobile cold/private load can
 * be made to download a desktop hero it will never display. The fallback
 * therefore deliberately targets the smaller derivative (~137 KiB) instead of
 * the master (~220 KiB). This is a COST BOUND, not a bug fix: this suite never
 * claims the speculative fetch itself is gone, and it must not be used as a
 * reason to delete the preload.
 *
 * WHAT IS PROVEN (read-only; no network, no production):
 *
 *   A. The mobile preload is untouched (media, asset, fetchpriority).
 *   B. The desktop preload is STILL PRESENT and still correct: `media`,
 *      `imagesrcset`, `imagesizes`, `fetchpriority`, `as`, `type` — and its
 *      fallback `href` is the 1600w derivative, NOT the 2057w master.
 *   C. The hero `<picture>` in front-page.php is unchanged in structure and
 *      still carries the mobile WebP, mobile PNG and desktop WebP sources with
 *      their media conditions, plus the `<img>` fallback.
 *   D. The fallback really is the smaller of two EXISTING committed assets, so
 *      "smaller" is a measured fact and no asset was replaced or added.
 *
 * NEGATIVE PROOF: the section B assertions are phrased so that deleting the
 * desktop preload, dropping `media`/`imagesrcset`/`imagesizes`, or silently
 * restoring the 2057w master as the fallback each fails this suite.
 *
 * @package Conexao_BR_Irlanda
 */

// Shared Stage E bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$theme_dir = get_template_directory();

test_title( 'Stage 20 — hero preload fallback (Firefox speculative-loader cost bound)' );

/**
 * Read a theme template and return its source.
 *
 * @param string $path Absolute path.
 * @return string
 */
function hero_preload_read( $path ) {
	return (string) file_get_contents( $path );
}

/**
 * Resolve the template's inline PHP to plain text, so element parsing works.
 *
 * The templates build their URLs inline (`<?php echo esc_url( ... ); ?>`), and
 * the closing `?>` puts a `>` INSIDE an attribute value. A naive `<tag ...>`
 * match would therefore stop half-way through the element. This rewrites each
 * PHP block to its bare code with single-quoted literals unwrapped, which
 * keeps every asset path readable and removes the embedded `>`. It is a source
 * transform only: nothing is executed and nothing is written.
 *
 * @param string $markup Raw template source.
 * @return string
 */
function hero_preload_resolve_php( $markup ) {
	return (string) preg_replace_callback(
		'/<\?php(.*?)\?>/s',
		static function ( $match ) {
			return (string) preg_replace( '#\'([^\']*)\'#', '$1', $match[1] );
		},
		$markup
	);
}

/**
 * Normalise a markup fragment so attribute parsing cannot depend on formatting.
 *
 * Collapses every run of whitespace (including the newlines and indentation of
 * a multi-line `<source>`) to a single space, so the assertions below test
 * semantics and never indentation.
 *
 * @param string $markup Raw markup.
 * @return string
 */
function hero_preload_flatten( $markup ) {
	return trim( (string) preg_replace( '/\s+/', ' ', $markup ) );
}

/**
 * Read a template and return markup ready for element parsing.
 *
 * @param string $path Absolute path.
 * @return string
 */
function hero_preload_markup( $path ) {
	return hero_preload_flatten( hero_preload_resolve_php( hero_preload_read( $path ) ) );
}

/**
 * Parse the attributes of one HTML element into a lower-cased name => value map.
 *
 * Values keep their PHP source (the templates build URLs inline), so callers
 * assert on the asset named inside the value rather than on a rendered URL.
 *
 * @param string $element Flattened element source, e.g. `<link ...>`.
 * @return array<string,string>
 */
function hero_preload_attributes( $element ) {
	$attributes = array();

	if ( preg_match_all( '/([a-zA-Z][a-zA-Z0-9_-]*)="([^"]*)"/', $element, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$attributes[ strtolower( $match[1] ) ] = $match[2];
		}
	}

	return $attributes;
}

/**
 * Extract every `<link rel="preload" ...>` element from flattened markup.
 *
 * @param string $markup Flattened markup.
 * @return array<int,string> Flattened element sources.
 */
function hero_preload_links( $markup ) {
	$links = array();

	if ( preg_match_all( '/<link\s[^>]*rel="preload"[^>]*>/', $markup, $matches ) ) {
		$links = $matches[0];
	}

	return $links;
}

/**
 * Parse a `srcset`/`imagesrcset` value into its ordered width candidates.
 *
 * Returns entries shaped like `conexaobr_Hero_image-1600.webp 1600w`, so a
 * ladder is compared as DATA (exact candidates, exact order) instead of as
 * substrings, and the comparison is immune to template formatting and to the
 * inline PHP the templates build these values with.
 *
 * @param string $value Raw srcset attribute value.
 * @return array<int,string>
 */
function hero_preload_candidates( $value ) {
	$candidates = array();

	if ( preg_match_all( '#([A-Za-z0-9._-]+\.(?:webp|png|jpg|jpeg))\D*?(\d+)w#', (string) $value, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$candidates[] = $match[1] . ' ' . $match[2] . 'w';
		}
	}

	return $candidates;
}

/**
 * The asset file name a template attribute value points at.
 *
 * @param string $value Raw attribute value (may contain PHP source).
 * @return string Basename, or '' when the value names no asset.
 */
function hero_preload_asset( $value ) {
	if ( ! preg_match( '#/assets/images/([A-Za-z0-9._-]+)#', (string) $value, $match ) ) {
		return '';
	}

	return $match[1];
}

/**
 * Locate one preload link by the media condition it carries.
 *
 * @param array<int,string> $links Flattened `<link>` elements.
 * @param string             $media Media condition to match.
 * @return string Flattened element, or '' when absent.
 */
function hero_preload_link_for_media( $links, $media ) {
	foreach ( $links as $link ) {
		$attributes = hero_preload_attributes( $link );
		if ( isset( $attributes['media'] ) && $media === $attributes['media'] ) {
			return $link;
		}
	}

	return '';
}

// ---------------------------------------------------------------------------
// header.php — the two hero preloads.
// ---------------------------------------------------------------------------

$header_markup = hero_preload_markup( $theme_dir . '/header.php' );
$preloads      = hero_preload_links( $header_markup );

$mobile_preload  = hero_preload_link_for_media( $preloads, '(max-width: 768px)' );
$desktop_preload = hero_preload_link_for_media( $preloads, '(min-width: 769px)' );

$mobile_attrs  = hero_preload_attributes( $mobile_preload );
$desktop_attrs = hero_preload_attributes( $desktop_preload );

// --- A. Mobile preload: untouched -------------------------------------------

test_section( 'A. Mobile preload is unchanged' );

assert_true( '' !== $mobile_preload, 'the mobile hero preload is still emitted by header.php' );
assert_equals( 'preload', $mobile_attrs['rel'] ?? '', 'the mobile hint is a preload' );
assert_equals( 'image', $mobile_attrs['as'] ?? '', 'the mobile hint preloads an image' );
assert_equals( 'image/webp', $mobile_attrs['type'] ?? '', 'the mobile hint keeps the image/webp type' );
assert_equals( 'high', $mobile_attrs['fetchpriority'] ?? '', 'the mobile hint keeps fetchpriority="high"' );
assert_equals(
	'(max-width: 768px)',
	$mobile_attrs['media'] ?? '',
	'the mobile hint keeps its max-width: 768px media condition'
);
assert_equals(
	'conexaobr_Hero_image_mobile.webp',
	hero_preload_asset( $mobile_attrs['href'] ?? '' ),
	'the mobile hint still points at the mobile hero WebP'
);

// --- B. Desktop preload: present, correct, cheaper fallback -----------------
//
// This section is the negative proof: a "performance optimization" that drops
// the desktop preload, strips its media/imagesrcset/imagesizes, or restores the
// 2057w master as the fallback fails here.

test_section( 'B. Desktop preload stays, and its fallback is the 1600w derivative' );

assert_true( '' !== $desktop_preload, 'the desktop hero preload is STILL emitted by header.php' );
assert_equals( 'preload', $desktop_attrs['rel'] ?? '', 'the desktop hint is a preload' );
assert_equals( 'image', $desktop_attrs['as'] ?? '', 'the desktop hint preloads an image' );
assert_equals( 'image/webp', $desktop_attrs['type'] ?? '', 'the desktop hint keeps the image/webp type' );
assert_equals( 'high', $desktop_attrs['fetchpriority'] ?? '', 'the desktop hint keeps fetchpriority="high"' );
assert_equals(
	'(min-width: 769px)',
	$desktop_attrs['media'] ?? '',
	'the desktop hint keeps its min-width: 769px media condition'
);
assert_equals( '100vw', $desktop_attrs['imagesizes'] ?? '', 'the desktop hint keeps imagesizes="100vw"' );

$desktop_srcset = $desktop_attrs['imagesrcset'] ?? '';
assert_true( '' !== $desktop_srcset, 'the desktop hint still carries imagesrcset' );
assert_equals(
	array(
		'conexaobr_Hero_image-1600.webp 1600w',
		'conexaobr_Hero_image.webp 2057w',
	),
	hero_preload_candidates( $desktop_srcset ),
	'imagesrcset is unchanged: the 1600w derivative and the 2057w master, in order (responsive selection is untouched)'
);

assert_equals(
	'conexaobr_Hero_image-1600.webp',
	hero_preload_asset( $desktop_attrs['href'] ?? '' ),
	'the desktop fallback href points at the existing 1600w derivative'
);
assert_true(
	'conexaobr_Hero_image.webp' !== hero_preload_asset( $desktop_attrs['href'] ?? '' ),
	'the 2057w master is no longer the desktop preload fallback'
);

// --- C. The hero <picture> is untouched -------------------------------------

test_section( 'C. Hero <picture> markup is preserved' );

$front_page = hero_preload_markup( $theme_dir . '/front-page.php' );

test_require(
	false !== strpos( $front_page, '<picture' ) && false !== strpos( $front_page, '</picture>' ),
	'hero-picture-markup',
	'front-page.php still emits the hero <picture> element'
);

$picture_open  = strpos( $front_page, '<picture' );
$picture_close = strpos( $front_page, '</picture>' );
$picture       = substr( $front_page, $picture_open, $picture_close - $picture_open + strlen( '</picture>' ) );

$sources = array();
if ( preg_match_all( '/<source\s[^>]*>/', $picture, $matches ) ) {
	$sources = $matches[0];
}
assert_equals( 3, count( $sources ), 'the hero picture still carries exactly three <source> elements' );

$mobile_webp   = array();
$mobile_png    = array();
$desktop_webp  = array();
foreach ( $sources as $source ) {
	$attributes = hero_preload_attributes( $source );
	$asset      = hero_preload_asset( $attributes['srcset'] ?? '' );

	if ( 'conexaobr_Hero_image_mobile.webp' === $asset ) {
		$mobile_webp = $attributes;
	} elseif ( 'conexaobr_Hero_image_mobile.png' === $asset ) {
		$mobile_png = $attributes;
	} elseif ( 'conexaobr_Hero_image-1600.webp' === $asset ) {
		$desktop_webp = $attributes;
	}
}

assert_equals( '(max-width: 768px)', $mobile_webp['media'] ?? '', 'the mobile WebP source keeps its max-width: 768px condition' );
assert_equals( '(max-width: 768px)', $mobile_png['media'] ?? '', 'the mobile PNG source keeps its max-width: 768px condition' );
assert_equals( 'image/webp', $mobile_webp['type'] ?? '', 'the mobile WebP source keeps type="image/webp"' );
assert_equals( '(min-width: 769px)', $desktop_webp['media'] ?? '', 'the desktop WebP source keeps its min-width: 769px condition' );
assert_equals( '100vw', $desktop_webp['sizes'] ?? '', 'the desktop WebP source keeps sizes="100vw"' );

$desktop_picture_srcset = $desktop_webp['srcset'] ?? '';
assert_equals(
	array(
		'conexaobr_Hero_image-1600.webp 1600w',
		'conexaobr_Hero_image.webp 2057w',
	),
	hero_preload_candidates( $desktop_picture_srcset ),
	'the desktop <picture> srcset still offers the 1600w candidate and the 2057w master candidate'
);

assert_contains( 'hero-background-img', $picture, 'the hero <img> keeps its hero-background-img class' );
assert_contains( '/assets/images/conexaobr_Hero_image.png', $picture, 'the hero <img> still falls back to the desktop PNG' );
assert_contains( 'fetchpriority="high"', $picture, 'the hero <img> keeps fetchpriority="high" (LCP)' );
assert_contains( 'loading="eager"', $picture, 'the hero <img> keeps loading="eager"' );

// --- D. The fallback is a real, smaller, already-committed asset ------------

test_section( 'D. The fallback asset is the smaller existing derivative' );

$images_dir = $theme_dir . '/assets/images';
$derivative = $images_dir . '/conexaobr_Hero_image-1600.webp';
$master     = $images_dir . '/conexaobr_Hero_image.webp';

test_require(
	file_exists( $derivative ) && file_exists( $master ),
	'hero-image-assets',
	'both hero WebP assets are present on disk',
	'restore wp-content/themes/conexao-br-irlanda/assets/images/conexaobr_Hero_image*.webp'
);

$derivative_size = (int) filesize( $derivative );
$master_size     = (int) filesize( $master );

assert_true(
	$derivative_size < $master_size,
	sprintf(
		'the fallback asset really is the smaller one (%d bytes vs %d bytes)',
		$derivative_size,
		$master_size
	)
);

$hero_assets = array_values( array_filter( (array) glob( $images_dir . '/conexaobr_Hero_image*' ), 'is_file' ) );
sort( $hero_assets );

assert_equals(
	array(
		'conexaobr_Hero_image-1600.webp',
		'conexaobr_Hero_image.png',
		'conexaobr_Hero_image.webp',
		'conexaobr_Hero_image_mobile.png',
		'conexaobr_Hero_image_mobile.webp',
	),
	array_map( 'basename', $hero_assets ),
	'no hero image asset was added, replaced or removed'
);

test_finish( 'hero-preload-fallback' );


