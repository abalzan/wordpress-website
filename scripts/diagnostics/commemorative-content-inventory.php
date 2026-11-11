#!/usr/bin/env php
<?php
/**
 * Read-only content inventory for the commemorative presentation work.
 *
 * Purpose: answer, from the REAL local database, which existing content could
 * support an editorial commemorative landing page (the r10 §7.13
 * "empty-landing-page rule": at least three real, non-duplicative modules).
 *
 * Scope: read-only. It never writes a post, term, option or meta row.
 *
 * Safety: local only. It uses the shared script bootstrap (which classifies the
 * target) and refuses nothing but reads; it never contacts production.
 *
 * Usage (from the project root):
 *   docker compose exec -T wordpress php /var/www/html/scripts/diagnostics/commemorative-content-inventory.php
 *
 * @package Conexao_BR_Scripts
 */

require_once dirname( __DIR__ ) . '/lib/bootstrap.php';

conexao_script_boot(
	array(
		'script'             => 'scripts/diagnostics/commemorative-content-inventory.php',
		'purpose'            => 'Inventory existing local content that could populate a commemorative landing page (read-only).',
		'scope'              => 'read-only',
		'safety'             => 'Reads WordPress posts, terms and Polylang links. Never writes.',
		'safety_level'       => 'read-only',
		'read_only'          => true,
		'writes'             => false,
		'production_capable' => false,
		'target_description' => 'the WordPress install it is executed against (local Docker WordPress by default)',
		'modes_description'  => "  --dry-run   The only mode (read-only).\n",
		'arguments'          => "  (no arguments)\n",
	)
);

/**
 * Print a table of posts for one post type.
 *
 * @param string $post_type Post type.
 * @param int    $limit     Maximum rows.
 * @return void
 */
function conexao_inventory_posts( $post_type, $limit = 200 ) {
	$posts = get_posts(
		array(
			'post_type'        => $post_type,
			'post_status'      => 'publish',
			'numberposts'      => $limit,
			'orderby'          => 'title',
			'order'            => 'ASC',
			'suppress_filters' => true,
		)
	);

	printf( "\n== %s (published=%d) ==\n", $post_type, count( $posts ) );

	foreach ( $posts as $post ) {
		$lang = function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $post->ID ) : '-';
		printf(
			"  %-7d %-4s %-42s %s\n",
			$post->ID,
			$lang,
			$post->post_name,
			wp_html_excerpt( $post->post_title, 70, '…' )
		);
	}
}

/**
 * Count posts whose title/content mentions any of the terms.
 *
 * @param string   $post_type Post type.
 * @param string[] $terms     Lower-case needles.
 * @return array<string,int>
 */
function conexao_inventory_matches( $post_type, array $terms ) {
	$posts = get_posts(
		array(
			'post_type'        => $post_type,
			'post_status'      => 'publish',
			'numberposts'      => 400,
			'suppress_filters' => true,
		)
	);

	$counts = array_fill_keys( $terms, 0 );

	foreach ( $posts as $post ) {
		$haystack = strtolower( $post->post_title . ' ' . $post->post_excerpt . ' ' . $post->post_content );

		foreach ( $terms as $term ) {
			if ( false !== strpos( $haystack, $term ) ) {
				$counts[ $term ]++;
			}
		}
	}

	return $counts;
}

$post_types = get_post_types( array( 'public' => true ), 'names' );
printf( "public post types: %s\n", implode( ', ', array_keys( $post_types ) ) );

foreach ( array( 'page', 'post', 'guide', 'event', 'leisure', 'sponsor', 'course' ) as $type ) {
	if ( post_type_exists( $type ) ) {
		conexao_inventory_posts( $type, 'event' === $type ? 40 : 200 );
	}
}

echo "\n== keyword matches (title/excerpt/content) ==\n";
foreach ( array( 'post', 'page', 'guide', 'event', 'leisure' ) as $type ) {
	if ( ! post_type_exists( $type ) ) {
		continue;
	}

	$counts = conexao_inventory_matches( $type, array( 'natal', 'christmas', 'ano novo', 'new year', 'réveillon', 'reveillon' ) );
	printf( "  %-8s natal=%d christmas=%d ano_novo=%d new_year=%d reveillon=%d\n", $type, $counts['natal'], $counts['christmas'], $counts['ano novo'], $counts['new year'], $counts['réveillon'] + $counts['reveillon'] );
}

echo "\n== candidate landing-page slugs (pages) ==\n";
foreach ( array( 'natal', 'christmas', 'ano-novo', 'new-year', 'halloween', 'pascoa', 'carnaval', 'festa-junina', 'st-patricks-day', 'independencia' ) as $slug ) {
	$page = get_page_by_path( $slug, OBJECT, 'page' );
	if ( $page ) {
		$lang = function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $page->ID ) : '-';
		printf( "  TAKEN  /%s/ -> id=%d lang=%s status=%s title=%s\n", $slug, $page->ID, $lang, $page->post_status, $page->post_title );
	} else {
		printf( "  free   /%s/\n", $slug );
	}
}

echo "\n== commemorative_event records ==\n";
$events = get_posts(
	array(
		'post_type'        => 'commemorative_event',
		'post_status'      => array( 'publish', 'draft' ),
		'numberposts'      => 100,
		'suppress_filters' => true,
	)
);

foreach ( $events as $event ) {
	printf(
		"  id=%-5d key=%-18s package=%-16s lang=%-3s status=%-8s title=%s\n",
		$event->ID,
		(string) get_post_meta( $event->ID, '_ce_event_key', true ),
		(string) get_post_meta( $event->ID, '_ce_presentation', true ),
		function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $event->ID ) : '-',
		$event->post_status,
		$event->post_title
	);
}

echo "\n== registered presentation packages (theme filter) ==\n";
if ( function_exists( 'conexao_commemorative_presentations' ) ) {
	foreach ( conexao_commemorative_presentations() as $slug => $package ) {
		$placements = isset( $package['placements'] ) && is_array( $package['placements'] ) ? array_keys( $package['placements'] ) : array();
		printf( "  %-18s placements=[%s]\n", $slug, implode( ', ', $placements ) );
	}
}

echo "\n== active event now ==\n";
if ( function_exists( 'conexao_commemorative_active' ) ) {
	$active = conexao_commemorative_active();
	printf( "  %s\n", $active ? $active->event_key . ' / ' . $active->presentation : 'none' );
}

echo "\nsummary: read-only inventory complete\n";
