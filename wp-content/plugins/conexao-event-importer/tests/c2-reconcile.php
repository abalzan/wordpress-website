<?php
/**
 * Stage C2 remediation - duplicate reconciliation (117 Cork pairs).
 *
 * Modes:
 *   php c2-reconcile.php manifest   -> build + verify manifest only (default)
 *   php c2-reconcile.php apply      -> reconcile using a verified manifest
 *   php c2-reconcile.php verify     -> re-verify an existing manifest against the DB
 *
 * Safety contract (see docs/importers/events-expansion-stage-c2-report.md 6-9):
 *  - Requires EXACTLY 117 duplicate pairs, each made of one source_not_found
 *    original (kept, restored to published) + one C2-created published twin
 *    (removed). Any mismatch -> STOP, nothing is written.
 *  - No attachment is ever deleted. Shared media stays in the library.
 */
set_time_limit( 0 );
require '/var/www/html/wp-load.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$pilot = array( 'eventbrite_laois', 'eventbrite_cork', 'eventbrite_dublin', 'heritage_week_laois', 'heritage_week_cork', 'heritage_week_dublin' );
$manifest_file = __DIR__ . '/c2-reconciliation-manifest.json';
$after_file    = __DIR__ . '/c2-reconciliation-after.json';
$required_pairs = 117;

/* ---------------------------------------------------------------- helpers */

function rc_terms( $post_id, $tax ) {
	$t = wp_get_object_terms( $post_id, $tax, array( 'fields' => 'slugs' ) );
	return ( ! is_wp_error( $t ) ) ? array_values( array_filter( array_map( 'strval', $t ) ) ) : array();
}

function rc_attachment_meta( $post_id ) {
	return (int) get_post_meta( $post_id, '_event_banner_attachment_id', true );
}

function rc_attachment_usage( $att_id ) {
	global $wpdb;
	if ( ! $att_id ) { return array(); }
	// Every post pointing at this attachment via the two link metas.
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT post_id, meta_key FROM {$wpdb->postmeta}
		 WHERE meta_value = %d AND meta_key IN ('_thumbnail_id','_event_banner_attachment_id')",
		$att_id
	) );
	$out = array();
	foreach ( $rows as $r ) { $out[ (int) $r->post_id ][] = $r->meta_key; }
	return $out;
}

function rc_census() {
	global $wpdb, $pilot;
	$pilot_placeholder = implode( ',', array_fill( 0, 6, '%s' ) );
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT p.ID, pm_src.meta_value AS source, pm_sid.meta_value AS source_id, pm_st.meta_value AS status, p.post_date
		 FROM {$wpdb->posts} p
		 JOIN {$wpdb->postmeta} pm_src ON pm_src.post_id = p.ID AND pm_src.meta_key = '_event_source'
		 LEFT JOIN {$wpdb->postmeta} pm_sid ON pm_sid.post_id = p.ID AND pm_sid.meta_key = '_event_source_id'
		 LEFT JOIN {$wpdb->postmeta} pm_st  ON pm_st.post_id  = p.ID AND pm_st.meta_key  = '_event_status'
		 WHERE p.post_type = 'event' AND p.post_status = 'publish'
		   AND pm_src.meta_value IN ({$pilot_placeholder})",
		$pilot
	) );
	$events = array();
	foreach ( $rows as $r ) {
		$pid = (int) $r->ID;
		$events[ $pid ] = array(
			'id'         => $pid,
			'source'     => (string) $r->source,
			'source_id'  => (string) $r->source_id,
			'status'     => (string) ( '' !== (string) $r->status ? $r->status : 'published' ),
			'post_date'  => $r->post_date,
			'title'      => (string) get_post_field( 'post_title', $pid ),
			'url'        => (string) get_post_meta( $pid, '_event_url', true ),
			'date'       => (string) get_post_meta( $pid, '_event_date', true ),
			'start_time' => (string) get_post_meta( $pid, '_event_start_time', true ),
			'venue'      => (string) get_post_meta( $pid, '_event_venue', true ),
			'organizer'  => (string) get_post_meta( $pid, '_event_organizer', true ),
			'county'     => rc_terms( $pid, 'conexao_county' ),
			'town'       => rc_terms( $pid, 'conexao_town' ),
			'category'   => rc_terms( $pid, 'conexao_category' ),
			'url_source' => (string) get_post_meta( $pid, '_event_source_url', true ),
			'attach'     => rc_attachment_meta( $pid ),
			'uuid'       => (string) get_post_meta( $pid, '_event_export_uuid', true ),
		);
	}
	return $events;
}

function rc_global_counts() {
	global $wpdb;
	$total_all = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='event' AND post_status='publish'" );
	$eb_cork   = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_event_source' WHERE p.post_type='event' AND p.post_status='publish' AND pm.meta_value=%s",
		'eventbrite_cork'
	) );
	$cork_county = (int) $wpdb->get_var(
		"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
		 JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
		 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy='conexao_county'
		 JOIN {$wpdb->terms} t ON t.term_id = tt.term_id AND t.slug = 'cork'
		 WHERE p.post_type='event' AND p.post_status='publish'"
	);
	return array(
		'total_events_all_sources'   => $total_all,
		'cork_county_events'         => $cork_county,
		'eventbrite_cork_events'     => $eb_cork,
	);
}

function rc_totals( $events ) {
	$published = $snf = $expired = $dupe = 0;
	$by_source = array();
	$identities = array();
	foreach ( $events as $e ) {
		$by_source[ $e['source'] ] = ( $by_source[ $e['source'] ] ?? 0 ) + 1;
		if ( 'published' === $e['status'] ) { $published++; }
		elseif ( 'source_not_found' === $e['status'] ) { $snf++; }
		elseif ( 'expired' === $e['status'] ) { $expired++; }
		$identities[ $e['source'] . '|' . $e['source_id'] ][] = $e['id'];
	}
	foreach ( $identities as $k => $ids ) { if ( count( $ids ) > 1 ) { $dupe++; } }
	return array(
		'total'                    => count( $events ),
		'published'                => $published,
		'source_not_found'         => $snf,
		'expired'                  => $expired,
		'duplicate_identities'     => $dupe,
		'distinct_source_identity' => count( $identities ),
		'by_source'                => $by_source,
	);
}

function rc_build_pairs( $events ) {
	$by_ident = array();
	foreach ( $events as $e ) { $by_ident[ $e['source'] . '|' . $e['source_id'] ][] = $e; }
	$pairs = array();
	foreach ( $by_ident as $key => $members ) {
		if ( count( $members ) < 2 ) { continue; }
		if ( 2 !== count( $members ) ) {
			return new WP_Error( 'multi_member_group', 'group has ' . count( $members ) . ' members: ' . $key . ' ids=' . implode( ',', wp_list_pluck( $members, 'id' ) ) );
		}
		usort( $members, fn( $a, $b ) => $a['post_date'] <=> $b['post_date'] );
		$pairs[] = array( 'original' => $members[0], 'duplicate' => $members[1] );
	}
	return $pairs;
}

function rc_verify_pair( $orig, $dup, $idx ) {
	$errors = array();
	if ( 'source_not_found' !== $orig['status'] ) { $errors[] = 'original status is ' . $orig['status']; }
	if ( 'published' !== $dup['status'] ) { $errors[] = 'duplicate status is ' . $dup['status']; }
	if ( $orig['source'] !== $dup['source'] ) { $errors[] = 'source mismatch'; }
	if ( $orig['source_id'] !== $dup['source_id'] ) { $errors[] = 'source_id mismatch'; }
	if ( '' !== $orig['url'] && $orig['url'] !== $dup['url'] ) { $errors[] = 'canonical URL mismatch'; }
	if ( '' !== $orig['url_source'] && $orig['url_source'] !== $dup['url_source'] ) { $errors[] = 'source URL mismatch'; }
	if ( sanitize_title( $orig['title'] ) !== sanitize_title( $dup['title'] ) ) { $errors[] = 'title mismatch'; }
	if ( '' !== $orig['date'] && $orig['date'] !== $dup['date'] ) { $errors[] = 'event date mismatch'; }
	// NOTE: attachment differences are NOT a duplicate-classification error.
	// The C2 report states 116/117 pairs share the same attachment and 1 pair
	// does not; media handling (transfer/reparent, never delete) resolves it
	// during apply. See section 8 (Media Safety).
	return $errors;
}

function rc_build_manifest( $events ) {
	global $required_pairs;
	$pairs = rc_build_pairs( $events );
	if ( is_wp_error( $pairs ) ) {
		echo 'ABORT: ' . $pairs->get_error_message() . "\n";
		return false;
	}
	if ( $required_pairs !== count( $pairs ) ) {
		echo 'ABORT-STOP: expected ' . $required_pairs . ' duplicate pairs, found ' . count( $pairs ) . ".\n";
		echo 'No manifest written; no destructive action taken.\n';
		return false;
	}

	$manifest = array(
		'captured_at' => current_time( 'c' ),
		'required_pairs' => $required_pairs,
		'found_pairs'    => count( $pairs ),
		'before'         => array_merge( rc_global_counts(), rc_totals( $events ) ),
		'pairs'          => array(),
		'media'          => array( 'pairs_share_attachment' => 0, 'pairs_distinct_attachment' => 0, 'attachments_deleted' => 0 ),
	);

	foreach ( $pairs as $i => $pair ) {
		$orig = $pair['original'];
		$dup  = $pair['duplicate'];
		$errors = rc_verify_pair( $orig, $dup, $i );

		// Attachment usage (read-only here).
		$orig_use = rc_attachment_usage( $orig['attach'] );
		$dup_use  = rc_attachment_usage( $dup['attach'] );
		$shared   = ( $orig['attach'] === $dup['attach'] && $orig['attach'] > 0 );
		if ( $shared ) { $manifest['media']['pairs_share_attachment']++; } else { $manifest['media']['pairs_distinct_attachment']++; }

		$reason = array(
			'identity'   => $orig['source'] . '|' . $orig['source_id'] . ' appears twice',
			'url'        => '' !== $orig['url'] && $orig['url'] === $dup['url'] ? 'identical canonical URL' : '',
			'content'    => sanitize_title( $orig['title'] ) === sanitize_title( $dup['title'] ) && ( '' === $orig['date'] || $orig['date'] === $dup['date'] ) ? 'identical sanitized title + event date' : '',
			'media'      => $shared ? 'shared attachment ' . $orig['attach'] : ( $orig['attach'] && $dup['attach'] ? 'distinct attachments ' . $orig['attach'] . ' vs ' . $dup['attach'] : 'attachment only on one side' ),
		);

		$manifest['pairs'][] = array(
			'original_post_id'   => $orig['id'],
			'duplicate_post_id'  => $dup['id'],
			'source'             => $orig['source'],
			'source_id'          => $orig['source_id'],
			'canonical_url'      => $orig['url'],
			'original_post_date' => $orig['post_date'],
			'duplicate_post_date'=> $dup['post_date'],
			'original_status'    => $orig['status'],
			'duplicate_status'   => $dup['status'],
			'compare'            => array(
				'title'      => array( 'original' => $orig['title'], 'duplicate' => $dup['title'], 'match' => sanitize_title( $orig['title'] ) === sanitize_title( $dup['title'] ) ),
				'date'       => array( 'original' => $orig['date'], 'duplicate' => $dup['date'], 'match' => $orig['date'] === $dup['date'] ),
				'time'       => array( 'original' => $orig['start_time'], 'duplicate' => $dup['start_time'], 'match' => $orig['start_time'] === $dup['start_time'] ),
				'venue'      => array( 'original' => $orig['venue'], 'duplicate' => $dup['venue'], 'match' => strtolower( $orig['venue'] ) === strtolower( $dup['venue'] ) ),
				'organizer'  => array( 'original' => $orig['organizer'], 'duplicate' => $dup['organizer'], 'match' => strtolower( $orig['organizer'] ) === strtolower( $dup['organizer'] ) ),
				'county'     => array( 'original' => $orig['county'], 'duplicate' => $dup['county'], 'match' => $orig['county'] === $dup['county'] ),
				'town'       => array( 'original' => $orig['town'], 'duplicate' => $dup['town'], 'match' => $orig['town'] === $dup['town'] ),
				'category'   => array( 'original' => $orig['category'], 'duplicate' => $dup['category'], 'match' => $orig['category'] === $dup['category'] ),
				'attachment' => array( 'original' => $orig['attach'], 'duplicate' => $dup['attach'], 'match' => $orig['attach'] === $dup['attach'] ),
				'export_uuid'=> array( 'original' => $orig['uuid'], 'duplicate' => $dup['uuid'], 'match' => $orig['uuid'] === $dup['uuid'] ),
			),
			'reason'             => array_values( array_filter( $reason ) ),
			'media'              => array(
				'original_attachment'  => $orig['attach'],
				'duplicate_attachment' => $dup['attach'],
				'shared'               => $shared,
				'original_attachment_used_by' => array_keys( $orig_use ),
				'duplicate_attachment_used_by' => array_keys( $dup_use ),
			),
			'verification_errors' => $errors,
			'selected_keep'    => $orig['id'],
			'selected_remove'  => $dup['id'],
		);
	}

	$with_errors = array_filter( $manifest['pairs'], fn( $p ) => ! empty( $p['verification_errors'] ) );
	if ( count( $with_errors ) > 0 ) {
		echo 'ABORT-STOP: ' . count( $with_errors ) . " pair(s) failed verification:\n";
		foreach ( $with_errors as $p ) { echo '  keep=' . $p['selected_keep'] . ' remove=' . $p['selected_remove'] . ' -> ' . implode( '; ', $p['verification_errors'] ) . "\n"; }
		return false;
	}

	file_put_contents( __DIR__ . '/c2-reconciliation-manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	return $manifest;
}

function rc_apply( $manifest ) {
	global $wpdb;
	if ( ! $manifest ) { echo "apply aborted: no valid manifest\n"; return false; }
	if ( count( $manifest['pairs'] ) !== (int) $manifest['required_pairs'] ) { echo "apply aborted: pair count mismatch\n"; return false; }

	$audit = array();
	foreach ( $manifest['pairs'] as $i => $p ) {
		$keep  = (int) $p['selected_keep'];
		$rm    = (int) $p['selected_remove'];
		$entry = array(
			'keep'   => $keep,
			'remove' => $rm,
			'source' => $p['source'],
			'source_id' => $p['source_id'],
			'keep_status' => get_post( $keep ) ? Conexao_Event_Status::get_status( $keep ) : 'missing',
		);

		if ( null === get_post( $keep ) ) {
			echo 'apply aborted: kept post ' . $keep . ' no longer exists — refusing to continue.' . "\n";
			return false;
		}

		if ( null === get_post( $rm ) ) {
			// Already reconciled in a previous (possibly interrupted) apply run.
			// Just make sure the kept record is published.
			if ( 'published' !== Conexao_Event_Status::get_status( $keep ) ) {
				Conexao_Event_Status::set_status( $keep, Conexao_Event_Status::PUBLISHED );
				$entry['keep_status_after'] = Conexao_Event_Status::get_status( $keep );
				$entry['restored_on_resume'] = true;
			} else {
				$entry['keep_status_after'] = 'published';
			}
			$entry['already_applied'] = true;
			$entry['delete_succeeded'] = true;
			$audit[] = $entry;
			continue;
		}

		// Re-verify identity before touching anything (never delete a
		// mismatched record discovered after the manifest was captured).
		$live_src = (string) get_post_meta( $rm, '_event_source', true );
		$live_sid = (string) get_post_meta( $rm, '_event_source_id', true );
		if ( $live_src !== $p['source'] || $live_sid !== $p['source_id'] ) {
			echo 'apply aborted: duplicate ' . $rm . ' identity drifted (expected ' . $p['source'] . '|' . $p['source_id'] . ', found ' . $live_src . '|' . $live_sid . ').' . "\n";
			return false;
		}
		$entry['remove_was'] = (string) get_post_meta( $rm, '_event_status', true );

		// 1. Restore the kept record to published (reappearing lifecycle).
		Conexao_Event_Status::set_status( $keep, Conexao_Event_Status::PUBLISHED );
		$entry['keep_status_after'] = Conexao_Event_Status::get_status( $keep );

		// 2. Media safety: never delete attachments. Transfer only when the
		// duplicate carries the sole attachment; otherwise leave the media
		// library untouched (an attachment referenced only by the duplicate
		// stays in the library — it is NOT deleted).
		$keep_att = (int) get_post_meta( $keep, '_event_banner_attachment_id', true );
		$rm_att   = (int) get_post_meta( $rm, '_event_banner_attachment_id', true );
		if ( $rm_att && ! $keep_att ) {
			( new Conexao_Event_Image_Handler() )->set_banner_attachment( $keep, $rm_att );
			$entry['attachment_transferred'] = $rm_att;
		} elseif ( $rm_att && $rm_att !== $keep_att ) {
			$entry['attachment_kept_on_both'] = array( $keep_att, $rm_att );
		}
		if ( $rm_att ) {
			$parent = (int) get_post_field( 'post_parent', $rm_att );
			if ( $parent === $rm ) {
				wp_update_post( array( 'ID' => $rm_att, 'post_parent' => $keep ) );
				$entry['attachment_reparented'] = $rm_att;
			}
		}

		// 3. Remove the C2-created duplicate record (permanent; no trash).
		wp_delete_post( $rm, true );
		$entry['delete_succeeded'] = ( null === get_post( $rm ) );
		$audit[] = $entry;
	}

	// Post-reconciliation census.
	$after_events = rc_census();
	$after_totals = rc_totals( $after_events );
	$result = array(
		'captured_at' => current_time( 'c' ),
		'before'      => $manifest['before'],
		'after'       => array_merge( rc_global_counts(), $after_totals ),
		'removed'     => count( $audit ),
		'audit'       => $audit,
	);
	file_put_contents( __DIR__ . '/c2-reconciliation-after.json', wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	return $result;
}

/* ---------------------------------------------------------------- main */

$mode = isset( $argv[1] ) ? $argv[1] : 'manifest';
$events = rc_census();

if ( 'apply' === $mode ) {
	$manifest = json_decode( (string) file_get_contents( $manifest_file ), true );
	$result = rc_apply( $manifest );
	if ( ! $result ) { echo "APPLY: ABORTED\n"; exit( 2 ); }
	echo 'APPLY: removed=' . $result['removed'] . "\n";
	echo 'after: ' . wp_json_encode( $result['after'] ) . "\n";
	exit( 0 );
}

if ( 'verify' === $mode ) {
	$manifest = json_decode( (string) file_get_contents( $manifest_file ), true );
	if ( ! is_array( $manifest ) ) { echo "verify: no manifest\n"; exit( 1 ); }
	$fresh = rc_build_manifest( $events );
	if ( ! $fresh ) { echo "VERIFY: FAILED\n"; exit( 1 ); }
	$ok = true;
	foreach ( $fresh['pairs'] as $i => $p ) {
		if ( $p['selected_keep'] !== $manifest['pairs'][ $i ]['selected_keep'] || $p['selected_remove'] !== $manifest['pairs'][ $i ]['selected_remove'] ) { $ok = false; }
	}
	echo ( $ok ? 'VERIFY: OK (manifest still matches database)' : 'VERIFY: MISMATCH' ) . "\n";
	exit( $ok ? 0 : 1 );
}

// Default: manifest (read-only).
$manifest = rc_build_manifest( $events );
if ( ! $manifest ) { echo "MANIFEST: STOPPED (see reason above)\n"; exit( 2 ); }

echo 'MANIFEST: ' . count( $manifest['pairs'] ) . " pairs written to " . $manifest_file . "\n";
echo 'media share: ' . $manifest['media']['pairs_share_attachment'] . ' distinct: ' . $manifest['media']['pairs_distinct_attachment'] . "\n";
echo 'before: ' . wp_json_encode( $manifest['before'] ) . "\n";
exit( 0 );
