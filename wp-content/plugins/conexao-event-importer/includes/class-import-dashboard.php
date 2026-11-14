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

		// The `conexao-import-history` screen was consolidated into the
		// dashboard in v1.10.0 and is no longer registered at all. WordPress
		// requires wp-admin/menu.php BEFORE admin_init, and menu.php fires this
		// hook immediately before refusing an unregistered admin page — so this
		// is the one lifecycle point that runs for the retired URL, and the
		// place a bookmarked link is turned into a hop to the dashboard.
		add_action( 'admin_page_access_denied', array( $this->sources, 'maybe_redirect_retired_history_page' ) );
	}
}