#!/usr/bin/env php
<?php
/**
 * Read-only probe of the Christmas-related content available locally.
 *
 * Purpose: prove (or disprove) that the `christmas` experience package can
 * populate a landing page with at least three real, non-duplicative modules
 * (r10 §7.13 "empty-landing-page rule"), and list the exact records reused.
 *
 * Scope: read-only. Never writes a post, term, option or meta row.
 *
 * Safety: local only; it uses the shared script bootstrap and reads WordPress.
 *
 * Usage (from the project root):
 *   docker compose exec -T wordpress php /var/www/html/scripts/diagnostics/commemorative-christmas-content.php
 *
 * @package Conexao_BR_Scripts
 */

require_once dirname( __DIR__ ) . '/lib/bootstrap.php';

conexao_script_boot(
	array(
		'script'             => 'scripts/diagnostics/commemorative-christmas-content.php',
		'purpose'            => 'List the real local Christmas/winter records a landing page could reuse (read-only).',
		'scope'              => 'read-only',
		'safety'             => 'Reads WordPress posts and Polylang links. Never writes.',
		'safety_level'       => 'read-only',
		'read_only'          => true,
		'writes'             => false,
		'production_capable' => false,
		'target_description' => 'the WordPress install it is executed against (local Docker WordPress by default)',
		'modes_description'  => "  --dry-run   The only mode (read-only).\n",
		'arguments'          => "  (no arguments)\n",
	)
);

$needles = array( 'christmas', 'natal', 'xmas', 'winter', 'inverno', 'solstice', 'new year', 'réveillon' );

/**
 * Find published records of one type whose title/excerpt/content match a needle.
 *
 * @param string   $post_type Post type.
 * @param string[] $needles   Lower-case needles.
 * @return array<int,array>
 */
function conexao_christmas_find( $post_type, array $needles ) {
	$posts = get_posts(
		array(
			'post_type'        => $post_type,
			'post_status'      => 'publish',
			'numberposts'      => 400,
			'suppress_filters' => true,
		)
	);

	$found = array();

	foreach ( $posts as $post ) {
		$haystack = strtolower( $post->post_title . ' ' . $post->post_excerpt . ' ' . $post->post_content );
		$hits     = array();

		foreach ( $needles as $needle ) {
			if ( false !== strpos( $haystack, $needle ) ) {
				$hits[] = $needle;
			}
		}

		if ( $hits ) {
			$found[] = array(
				'post'  => $post,
				'terms' => $hits,
			);
		}
	}

	return $found;
}

foreach ( array( 'event', 'guide', 'post', 'page', 'leisure' ) as $type ) {
	$matches = conexao_christmas_find( $type, $needles );

	printf( "\n== %s matches=%d ==\n", $type, count( $matches ) );

	foreach ( $matches as $match ) {
		$post = $match['post'];
		$lang = function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $post->ID ) : '-';
		$date = '';

		if ( 'event' === $type ) {
			$start = (string) get_post_meta( $post->ID, '_event_start_date', true );
			$end   = (string) get_post_meta( $post->ID, '_event_end_date', true );
			$date  = trim( $start . ( $end && $end !== $start ? ' → ' . $end : '' ) );
		}

		printf(
			"  %-7d %-3s %-46s %-18s %s\n",
			$post->ID,
			$lang,
			$post->post_name,
			$date,
			implode( ',', $match['terms'] )
		);
	}
}

echo "\n== Christmas event records with a December date ==\n";
$december = get_posts(
	array(
		'post_type'        => 'event',
		'post_status'      => 'publish',
		'numberposts'      => 400,
		'suppress_filters' => true,
		'meta_query'       => array(
			array(
				'key'     => '_event_start_date',
				'value'   => array( '2026-12-01', '2026-12-31' ),
				'compare' => 'BETWEEN',
				'type'    => 'DATE',
			),
		),
	)
);

foreach ( $december as $post ) {
	printf(
		"  %-7d %-46s %s\n",
		$post->ID,
		$post->post_name,
		(string) get_post_meta( $post->ID, '_event_start_date', true )
	);
}

echo "\nsummary: read-only probe complete\n";
