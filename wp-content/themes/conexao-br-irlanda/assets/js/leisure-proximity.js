/**
 * Conexão BR Irlanda — Lazer proximity helpers.
 *
 * Pure, dependency-free functions behind the optional location feature on
 * /lazer/ (and /en/lazer/). Everything here is side-effect free and operates
 * on plain data so it can be unit-tested in Node as well as used in the
 * browser (see tests/js/test-leisure-proximity.mjs).
 *
 * Responsibilities:
 *   - Haversine straight-line distance (metres) with full input validation;
 *   - locale-aware distance formatting following the approved display table
 *     (metres below 1 km, one decimal from 1 to 10 km, whole kilometres from
 *     10 km on);
 *   - global nearest-first ordering of the matching attraction catalogue,
 *     with a stable original-archive-order tie-break and attractions without
 *     coordinates appended last.
 *
 * This module never touches the DOM, the network, storage or geolocation.
 * It contains NO visitor coordinates: the caller passes them in.
 *
 * @package Conexao_BR_Irlanda
 */

( function ( root, factory ) {
	var api = factory();
	if ( typeof module === 'object' && module && module.exports ) {
		module.exports = api;
	}
	if ( root ) {
		root.ConexaoLeisureProximity = api;
	}
} )( typeof window !== 'undefined' ? window : null, function () {
	'use strict';

	// Mean Earth radius in metres (≈6,371 km).
	var EARTH_RADIUS_M = 6371000;

	/**
	 * Coerce a value to a finite number, or null.
	 *
	 * Accepts numbers and numeric strings (the JSON payload may deliver either)
	 * but rejects null, undefined, '', booleans, NaN and Infinity.
	 *
	 * @param {*} value Raw value.
	 * @return {number|null} Finite number, or null.
	 */
	function toFiniteNumber( value ) {
		if ( typeof value === 'number' ) {
			return isFinite( value ) ? value : null;
		}
		if ( typeof value === 'string' ) {
			var trimmed = value.trim();
			if ( '' === trimmed ) {
				return null;
			}
			var parsed = Number( trimmed );
			return isFinite( parsed ) ? parsed : null;
		}
		return null;
	}

	/**
	 * Whether a value is a valid latitude.
	 *
	 * @param {*} value Candidate.
	 * @return {boolean}
	 */
	function isValidLatitude( value ) {
		var n = toFiniteNumber( value );
		return null !== n && n >= -90 && n <= 90;
	}

	/**
	 * Whether a value is a valid longitude.
	 *
	 * @param {*} value Candidate.
	 * @return {boolean}
	 */
	function isValidLongitude( value ) {
		var n = toFiniteNumber( value );
		return null !== n && n >= -180 && n <= 180;
	}

	/**
	 * Whether a coordinate pair is usable.
	 *
	 * @param {*} lat Latitude.
	 * @param {*} lng Longitude.
	 * @return {boolean}
	 */
	function isValidCoordinate( lat, lng ) {
		return isValidLatitude( lat ) && isValidLongitude( lng );
	}

	function toRadians( degrees ) {
		return ( degrees * Math.PI ) / 180;
	}

	/**
	 * Great-circle (straight-line) distance between two points, in metres.
	 *
	 * Haversine formula. Returns null when any input is missing, non-numeric
	 * or out of range, so a missing attraction coordinate can never be
	 * mistaken for a real distance.
	 *
	 * @param {*} lat1 First latitude.
	 * @param {*} lng1 First longitude.
	 * @param {*} lat2 Second latitude.
	 * @param {*} lng2 Second longitude.
	 * @return {number|null} Distance in metres, or null when invalid.
	 */
	function haversineMeters( lat1, lng1, lat2, lng2 ) {
		if ( ! isValidCoordinate( lat1, lng1 ) || ! isValidCoordinate( lat2, lng2 ) ) {
			return null;
		}

		var phi1 = toRadians( toFiniteNumber( lat1 ) );
		var phi2 = toRadians( toFiniteNumber( lat2 ) );
		var dPhi = toRadians( toFiniteNumber( lat2 ) - toFiniteNumber( lat1 ) );
		var dLambda = toRadians( toFiniteNumber( lng2 ) - toFiniteNumber( lng1 ) );

		var sinHalfPhi = Math.sin( dPhi / 2 );
		var sinHalfLambda = Math.sin( dLambda / 2 );

		var a = sinHalfPhi * sinHalfPhi
			+ Math.cos( phi1 ) * Math.cos( phi2 ) * sinHalfLambda * sinHalfLambda;

		// Clamp to guard against floating-point drift producing a > 1 value
		// (which would make Math.sqrt/asin return NaN).
		if ( a < 0 ) {
			a = 0;
		} else if ( a > 1 ) {
			a = 1;
		}

		return 2 * EARTH_RADIUS_M * Math.asin( Math.sqrt( a ) );
	}

	/**
	 * Build a number formatter for a locale, tolerating an unsupported locale.
	 *
	 * @param {string} locale BCP-47 locale (e.g. "pt-BR", "en-US").
	 * @param {Object} options Intl.NumberFormat options.
	 * @return {Intl.NumberFormat}
	 */
	function numberFormatter( locale, options ) {
		try {
			return new Intl.NumberFormat( locale || undefined, options );
		} catch ( error ) {
			return new Intl.NumberFormat( undefined, options );
		}
	}

	/**
	 * Format a distance for display, WITHOUT the surrounding copy.
	 *
	 * The rounding follows the approved display table, so a shown value is
	 * precise enough to be useful and never falsely precise:
	 *
	 *  - below 1 km       → metres, rounded to the nearest 10 ("850 m");
	 *  - 1 km to 10 km    → kilometres, ONE decimal ("2,4 km" / "2.4 km");
	 *  - 10 km and above  → whole kilometres ("24 km", "125 km").
	 *
	 * The unit symbols are identical in Portuguese and English; only the
	 * number grouping/separator follows the locale.
	 *
	 * @param {number} meters  Distance in metres.
	 * @param {string} locale  BCP-47 locale for number formatting.
	 * @return {string} Formatted value including the unit, or '' when invalid.
	 */
	function formatDistanceValue( meters, locale ) {
		if ( typeof meters !== 'number' || ! isFinite( meters ) || meters < 0 ) {
			return '';
		}

		// Round to the nearest 10 metres FIRST so the unit boundary is applied
		// to the value that is actually shown (999 m rounds up to 1,0 km
		// rather than rendering a four-digit metre figure).
		var roundedMeters = Math.round( meters / 10 ) * 10;

		if ( roundedMeters < 1000 ) {
			var meterFormatter = numberFormatter( locale, {
				maximumFractionDigits: 0,
			} );
			return meterFormatter.format( roundedMeters ) + ' m';
		}

		var kilometres = roundedMeters / 1000;

		// One decimal below 10 km; from 10 km on a decimal is false precision
		// for a straight-line estimate, so whole kilometres are shown.
		var fractionDigits = kilometres < 10 ? 1 : 0;

		var kmFormatter = numberFormatter( locale, {
			minimumFractionDigits: fractionDigits,
			maximumFractionDigits: fractionDigits,
		} );
		return kmFormatter.format( kilometres ) + ' km';
	}

	/**
	 * Order the matching attraction catalogue globally by proximity.
	 *
	 * @param {Array<{id:number,lat:*,lng:*,index:number}>} items Attraction
	 *        coordinate dataset in original archive order.
	 * @param {{lat:number,lng:number}} visitor Visitor coordinates.
	 * @return {{ids:number[],distances:Map<number,number>,geolocated:number,total:number}}
	 *         `ids` is the globally sorted ID list (nearest first, then the
	 *         attractions without coordinates in their original order);
	 *         `distances` maps an attraction ID to its distance in metres.
	 */
	function sortByProximity( items, visitor ) {
		var list = Array.isArray( items ) ? items : [];
		var visitorLat = visitor ? visitor.lat : null;
		var visitorLng = visitor ? visitor.lng : null;

		var withCoordinates = [];
		var withoutCoordinates = [];

		for ( var i = 0; i < list.length; i++ ) {
			var item = list[ i ] || {};
			var id = item.id;
			var index = ( typeof item.index === 'number' && isFinite( item.index ) ) ? item.index : i;

			var distance = haversineMeters( visitorLat, visitorLng, item.lat, item.lng );

			if ( null === distance ) {
				withoutCoordinates.push( { id: id, index: index } );
			} else {
				withCoordinates.push( { id: id, index: index, distance: distance } );
			}
		}

		// Nearest first; identical distances fall back to the original
		// archive position so the order is deterministic and stable.
		withCoordinates.sort( function ( a, b ) {
			if ( a.distance !== b.distance ) {
				return a.distance - b.distance;
			}
			return a.index - b.index;
		} );

		// Attractions without coordinates keep their original relative order
		// and are appended after every geolocated attraction.
		withoutCoordinates.sort( function ( a, b ) {
			return a.index - b.index;
		} );

		var ids = [];
		var distances = new Map();

		for ( var j = 0; j < withCoordinates.length; j++ ) {
			ids.push( withCoordinates[ j ].id );
			distances.set( withCoordinates[ j ].id, withCoordinates[ j ].distance );
		}
		for ( var k = 0; k < withoutCoordinates.length; k++ ) {
			ids.push( withoutCoordinates[ k ].id );
		}

		return {
			ids: ids,
			distances: distances,
			geolocated: withCoordinates.length,
			total: list.length,
		};
	}

	/**
	 * Classify a Geolocation API failure into a coarse reason.
	 *
	 * Pure mapping of the W3C PositionError code so the caller's failure
	 * branches are unit-testable without a browser. Codes:
	 *   1 PERMISSION_DENIED, 2 POSITION_UNAVAILABLE, 3 TIMEOUT.
	 *
	 * @param {*} error A PositionError-like object, or null.
	 * @return {string} 'denied' | 'unavailable' | 'timeout' | 'unknown'.
	 */
	function classifyGeolocationError( error ) {
		var code = error && typeof error.code === 'number' ? error.code : 0;
		if ( 1 === code ) {
			return 'denied';
		}
		if ( 2 === code ) {
			return 'unavailable';
		}
		if ( 3 === code ) {
			return 'timeout';
		}
		return 'unknown';
	}

	/**
	 * Classify whether geolocation can be attempted at all.
	 *
	 * @param {*} navigatorRef navigator-like object (defaults to global).
	 * @param {boolean} secureContext Whether the page is a secure context.
	 * @return {string} 'available' | 'unsupported' | 'insecure'.
	 */
	function classifyGeolocationSupport( navigatorRef, secureContext ) {
		var nav = navigatorRef || ( typeof navigator !== 'undefined' ? navigator : null );
		if ( ! nav || ! nav.geolocation || typeof nav.geolocation.getCurrentPosition !== 'function' ) {
			return 'unsupported';
		}
		if ( false === secureContext ) {
			return 'insecure';
		}
		return 'available';
	}

	/**
	 * Read visitor coordinates from a GeolocationPosition-like object.
	 *
	 * @param {*} position Position object ({coords:{latitude,longitude}}).
	 * @return {{lat:number,lng:number}|null} Null when the coordinates are
	 *         missing or out of range.
	 */
	function readPosition( position ) {
		if ( ! position || ! position.coords ) {
			return null;
		}
		var lat = toFiniteNumber( position.coords.latitude );
		var lng = toFiniteNumber( position.coords.longitude );
		if ( ! isValidCoordinate( lat, lng ) ) {
			return null;
		}
		return { lat: lat, lng: lng };
	}

	/**
	 * Take the next slice of the globally sorted ID list.
	 *
	 * Pure cursor arithmetic for the proximity batch loader: it never skips
	 * and never repeats an ID, and a failed batch simply does not advance the
	 * cursor (the caller re-requests the same slice).
	 *
	 * @param {number[]} ids    Sorted attraction IDs.
	 * @param {number}   cursor Current cursor (index of the next ID).
	 * @param {number}   size   Batch size (>0).
	 * @return {{slice:number[],cursor:number,nextCursor:number,done:boolean}}
	 */
	function sliceBatch( ids, cursor, size ) {
		var list = Array.isArray( ids ) ? ids : [];
		var start = ( typeof cursor === 'number' && cursor > 0 ) ? Math.floor( cursor ) : 0;
		var step = ( typeof size === 'number' && size > 0 ) ? Math.floor( size ) : 1;
		var slice = list.slice( start, start + step );
		var nextCursor = start + slice.length;

		return {
			slice: slice,
			cursor: start,
			nextCursor: nextCursor,
			done: nextCursor >= list.length,
		};
	}

	return {
		EARTH_RADIUS_M: EARTH_RADIUS_M,
		toFiniteNumber: toFiniteNumber,
		isValidLatitude: isValidLatitude,
		isValidLongitude: isValidLongitude,
		isValidCoordinate: isValidCoordinate,
		haversineMeters: haversineMeters,
		formatDistanceValue: formatDistanceValue,
		sortByProximity: sortByProximity,
		classifyGeolocationError: classifyGeolocationError,
		classifyGeolocationSupport: classifyGeolocationSupport,
		readPosition: readPosition,
		sliceBatch: sliceBatch,
	};
} );
