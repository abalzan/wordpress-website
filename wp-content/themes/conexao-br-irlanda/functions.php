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
 * Get the canonical archive URL for the Guides CPT.
 *
 * @return string
 */
function conexao_get_guides_archive_url() {
	$archive_link = get_post_type_archive_link( 'guide' );

	if ( $archive_link ) {
		return $archive_link;
	}

	return home_url( '/guides/' );
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

	wp_enqueue_style( 'conexao-header-nav', CONEXAO_THEME_URI . '/assets/css/header-nav.css', array(), conexao_asset_version( 'assets/css/header-nav.css' ) );
	wp_enqueue_style( 'conexao-main', CONEXAO_THEME_URI . '/assets/css/main.css', array( 'conexao-header-nav' ), conexao_asset_version( 'assets/css/main.css' ) );

	// Load main.js with defer to avoid render-blocking.
	wp_enqueue_script( 'conexao-main', CONEXAO_THEME_URI . '/assets/js/main.js', array(), conexao_asset_version( 'assets/js/main.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );

	if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
		wp_enqueue_script( 'comment-reply' );
	}
}
add_action( 'wp_enqueue_scripts', 'conexao_enqueue_scripts' );

/**
 * Add preconnect hints for Google Fonts in the head.
 */
function conexao_fonts_preconnect() {
	echo '<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>' . "\n";
	echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
}
add_action( 'wp_head', 'conexao_fonts_preconnect', 1 );

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
 * Runs whenever a News, Guide, Event, Job, Apoiador or standard post is
 * published, updated, or deleted. This keeps:
 *  - the homepage card/featured/popular transients fresh,
 *  - the 404 page's guides/news/events transients fresh,
 *  - the per-post reading-time object-cache entry fresh.
 *
 * Transients store only public, non-user-specific data (ID, title, permalink,
 * date, excerpt, thumbnail URL), so no logged-in/admin data can leak. Keys are
 * predictable and unique per concern. Expiration is 5 minutes as a safety net
 * even if a save hook is missed.
 */
function conexao_homepage_cache_invalidate( $post_id ) {
	$post_type = get_post_type( $post_id );
	$cpt_types = array( 'news', 'guide', 'event', 'job', 'sponsor', 'post' );
	if ( in_array( $post_type, $cpt_types, true ) ) {
		// Homepage sections.
		delete_transient( 'conexao_home_news' );
		delete_transient( 'conexao_home_guides' );
		delete_transient( 'conexao_home_events' );
		delete_transient( 'conexao_home_sponsors' );
		delete_transient( 'conexao_home_jobs' );
		delete_transient( 'conexao_home_featured' );
		delete_transient( 'conexao_home_popular' );

		// 404 page sections.
		delete_transient( 'conexao_404_guides' );
		delete_transient( 'conexao_404_news' );
		delete_transient( 'conexao_404_events' );

		// Reading-time object-cache entry for this post.
		wp_cache_delete( 'conexao_reading_time_' . $post_id, 'conexao' );
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
 * creates static pages (eventos, cursos, noticias, contato, blog) whose body
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
		if ( $post && ( has_shortcode( $post->post_content, 'conexao_grid' ) || has_shortcode( $post->post_content, 'conexao_blog_categories' ) ) ) {
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
 * Share buttons
 */
function conexao_share_buttons() {
	if ( ! is_singular( 'post' ) ) return;
	$url      = urlencode( get_permalink() );
	$title    = urlencode( get_the_title() );
	$facebook = "https://www.facebook.com/sharer/sharer.php?u={$url}";
	$twitter  = "https://twitter.com/intent/tweet?url={$url}&text={$title}";
	$linkedin = "https://www.linkedin.com/sharing/share-offsite/?url={$url}";
	?>
	<div class="share-buttons">
		<span class="share-label"><?php esc_html_e( 'Compartilhar:', 'conexao-br-irlanda' ); ?></span>
		<a href="<?php echo esc_url( $facebook ); ?>" target="_blank" rel="noopener noreferrer" class="share-btn share-facebook" aria-label="<?php esc_attr_e( 'Compartilhar no Facebook', 'conexao-br-irlanda' ); ?>">
			<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
		</a>
		<a href="<?php echo esc_url( $twitter ); ?>" target="_blank" rel="noopener noreferrer" class="share-btn share-twitter" aria-label="<?php esc_attr_e( 'Compartilhar no X (Twitter)', 'conexao-br-irlanda' ); ?>">
			<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
		</a>
		<a href="<?php echo esc_url( $linkedin ); ?>" target="_blank" rel="noopener noreferrer" class="share-btn share-linkedin" aria-label="<?php esc_attr_e( 'Compartilhar no LinkedIn', 'conexao-br-irlanda' ); ?>">
			<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
		</a>
		<button type="button" class="share-btn share-copy" data-copy-url="<?php echo esc_url( get_permalink() ); ?>" aria-label="<?php esc_attr_e( 'Copiar link', 'conexao-br-irlanda' ); ?>">
			<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></svg>
		</button>
	</div>
	<?php
}

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
 * table scan on wp_posts and becomes expensive as the portal grows. No view-
 * count system exists yet, so this returns a lightweight, cached list.
 *
 * Architecture (future-ready):
 *   post ID → _conexao_view_count (meta) → ORDER BY meta_value_num
 *
 * When post meta `_conexao_view_count` is present on items, they sort first
 * (meta_value_num DESC). Until a view-count system is introduced, the helper
 * falls back to "recent content" (date DESC) so the homepage keeps working
 * without an expensive query. Results are transient-cached for 5 minutes and
 * invalidated on save/delete (see conexao_homepage_cache_invalidate()).
 *
 * @param int $limit Number of items to return (default 5).
 * @return array List of post IDs, most "popular" first.
 */
function conexao_popular_posts( $limit = 5 ) {
	$limit    = max( 1, absint( $limit ) );
	$cache_key = 'conexao_home_popular';

	$cached = get_transient( $cache_key );
	if ( false !== $cached ) {
		return array_slice( $cached, 0, $limit );
	}

	// Preferred: order by the cached view-count meta when it exists.
	// This is intentionally a single indexed meta query, not a JOIN over
	// comment counts. It returns nothing measurable until the meta is set,
	// so we fall through to the lightweight recent-content query below.
	$by_views = new WP_Query( array(
		'post_type'           => array( 'news', 'guide', 'event', 'job', 'sponsor', 'post' ),
		'posts_per_page'      => $limit,
		'meta_key'            => '_conexao_view_count',
		'orderby'             => 'meta_value_num',
		'order'               => 'DESC',
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
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
			'post_type'           => array( 'news', 'guide', 'event', 'job', 'sponsor', 'post' ),
			'posts_per_page'      => $limit,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
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
	// Store the hero image as a Media Library attachment ID so the theme can
	// use wp_get_attachment_image() (responsive srcset/sizes/width/height/alt).
	// Backward compatible: if an old URL value is present, it is converted to
	// an attachment ID on first read (see conexao_hero_image_attachment_id()).
	$wp_customize->add_setting( 'conexao_hero_image', array(
		'default'           => '',
		'sanitize_callback' => 'absint',
	) );
	$wp_customize->add_control( new WP_Customize_Media_Control( $wp_customize, 'conexao_hero_image', array(
		'label'           => __( 'Imagem do Hero', 'conexao-br-irlanda' ),
		'section'         => 'conexao_hero',
		'mime_type'       => 'image',
		'description'     => __( 'Selecione uma imagem da biblioteca para o lado direito do hero. A imagem fica responsiva automaticamente.', 'conexao-br-irlanda' ),
	) ) );

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
 * Event archive: only show upcoming events, ordered by event date ascending.
 */
function conexao_event_archive_query( $query ) {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}

	if ( is_post_type_archive( 'event' ) ) {
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
}
add_action( 'pre_get_posts', 'conexao_event_archive_query' );

/**
 * Custom image sizes
 */
function conexao_image_sizes() {
	add_image_size( 'conexao-card', 400, 300, true );
	add_image_size( 'conexao-hero', 1200, 600, true );
	add_image_size( 'conexao-thumb', 200, 150, true );
}
add_action( 'after_setup_theme', 'conexao_image_sizes' );

function conexao_custom_image_sizes( $sizes ) {
	return array_merge( $sizes, array(
		'conexao-card'  => __( 'Card do Portal', 'conexao-br-irlanda' ),
		'conexao-hero'  => __( 'Hero do Portal', 'conexao-br-irlanda' ),
		'conexao-thumb' => __( 'Miniatura do Portal', 'conexao-br-irlanda' ),
	) );
}
add_filter( 'image_size_names_choose', 'conexao_custom_image_sizes' );

/**
 * Resolve the hero image attachment ID.
 *
 * The hero is defined in the Customizer as a Media Library attachment. Older
 * versions stored a raw URL; this helper converts that legacy value to an
 * attachment ID on first read so wp_get_attachment_image() can be used to
 * output a fully responsive image (srcset, sizes, width, height, alt).
 *
 * @return int Hero attachment ID, or 0 when none is set.
 */
function conexao_hero_image_attachment_id() {
	$value = get_theme_mod( 'conexao_hero_image', '' );

	if ( empty( $value ) ) {
		return 0;
	}

	// Already an attachment ID.
	if ( is_numeric( $value ) ) {
		return absint( $value );
	}

	// Legacy URL value → convert to an attachment ID and persist it.
	$attachment_id = attachment_url_to_postid( $value );
	if ( $attachment_id ) {
		set_theme_mod( 'conexao_hero_image', $attachment_id );
		return $attachment_id;
	}

	return 0;
}


/**
 * WhatsApp floating button
 */
function conexao_whatsapp_button() {
	$whatsapp = get_theme_mod( 'conexao_whatsapp', 'https://wa.me/353899451428' );
	if ( empty( $whatsapp ) ) return;
	?>
	<a href="<?php echo esc_url( $whatsapp ); ?>" class="whatsapp-float" target="_blank" rel="noopener noreferrer" aria-label="<?php esc_attr_e( 'Fale conosco no WhatsApp', 'conexao-br-irlanda' ); ?>">
		<svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
	</a>
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
	$legacy_url = untrailingslashit( home_url( '/guias/' ) );

	foreach ( $items as $item ) {
		$title = strtolower( trim( wp_strip_all_tags( $item->title ) ) );
		$item_url = untrailingslashit( $item->url );

		if ( 'guias' === $title || 'guias práticos' === $title || $legacy_url === $item_url || false !== strpos( $item_url, '/guias' ) ) {
			$item->url = $guides_url;
		}
	}

	return $items;
}
add_filter( 'wp_nav_menu_objects', 'conexao_override_guides_menu_links', 10, 2 );
