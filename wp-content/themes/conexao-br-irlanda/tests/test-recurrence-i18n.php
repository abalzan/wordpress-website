<?php
/**
 * Tests for the Stage 1 recurrence i18n presentation layer.
 *
 * Verifies `conexao_recurrence_present_days()` (the presentation engine
 * behind `conexao_event_recurrence_label()`) after the Stage 1 i18n
 * refactor:
 *
 *  - PT output is EXACTLY the current site wording (byte-identical):
 *      "Toda quarta-feira", "Toda segunda e quarta",
 *      "Toda segunda, quarta e sexta", "Toda sábado e domingo".
 *  - EN output (via switch_to_locale + the theme's en_US catalog) is
 *      natural English: "Every Wednesday", "Every Monday and Wednesday",
 *      "Every Monday, Wednesday and Friday".
 *  - Single/multiple day handling, the PT-only "-feira" stripping rule,
 *      and edge cases (empty list, invalid ISO codes) behave as before.
 *
 * The recurrence MODEL is not touched here: ISO weekday codes 1–7 only,
 * presentation only.
 *
 * Usage (from the project root):
 *   docker compose exec wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-recurrence-i18n.php
 */

// --- Bootstrap WordPress (plugins + theme option). ---

// Load the active theme so the helpers under test are defined.
// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$theme_functions = get_template_directory() . '/functions.php';
if ( file_exists( $theme_functions ) ) {
	require_once $theme_functions;
}

$passed = 0;
$failed = 0;


// --- PT output must remain byte-identical to the pre-refactor wording. ---
assert_true(
	'Toda quarta-feira' === conexao_recurrence_present_days( array( 3 ) ),
	'PT single weekday: "Toda quarta-feira"'
);
assert_true(
	'Toda segunda e quarta' === conexao_recurrence_present_days( array( 1, 3 ) ),
	'PT two weekdays: "Toda segunda e quarta"'
);
assert_true(
	'Toda segunda, quarta e sexta' === conexao_recurrence_present_days( array( 1, 3, 5 ) ),
	'PT three weekdays: "Toda segunda, quarta e sexta"'
);
assert_true(
	'Toda sábado e domingo' === conexao_recurrence_present_days( array( 6, 7 ) ),
	'PT weekend (no -feira suffix): "Toda sábado e domingo"'
);
assert_true(
	'Toda segunda, terça, quinta e sexta' === conexao_recurrence_present_days( array( 1, 2, 4, 5 ) ),
	'PT four weekdays: "Toda segunda, terça, quinta e sexta"'
);

// --- The template entry point is still presentation-only and empty-safe. ---
assert_true( '' === conexao_recurrence_present_days( array() ), 'empty day list renders empty string' );
assert_true( '' === conexao_recurrence_present_days( array( 9, 12 ) ), 'invalid ISO codes render empty string' );

// --- End-to-end label stays '' for non-recurring events. ---
assert_true( '' === conexao_event_recurrence_label( 0 ), 'non-existent event renders empty recurrence label' );

// --- EN output via the theme's en_US catalog (not publicly active yet). ---
switch_to_locale( 'en_US' );

assert_true(
	'Every Wednesday' === conexao_recurrence_present_days( array( 3 ) ),
	'EN single weekday: "Every Wednesday"'
);
assert_true(
	'Every Monday and Wednesday' === conexao_recurrence_present_days( array( 1, 3 ) ),
	'EN two weekdays: "Every Monday and Wednesday"'
);
assert_true(
	'Every Monday, Wednesday and Friday' === conexao_recurrence_present_days( array( 1, 3, 5 ) ),
	'EN three weekdays: "Every Monday, Wednesday and Friday"'
);
assert_true(
	'Every Saturday and Sunday' === conexao_recurrence_present_days( array( 6, 7 ) ),
	'EN weekend: "Every Saturday and Sunday"'
);

restore_current_locale();

test_finish();
