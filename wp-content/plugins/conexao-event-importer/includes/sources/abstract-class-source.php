<?php
/**
 * Abstract event source handler.
 *
 * Defines the contract every source must implement so new sources can be
 * added later without rebuilding the system.
 *
 * HTTP fetching is structured: fetch_html() throws a
 * Conexao_Source_Fetch_Exception on network errors or non-200 responses so
 * the import engine can report a clean fatal error instead of guessing from
 * log entries. Sources that must tolerate individual page failures (e.g.
 * pagination) can use fetch_html_or_empty().
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

abstract class Conexao_Source_Base {

	/** @var array Source config. */
	protected $config;

	/**
	 * Constructor.
	 *
	 * @param array $config Source configuration from Event Sources.
	 */
	public function __construct( $config ) {
		$this->config = $config;
	}

	/**
	 * Get the source slug (e.g. "laois_tourism").
	 *
	 * @return string
	 */
	abstract public function get_id();

	/**
	 * Fetch raw event listings from the source.
	 *
	 * @return array[] List of raw event arrays. Each must include:
	 *   - title       (string)
	 *   - url         (string, original source URL)
	 *   - start_date  (string, raw date)
	 *   - start_time  (string, optional raw time)
	 *   - end_date    (string, optional raw date)
	 *   - end_time    (string, optional raw time)
	 *   - location    (string, optional raw location)
	 *   - description (string, optional)
	 *   - image       (string, optional banner URL)
	 *   - source_id   (string, optional source event ID)
	 */
	abstract public function fetch_events();

	/**
	 * Get the HTTP User-Agent used for source requests.
	 *
	 * Several sources (e.g. laoistourism.ie behind Cloudflare) reject
	 * generic/bot-like user agents with 403, so the default mimics a real
	 * browser. Override via the 'conexao_event_importer_http_user_agent'
	 * filter when a source needs something specific.
	 *
	 * @return string
	 */
	protected function get_http_user_agent() {
		return apply_filters(
			'conexao_event_importer_http_user_agent',
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36'
		);
	}

	/**
	 * Fetch the HTML of a URL, throwing on transport/HTTP failure.
	 *
	 * Network errors and non-200 status codes raise a
	 * Conexao_Source_Fetch_Exception (after logging) so callers can
	 * distinguish "source down" from "no events found".
	 *
	 * @param string $url  URL to fetch.
	 * @param array  $args Optional extra wp_remote_get args (headers etc).
	 * @return string HTML body.
	 * @throws Conexao_Source_Fetch_Exception On any transport or HTTP failure.
	 */
	protected function fetch_html( $url, $args = array() ) {
		$default_args = array(
			'timeout'    => 30,
			'user-agent' => $this->get_http_user_agent(),
		);

		$response = wp_remote_get( $url, array_merge( $default_args, $args ) );

		if ( is_wp_error( $response ) ) {
			$message = 'Falha ao buscar URL: ' . $response->get_error_message();
			Conexao_Import_Log::add(
				$this->get_id(),
				'error',
				$message,
				array(
					'url'         => $url,
					'http_status' => 0,
					'run_id'      => apply_filters( 'conexao_event_importer_current_run_id', '' ),
				)
			);
			throw new Conexao_Source_Fetch_Exception( $message, 0 );
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== (int) $code ) {
			$message = 'Resposta HTTP ' . $code . ' ao buscar URL.';
			Conexao_Import_Log::add(
				$this->get_id(),
				'error',
				$message,
				array(
					'url'         => $url,
					'http_status' => (int) $code,
					'run_id'      => apply_filters( 'conexao_event_importer_current_run_id', '' ),
				)
			);
			throw new Conexao_Source_Fetch_Exception( $message, (int) $code );
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Tolerant fetch: returns an empty string instead of throwing.
	 *
	 * Use for optional pages (pagination continuation, detail enrichment)
	 * where a single failed request should not abort the whole source run.
	 *
	 * @param string $url  URL to fetch.
	 * @param array  $args Optional extra wp_remote_get args.
	 * @return string HTML body or empty string on failure.
	 */
	protected function fetch_html_or_empty( $url, $args = array() ) {
		try {
			return $this->fetch_html( $url, $args );
		} catch ( Conexao_Source_Fetch_Exception $e ) {
			return '';
		}
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
	 * Extract text from a DOMElement.
	 *
	 * @param DOMElement $node     Node to extract from.
	 * @param string     $selector CSS selector (basic).
	 * @return string
	 */
	protected function extract_text( $node, $selector = '' ) {
		if ( $selector ) {
			$found = $node->querySelector( $selector );
			if ( $found ) {
				return trim( $found->textContent );
			}
			return '';
		}
		return trim( $node->textContent );
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