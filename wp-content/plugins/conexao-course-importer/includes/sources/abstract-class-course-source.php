<?php
/**
 * Abstract course source handler.
 *
 * Defines the contract every course source must implement so new sources
 * can be added later without rebuilding the system.
 *
 * @package Conexao_Course_Importer
 */

defined( 'ABSPATH' ) || exit;

abstract class Conexao_Course_Source_Base {

	/** @var array Source config. */
	protected $config;

	/**
	 * Constructor.
	 *
	 * @param array $config Source configuration from Course Sources.
	 */
	public function __construct( $config ) {
		$this->config = $config;
	}

	/**
	 * Get the source slug (e.g. "leo_laois").
	 *
	 * @return string
	 */
	abstract public function get_id();

	/**
	 * Fetch raw course listings from the source.
	 *
	 * @return array[] List of raw course arrays. Each must include:
	 *   - title       (string)
	 *   - url         (string, original source URL)
	 *   - start_date  (string, raw date, optional)
	 *   - end_date    (string, raw date, optional)
	 *   - start_time  (string, optional raw time)
	 *   - end_time    (string, optional raw time)
	 *   - location    (string, optional raw location)
	 *   - description (string, optional)
	 *   - image       (string, optional banner URL)
	 *   - source_id   (string, optional source course ID)
	 *   - price       (string, optional)
	 *   - currency    (string, optional)
	 *   - booking_url (string, optional)
	 *   - category    (string, optional)
	 *   - organizer   (string, optional)
	 *   - duration    (string, optional)
	 *   - delivery_mode (string, optional: in_person|online|hybrid)
	 */
	abstract public function fetch_courses();

	/**
	 * Fetch the HTML of a URL.
	 *
	 * @param string $url URL to fetch.
	 * @return string HTML body or empty string on failure.
	 */
	protected function fetch_html( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 30,
				'user-agent' => 'Mozilla/5.0 (compatible; ConexaoCourseImporter/1.0; +https://conexaobrirlanda.ie)',
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return '';
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Parse HTML into a DOMDocument.
	 *
	 * @param string $html Raw HTML.
	 * @return DOMDocument|false
	 */
	protected function parse_html( $html ) {
		if ( empty( $html ) ) {
			return false;
		}

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();

		return $dom;
	}

	/**
	 * Resolve a possibly-relative URL to an absolute URL.
	 *
	 * @param string $url  Raw URL.
	 * @param string $base Base URL.
	 * @return string
	 */
	protected function resolve_url( $url, $base ) {
		$url = trim( $url );
		if ( empty( $url ) ) {
			return '';
		}

		if ( 0 === strpos( $url, 'http://' ) || 0 === strpos( $url, 'https://' ) ) {
			return $url;
		}

		if ( 0 === strpos( $url, '//' ) ) {
			return 'https:' . $url;
		}

		if ( 0 === strpos( $url, '/' ) ) {
			$parts = wp_parse_url( $base );
			return ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . ( isset( $parts['host'] ) ? $parts['host'] : '' ) . $url;
		}

		return rtrim( $base, '/' ) . '/' . ltrim( $url, '/' );
	}
}