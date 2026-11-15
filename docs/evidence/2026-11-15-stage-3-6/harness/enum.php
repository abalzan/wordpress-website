<?php
/**
 * Stage 3.6 - per-URL enumeration procedure (G8 + G10 aware, budget-bounded).
 *
 * Deliberately does NOT reuse Conexao_Source_Eventbrite's pagination walk or
 * checkpoint/termination logic. It implements the independent enumeration the
 * task requires, driven only by the provider's declared pagination metadata.
 */

require_once '/tmp/stage36/lib.php';

function s36_new_state( $url_id, $source_ids, $url, $targets ) {
	return array(
		'url_id'            => $url_id,
		'source_ids'        => $source_ids,
		'url'               => $url,
		'targets'           => $targets,   // [ _event_source_id => [post_id, ...] ]
		'started_at'        => microtime( true ),
		'deadline'          => microtime( true ) + S36_PER_URL_MAX_SECONDS,
		'requests_used'     => 0,
		'requests_bytes'    => 0,
		'requests_ms'       => 0,
		'cause'             => array(),    // ledger causes
		'rate_limited'      => false,
		'rate_limit_hits'   => 0,
		'global_rate_limit' => false,
		'fatal_reason'      => '',
		'stop_reason'       => '',
		'parse_failures'    => array(),
		'pages'             => array(),    // page records, chronological
	);
}

function s36_budget_ok( &$st ) {
	if ( $st['requests_used'] >= S36_PER_URL_MAX_REQUESTS ) {
		$st['stop_reason'] = 'per_url_request_budget';
		return false;
	}
	if ( s36_global_requests_used() >= S36_GLOBAL_MAX_REQUESTS ) {
		$st['stop_reason'] = 'global_request_budget';
		return false;
	}
	if ( microtime( true ) >= $st['deadline'] ) {
		$st['stop_reason'] = 'per_url_time_budget';
		return false;
	}
	return true;
}

/**
 * One GET with the plugin's bounded retry policy.
 * Every attempt (including retries) is charged to both budgets and recorded.
 */
function s36_request( &$st, $page, $pass, $note = '' ) {
	$url = s36_page_url( $st['url'], $page );

	for ( $attempt = 1; $attempt <= S36_MAX_RETRIES; $attempt++ ) {
		if ( ! s36_budget_ok( $st ) ) {
			return null;
		}

		$wait = S36_PAGE_DELAY_SECONDS;
		if ( isset( $st['last_request_end'] ) ) {
			$wait = max( 0.0, S36_PAGE_DELAY_SECONDS - ( microtime( true ) - $st['last_request_end'] ) );
		}
		$remaining = $st['deadline'] - microtime( true );
		if ( $wait + 5.0 > $remaining ) {
			$st['stop_reason'] = 'per_url_time_budget';
			$st['fatal_reason'] = 'time_budget_before_request';
			return null;
		}
		if ( $wait > 0 ) {
			usleep( (int) round( $wait * 1000000 ) );
		}

		$r = s36_http_get( $url );
		$st['last_request_end'] = microtime( true );
		++$st['requests_used'];
		$st['requests_bytes'] += $r['bytes'];
		$st['requests_ms']    += $r['elapsed_ms'];

		$ra  = s36_parse_retry_after( isset( $r['headers']['retry-after'] ) ? $r['headers']['retry-after'] : null );
		$row = array(
			'url_id'        => $st['url_id'],
			'source_ids'    => $st['source_ids'],
			'pass'          => $pass,
			'page'          => $page,
			'request_url'   => $url,
			'ts_utc'        => s36_now_utc(),
			'method'        => 'GET',
			'http_status'   => $r['status'],
			'headers'       => s36_interesting_headers( $r['headers'] ),
			'bytes'         => $r['bytes'],
			'sha256'        => $r['sha256'],
			'elapsed_ms'    => $r['elapsed_ms'],
			'attempt'       => $attempt,
			'retry_reason'  => '',
			'curl_errno'    => $r['curl_errno'],
			'curl_error'    => $r['curl_error'],
			'note'          => $note,
		);

		if ( 0 === $r['curl_errno'] && 200 === $r['status'] ) {
			$row['outcome'] = 'ok';
			s36_ledger_append( $row );
			return array( 'transport' => $r, 'attempt' => $attempt, 'row' => $row );
		}

		$retryable = ( 0 !== $r['curl_errno'] ) || in_array( (int) $r['status'], S36_RETRYABLE_STATUSES, true );
		if ( ! $retryable ) {
			$row['outcome'] = 'non_retryable';
			$row['body_excerpt'] = s36_excerpt( $r['body'] );
			s36_ledger_append( $row );
			$st['fatal_reason'] = 0 === $r['status'] ? 'transport_error' : 'http_' . $r['status'];
			return null;
		}

		if ( 429 === (int) $r['status'] ) {
			$st['rate_limited'] = true;
			++$st['rate_limit_hits'];
			$cooldown = null !== $ra['delay'] ? $ra['delay'] : min( S36_RATE_LIMIT_BASE_SECONDS * $attempt, S36_MAX_COOLDOWN_SECONDS );
		} elseif ( null !== $ra['delay'] ) {
			$cooldown = $ra['delay'];
		} else {
			$cooldown = S36_BASE_BACKOFF_SECONDS * pow( 2, $attempt - 1 );
		}

		$row['outcome']           = 'retryable';
		$row['body_excerpt']      = s36_excerpt( $r['body'] );
		$row['retry_reason']      = 429 === (int) $r['status'] ? 'rate_limited' : ( 0 === $r['status'] ? 'transport_error' : 'http_' . $r['status'] );
		$row['retry_after_kind']  = $ra['kind'];
		$row['cooldown_seconds']  = $cooldown;
		s36_ledger_append( $row );

		if ( $attempt >= S36_MAX_RETRIES ) {
			$st['fatal_reason'] = 'rate_limited' === $row['retry_reason'] ? 'rate_limited' : $row['retry_reason'];
			return null;
		}

		$remaining = $st['deadline'] - microtime( true );
		if ( $cooldown + 5.0 > $remaining ) {
			$st['stop_reason'] = 'per_url_time_budget';
			$st['fatal_reason'] = 'cooldown_exceeds_budget';
			return null;
		}
		usleep( (int) round( $cooldown * 1000000 ) );
	}

	return null;
}

/**
 * Fetch + parse + validate one page. Returns a page record, or null when the
 * request itself failed (reason recorded in state).
 */
function s36_process_page( &$st, $page, $pass, $note = '', $recheck = false ) {
	$res = s36_request( $st, $page, $pass, $note );
	if ( null === $res ) {
		return null;
	}

	$html = $res['transport']['body'];
	$rec  = array(
		'pass'                 => $pass,
		'page'                 => $page,
		'requested_page'       => $page,
		'http_status'          => $res['transport']['status'],
		'attempt'              => $res['attempt'],
		'fetched_at_utc'       => $res['row']['ts_utc'],
		'elapsed_ms'           => $res['transport']['elapsed_ms'],
		'response_sha256'      => $res['transport']['sha256'],
		'body_bytes'           => $res['transport']['bytes'],
		'content_type'         => $res['transport']['content_type'],
		'parse_ok'             => false,
		'parse_error'          => '',
		'crosscheck'           => 'not_run',
		'result_count'         => 0,
		'promoted_count'       => 0,
		'ordered_result_ids'   => array(),
		'ordered_promoted_ids' => array(),
		'pagination'           => array(),
		'pagination_keys'      => array(),
		'page_number_reported' => null,
		'partial_page'         => false,
		'payload_file'         => '',
		'payload_sha256'       => '',
		'note'                 => $note,
		'recheck'              => (bool) $recheck,
	);

	$parser = new Conexao_Eventbrite_Parser();
	try {
		$parsed = $parser->parse( $html );
	} catch ( Exception $e ) {
		$rec['parse_error'] = $e->getMessage();
		$st['parse_failures'][] = sprintf( 'pass %d page %d: %s', $pass, $page, $e->getMessage() );
		$st['pages'][] = $rec;
		return $rec;
	}

	$results  = $parsed['events'];
	$pag      = $parsed['pagination'];
	$promoted = isset( $parsed['raw']['search_data']['events']['promoted_results'] )
		&& is_array( $parsed['raw']['search_data']['events']['promoted_results'] )
			? $parsed['raw']['search_data']['events']['promoted_results']
			: array();

	$ids = array();
	foreach ( $results as $e ) {
		$ids[] = (string) $e['id'];
	}
	$pids = array();
	foreach ( $promoted as $e ) {
		if ( is_array( $e ) && ! empty( $e['id'] ) ) {
			$pids[] = (string) $e['id'];
		}
	}

	$rec['parse_ok']             = true;
	$rec['result_count']         = count( $ids );
	$rec['promoted_count']       = count( $pids );
	$rec['ordered_result_ids']   = $ids;
	$rec['ordered_promoted_ids'] = $pids;
	$rec['pagination']           = array(
		'page_count'   => isset( $pag['page_count'] ) ? (int) $pag['page_count'] : null,
		'object_count' => isset( $pag['object_count'] ) ? (int) $pag['object_count'] : null,
		'page_size'    => isset( $pag['page_size'] ) ? (int) $pag['page_size'] : null,
		'page_number'  => isset( $pag['page_number'] ) ? (int) $pag['page_number'] : null,
	);
	$rec['pagination_keys']      = array_keys( $pag );
	$rec['page_number_reported'] = $rec['pagination']['page_number'];
	$rec['partial_page']         = ( count( $ids ) > 0 && $rec['pagination']['page_size'] && count( $ids ) < (int) $rec['pagination']['page_size'] );

	// Independent cross-check of the same page body.
	$indep = s36_independent_extract( $html );
	if ( null === $indep ) {
		$rec['crosscheck'] = 'independent_extract_failed';
	} else {
		$ih = isset( $indep['search_data']['events']['results'] ) && is_array( $indep['search_data']['events']['results'] )
			? $indep['search_data']['events']['results'] : array();
		$iids = array();
		foreach ( $ih as $e ) {
			if ( is_array( $e ) && ! empty( $e['id'] ) ) {
				$iids[] = (string) $e['id'];
			}
		}
		$ipag = isset( $indep['search_data']['events']['pagination'] ) && is_array( $indep['search_data']['events']['pagination'] )
			? $indep['search_data']['events']['pagination'] : array();
		$same = ( $iids === $ids )
			&& ( (int) ( $ipag['page_count'] ?? -1 ) === (int) ( $rec['pagination']['page_count'] ?? -2 ) )
			&& ( (int) ( $ipag['object_count'] ?? -1 ) === (int) ( $rec['pagination']['object_count'] ?? -2 ) )
			&& ( (int) ( $ipag['page_size'] ?? -1 ) === (int) ( $rec['pagination']['page_size'] ?? -2 ) );
		$rec['crosscheck'] = $same ? 'ok' : 'MISMATCH';
	}

	// Sanitised page payload evidence.
	$payload = array(
		'provenance' => array(
			'url_id'             => $st['url_id'],
			'source_ids'         => $st['source_ids'],
			'discovery_url'      => $st['url'],
			'request_url'        => s36_page_url( $st['url'], $page ),
			'pass'               => $pass,
			'page'               => $page,
			'fetched_at_utc'     => $rec['fetched_at_utc'],
			'http_status'        => $rec['http_status'],
			'response_sha256'    => $rec['response_sha256'],
			'body_bytes'         => $rec['body_bytes'],
			'result_count'       => $rec['result_count'],
			'promoted_count'     => $rec['promoted_count'],
			'pagination'         => $rec['pagination'],
			'pagination_keys'    => $rec['pagination_keys'],
			'parse'              => array( 'plugin_parser' => 'ok', 'independent_crosscheck' => $rec['crosscheck'] ),
		),
		'payload' => array(
			'pagination'       => $pag,
			'results'          => array_map( 's36_project_event', $results ),
			'promoted_results' => array_map( 's36_project_event', array_filter( $promoted, 'is_array' ) ),
		),
	);

	$file = sprintf( '%s/payload/%s_pass%d_page%02d.json', S36_RUN, $st['url_id'], $pass, $page );
	$json = json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	file_put_contents( $file, $json );
	$rec['payload_file']   = basename( $file );
	$rec['payload_sha256'] = hash( 'sha256', $json );

	$st['pages'][] = $rec;
	return $rec;
}
