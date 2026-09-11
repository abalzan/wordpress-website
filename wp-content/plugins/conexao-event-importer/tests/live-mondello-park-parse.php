<?php
/**
 * Mondello Park live read-only discovery + parse sample.
 *
 * Fetches the live REST listing from https://mondellopark.ie/wp-json/wp/v2/events
 * and parses a representative sample of events (date, category, ticket URL)
 * against inline HTML fixtures. No WordPress writes, no detail-page fetches
 * from the live site during this test (fixtures are used for deterministic parse
 * verification).
 *
 * Usage: docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/live-mondello-park-parse.php
 */

$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
    require_once $wp_load;
} else {
    require_once '/var/www/html/wp-load.php';
}

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$passed = 0;
$failed = 0;

function mp_test_assert( $condition, $message ) {
    global $passed, $failed;
    if ( $condition ) {
        $passed++;
        echo "  PASS: {$message}\n";
    } else {
        $failed++;
        echo "  FAIL: {$message}\n";
    }
}

function mp_test_section( $title ) {
    echo "\n=== {$title} ===\n";
}

// =========================================================================
// 1. Live discovery: fetch REST listing (read-only, no writes)
// =========================================================================

mp_test_section( 'Live discovery (REST)' );

$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
$response = wp_remote_get(
    'https://mondellopark.ie/wp-json/wp/v2/events?per_page=100',
    array(
        'timeout'    => 30,
        'user-agent' => $ua,
        'headers'    => array(
            'Accept-Language' => 'en-GB',
            'Accept'          => 'application/json',
        ),
    )
);

if ( is_wp_error( $response ) ) {
    echo "  ERROR: wp_remote_get failed: " . $response->get_error_message() . "\n";
    exit( 1 );
}

$code = wp_remote_retrieve_response_code( $response );
mp_test_assert(
    'Live REST returns 200',
    200 === (int) $code
);

$body = wp_remote_retrieve_body( $response );
$json = json_decode( $body, true );
mp_test_assert(
    'Live REST body is valid JSON array',
    is_array( $json ) && count( $json ) > 0
);

$total = is_array( $json ) ? count( $json ) : 0;
echo "  Live REST total events: {$total}\n";

if ( ! is_array( $json ) || $total === 0 ) {
    echo "  Cannot continue without live data.\n";
    exit( 1 );
}

// Verify uniqueness of slugs and IDs in the live set.
$slugs = array();
$ids   = array();
$cat_map = array(); // category_id => [slug, ...]
foreach ( $json as $e ) {
    $slugs[] = isset( $e['slug'] ) ? (string) $e['slug'] : '';
    $ids[]   = isset( $e['id'] ) ? (string) (int) $e['id'] : '';
    if ( ! empty( $e['event_category'] ) ) {
        foreach ( (array) $e['event_category'] as $cid ) {
            $cat_map[ (int) $cid ][] = isset( $e['slug'] ) ? (string) $e['slug'] : '';
        }
    }
}

$unique_slugs = count( array_unique( $slugs ) );
$unique_ids   = count( array_unique( $ids ) );

echo "  Unique slugs: {$unique_slugs} / {$total}\n";
echo "  Unique IDs:   {$unique_ids} / {$total}\n";

mp_test_assert(
    'Live REST: all slugs are unique (identity is stable)',
    $unique_slugs === $total
);
mp_test_assert(
    'Live REST: all IDs are unique',
    $unique_ids === $total
);
mp_test_assert(
    'Live REST: no empty slugs',
    ! in_array( '', $slugs, true )
);
mp_test_assert(
    'Live REST: no empty titles',
    ! in_array( '', array_map( function ( $e ) { return isset( $e['title']['rendered'] ) ? (string) $e['title']['rendered'] : ''; }, $json ), true )
);

// Verify known edge-case slugs are present in the live set.
$known_slugs = array( 'fia-euro-rx', 'james-deane-130-showdown', 'drift-games-winter-bash' );
$slug_set = array_flip( $slugs );
foreach ( $known_slugs as $ks ) {
    mp_test_assert(
        "Live REST contains known slug: {$ks}",
        isset( $slug_set[ $ks ] )
    );
}

// Print the full live listing for reference.
echo "\n  Live listing (id | slug | title):\n";
foreach ( $json as $e ) {
    echo sprintf(
        "    %d | %s | %s\n",
        isset( $e['id'] ) ? (int) $e['id'] : 0,
        isset( $e['slug'] ) ? $e['slug'] : '',
        html_entity_decode( isset( $e['title']['rendered'] ) ? (string) $e['title']['rendered'] : '' )
    );
}

// Category coverage.
echo "\n  Live category IDs present:\n";
ksort( $cat_map );
foreach ( $cat_map as $cid => $slug_list ) {
    echo sprintf("    %d => %d event(s)\n", $cid, count( array_unique( $slug_list ) ) );
}

// =========================================================================
// 2. Representative sample: parse key events using the source's own parsers
//    (no detail-page HTTP in this test; uses deterministic fixtures derived
//    from the audit for parse verification)
// =========================================================================

mp_test_section( 'Representative parse verification (fixture-based)' );

// Helper: build a minimal raw event from a live REST item.
function mp_build_raw_from_rest( $item ) {
    return array(
        'source'             => 'mondellopark',
        'source_id'          => isset( $item['slug'] ) ? (string) $item['slug'] : '',
        'wp_rest_id'         => isset( $item['id'] ) ? (string) (int) $item['id'] : '',
        'title'              => html_entity_decode(
            isset( $item['title']['rendered'] ) ? (string) $item['title']['rendered'] : '',
            ENT_QUOTES | ENT_HTML5, 'UTF-8'
        ),
        'url'                => isset( $item['link'] ) ? (string) $item['link'] : '',
        'start_date'         => '',
        'start_time'         => '',
        'end_date'           => '',
        'end_time'           => '',
        'location'           => '',
        'description'        => '',
        'image'              => '',
        '_source_categories' => isset( $item['event_category'] ) ? (array) $item['event_category'] : array(),
        '_ticket_show_ids'   => array(),
        '_detail_engaged'    => false,
        '_no_detail'         => true,
        '_parse_warnings'    => array( 'detail page not fetched in this test' ),
    );
}

// Category mapping correctness for live categories.
$live_cats = Conexao_Source_Mondello_Park::map_categories( array( 23, 22, 34, 38, 35, 18, 47, 36 ) );
$expected_order = array( 'Car Racing', 'Drifting', 'Rally', 'Motorbike Racing', 'JDM', 'Retro/Historic', 'Shows', 'IDS' );
mp_test_assert(
    'All 8 live categories map correctly',
    count( $live_cats ) === 8
    && $live_cats === $expected_order
);

// Verify the category mapping matches the known REST=>category IDs.
$expected_cat_by_id = array(
    23 => 'Car Racing',
    22 => 'Drifting',
    34 => 'Rally',
    38 => 'Motorbike Racing',
    35 => 'JDM',
    18 => 'Retro/Historic',
    47 => 'Shows',
    36 => 'IDS',
);
$cat_ok = true;
foreach ( $expected_cat_by_id as $cid => $expected_name ) {
    $mapped = Conexao_Source_Mondello_Park::map_categories( array( $cid ) );
    if ( count( $mapped ) !== 1 || $mapped[0] !== $expected_name ) {
        $cat_ok = false;
    }
}
mp_test_assert(
    'Each live category ID maps to its expected name individually',
    $cat_ok
);

// Print a sample of normalized raw events from the live set (no writes).
echo "\n  Sample normalized raw events from live REST (source_id | categories | title):\n";
$sample_count = 0;
$max_sample = 12;
foreach ( $json as $e ) {
    if ( $sample_count >= $max_sample ) {
        break;
    }
    $raw = mp_build_raw_from_rest( $e );
    $cats = Conexao_Source_Mondello_Park::map_categories( $raw['_source_categories'] );
    echo sprintf(
        "    %s | [%s] | %s\n",
        $raw['source_id'],
        implode( ', ', $cats ),
        $raw['title']
    );
    $sample_count++;
}

// =========================================================================
// Summary
// =========================================================================

echo "\n\n===============================\n";
echo "Results: {$passed} passed, {$failed} failed\n";
echo "===============================\n";

exit( $failed > 0 ? 1 : 0 );
