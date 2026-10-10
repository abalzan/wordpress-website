<?php
/**
 * G11 — Eventbrite volatility observation harness.
 *
 * ## Purpose
 *
 * READ-ONLY. Captures one observation of one Eventbrite source and writes it
 * as JSON so a later analysis pass can compare observations over time.
 *
 * ## What it deliberately does NOT do
 *
 *   - it never writes WordPress content, options, checkpoints or cooldowns;
 *   - it never calls `fetch_events()` on a registered source handler, so it
 *     cannot advance a checkpoint or trigger `mark_missing_events()`;
 *   - it never imports, and it never activates a source;
 *   - it never retries around a 429 or a WAF response, and it never uses a
 *     proxy, a header trick or an alternate host.
 *
 * It DOES reuse, rather than fork, the production transport and parser:
 * `Conexao_Eventbrite_Client` performs every request (with its retry/backoff
 * and pacing policy) and `Conexao_Eventbrite_Parser` performs every parse.
 * There is deliberately no second fetch implementation: a forked fetcher would
 * measure a different system than the one G10 checkpointed.
 *
 * ## Usage
 *
 *     docker compose exec wordpress php /var/www/html/tests/experiments/g11-eventbrite-volatility.php \
 *         --source=eventbrite_cork --label=obs1 [--evidence-dir=/var/www/html/...]
 *
 * Sources are the REAL registry ids, resolved through
 * `Conexao_County_Registry::get_all_eventbrite_sources()`, so the URLs measured
 * are exactly the URLs the importer uses. Nothing is substituted or invented.
 *
 * @package Conexao_Event_Importer
 */

require_once dirname( __DIR__, 2 ) . '/tests/bootstrap.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

// The measurement library owns identity, comparison and completeness.
require_once CONEXAO_TESTS_ROOT . '/lib/eb-volatility.php';

/**
 * Print a harness message to stderr.
 *
 * @param string $message Message.
 * @return void
 */
function g11_say( $message ) {
	fwrite( STDERR, '[g11] ' . $message . "\n" );
}

/**
 * Parse `--key=value` arguments.
 *
 * @param array $argv Raw argv.
 * @return array<string,string>
 */
function g11_args( array $argv ) {
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
 * Resolve a source id to its REAL registry configuration.
 *
 * The harness measures the shipped configuration, never a hand-written URL, so
 * a measured URL cannot drift from the one production would use.
 *
 * The G8 date-window ids (`eventbrite_<county>_events_{today,tomorrow,
 * this_week}`) were retired on 2026-09-10 and no longer resolve: the registry
 * no longer generates them, so the harness refuses them instead of measuring a
 * source the importer would never import.
 *
 * @param string $source_id Source id, e.g. `eventbrite_cork`.
 * @return array|null Source config, or null when unknown.
 */
function g11_resolve_source( $source_id ) {
	if ( preg_match( '/^eventbrite_[a-z]+_events_(today|tomorrow|this_week)$/', (string) $source_id ) ) {
		g11_say( sprintf(
			'%s is a RETIRED Eventbrite date-window source (removed 2026-09-10); it is no longer part of the registry',
			$source_id
		) );

		return null;
	}

	$all = Conexao_County_Registry::get_all_eventbrite_sources();
	if ( isset( $all[ $source_id ] ) ) {
		return $all[ $source_id ];
	}

	return null;
}

/**
 * Walk one source and capture a single observation.
 *
 * Read-only by construction: it drives `Conexao_Eventbrite_Client::fetch_page()`
 * (the production transport, so its retry/backoff/pacing all apply) and
 * `Conexao_Eventbrite_Parser::parse()` (the production parser).
 *
 * Only the fields the measurement needs are retained — never a response body,
 * never a description, never a ticket URL. Titles are hashed, venues are
 * hashed, so the evidence stays reproducible without carrying content.
 *
 * @param array  $config       Source config from the registry.
 * @param string $observation_id Observation identifier.
 * @param int    $max_pages    Hard page ceiling for this observation.
 * @return array Normalised observation.
 */
function g11_observe( array $config, $observation_id, $max_pages ) {
	$source_id = (string) $config['id'];
	$base_url  = (string) $config['url'];

	$parser = new Conexao_Eventbrite_Parser();

	// Reuse the production pacing policy so the G5 rate-limit protections are
	// honoured exactly as an import run would honour them.
	$pacing = new Conexao_Eventbrite_Pacing();
	$client = new Conexao_Eventbrite_Client( $source_id, $pacing );

	$started     = microtime( true );
	$requests    = 0;
	$events      = array();
	$page_count  = 0;
	$object_count = 0;
	$failure     = '';
	$stopped_at  = 0;
	$pages_walked = 0;

	for ( $page = 1; $page <= (int) $max_pages; $page++ ) {
		++$requests;

		$html = $client->fetch_page( $base_url, $page );

		if ( '' === $html || null === $html ) {
			// Classify exactly why, and stop. A 429 or a WAF is an OBSERVATION,
			// never something to work around for a bigger sample.
			$failure = eb_vol_classify_failure( $client->get_last_http_status(), $client->get_last_failure_reason() );
			$stopped_at = $page;
			g11_say( sprintf( 'stopped at page %d: failure_class=%s http=%d', $page, $failure, (int) $client->get_last_http_status() ) );
			break;
		}

		try {
			$parsed = $parser->parse( $html );
		} catch ( Exception $e ) {
			$failure    = 'parse_failed';
			$stopped_at = $page;
			g11_say( sprintf( 'stopped at page %d: parse_failed (%s)', $page, $e->getMessage() ) );
			break;
		}

		if ( 1 === $page ) {
			$page_count   = isset( $parsed['pagination']['page_count'] ) ? (int) $parsed['pagination']['page_count'] : 0;
			$object_count = isset( $parsed['pagination']['object_count'] ) ? (int) $parsed['pagination']['object_count'] : 0;
			g11_say( sprintf( 'declared pages=%d objects=%d', $page_count, $object_count ) );
		}

		$position = 0;
		foreach ( $parsed['events'] as $raw ) {
			++$position;

			$event_id = isset( $raw['id'] ) ? (string) $raw['id'] : '';
			if ( '' === $event_id ) {
				continue;
			}

			$events[] = array(
				'event_id'   => $event_id,
				'page'       => $page,
				'position'   => $position,
				// One hash over every tracked field, so a change in ANY of them
				// registers as a changed event without storing the values.
				'field_hash' => g11_field_hash( $raw ),
			);
		}

		$pages_walked = $page;

		if ( $page_count > 0 && $page >= $page_count ) {
			break;
		}
	}

	$elapsed = microtime( true ) - $started;

	$complete = ( '' === $failure && $pages_walked > 0 );

	// G8's upstream-truncation fact: a listing that declares more objects than
	// its pagination can expose has not been fully seen, even when every
	// declared page was walked.
	$truncated = ( $page_count > 0 && $object_count > ( $page_count * 20 ) );

	return array(
		'source'             => $source_id,
		'url'                => $base_url,
		'observation_id'     => $observation_id,
		'timestamp'          => gmdate( 'c' ),
		'complete'           => $complete,
		'failure_class'      => $failure,
		'http_status'        => (int) $client->get_last_http_status(),
		'stopped_at_page'    => $stopped_at,
		'pages_walked'       => $pages_walked,
		'declared_pages'     => $page_count,
		'declared_objects'   => $object_count,
		'requests'           => $requests,
		'elapsed_seconds'    => round( $elapsed, 2 ),
		'truncated_upstream' => $truncated,
		'events'             => $events,
	);
}

/**
 * Hash every tracked field of one raw Eventbrite event.
 *
 * G11 §8 "changed events" tracks title, date/time, venue, URL, image,
 * description and publication state. Hashing rather than storing keeps the
 * evidence small and free of content.
 *
 * @param array $raw Raw Eventbrite event.
 * @return string
 */
function g11_field_hash( array $raw ) {
	$venue = '';
	if ( isset( $raw['primary_venue']['name'] ) ) {
		$venue = (string) $raw['primary_venue']['name'];
	} elseif ( isset( $raw['locations'][0]['address']['address'] ) ) {
		$venue = (string) $raw['locations'][0]['address']['address'];
	}

	$parts = array(
		isset( $raw['name'] ) ? (string) $raw['name'] : '',
		isset( $raw['start_date'] ) ? (string) $raw['start_date'] : '',
		isset( $raw['start_time'] ) ? (string) $raw['start_time'] : '',
		isset( $raw['end_date'] ) ? (string) $raw['end_date'] : '',
		isset( $raw['url'] ) ? (string) $raw['url'] : '',
		isset( $raw['image_id'] ) ? (string) $raw['image_id'] : '',
		isset( $raw['summary'] ) ? md5( (string) $raw['summary'] ) : '',
		isset( $raw['published'] ) ? (string) (int) $raw['published'] : '',
		isset( $raw['is_cancelled'] ) ? (string) (int) $raw['is_cancelled'] : '',
		$venue,
	);

	return md5( implode( '|', $parts ) );
}
/**
 * Run the harness once: observe one source, write one observation file.
 *
 * @param array $args Parsed CLI arguments.
 * @return int Process exit code.
 */
function g11_main( array $args ) {
	$source_id = isset( $args['source'] ) ? (string) $args['source'] : '';
	$label     = isset( $args['label'] ) ? (string) $args['label'] : '';
	$max_pages = isset( $args['max-pages'] ) ? (int) $args['max-pages'] : 8;

	// A deliberately small default. The whole point of G11 is to observe
	// movement cheaply and repeatedly; 8 pages at Eventbrite's measured page
	// size of 20 covers 160 events, far more than a boundary needs, while
	// staying well inside the ~31-request 429 wall G8 measured.
	$max_pages = max( 1, min( 50, $max_pages ) );

	$evidence_dir = isset( $args['evidence-dir'] )
		? rtrim( (string) $args['evidence-dir'], '/' )
		: dirname( CONEXAO_TESTS_WP_ROOT ) . '/docs/reports/evidence/eventbrite-g11/observations';

	if ( '' === $source_id ) {
		g11_say( 'missing --source=<registry source id>' );
		return 2;
	}

	if ( '' === $label ) {
		g11_say( 'missing --label=<observation label>' );
		return 2;
	}

	$config = g11_resolve_source( $source_id );
	if ( ! $config ) {
		g11_say( sprintf( 'unknown source "%s" — refusing to invent a URL', $source_id ) );
		return 2;
	}

	/*
	 * MEASUREMENT INTEGRITY (G11 §27).
	 *
	 * The harness must not perturb — or be perturbed by — importer state. It
	 * snapshots the shared options before observing and restores them after,
	 * so an observation can never leave a checkpoint, a cooldown or a log entry
	 * behind, and can never inherit one from an earlier run.
	 *
	 * Note the ordering: the restore runs in a shutdown handler AND explicitly,
	 * because the process may exit through the early `return` paths above.
	 */
	$options = array(
		Conexao_Import_History::OPTION_KEY,
		Conexao_Import_Log::OPTION_KEY,
		Conexao_Event_Sources::OPTION_KEY,
		'conexao_event_source_health',
		Conexao_Source_Cooldown::OPTION_KEY,
		Conexao_Source_Enumeration_Checkpoint::OPTION_KEY,
	);

	$snapshot = test_options_isolate( $options );

	$restored = false;
	$restore  = static function () use ( $snapshot, &$restored ) {
		if ( $restored ) {
			return;
		}
		$restored = true;
		test_options_restore( $snapshot, true );
	};
	register_shutdown_function( $restore );

	g11_say( sprintf( 'observing %s (%s), max %d pages', $source_id, $config['url'], $max_pages ) );

	$observation_id = $source_id . ':' . $label;

	$observation = g11_observe( $config, $observation_id, $max_pages );

	// Normalise through the shared library so the stored evidence is exactly
	// the shape the comparison consumes, with completeness applied.
	$stored = eb_vol_normalise_observation( $observation );

	// Preserve the operational facts the comparison does not need but the
	// report must cite.
	$stored['elapsed_seconds'] = $observation['elapsed_seconds'];
	$stored['http_status']     = $observation['http_status'];
	$stored['stopped_at_page'] = $observation['stopped_at_page'];
	$stored['url']             = $observation['url'];

	if ( ! is_dir( $evidence_dir ) ) {
		wp_mkdir_p( $evidence_dir );
	}

	$slug     = preg_replace( '/[^a-z0-9_.-]+/', '_', strtolower( $source_id . '-' . $label ) );
	$filename = $evidence_dir . '/' . $slug . '.json';

	$json = wp_json_encode( $stored, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	file_put_contents( $filename, $json );

	g11_say( sprintf(
		'wrote %s — events=%d pages=%d requests=%d elapsed=%.1fs complete=%s failure=%s',
		$filename,
		count( $stored['events'] ),
		$stored['pages_walked'],
		$stored['requests'],
		$observation['elapsed_seconds'],
		$stored['complete'] ? 'yes' : 'no',
		'' === $stored['failure_class'] ? 'none' : $stored['failure_class']
	) );

	$restore();

	return 0;
}

exit( g11_main( g11_args( $argv ) ) );