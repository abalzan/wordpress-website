#!/usr/bin/env php
<?php
/**
 * dump-stage-manifests.php — dump the repository's OWN EN stage manifests.
 *
 * Read-only. Executes `conexao-en-translation`'s data and config files in PHP
 * and prints their resolved shape as JSON, so the read-only inventory/dry-run
 * tools join against the repository's real authored data instead of a
 * re-invented list.
 *
 * It also dumps the SHARED-SLUG POLICY per stage, read from
 * `conexao_en_translation_shared_page_slug_for()` — the single source of truth
 * declared by each stage's own manifest. The consumers therefore never carry a
 * second, hand-maintained list of which stages hold the permit, so the policy
 * cannot silently drift away from the code.
 *
 * Usage: php dump-stage-manifests.php > 00-repository-stage-manifests.json
 *
 * @package Conexao_EN_Translation_Tooling
 */

define( 'ABSPATH', __DIR__ . '/' );

$root = dirname( __DIR__, 3 );
$inc  = $root . '/wp-content/plugins/conexao-en-translation/includes/';

foreach (
	array(
		'manifest-data',
		'guide-translation-data',
		'guide-terms-data',
		'blog-translation-data',
		'blog-page-data',
		'jobs-page-data',
		'leisure-description-data',
		'course-provider-description-data',
		'translation-map',
		'stage-fields',
		'stage-config',
	) as $file
) {
	require_once $inc . $file . '.php';
}

/**
 * The shared-slug permit each registered stage holds, read from the repository.
 *
 * @return array<string,string> stage id => declared shared slug ('' when none).
 */
function conexao_dump_shared_slug_policy(): array {
	$policy = array();

	foreach ( conexao_en_translation_stage_ids() as $stage ) {
		$policy[ $stage ] = conexao_en_translation_shared_page_slug_for( $stage );
	}

	return $policy;
}

$map = conexao_en_translation_map();

$out = array(
	'b1_types'           => array(
		'guide' => conexao_en_translation_manifest_for( 'guide' ),
		'page'  => conexao_en_translation_manifest_for( 'page' ),
		'post'  => conexao_en_translation_manifest_for( 'post' ),
	),
	'guide_terms'        => conexao_en_translation_guide_terms_v1(),
	'blog_page'          => conexao_en_translation_blog_page_manifest(),
	'jobs_page'          => conexao_en_translation_jobs_page_manifest(),
	'leisure_desc'       => conexao_en_translation_leisure_description_data_v1(),
	'course_desc'        => conexao_en_translation_course_provider_description_data_v1(),
	'stage_ids'          => conexao_en_translation_stage_ids(),
	'raw_guide'          => array_keys( isset( $map['guide'] ) ? $map['guide'] : array() ),
	'raw_manifest'       => array(
		'guide' => array_keys( isset( $map['guide'] ) ? $map['guide'] : array() ),
		'page'  => array_keys( isset( $map['page'] ) ? $map['page'] : array() ),
		'post'  => array_keys( isset( $map['post'] ) ? $map['post'] : array() ),
	),
	'raw_blog'           => array_keys( conexao_en_translation_manifest_data_blog_v1() ),
	'batches'            => conexao_en_translation_blog_batches(),
	// Read from the repository, never re-declared here.
	'shared_slug_policy' => conexao_dump_shared_slug_policy(),
);

echo json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), "\n";
