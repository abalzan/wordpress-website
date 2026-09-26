<?php
/**
 * Stage 7 — clone inventory dump (WP eval-file; clone-only).
 *
 * Writes the measurable record-level state used by the Stage 7 acceptance
 * matrix: per leisure record — clone ID, slug, title, status, the Portuguese
 * description (post_excerpt), the EN description meta, the identity fields
 * (`_leisure_uuid`, `_leisure_export_uuid` — read-only), language, permalink,
 * taxonomy names and whether a featured image is attached.
 *
 * Usage:
 *   php wp-cli.phar eval-file scripts/stage7-clone-inventory.php --allow-root --url=http://127.0.0.1:8765 -- <label>
 *
 * Output: stage7-work/clone-inventory-<label>.json
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	require_once dirname( __DIR__ ) . '/wp-load.php';
}

$label = 'before';
foreach ( (array) ( $args ?? array() ) as $arg ) {
	if ( is_string( $arg ) && '' !== trim( $arg, '-' ) ) {
		$label = preg_replace( '/[^a-z0-9_-]/i', '', $arg );
		break;
	}
}
if ( '' === $label ) {
	$label = 'before';
}

$posts = get_posts(
	array(
		'post_type'        => 'leisure',
		'post_status'      => 'any',
		'numberposts'      => -1,
		'lang'             => '',
		'suppress_filters' => true,
		'no_found_rows'    => true,
		'orderby'          => 'date',
		'order'            => 'DESC',
	)
);

$records = array();
foreach ( $posts as $post ) {
	$taxonomies = array();
	foreach ( array( 'conexao_category', 'conexao_county', 'conexao_tag', 'conexao_leisure_attribute' ) as $taxonomy ) {
		$terms = get_the_terms( $post->ID, $taxonomy );
		$taxonomies[ $taxonomy ] = ( $terms && ! is_wp_error( $terms ) ) ? wp_list_pluck( $terms, 'name' ) : array();
	}

	$records[] = array(
		'id'             => (int) $post->ID,
		'slug'           => (string) $post->post_name,
		'title'          => (string) $post->post_title,
		'status'         => (string) $post->post_status,
		'date'           => (string) $post->post_date,
		'permalink'      => (string) get_permalink( $post->ID ),
		'pt_excerpt'     => (string) $post->post_excerpt,
		'en_excerpt'     => (string) get_post_meta( $post->ID, '_leisure_excerpt_en', true ),
		'uuid'           => (string) get_post_meta( $post->ID, '_leisure_uuid', true ),
		'export_uuid'    => (string) get_post_meta( $post->ID, '_leisure_export_uuid', true ),
		'language'       => function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $post->ID, 'slug' ) : '',
		'has_thumbnail'  => has_post_thumbnail( $post->ID ),
		'taxonomies'     => $taxonomies,
	);
}

$out = array(
	'label'       => $label,
	'captured_at' => gmdate( 'c' ),
	'total'       => count( $records ),
	'with_en'     => count(
		array_filter(
			$records,
			static function ( $record ) {
				return '' !== trim( $record['en_excerpt'] );
			}
		)
	),
	'records'     => $records,
);

$file = dirname( __DIR__ ) . '/stage7-work/clone-inventory-' . $label . '.json';
file_put_contents( $file, wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" );

echo "clone inventory written: {$file}\n";
echo 'total leisure records: ' . $out['total'] . ', with EN description: ' . $out['with_en'] . "\n";
