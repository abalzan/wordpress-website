<?php
/**
 * Conexão BR Irlanda — Seasonal and Commemorative Screen Foundation
 *
 * Provides a deterministic occasion eligibility check for seasonal/commemorative
 * screens. Designed as a reusable foundation that can be extended with visual
 * templates without rewriting core lifecycle logic.
 *
 * Key design decisions:
 * - Dynamic, request-time eligibility check (no scheduled task dependency)
 * - Configuration-driven occasions (PHP config file, no new DB table)
 * - Language isolation through existing Polylang/conexao conventions
 * - Cache-safe: eligibility computed per request to avoid stale state
 * - Safe failure: invalid/ambiguous config never activates a screen
 *
 * @package conexao-br-irlanda
 */

// Quick guard
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Occasion date rule types.
 */
enum Occasion_Date_Rule: string {
	case FIXED_ANNUAL = 'fixed_annual';
	case VARIABLE_ANNUAL = 'variable_annual';
	case ONE_OFF = 'one_off';
}

/**
 * Conexao_Seasonal_Occasion — occasion model and eligibility logic.
 */
class Conexao_Seasonal_Occasion {

	public $id;
	public $name;
	public $date_rule;
	public $fixed_month_day;
	public $variable_rule;
	public $one_off_date;
	public $lead_in_days;
	public $enabled;
	public $destination;
	public $variant;

	public function __construct( array $args ) {
		$this->id           = $args['id'] ?? false;
		$this->name         = $args['name'] ?? '';
		$this->date_rule    = $args['date_rule'] ?? Occasion_Date_Rule::FIXED_ANNUAL;
		$this->fixed_month_day   = $args['fixed_month_day'] ?? false;
		$this->variable_rule   = $args['variable_rule'] ?? false;
		$this->one_off_date    = $args['one_off_date'] ?? false;
		$this->lead_in_days   = (int) ($args['lead_in_days'] ?? 15);
		$this->enabled        = ! ! ( $args['enabled'] ?? true );
		$this->destination    = $args['destination'] ?? false;
		$this->variant        = $args['variant'] ?? false;

		if ( ! $this->id ) {
			throw new InvalidArgumentException( 'Occasion must have a stable identifier.' );
		}
	}

	public function resolve_occurrence_date(): ?DateTime {
		$now = new DateTime( 'now', new DateTimeZone( 'Europe/Lisbon' ) );
		$year = $now->format( 'Y' );

		switch ( $this->date_rule ) {
			case Occasion_Date_Rule::FIXED_ANNUAL:
				if ( $this->fixed_month_day ) {
					list( $month, $day ) = explode( '-', $this->fixed_month_day, 2 );
					$result = DateTime::createFromFormat( 'm-d-Y', $month . '-' . $day . '-' . $year );
					if ( $result && $result->format( 'm-d' ) === $month . '-' . $day ) {
						return $result;
					}
				}
				return false;

			case Occasion_Date_Rule::VARIABLE_ANNUAL:
				if ( $this->variable_rule === 'easter' ) {
					$easter = easter_date( $year );
					if ( $easter ) {
						return date_create_from_format( 'U', $easter, new DateTimeZone( 'Europe/Lisbon' ) );
					}
				}
				return false;

			case Occasion_Date_Rule::ONE_OFF:
				if ( $this->one_off_date ) {
					$result = DateTime::createFromFormat( 'Y-m-d', $this->one_off_date );
					if ( $result ) {
						return $result;
					}
				}
				return false;

			default:
				return false;
		}
	}

	public function resolve_start_date(): ?DateTime {
		$occurrence = $this->resolve_occurrence_date();
		if ( ! $occurrence ) {
			return false;
		}
		$start = clone $occurrence;
		$start->modify( '-' . $this->lead_in_days . ' days' );
		$start->setTime( 0, 0, 0 );
		return $start;
	}

	public function resolve_end_date(): ?DateTime {
		$occurrence = $this->resolve_occurrence_date();
		if ( ! $occurrence ) {
			return false;
		}
		$end = clone $occurrence;
		$end->setTime( 23, 59, 59 );
		return $end;
	}

	public function is_active(): bool {
		$start = $this->resolve_start_date();
		$end   = $this->resolve_end_date();

		if ( ! $start || ! $end ) {
			return false;
		}

		$now = new DateTime( 'now', new DateTimeZone( 'Europe/Lisbon' ) );

		return ( $now >= $start && $now <= $end );
	}

	public function get_display_content(): array {
		$locale = function_exists( 'conexao_current_locale' ) ? conexao_current_locale() : 'pt_BR';
		return [
			'heading'    => sprintf( __( 'Seasonal Occasion: %s', 'conexao-br-irlanda' ), $this->name ),
			'copy'       => '',
			'image'      => false,
			'cta_text'   => '',
			'destination' => $this->destination,
		];
	}
}

function ocasons_register(): array {
	$occasions = array();

	$occasions[] = new Conexao_Seasonal_Occasion( [
		'id'           => 'christmas',
		'name'         => __( 'Christmas', 'conexao-br-irlanda' ),
		'date_rule'    => Occasion_Date_Rule::FIXED_ANNUAL,
		'fixed_month_day' => '12-25',
		'lead_in_days' => 15,
		'enabled'      => true,
		'variant'      => 'christmas',
	] );

	$occasions[] = new Conexao_Seasonal_Occasion( [
		'id'           => 'halloween',
		'name'         => __( 'Halloween', 'conexao-br-irlanda' ),
		'date_rule'    => Occasion_Date_Rule::FIXED_ANNUAL,
		'fixed_month_day' => '10-31',
		'lead_in_days' => 15,
		'enabled'      => true,
		'variant'      => 'halloween',
	] );

	$occasions[] = new Conexao_Seasonal_Occasion( [
		'id'           => 'new_year',
		'name'         => __( 'New Year', 'conexao-br-irlanda' ),
		'date_rule'    => Occasion_Date_Rule::FIXED_ANNUAL,
		'fixed_month_day' => '01-01',
		'lead_in_days' => 15,
		'enabled'      => true,
		'variant'      => 'new_year',
	] );

	$occasions[] = new Conexao_Seasonal_Occasion( [
		'id'           => 'st_patricks_day',
		'name'         => __( 'St. Patrick\'s Day', 'conexao-br-irlanda' ),
		'date_rule'    => Occasion_Date_Rule::FIXED_ANNUAL,
		'fixed_month_day' => '03-17',
		'lead_in_days' => 15,
		'enabled'      => true,
		'variant'      => 'st_patricks_day',
	] );

	$occasions[] = new Conexao_Seasonal_Occasion( [
		'id'           => 'valentines_day',
		'name'         => __( 'Valentine\'s Day', 'conexao-br-irlanda' ),
		'date_rule'    => Occasion_Date_Rule::FIXED_ANNUAL,
		'fixed_month_day' => '02-14',
		'lead_in_days' => 15,
		'enabled'      => true,
		'variant'      => 'valentines_day',
	] );

	$occasions[] = new Conexao_Seasonal_Occasion( [
		'id'           => 'easter',
		'name'         => __( 'Easter', 'conexao-br-irlanda' ),
		'date_rule'    => Occasion_Date_Rule::VARIABLE_ANNUAL,
		'variable_rule' => 'easter',
		'lead_in_days' => 15,
		'enabled'      => true,
		'variant'      => 'easter',
	] );

	return $occasions;
}

function ocasons_get_all(): array {
	static $registered = null;
	if ( $registered === null ) {
		$registered = ocasons_register();
	}
	return $registered;
}

function ocasons_get_active(): array {
	$all = ocasons_get_active_no_filter();
	return array_filter( $all, function( $occasion ) {
		return $occasion->is_active() && $occasion->enabled;
	} );
}

function ocasons_get_active_no_filter(): array {
	$all = ocasons_get_all();
	$active = array();
	foreach ( $all as $occasion ) {
		if ( $occasion->is_active() ) {
			$active[] = $occasion;
		}
	}
	return $active;
}

function ocons_get_primary_active(): ?Conexao_Seasonal_Occasion {
	$active = ocasons_get_active();
	return count( $active ) > 0 ? $active[0] : false;
}

function ocacons_get_primary_display_content(): array {
	$occasion = ocons_get_primary_active();
	if ( ! $occasion ) {
		return false;
	}
	return $occasion->get_display_content();
}

function conexao_seasonal_is_active(): bool {
	return occons_get_primary_active() !== false;
}

function conexao_seasonal_variant(): ?string {
	$occasion = occons_get_primary_active();
	return $occasion ? $occasion->variant : false;
}
