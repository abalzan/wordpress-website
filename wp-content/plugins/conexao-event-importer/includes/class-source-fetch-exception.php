<?php
/**
 * Exception thrown when an event source cannot be fetched.
 *
 * Distinguishes hard transport/HTTP failures from a source that is simply
 * empty. The import engine treats this exception as a fatal per-source error
 * without having to diff log entries.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Source_Fetch_Exception extends Exception {

	/** @var int HTTP status code (0 for network-level failures). */
	protected $http_status;

	/**
	 * Constructor.
	 *
	 * @param string $message     Human-readable error message.
	 * @param int    $http_status HTTP status code, 0 when the request never completed.
	 * @param int    $code        Exception code.
	 */
	public function __construct( $message = '', $http_status = 0, $code = 0 ) {
		parent::__construct( $message, $code );
		$this->http_status = (int) $http_status;
	}

	/**
	 * Get the HTTP status associated with the failure.
	 *
	 * @return int
	 */
	public function get_http_status() {
		return $this->http_status;
	}
}