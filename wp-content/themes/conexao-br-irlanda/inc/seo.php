<?php
/**
 * Conexão BR Irlanda - SEO Foundation Module
 *
 * Provides migration-safe SEO: titles, meta descriptions, Open Graph,
 * canonical URLs, XML sitemap, robots.txt, structured data, breadcrumbs,
 * and index/noindex rules. Built into the theme to avoid plugin conflicts.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ---------------------------------------------------------------------------
 * 1. SEO TITLE TEMPLATES
 * ---------------------------------------------------------------------------
 * Dynamic titles for every content type. Uses the WordPress title filter so
 * the browser tab, search results, and social previews all stay consistent.
 */
function conexao_seo_title( $title ) {
	if ( is_feed() ) {
		return $title;
	}

	$site_name = get_bloginfo( 'name' );

	if ( is_front_page() || is_home() ) {
		return $site_name . ' | ' . get_bloginfo( 'description' );
	}

	if ( is_singular( 'guide' ) ) {
		return single_post_title( '', false ) . ' | Guia Prático | ' . $site_name;
	}

	if ( is_singular( 'event' ) ) {
		return single_post_title( '', false ) . ' | Eventos na Irlanda | ' . $site_name;
	}

	if ( is_singular( 'job' ) ) {
		$job_location = get_post_meta( get_the_ID(), '_job_location', true );
		$location     = $job_location ? ' em ' . $job_location : '';
		return single_post_title( '', false ) . $location . ' | Empregos | ' . $site_name;
	}

	if ( is_singular( 'sponsor' ) ) {
		$terms = get_the_terms( get_the_ID(), 'conexao_category' );
		$cat   = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : 'Apoiadores';
		return single_post_title( '', false ) . ' | ' . $cat . ' | ' . $site_name;
	}

	if ( is_singular( 'leisure' ) ) {
		$terms = get_the_terms( get_the_ID(), 'conexao_category' );
		$cat   = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : 'Lazer';
		return single_post_title( '', false ) . ' | ' . $cat . ' | ' . $site_name;
	}

	if ( is_singular( 'post' ) ) {
		return single_post_title( '', false ) . ' | ' . $site_name;
	}

	if ( is_post_type_archive( 'guide' ) ) {
		return 'Guias Práticos | ' . $site_name;
	}

	if ( is_post_type_archive( 'event' ) ) {
		return 'Eventos na Irlanda | ' . $site_name;
	}

	if ( is_post_type_archive( 'job' ) ) {
		return 'Empregos para Brasileiros na Irlanda | ' . $site_name;
	}

	if ( is_post_type_archive( 'sponsor' ) ) {
		return 'Apoiadores na Irlanda | ' . $site_name;
	}

	if ( is_post_type_archive( 'leisure' ) ) {
		return 'Lazer & Turismo na Irlanda | ' . $site_name;
	}

	if ( is_tax( 'conexao_category' ) ) {
		return single_term_title( '', false ) . ' | ' . $site_name;
	}

	if ( is_tax( 'conexao_county' ) ) {
		return single_term_title( '', false ) . ' | Irlanda | ' . $site_name;
	}

	if ( is_search() ) {
		return 'Busca: ' . get_search_query() . ' | ' . $site_name;
	}

	if ( is_404() ) {
		return 'Página não encontrada | ' . $site_name;
	}

	return $title;
}
add_filter( 'pre_get_document_title', 'conexao_seo_title', 20 );

/**
 * ---------------------------------------------------------------------------
 * 2. META DESCRIPTION TEMPLATES
 * ---------------------------------------------------------------------------
 * Uses a manually entered SEO description when available, otherwise falls
 * back to a dynamic template. Kept around 150-160 characters.
 */
function conexao_seo_meta_description() {
	$description = '';

	// Manual override (editor-entered) takes priority.
	if ( is_singular() ) {
		$manual = get_post_meta( get_the_ID(), 'conexao_meta_description', true );
		if ( $manual ) {
			$description = $manual;
		} elseif ( has_excerpt() ) {
			$description = get_the_excerpt();
		}
	}

	// Dynamic templates per content type.
	if ( ! $description ) {
		if ( is_front_page() || is_home() ) {
			$description = get_bloginfo( 'description' );
		} elseif ( is_singular( 'guide' ) ) {
			$description = 'Guia prático: ' . get_the_title() . '. Passo a passo completo para brasileiros na Irlanda.';
		} elseif ( is_singular( 'event' ) ) {
			$description = 'Evento: ' . get_the_title() . '. Participe e fortaleça a comunidade brasileira na Irlanda.';
		} elseif ( is_singular( 'job' ) ) {
			$description = 'Vaga de emprego: ' . get_the_title() . '. Oportunidade para brasileiros na Irlanda.';
		} elseif ( is_singular( 'sponsor' ) ) {
			$description = 'Apoiador: ' . get_the_title() . '. Conheça quem apoia e fortalece a comunidade brasileira na Irlanda.';
		} elseif ( is_singular( 'leisure' ) ) {
			$description = 'Lazer e turismo: ' . get_the_title() . '. Descubra este local incrível para visitar na Irlanda.';
		} elseif ( is_post_type_archive( 'guide' ) ) {
			$description = 'Guias práticos completos para brasileiros na Irlanda. PPS Number, Medical Card, moradia, emprego e mais.';
		} elseif ( is_post_type_archive( 'event' ) ) {
			$description = 'Eventos, encontros e atividades para a comunidade brasileira na Irlanda. Agenda cultural e networking.';
		} elseif ( is_post_type_archive( 'job' ) ) {
			$description = 'Vagas de emprego para brasileiros na Irlanda. Oportunidades em saúde, TI, construção e mais.';
		} elseif ( is_post_type_archive( 'sponsor' ) ) {
			$description = 'Conheça as organizações e empresas que apoiam a comunidade brasileira na Irlanda.';
		} elseif ( is_post_type_archive( 'leisure' ) ) {
			$description = 'Descubra lugares para visitar, natureza, cultura, turismo e coisas para fazer na Irlanda. Guia de lazer por condado.';
		} elseif ( is_tax( 'conexao_category' ) ) {
			$description = 'Conteúdo sobre ' . single_term_title( '', false ) . ' para brasileiros na Irlanda. Guias e recursos úteis.';
		} elseif ( is_tax( 'conexao_county' ) ) {
			$description = 'Guia sobre ' . single_term_title( '', false ) . ' na Irlanda. Eventos, apoiadores, guias e empregos para brasileiros.';
		} elseif ( is_404() ) {
			$description = 'Página não encontrada. Explore guias e eventos para brasileiros na Irlanda.';
		}
	}

	// Trim to ~160 chars.
	if ( $description ) {
		$description = wp_strip_all_tags( $description );
		$description = mb_substr( $description, 0, 160 );
		if ( mb_strlen( $description ) >= 160 ) {
			$description = mb_substr( $description, 0, 157 ) . '...';
		}
		echo '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
	}
}
add_action( 'wp_head', 'conexao_seo_meta_description', 5 );

/**
 * ---------------------------------------------------------------------------
 * 3. CANONICAL URLS
 * ---------------------------------------------------------------------------
 * Every indexable page gets a self-referencing canonical. Pagination and
 * query parameters are normalized to avoid duplicate content.
 */
function conexao_seo_canonical() {
	$canonical = '';

	if ( is_singular() ) {
		$canonical = get_permalink();
	} elseif ( is_front_page() || is_home() ) {
		$canonical = home_url( '/' );
	} elseif ( is_post_type_archive() ) {
		$canonical = get_post_type_archive_link( get_query_var( 'post_type' ) );
	} elseif ( is_tax() || is_category() || is_tag() ) {
		$canonical = get_term_link( get_queried_object() );
	} elseif ( is_search() ) {
		$canonical = home_url( '/?s=' . rawurlencode( get_search_query() ) );
	} elseif ( is_404() ) {
		return; // No canonical on 404.
	}

	if ( $canonical ) {
		// Strip pagination from canonical (page/2/ etc. canonicalizes to base).
		$canonical = preg_replace( '#/page/\d+/?#', '/', $canonical );
		echo '<link rel="canonical" href="' . esc_url( $canonical ) . '" />' . "\n";
	}
}
add_action( 'wp_head', 'conexao_seo_canonical', 5 );

/**
 * ---------------------------------------------------------------------------
 * 4. OPEN GRAPH + TWITTER CARDS
 * ---------------------------------------------------------------------------
 * Uses featured image by default, falls back to the Conexão BR logo so social
 * previews are never blank.
 */
function conexao_seo_og_meta() {
	$og_type  = 'website';
	$og_title = get_bloginfo( 'name' );
	$og_url   = home_url( '/' );
	$og_desc  = get_bloginfo( 'description' );
	$og_image = '';

	// Fallback social card image (always present).
	$fallback_image = CONEXAO_THEME_URI . '/assets/images/conexao-social-card.svg';

	if ( is_singular() ) {
		$og_type  = 'article';
		$og_title = get_the_title();
		$og_url   = get_permalink();
		$og_desc  = has_excerpt() ? get_the_excerpt() : wp_trim_words( get_the_content(), 30, '...' );
		$og_image = has_post_thumbnail() ? get_the_post_thumbnail_url( null, 'large' ) : $fallback_image;
	} elseif ( is_post_type_archive() ) {
		$post_type = get_query_var( 'post_type' );
		$og_url    = get_post_type_archive_link( $post_type );
		$og_title  = conexao_archive_title();
		$og_desc   = conexao_archive_description();
		$og_image  = $fallback_image;
	} elseif ( is_tax() || is_category() || is_tag() ) {
		$og_url   = get_term_link( get_queried_object() );
		$og_title = single_term_title( '', false );
		$og_desc  = term_description();
		$og_image = $fallback_image;
	} elseif ( is_front_page() || is_home() ) {
		$og_image = $fallback_image;
	}

	// Never allow a blank social image.
	if ( ! $og_image ) {
		$og_image = $fallback_image;
	}

	$og_desc = wp_strip_all_tags( $og_desc );
	$og_desc = mb_substr( $og_desc, 0, 200 );

	?>
	<meta property="og:type" content="<?php echo esc_attr( $og_type ); ?>" />
	<meta property="og:title" content="<?php echo esc_attr( $og_title ); ?>" />
	<meta property="og:url" content="<?php echo esc_url( $og_url ); ?>" />
	<meta property="og:description" content="<?php echo esc_attr( $og_desc ); ?>" />
	<meta property="og:site_name" content="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
	<meta property="og:locale" content="pt_BR" />
	<meta property="og:image" content="<?php echo esc_url( $og_image ); ?>" />
	<meta property="og:image:alt" content="<?php echo esc_attr( $og_title ); ?>" />
	<meta name="twitter:card" content="summary_large_image" />
	<meta name="twitter:title" content="<?php echo esc_attr( $og_title ); ?>" />
	<meta name="twitter:description" content="<?php echo esc_attr( $og_desc ); ?>" />
	<meta name="twitter:image" content="<?php echo esc_url( $og_image ); ?>" />
	<?php
}
add_action( 'wp_head', 'conexao_seo_og_meta', 5 );

/**
 * ---------------------------------------------------------------------------
 * 5. INDEX / NOINDEX RULES
 * ---------------------------------------------------------------------------
 * Index: homepage, CPTs, useful categories/counties, static pages.
 * Noindex: search results, empty archives, paginated archives, utility pages.
 */
function conexao_seo_noindex() {
	$noindex = false;

	if ( is_search() ) {
		$noindex = true;
	} elseif ( is_archive() && ! is_post_type_archive() && ! is_tax() ) {
		// Empty or non-CPT archives (e.g. date archives) get noindexed.
		$noindex = true;
	} elseif ( is_paged() ) {
		// Paginated archives canonicalize to base; noindex to avoid dupes.
		$noindex = true;
	} elseif ( is_tax( 'conexao_tag' ) ) {
		// Tag archives are thin/duplicative; noindex.
		$noindex = true;
	} elseif ( is_author() ) {
		$noindex = true;
	}

	if ( $noindex ) {
		echo '<meta name="robots" content="noindex, follow" />' . "\n";
	}
}
add_action( 'wp_head', 'conexao_seo_noindex', 3 );

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
}

/**
 * ---------------------------------------------------------------------------
 * 7. BREADCRUMBS
 * ---------------------------------------------------------------------------
 * Returns breadcrumb trail data (name + url) for the current page.
 */
/**
 * Get the Portuguese archive title for the current CPT archive.
 */
function conexao_archive_title() {
	if ( is_post_type_archive( 'guide' ) ) {
		return 'Guias Práticos';
	}
	if ( is_post_type_archive( 'event' ) ) {
		return 'Eventos';
	}
	if ( is_post_type_archive( 'job' ) ) {
		return 'Empregos';
	}
	if ( is_post_type_archive( 'sponsor' ) ) {
		return 'Apoiadores';
	}
	if ( is_post_type_archive( 'leisure' ) ) {
		return 'Lazer';
	}
	if ( is_post_type_archive( 'course_provider' ) ) {
		return 'Cursos';
	}
	if ( is_tax( 'conexao_category' ) || is_tax( 'conexao_county' ) || is_category() || is_tag() ) {
		$term = get_queried_object();
		return $term && isset( $term->name ) ? $term->name : '';
	}

	return wp_strip_all_tags( get_the_archive_title() );
}

/**
 * Get the archive subtitle/description for the current CPT archive.
 */
function conexao_archive_description() {
	if ( is_post_type_archive( 'guide' ) ) {
		return 'Guias passo a passo para facilitar sua vida na Irlanda.';
	}
	if ( is_post_type_archive( 'event' ) ) {
		return 'Eventos, encontros e atividades para a comunidade brasileira na Irlanda.';
	}
	if ( is_post_type_archive( 'job' ) ) {
		return 'Oportunidades de emprego para brasileiros na Irlanda.';
	}
	if ( is_post_type_archive( 'sponsor' ) ) {
		return 'Conheça as organizações e empresas que apoiam a comunidade brasileira na Irlanda.';
	}
	if ( is_post_type_archive( 'leisure' ) ) {
		return 'Descubra lugares para visitar, natureza, cultura, turismo e coisas para fazer na Irlanda.';
	}

	$description = get_the_archive_description();
	return $description ? wp_strip_all_tags( $description ) : '';
}

function conexao_seo_breadcrumb_data() {
	$crumbs = array();
	$home   = array( 'name' => 'Início', 'url' => home_url( '/' ) );
	$crumbs[] = $home;

	if ( is_singular() ) {
		$post_type = get_post_type();
		$type_obj  = get_post_type_object( $post_type );

		if ( $type_obj && $type_obj->has_archive ) {
			$crumbs[] = array(
				'name' => conexao_cpt_label( $post_type ),
				'url'  => get_post_type_archive_link( $post_type ),
			);
		}

		// Category crumb.
		$terms = get_the_terms( get_the_ID(), 'conexao_category' );
		if ( $terms && ! is_wp_error( $terms ) ) {
			$crumbs[] = array(
				'name' => $terms[0]->name,
				'url'  => get_term_link( $terms[0] ),
			);
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
		$crumbs[] = array( 'name' => 'Busca', 'url' => '' );
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
		'guide'    => 'Guias Práticos',
		'event'    => 'Eventos',
		'job'      => 'Empregos',
		'sponsor'  => 'Apoiadores',
		'leisure'  => 'Lazer e Turismo',
		'post'     => 'Blog',
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

/**
 * ---------------------------------------------------------------------------
 * 8. XML SITEMAP
 * ---------------------------------------------------------------------------
 * Custom lightweight sitemap (no plugin needed). Includes pages, all 5 CPTs,
 * and relevant taxonomies. Excludes search, admin, utility, and empty archives.
 * The WordPress core sitemap (/wp-sitemap.xml) is disabled to avoid competing
 * sitemaps.
 */
function conexao_seo_disable_core_sitemap() {
	return false;
}
add_filter( 'wp_sitemaps_enabled', 'conexao_seo_disable_core_sitemap' );

function conexao_seo_sitemap() {
	// Only serve on the exact sitemap path.
	if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
		return;
	}

	$path = wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
	$path = untrailingslashit( $path );

	if ( '/sitemap.xml' !== $path && '/sitemap_index.xml' !== $path ) {
		return;
	}

	// Prevent WordPress from processing this as a normal request.
	status_header( 200 );
	header( 'Content-Type: application/xml; charset=UTF-8' );
	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

	// Homepage.
	conexao_seo_sitemap_url( home_url( '/' ), '1.0', 'daily' );

	// Static pages (exclude utility/redirect pages).
	$excluded_pages = array( 'privacidade', 'termos', 'sobre', 'search', 'cookies' );
	$pages = get_posts( array(
		'post_type'      => 'page',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'no_found_rows'  => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	) );
	foreach ( $pages as $page ) {
		if ( in_array( $page->post_name, $excluded_pages, true ) ) {
			continue;
		}
		conexao_seo_sitemap_url( get_permalink( $page->ID ), '0.8', 'monthly' );
	}

	// CPTs — batched so the sitemap stays lightweight even when a CPT grows
	// to thousands of posts (no posts_per_page => -1 full-table load).
	$cpt_priorities = array(
		'guide'    => '0.9',
		'event'    => '0.8',
		'job'      => '0.7',
		'sponsor'  => '0.7',
		'leisure'  => '0.7',
	);
	$sitemap_batch = 500;
	foreach ( $cpt_priorities as $cpt => $priority ) {
		$page = 1;
		do {
			$items = get_posts( array(
				'post_type'      => $cpt,
				'post_status'    => 'publish',
				'posts_per_page' => $sitemap_batch,
				'paged'          => $page,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			) );
			foreach ( $items as $item_id ) {
				if ( 'leisure' === $cpt && conexao_leisure_external_url( $item_id ) ) {
					continue;
				}
				conexao_seo_sitemap_url( get_permalink( $item_id ), $priority, 'weekly' );
			}
			$page++;
		} while ( count( $items ) === $sitemap_batch );
	}

	// Taxonomies (categories + counties, only non-empty).
	foreach ( array( 'conexao_category', 'conexao_county' ) as $tax ) {
		$terms = get_terms( array(
			'taxonomy'   => $tax,
			'hide_empty' => true,
		) );
		if ( is_wp_error( $terms ) ) {
			continue;
		}
		foreach ( $terms as $term ) {
			conexao_seo_sitemap_url( get_term_link( $term ), '0.6', 'monthly' );
		}
	}

	echo '</urlset>';
	exit;
}
add_action( 'template_redirect', 'conexao_seo_sitemap', 0 );

/**
 * Helper: output a single sitemap <url> entry.
 */
function conexao_seo_sitemap_url( $url, $priority, $freq ) {
	echo "\t<url>\n";
	echo "\t\t<loc>" . esc_url( $url ) . "</loc>\n";
	echo "\t\t<changefreq>" . esc_html( $freq ) . "</changefreq>\n";
	echo "\t\t<priority>" . esc_html( $priority ) . "</priority>\n";
	echo "\t</url>\n";
}

/**
 * ---------------------------------------------------------------------------
 * 9. ROBOTS.TXT
 * ---------------------------------------------------------------------------
 * Serve a custom robots.txt that allows all public content and points to the
 * sitemap. Does not block CSS/JS or Googlebot.
 */
function conexao_seo_robots_txt( $output, $public ) {
	if ( ! $public ) {
		return $output;
	}

	$custom = "User-agent: *\n";
	$custom .= "Allow: /\n";
	$custom .= "Disallow: /wp-admin/\n";
	$custom .= "Disallow: /wp-includes/\n";
	$custom .= "Disallow: /?s=\n";
	$custom .= "Disallow: /search/\n";
	$custom .= "Disallow: /page/\n";
	$custom .= "Disallow: /feed/\n";
	$custom .= "Disallow: /trackback/\n";
	$custom .= "Disallow: /xmlrpc.php\n";
	$custom .= "Disallow: /wp-json/\n";
	$custom .= "\n";
	$custom .= "Sitemap: " . home_url( '/sitemap.xml' ) . "\n";

	return $custom;
}
add_filter( 'robots_txt', 'conexao_seo_robots_txt', 10, 2 );

/**
 * ---------------------------------------------------------------------------
 * 10. MIGRATION-SAFE REDIRECTS
 * ---------------------------------------------------------------------------
 * Additional 301 redirects for legacy/alternate URLs. No redirect chains.
 * All point directly to the final destination.
 */
function conexao_seo_redirects() {
	if ( is_admin() ) {
		return;
	}

	$path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
	$path = untrailingslashit( rawurldecode( $path ) );

	// Direct 301 map: old URL -> final URL (no chains).
	// Portuguese URLs are canonical. English URLs redirect to Portuguese.
	$redirects = array(
		// English guide paths -> Portuguese /guias/ CPT structure.
		'/guides'                      => '/guias/',
		'/guides/pps-number'           => '/guias/pps-number/',
		'/guides/medical-card'         => '/guias/medical-card/',
		'/guides/gp-registration'      => '/guias/gp-registration/',
		'/guides/abrir-conta-bancaria' => '/guias/abrir-conta-bancaria/',
		'/guides/alugar-casa'          => '/guias/alugar-casa/',
		'/guides/carteira-de-motorista' => '/guias/carteira-de-motorista/',
		'/guides/impostos'             => '/guias/impostos/',
		'/guides/cidadania-irlandesa'  => '/guias/cidadania-irlandesa/',
		'/guides/comprar-carro'        => '/guias/comprar-carro/',
		'/guides/child-benefit'        => '/guias/child-benefit/',
		'/guides/social-welfare'       => '/guias/social-welfare/',
		'/guides/abrir-empresa'        => '/guias/abrir-empresa/',
		'/guides/visto-irlanda'        => '/guias/visto-irlanda/',
		'/guides/irp-renewal'          => '/guias/irp-renewal/',
		'/guides/passaporte-irlandes'  => '/guias/passaporte-irlandes/',
		'/guides/mygovid'              => '/guias/mygovid/',
		'/guides/direitos-trabalhistas' => '/guias/direitos-trabalhistas/',
		'/guides/transporte-publico'   => '/guias/transporte-publico/',
		'/guides/susi'                 => '/guias/susi/',
		'/guides/servicos-emergencia'  => '/guias/servicos-emergencia/',
		'/guides/nct'                  => '/guias/nct/',
		'/guides/motor-tax'            => '/guias/motor-tax/',
		'/guides/eircode'              => '/guias/eircode/',
		'/guides/hap'                  => '/guias/hap/',
		'/guides/rtb'                  => '/guias/rtb/',
		'/guides/cao'                  => '/guias/cao/',
		'/guides/direitos-consumidor'  => '/guias/direitos-consumidor/',
		'/guides/protecao-dados'       => '/guias/protecao-dados/',
		'/guides/garda'                => '/guias/garda/',
		'/guides/revenue-myaccount'    => '/guias/revenue-myaccount/',

		// Legacy guide paths -> Portuguese /guias/ CPT structure.
		'/guias-praticos'              => '/guias/',
		'/guias-praticos/pps-number'   => '/guias/pps-number/',
		'/guias-praticos/medical-card' => '/guias/medical-card/',
		'/guias-praticos/gp-registration' => '/guias/gp-registration/',
		'/guias-praticos/abrir-conta-bancaria' => '/guias/abrir-conta-bancaria/',
		'/guias-praticos/alugar-casa'  => '/guias/alugar-casa/',
		'/guias-praticos/carteira-de-motorista' => '/guias/carteira-de-motorista/',
		'/guias-praticos/impostos'     => '/guias/impostos/',
		'/guias-praticos/cidadania-irlandesa' => '/guias/cidadania-irlandesa/',
		'/guias-praticos/comprar-carro' => '/guias/comprar-carro/',
		'/guias-praticos/child-benefit' => '/guias/child-benefit/',
		'/guias-praticos/social-welfare' => '/guias/social-welfare/',
		'/guias-praticos/abrir-empresa' => '/guias/abrir-empresa/',
		'/guias-praticos/visto-irlanda' => '/guias/visto-irlanda/',
		'/guias-praticos/irp-renewal'  => '/guias/irp-renewal/',
		'/guias-praticos/passaporte-irlandes' => '/guias/passaporte-irlandes/',
		'/guias-praticos/mygovid' => '/guias/mygovid/',
		'/guias-praticos/direitos-trabalhistas' => '/guias/direitos-trabalhistas/',
		'/guias-praticos/transporte-publico' => '/guias/transporte-publico/',
		'/guias-praticos/susi' => '/guias/susi/',
		'/guias-praticos/servicos-emergencia' => '/guias/servicos-emergencia/',
		'/guias-praticos/nct' => '/guias/nct/',
		'/guias-praticos/motor-tax' => '/guias/motor-tax/',
		'/guias-praticos/eircode' => '/guias/eircode/',
		'/guias-praticos/hap' => '/guias/hap/',
		'/guias-praticos/rtb' => '/guias/rtb/',
		'/guias-praticos/cao' => '/guias/cao/',
		'/guias-praticos/direitos-consumidor' => '/guias/direitos-consumidor/',
		'/guias-praticos/protecao-dados' => '/guias/protecao-dados/',
		'/guias-praticos/garda' => '/guias/garda/',
		'/guias-praticos/revenue-myaccount' => '/guias/revenue-myaccount/',

		// English events paths -> Portuguese.
		'/events'                      => '/eventos/',
		'/turismo-e-lazer'             => '/eventos/',

		// English jobs paths -> Portuguese.
		'/jobs'                        => '/empregos/',

		// English courses paths -> Portuguese.
		'/courses'                     => '/cursos/',

		// English sponsors paths -> Portuguese.
		'/sponsors'                    => '/apoiadores/',

		// English static pages -> Portuguese.
		'/ireland'                     => '/irlanda/',
		'/about-us'                    => '/sobre-nos/',
		'/about'                       => '/sobre-nos/',
		'/contact'                     => '/contato/',

		// Legacy business paths -> Apoiadores.
		'/empresas'                    => '/apoiadores/',
		'/businesses'                  => '/apoiadores/',

		// Legacy category paths -> static category landing pages.
		'/categories/moradia'          => '/moradia/',
		'/categories/saude'            => '/saude/',
		'/categories/familia'          => '/familia/',
		'/categories/transporte'       => '/transporte/',
		'/categories/financas'         => '/financas/',
		'/categories/beneficios'       => '/beneficios/',
		'/categories/educacao'         => '/educacao/',
		'/categories/documentos'       => '/documentos/',
		'/categories/onde-comer'       => '/onde-comer/',
		'/categories/empregos'         => '/empregos/',

		// Legacy county paths -> static county landing pages.
		'/counties/dublin'             => '/dublin/',
		'/counties/laois'              => '/laois/',
		'/counties/cork'               => '/cork/',
		'/counties/galway'             => '/galway/',
		'/counties/limerick'           => '/limerick/',
		'/counties/kildare'            => '/kildare/',
		'/counties/meath'              => '/meath/',
		'/counties/wicklow'            => '/wicklow/',
		'/counties/waterford'          => '/waterford/',

		// Legacy utility pages.
		'/privacidade'                 => '/politica-de-privacidade/',
		'/termos'                      => '/termos-de-uso/',
		'/sobre'                       => '/sobre-nos/',

		// Legacy Wix migration paths (Spanish/legacy) -> Portuguese structure.
		'/capacitação'                 => '/cursos/',
		'/capacitacao'                 => '/cursos/',
		'/s-projects-basic'            => '/guias/',
	);

	if ( isset( $redirects[ $path ] ) ) {
		wp_safe_redirect( home_url( $redirects[ $path ] ), 301 );
		exit;
	}

	// Pattern: /guides/{slug} -> /guias/{slug}/ (any remaining).
	if ( preg_match( '#^/guides/([^/]+)$#', $path, $m ) ) {
		wp_safe_redirect( home_url( '/guias/' . $m[1] . '/' ), 301 );
		exit;
	}

	// Pattern: /guias-praticos/{slug} -> /guias/{slug}/ (any remaining).
	if ( preg_match( '#^/guias-praticos/([^/]+)$#', $path, $m ) ) {
		wp_safe_redirect( home_url( '/guias/' . $m[1] . '/' ), 301 );
		exit;
	}

	// Pattern: /events/{slug} -> /eventos/{slug}/ (any remaining).
	if ( preg_match( '#^/events/([^/]+)$#', $path, $m ) ) {
		wp_safe_redirect( home_url( '/eventos/' . $m[1] . '/' ), 301 );
		exit;
	}

	// Pattern: /jobs/{slug} -> /empregos/{slug}/ (any remaining).
	if ( preg_match( '#^/jobs/([^/]+)$#', $path, $m ) ) {
		wp_safe_redirect( home_url( '/empregos/' . $m[1] . '/' ), 301 );
		exit;
	}

	// Pattern: /courses/{slug} -> /cursos/{slug}/ (any remaining).
	if ( preg_match( '#^/courses/([^/]+)$#', $path, $m ) ) {
		wp_safe_redirect( home_url( '/cursos/' . $m[1] . '/' ), 301 );
		exit;
	}

	// Pattern: /sponsors/{slug} -> /apoiadores/{slug}/ (any remaining).
	if ( preg_match( '#^/sponsors/([^/]+)$#', $path, $m ) ) {
		wp_safe_redirect( home_url( '/apoiadores/' . $m[1] . '/' ), 301 );
		exit;
	}

	// Pattern: /categories/{slug} -> /{slug}/ (any remaining).
	if ( preg_match( '#^/categories/([^/]+)$#', $path, $m ) ) {
		wp_safe_redirect( home_url( '/' . $m[1] . '/' ), 301 );
		exit;
	}

	// Pattern: /counties/{slug} -> /{slug}/ (any remaining).
	if ( preg_match( '#^/counties/([^/]+)$#', $path, $m ) ) {
		wp_safe_redirect( home_url( '/' . $m[1] . '/' ), 301 );
		exit;
	}

	// Pattern: /post/{slug} -> /blog/{slug}/ (legacy Wix blog posts).
	if ( preg_match( '#^/post/([^/]+)$#', $path, $m ) ) {
		wp_safe_redirect( home_url( '/blog/' . $m[1] . '/' ), 301 );
		exit;
	}

	// Pattern: /blog/categories/{slug} -> /category/{slug}/ (legacy Wix blog categories).
	if ( preg_match( '#^/blog/categories/([^/]+)$#', $path, $m ) ) {
		wp_safe_redirect( home_url( '/category/' . $m[1] . '/' ), 301 );
		exit;
	}
}
add_action( 'template_redirect', 'conexao_seo_redirects', 5 );

/**
 * Resolve the external destination URL for a leisure location, if any.
 *
 * Priority: Offical Website URL, then Discover Ireland URL. Returns '' when
 * the location should use its internal /lazer/{slug}/ page.
 *
 * @param int $post_id Leisure post ID.
 * @return string External URL, or '' when none configured.
 */
function conexao_leisure_external_url( $post_id = 0 ) {
	$post_id = $post_id ? (int) $post_id : get_the_ID();

	if ( ! $post_id || 'leisure' !== get_post_type( $post_id ) ) {
		return '';
	}

	$official  = get_post_meta( $post_id, '_leisure_official_website', true );
	$discover  = get_post_meta( $post_id, '_leisure_discover_ireland', true );

	// Pick the first valid (non-empty) external destination by priority.
	$candidates = array_filter( array( $official, $discover ) );
	foreach ( $candidates as $candidate ) {
		$candidate = trim( (string) $candidate );
		// Defence in depth: only ever redirect to an absolute http(s) URL.
		if ( $candidate && preg_match( '#^https?://#i', $candidate ) && esc_url_raw( $candidate ) === $candidate ) {
			return $candidate;
		}
	}

	return '';
}

/**
 * Redirect a single leisure post to its configured external destination.
 *
 * Hooked on template_redirect at priority 6 (after the migration-safe 301
 * redirects at priority 5, before normal template rendering). Only applies to
 * singular leisure queries; uses a 302 so the destination can be changed later.
 */
function conexao_leisure_redirect() {
	if ( is_admin() || ! is_singular( 'leisure' ) ) {
		return;
	}

	$external = conexao_leisure_external_url( get_the_ID() );
	if ( ! $external ) {
		return; // No external destination -> render the normal internal page.
	}

	wp_redirect( $external, 302 );
	exit;
}
add_action( 'template_redirect', 'conexao_leisure_redirect', 6 );

/**
 * ---------------------------------------------------------------------------
 * 11. IMAGE SEO
 * ---------------------------------------------------------------------------
 * Ensure featured images always have descriptive alt text. Falls back to the
 * post title when the editor left alt blank.
 */
function conexao_seo_image_alt( $html, $post_id ) {
	if ( ! $html ) {
		return $html;
	}

	// Only add alt if it's missing or empty.
	if ( preg_match( '/alt=["\']\s*["\']/', $html ) || ! preg_match( '/alt=/', $html ) ) {
		$title = get_the_title( $post_id );
		$html  = preg_replace( '/alt=["\']\s*["\']/', 'alt="' . esc_attr( $title ) . '"', $html, 1 );
		if ( ! preg_match( '/alt=/', $html ) ) {
			$html = preg_replace( '/(<img[^>]+?)(\/?>)/', '$1 alt="' . esc_attr( $title ) . '"$2', $html, 1 );
		}
	}

	return $html;
}
add_filter( 'post_thumbnail_html', 'conexao_seo_image_alt', 10, 2 );

/**
 * ---------------------------------------------------------------------------
 * 12. INTERNAL LINKING HELPERS
 * ---------------------------------------------------------------------------
 * Provide reusable helpers for templates to link related content naturally.
 */

/**
 * Get related guides for a given post (by shared category).
 */
function conexao_seo_related_guides( $post_id = 0, $limit = 3 ) {
	$post_id = $post_id ? $post_id : get_the_ID();
	$terms   = get_the_terms( $post_id, 'conexao_category' );
	if ( empty( $terms ) || is_wp_error( $terms ) ) {
		return array();
	}

	$query = new WP_Query( array(
		'post_type'      => 'guide',
		'posts_per_page' => $limit,
		'post__not_in'   => array( $post_id ),
		'tax_query'      => array(
			array(
				'taxonomy' => 'conexao_category',
				'field'    => 'term_id',
				'terms'    => wp_list_pluck( $terms, 'term_id' ),
			),
		),
		'no_found_rows'  => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	) );

	$guides = array();
	if ( $query->have_posts() ) {
		while ( $query->have_posts() ) {
			$query->the_post();
			$guides[] = array(
				'title' => get_the_title(),
				'url'   => get_permalink(),
			);
		}
		wp_reset_postdata();
	}

	return $guides;
}

/**
 * Get related events for a given post (by shared county).
 */
function conexao_seo_related_events( $post_id = 0, $limit = 3 ) {
	$post_id = $post_id ? $post_id : get_the_ID();
	$counties = get_the_terms( $post_id, 'conexao_county' );
	if ( empty( $counties ) || is_wp_error( $counties ) ) {
		return array();
	}

	$query = new WP_Query( array(
		'post_type'      => 'event',
		'posts_per_page' => $limit,
		'post__not_in'   => array( $post_id ),
		'tax_query'      => array(
			array(
				'taxonomy' => 'conexao_county',
				'field'    => 'term_id',
				'terms'    => wp_list_pluck( $counties, 'term_id' ),
			),
		),
		'no_found_rows'  => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	) );

	$events = array();
	if ( $query->have_posts() ) {
		while ( $query->have_posts() ) {
			$query->the_post();
			$events[] = array(
				'title' => get_the_title(),
				'url'   => get_permalink(),
			);
		}
		wp_reset_postdata();
	}

	return $events;
}

/**
 * Get related apoiaiadores for a given post (by shared category or county).
 */
function conexao_seo_related_sponsors( $post_id = 0, $limit = 3 ) {
	$post_id = $post_id ? $post_id : get_the_ID();
	$terms   = get_the_terms( $post_id, 'conexao_category' );
	$counties = get_the_terms( $post_id, 'conexao_county' );

	$tax_query = array( 'relation' => 'OR' );
	if ( $terms && ! is_wp_error( $terms ) ) {
		$tax_query[] = array(
			'taxonomy' => 'conexao_category',
			'field'    => 'term_id',
			'terms'    => wp_list_pluck( $terms, 'term_id' ),
		);
	}
	if ( $counties && ! is_wp_error( $counties ) ) {
		$tax_query[] = array(
			'taxonomy' => 'conexao_county',
			'field'    => 'term_id',
			'terms'    => wp_list_pluck( $counties, 'term_id' ),
		);
	}

	if ( empty( $tax_query ) ) {
		return array();
	}

	$query = new WP_Query( array(
		'post_type'      => 'sponsor',
		'posts_per_page' => $limit,
		'post__not_in'   => array( $post_id ),
		'tax_query'      => $tax_query,
		'no_found_rows'  => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	) );

	$sponsors = array();
	if ( $query->have_posts() ) {
		while ( $query->have_posts() ) {
			$query->the_post();
			$sponsors[] = array(
				'title' => get_the_title(),
				'url'   => get_permalink(),
			);
		}
		wp_reset_postdata();
	}

	return $sponsors;
}
