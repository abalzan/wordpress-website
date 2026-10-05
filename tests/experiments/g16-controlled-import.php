<?php
/**
 * G16 — pre-import safety check (§13) and the controlled full import.
 *
 * ## What this is
 *
 * The write-capable half of G16. It runs the repository's SUPPORTED import
 * entry point — the `conexao_event_importer_run_all` filter, the same filter
 * the admin "Run all" button and the documented operator command call — and
 * records every metric §14 asks for, per source.
 *
 * ## Safety contract
 *
 * 1. Environment is proven non-production first, and the proof is printed.
 * 2. A pre-import safety check asserts every value §13 names and ABORTS if any
 *    is unexpected. It is a gate, not a report.
 * 3. CONCURRENCY: the importer holds NO lock (G14 §9). This harness therefore
 *    writes a run marker, refuses to start when another run marker is live,
 *    and removes it in a `finally`. The operator rule remains: one run per
 *    environment at a time.
 * 4. Serial by construction: this script runs sources through the engine one
 *    run at a time and never forks a second process.
 *
 * ## Usage
 *
 *     docker compose exec -T wordpress php /var/www/html/tests/experiments/g16-controlled-import.php --label=run1 [--source=<id>]
 *
 * @package Conexao_Event_Importer
 */

require_once dirname( __DIR__, 2 ) . '/tests/bootstrap.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

const G16_RUN_MARKER = 'conexao_g16_import_running';

/**
 * Print a harness message to stdout.
 *
 * @param string $message Message.
 * @return void
 */
function g16_say( $message ) {
	echo $message . "\n";
}

/**
 * Parse `--key=value` arguments.
 *
 * @param array<int,string> $argv Raw argv.
 * @return array<string,string> Parsed arguments.
 */
function g16_args( array $argv ) {
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
 * Fails closed on the same three signals the G14 harness uses, through the
 * repository's own classifier. There is no --force bypass.
 *
 * @return array<string,mixed> Environment proof.
 */
function g16_assert_non_production() {
	require_once CONEXAO_TESTS_ROOT . '/../scripts/lib/bootstrap.php';

	$home_url = rtrim( (string) home_url( '/' ), '/' );
	$target   = conexao_script_site_url();
	$override = getenv( 'CONEXAO_SITE_URL' );

	$signals = array( 'home_url' => $home_url, 'target' => $target );

	if ( is_string( $override ) && '' !== $override ) {
		$signals['env_override'] = rtrim( $override, '/' );
	}

	$classes = array();

	foreach ( $signals as $name => $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if ( in_array( $host, array( 'conexaobr.ie', 'www.conexaobr.ie' ), true ) ) {
			g16_say( 'REFUSING TO IMPORT: ' . $name . ' (' . $url . ') is the PRODUCTION host.' );
			exit( 3 );
		}

		$class = conexao_script_classify_target( $url );

		if ( ! in_array( $class, array( 'local', 'staging' ), true ) ) {
			g16_say( 'REFUSING TO IMPORT: ' . $name . ' (' . $url . ') classifies as "' . $class . '", not local/staging.' );
			exit( 3 );
		}

		$classes[ $name ] = $class;
	}

	$env_type = function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : 'unknown';

	if ( ! in_array( $env_type, array( 'local', 'staging' ), true ) ) {
		g16_say( 'REFUSING TO IMPORT: environment type is "' . $env_type . '", not local/staging.' );
		exit( 3 );
	}

	g16_say( 'non-production confirmed: home=' . $home_url . ' env=' . $env_type . ' classes=' . implode( ',', array_unique( array_values( $classes ) ) ) );

	return array(
		'home_url' => $home_url,
		'target'   => $target,
		'env_type' => $env_type,
		'classes'  => $classes,
	);
}

/**
 * Assert every §13 pre-import safety value, or abort.
 *
 * This is a GATE. Every expectation is checked against the live code/registry,
 * not asserted on faith, and any mismatch stops the run before a single event
 * is written.
 *
 * @return array<string,mixed> Verified safety values.
 */
function g16_pre_import_check() {
	$sources = new Conexao_Event_Sources();
	$all     = $sources->get_all();
	$active  = $sources->get_active();

	$ref = new ReflectionClass( 'Conexao_Source_Eventbrite' );

	$overlap = $ref->getConstant( 'OVERLAP_PAGES' );
	$time    = $ref->getConstant( 'TIME_BUDGET_SECONDS' );
	$maxpg   = $ref->getConstant( 'MAX_PAGES' );

	$budget = (int) apply_filters( 'conexao_event_importer_request_budget', Conexao_Request_Budget::DEFAULT_LIMIT );

	$cd = new ReflectionClass( 'Conexao_Source_Cooldown' );

	$expectations = array(
		'plugin_active'          => in_array( 'conexao-event-importer/conexao-event-importer.php', (array) get_option( 'active_plugins', array() ), true ),
		'registry_valid'         => ( count( $all ) === count( array_unique( array_keys( $all ) ) ) ),
		'overlap_pages_is_1'     => ( 1 === (int) $overlap ),
		'request_budget_400'     => ( 400 === $budget ),
		'time_budget_45'         => ( 45 === (int) $time ),
		'max_pages_50'           => ( 50 === (int) $maxpg ),
		'cooldown_present'       => class_exists( 'Conexao_Source_Cooldown' ),
		'cooldown_base_20'       => ( 20 === (int) $cd->getConstant( 'BASE_SECONDS' ) ),
		'cooldown_max_60'        => ( 60 === (int) $cd->getConstant( 'MAX_SECONDS' ) ),
		'checkpoint_present'     => class_exists( 'Conexao_Source_Enumeration_Checkpoint' ),
		'checkpoint_enabled'     => (bool) apply_filters( 'conexao_eventbrite_checkpoint_enabled', true ),
		'api_token_absent'       => ( '' === (string) getenv( 'WP_EVENTBRITE_API_TOKEN' ) ),
		'active_sources_present' => ( count( $active ) > 0 ),
	);

	/*
	 * Concurrency: the importer holds NO lock (verified by reading the engine:
	 * no flock/LOCK_EX/mutex anywhere in the plugin). G14 §9 recorded the
	 * resulting hazard. This stage therefore runs strictly serially and asserts
	 * no other run marker is live, rather than pretending a lock exists.
	 */
	$expectations['no_lock_implementation'] = ! g16_lock_code_exists();
	$expectations['no_concurrent_run']       = ! get_option( G16_RUN_MARKER, false );

	$failed = array();

	foreach ( $expectations as $name => $ok ) {
		g16_say( sprintf( '  %-24s %s', $name, $ok ? 'OK' : 'UNEXPECTED' ) );

		if ( ! $ok ) {
			$failed[] = $name;
		}
	}

	if ( ! empty( $failed ) ) {
		g16_say( 'ABORT: pre-import safety check failed: ' . implode( ', ', $failed ) );
		exit( 5 );
	}

	g16_say( 'pre-import safety check PASSED (' . count( $expectations ) . ' checks)' );

	return array(
		'expectations'  => $expectations,
		'active_sources' => array_keys( $active ),
		'overlap'       => (int) $overlap,
		'time_budget'   => (int) $time,
		'max_pages'     => (int) $maxpg,
		'request_budget' => $budget,
	);
}

/**
 * Whether the importer contains any real locking primitive.
 *
 * A grep over the plugin's executable PHP for flock/LOCK_EX/mutex. Measured by
 * G16: zero hits, which is why §12 mandates serial execution.
 *
 * @return bool True when a lock primitive exists.
 */
function g16_lock_code_exists() {
	static $found = null;

	if ( null !== $found ) {
		return $found;
	}

	$found = false;

	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WP_PLUGIN_DIR . '/conexao-event-importer' ) );

	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}

		$body = (string) file_get_contents( $file->getPathname() );

		if ( preg_match( '/\bflock\s*\(|LOCK_EX|import_lock|acquire_lock/', $body ) ) {
			$found = true;
			break;
		}
	}

	return $found;
}

/**
 * Capture the operator-visible state of one source.
 *
 * @param string $source_id Source slug.
 * @return array<string,mixed> Checkpoint, cooldown and stored-event count.
 */
function g16_source_state( $source_id ) {
	global $wpdb;

	$stored = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_event_source_id' AND meta_value = %s",
			(string) $source_id
		)
	);

	return array(
		'checkpoint'    => Conexao_Source_Enumeration_Checkpoint::get_state( $source_id ),
		'cooldown'      => Conexao_Source_Cooldown::get_state( $source_id ),
		'cooling_down'  => Conexao_Source_Cooldown::is_cooling_down( $source_id ),
		'stored_events' => $stored,
	);
}

/**
 * Global database counters used to judge the run's net effect.
 *
 * @return array<string,mixed> Counters.
 */
function g16_global_counts() {
	global $wpdb;

	$rows = $wpdb->get_results(
		"SELECT meta_value AS src, COUNT(*) AS c
		 FROM {$wpdb->postmeta}
		 WHERE meta_key = '_event_source_id'
		 GROUP BY meta_value",
		ARRAY_A
	);

	$by_source = array();
	$distinct  = 0;

	foreach ( (array) $rows as $row ) {
		$by_source[ $row['src'] ] = (int) $row['c'];
		++$distinct;
	}

	// Native ids mapped to more than one WordPress post = duplicate events.
	$dupes = $wpdb->get_results(
		"SELECT meta_value AS src, COUNT(*) AS c
		 FROM {$wpdb->postmeta}
		 WHERE meta_key = '_event_source_id'
		 GROUP BY meta_value
		 HAVING c > 1",
		ARRAY_A
	);

	$dup_ids = 0;

	foreach ( (array) $dupes as $row ) {
		$dup_ids += (int) $row['c'] - 1;
	}

	return array(
		'events_total'         => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'event'" ),
		'source_id_posts'      => array_sum( $by_source ),
		'distinct_source_ids'  => $distinct,
		'duplicate_source_ids' => $dup_ids,
		'source_not_found'     => (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_event_status' AND meta_value = 'source_not_found'"
		),
	);
}

/**
 * Render one scalar-or-json value for the console.
 *
 * @param mixed $value Value.
 * @return string Printable value.
 */
function g16_printable( $value ) {
	return is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
}

/**
 * Print the checkpoint/cooldown line for one source.
 *
 * @param string $id    Source id.
 * @param array  $state State from g16_source_state().
 * @return void
 */
function g16_print_state( $id, array $state ) {
	g16_say(
		sprintf(
			'  %-42s checkpoint=%s cooldown=%ss cooling=%s stored=%d',
			$id,
			$state['checkpoint'] ? $state['checkpoint']['last_page'] . '/' . $state['checkpoint']['page_count'] : 'none',
			$state['cooldown']['seconds_remaining'],
			$state['cooling_down'] ? 'yes' : 'no',
			$state['stored_events']
		)
	);
}

$args   = g16_args( (array) $argv );
$label  = $args['label'] ?? 'run';
$single = $args['source'] ?? '';

$proof = g16_assert_non_production();

g16_say( '=== G16 controlled import: ' . $label . ' ===' );
g16_say( '--- §13 pre-import safety check ---' );

$safety = g16_pre_import_check();

g16_say( 'active_sources=' . count( $safety['active_sources'] ) );

/*
 * Concurrency marker. The importer has no lock, so the only protection
 * available without new architecture is a run marker plus strict serial
 * operation. Set BEFORE the run, cleared in `finally`.
 */
update_option( G16_RUN_MARKER, array( 'label' => $label, 'pid' => getmypid(), 'started' => gmdate( 'c' ) ), false );

$sources = new Conexao_Event_Sources();

$before = array();

foreach ( ( $single ? array( $single ) : array_keys( $sources->get_active() ) ) as $id ) {
	$before[ $id ] = g16_source_state( $id );
}

g16_say( '--- checkpoint / cooldown BEFORE ---' );

foreach ( $before as $id => $state ) {
	g16_print_state( $id, $state );
}

$global_before = g16_global_counts();

g16_say( '--- RUN START ---' );

$started = microtime( true );

try {
	if ( $single ) {
		// Same filter the admin single-source button uses.
		$result = apply_filters( 'conexao_event_importer_run_source', $single );
		$per    = array( $single => $result );
	} else {
		// Same filter the admin "Run all" button and the operator command use.
		$result = apply_filters( 'conexao_event_importer_run_all', array() );
		$per    = isset( $result['sources'] ) && is_array( $result['sources'] ) ? $result['sources'] : array();
	}
} finally {
	delete_option( G16_RUN_MARKER );
}

$elapsed = microtime( true ) - $started;

g16_say( '--- RUN END (' . round( $elapsed, 1 ) . 's) ---' );

$global_after = g16_global_counts();

g16_say( '=== combined ===' );

foreach ( array( 'found', 'created', 'new', 'updated', 'unchanged', 'duplicates', 'skipped', 'skipped_past', 'failed', 'errors', 'status' ) as $key ) {
	if ( isset( $result[ $key ] ) ) {
		g16_say( sprintf( '  %-14s %s', $key, g16_printable( $result[ $key ] ) ) );
	}
}

g16_say( '=== per-source ===' );

$rows = array();

foreach ( $per as $id => $r ) {
	$rows[ $id ] = array(
		'found'                    => (int) ( $r['found'] ?? 0 ),
		'created'                  => (int) ( $r['created'] ?? 0 ),
		'updated'                  => (int) ( $r['updated'] ?? 0 ),
		'unchanged'                => (int) ( $r['unchanged'] ?? 0 ),
		'duplicates'               => (int) ( $r['duplicates'] ?? 0 ),
		'skipped'                  => (int) ( $r['skipped'] ?? 0 ),
		'skipped_past'             => (int) ( $r['skipped_past'] ?? 0 ),
		'failed'                   => (int) ( $r['failed'] ?? 0 ),
		'errors'                   => (int) ( $r['errors'] ?? 0 ),
		'status'                   => (string) ( $r['status'] ?? '' ),
		'complete'                 => (bool) ( $r['complete'] ?? false ),
		'reconciliation_safe'      => isset( $r['reconciliation_safe'] ) ? (bool) $r['reconciliation_safe'] : null,
		'rate_limited'             => (int) ( $r['rate_limited'] ?? 0 ),
		'waf'                      => (int) ( $r['waf'] ?? 0 ),
		'http_failures'            => (int) ( $r['http_failures'] ?? 0 ),
		'fatal_errors'             => $r['fatal_errors'] ?? array(),
		'checkpoint'               => $r['checkpoint'] ?? null,
		'cooldown'                 => $r['cooldown'] ?? null,
		'source_not_found_changes' => (int) ( $r['source_not_found_changes'] ?? 0 ),
	);

	g16_say(
		sprintf(
			'  %-42s found=%-5d created=%-5d upd=%-4d unch=%-4d dup=%-4d err=%-3d status=%-8s complete=%s',
			$id,
			$rows[ $id ]['found'],
			$rows[ $id ]['created'],
			$rows[ $id ]['updated'],
			$rows[ $id ]['unchanged'],
			$rows[ $id ]['duplicates'],
			$rows[ $id ]['errors'],
			$rows[ $id ]['status'],
			$rows[ $id ]['complete'] ? 'yes' : 'no'
		)
	);
}

g16_say( '=== checkpoint / cooldown AFTER ===' );

foreach ( array_keys( $before ) as $id ) {
	$after = g16_source_state( $id );

	$rows[ $id ]['before'] = $before[ $id ];
	$rows[ $id ]['after']  = $after;

	g16_print_state( $id, $after );
}

g16_say( '=== global counts ===' );

foreach ( $global_before as $key => $v ) {
	g16_say( sprintf( '  %-28s %s -> %s', $key, g16_printable( $v ), g16_printable( $global_after[ $key ] ) ) );
}

$evidence_dir = dirname( __DIR__, 2 ) . '/docs/evidence/2026-10-05-eventbrite-g16';

$out = array(
	'label'         => $label,
	'environment'   => $proof,
	'safety'        => $safety,
	'elapsed'       => round( $elapsed, 1 ),
	'combined'      => $result,
	'per_source'    => $rows,
	'global_before' => $global_before,
	'global_after'  => $global_after,
);

file_put_contents( $evidence_dir . '/' . $label . '-import.json', wp_json_encode( $out, JSON_PRETTY_PRINT ) . "\n" );

g16_say( 'wrote ' . $label . '-import.json' );
