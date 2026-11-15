<?php
/**
 * Stage 3.6 - one diagnostic GET to the authorised canary URL, capturing the
 * COMPLETE response header set so the blocking party is documented.
 * Still a discovery-only GET to an authorised URL; no other URL is touched.
 */
require_once '/tmp/stage36/lib.php';

$cfg = json_decode( file_get_contents( '/tmp/stage36/urls.json' ), true );
$url = $cfg['offaly']['url'];
$r   = s36_http_get( $url );

echo "URL: {$url}\n";
echo 'HTTP: ' . $r['status'] . "\n";
echo 'bytes: ' . $r['bytes'] . "\n";
echo 'sha256: ' . $r['sha256'] . "\n\n";
echo "ALL RESPONSE HEADERS:\n";
foreach ( $r['headers'] as $k => $v ) {
	echo '  ' . $k . ': ' . $v . "\n";
}
echo "\nBODY EXCERPT:\n" . s36_excerpt( $r['body'], 900 ) . "\n";

s36_ledger_append(
	array(
		'url_id'      => 'offaly',
		'source_ids'  => array( 'eventbrite_offaly' ),
		'pass'        => 1,
		'page'        => 1,
		'request_url' => $url,
		'ts_utc'      => s36_now_utc(),
		'method'      => 'GET',
		'http_status' => $r['status'],
		'headers'     => $r['headers'],
		'bytes'       => $r['bytes'],
		'sha256'      => $r['sha256'],
		'elapsed_ms'  => $r['elapsed_ms'],
		'attempt'     => 1,
		'retry_reason'=> '',
		'curl_errno'  => $r['curl_errno'],
		'curl_error'  => $r['curl_error'],
		'note'        => 'canary_diagnostic_full_headers',
		'outcome'     => 'non_retryable',
		'body_excerpt'=> s36_excerpt( $r['body'] ),
	)
);
