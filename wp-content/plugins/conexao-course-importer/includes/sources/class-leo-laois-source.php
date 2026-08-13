<?php
/**
 * Local Enterprise Office — Laois course source handler.
 *
 * Reuses the proven Local Enterprise Office — Laois retrieval logic from the
 * Event Importer (Conexao_Source_LEO_Laois) instead of duplicating the
 * scraping/parsing code. The LEO website loads its training listings
 * dynamically via an ASP.NET web service (EventService.asmx/SearchEvents), so
 * a static HTML scrape can never find them. This handler delegates to the
 * exact retrieval + parsing implementation already working for Events and then
 * maps the retrieved items into the Course data model.
 *
 * Events and Courses remain completely separate: this handler only produces
 * course arrays that feed the Course importer's normalizer/deduplicator/
 * storage pipeline.
 *
 * @package Conexao_Course_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Course_LEO_Laois_Source extends Conexao_Course_Source_Base {

	/**
	 * LEO API endpoint reused from the Event Importer source.
	 *
	 * @var string
	 */
	protected $api_url = 'https://www.localenterprise.ie/Laois/WebServices/EventService.asmx/SearchEvents';

	/**
	 * Collected diagnostics for this run, exposed to the admin UI.
	 *
	 * @var array
	 */
	protected $diagnostics = array();

	/**
	 * Get the source slug.
	 *
	 * @return string
	 */
	public function get_id() {
		return isset( $this->config['id'] ) ? $this->config['id'] : 'leo_laois';
	}

	/**
	 * Fetch raw course listings from Local Enterprise Office — Laois.
	 *
	 * Reuses the working Event importer logic (Conexao_Source_LEO_Laois) so
	 * the API request, pagination (single SearchEvents response), HTML
	 * parsing, link extraction, detail extraction and image extraction are
	 * all the proven paths already in production for Events.
	 *
	 * @return array[]
	 */
	public function fetch_courses() {
		$this->diagnostics = array(
			'source_name'      => isset( $this->config['name'] ) ? $this->config['name'] : 'Local Enterprise Office — Laois',
			'source_reachable' => false,
			'pages_scanned'    => 0,
			'links_discovered' => 0,
			'courses_parsed'   => 0,
			'error'            => '',
		);

		// The proven Event importer LEO source must be available to reuse.
		if ( ! class_exists( 'Conexao_Source_LEO_Laois' ) ) {
			$this->diagnostics['error'] = __( 'O importador de Eventos (Local Enterprise Office — Laois) não está ativo; o handler de Cursos não está disponível.', 'conexao-course-importer' );
			return array();
		}

		// 1. Lightweight reachability probe (HTTP status + payload shape).
		$this->diagnostics['source_reachable'] = $this->probe_source();

		// 2. Reuse the proven retrieval/parsing logic from the Event importer.
		$leo   = new Conexao_Source_LEO_Laois( $this->config );
		$items = $leo->fetch_events();

		// The SearchEvents API returns all listings in a single response body.
		$this->diagnostics['pages_scanned']    = $this->diagnostics['source_reachable'] ? 1 : 0;
		$this->diagnostics['links_discovered'] = count( $items );

		if ( $this->diagnostics['source_reachable'] && empty( $items ) ) {
			$this->diagnostics['error'] = __( 'Fonte acessível, mas nenhum curso foi encontrado (provável problema de seletor/parser).', 'conexao-course-importer' );
		}

		// 3. Map retrieved items into the Course data model.
		$courses = array();
		foreach ( $items as $item ) {
			$course = $this->map_to_course( $item );
			if ( ! empty( $course['title'] ) && ! empty( $course['url'] ) ) {
				$courses[] = $course;
			}
		}

		$this->diagnostics['courses_parsed'] = count( $courses );

		return $courses;
	}

	/**
	 * Get the diagnostics collected during the last fetch_courses() run.
	 *
	 * @return array
	 */
	public function get_diagnostics() {
		return $this->diagnostics;
	}

	/**
	 * Lightweight probe of the LEO API to determine source reachability.
	 *
	 * This mirrors the request used by the proven Event importer so the
	 * reachability check reflects real-world access to the source.
	 *
	 * @return bool
	 */
	protected function probe_source() {
		$body = wp_json_encode( array(
			'req' => array(
				'Category' => '',
				'DateFrom' => '',
				'DateTo'   => '',
			),
		) );

		$response = wp_remote_post(
			$this->api_url,
			array(
				'timeout'    => 30,
				'user-agent' => 'Mozilla/5.0 (compatible; ConexaoCourseImporter/1.0; +https://conexaobrirlanda.ie)',
				'headers'    => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'       => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->diagnostics['error'] = __( 'Falha ao alcançar a API do LEO: ', 'conexao-course-importer' ) . $response->get_error_message();
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$this->diagnostics['error'] = __( 'API do LEO retornou HTTP ', 'conexao-course-importer' ) . $code . '.';
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! $data || ! isset( $data['d'] ) ) {
			$this->diagnostics['error'] = __( 'Resposta inválida da API do LEO.', 'conexao-course-importer' );
			return false;
		}

		$inner = json_decode( $data['d'], true );
		if ( ! $inner || ! isset( $inner['IsSuccess'] ) || ! $inner['IsSuccess'] ) {
			$this->diagnostics['error'] = isset( $inner['ErrorText'] ) ? $inner['ErrorText'] : __( 'API do LEO retornou erro.', 'conexao-course-importer' );
			return false;
		}

		return true;
	}

	/**
	 * Map a retrieved LEO item (event-shaped) into the Course data model.
	 *
	 * Keeps Events and Courses separate: the item array is transformed into
	 * the fields the Course normalizer/storage expects. Missing values are
	 * left empty rather than invented.
	 *
	 * @param array $item Raw item from the reused LEO retrieval.
	 * @return array
	 */
	protected function map_to_course( $item ) {
		$venue    = isset( $item['location'] ) ? trim( (string) $item['location'] ) : '';
		$source   = isset( $item['url'] ) ? trim( (string) $item['url'] ) : '';
		$category = isset( $this->config['category'] ) && '' !== trim( (string) $this->config['category'] )
			? trim( (string) $this->config['category'] )
			: ( isset( $item['category'] ) ? trim( (string) $item['category'] ) : '' );

		// Ensure Laois is detected by the course location normalizer.
		$location = 'Co. Laois';
		if ( $venue ) {
			$location = $venue . ', Co. Laois';
		}

		return array(
			'title'         => isset( $item['title'] ) ? $item['title'] : '',
			'description'   => isset( $item['description'] ) ? $item['description'] : '',
			'url'           => $source,
			'start_date'    => isset( $item['start_date'] ) ? $item['start_date'] : '',
			'end_date'      => isset( $item['end_date'] ) ? $item['end_date'] : '',
			'start_time'    => isset( $item['start_time'] ) ? $item['start_time'] : '',
			'end_time'      => isset( $item['end_time'] ) ? $item['end_time'] : '',
			'location'      => $location,
			'image'         => isset( $item['image'] ) ? $item['image'] : '',
			'price'         => isset( $item['price'] ) ? $item['price'] : '',
			'organizer'     => isset( $item['organizer'] ) ? $item['organizer'] : 'Local Enterprise Office — Laois',
			'category'      => $category,
			'source_id'     => isset( $item['source_id'] ) ? $item['source_id'] : ( $source ? md5( $source ) : '' ),
			'source'        => $this->get_id(),
			'county'        => isset( $this->config['county'] ) && '' !== trim( (string) $this->config['county'] )
				? trim( (string) $this->config['county'] )
				: 'Laois',
		);
	}
}