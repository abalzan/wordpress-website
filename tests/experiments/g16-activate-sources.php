<?php
/**
 * G16 — activate the evidence-backed eligible event sources.
 *
 * ## What this is
 *
 * The write half of G16. It flips source status through
 * `Conexao_Event_Sources::set_status()` — the SAME method the wp-admin
 * **Enable** button calls — for exactly the classes the activation matrix
 * classifies `ENABLE`, and for nothing else.
 *
 * ## Why activation is data, not code
 *
 * `set_status()` writes the `conexao_event_sources` option. The registry's
 * shipped defaults stay `inactive` in code, so this stage adds no second
 * source of truth and changes no default: activation is an operator/runtime
 * decision, exactly as `class-event-sources.php` documents ("registering a
 * source never starts an import"). The authoritative record of WHICH classes
 * are eligible lives in the report's activation matrix.
 *
 * ## The class list is the whole decision
 *
 * ENABLED here, and only here:
 *   - `eventbrite_<county>`                   26 county all-events listings
 *   - `heritage_week_<county>`                26 county Heritage Week listings
 *   - `eventbrite_<county>_events_this_week`  26 this-week windows
 *
 * DELIBERATELY LEFT INACTIVE:
 *   - `eventbrite_<county>_events_today`      G13 REJECT (0.20 events/request)
 *   - `eventbrite_<county>_events_tomorrow`   G13 DEFER (0 change / 9 rounds)
 *   - `ivvcc`, `motorsport_ireland`, `mondello_park`  documented operator gate
 *
 * ## Safety contract
 *
 * Refuses to run unless the environment is positively non-production, and
 * takes a full pre-change snapshot of the registry option before writing so
 * the change is reversible.
 *
 * ## Usage
 *
 *     docker compose exec -T wordpress php /var/www/html/tests/experiments/g16-activate-sources.php [--dry-run]
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
 * Prove the environment is non-production, or refuse to run.
 *
 * Fails closed on the same three signals the G14 harness uses, through the
 * repository's own classifier.
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
			g16_say( 'REFUSING TO ACTIVATE: ' . $name . ' (' . $url . ') is the PRODUCTION host.' );
			exit( 3 );
		}

		$class = conexao_script_classify_target( $url );

		if ( ! in_array( $class, array( 'local', 'staging' ), true ) ) {
			g16_say( 'REFUSING TO ACTIVATE: ' . $name . ' (' . $url . ') classifies as "' . $class . '", not local/staging.' );
			exit( 3 );
		}

		$classes[ $name ] = $class;
	}

	$env_type = function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : 'unknown';

	if ( ! in_array( $env_type, array( 'local', 'staging' ), true ) ) {
		g16_say( 'REFUSING TO ACTIVATE: environment type is "' . $env_type . '", not local/staging.' );
		exit( 3 );
	}

	g16_say( 'non-production confirmed: home=' . $home_url . ' env=' . $env_type );

	return array(
		'home_url' => $home_url,
		'target'   => $target,
		'env_type' => $env_type,
		'classes'  => $classes,
	);
}

$dry_run = in_array( '--dry-run', (array) $argv, true );

$proof = g16_assert_non_production();

$sources = new Conexao_Event_Sources();
$before  = $sources->get_all();

$active_before = array();

foreach ( $before as $id => $source ) {
	if ( 'active' === ( $source['status'] ?? '' ) ) {
		$active_before[] = $id;
	}
/*
 * The activation rule, expressed as ONE predicate so the matrix and the code
 * cannot drift. Every source id is classified by shape; there is no
 * hand-maintained per-source list that could forget a county.
 *
 * G16 §6/§7: `events_today` and `events_tomorrow` are matched but explicitly
 * NOT enabled, and the venue sources are never enabled.
 */
$enable = array();
$keep   = array();

foreach ( $before as $id => $source ) {
	if ( preg_match( '/_events_today$/', $id ) ) {
		$keep[ $id ] = 'G13 REJECT (0.20 events/request, calendar-driven)';
	} elseif ( preg_match( '/_events_tomorrow$/', $id ) ) {
		$keep[ $id ] = 'G13 DEFER (0 changes across 9 comparisons; subsumed by this-week)';
	} elseif ( in_array( $id, array( 'ivvcc', 'motorsport_ireland', 'mondello_park' ), true ) ) {
		$keep[ $id ] = 'documented operator-confirmation gate (docs/importers/*)';
	} elseif ( preg_match( '/^eventbrite_[a-z]+_events_this_week$/', $id ) ) {
		$enable[ $id ] = 'G13 ACTIVATE class: this-week window, complete + incremental';
	} elseif ( preg_match( '/^eventbrite_[a-z]+$/', $id ) ) {
		$enable[ $id ] = 'primary county coverage; truncation-guarded, incomplete-safe';
	} elseif ( preg_match( '/^heritage_week_[a-z]+$/', $id ) ) {
		$enable[ $id ] = 'county Heritage Week listing, reachable + parses (G16 probe)';
	} else {
		// The legacy defaults keep whatever status they already have; this
		// stage must not silently enable or disable them.
		$keep[ $id ] = 'legacy default: status preserved unchanged';
	}
}

g16_say( 'to_enable=' . count( $enable ) . ' to_keep=' . count( $keep ) );

$changed   = array();
$unchanged = array();

foreach ( $enable as $id => $reason ) {
	$current = $before[ $id ]['status'] ?? '';

	if ( 'active' === $current ) {
		$unchanged[] = $id;
		continue;
	}

	if ( ! $dry_run ) {
		$sources->set_status( $id, 'active' );
	}

	$changed[ $id ] = array(
		'from'   => $current,
		'to'     => 'active',
		'reason' => $reason,
	);
}

g16_say( 'changed=' . count( $changed ) . ' already_active=' . count( $unchanged ) );

$after  = $sources->get_all();
$active = array();

foreach ( $after as $id => $source ) {
	if ( 'active' === ( $source['status'] ?? '' ) ) {
		$active[] = $id;
	}
}

g16_say( 'active_after=' . count( $active ) );

/*
 * Invariant: a source G16 deliberately kept disabled must still be disabled.
 * This is asserted here so a rule change cannot silently activate a rejected
 * source.
 */
$leaked = array();

foreach ( $keep as $id => $reason ) {
	if ( false !== strpos( $reason, 'G13' ) || false !== strpos( $reason, 'operator-confirmation' ) ) {
		if ( 'active' === ( $after[ $id ]['status'] ?? '' ) ) {
			$leaked[] = $id;
		}
	}
}

g16_say( 'rejected_or_deferred_now_active=' . count( $leaked ) );

if ( ! empty( $leaked ) ) {
	g16_say( 'ABORT: a rejected/deferred/gated source became active: ' . implode( ',', $leaked ) );
	exit( 4 );
}

$evidence_dir = dirname( __DIR__, 2 ) . '/docs/evidence/2026-10-05-eventbrite-g16';

if ( ! is_dir( $evidence_dir ) ) {
	wp_mkdir_p( $evidence_dir );
}

/*
 * The pre-change snapshot is the rollback artifact and must be captured ONCE.
 *
 * It is deliberately NOT overwritten on a later run: this script is
 * idempotent, so re-running it after a successful activation would otherwise
 * capture the POST-activation state and silently destroy the only record of
 * what to roll back to. Measured 2026-10-05: that is exactly what happened on
 * the verification re-run, so the guard is load-bearing.
 */
$snapshot_path = $evidence_dir . '/02-registry-before-activation.json';

if ( file_exists( $snapshot_path ) ) {
	g16_say( 'rollback snapshot already exists — NOT overwritten (preserving the true pre-change state)' );
} else {
	file_put_contents( $snapshot_path, wp_json_encode( $before, JSON_PRETTY_PRINT ) . "\n" );
	g16_say( 'wrote 02-registry-before-activation.json (rollback snapshot)' );
}

$report = array(
	'environment'    => $proof,
	'dry_run'        => $dry_run,
	'total'          => count( $before ),
	'active_before'  => $active_before,
	'active_after'   => $active,
	'enable_rule'    => $enable,
	'keep_rule'      => $keep,
	'changed'        => $changed,
	'already_active' => $unchanged,
	'leaked'         => $leaked,
);

file_put_contents( $evidence_dir . '/03-activation.json', wp_json_encode( $report, JSON_PRETTY_PRINT ) . "\n" );

g16_say( 'wrote 02-registry-before-activation.json (rollback snapshot)' );
g16_say( 'wrote 03-activation.json' );
}

g16_say( 'registry_total=' . count( $before ) . ' active_before=' . count( $active_before ) );
g16_say( 'dry_run=' . ( $dry_run ? 'yes' : 'no' ) );