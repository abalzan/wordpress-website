<?php
/**
 * Asset registration and versioned loading
 *
 * conexao_asset_version() (filemtime cache-busting) and the stylesheet/
 * script enqueue chain, plus the Google Fonts preconnect and non-blocking
 * font handling and the hero image preload.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function conexao_asset_version( $relative_path ) {
	$file = CONEXAO_THEME_DIR . '/' . ltrim( $relative_path, '/' );
	return file_exists( $file ) ? (string) filemtime( $file ) : CONEXAO_THEME_VERSION;
}

function conexao_enqueue_scripts() {
	// Only load the font weights actually used in the design.
	// Inter: 400 (body), 500 (nav), 600 (buttons/headings), 700 (headings).
	// Poppins: 500 (nav), 600 (subheadings), 700 (headings).
	wp_enqueue_style( 'conexao-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700&display=swap', array(), null );

	wp_enqueue_style( 'conexao-design-system', CONEXAO_THEME_URI . '/assets/css/design-system.css', array(), conexao_asset_version( 'assets/css/design-system.css' ) );
	wp_enqueue_style( 'conexao-header-nav', CONEXAO_THEME_URI . '/assets/css/header-nav.css', array( 'conexao-design-system' ), conexao_asset_version( 'assets/css/header-nav.css' ) );
	wp_enqueue_style( 'conexao-main', CONEXAO_THEME_URI . '/assets/css/main.css', array( 'conexao-header-nav' ), conexao_asset_version( 'assets/css/main.css' ) );
	// Conditional component stylesheets. Evidence from the audit: the
	// leisure-* classes are only rendered by the Lazer archive/single
	// templates (and taxonomy term archives, which can list leisure posts
	// via archive.php), and the sponsor-single-*/sponsor-contact-* classes
	// in sponsor.css are only rendered by single-sponsor.php. The homepage
	// hero carousel styles (.sponsor-tile etc.) live in main.css, so the
	// carousel is unaffected. Skipping these files saves ~29 KB of CSS
	// (before compression) on every other page type.
	$needs_leisure_css = is_post_type_archive( 'leisure' ) || is_singular( 'leisure' ) || is_tax();
	$needs_sponsor_css = is_singular( 'sponsor' );

	// Keep the cascade order identical to the previous site-wide chain
	// (main → leisure → sponsor → dark-mode) by chaining dependencies
	// dynamically; dark-mode.css must always come last so its overrides win.
	$dark_mode_dep = 'conexao-main';

	if ( $needs_leisure_css ) {
		wp_enqueue_style( 'conexao-leisure', CONEXAO_THEME_URI . '/assets/css/leisure.css', array( 'conexao-main' ), conexao_asset_version( 'assets/css/leisure.css' ) );
		$dark_mode_dep = 'conexao-leisure';
	}

	if ( $needs_sponsor_css ) {
		wp_enqueue_style( 'conexao-sponsor', CONEXAO_THEME_URI . '/assets/css/sponsor.css', array( $dark_mode_dep ), conexao_asset_version( 'assets/css/sponsor.css' ) );
		$dark_mode_dep = 'conexao-sponsor';
	}

	wp_enqueue_style( 'conexao-dark-mode', CONEXAO_THEME_URI . '/assets/css/dark-mode.css', array( $dark_mode_dep ), conexao_asset_version( 'assets/css/dark-mode.css' ) );

	// Load main.js with defer to avoid render-blocking.
	wp_enqueue_script( 'conexao-main', CONEXAO_THEME_URI . '/assets/js/main.js', array(), conexao_asset_version( 'assets/js/main.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );

	/*
	 * Inject the localized UI strings for main.js (Stage 1 i18n foundation).
	 * main.js reads `window.ConexaoI18n` and falls back to the Portuguese
	 * literals only when this payload is absent. The strings' single gettext
	 * source is conexao_js_i18n_strings() in inc/i18n.php.
	 */
	wp_add_inline_script(
		'conexao-main',
		'window.ConexaoI18n = ' . wp_json_encode( conexao_js_i18n_strings() ) . ';',
		'before'
	);

	if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
		wp_enqueue_script( 'comment-reply' );
	}
}
add_action( 'wp_enqueue_scripts', 'conexao_enqueue_scripts' );

/**
 * Add preconnect hints for Google Fonts in the head.
 *
 * We deliberately do NOT emit a `rel="preload" as="style"` hint for the
 * fonts here. The Google Fonts stylesheet is already loaded NON render-
 * blocking via `conexao_fonts_non_blocking` (`media="print"` + `onload`,
 * with `font-display:swap` in its URL), so the browser downloads it on its
 * own in the background — a redundant cross-origin `preload as="style"`
 * would only add a high-priority, third-party request (own DNS+TLS) to the
 * top of the <head> that competes with the LCP hero image preload for the
 * first network slot. Removing it lets the browser start the Hero fetch
 * with nothing in front of it. The two preconnects below still warm the
 * DNS/TLS path so the font swap happens quickly.
 */
function conexao_fonts_preconnect() {
	echo '<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>' . "\n";
	echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
}
add_action( 'wp_head', 'conexao_fonts_preconnect', 2 );

/**
 * Load the Google Fonts stylesheet WITHOUT blocking first render.
 *
 * The external fonts CSS used to be a render-blocking stylesheet on a
 * third-party origin: the browser had to complete DNS + TLS + request to
 * fonts.googleapis.com BEFORE painting anything, which delayed FCP and —
 * most visibly on mobile — pushed the hero image's first paint out by
 * ~1 s (the PageSpeed "element render delay" on the LCP hero image).
 *
 * The standard async-CSS pattern fixes this without changing the visual
 * result: the link is printed with media="print" (non-render-blocking,
 * still downloaded), promoted to media="all" by onload, and a <noscript>
 * fallback keeps the fonts working without JavaScript. font-display:swap
 * (already in the URL) means text paints with the fallback face and
 * upgrades when the real fonts arrive — identical behavior to before on
 * slow connections, but first paint no longer waits for the third-party
 * round-trip.
 */
function conexao_fonts_non_blocking( $tag, $handle ) {
	if ( 'conexao-fonts' !== $handle ) {
		return $tag;
	}

	// Non-blocking: print media never blocks rendering; onload promotes it.
	$async_tag = str_replace(
		"media='all'",
		"media='print' onload=\"this.media='all'\"",
		$tag
	);

	// Progressive enhancement fallback: keep the blocking stylesheet when
	// JavaScript is unavailable (or the onload handler never runs).
	$noscript = '<noscript>' . trim( $tag ) . '</noscript>' . "\n";

	return $async_tag . $noscript;
}
add_filter( 'style_loader_tag', 'conexao_fonts_non_blocking', 10, 2 );

/**
 * Preload the homepage Hero background (the LCP element) so the browser
 * starts fetching it in parallel with the render-blocking CSS instead of
 * only discovering it after the CSS finishes. Matches the <picture>
 * sources in front-page.php exactly: mobile WebP ≤768px, desktop WebP
 * ≥769px. No-op on every other page.
 *
 * IMPORTANT: This function is kept for reference but is no longer hooked
 * to wp_head. The preload links are now emitted directly in header.php,
 * BEFORE the inline theme-detection <script>, so the browser's preload
 * scanner can discover the LCP fetch before the script blocks HTML
 * parsing. Previously, the preload was emitted at wp_head priority 1
 * — the first wp_head hook — but wp_head() itself runs AFTER the inline
 * <script> in header.php. On some mobile browsers, window.matchMedia()
 * in that script can take several hundred of milliseconds to query the
 * OS theme, blocking the main parser from reaching the preload links and
 * causing a ~610 ms resource-load delay on the LCP image. Moving the
 * preload to the very first position in <head> eliminates that delay.
 */
function conexao_hero_preload() {
	if ( ! is_front_page() ) {
		return;
	}

	$base = get_template_directory_uri() . '/assets/images/';

	echo '<link rel="preload" as="image" fetchpriority="high" media="(max-width: 768px)" href="' . esc_url( $base . 'conexaobr_Hero_image_mobile.webp' ) . '">' . "\n";
	// Desktop: mirror the <picture> srcset ladder (1600w + 2057w,
	// sizes="100vw") so the preload resolves to the same candidate the
	// browser would select from the markup — DPR-1 desktops get the 1600w
	// derivative instead of always fetching the 2057w master.
	echo '<link rel="preload" as="image" fetchpriority="high" media="(min-width: 769px)" imagesrcset="' . esc_attr( $base . 'conexaobr_Hero_image-1600.webp 1600w, ' . $base . 'conexaobr_Hero_image.webp 2057w' ) . '" imagesizes="100vw" href="' . esc_url( $base . 'conexaobr_Hero_image.webp' ) . '">' . "\n";
}
// Preload now emitted in header.php before the theme init script — see above.

/**
 * ---------------------------------------------------------------------------
 * PERFORMANCE OPTIMIZATIONS
 * ---------------------------------------------------------------------------
 */

/**
 * Remove emoji scripts/styles (saves ~15KB of JS/CSS on every page).
 * Emojis are not used in the design and the browser fallback is fine.
 */
