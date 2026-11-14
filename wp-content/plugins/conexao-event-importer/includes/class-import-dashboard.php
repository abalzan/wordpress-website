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

	/**
	 * The sources manager.
	 *
	 * @var Conexao_Event_Sources
	 */
	protected $sources;

	/**
	 * Log download/clear actions.
	 *
	 * @var Conexao_Import_Log_Admin
	 */
	public $log_admin;

	/**
	 * The importer engine.
	 *
	 * @var Conexao_Event_Importer_Engine
	 */
	protected $importer;

	/**
	 * Constructor.
	 *
	 * @param Conexao_Event_Sources         $sources   Sources manager.
	 * @param Conexao_Event_Importer_Engine $importer  Importer engine.
	 * @param Conexao_Import_Log_Admin|null $log_admin Log actions (download/clear/retired redirect).
	 */
	public function __construct( Conexao_Event_Sources $sources, Conexao_Event_Importer_Engine $importer, $log_admin = null ) {
		$this->sources   = $sources;
		$this->importer  = $importer;
		$this->log_admin = $log_admin instanceof Conexao_Import_Log_Admin ? $log_admin : new Conexao_Import_Log_Admin();

		add_action( 'admin_menu', array( $this->sources, 'register_admin_menu' ) );

		// The `conexao-import-history` screen was consolidated into the
		// dashboard in v1.10.0 and is no longer registered at all. WordPress
		// requires wp-admin/menu.php BEFORE admin_init, and menu.php fires this
		// hook immediately before refusing an unregistered admin page — so this
		// is the one lifecycle point that runs for the retired URL, and the
		// place a bookmarked link is turned into a hop to the dashboard.
		add_action( 'admin_page_access_denied', array( $this->sources, 'maybe_redirect_retired_history_page' ) );

		// The `conexao-import-log` screen was retired the same way once its
		// run-level inspection moved into the Import History table. Its
		// download/clear handlers survive; only the second screen is gone.
		add_action( 'admin_page_access_denied', array( $this->log_admin, 'maybe_redirect_retired_log_page' ) );
	}
}
