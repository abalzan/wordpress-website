<?php
/**
 * Authoritative county registry for the Events multi-county expansion.
 *
 * Single source of truth for the 26 Republic of Ireland counties used by
 * the Eventbrite and National Heritage Week county source registrations.
 *
 * Northern Ireland counties are explicitly OUT OF SCOPE.
 *
 * The canonical county slug is identical to the conexao_county term slug.
 * Source keys are derived from these slugs:
 *   eventbrite_<slug>       e.g. eventbrite_laois
 *   heritage_week_<slug>    e.g. heritage_week_laois
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_County_Registry {

	/**
	 * Full county registry.
	 *
	 * Each entry: name, slug, jurisdiction, eb_slug, eb_region_labels, hw_where.
	 *
	 * @var array
	 */
	const COUNTIES = array(
		'carlow'     => array(
			'name'             => 'Carlow',
			'slug'             => 'carlow',
			'jurisdiction'     => 'ROI',
			'eb_slug'          => 'ireland--carlow',
			'eb_region_labels' => array( 'Carlow' ),
			'hw_where'         => array( 'carlow' ),
		),
		'cavan'      => array(
			'name'             => 'Cavan',
			'slug'             => 'cavan',
			'jurisdiction'     => 'ROI',
			'eb_slug'          => 'ireland--cavan',
			'eb_region_labels' => array( 'Cavan' ),
			'hw_where'         => array( 'cavan' ),
		),
		'clare'      => array(
			'name'             => 'Clare',
			'slug'             => 'clare',
			'jurisdiction'     => 'ROI',
			'eb_slug'          => 'ireland--clare',
			'eb_region_labels' => array( 'Clare' ),
			'hw_where'         => array( 'clare' ),
		),
		'cork'       => array(
			'name'             => 'Cork',
			'slug'             => 'cork',
			'jurisdiction'     => 'ROI',
			'eb_slug'          => 'ireland--cork',
			'eb_region_labels' => array( 'Cork', 'Cork City' ),
			'hw_where'         => array( 'cork-county' ),
		),
		'donegal'    => array(
			'name'             => 'Donegal',
			'slug'             => 'donegal',
			'jurisdiction'     => 'ROI',
			'eb_slug'          => 'ireland--donegal',
			'eb_region_labels' => array( 'Donegal' ),
			'hw_where'         => array( 'donegal' ),
		),
		'dublin'     => array(
			'name'             => 'Dublin',
			'slug'             => 'dublin',
			'jurisdiction'     => 'ROI',
			'eb_slug'          => 'ireland--dublin',
			'eb_region_labels' => array( 'Dublin City', 'Dunlaoghaire-Rathdown', 'Fingal' ),
			'hw_where'         => array( 'dublin-city', 'dublin-dunlaoghaire-rathdown', 'dublin-fingal', 'dublin-south' ),
		),
		'galway'     => array(
			'name'             => 'Galway',
			'slug'             => 'galway',
			'jurisdiction'     => 'ROI',
			'eb_slug'          => 'ireland--galway',
			'eb_region_labels' => array( 'Galway', 'Galway City' ),
			'hw_where'         => array( 'galway-county', 'galway-city' ),
		),
		'kerry'      => array(
			'name'             => 'Kerry',
			'slug'             => 'kerry',
			'jurisdiction'     => 'ROI',
			'eb_slug'          => 'ireland--kerry',
			'eb_region_labels' => array( 'Kerry' ),
			'hw_where'         => array( 'kerry' ),
		),
		'kildare'    => array(
			'name'             => 'Kildare',
			'slug'             => 'kildare',
			'jurisdiction'     => 'ROI',
			'eb_slug'          => 'ireland--kildare',
			'eb_region_labels' => array( 'Kildare' ),
			'hw_where'         => array( 'kildare' ),
		),
		'kilkenny'   => array(
			'name'             => 'Kilkenny',
			'slug'             => 'kilkenny',
			'jurisdiction'     => 'ROI',
			'eb_slug'          => 'ireland--kilkenny',
			'eb_region_labels' => array( 'Kilkenny' ),
			'hw_where'         => array( 'kilkenny' ),
		),
		'laois'      => array(
			'name'             => 'Laois',
			'slug'             => 'laois',
			'jurisdiction'     => 'ROI',
			'eb_slug'          => 'ireland--laois',
			'eb_region_labels' => array( 'Laois' ),
			'hw_where'         => array( 'laois' ),
		),
		'leitrim'    => array(
			'name'             => 'Leitrim',
			'slug'             => 'leitrim',
			'jurisdiction'     => 'ROI',
			'eb_slug'          => 'ireland--leitrim',
			'eb_region_labels' => array( 'Leitrim' ),
			'hw_where'         => array( 'leitrim' ),
		),
		'limerick'   => array(
			'name'             => 'Limerick',
			'slug'             => 'limerick',
			'jurisdiction'     => 'ROI',
			'eb_slug'          => 'ireland--limerick',
			'eb_region_labels' => array( 'Limerick' ),
			'hw_where'         => array( 'limerick' ),
		),
		'longford'   => array(
			'name'             => 'Longford',
			'slug'             => 'longford',
			'jurisdiction'     => 'ROI',
			'eb_slug'          => 'ireland--longford',
			'eb_region_labels' => array( 'Longford' ),
			'hw_where'         => array( 'longford' ),
		),
		'louth'      => array(
			'name'             => 'Louth',
			'slug'             => 'louth',
			'jurisdiction'     => 'ROI',
			'eb_slug'          => 'ireland--louth',
			'eb_region_labels' => array( 'Louth' ),
			'hw_where'         => array( 'louth' ),
		),
		'mayo'       => array(
			'name'             => 'Mayo',
			'slug'             => 'mayo',
			'jurisdiction'     => 'ROI',
			'eb_slug'          => 'ireland--mayo',
			'eb_region_labels' => array( 'Mayo' ),
			'hw_where'         => array( 'mayo' ),
		),
		'meath'      => array(
			'name'             => 'Meath',
			'slug'             => 'meath',
			'jurisdiction'     => 'ROI',
			'eb_slug'          => 'ireland--meath',
			'eb_region_labels' => array( 'Meath' ),
			'hw_where'         => array( 'meath' ),
		),
		'monaghan'   => array(
			'name'             => 'Monaghan',
			'slug'             => 'monaghan',
			'jurisdiction'     => 'ROI',
			'eb_slug'          => 'ireland--monaghan',
			'eb_region_labels' => array( 'Monaghan' ),
			'hw_where'         => array( 'monaghan' ),
		),
		'offaly'     => array(
			'name'             => 'Offaly',
			'slug'             => 'offaly',
			'jurisdiction'     => 'ROI',
			'eb_slug'          => 'ireland--offaly',
			'eb_region_labels' => array( 'Offaly' ),
			'hw_where'         => array( 'offaly' ),
		),
		'roscommon'  => array(
			'name'             => 'Roscommon',
			'slug'             => 'roscommon',
			'jurisdiction'     => 'ROI',
			'eb_slug'          => 'ireland--roscommon',
			'eb_region_labels' => array( 'Roscommon' ),
			'hw_where'         => array( 'roscommon' ),
		),
		'sligo'      => array(
			'name'             => 'Sligo',
			'slug'             => 'sligo',
			'jurisdiction'     => 'ROI',
			'eb_slug'          => 'ireland--sligo',
			'eb_region_labels' => array( 'Sligo' ),
			'hw_where'         => array( 'sligo' ),
		),
		'tipperary'  => array(
			'name'             => 'Tipperary',
			'slug'             => 'tipperary',
			'jurisdiction'     => 'ROI',
			'eb_slug'          => 'ireland--tipperary',
			// Eventbrite labels County Tipperary events under the historical
			// ridings ("South Tipperary" / "North Tipperary"); the bare
			// "Tipperary" label is kept for forward compatibility.
			'eb_region_labels' => array( 'Tipperary', 'South Tipperary', 'North Tipperary' ),
			'hw_where'         => array( 'tipperary' ),
		),
		'waterford'  => array(
			'name'             => 'Waterford',
			'slug'             => 'waterford',
			'jurisdiction'     => 'ROI',
			'eb_slug'          => 'ireland--waterford',
			'eb_region_labels' => array( 'Waterford' ),
			'hw_where'         => array( 'waterford' ),
		),
		'westmeath'  => array(
			'name'             => 'Westmeath',
			'slug'             => 'westmeath',
			'jurisdiction'     => 'ROI',
			'eb_slug'          => 'ireland--westmeath',
			'eb_region_labels' => array( 'Westmeath' ),
			'hw_where'         => array( 'westmeath' ),
		),
		'wexford'    => array(
			'name'             => 'Wexford',
			'slug'             => 'wexford',
			'jurisdiction'     => 'ROI',
			'eb_slug'          => 'ireland--wexford',
			'eb_region_labels' => array( 'Wexford' ),
			'hw_where'         => array( 'wexford' ),
		),
		'wicklow'    => array(
			'name'             => 'Wicklow',
			'slug'             => 'wicklow',
			'jurisdiction'     => 'ROI',
			'eb_slug'          => 'ireland--wicklow',
			'eb_region_labels' => array( 'Wicklow' ),
			'hw_where'         => array( 'wicklow' ),
		),
	);

	/**
	 * Get the full county registry.
	 *
	 * @return array Array of county data keyed by county slug.
	 */
	public static function get_counties() {
		return self::COUNTIES;
	}

	/**
	 * Get a single county's data.
	 *
	 * @param string $slug County slug (e.g. "cork").
	 * @return array|null County data or null if not found.
	 */
	public static function get_county( $slug ) {
		return isset( self::COUNTIES[ $slug ] ) ? self::COUNTIES[ $slug ] : null;
	}

	/**
	 * Get all county slugs.
	 *
	 * @return array List of county slugs.
	 */
	public static function get_slugs() {
		return array_keys( self::COUNTIES );
	}

	/**
	 * Generate the Eventbrite source configuration for a county.
	 *
	 * @param string $county_slug County slug.
	 * @return array|null Source config or null if county not found.
	 */
	public static function get_eventbrite_source( $county_slug ) {
		$county = self::get_county( $county_slug );
		if ( ! $county ) {
			return null;
		}

		$id = 'eventbrite_' . $county_slug;

		return array(
			'id'               => $id,
			'name'             => 'Eventbrite — ' . $county['name'],
			'url'              => 'https://www.eventbrite.ie/d/' . $county['eb_slug'] . '/all-events/',
			'type'             => 'eventbrite',
			'status'           => 'inactive',
			'county'           => $county['name'],
			'region_labels'    => $county['eb_region_labels'],
			'last_import'      => '',
			'last_import_status' => '',
			'events_imported'  => 0,
			'last_error'       => '',
		);
	}

	/**
	 * Generate the Heritage Week source configuration for a county.
	 *
	 * @param string $county_slug County slug.
	 * @return array|null Source config or null if county not found.
	 */
	public static function get_heritage_week_source( $county_slug ) {
		$county = self::get_county( $county_slug );
		if ( ! $county ) {
			return null;
		}

		$id = 'heritage_week_' . $county_slug;

		return array(
			'id'               => $id,
			'name'             => 'National Heritage Week — ' . $county['name'],
			'url'              => 'https://www.heritageweek.ie/event-listings?q=&where%5B%5D=' . reset( $county['hw_where'] ),
			'type'             => 'heritage_week',
			'status'           => 'inactive',
			'county'           => $county['name'],
			'category'         => 'Heritage',
			'hw_where'         => $county['hw_where'],
			'last_import'      => '',
			'last_import_status' => '',
			'events_imported'  => 0,
			'last_error'       => '',
		);
	}

	/**
	 * Get all Eventbrite source configs.
	 *
	 * @return array Array of source configs keyed by source ID.
	 */
	public static function get_all_eventbrite_sources() {
		$sources = array();
		foreach ( self::get_slugs() as $slug ) {
			$source = self::get_eventbrite_source( $slug );
			if ( $source ) {
				$sources[ $source['id'] ] = $source;
			}
		}
		return $sources;
	}

	/**
	 * Get all Heritage Week source configs.
	 *
	 * @return array Array of source configs keyed by source ID.
	 */
	public static function get_all_heritage_week_sources() {
		$sources = array();
		foreach ( self::get_slugs() as $slug ) {
			$source = self::get_heritage_week_source( $slug );
			if ( $source ) {
				$sources[ $source['id'] ] = $source;
			}
		}
		return $sources;
	}

	/**
	 * Eventbrite date-window listing slugs.
	 *
	 * G8. The county `all-events` listing truncates its own pagination
	 * (measured 2026-10-04: `page_count` pinned at 49 while `object_count`
	 * runs 1,547–5,722), so a large share of a county's events is simply not
	 * reachable through it. Eventbrite's *date-window* listings slice the same
	 * county population by time and, because each slice is far smaller, each one
	 * declares a page count it can actually serve — so it can be walked to
	 * genuine completion.
	 *
	 * Measured Cork: `events-this-week` enumerated 16/16 declared pages with zero
	 * errors and surfaced 198 events the capped `all-events` walk never reached.
	 *
	 * `events-this-month` is deliberately NOT registered: it measured 48 declared
	 * pages against a 49-page cap, so it is as exposed to truncation as
	 * `all-events` and would add request cost without adding reachable coverage.
	 *
	 * @var array
	 */
	const EVENTBRITE_DATE_WINDOWS = array(
		'events-today'    => 'Today',
		'events-tomorrow' => 'Tomorrow',
		'events-this-week' => 'This week',
	);

	/**
	 * Generate one Eventbrite date-window source configuration.
	 *
	 * This is the SAME `eventbrite` source type as the county listing: it is
	 * handled by `Conexao_Source_Eventbrite` and differs only in its discovery
	 * URL. No new adapter, parser or importer architecture is introduced — the
	 * existing handler is already configuration-driven (url, county,
	 * region_labels), so a different window is just a different URL.
	 *
	 * @param string $county_slug County slug.
	 * @param string $window_slug Eventbrite date-window slug.
	 * @param string $window_name Human-readable window label.
	 * @return array|null Source config, or null when the county is unknown.
	 */
	public static function get_eventbrite_window_source( $county_slug, $window_slug, $window_name ) {
		$county = self::get_county( $county_slug );
		if ( ! $county ) {
			return null;
		}

		$id = 'eventbrite_' . $county_slug . '_' . str_replace( '-', '_', $window_slug );

		return array(
			'id'                => $id,
			'name'              => 'Eventbrite — ' . $county['name'] . ' (' . $window_name . ')',
			'url'               => 'https://www.eventbrite.ie/d/' . $county['eb_slug'] . '/' . $window_slug . '/',
			'type'              => 'eventbrite',
			// Ships inactive, exactly like every other registry source: it must
			// never start an import or send a request until an operator enables it.
			'status'            => 'inactive',
			'county'            => $county['name'],
			'region_labels'     => $county['eb_region_labels'],
			'date_window'       => $window_slug,
			'last_import'       => '',
			'last_import_status' => '',
			'events_imported'   => 0,
			'last_error'        => '',
		);
	}

	/**
	 * Get all Eventbrite date-window source configs.
	 *
	 * @return array Array of source configs keyed by source ID.
	 */
	public static function get_all_eventbrite_window_sources() {
		$sources = array();

		foreach ( self::get_slugs() as $slug ) {
			foreach ( self::EVENTBRITE_DATE_WINDOWS as $window_slug => $window_name ) {
				$source = self::get_eventbrite_window_source( $slug, $window_slug, $window_name );
				if ( $source ) {
					$sources[ $source['id'] ] = $source;
				}
			}
		}

		return $sources;
	}

	/**
	 * Get all county source configs (both providers).
	 *
	 * @return array Array of source configs keyed by source ID.
	 */
	public static function get_all_county_sources() {
		return array_merge(
			self::get_all_eventbrite_sources(),
			self::get_all_heritage_week_sources(),
			self::get_all_eventbrite_window_sources()
		);
	}
}
