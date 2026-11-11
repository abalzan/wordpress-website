#!/usr/bin/env php
<?php
/**
 * Seed the six legacy commemorative occasions as `commemorative_event` records.
 *
 * LOCAL ONLY. One-shot, idempotent, dry-run-first content change
 * (architecture proposal r10 §8.2). It maps the six legacy registry entries
 * (`christmas`, `halloween`, `new_year`, `st_patricks_day`, `valentines_day`,
 * `easter`) onto records carrying the SAME dates and lead-ins, so the
 * observable behaviour is identical — with ONE intentional exception: New Year
 * is seeded with the corrected seven-day `12-26 → 01-01` window.
 *
 * Idempotence: an existing `_ce_event_key` is skipped, and a key recorded in
 * `conexao_commemorative_seeded_keys` is skipped even when its record was
 * permanently deleted, so a deliberately removed event never reappears. A
 * second run reports `created = 0`.
 *
 * Safety: dry-run is the default and performs zero writes. `--apply` is the only
 * mode that writes, and it only creates records for keys that are missing. It
 * never updates or deletes an existing record.
 *
 * Usage (from the project root):
 *   docker compose exec -T wordpress php /var/www/html/scripts/seed-commemorative-events.php --dry-run
 *   docker compose exec -T wordpress php /var/www/html/scripts/seed-commemorative-events.php --apply
 *
 * @package Conexao_BR_Irlanda
 */

require_once __DIR__ . '/lib/bootstrap.php';

$ctx = conexao_script_boot(
	array(
		'script'             => 'scripts/seed-commemorative-events.php',
		'purpose'            => 'Create the six legacy commemorative occasions as commemorative_event records (idempotent, dry-run first).',
		'scope'              => 'local-write (creates records; never updates or deletes an existing record)',
		'safety'             => 'Dry-run is the default and writes nothing. --apply creates only the records whose event key is missing, plus their linked EN record when Polylang is active.',
		'safety_level'       => 'local-write',
		'writes'             => true,
		'production_capable' => false,
		'target_description' => 'the WordPress install it is executed against (local Docker WordPress by default)',
		'modes_description'  => "  --dry-run   Plan only; performs zero writes (DEFAULT).\n"
			. "  --apply     Create the missing records.\n"
			. "  --json      Emit the result as JSON on stdout.\n",
		'arguments'          => "  --no-translations   Do not create the linked EN record (PT only).\n",
		'extra_flags'        => array( '--no-translations' => 'no_translations' ),
	)
);

if ( ! class_exists( 'Conexao_Commemorative_Migration' ) ) {
	fwrite( STDERR, "ERROR: the conexao-commemorative plugin is not active. Activate it and re-run.\n" );

	return 1;
}

$apply           = 'apply' === $ctx['mode'];
$with_translations = empty( $ctx['extra']['no_translations'] );

$plan = Conexao_Commemorative_Migration::plan();

fwrite(
	STDOUT,
	sprintf(
		"planned: create=%d, skip=%d, conflicts=%d\n",
		count( $plan['create'] ),
		count( $plan['skip'] ),
		count( $plan['conflicts'] )
	)
);

foreach ( $plan['skip'] as $skip ) {
	fwrite( STDOUT, sprintf( "  skip  %-18s reason=%s id=%d\n", $skip['key'], $skip['reason'], (int) $skip['id'] ) );
}

foreach ( $plan['create'] as $item ) {
	fwrite( STDOUT, sprintf( "  create %-18s rule=%s\n", $item['key'], $item['definition']['rule'] ) );
}

$result = Conexao_Commemorative_Migration::apply( ! $apply, $with_translations );

fwrite(
	STDOUT,
	sprintf(
		"%s: created=%d, skipped=%d, translations=%d\n",
		$apply ? 'applied' : 'dry-run',
		(int) $result['created'],
		(int) $result['skipped'],
		(int) $result['translations']
	)
);

return conexao_script_summary(
	$ctx,
	array(
		'mode'         => $ctx['mode'],
		'created'      => (int) $result['created'],
		'skipped'      => (int) $result['skipped'],
		'translations' => (int) $result['translations'],
		'errors'       => 0,
	),
	array(
		'plan' => array(
			'create' => array_map(
				static function ( $item ) {
					return $item['key'];
				},
				$plan['create']
			),
			'skip'   => $plan['skip'],
		),
	)
);
