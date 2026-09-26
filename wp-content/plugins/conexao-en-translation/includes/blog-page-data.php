<?php
/**
 * STAGE O — authored English for the Blog POSTS PAGE (the `page` record).
 *
 * Version: blog-page-v1.
 *
 * This is a deliberately SEPARATE, single-record data structure from the two
 * datasets that already exist in this plugin:
 *
 *   - `manifest-data.php`        (Stage M: `guide` + `page`, 30 records)
 *   - `blog-translation-data.php`(Stage N: the 42 `post` records)
 *
 * The 42 Blog POST rows are never mixed into this file and this Blog PAGE row
 * is never mixed into theirs. They are different post types, different
 * identities and different lifecycle stages; merging them into one opaque blob
 * would make a single reviewable unit impossible and would risk an EN change
 * to one silently rewriting the other.
 *
 * ## Why this record exists
 *
 * `/en/blog/` exists today only through the B2 fallback path: the Portuguese
 * `blog` posts-page record is on `conexao_b2_page_allowlist()`, so the route
 * serves the PT posts under the EN URL with the B2 notice. The documented
 * retirement condition (Stage 5, `inc/b2-fallback.php`) is *"a real, published
 * EN posts page"*. Creating this one linked EN page is what retires it.
 *
 * ## Why the EN slug is the SAME slug (`blog`)
 *
 * The approved route shape is `/blog/` ↔ `/en/blog/`: one canonical path in
 * two languages, not two translated slugs. The EN page therefore intentionally
 * reuses the Portuguese `post_name`. WordPress makes page slugs unique per
 * tree, so the shared slug needs an explicit `wp_unique_post_slug` filter in
 * the stage adapter — see `conexao_en_translation_blog_page_adapter()` in
 * `stage-config.php`. The filter is scoped to this one record and is removed
 * again immediately after the write.
 *
 * ## Editorial rules for this row
 *
 *   - the EN body is authored English, never a copy of the PT body;
 *   - the archive itself is template-driven (`home.php` renders the heading,
 *     the description and the category filter), so the page body is a short
 *     archive introduction only — no fabricated sections, no invented links;
 *   - the EN title is the same proper name as the PT one ("Blog" is a label
 *     that does not translate);
 *   - the EN meta description is authored English with no untranslated
 *     Portuguese, exactly like every other row in this plugin.
 *
 * Identity is the **PT page slug** — the only portable identity the
 * engineering standard allows (§0.4). No local post ID appears here.
 *
 * @package Conexao_EN_Translation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The version label of this data file.
 *
 * Recorded so the apply output, the report and the evidence can name the exact
 * dataset that produced the EN posts page, and so a future revision is a new
 * version rather than a silent edit of this one.
 *
 * @return string Version label.
 */
function conexao_en_translation_blog_page_version(): string {
	return 'blog-page-v1';
}

/**
 * The authored English translation of the Blog posts page.
 *
 * Keyed by the PT page slug. Exactly one record: the Blog archive is a routing
 * object, not an editorial page, so there is nothing else to translate.
 *
 * @return array<string,array<string,string>>
 */
function conexao_en_translation_blog_page_data(): array {
	return array(
		'blog' => array(
			// The approved /en/blog/ shape: the same path, language-prefixed.
			'en_slug'             => 'blog',
			'en_title'            => 'Blog',
			'en_excerpt'          => '',
			'en_meta_description' => 'The Conexão BR Irlanda blog: articles, news and practical information for Brazilians living in Ireland.',
			// Template-driven archive: a short English introduction, authored
			// here and deliberately NOT a copy of the Portuguese body.
			'en_content'          => '<!-- wp:paragraph --><p>Articles and news for the Brazilian community in Ireland, with practical information to help you live, work and settle in here.</p><!-- /wp:paragraph -->
',
		),
	);
}

/**
 * The Blog posts page manifest, in the shape the shared engine validates.
 *
 * @return array{source_lang:string,target_lang:string,records:array}
 */
function conexao_en_translation_blog_page_manifest(): array {
	$out = array();

	foreach ( conexao_en_translation_blog_page_data() as $pt_slug => $row ) {
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
