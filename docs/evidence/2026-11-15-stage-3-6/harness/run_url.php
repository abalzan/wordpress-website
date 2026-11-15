<?php
/**
 * Stage 3.6 - run the enumeration for ONE unique discovery URL.
 * Usage: php run_url.php <url_id>
 */

require_once '/tmp/stage36/enum.php';
require_once '/tmp/stage36/classify.php';

s36_ensure_dirs();

$url_id = isset( $argv[1] ) ? $argv[1] : '';
if ( '' === $url_id ) {
	fwrite( STDERR, "usage: php run_url.php <url_id>\n" );
	exit( 2 );
}

$cfg = json_decode( file_get_contents( '/tmp/stage36/urls.json' ), true );
if ( ! isset( $cfg[ $url_id ] ) ) {
	fwrite( STDERR, "unknown url_id: {$url_id}\n" );
	exit( 2 );
}

// Group the target _event_source_id values under this URL's source IDs.
$targets = array();
$fh = fopen( '/tmp/stage36/targets.tsv', 'r' );
$hdr = fgetcsv( $fh, 0, "\t" );
while ( ( $row = fgetcsv( $fh, 0, "\t" ) ) !== false ) {
	$r = array_combine( $hdr, $row );
	if ( ! in_array( $r['source_id'], $cfg[ $url_id ]['source_ids'], true ) ) {
		continue;
	}
	$sid = $r['_event_source_id'];
	$targets[ $sid ][] = $r['post_id'];
}
fclose( $fh );

$st = s36_new_state( $url_id, $cfg[ $url_id ]['source_ids'], $cfg[ $url_id ]['url'], $targets );

printf( "[%s] target ids: %d across %d source(s)\n", $url_id, array_sum( array_map( 'count', $targets ) ), count( $cfg[ $url_id ]['source_ids'] ) );

$meta = array(
	'url_id'                  => $url_id,
	'url'                     => $st['url'],
	'source_ids'              => $st['source_ids'],
	'mode'                    => 'FAILED',
	'declared_pages'          => null,
	'object_count'            => null,
	'page_size'               => null,
	'ceiling'                 => null,
	'g8'                      => 'UNKNOWN',
	'page1_fingerprint'       => null,
	'page1_fingerprint_end'   => null,
	'global_requests_at_start'=> s36_global_requests_used(),
);

$p1 = s36_process_page( $st, 1, 1, 'pass1_page1' );
if ( null === $p1 || ! $p1['parse_ok'] ) {
	$meta['mode'] = 'FAILED';
	$res = array( 'meta' => $meta, 'state' => $st, 'verdict' => null );
	file_put_contents( S36_RUN . "/result-{$url_id}.json", json_encode( $res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	printf( "[%s] FAILED on page 1: fatal=%s parse=%s\n", $url_id, $st['fatal_reason'], $p1 ? $p1['parse_error'] : 'no response' );
	exit( 0 );
}

$declared    = $p1['pagination']['page_count'];
$object_cnt  = $p1['pagination']['object_count'];
$page_size   = $p1['pagination']['page_size'];
$ceiling     = ( $declared > 0 && $page_size > 0 ) ? $declared * $page_size : null;

$meta['declared_pages'] = $declared;
$meta['object_count']   = $object_cnt;
$meta['page_size']      = $page_size;
$meta['ceiling']        = $ceiling;
$meta['g8']             = ( null === $ceiling ) ? 'UNKNOWN' : ( ( null !== $object_cnt && $object_cnt > $ceiling ) ? 'TRUNCATED' : 'OK' );
$meta['page1_fingerprint'] = s36_page_fingerprint( $p1 );

$two_pass = ( null !== $declared && $declared > 0 && ( 2 * $declared ) <= ( S36_PER_URL_MAX_REQUESTS - 2 ) );
$meta['mode'] = $two_pass ? 'TWO_PASS' : 'DEEP_SINGLE';

printf( "[%s] page_count=%s object_count=%s page_size=%s ceiling=%s G8=%s mode=%s\n", $url_id, var_export( $declared, true ), var_export( $object_cnt, true ), var_export( $page_size, true ), var_export( $ceiling, true ), $meta['g8'], $meta['mode'] );

if ( $two_pass ) {
	for ( $p = 2; $p <= $declared; $p++ ) {
		if ( null === s36_process_page( $st, $p, 1, 'pass1' ) ) {
			break;
		}
	}
	$p1b = s36_process_page( $st, 1, 2, 'pass2_page1_stability_check' );
	$meta['page1_fingerprint_end'] = $p1b ? s36_page_fingerprint( $p1b ) : null;
	if ( $p1b && $p1b['parse_ok'] ) {
		for ( $p = 2; $p <= $declared; $p++ ) {
			if ( null === s36_process_page( $st, $p, 2, 'pass2' ) ) {
				break;
			}
		}
	}
} else {
	$walk_to = ( null !== $declared && $declared > 0 ) ? min( $declared, 27 ) : 27;
	for ( $p = 2; $p <= $walk_to; $p++ ) {
		if ( null === s36_process_page( $st, $p, 1, 'pass1_deep' ) ) {
			break;
		}
	}
	$rc = s36_process_page( $st, 1, 1, 'page1_stability_recheck', true );
	$meta['page1_fingerprint_end'] = ( $rc && $rc['parse_ok'] ) ? s36_page_fingerprint( $rc ) : null;
}

$verdict = s36_classify( $st, $meta );

$res = array( 'meta' => $meta, 'state' => $st, 'verdict' => $verdict );
file_put_contents( S36_RUN . "/result-{$url_id}.json", json_encode( $res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

printf(
	"[%s] DONE requests=%d bytes=%d elapsed=%.1fs stop=%s fatal=%s pass1_unique=%d pass2_unique=%d absent_allowed=%s\n",
	$url_id,
	$st['requests_used'],
	$st['requests_bytes'],
	microtime( true ) - $st['started_at'],
	'' === $st['stop_reason'] ? 'walk_finished' : $st['stop_reason'],
	'' === $st['fatal_reason'] ? '-' : $st['fatal_reason'],
	$verdict['pass1']['count'],
	$verdict['pass2']['count'],
	$verdict['absent_allowed'] ? 'YES' : 'NO'
);
if ( ! empty( $verdict['blockers'] ) ) {
	foreach ( $verdict['blockers'] as $b ) {
		printf( "    blocker: %s\n", $b );
	}
}
$counts = array( 'PRESENT' => 0, 'ABSENT' => 0, 'INDETERMINATE' => 0 );
foreach ( $verdict['rows'] as $r ) {
	$counts[ $r['presence'] ]++;
}
printf( "    targets: PRESENT=%d ABSENT=%d INDETERMINATE=%d\n", $counts['PRESENT'], $counts['ABSENT'], $counts['INDETERMINATE'] );
