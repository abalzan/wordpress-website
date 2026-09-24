<?php
/**
 * Stage 6 — Job EN translation map (human-authored English).
 *
 * The deterministic source of truth for the EN Jobs rollout: one entry per
 * eligible public Portuguese `job` record, keyed by the Portuguese slug.
 * Every entry carries the natural English slug/title/excerpt/body and meta
 * description authored from the real production record (see
 * stage6-work/job-inventory.{json,md}), plus the field policy the apply
 * engine enforces.
 *
 * Field policy (the full audit lives in CONEXAO_BR_ENGLISH_JOBS_TRANSLATION_REPORT.md §5):
 *   - TRANSLATED (from this map): post_title, post_content, post_excerpt,
 *     conexao_meta_description — the visible/SEO text layer;
 *   - COPIED VERBATIM from the PT record at run time: every `_job_*` meta key
 *     actually stored (`_job_company`, `_job_location`, `_job_salary`,
 *     `_job_employment_type`, `_job_expiration_date`, `_job_application_url`,
 *     `_job_closing_date`, `_job_source`, `_job_status`, `_job_requirements`)
 *     — company names are proper nouns, locations are Irish place names,
 *     URLs/dates/controlled values are language-neutral, and every one of
 *     them is EMPTY in the measured production data; `_thumbnail_id` (media
 *     is shared — Polylang `media_support = 0`); post_date / post_author /
 *     menu_order (record identity).
 *   - NEVER TOUCHED: the PT record itself (verified by the PT snapshot gate),
 *     the Jobs landing Page (Stage 4.5 owns it), any other CPT.
 *
 * The `job` CPT has NO importer/source-identity meta (verified: no
 * `_job_source_id`, no UUID, no external ID anywhere in the data model — job
 * records are manual). Deduplication identity is therefore the Polylang pair
 * itself plus the EN slug uniqueness probe; the apply engine hard-fails on
 * any duplicate.
 *
 * @package Conexao_Job_Translation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Stage 6 Job translation manifest.
 *
 * @return array{
 *     source: array<string,string>,
 *     fields: array<string,array>,
 *     jobs: array<string,array>,
 *     excluded: array
 * }
 */
function conexao_job_translation_manifest(): array {
	return array(
		'source'  => array(
			'post_type'        => 'job',
			'jobs_page_pt_slug' => 'empregos',
			'jobs_page_en_slug' => 'jobs',
		),
		'fields'  => array(
			'translate' => array( 'title', 'content', 'excerpt', 'meta_desc' ),
			'copy'      => array(
				'_job_company',
				'_job_location',
				'_job_salary',
				'_job_employment_type',
				'_job_expiration_date',
				'_job_application_url',
				'_job_closing_date',
				'_job_source',
				'_job_status',
			),
			// Text-bearing when present (empty in the measured production data);
			// an EN value in `en_meta` wins, otherwise the PT value is copied and
			// the completeness scanner flags it for review.
			'translate_if_present' => array( '_job_requirements' ),
		),
		'jobs'    => array(
			'oportunidades' => array(
				'en_slug'             => 'opportunities',
				'en_title'            => 'Opportunities',
				'en_excerpt'          => 'At Conexão BR Irlanda, we share new job opportunities every day — and finding openings near you just got even easier! In our Instagram Highlights, the openings are grouped by County…',
				'en_meta_description' => 'New job opportunities every day for the Brazilian community in Ireland — browse the openings grouped by county on the Conexão BR Irlanda Instagram.',
				'en_content'          => '<p class="wp-block-paragraph">At Conexão BR Irlanda, we share new job opportunities every day — and finding openings near you just got even easier! 📍</p>
<p class="wp-block-paragraph">👉 In our Instagram Highlights, the openings are grouped by County, making it easier to search the region where you live or plan to work.</p>
<p class="wp-block-paragraph">📌 Update your résumé<br>📌 Check the Highlights by County<br>📌 Follow the new openings daily<br>📌 Browse our free Guides to find out where else to look for work in Ireland</p>
<p class="wp-block-paragraph">🌐 More information on our Instagram — <a href="https://www.instagram.com/reel/Dcd0YXqMrEK/?igsi=M3VrZm04aHV4czN6">click here</a></p>
<p class="wp-block-paragraph">💚 Save this post and share it with a friend who is also looking for an opportunity in Ireland.</p>
<p class="wp-block-paragraph"></p>
<p class="wp-block-paragraph">#JobsInIreland #JobOpeningsIreland #BraziliansInIreland #WorkInIreland #ConexaoBRIrlanda #JobVacancies #IrelandJobs #BraziliansInEurope</p>
<p class="wp-block-paragraph"></p>',
				'en_meta'             => array(),
				'link_map'            => array(),
				'translator_notes'    => 'Instagram reel URL copied verbatim (external application/CTA link). Hashtags localised to their natural English community forms. "Destaques do Instagram" = "Instagram Highlights" (the product surface name). "Guias Gratuitos" = "free Guides" (the site\'s own Guides section label).',
			),
		),
		'excluded' => array(),
	);
}
