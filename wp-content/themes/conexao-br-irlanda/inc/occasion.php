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
		$this->id               = $args['id'] ?? false;
		$this->name             = $args['name'] ?? '';
		$this->date_rule        = $args['date_rule'] ?? Occasion_Date_Rule::FIXED_ANNUAL;
		$this->fixed_month_day  = $args['fixed_month_day'] ?? false;
		$this->variable_rule    = $args['variable_rule'] ?? false;
		$this->one_off_date     = $args['one_off_date'] ?? false;
		$this->lead_in_days     = (int) ( $args['lead_in_days'] ?? 15 );
		$this->enabled          = ! ! ( $args['enabled'] ?? true );
		$this->destination      = $args['destination'] ?? false;
		$this->variant          = $args['variant'] ?? false;

		if ( ! $this->id ) {
			throw new InvalidArgumentException( 'Occasion must have a stable identifier.' );
		}
	}

	private function get_timezone(): DateTimeZone {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}

		$timezone_name = function_exists( 'get_option' ) ? get_option( 'timezone_string' ) : '';
		if ( is_string( $timezone_name ) && '' !== $timezone_name ) {
			return new DateTimeZone( $timezone_name );
		}

		return new DateTimeZone( 'Europe/Lisbon' );
	}

	private function resolve_variable_annual_date_for_year( int $year ): ?DateTime {
		if ( 'easter' !== $this->variable_rule ) {
			return null;
		}

		$a = $year % 19;
		$b = intdiv( $year, 100 );
		$c = $year % 100;
		$d = intdiv( $b, 4 );
		$e = $b % 4;
		$f = intdiv( $b + 8, 25 );
		$g = intdiv( $b - $f + 1, 3 );
		$h = ( 19 * $a + $b - $d - $g + 15 ) % 30;
		$i = intdiv( $c, 4 );
		$k = $c % 4;
		$l = ( 32 + 2 * $e + 2 * $i - $h - $k ) % 7;
		$m = intdiv( $a + 11 * $h + 22 * $l, 451 );
		$month = intdiv( $h + $l - 7 * $m + 114, 31 );
		$day = ( ( $h + $l - 7 * $m + 114 ) % 31 ) + 1;

		$timezone = $this->get_timezone();
		$date = DateTime::createFromFormat( '!Y-m-d', sprintf( '%04d-%02d-%02d', $year, $month, $day ), $timezone );
		if ( ! $date ) {
			return null;
		}
		$date->setTime( 0, 0, 0 );
		return $date;
	}

	public function resolve_occurrence_date( ?DateTimeInterface $now = null ): ?DateTime {
		$timezone = $this->get_timezone();
		$clock    = $now instanceof DateTimeInterface
			? DateTimeImmutable::createFromInterface( $now )
			: new DateTimeImmutable( 'now', $timezone );
		$year     = (int) $clock->setTimezone( $timezone )->format( 'Y' );

		switch ( $this->date_rule ) {
			case Occasion_Date_Rule::FIXED_ANNUAL:
				if ( $this->fixed_month_day ) {
					$parts = explode( '-', $this->fixed_month_day, 2 );
					if ( 2 !== count( $parts ) || ! is_numeric( $parts[0] ) || ! is_numeric( $parts[1] ) ) {
						return null;
					}

					$month = (int) $parts[0];
					$day   = (int) $parts[1];
					$result = DateTime::createFromFormat( '!m-d-Y', sprintf( '%02d-%02d-%04d', $month, $day, $year ), $timezone );
					if ( $result && $result->format( 'm-d' ) === sprintf( '%02d-%02d', $month, $day ) ) {
						return $result;
					}
				}
				return null;

			case Occasion_Date_Rule::VARIABLE_ANNUAL:
				return $this->resolve_variable_annual_date_for_year( $year );

			case Occasion_Date_Rule::ONE_OFF:
				if ( $this->one_off_date ) {
					$result = DateTime::createFromFormat( '!Y-m-d', $this->one_off_date, $timezone );
					if ( $result ) {
						return $result;
					}
				}
				return null;

			default:
				return null;
		}
	}

	public function resolve_start_date( ?DateTimeInterface $now = null ): ?DateTime {
		$occurrence = $this->resolve_occurrence_date( $now );
		if ( ! $occurrence ) {
			return null;
		}
		$start = clone $occurrence;
		$start->modify( '-' . $this->lead_in_days . ' days' );
		$start->setTime( 0, 0, 0 );
		return $start;
	}

	public function resolve_end_date( ?DateTimeInterface $now = null ): ?DateTime {
		$occurrence = $this->resolve_occurrence_date( $now );
		if ( ! $occurrence ) {
			return null;
		}
		$end = clone $occurrence;
		$end->setTime( 23, 59, 59 );
		return $end;
	}

	/**
	 * Whether the occasion is inside its window.
	 *
	 * r10 §9.1: every scheduling assertion must inject the clock, so this method
	 * accepts an optional `$now` instead of always reading the wall clock. The
	 * default (`null`) keeps the historical behaviour for every existing caller.
	 *
	 * @param DateTimeInterface|null $now Injected clock (site timezone assumed).
	 * @return bool
	 */
	public function is_active( ?DateTimeInterface $now = null ): bool {
		$start = $this->resolve_start_date( $now );
		$end   = $this->resolve_end_date( $now );

		if ( ! $start || ! $end ) {
			return false;
		}

		$clock = $now instanceof DateTimeInterface
			? DateTime::createFromInterface( $now )->setTimezone( $this->get_timezone() )
			: new DateTime( 'now', $this->get_timezone() );

		return ( $clock >= $start && $clock <= $end );
	}

	public function get_display_content(): array {
		$locale = function_exists( 'conexao_current_locale' ) ? conexao_current_locale() : 'pt_BR';
		return array(
			'heading'    => sprintf( __( 'Seasonal Occasion: %s', 'conexao-br-irlanda' ), $this->name ),
			'copy'       => '',
			'image'      => false,
			'cta_text'   => '',
			'destination' => $this->destination,
		);
	}
}

function ocasons_register(): array {
	$occasions = array();

	$occasions[] = new Conexao_Seasonal_Occasion( array(
		'id'               => 'christmas',
		'name'             => __( 'Christmas', 'conexao-br-irlanda' ),
		'date_rule'        => Occasion_Date_Rule::FIXED_ANNUAL,
		'fixed_month_day'  => '12-25',
		'lead_in_days'     => 15,
		'enabled'          => true,
		'variant'          => 'christmas',
	) );

	$occasions[] = new Conexao_Seasonal_Occasion( array(
		'id'               => 'halloween',
		'name'             => __( 'Halloween', 'conexao-br-irlanda' ),
		'date_rule'        => Occasion_Date_Rule::FIXED_ANNUAL,
		'fixed_month_day'  => '10-31',
		'lead_in_days'     => 15,
		'enabled'          => true,
		'variant'          => 'halloween',
	) );

	$occasions[] = new Conexao_Seasonal_Occasion( array(
		'id'               => 'new_year',
		'name'             => __( 'New Year', 'conexao-br-irlanda' ),
		'date_rule'        => Occasion_Date_Rule::FIXED_ANNUAL,
		'fixed_month_day'  => '01-01',
		'lead_in_days'     => 15,
		'enabled'          => true,
		'variant'          => 'new_year',
	) );

	$occasions[] = new Conexao_Seasonal_Occasion( array(
		'id'               => 'st_patricks_day',
		'name'             => __( 'St. Patrick\'s Day', 'conexao-br-irlanda' ),
		'date_rule'        => Occasion_Date_Rule::FIXED_ANNUAL,
		'fixed_month_day'  => '03-17',
		'lead_in_days'     => 15,
		'enabled'          => true,
		'variant'          => 'st_patricks_day',
	) );

	$occasions[] = new Conexao_Seasonal_Occasion( array(
		'id'               => 'valentines_day',
		'name'             => __( 'Valentine\'s Day', 'conexao-br-irlanda' ),
		'date_rule'        => Occasion_Date_Rule::FIXED_ANNUAL,
		'fixed_month_day'  => '02-14',
		'lead_in_days'     => 15,
		'enabled'          => true,
		'variant'          => 'valentines_day',
	) );

	$occasions[] = new Conexao_Seasonal_Occasion( array(
		'id'               => 'easter',
		'name'             => __( 'Easter', 'conexao-br-irlanda' ),
		'date_rule'        => Occasion_Date_Rule::VARIABLE_ANNUAL,
		'variable_rule'    => 'easter',
		'lead_in_days'     => 15,
		'enabled'          => true,
		'variant'          => 'easter',
	) );

	return $occasions;
}

function ocasons_get_all(): array {
	static $registered = null;
	if ( null === $registered ) {
		$registered = ocasons_register();
	}
	return $registered;
}

function ocasons_get_active(): array {
	$all = ocasons_get_active_no_filter();
	return array_values(
		array_filter(
			$all,
			function ( $occasion ) {
				return $occasion->is_active() && $occasion->enabled;
			}
		)
	);
}

function ocasons_get_active_no_filter(): array {
	$all    = ocasons_get_all();
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
	return count( $active ) > 0 ? $active[0] : null;
}

function ocasons_get_primary_active(): ?Conexao_Seasonal_Occasion {
	return ocons_get_primary_active();
}

function ocacons_get_primary_display_content() {
	$occasion = ocons_get_primary_active();
	if ( ! $occasion ) {
		return false;
	}
	return $occasion->get_display_content();
}

function ocasons_get_primary_display_content() {
	return ocacons_get_primary_display_content();
}

function conexao_seasonal_is_active(): bool {
	return null !== ocons_get_primary_active();
}

function conexao_seasonal_variant(): ?string {
	$occasion = ocons_get_primary_active();
	return $occasion ? $occasion->variant : null;
}
