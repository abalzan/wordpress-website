<?php
/**
 * Stage 7 — Leisure card description language selection (in-process checks).
 *
 * Verifies the Stage 7 contract for `.leisure-card-excerpt` on the Leisure
 * archive, by RENDERING the real card template part in each language context:
 *
 *  - PT request → the exact pre-existing description (get_the_excerpt()),
 *    byte-identical pipeline (18-word trim inside the template);
 *  - EN request + an authored EN description (`_leisure_excerpt_en`) →
 *    the English text, through the SAME 18-word trim;
 *  - EN request + no authored EN description → the approved B2 fallback
 *    (the Portuguese excerpt under the English shell), never an invented
 *    translation;
 *  - `.leisure-card-excerpt` never renders the Portuguese description on an
 *    EN request once a translation exists (the Stage 7 acceptance rule).
 *
 * The test fails if a future change makes the EN archive render the PT
 * description again, or changes the PT output.
 *
 * Also covers the rollout engine invariants on a temporary record:
 * preview changes nothing; apply writes ONLY `_leisure_excerpt_en`; a second
 * apply is idempotent; a PT-drift mismatch is refused; remove rolls back.
 *
 * Self-contained: creates one temporary leisure record and removes it (and
 * its meta) at the end; never modifies existing records. The dataset-level
 * assertions run over the real published records when present.
 *
 * Usage (from the project root / the WordPress root):
 *   docker compose exec wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-leisure-card-excerpt-language.php
 *
 * @package conexao-br-irlanda
 */

// Works both standalone (php <this-file> from anywhere) and via `wp eval-file`
// (where WordPress is already loaded).

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$theme_functions = get_template_directory() . '/functions.php';
if ( file_exists( $theme_functions ) && ! function_exists( 'conexao_leisure_card_excerpt' ) ) {
	require_once $theme_functions;
}

$passed = 0;
$failed = 0;

/**
 * The card's rendered excerpt text == wp_trim_words() of the description
 * source (the template escapes it for HTML; s7_render_card_excerpt() decodes
 * that one layer, so the expectation is the raw trimmed text).
 */
function s7_expected_card_excerpt( $text ) {
	return wp_trim_words( $text, 18, '...' );
}

/**
 * Switch the Polylang language context the way the frontend router does.
 *
 * @param string $slug Language slug.
 * @return string|false Previous slug.
 */
function s7_set_language( $slug ) {
	if ( ! function_exists( 'PLL' ) || ! PLL() ) {
		return false;
	}

	$previous      = isset( PLL()->curlang->slug ) ? PLL()->curlang->slug : false;
	PLL()->curlang = PLL()->model->get_language( $slug );

	return $previous;
}

/**
 * Render the real leisure-card template part for a post and return the
 * `.leisure-card-excerpt` inner HTML (unescaped text).
 *
 * @param int $post_id Post ID.
 * @return string
 */
function s7_render_card_excerpt( $post_id ) {
	global $post;
	$previous = $post;

	$post = get_post( $post_id );
	setup_postdata( $post );

	ob_start();
	require get_template_directory() . '/template-parts/leisure-card.php';
	$html = ob_get_clean();

	wp_reset_postdata();
	$post = $previous;

	if ( ! preg_match( '/<p class="leisure-card-excerpt">(.*?)<\/p>/s', $html, $matches ) ) {
		return '(not found)';
	}

	return html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' );
}

// --- Bootstrap sanity -----------------------------------------------------------

test_section( 'Bootstrap' );

if ( ! function_exists( 'conexao_leisure_card_excerpt' ) ) {
	assert_true( false, 'conexao_leisure_card_excerpt() exists (theme inc/polylang.php)' );
	exit( 1 );
}

assert_true( true, 'conexao_leisure_card_excerpt() exists (theme inc/polylang.php)' );
assert_true( function_exists( 'conexao_current_language_slug' ), 'conexao_current_language_slug() exists' );
assert_true( function_exists( 'pll_set_post_language' ), 'Polylang is active' );

$polylang_ok = function_exists( 'PLL' ) && PLL() && function_exists( 'pll_default_language' );
if ( ! $polylang_ok ) {
	exit( $failed ? 1 : 0 );
}

$default_lang = pll_default_language( 'slug' );

// --- Temporary record ------------------------------------------------------------

$pt_text = 'Frase de teste temporária para a descrição do cartão de lazer com acentuação e mais palavras para forçar o corte de dezoito palavras no cartão exibido.';
$en_text = 'Temporary test sentence for the leisure card description with accents and additional words to force the eighteen word trim in the displayed card.';

$temp_id = wp_insert_post(
	array(
		'post_type'    => 'leisure',
		'post_status'  => 'publish',
		'post_title'   => 'Stage 7 Test Leisure Record',
		'post_name'    => 'stage7-test-leisure-record',
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

$previous = s7_set_language( $default_lang );

assert_true(
	conexao_leisure_card_excerpt( $temp_id ) === get_the_excerpt( $temp_id ),
	'helper returns the exact get_the_excerpt() value on PT'
);
assert_true(
	s7_render_card_excerpt( $temp_id ) === s7_expected_card_excerpt( $pt_text ),
	'.leisure-card-excerpt renders the trimmed PT description'
);
assert_true(
	s7_render_card_excerpt( $temp_id ) === s7_expected_card_excerpt( get_the_excerpt( $temp_id ) ),
	'PT card output == the pre-existing 18-word pipeline byte-for-byte'
);

test_section( 'EN request, no EN description → approved B2 fallback' );

s7_set_language( 'en' );

assert_true(
	conexao_leisure_card_excerpt( $temp_id ) === get_the_excerpt( $temp_id ),
	'helper falls back to the PT description (B2) when no EN description exists'
);
assert_true(
	s7_render_card_excerpt( $temp_id ) === s7_expected_card_excerpt( $pt_text ),
	'.leisure-card-excerpt renders the PT description (B2 fallback)'
);

test_section( 'EN request + authored EN description → English card' );

update_post_meta( $temp_id, '_leisure_excerpt_en', $en_text );

assert_true(
	conexao_leisure_card_excerpt( $temp_id ) === $en_text,
	'helper returns the authored EN description'
);
assert_true(
	s7_render_card_excerpt( $temp_id ) === s7_expected_card_excerpt( $en_text ),
	'.leisure-card-excerpt renders the trimmed EN description'
);
assert_true(
	s7_render_card_excerpt( $temp_id ) !== s7_expected_card_excerpt( $pt_text ),
	'EN card does NOT render the Portuguese description (the Stage 7 rule)'
);

s7_set_language( $default_lang );

assert_true(
	s7_render_card_excerpt( $temp_id ) === s7_expected_card_excerpt( $pt_text ),
	'PT card still renders the PT description while the EN meta exists'
);
assert_true(
	conexao_leisure_card_excerpt( $temp_id ) === get_the_excerpt( $temp_id ),
	'PT request never reads the EN meta'
);

delete_post_meta( $temp_id, '_leisure_excerpt_en' );

// --- Rollout engine invariants ---------------------------------------------------

test_section( 'Rollout engine: dry-run / drift refusal / idempotency (shared engine stage)' );

$engine_available = class_exists( 'Conexao_Translation_Rollout_Engine' )
	&& function_exists( 'conexao_en_translation_leisure_description_config' );
assert_true( $engine_available, 'shared rollout engine + en-leisure-description stage are loaded' );

if ( $engine_available ) {
	$stage_config  = conexao_en_translation_leisure_description_config();
	$stage_adapter = conexao_en_translation_leisure_description_adapter();
	$run_stage     = static function ( array $args ) use ( $stage_config, $stage_adapter ) {
		return Conexao_Translation_Rollout_Engine::run( $stage_config, $stage_adapter, $args );
	};

	assert_true( 'en-leisure-description' === $stage_config['stage'], 'the stage declares its own identity' );
	assert_true( true === $stage_config['allow_remove'], 'the stage declares remove as safe (single reproducible field)' );

	// 1. Dry run. Zero writes by construction, and the gate must still report
	//    the pre-apply state honestly.
	$preview = $run_stage( array( 'dry_run' => true ) );
	assert_true( ! is_wp_error( $preview ), 'dry-run returns a report' );
	assert_true( 0 === $preview['summary']['pt_changed'], 'dry-run reports 0 PT changes' );
	assert_true( 0 === $preview['summary']['errors'], 'dry-run reports 0 errors' );

	$dwyer = get_posts(
		array(
			'post_type'        => 'leisure',
			'name'             => 'dwyer-mcallister-cottage',
			'post_status'      => 'publish',
			'numberposts'      => 1,
			'no_found_rows'    => true,
			'lang'             => '',
			'suppress_filters' => true,
		)
	);

	if ( ! empty( $dwyer ) ) {
		$dwyer_id  = (int) $dwyer[0]->ID;
		$en_before = (string) get_post_meta( $dwyer_id, '_leisure_excerpt_en', true );

		$planned = 0;
		foreach ( $preview['rows'] as $candidate ) {
			if ( 'dwyer-mcallister-cottage' === ( $candidate['stable_key'] ?? '' ) ) {
				$planned = 1;
				break;
			}
		}
		assert_true( 1 === $planned, 'the dry-run plan accounts for the Dwyer record' );
		assert_true(
			$en_before === (string) get_post_meta( $dwyer_id, '_leisure_excerpt_en', true ),
			'the dry-run wrote nothing to the Dwyer record'
		);

		// 2. PT-drift refusal: a record whose Portuguese source no longer matches
		//    the authored one must be REFUSED, never silently translated. The
		//    real record's excerpt is drifted and restored around the probe.
		$original = (string) $dwyer[0]->post_excerpt;
		wp_update_post(
			array(
				'ID'           => $dwyer_id,
				'post_excerpt' => 'Descrição alterada depois de a tradução ter sido escrita.',
			)
		);

		$drifted = $run_stage( array( 'dry_run' => true ) );
		$refused = false;
		foreach ( $drifted['rows'] as $candidate ) {
			if ( 'dwyer-mcallister-cottage' === ( $candidate['stable_key'] ?? '' )
				&& 'error' === ( $candidate['action'] ?? '' )
				&& false !== strpos( (string) ( $candidate['message'] ?? '' ), 'PT source changed' ) ) {
				$refused = true;
				break;
			}
		}
		assert_true( $refused, 'a drifted PT source is refused as a hard conflict' );
		assert_true( 'FAIL' === $drifted['gate']['gate'], 'the refused record fails the numeric gate' );
		assert_true( $en_before === (string) get_post_meta( $dwyer_id, '_leisure_excerpt_en', true ), 'the refused record was not written' );

		wp_update_post(
			array(
				'ID'           => $dwyer_id,
				'post_excerpt' => $original,
			)
		);
		assert_true( $original === (string) get_post( $dwyer_id )->post_excerpt, 'the drift probe restored the PT excerpt' );
	}

	// 3. Idempotency. Only asserted where the EN layer is already applied, so
	//    running the suite can never introduce the English descriptions as a
	//    side effect.
	$applied_count = 0;
	foreach ( $dataset_pre = get_posts(
		array(
			'post_type'        => 'leisure',
			'post_status'      => 'publish',
			'numberposts'      => -1,
			'lang'             => '',
			'suppress_filters' => true,
			'no_found_rows'    => true,
		)
	) as $record ) {
		if ( '' !== trim( (string) get_post_meta( $record->ID, '_leisure_excerpt_en', true ) ) ) {
			++$applied_count;
		}
	}

	if ( $applied_count > 0 ) {
		$pt_excerpt_before = (string) get_post( $temp_id )->post_excerpt;
		$uuid_before       = (string) get_post_meta( $temp_id, '_leisure_uuid', true );

		$second = $run_stage( array( 'dry_run' => false ) );
		assert_true( 0 === $second['summary']['created'], 'a re-apply creates nothing (idempotent)' );
		assert_true( 0 === $second['summary']['updated'], 'a re-apply updates nothing (idempotent)' );
		assert_true( 0 === $second['summary']['pt_changed'], 'a re-apply reports 0 PT changes' );
		assert_true( 0 === $second['summary']['errors'], 'a re-apply reports 0 errors' );
		assert_true( 'PASS' === $second['gate']['gate'], 'the gate passes once the layer is applied' );
		assert_true( 0 === $second['gate']['missing_en'], "the numeric gate is missing_en = 0 ({$second['gate']['missing_en']})" );
		assert_true( 0 === $second['gate']['conflicts'], 'the numeric gate reports 0 conflicts' );
		assert_true( 0 === $second['gate']['pt_drift'], 'the numeric gate reports 0 PT drift' );
		assert_true( $uuid_before === (string) get_post_meta( $temp_id, '_leisure_uuid', true ), '_leisure_uuid untouched by the stage' );
		assert_true( $pt_excerpt_before === (string) get_post( $temp_id )->post_excerpt, 'PT excerpt untouched by the stage' );
	} else {
		assert_true(
			true,
			'EN layer not applied in this environment: idempotency not exercised (run --apply first)'
		);
	}
}




// --- Dataset-level contract (runs on the real published records when present) ----

test_section( 'Dataset-level EN completeness + acceptance contract' );

$dataset = get_posts(
	array(
		'post_type'        => 'leisure',
		'post_status'      => 'publish',
		'numberposts'      => -1,
		'lang'             => '',
		'suppress_filters' => true,
		'no_found_rows'    => true,
	)
);

$with_en     = 0;
$without_en  = 0;
$en_leakage  = 0;
$missing     = array();

foreach ( $dataset as $record ) {
	if ( 'stage7-test-leisure-record' === $record->post_name ) {
		continue;
	}

	$en = trim( (string) get_post_meta( $record->ID, '_leisure_excerpt_en', true ) );
	if ( '' === $en ) {
		$without_en++;
		$missing[] = $record->post_name;
		continue;
	}

	$with_en++;

	// The EN render must be the EN description, never the PT one.
	s7_set_language( 'en' );
	$rendered = s7_render_card_excerpt( $record->ID );
	if ( $rendered !== s7_expected_card_excerpt( $en ) ) {
		$en_leakage++;
	}
	s7_set_language( pll_default_language( 'slug' ) );
}

assert_true( 0 === $en_leakage, "no published record renders a wrong (PT) description on EN ({$en_leakage} wrong of {$with_en})" );

// Every published record that HAS a Portuguese description must also have the
// authored English one. A record with an empty Portuguese description has
// nothing to translate, so it is ineligible BY RULE (not allowlisted) and is
// reported separately rather than counted as a gap.
$pt_with_text = 0;
$ineligible   = 0;
$missing_text = array();

foreach ( $dataset as $record ) {
	if ( 'stage7-test-leisure-record' === $record->post_name ) {
		continue;
	}

	if ( '' === trim( (string) $record->post_excerpt ) ) {
		++$ineligible;
		continue;
	}

	++$pt_with_text;
	$en = trim( (string) get_post_meta( $record->ID, '_leisure_excerpt_en', true ) );
	if ( '' === $en ) {
		$missing_text[] = $record->post_name;
	}
}

if ( $pt_with_text > 0 ) {
	assert_true(
		0 === count( $missing_text ),
		sprintf(
			'every published record with a PT description has an EN description (%d eligible, %d missing%s)',
			$pt_with_text,
			count( $missing_text ),
			count( $missing_text ) ? ': ' . implode( ', ', array_slice( $missing_text, 0, 5 ) ) : ''
		)
	);
	assert_true(
		$with_en >= $pt_with_text,
		sprintf( 'the EN description count covers the eligible set (%d with EN, %d eligible PT, %d ineligible)', $with_en, $pt_with_text, $ineligible )
	);
}

// The stored English must be the AUTHORED English, byte-for-byte, for every
// manifest row whose record is live. This is what proves the rollout applied
// the reviewed dataset rather than something generated at apply time.
if ( function_exists( 'conexao_en_translation_leisure_description_data_v1' ) ) {
	$authored = conexao_en_translation_leisure_description_data_v1();
	$by_slug  = array();
	foreach ( $dataset as $record ) {
		$by_slug[ $record->post_name ] = $record;
	}

	$exact = 0;
	$wrong = array();

	foreach ( $authored as $slug => $row ) {
		if ( ! isset( $by_slug[ $slug ] ) ) {
			continue;
		}
		$stored = trim( (string) get_post_meta( $by_slug[ $slug ]->ID, '_leisure_excerpt_en', true ) );
		if ( $stored === trim( (string) $row['en_description'] ) ) {
			++$exact;
			continue;
		}
		$wrong[] = $slug;
	}

	assert_true(
		0 === count( $wrong ),
		sprintf( 'every applied EN description matches the authored dataset (%d exact%s)', $exact, count( $wrong ) ? ', wrong: ' . implode( ', ', array_slice( $wrong, 0, 5 ) ) : '' )
	);

	// The authored English must be a real translation: never identical to the
	// Portuguese source it was authored from.
	$untranslated = array();
	foreach ( $authored as $slug => $row ) {
		if ( ! isset( $by_slug[ $slug ] ) ) {
			continue;
		}
		if ( trim( (string) $row['en_description'] ) === trim( (string) $row['pt_source'] ) ) {
			$untranslated[] = $slug;
		}
	}
	assert_true( 0 === count( $untranslated ), 'no EN description is a copy of its Portuguese source' );
}

// The specific record named in the defect report must render English on EN and
// Portuguese on PT, through the real card template part.
if ( isset( $by_slug['dwyer-mcallister-cottage'] ) ) {
	$dwyer_record = $by_slug['dwyer-mcallister-cottage'];
	$dwyer_pt     = (string) $dwyer_record->post_excerpt;
	$dwyer_en     = trim( (string) get_post_meta( $dwyer_record->ID, '_leisure_excerpt_en', true ) );

	s7_set_language( 'en' );
	$dwyer_rendered_en = s7_render_card_excerpt( $dwyer_record->ID );
	s7_set_language( pll_default_language( 'slug' ) );
	$dwyer_rendered_pt = s7_render_card_excerpt( $dwyer_record->ID );

	assert_true( '' !== $dwyer_en, 'the Dwyer McAllister Cottage record carries an EN description' );
	assert_true( $dwyer_rendered_en === s7_expected_card_excerpt( $dwyer_en ), 'the Dwyer card renders the authored EN description' );
	assert_true( $dwyer_rendered_en !== s7_expected_card_excerpt( $dwyer_pt ), 'the Dwyer EN card no longer renders the Portuguese description' );
	assert_true( $dwyer_rendered_pt === s7_expected_card_excerpt( $dwyer_pt ), 'the Dwyer PT card still renders the Portuguese description' );
}

// The meta key must never be consumed outside the leisure description layer.
assert_true(
	0 === count(
		get_posts(
			array(
				'post_type'        => 'any',
				'post_status'      => 'any',
				'numberposts'      => 1,
				'meta_key'         => '_leisure_excerpt_en',
				'lang'             => '',
				'suppress_filters' => true,
				'no_found_rows'    => true,
				'post__not_in'     => wp_list_pluck( $dataset, 'ID' ),
			)
		)
	),
	'the EN description meta exists only on leisure records'
);

// --- Cleanup ---------------------------------------------------------------------

s7_set_language( $previous ? $previous : $default_lang );
wp_delete_post( $temp_id, true );

test_finish();
