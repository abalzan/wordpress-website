<?php
/**
 * Conexão BR Irlanda - Community Portal theme functions
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CONEXAO_THEME_VERSION', '1.0.0' );
define( 'CONEXAO_THEME_DIR', get_template_directory() );
define( 'CONEXAO_THEME_URI', get_template_directory_uri() );

/**
 * Load SEO foundation module.
 */
require_once CONEXAO_THEME_DIR . '/inc/seo.php';

/**
 * Load the Empregos landing page support (optional "Mais informações" link
 * field + helpers). It adds a single meta field on top of the standard
 * WordPress page fields used by page-empregos.php (title, featured image,
 * body content).
 */
require_once CONEXAO_THEME_DIR . '/inc/empregos-landing.php';

/**
 * Load the Empregos "Onde procurar emprego" job-search resources (filterable
 * data source for the external job-site cards rendered below the landing
 * content by page-empregos.php).
  */
require_once CONEXAO_THEME_DIR . '/inc/job-resources.php';

/**
 * Load the view-counting module: records a single view per front-end content
 * page load into the `_conexao_view_count` post meta, which powers the
 * homepage "Mais Lidos" section (see conexao_popular_posts()). See
 * inc/post-views.php.
 */
require_once CONEXAO_THEME_DIR . '/inc/post-views.php';

/**
 * Load the search module: accent-insensitive search matching over the native
 * post_title / post_excerpt / post_content search columns. See inc/search.php.
 */
require_once CONEXAO_THEME_DIR . '/inc/search.php';

/**
 * Relabel "Posts" to "Blog" in the WordPress admin.
 *
 * This makes the admin interface clearer for non-technical administrators
 * by using "Blog" terminology instead of WordPress's native "Posts".
 * All native WordPress post functionality remains intact.
 */
function conexao_relabel_posts_to_blog() {
	global $wp_post_types;
	
	if ( isset( $wp_post_types['post'] ) && isset( $wp_post_types['post']->labels ) ) {
		// Update the existing labels object in-place rather than replacing it.
		// WordPress core expects properties such as `menu_name` and
		// `name_admin_bar` to exist on the labels object; replacing the whole
		// object with a partial one triggers "Undefined property" warnings in
		// wp-admin/menu.php and wp-includes/admin-bar.php.
		$labels = $wp_post_types['post']->labels;

		$labels->name                  = 'Blog';
		$labels->singular_name         = 'Artigo';
		$labels->add_new               = 'Adicionar Novo';
		$labels->add_new_item          = 'Adicionar Novo Artigo';
		$labels->edit_item             = 'Editar Artigo';
		$labels->new_item              = 'Novo Artigo';
		$labels->view_item             = 'Ver Artigo';
		$labels->view_items            = 'Ver Artigos';
		$labels->search_items          = 'Buscar Artigos';
		$labels->not_found             = 'Nenhum artigo encontrado';
		$labels->not_found_in_trash    = 'Nenhum artigo encontrado na lixeira';
		$labels->parent_item_colon     = 'Artigo pai:';
		$labels->all_items             = 'Todos os Artigos';
		$labels->archives              = 'Arquivos do Blog';
		$labels->attributes            = 'Atributos do Artigo';
		$labels->insert_into_item      = 'Inserir no artigo';
		$labels->uploaded_to_this_item = 'Enviado para este artigo';
		$labels->featured_image        = 'Imagem Destacada';
		$labels->set_featured_image    = 'Definir imagem destacada';
		$labels->remove_featured_image = 'Remover imagem destacada';
		$labels->use_featured_image    = 'Usar como imagem destacada';
		$labels->filter_items_list     = 'Filtrar lista de artigos';
		$labels->items_list_navigation = 'Navegação da lista de artigos';
		$labels->items_list            = 'Lista de artigos';

		// Explicitly set the menu/admin-bar labels so the Blog terminology is
		// used consistently in the admin menu and admin bar.
		$labels->menu_name             = 'Blog';
		$labels->name_admin_bar        = 'Artigo';
	}
}
add_action( 'init', 'conexao_relabel_posts_to_blog', 10 );

/**
 * Change the admin menu label for "Posts" to "Blog".
 */
function conexao_change_admin_menu_label() {
	global $menu;
	
	foreach ( $menu as $key => $value ) {
		if ( isset( $value[0] ) && 'Posts' === $value[0] ) {
			$menu[ $key ][0] = 'Blog';
		}
	}
}
add_action( 'admin_menu', 'conexao_change_admin_menu_label', 5 );

/**
 * Get the canonical archive URL for the Guides CPT.
 *
 * @return string
 */
function conexao_get_guides_archive_url() {
	$archive_link = get_post_type_archive_link( 'guide' );

	if ( $archive_link ) {
		return $archive_link;
	}

	return home_url( '/guias/' );
}

/**
 * Resolve the canonical Guide category URL for a Quick Access card.
 *
 * The homepage Quick Access cards should funnel straight into the existing
 * /guias/ archive filter (the same tax_query used by the filter bar), rather
 * than linking to static placeholder pages. The ?categoria= parameter must use
 * the real conexao_category term slug — which is NOT always the same as the
 * visible card label (e.g. card "Moradia" -> term name "Moradia e Aluguel"
 * with slug "moradia"; card "Finanças" -> term name "Consumidor e Finanças"
 * with slug "financas").
 *
 * Resolution order (first match wins):
 *  1. If $identifier is an existing conexao_category term slug, use it as-is.
 *  2. If $identifier matches an existing term by name (case-insensitive),
 *     use that term's slug.
 *  3. Fall back to a manual label -> slug mapping ($fallback_slug) so the
 *     card still works even before terms are seeded. The manual slug is only
 *     used when the term is not resolvable via taxonomy, and is itself
 *     validated: if it doesn't exist we fall through to the plain archive.
 *
 * @param string $identifier   Card identifier (label or slug).
 * @param string $fallback_slug Optional known term slug to try when the term
 *                              cannot be resolved from taxonomy data.
 * @return string Fully-qualified URL, or the plain /guias/ archive when no
 *                matching category exists.
 */
function conexao_get_guide_category_url( $identifier, $fallback_slug = '' ) {
	$archive_url = conexao_get_guides_archive_url();
	$identifier  = trim( (string) $identifier );

	if ( '' === $identifier || '/' === $identifier ) {
		return $archive_url;
	}

	$candidates = array();

	// 1. The identifier is itself a term slug.
	$candidates[] = sanitize_title( $identifier );

	// 2. A manual fallback slug, if provided.
	if ( '' !== $fallback_slug ) {
		$candidates[] = sanitize_title( $fallback_slug );
	}

	$term = null;

	foreach ( $candidates as $candidate_slug ) {
		if ( '' === $candidate_slug ) {
			continue;
		}

		$found = get_term_by( 'slug', $candidate_slug, 'conexao_category' );
		if ( $found && ! is_wp_error( $found ) ) {
			$term = $found;
			break;
		}
	}

	// 3. Match by term name (case-insensitive) — covers labels like
	//    "Moradia e Aluguel" being passed directly, or accented labels.
	if ( ! $term ) {
		$terms = get_terms( array(
			'taxonomy'   => 'conexao_category',
			'hide_empty' => false,
		) );

		if ( ! is_wp_error( $terms ) ) {
			$needle = mb_strtolower( $identifier );
			foreach ( $terms as $candidate_term ) {
				if ( mb_strtolower( $candidate_term->name ) === $needle ) {
					$term = $candidate_term;
					break;
				}
			}
		}
	}

	if ( $term ) {
		return add_query_arg( 'categoria', $term->slug, $archive_url );
	}

	return $archive_url;
}

/**
 * Get taxonomy terms that are actually used by a specific post type.
 *
 * WordPress' get_terms() counts posts across every post type that shares a
 * taxonomy. On a site where conexao_category is shared by guides, events,
 * jobs, sponsors and course providers, that means a category used only by
 * guides would appear in the events filter bar.
 *
 * This helper restricts the result to terms that have at least one published
 * post of the given post type, so each archive only shows filters that are
 * relevant to its own content.
 *
 * The underlying get_posts() call respects pre_get_posts hooks, so editorial
 * status filters (e.g. the event-importer's _event_status = published filter)
 * are applied automatically — terms only appear when they have visible content.
 *
 * Results are cached in the object cache for 5 minutes.
 *
 * @param string $taxonomy  Taxonomy slug (e.g. conexao_category).
 * @param string $post_type Post type slug (e.g. event, guide).
 * @return WP_Term[] Array of term objects, empty when none match.
 */
function conexao_get_terms_for_post_type( $taxonomy, $post_type, $extra_args = array() ) {
	$cache_key = 'conexao_terms_' . $taxonomy . '_' . $post_type;
	$cache_key .= empty( $extra_args ) ? '' : '_' . md5( wp_json_encode( $extra_args ) );
	$cached    = wp_cache_get( $cache_key, 'conexao_filters' );

	if ( false !== $cached ) {
		return $cached;
	}

	$post_ids = get_posts( array_merge( array(
		'post_type'              => $post_type,
		'post_status'            => 'publish',
		'posts_per_page'         => -1,
		'fields'                 => 'ids',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	), $extra_args ) );

	if ( empty( $post_ids ) ) {
		wp_cache_set( $cache_key, array(), 'conexao_filters', 300 );
		return array();
	}

	$terms = wp_get_object_terms( $post_ids, $taxonomy, array(
		'orderby' => 'name',
		'order'   => 'ASC',
	) );

	if ( is_wp_error( $terms ) ) {
		$terms = array();
	}

	wp_cache_set( $cache_key, $terms, 'conexao_filters', 300 );
	return $terms;
}

/**
 * Get the distinct provider categories that have at least one published
 * course provider.
 *
 * Course providers categorize via the _provider_category post meta (a string
 * label such as "Educação"), not via a taxonomy. This helper dynamically
 * discovers which categories are actually in use so the Cursos filter bar
 * never shows empty or irrelevant categories.
 *
 * Results are cached in the object cache for 5 minutes.
 *
 * @return array Array of associative arrays with 'name' and 'slug' keys.
 */
function conexao_get_provider_categories() {
	$cache_key = 'conexao_provider_categories';
	$cached    = wp_cache_get( $cache_key, 'conexao_filters' );

	if ( false !== $cached ) {
		return $cached;
	}

	$providers = get_posts( array(
		'post_type'              => 'course_provider',
		'post_status'            => 'publish',
		'posts_per_page'         => -1,
		'meta_query'             => array(
			array(
				'key'     => '_provider_status',
				'value'   => 'published',
				'compare' => '=',
			),
		),
		'no_found_rows'          => true,
		'update_post_meta_cache' => true,
		'update_post_term_cache' => false,
	) );

	if ( empty( $providers ) ) {
		wp_cache_set( $cache_key, array(), 'conexao_filters', 300 );
		return array();
	}

	$seen   = array();
	$result = array();

	foreach ( $providers as $provider ) {
		$category = get_post_meta( $provider->ID, '_provider_category', true );

		if ( $category && ! isset( $seen[ $category ] ) ) {
			$seen[ $category ] = true;
			$result[] = array(
				'name' => $category,
				'slug' => sanitize_title( $category ),
			);
		}
	}

	usort( $result, function( $a, $b ) {
		return strcasecmp( $a['name'], $b['name'] );
	} );

	wp_cache_set( $cache_key, $result, 'conexao_filters', 300 );
	return $result;
}

/**
 * Theme setup
 */
function conexao_theme_setup() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'custom-logo', array(
		'height'      => 80,
		'width'       => 240,
		'flex-height' => true,
		'flex-width'  => true,
	) );
	add_theme_support( 'html5', array(
		'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script',
	) );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'align-wide' );
	add_theme_support( 'wp-block-styles' );
	add_theme_support( 'editor-styles' );
	add_theme_support( 'customize-selective-refresh-widgets' );
	add_theme_support( 'custom-header', array(
		'default-image' => '', 'width' => 1920, 'height' => 400, 'flex-height' => true, 'flex-width' => true,
	) );
	add_theme_support( 'custom-background', array( 'default-color' => 'f5f7f8' ) );

	register_nav_menus( array(
		'primary' => __( 'Menu Principal', 'conexao-br-irlanda' ),
		'footer'  => __( 'Menu Rodapé', 'conexao-br-irlanda' ),
		'social'  => __( 'Menu Social', 'conexao-br-irlanda' ),
	) );

	$GLOBALS['content_width'] = 1200;
	load_theme_textdomain( 'conexao-br-irlanda', CONEXAO_THEME_DIR . '/languages' );
}
add_action( 'after_setup_theme', 'conexao_theme_setup' );

/**
 * Register widget areas
 */
function conexao_widgets_init() {
	$sidebars = array(
		'sidebar-1' => __( 'Sidebar Principal', 'conexao-br-irlanda' ),
		'footer-1'  => __( 'Rodapé - Coluna 1', 'conexao-br-irlanda' ),
		'footer-2'  => __( 'Rodapé - Coluna 2', 'conexao-br-irlanda' ),
		'footer-3'  => __( 'Rodapé - Coluna 3', 'conexao-br-irlanda' ),
	);

	foreach ( $sidebars as $id => $name ) {
		register_sidebar( array(
			'name'          => $name,
			'id'            => $id,
			'description'   => $name,
			'before_widget' => '<section id="%1$s" class="widget %2$s">',
			'after_widget'  => '</section>',
			'before_title'  => '<h3 class="widget-title">',
			'after_title'   => '</h3>',
		) );
	}
}
add_action( 'widgets_init', 'conexao_widgets_init' );

/**
 * Enqueue scripts and styles
 */
/**
 * Return a cache-busting version for a theme asset based on its filemtime.
 *
 * The .htaccess applies `Cache-Control: public, max-age=31536000, immutable`
 * to CSS/JS. That is only safe when the asset URL changes whenever the file
 * changes. Using filemtime() guarantees the browser receives a new URL after
 * any edit, so the long-lived cache can never serve stale CSS/JS.
 *
 * @param string $relative_path Path relative to the theme directory (e.g. "assets/css/main.css").
 * @return string A version string (unix mtime) safe for the "ver" query arg.
 */
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
function conexao_disable_emoji() {
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );
	remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
	remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
	remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
}
add_action( 'init', 'conexao_disable_emoji' );

/**
 * Remove the global "wp-embed" script (saves ~2KB on every page).
 * The site does not use oEmbed embeds in the theme templates.
 */
function conexao_disable_embeds() {
	wp_deregister_script( 'wp-embed' );
}
add_action( 'wp_footer', 'conexao_disable_embeds' );

/**
 * Add fetchpriority="high" to the hero image (LCP element) on the front page.
 * WordPress 6.3+ supports fetchpriority natively; this is a safe fallback.
 */
function conexao_hero_fetchpriority( $html, $post_id ) {
	if ( is_front_page() && has_post_thumbnail( $post_id ) ) {
		$html = preg_replace( '/<img /', '<img fetchpriority="high" ', $html, 1 );
	}
	return $html;
}
add_filter( 'post_thumbnail_html', 'conexao_hero_fetchpriority', 10, 2 );

/**
 * Add explicit width/height to images that lack them (CLS prevention).
 * WordPress already adds width/height for registered sizes; this catches
 * any images output without dimensions.
 */
function conexao_add_image_dimensions( $html ) {
	if ( ! $html || is_admin() ) {
		return $html;
	}

	// Only process <img> tags without width/height attributes.
	if ( preg_match( '/<img(?![^>]*\bwidth=)[^>]*>/i', $html, $matches ) ) {
		$img = $matches[0];
		if ( preg_match( '/src="([^"]+)"/', $img, $src_match ) ) {
			$src = $src_match[1];
			$size = @getimagesize( $src );
			if ( $size ) {
				$html = str_replace( $img, preg_replace( '/\/?>/', ' width="' . $size[0] . '" height="' . $size[1] . '" />', $img, 1 ), $html );
			}
		}
	}

	return $html;
}
add_filter( 'the_content', 'conexao_add_image_dimensions', 20 );

/**
 * Add loading="lazy" to content images that don't have it.
 * The hero image is handled separately (eager + fetchpriority).
 */
function conexao_lazy_content_images( $content ) {
	if ( is_admin() || is_feed() ) {
		return $content;
	}

	// Only add loading="lazy" to images that don't already have it.
	$content = preg_replace(
		'/<img(?![^>]*\bloading=)[^>]*>/i',
		'<img loading="lazy"$0',
		$content
	);

	return $content;
}
add_filter( 'the_content', 'conexao_lazy_content_images', 20 );

/**
 * Homepage query cache.
 *
 * The homepage runs 8 WP_Query calls. Cache the results in transients for
 * 5 minutes to avoid re-running expensive meta queries on every page load.
 * The cache is invalidated whenever any of the relevant CPTs are saved.
 */
function conexao_homepage_query( $args, $cache_key, $expiration = 300 ) {
	$cached = get_transient( $cache_key );
	if ( false !== $cached ) {
		return $cached;
	}

	$query = new WP_Query( $args );
	$posts = $query->posts;

	// Store minimal post data (ID, title, permalink, date, excerpt, thumbnail).
	$data = array();
	foreach ( $posts as $post ) {
		$data[] = array(
			'ID'         => $post->ID,
			'post_title' => $post->post_title,
			'post_type'  => $post->post_type,
			'post_date'  => $post->post_date,
			'post_excerpt' => $post->post_excerpt,
			'permalink'  => get_permalink( $post->ID ),
			'thumbnail'  => get_the_post_thumbnail_url( $post->ID, 'conexao-card' ),
			'thumbnail_hero' => get_the_post_thumbnail_url( $post->ID, 'conexao-hero' ),
		);
	}

	set_transient( $cache_key, $data, $expiration );
	return $data;
}

/**
 * Invalidate all transient caches that depend on portal content.
 *
 * Runs whenever a Guide, Event, Job, Apoiador or standard post is
 * published, updated, or deleted. This keeps:
 *  - the homepage card/featured/popular transients fresh,
 *  - the 404 page's guides/events transients fresh,
 *  - the per-post reading-time object-cache entry fresh.
 *
 * Transients store only public, non-user-specific data (ID, title, permalink,
 * date, excerpt, thumbnail URL), so no logged-in/admin data can leak. Keys are
 * predictable and unique per concern. Expiration is 5 minutes as a safety net
 * even if a save hook is missed.
 */
function conexao_homepage_cache_invalidate( $post_id ) {
	$post_type = get_post_type( $post_id );
	$cpt_types = array( 'guide', 'event', 'job', 'sponsor', 'course_provider', 'leisure', 'post' );
	if ( in_array( $post_type, $cpt_types, true ) ) {
		// Homepage sections.
		delete_transient( 'conexao_home_news' );
		delete_transient( 'conexao_home_events' );
		delete_transient( 'conexao_home_sponsors' );
		delete_transient( 'conexao_home_jobs' );
		delete_transient( 'conexao_home_featured' );
		delete_transient( 'conexao_home_popular' );
		delete_transient( 'conexao_home_latest' );

		// 404 page sections.
		delete_transient( 'conexao_404_guides' );
		delete_transient( 'conexao_404_events' );

		// Reading-time object-cache entry for this post.
		wp_cache_delete( 'conexao_reading_time_' . $post_id, 'conexao' );

		// Filter bar caches (per-content-type term/category lists).
		wp_cache_delete( 'conexao_terms_conexao_category_event', 'conexao_filters' );
		wp_cache_delete( 'conexao_terms_conexao_town_event', 'conexao_filters' );
		wp_cache_delete( 'conexao_terms_conexao_category_guide', 'conexao_filters' );
		wp_cache_delete( 'conexao_provider_categories', 'conexao_filters' );
	}
}
add_action( 'save_post', 'conexao_homepage_cache_invalidate' );
add_action( 'delete_post', 'conexao_homepage_cache_invalidate' );
add_action( 'wp_insert_post', 'conexao_homepage_cache_invalidate' );

/**
 * Limit REST API exposure: only expose the endpoints the theme actually uses.
 * The REST API is still fully functional for admin/editor use.
 */
function conexao_rest_api_optimize() {
	// Remove the global REST API link from the head.
	remove_action( 'wp_head', 'rest_output_link_wp_head' );
	remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
	remove_action( 'wp_head', 'wp_oembed_add_host_js' );
}
add_action( 'init', 'conexao_rest_api_optimize' );

/**
 * Remove the global "wp-block-library" CSS only when the current page does not
 * render Gutenberg blocks or the plugin's block-based shortcode pages.
 *
 * The theme templates (homepage, CPT archives, CPT singles, search, 404) are
 * fully custom and do not need block CSS — keeping it dequeued there saves
 * ~90KB on the most important pages. However, the "conexao-content" plugin
 * creates static pages (eventos, cursos, contato, blog) whose body
 * uses Gutenberg block markup (headings, lists, paragraphs, shortcodes). On
 * those pages we selectively restore the block-library CSS so the migrated
 * content keeps its intended styling. We do NOT blanket-restore wc-blocks-style
 * (WooCommerce is not used).
 */
function conexao_dequeue_block_library() {
	if ( is_admin() ) {
		return;
	}

	$needs_blocks = false;

	// A queried post whose content uses Gutenberg blocks.
	if ( is_singular() ) {
		$post = get_queried_object();
		if ( $post && ! empty( $post->post_content ) ) {
			$needs_blocks = has_blocks( $post->post_content );
		}
	}

	// The plugin's shortcodes render block-styled card grids.
	if ( ! $needs_blocks && is_singular() ) {
		$post = get_queried_object();
		if ( $post && ( has_shortcode( $post->post_content, 'conexao_grid' ) || has_shortcode( $post->post_content, 'conexao_blog_categories' ) || has_shortcode( $post->post_content, 'conexao_course_providers' ) ) ) {
			$needs_blocks = true;
		}
	}

	// Front page may host block content in the future; keep it lightweight now.
	if ( ! $needs_blocks ) {
		wp_dequeue_style( 'wp-block-library' );
		wp_dequeue_style( 'wp-block-library-theme' );
		wp_dequeue_style( 'wc-blocks-style' );
	}
}
/**
 * Dequeue the "Gravatar Enhanced" pattern stylesheets on the frontend.
 *
 * PageSpeed audit evidence: gravatar-enhanced-patterns-shared,
 * -edit and -view load as render-blocking CSS on every public page,
 * including the homepage — yet the theme and all custom templates
 * contain no Gravatar pattern blocks (comments use core get_avatar(),
 * which needs no stylesheet). The "-edit" sheet is editor-only markup
 * leaking into the public head.
 *
 * They are only kept when the queried singular content actually embeds
 * a Gravatar block, so a future block-based page keeps working.
 */
function conexao_dequeue_gravatar_patterns() {
	if ( is_admin() ) {
		return;
	}

	$content = '';
	if ( is_singular() ) {
		$post = get_queried_object();
		if ( $post && ! empty( $post->post_content ) ) {
			$content = $post->post_content;
		}
	}

	if ( false === strpos( $content, 'gravatar' ) ) {
		wp_dequeue_style( 'gravatar-enhanced-patterns-shared' );
		wp_dequeue_style( 'gravatar-enhanced-patterns-edit' );
		wp_dequeue_style( 'gravatar-enhanced-patterns-view' );
	}
}
add_action( 'wp_enqueue_scripts', 'conexao_dequeue_gravatar_patterns', 100 );

/**
 * Remove emoji scripts/styles (saves ~15KB of JS/CSS on every page).
add_action( 'wp_enqueue_scripts', 'conexao_dequeue_block_library', 100 );

/**
 * Editor styles
 */
function conexao_editor_styles() {
	add_editor_style( array(
		'assets/css/editor.css',
		'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@400;500;600;700;800&display=swap',
	) );
}
add_action( 'after_setup_theme', 'conexao_editor_styles' );

/**
 * Custom excerpt
 */
function conexao_excerpt_length( $length ) {
	return is_admin() ? $length : 30;
}
add_filter( 'excerpt_length', 'conexao_excerpt_length' );

function conexao_excerpt_more( $more ) {
	return '&hellip;';
}
add_filter( 'excerpt_more', 'conexao_excerpt_more' );

/**
 * Body classes
 */
function conexao_body_classes( $classes ) {
	if ( is_singular() ) $classes[] = 'singular';
	if ( is_home() || is_archive() || is_search() ) $classes[] = 'blog-page';
	if ( is_front_page() ) $classes[] = 'front-page';
	return $classes;
}
add_filter( 'body_class', 'conexao_body_classes' );

/**
 * Reading time
 * Cached per-post to avoid repeated get_post_field() DB calls on card grids.
 */
function conexao_reading_time() {
	$post_id = get_the_ID();
	$cached  = wp_cache_get( 'conexao_reading_time_' . $post_id, 'conexao' );
	if ( false !== $cached ) {
		return $cached;
	}

	$content = get_post_field( 'post_content', $post_id );
	$words   = str_word_count( strip_tags( $content ) );
	$minutes = max( 1, ceil( $words / 200 ) );

	wp_cache_set( 'conexao_reading_time_' . $post_id, $minutes, 'conexao', 300 );
	return $minutes;
}

function conexao_reading_time_text() {
	$minutes = conexao_reading_time();
	return sprintf( _n( '%d min de leitura', '%d min de leitura', $minutes, 'conexao-br-irlanda' ), $minutes );
}

/**
 * Post meta
 */
function conexao_post_meta() {
	$time_string = sprintf(
		'<time class="entry-date published updated" datetime="%1$s">%2$s</time>',
		esc_attr( get_the_date( DATE_W3C ) ),
		esc_html( get_the_date() )
	);
	printf( '<span class="posted-on">%1$s</span>', $time_string );
	if ( ! is_singular() ) {
		printf( '<span class="reading-time">%1$s</span>', esc_html( conexao_reading_time_text() ) );
	}
}

/**
 * Share buttons (canonical component, shared by Blog posts and Guias).
 *
 * The markup lives in template-parts/share-buttons.php so Blog and Guias
 * always render the exact same component. Call this function (or
 * get_template_part( 'template-parts/share-buttons' )) from within The Loop.
 */
function conexao_share_buttons() {
	if ( ! is_singular( array( 'post', 'guide' ) ) ) {
		return;
	}
	get_template_part( 'template-parts/share-buttons' );
}

/**
 * Disable the Jetpack/WordPress.com sharing module output.
 *
 * On WordPress.com the Jetpack Share module injects a duplicate sharing UI
 * (div.sd-sharing via sharing_display) into post content, which duplicates
 * the theme's canonical .share-buttons component. Remove only that output —
 * no other Jetpack functionality is touched.
 */
function conexao_disable_jetpack_sharing() {
	if ( function_exists( 'sharing_display' ) ) {
		remove_filter( 'the_content', 'sharing_display', 19 );
		remove_filter( 'the_excerpt', 'sharing_display', 19 );
	}
	if ( function_exists( 'sharing_add_header' ) ) {
		// Jetpack's Share module CSS/JS header block (only rendered when
		// sharing buttons are displayed — safe to drop alongside the output).
		remove_action( 'wp_head', 'sharing_add_header', 1 );
	}
}
add_action( 'init', 'conexao_disable_jetpack_sharing', 20 );

/**
 * Related posts
 */
function conexao_related_posts() {
	$categories = wp_get_post_categories( get_the_ID() );
	if ( empty( $categories ) ) return;

	$related = new WP_Query( array(
		'category__in'        => $categories,
		'post__not_in'        => array( get_the_ID() ),
		'posts_per_page'      => 3,
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	) );

	if ( $related->have_posts() ) : ?>
		<div class="related-posts">
			<h3 class="related-posts-title"><?php esc_html_e( 'Posts Relacionados', 'conexao-br-irlanda' ); ?></h3>
			<div class="related-posts-grid">
				<?php while ( $related->have_posts() ) : $related->the_post(); ?>
					<article class="related-post-card">
						<?php if ( has_post_thumbnail() ) : ?>
							<a href="<?php the_permalink(); ?>" class="related-post-thumb"><?php the_post_thumbnail( 'medium', array( 'loading' => 'lazy' ) ); ?></a>
						<?php endif; ?>
						<div class="related-post-content">
							<h4 class="related-post-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h4>
							<span class="related-post-date"><?php echo esc_html( get_the_date() ); ?></span>
						</div>
					</article>
				<?php endwhile; ?>
			</div>
		</div>
	<?php endif;
	wp_reset_postdata();
}

/**
 * "Mais Lidos" (most read) query.
 *
 * The homepage previously ordered by `comment_count`, which requires a full
 * table scan on wp_posts and becomes expensive as the portal grows. Views are
 * recorded server-side by inc/post-views.php into the `_conexao_view_count`
 * post meta (one increment per front-end content page view).
 *
 * Content scope: ONLY Blog (`post`) and Guias (`guide`). This is an
 * informational-content ranking — events, jobs, apoiadores, cursos and lazer
 * are never included, even when they have higher view counts. The scope is
 * read from conexao_view_count_post_types() (inc/post-views.php) so the
 * counting gate and this ranking can never drift apart.
 *
 * Architecture:
 *   post ID → _conexao_view_count (meta, written by inc/post-views.php)
 *   → ORDER BY meta_value_num DESC
 *
 * Posts with view counts sort first (meta_value_num DESC); content never
 * visited yet falls back to "recent content" (date DESC) so the homepage
 * keeps working without an expensive query. Results are transient-cached for
 * 5 minutes and invalidated on save/delete (see conexao_homepage_cache_invalidate()).
 *
 * @param int $limit Number of items to return (default 5).
 * @return array List of post IDs, most read first.
 */
function conexao_popular_posts( $limit = 5 ) {
	$limit     = max( 1, absint( $limit ) );
	$cache_key = 'conexao_home_popular';
	$scopes    = conexao_view_count_post_types();

	$cached = get_transient( $cache_key );
	if ( false !== $cached ) {
		return array_slice( $cached, 0, $limit );
	}

	// Preferred: order by the cached view-count meta when it exists.
	// This is intentionally a single indexed meta query, not a JOIN over
	// comment counts. It returns nothing measurable until the meta is set,
	// so we fall through to the lightweight recent-content query below.
	$by_views = new WP_Query( array(
		'post_type'              => $scopes,
		'posts_per_page'         => $limit,
		'meta_key'               => '_conexao_view_count',
		'orderby'                => 'meta_value_num',
		'order'                  => 'DESC',
		'ignore_sticky_posts'    => true,
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	) );

	$ids = array();
	if ( $by_views->have_posts() ) {
		$ids = wp_list_pluck( $by_views->posts, 'ID' );
	}

	// Fallback: recent content (lightweight, no ORDER BY comment_count).
	if ( empty( $ids ) ) {
		$recent = new WP_Query( array(
			'post_type'              => $scopes,
			'posts_per_page'         => $limit,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );
		if ( $recent->have_posts() ) {
			$ids = wp_list_pluck( $recent->posts, 'ID' );
		}
	}

	set_transient( $cache_key, $ids, 300 );
	return array_slice( $ids, 0, $limit );
}

/**
 * Latest Blog posts for the homepage "Ultimas Novidades" section.
 *
 * Answers "what's new?" and is intentionally distinct from
 * conexao_popular_posts() ("Mais Lidos" = what's popular): this
 * ranks strictly by publication date, newest first, and sources
 * Blog posts (post) ONLY — no Guias, Eventos, Cursos, Lazer,
 * Empregos or Apoiadores.
 *
 * Architecture mirrors conexao_popular_posts(): a single bounded
 * query stores only the post IDs in a transient (5 minutes,
 * invalidated on save/delete via conexao_homepage_cache_invalidate()),
 * so publishing a new post makes it appear without a manual cache
 * purge. The rendering query in front-page.php re-fetches by ID
 * (post__in) so cards keep the full responsive-image treatment.
 *
 * @param int $limit Number of posts to return (default 3).
 * @return array List of post IDs, newest first.
 */
function conexao_latest_blog_posts( $limit = 3 ) {
	$limit     = max( 1, absint( $limit ) );
	$cache_key = 'conexao_home_latest';

	$cached = get_transient( $cache_key );
	if ( false !== $cached ) {
		return array_slice( $cached, 0, $limit );
	}

	$query = new WP_Query( array(
		'post_type'              => 'post',
		'post_status'            => 'publish',
		'posts_per_page'         => $limit,
		'orderby'                => 'date',
		'order'                  => 'DESC',
		'ignore_sticky_posts'    => true,
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	) );

	$ids = array();
	if ( $query->have_posts() ) {
		$ids = wp_list_pluck( $query->posts, 'ID' );
	}

	set_transient( $cache_key, $ids, 300 );
	return array_slice( $ids, 0, $limit );
}

/**
 * Resolve an Apoiador's canonical image attachment ID.
 *
 * Data model (see docs/content-model.md):
 *
 *   _sponsor_image → single portrait "Imagem do Apoiador" used at every
 *                    breakpoint (desktop carousel, mobile carousel, detail
 *                    page).
 *
 * Backward compatibility with records created before the consolidation — no
 * administrator action is ever required for existing Apoiadores to keep
 * working:
 *
 *   _sponsor_image → _sponsor_mobile_image → _sponsor_desktop_image →
 *   legacy _sponsor_logo → featured image
 *
 * All values are Media Library attachment IDs. URL-only legacy values cannot
 * identify an attachment reliably and are ignored here; the admin editor
 * migrates them into attachment IDs on the next save.
 *
 * Only real image attachments qualify; anything else resolves to the next
 * candidate so a deleted attachment can never render as a broken image.
 *
 * @param int $sponsor_id Sponsor post ID.
 * @return int Attachment ID (0 = none).
 */
function conexao_sponsor_image_id( $sponsor_id ) {
	$candidates = array(
		absint( get_post_meta( $sponsor_id, '_sponsor_image', true ) ),
		absint( get_post_meta( $sponsor_id, '_sponsor_mobile_image', true ) ),
		absint( get_post_meta( $sponsor_id, '_sponsor_desktop_image', true ) ),
	);

	$legacy = get_post_meta( $sponsor_id, '_sponsor_logo', true );
	if ( $legacy && ctype_digit( (string) $legacy ) ) {
		$candidates[] = absint( $legacy );
	}

	$candidates[] = absint( get_post_thumbnail_id( $sponsor_id ) );

	foreach ( $candidates as $candidate_id ) {
		if ( $candidate_id && wp_attachment_is_image( $candidate_id ) ) {
			return $candidate_id;
		}
	}

	return 0;
}

/**
 * Build the responsive image markup for an Apoiador carousel slide.
 *
 * ONE canonical portrait asset ("Imagem do Apoiador") serves every
 * breakpoint — desktop and mobile share the same artwork and the same visual
 * portrait treatment; there is no responsive source switching between
 * different Apoiador images. WordPress still serves appropriately sized
 * derivatives via srcset/sizes so artwork stays crisp at the enlarged display
 * scale without downloading oversized files.
 *
 * Alt text comes from the attachment's alt field and falls back to the
 * sponsor name, so screen readers always announce the supporter exactly once.
 *
 * width/height attributes carry truthful intrinsic metadata of the served
 * size only; CSS fully determines the rendered box, so they never stretch or
 * resize anything (same model as the hero background picture).
 *
 * @param int    $sponsor_id    Sponsor post ID.
 * @param string $sponsor_title Sponsor name (alt-text fallback).
 * @return string Image HTML, or '' when the sponsor has no usable image.
 */
function conexao_sponsor_carousel_image( $sponsor_id, $sponsor_title = '', $eager = false ) {
	$attachment_id = conexao_sponsor_image_id( $sponsor_id );

	if ( ! $attachment_id ) {
		return '';
	}

	// Request the proportional 'medium' (300px) derivative as the src so the
	// srcset ladder keeps EVERY candidate ≥300px — including the dedicated
	// 512px 'conexao-sponsor-tile' derivative — and lets `sizes` below pick
	// the right one per viewport. (wp_get_attachment_image_srcset() excludes
	// candidates smaller than the requested size, so requesting the 512px
	// tile directly would drop the 300px rung.)
	$size = 'medium';
	$src  = wp_get_attachment_image_url( $attachment_id, $size );
	if ( ! $src ) {
		return '';
	}

	// Meaningful alt text with a sponsor-name fallback.
	$alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
	if ( '' === $alt ) {
		$alt = $sponsor_title;
	}

	$dimensions = wp_get_attachment_image_src( $attachment_id, $size );
	$srcset     = wp_get_attachment_image_srcset( $attachment_id, $size );

	$html = '<img src="' . esc_url( $src ) . '"';
	if ( $srcset ) {
		$html .= ' srcset="' . esc_attr( $srcset ) . '"';
	}
	// Actual tile width hint: on desktop the Hero's right column is ~30vw;
	// on mobile the portrait tile is roughly 46% of the content width. With
	// the 512px 'conexao-sponsor-tile' derivative registered, this keeps the
	// browser on the small candidate instead of the 768/960px originals —
	// the tile never renders wider than ~350 CSS px, so DPR-2 needs at most
	// ~700 device px and 512 is the closest adequate candidate.
	$html .= ' sizes="(min-width: 769px) 30vw, 46vw"';
	if ( $dimensions ) {
		$html .= ' width="' . esc_attr( (int) $dimensions[1] ) . '"'
			. ' height="' . esc_attr( (int) $dimensions[2] ) . '"';
	}
	// Above-the-fold usage (homepage Hero carousel, first slide) loads
	// eagerly; every other usage stays lazy. The Hero renders its first
	// slide immediately on page load, so lazy-loading it only delays
	// paint of visible content — no bandwidth is ever saved.
	$loading_attr = $eager ? 'loading="eager" decoding="async"' : 'loading="lazy" decoding="async"';

	$html .= ' alt="' . esc_attr( $alt ) . '" ' . $loading_attr . ' />';

	return $html;
}

/**
 * Featured Apoiadores for the homepage carousel.
 *
 * Fully data-driven from the EXISTING sponsor post type and its editorial
 * fields — no new content model and no duplicated supporter data:
 *
 *   - "Apoiador em destaque" (_sponsor_featured = 1) decides WHAT appears.
 *   - "Ordem de exibição"   (_sponsor_display_order) decides WHERE it appears.
 *
 * Editing a supporter in wp-admin therefore updates the homepage carousel
 * automatically; no code change is ever required.
 *
 * Ordering is deterministic so the carousel never shuffles between loads:
 *   1. _sponsor_display_order ascending (numeric; supporters without a
 *      value sort last so they are never hidden just because the field
 *      was left blank),
 *   2. title ascending as tiebreaker for equal order values.
 *
 * Results are transient-cached under "conexao_home_sponsors" — the exact
 * key conexao_homepage_cache_invalidate() already deletes whenever any
 * sponsor is saved, updated or deleted — so admin edits appear on the
 * homepage immediately (with a 5-minute safety-net expiration).
 *
 * @return array[] List of normalized sponsor card data arrays.
 */
function conexao_get_featured_sponsors() {
	$cache_key = 'conexao_home_sponsors';
	$cached    = get_transient( $cache_key );

	if ( false !== $cached && is_array( $cached ) ) {
		return $cached;
	}

	$query = new WP_Query( array(
		'post_type'      => 'sponsor',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'no_found_rows'  => true,
		'meta_query'     => array(
			array(
				'key'     => '_sponsor_featured',
				'value'   => '1',
				'compare' => '=',
			),
		),
	) );

	$sponsors = array();

	if ( $query->have_posts() ) {
		foreach ( $query->posts as $sponsor_index => $sponsor_post ) {
			$sponsor_id = $sponsor_post->ID;
			$link       = get_post_meta( $sponsor_id, '_sponsor_link', true );

			$terms    = get_the_terms( $sponsor_id, 'conexao_category' );
			$category = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : '';

			$counties = get_the_terms( $sponsor_id, 'conexao_county' );
			$county   = ( $counties && ! is_wp_error( $counties ) ) ? $counties[0]->name : '';

			// Mirror get_the_excerpt(): manual excerpt wins, otherwise derive
			// one from the content. Trimmed to the same 12 words the homepage
			// card has always displayed.
			$excerpt = trim( (string) $sponsor_post->post_excerpt );
			if ( '' === $excerpt ) {
				$excerpt = wp_strip_all_tags( $sponsor_post->post_content );
			}

			$sponsors[] = array(
				'title'         => $sponsor_post->post_title,
				'permalink'     => get_permalink( $sponsor_id ),
				'url'           => $link,
				'category'      => $category,
				'county'        => $county,
				'description'   => wp_trim_words( $excerpt, 12, '...' ),
				// Responsive artwork: a <picture> that serves the portrait
				// Imagem Mobile at ≤768px and the landscape Imagem Desktop
				// above it (see conexao_sponsor_carousel_image()). Requested
				// at the "large" registered size (falls back to the original
				// file when smaller) with srcset candidates so logos stay
				// crisp at the enlarged display scale. CSS object-fit:contain
				// still preserves every asset's natural proportions inside
				// its breakpoint-specific tile frame.
				// The Hero renders slide 0 as soon as the page paints, so
				// the first image loads eagerly; the rest stay lazy.
				'image'         => conexao_sponsor_carousel_image( $sponsor_id, $sponsor_post->post_title, 0 === $sponsor_index ),
				'display_order' => get_post_meta( $sponsor_id, '_sponsor_display_order', true ),
			);
		}
		wp_reset_postdata();
	}

	// Deterministic ordering: Ordem de exibição ascending (numeric), ties
	// broken by title ascending.
	usort( $sponsors, function( $a, $b ) {
		$order_a = ( '' !== (string) $a['display_order'] ) ? (int) $a['display_order'] : PHP_INT_MAX;
		$order_b = ( '' !== (string) $b['display_order'] ) ? (int) $b['display_order'] : PHP_INT_MAX;

		if ( $order_a === $order_b ) {
			return strcasecmp( $a['title'], $b['title'] );
		}

		return ( $order_a < $order_b ) ? -1 : 1;
	} );

	set_transient( $cache_key, $sponsors, 300 );

	return $sponsors;
}

/**
 * Normalize the contact rows displayed on an Apoiador detail page.
 *
 * Merges the TWO existing contact sources into one ordered display list
 * without duplicating data or changing how anything is stored:
 *
 *   1. `_sponsor_link`     — the canonical/official website. Rendered FIRST
 *      so long-standing records that only have this field keep working
 *      unchanged (backwards compatibility).
 *   2. `_sponsor_contacts` — the structured repeater rows managed by the
 *      admin editor (see Conexao_Data_Model_Contacts), rendered in their
 *      stored (admin-curated) order.
 *
 * A Website-type repeater row pointing at the SAME address as
 * `_sponsor_link` is skipped, so one URL never renders twice. Malformed
 * rows are dropped defensively. Every returned row is directly usable as
 * a link href (mailto: included) and carries an `external` flag telling
 * the template whether the link leaves the site (target="_blank").
 *
 * @param int $post_id Sponsor post ID.
 * @return array<int,array{type:string,url:string,label:string,external:bool}>
 */
function conexao_sponsor_contact_rows( $post_id ) {
	$post_id = absint( $post_id );
	if ( ! $post_id ) {
		return array();
	}

	$website = get_post_meta( $post_id, '_sponsor_link', true );
	$stored  = class_exists( 'Conexao_Data_Model_Contacts' )
		? Conexao_Data_Model_Contacts::get( $post_id )
		: array();

	$rows = array();
	$seen = array();

	// The official website leads the list (backwards compatibility:
	// an Apoiador with only `_sponsor_link` still gets a valid,
	// complete-looking contact section).
	if ( $website ) {
		$rows[] = array(
			'type'     => 'website',
			'url'      => $website,
			'label'    => __( 'Website', 'conexao-br-irlanda' ),
			'external' => true,
		);
		$seen[ conexao_contact_dedupe_key( $website ) ] = true;
	}

	foreach ( $stored as $row ) {
		$type = isset( $row['type'] ) ? sanitize_key( $row['type'] ) : '';
		$url  = isset( $row['url'] ) ? trim( (string) $row['url'] ) : '';

		if ( '' === $type || '' === $url ) {
			continue;
		}

		$key = conexao_contact_dedupe_key( $url );
		if ( isset( $seen[ $key ] ) ) {
			continue;
		}
		$seen[ $key ] = true;

		$rows[] = array(
			'type'     => $type,
			'url'      => $url,
			'label'    => conexao_contact_label( $type, $url ),
			// mailto: links stay in the same tab; everything else leaves
			// the site and is flagged for target="_blank" treatment.
			'external' => 0 !== stripos( $url, 'mailto:' ),
		);
	}

	return $rows;
}

/**
 * Human-readable label for a contact row on the frontend.
 *
 * Platform names come from the data model's canonical type labels when the
 * plugin is active (with a static fallback so the theme degrades gracefully
 * on its own). An "Outro" row has no meaningful platform name, so the
 * destination hostname is shown instead — informative, yet never a raw URL.
 *
 * @param string $type Contact type slug.
 * @param string $url  Stored contact value (http(s)/mailto).
 * @return string
 */
function conexao_contact_label( $type, $url ) {
	if ( class_exists( 'Conexao_Data_Model_Contacts' ) ) {
		$label = Conexao_Data_Model_Contacts::type_label( $type );
	} else {
		$labels = array(
			'website'   => 'Website',
			'instagram' => 'Instagram',
			'facebook'  => 'Facebook',
			'whatsapp'  => 'WhatsApp',
			'linkedin'  => 'LinkedIn',
			'tiktok'    => 'TikTok',
			'email'     => 'E-mail',
			'outro'     => 'Outro',
		);
		$label = isset( $labels[ $type ] ) ? $labels[ $type ] : 'Outro';
	}

	if ( 'outro' === $type ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( $host ) {
			$label = preg_replace( '/^www\./i', '', $host );
		}
	}

	return $label;
}

/**
 * Scheme/host-normalized key used to avoid rendering the same contact
 * URL twice (e.g. `_sponsor_link` vs an identical Website repeater row).
 *
 * @param string $url Contact URL.
 * @return string
 */
function conexao_contact_dedupe_key( $url ) {
	$key = strtolower( trim( (string) $url ) );
	$key = preg_replace( '#^[a-z][a-z0-9+.\-]*://#i', '', $key );
	$key = preg_replace( '#^www\.#i', '', $key );

	return untrailingslashit( $key );
}

/**
 * Inline SVG icon for a contact type.
 *
 * Reuses the exact brand icon paths already used across the theme
 * (footer/header social links, share buttons) — no new icon library.
 * Brand marks (Instagram/Facebook/WhatsApp/LinkedIn/TikTok) are
 * fill-based; utility glyphs (globe/envelope/link) follow the theme's
 * stroke-based icon style. Output is static, escaped-safe markup.
 *
 * @param string $type Contact type slug.
 * @return string SVG markup (aria-hidden).
 */
function conexao_contact_icon( $type ) {
	switch ( $type ) {
		case 'instagram':
			return '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>';
		case 'facebook':
			return '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>';
		case 'whatsapp':
			return '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>';
		case 'linkedin':
			return '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>';
		case 'tiktok':
			return '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg>';
		case 'email':
			return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>';
		case 'outro':
			return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>';
		case 'website':
		default:
			return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>';
	}
}

/**
 * Customizer settings
 */
function conexao_customize_register( $wp_customize ) {
	// Colors Section
	$wp_customize->add_section( 'conexao_colors', array( 'title' => __( 'Cores do Portal', 'conexao-br-irlanda' ), 'priority' => 30 ) );
	$wp_customize->add_setting( 'conexao_primary_color', array( 'default' => '#0E6B3A', 'sanitize_callback' => 'sanitize_hex_color' ) );
	$wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, 'conexao_primary_color', array(
		'label' => __( 'Cor Primária (Verde)', 'conexao-br-irlanda' ), 'section' => 'conexao_colors',
	) ) );
	$wp_customize->add_setting( 'conexao_accent_color', array( 'default' => '#F68B1F', 'sanitize_callback' => 'sanitize_hex_color' ) );
	$wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, 'conexao_accent_color', array(
		'label' => __( 'Cor de Destaque (Laranja)', 'conexao-br-irlanda' ), 'section' => 'conexao_colors',
	) ) );

	// Social Section
	$wp_customize->add_section( 'conexao_social', array( 'title' => __( 'Redes Sociais', 'conexao-br-irlanda' ), 'priority' => 40 ) );
	$social_fields = array(
		'conexao_instagram' => array( __( 'Instagram URL', 'conexao-br-irlanda' ), 'https://www.instagram.com/conexaobr.ie/' ),
		'conexao_whatsapp'  => array( __( 'WhatsApp URL', 'conexao-br-irlanda' ), 'https://wa.me/353899451428' ),
		'conexao_facebook'  => array( __( 'Facebook URL', 'conexao-br-irlanda' ), '' ),
	);
	foreach ( $social_fields as $id => $data ) {
		$wp_customize->add_setting( $id, array( 'default' => $data[1], 'sanitize_callback' => 'esc_url_raw' ) );
		$wp_customize->add_control( $id, array( 'label' => $data[0], 'section' => 'conexao_social', 'type' => 'url' ) );
	}

	// Hero Section
	$wp_customize->add_section( 'conexao_hero', array( 'title' => __( 'Hero Section', 'conexao-br-irlanda' ), 'priority' => 35 ) );
	$wp_customize->add_setting( 'conexao_hero_title', array(
		'default' => __( 'Tudo que o brasileiro precisa para viver melhor na <span>Irlanda</span>', 'conexao-br-irlanda' ),
		'sanitize_callback' => 'wp_kses_post',
	) );
	$wp_customize->add_control( 'conexao_hero_title', array(
		'label' => __( 'Título do Hero', 'conexao-br-irlanda' ), 'section' => 'conexao_hero', 'type' => 'textarea',
	) );
	$wp_customize->add_setting( 'conexao_hero_subtitle', array(
		'default' => __( 'Conectando a comunidade brasileira com informações, eventos, guias práticos e muito mais.', 'conexao-br-irlanda' ),
		'sanitize_callback' => 'sanitize_textarea_field',
	) );
	$wp_customize->add_control( 'conexao_hero_subtitle', array(
		'label' => __( 'Subtítulo do Hero', 'conexao-br-irlanda' ), 'section' => 'conexao_hero', 'type' => 'textarea',
	) );
	// The front-page hero background is the committed theme asset
	// assets/images/conexaobr_Hero_image.png (loaded via
	// get_template_directory_uri() in front-page.php), so there is no
	// Customizer hero-image setting. The green text-safe zone and the
	// castle/family/flags composition are part of that fixed asset.

	// Footer Section
	$wp_customize->add_section( 'conexao_footer', array( 'title' => __( 'Rodapé', 'conexao-br-irlanda' ), 'priority' => 50 ) );
	$wp_customize->add_setting( 'conexao_footer_text', array(
		'default' => __( '© 2025 Conexão BR Irlanda. Todos os direitos reservados.', 'conexao-br-irlanda' ),
		'sanitize_callback' => 'wp_kses_post',
	) );
	$wp_customize->add_control( 'conexao_footer_text', array(
		'label' => __( 'Texto do Rodapé', 'conexao-br-irlanda' ), 'section' => 'conexao_footer', 'type' => 'textarea',
	) );
}
add_action( 'customize_register', 'conexao_customize_register' );

/**
 * Customizer CSS
 */
function conexao_customizer_css() {
	$primary = get_theme_mod( 'conexao_primary_color', '#0E6B3A' );
	$accent  = get_theme_mod( 'conexao_accent_color', '#F68B1F' );
	?>
	<style type="text/css">
		:root {
			--conexao-primary: <?php echo esc_attr( $primary ); ?>;
			--conexao-primary-dark: <?php echo esc_attr( conexao_darken_color( $primary, 20 ) ); ?>;
			--conexao-accent: <?php echo esc_attr( $accent ); ?>;
			--conexao-accent-dark: <?php echo esc_attr( conexao_darken_color( $accent, 20 ) ); ?>;
		}
	</style>
	<?php
}
add_action( 'wp_head', 'conexao_customizer_css' );

/**
 * Darken hex color
 */
function conexao_darken_color( $hex, $percent ) {
	$hex = ltrim( $hex, '#' );
	if ( strlen( $hex ) === 3 ) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	$r = hexdec( substr( $hex, 0, 2 ) );
	$g = hexdec( substr( $hex, 2, 2 ) );
	$b = hexdec( substr( $hex, 4, 2 ) );
	$r = max( 0, min( 255, $r - ( $r * $percent / 100 ) ) );
	$g = max( 0, min( 255, $g - ( $g * $percent / 100 ) ) );
	$b = max( 0, min( 255, $b - ( $b * $percent / 100 ) ) );
	return sprintf( '#%02x%02x%02x', $r, $g, $b );
}

/**
 * Build the Blog category filter URL.
 *
 * Blog categories are filtered on the Blog archive itself via the
 * ?categoria= query parameter (/blog/?categoria=slug) — the same pattern
 * as Guias — instead of navigating to the native WordPress
 * /category/{slug}/ archive. The archive base URL is resolved from the
 * configured posts page (page_for_posts) so it follows the permalink
 * rather than being hard-coded.
 *
 * @param string $category_slug Category slug. Empty returns the base archive URL.
 * @return string Absolute URL to the (optionally filtered) Blog archive.
 */
function conexao_blog_category_filter_url( $category_slug ) {
	$blog_url = get_permalink( (int) get_option( 'page_for_posts' ) );
	if ( ! is_string( $blog_url ) || '' === $blog_url ) {
		$blog_url = home_url( '/blog/' );
	}

	$category_slug = sanitize_title( $category_slug );
	if ( '' === $category_slug ) {
		return $blog_url;
	}

	return add_query_arg( 'categoria', $category_slug, $blog_url );
}

/**
 * Content-type archive query filtering.
 *
 * Each archive (Eventos, Cursos, Guias, Blog) applies its own filtering and ordering
 * to the main query:
 *
 *  - Eventos: only upcoming events (date >= today), ordered by date ascending,
 *    with optional ?cidade= (town) and ?categoria= (category) taxonomy filters.
 *  - Cursos: only published providers (_provider_status = published), ordered
 *    by display order, with optional ?categoria= (provider category meta) filter.
 *  - Guias: optional ?categoria= (conexao_category taxonomy) filter.
 *  - Blog (/blog/): optional ?categoria= (native `category` taxonomy) filter.
 *
 * The ?categoria= parameter is content-type-aware: on /eventos/ it filters by
 * the conexao_category taxonomy, on /cursos/ by the _provider_category meta,
 * and on /guias/ by the conexao_category taxonomy again. A filter from one
 * content type never produces results in another's archive.
 */
function conexao_content_archive_query( $query ) {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}

	/*
	 * Eventos: upcoming events ordered by date, with town/category filters.
	 */
	if ( $query->is_post_type_archive( 'event' ) ) {
		$query->set( 'meta_key', '_event_date' );
		$query->set( 'meta_value', current_time( 'Y-m-d' ) );
		$query->set( 'meta_compare', '>=' );
		$query->set( 'meta_type', 'DATE' );
		$query->set( 'orderby', 'meta_value' );
		$query->set( 'order', 'ASC' );

		$tax_query = $query->get( 'tax_query' );
		if ( ! is_array( $tax_query ) ) {
			$tax_query = array();
		}

		// Town/city filter via ?cidade=slug
		$town = isset( $_GET['cidade'] ) ? sanitize_title( wp_unslash( $_GET['cidade'] ) ) : '';
		if ( $town ) {
			$tax_query[] = array(
				'taxonomy' => 'conexao_town',
				'field'    => 'slug',
				'terms'    => $town,
			);
		}

		// Category filter via ?categoria=slug (e.g., "treinamento")
		$category = isset( $_GET['categoria'] ) ? sanitize_title( wp_unslash( $_GET['categoria'] ) ) : '';
		if ( $category ) {
			$tax_query[] = array(
				'taxonomy' => 'conexao_category',
				'field'    => 'slug',
				'terms'    => $category,
			);
		}

		if ( ! empty( $tax_query ) ) {
			$query->set( 'tax_query', $tax_query );
		}
	}

	/*
	 * Cursos: published providers, ordered by display order, with an
	 * optional ?categoria= filter that maps to the _provider_category meta.
	 */
	if ( $query->is_post_type_archive( 'course_provider' ) ) {
		$meta_query = $query->get( 'meta_query' );
		if ( ! is_array( $meta_query ) ) {
			$meta_query = array();
		}

		// Only show published providers on the public archive.
		$meta_query[] = array(
			'key'     => '_provider_status',
			'value'   => 'published',
			'compare' => '=',
		);

		// Category filter via ?categoria=<slug>.
		// Provider categories are stored as meta values (human-readable labels),
		// so the slug is resolved back to the label before querying.
		$category_slug = isset( $_GET['categoria'] ) ? sanitize_title( wp_unslash( $_GET['categoria'] ) ) : '';
		if ( $category_slug ) {
			$provider_categories = conexao_get_provider_categories();
			$matched_name        = '';

			foreach ( $provider_categories as $cat ) {
				if ( $cat['slug'] === $category_slug ) {
					$matched_name = $cat['name'];
					break;
				}
			}

			if ( $matched_name ) {
				$meta_query[] = array(
					'key'     => '_provider_category',
					'value'   => $matched_name,
					'compare' => '=',
				);
			} else {
				// No matching category — force no results so an irrelevant
				// filter never produces misleading content.
				$meta_query[] = array(
					'key'     => '_provider_category',
					'value'   => '__conexao_no_such_category__',
					'compare' => '=',
				);
			}
		}

		$query->set( 'meta_query', $meta_query );

		// Order by display order, then title.
		$query->set( 'meta_key', '_provider_order' );
		$query->set( 'orderby', 'meta_value_num title' );
		$query->set( 'order', 'ASC' );
	}

	/*
	 * Lazer: optional ?county= (conexao_county) and ?categoria=
	 * (conexao_category taxonomy) filters, combinable.
	 */
	if ( $query->is_post_type_archive( 'leisure' ) ) {
		$tax_query = $query->get( 'tax_query' );
		if ( ! is_array( $tax_query ) ) {
			$tax_query = array();
		}

		// Combine filters in a single nested relation so county + category work together.
		$county   = isset( $_GET['county'] ) ? sanitize_title( wp_unslash( $_GET['county'] ) ) : '';
		$category = isset( $_GET['categoria'] ) ? sanitize_title( wp_unslash( $_GET['categoria'] ) ) : '';

		if ( $county ) {
			$tax_query[] = array(
				'taxonomy' => 'conexao_county',
				'field'    => 'slug',
				'terms'    => $county,
			);
		}

		if ( $category ) {
			$tax_query[] = array(
				'taxonomy' => 'conexao_category',
				'field'    => 'slug',
				'terms'    => $category,
			);
		}

		if ( ! empty( $tax_query ) ) {
			if ( count( $tax_query ) > 1 ) {
				$tax_query['relation'] = 'AND';
			}
			$query->set( 'tax_query', $tax_query );
		}
	}

	/*
	 * Guias: optional ?categoria= filter via the conexao_category taxonomy.
	 */
	if ( $query->is_post_type_archive( 'guide' ) ) {
		$tax_query = $query->get( 'tax_query' );
		if ( ! is_array( $tax_query ) ) {
			$tax_query = array();
		}

		// Category filter via ?categoria=slug
		$category = isset( $_GET['categoria'] ) ? sanitize_title( wp_unslash( $_GET['categoria'] ) ) : '';
		if ( $category ) {
			$tax_query[] = array(
				'taxonomy' => 'conexao_category',
				'field'    => 'slug',
				'terms'    => $category,
			);
		}

		if ( ! empty( $tax_query ) ) {
			$query->set( 'tax_query', $tax_query );
		}
	}

	/*
	 * Blog (/blog/): optional ?categoria= filter via the native `category`
	 * taxonomy. Blog category links point at the Blog archive itself
	 * (/blog/?categoria=slug — see conexao_blog_category_filter_url())
	 * instead of the default /category/{slug}/ archive, so the same
	 * main-query filtering pattern used by Guias above applies here. The
	 * native category taxonomy and its archives remain fully intact for
	 * the rest of WordPress (wp-admin, feeds, direct /category/ URLs).
	 *
	 * The current Blog query is reused as-is (no extra query); pagination
	 * links carry the query string, so the filter is preserved on every
	 * page — including the infinite-scroll enhancement, which fetches the
	 * real /page/N/ URLs rendered by the pagination component.
	 */
	if ( $query->is_home() ) {
		$category = isset( $_GET['categoria'] ) ? sanitize_title( wp_unslash( $_GET['categoria'] ) ) : '';
		if ( $category ) {
			$query->set( 'category_name', $category );

			// WordPress prepends sticky posts on the posts page even when
			// they do not match the category filter — a filtered view must
			// never leak off-category sticky posts to the top.
			$query->set( 'ignore_sticky_posts', true );
		}
	}
}
add_action( 'pre_get_posts', 'conexao_content_archive_query' );

/**
 * Course Provider shortcode.
 *
 * Renders the Cursos directory: a category filter bar plus a grid of
 * course-provider cards. Each card links to the provider's external website
 * in a new browser tab. Providers are curated records (course_provider CPT) —
 * we intentionally do NOT list individual courses here.
 *
 * Optional attribute: [conexao_course_providers categories="Educação,Formação"]
 *
 * @param array $atts Shortcode attributes.
 * @return string HTML.
 */
function conexao_course_providers_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'categories' => '',
		),
		$atts,
		'conexao_course_providers'
	);

	// Provider categories. Prefer the Admin UX config when available, otherwise
	// fall back to this theme-local list so the directory always works.
	if ( class_exists( 'Conexao_Admin_Ux_Config' ) ) {
		$categories = Conexao_Admin_Ux_Config::provider_categories();
	} else {
		$categories = array(
			'Educação',
			'Formação Profissional',
			'Cursos Online',
			'Negócios',
			'Diretórios de Cursos',
		);
	}
	$current    = isset( $_GET['categoria'] ) ? sanitize_text_field( wp_unslash( $_GET['categoria'] ) ) : '';

	$args = array(
		'post_type'           => 'course_provider',
		'post_status'         => 'publish',
		'posts_per_page'      => -1,
		'no_found_rows'       => true,
		'update_post_meta_cache' => true,
		'update_post_term_cache' => false,
	);

	// Filter by provider status meta (published) to match the admin status model.
	$args['meta_query'] = array(
		array(
			'key'     => '_provider_status',
			'value'   => 'published',
			'compare' => '=',
		),
	);

	// Category filter via ?categoria=<slug> (slugified category label).
	if ( $current ) {
		$args['meta_query'][] = array(
			'key'   => '_provider_category',
			'value' => $current,
		);
	}

	// Optional comma-separated categories restriction from the shortcode.
	$allowed = array();
	if ( ! empty( $atts['categories'] ) ) {
		$allowed = array_map( 'trim', explode( ',', $atts['categories'] ) );
	}

	// Order by the display order meta, then title.
	$args['meta_key'] = '_provider_order';
	$args['orderby']  = 'meta_value_num title';
	$args['order']    = 'ASC';

	$query = new WP_Query( $args );

	if ( ! $query->have_posts() ) {
		return '';
	}

	// Build the category filter bar (Todos + provider categories).
	$filter = '<div class="events-filter-bar providers-filter-bar">';
	$filter .= '<span class="events-filter-label">' . esc_html__( 'Categorias', 'conexao-br-irlanda' ) . '</span>';
	$filter .= '<a class="events-filter-link' . ( $current ? '' : ' is-active' ) . '" href="' . esc_url( get_permalink() ) . '">' . esc_html__( 'Todos', 'conexao-br-irlanda' ) . '</a>';
	foreach ( $categories as $category ) {
		if ( $allowed && ! in_array( $category, $allowed, true ) ) {
			continue;
		}
		$slug = sanitize_title( $category );
		$url  = add_query_arg( 'categoria', $slug, get_permalink() );
		$filter .= '<a class="events-filter-link' . ( $current === $slug ? ' is-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $category ) . '</a>';
	}
	$filter .= '</div>';

	$html  = '<div class="provider-directory">';
	$html .= $filter;
	$html .= '<div class="provider-grid">';

	while ( $query->have_posts() ) {
		$query->the_post();
		ob_start();
		get_template_part( 'template-parts/provider', 'card' );
		$html .= ob_get_clean();
	}

	wp_reset_postdata();

	$html .= '</div></div>';
	return $html;
}
add_shortcode( 'conexao_course_providers', 'conexao_course_providers_shortcode' );


/**
 * Custom image sizes
 */
function conexao_image_sizes() {
	add_image_size( 'conexao-card', 400, 300, true );
	add_image_size( 'conexao-hero', 1200, 600, true );
	add_image_size( 'conexao-thumb', 200, 150, true );
	add_image_size( 'conexao-event-banner', 640, 360, true );
	// Homepage "Próximos Eventos" preview thumb (see template-parts/
	// event-preview.php). The preview renders at 76–92 CSS px on phones, so
	// the browser only needs a ~160–320 device-px 16:9 candidate; the 640×360
	// banner above is kept as the desktop candidate via srcset.
	add_image_size( 'conexao-event-preview', 240, 135, true );
	// Homepage Hero Apoiador carousel tile (see
	// conexao_sponsor_carousel_image()). The tile renders ~170 CSS px wide on
	// phones and ~30vw on desktop; a 512px-wide proportional derivative
	// covers both without shipping the 768/960+ originals. Uncropped so the
	// canonical artwork keeps its natural ratio (CSS letterboxes via
	// object-fit: contain).
	add_image_size( 'conexao-sponsor-tile', 512 );
	add_image_size( 'conexao-provider-logo', 320, 180, false );

	// Empregos (Jobs) featured image — Instagram Story-style portrait.
	// Soft crop (fit within the box): portrait artwork keeps its full
	// composition (9:16 sources become 1080×1920, 2:3 become 1080×1620),
	// and legacy landscape images are constrained proportionally instead
	// of being cropped. Sources already smaller than the box fall back to
	// the original file, so existing Jobs need no regeneration.
	add_image_size( 'conexao-job-portrait', 1080, 1920, false );
}
add_action( 'after_setup_theme', 'conexao_image_sizes' );

function conexao_custom_image_sizes( $sizes ) {
	return array_merge( $sizes, array(
		'conexao-card'         => __( 'Card do Portal', 'conexao-br-irlanda' ),
		'conexao-hero'         => __( 'Hero do Portal', 'conexao-br-irlanda' ),
		'conexao-thumb'        => __( 'Miniatura do Portal', 'conexao-br-irlanda' ),
		'conexao-event-preview' => __( 'Prévia de Evento (16:9)', 'conexao-br-irlanda' ),
		'conexao-sponsor-tile'  => __( 'Tile do Apoiador (512px)', 'conexao-br-irlanda' ),
		'conexao-event-banner' => __( 'Banner de Evento', 'conexao-br-irlanda' ),
		'conexao-provider-logo' => __( 'Logo de Provedor de Cursos', 'conexao-br-irlanda' ),
		'conexao-job-portrait' => __( 'Vaga Vertical (Instagram)', 'conexao-br-irlanda' ),
	) );
}
add_filter( 'image_size_names_choose', 'conexao_custom_image_sizes' );

/**
 * Explain the expected featured-image format when editing a Job.
 *
 * Job artwork is authored vertically for Instagram Stories. This hint sets
 * expectations in the admin without blocking uploads of other dimensions.
 */
function conexao_job_featured_image_hint( $content, $post_id ) {
	if ( ! $post_id || 'job' !== get_post_type( $post_id ) ) {
		return $content;
	}

	$hint = '<p class="description">'
		. __( 'Imagem da vaga: use uma imagem vertical, preferencialmente 1080 × 1920 px (formato Instagram Stories).', 'conexao-br-irlanda' )
		. '</p>';

	return $hint . $content;
}
add_filter( 'admin_post_thumbnail_html', 'conexao_job_featured_image_hint', 10, 2 );


/**
 * WhatsApp floating button
 */
function conexao_whatsapp_button() {
	$whatsapp = get_theme_mod( 'conexao_whatsapp', 'https://wa.me/353899451428' );
	if ( empty( $whatsapp ) ) return;
	// Wrap in a complementary landmark (aside) so the fixed float is contained
	// by a landmark (axe `region`) instead of orphaned outside <main>/<footer>.
	// `position:fixed` on the child keeps it floating regardless of the wrapper.
	?>
	<aside class="whatsapp-float-region" aria-label="<?php esc_attr_e( 'Fale com a Conexão BR', 'conexao-br-irlanda' ); ?>">
	<a href="<?php echo esc_url( $whatsapp ); ?>" class="whatsapp-float" target="_blank" rel="noopener noreferrer" aria-label="<?php esc_attr_e( 'Fale conosco no WhatsApp', 'conexao-br-irlanda' ); ?>">
		<svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
	</a>
	</aside>
	<?php
}
add_action( 'wp_footer', 'conexao_whatsapp_button' );

/**
 * Skip link
 */
function conexao_skip_link() {
	echo '<a class="skip-link screen-reader-text" href="#primary">' . esc_html__( 'Pular para o conteúdo', 'conexao-br-irlanda' ) . '</a>';
}
add_action( 'wp_body_open', 'conexao_skip_link' );

/**
 * Pingback header
 */
function conexao_pingback_header() {
	if ( is_singular() && pings_open() ) {
		printf( '<link rel="pingback" href="%s">' . "\n", esc_url( get_bloginfo( 'pingback_url' ) ) );
	}
}
add_action( 'wp_head', 'conexao_pingback_header' );

/**
 * Nav menu classes
 */
function conexao_nav_menu_css_class( $classes, $item, $args ) {
	if ( 'primary' === $args->theme_location ) {
		$classes[] = 'nav-item';
		if ( in_array( 'menu-item-has-children', $classes, true ) ) {
			$classes[] = 'has-dropdown';
		}
	}
	return $classes;
}
add_filter( 'nav_menu_css_class', 'conexao_nav_menu_css_class', 10, 3 );

function conexao_nav_menu_link_attributes( $atts, $item, $args ) {
	if ( 'primary' === $args->theme_location ) {
		$atts['class'] = 'nav-link';
	}
	return $atts;
}
add_filter( 'nav_menu_link_attributes', 'conexao_nav_menu_link_attributes', 10, 3 );

function conexao_submenu_class( $classes ) {
	$classes[] = 'sub-menu';
	return $classes;
}
add_filter( 'nav_menu_submenu_css_class', 'conexao_submenu_class' );

/**
 * Set menu_class for primary navigation
 * Only applies when the default class is still set,
 * so explicit menu_class values (like mobile-menu) are preserved.
 */
function conexao_nav_menu_args( $args ) {
	if ( 'primary' === $args['theme_location'] && 'menu' === $args['menu_class'] ) {
		$args['menu_class'] = 'primary-menu';
	}
	return $args;
}
add_filter( 'wp_nav_menu_args', 'conexao_nav_menu_args' );

/**
 * Determine whether an event's URL points to an external website.
 *
 * Compares the stored `_event_url` meta (the original source URL) with the
 * event's own permalink. When they differ, the link is considered external
 * and should open in a new browser tab.
 *
 * @param int $event_id The event post ID.
 * @return bool True if the event URL is external, false otherwise.
 */
function conexao_is_external_event_url( $event_id ) {
	$event_url = get_post_meta( $event_id, '_event_url', true );

	if ( empty( $event_url ) ) {
		return false;
	}

	$permalink = get_permalink( $event_id );

	// If the stored URL differs from the permalink, it is external.
	return untrailingslashit( $event_url ) !== untrailingslashit( $permalink );
}

/**
 * Return the HTML target and rel attributes for external event links.
 *
 * Returns `target="_blank" rel="noopener noreferrer"` when the event URL is
 * external, or an empty string for internal links.
 *
 * @param int $event_id The event post ID.
 * @return string HTML attributes string (includes leading space when non-empty).
 */
function conexao_event_link_target_attrs( $event_id ) {
	if ( conexao_is_external_event_url( $event_id ) ) {
		return ' target="_blank" rel="noopener noreferrer"';
	}
	return '';
}

function conexao_override_guides_menu_links( $items, $args ) {
	if ( 'primary' !== $args->theme_location ) {
		return $items;
	}

	$guides_url = conexao_get_guides_archive_url();
	$legacy_url = untrailingslashit( home_url( '/guides/' ) );

	foreach ( $items as $item ) {
		$title = strtolower( trim( wp_strip_all_tags( $item->title ) ) );
		$item_url = untrailingslashit( $item->url );

		if ( 'guias' === $title || 'guias práticos' === $title || $legacy_url === $item_url || false !== strpos( $item_url, '/guides' ) ) {
			$item->url = $guides_url;
		}
	}

	return $items;
}
add_filter( 'wp_nav_menu_objects', 'conexao_override_guides_menu_links', 10, 2 );

/**
 * Modify the primary navigation at render time.
 *
 * Guarantees the "Notícias" item never appears, renames "Home" to "Início",
 * renames the "Lazer" label to "Lazer e turismo" (URL unchanged), inserts a
 * "Blog" item (linked to the existing /blog/ page) immediately after "Início",
 * and inserts a "Cursos" item (linked to the existing /courses/ page)
 * immediately before "Empregos", so the final order is:
 *
 *   Início, Blog, Guias, Eventos, Cursos, Empregos, Apoiadores, Contato
 *
 * Both the desktop nav and the mobile/hamburger menu render the 'primary'
 * theme location, so this single filter applies the change everywhere the
 * main navigation appears — no CSS hiding is involved.
 *
 * Active-state styling is delegated to WordPress' own menu logic
 * (_wp_menu_item_classes_by_context), so "Blog" and "Cursos" receive the same
 * current-menu-item/current_page_item underline as the other sections
 * without hard-coding any state.
 *
 * @param array    $items An array of menu item objects.
 * @param stdClass $args  An object containing wp_nav_menu() arguments.
 * @return array
 */
function conexao_modify_primary_nav_items( $items, $args ) {
	if ( 'primary' !== $args->theme_location ) {
		return $items;
	}

	// 1. Remove the "Notícias" item entirely from the main navigation.
	foreach ( $items as $key => $item ) {
		$title    = strtolower( trim( wp_strip_all_tags( $item->title ) ) );
		$item_url = untrailingslashit( (string) $item->url );

		$is_news = in_array( $title, array( 'notícias', 'noticias' ), true )
			|| false !== strpos( $item_url, '/noticias' )
			|| untrailingslashit( home_url( '/news' ) ) === $item_url;

		if ( $is_news ) {
			unset( $items[ $key ] );
		}
	}
	$items = array_values( $items );

	// 1b. Remove the "Sobre Nós" item entirely from the main navigation.
	//     The /sobre-nos/ page itself stays published and directly accessible;
	//     only its navigation entry is removed (desktop + mobile share this
	//     same 'primary' menu location).
	foreach ( $items as $key => $item ) {
		$title    = strtolower( trim( wp_strip_all_tags( $item->title ) ) );
		$item_url = untrailingslashit( (string) $item->url );

		$is_about = 'sobre nós' === $title
			|| 'sobre nos' === $title
			|| 'about us' === $title
			|| false !== strpos( $item_url, '/sobre-nos' )
			|| untrailingslashit( home_url( '/about-us' ) ) === $item_url;

		if ( $is_about ) {
			unset( $items[ $key ] );
		}
	}
	$items = array_values( $items );

	// 2. Change the "Home" label to "Início" (Portuguese-first portal).
	//    The URL is left untouched so the homepage link still works.
	foreach ( $items as $item ) {
		$title = strtolower( trim( wp_strip_all_tags( $item->title ) ) );
		if ( 'home' === $title || 'início' === $title || 'inicio' === $title ) {
			$item->title = 'Início';
		}
	}

	// 3. Rename the "Lazer" navigation label to "Lazer e turismo".
	//    Label-only change: the /lazer/ URL, the leisure post type and every
	//    other attribute stay untouched, so object binding, deduplication and
	//    active-state logic keep resolving this item to the "lazer" section.
	foreach ( $items as $item ) {
		$title = strtolower( trim( wp_strip_all_tags( $item->title ) ) );
		if ( 'lazer' === $title || 'leisure' === $title ) {
			$item->title = 'Lazer e turismo';
		}
	}

	// 4. Insert "Blog" immediately after "Início" (=> before "Guias").
	//    The Blog section uses the native WordPress posts archive at /blog/.
	//    We intentionally do NOT look up a Page with slug "blog" — a Page
	//    with that slug would shadow the posts archive and prevent published
	//    posts from appearing on /blog/.
	$blog_item = array(
		'ID'               => 0,
		'db_id'            => 0,
		'menu_item_parent' => 0,
		'object_id'        => 0,
		'object'           => 'custom',
		'post_parent'      => 0,
		'type'             => 'custom',
		'type_label'       => 'Custom Link',
		'title'            => 'Blog',
		'url'              => home_url( '/blog/' ),
		'classes'          => array( 'menu-item', 'menu-item-type-custom', 'menu-item-object-custom' ),
		'attr_title'       => '',
		'target'           => '',
		'xfn'              => '',
		'description'      => '',
		'menu_order'       => 0,
	);

	// Let WordPress compute the active/current classes using its own
	// queried-object/URL logic (current-menu-item, current_page_item, etc.).
	$blog_item_obj = (object) $blog_item;
	$blog_items_for_context = array( $blog_item_obj );
	_wp_menu_item_classes_by_context( $blog_items_for_context );
	$blog_item_obj = $blog_items_for_context[0];

	// Insert "Blog" immediately before "Guias" (=> after "Início").
	$insert_blog_at = null;
	foreach ( $items as $k => $item ) {
		$item_title = strtolower( trim( wp_strip_all_tags( $item->title ) ) );
		if ( 'guias' === $item_title || 'guias práticos' === $item_title ) {
			$insert_blog_at = $k;
			break;
		}
	}

	if ( null === $insert_blog_at ) {
		// If "Guias" not found, insert after "Início" (position 1).
		$insert_blog_at = 1;
	}

	array_splice( $items, $insert_blog_at, 0, array( $blog_item_obj ) );

	// "Cursos" is now handled by conexao_normalize_primary_nav_sections() (priority 25)
	// to avoid duplicate items in the navigation.
	return $items;
}
add_filter( 'wp_nav_menu_objects', 'conexao_modify_primary_nav_items', 20, 2 );

/**
 * Canonical WordPress object bindings for each primary navigation section.
 *
 * Each top-level section maps to the WordPress object that actually backs it
 * (a Page or a custom post type archive). Binding sections to real objects lets
 * WordPress' own menu-context logic — the same mechanism the reference "Cursos"
 * item uses (_wp_menu_item_classes_by_context) — decide when a section is
 * "current". This makes the active state work reliably for:
 *   - section landing pages,
 *   - custom post type archives (Guias, Eventos, Empregos, Apoiadores),
 *   - individual post detail pages (e.g. /events/event-name/ keeps Eventos active),
 *   - taxonomy/archive pages where the bound post type is queried,
 *   - child/descendant pages of a section page.
 *
 * @return array
 */
function conexao_primary_nav_sections() {
	$archive_url = function ( $post_type, $fallback_slug ) {
		$link = get_post_type_archive_link( $post_type );
		return $link ? $link : home_url( '/' . $fallback_slug . '/' );
	};

	return array(
		'início'     => array( 'key' => 'inicio', 'type' => 'custom', 'object' => 'custom', 'url' => home_url( '/' ), 'match' => array() ),
		// Blog uses the native posts archive at /blog/ (not a static page).
		'blog'       => array( 'key' => 'blog', 'type' => 'posts_archive', 'object' => 'post', 'url' => home_url( '/blog/' ), 'match' => array( 'blog' ) ),
		'guias'      => array( 'key' => 'guias', 'type' => 'post_type_archive', 'object' => 'guide', 'url' => $archive_url( 'guide', 'guias' ), 'match' => array( 'guias', 'guides' ) ),
		'eventos'    => array( 'key' => 'eventos', 'type' => 'post_type_archive', 'object' => 'event', 'url' => $archive_url( 'event', 'eventos' ), 'match' => array( 'eventos', 'events' ) ),
		// Cursos is a CPT archive (course_provider CPT), not a static page.
		'cursos'     => array( 'key' => 'cursos', 'type' => 'post_type_archive', 'object' => 'course_provider', 'url' => $archive_url( 'course_provider', 'cursos' ), 'match' => array( 'cursos', 'courses' ) ),
		// Lazer is a CPT archive (leisure CPT) at /lazer/. Its navigation label
		// is "Lazer e turismo"; both labels resolve to the same section spec.
		'lazer'           => array( 'key' => 'lazer', 'type' => 'post_type_archive', 'object' => 'leisure', 'url' => $archive_url( 'leisure', 'lazer' ), 'match' => array( 'lazer', 'leisure' ) ),
		'lazer e turismo' => array( 'key' => 'lazer', 'type' => 'post_type_archive', 'object' => 'leisure', 'url' => $archive_url( 'leisure', 'lazer' ), 'match' => array( 'lazer', 'leisure' ) ),
		'empregos'   => array( 'key' => 'empregos', 'type' => 'post_type_archive', 'object' => 'job', 'url' => $archive_url( 'job', 'empregos' ), 'match' => array( 'empregos', 'jobs' ) ),
		'apoiadores' => array( 'key' => 'apoiadores', 'type' => 'post_type_archive', 'object' => 'sponsor', 'url' => $archive_url( 'sponsor', 'apoiadores' ), 'match' => array( 'apoiadores', 'sponsors', 'sponsor' ) ),
		'irlanda'    => array( 'key' => 'irlanda', 'type' => 'page', 'object' => 'page', 'path' => 'irlanda', 'match' => array( 'irlanda', 'ireland' ) ),
		'sobre nós'  => array( 'key' => 'sobre-nos', 'type' => 'page', 'object' => 'page', 'path' => 'sobre-nos', 'match' => array( 'sobre-nos', 'sobre', 'sobre nós', 'about-us', 'about' ) ),
		'sobre nos'  => array( 'key' => 'sobre-nos', 'type' => 'page', 'object' => 'page', 'path' => 'sobre-nos', 'match' => array( 'sobre-nos', 'sobre', 'sobre nós', 'about-us', 'about' ) ),
		'contato'    => array( 'key' => 'contato', 'type' => 'page', 'object' => 'page', 'path' => 'contato', 'match' => array( 'contato', 'contact' ) ),
	);
}

/**
 * Bind a primary navigation item to its canonical WordPress object.
 *
 * Updates the item's type/object/object_id/url so WordPress' menu-context
 * logic can recognise it, and strips stale type/object/current classes so the
 * re-computed classes stay clean.
 *
 * @param stdClass $item    A menu item object (by reference).
 * @param array    $section A section spec from conexao_primary_nav_sections().
 */
function conexao_bind_section_object( $item, $section ) {
	// Front page link.
	if ( 'custom' === $section['type'] ) {
		$item->type      = 'custom';
		$item->object    = 'custom';
		$item->object_id = 0;
		$item->url       = home_url( '/' );
	}

	// Post type archive sections (Guias, Eventos, Empregos, Apoiadores).
	if ( 'post_type_archive' === $section['type'] ) {
		$item->type      = 'post_type_archive';
		$item->object    = $section['object'];
		$item->object_id = 0;
		if ( ! empty( $section['url'] ) ) {
			$item->url = $section['url'];
		}
	}

	// Posts archive section (Blog) - uses native WordPress posts.
	if ( 'posts_archive' === $section['type'] ) {
		$item->type      = 'custom';
		$item->object    = 'custom';
		$item->object_id = 0;
		$item->url       = ! empty( $section['url'] ) ? $section['url'] : home_url( '/blog/' );
	}

	// Page sections (Cursos, Irlanda, Sobre Nós, Contato).
	if ( 'page' === $section['type'] && ! empty( $section['path'] ) ) {
		$page = get_page_by_path( $section['path'] );
		if ( $page ) {
			$item->type        = 'post_type';
			$item->object      = 'page';
			$item->object_id   = (int) $page->ID;
			$item->url         = get_permalink( $page->ID );
			$item->post_parent = $page->post_parent ? (int) $page->post_parent : 0;
		}
	}

	// Refresh the <li> classes so they reflect the canonical binding and drop
	// any stale type/object/current classes baked into the stored menu item.
	$clean = array();
	foreach ( (array) $item->classes as $class ) {
		if ( 0 === strpos( $class, 'menu-item-type-' ) ) {
			continue;
		}
		if ( 0 === strpos( $class, 'menu-item-object-' ) ) {
			continue;
		}
		if ( 0 === strpos( $class, 'current-menu-' ) || 0 === strpos( $class, 'current_page' ) || 0 === strpos( $class, 'current-post-' ) ) {
			continue;
		}
		$clean[] = $class;
	}
	$item->classes = $clean;
}

/**
 * Bind every primary section to its canonical object and let WordPress compute
 * the active/current classes with its own menu-context logic.
 *
 * This reuses the exact mechanism the reference "Cursos" item uses
 * (_wp_menu_item_classes_by_context) and applies it uniformly to every
 * section, so Início, Guias, Eventos, Cursos, Empregos, Apoiadores, Irlanda,
 * Sobre Nós and Contato all receive the same reliable active state.
 *
 * @param array    $items An array of menu item objects.
 * @param stdClass $args  An object containing wp_nav_menu() arguments.
 * @return array
 */
function conexao_normalize_primary_nav_sections( $items, $args ) {
	if ( 'primary' !== $args->theme_location ) {
		return $items;
	}

	$sections   = conexao_primary_nav_sections();
	$has_blog   = false;
	$has_cursos = false;
	$has_guias  = false;
	$has_eventos = false;
	$has_empregos = false;
	$has_apoiadores = false;

	foreach ( $items as $item ) {
		$title    = strtolower( trim( wp_strip_all_tags( $item->title ) ) );
		$item_url = untrailingslashit( (string) $item->url );

		$section = isset( $sections[ $title ] ) ? $sections[ $title ] : null;

		// Fall back to URL matching so binding still works if a menu item uses
		// a non-canonical label.
		if ( null === $section ) {
			foreach ( $sections as $section_spec ) {
				foreach ( $section_spec['match'] as $needle ) {
					if ( '' !== $needle && false !== strpos( $item_url, '/' . $needle ) ) {
						$section = $section_spec;
						break 2;
					}
				}
			}
		}

		if ( null === $section ) {
			continue;
		}

		conexao_bind_section_object( $item, $section );

		if ( 'blog' === $section['key'] ) {
			$has_blog = true;
		}

		if ( 'cursos' === $section['key'] ) {
			$has_cursos = true;
		}

		if ( 'guias' === $section['key'] ) {
			$has_guias = true;
		}

		if ( 'eventos' === $section['key'] ) {
			$has_eventos = true;
		}

		if ( 'empregos' === $section['key'] ) {
			$has_empregos = true;
		}

		if ( 'apoiadores' === $section['key'] ) {
			$has_apoiadores = true;
		}
	}

	// Ensure a "Blog" item bound to the /blog/ posts archive exists (inserted
	// before "Guias" if the theme's base filter did not already provide one).
	// We intentionally do NOT look up a Page with slug "blog" — a Page with
	// that slug would shadow the posts archive and prevent published posts
	// from appearing on /blog/.
	if ( ! $has_blog ) {
		$blog_item = (object) array(
			'ID'               => 0,
			'db_id'            => 0,
			'menu_item_parent' => 0,
			'object_id'        => 0,
			'object'           => 'custom',
			'post_parent'      => 0,
			'type'             => 'custom',
			'type_label'       => 'Custom Link',
			'title'            => 'Blog',
			'url'              => home_url( '/blog/' ),
			'classes'          => array( 'menu-item', 'menu-item-type-custom', 'menu-item-object-custom' ),
			'attr_title'       => '',
			'target'           => '',
			'xfn'              => '',
			'description'      => '',
			'menu_order'       => 0,
		);

		$insert_blog_at = null;
		foreach ( $items as $k => $item ) {
			$item_title = strtolower( trim( wp_strip_all_tags( $item->title ) ) );
			if ( 'guias' === $item_title || 'guias práticos' === $item_title ) {
				$insert_blog_at = $k;
				break;
			}
		}

		if ( null === $insert_blog_at ) {
			$insert_blog_at = 1;
		}

		array_splice( $items, $insert_blog_at, 0, array( $blog_item ) );
		conexao_bind_section_object( $items[ $insert_blog_at ], $sections['blog'] );
	}

	// Ensure a "Cursos" item bound to the /cursos/ CPT archive exists (inserted before
	// "Empregos" if the theme's base filter did not already provide one).
	if ( ! $has_cursos ) {
		$cursos_url = get_post_type_archive_link( 'course_provider' );
		if ( ! $cursos_url ) {
			$cursos_url = home_url( '/cursos/' );
		}

		$cursos_item = (object) array(
			'ID'               => 0,
			'db_id'            => 0,
			'menu_item_parent' => 0,
			'object_id'        => 0,
			'object'           => 'course_provider',
			'post_parent'      => 0,
			'type'             => 'post_type_archive',
			'type_label'       => 'Cursos',
			'title'            => 'Cursos',
			'url'              => $cursos_url,
			'classes'          => array( 'menu-item', 'menu-item-type-post_type_archive', 'menu-item-object-course_provider' ),
			'attr_title'       => '',
			'target'           => '',
			'xfn'              => '',
			'description'      => '',
			'menu_order'       => 0,
		);

		$insert_at = null;
		foreach ( $items as $k => $item ) {
			if ( 'empregos' === strtolower( trim( wp_strip_all_tags( $item->title ) ) ) ) {
				$insert_at = $k;
				break;
			}
		}

		if ( null === $insert_at ) {
			$items[] = $cursos_item;
		} else {
			array_splice( $items, $insert_at, 0, array( $cursos_item ) );
		}

		conexao_bind_section_object( $cursos_item, $sections['cursos'] );
	}

	// Deduplicate: remove any duplicate items that map to the same section key.
	// This handles stale menu items that survived deletion (e.g. old Cursos items).
	$seen_sections = array();
	foreach ( $items as $k => $item ) {
		$section_key = conexao_get_item_section_key( $item );
		if ( null !== $section_key ) {
			if ( isset( $seen_sections[ $section_key ] ) ) {
				unset( $items[ $k ] );
				continue;
			}
			$seen_sections[ $section_key ] = true;
		}
	}
	$items = array_values( $items );

	// Ensure all CPT archive sections (Guias, Eventos, Empregos, Apoiadores)
	// are always present in the navigation, even if the stored menu is missing them.
	$cpt_sections = array(
		'guias'      => array( 'post_type' => 'guide',   'title' => 'Guias',      'url' => get_post_type_archive_link( 'guide' ) ? get_post_type_archive_link( 'guide' ) : home_url( '/guias/' ) ),
		'eventos'    => array( 'post_type' => 'event',   'title' => 'Eventos',    'url' => get_post_type_archive_link( 'event' ) ? get_post_type_archive_link( 'event' ) : home_url( '/eventos/' ) ),
		'lazer'      => array( 'post_type' => 'leisure', 'title' => 'Lazer e turismo', 'url' => get_post_type_archive_link( 'leisure' ) ? get_post_type_archive_link( 'leisure' ) : home_url( '/lazer/' ) ),
		'empregos'   => array( 'post_type' => 'job',     'title' => 'Empregos',   'url' => get_post_type_archive_link( 'job' ) ? get_post_type_archive_link( 'job' ) : home_url( '/empregos/' ) ),
		'apoiadores' => array( 'post_type' => 'sponsor', 'title' => 'Apoiadores', 'url' => get_post_type_archive_link( 'sponsor' ) ? get_post_type_archive_link( 'sponsor' ) : home_url( '/apoiadores/' ) ),
	);

	foreach ( $cpt_sections as $section_key => $cpt_info ) {
		$has_section = false;
		foreach ( $items as $item ) {
			$item_title = strtolower( trim( wp_strip_all_tags( $item->title ) ) );
			$item_url   = untrailingslashit( (string) $item->url );
			if ( $section_key === $item_title || false !== strpos( $item_url, '/' . $section_key ) ) {
				$has_section = true;
				break;
			}
		}

		if ( ! $has_section ) {
			$cpt_item = (object) array(
				'ID'               => 0,
				'db_id'            => 0,
				'menu_item_parent' => 0,
				'object_id'        => 0,
				'object'           => $cpt_info['post_type'],
				'post_parent'      => 0,
				'type'             => 'post_type_archive',
				'type_label'       => $cpt_info['title'],
				'title'            => $cpt_info['title'],
				'url'              => $cpt_info['url'],
				'classes'          => array( 'menu-item', 'menu-item-type-post_type_archive', 'menu-item-object-' . $cpt_info['post_type'] ),
				'attr_title'       => '',
				'target'           => '',
				'xfn'              => '',
				'description'      => '',
				'menu_order'       => 0,
			);

			// Insert in the correct position based on the canonical order.
			$order = array( 'inicio', 'blog', 'guias', 'eventos', 'cursos', 'lazer', 'empregos', 'apoiadores', 'irlanda', 'sobre-nos', 'contato' );
			$target_index = array_search( $section_key, $order, true );
			$insert_at = count( $items );

			foreach ( $items as $k => $item ) {
				$item_key = conexao_get_item_section_key( $item );
				$item_pos = $item_key ? array_search( $item_key, $order, true ) : false;
				if ( false !== $item_pos && $item_pos > $target_index ) {
					$insert_at = $k;
					break;
				}
			}

			array_splice( $items, $insert_at, 0, array( $cpt_item ) );
			conexao_bind_section_object( $cpt_item, $sections[ $section_key ] );
		}
	}

	// Let WordPress compute the active/current classes using its own
	// queried-object/URL logic — applied to the whole primary navigation.
	_wp_menu_item_classes_by_context( $items );

	// Fix active state conflicts.
	// WordPress's _wp_menu_item_classes_by_context() can incorrectly set
	// current-menu-item on multiple items (e.g., both Início and Blog on
	// the homepage). This filter ensures each section has the correct
	// active state based on explicit URL/route matching.
	$items = conexao_fix_nav_active_states( $items );

	return $items;
}
add_filter( 'wp_nav_menu_objects', 'conexao_normalize_primary_nav_sections', 25, 2 );

/**
 * Fix navigation active state conflicts.
 *
 * WordPress's _wp_menu_item_classes_by_context() may incorrectly assign
 * current-menu-item to multiple top-level items (e.g., both "Início" and
 * "Blog" when on the homepage). This function ensures that:
 *
 * 1. The homepage ("Início") is only active when exactly on the homepage.
 * 2. Each section is only active when on its corresponding page/archive.
 * 3. No two unrelated top-level items are simultaneously active.
 *
 * @param array $items Menu item objects.
 * @return array Modified menu item objects.
 */
function conexao_fix_nav_active_states( $items ) {
	// Determine the current request path.
	$current_path = conexao_get_current_path();

	// Define explicit active-state rules for each section.
	// Each rule maps a section key to a callback that returns true when
	// the section should be active.
	$active_rules = array(
		'inicio'     => function( $path ) {
			// Homepage: only active when path is empty or '/'.
			return '' === $path || '/' === $path;
		},
		'blog'       => function( $path ) {
			// Blog: active on /blog/ and /blog/* pages.
			return preg_match( '#^/blog(/.*)?$#', $path );
		},
		'guias'      => function( $path ) {
			// Guides: active on /guides/ or /guias/ and their subpages.
			return preg_match( '#^/(guides|guias)(/.*)?$#', $path );
		},
		'eventos'    => function( $path ) {
			// Events: active on /events/ or /eventos/ and their subpages.
			return preg_match( '#^/(events|eventos)(/.*)?$#', $path );
		},
		'cursos'     => function( $path ) {
			// Courses: active on /courses/ or /cursos/ and their subpages.
			return preg_match( '#^/(courses|cursos)(/.*)?$#', $path );
		},
		'lazer'      => function( $path ) {
			// Lazer: active on /lazer/ or /leisure/ and their subpages.
			return preg_match( '#^/(lazer|leisure)(/.*)?$#', $path );
		},
		'empregos'   => function( $path ) {
			// Jobs: active on /jobs/ or /empregos/ and their subpages.
			return preg_match( '#^/(jobs|empregos)(/.*)?$#', $path );
		},
		'apoiadores' => function( $path ) {
			// Sponsors: active on /sponsors/ or /apoiadores/ and their subpages.
			return preg_match( '#^/(sponsors|apoiadores)(/.*)?$#', $path );
		},
		'irlanda'    => function( $path ) {
			// Ireland: active on /irlanda/ or /ireland/ and their subpages.
			return preg_match( '#^/(irlanda|ireland)(/.*)?$#', $path );
		},
		'sobre-nos'  => function( $path ) {
			// About: active on /sobre-nos/ or /about-us/ and their subpages.
			return preg_match( '#^/(sobre-nos|about-us|about)(/.*)?$#', $path );
		},
		'contato'    => function( $path ) {
			// Contact: active on /contato/ or /contact/ and their subpages.
			return preg_match( '#^/(contato|contact)(/.*)?$#', $path );
		},
	);

	// First pass: remove all current-* classes from all items.
	foreach ( $items as $item ) {
		$item->classes = array_filter( (array) $item->classes, function( $class ) {
			return 0 !== strpos( $class, 'current-menu-' )
				&& 0 !== strpos( $class, 'current_page' )
				&& 0 !== strpos( $class, 'current-post-' );
		} );
		$item->classes = array_values( $item->classes );
	}

	// Second pass: apply active classes based on explicit rules.
	foreach ( $items as $item ) {
		$section_key = conexao_get_item_section_key( $item );
		if ( null === $section_key || ! isset( $active_rules[ $section_key ] ) ) {
			continue;
		}

		$is_active = $active_rules[ $section_key ]( $current_path );
		if ( $is_active ) {
			$item->classes[] = 'current-menu-item';
		}
	}

	return $items;
}

/**
 * Get the current request path, normalized.
 *
 * Returns the path portion of the current URL, with trailing slash removed
 * (except for the homepage which returns '/').
 *
 * @return string Normalized current path.
 */
function conexao_get_current_path() {
	$path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

	// Remove query string.
	$path = strtok( $path, '?' );

	// Ensure path starts with '/'.
	if ( empty( $path ) || '/' !== $path[0] ) {
		$path = '/' . $path;
	}

	// Normalize: remove trailing slash (except for homepage).
	if ( '/' !== $path ) {
		$path = untrailingslashit( $path );
	}

	return $path;
}

/**
 * Determine the section key for a menu item.
 *
 * Maps a menu item to its section key based on the item's title, URL,
 * or bound object.
 *
 * @param object $item Menu item object.
 * @return string|null Section key or null if not matched.
 */
function conexao_get_item_section_key( $item ) {
	$title    = strtolower( trim( wp_strip_all_tags( $item->title ) ) );
	$item_url = untrailingslashit( (string) $item->url );

	// Map titles to section keys.
	$title_map = array(
		'início'          => 'inicio',
		'inicio'          => 'inicio',
		'home'            => 'inicio',
		'blog'            => 'blog',
		'guias'           => 'guias',
		'guias práticos'  => 'guias',
		'eventos'         => 'eventos',
		'cursos'          => 'cursos',
		'lazer'           => 'lazer',
		'lazer e turismo' => 'lazer',
		'empregos'        => 'empregos',
		'apoiadores'      => 'apoiadores',
		'irlanda'         => 'irlanda',
		'sobre nós'       => 'sobre-nos',
		'sobre nos'       => 'sobre-nos',
		'contato'         => 'contato',
	);

	if ( isset( $title_map[ $title ] ) ) {
		return $title_map[ $title ];
	}

	// Fall back to URL matching.
	$url_patterns = array(
		array( 'key' => 'blog',       'pattern' => '/blog' ),
		array( 'key' => 'guias',      'pattern' => '/guias' ),
		array( 'key' => 'guias',      'pattern' => '/guides' ),
		array( 'key' => 'eventos',    'pattern' => '/eventos' ),
		array( 'key' => 'eventos',    'pattern' => '/events' ),
		array( 'key' => 'cursos',     'pattern' => '/cursos' ),
		array( 'key' => 'cursos',     'pattern' => '/courses' ),
		array( 'key' => 'lazer',      'pattern' => '/lazer' ),
		array( 'key' => 'lazer',      'pattern' => '/leisure' ),
		array( 'key' => 'empregos',   'pattern' => '/empregos' ),
		array( 'key' => 'empregos',   'pattern' => '/jobs' ),
		array( 'key' => 'apoiadores', 'pattern' => '/apoiadores' ),
		array( 'key' => 'apoiadores', 'pattern' => '/sponsors' ),
		array( 'key' => 'irlanda',    'pattern' => '/irlanda' ),
		array( 'key' => 'irlanda',    'pattern' => '/ireland' ),
		array( 'key' => 'sobre-nos',  'pattern' => '/sobre-nos' ),
		array( 'key' => 'sobre-nos',  'pattern' => '/about-us' ),
		array( 'key' => 'sobre-nos',  'pattern' => '/about' ),
		array( 'key' => 'contato',    'pattern' => '/contato' ),
		array( 'key' => 'contato',    'pattern' => '/contact' ),
	);

	foreach ( $url_patterns as $mapping ) {
		if ( false !== strpos( $item_url, $mapping['pattern'] ) ) {
			return $mapping['key'];
		}
	}

	// Check if this is the homepage.
	$home_url = untrailingslashit( home_url( '/' ) );
	if ( $item_url === $home_url || '' === $item_url || '/' === $item_url ) {
		return 'inicio';
	}

	return null;
}
