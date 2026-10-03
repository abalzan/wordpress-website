<?php
/**
 * EN JOBS LANDING PAGE — authored English for the `/empregos/` page, the
 * second single-record page dataset in this plugin (the first is the Stage O
 * Blog posts page in `blog-page-data.php`).
 *
 * Version: jobs-page-v1.
 *
 * ## What this stage changes, and why it exists
 *
 * Every other EN route in the `/en/` layer reuses the PORTUGUESE path with an
 * `/en/` prefix — `/en/guias/`, `/en/eventos/`, `/en/lazer/`, `/en/cursos/`,
 * `/en/apoiadores/`, `/en/blog/`. That is the documented rule
 * (`docs/routing.md`): "English URLs wrap the same paths in `/en/`".
 *
 * The EN Jobs landing page was the single exception. It was created in Stage
 * 3.2 with the EN slug `jobs` (`/en/jobs/`), so it was the only `/en/` route
 * that did NOT mirror its PT path — and `/en/empregos/` (the PT-derived path,
 * and the path every EN job SINGLE already uses) answered 302 → `/empregos/`.
 *
 * This stage makes the EN landing page reuse the PT `empregos` post_name, so
 * `/en/empregos/` becomes the real English landing page. The approved route
 * shape becomes `/empregos/` ↔ `/en/empregos/`, matching `/blog/` ↔
 * `/en/blog/` and the PT structure (where `/empregos/` is a page AND
 * `/empregos/{slug}/` are job singles, side by side).
 *
 * ## Why the EN slug is the SAME slug (`empregos`)
 *
 * The approved route shape is one canonical path in two languages, not two
 * translated slugs — exactly the Stage O `blog` precedent. The EN page
 * therefore intentionally reuses the Portuguese `post_name`. WordPress makes
 * page slugs unique per tree, so the shared slug needs the scoped
 * `wp_unique_post_slug` permit that the stage adapter arms — the SAME
 * mechanism, and the same transient-scoped filter, that `blog-page-data.php`
 * documents. Without it WordPress silently renames the EN page to
 * `empregos-2` and breaks the route.
 *
 * ## Identity, and what this stage never touches
 *
 * Identity is the **PT page slug** `empregos` — the only portable identity
 * the engineering standard allows (§0.4). No local post ID appears here.
 *
 * This stage writes ONE field on ONE EN record: `post_name`. The EN title,
 * body, excerpt and meta description below are the record's CURRENT,
 * already-authored English values, carried verbatim so that repairing the
 * slug through the engine's `update` path cannot silently rewrite content.
 * The PT page is only ever read.
 *
 * @package Conexao_EN_Translation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The version label of this data file.
 *
 * Recorded so the apply output, the report and the evidence can name the exact
 * dataset that produced the EN Jobs landing page, and so a future revision is
 * a new version rather than a silent edit of this one.
 *
 * @return string Version label.
 */
function conexao_en_translation_jobs_page_version(): string {
	return 'jobs-page-v1';
}

/**
 * The EN Jobs landing page, in the shape the shared engine validates.
 *
 * Exactly one record, keyed by the PT page slug. The `en_*` values are the
 * EN record's existing authored English, carried verbatim: this stage changes
 * the EN `post_name` and nothing else.
 *
 * @return array{source_lang:string,target_lang:string,records:array}
 */
function conexao_en_translation_jobs_page_manifest(): array {
	return array(
		'source_lang' => 'pt',
		'target_lang' => 'en',
		'records'     => array(
			'empregos' => array(
				// The approved /en/empregos/ shape: the same path, language-prefixed.
				'en_slug'             => 'empregos',
				'en_title'            => 'Jobs',
				'en_excerpt'          => '',
				'en_meta_description' => 'The latest job openings are on our Instagram. Follow our posts to find new work opportunities in Ireland.',
				// Carried verbatim from the existing EN record: this stage repairs
				// the slug only and must not rewrite authored content. Written as
				// a single line with no trailing newline so the stored value stays
				// byte-identical to the EN record's existing body.
				'en_content'          => '<!-- wp:paragraph --><p>Our latest job openings are on our Instagram.<br>Follow our posts to find new work opportunities in Ireland.</p><!-- /wp:paragraph -->',
			),
		),
	);
}
