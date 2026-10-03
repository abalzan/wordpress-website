<?php
/**
 * Repair the missing `conexao_county` term on Events imported from a source
 * whose county hint is (or was) empty.
 *
 * Stage 7.x. See docs/reports/2026-09-26-stage7-laois-events-visibility-plan.md.
 *
 * ## Root cause this script corrects
 *
 * An Event source carries a per-source `county` taxonomy hint. The iCalendar
 * and Eventbrite handlers copy it onto every event they emit, and
 * Conexao_Event_Importer::save_event_taxonomies() turns it into a
 * `conexao_county` term. The /eventos archive filters on exactly that term
 * (?county=<slug>).
 *
 * The stored `conexao_event_sources` option had `laois_tourism.county = ''`,
 * so the hint was never forwarded and NO Laois Tourism event ever received a
 * county term. The events are valid, published and correctly dated — they are
 * simply unreachable through the county filter. The shipped default in
 * Conexao_Event_Sources::get_defaults() declares `'county' => 'Laois'`, and
 * get_all() now self-heals that drift, but events imported while the hint was
 * empty still need the term assigned once.
 *
 * ## What it does
 *
 * For every `event` post whose `_event_source` matches a source that declares
 * a non-empty county, and which has NO `conexao_county` term, it assigns the
 * EXISTING shared `conexao_county` term for that county.
 *
 * ## Safety
 *
 * - Dry run by DEFAULT. `--apply` is required to write.
 * - It NEVER creates a term. The county term must already exist; otherwise the
 *   event is reported as `county_term_absent` and skipped. This preserves the
 *   shared-county policy (AGENTS.md): one physical term per county, no
 *   per-language term, no second source of truth.
 * - It NEVER writes post content, post meta, post status, slugs, dates or any
 *   identity field (`_event_source`, `_event_source_id`, `_event_export_uuid`).
 *   The ONLY write is `wp_set_object_terms( ..., 'conexao_county' )`.
 * - Idempotent: an event that already has a county term is never touched.
 * - Local-only (`production_capable => false`).
 * - Emits a JSON plan sufficient to reverse the change.
 *
 * Usage:
 *   php scripts/repair-event-county-terms.php --dry-run
 *   php scripts/repair-event-county-terms.php --apply
 *   php scripts/repair-event-county-terms.php --apply --json
 *
 * @package Conexao_BR_Scripts
 */

require_once __DIR__ . '/lib/bootstrap.php';

$ctx = conexao_script_boot(
	array(
		'script'             => 'repair-event-county-terms.php',
		'purpose'            => 'Assign the existing shared conexao_county term to Events whose source declares a county but which never received one, so they are reachable through /eventos?county=<slug>.',
		'scope'              => 'event posts with no conexao_county term whose _event_source maps to a source declaring a non-empty county. The ONLY write is a conexao_county term assignment. No term is created; no post field, meta, status, slug, date or identity field is written.',
		'safety'             => 'local-only; dry-run by default; --apply required to write; creates no terms; idempotent; emits a reversible JSON plan',
		'target_description' => 'the WordPress install the script is connected to (site URL printed in the header)',
		'modes_description'  => '--dry-run (default) prints the plan and writes nothing. --apply assigns the missing county terms. --json prints the machine-readable result.',
		'arguments'          => "--dry-run   Plan only, zero writes (default).\n"
			. "            --apply     Assign the missing conexao_county terms.\n"
			. "            --json      Emit the machine-readable result and reversal plan.\n"
			. '            --help      This message.',
		'writes'             => true,
		'read_only'          => false,
		'production_capable' => false,
	)
);

$apply = ( 'apply' === $ctx['mode'] );

if ( ! taxonomy_exists( 'conexao_county' ) ) {
	conexao_script_fail( 'the conexao_county taxonomy is not registered on this install.' );
}


/*
 * Read the sources through Conexao_Event_Sources::get_all(), never the raw
 * option. get_all() is the layer that merges shipped defaults and self-heals a
 * drifted county hint, and it persists the repair — so this script repairs the
 * configuration AND the data in one pass, and it reads exactly the same
 * effective configuration the importer itself will use on its next run.
 */
global $wpdb;

$sources = array();
if ( class_exists( 'Conexao_Event_Sources' ) ) {
	$sources = ( new Conexao_Event_Sources() )->get_all();
} else {
	conexao_script_fail( 'Conexao_Event_Sources is not loaded; run this through the local stack with the event importer active.' );
}
if ( ! is_array( $sources ) ) {
	conexao_script_fail( 'the event sources registry is not an array; refusing to guess.' );
}

// Source id => county name, for sources that declare one. The declared county
// is the ONLY authority: nothing is inferred from a title or an address.
$source_county = array();
foreach ( $sources as $source_id => $source ) {
	$county = isset( $source['county'] ) ? trim( (string) $source['county'] ) : '';
	if ( '' !== $county ) {
		$source_county[ (string) $source_id ] = $county;
	}
}

if ( empty( $source_county ) ) {
	conexao_script_fail( 'no event source declares a county; there is nothing to repair.' );
}

/*
 * Candidate events.
 *
 * Read the IDs with direct SQL rather than get_posts(): the event runtime's
 * filter_public_event_queries() gate is a FRONTEND gate — it narrows every
 * non-admin, non-WP-CLI event query to `_event_status` published/legacy, and
 * this maintenance script is neither. Using WP_Query here would silently hide
 * the very records that need repair. Taxonomy relationships are read per post
 * through the normal API below, so nothing else bypasses WordPress.
 */
$event_ids = $wpdb->get_col(
	"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'event' ORDER BY ID ASC"
);

$plan             = array();
$considered       = 0;
$already_ok       = 0;
$missing_term     = array();
$no_source_county = 0;
$assigned         = 0;

foreach ( $event_ids as $event_id ) {
	$considered++;

	$existing = wp_get_object_terms( $event_id, 'conexao_county', array( 'fields' => 'ids' ) );
	if ( is_wp_error( $existing ) ) {
		continue;
	}
	if ( ! empty( $existing ) ) {
		++$already_ok;
		continue;
	}

	$source_id = (string) get_post_meta( $event_id, '_event_source', true );
	if ( '' === $source_id || ! isset( $source_county[ $source_id ] ) ) {
		++$no_source_county;
		continue;
	}

	$county_name = $source_county[ $source_id ];

	// Resolve the EXISTING shared term. Never insert one.
	$term = term_exists( $county_name, 'conexao_county' );
	if ( ! $term || is_wp_error( $term ) ) {
		$missing_term[] = array(
			'event_id' => (int) $event_id,
			'source'   => $source_id,
			'county'   => $county_name,
			'title'    => get_the_title( $event_id ),
		);
		continue;
	}

	$term_id = (int) ( is_array( $term ) ? $term['term_id'] : $term );

	$plan[] = array(
		'event_id'           => (int) $event_id,
		'title'              => get_the_title( $event_id ),
		'source'             => $source_id,
		'county'             => $county_name,
		'term_id'            => $term_id,
		// Identity fields, recorded so a reviewer can confirm they are NOT
		// written and so a reversal is unambiguous.
		'_event_source'      => get_post_meta( $event_id, '_event_source', true ),
		'_event_source_id'   => get_post_meta( $event_id, '_event_source_id', true ),
		'_event_export_uuid' => get_post_meta( $event_id, '_event_export_uuid', true ),
		'action'             => $apply ? 'assigned' : 'would_assign',
	);

	if ( $apply ) {
		wp_set_object_terms( $event_id, array( $term_id ), 'conexao_county' );
		++$assigned;
	}
}


// The archive's own ordered set is transient-cached per day+language; flush it
// so the freshly assigned terms are visible on the very next request.
if ( $apply && class_exists( 'Conexao_Event_Query' ) ) {
	Conexao_Event_Query::flush_cache();
}

$result = array(
	'target'                 => $ctx['target_url'],
	'target_class'           => $ctx['target_class'],
	'mode'                   => $ctx['mode'],
	'events_considered'      => $considered,
	'already_had_county'     => $already_ok,
	'no_county_declared'     => $no_source_county,
	'would_assign'           => $apply ? 0 : count( $plan ),
	'assigned'               => $assigned,
	'county_term_absent'     => count( $missing_term ),
	'county_term_absent_for' => $missing_term,
	'plan'                   => $plan,
);

if ( $ctx['json'] ) {
	echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
} else {
	printf(
		"events considered      : %d\nalready had a county   : %d\nno county declared     : %d\nwould assign           : %d\nassigned               : %d\ncounty term absent     : %d\n",
		$considered,
		$already_ok,
		$no_source_county,
		$apply ? 0 : count( $plan ),
		$assigned,
		count( $missing_term )
	);

	if ( ! empty( $plan ) ) {
		echo "\n" . ( $apply ? 'ASSIGNED:' : 'WOULD ASSIGN:' ) . "\n";
		foreach ( $plan as $row ) {
			printf(
				"  %-7d %-16s %-9s term=%-5d %s\n",
				$row['event_id'],
				$row['source'],
				$row['county'],
				$row['term_id'],
				mb_strimwidth( (string) $row['title'], 0, 52, '...' )
			);
		}
	}

	if ( ! empty( $missing_term ) ) {
		echo "\nSKIPPED (county term does not exist; nothing created):\n";
		foreach ( $missing_term as $row ) {
			printf( "  %-7d %-16s wants '%s'\n", $row['event_id'], $row['source'], $row['county'] );
		}
	}
}

printf(
	"summary: %d assigned / %d planned / %d skipped; %d events already correct; mode=%s\n",
	$assigned,
	$apply ? 0 : count( $plan ),
	count( $missing_term ),
	$already_ok,
	$ctx['mode']
);
