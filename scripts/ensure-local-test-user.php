<?php
/**
 * File doc comment: ensure-local-test-user.php
 *
 * Purpose: recreate the local Docker test account and its REST application
 * password, which a production database restore removes.
 *
 * Scope: exactly one account, the one named by WP_USERNAME. Local only.
 *
 * Safety: local-write. Dry-run by default; --apply performs the writes.
 * Credentials are read from the environment and are never printed.
 *
 * ## Why this exists
 *
 * `scripts/restore-updraft-db.sh` DROPs and recreates the local `wordpress`
 * database from a production UpdraftPlus dump. A production dump contains only
 * production users, and production accounts have no application passwords, so
 * after every restore the local development account documented in `.env`
 * (`WP_USERNAME` / `WP_APPLICATION_PASSWORD`) is gone. Every Basic-auth REST
 * call then fails with 401 `rest_not_logged_in`, which takes down the Python
 * `scripts/*-rest.py` tooling and the HTTP acceptance suites
 * (`./scripts/run-tests.sh --acceptance`).
 *
 * This script recreates that account idempotently. It is the documented
 * post-restore step: restore the database, then run this.
 *
 * ## What it guarantees
 *
 *   1. A user with the login `WP_USERNAME` (default `test`) exists, is an
 *      administrator, and its login password is `WP_TEST_USER_PASSWORD`
 *      (default `test`), so `wp-login.php` works in the browser.
 *   2. That user owns an application password equal to
 *      `WP_APPLICATION_PASSWORD`, so `scripts/lib/rest.py` — which base64
 *      encodes `user:password` itself — authenticates without editing `.env`.
 *   3. `is_ssl()` is not required: WordPress only serves the application
 *      password REST path over plain HTTP when `wp_get_environment_type()`
 *      is `local`, which `compose.yaml` sets via `WORDPRESS_CONFIG_EXTRA`.
 *
 * ## Safety
 *
 * LOCAL ONLY, and it refuses to run against production or any unclassified
 * target. It only ever touches the single account named by `WP_USERNAME`; it
 * never enumerates, modifies or deletes any other user. Dry-run is the default
 * and performs zero writes.
 *
 * Credentials are read from the ENVIRONMENT only (never a command-line value,
 * never printed). The application password is stored hashed, exactly as
 * `WP_Application_Passwords` stores it.
 *
 * @package Conexao_BR_Scripts
 */

require_once __DIR__ . '/lib/bootstrap.php';

$ctx = conexao_script_boot(
	array(
		'script'             => 'ensure-local-test-user.php',
		'purpose'            => 'Recreate the local Docker test account (and its REST application password) that a production database restore removes, so scripts/lib/rest.py and the HTTP acceptance suites can authenticate again.',
		'scope'              => 'Exactly one account, the one named by WP_USERNAME. Creates it if missing, otherwise repairs its role, login password and application password. Never touches any other user. Dry-run by default; --apply performs the writes.',
		'safety'             => 'local-only (refuses production and unknown targets); dry-run by default; --apply required; reads credentials from the environment and never prints them',
		'target_description' => 'the local WordPress install (the site URL is printed in the header)',
		'modes_description'  => '--dry-run (default) reports what is missing and writes nothing. --apply creates or repairs the account. --json emits the machine-readable result. --help prints this message.',
		'arguments'          => "--dry-run   Report only, zero writes (default).\n"
			. "                --apply     Create or repair the account.\n"
			. "                --json      Emit the machine-readable result.\n"
			. '                --help      This message.',
		'writes'             => true,
		'read_only'          => false,
		// Local-only by design. `production_capable` stays false so the
		// bootstrap never offers a --confirm-production escape hatch; the
		// explicit guard below rejects production and unknown outright.
		'production_capable' => false,
	)
);

// ---------------------------------------------------------------------------
// Local-only guard. A production install has real accounts with real
// passwords; this script must never be pointed at one.
// ---------------------------------------------------------------------------

if ( 'local' !== $ctx['target_class'] ) {
	conexao_script_fail(
		sprintf(
			'refusing to run: target "%s" is classified as "%s". This script manages a '
			. 'throwaway local development account and must only ever run against the '
			. 'local Docker stack. It is refused on production and on any unclassified '
			. 'target so it fails closed.',
			$ctx['target_url'],
			$ctx['target_class']
		),
		3
	);
}

// ---------------------------------------------------------------------------
// Credentials: environment only, never a CLI value, never echoed.
// ---------------------------------------------------------------------------

$login = (string) getenv( 'WP_USERNAME' );
if ( '' === $login ) {
	$login = 'test';
}

// The login password is a local-only fixture credential, not a secret. It is
// overridable so a developer can harden the local account.
$user_password = getenv( 'WP_TEST_USER_PASSWORD' );
if ( ! is_string( $user_password ) || '' === $user_password ) {
	$user_password = 'test';
}

$app_password = (string) getenv( 'WP_APPLICATION_PASSWORD' );
if ( '' === $app_password ) {
	conexao_script_fail(
		'WP_APPLICATION_PASSWORD is not set in the environment. It holds the plaintext '
		. 'application password that scripts/lib/rest.py sends, and it must match the '
		. 'value stored for the account. Load it from .env before running this script '
		. '(set -a; . ./.env; set +a) and re-run. The script will not invent one, '
		. 'because a generated password would not match .env and every REST call '
		. 'would still fail with 401.'
	);
}

$email = (string) getenv( 'WP_TEST_USER_EMAIL' );
if ( '' === $email ) {
	$email = $login . '@example.com';
}


// ---------------------------------------------------------------------------
// Inspect current state (read-only; runs in both modes).
// ---------------------------------------------------------------------------

$user = get_user_by( 'login', $login );

$state = array(
	'exists'          => (bool) $user,
	'is_admin'        => false,
	'password_ok'     => false,
	'app_password_ok' => false,
);

if ( $user ) {
	$state['is_admin']    = user_can( $user, 'manage_options' );
	$state['password_ok'] = wp_check_password( $user_password, $user->user_pass, $user->ID );

	foreach ( WP_Application_Passwords::get_user_application_passwords( $user->ID ) as $item ) {
		if ( WP_Application_Passwords::check_password( $app_password, $item['password'] ) ) {
			$state['app_password_ok'] = true;
			break;
		}
	}
}

$needs_user     = ! $user;
$needs_role     = (bool) $user && ! $state['is_admin'];
$needs_password = (bool) $user && ! $state['password_ok'];
// A user that does not exist yet cannot hold an application password, so
// creating the account implies creating its application password too. Without
// this, the create path would build a user that can log in but cannot
// authenticate over REST, which is the exact failure this script exists to
// prevent.
$needs_app_pass = $needs_user || ( (bool) $user && ! $state['app_password_ok'] );
$needs_work     = $needs_user || $needs_role || $needs_password || $needs_app_pass;

// Nothing to do: the desired state already holds. Exit 0.
if ( ! $needs_work ) {
	conexao_script_summary(
		$ctx,
		array(
			'created'            => 0,
			'role_updated'       => 0,
			'password_updated'   => 0,
			'app_password_added' => 0,
			'errors'             => 0,
		),
		array(
			'login'    => $login,
			'result'   => 'ok',
			'note'     => 'the account already exists with the expected role, login password and application password; nothing was written',
			'verified' => $state,
		)
	);
	exit( 0 );
}

// Dry-run: describe the work, write nothing.
if ( 'apply' !== $ctx['mode'] ) {
	conexao_script_summary(
		$ctx,
		array(
			'created'            => 0,
			'role_updated'       => 0,
			'password_updated'   => 0,
			'app_password_added' => 0,
			'errors'             => 0,
		),
		array(
			'login'   => $login,
			'result'  => 'dry-run',
			'plan'    => array(
				'create_user'              => $needs_user,
				'grant_administrator'      => $needs_role,
				'set_login_password'       => $needs_password,
				'add_application_password' => $needs_app_pass,
			),
			'note'    => 'dry-run: nothing was written. Re-run with --apply.',
			'current' => $state,
		)
	);
	exit( 0 );
}


// ---------------------------------------------------------------------------
// Apply
// ---------------------------------------------------------------------------

$created            = 0;
$role_updated       = 0;
$password_updated   = 0;
$app_password_added = 0;
$failures           = array();

if ( $needs_user ) {
	$user_id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => $user_password,
			'user_email' => $email,
			'role'       => 'administrator',
		)
	);

	if ( is_wp_error( $user_id ) ) {
		$failures[] = 'could not create the user: ' . $user_id->get_error_message();
		conexao_script_summary(
			$ctx,
			array(
				'created'            => 0,
				'role_updated'       => 0,
				'password_updated'   => 0,
				'app_password_added' => 0,
				'errors'             => count( $failures ),
			),
			array(
				'login'  => $login,
				'result' => 'failed',
				'errors' => $failures,
			)
		);
		exit( 1 );
	}

	$created = 1;
	$user_id = (int) $user_id;
} else {
	$user_id = (int) $user->ID;
}

if ( $needs_role ) {
	$role_user = get_user_by( 'id', $user_id );
	if ( $role_user ) {
		$role_user->set_role( 'administrator' );
	}
	$role_updated = 1;
}

if ( $needs_password ) {
	wp_set_password( $user_password, $user_id );
	$password_updated = 1;
	clean_user_cache( $user_id );
}


// Store a KNOWN application password rather than letting WordPress generate
// one: scripts/lib/rest.py sends the plaintext from .env, so a generated
// value would not match and every REST call would still fail with 401. The
// value is hashed with the same helper the core class uses, so verification
// is byte-identical to a core-created application password.
if ( $needs_app_pass ) {
	$existing = WP_Application_Passwords::get_user_application_passwords( $user_id );

	$existing[] = array(
		'uuid'      => wp_generate_uuid4(),
		'app_id'    => '',
		'name'      => 'local-dev-rest',
		'password'  => WP_Application_Passwords::hash_password( $app_password ),
		'created'   => time(),
		'last_used' => null,
		'last_ip'   => null,
	);

	// WP_Application_Passwords::set_user_application_passwords() is protected
	// (WordPress 6.8+); update_user_meta() is the exact call it wraps, using
	// the same user meta key, so the stored value is indistinguishable from one
	// written by the core class.
	$saved = update_user_meta(
		$user_id,
		WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS,
		$existing
	);

	if ( false === $saved ) {
		$failures[] = 'could not store the application password.';
	} else {
		$app_password_added = 1;
		$network_id         = get_main_network_id();
		if ( ! get_network_option( $network_id, WP_Application_Passwords::OPTION_KEY_IN_USE ) ) {
			update_network_option( $network_id, WP_Application_Passwords::OPTION_KEY_IN_USE, true );
		}
	}
}

// ---------------------------------------------------------------------------
// Verify what was actually written, by reading it back.
// ---------------------------------------------------------------------------

$final = array(
	'exists'          => false,
	'is_admin'        => false,
	'password_ok'     => false,
	'app_password_ok' => false,
);

$check = get_user_by( 'id', $user_id );
if ( $check ) {
	$final['exists']      = true;
	$final['is_admin']    = user_can( $check, 'manage_options' );
	$final['password_ok'] = wp_check_password( $user_password, $check->user_pass, $check->ID );

	foreach ( WP_Application_Passwords::get_user_application_passwords( $check->ID ) as $item ) {
		if ( WP_Application_Passwords::check_password( $app_password, $item['password'] ) ) {
			$final['app_password_ok'] = true;
			break;
		}
	}
}

if ( ! $final['exists'] || ! $final['is_admin'] || ! $final['password_ok'] || ! $final['app_password_ok'] ) {
	$failures[] = 'post-apply verification failed: the account does not satisfy every expected property.';
}

// The shared summary helper prints the machine-readable/human-readable result
// and returns the process exit code (non-zero when errors were counted). It is
// assigned before use so the exit status is honoured explicitly, and because
// the output-escaping sniff cannot see that this helper escapes internally.
$exit_code = conexao_script_summary(
	$ctx,
	array(
		'created'            => $created,
		'role_updated'       => $role_updated,
		'password_updated'   => $password_updated,
		'app_password_added' => $app_password_added,
		'errors'             => count( $failures ),
	),
	array(
		'login'    => $login,
		'result'   => $failures ? 'failed' : 'ok',
		'verified' => $final,
		'errors'   => $failures,
		'note'     => $failures
			? 'one or more checks failed'
			: 'the local test account is present with an administrator role, the expected login password and a matching application password',
	)
);

exit( $exit_code );
