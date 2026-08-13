<?php
/**
 * Course Import Dashboard.
 *
 * Thin wrapper that hooks the Course Sources admin menu into WordPress.
 *
 * @package Conexao_Course_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Course_Import_Dashboard {

	/** @var Conexao_Course_Sources */
	protected $sources;

	/** @var Conexao_Course_Importer_Engine */
	protected $importer;

	/**
	 * Constructor.
	 *
	 * @param Conexao_Course_Sources       $sources  Sources manager.
	 * @param Conexao_Course_Importer_Engine $importer Importer engine.
	 */
	public function __construct( Conexao_Course_Sources $sources, Conexao_Course_Importer_Engine $importer ) {
		$this->sources  = $sources;
		$this->importer = $importer;

		add_action( 'admin_menu', array( $this->sources, 'register_admin_menu' ) );
	}
}