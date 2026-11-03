#!/usr/bin/env node
/**
 * Node unit tests for the Lazer proximity helpers.
 *
 * The browser feature is deliberately split so that every decision with a
 * correctness consequence (distance maths, ordering, tie-breaking, formatting,
 * geolocation outcome classification, batch cursor arithmetic) lives in a
 * side-effect-free module (assets/js/leisure-proximity.js) that this suite
 * exercises directly. The DOM/network orchestration is covered by the browser
 * verification script (tests/browser/verify-lazer-proximity-browser.py).
 *
 * Run from the repository root:
 *   node tests/js/test-leisure-proximity.mjs
 *
 * No dependencies, no network, no WordPress. Exit code 0 = all pass.
 */

import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const require = createRequire( import.meta.url );
const here = path.dirname( fileURLToPath( import.meta.url ) );
const modulePath = path.resolve( here, '..', '..', 'wp-content', 'themes', 'conexao-br-irlanda', 'assets', 'js', 'leisure-proximity.js' );
const P = require( modulePath );

let passed = 0;
let failed = 0;

function ok( condition, message ) {
	if ( condition ) {
		passed += 1;
		console.log( `  PASS: ${ message }` );
	} else {
		failed += 1;
		console.log( `  FAIL: ${ message }` );
	}
}

function eq( actual, expected, message ) {
	const same = JSON.stringify( actual ) === JSON.stringify( expected );
	ok( same, same ? message : `${ message } — expected ${ JSON.stringify( expected ) }, got ${ JSON.stringify( actual ) }` );
}

function section( title ) {
	console.log( `\n== ${ title } ==` );
}

function approx( actual, expected, tolerance, message ) {
	const okValue = typeof actual === 'number' && Math.abs( actual - expected ) <= tolerance;
	ok( okValue, okValue ? message : `${ message } — expected ~${ expected } ±${ tolerance }, got ${ actual }` );
}

// ---------------------------------------------------------------------------
section( 'Haversine — known coordinate pairs' );

// Dublin (Trinity College) → Cork (city centre): ~219.6 km great-circle.
approx( P.haversineMeters( 53.3498, -6.2603, 51.8985, -8.4756 ), 219600, 1500, 'Dublin → Cork ≈ 219.6 km' );

// Identical points → exactly 0.
eq( P.haversineMeters( 53.3498, -6.2603, 53.3498, -6.2603 ), 0, 'identical points → 0 m' );

// One degree of latitude ≈ 111.19 km.
approx( P.haversineMeters( 0, 0, 1, 0 ), 111195, 200, '1° latitude ≈ 111.2 km' );

// One degree of longitude at the equator ≈ 111.19 km.
approx( P.haversineMeters( 0, 0, 0, 1 ), 111195, 200, '1° longitude at equator ≈ 111.2 km' );

// Antipodal-ish sanity: never NaN, never negative.
const far = P.haversineMeters( 90, 0, -90, 0 );
ok( typeof far === 'number' && far > 0 && ! Number.isNaN( far ), 'antipodal points return a finite positive distance' );

// ---------------------------------------------------------------------------
section( 'Haversine — invalid inputs return null (never a number)' );

eq( P.haversineMeters( null, -6.26, 53.34, -6.26 ), null, 'null latitude → null' );
eq( P.haversineMeters( 53.34, undefined, 53.34, -6.26 ), null, 'undefined longitude → null' );
eq( P.haversineMeters( '', '', 53.34, -6.26 ), null, 'empty strings → null' );
eq( P.haversineMeters( 'abc', -6.26, 53.34, -6.26 ), null, 'non-numeric → null' );
eq( P.haversineMeters( 91, -6.26, 53.34, -6.26 ), null, 'latitude > 90 → null' );
eq( P.haversineMeters( -91, -6.26, 53.34, -6.26 ), null, 'latitude < -90 → null' );
eq( P.haversineMeters( 53.34, 181, 53.34, -6.26 ), null, 'longitude > 180 → null' );
eq( P.haversineMeters( 53.34, -181, 53.34, -6.26 ), null, 'longitude < -180 → null' );
eq( P.haversineMeters( NaN, -6.26, 53.34, -6.26 ), null, 'NaN → null' );
eq( P.haversineMeters( Infinity, -6.26, 53.34, -6.26 ), null, 'Infinity → null' );
ok( P.haversineMeters( '53.3498', '-6.2603', '51.8985', '-8.4756' ) > 0, 'numeric strings are accepted' );
ok( P.haversineMeters( 0, 0, 0, 0 ) === 0, 'valid zero coordinates are accepted' );

// ---------------------------------------------------------------------------
section( 'formatDistanceValue — kilometres and metres, PT and EN' );

eq( P.formatDistanceValue( 2500, 'pt-BR' ), '2,5 km', 'PT: 2500 m → "2,5 km"' );
eq( P.formatDistanceValue( 2500, 'en-US' ), '2.5 km', 'EN: 2500 m → "2.5 km"' );
eq( P.formatDistanceValue( 1000, 'pt-BR' ), '1,0 km', 'PT: 1000 m → "1,0 km"' );
eq( P.formatDistanceValue( 12500, 'en-US' ), '12.5 km', 'EN: 12500 m → "12.5 km"' );
eq( P.formatDistanceValue( 400, 'pt-BR' ), '400 m', 'PT: 400 m → "400 m" (below 1 km)' );
eq( P.formatDistanceValue( 400, 'en-US' ), '400 m', 'EN: 400 m → "400 m"' );
eq( P.formatDistanceValue( 396, 'pt-BR' ), '400 m', 'PT: 396 m rounds to nearest 10 → "400 m"' );
eq( P.formatDistanceValue( 394, 'en-US' ), '390 m', 'EN: 394 m rounds to nearest 10 → "390 m"' );
eq( P.formatDistanceValue( 999, 'en-US' ), '1.0 km', 'EN: 999 m rounds up across the km boundary → "1.0 km"' );
eq( P.formatDistanceValue( 999, 'pt-BR' ), '1,0 km', 'PT: 999 m rounds up across the km boundary → "1,0 km"' );
eq( P.formatDistanceValue( 0, 'pt-BR' ), '0 m', 'PT: 0 m → "0 m"' );
eq( P.formatDistanceValue( -5, 'pt-BR' ), '', 'negative distance → "" (invalid)' );
eq( P.formatDistanceValue( NaN, 'pt-BR' ), '', 'NaN distance → "" (invalid)' );
eq( P.formatDistanceValue( '400', 'pt-BR' ), '', 'non-number distance → "" (invalid)' );

// ---------------------------------------------------------------------------
section( 'sortByProximity — global nearest-first ordering' );

// Visitor in Dublin. Attractions deliberately listed in an order that is NOT
// proximity order, with two sharing the exact same distance (tie-break) and
// two with no coordinates at all.
const visitor = { lat: 53.3498, lng: -6.2603 };
const items = [
	{ id: 10, lat: 51.8985, lng: -8.4756, index: 0 }, // Cork — far
	{ id: 20, lat: null, lng: null, index: 1 },       // no coordinates
	{ id: 30, lat: 53.3498, lng: -6.2603, index: 2 }, // Dublin — 0 m
	{ id: 40, lat: 53.3500, lng: -6.2600, index: 3 }, // very close, ~30 m
	{ id: 50, lat: null, lng: -6.26, index: 4 },      // partial coordinate
	{ id: 60, lat: 53.3500, lng: -6.2600, index: 5 }, // same as id 40 (tie)
];

const sorted = P.sortByProximity( items, visitor );

eq(
	sorted.ids,
	[ 30, 40, 60, 10, 20, 50 ],
	'nearest first, ties by original archive order, no-coordinate attractions appended last'
);
eq( sorted.geolocated, 4, 'four attractions have usable coordinates' );
eq( sorted.total, 6, 'all six attractions are represented (none dropped)' );
ok( sorted.distances instanceof Map, 'distances are returned as an ID-keyed Map' );
eq( sorted.distances.get( 30 ), 0, 'ID 30 is associated with its own 0 m distance' );
ok( sorted.distances.get( 40 ) > 0 && sorted.distances.get( 40 ) < 100, 'ID 40 is associated with a ~30 m distance' );
eq( sorted.distances.get( 40 ), sorted.distances.get( 60 ), 'tied attractions share the same distance' );
eq( sorted.distances.has( 20 ), false, 'an attraction without coordinates has NO distance entry' );
eq( sorted.distances.has( 50 ), false, 'an attraction with a partial coordinate has NO distance entry' );

// Stable tie-break is deterministic across repeated calls.
const sortedAgain = P.sortByProximity( items, visitor );
eq( sortedAgain.ids, sorted.ids, 'ordering is deterministic across repeated calls' );

// No attraction is ever removed, even when none has coordinates.
const noneHaveCoords = P.sortByProximity(
	[ { id: 1, lat: null, lng: null, index: 0 }, { id: 2, lat: '', lng: '', index: 1 } ],
	visitor
);
eq( noneHaveCoords.ids, [ 1, 2 ], 'with no coordinates anywhere, the original archive order is preserved' );
eq( noneHaveCoords.geolocated, 0, 'nothing is reported as geolocated' );

// Invalid visitor coordinates degrade to "original order, no distances".
const badVisitor = P.sortByProximity( items, { lat: 'x', lng: null } );
eq( badVisitor.ids, [ 10, 20, 30, 40, 50, 60 ], 'an invalid visitor position preserves the original archive order' );
eq( badVisitor.geolocated, 0, 'an invalid visitor position produces no distances' );

// ---------------------------------------------------------------------------
section( 'Geolocation outcome classification (permission + failure branches)' );

eq( P.classifyGeolocationError( { code: 1 } ), 'denied', 'permission denied (code 1) → "denied"' );
eq( P.classifyGeolocationError( { code: 2 } ), 'unavailable', 'position unavailable (code 2) → "unavailable"' );
eq( P.classifyGeolocationError( { code: 3 } ), 'timeout', 'timeout (code 3) → "timeout"' );
eq( P.classifyGeolocationError( null ), 'unknown', 'a missing error → "unknown" (never throws)' );

eq( P.classifyGeolocationSupport( {} ), 'unsupported', 'no geolocation API → "unsupported"' );
eq( P.classifyGeolocationSupport( null ), 'unsupported', 'no navigator → "unsupported"' );
eq(
	P.classifyGeolocationSupport( { geolocation: { getCurrentPosition: () => {} } }, false ),
	'insecure',
	'geolocation present but insecure context → "insecure"'
);
eq(
	P.classifyGeolocationSupport( { geolocation: { getCurrentPosition: () => {} } }, true ),
	'available',
	'geolocation present in a secure context → "available"'
);

// Permission granted → a usable position.
eq( P.readPosition( { coords: { latitude: 53.3498, longitude: -6.2603 } } ), { lat: 53.3498, lng: -6.2603 }, 'a granted position yields the visitor coordinates' );
eq( P.readPosition( { coords: { latitude: 200, longitude: -6.26 } } ), null, 'out-of-range visitor latitude → null' );
eq( P.readPosition( { coords: {} } ), null, 'missing visitor coordinates → null' );
eq( P.readPosition( null ), null, 'missing position object → null (never throws)' );
eq( P.readPosition( { coords: { latitude: '53.3498', longitude: '-6.2603' } } ), { lat: 53.3498, lng: -6.2603 }, 'numeric-string visitor coordinates are accepted' );

// ---------------------------------------------------------------------------
section( 'sliceBatch — proximity batch cursor arithmetic' );

const ids = [ 30, 40, 60, 10, 20, 50, 70, 80 ];

const b1 = P.sliceBatch( ids, 0, 3 );
eq( b1.slice, [ 30, 40, 60 ], 'first batch is the first three sorted IDs' );
eq( b1.nextCursor, 3, 'first batch advances the cursor to 3' );
eq( b1.done, false, 'first batch is not the end' );

const b2 = P.sliceBatch( ids, b1.nextCursor, 3 );
eq( b2.slice, [ 10, 20, 50 ], 'second batch continues where the first stopped (no skips)' );

// A FAILED batch does not advance the cursor: retrying the same cursor
// returns the identical slice (no duplicates, no skips).
const retry = P.sliceBatch( ids, b1.nextCursor, 3 );
eq( retry.slice, b2.slice, 'retrying an unadvanced cursor returns the same slice' );

const b3 = P.sliceBatch( ids, b2.nextCursor, 3 );
eq( b3.slice, [ 70, 80 ], 'final batch contains the remaining IDs' );
eq( b3.done, true, 'final batch reports done' );

const b4 = P.sliceBatch( ids, b3.nextCursor, 3 );
eq( b4.slice, [], 'past the end the batch is empty' );
eq( b4.done, true, 'past the end done stays true' );

// Walking the whole list never duplicates or skips an ID.
const walked = [];
let cursor = 0;
for ( let guard = 0; guard < 50; guard++ ) {
	const batch = P.sliceBatch( ids, cursor, 2 );
	if ( batch.slice.length === 0 ) {
		break;
	}
	walked.push( ...batch.slice );
	cursor = batch.nextCursor;
}
eq( walked, ids, 'walking batches in sequence visits every ID exactly once, in order' );

// ---------------------------------------------------------------------------
console.log( `\n${ passed } passed, ${ failed } failed` );
process.exit( failed > 0 ? 1 : 0 );
