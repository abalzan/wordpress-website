<?php
/**
 * Raw, pre-WordPress identity check for the test database.
 */

function conexao_test_db_fail( $message ) {
	fwrite( STDERR, 'conexao-test-bootstrap: ' . $message . "\n" );

	return false;
}

function conexao_test_db_preflight() {
	$expected = getenv( 'CONEXAO_TEST_DB_NAME' );
	$active   = getenv( 'WORDPRESS_DB_NAME' );
	$shared   = getenv( 'CONEXAO_TEST_SHARED_DB_NAME' );

	if ( ! is_string( $expected ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $expected ) ) {
		return conexao_test_db_fail( 'CONEXAO_TEST_DB_NAME is required and must be a database identifier.' );
	}

	if ( $expected !== $active ) {
		return conexao_test_db_fail( 'WORDPRESS_DB_NAME does not match the explicitly requested test database.' );
	}

	if ( is_string( $shared ) && '' !== $shared && $expected === $shared && 'true' !== getenv( 'GITHUB_ACTIONS' ) ) {
		return conexao_test_db_fail( 'the test database is the shared local WordPress database.' );
	}

	if ( 'true' !== getenv( 'GITHUB_ACTIONS' ) && ( ! is_string( $shared ) || '' === $shared ) ) {
		return conexao_test_db_fail( 'CONEXAO_TEST_SHARED_DB_NAME is required outside isolated CI.' );
	}

	$host     = getenv( 'WORDPRESS_DB_HOST' );
	$user     = getenv( 'CONEXAO_TEST_DB_USER' );
	$password = getenv( 'CONEXAO_TEST_DB_PASSWORD' );

	if ( ! is_string( $user ) || '' === $user ) {
		return conexao_test_db_fail( 'CONEXAO_TEST_DB_USER is required for the isolated test connection.' );
	}

	if ( ! is_string( $password ) || '' === $password ) {
		return conexao_test_db_fail( 'CONEXAO_TEST_DB_PASSWORD is required for the isolated test connection.' );
	}

	if ( ! is_string( $host ) ) {
		return conexao_test_db_fail( 'local WordPress database connection settings are unavailable.' );
	}

	$host_name = preg_replace( '/:\d+$/', '', $host );
	if ( ! in_array( $host_name, array( 'db', 'localhost', '127.0.0.1', '::1', 'host.docker.internal' ), true ) ) {
		return conexao_test_db_fail( 'database host is not an approved local host.' );
	}

	$port = 3306;
	if ( preg_match( '/:(\d+)$/', $host, $matches ) ) {
		$port = (int) $matches[1];
	}

	if ( ! extension_loaded( 'mysqli' ) ) {
		return conexao_test_db_fail( 'mysqli is required for the raw database identity check.' );
	}

	mysqli_report( MYSQLI_REPORT_OFF );
	$connection = mysqli_init();
	if ( false === $connection || ! mysqli_real_connect( $connection, $host_name, $user, $password, $expected, $port ) ) {
		$error = ( false !== $connection ) ? $connection->connect_error : 'mysqli_init failed';
		return conexao_test_db_fail( 'could not connect to the explicitly requested test database: ' . $error );
	}

	$result = mysqli_query( $connection, 'SELECT DATABASE()' );
	$row    = ( false !== $result ) ? mysqli_fetch_row( $result ) : false;
	$actual = ( is_array( $row ) && isset( $row[0] ) ) ? (string) $row[0] : '';
	mysqli_close( $connection );

	if ( $expected !== $actual ) {
		return conexao_test_db_fail( 'actual database identity did not match CONEXAO_TEST_DB_NAME.' );
	}

	printf( "ISOLATED_DB_PREFLIGHT=PASS database=%s host=%s\n", $actual, $host_name );

	return true;
}

if ( isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	exit( conexao_test_db_preflight() ? 0 : 1 );
}
