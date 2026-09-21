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
$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

// Load the active theme so the helpers under test are defined.
$theme_functions = get_template_directory() . '/functions.php';
if ( file_exists( $theme_functions ) ) {
	require_once $theme_functions;
}

$passed = 0;
$failed = 0;

function t_assert( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		echo "  PASS: {$message}\n";
	} else {
		$failed++;
		echo "  FAIL: {$message}\n";
	}
}

echo "== Recurrence i18n presentation (Stage 1) ==\n";

// --- PT output must remain byte-identical to the pre-refactor wording. ---
t_assert(
	'Toda quarta-feira' === conexao_recurrence_present_days( array( 3 ) ),
	'PT single weekday: "Toda quarta-feira"'
);
t_assert(
	'Toda segunda e quarta' === conexao_recurrence_present_days( array( 1, 3 ) ),
	'PT two weekdays: "Toda segunda e quarta"'
);
t_assert(
	'Toda segunda, quarta e sexta' === conexao_recurrence_present_days( array( 1, 3, 5 ) ),
	'PT three weekdays: "Toda segunda, quarta e sexta"'
);
t_assert(
	'Toda sábado e domingo' === conexao_recurrence_present_days( array( 6, 7 ) ),
	'PT weekend (no -feira suffix): "Toda sábado e domingo"'
);
t_assert(
	'Toda segunda, terça, quinta e sexta' === conexao_recurrence_present_days( array( 1, 2, 4, 5 ) ),
	'PT four weekdays: "Toda segunda, terça, quinta e sexta"'
);

// --- The template entry point is still presentation-only and empty-safe. ---
t_assert( '' === conexao_recurrence_present_days( array() ), 'empty day list renders empty string' );
t_assert( '' === conexao_recurrence_present_days( array( 9, 12 ) ), 'invalid ISO codes render empty string' );

// --- End-to-end label stays '' for non-recurring events. ---
t_assert( '' === conexao_event_recurrence_label( 0 ), 'non-existent event renders empty recurrence label' );

// --- EN output via the theme's en_US catalog (not publicly active yet). ---
switch_to_locale( 'en_US' );

t_assert(
	'Every Wednesday' === conexao_recurrence_present_days( array( 3 ) ),
	'EN single weekday: "Every Wednesday"'
);
t_assert(
	'Every Monday and Wednesday' === conexao_recurrence_present_days( array( 1, 3 ) ),
	'EN two weekdays: "Every Monday and Wednesday"'
);
t_assert(
	'Every Monday, Wednesday and Friday' === conexao_recurrence_present_days( array( 1, 3, 5 ) ),
	'EN three weekdays: "Every Monday, Wednesday and Friday"'
);
t_assert(
	'Every Saturday and Sunday' === conexao_recurrence_present_days( array( 6, 7 ) ),
	'EN weekend: "Every Saturday and Sunday"'
);

restore_current_locale();

echo "\nRecurrence i18n: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
