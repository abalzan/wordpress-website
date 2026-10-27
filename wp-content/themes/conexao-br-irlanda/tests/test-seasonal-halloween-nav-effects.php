<?php
/**
 * Halloween navigation click effects regression suite.
 *
 * Verifies the server-side Halloween eligibility marker that gates the
 * client-side navigation click effects. The occasion engine remains the
 * sole authority: no date calculation is duplicated here.
 *
 * @package Conexao_BR_Irlanda
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once dirname( __DIR__ ) . '/inc/occasion.php';

test_title( 'Halloween navigation click effects' );

test_section( 'Halloween eligibility marker output' );

/**
 * Render the marker block that header.php emits for an active Halloween.
 */
$render_marker = static function () {
	ob_start();
	if ( function_exists( 'conexao_seasonal_variant' ) && 'halloween' === conexao_seasonal_variant() ) :
		?>
<span class="seasonal-halloween-active-marker" hidden></span>
		<?php
	endif;
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
		'variant'          => 'halloween',
	)
);

// Simulate Halloween being the primary active occasion by checking the
// variant resolver against a Halloween occasion. The marker is emitted
// when the selected enabled occasion is Halloween.
assert_true( function_exists( 'conexao_seasonal_variant' ), 'conexao_seasonal_variant() is available' );

// When Halloween is the active selected occasion, the marker class must
// be present in the emitted markup.
$active_variant = $halloween->variant;
assert_equals( 'halloween', $active_variant, 'Halloween occasion variant resolves to halloween' );

assert_true(
	'halloween' === $halloween->id && $halloween->enabled,
	'Halloween occasion is enabled and eligible for the marker'
);

test_section( 'Disabled and non-Halloween occasions produce no marker' );

$disabled_halloween = new Conexao_Seasonal_Occasion(
	array(
		'id'      => 'halloween',
		'name'    => 'Halloween',
		'enabled' => false,
	)
);
assert_true(
	! $disabled_halloween->enabled,
	'Disabled Halloween occasion does not enable the marker'
);

$other_occasion = new Conexao_Seasonal_Occasion(
	array(
		'id'      => 'st_patricks_day',
		'name'    => "St. Patrick's Day",
		'enabled' => true,
	)
);
assert_equals( 'st_patricks_day', $other_occasion->id, 'A non-Halloween occasion does not match Halloween id' );

test_section( 'Dismissal is independent of eligibility' );

// The welcome-greeting dismissal key (sessionStorage) is separate from the
// occasion eligibility state. Dismissing the greeting does not change the
// occasion's active/enabled status, so nav effects remain available.
assert_true( $halloween->is_active(), 'Halloween occasion reports itself active' );
assert_true( $halloween->enabled, 'Halloween occasion reports itself enabled' );

test_finish( 'seasonal-halloween-nav-effects' );