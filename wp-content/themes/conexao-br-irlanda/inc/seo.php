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

	if ( is_singular( 'news' ) ) {
		return single_post_title( '', false ) . ' | ' . $site_name;
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

	if ( is_singular( 'business' ) ) {
		$terms = get_the_terms( get_the_ID(), 'conexao_category' );
		$cat   = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : 'Empresas';
		return single_post_title( '', false ) . ' | ' . $cat . ' | ' . $site_name;
	}

	if ( is_singular( 'post' ) ) {
		return single_post_title( '', false ) . ' | ' . $site_name;
	}

	if ( is_post_type_archive( 'news' ) ) {
		return 'Notícias | ' . $site_name;
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

	if ( is_post_type_archive( 'business' ) ) {
		return 'Empresas Brasileiras na Irlanda | ' . $site_name;
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
		} elseif ( is_singular( 'news' ) ) {
			$description = 'Notícia: ' . get_the_title() . '. Informações atualizadas para a comunidade brasileira na Irlanda.';
		} elseif ( is_singular( 'guide' ) ) {
			$description = 'Guia prático: ' . get_the_title() . '. Passo a passo completo para brasileiros na Irlanda.';
		} elseif ( is_singular( 'event' ) ) {
			$description = 'Evento: ' . get_the_title() . '. Participe e fortaleça a comunidade brasileira na Irlanda.';
		} elseif ( is_singular( 'job' ) ) {
			$description = 'Vaga de emprego: ' . get_the_title() . '. Oportunidade para brasileiros na Irlanda.';
		} elseif ( is_singular( 'business' ) ) {
			$description = 'Empresa: ' . get_the_title() . '. Conheça serviços e negócios para a comunidade brasileira na Irlanda.';
		} elseif ( is_post_type_archive( 'news' ) ) {
			$description = 'Notícias atualizadas para brasileiros na Irlanda. Imigração, economia, cultura e informações relevantes.';
		} elseif ( is_post_type_archive( 'guide' ) ) {
			$description = 'Guias práticos completos para brasileiros na Irlanda. PPS Number, Medical Card, moradia, emprego e mais.';
		} elseif ( is_post_type_archive( 'event' ) ) {
			$description = 'Eventos, encontros e atividades para a comunidade brasileira na Irlanda. Agenda cultural e networking.';
		} elseif ( is_post_type_archive( 'job' ) ) {
			$description = 'Vagas de emprego para brasileiros na Irlanda. Oportunidades em saúde, TI, construção e mais.';
		} elseif ( is_post_type_archive( 'business' ) ) {
			$description = 'Diretório de empresas e profissionais brasileiros na Irlanda. Restaurantes, serviços e mais.';
		} elseif ( is_tax( 'conexao_category' ) ) {
			$description = 'Conteúdo sobre ' . single_term_title( '', false ) . ' para brasileiros na Irlanda. Guias, notícias e recursos úteis.';
		} elseif ( is_tax( 'conexao_county' ) ) {
			$description = 'Guia sobre ' . single_term_title( '', false ) . ' na Irlanda. Eventos, empresas, guias e empregos para brasileiros.';
		} elseif ( is_404() ) {
			$description = 'Página não encontrada. Explore guias, notícias e eventos para brasileiros na Irlanda.';
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

	// --- News / Article ---
	if ( in_array( $type, array( 'news', 'post' ), true ) ) {
		$schema = array(
			'@context'      => 'https://schema.org',
			'@type'         => 'news' === $type ? 'NewsArticle' : 'Article',
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

	// --- LocalBusiness ---
	if ( 'business' === $type ) {
		$biz_phone    = get_post_meta( $post_id, '_business_phone', true );
		$biz_website  = get_post_meta( $post_id, '_business_website', true );
		$biz_location = get_post_meta( $post_id, '_business_location', true );
		$counties     = get_the_terms( $post_id, 'conexao_county' );

		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => 'LocalBusiness',
			'name'     => get_the_title(),
			'url'      => get_permalink(),
			'image'    => $image,
			'description' => wp_strip_all_tags( get_the_excerpt() ),
		);

		if ( $biz_phone ) {
			$schema['telephone'] = $biz_phone;
		}

		if ( $biz_website ) {
			$schema['sameAs'] = $biz_website;
		}

		if ( $biz_location || ( $counties && ! is_wp_error( $counties ) ) ) {
			$loc = $biz_location ? $biz_location : $counties[0]->name;
			$schema['address'] = array(
				'@type'           => 'PostalAddress',
				'addressLocality' => $loc,
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
	if ( is_post_type_archive( 'news' ) ) {
		return 'Notícias';
	}
	if ( is_post_type_archive( 'guide' ) ) {
		return 'Guias Práticos';
	}
	if ( is_post_type_archive( 'event' ) ) {
		return 'Eventos';
	}
	if ( is_post_type_archive( 'job' ) ) {
		return 'Empregos';
	}
	if ( is_post_type_archive( 'business' ) ) {
		return 'Empresas';
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
	if ( is_post_type_archive( 'news' ) ) {
		return 'Informações e novidades da comunidade brasileira na Irlanda.';
	}
	if ( is_post_type_archive( 'guide' ) ) {
		return 'Guias passo a passo para facilitar sua vida na Irlanda.';
	}
	if ( is_post_type_archive( 'event' ) ) {
		return 'Eventos, encontros e atividades para a comunidade brasileira na Irlanda.';
	}
	if ( is_post_type_archive( 'job' ) ) {
		return 'Oportunidades de emprego para brasileiros na Irlanda.';
	}
	if ( is_post_type_archive( 'business' ) ) {
		return 'Diretório de empresas e serviços para a comunidade brasileira na Irlanda.';
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

		// County crumb for events/businesses.
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
		'news'     => 'Notícias',
		'guide'    => 'Guias Práticos',
		'event'    => 'Eventos',
		'job'      => 'Empregos',
		'business' => 'Empresas',
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

	// CPTs.
	$cpt_priorities = array(
		'news'     => '0.9',
		'guide'    => '0.9',
		'event'    => '0.8',
		'job'      => '0.7',
		'business' => '0.7',
	);
	foreach ( $cpt_priorities as $cpt => $priority ) {
		$items = get_posts( array(
			'post_type'      => $cpt,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );
		foreach ( $items as $item ) {
			conexao_seo_sitemap_url( get_permalink( $item->ID ), $priority, 'weekly' );
		}
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
	$redirects = array(
		// Legacy guide paths -> new /guides/ CPT structure.
		'/guias-praticos'              => '/guides/',
		'/guias-praticos/pps-number'   => '/guides/pps-number/',
		'/guias-praticos/medical-card' => '/guides/medical-card/',
		'/guias-praticos/gp-registration' => '/guides/gp-registration/',
		'/guias-praticos/abrir-conta-bancaria' => '/guides/abrir-conta-bancaria/',
		'/guias-praticos/alugar-casa'  => '/guides/alugar-casa/',
		'/guias-praticos/carteira-de-motorista' => '/guides/carteira-de-motorista/',
		'/guias-praticos/impostos'     => '/guides/impostos/',
		'/guias-praticos/cidadania-irlandesa' => '/guides/cidadania-irlandesa/',
		'/guias-praticos/comprar-carro' => '/guides/comprar-carro/',
		'/guias-praticos/child-benefit' => '/guides/child-benefit/',
		'/guias-praticos/social-welfare' => '/guides/social-welfare/',
		'/guias-praticos/abrir-empresa' => '/guides/abrir-empresa/',
		'/guias-praticos/visto-irlanda' => '/guides/visto-irlanda/',
		'/guias-praticos/irp-renewal'  => '/guides/irp-renewal/',
		'/guias-praticos/passaporte-irlandes' => '/guides/passaporte-irlandes/',

		// Legacy news paths.
		'/noticias'                    => '/news/',
		'/fique-por-dentro'            => '/news/',

		// Legacy events paths.
		'/eventos'                     => '/events/',
		'/turismo-e-lazer'             => '/events/',

		// Legacy jobs paths.
		'/empregos'                    => '/jobs/',

		// Legacy business paths.
		'/empresas'                    => '/businesses/',

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
	);

	if ( isset( $redirects[ $path ] ) ) {
		wp_safe_redirect( home_url( $redirects[ $path ] ), 301 );
		exit;
	}

	// Pattern: /guias-praticos/{slug} -> /guides/{slug}/ (any remaining).
	if ( preg_match( '#^/guias-praticos/([^/]+)$#', $path, $m ) ) {
		wp_safe_redirect( home_url( '/guides/' . $m[1] . '/' ), 301 );
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
}
add_action( 'template_redirect', 'conexao_seo_redirects', 5 );

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
 * Get related news for a given post (by shared category).
 */
function conexao_seo_related_news( $post_id = 0, $limit = 3 ) {
	$post_id = $post_id ? $post_id : get_the_ID();
	$terms   = get_the_terms( $post_id, 'conexao_category' );
	if ( empty( $terms ) || is_wp_error( $terms ) ) {
		return array();
	}

	$query = new WP_Query( array(
		'post_type'      => 'news',
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

	$news = array();
	if ( $query->have_posts() ) {
		while ( $query->have_posts() ) {
			$query->the_post();
			$news[] = array(
				'title' => get_the_title(),
				'url'   => get_permalink(),
			);
		}
		wp_reset_postdata();
	}

	return $news;
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
 * Get related businesses for a given post (by shared category or county).
 */
function conexao_seo_related_businesses( $post_id = 0, $limit = 3 ) {
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
		'post_type'      => 'business',
		'posts_per_page' => $limit,
		'post__not_in'   => array( $post_id ),
		'tax_query'      => $tax_query,
		'no_found_rows'  => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	) );

	$businesses = array();
	if ( $query->have_posts() ) {
		while ( $query->have_posts() ) {
			$query->the_post();
			$businesses[] = array(
				'title' => get_the_title(),
				'url'   => get_permalink(),
			);
		}
		wp_reset_postdata();
	}

	return $businesses;
}