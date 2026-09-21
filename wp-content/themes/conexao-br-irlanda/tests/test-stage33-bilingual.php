<?php
/**
 * Stage 3.3 — English navigation / link-resolution focus tests.
 *
 * Covers the Stage 3.3 deliverables at the logic + template level (the HTTP
 * matrix shipped next to the stage report covers the rendered level):
 *
 *  - conexao_lang_url(): the language-aware destination resolver used by the
 *    homepage quick-access cards, the help shortcuts, the section links and
 *    the footer legal links.
 *  - Portuguese invariance: every canonical Portuguese path resolves exactly
 *    as it did before the helper existed (byte-identical home_url() output).
 *  - English resolution: real EN destinations for pages/archives that have
 *    one; the approved B1 Portuguese destination everywhere else (Blog,
 *    untranslated B1 pages, B2 pages are never auto-promoted).
 *  - Guides category cards: PT slug in PT, LINKED EN slug in EN (only when the
 *    EN term carries published EN guides), never a PT slug under /en/.
 *  - Template-level proof that the quick-access card markup renders the
 *    resolved destination in both languages.
 *  - Stage 3.2 taxonomy contracts re-asserted for the pilot dataset: the 12
 *    linked EN terms, shared (untranslated) county/town terms, and the B2
 *    "PT records keep PT terms" rule.
 *
 * Read-only. No content is created or modified.
 *
 * Usage (from the project root):
 *   php wp-content/themes/conexao-br-irlanda/tests/test-stage33-bilingual.php
 *
 * @package conexao-br-irlanda
 */

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

$passed = 0;
$failed = 0;

function s33_assert( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		echo "  PASS: {$message}\n";
	} else {
		$failed++;
		echo "  FAIL: {$message}\n";
	}
}

/**
 * Switch the request language context the way Polylang does while routing.
 *
 * @param string $slug Language slug.
 * @return string|false Previous language slug.
 */
function s33_set_language( $slug ) {
	if ( ! function_exists( 'PLL' ) || ! PLL() ) {
		return false;
	}

	$model    = PLL()->model;
	$previous = isset( PLL()->curlang->slug ) ? PLL()->curlang->slug : false;

	PLL()->curlang = $model->get_language( $slug );

	return $previous;
}

/**
 * Path-only comparison so host/scheme never influences an assertion.
 *
 * @param string $url URL.
 * @return string
 */
function s33_path( $url ) {
	return (string) wp_parse_url( (string) $url, PHP_URL_PATH );
}

echo "== Stage 3.3 — English navigation / link resolution ==\n";

if ( ! function_exists( 'conexao_lang_url' ) ) {
	echo "  SKIP: conexao_lang_url() is missing.\n";
	exit( 1 );
}

if ( ! function_exists( 'pll_current_language' ) ) {
	echo "  SKIP: Polylang is not active.\n";
	exit( 0 );
}

// ---------------------------------------------------------------------------
// Paths exercised by the theme chrome (cards, section links, footer).
$paths = array(
	'/eventos/',
	'/lazer/',
	'/cursos/',
	'/apoiadores/',
	'/guias/',
	'/empregos/',
	'/blog/',
	'/sobre-nos/',
	'/contato/',
	'/politica-de-privacidade/',
	'/termos-de-uso/',
	'/cookies/',
	'/anuncie/',
	'/moradia/',
	'/saude/',
	'/irlanda/',
	'/dublin/',
	'/nao-existe/',
);

// ---------------------------------------------------------------------------
echo "\n-- Portuguese invariance (default language context) --\n";
s33_set_language( 'pt' );
s33_assert( 'pt' === conexao_current_language_slug(), "current language is 'pt' in the default context" );

foreach ( $paths as $path ) {
	s33_assert(
		untrailingslashit( conexao_lang_url( $path ) ) === untrailingslashit( home_url( $path ) ),
		"PT: conexao_lang_url( {$path} ) === home_url( {$path} )"
	);
}

// ---------------------------------------------------------------------------
echo "\n-- English resolution (real EN destinations) --\n";
s33_set_language( 'en' );
s33_assert( 'en' === conexao_current_language_slug(), "current language is 'en' in the English context" );

$en_archives = array(
	'/eventos/'    => '/en/eventos/',
	'/lazer/'      => '/en/lazer/',
	'/cursos/'     => '/en/cursos/',
	'/apoiadores/' => '/en/apoiadores/',
	'/guias/'      => '/en/guias/',
);

foreach ( $en_archives as $path => $expected ) {
	s33_assert(
		untrailingslashit( s33_path( conexao_lang_url( $path ) ) ) === untrailingslashit( $expected ),
		"EN archive: conexao_lang_url( {$path} ) === {$expected} (got " . s33_path( conexao_lang_url( $path ) ) . ')'
	);
}

$en_pages = array(
	'/empregos/'                => '/en/jobs/',
	'/sobre-nos/'               => '/en/about-us/',
	'/contato/'                 => '/en/contact/',
	'/politica-de-privacidade/' => '/en/privacy-policy/',
	'/termos-de-uso/'           => '/en/terms-of-use/',
	'/cookies/'                 => '/en/cookie-policy/',
);

foreach ( $en_pages as $path => $expected ) {
	s33_assert(
		untrailingslashit( s33_path( conexao_lang_url( $path ) ) ) === untrailingslashit( $expected ),
		"EN page: conexao_lang_url( {$path} ) === {$expected} (got " . s33_path( conexao_lang_url( $path ) ) . ')'
	);
}

// ---------------------------------------------------------------------------
echo "\n-- English resolution (approved B1 / B2 behaviour preserved) --\n";
foreach ( array( '/blog/', '/anuncie/', '/moradia/', '/saude/', '/irlanda/', '/dublin/', '/nao-existe/' ) as $path ) {
	s33_assert(
		untrailingslashit( conexao_lang_url( $path ) ) === untrailingslashit( home_url( $path ) ),
		"EN/B1: conexao_lang_url( {$path} ) keeps the Portuguese destination (got " . s33_path( conexao_lang_url( $path ) ) . ')'
	);
}

s33_assert(
	0 !== strpos( s33_path( conexao_lang_url( '/irlanda/' ) ), '/en/' ),
	'EN: a B2 page (/irlanda/) is never auto-promoted to an EN URL'
);

// ---------------------------------------------------------------------------
echo "\n-- Guides category cards (language-aware term + archive) --\n";
s33_set_language( 'pt' );

$pt_moradia    = conexao_get_guide_category_url( 'moradia', 'moradia' );
$pt_documentos = conexao_get_guide_category_url( 'documentos', 'documentos' );

s33_assert(
	'/guias' === untrailingslashit( s33_path( $pt_moradia ) ) && 'categoria=moradia' === wp_parse_url( $pt_moradia, PHP_URL_QUERY ),
	'PT: card "Moradia" still resolves to /guias/?categoria=moradia (got ' . $pt_moradia . ')'
);
s33_assert(
	'categoria=documentos' === wp_parse_url( $pt_documentos, PHP_URL_QUERY ),
	'PT: card "Documentos" still resolves with the Portuguese slug (got ' . $pt_documentos . ')'
);

s33_set_language( 'en' );

$en_documentos = conexao_get_guide_category_url( 'documentos', 'documentos' );
$en_financas   = conexao_get_guide_category_url( 'financas', 'financas' );
$en_moradia    = conexao_get_guide_category_url( 'moradia', 'moradia' );

s33_assert(
	'/en/guias' === untrailingslashit( s33_path( $en_documentos ) ) && 'categoria=documents' === wp_parse_url( $en_documentos, PHP_URL_QUERY ),
	'EN: card "Documentos" resolves to /en/guias/?categoria=documents (linked EN term with EN guides) (got ' . $en_documentos . ')'
);
s33_assert(
	'categoria=finances' === wp_parse_url( $en_financas, PHP_URL_QUERY ),
	'EN: card "Finanças" resolves with the linked EN slug "finances" (got ' . $en_financas . ')'
);
s33_assert(
	'/en/guias' === untrailingslashit( s33_path( $en_moradia ) ) && '' === (string) wp_parse_url( $en_moradia, PHP_URL_QUERY ),
	'EN: card "Moradia" (no linked EN term with EN guides) falls back to the plain EN archive (got ' . $en_moradia . ')'
);
s33_assert(
	false === strpos( $en_moradia . $en_documentos . $en_financas, '/guias/?categoria=moradia' ),
	'EN: no Portuguese term slug is ever placed under /en/'
);

// ---------------------------------------------------------------------------
echo "\n-- Quick-access card markup (template level) --\n";
$card_cases = array(
	'/eventos/'    => '/en/eventos/',
	'/lazer/'      => '/en/lazer/',
	'/cursos/'     => '/en/cursos/',
	'/apoiadores/' => '/en/apoiadores/',
	'/empregos/'   => '/en/jobs/',
	'/blog/'       => '/blog/',
);

foreach ( $card_cases as $path => $expected ) {
	ob_start();
	get_template_part(
		'template-parts/quick-access-card',
		null,
		array(
			'card'  => array( 'key' => 'test', 'title' => 'Test', 'url' => $path ),
			'icons' => array(),
		)
	);
	$markup = (string) ob_get_clean();

	s33_assert(
		false !== strpos( $markup, 'href="' . home_url( $expected ) . '"' ),
		"EN card markup for {$path} links to {$expected}"
	);
}

s33_set_language( 'pt' );

foreach ( $card_cases as $path => $expected ) {
	ob_start();
	get_template_part(
		'template-parts/quick-access-card',
		null,
		array(
			'card'  => array( 'key' => 'test', 'title' => 'Test', 'url' => $path ),
			'icons' => array(),
		)
	);
	$markup = (string) ob_get_clean();

	s33_assert(
		false !== strpos( $markup, 'href="' . home_url( $path ) . '"' ),
		"PT card markup for {$path} is unchanged"
	);
}

// ---------------------------------------------------------------------------
echo "\n-- Help shortcut markup (template level) --\n";
s33_set_language( 'en' );

ob_start();
get_template_part(
	'template-parts/help-shortcut-card',
	null,
	array(
		'card'  => array( 'key' => 'empregos', 'title' => 'Jobs', 'url' => '/empregos/' ),
		'icons' => array(),
	)
);
$shortcut = (string) ob_get_clean();

s33_assert(
	false !== strpos( $shortcut, 'href="' . home_url( '/en/jobs/' ) . '"' ),
	'EN help shortcut resolves /empregos/ to /en/jobs/'
);

// ---------------------------------------------------------------------------
echo "\n-- Footer legal links (template level) --\n";
$footer = file_get_contents( get_stylesheet_directory() . '/footer.php' );
s33_assert(
	false !== strpos( $footer, "conexao_lang_url( '/politica-de-privacidade/' )" )
	&& false !== strpos( $footer, "conexao_lang_url( '/termos-de-uso/' )" )
	&& false !== strpos( $footer, "conexao_lang_url( '/cookies/' )" ),
	'footer legal links are resolved through conexao_lang_url()'
);

// ---------------------------------------------------------------------------
echo "\n-- Stage 3.2 pilot EN terms (12) --\n";
$term_map = array(
	'conexao_category' => array(
		'documentos'  => 'documents',
		'trabalho'    => 'work',
		'financas'    => 'finances',
		'festivais'   => 'festivals',
		'musica'      => 'music',
		'cultura'     => 'culture',
		'natureza'    => 'nature',
		'cidades'     => 'cities',
		'negocios'    => 'businesses',
		'gastronomia' => 'gastronomy',
		'empregos'    => 'jobs',
	),
	'category'         => array( 'comunidade' => 'community' ),
);

$term_count = 0;

foreach ( $term_map as $taxonomy => $map ) {
	foreach ( $map as $pt_slug => $en_slug ) {
		$pt_terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'slug'       => $pt_slug,
				'hide_empty' => false,
				'lang'       => 'pt',
			)
		);
		$en_terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'slug'       => $en_slug,
				'hide_empty' => false,
				'lang'       => 'en',
			)
		);

		if ( is_wp_error( $pt_terms ) || is_wp_error( $en_terms ) || ! $pt_terms || ! $en_terms ) {
			s33_assert( false, "term pair {$taxonomy}:{$pt_slug} <=> {$en_slug} exists" );
			continue;
		}

		$linked = pll_get_term( (int) $pt_terms[0]->term_id, 'en' );
		$term_count++;

		s33_assert(
			(int) $en_terms[0]->term_id === (int) $linked,
			"term pair {$taxonomy}:{$pt_slug} <=> {$en_slug} is linked in Polylang"
		);
	}
}

s33_assert( 12 === $term_count, "all 12 Stage 3.2 EN terms are present and linked (got {$term_count})" );

// ---------------------------------------------------------------------------
echo "\n-- Shared county / town contract (Stage 3.2 policy) --\n";
$location_taxonomies = array( 'conexao_county', 'conexao_town' );

foreach ( $location_taxonomies as $taxonomy ) {
	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'lang'       => '',
			'number'     => 0,
		)
	);

	if ( is_wp_error( $terms ) ) {
		s33_assert( false, "{$taxonomy} terms are readable" );
		continue;
	}

	$suffixed = array();
	$langful  = 0;

	foreach ( $terms as $term ) {
		if ( preg_match( '/-(en|pt)$/', $term->slug ) ) {
			$suffixed[] = $term->slug;
		}

		$language = function_exists( 'pll_get_term_language' ) ? pll_get_term_language( $term->term_id ) : false;
		if ( ! empty( $language ) ) {
			$langful++;
		}
	}

	s33_assert( array() === $suffixed, "{$taxonomy}: no suffixed duplicate terms (" . implode( ', ', $suffixed ) . ')' );
	s33_assert( 0 === $langful, "{$taxonomy}: every term is language-neutral (shared), {$langful} language-tagged" );
}

// An EN record and its PT master must carry the SAME county/town term ids.
$en_leisure_terms = get_posts(
	array(
		'post_type'      => 'leisure',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'lang'           => 'en',
	)
);

foreach ( $en_leisure_terms as $en_id ) {
	$master = pll_get_post( $en_id, 'pt' );

	if ( ! $master || (int) $master === (int) $en_id ) {
		continue;
	}

	foreach ( $location_taxonomies as $taxonomy ) {
		$en_terms     = wp_get_post_terms( $en_id, $taxonomy, array( 'fields' => 'ids' ) );
		$master_terms = wp_get_post_terms( $master, $taxonomy, array( 'fields' => 'ids' ) );

		s33_assert(
			! is_wp_error( $en_terms ) && ! is_wp_error( $master_terms ) && $en_terms === $master_terms,
			"leisure #{$en_id} shares the exact {$taxonomy} term ids with its PT master #{$master}"
		);
	}
}

// ---------------------------------------------------------------------------
echo "\n-- EN records use linked EN terms; PT masters keep PT terms --\n";
$pilot_types = array( 'guide', 'event', 'leisure', 'sponsor', 'course_provider', 'job' );
$pairs       = 0;

foreach ( $pilot_types as $post_type ) {
	$en_ids = get_posts(
		array(
			'post_type'      => $post_type,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'lang'           => 'en',
		)
	);

	foreach ( $en_ids as $en_id ) {
		$master = pll_get_post( $en_id, 'pt' );

		if ( ! $master ) {
			continue;
		}

		$pairs++;

		$en_cats     = wp_get_post_terms( $en_id, 'conexao_category', array( 'fields' => 'ids' ) );
		$master_cats = wp_get_post_terms( $master, 'conexao_category', array( 'fields' => 'ids' ) );

		if ( is_wp_error( $en_cats ) || is_wp_error( $master_cats ) ) {
			s33_assert( false, "{$post_type} #{$en_id}: category terms readable" );
			continue;
		}

		$mapped = array();

		foreach ( $master_cats as $master_term_id ) {
			$translation = pll_get_term( $master_term_id, 'en' );

			if ( $translation ) {
				$mapped[] = (int) $translation;
			}

			$mapped[] = (int) $master_term_id;
		}

		$mapped = array_values( array_unique( $mapped ) );

		s33_assert(
			array() === array_diff( $en_cats, $mapped ),
			"{$post_type} EN #{$en_id}: every category is the linked EN term of a master term (or the shared master term)"
		);

		$master_languages = array();

		foreach ( $master_cats as $master_term_id ) {
			$master_languages[] = (string) pll_get_term_language( $master_term_id );
		}

		s33_assert(
			array() === array_diff( array_unique( $master_languages ), array( 'pt' ) ),
			"{$post_type} PT master #{$master}: category terms stay Portuguese"
		);
	}
}

s33_assert( $pairs > 0, "at least one PT/EN pilot pair was inspected (got {$pairs})" );

// ---------------------------------------------------------------------------
echo "\nstage 3.3 bilingual: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );



