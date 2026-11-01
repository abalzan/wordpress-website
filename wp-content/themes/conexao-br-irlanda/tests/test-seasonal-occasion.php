<?php
/**
 * Seasonal occasion foundation regression suite.
 *
 * @package Conexao_BR_Irlanda
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once dirname( __DIR__ ) . '/inc/occasion.php';

echo "Seasonal occasion foundation\n";
echo str_repeat( '-', 72 ) . "\n";

$christmas = new Conexao_Seasonal_Occasion(
	array(
		'id'              => 'christmas',
		'name'            => 'Christmas',
		'date_rule'       => Occasion_Date_Rule::FIXED_ANNUAL,
		'fixed_month_day' => '12-25',
		'lead_in_days'    => 15,
		'enabled'         => true,
	)
);

$halloween = new Conexao_Seasonal_Occasion(
	array(
		'id'              => 'halloween',
		'name'            => 'Halloween',
		'date_rule'       => Occasion_Date_Rule::FIXED_ANNUAL,
		'fixed_month_day' => '10-31',
		'lead_in_days'    => 15,
		'enabled'         => true,
	)
);

$easter = new Conexao_Seasonal_Occasion(
	array(
		'id'            => 'easter',
		'name'          => 'Easter',
		'date_rule'     => Occasion_Date_Rule::VARIABLE_ANNUAL,
		'variable_rule' => 'easter',
		'lead_in_days'  => 15,
		'enabled'       => true,
	)
);

$christmas_occurrence = $christmas->resolve_occurrence_date();
assert_true( $christmas_occurrence instanceof DateTime, 'Christmas occurrence resolves to a DateTime' );
assert_equals( date( 'Y' ), $christmas_occurrence->format( 'Y' ), 'Christmas occurrence uses the current calendar year' );
assert_equals( '12-25', $christmas_occurrence->format( 'm-d' ), 'Christmas occurrence resolves to Dec 25' );

$christmas_start = $christmas->resolve_start_date();
assert_true( $christmas_start instanceof DateTime, 'Christmas start date resolves to a DateTime' );
assert_equals( '12-10', $christmas_start->format( 'm-d' ), 'Christmas start date is 15 days before the occasion' );

$christmas_end = $christmas->resolve_end_date();
assert_true( $christmas_end instanceof DateTime, 'Christmas end date resolves to a DateTime' );
assert_equals( '23:59:59', $christmas_end->format( 'H:i:s' ), 'Christmas end date is inclusive on the occasion date' );

$easter_occurrence = $easter->resolve_occurrence_date();
assert_true( $easter_occurrence instanceof DateTime, 'Easter occurrence resolves to a DateTime' );

$all_occasions = ocasons_get_all();
assert_true( is_array( $all_occasions ) && count( $all_occasions ) > 0, 'The registry contains at least one configured occasion' );
assert_true( is_callable( 'ocons_get_primary_active' ), 'Primary active selection function is available' );
assert_true( is_bool( $halloween->is_active() ), 'Fixed annual eligibility is boolean' );
assert_true( is_bool( $christmas->is_active() ), 'Date-boundary eligibility is boolean' );
assert_true( is_bool( conexao_seasonal_is_active() ), 'Seasonal screen active check returns a boolean' );

$variant = conexao_seasonal_variant();
assert_true( null === $variant || is_string( $variant ), 'Selected seasonal variant is a string or null' );
if ( null !== $variant ) {
	assert_true( in_array( $variant, array( 'christmas', 'halloween', 'new_year', 'st_patricks_day', 'valentines_day', 'easter' ), true ), 'Selected variant matches a configured occasion' );
}

$primary = ocons_get_primary_active();
if ( $primary ) {
	assert_true( $primary instanceof Conexao_Seasonal_Occasion, 'Primary active occasion is a Conexao_Seasonal_Occasion' );
	$now = new DateTime( 'now', new DateTimeZone( 'Europe/Lisbon' ) );
	$season_start = new DateTime( sprintf( '%d-10-16 00:00:00', (int) $now->format( 'Y' ) ), new DateTimeZone( 'Europe/Lisbon' ) );
	$season_end   = new DateTime( sprintf( '%d-10-31 23:59:59', (int) $now->format( 'Y' ) ), new DateTimeZone( 'Europe/Lisbon' ) );
	if ( $now >= $season_start && $now <= $season_end ) {
		assert_equals( 'halloween', $primary->id, 'Halloween is the primary occasion within its active window' );
	}
}

test_finish( 'seasonal-occasion' );
