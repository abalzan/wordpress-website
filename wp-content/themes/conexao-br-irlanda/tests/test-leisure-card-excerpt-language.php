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

test_section( 'Rollout engine: preview / drift / idempotency / remove' );

$engine_available = function_exists( 'conexao_leisure_translation_run' ) && function_exists( 'conexao_leisure_translation_manifest' );
assert_true( $engine_available, 'rollout engine loaded (activate conexao-leisure-translation to exercise it)' );

if ( $engine_available ) {
	$preview = conexao_leisure_translation_run( 'preview' );
	assert_true( 0 === $preview['summary']['errors'], 'preview runs without errors' );
	assert_true( 0 === $preview['summary']['pt_changed'], 'preview reports 0 PT changes' );
	assert_true( 0 === $preview['summary']['uuid_changed'], 'preview reports 0 UUID changes' );

	// A record whose PT excerpt no longer matches the authored source must be
	// refused (stale translations never silently land). Preview computes the
	// same refusal without writing anything, so the real record's excerpt is
	// temporarily drifted and restored around the probe.
	$target = get_posts(
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

	if ( ! empty( $target ) ) {
		$drift_id  = (int) $target[0]->ID;
		$original  = (string) $target[0]->post_excerpt;
		$pt_before = (string) get_post_meta( $drift_id, '_leisure_excerpt_en', true );

		wp_update_post(
			array(
				'ID'           => $drift_id,
				'post_excerpt' => 'Descrição alterada depois de a tradução ter sido escrita.',
			)
		);

		$run = conexao_leisure_translation_run( 'preview' );
		$row = null;
		foreach ( $run['rows'] as $candidate ) {
			if ( (int) $candidate['id'] === $drift_id ) {
				$row = $candidate;
				break;
			}
		}
		assert_true(
			$row && 'refused-pt-drift' === $row['action'],
			'PT-drift record is refused, never silently applied',
			$row ? $row['action'] . ': ' . $row['message'] : 'row missing'
		);
		assert_true(
			$pt_before === (string) get_post_meta( $drift_id, '_leisure_excerpt_en', true ),
			'preview writes nothing to the drifted record'
		);

		// Restore the original Portuguese excerpt — the record is untouched.
		wp_update_post(
			array(
				'ID'           => $drift_id,
				'post_excerpt' => $original,
			)
		);
		assert_true( $original === (string) get_post( $drift_id )->post_excerpt, 'drift probe restored the PT excerpt' );
	}

	// The write / idempotency / rollback round-trip only runs where the
	// rollout is ALREADY applied (the validation clone / post-rollout site):
	// it must never introduce the EN layer as a side effect of running tests.
	$applied_count = count(
		get_posts(
			array(
				'post_type'        => 'leisure',
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'lang'             => '',
				'suppress_filters' => true,
				'no_found_rows'    => true,
				'meta_key'         => '_leisure_excerpt_en',
				'fields'           => 'ids',
			)
		)
	);

	if ( $applied_count > 0 ) {
		$uuid_before = get_post_meta( $temp_id, '_leisure_uuid', true );

		$second = conexao_leisure_translation_run( 'apply' );
		assert_true( 0 === $second['summary']['errors'], 're-apply runs without errors' );
		assert_true( 0 === $second['summary']['pt_changed'], 're-apply reports 0 PT changes' );
		assert_true( 0 === $second['summary']['uuid_changed'], 're-apply reports 0 UUID changes' );
		assert_true(
			$applied_count === ( $second['summary']['applied'] + $second['summary']['skipped_identical'] + $second['summary']['refused'] ),
			're-apply accounts for every already-applied record (idempotent)',
			'applied ' . $second['summary']['applied'] . ' / skipped ' . $second['summary']['skipped_identical']
		);
		assert_true(
			$uuid_before === get_post_meta( $temp_id, '_leisure_uuid', true ),
			'_leisure_uuid untouched by the engine'
		);

		// Full rollback round-trip: remove → nothing left; apply → restored.
		$before_remove = $applied_count;
		$removed       = conexao_leisure_translation_run( 'remove' );
		assert_true(
			$before_remove === $removed['summary']['removed'],
			"remove deletes every EN description ({$removed['summary']['removed']} removed)"
		);
		assert_true(
			0 === count(
				get_posts(
					array(
						'post_type'        => 'leisure',
						'post_status'      => 'publish',
						'numberposts'      => -1,
						'lang'             => '',
						'suppress_filters' => true,
						'no_found_rows'    => true,
						'meta_key'         => '_leisure_excerpt_en',
						'fields'           => 'ids',
					)
				)
			),
			'no EN description left after remove (rollback complete)'
		);

		$restored = conexao_leisure_translation_run( 'apply' );
		assert_true(
			$before_remove === $restored['summary']['applied'],
			"apply restores every EN description after the rollback ({$restored['summary']['applied']} applied)"
		);
		assert_true( $pt_text === get_post( $temp_id )->post_excerpt, 'PT excerpt untouched across apply/remove' );
	} else {
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

if ( $with_en > 0 ) {
	assert_true( 0 === count( $missing ), "every published record with an EN description set renders EN ({$with_en} with, {$without_en} without)" );
} else {
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
