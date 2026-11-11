<?php
/**
 * Commemorative event resolution: eligibility, ranking and explanation.
 *
 * Resolution order (§6.1):
 *   1. editorial lifecycle — `post_status === 'publish'`
 *   2. global scheduling switch — `_ce_enabled === true`
 *   3. date eligibility — the resolved window contains the clock
 *   4. package validity — `_ce_presentation` names a registered package
 *   5. manual annual precedence — `_ce_year_precedence[occurrence_year]`
 *   6. global `_ce_priority` descending
 *   7. `_ce_event_key` ascending (never slugs, never file/array order)
 *
 * Everything else is explained: each eligible loser is recorded as
 * `superseded by "<event_key>"` (with `manual preference` when that flag
 * decided the ordering).
 *
 * @package Conexao_Commemorative
 */

defined( 'ABSPATH' ) || exit;

/**
 * Eligibility and ranking.
 */
final class Conexao_Commemorative_Resolver {

	/**
	 * Reason codes used by the admin list and the diagnostics box.
	 */
	const REASON_OK              = 'ok';
	const REASON_DRAFT           = 'draft';
	const REASON_DISABLED        = 'disabled';
	const REASON_OUTSIDE_WINDOW  = 'outside_window';
	const REASON_INVALID         = 'invalid_schedule';
	const REASON_UNKNOWN_PACKAGE = 'unknown_package';
	const REASON_NO_IDENTITY     = 'invalid_identity';

	/**
	 * The registered experience-package slugs.
	 *
	 * An EMPTY registry means the theme registry is not loaded in this context,
	 * so package validity cannot be judged and is not used to disqualify an
	 * event (the read-time check still applies wherever the registry exists).
	 *
	 * @return string[]
	 */
	public static function packages() {
		return Conexao_Data_Model_Commemorative::registered_presentation_slugs();
	}

	/**
	 * Evaluate one event against the injected clock.
	 *
	 * @param array                  $event Normalised event.
	 * @param DateTimeInterface|null $now   Injected clock.
	 * @return array
	 */
	public static function evaluate( array $event, $now = null ) {
		$packages = self::packages();
		$window   = Conexao_Commemorative_Schedule::resolve( $event, $now );
		$next     = $window ? $window : Conexao_Commemorative_Schedule::next_window( $event, $now );

		$evaluation = array(
			'event'           => $event,
			'eligible'        => false,
			'reason'          => self::REASON_OUTSIDE_WINDOW,
			'window'          => $window,
			'next_window'     => $next,
			'occurrence_year' => $window ? (int) $window['occurrence_year'] : ( $next ? (int) $next['occurrence_year'] : null ),
			'preferred'       => false,
		);

		if ( 'trash' === $event['status'] ) {
			$evaluation['reason'] = self::REASON_DRAFT;

			return $evaluation;
		}

		if ( 'publish' !== $event['status'] ) {
			$evaluation['reason'] = self::REASON_DRAFT;

			return $evaluation;
		}

		if ( '' === $event['event_key'] ) {
			// No stable identity: it cannot be logged, previewed or ranked.
			$evaluation['reason'] = self::REASON_NO_IDENTITY;

			return $evaluation;
		}

		if ( ! $event['enabled'] ) {
			$evaluation['reason'] = self::REASON_DISABLED;

			return $evaluation;
		}

		if ( ! $window ) {
			// Distinguish "not yet" from "cannot ever resolve".
			$evaluation['reason'] = $next ? self::REASON_OUTSIDE_WINDOW : self::REASON_INVALID;

			return $evaluation;
		}

		$package = (string) $event['presentation'];

		if ( ! empty( $packages ) && ( '' === $package || ! in_array( $package, $packages, true ) ) ) {
			$evaluation['reason'] = self::REASON_UNKNOWN_PACKAGE;

			return $evaluation;
		}

		$evaluation['eligible']  = true;
		$evaluation['reason']    = self::REASON_OK;
		$evaluation['preferred'] = self::is_preferred( $event, (int) $window['occurrence_year'] );

		return $evaluation;
	}

	/**
	 * Whether the event carries a manual preference for an occurrence year.
	 *
	 * A pure flag read: it never detects overlaps, never calculates dates and
	 * never changes enablement or scheduling (§5.7).
	 *
	 * @param array $event           Normalised event.
	 * @param int   $occurrence_year Resolved occurrence year.
	 * @return bool
	 */
	public static function is_preferred( array $event, $occurrence_year ) {
		$year = (string) (int) $occurrence_year;

		return ! empty( $event['year_precedence'][ $year ]['preferred'] );
	}

	/**
	 * Rank evaluated events deterministically (§6.1 steps 4-6).
	 *
	 * Manual preference first, then global `_ce_priority` descending, then
	 * `_ce_event_key` ascending. Ties on the key fall back to the record ID only
	 * so the order is total; two different events can never share a key.
	 *
	 * @param array $evaluated Evaluations.
	 * @return array Sorted evaluations (winner first).
	 */
	public static function rank( array $evaluated ) {
		usort(
			$evaluated,
			static function ( $a, $b ) {
				if ( $a['preferred'] !== $b['preferred'] ) {
					return $a['preferred'] ? -1 : 1;
				}

				if ( $a['event']['priority'] !== $b['event']['priority'] ) {
					return $b['event']['priority'] <=> $a['event']['priority'];
				}

				$key = strcmp( $a['event']['event_key'], $b['event']['event_key'] );

				return 0 !== $key ? $key : ( $a['event']['id'] <=> $b['event']['id'] );
			}
		);

		return $evaluated;
	}

	/**
	 * Occurrence years for which MORE THAN ONE event is marked preferred.
	 *
	 * The admin warning counts flags only. It never infers or calculates date
	 * collisions (§5.7).
	 *
	 * @param array $evaluated Evaluations.
	 * @return array<array-key,string[]> Year => event keys.
	 */
	public static function preferred_conflicts( array $evaluated ) {
		$by_year = array();

		foreach ( $evaluated as $row ) {
			if ( ! $row['eligible'] || ! $row['preferred'] || null === $row['occurrence_year'] ) {
				continue;
			}

			$year               = (string) (int) $row['occurrence_year'];
			$by_year[ $year ][] = (string) $row['event']['event_key'];
		}

		$conflicts = array();

		foreach ( $by_year as $year => $keys ) {
			$keys = array_values( array_unique( $keys ) );

			if ( count( $keys ) > 1 ) {
				sort( $keys );
				$conflicts[ $year ] = $keys;
			}
		}

		return $conflicts;
	}

	/**
	 * Resolve the selected event for a request.
	 *
	 * @param DateTimeInterface|null $now     Injected clock.
	 * @param array                  $options {
	 *     Optional resolution options.
	 *
	 *     @type array|null $preview An authorised preview event (bypasses eligibility).
	 * }
	 * @return array{event:array|null,superseded:array,evaluated:array,occurrence_year:int|null,preferred_conflicts:array,preview:bool}
	 */
	public static function active( $now = null, array $options = array() ) {
		$preview = isset( $options['preview'] ) && is_array( $options['preview'] ) ? $options['preview'] : null;

		if ( $preview ) {
			return array(
				'event'               => $preview,
				'superseded'          => array(),
				'evaluated'           => array(),
				'occurrence_year'     => isset( $preview['occurrence_year'] ) ? (int) $preview['occurrence_year'] : null,
				'preferred_conflicts' => array(),
				'preview'             => true,
			);
		}

		$evaluated = array();

		foreach ( Conexao_Commemorative_Repository::scheduled() as $event ) {
			$evaluated[] = self::evaluate( $event, $now );
		}

		$conflicts = self::preferred_conflicts( $evaluated );
		$eligible  = array_values(
			array_filter(
				$evaluated,
				static function ( $row ) {
					return $row['eligible'];
				}
			)
		);

		$eligible = self::rank( $eligible );
		$winner   = isset( $eligible[0] ) ? $eligible[0] : null;
		$losers   = array();

		if ( $winner ) {
			foreach ( $eligible as $index => $row ) {
				if ( 0 === $index ) {
					continue;
				}

				$reason = sprintf( 'superseded by "%s"', $winner['event']['event_key'] );

				if ( $winner['preferred'] || $row['preferred'] ) {
					$reason .= ' (manual preference)';
				}

				$row['reason'] = $reason;
				$losers[]      = $row;
			}
		}

		return array(
			'event'               => $winner,
			'superseded'          => $losers,
			'evaluated'           => $evaluated,
			'occurrence_year'     => $winner && null !== $winner['occurrence_year'] ? (int) $winner['occurrence_year'] : null,
			'preferred_conflicts' => $conflicts,
			'preview'             => false,
		);
	}
}
