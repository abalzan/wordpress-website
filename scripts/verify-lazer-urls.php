<?php
/**
 * Verify official websites and Discover Ireland URLs for the Lazer expansion dataset.
 *
 * For every entry in scripts/data/leisure-expansion-data-*.php this script:
 *   1. Checks the official_website URL responds with HTTP 2xx (following redirects).
 *      - 403/405/429 are flagged as "manual review" (site may block bots) but kept.
 *      - 404/410/5xx/timeouts are reported as FAIL (must be removed before import).
 *   2. For entries without a discover_ireland URL, tries two candidate URL patterns
 *      (/{county}/{slug} and /{slug}) and validates the candidate by checking that
 *      the attraction name appears in the page body. Validated URLs are reported
 *      as SUGGESTED so they can be added to the dataset.
 *   3. Explicit discover_ireland URLs are validated the same way.
 *
 * Run via:
 *   wp eval-file scripts/verify-lazer-urls.php --allow-root
 *
 * Output: console summary + scripts/data/url-verification-report.json
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'WP_USE_THEMES', false );
	$dir = dirname( __FILE__ );
	while ( $dir !== dirname( $dir ) ) {
		if ( file_exists( $dir . '/wp-load.php' ) ) {
			require_once $dir . '/wp-load.php';
			break;
		}
		$dir = dirname( $dir );
	}
	if ( ! defined( 'ABSPATH' ) ) {
		fwrite( STDERR, "Unable to locate wp-load.php\n" );
		exit( 1 );
	}
}

$part1 = require dirname( __FILE__ ) . '/data/leisure-expansion-data-1.php';
$part2 = require dirname( __FILE__ ) . '/data/leisure-expansion-data-2.php';
$locations = array_merge( $part1, $part2 );

echo 'Verifying URLs for ' . count( $locations ) . " locations...\n";

/**
 * Fetch a URL and return array(status_code, body).
 */
function conexao_verify_fetch( $url ) {
	$response = wp_remote_get( $url, array(
		'timeout'     => 12,
		'redirection' => 5,
		'headers'     => array(
			'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
			'Accept'     => 'text/html,application/xhtml+xml',
		),
	) );
	if ( is_wp_error( $response ) ) {
		return array( 0, '' );
	}
	return array( (int) wp_remote_retrieve_response_code( $response ), wp_remote_retrieve_body( $response ) );
}

/**
 * Extract a lowercase text needle from an attraction title for body matching.
 */
function conexao_verify_needle( $title ) {
	$t = strtolower( $title );
	$t = preg_replace( '/\(.*?\)/', ' ', $t );          // drop parentheticals
	$t = str_replace( array( '–', '—', '&', '/' ), ' ', $t );
	$t = preg_replace( '/[^a-z0-9áéíóúàèìòùâêîôûãõç\s]/u', ' ', $t );
	$t = trim( preg_replace( '/\s+/u', ' ', $t ) );
	$words = explode( ' ', $t );
	// Skip leading articles.
	while ( $words && in_array( $words[0], array( 'the', 'a', 'o', 'a' ), true ) ) {
		array_shift( $words );
	}
	return implode( ' ', $words );
}

/**
 * Check whether a page body plausibly matches the attraction name.
 */
function conexao_verify_body_matches( $body, $needle ) {
	if ( '' === $body ) {
		return false;
	}
	$text = strtolower( wp_strip_all_tags( $body ) );
	$text = preg_replace( '/\s+/u', ' ', $text );
	if ( false !== strpos( $text, $needle ) ) {
		return true;
	}
	// Fall back to the first two significant words.
	$words = explode( ' ', $needle );
	if ( count( $words ) >= 2 ) {
		$two = $words[0] . ' ' . $words[1];
		if ( false !== strpos( $text, $two ) ) {
			return true;
		}
	}
	return false;
}

$report = array();
$ok = 0; $manual = 0; $fail = 0; $di_ok = 0; $di_suggested = 0; $di_fail = 0;

foreach ( $locations as $loc ) {
	$slug = $loc['slug'];
	$needle = conexao_verify_needle( $loc['title'] );

	// --- Official website ---
	$official = $loc['official_website'];
	$official_result = array( 'status' => 'none', 'http' => 0, 'url' => $official );
	if ( $official ) {
		list( $code, $body ) = conexao_verify_fetch( $official );
		if ( $code >= 200 && $code < 300 ) {
			$official_result = array( 'status' => 'ok', 'http' => $code, 'url' => $official );
			$ok++;
			echo "  OK      {$slug} official {$code}\n";
		} elseif ( in_array( $code, array( 403, 405, 406, 429, 999 ), true ) ) {
			$official_result = array( 'status' => 'manual_review', 'http' => $code, 'url' => $official );
			$manual++;
			echo "  REVIEW  {$slug} official {$code} (may block bots — verify manually)\n";
		} else {
			$official_result = array( 'status' => 'fail', 'http' => $code, 'url' => $official );
			$fail++;
			echo "  FAIL    {$slug} official {$code} {$official}\n";
		}
	}

	// --- Discover Ireland ---
	$di = $loc['discover_ireland'];
	$di_result = array( 'status' => 'none', 'http' => 0, 'url' => '', 'suggestions' => array() );
	if ( $di ) {
		list( $code, $body ) = conexao_verify_fetch( $di );
		$matches = conexao_verify_body_matches( $body, $needle );
		if ( $code >= 200 && $code < 300 && $matches ) {
			$di_result['status'] = 'ok';
			$di_result['http'] = $code;
			$di_result['url'] = $di;
			$di_ok++;
			echo "  DI-OK   {$slug} {$code}\n";
		} else {
			$di_result['status'] = 'fail';
			$di_result['http'] = $code;
			$di_result['url'] = $di;
			$di_fail++;
			echo "  DI-FAIL {$slug} {$code} {$di}\n";
		}
	} else {
		// Try candidate patterns.
		$county_slug = strtolower( $loc['county'] );
		$name_slug = $slug;
		$candidates = array(
			'https://www.discoverireland.ie/' . $county_slug . '/' . $name_slug,
			'https://www.discoverireland.ie/' . $name_slug,
		);
		foreach ( $candidates as $candidate ) {
			list( $code, $body ) = conexao_verify_fetch( $candidate );
			if ( $code >= 200 && $code < 300 && conexao_verify_body_matches( $body, $needle ) ) {
				$di_result['status'] = 'suggested';
				$di_result['http'] = $code;
				$di_result['url'] = $candidate;
				$di_result['suggestions'][] = $candidate;
				$di_suggested++;
				echo "  DI-SUGG {$slug} {$code} {$candidate}\n";
				break;
			}
		}
	}

	$report[] = array(
		'slug'     => $slug,
		'title'    => $loc['title'],
		'county'   => $loc['county'],
		'official' => $official_result,
		'discover' => $di_result,
	);
}

$summary = array(
	'checked_at'        => current_time( 'c' ),
	'total'             => count( $locations ),
	'official_ok'       => $ok,
	'official_review'   => $manual,
	'official_fail'     => $fail,
	'discover_ok'       => $di_ok,
	'discover_suggested'=> $di_suggested,
	'discover_fail'     => $di_fail,
	'items'             => $report,
);

$out = dirname( __FILE__ ) . '/data/url-verification-report.json';
file_put_contents( $out, json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

echo "\n=== Summary ===\n";
echo "Official OK: {$ok} | Manual review: {$manual} | FAIL: {$fail}\n";
echo "Discover OK: {$di_ok} | Suggested: {$di_suggested} | FAIL: {$di_fail}\n";
echo "Report saved to: {$out}\n";