<?php
/**
 * REST API: external triggers + health status.
 *
 * Provides token-protected endpoints so imports can be triggered from
 * outside WordPress (system cron, uptime monitors, CI pipelines) and the
 * importer health can be monitored remotely:
 *
 *   GET /wp-json/conexao-events/v1/run     — start a chunked import run (async)
 *   GET /wp-json/conexao-events/v1/status  — JSON health snapshot
 *
 * Authentication: secret token via ?token=… or the X-Conexao-Token header.
 * The token is managed on Event Import → Settings.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Import_Rest {

	const NAMESPACE_V1 = 'conexao-events/v1';

	/** @var Conexao_Import_Scheduler */
	protected $scheduler;

	/**
	 * Constructor.
	 *
	 * @param Conexao_Import_Scheduler $scheduler Scheduler (for run state checks).
	 */
	public function __construct( Conexao_Import_Scheduler $scheduler ) {
		$this->scheduler = $scheduler;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/run',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( $this, 'handle_run' ),
				'permission_callback' => array( $this, 'check_token' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_status' ),
				'permission_callback' => array( $this, 'check_token' ),
			)
		);
	}

	/**
	 * Verify the request token against the configured secret.
	 *
	 * Accepts ?token=… or an X-Conexao-Token header. Comparison is
	 * timing-safe via hash_equals().
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error True when authorized, WP_Error otherwise.
	 */
	public function check_token( $request ) {
		$expected = Conexao_Import_Settings::get_rest_token();

		if ( '' === $expected ) {
			return new WP_Error(
				'conexao_events_no_token',
				__( 'No API token is configured for the event importer.', 'conexao-event-importer' ),
				array( 'status' => 503 )
			);
		}

		$provided = (string) $request->get_param( 'token' );
		if ( '' === $provided ) {
			$provided = (string) $request->get_header( 'X-Conexao-Token' );
		}

		if ( '' === $provided || ! hash_equals( $expected, $provided ) ) {
			return new WP_Error(
				'conexao_events_invalid_token',
				__( 'Invalid or missing API token.', 'conexao-event-importer' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Handle POST/GET /run — kick off an async chunked import run.
	 *
	 * Returns immediately; sources are processed one per cron tick.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_run( $request ) {
		$result = $this->scheduler->start_run( 'rest' );

		$status = ! empty( $result['ok'] ) ? 200 : 409;

		return new WP_REST_Response(
			array(
				'ok'      => (bool) $result['ok'],
				'run_id'  => isset( $result['run_id'] ) ? $result['run_id'] : '',
				'queued'  => isset( $result['queued'] ) ? (int) $result['queued'] : 0,
				'message' => isset( $result['message'] ) ? $result['message'] : '',
			),
			$status
		);
	}

	/**
	 * Handle GET /status — JSON health snapshot of the importer.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_status( $request ) {
		$sources_manager = new Conexao_Event_Sources();
		$next_import     = $this->scheduler->get_next_scheduled();
		$next_cleanup    = wp_next_scheduled( 'conexao_event_cleanup_cron' );

		// Last combined history entry (source "all") or newest entry overall.
		$history    = Conexao_Import_History::get_all();
		$last_run   = null;
		foreach ( $history as $entry ) {
			if ( isset( $entry['source'] ) && 'all' === $entry['source'] ) {
				$last_run = $entry;
				break;
			}
		}
		if ( null === $last_run && ! empty( $history ) ) {
			$last_run = $history[0];
		}

		// Pending review count.
		$review_query = new WP_Query(
			array(
				'post_type'              => 'event',
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'   => '_event_status',
						'value' => Conexao_Event_Status::NEEDS_REVIEW,
					),
				),
			)
		);
		$pending_review = (int) $review_query->found_posts;

		$sources_payload = array();
		foreach ( $sources_manager->get_all() as $source ) {
			$health = Conexao_Source_Health::get( $source['id'] );

			$sources_payload[] = array(
				'id'                   => $source['id'],
				'name'                 => isset( $source['name'] ) ? $source['name'] : $source['id'],
				'status'               => isset( $source['status'] ) ? $source['status'] : '',
				'type'                 => isset( $source['type'] ) ? $source['type'] : '',
				'import_frequency'     => isset( $source['import_frequency'] ) ? $source['import_frequency'] : 'weekly',
				'last_import'          => isset( $source['last_import'] ) ? $source['last_import'] : '',
				'last_import_status'   => isset( $source['last_import_status'] ) ? $source['last_import_status'] : '',
				'last_checked'         => isset( $source['last_checked'] ) ? $source['last_checked'] : '',
				'consecutive_failures' => (int) $health['consecutive_failures'],
				'stale'                => Conexao_Source_Health::is_stale( $source ),
				'disabled_at'          => $health['disabled_at'],
				'disable_reason'       => $health['disable_reason'],
			);
		}

		return new WP_REST_Response(
			array(
				'generated_at'      => gmdate( 'c' ),
				'run_in_progress'   => $this->scheduler->is_running(),
				'next_import'       => $next_import ? gmdate( 'c', $next_import ) : null,
				'next_cleanup'      => $next_cleanup ? gmdate( 'c', $next_cleanup ) : null,
				'pending_review'    => $pending_review,
				'last_run'          => $last_run,
				'sources'           => $sources_payload,
			),
			200
		);
	}
}