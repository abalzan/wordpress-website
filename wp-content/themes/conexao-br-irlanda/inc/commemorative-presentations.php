<?php
/**
 * Commemorative experience-package registry and placement dispatcher (theme-owned).
 *
 * Architecture proposal r10 §7.3–§7.11. This is the ONE theme file that owns
 * commemorative presentation: the package registry, the placement catalogue, the
 * request-time dispatch plan, the conditional asset map and the private-preview
 * banner. There is deliberately no second registry or dispatcher.
 *
 * ## Boundaries
 *
 * - The PLUGIN (`conexao-commemorative`) is the only scheduling authority. It
 *   answers one question — "which single event is selected for this request?" —
 *   and knows nothing about templates, placements, handles or holidays.
 * - The THEME resolves the selected event's registered package and decides which
 *   of that package's placements apply to the current route. It never recomputes
 *   dates, priorities, enablement or annual precedence.
 *
 * ## Fail-closed validation
 *
 * A package definition is validated before it is used. An unknown package key, an
 * unknown placement identifier, a template that does not exist or an asset file
 * that does not exist never produces a fatal error, a public warning or
 * accidental output: the offending part is dropped and the rest still renders.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The placement catalogue.
 *
 * Deliberately small (r10 §7.5): a package with several placements IS the
 * "combined" experience; there is no `combined` placement.
 *
 * `routes` lists the route kinds on which the placement may render:
 *   - homepage  — the front page only
 *   - internal  — any singular/archive/home/search request that is not the front
 *                 page, a 404, a feed or an admin/AJAX/REST request
 *   - landing   — the opt-in commemorative landing page for that package
 *
 * @return array<string,array>
 */
function conexao_commemorative_placement_catalogue() {
	$catalogue = array(
		'homepage_hero'     => array(
			'label'  => __( 'Destaque da página inicial', 'conexao-br-irlanda' ),
			'routes' => array( 'homepage' ),
		),
		'site_wide_ambient' => array(
			'label'  => __( 'Decoração ambiente em todo o site', 'conexao-br-irlanda' ),
			'routes' => array( 'homepage', 'internal' ),
		),
		'landing_page'      => array(
			'label'  => __( 'Página comemorativa dedicada', 'conexao-br-irlanda' ),
			'routes' => array( 'landing' ),
		),
	);

	/**
	 * Filters the placement catalogue.
	 *
	 * A new reusable placement is theme work (r10 §7.11); a new event using an
	 * existing package is data work.
	 *
	 * @param array $catalogue Placement definitions keyed by identifier.
	 */
	return (array) apply_filters( 'conexao_commemorative_placement_catalogue', $catalogue );
}

/**
 * The energy table (r10 §7.7 addendum). Supersedes the generic ambient defaults.
 *
 * @return array<string,array>
 */
function conexao_commemorative_energy_table() {
	return array(
		'calm'     => array(
			'interval_min_ms'  => 60000,
			'interval_max_ms'  => 120000,
			'duration_min_ms'  => 2000,
			'duration_max_ms'  => 4000,
			'max_simultaneous' => 1,
			'session_cap'      => 3,
		),
		'moderate' => array(
			'interval_min_ms'  => 45000,
			'interval_max_ms'  => 90000,
			'duration_min_ms'  => 3000,
			'duration_max_ms'  => 6000,
			'max_simultaneous' => 1,
			'session_cap'      => 5,
		),
		'playful'  => array(
			'interval_min_ms'  => 30000,
			'interval_max_ms'  => 60000,
			'duration_min_ms'  => 3000,
			'duration_max_ms'  => 5000,
			'max_simultaneous' => 1,
			'session_cap'      => 7,
		),
	);
}

/**
 * Resolve the ambient options for one placement definition.
 *
 * The package's energy row supplies the defaults; the placement may narrow them.
 * Halloween's already-approved navigation-surprise cadence (20–30 s first,
 * 25–35 s subsequent — `2026-10-27-halloween-mobile-experience-plan.md` §1/§5)
 * is an explicit, approved override of the interval pair only, never a silent
 * replacement of the energy defaults (r10 §7.7 exception).
 *
 * @param array $placement Validated placement definition.
 * @return array
 */
function conexao_commemorative_ambient_options( array $placement ) {
	$table    = conexao_commemorative_energy_table();
	$energy   = isset( $placement['energy'] ) ? (string) $placement['energy'] : 'calm';
	$defaults = isset( $table[ $energy ] ) ? $table[ $energy ] : $table['calm'];
	$options  = isset( $placement['options'] ) && is_array( $placement['options'] ) ? $placement['options'] : array();

	$resolved = array(
		'energy'                     => isset( $table[ $energy ] ) ? $energy : 'calm',
		'interval_min_ms'            => (int) $defaults['interval_min_ms'],
		'interval_max_ms'            => (int) $defaults['interval_max_ms'],
		'subsequent_interval_min_ms' => (int) $defaults['interval_min_ms'],
		'subsequent_interval_max_ms' => (int) $defaults['interval_max_ms'],
		'duration_min_ms'            => (int) $defaults['duration_min_ms'],
		'duration_max_ms'            => (int) $defaults['duration_max_ms'],
		'max_simultaneous'           => 1,
		'session_cap'                => (int) $defaults['session_cap'],
	);

	$integers = array(
		'interval_min_ms',
		'interval_max_ms',
		'subsequent_interval_min_ms',
		'subsequent_interval_max_ms',
		'duration_min_ms',
		'duration_max_ms',
		'max_simultaneous',
		'session_cap',
	);

	foreach ( $integers as $key ) {
		if ( isset( $options[ $key ] ) && is_numeric( $options[ $key ] ) ) {
			$resolved[ $key ] = (int) $options[ $key ];
		}
	}

	// Hard bounds: never invert a range, never allow more than one simultaneous
	// decoration in v1, never allow a cap of zero or a non-positive duration.
	$resolved['interval_min_ms']            = max( 1000, (int) $resolved['interval_min_ms'] );
	$resolved['interval_max_ms']            = max( $resolved['interval_min_ms'], (int) $resolved['interval_max_ms'] );
	$resolved['subsequent_interval_min_ms'] = max( 1000, (int) $resolved['subsequent_interval_min_ms'] );
	$resolved['subsequent_interval_max_ms'] = max( $resolved['subsequent_interval_min_ms'], (int) $resolved['subsequent_interval_max_ms'] );
	$resolved['duration_min_ms']            = max( 200, (int) $resolved['duration_min_ms'] );
	$resolved['duration_max_ms']            = max( $resolved['duration_min_ms'], (int) $resolved['duration_max_ms'] );
	$resolved['max_simultaneous']           = 1;
	$resolved['session_cap']                = max( 1, (int) $resolved['session_cap'] );

	return $resolved;
}


/**
 * The theme-owned experience-package definitions.
 *
 * Two packages are IMPLEMENTED (a full placement map, tokens, art and assets):
 * `halloween` (Stage 4) and `christmas` (Stage 5). The other four remain
 * REGISTERED IDENTITIES with no placements: their event records carry a valid
 * `_ce_presentation` value, but they render nothing until a developer ships their
 * placement map. Registering an identity is not a claim that it is implemented
 * (r10 §7.12: the content calendar lists event records, not a promise that every
 * package ships).
 *
 * @return array<string,array>
 */
function conexao_commemorative_package_definitions() {
	return array(
		'halloween'       => array(
			'label'      => __( 'Experiência de Halloween', 'conexao-br-irlanda' ),
			'status'     => 'implemented',
			'design'     => array(
				'motif'  => 'carved_pumpkin',
				'energy' => 'playful',
				'tokens' => array(
					'primary'   => '#4b2163',
					'secondary' => '#e07b24',
					'accent'    => '#f3a34c',
					'surface'   => '#fff2dd',
				),
				'motion' => array(
					'duration_ms' => 1000,
					'easing'      => 'cubic-bezier(0.35, 0.03, 0.62, 1)',
					'entry'       => 'bounded drift or flight in from a viewport edge',
					'exit'        => 'fades out along the same path, never a hard cut',
				),
				'voice'  => array(
					'tone'       => 'playful, warm, family-friendly',
					'formality'  => 'informal "você" (PT) / direct "you" (EN)',
					'example_pt' => array(
						'headline' => 'Feliz Halloween!',
						'cta'      => 'Explorar eventos',
					),
					'example_en' => array(
						'headline' => 'Happy Halloween!',
						'cta'      => 'Explore events',
					),
				),
				'do_not' => array(
					'No gore-heavy imagery.',
					'Nothing designed to frighten families or children.',
					'Never more than one ambient decoration at once.',
					'Never replace the approved 20-30 s / 25-35 s navigation cadence.',
				),
			),
			'assets'     => array(
				'base_path'   => 'assets/images/presentations/halloween/',
				// Only the witch illustration is a file request; the bat, ghost and
				// pumpkin are inline SVG in the package templates, so they cost no
				// network request and are never a missing-asset risk.
				'decorations' => array(
					'witch' => 'assets/images/presentations/halloween/decorations/witch-broom.svg',
				),
			),
			'placements' => array(
				'homepage_hero'     => array(
					'template' => 'template-parts/commemorative/homepage-hero',
					'content'  => 'template-parts/commemorative/halloween/hero',
					'styles'   => array( 'assets/css/presentations/halloween.css' ),
					'scripts'  => array( 'assets/js/presentations/halloween/halloween.js' ),
					'energy'   => 'playful',
				),
				'site_wide_ambient' => array(
					'template' => 'template-parts/commemorative/ambient-layer',
					'content'  => 'template-parts/commemorative/halloween/ambient',
					'styles'   => array( 'assets/css/presentations/halloween.css' ),
					'scripts'  => array( 'assets/js/presentations/halloween/halloween.js' ),
					'energy'   => 'playful',
					'options'  => array(
						// Approved cadence: 2026-10-27-halloween-mobile-experience-plan §1/§5.
						'interval_min_ms'            => 20000,
						'interval_max_ms'            => 30000,
						'subsequent_interval_min_ms' => 25000,
						'subsequent_interval_max_ms' => 35000,
						// Each effect's own visual duration is 0.9-3.0 s.
						'duration_min_ms'            => 900,
						'duration_max_ms'            => 3000,
						'max_simultaneous'           => 1,
						'session_cap'                => 7,
					),
				),
			),
		),
		'christmas'       => array(
			'label'      => __( 'Experiência de Natal', 'conexao-br-irlanda' ),
			'status'     => 'implemented',
			'design'     => array(
				'motif'  => 'bauble_ornament',
				'energy' => 'calm',
				'tokens' => array(
					'primary'   => '#0f5132',
					'secondary' => '#b3202c',
					'accent'    => '#c8a243',
					'surface'   => '#fdf8ef',
				),
				'motion' => array(
					'duration_ms' => 2600,
					'easing'      => 'cubic-bezier(0.22, 0.61, 0.36, 1)',
					'entry'       => 'one slow settle downwards',
					'exit'        => 'a slow fade while still descending',
				),
				'voice'  => array(
					'tone'       => 'warm, reflective, community-minded',
					'formality'  => 'informal "você" (PT) / direct "you" (EN)',
					'example_pt' => array(
						'headline' => 'Um Natal com sabor de casa',
						'cta'      => 'Ver a agenda de Natal',
					),
					'example_en' => array(
						'headline' => 'A Christmas that feels like home',
						'cta'      => 'See the Christmas calendar',
					),
				),
				'do_not' => array(
					'No tropical beach imagery that clashes with an Irish winter.',
					'Do not ignore the religious dimension of the season.',
					'Never reuse the Halloween motif, palette or motion language.',
					'No countdown timers and no auto-playing music.',
				),
			),
			'assets'     => array(
				'base_path'   => 'assets/images/presentations/christmas/',
				'decorations' => array(
					'bauble' => 'assets/images/presentations/christmas/decorations/bauble.svg',
				),
			),
			'placements' => array(
				'homepage_hero'     => array(
					'template' => 'template-parts/commemorative/homepage-hero',
					'content'  => 'template-parts/commemorative/christmas/hero',
					'styles'   => array( 'assets/css/presentations/christmas.css' ),
					'scripts'  => array( 'assets/js/presentations/christmas/christmas.js' ),
					'energy'   => 'calm',
				),
				'site_wide_ambient' => array(
					'template' => 'template-parts/commemorative/ambient-layer',
					'content'  => 'template-parts/commemorative/christmas/ambient',
					'styles'   => array( 'assets/css/presentations/christmas.css' ),
					'scripts'  => array( 'assets/js/presentations/christmas/christmas.js' ),
					'energy'   => 'calm',
					'options'  => array(
						'max_simultaneous' => 1,
						'session_cap'      => 3,
					),
				),
				'landing_page'      => array(
					'template' => 'template-parts/commemorative/landing-page',
					'content'  => 'template-parts/commemorative/christmas/landing',
					'styles'   => array( 'assets/css/presentations/christmas.css' ),
					'scripts'  => array( 'assets/js/presentations/christmas/christmas.js' ),
					'energy'   => 'calm',
					'options'  => array(
						'page_slugs' => array(
							'pt' => 'natal',
							'en' => 'christmas',
						),
					),
				),
			),
		),
		'new_year'        => array(
			'label'      => __( 'Experiência de Ano Novo', 'conexao-br-irlanda' ),
			'status'     => 'planned',
			'design'     => array(
				'motif'  => 'four_point_sparkle',
				'energy' => 'playful',
				'tokens' => array(),
			),
			'placements' => array(),
		),
		'st_patricks_day' => array(
			'label'      => __( 'Experiência do Dia de São Patrício', 'conexao-br-irlanda' ),
			'status'     => 'planned',
			'design'     => array(
				'motif'  => 'shamrock',
				'energy' => 'moderate',
				'tokens' => array(),
			),
			'placements' => array(),
		),
		'valentines_day'  => array(
			'label'      => __( 'Experiência do Dia dos Namorados', 'conexao-br-irlanda' ),
			'status'     => 'planned',
			'design'     => array(
				'motif'  => 'heart',
				'energy' => 'calm',
				'tokens' => array(),
			),
			'placements' => array(),
		),
		'easter'          => array(
			'label'      => __( 'Experiência de Páscoa', 'conexao-br-irlanda' ),
			'status'     => 'planned',
			'design'     => array(
				'motif'  => 'decorated_egg',
				'energy' => 'calm',
				'tokens' => array(),
			),
			'placements' => array(),
		),
	);
}

/**
 * Register the commemorative experience packages.
 *
 * The r10 §7.4 contract: this filter is how the THEME tells the plugin which
 * package keys exist. An entry supplied by another filter is never overwritten.
 *
 * @param mixed $packages Existing packages (a filter may return anything).
 * @return array
 */
function conexao_commemorative_register_presentations( $packages ) {
	if ( ! is_array( $packages ) ) {
		$packages = array();
	}

	foreach ( conexao_commemorative_package_definitions() as $slug => $definition ) {
		if ( isset( $packages[ $slug ] ) ) {
			continue;
		}

		$packages[ $slug ] = $definition;
	}

	return $packages;
}
add_filter( 'conexao_commemorative_presentations', 'conexao_commemorative_register_presentations' );

/**
 * Whether a theme-relative file exists.
 *
 * @param string $relative_path Path relative to the theme root.
 * @return bool
 */
function conexao_commemorative_theme_file_exists( $relative_path ) {
	$relative_path = ltrim( (string) $relative_path, '/' );

	if ( '' === $relative_path || false !== strpos( $relative_path, '..' ) ) {
		return false;
	}

	$root = defined( 'CONEXAO_THEME_DIR' ) ? CONEXAO_THEME_DIR : get_template_directory();

	return is_file( $root . '/' . $relative_path );
}


/**
 * Validate one package definition.
 *
 * Fail-closed (r10 §7.4/§7.11): unknown placement identifiers, missing templates
 * and missing asset files are dropped; nothing is echoed or warned about.
 *
 * @param string $slug       Package slug.
 * @param mixed  $definition Raw definition from the registry.
 * @return array|null Validated definition, or null when the package is unusable.
 */
function conexao_commemorative_validate_package( $slug, $definition ) {
	$slug = (string) $slug;

	if ( '' === $slug || ! is_array( $definition ) ) {
		return null;
	}

	$catalogue = conexao_commemorative_placement_catalogue();
	$design    = isset( $definition['design'] ) && is_array( $definition['design'] ) ? $definition['design'] : array();
	$tokens    = array();

	foreach ( array( 'primary', 'secondary', 'accent', 'surface' ) as $token ) {
		$value     = isset( $design['tokens'][ $token ] ) ? (string) $design['tokens'][ $token ] : '';
		$sanitised = function_exists( 'sanitize_hex_color' ) ? sanitize_hex_color( $value ) : '';

		// Only a hex colour is accepted; anything else falls back to the CSS file.
		if ( is_string( $sanitised ) && '' !== $sanitised ) {
			$tokens[ $token ] = $sanitised;
		}
	}

	$placements = array();
	$raw        = isset( $definition['placements'] ) && is_array( $definition['placements'] ) ? $definition['placements'] : array();

	foreach ( $raw as $placement_id => $placement ) {
		$placement_id = (string) $placement_id;

		// Unknown placement identifier: dropped. Never rendered, never warned.
		if ( ! isset( $catalogue[ $placement_id ] ) || ! is_array( $placement ) ) {
			continue;
		}

		$template = isset( $placement['template'] ) ? (string) $placement['template'] : '';
		$content  = isset( $placement['content'] ) ? (string) $placement['content'] : '';

		// A missing shared template makes the placement unrenderable: drop it.
		if ( '' === $template || ! conexao_commemorative_theme_file_exists( $template . '.php' ) ) {
			continue;
		}

		// A missing package content template is equally fatal for the placement.
		if ( '' !== $content && ! conexao_commemorative_theme_file_exists( $content . '.php' ) ) {
			continue;
		}

		$styles  = array();
		$scripts = array();

		foreach ( isset( $placement['styles'] ) && is_array( $placement['styles'] ) ? $placement['styles'] : array() as $path ) {
			if ( conexao_commemorative_theme_file_exists( (string) $path ) ) {
				$styles[] = (string) $path;
			}
		}

		foreach ( isset( $placement['scripts'] ) && is_array( $placement['scripts'] ) ? $placement['scripts'] : array() as $path ) {
			if ( conexao_commemorative_theme_file_exists( (string) $path ) ) {
				$scripts[] = (string) $path;
			}
		}

		$placements[ $placement_id ] = array(
			'id'       => $placement_id,
			'template' => $template,
			'content'  => $content,
			'styles'   => array_values( array_unique( $styles ) ),
			'scripts'  => array_values( array_unique( $scripts ) ),
			'energy'   => isset( $placement['energy'] ) ? (string) $placement['energy'] : ( isset( $design['energy'] ) ? (string) $design['energy'] : 'calm' ),
			'options'  => isset( $placement['options'] ) && is_array( $placement['options'] ) ? $placement['options'] : array(),
			'routes'   => $catalogue[ $placement_id ]['routes'],
		);
	}

	return array(
		'slug'       => $slug,
		'label'      => isset( $definition['label'] ) ? (string) $definition['label'] : $slug,
		'status'     => isset( $definition['status'] ) ? (string) $definition['status'] : 'planned',
		'motif'      => isset( $design['motif'] ) ? (string) $design['motif'] : '',
		'energy'     => isset( $design['energy'] ) ? (string) $design['energy'] : 'calm',
		'tokens'     => $tokens,
		'motion'     => isset( $design['motion'] ) && is_array( $design['motion'] ) ? $design['motion'] : array(),
		'voice'      => isset( $design['voice'] ) && is_array( $design['voice'] ) ? $design['voice'] : array(),
		'do_not'     => isset( $design['do_not'] ) && is_array( $design['do_not'] ) ? array_values( array_map( 'strval', $design['do_not'] ) ) : array(),
		'assets'     => isset( $definition['assets'] ) && is_array( $definition['assets'] ) ? $definition['assets'] : array(),
		'placements' => $placements,
	);
}


/**
 * The validated package registry (cached per request, resettable for tests).
 *
 * @param bool $refresh Rebuild the cache.
 * @return array<string,array>
 */
function conexao_commemorative_packages( $refresh = false ) {
	if ( $refresh || ! isset( $GLOBALS['conexao_ce_packages'] ) ) {
		$packages = array();
		$raw      = apply_filters( 'conexao_commemorative_presentations', array() );

		if ( is_array( $raw ) ) {
			foreach ( $raw as $slug => $definition ) {
				$validated = conexao_commemorative_validate_package( $slug, $definition );

				if ( null !== $validated ) {
					$packages[ (string) $slug ] = $validated;
				}
			}
		}

		$GLOBALS['conexao_ce_packages'] = $packages;
	}

	return $GLOBALS['conexao_ce_packages'];
}

/**
 * The validated definition of one package, or null when it is not registered.
 *
 * @param string $slug Package slug.
 * @return array|null
 */
function conexao_commemorative_package( $slug ) {
	$packages = conexao_commemorative_packages();
	$slug     = (string) $slug;

	return isset( $packages[ $slug ] ) ? $packages[ $slug ] : null;
}

/**
 * Discard the memoised registry and request plan (tests, and after a filter change).
 *
 * @return void
 */
function conexao_commemorative_flush_plan() {
	unset( $GLOBALS['conexao_ce_packages'], $GLOBALS['conexao_ce_plan'] );
}

/**
 * The package a page opted into as its commemorative landing page, or ''.
 *
 * Two independent opt-ins are required: the assigned page template AND the
 * `_ce_landing_package` meta naming a registered package. A page cannot become a
 * commemorative landing page by accident, and the `commemorative_event` CPT is
 * never involved (r10 §7.5, gate (c)).
 *
 * `$_ce_landing_forced` is a test-only override used by the in-process suites to
 * simulate a route without mutating the database.
 *
 * @param int $post_id Page ID (0 = the queried page).
 * @return string Package slug, or '' when the page is not an opt-in landing page.
 */
function conexao_commemorative_landing_page_package( $post_id = 0 ) {
	$post_id = $post_id > 0 ? (int) $post_id : ( is_page() ? (int) get_queried_object_id() : 0 );

	if ( $post_id <= 0 ) {
		return '';
	}

	$template = (string) get_post_meta( $post_id, '_wp_page_template', true );

	if ( 'page-commemorative-landing.php' !== $template ) {
		return '';
	}

	$slug     = (string) get_post_meta( $post_id, '_ce_landing_package', true );
	$packages = conexao_commemorative_packages();

	if ( '' === $slug || ! isset( $packages[ $slug ] ) ) {
		return '';
	}

	return $slug;
}

/**
 * Whether a page is an opt-in commemorative landing page.
 *
 * Used by the sitemap exporter: such a page is archived, not promoted.
 *
 * @param int $post_id Page ID.
 * @return bool
 */
function conexao_commemorative_is_landing_page( $post_id ) {
	return '' !== conexao_commemorative_landing_page_package( (int) $post_id );
}

/**
 * Whether a package's landing-page placement matches the queried page.
 *
 * The page slug must equal the package's declared slug for the current language,
 * so a mis-published page renders nothing rather than the wrong experience.
 *
 * @param array $package Validated package definition.
 * @return bool
 */
function conexao_commemorative_landing_slug_matches( array $package ) {
	if ( ! isset( $package['placements']['landing_page'] ) ) {
		return false;
	}

	$options = $package['placements']['landing_page']['options'];
	$slugs   = isset( $options['page_slugs'] ) && is_array( $options['page_slugs'] ) ? $options['page_slugs'] : array();
	$lang    = function_exists( 'conexao_current_language_slug' ) ? (string) conexao_current_language_slug() : '';
	$expect  = isset( $slugs[ $lang ] ) ? (string) $slugs[ $lang ] : '';
	$page    = get_queried_object();
	$actual  = $page instanceof WP_Post ? (string) $page->post_name : '';

	return '' !== $expect && $expect === $actual;
}

/**
 * The route kinds that apply to the current request.
 *
 * @return string[]
 */
function conexao_commemorative_route_kinds() {
	$kinds = array();

	if ( is_admin() || is_feed() || is_404() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
		return $kinds;
	}

	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return $kinds;
	}

	if ( is_front_page() ) {
		$kinds[] = 'homepage';
	} elseif ( is_singular() || is_archive() || is_home() || is_search() ) {
		$kinds[] = 'internal';
	}

	if ( '' !== conexao_commemorative_landing_page_package() ) {
		$kinds[] = 'landing';
	}

	return $kinds;
}


/**
 * Build the dispatch plan for this request.
 *
 * This is the ONLY place that decides which placements render. It asks the engine
 * for the single active event (never recomputing scheduling) and resolves that
 * event's registered package. The landing-page placement is resolved
 * independently: an opted-in landing page stays accessible after the event
 * window for archival value (r10 §7.13), so it does not require an active event.
 *
 * @param DateTimeInterface|null $now     Injected clock (tests).
 * @return array
 */
function conexao_commemorative_build_plan( $now = null ) {
	$route_kinds = conexao_commemorative_route_kinds();
	$event       = function_exists( 'conexao_commemorative_active' ) ? conexao_commemorative_active( $now ) : null;
	$package     = null;
	$placements  = array();

	if ( $event && '' !== (string) $event->presentation ) {
		$candidate = conexao_commemorative_package( $event->presentation );

		if ( null !== $candidate ) {
			foreach ( $candidate['placements'] as $placement_id => $placement ) {
				if ( array_intersect( $placement['routes'], $route_kinds ) ) {
					$placements[ $placement_id ] = $placement;
				}
			}

			// A package with no placement on this route renders nothing at all.
			if ( $placements ) {
				$package = $candidate;
			}
		}
	}

	$landing = array();

	if ( in_array( 'landing', $route_kinds, true ) ) {
		$landing_slug = conexao_commemorative_landing_page_package();
		$landing_pkg  = '' !== $landing_slug ? conexao_commemorative_package( $landing_slug ) : null;

		if ( null !== $landing_pkg && conexao_commemorative_landing_slug_matches( $landing_pkg ) ) {
			$landing = array(
				'package'   => $landing_pkg,
				'placement' => $landing_pkg['placements']['landing_page'],
				'page_id'   => (int) get_queried_object_id(),
			);
		}
	}

	return array(
		'route_kinds' => $route_kinds,
		'event'       => $event,
		'package'     => $package,
		'placements'  => $placements,
		'landing'     => $landing,
	);
}

/**
 * The memoised dispatch plan for this request.
 *
 * @param bool $refresh Rebuild the plan.
 * @return array
 */
function conexao_commemorative_request_plan( $refresh = false ) {
	if ( $refresh || ! isset( $GLOBALS['conexao_ce_plan'] ) ) {
		$GLOBALS['conexao_ce_plan'] = conexao_commemorative_build_plan();
	}

	return $GLOBALS['conexao_ce_plan'];
}

/**
 * Whether any commemorative placement renders on this request.
 *
 * @return bool
 */
function conexao_commemorative_has_dispatch() {
	$plan = conexao_commemorative_request_plan();

	return ! empty( $plan['placements'] ) || ! empty( $plan['landing'] );
}

/**
 * Whether one placement renders on this request.
 *
 * @param string $placement_id Placement identifier.
 * @return bool
 */
function conexao_commemorative_has_placement( $placement_id ) {
	$context = conexao_commemorative_placement_context( $placement_id );

	return null !== $context;
}

/**
 * The render context for one placement, or null when it does not render here.
 *
 * @param string $placement_id Placement identifier.
 * @return array|null
 */
function conexao_commemorative_placement_context( $placement_id ) {
	$plan         = conexao_commemorative_request_plan();
	$placement_id = (string) $placement_id;

	if ( isset( $plan['placements'][ $placement_id ] ) && null !== $plan['package'] ) {
		return array(
			'placement_id' => $placement_id,
			'placement'    => $plan['placements'][ $placement_id ],
			'package'      => $plan['package'],
			'event'        => $plan['event'],
			'source'       => 'event',
			'page_id'      => 0,
		);
	}

	if ( ! empty( $plan['landing'] ) && 'landing_page' === $placement_id ) {
		return array(
			'placement_id' => $placement_id,
			'placement'    => $plan['landing']['placement'],
			'package'      => $plan['landing']['package'],
			'event'        => $plan['event'],
			'source'       => 'landing',
			'page_id'      => (int) $plan['landing']['page_id'],
		);
	}

	return null;
}

/**
 * Render one placement.
 *
 * Fail-closed: a placement that does not render here, or whose template vanished
 * between validation and render, outputs nothing at all.
 *
 * @param string $placement_id Placement identifier.
 * @return bool Whether something was rendered.
 */
function conexao_commemorative_render_placement( $placement_id ) {
	$context = conexao_commemorative_placement_context( $placement_id );

	if ( null === $context ) {
		return false;
	}

	$template = (string) $context['placement']['template'];

	if ( '' === $template || ! conexao_commemorative_theme_file_exists( $template . '.php' ) ) {
		return false;
	}

	get_template_part( $template, null, array( 'ce' => $context ) );

	return true;
}

/**
 * The wrapper attributes every placement shares: placement class, package class,
 * data attributes and the package design tokens as `--ce-*` custom properties.
 *
 * @param array $context Render context.
 * @return string Escaped attribute string (leading space included).
 */
function conexao_commemorative_wrapper_attributes( array $context ) {
	$package   = $context['package'];
	$placement = $context['placement'];

	$classes = array(
		'ce-placement',
		'ce-placement--' . sanitize_html_class( $context['placement_id'] ),
		'ce-package',
		'ce-package--' . sanitize_html_class( $package['slug'] ),
	);

	$style = '';

	foreach ( $package['tokens'] as $token => $value ) {
		$style .= '--ce-' . sanitize_html_class( $token ) . ':' . $value . ';';
	}

	$attributes = sprintf(
		' class="%s" data-ce-package="%s" data-ce-placement="%s"',
		esc_attr( implode( ' ', $classes ) ),
		esc_attr( $package['slug'] ),
		esc_attr( $context['placement_id'] )
	);

	if ( '' !== $style ) {
		$attributes .= sprintf( ' style="%s"', esc_attr( $style ) );
	}

	if ( ! empty( $package['motif'] ) ) {
		$attributes .= sprintf( ' data-ce-motif="%s"', esc_attr( $package['motif'] ) );
	}

	return $attributes;
}


/**
 * The deterministic handle for one theme asset path.
 *
 * `assets/css/presentations/halloween.css` → `conexao-ce-css-presentations-halloween`.
 * The same file always maps to the same handle, so two placements that share an
 * asset never enqueue it twice.
 *
 * @param string $path Theme-relative asset path.
 * @return string
 */
function conexao_commemorative_asset_handle( $path ) {
	$path = (string) $path;
	$path = preg_replace( '/\.[a-z0-9]+$/i', '', $path );
	$path = preg_replace( '#^assets/#', '', (string) $path );

	return 'conexao-ce-' . sanitize_key( str_replace( '/', '-', (string) $path ) );
}

/**
 * The conditional asset plan for a dispatch plan (pure; no side effects).
 *
 * Only the assets of placements that actually render on this route are included
 * (r10 §7.9). With no placement, every list is empty — the negative proof for
 * gate (a) and gate (f).
 *
 * @param array $plan Dispatch plan from `conexao_commemorative_request_plan()`.
 * @return array
 */
function conexao_commemorative_asset_plan( array $plan ) {
	$styles     = array();
	$scripts    = array();
	$placements = array();
	$sources    = array();

	foreach ( isset( $plan['placements'] ) && is_array( $plan['placements'] ) ? $plan['placements'] : array() as $placement_id => $placement ) {
		$sources[ $placement_id ] = array(
			'package' => isset( $plan['package']['slug'] ) ? (string) $plan['package']['slug'] : '',
			'styles'  => isset( $placement['styles'] ) ? (array) $placement['styles'] : array(),
			'scripts' => isset( $placement['scripts'] ) ? (array) $placement['scripts'] : array(),
		);
	}

	if ( ! empty( $plan['landing']['placement'] ) ) {
		$sources['landing_page'] = array(
			'package' => isset( $plan['landing']['package']['slug'] ) ? (string) $plan['landing']['package']['slug'] : '',
			'styles'  => isset( $plan['landing']['placement']['styles'] ) ? (array) $plan['landing']['placement']['styles'] : array(),
			'scripts' => isset( $plan['landing']['placement']['scripts'] ) ? (array) $plan['landing']['placement']['scripts'] : array(),
		);
	}

	foreach ( $sources as $placement_id => $source ) {
		$placement_styles  = array();
		$placement_scripts = array();

		foreach ( $source['styles'] as $path ) {
			$handle             = conexao_commemorative_asset_handle( $path );
			$styles[ $handle ]  = (string) $path;
			$placement_styles[] = $handle;
		}

		foreach ( $source['scripts'] as $path ) {
			$handle              = conexao_commemorative_asset_handle( $path );
			$scripts[ $handle ]  = (string) $path;
			$placement_scripts[] = $handle;
		}

		$placements[ $placement_id ] = array(
			'package' => $source['package'],
			'styles'  => array_values( array_unique( $placement_styles ) ),
			'scripts' => array_values( array_unique( $placement_scripts ) ),
		);
	}

	return array(
		'shared'        => ( ! empty( $styles ) || ! empty( $scripts ) ),
		'styles'        => $styles,
		'scripts'       => $scripts,
		'placements'    => $placements,
		'shared_style'  => 'conexao-commemorative',
		'shared_script' => 'conexao-commemorative',
	);
}

/**
 * Enqueue the commemorative assets for exactly the placements that render.
 *
 * The shared framework loads only when at least one placement is displayed;
 * package/placement assets load only for their own placement (r10 §7.9). With no
 * active event the function enqueues nothing at all.
 *
 * @return void
 */
function conexao_commemorative_enqueue_assets() {
	$assets = conexao_commemorative_asset_plan( conexao_commemorative_request_plan() );

	if ( ! $assets['shared'] ) {
		return;
	}

	$theme_uri = defined( 'CONEXAO_THEME_URI' ) ? CONEXAO_THEME_URI : get_template_directory_uri();
	$version   = function_exists( 'conexao_asset_version' ) ? 'conexao_asset_version' : null;

	$shared_deps = array();

	// dark-mode.css must keep winning the cascade, so the shared framework and
	// every package stylesheet are declared AFTER it.
	if ( wp_style_is( 'conexao-dark-mode', 'enqueued' ) || wp_style_is( 'conexao-dark-mode', 'registered' ) ) {
		$shared_deps[] = 'conexao-dark-mode';
	}

	wp_enqueue_style(
		$assets['shared_style'],
		$theme_uri . '/assets/css/commemorative.css',
		$shared_deps,
		$version ? conexao_asset_version( 'assets/css/commemorative.css' ) : null
	);

	wp_enqueue_script(
		$assets['shared_script'],
		$theme_uri . '/assets/js/commemorative.js',
		array(),
		$version ? conexao_asset_version( 'assets/js/commemorative.js' ) : null,
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);

	foreach ( $assets['styles'] as $handle => $path ) {
		wp_enqueue_style(
			$handle,
			$theme_uri . '/' . ltrim( $path, '/' ),
			array( $assets['shared_style'] ),
			$version ? conexao_asset_version( $path ) : null
		);
	}

	foreach ( $assets['scripts'] as $handle => $path ) {
		wp_enqueue_script(
			$handle,
			$theme_uri . '/' . ltrim( $path, '/' ),
			array( $assets['shared_script'] ),
			$version ? conexao_asset_version( $path ) : null,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}
}
add_action( 'wp_enqueue_scripts', 'conexao_commemorative_enqueue_assets', 20 );


/**
 * Add the generic commemorative body classes.
 *
 * `body.ce-active` plus one class per rendered placement and the package slug.
 * They carry no holiday identity of their own — the slug is data — and they let a
 * package reserve space for its own band without any layout read in JavaScript:
 *
 *   body.ce-active.ce-package-christmas.ce-placement-homepage_hero .hero-section
 *
 * @param mixed $classes Existing body classes (a filter may pass anything).
 * @return array
 */
function conexao_commemorative_body_classes( $classes ) {
	$classes = is_array( $classes ) ? $classes : array();
	$plan    = conexao_commemorative_request_plan();

	$placements = array_keys( isset( $plan['placements'] ) && is_array( $plan['placements'] ) ? $plan['placements'] : array() );
	$slug       = isset( $plan['package']['slug'] ) ? (string) $plan['package']['slug'] : '';

	if ( ! empty( $plan['landing']['package']['slug'] ) ) {
		$placements[] = 'landing_page';
		$slug         = (string) $plan['landing']['package']['slug'];
	}

	if ( empty( $placements ) ) {
		return $classes;
	}

	$classes[] = 'ce-active';

	if ( '' !== $slug ) {
		$classes[] = 'ce-package-' . sanitize_html_class( $slug );
	}

	foreach ( $placements as $placement_id ) {
		$classes[] = 'ce-placement-' . sanitize_html_class( str_replace( '_', '-', (string) $placement_id ) );
	}

	return array_values( array_unique( $classes ) );
}
add_filter( 'body_class', 'conexao_commemorative_body_classes' );

/**
 * Print the generic client marker for whichever packages render here.
 *
 * The marker carries no holiday name: the client script reads the package slug
 * and the placement list, so `header.php` never needs an event-identity check
 * (r10 §8.1: "the dispatcher emits the marker for whichever presentation
 * requests it").
 *
 * @return void
 */
function conexao_commemorative_body_marker() {
	$plan = conexao_commemorative_request_plan();
	$rows = array();

	if ( ! empty( $plan['placements'] ) && isset( $plan['package']['slug'] ) ) {
		$rows[] = array(
			'slug'       => (string) $plan['package']['slug'],
			'placements' => array_keys( $plan['placements'] ),
		);
	}

	if ( ! empty( $plan['landing']['package']['slug'] ) ) {
		$rows[] = array(
			'slug'       => (string) $plan['landing']['package']['slug'],
			'placements' => array( 'landing_page' ),
		);
	}

	foreach ( $rows as $row ) {
		printf(
			'<span class="ce-active-marker" data-ce-package="%1$s" data-ce-placements="%2$s" hidden></span>',
			esc_attr( $row['slug'] ),
			esc_attr( implode( ' ', $row['placements'] ) )
		);
	}
}

/**
 * Print the non-dismissible private-preview banner.
 *
 * The ENGINE owns the preview authorisation; the THEME owns this markup (§2.2:
 * the engine never renders markup). The banner plus the `data-preview`
 * attribute make it impossible to mistake a preview for public activation.
 *
 * @return void
 */
function conexao_commemorative_preview_banner() {
	if ( ! function_exists( 'conexao_commemorative_is_preview' ) || ! conexao_commemorative_is_preview() ) {
		return;
	}

	$event = function_exists( 'conexao_commemorative_active' ) ? conexao_commemorative_active() : null;
	$key   = $event ? $event->event_key : '';

	printf(
		'<div class="conexao-preview-banner" data-preview="1" role="status"><strong>%1$s</strong> %2$s</div>',
		esc_html__( 'Pré-visualização', 'conexao-br-irlanda' ),
		esc_html(
			sprintf(
				/* translators: %s: event key. */
				__( 'Você está vendo uma pré-visualização privada do evento "%s". Esta página não está visível para o público.', 'conexao-br-irlanda' ),
				'' !== $key ? $key : '—'
			)
		)
	);
}
add_action( 'wp_body_open', 'conexao_commemorative_preview_banner', 1 );
add_action( 'wp_body_open', 'conexao_commemorative_body_marker', 2 );
