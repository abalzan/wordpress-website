<?php
/**
 * Remove only inherited importer continuation scheduling state from a Stage 0.5 clone.
 */

require_once __DIR__ . '/isolated-db.php';

function conexao_stage05_neutralize_fail( $message ) {
	fwrite( STDERR, 'stage05-clone-neutralizer: ' . $message . "\n" );

	return false;
}

function conexao_stage05_option( $connection, $table, $name ) {
	$statement = mysqli_prepare( $connection, "SELECT option_value FROM `{$table}` WHERE option_name = ?" );
	if ( false === $statement ) {
		throw new RuntimeException( 'could not prepare option read: ' . mysqli_error( $connection ) );
	}

	mysqli_stmt_bind_param( $statement, 's', $name );
	if ( ! mysqli_stmt_execute( $statement ) ) {
		throw new RuntimeException( 'could not read option ' . $name . ': ' . mysqli_stmt_error( $statement ) );
	}

	mysqli_stmt_bind_result( $statement, $value );
	$found = mysqli_stmt_fetch( $statement );
	mysqli_stmt_close( $statement );

	return ( true === $found ) ? (string) $value : null;
}

function conexao_stage05_update_option( $connection, $table, $name, $value ) {
	$statement = mysqli_prepare( $connection, "UPDATE `{$table}` SET option_value = ? WHERE option_name = ?" );
	if ( false === $statement ) {
		throw new RuntimeException( 'could not prepare option update: ' . mysqli_error( $connection ) );
	}

	mysqli_stmt_bind_param( $statement, 'ss', $value, $name );
	if ( ! mysqli_stmt_execute( $statement ) || 1 !== mysqli_stmt_affected_rows( $statement ) ) {
		$error = mysqli_stmt_error( $statement );
		mysqli_stmt_close( $statement );
		throw new RuntimeException( 'could not update option ' . $name . ': ' . $error );
	}

	mysqli_stmt_close( $statement );
}

function conexao_stage05_lock_names( $connection, $table ) {
	$prefix    = 'conexao_event_import_lock_';
	$statement = mysqli_prepare(
		$connection,
		"SELECT option_name FROM `{$table}` WHERE LEFT(option_name, ?) = ? ORDER BY option_name"
	);
	if ( false === $statement ) {
		throw new RuntimeException( 'could not prepare lock lookup: ' . mysqli_error( $connection ) );
	}

	$length = strlen( $prefix );
	mysqli_stmt_bind_param( $statement, 'is', $length, $prefix );
	if ( ! mysqli_stmt_execute( $statement ) ) {
		throw new RuntimeException( 'could not read continuation locks: ' . mysqli_stmt_error( $statement ) );
	}

	mysqli_stmt_bind_result( $statement, $name );
	$names = array();
	while ( mysqli_stmt_fetch( $statement ) ) {
		$names[] = (string) $name;
	}
	mysqli_stmt_close( $statement );

	return $names;
}

function conexao_stage05_delete_locks( $connection, $table ) {
	$prefix    = 'conexao_event_import_lock_';
	$statement = mysqli_prepare(
		$connection,
		"DELETE FROM `{$table}` WHERE LEFT(option_name, ?) = ?"
	);
	if ( false === $statement ) {
		throw new RuntimeException( 'could not prepare lock deletion: ' . mysqli_error( $connection ) );
	}

	$length = strlen( $prefix );
	mysqli_stmt_bind_param( $statement, 'is', $length, $prefix );
	if ( ! mysqli_stmt_execute( $statement ) ) {
		throw new RuntimeException( 'could not delete continuation locks: ' . mysqli_stmt_error( $statement ) );
	}

	$deleted = mysqli_stmt_affected_rows( $statement );
	mysqli_stmt_close( $statement );

	return $deleted;
}

function conexao_stage05_cron_events( $cron ) {
	$hook   = 'conexao_event_import_continuation';
	$count  = 0;
	$sources = array();

	foreach ( $cron as $timestamp => $hooks ) {
		if ( ! is_array( $hooks ) || ! isset( $hooks[ $hook ] ) || ! is_array( $hooks[ $hook ] ) ) {
			continue;
		}

		foreach ( $hooks[ $hook ] as $event ) {
			++$count;
			if ( isset( $event['args'][0] ) && is_string( $event['args'][0] ) ) {
				$sources[] = $event['args'][0];
			}
		}

		unset( $cron[ $timestamp ][ $hook ] );
		if ( empty( $cron[ $timestamp ] ) ) {
			unset( $cron[ $timestamp ] );
		}
	}

	return array( $cron, $count, $sources );
}

function conexao_stage05_neutralize() {
	$database = getenv( 'CONEXAO_TEST_DB_NAME' );
	$shared   = getenv( 'CONEXAO_TEST_SHARED_DB_NAME' );
	$prefix   = getenv( 'CONEXAO_TEST_DB_PREFIX' );

	if ( ! is_string( $database ) || ! preg_match( '/^conexao_stage05_test_[A-Za-z0-9_]+$/', $database ) ) {
		return conexao_stage05_neutralize_fail( 'refusing to alter a database not named conexao_stage05_test_<token>.' );
	}

	if ( ! is_string( $shared ) || '' === $shared || $database === $shared || 'true' === getenv( 'GITHUB_ACTIONS' ) ) {
		return conexao_stage05_neutralize_fail( 'a distinct local shared-database identity is required.' );
	}

	if ( ! is_string( $prefix ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ) {
		return conexao_stage05_neutralize_fail( 'CONEXAO_TEST_DB_PREFIX must be an explicit table prefix.' );
	}

	if ( ! conexao_test_db_preflight() ) {
		return false;
	}

	$host     = (string) getenv( 'WORDPRESS_DB_HOST' );
	$user     = (string) getenv( 'WORDPRESS_DB_USER' );
	$password = (string) getenv( 'WORDPRESS_DB_PASSWORD' );
	$host_name = preg_replace( '/:\d+$/', '', $host );
	$port      = preg_match( '/:(\d+)$/', $host, $matches ) ? (int) $matches[1] : 3306;
	$connection = mysqli_init();

	if ( false === $connection || ! mysqli_real_connect( $connection, $host_name, $user, $password, $database, $port ) ) {
		$error = ( false !== $connection ) ? $connection->connect_error : 'mysqli_init failed';
		return conexao_stage05_neutralize_fail( 'could not reopen the isolated clone: ' . $error );
	}

	$table = $prefix . 'options';
	$queue_raw = conexao_stage05_option( $connection, $table, 'conexao_event_import_continuations' );
	$cron_raw = conexao_stage05_option( $connection, $table, 'cron' );
	$queue = null === $queue_raw ? array() : unserialize( trim( $queue_raw ), array( 'allowed_classes' => false ) );
	$cron = null === $cron_raw ? array() : unserialize( trim( $cron_raw ), array( 'allowed_classes' => false ) );

	if ( ! is_array( $queue ) || ! is_array( $cron ) ) {
		mysqli_close( $connection );
		return conexao_stage05_neutralize_fail( 'the continuation queue or cron option is not a serialized array; no data was changed.' );
	}

	$queue_sources = array_keys( $queue );
	list( $clean_cron, $cron_count, $cron_sources ) = conexao_stage05_cron_events( $cron );
	$lock_names = conexao_stage05_lock_names( $connection, $table );
	$lock_count = count( $lock_names );

	printf(
		"CLONE_CONTINUATIONS_BEFORE queue=%d cron=%d locks=%d sources=%s lock_options=%s\n",
		count( $queue_sources ),
		$cron_count,
		$lock_count,
		implode( ',', $queue_sources ),
		implode( ',', $lock_names )
	);

	if ( ! mysqli_begin_transaction( $connection ) ) {
		mysqli_close( $connection );
		return conexao_stage05_neutralize_fail( 'could not start the clone-local transaction.' );
	}

	try {
		if ( null !== $queue_raw && ! empty( $queue ) ) {
			conexao_stage05_update_option( $connection, $table, 'conexao_event_import_continuations', serialize( array() ) );
		}
		if ( null !== $cron_raw && $cron_count > 0 ) {
			conexao_stage05_update_option( $connection, $table, 'cron', serialize( $clean_cron ) );
		}
		$deleted_locks = conexao_stage05_delete_locks( $connection, $table );

		$queue_after_raw = conexao_stage05_option( $connection, $table, 'conexao_event_import_continuations' );
		$cron_after_raw = conexao_stage05_option( $connection, $table, 'cron' );
		$queue_after = null === $queue_after_raw ? array() : unserialize( trim( $queue_after_raw ), array( 'allowed_classes' => false ) );
		$cron_after = null === $cron_after_raw ? array() : unserialize( trim( $cron_after_raw ), array( 'allowed_classes' => false ) );
		$locks_after = conexao_stage05_lock_names( $connection, $table );

		if ( ! is_array( $queue_after ) || ! is_array( $cron_after ) ) {
			throw new RuntimeException( 'clone-local verification could not parse continuation state.' );
		}

		list( $other_cron_after, $cron_after_count ) = conexao_stage05_cron_events( $cron_after );
		if (
			! empty( $queue_after )
			|| 0 !== $cron_after_count
			|| $clean_cron !== $other_cron_after
			|| ! empty( $locks_after )
			|| $lock_count !== $deleted_locks
		) {
			throw new RuntimeException( 'clone-local verification found continuation state still present.' );
		}

		if ( ! mysqli_commit( $connection ) ) {
			throw new RuntimeException( 'could not commit the verified clone-local changes.' );
		}
	} catch ( Throwable $error ) {
		mysqli_rollback( $connection );
		mysqli_close( $connection );
		return conexao_stage05_neutralize_fail( $error->getMessage() );
	}

	mysqli_close( $connection );

	printf(
		"CLONE_CONTINUATIONS_AFTER queue=0 cron=0 locks=0 deleted_locks=%d removed_cron_sources=%s\n",
		$deleted_locks,
		implode( ',', $cron_sources )
	);

	return true;
}

if ( isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	if ( 2 !== $argc || '--neutralize-continuations' !== $argv[1] ) {
		fwrite( STDERR, "Usage: php tests/lib/neutralize-test-continuations.php --neutralize-continuations\n" );
		exit( 2 );
	}

	mysqli_report( MYSQLI_REPORT_OFF );
	exit( conexao_stage05_neutralize() ? 0 : 1 );
}
