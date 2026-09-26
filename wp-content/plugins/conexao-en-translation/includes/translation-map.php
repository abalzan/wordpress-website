<?php
/**
 * Stage M authored English translation manifest.
 *
 * The ONLY portable identity is the PT slug (engineering standard §0.4). Local
 * post IDs are never used as cross-environment identity; a slug is the authored
 * stable key the shared rollout engine resolves.
 *
 * `en_content` is authored English, never a copy of the PT body: the
 * `test-guide-en-translation.php` contract explicitly asserts the EN body is not
 * the PT body and carries no Portuguese stop-words, and the same rule is applied
 * to every type this stage covers.
 *
 * The map is keyed by post type so one file carries all three B1 types and the
 * shared engine still gets one validated stage per type.
 *
 * @package Conexao_EN_Translation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The B1 post types this stage closes the EN completeness debt for.
 *
 * These are the types the permanent translation-completeness gate reports as
 * `missing_en`: `guide`, `page` and `post`. The B2 directory types
 * (event / leisure / sponsor / course_provider / job) are excluded here because
 * the documented B2 policy already covers them — adding them would create a
 * second, competing policy.
 *
 * @return string[]
 */
function conexao_en_translation_post_types(): array {
	return array( 'guide', 'page', 'post' );
}

/**
 * The authored English translations, keyed by PT post type then PT slug.
 *
 * The Blog (`post`) rows come from the separately versioned
 * `blog-translation-data.php`, so the Stage M record in `manifest-data.php`
 * stays a Stage M record while Stage N's Blog data keeps its own reviewable
 * file. Both halves are merged here into the single per-type manifest the
 * shared engine validates; the merge is a union keyed by PT slug, so a slug may
 * only ever appear once.
 *
 * @return array<string,array<string,array>>
 */
function conexao_en_translation_map(): array {
	$data = array();

	if ( function_exists( 'conexao_en_translation_manifest_data' ) ) {
		$data = conexao_en_translation_manifest_data();
	}

	if ( function_exists( 'conexao_en_translation_manifest_data_blog_v1' ) ) {
		$blog = conexao_en_translation_manifest_data_blog_v1();

		// Union, never overwrite: a duplicate PT slug across the two data files
		// is a manifest defect, and a blind array_merge would silently let the
		// later file win. Failing closed here keeps a second identity from ever
		// reaching the engine.
		foreach ( $blog as $pt_slug => $row ) {
			if ( isset( $data['post'][ $pt_slug ] ) ) {
				return $data;
			}
		}

		$data['post'] = isset( $data['post'] ) ? array_merge( $data['post'], $blog ) : $blog;
	}

	return $data;
}

/**
 * The manifest for one post type, in the shape the shared engine validates.
 *
 * @param string $post_type Post type.
 * @return array{source_lang:string,target_lang:string,records:array}
 */
function conexao_en_translation_manifest_for( string $post_type ): array {
	$map     = conexao_en_translation_map();
	$records = isset( $map[ $post_type ] ) ? $map[ $post_type ] : array();

	$out = array();

	foreach ( $records as $pt_slug => $row ) {
		$out[ (string) $pt_slug ] = array(
			'en_slug'             => (string) ( $row['en_slug'] ?? '' ),
			'en_title'            => (string) ( $row['en_title'] ?? '' ),
			'en_excerpt'          => (string) ( $row['en_excerpt'] ?? '' ),
			'en_content'          => (string) ( $row['en_content'] ?? '' ),
			'en_meta_description' => (string) ( $row['en_meta_description'] ?? '' ),
		);
	}

	return array(
		'source_lang' => 'pt',
		'target_lang' => 'en',
		'records'     => $out,
	);
}
