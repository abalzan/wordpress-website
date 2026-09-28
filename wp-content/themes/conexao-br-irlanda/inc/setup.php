<?php
/**
 * Theme supports, menus, widgets and image sizes
 *
 * Registers add_theme_support(), navigation menus, widget areas, editor styles and the
 * registered image sizes. Runs on after_setup_theme / widgets_init.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Theme setup
 */
function conexao_theme_setup() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support(
		'custom-logo',
		array(
			'height'      => 80,
			'width'       => 240,
			'flex-height' => true,
			'flex-width'  => true,
		)
	);
	add_theme_support(
		'html5',
		array(
			'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script',
		)
	);
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'align-wide' );
	add_theme_support( 'wp-block-styles' );
	add_theme_support( 'editor-styles' );
	add_theme_support( 'customize-selective-refresh-widgets' );
	add_theme_support(
		'custom-header',
		array(
			'default-image' => '', 'width' => 1920, 'height' => 400, 'flex-height' => true, 'flex-width' => true,
		)
	);
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
 * Register the theme's image sizes.
 *
 * @return void
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

/**
 * Add the theme's image sizes to the media library chooser.
 *
 * @param array $sizes Registered image sizes, keyed by name.
 * @return array Filtered image sizes.
 */
function conexao_custom_image_sizes( $sizes ) {
	return array_merge(
		$sizes,
		array(
			'conexao-card'         => __( 'Card do Portal', 'conexao-br-irlanda' ),
			'conexao-hero'         => __( 'Hero do Portal', 'conexao-br-irlanda' ),
			'conexao-thumb'        => __( 'Miniatura do Portal', 'conexao-br-irlanda' ),
			'conexao-event-preview' => __( 'Prévia de Evento (16:9)', 'conexao-br-irlanda' ),
			'conexao-sponsor-tile'  => __( 'Tile do Apoiador (512px)', 'conexao-br-irlanda' ),
			'conexao-event-banner' => __( 'Banner de Evento', 'conexao-br-irlanda' ),
			'conexao-provider-logo' => __( 'Logo de Provedor de Cursos', 'conexao-br-irlanda' ),
			'conexao-job-portrait' => __( 'Vaga Vertical (Instagram)', 'conexao-br-irlanda' ),
		)
	);
}
add_filter( 'image_size_names_choose', 'conexao_custom_image_sizes' );

/**
 * Explain the expected featured-image format when editing a Job.
 *
 * Job artwork is authored vertically for Instagram Stories. This hint sets
 * expectations in the admin without blocking uploads of other dimensions.
 */
