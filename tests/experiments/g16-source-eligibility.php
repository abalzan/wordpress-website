<?php
/**
 * G16 — READ-ONLY source eligibility probe.
 *
 * ## What this is
 *
 * The evidence half of G16. Before anything is activated, every source class
 * that no earlier stage measured is probed live, read-only, to decide whether
 * it is *supported, configured, validated and safe to activate*.
 *
 * "It exists in the registry" is explicitly NOT eligibility (G16 §1). This
 * script produces the numbers the activation matrix cites.
 *
 * ## Safety contract
 *
 * READ-ONLY. It never activates a source, never runs the import engine, never
 * writes a post, taxonomy, image or option. It:
 *   - refuses to run unless the environment is positively non-production
 *     (same three-signal guard as the G14 harness, no --force bypass);
 *   - fetches only PUBLIC listing pages of already-registered sources;
 *   - never guesses an endpoint, so an unconfigured source is reported
 *     UNCONFIGURED rather than probed against an invented URL (G16 §9).
 *
 * ## Usage
 *
 *     docker compose exec -T wordpress php /var/www/html/tests/experiments/g16-source-eligibility.php
 *
 * @package Conexao_Event_Importer
 */

require_once dirname( __DIR__, 2 ) . '/tests/bootstrap.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

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
 * Parse an HTML string into a DOMDocument through the base handler.
 *
 * Reuses `Conexao_Source_Base::parse_html()` so the probe parses exactly the
 * way the importer does (same libxml options), rather than with a second,
 * possibly divergent, parser.
 *
 * @param string $html Raw HTML.
 * @return DOMDocument|null DOM document, or null when unparseable.
 */
function g16_parse_html( $html ) {
	// Conexao_Source_Base is abstract, so borrow the inherited parse_html()
	// from a concrete handler rather than inventing a second parser.
	$base = new Conexao_Source_Heritage_Week( array( 'id' => 'g16_probe', 'url' => '' ) );

	$ref = new ReflectionMethod( 'Conexao_Source_Base', 'parse_html' );
	$ref->setAccessible( true );

	$dom = $ref->invoke( $base, (string) $html );

	return $dom instanceof DOMDocument ? $dom : null;
}

/**
 * Prove the environment is non-production, or refuse to run.
 *
 * Fails closed on the same three signals the G14 harness uses, through the
 * repository's own classifier rather than a re-implementation.
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
			g16_say( 'REFUSING TO PROBE: ' . $name . ' (' . $url . ') is the PRODUCTION host.' );
			exit( 3 );
		}

		$class = conexao_script_classify_target( $url );

		if ( ! in_array( $class, array( 'local', 'staging' ), true ) ) {
			g16_say( 'REFUSING TO PROBE: ' . $name . ' (' . $url . ') classifies as "' . $class . '", not local/staging.' );
			exit( 3 );
		}

		$classes[ $name ] = $class;
	}

	$env_type = function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : 'unknown';

	if ( ! in_array( $env_type, array( 'local', 'staging' ), true ) ) {
		g16_say( 'REFUSING TO PROBE: environment type is "' . $env_type . '", not local/staging.' );
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

$proof = g16_assert_non_production();

$sources = new Conexao_Event_Sources();
$all     = $sources->get_all();

g16_say( 'registry_total=' . count( $all ) );

/*
 * Group the registry into the classes G16 must decide about. The classes are
 * derived from the SOURCE ID SHAPE (which is what the registry generates),
 * never from a hard-coded list, so a new county automatically joins its class
 * and the matrix can never silently omit a source.
 */
$classes = array(
	'legacy_default'       => array(),
	'eventbrite_county'    => array(),
	'heritage_week_county' => array(),
	'eb_window'            => array(),
);

foreach ( $all as $id => $source ) {
	if ( preg_match( '/^eventbrite_[a-z]+_events_(today|tomorrow|this_week)$/', $id ) ) {
		$classes['eb_window'][] = $id;
	} elseif ( preg_match( '/^eventbrite_[a-z]+$/', $id ) ) {
		$classes['eventbrite_county'][] = $id;
	} elseif ( preg_match( '/^heritage_week_[a-z]+$/', $id ) ) {
		$classes['heritage_week_county'][] = $id;
	} else {
		$classes['legacy_default'][] = $id;
	}
}

foreach ( $classes as $class => $ids ) {
	g16_say( 'class ' . $class . ' = ' . count( $ids ) );
}
g16_say( '--- configured / unconfigured census (G16 §5) ---' );

$unconfigured = array();

foreach ( $all as $id => $source ) {
	$url = isset( $source['url'] ) ? trim( (string) $source['url'] ) : '';

	if ( '' === $url ) {
		$unconfigured[] = $id;
	}
}

g16_say( 'sources_with_url=' . ( count( $all ) - count( $unconfigured ) ) . ' unconfigured=' . count( $unconfigured ) );

if ( ! empty( $unconfigured ) ) {
	g16_say( 'unconfigured_ids=' . implode( ',', $unconfigured ) );
}

/*
 * Live probe: reachability + parse-ability of ONE representative page per
 * class, through the SAME parser the importer uses.
 *
 * This deliberately probes page 1 only. It answers "is this source reachable
 * and does it parse?", which is what eligibility needs. It does NOT walk a
 * listing, because a walk is the import's job, not the matrix's.
 */
g16_say( '--- live page-1 probes (read-only) ---' );

$probes = array(
	// Eventbrite all-events listing (the primary county coverage class).
	'eventbrite_county'    => $classes['eventbrite_county'] ? $all[ $classes['eventbrite_county'][0] ] : null,
	// Eventbrite this-week window (the G13 ACTIVATE class).
	'eb_window'            => isset( $all['eventbrite_cork_events_this_week'] ) ? $all['eventbrite_cork_events_this_week'] : null,
	// Heritage Week county listing (never classified by any prior stage).
	'heritage_week_county' => $classes['heritage_week_county'] ? $all[ $classes['heritage_week_county'][0] ] : null,
	// The legacy Laois iCalendar source (already active).
	'legacy_icalendar'     => isset( $all['laois_tourism'] ) ? $all['laois_tourism'] : null,
	// Venue sources that ship inactive by documented gate.
	'ivvcc'                => isset( $all['ivvcc'] ) ? $all['ivvcc'] : null,
	'motorsport_ireland'   => isset( $all['motorsport_ireland'] ) ? $all['motorsport_ireland'] : null,
	'mondello_park'        => isset( $all['mondello_park'] ) ? $all['mondello_park'] : null,
);

$results = array();

foreach ( $probes as $label => $source ) {
	if ( ! $source ) {
		g16_say( sprintf( '%-22s ABSENT from registry', $label ) );
		$results[ $label ] = array( 'probe' => 'absent' );
		continue;
	}

	$url      = isset( $source['url'] ) ? (string) $source['url'] : '';

	/*
	 * Use the handler's OWN User-Agent (via Conexao_Source_Base) rather than
	 * a hard-coded one. Measured 2026-10-05: laoistourism.ie answers HTTP 403
	 * to Chrome/120 and HTTP 200 to the Chrome/126 UA the base class actually
	 * sends, so a probe that invents its own UA reports a FALSE negative for a
	 * perfectly healthy source.
	 */
	$ua_source = new Conexao_Source_Heritage_Week( array( 'id' => 'g16_probe', 'url' => '' ) );

	$ua_ref = new ReflectionMethod( 'Conexao_Source_Base', 'get_http_user_agent' );
	$ua_ref->setAccessible( true );
	$user_agent = (string) $ua_ref->invoke( $ua_source );

	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => 30,
			'redirection' => 3,
			'user-agent'  => $user_agent,
		)
	);

	if ( is_wp_error( $response ) ) {
		g16_say( sprintf( '%-22s ERROR %s', $label, $response->get_error_message() ) );
		$results[ $label ] = array(
			'probe'   => 'error',
			'message' => $response->get_error_message(),
			'url'     => $url,
		);
		continue;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = (string) wp_remote_retrieve_body( $response );

	$parsed  = 0;
	$objects = 0;
	$pages   = 0;

	if ( 200 === $code && '' !== $body ) {
		if ( 'eventbrite' === ( $source['type'] ?? '' ) ) {
			$parser = new Conexao_Eventbrite_Parser();
			$page   = $parser->parse( $body );

			if ( is_array( $page ) ) {
				$parsed  = isset( $page['events'] ) && is_array( $page['events'] ) ? count( $page['events'] ) : 0;
				$objects = isset( $page['pagination']['object_count'] ) ? (int) $page['pagination']['object_count'] : 0;
				$pages   = isset( $page['pagination']['page_count'] ) ? (int) $page['pagination']['page_count'] : 0;
			}
		} elseif ( 'heritage_week' === ( $source['type'] ?? '' ) ) {
			// Count listing cards with the handler's OWN selector, so this
			// probe measures reachability of the real parse target rather
			// than a guessed one.
			$dom = g16_parse_html( $body );

			if ( $dom ) {
				$xpath = new DOMXPath( $dom );
				$cards = $xpath->query( '//article[contains(concat(" ", normalize-space(@class), " "), " item-summary ")]' );

				$parsed = ( $cards && $cards->length > 0 ) ? $cards->length : 0;
			}
		} else {
			// A non-empty body shows the page is served; per-handler parse
			// depth is covered by each handler's own suite.
			$parsed = ( '' !== trim( $body ) ) ? 1 : 0;
		}
	}

	g16_say(
		sprintf(
			'%-22s http=%d bytes=%d parsed=%d objects=%d pages=%d url=%s',
			$label,
			$code,
			strlen( $body ),
			$parsed,
			$objects,
			$pages,
			$url
		)
	);

	$results[ $label ] = array(
		'probe'   => 'ok',
		'http'    => $code,
		'bytes'   => strlen( $body ),
		'parsed'  => $parsed,
		'objects' => $objects,
		'pages'   => $pages,
		'type'    => $source['type'] ?? '',
		'url'     => $url,
	);
}

g16_say( '--- G7 API-token retirement re-check ---' );

global $wpdb;

$token_options = $wpdb->get_col(
	"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%eventbrite%'"
);

g16_say( 'eventbrite_options_in_db=' . count( (array) $token_options ) );

$token_refs = array();

foreach ( (array) glob( WP_PLUGIN_DIR . '/conexao-event-importer/*.php' ) as $file ) {
	if ( false !== strpos( (string) file_get_contents( $file ), 'eventbrite_api_token' ) ) {
		$token_refs[] = basename( $file );
	}
}

g16_say( 'executable_files_referencing_eventbrite_api_token=' . count( $token_refs ) );

g16_say( '--- checkpoint / cooldown census ---' );

$checkpoints = get_option( Conexao_Source_Enumeration_Checkpoint::OPTION_KEY, array() );
$cooldown    = get_option( Conexao_Source_Cooldown::OPTION_KEY, array() );

g16_say( 'checkpoints=' . count( (array) $checkpoints ) . ' cooldown_records=' . count( (array) $cooldown ) );

$evidence = array(
	'environment'           => $proof,
	'registry_total'        => count( $all ),
	'class_counts'          => array_map( 'count', $classes ),
	'unconfigured_ids'      => $unconfigured,
	'probes'                => $results,
	'eventbrite_db_options' => count( (array) $token_options ),
	'token_file_refs'       => $token_refs,
	'checkpoints'           => count( (array) $checkpoints ),
	'cooldown_records'      => count( (array) $cooldown ),
);

$out = dirname( __DIR__, 2 ) . '/docs/evidence/2026-10-05-eventbrite-g16/01-source-eligibility.json';

if ( ! is_dir( dirname( $out ) ) ) {
	wp_mkdir_p( dirname( $out ) );
}

file_put_contents( $out, wp_json_encode( $evidence, JSON_PRETTY_PRINT ) . "\n" );

g16_say( 'wrote ' . $out );