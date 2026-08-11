<?php
/**
 * Import dashboard.
 *
 * Thin wrapper that wires up the admin menu + dashboard rendering. The actual
 * rendering logic lives in Conexao_Event_Sources to keep the admin UI in one
 * place.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Import_Dashboard {

	/** @var Conexao_Event_Sources */
	protected $sources;

	/** @var Conexao_Event_Importer_Engine */
	protected $importer;

	/**
	 * Constructor.
	 *
	 * @param Conexao_Event_Sources          $sources  Sources manager.
	 * @param Conexao_Event_Importer_Engine  $importer Importer engine.
	 */
	public function __construct( Conexao_Event_Sources $sources, Conexao_Event_Importer_Engine $importer ) {
		$this->sources  = $sources;
		$this->importer = $importer;

		add_action( 'admin_menu', array( $this->sources, 'register_admin_menu' ) );
	}
}