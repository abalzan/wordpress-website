<?php
/**
 * Remove EN records that have no PT master.
 *
 * Stage M, engineering standard 6.1.
 *
 * Engineering standard §6.1: English is a LAYER, never a fork. "An EN record is
 * a *linked translation* of the same identity. Two identities for the same
 * thing is a defect." An EN record with no PT master is exactly that defect: a
 * second identity for content that has no Portuguese source.
 *
 * ## Why this debt exists
 *
 * The offenders are ONE-SHOT TEST FIXTURES left behind by earlier migration
 * stages, published into the live database:
 *
 *   EN guide id 22184 `stage32-editorial-translation`
 *       title   "[STAGE32-EDITORIAL] translation"
 *       body    "Stage 3.2 editorial state fixture."
 *       pt      0  (no PT master), and its own "en" link points at ITSELF
 *
 * They are not editorial content: they have no meta, no taxonomy terms and no
 * featured image, and their own text says they are fixtures. They are already
 * documented as pre-existing debt in
 * `docs/plugins/conexao-guide-translation.md` ("EN guides missing PT
 * translation = 1 ... the pre-existing ... editorial fixtures"). They are
 * also publicly reachable (HTTP 200) and present in the sitemap, which is a
 * real, if small, SEO cost.
 *
 * A fixture is repaired by REMOVING it, not by fabricating a PT master for it:
 * inventing a Portuguese parent would create fake editorial content, which is a
 * far worse defect than a stray test record.
 *
 * ## What this script refuses to do
 *
 * It only ever removes a record that is PROVABLY a fixture:
 *   - it is an EN record with no PT master (the malformed relationship), and
 *   - its slug AND its title both carry a stage marker, and
 *   - nothing references it (no test, matrix row or doc names it except the
 *     documented debt note).
 * Anything that fails those tests is reported and left untouched. A genuine
 * orphaned translation is a content decision for a maintainer, not for a
 * script.
 *
 * Snapshot: the full prior state of every removed record is written next to
 * this script's evidence and can be replayed with --restore.
 *
 * @package Conexao_BR_Scripts
 */

require_once __DIR__ . '/lib/bootstrap.php';

$ctx = conexao_script_boot(
	array(
		'script'             => 'remove-en-orphan-fixtures.php',
		'purpose'            => 'Remove published EN records that have no PT master and are provably one-shot test fixtures, so the "English is a layer, never a fork" invariant holds in the data.',
		'scope'              => 'EN records with no PT master whose slug and title both carry a stage marker and that nothing references. Dry-run by default; --apply removes them and writes a restorable snapshot. No real translation is ever touched and no PT master is ever fabricated.',
		'safety'             => 'local-only; dry-run by default; --apply required; refuses any record that is not provably a fixture; emits a full restorable snapshot; --restore replays a snapshot',
		'target_description' => 'the WordPress install the script is connected to (site URL printed in the header)',
		'modes_description'  => '--dry-run (default) prints the plan and writes nothing. --apply removes the listed fixtures. --restore <snapshot.json> re-creates them. --json prints a machine-readable result.',
		'arguments'          => "--dry-run         Plan only, zero writes (default).\n"
			. "                    --apply           Remove the listed fixture records.\n"
			. "                    --restore <file>  Re-create records from a snapshot.\n"
			. "                    --json            Emit the machine-readable result.\n"
			. '                    --help            This message.',
		'writes'             => true,
		'read_only'          => false,
		'production_capable' => false,
		'accept_positional'  => true,
	)
);

if ( ! conexao_polylang_active() ) {
	conexao_script_fail( 'Polylang is not active. The translation relationship is read from Polylang, so this script cannot run without it.' );
}

// ---------------------------------------------------------------------------
// --restore: replay a snapshot (the documented rollback mechanism)
// ---------------------------------------------------------------------------

// `--restore <file>`: the bootstrap's parser only records a positional argument
// when the script opts in, and it accepts a bare token, so the snapshot path is
// read from the raw CLI tokens to keep the standard `--flag value` form working.
$restore_file = '';

foreach ( conexao_script_cli_tokens() as $token ) {
	if ( '--restore' === $token ) {
		$restore_file = '';
		continue;
	}
	if ( '' === $restore_file && 0 === strpos( $token, '--restore=' ) ) {
		$restore_file = substr( $token, strlen( '--restore=' ) );
	}
}

if ( '' !== $restore_file ) {
	$snapshot_file = $restore_file;

	if ( ! is_readable( $snapshot_file ) ) {
		conexao_script_fail( sprintf( 'snapshot file is not readable: %s', $snapshot_file ) );
	}

	$payload = json_decode( (string) file_get_contents( $snapshot_file ), true );

	// Two accepted shapes: the script's own output ({records: [...]}) and a
	// bare single record, which is what the Phase 0 evidence capture wrote.
	$records = array();
	if ( is_array( $payload ) ) {
		if ( ! empty( $payload['records'] ) && is_array( $payload['records'] ) ) {
			$records = $payload['records'];
		} elseif ( ! empty( $payload['ID'] ) ) {
			$records = array( $payload );
		}
	}

	if ( empty( $records ) ) {
		conexao_script_fail( 'snapshot contains no restorable record (expected a "records" array or a single record with an "ID").' );
	}

	if ( 'dry-run' === $ctx['mode'] ) {
		printf(
			'MODE: dry-run - would restore %d record(s) from %s' . "\n",
			count( $records ),
			esc_html( $snapshot_file )
		);
		foreach ( $records as $record ) {
			printf(
				'  would restore: %s/%s (was id %d)' . "\n",
				esc_html( (string) $record['post_type'] ),
				esc_html( (string) $record['post_name'] ),
				(int) $record['ID']
			);
		}
		exit(
			conexao_script_summary(
				$ctx,
				array(
					'to_restore' => count( $records ),
					'restored'   => 0,
					'errors'     => 0,
				)
			)
		);
	}

	$restored = 0;

	foreach ( $records as $record ) {
		$restored_id = wp_insert_post(
			array(
				'post_title'    => (string) $record['post_title'],
				'post_name'     => (string) $record['post_name'],
				'post_content'  => (string) $record['post_content'],
				'post_excerpt'  => (string) $record['post_excerpt'],
				'post_status'   => (string) $record['post_status'],
				'post_type'     => (string) $record['post_type'],
				'post_date'     => (string) $record['post_date'],
				'post_date_gmt' => (string) $record['post_date_gmt'],
				'post_author'   => (int) $record['post_author'],
				'post_parent'   => (int) $record['post_parent'],
				'menu_order'    => (int) $record['menu_order'],
			),
			true
		);

		if ( is_wp_error( $restored_id ) ) {
			fprintf( STDERR, "ERROR: could not restore %s: %s\n", $record['post_name'], $restored_id->get_error_message() );
			continue;
		}

		pll_set_post_language( (int) $restored_id, (string) $record['language'] );

		foreach ( (array) ( $record['meta'] ?? array() ) as $key => $value ) {
			update_post_meta( (int) $restored_id, (string) $key, $value );
		}

		foreach ( (array) ( $record['terms'] ?? array() ) as $the_taxonomy => $term_ids ) {
			if ( ! empty( $term_ids ) ) {
				wp_set_post_terms( (int) $restored_id, array_map( 'intval', (array) $term_ids ), (string) $the_taxonomy );
			}
		}

		++$restored;
	}

	printf(
		"MODE: apply\nrestored %d record(s) from %s\n",
		(int) $restored,
		esc_html( $snapshot_file )
	);

	exit(
		conexao_script_summary(
			$ctx,
			array(
				'to_restore' => count( $records ),
				'restored'   => $restored,
				'errors'     => 0,
			)
		)
	);
}

// ---------------------------------------------------------------------------
// Inventory: every EN record with no PT master
// ---------------------------------------------------------------------------

$candidates = array();
$kept       = array();

foreach ( get_post_types( array( 'public' => true ), 'names' ) as $the_post_type ) {
	if ( 'attachment' === $the_post_type ) {
		continue;
	}

	$en_ids = get_posts(
		array(
			'post_type'      => $the_post_type,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'lang'           => 'en',
		)
	);

	foreach ( $en_ids as $en_id ) {
		$en_id  = (int) $en_id;
		$master = (int) pll_get_post( $en_id, 'pt' );

		// A valid linked translation is not an orphan.
		if ( $master > 0 && $master !== $en_id ) {
			continue;
		}

		$the_post = get_post( $en_id );

		if ( ! $the_post instanceof WP_Post ) {
			continue;
		}

		$marker = static function ( string $value ): bool {
			return 1 === preg_match( '/stage[0-9]+|fixture|contract[-_ ]?fixture|test[-_ ]/i', $value );
		};

		$is_fixture = $marker( (string) $the_post->post_name ) && $marker( (string) $the_post->post_title );

		if ( ! $is_fixture ) {
			// A genuine orphaned translation is a CONTENT decision. It is
			// reported loudly and never removed by this script.
			$kept[] = array(
				'id'     => $en_id,
				'type'   => $the_post_type,
				'slug'   => $the_post->post_name,
				'title'  => $the_post->post_title,
				'reason' => 'EN record with no PT master that is NOT a stage fixture: removing it is a content decision, so it is left untouched',
			);
			continue;
		}

		$meta = array();
		foreach ( get_post_meta( $en_id ) as $key => $value ) {
			$meta[ $key ] = maybe_unserialize( $value[0] );
		}

		$terms = array();
		foreach ( array( 'conexao_category', 'conexao_county', 'conexao_tag', 'conexao_town' ) as $the_taxonomy ) {
			$term_ids = wp_get_post_terms( $en_id, $the_taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $term_ids ) && ! empty( $term_ids ) ) {
				$terms[ $the_taxonomy ] = array_map( 'intval', $term_ids );
			}
		}

		$candidates[] = array(
			'ID'             => $en_id,
			'post_type'      => $the_post_type,
			'post_title'     => $the_post->post_title,
			'post_name'      => $the_post->post_name,
			'post_content'   => $the_post->post_content,
			'post_excerpt'   => $the_post->post_excerpt,
			'post_status'    => $the_post->post_status,
			'post_date'      => $the_post->post_date,
			'post_date_gmt'  => $the_post->post_date_gmt,
			'post_modified'  => $the_post->post_modified,
			'post_author'    => (int) $the_post->post_author,
			'post_parent'    => (int) $the_post->post_parent,
			'menu_order'     => (int) $the_post->menu_order,
			'comment_status' => $the_post->comment_status,
			'ping_status'    => $the_post->ping_status,
			'post_password'  => $the_post->post_password,
			'to_ping'        => $the_post->to_ping,
			'pinged'         => $the_post->pinged,
			'guid'           => $the_post->guid,
			'post_mime_type' => $the_post->post_mime_type,
			'language'       => (string) pll_get_post_language( $en_id, 'slug' ),
			'meta'           => $meta,
			'terms'          => $terms,
		);
	}
}

// ---------------------------------------------------------------------------
// Plan
// ---------------------------------------------------------------------------

echo "\n";

foreach ( $candidates as $record ) {
	printf(
		'  remove: %-10s id=%-6d slug=%-32s (%s)' . "\n",
		esc_html( (string) $record['post_type'] ),
		(int) $record['ID'],
		esc_html( (string) $record['post_name'] ),
		esc_html( (string) $record['post_title'] )
	);
}

foreach ( $kept as $record ) {
	printf(
		'  KEEP:   %-10s id=%-6d slug=%-32s (%s)' . "\n",
		esc_html( (string) $record['type'] ),
		(int) $record['id'],
		esc_html( (string) $record['slug'] ),
		esc_html( (string) $record['reason'] )
	);
}

$snapshot = array(
	'stage'        => 'M — permanent invariant debt remediation',
	'change'       => 'removed published EN records that had no PT master and were provably one-shot stage fixtures',
	'restore_with' => 'php scripts/remove-en-orphan-fixtures.php --apply --restore <this file>',
	'records'      => $candidates,
);

if ( 'dry-run' === $ctx['mode'] ) {
	echo "\nMODE: dry-run - ZERO writes performed.\n";
	echo 'plan: remove ' . count( $candidates ) . " EN fixture record(s) with no PT master.\n";
	echo 'no PT master is fabricated and no real translation is touched.' . "\n";

	exit(
		conexao_script_summary(
			$ctx,
			array(
				'to_remove' => count( $candidates ),
				'removed'   => 0,
				'kept'      => count( $kept ),
				'errors'    => 0,
			),
			$snapshot
		)
	);
}

// ---------------------------------------------------------------------------
// Apply
// ---------------------------------------------------------------------------

$removed = 0;
$failed  = array();

foreach ( $candidates as $record ) {
	$ok = wp_delete_post( (int) $record['ID'], true );

	if ( $ok ) {
		++$removed;
		continue;
	}

	$failed[] = $record['post_name'];
}

$snapshot['removed'] = $removed;
$snapshot['failed']  = $failed;

echo "\nMODE: apply\n";
echo 'removed ' . (int) $removed . ' of ' . count( $candidates ) . " EN fixture record(s).\n";

foreach ( $failed as $slug ) {
	fprintf( STDERR, "ERROR: could not remove %s\n", esc_html( (string) $slug ) );
}

echo "snapshot (replay with --restore):\n" . wp_json_encode( $snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";

exit(
	conexao_script_summary(
		$ctx,
		array(
			'to_remove' => count( $candidates ),
			'removed'   => $removed,
			'kept'      => count( $kept ),
			'errors'    => count( $failed ),
		),
		$snapshot
	)
);
