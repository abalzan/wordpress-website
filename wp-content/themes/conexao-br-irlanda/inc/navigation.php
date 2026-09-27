<?php
/**
 * Primary navigation shaping and active-state resolution
 *
 * nav_menu_* filters, the Guides/primary menu item rewriting, the section
 * normalisation that binds a section to each menu item, and the current-path
 * active-state resolution. Language-aware URL resolution is delegated to
 * inc/i18n/ (conexao_lang_url, conexao_language_archive_url).
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
 * Safe empty fallback for the header navigations.
 *
 * Replaces WordPress' wp_page_menu() as the wp_nav_menu() fallback for both
 * the desktop primary navigation and the mobile drawer navigation: when the
 * 'primary' theme location has no valid menu for the current language (for
 * example while Polylang has no per-language assignment for a language yet,
 * which makes Polylang nullify the location), the header must NOT silently
 * render WordPress' full automatic page list. This fallback explicitly
 * renders no navigation items instead — the page list overflowed the header
 * and buried the curated menu.
 *
 * Re-introducing wp_page_menu here is a regression: see
 * CONEXAO_BR_HEADER_NAVIGATION_REGRESSION_REPORT.md.
 *
 * @param array $args wp_nav_menu() arguments (unused).
 * @return void
 */
function conexao_safe_nav_menu_fallback( $args = array() ) {
	// Intentionally empty: render no primary navigation items.
}

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
 * renames the "Lazer" label to "Lazer e turismo" (URL unchanged) and inserts a
 * fallback "Blog" item (linked to the existing /blog/ page) immediately before
 * "Contato", so the final order matches the canonical sequence:
 *
 *   Início, Apoiadores, Guias, Eventos, Cursos, Lazer e turismo, Empregos, Blog, Contato
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

	// 2. Change the "Home" label to the canonical one for the current language:
	//    "Início" on Portuguese (the Portuguese-first portal) or "Home" on
	//    English — the label of the existing English front page. The URL is
	//    left untouched so the homepage link still works. When no language
	//    context exists (CLI/admin), the Portuguese default applies, so
	//    single-language behaviour is byte-identical to the pre-Polylang theme.
	$home_label = 'en' === conexao_current_language_slug()
		? 'Home'
		: __( 'Início', 'conexao-br-irlanda' );
	foreach ( $items as $item ) {
		$title = strtolower( trim( wp_strip_all_tags( $item->title ) ) );
		if ( 'home' === $title || 'início' === $title || 'inicio' === $title ) {
			$item->title = $home_label;
		}
	}

	// 3. Rename the "Lazer" navigation label to the canonical one for the
	//    current language: "Lazer e turismo" (Portuguese) or "Leisure &amp;
	//    Tourism" (English). Label-only change: the /lazer/ URL, the leisure
	//    post type and every other attribute stay untouched, so object
	//    binding, deduplication and active-state logic keep resolving this
	//    item to the "lazer" section.
	$lazer_label = 'en' === conexao_current_language_slug()
		? 'Leisure & Tourism'
		: __( 'Lazer e turismo', 'conexao-br-irlanda' );
	foreach ( $items as $item ) {
		$title = strtolower( trim( wp_strip_all_tags( $item->title ) ) );
		if ( 'lazer' === $title || 'leisure' === $title ) {
			$item->title = $lazer_label;
		}
	}

	// 4. Ensure a "Blog" item exists even if the stored menu lacks one.
	//    The Blog section uses the native WordPress posts archive at /blog/.
	//    We intentionally do NOT look up a Page with slug "blog" — a Page
	//    with that slug would shadow the posts archive and prevent published
	//    posts from appearing on /blog/.
	//    The destination goes through conexao_lang_url(): byte-identical to
	//    home_url( '/blog/' ) on Portuguese; on English it resolves through
	//    conexao_language_archive_url(), which checks for a translated Blog
	//    page (page_for_posts) and falls back to the EN home when no
	//    translation exists — keeping the Blog navigation in English.
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
		'url'              => conexao_lang_url( '/blog/' ),
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

	// Insert "Blog" immediately before "Contato" (canonical position: after
	// "Empregos", near the end of the navigation).
	$insert_blog_at = null;
	foreach ( $items as $k => $item ) {
		$item_title = strtolower( trim( wp_strip_all_tags( $item->title ) ) );
		if ( 'contato' === $item_title ) {
			$insert_blog_at = $k;
			break;
		}
	}

	if ( null === $insert_blog_at ) {
		// If "Contato" not found, append at the end of the navigation.
		$insert_blog_at = count( $items );
	}

	array_splice( $items, $insert_blog_at, 0, array( $blog_item_obj ) );

	// "Cursos" is now handled by conexao_normalize_primary_nav_sections() (priority 25)
	// to avoid duplicate items in the navigation.
	return $items;
}
add_filter( 'wp_nav_menu_objects', 'conexao_modify_primary_nav_items', 20, 2 );

/**
 * Language-aware URL for a primary-nav CPT archive section.
 *
 * Portuguese (the default language) keeps the canonical
 * get_post_type_archive_link() behaviour byte-identical to the pre-Polylang
 * theme. Any other current language resolves the same section to that
 * language's archive URL (e.g. /en/eventos/) via
 * conexao_language_archive_url() — the same rule Stage 3.3 applies to every
 * internal theme link (see conexao_lang_url()).
 *
 * @param string $post_type     Post type backing the section.
 * @param string $fallback_slug Canonical PT path segment (e.g. "eventos").
 * @return string Absolute URL.
 */
function conexao_primary_nav_archive_url( $post_type, $fallback_slug ) {
	$current = conexao_current_language_slug();
	$default = conexao_default_language_slug();

	if ( $current && $default && $current !== $default ) {
		$archive = conexao_language_archive_url( $post_type, $current );
		if ( '' !== $archive ) {
			return $archive;
		}
	}

	$link = get_post_type_archive_link( $post_type );
	return $link ? $link : home_url( '/' . $fallback_slug . '/' );
}

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
 * URLs are language-aware: the default language (Portuguese) keeps the exact
 * pre-Polylang destinations; other languages resolve through the Stage 3.3
 * language-aware link helpers so the English header never links into the
 * Portuguese URL space.
 *
 * @return array
 */
function conexao_primary_nav_sections() {
	$archive_url = 'conexao_primary_nav_archive_url';

	return array(
		'início'     => array( 'key' => 'inicio', 'type' => 'custom', 'object' => 'custom', 'url' => home_url( '/' ), 'match' => array() ),
		// Blog uses the native posts archive at /blog/ (not a static page).
		// conexao_lang_url() is byte-identical on Portuguese. On English it
		// resolves through conexao_language_archive_url(), which checks for a
		// translated Blog page (page_for_posts) and falls back to the EN home
		// when no translation exists — keeping the Blog navigation in English.
		'blog'       => array( 'key' => 'blog', 'type' => 'posts_archive', 'object' => 'post', 'url' => conexao_lang_url( '/blog/' ), 'match' => array( 'blog' ) ),
		'guias'      => array( 'key' => 'guias', 'type' => 'post_type_archive', 'object' => 'guide', 'url' => $archive_url( 'guide', 'guias' ), 'match' => array( 'guias', 'guides' ) ),
		'eventos'    => array( 'key' => 'eventos', 'type' => 'post_type_archive', 'object' => 'event', 'url' => $archive_url( 'event', 'eventos' ), 'match' => array( 'eventos', 'events' ) ),
		// Cursos is a CPT archive (course_provider CPT), not a static page.
		'cursos'     => array( 'key' => 'cursos', 'type' => 'post_type_archive', 'object' => 'course_provider', 'url' => $archive_url( 'course_provider', 'cursos' ), 'match' => array( 'cursos', 'courses' ) ),
		// Lazer is a CPT archive (leisure CPT) at /lazer/. Its navigation label
		// is "Lazer e turismo"; both labels resolve to the same section spec.
		'lazer'           => array( 'key' => 'lazer', 'type' => 'post_type_archive', 'object' => 'leisure', 'url' => $archive_url( 'leisure', 'lazer' ), 'match' => array( 'lazer', 'leisure' ) ),
		'lazer e turismo' => array( 'key' => 'lazer', 'type' => 'post_type_archive', 'object' => 'leisure', 'url' => $archive_url( 'leisure', 'lazer' ), 'match' => array( 'lazer', 'leisure' ) ),
		// Jobs is a STATIC LANDING PAGE (/empregos/ -> page-empregos.php), not a
		// CPT archive: the `job` CPT is registered with has_archive = false, so
		// /empregos/ belongs to the page while job singles keep /empregos/{slug}/.
		// Binding it as a page (like Contato / Sobre Nos) lets the existing
		// translation-aware page binding resolve the LINKED EN translation
		// (/en/jobs/) at render time -- the same rule Stage 3.3 applied to the EN
		// homepage Jobs card through conexao_lang_url(). Treating it as a
		// post_type_archive returned '' for the archive URL (has_archive = false)
		// and fell back to the Portuguese /empregos/, which switched an EN visitor
		// back to Portuguese. See CONEXAO_BR_EN_NAV_LANGUAGE_CONTEXT_FIX_REPORT.md.
		'empregos'   => array( 'key' => 'empregos', 'type' => 'page', 'object' => 'page', 'path' => 'empregos', 'match' => array( 'empregos', 'jobs' ) ),
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
			$page_id = (int) $page->ID;

			// English layer: bind the current language's LINKED translation of
			// the canonical Portuguese page when one exists (e.g. /contato/ →
			// /en/contact/) — the same rule Stage 3.3 applies to internal
			// theme links. An English record is a linked translation of the
			// same identity, never a second identity. Without a translation
			// the approved B1 behaviour applies — the Portuguese page.
			$current = conexao_current_language_slug();
			$default = conexao_default_language_slug();
			if ( $current && $default && $current !== $default && function_exists( 'pll_get_post' ) ) {
				$translation = pll_get_post( $page_id, $current );
				if ( $translation && 'publish' === get_post_status( (int) $translation ) ) {
					$page_id = (int) $translation;
				}
			}

			$item->type        = 'post_type';
			$item->object      = 'page';
			$item->object_id   = $page_id;
			$item->url         = get_permalink( $page_id );
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

		// Final fallback: the shared section-key resolver. It understands the
		// canonical English titles (Home, Sponsors, Guides, …) and the bare
		// homepage link — items the title/url lookup above cannot match (the
		// home section carries no URL needle). Portuguese items never reach
		// this branch (they match by title/URL above), so PT output is
		// unchanged.
		if ( null === $section ) {
			$key = conexao_get_item_section_key( $item );
			if ( null !== $key ) {
				foreach ( $sections as $section_spec ) {
					if ( $section_spec['key'] === $key ) {
						$section = $section_spec;
						break;
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
	// immediately before "Contato" if the theme's base filter did not already
	// provide one).
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
			'url'              => conexao_lang_url( '/blog/' ),
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
			if ( 'contato' === $item_title ) {
				$insert_blog_at = $k;
				break;
			}
		}

		if ( null === $insert_blog_at ) {
			// If "Contato" not found, append at the end of the navigation.
			$insert_blog_at = count( $items );
		}

		array_splice( $items, $insert_blog_at, 0, array( $blog_item ) );
		conexao_bind_section_object( $items[ $insert_blog_at ], $sections['blog'] );
	}

	// Ensure a "Cursos" item bound to the /cursos/ CPT archive exists (inserted before
	// "Empregos" if the theme's base filter did not already provide one).
	if ( ! $has_cursos ) {
		$cursos_url = conexao_primary_nav_archive_url( 'course_provider', 'cursos' );

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

	// Ensure all section-backed navigation items (Guias, Eventos, Empregos,
	// Apoiadores) are always present in the navigation, even if the stored menu
	// is missing them. Jobs is page-backed (see conexao_primary_nav_sections()).
	$is_en = 'en' === conexao_current_language_slug();

	$cpt_sections = array(
		'guias'      => array( 'post_type' => 'guide',   'title' => $is_en ? 'Guides' : __( 'Guias', 'conexao-br-irlanda' ),                'url' => conexao_primary_nav_archive_url( 'guide', 'guias' ) ),
		'eventos'    => array( 'post_type' => 'event',   'title' => $is_en ? 'Events' : __( 'Eventos', 'conexao-br-irlanda' ),              'url' => conexao_primary_nav_archive_url( 'event', 'eventos' ) ),
		'lazer'      => array( 'post_type' => 'leisure', 'title' => $is_en ? 'Leisure & Tourism' : __( 'Lazer e turismo', 'conexao-br-irlanda' ), 'url' => conexao_primary_nav_archive_url( 'leisure', 'lazer' ) ),
		// Jobs is page-backed: conexao_lang_url( '/empregos/' ) resolves the linked
		// EN translation (/en/jobs/) with the same helper Stage 3.3 uses; binding is
		// then canonicalised by conexao_bind_section_object() below.
		'empregos'   => array( 'post_type' => 'page',    'title' => $is_en ? 'Jobs' : __( 'Empregos', 'conexao-br-irlanda' ),               'url' => conexao_lang_url( '/empregos/' ) ),
		'apoiadores' => array( 'post_type' => 'sponsor', 'title' => $is_en ? 'Sponsors' : __( 'Apoiadores', 'conexao-br-irlanda' ),         'url' => conexao_primary_nav_archive_url( 'sponsor', 'apoiadores' ) ),
	);

	foreach ( $cpt_sections as $section_key => $cpt_info ) {
		$has_section = false;
		$section_spec = isset( $sections[ $section_key ] ) ? $sections[ $section_key ] : null;
		$match_patterns = $section_spec && isset( $section_spec['match'] ) ? $section_spec['match'] : array( $section_key );
		foreach ( $items as $item ) {
			$item_title = strtolower( trim( wp_strip_all_tags( $item->title ) ) );
			$item_url   = untrailingslashit( (string) $item->url );
			foreach ( $match_patterns as $pattern ) {
				if ( $pattern === $item_title || false !== strpos( $item_url, '/' . $pattern ) ) {
					$has_section = true;
					break 2;
				}
			}
		}

		if ( ! $has_section ) {
			// The section spec decides whether this is a CPT archive item or a
			// page-backed item (Jobs); conexao_bind_section_object() then binds the
			// canonical object/URL for the current language.
			$section_spec = isset( $sections[ $section_key ] ) ? $sections[ $section_key ] : null;
			$is_page_section = ( $section_spec && 'page' === $section_spec['type'] );
			$item_type   = $is_page_section ? 'post_type' : 'post_type_archive';
			$item_object = $is_page_section ? 'page' : $cpt_info['post_type'];

			$cpt_item = (object) array(
				'ID'               => 0,
				'db_id'            => 0,
				'menu_item_parent' => 0,
				'object_id'        => 0,
				'object'           => $item_object,
				'post_parent'      => 0,
				'type'             => $item_type,
				'type_label'       => $cpt_info['title'],
				'title'            => $cpt_info['title'],
				'url'              => $cpt_info['url'],
				'classes'          => array( 'menu-item', 'menu-item-type-' . $item_type, 'menu-item-object-' . $item_object ),
				'attr_title'       => '',
				'target'           => '',
				'xfn'              => '',
				'description'      => '',
				'menu_order'       => 0,
			);

			// Insert in the correct position based on the canonical order.
			$order = array( 'inicio', 'apoiadores', 'guias', 'eventos', 'cursos', 'lazer', 'empregos', 'blog', 'irlanda', 'sobre-nos', 'contato' );
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
	// Determine the current request path. The active-state rules below match
	// canonical Portuguese paths (/guias/, /empregos/, …), so the language
	// prefix of an English request (/en/guias/) is stripped first — otherwise
	// English pages would never receive their current-menu-item state. The
	// homepage rule then treats /en/ exactly like /.
	$current_path = conexao_get_current_path();

	$lang_slug = conexao_current_language_slug();
	$default   = conexao_default_language_slug();
	if ( $lang_slug && $default && $lang_slug !== $default ) {
		$prefix = '/' . $lang_slug;
		if ( $current_path === $prefix ) {
			$current_path = '/';
		} elseif ( 0 === strpos( $current_path, $prefix . '/' ) ) {
			$current_path = substr( $current_path, strlen( $prefix ) );
		}
	}

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
		'início'            => 'inicio',
		'inicio'            => 'inicio',
		'home'              => 'inicio',
		'blog'              => 'blog',
		'guias'             => 'guias',
		'guias práticos'    => 'guias',
		'guides'            => 'guias',
		'eventos'           => 'eventos',
		'events'            => 'eventos',
		'cursos'            => 'cursos',
		'courses'           => 'cursos',
		'lazer'             => 'lazer',
		'lazer e turismo'   => 'lazer',
		'leisure'           => 'lazer',
		'leisure & tourism' => 'lazer',
		'empregos'          => 'empregos',
		'jobs'              => 'empregos',
		'apoiadores'        => 'apoiadores',
		'sponsors'          => 'apoiadores',
		'irlanda'           => 'irlanda',
		'ireland'           => 'irlanda',
		'sobre nós'         => 'sobre-nos',
		'sobre nos'         => 'sobre-nos',
		'about'             => 'sobre-nos',
		'about us'          => 'sobre-nos',
		'contato'           => 'contato',
		'contact'           => 'contato',
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
