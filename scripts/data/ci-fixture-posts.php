<?php
/**
 * CI FIXTURE DATASET — synthetic PT `post` (Blog) records (Stage P, CI readiness).
 *
 * The `/blog/` archive paginates at 10 records per page, so the maintained
 * acceptance rows `en-archive-en-blog-page-2` (/en/blog/page/2/ must be 200) and
 * the PT equivalent need at least 11 published posts on a database built from
 * nothing. This dataset supplies them.
 *
 * IDENTITY: the PT slug, and nothing else (engineering standard 0.4).
 *
 * WHY THESE SPECIFIC SLUGS: every one is a row of the AUTHORED English manifest
 * in `wp-content/plugins/conexao-en-translation/includes/blog-translation-data.php`.
 * The `en-post` stage of the SHARED translation-rollout engine resolves records
 * by PT slug, so a post created under any other slug would be a B1 record with
 * no authored English and would break translation completeness for a reason
 * unrelated to the code under test. These fixtures create the PT half of pairs
 * whose EN half the repository already ships.
 *
 * PT CONTENT IS NEVER FROZEN: the `en-post` stage snapshots each PT record and
 * fails the numeric gate on PT drift. The excerpt/body below are therefore
 * written once and never edited afterwards, so the snapshot taken on the first
 * run stays valid on every later run.
 *
 * DATES ARE DETERMINISTIC: fixed, distinct publication dates rather than
 * "now", so `/blog/` ordering and pagination are identical on every fresh run.
 * They are historical and never in the future, so no acceptance row can start
 * depending on the current date.
 *
 * @package Conexao_BR_Scripts
 */

defined( 'ABSPATH' ) || defined( 'CONEXAO_SCRIPTS_BOOTSTRAP' ) || exit;

/**
 * The synthetic PT blog posts, keyed by PT slug (the stable key).
 *
 * @return array<string,array{title:string,excerpt:string,content:string,date:string}>
 */
function conexao_ci_fixture_posts(): array {
	$rows = array();

	// Ordered PT slugs, read from the AUTHORED manifest itself
	// (`conexao_en_translation_manifest_data_blog_v1()`) rather than
	// hand-copied into this file, and each record's publication date is DERIVED
	// from its position rather than hand-written.
	//
	// Reading the manifest is a CORRECTNESS FIX, not a style choice. An earlier
	// version carried a hand-typed list extracted with a regex over the whole
	// data file — and that regex also matched the KEYS of a SECOND function in
	// the same file, `conexao_en_translation_blog_batches()`, whose
	// "batch-1" … "batch-6" entries are BATCH LABELS holding a list of child
	// slugs, not posts. Six phantom fixtures were created that no English
	// manifest row can ever pair with, which made
	// `test-blog-en-translation.php` fail its real contract that every public PT
	// post has a published EN translation. Reading the manifest function
	// directly makes that entire class of mistake impossible, and keeps exactly
	// ONE list of PT slugs in the repository.
	//
	// Deriving the date from the index is the same kind of fix: a hand-written
	// list of 40 dates is a list that can contain an impossible day, and
	// `2026-01-35` was exactly that bug when the dataset was first written —
	// WordPress rejected the insert with the opaque "Data inválida." Real date
	// arithmetic makes that class of bug impossible too.
	$list = array();
	if ( function_exists( 'conexao_en_translation_manifest_data_blog_v1' ) ) {
		$list = array_keys( conexao_en_translation_manifest_data_blog_v1() );
	}

	// The epoch is a fixed, historical, valid date, and each record is one
	// further day after the previous one. One day apart keeps the /blog/
	// ordering total and unambiguous, and keeps every record comfortably in
	// the past, so no acceptance row can start depending on the current date.
	$epoch = '2026-01-01 09:00:00';
	$base  = strtotime( $epoch );

	foreach ( $list as $index => $slug ) {
		// gmdate() on a rolled timestamp is what rolls the month over correctly:
		// day 32 of the sequence becomes 1 February, not an impossible date.
		$date = gmdate( 'Y-m-d H:i:s', $base + ( $index * DAY_IN_SECONDS ) );

		$rows[ $slug ] = array(
			'title'   => conexao_ci_fixture_post_title( $slug ),
			'excerpt' => conexao_ci_fixture_post_excerpt( $slug ),
			'content' => conexao_ci_fixture_post_content( $slug ),
			'date'    => $date,
		);
	}

	return $rows;
}

/**
 * Deterministic Portuguese title for a fixture post.
 *
 * @param string $slug PT slug.
 * @return string
 */
function conexao_ci_fixture_post_title( string $slug ): string {
	return 'Blog CI: ' . ucwords( implode( ' ', explode( '-', $slug ) ) );
}

/**
 * Deterministic Portuguese excerpt.
 *
 * @param string $slug PT slug.
 * @return string
 */
function conexao_ci_fixture_post_excerpt( string $slug ): string {
	return sprintf(
		'Conteúdo sintético de teste automático para o artigo "%s". Gerado pela arvore de fixtures determinísticas da CI; não é conteúdo editorial.',
		$slug
	);
}

/**
 * Deterministic Portuguese body.
 *
 * @param string $slug PT slug.
 * @return string
 */
function conexao_ci_fixture_post_content( string $slug ): string {
	return sprintf(
		'<!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Este é um artigo sintético criado apenas pela arvore de fixtures determinísticas da integração contínua, para que /blog/ e /en/blog/ tenham conteúdo suficiente para exercitar paginação e a camada de tradução. Não é conteúdo editorial.</p><!-- /wp:paragraph -->',
		esc_html( conexao_ci_fixture_post_excerpt( $slug ) )
	);
}
