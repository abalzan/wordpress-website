<?php
/**
 * Stage 6 — Job EN translation (in-process checks).
 *
 * Covers the completion gate and the content/architecture contract of the Job
 * translation (the HTTP matrix — rendering, canonical, hreflang, B2 transition —
 * is covered by scripts/stage6-job-verify.py; the landing-listing membership is
 * asserted in-process below, because the «Vagas»/"Openings" preview section was
 * removed from the landing pages — rendering only — so its card checks there are
 * SKIPped):
 *
 *  - the Jobs landing Page pair (PT `empregos` ↔ EN `jobs`) exists, is
 *    published, linked from both sides and keeps template parity (Stage 4.5
 *    owns the pages — this stage never creates them);
 *  - `job` REMAINS a B2 fallback post type. This is the documented current
 *    contract (docs/routing.md "Job singles", and `conexao_b2_post_types()` in
 *    inc/i18n/fallback.php, which lists `job`): real EN job records may exist at
 *    /en/empregos/{en-slug}/, but a PT job with no EN translation is NOT a
 *    failure — it is answered by the approved B2 fallback (the PT body under the
 *    EN shell + notice). The authoritative translation-completeness gate
 *    (tests/test-translation-completeness.php) applies exactly the same
 *    exemption: `post_type:job:missing_en` is exempt by B2 policy, unlike B1
 *    `guide`/`post` where a real EN record is required.
 *
 *    Consequently this suite no longer asserts a B1-style
 *    "every PT job has an EN translation" gate. It asserts the B2 contract
 *    instead: a job with no EN translation stays B2-eligible and is never given
 *    a fabricated orphan EN record, while a job that DOES have a real EN
 *    translation is no longer B2-eligible and keeps the full verbatim
 *    translation-quality invariants below.
 *  - no EN job exists without its PT sibling (no second identities);
 *  - EN jobs keep the shared media, date, author and menu order of their PT
 *    sibling, carry the verbatim `_job_*` layer, and their body is genuinely
 *    English (not a copy of the PT body);
 *  - B2 state machine: a translated PT job is no longer B2-eligible, while a
 *    freshly created untranslated PT job still is (future-job behaviour);
 *  - the Portuguese originals are untouched: still `pt`, slugs unchanged,
 *    bodies unchanged, `_job_*` meta unchanged.
 *
 * Usage (from the project root / the WordPress root):
 *   php wp-content/themes/conexao-br-irlanda/tests/test-job-en-translation.php
 *
 * Read-only (a throwaway probe job is created and force-deleted inside the
 * B2 check; nothing else is written). Requires Polylang + a completed Job
 * translation run.
 *
 * @package conexao-br-irlanda
 */


// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$passed = 0;
$failed = 0;


test_prerequisite_hint( 'polylang' );
test_require( function_exists( 'pll_get_post' ), 'polylang', 'test prerequisite is available: function_exists( pll_get_post )', 'activate the Polylang plugin' );
// ---------------------------------------------------------------------------

$pt_page = get_page_by_path( 'empregos', OBJECT, 'page' );
assert_true( $pt_page instanceof WP_Post, 'the PT Empregos landing page exists' );

$pt_page_id = $pt_page instanceof WP_Post ? (int) $pt_page->ID : 0;
$en_page_id = $pt_page_id > 0 ? (int) pll_get_post( $pt_page_id, 'en' ) : 0;
assert_true( $en_page_id > 0 && $en_page_id !== $pt_page_id, 'the Jobs page has an EN translation', "en_id={$en_page_id}" );
assert_true( $en_page_id > 0 && (int) pll_get_post( $en_page_id, 'pt' ) === $pt_page_id, 'the Jobs page pair is linked from both sides' );
assert_true( 'publish' === get_post_status( $en_page_id ), 'the EN Jobs page is published' );
assert_true( $en_page_id > 0 && 'en' === pll_get_post_language( $en_page_id, 'slug' ), 'the EN Jobs page is assigned to en' );
assert_true( $pt_page_id > 0 && 'pt' === pll_get_post_language( $pt_page_id, 'slug' ), 'the PT Jobs page is still assigned to pt' );
// Since the EN Jobs URL consistency change the EN Jobs page reuses the PT
// `empregos` post_name, so it is a shared-slug pair like /en/blog/ and
// resolves at /en/empregos/. The former /en/jobs/ slug is retired.
assert_true(
	$en_page_id > 0 && get_permalink( $en_page_id ) === trailingslashit( home_url( '/en/empregos/' ) ),
	'the EN Jobs page lives at /en/empregos/ (shared-slug pair with PT)',
	$en_page_id > 0 ? (string) get_permalink( $en_page_id ) : ''
);
assert_true(
	$pt_page_id > 0 && $en_page_id > 0 && get_page_template_slug( $pt_page_id ) === get_page_template_slug( $en_page_id ),
	'Jobs page template parity (page-empregos.php on both)',
	$pt_page_id > 0 && $en_page_id > 0 ? get_page_template_slug( $pt_page_id ) . ' vs ' . get_page_template_slug( $en_page_id ) : ''
);

// ---------------------------------------------------------------------------

$base  = array(
	'post_type'      => 'job',
	'post_status'    => 'publish',
	'posts_per_page' => -1,
	'fields'         => 'ids',
	'orderby'        => 'ID',
	'order'          => 'ASC',
);
$pt_ids = get_posts( array_merge( $base, array( 'lang' => 'pt' ) ) );
$en_ids = get_posts( array_merge( $base, array( 'lang' => 'en' ) ) );

assert_true( count( $pt_ids ) > 0, 'the site has public PT jobs', (string) count( $pt_ids ) );

// `job` is a B2 fallback post type (documented, see the file header and
// inc/i18n/fallback.php::conexao_b2_post_types()). The EN collection is
// therefore NOT required to be the same size as the PT collection: a PT job
// with no EN translation is answered by the approved B2 fallback. What IS
// required — and what a B1 post type such as `guide` would additionally
// demand — is that no EN job exists without its PT sibling (asserted below).
assert_true(
	function_exists( 'conexao_is_b2_post_type' ) && conexao_is_b2_post_type( 'job' ),
	'the job CPT is still a B2 fallback post type (documented contract preserved)'
);

// Split the PT set into the two documented populations. Only a job that HAS a
// real EN translation is subject to the translation-quality invariants below;
// a job without one is a legitimate B2 record and must be left alone.
$translated_pt   = array();
$b2_only_pt      = array();
$missing_en      = array();
foreach ( $pt_ids as $pt_id ) {
	$en_id = (int) pll_get_post( (int) $pt_id, 'en' );
	if ( $en_id > 0 && 'publish' === get_post_status( $en_id ) ) {
		$translated_pt[] = (int) $pt_id;
	} else {
		$b2_only_pt[] = (int) $pt_id;
		$missing_en[]  = get_post_field( 'post_name', $pt_id );
	}
}

assert_true(
	! empty( $translated_pt ),
	'at least one PT job has a real EN translation (the real-translation path is exercised)',
	'translated=' . count( $translated_pt )
);

// B2 CONTRACT: a PT job with no EN translation is served by the approved
// fallback, so it MUST remain B2-eligible and MUST NOT have been given a
// fabricated/orphan EN record. Job #10954 (`oportunidades`) is exactly this
// case and is a real, long-standing record — no EN content is created for it.
//
// conexao_should_render_b2_fallback() is request-scoped: it only answers true
// when the REQUESTED language is `en` (a PT request never renders the PT body
// as a "fallback" of itself). A CLI process has no HTTP request, so the EN
// request context is entered the same way the Stage 3.2 suite does it
// (tests/test-stage32-bilingual.php): by setting Polylang's current language.
// The previous language is always restored afterwards.
$saved_curlang = ( function_exists( 'PLL' ) && PLL() ) ? PLL()->curlang : null;
if ( function_exists( 'PLL' ) && PLL() ) {
	PLL()->curlang = PLL()->model->get_language( 'en' );
}

foreach ( $b2_only_pt as $b2_id ) {
	$slug = get_post_field( 'post_name', $b2_id );
	assert_true(
		conexao_should_render_b2_fallback( (int) $b2_id ),
		"B2: untranslated PT job '{$slug}' remains B2-eligible (PT content under the EN shell + notice)",
		"id={$b2_id}"
	);
	assert_true(
		'pt' === pll_get_post_language( (int) $b2_id, 'slug' ),
		"B2: untranslated PT job '{$slug}' stays assigned to pt (PT remains canonical)",
		"id={$b2_id}"
	);
	assert_true(
		0 === (int) pll_get_post( (int) $b2_id, 'en' ),
		"B2: no orphan EN record is fabricated for '{$slug}'",
		"id={$b2_id}"
	);
}

if ( $saved_curlang ) {
	PLL()->curlang = $saved_curlang;
}
// An explicitly documented B2 population is a valid state, not a defect:
// record it so the report shows the split rather than hiding it.
if ( ! empty( $b2_only_pt ) ) {
	echo '  NOTE: ' . count( $b2_only_pt ) . ' PT job(s) have no EN translation and are served by B2 fallback by policy: '
		. implode( ', ', $missing_en ) . "\n";
}

$not_linked    = array();
$not_english   = array();
$copied_body   = array();
$pt_changed    = array();
$meta_drift    = array();
$media_mismatch = array();
$date_mismatch = array();
$bad_en_slug   = array();

$strip = static function ( $html ) {
	return preg_replace( '/\s+/', ' ', trim( wp_strip_all_tags( (string) $html ) ) );
};

// Translation-quality invariants apply ONLY to the jobs that actually have a
// real EN translation ($translated_pt). A B2-only job has no EN record to
// inspect, so it was already accounted for by the B2 assertions above.
foreach ( $translated_pt as $pt_id ) {
	$pt    = get_post( $pt_id );
	$en_id = (int) pll_get_post( (int) $pt_id, 'en' );

	if ( (int) pll_get_post( $en_id, 'pt' ) !== (int) $pt_id ) {
		$not_linked[] = $pt->post_name;
	}

	$en = get_post( $en_id );

	if ( $en->post_name === $pt->post_name || preg_match( '/-2$/', $en->post_name ) || preg_match( '/-en$/', $en->post_name ) ) {
		$bad_en_slug[] = $en->post_name;
	}

	if ( $strip( $en->post_content ) === $strip( $pt->post_content ) && '' !== $strip( $pt->post_content ) ) {
		$copied_body[] = $pt->post_name;
	}

	if ( preg_match( '/(compartilhamos|vagas estão|seu currículo|Destaques do Instagram|procurando uma oportunidade)/iu', wp_strip_all_tags( $en->post_content ) ) ) {
		$not_english[] = $pt->post_name;
	}

	if ( 'pt' !== pll_get_post_language( (int) $pt_id, 'slug' ) ) {
		$pt_changed[] = $pt->post_name;
	}

	if ( 'en' !== pll_get_post_language( $en_id, 'slug' ) ) {
		$pt_changed[] = $en->post_name . ' (EN not en)';
	}

	// The verbatim `_job_*` layer must be identical on both records.
	foreach ( array_keys( (array) get_post_meta( $pt_id ) ) as $key ) {
		$key = (string) $key;
		if ( 0 !== strpos( $key, '_job_' ) ) {
			continue;
		}
		if ( get_post_meta( $pt_id, $key, true ) !== get_post_meta( $en_id, $key, true ) ) {
			$meta_drift[] = $pt->post_name . ':' . $key;
		}
	}

	if ( (int) get_post_thumbnail_id( $pt_id ) !== (int) get_post_thumbnail_id( $en_id ) ) {
		$media_mismatch[] = $pt->post_name;
	}

	if ( $pt->post_date !== $en->post_date ) {
		$date_mismatch[] = $pt->post_name;
	}
}

// Every job that HAS an EN translation must have a real, published, linked one
// (the `continue` branch that used to collect untranslated jobs into
// $missing_en is gone: for a B2 type, "no EN translation" is a valid state, not
// a missing translation). The B2 population was asserted above.
assert_true(
	count( $translated_pt ) + count( $b2_only_pt ) === count( $pt_ids ),
	'every public PT job is accounted for: either a real EN translation or the documented B2 fallback'
);
assert_true( 0 === count( $not_linked ), 'every EN job is linked back to its PT job', implode( ', ', $not_linked ) );
assert_true( 0 === count( $copied_body ), 'EN bodies are translations, not copies of the PT body', implode( ', ', $copied_body ) );
assert_true( 0 === count( $not_english ), 'EN bodies contain no Portuguese prose', implode( ', ', $not_english ) );
assert_true( 0 === count( $pt_changed ), 'PT originals still pt / EN records still en', implode( ', ', $pt_changed ) );
assert_true( 0 === count( $meta_drift ), 'the _job_* meta layer is verbatim-identical on PT and EN', implode( ', ', $meta_drift ) );
assert_true( 0 === count( $media_mismatch ), 'EN jobs share the PT featured image', implode( ', ', $media_mismatch ) );
assert_true( 0 === count( $date_mismatch ), 'EN jobs keep the PT publication date', implode( ', ', $date_mismatch ) );
assert_true( 0 === count( $bad_en_slug ), 'EN slugs are natural (no -2 / -en suffixes, no PT slug reuse)', implode( ', ', $bad_en_slug ) );


// No EN job without a PT sibling.
$en_orphans = array();
foreach ( $en_ids as $en_id ) {
	$pt_sibling = (int) pll_get_post( (int) $en_id, 'pt' );
	if ( $pt_sibling <= 0 ) {
		$en_orphans[] = get_post_field( 'post_name', $en_id );
	}
}
assert_true( 0 === count( $en_orphans ), 'no EN job without a PT sibling', implode( ', ', $en_orphans ) );

// No duplicate pairs: every EN job maps to a distinct PT job.
$seen_pt = array();
foreach ( $en_ids as $en_id ) {
	$pt_sibling = (int) pll_get_post( (int) $en_id, 'pt' );
	if ( $pt_sibling > 0 ) {
		$seen_pt[ $pt_sibling ] = isset( $seen_pt[ $pt_sibling ] ) ? $seen_pt[ $pt_sibling ] + 1 : 1;
	}
}
$dupes = array();
foreach ( $seen_pt as $pt => $n ) {
	if ( $n > 1 ) {
		$dupes[] = $pt;
	}
}
assert_true( 0 === count( $dupes ), 'no PT job has two EN translations', implode( ', ', array_map( 'strval', $dupes ) ) );

// ---------------------------------------------------------------------------

// "A translated PT job is no longer B2-eligible (real EN wins)". This must be
// evaluated on a job that ACTUALLY has an EN translation — picking $pt_ids[0]
// made the assertion depend on post ordering and would break as soon as the
// lowest-id PT job is a legitimate B2-only record (Job #10954).
$first_pt = ! empty( $translated_pt ) ? (int) $translated_pt[0] : 0;
assert_true(
	$first_pt > 0 && 0 !== (int) pll_get_post( $first_pt, 'en' ) && ! conexao_should_render_b2_fallback( $first_pt ),
	'a translated PT job is no longer B2-eligible (real EN wins)',
	"id={$first_pt}"
);

// Future-job behaviour: a new untranslated PT job must remain B2-eligible.
$probe_id = wp_insert_post(
	array(
		'post_type'    => 'job',
		'post_name'    => 's6-b2-probe',
		'post_title'   => 'S6 B2 probe',
		'post_content' => '<p>probe</p>',
		'post_status'  => 'publish',
	)
);
if ( is_wp_error( $probe_id ) ) {
	assert_true( false, 'B2 probe job could be created', $probe_id->get_error_message() );
} else {
	pll_set_post_language( $probe_id, 'pt' );
	// B2 rendering is request-scoped (an EN request), which a CLI process
	// cannot reproduce — the EN-request render of an untranslated job used to be
	// asserted over HTTP by scripts/stage6-job-verify.py (state A), whose
	// landing-card probe is now SKIPped because the «Vagas»/"Openings" preview
	// section was removed from the landing pages (rendering only). Here the
	// in-process preconditions are asserted instead.
	assert_true(
		conexao_is_b2_post_type( 'job' ),
		'the job CPT remains in the B2 post-type allowlist (fallback architecture preserved)'
	);
	assert_true(
		0 === (int) pll_get_post( (int) $probe_id, 'en' ),
		'an untranslated future PT job has no EN translation (state A precondition)'
	);
	$probe_in_pt_set = function_exists( 'conexao_empregos_current_jobs' ) ? conexao_empregos_current_jobs() : array();
	assert_true(
		in_array( (int) $probe_id, array_map( 'intval', (array) $probe_in_pt_set ), true ),
		'the untranslated probe appears in the (default-language) jobs listing set'
	);
	wp_delete_post( (int) $probe_id, true );
	if ( function_exists( 'conexao_empregos_flush_jobs_cache' ) ) {
		conexao_empregos_flush_jobs_cache( (int) $probe_id );
	}
}

// ---------------------------------------------------------------------------

$listing = function_exists( 'conexao_empregos_current_jobs' ) ? array_map( 'intval', (array) conexao_empregos_current_jobs() ) : array();
assert_true( ! empty( $listing ), 'the jobs listing helper returns the public jobs' );
assert_true(
	count( $listing ) === count( array_intersect( $listing, array_map( 'intval', $pt_ids ) ) ),
	'the default-language listing contains exactly the PT jobs (no EN records leak into PT)',
	implode( ',', $listing )
);

$page_url = function_exists( 'conexao_empregos_page_url' ) ? (string) conexao_empregos_page_url() : '';
assert_true(
	'' !== $page_url && false !== strpos( $page_url, '/empregos/' ),
	'conexao_empregos_page_url() stays the PT page on the default language',
	$page_url
);

// ---------------------------------------------------------------------------

foreach ( $pt_ids as $pt_id ) {
	$en_id = (int) pll_get_post( (int) $pt_id, 'en' );
	if ( $en_id <= 0 ) {
		continue;
	}
	assert_true(
		'' !== (string) get_post_meta( $en_id, 'conexao_meta_description', true ),
		'EN job has an English meta description',
		get_post_field( 'post_name', $en_id )
	);
}

test_finish();
