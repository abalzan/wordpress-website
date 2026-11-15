<?php
/**
 * Stage 3.6 - standalone Eventbrite discovery fetch harness (shared library).
 *
 * Isolation contract (verified, see 01-canary-offaly.md):
 *   - NEVER loads WordPress (no wp-load.php, no wp-settings.php).
 *   - NEVER loads the plugin bootstrap (no conexao-event-importer.php).
 *   - The ONLY plugin file required is class-eventbrite-parser.php, a pure
 *     data-transformation class that calls no WordPress function at all
 *     (verified by grep). It carries a `defined('ABSPATH') || exit;` guard,
 *     so the constant is defined here to a scratch path. Defining a constant
 *     registers nothing: no hook, no filter, no scheduled task.
 *   - No option, transient, cache, log, history or continuation store is read
 *     or written. No database connection is opened.
 *
 * Policy values below are COPIED from the plugin, not invented:
 *   class-eventbrite-client.php  USER_AGENT, MAX_RETRIES, BASE_BACKOFF_SECONDS,
 *                                RETRYABLE_STATUSES, MAX_RETRY_AFTER_SECONDS,
 *                                build_page_url()
 *   class-eventbrite-pacing.php  PAGE_DELAY_SECONDS, RATE_LIMIT_BASE_SECONDS,
 *                                MAX_COOLDOWN_SECONDS, get_cooldown_seconds()
 *
 * The source handler's pagination/termination logic is deliberately NOT used.
 */

define( 'ABSPATH', '/tmp/stage36/isolated-no-wordpress/' );

require_once '/home/andrei/IdeaProjects/wordpress-website/wp-content/plugins/conexao-event-importer/includes/class-eventbrite-parser.php';

const S36_USER_AGENT              = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
const S36_MAX_RETRIES             = 3;
const S36_BASE_BACKOFF_SECONDS    = 2;
const S36_PAGE_DELAY_SECONDS      = 2.0;
const S36_RATE_LIMIT_BASE_SECONDS = 20;
const S36_MAX_COOLDOWN_SECONDS    = 60;
const S36_MAX_RETRY_AFTER_SECONDS = 60;
const S36_RETRYABLE_STATUSES      = array( 429, 500, 502, 503, 504 );

const S36_PER_URL_MAX_REQUESTS = 30;
const S36_PER_URL_MAX_SECONDS  = 120;
const S36_GLOBAL_MAX_REQUESTS  = 200;

define( 'S36_RUN', '/tmp/stage36/run' );

function s36_ensure_dirs() {
	foreach ( array( S36_RUN, S36_RUN . '/payload' ) as $d ) {
		if ( ! is_dir( $d ) ) {
			mkdir( $d, 0775, true );
		}
	}
}

function s36_now_utc() {
	return gmdate( 'Y-m-d\TH:i:s\Z' );
}

function s36_page_url( $base_url, $page ) {
	if ( $page <= 1 ) {
		return $base_url;
	}
	$separator = ( false === strpos( $base_url, '?' ) ) ? '?' : '&';
	return $base_url . $separator . 'page=' . (int) $page;
}

function s36_ledger_path() {
	return S36_RUN . '/request-ledger.jsonl';
}

function s36_ledger_append( array $row ) {
	file_put_contents( s36_ledger_path(), json_encode( $row, JSON_UNESCAPED_SLASHES ) . "\n", FILE_APPEND );
}

function s36_ledger_rows() {
	$p = s36_ledger_path();
	if ( ! file_exists( $p ) ) {
		return array();
	}
	$rows = array();
	foreach ( explode( "\n", trim( (string) file_get_contents( $p ) ) ) as $line ) {
		if ( '' === $line ) {
			continue;
		}
		$d = json_decode( $line, true );
		if ( is_array( $d ) ) {
			$rows[] = $d;
		}
	}
	return $rows;
}

function s36_global_requests_used() {
	return count( s36_ledger_rows() );
}

function s36_parse_retry_after( $value ) {
	if ( null === $value || '' === trim( (string) $value ) ) {
		return array( 'delay' => null, 'kind' => 'absent' );
	}
	$value = trim( (string) $value );
	if ( ctype_digit( $value ) ) {
		return array( 'delay' => (float) min( (int) $value, S36_MAX_RETRY_AFTER_SECONDS ), 'kind' => 'seconds' );
	}
	$ts = strtotime( $value );
	if ( false !== $ts ) {
		$delta = max( 0, $ts - time() );
		return array( 'delay' => (float) min( $delta, S36_MAX_RETRY_AFTER_SECONDS ), 'kind' => 'http-date' );
	}
	return array( 'delay' => null, 'kind' => 'unsupported' );
}

function s36_http_get( $url ) {
	$ch      = curl_init();
	$headers = array();
	$t0      = microtime( true );

	curl_setopt_array(
		$ch,
		array(
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_MAXREDIRS      => 0,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_USERAGENT      => S36_USER_AGENT,
			CURLOPT_ENCODING       => '',
			CURLOPT_HTTPHEADER     => array( 'Accept: text/html,application/xhtml+xml' ),
			CURLOPT_HEADERFUNCTION => function ( $c, $line ) use ( &$headers ) {
				$pos = strpos( $line, ':' );
				if ( false !== $pos ) {
					$name = strtolower( trim( substr( $line, 0, $pos ) ) );
					$val  = trim( substr( $line, $pos + 1 ) );
					$headers[ $name ] = isset( $headers[ $name ] ) ? $headers[ $name ] . ', ' . $val : $val;
				}
				return strlen( $line );
			},
		)
	);

	$body    = curl_exec( $ch );
	$errno   = curl_errno( $ch );
	$err     = curl_error( $ch );
	$status  = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	$ctype   = (string) curl_getinfo( $ch, CURLINFO_CONTENT_TYPE );
	$elapsed = ( microtime( true ) - $t0 ) * 1000;

	$body = ( false === $body ) ? '' : $body;

	return array(
		'status'       => $status,
		'headers'      => $headers,
		'content_type' => $ctype,
		'body'         => $body,
		'bytes'        => strlen( $body ),
		'sha256'       => hash( 'sha256', $body ),
		'elapsed_ms'   => (int) round( $elapsed ),
		'curl_errno'   => (int) $errno,
		'curl_error'   => (string) $err,
	);
}

function s36_interesting_headers( array $headers ) {
	$keep = array( 'retry-after', 'link', 'content-type', 'content-length', 'content-encoding', 'location', 'x-ratelimit-limit', 'x-ratelimit-remaining', 'x-ratelimit-reset', 'x-total-count', 'x-page', 'cache-control', 'age', 'vary' );
	$out  = array();
	foreach ( $keep as $k ) {
		if ( isset( $headers[ $k ] ) ) {
			$out[ $k ] = $headers[ $k ];
		}
	}
	foreach ( $headers as $k => $v ) {
		if ( 0 === strpos( $k, 'x-ratelimit-' ) && ! isset( $out[ $k ] ) ) {
			$out[ $k ] = $v;
		}
	}
	return $out;
}

function s36_independent_extract( $html ) {
	$pos = strpos( $html, 'window.__SERVER_DATA__' );
	if ( false === $pos ) {
		return null;
	}
	$eq = strpos( $html, '=', $pos );
	if ( false === $eq ) {
		return null;
	}
	$start  = strpos( $html, '{', $eq );
	if ( false === $start ) {
		return null;
	}
	$len    = strlen( $html );
	$depth  = 0;
	$in_str = false;
	$esc    = false;
	for ( $i = $start; $i < $len; $i++ ) {
		$c = $html[ $i ];
		if ( $in_str ) {
			if ( $esc ) { $esc = false; continue; }
			if ( '\\' === $c ) { $esc = true; continue; }
			if ( '"' === $c ) { $in_str = false; }
			continue;
		}
		if ( '"' === $c ) { $in_str = true; continue; }
		if ( '{' === $c ) { $depth++; continue; }
		if ( '}' === $c ) {
			$depth--;
			if ( 0 === $depth ) {
				$data = json_decode( substr( $html, $start, $i - $start + 1 ), true );
				return is_array( $data ) ? $data : null;
			}
		}
	}
	return null;
}

function s36_project_event( $raw ) {
	return array(
		'id'         => isset( $raw['id'] ) ? (string) $raw['id'] : '',
		'name'       => isset( $raw['name'] ) ? (string) $raw['name'] : '',
		'start_date' => isset( $raw['start_date'] ) ? (string) $raw['start_date'] : '',
		'end_date'   => isset( $raw['end_date'] ) ? (string) $raw['end_date'] : '',
		'url'        => isset( $raw['url'] ) ? (string) $raw['url'] : '',
	);
}

/** Bounded, tag-stripped excerpt of a non-200 body (diagnostic only). */
function s36_excerpt( $body, $limit = 600 ) {
	$text = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', ' ', (string) $body );
	$text = preg_replace( '/<style\b[^>]*>.*?<\/style>/is', ' ', $text );
	$text = strip_tags( $text );
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = preg_replace( '/\s+/', ' ', $text );
	return trim( substr( trim( (string) $text ), 0, $limit ) );
}
