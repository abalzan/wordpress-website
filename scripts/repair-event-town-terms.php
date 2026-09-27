<?php
/**
 * Remove the erroneous `conexao_town` relationship on Events whose town term
 * is only a COUNTY name.
 *
 * Stage P. See docs/reports/2026-09-26-stage-p-county-not-town-report.md.
 *
 * ## Root cause this script corrects
 *
 * `Conexao_Event_Location::sanitize_town()` is the single choke point every
 * source's locality passes through before a `conexao_town` term is created by
 * `ensure_town()`. It used to accept ANY string as a town, so when a source's
 * locality field carried nothing but a county — Eventbrite's
 * `primary_venue.address.city` is literally "Laois" for a venue whose address
 * resolves no further (`_event_address` = "Gorteenameale Eco Trail, Laois,
 * Laois") — the county was copied straight into the town taxonomy.
 *
 * `sanitize_town()` now rejects a value that is only a county name (see
 * `Conexao_Event_Location::is_county_only_value()`), so future imports cannot
 * recreate the term. Events imported before that fix still carry it.
 *
 * ## What it does
 *
 * For every `event` post holding a `conexao_town` term whose name is ONLY a
 * county name, it removes that one relationship and then deletes the town
 * term if it is left empty.
 *
 * ## Why the list is narrow
 *
 * This is NOT "a county name is never a town". In Ireland Cavan, Wicklow,
 * Kildare, Carlow, Longford, Monaghan and Donegal are real towns sharing
 * their county's name, as are Cork, Dublin, Galway, Kilkenny, Sligo,
 * Waterford and Wexford. Only counties with NO same-named town (Laois, Clare,
 * Kerry, Meath, Offaly) are affected. The decision is delegated to
 * `Conexao_Event_Location::is_county_only_value()` so this script and the
 * importer can never disagree — there is no second list here.
 *
 * ## Safety
 *
 * - Dry run by DEFAULT. `--apply` is required to write.
 * - It NEVER creates a term and NEVER invents a replacement town: an event
 *   whose source gave no real locality is simply left with no `conexao_town`
 *   term, exactly as the importer would now import it.
 * - It NEVER writes post content, post meta, post status, slugs, dates,
 *   attachments or any identity field (`_event_source`, `_event_source_id`,
 *   `_event_export_uuid`). The ONLY writes are
 *   `wp_remove_object_terms( ..., 'conexao_town' )` and — when the emptied
 *   term has no remaining relationships — `wp_delete_term()`.
 *   `conexao_county`, `conexao_category` and `conexao_tag` are never touched.
 * - The event post itself is NEVER deleted.
 * - Idempotent: a second run plans 0 further removals.
 * - Local-only (`production_capable => false`).
 * - Emits a JSON plan sufficient to reverse the change.
 *
 * Usage:
 *   php scripts/repair-event-town-terms.php --dry-run
 *   php scripts/repair-event-town-terms.php --apply
 *   php scripts/repair-event-town-terms.php --apply --json
 *
 * @package Conexao_BR_Scripts
 */

require_once __DIR__ . '/lib/bootstrap.php';

$ctx = conexao_script_boot(
	array(
		'script'             => 'repair-event-town-terms.php',
		'purpose'            => 'Remove the erroneous conexao_town relationship from Events whose town term is only a county name (e.g. conexao_town-laois), preserving the event, its county, category, tags, dates, location metadata, images, URLs and import metadata.',
		'scope'              => 'conexao_town relationships whose term name is only a county name with no same-named town (Laois, Clare, Kerry, Meath, Offaly). The ONLY writes are the removal of that one town relationship and, when it is left empty, the deletion of the empty town term. No post field, meta, status, slug, date or identity field is written; no event is deleted; conexao_county/conexao_category/conexao_tag are never touched.',
		'safety'             => 'local-only; dry-run by default; --apply required to write; creates no terms and invents no town; idempotent; deletes no event; emits a reversible JSON plan',
		'target_description' => 'the WordPress install the script is connected to (site URL printed in the header)',
		'modes_description'  => '--dry-run (default) prints the plan and writes nothing. --apply removes the erroneous town relationships and the emptied term. --json prints the machine-readable result.',
		'arguments'          => "--dry-run   Plan only, zero writes (default).\n"
			. "            --apply     Remove the erroneous town relationships.\n"
			. "            --json      Emit the machine-readable result and reversal plan.\n"
			. "            --county=X  Restrict to one county (default: every county with no same-named town).\n"
			. '            --help      This message.',
		'writes'             => true,
		'read_only'          => false,
		'production_capable' => false,
		'extra_flags'        => array( '--county' => 'county' ),
	)
);

// Optional --county=<slug> scope, so an operator can repair one county without
// touching any other county's data.
$county_filter = isset( $ctx['extra']['county'] ) ? strtolower( trim( (string) $ctx['extra']['county'] ) ) : '';

$apply = ( 'apply' === $ctx['mode'] );

if ( ! taxonomy_exists( 'conexao_town' ) ) {
	conexao_script_fail( 'the conexao_town taxonomy is not registered on this install.' );
}

if ( ! class_exists( 'Conexao_Event_Location' ) ) {
	conexao_script_fail( 'Conexao_Event_Location is not loaded; run this through the local stack with the event importer active.' );
}

/*
 * The one authority for "this value is only a county name" is the importer
 * itself. Re-implementing the list here would create a second source of truth
 * and let the script and the importer drift apart.
 */
$location = new Conexao_Event_Location();

/*
 * Candidate events.
 *
 * Read the IDs with direct SQL rather than get_posts(): the event runtime's
 * filter_public_event_queries() gate is a FRONTEND gate — it narrows every
 * non-admin, non-WP-CLI event query to `_event_status` published/legacy, and
 * this maintenance script is neither. Using WP_Query here would silently hide
 * the very records that need repair. Taxonomy relationships are read and
 * written per post through the normal API below.
 */
global $wpdb;

$event_ids = $wpdb->get_col(
	"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'event' ORDER BY ID ASC"
);

$plan          = array();
$considered    = 0;
$no_bad_town   = 0;
$removed       = 0;
$terms_touched = array();

foreach ( $event_ids as $event_id ) {
	$considered++;

	$town_ids = wp_get_object_terms( $event_id, 'conexao_town', array( 'fields' => 'ids' ) );
	if ( is_wp_error( $town_ids ) || empty( $town_ids ) ) {
		++$no_bad_town;
		continue;
	}

	foreach ( $town_ids as $town_id ) {
		$term = get_term( (int) $town_id, 'conexao_town' );
		if ( ! $term || is_wp_error( $term ) ) {
			continue;
		}

		// THE RULE: only act on a town term that is really a county.
		if ( ! $location->is_county_only_value( $term->name ) ) {
			continue;
		}

		// Optional --county=<slug> scope.
		if ( '' !== $county_filter && $term->slug !== $county_filter ) {
			continue;
		}

		$kept_county = wp_get_object_terms( $event_id, 'conexao_county', array( 'fields' => 'names' ) );
		if ( is_wp_error( $kept_county ) ) {
			$kept_county = array();
		}

		$plan[] = array(
			'event_id'           => (int) $event_id,
			'title'              => get_the_title( $event_id ),
			'source'             => (string) get_post_meta( $event_id, '_event_source', true ),
			'erroneous_town'     => $term->name,
			'town_slug'          => $term->slug,
			'town_term_id'       => (int) $term->term_id,
			'post_status'        => get_post_status( $event_id ),
			// Recorded so a reviewer can confirm the county is NOT touched.
			'kept_county'        => implode( ',', $kept_county ),
			// Identity fields, recorded so a reviewer can confirm they are NOT
			// written and so a reversal is unambiguous.
			'_event_source'      => get_post_meta( $event_id, '_event_source', true ),
			'_event_source_id'   => get_post_meta( $event_id, '_event_source_id', true ),
			'_event_export_uuid' => get_post_meta( $event_id, '_event_export_uuid', true ),
			'action'             => $apply ? 'removed' : 'would_remove',
		);

		if ( $apply ) {
			/*
			 * Remove ONLY this one town relationship. Any other legitimate
			 * town the event may also carry is re-set rather than cleared,
			 * and no other taxonomy is touched.
			 */
			$survivors = array_values( array_diff( array_map( 'intval', $town_ids ), array( (int) $term->term_id ) ) );

			if ( empty( $survivors ) ) {
				wp_remove_object_terms( (int) $event_id, array( (int) $term->term_id ), 'conexao_town' );
			} else {
				wp_set_object_terms( (int) $event_id, $survivors, 'conexao_town', false );
			}

			++$removed;
			$terms_touched[ (int) $term->term_id ] = $term->name;
		}
	}
}

/*
 * Delete a town term ONLY once it has no remaining relationships, so the
 * "Laois" option disappears from the /eventos cidade filter instead of
 * lingering as an empty, permanently-false filter choice. A term that still
 * holds relationships is left alone and reported.
 */
$terms_deleted = array();
$terms_kept    = array();

if ( $apply ) {
	foreach ( $terms_touched as $term_id => $term_name ) {
		$remaining = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				WHERE tt.taxonomy = 'conexao_town' AND tt.term_id = %d",
				$term_id
			)
		);

		if ( 0 === $remaining ) {
			$deleted = wp_delete_term( $term_id, 'conexao_town' );
			if ( ! is_wp_error( $deleted ) ) {
				$terms_deleted[] = $term_name;
			}
		} else {
			$terms_kept[] = $term_name . ' (' . $remaining . ' relationship(s) left)';
		}
	}
}

// The archive's own ordered set is transient-cached per day+language; flush it
// so the removed terms are visible on the very next request.
if ( $apply && class_exists( 'Conexao_Event_Query' ) ) {
	Conexao_Event_Query::flush_cache();
}

$result = array(
	'target'                        => $ctx['target_url'],
	'target_class'                  => $ctx['target_class'],
	'mode'                          => $ctx['mode'],
	'county_filter'                 => $county_filter,
	'events_considered'             => $considered,
	'events_without_erroneous_town' => $no_bad_town,
	'would_remove'                  => $apply ? 0 : count( $plan ),
	'removed'                       => $removed,
	'terms_deleted'                 => $terms_deleted,
	'terms_kept'                    => $terms_kept,
	'plan'                          => $plan,
);

if ( $ctx['json'] ) {
	echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
} else {
	printf(
		"events considered       : %d\nno erroneous town       : %d\nwould remove           : %d\nremoved                : %d\n",
		$considered,
		$no_bad_town,
		$apply ? 0 : count( $plan ),
		$removed
	);

	if ( ! empty( $plan ) ) {
		echo "\n" . ( $apply ? 'REMOVED:' : 'WOULD REMOVE:' ) . "\n";
		foreach ( $plan as $row ) {
			printf(
				"  %-7d %-22s town='%s' county='%s' %s\n",
				$row['event_id'],
				$row['source'],
				$row['erroneous_town'],
				'' === $row['kept_county'] ? '(none)' : $row['kept_county'],
				mb_strimwidth( (string) $row['title'], 0, 46, '...' )
			);
		}
	}

	if ( $apply ) {
		printf(
			"town terms deleted     : %s\ntown terms kept (in use): %s\n",
			empty( $terms_deleted ) ? '(none)' : implode( ', ', $terms_deleted ),
			empty( $terms_kept ) ? '(none)' : implode( ', ', $terms_kept )
		);
	}
}

printf(
	"summary: %d removed / %d planned; %d events already correct; mode=%s\n",
	$removed,
	$apply ? 0 : count( $plan ),
	$no_bad_town,
	$ctx['mode']
);
