<?php
/**
 * JSON-LD structured data and breadcrumbs
 *
 * The JSON-LD graph emitted in wp_head, the singular-document schema
 * builder, the breadcrumb data chain and the visible breadcrumb renderer
 * used by header.php.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * ---------------------------------------------------------------------------
 * 6. STRUCTURED DATA / SCHEMA.ORG
 * ---------------------------------------------------------------------------
 * WebSite + Organization on every page. Content-specific schema on singles.
 */
function conexao_seo_schema() {
	$site_name = get_bloginfo( 'name' );
	$home_url  = home_url( '/' );

	// --- WebSite + Organization (site-wide) ---
	$website = array(
		'@context'    => 'https://schema.org',
		'@type'       => 'WebSite',
		'name'        => $site_name,
		'url'         => $home_url,
		'description' => get_bloginfo( 'description' ),
		'inLanguage'  => conexao_seo_in_language(),
		'potentialAction' => array(
			'@type'       => 'SearchAction',
			'target'      => $home_url . '?s={search_term_string}',
			'query-input' => 'required name=search_term_string',
		),
	);

	$organization = array(
		'@context' => 'https://schema.org',
		'@type'    => 'Organization',
		'name'     => $site_name,
		'url'      => $home_url,
		'logo'     => CONEXAO_THEME_URI . '/assets/images/conexao-social-card.svg',
		'sameAs'   => array_filter( array(
			get_theme_mod( 'conexao_instagram', '' ),
			get_theme_mod( 'conexao_facebook', '' ),
		) ),
	);

	echo '<script type="application/ld+json">' . wp_json_encode( $website ) . '</script>' . "\n";
	echo '<script type="application/ld+json">' . wp_json_encode( $organization ) . '</script>' . "\n";

	// --- BreadcrumbList (site-wide, on non-front pages) ---
	if ( ! is_front_page() ) {
		$crumbs = conexao_seo_breadcrumb_data();
		if ( ! empty( $crumbs ) ) {
			$breadcrumb_schema = array(
				'@context'        => 'https://schema.org',
				'@type'           => 'BreadcrumbList',
				'itemListElement' => array(),
			);
			$position = 1;
			foreach ( $crumbs as $crumb ) {
				$breadcrumb_schema['itemListElement'][] = array(
					'@type'    => 'ListItem',
					'position' => $position,
					'name'     => $crumb['name'],
					'item'     => $crumb['url'],
				);
				$position++;
			}
			echo '<script type="application/ld+json">' . wp_json_encode( $breadcrumb_schema ) . '</script>' . "\n";
		}
	}

	// --- Content-specific schema on singular pages ---
	if ( is_singular() ) {
		conexao_seo_schema_singular();
	}
}
add_action( 'wp_head', 'conexao_seo_schema', 10 );

/**
 * Content-specific structured data for singular CPTs.
 */
function conexao_seo_schema_singular() {
	$post_id = get_the_ID();
	$type    = get_post_type();

	$publisher = array(
		'@type' => 'Organization',
		'name'  => get_bloginfo( 'name' ),
		'logo'  => array(
			'@type' => 'ImageObject',
			'url'   => CONEXAO_THEME_URI . '/assets/images/conexao-social-card.svg',
		),
	);

	$image = has_post_thumbnail() ? get_the_post_thumbnail_url( null, 'full' ) : CONEXAO_THEME_URI . '/assets/images/conexao-social-card.svg';

	// --- Article (blog posts) ---
	if ( 'post' === $type ) {
		$schema = array(
			'@context'      => 'https://schema.org',
			'@type'         => 'Article',
			'headline'      => get_the_title(),
			'image'         => $image,
			'datePublished' => get_the_date( 'c' ),
			'dateModified'  => get_the_modified_date( 'c' ),
			'author'        => array( '@type' => 'Person', 'name' => get_the_author() ),
			'publisher'     => $publisher,
			'mainEntityOfPage' => get_permalink(),
			'inLanguage'    => conexao_seo_in_language(),
		);
		echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>' . "\n";
	}

	// --- Guide (Article) ---
	if ( 'guide' === $type ) {
		$schema = array(
			'@context'      => 'https://schema.org',
			'@type'         => 'Article',
			'headline'      => get_the_title(),
			'image'         => $image,
			'datePublished' => get_the_date( 'c' ),
			'dateModified'  => get_the_modified_date( 'c' ),
			'author'        => array( '@type' => 'Person', 'name' => get_the_author() ),
			'publisher'     => $publisher,
			'mainEntityOfPage' => get_permalink(),
			'inLanguage'    => conexao_seo_in_language(),
		);
		echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>' . "\n";
	}

	// --- Event ---
	if ( 'event' === $type ) {
		$event_date     = get_post_meta( $post_id, '_event_date', true );
		$event_time     = get_post_meta( $post_id, '_event_time', true );
		$event_location = get_post_meta( $post_id, '_event_location', true );
		$counties       = get_the_terms( $post_id, 'conexao_county' );

		$schema = array(
			'@context'  => 'https://schema.org',
			'@type'     => 'Event',
			'name'      => get_the_title(),
			'url'       => get_permalink(),
			'image'     => $image,
			'eventStatus' => 'https://schema.org/EventScheduled',
			'inLanguage'  => conexao_seo_in_language(),
		);

		if ( $event_date ) {
			$start = $event_date . ( $event_time ? 'T' . $event_time : '' );
			$schema['startDate'] = $start;
		}

		if ( $event_location || ( $counties && ! is_wp_error( $counties ) ) ) {
			$location_name = $event_location ? $event_location : $counties[0]->name;
			$schema['location'] = array(
				'@type' => 'Place',
				'name'  => $location_name,
			);
		}

		$schema['organizer'] = array(
			'@type' => 'Organization',
			'name'  => get_bloginfo( 'name' ),
			'url'   => home_url( '/' ),
		);

		echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>' . "\n";
	}

	// --- JobPosting ---
	if ( 'job' === $type ) {
		$job_company   = get_post_meta( $post_id, '_job_company', true );
		$job_location  = get_post_meta( $post_id, '_job_location', true );
		$job_salary    = get_post_meta( $post_id, '_job_salary', true );
		$job_type      = get_post_meta( $post_id, '_job_employment_type', true );
		$job_expires   = get_post_meta( $post_id, '_job_expiration_date', true );

		$schema = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'JobPosting',
			'title'           => get_the_title(),
			'description'     => wp_strip_all_tags( get_the_excerpt() ? get_the_excerpt() : get_the_content() ),
			'datePosted'      => get_the_date( 'c' ),
			'hiringOrganization' => array(
				'@type' => 'Organization',
				'name'  => $job_company ? $job_company : get_bloginfo( 'name' ),
			),
		);

		if ( $job_location ) {
			$schema['jobLocation'] = array(
				'@type'   => 'Place',
				'address' => array(
					'@type'   => 'PostalAddress',
					'addressLocality' => $job_location,
					'addressCountry'  => 'IE',
				),
			);
		}

		if ( $job_type ) {
			$schema['employmentType'] = $job_type;
		}

		if ( $job_salary ) {
			$schema['baseSalary'] = array(
				'@type'    => 'MonetaryAmount',
				'currency' => 'EUR',
				'value'    => array(
					'@type'   => 'QuantitativeValue',
					'value'   => $job_salary,
					'unitText' => 'MONTH',
				),
			);
		}

		if ( $job_expires ) {
			$schema['validThrough'] = $job_expires;
		}

		echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>' . "\n";
	}

	// --- Apoiador (Organization / LocalBusiness) ---
	if ( 'sponsor' === $type ) {
		$sponsor_link = get_post_meta( $post_id, '_sponsor_link', true );
		$counties     = get_the_terms( $post_id, 'conexao_county' );

		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Organization',
			'name'     => get_the_title(),
			'url'      => $sponsor_link ? $sponsor_link : get_permalink(),
			'image'    => $image,
			'description' => wp_strip_all_tags( get_the_excerpt() ),
		);

		if ( $sponsor_link ) {
			$schema['sameAs'] = $sponsor_link;
		}

		if ( $counties && ! is_wp_error( $counties ) ) {
			$schema['address'] = array(
				'@type'           => 'PostalAddress',
				'addressLocality' => $counties[0]->name,
				'addressCountry'  => 'IE',
			);
		}

		echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>' . "\n";
	}

	// --- Lazer (TouristAttraction) — INTERNAL pages only ---
	// Records with an external destination redirect via conexao_leisure_redirect()
	// BEFORE this header renders; the guard below keeps that claim explicit and
	// truthful. Only properties actually stored/verified are emitted (name,
	// description, image, place/address) — never opening hours, price,
	// aggregate rating, reviews, accessibility or geo coordinates, which are
	// not stored for this content. WebSite / Organization / BreadcrumbList and
	// canonical/sitemap behaviour are untouched (emitted separately, site-wide).
	if ( 'leisure' === $type && ( ! function_exists( 'conexao_leisure_external_url' ) || ! conexao_leisure_external_url( $post_id ) ) ) {
		$schema = array(
			'@context'         => 'https://schema.org',
			'@type'            => 'TouristAttraction',
			'name'             => get_the_title(),
			'url'              => get_permalink(),
			'image'            => $image,
			'mainEntityOfPage' => get_permalink(),
		);

		$description = trim( wp_strip_all_tags( get_the_excerpt() ) );
		if ( '' === $description ) {
			$description = trim( wp_strip_all_tags( get_the_content() ) );
			if ( '' === $description ) {
				$description = get_the_title();
			} else {
				// Keep long free-text content brief for structured data.
				$description = mb_substr( $description, 0, 300 );
			}
		}
		$schema['description'] = $description;

		// Place/address — only claim what is actually stored.
		$leisure_town     = trim( (string) get_post_meta( $post_id, '_leisure_town', true ) );
		$leisure_address  = trim( (string) get_post_meta( $post_id, '_leisure_address', true ) );
		$leisure_counties = get_the_terms( $post_id, 'conexao_county' );
		$county_name      = ( $leisure_counties && ! is_wp_error( $leisure_counties ) && ! empty( $leisure_counties ) ) ? $leisure_counties[0]->name : '';
		$locality         = implode( ', ', array_filter( array( $leisure_town, $county_name ) ) );

		if ( $leisure_address || $locality ) {
			$address = array(
				'@type'          => 'PostalAddress',
				'addressCountry' => 'IE',
			);
			if ( $leisure_address ) {
				$address['streetAddress'] = $leisure_address;
			}
			if ( $locality ) {
				$address['addressLocality'] = $locality;
			}
			$schema['address'] = $address;
		}

		echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>' . "\n";
	}
}

function conexao_seo_breadcrumb_data() {
	$crumbs = array();
	$home   = array( 'name' => __( 'Início', 'conexao-br-irlanda' ), 'url' => home_url( '/' ) );
	$crumbs[] = $home;

	if ( is_singular() ) {
		$post_type = get_post_type();
		$type_obj  = get_post_type_object( $post_type );

		// The archive crumb. Unless the CPT archive is disabled (the `job`
		// archive was replaced by the /empregos/ landing page), in which case
		// we still point to the Empregos page so job singles keep their
		// breadcrumb trail.
		$archive_url = '';
		if ( 'post' === $post_type ) {
			// Blog posts live under the /blog/ posts page (see
			// conexao-content's create-pages.php, which sets `page_for_posts`).
			// Link the crumb to that page instead of
			// get_post_type_archive_link( 'post' ), which falls back to the
			// front page when no posts page is set and would duplicate the
			// Início crumb.
			$blog_page_id = ( 'page' === get_option( 'show_on_front' ) ) ? (int) get_option( 'page_for_posts' ) : 0;
			if ( $blog_page_id ) {
				$archive_url = get_permalink( $blog_page_id );
			}
		} elseif ( $type_obj && $type_obj->has_archive ) {
			$archive_url = get_post_type_archive_link( $post_type );
		} elseif ( 'job' === $post_type ) {
			$archive_url = function_exists( 'conexao_empregos_page_url' ) ? conexao_empregos_page_url() : '';
		}

		if ( $archive_url ) {
			$crumbs[] = array(
				'name' => conexao_cpt_label( $post_type ),
				'url'  => $archive_url,
			);
		}

		// Category crumb. Blog posts use the native `category` taxonomy —
		// its /category/{slug}/ archives remain intact for direct access,
		// while the Blog archive itself filters via /blog/?categoria=slug.
		// All other CPTs use the shared `conexao_category` taxonomy.
		// When a post has multiple terms, get_the_terms() returns them
		// ordered by name, so $terms[0] is the deterministic primary term
		// (same selection rule used by the other CPTs below).
		$category_taxonomy = ( 'post' === $post_type ) ? 'category' : 'conexao_category';
		$terms = get_the_terms( get_the_ID(), $category_taxonomy );
		if ( $terms && ! is_wp_error( $terms ) ) {
			// Guides: the category crumb must link to the EXISTING filtered
			// Guias archive (/guias/?categoria=<slug> — the same tax_query the
			// archive filter bar applies) instead of the taxonomy term archive.
			// get_term_link() would produce /categories/{slug}/, which this
			// site's .htaccess 301-redirects to the standalone /{slug}/ static
			// page (e.g. /moradia/) — not the filtered Guias archive. The URL
			// is built through the shared conexao_get_guide_category_url()
			// helper (add_query_arg + canonical term slug) so the breadcrumb
			// and the archive filter bar can never drift apart.
			if ( 'guide' === $post_type && function_exists( 'conexao_get_guide_category_url' ) ) {
				$term_link = conexao_get_guide_category_url( $terms[0]->slug, $terms[0]->slug );
			} else {
				$term_link = get_term_link( $terms[0] );
			}
			if ( ! is_wp_error( $term_link ) ) {
				$crumbs[] = array(
					'name' => $terms[0]->name,
					'url'  => $term_link,
				);
			}
		}

		// County crumb for events/apoiadores.
		$counties = get_the_terms( get_the_ID(), 'conexao_county' );
		if ( $counties && ! is_wp_error( $counties ) ) {
			$crumbs[] = array(
				'name' => $counties[0]->name,
				'url'  => get_term_link( $counties[0] ),
			);
		}

		$crumbs[] = array( 'name' => get_the_title(), 'url' => get_permalink() );
	} elseif ( is_home() ) {
		// Blog archive (/blog/, including its ?categoria= filtered views):
		// Início → Blog. No category or post crumb — filtered archive views
		// must not look like individual post breadcrumbs.
		$blog_url = '';
		$blog_page_id = (int) get_option( 'page_for_posts' );
		if ( $blog_page_id ) {
			$blog_url = get_permalink( $blog_page_id );
		}
		$crumbs[] = array( 'name' => 'Blog', 'url' => $blog_url );
	} elseif ( is_post_type_archive() ) {
		$crumbs[] = array(
			'name' => conexao_archive_title(),
			'url'  => get_post_type_archive_link( get_query_var( 'post_type' ) ),
		);
	} elseif ( is_tax() || is_category() || is_tag() ) {
		$term = get_queried_object();
		$crumbs[] = array( 'name' => $term->name, 'url' => get_term_link( $term ) );
	} elseif ( is_page() ) {
		$crumbs[] = array( 'name' => get_the_title(), 'url' => get_permalink() );
	} elseif ( is_search() ) {
		$crumbs[] = array( 'name' => __( 'Busca', 'conexao-br-irlanda' ), 'url' => '' );
	} elseif ( is_404() ) {
		$crumbs[] = array( 'name' => 'Página não encontrada', 'url' => '' );
	}

	return $crumbs;
}

/**
 * Get the Portuguese label for a CPT.
 */
function conexao_cpt_label( $post_type ) {
	$labels = array(
		'guide'    => __( 'Guias Práticos', 'conexao-br-irlanda' ),
		'event'    => __( 'Eventos', 'conexao-br-irlanda' ),
		'job'      => __( 'Empregos', 'conexao-br-irlanda' ),
		'sponsor'  => __( 'Apoiadores', 'conexao-br-irlanda' ),
		'leisure'  => __( 'Lazer e Turismo', 'conexao-br-irlanda' ),
		'post'     => __( 'Blog', 'conexao-br-irlanda' ),
	);
	return isset( $labels[ $post_type ] ) ? $labels[ $post_type ] : get_post_type_object( $post_type )->labels->name;
}

/**
 * Render visible breadcrumbs in templates.
 *
 * Uses semantic <nav aria-label="Breadcrumb"> with an ordered list.
 */
function conexao_seo_breadcrumbs() {
	if ( is_front_page() ) {
		return;
	}

	$crumbs = conexao_seo_breadcrumb_data();
	if ( empty( $crumbs ) ) {
		return;
	}

	echo '<nav class="conexao-breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'conexao-br-irlanda' ) . '">';
	echo '<ol class="conexao-breadcrumb-list">';
	$count = count( $crumbs );
	foreach ( $crumbs as $i => $crumb ) {
		$is_last = ( $i === $count - 1 );
		echo '<li class="conexao-breadcrumb-item">';
		if ( $is_last || empty( $crumb['url'] ) ) {
			echo '<span class="conexao-breadcrumb-current" aria-current="page">' . esc_html( $crumb['name'] ) . '</span>';
		} else {
			echo '<a href="' . esc_url( $crumb['url'] ) . '" class="conexao-breadcrumb-link">' . esc_html( $crumb['name'] ) . '</a>';
			echo '<span class="conexao-breadcrumb-sep" aria-hidden="true">&rsaquo;</span>';
		}
		echo '</li>';
	}
	echo '</ol>';
	echo '</nav>';
}
