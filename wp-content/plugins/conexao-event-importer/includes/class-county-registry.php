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
			'eb_region_labels' => array( 'Dublin' ),
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
			'eb_region_labels' => array( 'Tipperary' ),
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
	 * Get all county source configs (both providers).
	 *
	 * @return array Array of source configs keyed by source ID.
	 */
	public static function get_all_county_sources() {
		return array_merge(
			self::get_all_eventbrite_sources(),
			self::get_all_heritage_week_sources()
		);
	}
}
