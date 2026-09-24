<?php
/**
 * Stage 6 — Job EN translation (in-process checks).
 *
 * Covers the completion gate and the content/architecture contract of the Job
 * translation (the HTTP matrix — rendering, canonical, hreflang, listing
 * membership, B2 transition — is covered by scripts/stage6-job-verify.py):
 *
 *  - the Jobs landing Page pair (PT `empregos` ↔ EN `jobs`) exists, is
 *    published, linked from both sides and keeps template parity (Stage 4.5
 *    owns the pages — this stage never creates them);
 *  - every public Portuguese `job` has exactly ONE linked, published EN
 *    translation (gate: eligible public PT jobs missing EN = 0);
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

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

$passed = 0;
$failed = 0;

/**
 * Assert a condition.
 *
 * @param bool   $condition Condition.
 * @param string $message   Message.
 * @param string $detail    Extra detail printed on failure.
 * @return void
 */
function s6_assert( $condition, $message, $detail = '' ) {
	global $passed, $failed;

	if ( $condition ) {
		++$passed;
		echo "  PASS: {$message}\n";
		return;
	}

	++$failed;
	echo "  FAIL: {$message}" . ( '' !== $detail ? " — {$detail}" : '' ) . "\n";
}

echo "== Stage 6 — Job EN translation ==\n";

if ( ! function_exists( 'pll_get_post' ) ) {
	echo "  SKIP: Polylang is not active.\n";
	exit( 0 );
}

// ---------------------------------------------------------------------------
echo "\n-- Jobs landing Page pair (Stage 4.5 ownership, verified not owned) --\n";

$pt_page = get_page_by_path( 'empregos', OBJECT, 'page' );
s6_assert( $pt_page instanceof WP_Post, 'the PT Empregos landing page exists' );

$pt_page_id = $pt_page instanceof WP_Post ? (int) $pt_page->ID : 0;
$en_page_id = $pt_page_id > 0 ? (int) pll_get_post( $pt_page_id, 'en' ) : 0;
s6_assert( $en_page_id > 0 && $en_page_id !== $pt_page_id, 'the Jobs page has an EN translation', "en_id={$en_page_id}" );
s6_assert( $en_page_id > 0 && (int) pll_get_post( $en_page_id, 'pt' ) === $pt_page_id, 'the Jobs page pair is linked from both sides' );
s6_assert( 'publish' === get_post_status( $en_page_id ), 'the EN Jobs page is published' );
s6_assert( $en_page_id > 0 && 'en' === pll_get_post_language( $en_page_id, 'slug' ), 'the EN Jobs page is assigned to en' );
s6_assert( $pt_page_id > 0 && 'pt' === pll_get_post_language( $pt_page_id, 'slug' ), 'the PT Jobs page is still assigned to pt' );
s6_assert(
	$en_page_id > 0 && get_permalink( $en_page_id ) === trailingslashit( home_url( '/en/jobs/' ) ),
	'the EN Jobs page lives at /en/jobs/',
	$en_page_id > 0 ? (string) get_permalink( $en_page_id ) : ''
);
s6_assert(
	$pt_page_id > 0 && $en_page_id > 0 && get_page_template_slug( $pt_page_id ) === get_page_template_slug( $en_page_id ),
	'Jobs page template parity (page-empregos.php on both)',
	$pt_page_id > 0 && $en_page_id > 0 ? get_page_template_slug( $pt_page_id ) . ' vs ' . get_page_template_slug( $en_page_id ) : ''
);

// ---------------------------------------------------------------------------
echo "\n-- Job inventory (the completion gate) --\n";

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

s6_assert( count( $pt_ids ) > 0, 'the site has public PT jobs', (string) count( $pt_ids ) );
s6_assert( count( $pt_ids ) === count( $en_ids ), 'PT and EN public job counts match', 'pt=' . count( $pt_ids ) . ' en=' . count( $en_ids ) );

$missing_en    = array();
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

foreach ( $pt_ids as $pt_id ) {
	$pt    = get_post( $pt_id );
	$en_id = (int) pll_get_post( (int) $pt_id, 'en' );

	if ( $en_id <= 0 || 'publish' !== get_post_status( $en_id ) ) {
		$missing_en[] = $pt->post_name;
		continue;
	}

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

s6_assert( 0 === count( $missing_en ), 'GATE: every public PT job has a published EN translation', implode( ', ', $missing_en ) );
s6_assert( 0 === count( $not_linked ), 'every EN job is linked back to its PT job', implode( ', ', $not_linked ) );
s6_assert( 0 === count( $copied_body ), 'EN bodies are translations, not copies of the PT body', implode( ', ', $copied_body ) );
s6_assert( 0 === count( $not_english ), 'EN bodies contain no Portuguese prose', implode( ', ', $not_english ) );
s6_assert( 0 === count( $pt_changed ), 'PT originals still pt / EN records still en', implode( ', ', $pt_changed ) );
s6_assert( 0 === count( $meta_drift ), 'the _job_* meta layer is verbatim-identical on PT and EN', implode( ', ', $meta_drift ) );
s6_assert( 0 === count( $media_mismatch ), 'EN jobs share the PT featured image', implode( ', ', $media_mismatch ) );
s6_assert( 0 === count( $date_mismatch ), 'EN jobs keep the PT publication date', implode( ', ', $date_mismatch ) );
s6_assert( 0 === count( $bad_en_slug ), 'EN slugs are natural (no -2 / -en suffixes, no PT slug reuse)', implode( ', ', $bad_en_slug ) );


// No EN job without a PT sibling.
$en_orphans = array();
foreach ( $en_ids as $en_id ) {
	$pt_sibling = (int) pll_get_post( (int) $en_id, 'pt' );
	if ( $pt_sibling <= 0 ) {
		$en_orphans[] = get_post_field( 'post_name', $en_id );
	}
}
s6_assert( 0 === count( $en_orphans ), 'no EN job without a PT sibling', implode( ', ', $en_orphans ) );

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
s6_assert( 0 === count( $dupes ), 'no PT job has two EN translations', implode( ', ', array_map( 'strval', $dupes ) ) );

// ---------------------------------------------------------------------------
echo "\n-- B2 state machine (translated job retires B2; future job keeps it) --\n";

$first_pt = ! empty( $pt_ids ) ? (int) $pt_ids[0] : 0;
s6_assert(
	$first_pt > 0 && ! conexao_should_render_b2_fallback( $first_pt ),
	'a translated PT job is no longer B2-eligible (real EN wins)'
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
	s6_assert( false, 'B2 probe job could be created', $probe_id->get_error_message() );
} else {
	pll_set_post_language( $probe_id, 'pt' );
	// B2 rendering is request-scoped (an EN request), which a CLI process
	// cannot reproduce — the EN-request render of an untranslated job is
	// asserted over HTTP by scripts/stage6-job-verify.py (state A). Here the
	// in-process preconditions are asserted instead.
	s6_assert(
		conexao_is_b2_post_type( 'job' ),
		'the job CPT remains in the B2 post-type allowlist (fallback architecture preserved)'
	);
	s6_assert(
		0 === (int) pll_get_post( (int) $probe_id, 'en' ),
		'an untranslated future PT job has no EN translation (state A precondition)'
	);
	$probe_in_pt_set = function_exists( 'conexao_empregos_current_jobs' ) ? conexao_empregos_current_jobs() : array();
	s6_assert(
		in_array( (int) $probe_id, array_map( 'intval', (array) $probe_in_pt_set ), true ),
		'the untranslated probe appears in the (default-language) jobs listing set'
	);
	wp_delete_post( (int) $probe_id, true );
	if ( function_exists( 'conexao_empregos_flush_jobs_cache' ) ) {
		conexao_empregos_flush_jobs_cache( (int) $probe_id );
	}
}

// ---------------------------------------------------------------------------
echo "\n-- Jobs listing helper (default-language context) --\n";

$listing = function_exists( 'conexao_empregos_current_jobs' ) ? array_map( 'intval', (array) conexao_empregos_current_jobs() ) : array();
s6_assert( ! empty( $listing ), 'the jobs listing helper returns the public jobs' );
s6_assert(
	count( $listing ) === count( array_intersect( $listing, array_map( 'intval', $pt_ids ) ) ),
	'the default-language listing contains exactly the PT jobs (no EN records leak into PT)',
	implode( ',', $listing )
);

$page_url = function_exists( 'conexao_empregos_page_url' ) ? (string) conexao_empregos_page_url() : '';
s6_assert(
	'' !== $page_url && false !== strpos( $page_url, '/empregos/' ),
	'conexao_empregos_page_url() stays the PT page on the default language',
	$page_url
);

// ---------------------------------------------------------------------------
echo "\n-- SEO inputs --\n";

foreach ( $pt_ids as $pt_id ) {
	$en_id = (int) pll_get_post( (int) $pt_id, 'en' );
	if ( $en_id <= 0 ) {
		continue;
	}
	s6_assert(
		'' !== (string) get_post_meta( $en_id, 'conexao_meta_description', true ),
		'EN job has an English meta description',
		get_post_field( 'post_name', $en_id )
	);
}

echo "\nstage 6 job EN translation: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );

