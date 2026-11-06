#!/usr/bin/env php
<?php
/**
 * Seed the opt-in commemorative landing pages (Christmas) — local only.
 *
 * Purpose: create the PT `/natal/` page and its linked EN `/en/christmas/`
 *          translation, each opted into the `christmas` experience package through
 *          the assigned template + `_ce_landing_package` meta. This is the r10
 *          §7.13 landing-page fixture: a NORMAL page, never a CPT route.
 *
 * Scope: local-write (creates at most two pages). It never updates or deletes an
 *        existing page, and it never touches the `commemorative_event` records.
 *
 * Safety: dry-run is the default and performs zero writes. `--apply` is the only
 *         mode that writes, it is idempotent (a second run reports created=0), and
 *         a slug already taken by a page that is NOT an opt-in landing page is
 *         reported as a CONFLICT and left untouched — it is never silently
 *         converted. Production is never a target: `production_capable` is false.
 *
 * Usage (from the project root):
 *   docker compose exec -T wordpress php /var/www/html/scripts/seed-commemorative-landing-pages.php --dry-run
 *   docker compose exec -T wordpress php /var/www/html/scripts/seed-commemorative-landing-pages.php --apply
 *
 * @package Conexao_BR_Scripts
 */

require_once __DIR__ . '/lib/bootstrap.php';

$ctx = conexao_script_boot(
	array(
		'script'             => 'scripts/seed-commemorative-landing-pages.php',
		'purpose'            => 'Create the opt-in commemorative landing pages (PT /natal/ + linked EN /christmas/) for the christmas package (idempotent, dry-run first).',
		'scope'              => 'local-write (creates at most two pages; never updates or deletes an existing page)',
		'safety'             => 'Dry-run is the default and writes nothing. --apply creates only the pages that do not exist, refuses to convert an existing non-commemorative page, and never touches commemorative_event records.',
		'safety_level'       => 'local-write',
		'writes'             => true,
		'production_capable' => false,
		'target_description' => 'the WordPress install it is executed against (local Docker WordPress by default)',
		'modes_description'  => "  --dry-run   Plan only; performs zero writes (DEFAULT).\n"
			. "  --apply     Create the missing landing pages.\n"
			. "  --json      Emit the result as JSON on stdout.\n",
		'arguments'          => "  (no extra arguments)\n",
	)
);

if ( ! function_exists( 'conexao_commemorative_package_definitions' ) ) {
	fwrite( STDERR, "ERROR: the theme (or its commemorative presentation framework) is not active.\n" );

	return 1;
}

const CONEXAO_LANDING_TEMPLATE = 'page-commemorative-landing.php';
const CONEXAO_LANDING_PACKAGE  = 'christmas';

$definitions = array(
	'pt' => array(
		'title'    => 'Natal',
		'slug'     => 'natal',
		'language' => 'pt',
		'intro'    => '<p>O Natal da comunidade brasileira na Irlanda: a agenda de concertos e feiras, o solstício em Newgrange, os cuidados com o inverno e leituras para as noites longas.</p><p>Tudo aqui aponta para conteúdo já publicado no portal — e esta página continua disponível depois das festas.</p>',
	),
	'en' => array(
		'title'    => 'Christmas',
		'slug'     => 'christmas',
		'language' => 'en',
		'intro'    => '<p>Christmas for the Brazilian community in Ireland: concerts and markets, the solstice at Newgrange, looking after yourself through the winter and reading for the long nights.</p><p>Everything here links to content already published on the portal — and this page stays available after the season ends.</p>',
	),
);

/**
 * Find an existing page by slug in one language.
 *
 * @param string $slug     Slug.
 * @param string $language Polylang language slug.
 * @return WP_Post|null
 */
function conexao_landing_find( $slug, $language ) {
	$args = array(
		'post_type'        => 'page',
		'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
		'name'             => $slug,
		'numberposts'      => 1,
		'suppress_filters' => true,
	);

	$pages = get_posts( $args );

	if ( empty( $pages ) ) {
		return null;
	}

	$page = $pages[0];

	if ( function_exists( 'pll_get_post_language' ) ) {
		$actual = (string) pll_get_post_language( $page->ID );

		if ( '' !== $actual && $actual !== $language ) {
			return null;
		}
	}

	return $page;
}

/**
 * Whether an existing page is already our opt-in landing page.
 *
 * @param WP_Post $page Page.
 * @return bool
 */
function conexao_landing_is_opted_in( WP_Post $page ) {
	$template = (string) get_post_meta( $page->ID, '_wp_page_template', true );
	$package  = (string) get_post_meta( $page->ID, '_ce_landing_package', true );

	return CONEXAO_LANDING_TEMPLATE === $template && CONEXAO_LANDING_PACKAGE === $package;
}

echo "inventory (read-only):\n";

$existing  = array();
$conflicts = array();

foreach ( $definitions as $language => $definition ) {
	$page = conexao_landing_find( $definition['slug'], $language );

	if ( null === $page ) {
		printf( "  %-3s /%s/  free\n", $language, $definition['slug'] );
		continue;
	}

	$existing[ $language ] = $page;

	$opted_in = conexao_landing_is_opted_in( $page );

	printf(
		"  %-3s /%s/  exists id=%d status=%s opt-in=%s title=%s\n",
		$language,
		$definition['slug'],
		$page->ID,
		$page->post_status,
		$opted_in ? 'yes' : 'no',
		$page->post_title
	);

	if ( ! $opted_in ) {
		$conflicts[] = $language;
	}
}

if ( $conflicts ) {
	fwrite( STDERR, "\nCONFLICT: an existing page already uses the slug but is not an opt-in commemorative landing page:\n" );
	foreach ( $conflicts as $language ) {
		fwrite( STDERR, sprintf( "  - %s /%s/ (left untouched; resolve manually)\n", $language, $definitions[ $language ]['slug'] ) );
	}

	return conexao_script_summary(
		$ctx,
		array(
			'mode'      => $ctx['mode'],
			'created'   => 0,
			'skipped'   => count( $existing ),
			'conflicts' => count( $conflicts ),
			'errors'    => 1,
		),
		array( 'conflicts' => $conflicts )
	);
}

$apply = 'apply' === $ctx['mode'];

// The plan: create only the languages whose page is missing.
$plan = array();

foreach ( $definitions as $language => $definition ) {
	$plan[ $language ] = isset( $existing[ $language ] ) ? 'skip' : 'create';
}

printf(
	"\nplan: create=%d skip=%d conflicts=%d\n",
	count( array_filter( $plan, static function ( $action ) { return 'create' === $action; } ) ),
	count( array_filter( $plan, static function ( $action ) { return 'skip' === $action; } ) ),
	0
);

$created = array();
$errors  = 0;

if ( $apply ) {
	foreach ( $definitions as $language => $definition ) {
		if ( isset( $existing[ $language ] ) ) {
			continue;
		}

		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $definition['title'],
				'post_name'    => $definition['slug'],
				'post_content' => $definition['intro'],
			),
			true
		);

		if ( is_wp_error( $page_id ) || ! $page_id ) {
			fwrite( STDERR, sprintf( "ERROR: could not create the %s landing page.\n", $language ) );
			$errors++;
			continue;
		}

		$page_id = (int) $page_id;

		update_post_meta( $page_id, '_wp_page_template', CONEXAO_LANDING_TEMPLATE );
		update_post_meta( $page_id, '_ce_landing_package', CONEXAO_LANDING_PACKAGE );

		if ( function_exists( 'pll_set_post_language' ) ) {
			pll_set_post_language( $page_id, $language );
		}

		$created[ $language ] = $page_id;

		printf( "  created %-3s id=%d /%s/\n", $language, $page_id, $definition['slug'] );
	}

	// Link the pair once both records exist (Polylang owns the relationship).
	if ( isset( $created['pt'], $created['en'] ) && function_exists( 'pll_save_post_translations' ) ) {
		pll_save_post_translations(
			array(
				'pt' => (int) $created['pt'],
				'en' => (int) $created['en'],
			)
		);

		echo "  linked the PT/EN pair\n";
	}
}

// Verification summary (read-only, always executed).
$verify = array();

foreach ( $definitions as $language => $definition ) {
	$page = conexao_landing_find( $definition['slug'], $language );

	$verify[ $language ] = array(
		'id'       => $page ? (int) $page->ID : 0,
		'status'   => $page ? $page->post_status : 'missing',
		'opt_in'   => $page ? conexao_landing_is_opted_in( $page ) : false,
		'linked'   => false,
	);
}

if ( isset( $verify['pt']['id'], $verify['en']['id'] ) && $verify['pt']['id'] && $verify['en']['id'] && function_exists( 'pll_get_post_translations' ) ) {
	$translations = pll_get_post_translations( (int) $verify['pt']['id'] );

	$verify['en']['linked'] = isset( $translations['en'] ) && (int) $translations['en'] === (int) $verify['en']['id'];
	$verify['pt']['linked'] = isset( $translations['pt'] ) && (int) $translations['pt'] === (int) $verify['pt']['id'];
}

echo "\nverify:\n";

foreach ( $verify as $language => $row ) {
	printf(
		"  %-3s id=%-6d status=%-9s opt-in=%-3s linked=%s\n",
		$language,
		$row['id'],
		$row['status'],
		$row['opt_in'] ? 'yes' : 'no',
		$row['linked'] ? 'yes' : 'no'
	);
}

$all_ok = true;

foreach ( $verify as $row ) {
	if ( 0 === $row['id'] || 'publish' !== $row['status'] || ! $row['opt_in'] ) {
		$all_ok = false;
	}
}

return conexao_script_summary(
	$ctx,
	array(
		'mode'         => $ctx['mode'],
		'created'      => count( $created ),
		'skipped'      => count( $existing ),
		'translations' => ( $verify['pt']['linked'] && $verify['en']['linked'] ) ? 1 : 0,
		'errors'       => $errors,
		'verified'     => $all_ok ? 'yes' : 'no',
	),
	array(
		'pages'   => $verify,
		'created' => $created,
	)
);

