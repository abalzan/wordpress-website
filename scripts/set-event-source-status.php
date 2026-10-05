<?php
/**
 * Set the `status` (the operator's Enable/Disable switch) of registered event
 * sources.
 *
 * ## What it does
 *
 * The event sources registry (`conexao_event_sources`) holds one record per
 * source, and each record carries a `status` of `active` or `inactive`. The
 * import engine refuses to fetch any source that is not `active`, so this field
 * IS the switch an operator flips on the Event Sources screen — one source at a
 * time, through a form.
 *
 * This script performs the same change in bulk, so an operator can enable or
 * disable a whole class of sources (for example every registered county source)
 * without clicking through 130 forms.
 *
 * It changes the `status` field and NOTHING else. Every other field of every
 * record — name, url, type, county hint, region labels, last_import,
 * events_imported, last_error — is preserved exactly.
 *
 * ## What it deliberately does NOT do
 *
 * - It does not run an import. Enabling a source does not fetch anything; a run
 *   is still an explicit operator action (or, for a resumable enumeration, the
 *   continuation the importer itself schedules).
 * - It does not create, delete or rename a source. It only flips a switch that
 *   already exists.
 * - It does not touch content: no post, no post meta, no taxonomy term, no
 *   attachment, no Polylang relationship.
 * - It does not change the cadence or request-budget policy, which are separate
 *   concerns.
 *
 * ## Safety
 *
 * - Dry run by DEFAULT. `--apply` is required to write.
 * - Local-only (`production_capable => false`): the importer is `production:
 *   false` in `plugins.json` and runs in the local stack.
 * - Idempotent: a source already in the requested state is reported as
 *   `unchanged` and is not written.
 * - The plan is a reversal recipe: every changed source carries its previous
 *   status, so the `--json` output can be replayed to restore the old state.
 * - One option write per changed source, through the SAME
 *   `Conexao_Event_Sources::set_status()` the admin screen uses, so the script
 *   and the UI can never disagree about what "enabled" means.
 *
 * Usage:
 *   php scripts/set-event-source-status.php --status=active
 *   php scripts/set-event-source-status.php --status=active --apply
 *   php scripts/set-event-source-status.php --status=inactive --type=eventbrite --apply
 *   php scripts/set-event-source-status.php --status=active --ids=a,b --json --apply
 *
 * @package Conexao_BR_Scripts
 */

require_once __DIR__ . '/lib/bootstrap.php';

$ctx = conexao_script_boot(
	array(
		'script'             => 'set-event-source-status.php',
		'purpose'            => 'Set the status field (the Enable/Disable switch the import engine reads) of registered event sources, in bulk. Changes only that field: every other field of every source record is preserved.',
		'scope'              => 'the conexao_event_sources registry option only, through the same Conexao_Event_Sources::set_status() the admin screen uses. No content write of any kind: no post, meta, taxonomy term, attachment or language relationship. Sources are never created, deleted or renamed.',
		'safety'             => 'local-only; dry-run by default; --apply required to write; idempotent; never runs an import; emits a reversible plan (previous status per changed source)',
		'target_description' => 'the WordPress install the script is connected to (site URL printed in the header)',
		'modes_description'  => '--dry-run (default) prints the plan and writes nothing. --apply writes the new status for every selected source. --json prints the machine-readable plan, which doubles as the reversal recipe.',
		'arguments'          => "--status=active|inactive  Required. The status to set. There is no default: an unstated\n"
			. "                         intent is exactly how a switch gets flipped by accident.\n"
			. "--type=<csv>            Only sources of these types (eventbrite, heritage_week,\n"
			. "                         website, icalendar).\n"
			. "--ids=<csv>             Only these source ids (an explicit allowlist).\n"
			. "--exclude-ids=<csv>     Never these source ids.\n"
			. "--exclude-prefix=<csv>  Never ids starting with any of these prefixes.\n"
			. "--dry-run               Plan only, zero writes (default).\n"
			. "--apply                 Perform the writes.\n"
			. "--json                  Emit the machine-readable plan.\n"
			. '--help                  This message.',
		'writes'             => true,
		'read_only'          => false,
		'production_capable' => false,
		'extra_flags'        => array(
			'--status'         => 'status',
			'--type'           => 'type',
			'--ids'            => 'ids',
			'--exclude-ids'    => 'exclude_ids',
			'--exclude-prefix' => 'exclude_prefix',
		),
	)
);


/**
 * Split a comma-separated argument into a clean, lowercased list.
 *
 * @param string $raw Raw argument value.
 * @return string[]
 */
function conexao_sss_csv( $raw ) {
	$parts = array_map( 'trim', explode( ',', (string) $raw ) );
	$parts = array_map( 'strtolower', $parts );

	return array_values( array_filter( $parts, 'strlen' ) );
}

$status = isset( $ctx['extra']['status'] ) ? strtolower( trim( (string) $ctx['extra']['status'] ) ) : '';

if ( ! in_array( $status, array( 'active', 'inactive' ), true ) ) {
	conexao_script_fail(
		'--status=active or --status=inactive is required. Run with --help to see the supported arguments.',
		2
	);
}

if ( ! class_exists( 'Conexao_Event_Sources' ) ) {
	conexao_script_fail(
		'Conexao_Event_Sources is not loaded. Run this through the local stack with the event importer active.'
	);
}

$type_filter    = isset( $ctx['extra']['type'] ) ? conexao_sss_csv( $ctx['extra']['type'] ) : array();
$id_filter      = isset( $ctx['extra']['ids'] ) ? conexao_sss_csv( $ctx['extra']['ids'] ) : array();
$exclude_ids    = isset( $ctx['extra']['exclude_ids'] ) ? conexao_sss_csv( $ctx['extra']['exclude_ids'] ) : array();
$exclude_prefix = isset( $ctx['extra']['exclude_prefix'] ) ? conexao_sss_csv( $ctx['extra']['exclude_prefix'] ) : array();

$apply = ( 'apply' === $ctx['mode'] );

$sources = new Conexao_Event_Sources();
$all     = $sources->get_all();

/*
 * Selection.
 *
 * `--ids` is an allowlist: when given, nothing outside it can be touched, which
 * is the strongest form of intent an operator can express and therefore the one
 * that wins over every other filter.
 */
$selected  = array();
$excluded  = array();
$unchanged = array();

foreach ( $all as $source_id => $source ) {
	$source_id   = (string) $source_id;
	$source_type = isset( $source['type'] ) ? strtolower( (string) $source['type'] ) : '';
	$source_stat = isset( $source['status'] ) ? (string) $source['status'] : '';

	$skip_reason = '';

	if ( ! empty( $id_filter ) && ! in_array( strtolower( $source_id ), $id_filter, true ) ) {
		$skip_reason = 'not-in-ids';
	} elseif ( in_array( strtolower( $source_id ), $exclude_ids, true ) ) {
		$skip_reason = 'excluded-id';
	} else {
		foreach ( $exclude_prefix as $prefix ) {
			if ( '' !== $prefix && 0 === strpos( strtolower( $source_id ), $prefix ) ) {
				$skip_reason = 'excluded-prefix:' . $prefix;
				break;
			}
		}
	}

	if ( '' === $skip_reason && ! empty( $type_filter ) && ! in_array( $source_type, $type_filter, true ) ) {
		$skip_reason = 'type-not-selected';
	}

	if ( '' !== $skip_reason ) {
		$excluded[ $source_id ] = $skip_reason;
		continue;
	}

	if ( $source_stat === $status ) {
		$unchanged[ $source_id ] = $source_stat;
		continue;
	}

	$selected[ $source_id ] = array(
		'id'   => $source_id,
		'type' => $source_type,
		'from' => $source_stat,
		'to'   => $status,
	);
}

/* Apply through the SAME method the admin screen uses. */
$changed = array();
$errors  = 0;

if ( $apply ) {
	foreach ( $selected as $source_id => $change ) {
		if ( $sources->set_status( $source_id, $status ) ) {
			$changed[ $source_id ] = $change;
			continue;
		}

		++$errors;
		fwrite( STDERR, 'ERROR: could not set ' . $source_id . ' to ' . $status . "\n" );
	}
}

/* Operator-facing plan. */
if ( ! $ctx['json'] ) {
	printf( "%s %s\n", $apply ? 'changed' : 'would change', $status );

	foreach ( $selected as $source_id => $change ) {
		if ( $apply && ! isset( $changed[ $source_id ] ) ) {
			continue;
		}

		printf(
			"  %-46s %-9s -> %s\n",
			$source_id,
			$change['from'] ? $change['from'] : '(unset)',
			$change['to']
		);
	}

	if ( empty( $selected ) ) {
		printf( "  (nothing to change)\n" );
	}

	if ( ! empty( $unchanged ) ) {
		printf( "unchanged: %d already %s\n", count( $unchanged ), $status );
	}

	if ( ! empty( $excluded ) ) {
		$by_reason = array();
		foreach ( $excluded as $reason ) {
			$by_reason[ $reason ] = isset( $by_reason[ $reason ] ) ? $by_reason[ $reason ] + 1 : 1;
		}

		$parts = array();
		foreach ( $by_reason as $reason => $count ) {
			$parts[] = $reason . '=' . $count;
		}

		printf( "not selected: %s\n", implode( ', ', $parts ) );
	}

	if ( ! $apply && ! empty( $selected ) ) {
		printf( "\nDry run: nothing was written. Re-run with --apply to perform it.\n" );
	}
}

$summary = array(
	'status'    => $status,
	'selected'  => count( $selected ),
	'changed'   => $apply ? count( $changed ) : 0,
	'unchanged' => count( $unchanged ),
	'excluded'  => count( $excluded ),
	'errors'    => $errors,
);

exit(
	conexao_script_summary(
		$ctx,
		$summary,
		array(
			/* The reversal recipe: replay `previous` to restore the old state. */
			'plan'          => array_values( $selected ),
			'previous'      => array_map(
				static function ( $change ) {
					return array(
						'id'     => $change['id'],
						'status' => $change['from'],
					);
				},
				array_values( $selected )
			),
			'unchanged_ids' => array_keys( $unchanged ),
			'excluded_ids'  => array_keys( $excluded ),
		)
	)
);
