<?php
/**
 * Eventbrite discovery client.
 *
 * Fetches Eventbrite's public Laois discovery pages and returns the raw HTML.
 * Implements conservative request handling with retry/backoff for transient
 * failures (429, 500, 503).
 *
 * This client ONLY performs server-side GET requests to the public discovery
 * page. It does NOT use Eventbrite's undocumented internal search API.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Eventbrite_Client {

	/**
	 * Default discovery URL for Laois events.
	 *
	 * @var string
	 */
	const DEFAULT_DISCOVERY_URL = 'https://www.eventbrite.ie/d/ireland--laois/all-events/';

	/**
	 * Maximum number of retries per page.
	 *
	 * @var int
	 */
	const MAX_RETRIES = 3;

	/**
	 * Base delay in seconds for exponential backoff.
	 *
	 * @var int
	 */
	const BASE_BACKOFF_SECONDS = 2;

	/**
	 * Delay between page requests in seconds (1–2s recommended).
	 *
	 * @var int
	 */
	const REQUEST_DELAY_SECONDS = 1;

	/**
	 * Browser-like User-Agent.
	 *
	 * @var string
	 */
	const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

	/**
	 * Fetch a discovery page.
	 *
	 * @param string $url  Page URL.
	 * @param int    $page Page number (1-based).
	 * @return string HTML body or empty string on failure.
	 */
	public function fetch_page( $url, $page = 1 ) {
		$page_url = $this->build_page_url( $url, $page );

		for ( $attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++ ) {
			$response = wp_remote_get(
				$page_url,
				array(
					'timeout'    => 30,
					'user-agent' => self::USER_AGENT,
					'headers'    => array(
						'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
						'Accept-Language' => 'en-IE,en;q=0.9',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				Conexao_Import_Log::add(
					'eventbrite',
					'error',
					'Eventbrite fetch failed: ' . $response->get_error_message(),
					array( 'url' => $page_url, 'attempt' => $attempt )
				);
				$this->backoff( $attempt );
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );

			switch ( $code ) {
				case 200:
					return wp_remote_retrieve_body( $response );

				case 401:
					// Authentication required — do not retry, do not attempt internal API.
					Conexao_Import_Log::add(
						'eventbrite',
						'error',
						'Eventbrite returned 401 Unauthorized. The discovery page may require authentication.',
						array( 'url' => $page_url )
					);
					return '';

				case 404:
					Conexao_Import_Log::add(
						'eventbrite',
						'error',
						'Eventbrite returned 404 Not Found.',
						array( 'url' => $page_url )
					);
					return '';

				case 429:
				case 500:
				case 503:
					Conexao_Import_Log::add(
						'eventbrite',
						'warning',
						'Eventbrite returned HTTP ' . $code . '. Retrying with backoff.',
						array( 'url' => $page_url, 'attempt' => $attempt )
					);
					$this->backoff( $attempt );
					break;

				default:
					Conexao_Import_Log::add(
						'eventbrite',
						'error',
						'Eventbrite returned unexpected HTTP ' . $code . '.',
						array( 'url' => $page_url )
					);
					return '';
			}
		}

		Conexao_Import_Log::add(
			'eventbrite',
			'error',
			'Eventbrite fetch failed after ' . self::MAX_RETRIES . ' attempts.',
			array( 'url' => $page_url )
		);

		return '';
	}

	/**
	 * Build a page URL with the ?page=N parameter.
	 *
	 * @param string $base_url Base discovery URL.
	 * @param int    $page     Page number (1-based).
	 * @return string
	 */
	public function build_page_url( $base_url, $page ) {
		if ( $page <= 1 ) {
			return $base_url;
		}

		$separator = ( false === strpos( $base_url, '?' ) ) ? '?' : '&';
		return $base_url . $separator . 'page=' . (int) $page;
	}

	/**
	 * Sleep between requests to be respectful of Eventbrite's servers.
	 */
	public function delay() {
		sleep( self::REQUEST_DELAY_SECONDS );
	}

	/**
	 * Exponential backoff between retries.
	 *
	 * @param int $attempt Current attempt number (1-based).
	 */
	protected function backoff( $attempt ) {
		$seconds = self::BASE_BACKOFF_SECONDS * pow( 2, $attempt - 1 );
		sleep( $seconds );
	}
}