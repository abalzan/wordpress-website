<?php
/**
 * The seasonal occasion model used by the pre-commemorative callers.
 *
 * Split out of `inc/occasion.php` so the class lives in a file named after it
 * (WordPress.Files.FileName) while the legacy helper functions keep their own
 * file. Behaviour is unchanged: `inc/occasion.php` requires this file, so every
 * existing include path still works.
 *
 * @package conexao-br-irlanda
 */

defined( 'ABSPATH' ) || exit;

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

	/**
	 * ID of the occasion (as supplied).
	 *
	 * @var mixed
	 */
	public $id;
	/**
	 * Human-readable occasion name.
	 *
	 * @var mixed
	 */
	public $name;
	/**
	 * Occasion_Date_Rule the occasion resolves with.
	 *
	 * @var mixed
	 */
	public $date_rule;
	/**
	 * `MM-DD` for a fixed annual rule, or false.
	 *
	 * @var mixed
	 */
	public $fixed_month_day;
	/**
	 * Variable-annual key (`easter`), or false.
	 *
	 * @var mixed
	 */
	public $variable_rule;
	/**
	 * `Y-m-d` for a one-off occasion, or false.
	 *
	 * @var mixed
	 */
	public $one_off_date;
	/**
	 * Days the occasion is considered active before its date.
	 *
	 * @var mixed
	 */
	public $lead_in_days;
	/**
	 * Whether the occasion may ever be active.
	 *
	 * @var mixed
	 */
	public $enabled;
	/**
	 * Destination route/template key, or false.
	 *
	 * @var mixed
	 */
	public $destination;
	/**
	 * Variant key passed to the templates, or false.
	 *
	 * @var mixed
	 */
	public $variant;

	public function __construct( array $args ) {
		$this->id               = $args['id'] ?? false;
		$this->name             = $args['name'] ?? '';
		$this->date_rule        = $args['date_rule'] ?? Occasion_Date_Rule::FIXED_ANNUAL;
		$this->fixed_month_day  = $args['fixed_month_day'] ?? false;
		$this->variable_rule    = $args['variable_rule'] ?? false;
		$this->one_off_date     = $args['one_off_date'] ?? false;
		$this->lead_in_days     = (int) ( $args['lead_in_days'] ?? 15 );
		$this->enabled          = (bool) ( $args['enabled'] ?? true );
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
			/* translators: %s: occasion name. */
			'heading'    => sprintf( __( 'Seasonal Occasion: %s', 'conexao-br-irlanda' ), $this->name ),
			'copy'       => '',
			'image'      => false,
			'cta_text'   => '',
			'destination' => $this->destination,
		);
	}
}
