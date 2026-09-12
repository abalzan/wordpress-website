<?php
require '/var/www/html/wp-load.php';

$url = 'https://www.heritageweek.ie/event-listings?q=&where%5B%5D=laois';
$resp = @wp_remote_get(
	$url,
	array(
		'timeout'   => 30,
		'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
	)
);
if ( is_wp_error( $resp ) ) {
	echo 'ERROR: ' . $resp->get_error_message() . "\n";
	exit;
}
$body  = wp_remote_retrieve_body( $resp );
$title = '';
if ( preg_match( '/<title>([^<]*)<\/title>/i', $body, $m ) ) {
	$title = $m[1];
}
echo "Title: $title\n";
echo 'Body length: ' . strlen( $body ) . "\n";
echo 'Has item-summary: ' . ( preg_match( '/item-summary/i', $body ) ? 'yes' : 'no' ) . "\n";
echo 'Has no-results: ' . ( preg_match( '/no results|no events|nothing found|no items/i', $body ) ? 'yes' : 'no' ) . "\n";
echo 'Has article tag: ' . ( preg_match( '/<article/i', $body ) ? 'yes' : 'no' ) . "\n";

// Try various selectors.
$dom = new DOMDocument();
libxml_use_internal_errors( true );
$dom->loadHTML( '<?xml encoding="UTF-8">' . $body );
libxml_clear_errors();
$xpath = new DOMXPath( $dom );

$selectors = array(
	'//article[contains(concat(" ", normalize-space(@class), " "), " item-summary ")]',
	'//article',
	'//div[contains(@class, "item-summary")]',
	'//div[contains(@class, "event")]',
	'//*[contains(@class, "summary")]',
	'//a[contains(@href, "/event/")]',
);

foreach ( $selectors as $sel ) {
	$nodes = $xpath->query( $sel );
	echo 'Selector "' . $sel . '": ' . ( $nodes ? $nodes->length : 0 ) . "\n";
}

file_put_contents( '/tmp/hw-page.html', $body );
echo "Saved /tmp/hw-page.html\n";
