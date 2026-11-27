<?php
/**
 * Stage 3.6 - presence / absence / indeterminate classification.
 *
 * Implements §4.2 (G8), §4.3 (G10) and §4.4 strictly. ABSENT is returned only
 * when every completeness condition holds; every other case is INDETERMINATE
 * with an explicit reason code.
 */

function s36_page_fingerprint( $rec ) {
	return hash( 'sha256', json_encode( array( 'ids' => $rec['ordered_result_ids'], 'pag' => $rec['pagination'] ) ) );
}

function s36_collect( &$st, $pass ) {
	$set   = array();
	$order = array();
	$dupes = array();
	$pages = array();
	$page_number_mismatch = array();
	$partial = array();

	foreach ( $st['pages'] as $rec ) {
		if ( (int) $rec['pass'] !== (int) $pass ) {
			continue;
		}
		if ( ! $rec['parse_ok'] ) {
			continue;
		}
		if ( ! empty( $rec['recheck'] ) ) {
			continue; // page-1 stability recheck is not a listing page
		}
		$pages[] = (int) $rec['page'];
		if ( null !== $rec['page_number_reported'] && (int) $rec['page_number_reported'] !== (int) $rec['page'] ) {
			$page_number_mismatch[] = sprintf( 'requested %d reported %d', $rec['page'], $rec['page_number_reported'] );
		}
		if ( $rec['partial_page'] ) {
			$partial[] = (int) $rec['page'];
		}
		foreach ( $rec['ordered_result_ids'] as $id ) {
			$order[] = array( 'id' => $id, 'page' => (int) $rec['page'] );
			if ( isset( $set[ $id ] ) ) {
				$dupes[ $id ] = true;
			} else {
				$set[ $id ] = (int) $rec['page'];
			}
		}
	}

	return array(
		'set'                   => $set,
		'order'                 => $order,
		'duplicates'            => array_keys( $dupes ),
		'pages_fetched'         => $pages,
		'page_number_mismatch'  => $page_number_mismatch,
		'partial_pages'         => $partial,
		'count'                 => count( $set ),
	);
}

/**
 * Build the per-URL verdict context, then classify every target.
 *
 * @param array $st      State.
 * @param array $meta    URL-level metadata (mode, declared pagination, G8).
 * @return array
 */
function s36_classify( &$st, array $meta ) {
	$p1 = s36_collect( $st, 1 );
	$p2 = s36_collect( $st, 2 );

	$declared         = $meta['declared_pages'];
	$pass1_complete   = ( ! empty( $declared ) && count( $p1['pages_fetched'] ) === $declared && array() === $st['parse_failures'] && '' === $st['fatal_reason'] );
	$pass2_complete   = ( 'TWO_PASS' === $meta['mode'] && ! empty( $declared ) && count( $p2['pages_fetched'] ) === $declared );

	$p1_changed = ( null !== $meta['page1_fingerprint'] && $meta['page1_fingerprint_end'] !== $meta['page1_fingerprint'] );
	$sets_equal = ( $p1['count'] === $p2['count'] ) && ( array_keys( $p1['set'] ) == array_keys( $p2['set'] ) );

	$blockers = array();
	if ( ! empty( $st['parse_failures'] ) ) {
		$blockers[] = 'PARSE FAILURE: ' . implode( '; ', $st['parse_failures'] );
	}
	if ( '' !== $st['fatal_reason'] ) {
		$blockers[] = 'REQUEST FAILED: ' . $st['fatal_reason'];
	}
	if ( 'UNKNOWN' === $meta['g8'] ) {
		$blockers[] = 'PAGINATION CEILING UNKNOWN: page_size/page_count absent or invalid';
	}
	if ( 'TRUNCATED' === $meta['g8'] ) {
		$blockers[] = sprintf( 'UPSTREAM TRUNCATION: declared %s objects, reachable ceiling %s', $meta['object_count'], $meta['ceiling'] );
	}
	if ( ! $pass1_complete ) {
		$blockers[] = sprintf( 'INCOMPLETE PAGINATION: walked %d of %s declared page(s)', count( $p1['pages_fetched'] ), var_export( $declared, true ) );
	}
	if ( $p1_changed ) {
		$blockers[] = 'LISTING CHANGED DURING ENUMERATION: page-1 fingerprint differed between start and end of walk';
	}
	if ( 'TWO_PASS' !== $meta['mode'] ) {
		$blockers[] = 'SECOND-PASS NOT COMPLETED: declared depth cannot fit two passes inside the 30-request cap';
	} elseif ( ! $pass2_complete ) {
		$blockers[] = sprintf( 'SECOND-PASS NOT COMPLETED: walked %d of %s declared page(s)', count( $p2['pages_fetched'] ), var_export( $declared, true ) );
	} elseif ( ! $sets_equal ) {
		$blockers[] = 'PASS ID SETS DIFFER: pass 1 and pass 2 produced different unique provider-ID sets';
	}
	if ( ! empty( $p1['duplicates'] ) || ! empty( $p2['duplicates'] ) ) {
		$blockers[] = 'UNSTABLE PAGINATION: duplicate provider IDs across pages (' . implode( ',', array_slice( array_unique( array_merge( $p1['duplicates'], $p2['duplicates'] ) ), 0, 10 ) ) . ')';
	}
	if ( ! empty( $p1['page_number_mismatch'] ) || ! empty( $p2['page_number_mismatch'] ) ) {
		$blockers[] = 'PAGE IDENTITY INCONSISTENT: reported page_number did not match the requested page';
	}

	$absent_allowed = empty( $blockers );

	$rows = array();
	foreach ( $st['targets'] as $sid => $post_ids ) {
		$found_pass = array();
		$found_page = array();
		if ( isset( $p1['set'][ $sid ] ) ) { $found_pass[] = 1; $found_page[] = 'p1:pg' . $p1['set'][ $sid ]; }
		if ( isset( $p2['set'][ $sid ] ) ) { $found_pass[] = 2; $found_page[] = 'p2:pg' . $p2['set'][ $sid ]; }

		if ( $found_pass ) {
			$presence = 'PRESENT';
			$conf     = 'HIGH-PRESENT';
			$refs     = implode( ', ', $found_page );
		} elseif ( $absent_allowed ) {
			$presence = 'ABSENT';
			$conf     = 'HIGH-ABSENT';
			$refs     = 'absent from ' . $p1['count'] . ' unique IDs in pass 1 and ' . $p2['count'] . ' unique IDs in pass 2 (pages 1-' . $declared . ' twice)';
		} else {
			$presence = 'INDETERMINATE';
			$conf     = 'INDETERMINATE';
			$refs     = implode( '; ', $blockers );
		}

		foreach ( $post_ids as $pid ) {
			$rows[] = array(
				'post_id'      => $pid,
				'presence'     => $presence,
				'confidence'   => $conf,
				'evidence'     => $refs,
				'found_passes' => implode( ',', $found_pass ),
			);
		}
	}

	return array(
		'pass1'           => $p1,
		'pass2'           => $p2,
		'pass1_complete'  => $pass1_complete,
		'pass2_complete'  => $pass2_complete,
		'p1_changed'      => $p1_changed,
		'sets_equal'      => $sets_equal,
		'blockers'        => $blockers,
		'absent_allowed'  => $absent_allowed,
		'rows'            => $rows,
	);
}
