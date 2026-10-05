<?php
/**
 * G14 — controlled Eventbrite importer run (local/staging only).
 *
 * ## What this is
 *
 * The G14 write-capable execution harness. It performs ONE controlled import of
 * ONE named source through the repository's documented entry point — the
 * `conexao_event_importer_run_source` filter, the same filter the G9 report
 * documents as the operator command — and records, as evidence, everything the
 * brief asks for in §27/§28/§29/§31.
 *
 * ## Why the filter and not `wp conexao-events import`
 *
 * WP-CLI is NOT installed in this local container (`command -v wp` is empty), so
 * the documented `wp conexao-events import --source=X` command cannot be
 * executed here. The filter it ultimately calls IS registered and reachable
 * from PHP, so this harness calls exactly that. It is the same code path, not a
 * reimplementation: `Conexao_Event_Importer::run_source()` runs inside.
 *
 * This substitution is recorded as a limitation, not hidden.
 *
 * ## Safety contract (G14 §23/§26)
 *
 * This script REFUSES TO RUN unless the environment is positively identified as
 * non-production. It fails closed on three independent signals:
 *
 *   1. `wp_get_environment_type()` must be `local` or `staging`;
 *   2. the resolved site URL must classify as local/staging through the
 *      repository's own `conexao_script_classify_target()` helper — the SAME
 *      helper `scripts/lib/bootstrap.php` uses for its production write guard;
 *   3. the site URL must not be the production host.
 *
 * If any check fails it exits non-zero WITHOUT importing. There is no
 * `--force` bypass, by design.
 *
 * ## Usage
 *
 *     docker compose exec -T wordpress php /var/www/html/tests/experiments/g14-controlled-import.php \
 *         --source=<id> [--activate] [--label=<name>] [--out=<path>]
 *
 * `--activate` flips the named source from `inactive` to `active` through the
 * registry's own `set_status()` — the same code path the admin Enable button
 * uses. It never creates, deletes or re-points a source.
 *
 * @package Conexao_Event_Importer
 */

require_once dirname( __DIR__, 2 ) . '/tests/bootstrap.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

/**
 * Print a harness message to stderr.
 *
 * @param string $message Message.
 * @return void
 */
function g14_say( $message ) {
	fwrite( STDERR, '[g14] ' . $message . "\n" );
}

/**
 * Parse `--key=value` arguments.
 *
 * @param array $argv Raw argv.
 * @return array<string,string>
 */
function g14_args( array $argv ) {
	$args = array();

	foreach ( array_slice( $argv, 1 ) as $token ) {
		if ( 0 !== strpos( $token, '--' ) ) {
			continue;
		}

		$token = substr( $token, 2 );
		$eq    = strpos( $token, '=' );

		if ( false === $eq ) {
			$args[ $token ] = '1';
			continue;
		}

		$args[ substr( $token, 0, $eq ) ] = substr( $token, $eq + 1 );
	}

	return $args;
}

/**
 * Prove the environment is non-production, or refuse to run.
 *
 * Fails closed. Returns the evidence array on success; exits non-zero otherwise.
 *
 * @return array Environment proof.
 */
function g14_assert_non_production() {
	require_once CONEXAO_TESTS_ROOT . '/../scripts/lib/bootstrap.php';

	$home_url  = rtrim( (string) home_url( '/' ), '/' );
	$override  = getenv( 'CONEXAO_SITE_URL' );
	$target    = conexao_script_site_url();

	/*
	 * BOTH signals must agree that this is non-production.
	 *
	 * `home_url()` is what WordPress itself believes; it is authoritative about
	 * the database this process is actually attached to. `conexao_script_site_url()`
	 * additionally honours the repository's CONEXAO_SITE_URL override, which is
	 * what an operator uses to point a script at a specific target.
	 *
	 * Requiring both to be non-production is deliberately the STRICTER reading:
	 * a production install, a pointed-at-production override, or a disagreement
	 * between the two all stop the run. It also makes the guard testable — the
	 * override can be set to the production host and the refusal observed.
	 */
	$signals = array( 'home_url' => $home_url, 'target' => $target );

	if ( is_string( $override ) && '' !== $override ) {
		$signals['env_override'] = rtrim( $override, '/' );
	}

	$classes = array();
	$hosts   = array();

	foreach ( $signals as $name => $url ) {
		$host             = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$hosts[ $name ]   = $host;
		$classes[ $name ] = conexao_script_classify_target( $url );

		if ( in_array( $host, array( 'conexaobr.ie', 'www.conexaobr.ie' ), true ) ) {
			g14_say( 'REFUSING TO RUN: ' . $name . ' (' . $url . ') is the PRODUCTION host.' );
			exit( 3 );
		}

		if ( ! in_array( $classes[ $name ], array( 'local', 'staging' ), true ) ) {
			g14_say( 'REFUSING TO RUN: ' . $name . ' (' . $url . ') classifies as "' . $classes[ $name ] . '", not local/staging.' );
			exit( 3 );
		}
	}

	$env_type = function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : 'unknown';

	if ( ! in_array( $env_type, array( 'local', 'staging' ), true ) ) {
		g14_say( 'REFUSING TO RUN: environment type is "' . $env_type . '", not local/staging.' );
		exit( 3 );
	}

	$proof = array(
		'home_url'   => $home_url,
		'target'     => $target,
		'env_type'   => $env_type,
		'classes'    => $classes,
		'hosts'      => $hosts,
		'override'   => ( is_string( $override ) && '' !== $override ) ? rtrim( $override, '/' ) : '',
		'non_prod'   => true,
	);

	g14_say(
		sprintf(
			'non-production confirmed: home=%s env=%s classes=%s',
			$home_url,
			$env_type,
			implode( ',', array_unique( array_values( $classes ) ) )
		)
	);

	return $proof;
}

/**
 * Snapshot the operational state a reader needs to judge the run afterwards.
 *
 * @param string $source_id Source id.
 * @return array
 */
function g14_snapshot( $source_id ) {
	$checkpoint = class_exists( 'Conexao_Source_Enumeration_Checkpoint' )
		? Conexao_Source_Enumeration_Checkpoint::get_state( $source_id )
		: array();

	$cooldown = class_exists( 'Conexao_Source_Cooldown' )
		? Conexao_Source_Cooldown::get_state( $source_id )
		: array();

	$sources = new Conexao_Event_Sources();
	$source  = $sources->get( $source_id );

	return array(
		'checkpoint'    => $checkpoint,
		'cooldown'      => $cooldown,
		'cooling_down'  => class_exists( 'Conexao_Source_Cooldown' )
			? Conexao_Source_Cooldown::is_cooling_down( $source_id )
			: false,
		'source_status' => $source ? $source['status'] : '(unknown)',
		'stored_events' => g14_stored_events( $source_id ),
	);
}

/**
 * Count this source's stored events, split by lifecycle status.
 *
 * Used to prove three separate claims after a run:
 *   - a resumed run does not duplicate (count must not jump by `found`);
 *   - an incomplete run does not destroy (source_not_found must not rise);
 *   - events survive with a real status rather than vanishing.
 *
 * @param string $source_id Source id.
 * @return array{count:int,source_not_found:int,published:int}
 */
function g14_stored_events( $source_id ) {
	$ids = get_posts(
		array(
			'post_type'      => 'event',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'   => '_event_source',
					'value' => $source_id,
				),
			),
		)
	);

	$not_found = 0;
	$published = 0;

	foreach ( $ids as $id ) {
		$status = (string) get_post_meta( $id, '_event_status', true );

		if ( class_exists( 'Conexao_Event_Status' ) && Conexao_Event_Status::SOURCE_NOT_FOUND === $status ) {
			++$not_found;
		}

		if ( class_exists( 'Conexao_Event_Status' ) && Conexao_Event_Status::PUBLISHED === $status ) {
			++$published;
		}
	}

	return array(
		'count'           => count( $ids ),
		'source_not_found'=> $not_found,
		'published'       => $published,
	);
}

/**
 * The most recent Import History entry for a source.
 *
 * §31 requires the history row to be internally consistent, so the whole row is
 * captured rather than just the counters.
 *
 * @param string $source_id Source id.
 * @return array|null
 */
function g14_last_history( $source_id ) {
	$rows = Conexao_Import_History::get_for_source( $source_id, 1 );

	return empty( $rows ) ? null : $rows[0];
}

/**
 * Entry point.
 *
 * @param array $argv Raw argv.
 * @return int Exit code.
 */
function g14_main( array $argv ) {
	$args      = g14_args( $argv );
	$source_id = isset( $args['source'] ) ? sanitize_key( $args['source'] ) : '';
	$label     = isset( $args['label'] ) ? preg_replace( '/[^a-z0-9_.-]+/', '_', strtolower( $args['label'] ) ) : 'run';

	if ( '' === $source_id ) {
		g14_say( 'missing --source=<registry source id>' );
		return 2;
	}

	// The write boundary is verified BEFORE anything else can touch a source.
	$env = g14_assert_non_production();

	$sources = new Conexao_Event_Sources();
	$source  = $sources->get( $source_id );

	if ( ! $source ) {
		g14_say( 'unknown source "' . $source_id . '" — refusing to invent a URL' );
		return 2;
	}

	$registry = array(
		'id'      => $source['id'],
		'name'    => $source['name'],
		'url'     => $source['url'],
		'type'    => $source['type'],
		'county'  => isset( $source['county'] ) ? $source['county'] : '',
		'status'  => $source['status'],
	);

	g14_say( sprintf( 'source %s -> %s (status=%s)', $source_id, $source['url'], $source['status'] ) );

	$activated = false;

	if ( ! empty( $args['activate'] ) && 'active' !== $source['status'] ) {
		// The registry's own status setter — the admin Enable button's code
		// path. It never creates, deletes or re-points a source.
		$sources->set_status( $source_id, 'active' );
		$activated = true;
		g14_say( 'activated ' . $source_id );
	}

	$before = g14_snapshot( $source_id );

	g14_say(
		sprintf(
			'before: status=%s stored=%d checkpoint=%s cooling_down=%s',
			$before['source_status'],
			$before['stored_events']['count'],
			isset( $before['checkpoint']['last_page'] ) ? (int) $before['checkpoint']['last_page'] : 0,
			$before['cooling_down'] ? 'yes' : 'no'
		)
	);

	// THE DOCUMENTED ENTRY POINT. Same filter the operator command calls.
	$result = apply_filters( 'conexao_event_importer_run_source', $source_id );

	if ( is_wp_error( $result ) || ! is_array( $result ) ) {
		g14_say( 'the importer returned no usable result' );
		return 4;
	}

	$after = g14_snapshot( $source_id );

	/*
	 * §30 — reconciliation safety, verified rather than asserted.
	 *
	 * An incomplete source must NOT have marked anything missing. The observable
	 * proof is that `source_not_found` did not rise across a run the importer
	 * itself reported as incomplete.
	 */
	$incomplete      = isset( $result['complete'] ) ? ! $result['complete'] : null;
	$marking_allowed = ( null === $incomplete ) ? null : ! $incomplete;
	$nf_before       = (int) $before['stored_events']['source_not_found'];
	$nf_after        = (int) $after['stored_events']['source_not_found'];

	$reconciliation = array(
		// Named for what it IS: the run reported itself incomplete.
		'incomplete'                => $incomplete,
		// Whether absence-based reconciliation was therefore permitted.
		// False here means mark_missing_events() was NOT called.
		'mark_missing_expected'     => $marking_allowed,
		'source_not_found_before'   => $nf_before,
		'source_not_found_after'    => $nf_after,
		// When the run was incomplete, this MUST be 0.
		'marked_missing_while_incomplete' => ( null === $incomplete || $incomplete ) ? ( $nf_after - $nf_before ) : 0,
	);

	$payload = array(
		'label'        => $label,
		'timestamp'    => gmdate( 'c' ),
		'environment'  => $env,
		'registry'     => $registry,
		'activated'    => $activated,
		'before'       => $before,
		'result'       => $result,
		'after'        => $after,
		'reconciliation'=> $reconciliation,
		'history'      => g14_last_history( $source_id ),
	);

	$out = isset( $args['out'] )
		? (string) $args['out']
		: CONEXAO_TESTS_WP_ROOT . '/tests/experiments/observations/g14-' . $source_id . '-' . $label . '.json';

	if ( ! is_dir( dirname( $out ) ) ) {
		wp_mkdir_p( dirname( $out ) );
	}

	file_put_contents( $out, wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

	g14_say(
		sprintf(
			'found=%d created=%d updated=%d unchanged=%d duplicates=%d skipped=%d errors=%d complete=%s',
			isset( $result['found'] ) ? (int) $result['found'] : 0,
			isset( $result['created'] ) ? (int) $result['created'] : 0,
			isset( $result['updated'] ) ? (int) $result['updated'] : 0,
			isset( $result['unchanged'] ) ? (int) $result['unchanged'] : 0,
			isset( $result['duplicates'] ) ? (int) $result['duplicates'] : 0,
			isset( $result['skipped'] ) ? (int) $result['skipped'] : 0,
			isset( $result['errors'] ) ? (int) $result['errors'] : 0,
			null === $incomplete ? 'unknown' : ( $incomplete ? 'NO' : 'yes' )
		)
	);

	g14_say(
		sprintf(
			'after: stored=%d checkpoint=%s | wrote %s',
			$after['stored_events']['count'],
			isset( $after['checkpoint']['last_page'] ) ? (int) $after['checkpoint']['last_page'] : 0,
			$out
		)
	);

	return 0;
}

exit( g14_main( $argv ) );