<?php
/**
 * Halloween homepage presentation regression suite.
 *
 * @package Conexao_BR_Irlanda
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once dirname( __DIR__ ) . '/inc/occasion.php';

test_title( 'Halloween homepage presentation' );
test_section( 'Selected occasion controls presentation output' );

$render_presentation = static function ( $occasion ) {
	ob_start();
	get_template_part(
		'template-parts/halloween-hero',
		null,
		array( 'seasonal_occasion' => $occasion )
	);
	return ob_get_clean();
};

$halloween = new Conexao_Seasonal_Occasion(
	array(
		'id'               => 'halloween',
		'name'             => 'Halloween',
		'date_rule'        => Occasion_Date_Rule::FIXED_ANNUAL,
		'fixed_month_day'  => '10-31',
		'lead_in_days'     => 15,
		'enabled'          => true,
	)
);
$halloween_markup = $render_presentation( $halloween );

assert_contains( 'seasonal-halloween-presentation', $halloween_markup, 'the selected Halloween occasion renders its presentation' );
assert_contains( 'hidden', $halloween_markup, 'the presentation stays hidden until the deferred enhancement initializes' );
assert_contains( 'seasonal-halloween-title', $halloween_markup, 'the presentation has a labelled semantic heading' );
assert_true(
	false !== strpos( $halloween_markup, 'Feliz Halloween!' ) || false !== strpos( $halloween_markup, 'Happy Halloween!' ),
	'the presentation renders the localized Halloween greeting'
);
assert_contains( 'type="button"', $halloween_markup, 'the dismiss control is a real button' );
assert_true(
	false !== strpos( $halloween_markup, 'Fechar mensagem de Halloween' ) || false !== strpos( $halloween_markup, 'Close Halloween message' ),
	'the dismiss control has a localized accessible name'
);
assert_contains( 'seasonal-halloween-bat-wing--left', $halloween_markup, 'bat artwork includes distinct articulated wings' );
assert_contains( 'seasonal-halloween-pumpkin', $halloween_markup, 'the greeting includes original pumpkin artwork' );

$occurrence = $halloween->resolve_occurrence_date();
assert_true( $occurrence instanceof DateTime, 'the dismissal namespace uses the selected occasion occurrence' );
if ( $occurrence instanceof DateTime ) {
	assert_contains(
		'data-dismiss-key="conexao-seasonal-halloween-' . $occurrence->format( 'Y' ) . '"',
		$halloween_markup,
		'the dismissal key is namespaced by occasion and occurrence year'
	);
}

test_section( 'Inactive, disabled, and different occasions do not render Halloween' );

assert_equals( '', trim( $render_presentation( null ) ), 'an inactive occasion renders no Halloween wrapper' );

$disabled_halloween = new Conexao_Seasonal_Occasion(
	array(
		'id'      => 'halloween',
		'name'    => 'Halloween',
		'enabled' => false,
	)
);
assert_equals( '', trim( $render_presentation( $disabled_halloween ) ), 'a disabled Halloween occasion renders no presentation' );

$other_occasion = new Conexao_Seasonal_Occasion(
	array(
		'id'      => 'st_patricks_day',
		'name'    => 'St. Patrick\'s Day',
		'enabled' => true,
	)
);
assert_equals( '', trim( $render_presentation( $other_occasion ) ), 'a different selected occasion renders no Halloween presentation' );

test_section( 'Homepage integration keeps occasion eligibility authoritative' );

$front_page_path   = get_template_directory() . '/front-page.php';
$front_page_source = file_exists( $front_page_path ) ? file_get_contents( $front_page_path ) : false;
assert_true( is_string( $front_page_source ) && '' !== $front_page_source, 'front-page.php is readable for the integration check' );
if ( is_string( $front_page_source ) ) {
	assert_contains( 'ocons_get_primary_active()', $front_page_source, 'homepage reads the existing primary occasion selection' );
	assert_contains( '\'halloween\' === $active_occasion->id && $active_occasion->enabled', $front_page_source, 'only an enabled selected Halloween occasion renders the presentation' );
	assert_contains( '\'seasonal_occasion\' => $active_occasion', $front_page_source, 'the selected occasion is passed directly to the presentation' );
}

test_finish( 'seasonal-halloween-presentation' );
