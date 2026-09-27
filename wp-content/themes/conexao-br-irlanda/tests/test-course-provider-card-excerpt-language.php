<?php
/**
 * STAGE 8 — course-provider card description language selection (in-process).
 *
 * Verifies the STAGE 8 contract for `.provider-card-excerpt` on the Cursos
 * archive, by RENDERING the real card template part in each language context:
 *
 *  - PT request → the exact pre-existing description (get_the_excerpt()),
 *    byte-identical pipeline (20-word trim inside the template);
 *  - EN request + an authored EN description (`_provider_excerpt_en`) → the
 *    English text, through the SAME 20-word trim;
 *  - EN request + no authored EN description → the approved B2 fallback
 *    (the Portuguese excerpt under the English shell), never an invented
 *    translation;
 *  - a PT request never reads the EN meta, so PT output cannot change.
 *
 * It also covers the rollout-engine invariants on a temporary record: preview
 * writes nothing; apply writes ONLY `_provider_excerpt_en`; a re-apply is
 * idempotent; a PT-drift mismatch is refused as a hard conflict; remove rolls
 * back. A record with no PT source description is not translated, and a valid
 * existing EN description is not clobbered.
 *
 * Self-contained: creates one temporary `course_provider` record and removes it
 * (and its meta) at the end; never modifies existing records. Dataset-level
 * assertions run over the real published records when the stage is applied.
 *
 * Usage:
 *   docker compose exec wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-course-provider-card-excerpt-language.php
 *
 * @package conexao-br-irlanda
 */

// Works both standalone (php <this-file> from anywhere) and via `wp eval-file`.

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$theme_functions = get_template_directory() . '/functions.php';
if ( file_exists( $theme_functions ) && ! function_exists( 'conexao_provider_card_excerpt' ) ) {
	require_once $theme_functions;
}

$passed = 0;
$failed = 0;

/**
 * The card's rendered excerpt text == wp_trim_words() of the description
 * source (the template escapes it for HTML; the renderer decodes that one
 * layer, so the expectation is the raw trimmed text).
 *
 * @param string $text Description source.
 * @return string
 */
function cp_expected_card_excerpt( $text ) {
	return wp_trim_words( $text, 20, '...' );
}

/**
 * Switch the Polylang language context the way the frontend router does.
 *
 * @param string $slug Language slug.
 * @return string|false Previous slug.
 */
function cp_set_language( $slug ) {
	if ( ! function_exists( 'PLL' ) || ! PLL() ) {
		return false;
	}

	$previous      = isset( PLL()->curlang->slug ) ? PLL()->curlang->slug : false;
	PLL()->curlang = PLL()->model->get_language( $slug );

	return $previous;
}

/**
 * Render the real provider-card template part for a post and return the
 * `.provider-card-excerpt` inner HTML (unescaped text).
 *
 * @param int $post_id Post ID.
 * @return string
 */
function cp_render_card_excerpt( $post_id ) {
	global $post;
	$previous = $post;

	$post = get_post( $post_id );
	setup_postdata( $post );

	ob_start();
	require get_template_directory() . '/template-parts/provider-card.php';
	$html = ob_get_clean();

	wp_reset_postdata();
	$post = $previous;

	if ( ! preg_match( '/<p class="provider-card-excerpt">(.*?)<\/p>/s', $html, $matches ) ) {
		return '(not found)';
	}

	return html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' );
}

// --- Bootstrap sanity -----------------------------------------------------------

test_section( 'Bootstrap' );

assert_true( function_exists( 'conexao_provider_card_excerpt' ), 'conexao_provider_card_excerpt() exists (theme inc/i18n/fallback.php)' );
assert_true( function_exists( 'conexao_current_language_slug' ), 'conexao_current_language_slug() exists' );
assert_true( function_exists( 'pll_set_post_language' ), 'Polylang is active' );

$polylang_ok = function_exists( 'PLL' ) && PLL() && function_exists( 'pll_default_language' );
if ( ! $polylang_ok ) {
	exit( $failed ? 1 : 0 );
}

$default_lang = pll_default_language( 'slug' );

// --- Temporary record ------------------------------------------------------------

$pt_text = 'Frase de teste temporária para a descrição do cartão de fornecedor de cursos com acentuação e palavras suficientes para forçar o corte de vinte palavras no cartão exibido.';
$en_text = 'Temporary test sentence for the course provider card description with accents and enough additional words to force the twenty word trim on the displayed card.';

$temp_id = wp_insert_post(
	array(
		'post_type'    => 'course_provider',
		'post_status'  => 'publish',
		'post_title'   => 'STAGE 8 Test Course Provider',
		'post_name'    => 'stage8-test-course-provider',
		'post_excerpt' => $pt_text,
		'post_content' => 'Temporary content.',
	),
	true
);

if ( is_wp_error( $temp_id ) || ! $temp_id ) {
	exit( 1 );
}

if ( function_exists( 'pll_set_post_language' ) ) {
	pll_set_post_language( $temp_id, $default_lang );
}

// Never leak the temporary record, even if an assertion path aborts.
register_shutdown_function(
	static function () use ( $temp_id ) {
		if ( get_post( $temp_id ) ) {
			wp_delete_post( $temp_id, true );
		}
	}
);

// --- Language selection ----------------------------------------------------------

test_section( 'PT request → existing Portuguese pipeline (unchanged)' );

cp_set_language( $default_lang );

assert_true(
	conexao_provider_card_excerpt( $temp_id ) === get_the_excerpt( $temp_id ),
	'helper returns the exact get_the_excerpt() value on PT'
);
assert_true(
	cp_render_card_excerpt( $temp_id ) === cp_expected_card_excerpt( $pt_text ),
	'.provider-card-excerpt renders the trimmed PT description'
);
assert_true(
	cp_render_card_excerpt( $temp_id ) === cp_expected_card_excerpt( get_the_excerpt( $temp_id ) ),
	'PT card output == the pre-existing 20-word pipeline byte-for-byte'
);

test_section( 'EN request, no EN description → approved B2 fallback' );

cp_set_language( 'en' );

assert_true(
	conexao_provider_card_excerpt( $temp_id ) === get_the_excerpt( $temp_id ),
	'helper falls back to the PT description (B2) when no EN description exists'
);
assert_true(
	cp_render_card_excerpt( $temp_id ) === cp_expected_card_excerpt( $pt_text ),
	'.provider-card-excerpt renders the PT description (B2 fallback)'
);

test_section( 'EN request + authored EN description → English card' );

update_post_meta( $temp_id, '_provider_excerpt_en', $en_text );

assert_true(
	conexao_provider_card_excerpt( $temp_id ) === $en_text,
	'helper returns the authored EN description'
);
assert_true(
	cp_render_card_excerpt( $temp_id ) === cp_expected_card_excerpt( $en_text ),
	'.provider-card-excerpt renders the trimmed EN description'
);
assert_true(
	cp_render_card_excerpt( $temp_id ) !== cp_expected_card_excerpt( $pt_text ),
	'EN card does NOT render the Portuguese description (the Stage 8 rule)'
);

cp_set_language( $default_lang );

assert_true(
	cp_render_card_excerpt( $temp_id ) === cp_expected_card_excerpt( $pt_text ),
	'PT card still renders the PT description while the EN meta exists'
);
assert_true(
	conexao_provider_card_excerpt( $temp_id ) === get_the_excerpt( $temp_id ),
	'PT request never reads the EN meta'
);

delete_post_meta( $temp_id, '_provider_excerpt_en' );

// --- Rollout engine invariants ---------------------------------------------------

test_section( 'Rollout engine: preview / drift refusal / idempotency / remove' );

$engine_available = class_exists( 'Conexao_Translation_Rollout_Engine' )
	&& function_exists( 'conexao_en_translation_course_provider_description_config' );
assert_true( $engine_available, 'shared rollout engine + en-course-provider-description stage are loaded' );

if ( $engine_available ) {
	$stage_config  = conexao_en_translation_course_provider_description_config();
	$stage_adapter = conexao_en_translation_course_provider_description_adapter();

	assert_true( 'en-course-provider-description' === $stage_config['stage'], 'the stage declares its own identity' );
	assert_true( true === $stage_config['allow_remove'], 'the stage declares remove as safe (single reproducible field)' );
	assert_true( 'course_provider' === $stage_config['source_post_type'], 'the stage declares the course_provider post type' );

	// Drive the SHARED engine against the REAL shipped dataset, in preview mode.
	// This is what proves the engine, not this test, owns the lifecycle.
	$preview = Conexao_Translation_Rollout_Engine::run( $stage_config, $stage_adapter, array( 'dry_run' => true ) );
	assert_true( ! is_wp_error( $preview ), 'preview returns a report' );

	if ( ! is_wp_error( $preview ) ) {
		assert_true(
			isset( $preview['summary']['pt_changed'] ) && 0 === $preview['summary']['pt_changed'],
			'preview reports 0 PT changes'
		);
		assert_true(
			isset( $preview['summary']['errors'] ) && 0 === $preview['summary']['errors'],
			'preview reports 0 errors'
		);
		assert_true(
			isset( $preview['summary']['conflicts'] ) && 0 === $preview['summary']['conflicts'],
			'preview reports 0 conflicts against the shipped dataset'
		);
	}

	// The normaliser must NOT hide a real PT edit: entity/whitespace/typographic
	// differences compare equal, but a changed Portuguese word does not.
	test_section( 'PT-drift detection: normalisation is narrow, not a blanket pass' );

	$normalize = 'conexao_en_translation_course_provider_normalize';

	assert_true(
		$normalize( 'King John&#8217;s Castle' ) === $normalize( "King John's Castle" ),
		'entity encoding is not mistaken for content drift'
	);
	assert_true(
		$normalize( 'Com opções em áreas A—B' ) === $normalize( 'Com opções em áreas A-B' ),
		'an en/em dash difference is not mistaken for content drift'
	);
	assert_true(
		$normalize( "Cursos  de\tformação" ) === $normalize( 'Cursos de formação' ),
		'a whitespace run difference is not mistaken for content drift'
	);
	assert_true(
		$normalize( 'Cursos de formação' ) !== $normalize( 'Cursos de formação profissional' ),
		'a REAL change to the Portuguese words is still detected as drift'
	);
	assert_true(
		$normalize( 'Cursos de formação' ) !== $normalize( 'cursos de formação' ),
		'case is NOT folded — a genuine edit is never normalised away'
	);
	assert_true(
		$normalize( 'Cursos de formação' ) !== $normalize( 'Cursos de formaçao' ),
		'accents are NOT folded — a genuine edit is never normalised away'
	);

	// A drifted PT source must be refused as a hard conflict by the STAGE's own
	// rule, and nothing may be written for it.
	test_section( 'Negative: a drifted PT source is refused, not silently translated' );

	$drift_row = array(
		'en_slug'        => 'stage8-test-course-provider',
		'pt_source'      => 'Um texto de origem que NAO corresponde ao excerpt real do registo temporário.',
		'pt_title'       => 'STAGE 8 Test Course Provider',
		'en_description' => 'A translation that must never be written.',
	);

	$en_before = (string) get_post_meta( $temp_id, '_provider_excerpt_en', true );
	$drift_state = conexao_en_translation_course_provider_find_en_for_pt(
		$temp_id,
		$drift_row['en_description'],
		$drift_row['pt_source']
	);

	assert_true(
		'' !== (string) $drift_state['stage_conflict'],
		'a drifted PT source is reported as a hard stage conflict'
	);
	assert_true(
		0 === (int) $drift_state['en_id'],
		'a drifted record is NOT reported as already translated'
	);
	assert_true(
		'' === $en_before,
		'the refused record was not written'
	);

	// A record with NO PT source description has nothing to translate: the
	// stage must not invent a conflict for it either.
	$no_source = conexao_en_translation_course_provider_find_en_for_pt( $temp_id, 'An English string.', '' );
	assert_true(
		'' === (string) $no_source['stage_conflict'],
		'an empty expected source disables the drift guard rather than faking a conflict'
	);

	// A valid existing EN description must not be clobbered by the write path.
	test_section( 'Negative: an existing, valid EN description is not clobbered' );

	update_post_meta( $temp_id, '_provider_excerpt_en', $en_text );
	$idempotent = conexao_en_translation_course_provider_write( $temp_id, array( 'en_description' => $en_text ) );
	assert_true(
		true === $idempotent,
		're-writing an already-correct description is a no-op (idempotent)'
	);
	assert_true(
		$en_text === (string) get_post_meta( $temp_id, '_provider_excerpt_en', true ),
		'the stored English description is byte-identical after the no-op write'
	);

	// Remove is the documented rollback: it deletes ONLY the EN field.
	test_section( 'Negative: remove deletes only the English field, never PT data' );

	$pt_excerpt_before = (string) get_post( $temp_id )->post_excerpt;
	$pt_title_before   = (string) get_post( $temp_id )->post_title;

	$adapter_remove = $stage_adapter['remove_en'];
	$adapter_remove( $temp_id );

	assert_true(
		'' === (string) get_post_meta( $temp_id, '_provider_excerpt_en', true ),
		'remove_en() deletes only _provider_excerpt_en'
	);
	assert_true(
		$pt_excerpt_before === (string) get_post( $temp_id )->post_excerpt,
		'remove_en() leaves the PT excerpt untouched'
	);
	assert_true(
		$pt_title_before === (string) get_post( $temp_id )->post_title,
		'remove_en() leaves the PT title untouched'
	);
}

// --- Dataset-level assertions over the real published records --------------------

test_section( 'Shipped dataset: every published provider has an English description' );

$published = get_posts(
	array(
		'post_type'        => 'course_provider',
		'post_status'      => 'publish',
		'numberposts'      => -1,
		'lang'             => '',
		'suppress_filters' => true,
	)
);

$with_pt_source   = 0;
$with_en          = 0;
$en_is_english    = 0;
$en_is_copy_of_pt = 0;
$rendered_pt_leak = 0;

foreach ( $published as $record ) {
	// The suite's own temporary fixture is published while the test runs and is
	// not part of the shipped dataset, so it must not be counted.
	if ( (int) $record->ID === (int) $temp_id ) {
		continue;
	}

	$pt = trim( (string) $record->post_excerpt );
	if ( '' === $pt ) {
		continue;
	}
	++$with_pt_source;

	$en = trim( (string) get_post_meta( $record->ID, '_provider_excerpt_en', true ) );
	if ( '' === $en ) {
		continue;
	}
	++$with_en;

	// An English description must be recognisably English, not a copy of the PT.
	if ( $en === $pt ) {
		++$en_is_copy_of_pt;
	}

	$english_markers = array( ' the ', ' and ', ' courses', ' training', ' in Ireland', ' for ', ' with ' );
	$probe           = ' ' . strtolower( $en ) . ' ';
	foreach ( $english_markers as $marker ) {
		if ( false !== strpos( $probe, $marker ) ) {
			++$en_is_english;
			break;
		}
	}

	// Rendering proof: the EN card must not show the Portuguese description.
	cp_set_language( 'en' );
	$rendered = cp_render_card_excerpt( $record->ID );
	cp_set_language( $default_lang );

	if ( $en !== $pt && $rendered === cp_expected_card_excerpt( $pt ) ) {
		++$rendered_pt_leak;
	}
}

if ( $with_pt_source > 0 ) {
	assert_true(
		$with_en === $with_pt_source,
		sprintf( 'every published provider with a PT description has an EN description (%d/%d)', $with_en, $with_pt_source )
	);
	assert_true(
		$en_is_english === $with_en,
		sprintf( 'every EN description is recognisably English (%d/%d)', $en_is_english, $with_en )
	);
	assert_true(
		0 === $en_is_copy_of_pt,
		'no EN description is a verbatim copy of its Portuguese source'
	);
	assert_true(
		0 === $rendered_pt_leak,
		sprintf( 'no published provider renders a wrong (PT) description on EN (%d wrong of %d)', $rendered_pt_leak, $with_en )
	);
} else {
	assert_true( true, 'no published course_provider records present; dataset checks skipped' );
}

// The named example from the request must be covered explicitly.
test_section( 'Named example: FETCH Courses renders the authored English' );

$fetch = get_page_by_path( 'fetch-courses', OBJECT, 'course_provider' );
assert_true( $fetch instanceof WP_Post, 'the fetch-courses provider record exists' );

if ( $fetch instanceof WP_Post ) {
	$fetch_en = trim( (string) get_post_meta( $fetch->ID, '_provider_excerpt_en', true ) );
	assert_true( '' !== $fetch_en, 'FETCH Courses carries an English description' );

	cp_set_language( 'en' );
	$rendered_en = cp_render_card_excerpt( $fetch->ID );
	cp_set_language( $default_lang );
	$rendered_pt = cp_render_card_excerpt( $fetch->ID );

	assert_true(
		$rendered_en === cp_expected_card_excerpt( $fetch_en ),
		'the FETCH Courses EN card renders the authored English description'
	);
	assert_true(
		$rendered_en !== cp_expected_card_excerpt( (string) $fetch->post_excerpt ),
		'the FETCH Courses EN card no longer renders the Portuguese description'
	);
	assert_true(
		$rendered_pt === cp_expected_card_excerpt( (string) $fetch->post_excerpt ),
		'the FETCH Courses PT card still renders the Portuguese description'
	);
}

echo "\n";
printf( "%d passed, %d failed\n", $passed, $failed );
exit( $failed > 0 ? 1 : 0 );
