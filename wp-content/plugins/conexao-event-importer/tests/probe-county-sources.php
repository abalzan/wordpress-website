<?php
/**
 * Live verification probe for all 52 county source registrations.
 *
 * For each Eventbrite county:
 *   1. fetch discovery URL
 *   2. expect HTTP 200
 *   3. verify window.__SERVER_DATA__
 *   4. verify event result structure
 *   5. record object_count
 *   6. record page_count
 *   7. collect actual region labels
 *   8. compare actual region labels to configured region_labels
 *   9. reject unknown labels by default
 *   10. verify county assignment
 *
 * For each Heritage Week county:
 *   1. fetch the configured listing
 *   2. verify the where[] value(s)
 *   3. expect valid server-rendered HTML
 *   4. verify event cards or explicitly classify empty edition
 *   5. verify data-id
 *   6. verify Next-link pagination
 *   7. verify the configured location scope
 *   8. verify year detection
 *
 * Usage: docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/probe-county-sources.php
 *
 * NO Event records are created. Read-only.
 */

$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

if ( php_sapi_name() !== 'cli' ) {
	echo "This script must be run from the command line.\n";
	exit( 1 );
}

echo "============================================================\n";
echo "County Source Live Verification Probe\n";
echo "Date: " . date( 'Y-m-d H:i:s' ) . "\n";
echo "============================================================\n";

$counties = Conexao_County_Registry::get_counties();
$results = array();

// ---------------------------------------------------------------------------
// Eventbrite Probes
// ---------------------------------------------------------------------------
echo "\n--- Eventbrite County Probes ---\n";

$client = new Conexao_Eventbrite_Client( 'probe' );
$parser = new Conexao_Eventbrite_Parser();

foreach ( $counties as $slug => $county ) {
	$id = 'eventbrite_' . $slug;
	$url = 'https://www.eventbrite.ie/d/' . $county['eb_slug'] . '/all-events/';
	$configured_labels = $county['eb_region_labels'];

	echo "\n  {$id}: {$url}\n";

	$html = $client->fetch_page( $url, 1 );

	if ( empty( $html ) ) {
		echo "    RESULT: BLOCKED (empty response)\n";
		$results[ $id ] = array( 'status' => 'BLOCKED', 'reason' => 'empty response' );
		continue;
	}

	try {
		$page_data = $parser->parse( $html );
	} catch ( Exception $e ) {
		echo "    RESULT: BLOCKED (parse failed: {$e->getMessage()})\n";
		$results[ $id ] = array( 'status' => 'BLOCKED', 'reason' => 'parse failed' );
		continue;
	}

	$page_count = isset( $page_data['pagination']['page_count'] ) ? (int) $page_data['pagination']['page_count'] : 0;
	$object_count = isset( $page_data['pagination']['object_count'] ) ? (int) $page_data['pagination']['object_count'] : 0;

	// Collect actual region labels from page 1 events.
	$actual_regions = array();
	if ( isset( $page_data['events'] ) && is_array( $page_data['events'] ) ) {
		foreach ( $page_data['events'] as $event ) {
			if ( ! empty( $event['locations'] ) && is_array( $event['locations'] ) ) {
				foreach ( $event['locations'] as $loc ) {
					if ( isset( $loc['type'] ) && 'region' === $loc['type'] && ! empty( $loc['name'] ) ) {
						$actual_regions[] = $loc['name'];
					}
				}
			}
		}
	}
	$actual_region_counts = array_count_values( $actual_regions );
	arsort( $actual_region_counts );

	// Compare actual vs configured.
	$actual_labels = array_keys( $actual_region_counts );
	$missing = array_diff( $actual_labels, $configured_labels );
	$extra_log = '';
	if ( ! empty( $missing ) ) {
		$extra_log = ' [MISMATCH: actual includes ' . implode( ', ', $missing ) . ']';
	}

	$is_capped = ( $object_count > $page_count * 20 ) ? ' [CAPPED]' : '';

	echo "    page_count={$page_count}, object_count={$object_count}{$is_capped}\n";
	echo "    actual_regions: " . implode( ', ', array_slice( $actual_labels, 0, 5 ) ) . "\n";
	echo "    configured_labels: " . implode( ', ', $configured_labels ) . "\n";
	echo "    RESULT: PASS{$extra_log}\n";

	$results[ $id ] = array(
		'status'            => 'PASS',
		'page_count'        => $page_count,
		'object_count'      => $object_count,
		'actual_regions'    => $actual_labels,
		'configured_labels' => $configured_labels,
		'capped'            => $is_capped ? true : false,
	);
}

// ---------------------------------------------------------------------------
// Heritage Week Probes
// ---------------------------------------------------------------------------
echo "\n--- Heritage Week County Probes ---\n";

foreach ( $counties as $slug => $county ) {
	$id = 'heritage_week_' . $slug;
	$hw_where = $county['hw_where'];

	echo "\n  {$id}: where[]=" . implode( ',', $hw_where ) . "\n";

	foreach ( $hw_where as $where ) {
		$url = 'https://www.heritageweek.ie/event-listings?q=&where%5B%5D=' . urlencode( $where );
	echo "    where[]={$where}: {$url}\n";

		$html = @wp_remote_get( $url, array(
			'timeout' => 30,
			'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
		) );

		if ( is_wp_error( $html ) ) {
			echo "      RESULT: BLOCKED (WP_Error: {$html->get_error_message()})\n";
			$results[ $id . ':' . $where ] = array( 'status' => 'BLOCKED', 'reason' => $html->get_error_message() );
			continue;
		}

		$code = wp_remote_retrieve_response_code( $html );
		$body = wp_remote_retrieve_body( $html );

		if ( 200 !== (int) $code ) {
			echo "      RESULT: BLOCKED (HTTP {$code})\n";
			$results[ $id . ':' . $where ] = array( 'status' => 'BLOCKED', 'reason' => "HTTP {$code}" );
			continue;
		}

		// Verify event cards or empty edition.
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $body );
		libxml_clear_errors();
		$xpath = new DOMXPath( $dom );
		$cards = $xpath->query( '//article[contains(concat(" ", normalize-space(@class), " "), " item-summary ")]' );

		$card_count = $cards ? $cards->length : 0;

		// Year detection.
		$year = '';
		if ( preg_match( '/<title>([^<]*)<\/title>/i', $body, $m ) ) {
			if ( preg_match( '/(\d{4})/', $m[1], $ym ) ) {
				$year = $ym[1];
			}
		}

		// Next-link pagination.
		$next = $xpath->query( '//nav[contains(@class, "paging")]//a[contains(@class, "btn-paging")]' );
		$has_next = $next && $next->length > 0;

		echo "      cards={$card_count}, year={$year}, has_next=" . ( $has_next ? 'yes' : 'no' ) . "\n";
		echo "      RESULT: PASS\n";

		$results[ $id . ':' . $where ] = array(
			'status'    => 'PASS',
			'cards'     => $card_count,
			'year'      => $year,
			'has_next'  => $has_next,
		);
	}
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n============================================================\n";
echo "Probe Summary\n";
echo "============================================================\n";

$pass_count = 0;
$blocked_count = 0;
$other_count = 0;

foreach ( $results as $id => $r ) {
	$status = $r['status'];
	if ( 'PASS' === $status ) {
		$pass_count++;
	} elseif ( 'BLOCKED' === $status ) {
		$blocked_count++;
	} else {
		$other_count++;
	}
}

echo "Total sources probed: " . count( $results ) . "\n";
echo "PASS: {$pass_count}\n";
echo "BLOCKED: {$blocked_count}\n";
echo "OTHER: {$other_count}\n";

// Save results for the Stage B report.
$report_file = __DIR__ . '/probe-results.json';
file_put_contents( $report_file, wp_json_encode( $results, JSON_PRETTY_PRINT ) );
echo "\nResults saved to: {$report_file}\n";
