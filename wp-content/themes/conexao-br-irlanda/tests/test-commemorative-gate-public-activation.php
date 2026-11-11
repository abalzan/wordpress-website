<?php
/**
 * PERMANENT GATE (b) — no public activation through query parameters.
 *
 * Invariant (r10 §5.4, §7.10, §9.5b): an unauthenticated public request can never
 * activate an event or force a package by supplying a query parameter. The only
 * activation path is the signed preview, which requires a logged-in user with
 * `manage_options`, `edit_post` on the canonical record AND a valid per-key nonce.
 *
 * ## The injected negative proof
 *
 * The gate proves BOTH directions:
 *   - the forbidden condition (an anonymous request with a preview key + nonce)
 *     resolves to no event — the violation count must be 0;
 *   - the authorised condition (an administrator with a valid nonce) DOES resolve
 *     the event — and the gate fails if it does not, because then the check could
 *     not tell "authorised" from "not authorised" and would be vacuous.
 *
 * Read-only: it sets `$_GET`, switches the current user in-process and restores
 * both. Nothing is persisted and no real request is made.
 *
 * @package Conexao_BR_Irlanda
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_ROOT . '/lib/permanent-gates.php';

conexao_gate_open(
	'commemorative_no_public_activation',
	'A public request cannot activate a commemorative event through a query parameter; the signed preview still works for an authorised administrator.',
	array( 'conexao-commemorative plugin active', 'an administrator account' )
);

$saved_get  = $_GET;
$saved_user = get_current_user_id();

/**
 * Ask the engine whether the current request activated a preview.
 *
 * @param array $query Query parameters to simulate.
 * @return bool
 */
function conexao_gate_preview_activates( array $query ) {
	$_GET = $query;

	Conexao_Commemorative_Preview::reset();

	$active = Conexao_Commemorative_Preview::is_active();

	Conexao_Commemorative_Preview::reset();
	$_GET = array();

	return $active;
}

test_section( 'Anonymous request with a preview key and a bogus nonce' );

wp_set_current_user( 0 );

$anonymous = array();

if ( conexao_gate_preview_activates( array( 'conexao_preview' => 'halloween', '_wpnonce' => 'deadbeefdead', '_ce_ts' => '1' ) ) ) {
	$anonymous[] = 'preview:activation_without_session';
}

if ( conexao_gate_preview_activates( array( 'ce_package' => 'halloween', 'ce_placement' => 'homepage_hero' ) ) ) {
	$anonymous[] = 'package:activation_by_query';
}

// The dispatcher must not be activated by a bare query parameter either.
$_GET = array( 'ce_package' => 'halloween' );
conexao_commemorative_flush_plan();
$query_plan = conexao_commemorative_build_plan();
$_GET   = array();

if ( ! empty( $query_plan['package'] ) || ! empty( $query_plan['placements'] ) ) {
	$anonymous[] = 'dispatcher:activated_by_query';
}

conexao_gate_violation(
	'public_activation:anonymous',
	count( $anonymous ),
	'an unauthenticated request cannot activate an event or a package through a query parameter',
	array( 'paths' => $anonymous )
);

test_section( 'Authorised administrator with a valid nonce (injected positive proof)' );

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);

conexao_gate_require(
	! empty( $admins ),
	'administrator-account',
	'the gate prerequisite is available: an administrator account'
);

wp_set_current_user( (int) $admins[0] );

$event = Conexao_Commemorative_Repository::find_by_event_key( 'halloween' );

conexao_gate_require(
	null !== $event,
	'seed-commemorative-events',
	'the gate prerequisite is available: the halloween event record'
);

$nonce = wp_create_nonce( Conexao_Commemorative_Preview::nonce_action( 'halloween' ) );

$authorised = conexao_gate_preview_activates(
	array(
		'conexao_preview' => 'halloween',
		'_wpnonce'        => $nonce,
		'_ce_ts'          => (string) time(),
	)
);

conexao_gate_violation(
	'gate:injected_positive_not_detected',
	$authorised ? 0 : 1,
	'the gate can detect AUTHORISED activation (the signed preview still works for an administrator)'
);

// A valid session with a WRONG nonce must still be refused.
wp_set_current_user( (int) $admins[0] );

$wrong_nonce = conexao_gate_preview_activates(
	array(
		'conexao_preview' => 'halloween',
		'_wpnonce'        => 'deadbeefdead',
	)
);

conexao_gate_violation(
	'public_activation:bad_nonce',
	$wrong_nonce ? 1 : 0,
	'an administrator session with an invalid nonce is still refused'
);

// Restore the request state.
$_GET = $saved_get;
wp_set_current_user( (int) $saved_user );
Conexao_Commemorative_Preview::reset();
conexao_commemorative_flush_plan();

conexao_gate_close();
