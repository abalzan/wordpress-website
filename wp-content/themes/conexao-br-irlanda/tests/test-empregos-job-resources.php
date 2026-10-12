<?php
/**
 * In-process regression suite — /empregos/ "Onde procurar emprego" job-search
 * resource cards.
 *
 * ## What this suite locks down
 *
 * The task restored the shared job-resources component on the Jobs landing
 * page (page-empregos.php had carried an "INTENTIONALLY not rendered" comment
 * since commit a035ce0; the call
 * `get_template_part( 'template-parts/job-resources' )` was restored
 * 2026-12-10). The suite proves:
 *
 *   1. the shared data source (conexao_job_resource_defaults() /
 *      conexao_job_resources()) carries exactly the three specified resources
 *      — names, URLs and descriptions;
 *   2. page-empregos.php renders the component exactly once, and still calls
 *      the landing body content, the Instagram CTA and the unified
 *      employment-opportunities directory (its filters and pagination intact);
 *   3. the rendered /empregos/ section carries the section wrapper, an h2
 *      heading, exactly three `event-card job-resource-card` cards, the exact
 *      title / URL / description per resource, an "Acessar" CTA with the
 *      accessible aria-label, the shared SVG arrow icon, and
 *      target="_blank" rel="noopener noreferrer" on every external link;
 *   4. the homepage preview (front-page.php → job-resources-preview) is
 *      unchanged and surfaces the exact same resource set as /empregos/ (one
 *      shared data source — no second list);
 *   5. the EN catalogue renders the section heading, copy and accessible names
 *      in English (existing translation architecture, no new lifecycle);
 *   6. the design-system CSS for both grids still ships.
 *
 * Data: the `conexao_job_resources` option is isolated through the shared
 * option-isolation helper (seeded empty → the built-in defaults apply) and
 * restored verbatim at the end, so the suite is deterministic on any install
 * and never destroys operator state. Read-only otherwise; never contacts
 * production.
 *
 * Usage (inside the WordPress container):
 *   php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-empregos-job-resources.php
 *
 * @package Conexao_BR_Irlanda
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$theme_functions = get_template_directory() . '/functions.php';
if ( file_exists( $theme_functions ) ) {
	require_once $theme_functions;
}

test_prerequisite_hint( 'the conexao-br-irlanda theme must be active (stack up: docker compose up -d)' );
test_require(
	function_exists( 'conexao_job_resources' ) && function_exists( 'get_template_part' ),
	'the conexao-br-irlanda theme is loaded',
	'the shared job-resource data source and the template loader exist',
	'activate the conexao-br-irlanda theme and start the stack with docker compose up -d'
);

test_title( '/empregos/ job-search resource cards' );

/**
 * Activate a catalogue for the theme text domain, the way a real request does.
 *
 * Same approach as test-homepage-content-type-label.php (`hcl_switch_locale`):
 * WordPress 6.7+ memoises just-in-time textdomain loads, so a single CLI
 * process cannot flip locales with switch_to_locale() alone. Unload first so
 * every switch is honoured, and fail loudly if a committed .mo is missing.
 *
 * @param string $locale Locale to activate, e.g. 'en_US'.
 * @return void
 */
function ejr_switch_locale( $locale ) {
	$domain  = 'conexao-br-irlanda';
	$mo_file = get_template_directory() . '/languages/' . $locale . '.mo';

	assert_true(
		file_exists( $mo_file ),
		"the {$locale} catalogue is compiled and present",
		$mo_file
	);

	unload_textdomain( $domain );
	load_textdomain( $domain, $mo_file, $locale );
}

/**
 * The three resources exactly as specified by the task, in render order.
 *
 * @return array<int,array{title:string,url:string,description:string}>
 */
function ejr_spec_resources() {
	return array(
		array(
			'title'       => 'Jobs.ie',
			'url'         => 'https://www.jobs.ie/',
			'description' => 'Encontre vagas de emprego em diversas áreas e regiões da Irlanda.',
		),
		array(
			'title'       => 'Indeed Ireland',
			'url'         => 'https://ie.indeed.com/',
			'description' => 'Busca de vagas em todo o país, com filtros por área, salário e localização.',
		),
		array(
			'title'       => 'IrishJobs',
			'url'         => 'https://www.irishjobs.ie/',
			'description' => 'Uma das maiores plataformas de recrutamento da Irlanda, com vagas de grandes empresas.',
		),
	);
}

/**
 * Extract the sorted unique set of job-site URLs found in rendered markup.
 *
 * @param string $html Rendered markup.
 * @return string[] Sorted unique URLs from the three specified resources.
 */
function ejr_resource_urls( $html ) {
	preg_match_all( '#href="(https://(www\.jobs\.ie|ie\.indeed\.com|www\.irishjobs\.ie)/)"#', (string) $html, $matches );
	$urls = array_unique( isset( $matches[1] ) ? $matches[1] : array() );
	sort( $urls );
	return $urls;
}

$spec = ejr_spec_resources();

// ---------------------------------------------------------------------------
test_section( 'Shared data source (spec contract)' );

// Force the built-in defaults deterministically and restore verbatim at the
// end: the suite must not depend on whether a maintainer already saved
// different resources through the admin screen.
$option_token = test_options_isolate( array( 'conexao_job_resources' ) );

ejr_switch_locale( 'pt_BR' );

$defaults = conexao_job_resource_defaults();
assert_equals( 3, count( $defaults ), 'the built-in defaults define exactly 3 resources' );

foreach ( $spec as $index => $resource ) {
	assert_equals( $resource['title'], $defaults[ $index ]['title'], "default #{$index} title matches the spec" );
	assert_equals( $resource['url'], $defaults[ $index ]['url'], "default #{$index} URL matches the spec" );
	assert_equals( $resource['description'], $defaults[ $index ]['description'], "default #{$index} description matches the spec" );
}

$shared = conexao_job_resources();
assert_equals(
	wp_list_pluck( $shared, 'title' ),
	wp_list_pluck( $spec, 'title' ),
	'conexao_job_resources() returns the three specified resources (empty option → defaults)'
);

// ---------------------------------------------------------------------------
test_section( 'page-empregos.php integration (static)' );

$page_template = file_get_contents( get_template_directory() . '/page-empregos.php' );
assert_true( '' !== $page_template, 'page-empregos.php is readable' );

assert_equals(
	1,
	substr_count( $page_template, "get_template_part( 'template-parts/job-resources' )" ),
	'/empregos/ renders the shared job-resources component exactly once'
);

// The jobs-page functionality around the new section must survive untouched.
assert_contains( 'the_content();', $page_template, 'the landing page body content still renders' );
assert_contains(
	"get_template_part( 'template-parts/employment-opportunities' )",
	$page_template,
	'the unified opportunities directory (filters + listing + pagination) still renders'
);
assert_contains( 'conexao_empregos_link()', $page_template, 'the optional Instagram CTA is preserved' );

$opportunities_template = file_get_contents( get_template_directory() . '/template-parts/employment-opportunities.php' );
assert_true( '' !== $opportunities_template, 'the employment-opportunities template is readable' );
assert_contains( 'agency-filters-dropdown-link', $opportunities_template, 'the directory filter markup is intact' );
assert_contains( 'paginate_links', $opportunities_template, 'the directory pagination is intact' );

// ---------------------------------------------------------------------------
test_section( 'Shared rendering and homepage intact (static)' );

$front_page = file_get_contents( get_template_directory() . '/front-page.php' );
assert_contains(
	"get_template_part( 'template-parts/job-resources', 'preview' )",
	$front_page,
	'the homepage still renders the compact job-resources preview'
);
assert_contains( 'Onde procurar emprego', $front_page, 'the homepage section heading is unchanged' );

$empregos_part = file_get_contents( get_template_directory() . '/template-parts/job-resources.php' );
$preview_part  = file_get_contents( get_template_directory() . '/template-parts/job-resources-preview.php' );
assert_contains( 'conexao_job_resources()', $empregos_part, '/empregos/ component reads the shared data source' );
assert_contains( 'conexao_job_resources()', $preview_part, 'homepage preview reads the SAME shared data source' );

$main_css = file_get_contents( get_template_directory() . '/assets/css/main.css' );
assert_contains( '.empregos-job-resources {', $main_css, 'the /empregos/ section styles ship in the theme CSS' );
assert_contains( '.jobs-home-grid .event-card {', $main_css, 'the homepage grid styles ship in the theme CSS' );

// ---------------------------------------------------------------------------
test_section( '/empregos/ rendered section (PT)' );

ejr_switch_locale( 'pt_BR' );

ob_start();
get_template_part( 'template-parts/job-resources' );
$empregos_html = (string) ob_get_clean();

assert_contains(
	'<section class="empregos-job-resources" aria-labelledby="empregos-job-resources-title">',
	$empregos_html,
	'the section wrapper renders with its aria-labelledby'
);
assert_contains(
	'<h2 id="empregos-job-resources-title" class="empregos-job-resources-title">',
	$empregos_html,
	'the section heading is an h2 bound to the wrapper (not an oversized h1)'
);
assert_contains( 'Onde procurar emprego', $empregos_html, 'the PT section heading text is unchanged' );
assert_contains( 'events-grid empregos-job-resources-grid', $empregos_html, 'the cards use the shared events-grid layout' );

assert_equals(
	3,
	substr_count( $empregos_html, 'event-card job-resource-card' ),
	'exactly three job-resource cards render on /empregos/'
);

preg_match_all( '#<article class="event-card job-resource-card">.*?</article>#s', $empregos_html, $card_matches );
$cards = isset( $card_matches[0] ) ? $card_matches[0] : array();
assert_equals( 3, count( $cards ), 'three <article class="event-card job-resource-card"> blocks are parsed' );

$found = 0;
foreach ( $spec as $resource ) {
	$block = '';
	foreach ( $cards as $card ) {
		if ( false !== strpos( $card, '>' . $resource['title'] . '</a>' ) ) {
			$block = $card;
			break;
		}
	}

	if ( '' === $block ) {
		assert_fail( "card for {$resource['title']} rendered on /empregos/" );
		continue;
	}
	++$found;

	$label = $resource['title'];
	assert_contains( 'href="' . $resource['url'] . '"', $block, "{$label}: exact destination URL renders" );
	assert_contains( $resource['description'], $block, "{$label}: exact description renders" );
	assert_contains( 'target="_blank" rel="noopener noreferrer"', $block, "{$label}: external-link security attributes present" );
	assert_contains( 'class="event-card-cta"', $block, "{$label}: the CTA uses the shared event-card-cta class" );
	assert_contains(
		'aria-label="Acessar ' . $resource['title'] . ' (abre em nova aba)"',
		$block,
		"{$label}: accessible CTA name names the resource and the new tab"
	);
	assert_true(
		1 === preg_match( '#\>\s*Acessar\s*<svg#', $block ),
		"{$label}: visible CTA text is 'Acessar', immediately followed by the icon"
	);
	assert_contains(
		'<svg viewBox="0 0 24 24" width="14" height="14"',
		$block,
		"{$label}: the shared arrow SVG icon renders"
	);
	assert_contains( 'title="(abre em nova aba)"', $block, "{$label}: new-tab hint on the links" );
	assert_not_contains( 'Saiba mais', $block, "{$label}: no stray legacy CTA text" );
}
assert_equals( 3, $found, 'all three specified resources have a card on /empregos/' );

assert_equals(
	9,
	substr_count( $empregos_html, 'target="_blank" rel="noopener noreferrer"' ),
	'every external link (banner + title + CTA per card) carries target/rel'
);

// ---------------------------------------------------------------------------
test_section( 'Homepage preview (unchanged)' );

ob_start();
get_template_part( 'template-parts/job-resources', 'preview' );
$preview_html = (string) ob_get_clean();

assert_contains( 'events-grid jobs-home-grid', $preview_html, 'the homepage keeps its compact grid wrapper' );
assert_equals(
	3,
	substr_count( $preview_html, 'event-card job-resource-card' ),
	'the homepage still renders exactly three cards'
);

foreach ( $spec as $resource ) {
	assert_contains( 'href="' . $resource['url'] . '"', $preview_html, "homepage: {$resource['title']} URL unchanged" );
	assert_contains( $resource['description'], $preview_html, "homepage: {$resource['title']} description unchanged" );
	assert_contains(
		'aria-label="Acessar ' . $resource['title'] . ' (abre em nova aba)"',
		$preview_html,
		"homepage: {$resource['title']} CTA accessible name unchanged"
	);
}
assert_contains(
	'target="_blank" rel="noopener noreferrer"',
	$preview_html,
	'homepage links keep the external-link security attributes'
);

// One shared data source: both pages surface the identical resource set.
$empregos_urls = ejr_resource_urls( $empregos_html );
$preview_urls  = ejr_resource_urls( $preview_html );
assert_equals( 3, count( $empregos_urls ), 'all three external URLs render on /empregos/' );
assert_equals( $empregos_urls, $preview_urls, 'both pages surface the identical resource URL set (no second list)' );

// ---------------------------------------------------------------------------
test_section( 'EN catalogue rendering (translation architecture)' );

ejr_switch_locale( 'en_US' );

ob_start();
get_template_part( 'template-parts/job-resources' );
$empregos_en_html = (string) ob_get_clean();

assert_contains( 'Where to look for jobs', $empregos_en_html, 'EN: the section heading renders from the existing EN catalogue' );
assert_contains(
	'Find job openings across many sectors and regions of Ireland.',
	$empregos_en_html,
	'EN: the Jobs.ie description renders in English'
);
assert_not_contains(
	'Encontre vagas de emprego em diversas áreas e regiões da Irlanda.',
	$empregos_en_html,
	'EN: the PT description does not leak into the EN render'
);
assert_contains(
	'aria-label="Open Jobs.ie (opens in a new tab)"',
	$empregos_en_html,
	'EN: the CTA accessible name is translated'
);
foreach ( $spec as $resource ) {
	assert_contains( 'href="' . $resource['url'] . '"', $empregos_en_html, "EN: {$resource['title']} URL unchanged" );
	assert_contains(
		'>' . $resource['title'] . '</a>',
		$empregos_en_html,
		"EN: brand name {$resource['title']} is not translated away"
	);
}

// Leave the theme text domain on the Portuguese catalogue, the site's source
// language, and put the operator's option back exactly as it was.
ejr_switch_locale( 'pt_BR' );
test_options_restore( $option_token );

test_finish( 'empregos-job-resources' );




