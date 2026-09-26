<?php
/**
 * Event Sources management (admin area + storage).
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Event_Sources {

	const OPTION_KEY = 'conexao_event_sources';

	/**
	 * Default sources seeded on activation.
	 *
	 * @return array
	 */
	public function get_defaults() {
		return array(
			'laois_tourism' => array(
				'id'                 => 'laois_tourism',
				'name'               => 'Laois Tourism',
				// Photo-view ICS export with recurrences hidden. Requires a
				// browser-like User-Agent (Cloudflare blocks generic bots).
				'url'                => 'https://laoistourism.ie/events/photo/?hide_subsequent_recurrences=1&ical=1',
				'type'               => 'icalendar',
				'status'             => 'active',
				'last_import'        => '',
				'last_import_status' => '',
				'events_imported'    => 0,
				'last_error'         => '',
				'county'             => 'Laois',
			),
			'heritage_week' => array(
				'id'                 => 'heritage_week',
				'name'               => 'National Heritage Week',
				'url'                => 'https://www.heritageweek.ie/event-listings?q=&where%5B%5D=laois',
				'type'               => 'website',
				'status'             => 'active',
				'last_import'        => '',
				'last_import_status' => '',
				'events_imported'    => 0,
				'last_error'         => '',
				'county'             => 'Laois',
				'category'           => 'Heritage',
			),
			'eventbrite' => array(
				'id'                 => 'eventbrite',
				'name'               => 'Eventbrite — Laois',
				'url'                => 'https://www.eventbrite.ie/d/ireland--laois/all-events/',
				'type'               => 'eventbrite',
				'status'             => 'active',
				'last_import'        => '',
				'last_import_status' => '',
				'events_imported'    => 0,
				'last_error'         => '',
				'county'             => 'Laois',
			),
			'ivvcc' => array(
				'id'                 => 'ivvcc',
				'name'               => 'IVVCC — Irish Veteran & Vintage Car Club',
				'url'                => 'https://www.ivvcc.ie/upcoming-events-calendar/',
				'type'               => 'website',
				'status'             => 'inactive',
				'last_import'        => '',
				'last_import_status' => '',
				'events_imported'    => 0,
				'last_error'         => '',
				'county'             => '',
			),
			'motorsport_ireland' => array(
				'id'                 => 'motorsport_ireland',
				'name'               => 'Motorsport Ireland',
				'url'                => 'https://www.motorsportireland.com/events',
				'type'               => 'website',
				'status'             => 'inactive',
				'last_import'        => '',
				'last_import_status' => '',
				'events_imported'    => 0,
				'last_error'         => '',
				'county'             => '',
			),
			'mondello_park' => array(
				'id'                 => 'mondello_park',
				'name'               => 'Mondello Park — Ireland\'s National Motorsports Campus',
				'url'                => 'https://mondellopark.ie/wp-json/wp/v2/events?per_page=100',
				'type'               => 'website',
				'status'             => 'inactive',
				'last_import'        => '',
				'last_import_status' => '',
				'events_imported'    => 0,
				'last_error'         => '',
				'county'             => 'Kildare',
			),
		);
	}

	/**
	 * Get all sources.
	 *
	 * Lazily merges any missing default sources (e.g. heritage_week on older
	 * installs) and migrates legacy source IDs to their canonical form.
	 * This keeps the source list complete and idempotent without overwriting
	 * user changes to existing sources.
	 *
	 * @return array
	 */
	public function get_all() {
		$sources = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $sources ) || empty( $sources ) ) {
			$sources = $this->get_defaults();
			update_option( self::OPTION_KEY, $sources, false );
			return $sources;
		}

		$changed = false;

		// Remove retired sources (Laois County Council, LEO Laois). They are
		// no longer import sources; previously imported events are preserved.
		foreach ( array( 'laois_council', 'leo_laois', 'local_enterprise_office_laois' ) as $retired_id ) {
			if ( isset( $sources[ $retired_id ] ) ) {
				unset( $sources[ $retired_id ] );
				$changed = true;
			}
		}

		// Migrate legacy source IDs to their canonical form.
		if ( isset( $sources['national-heritage-week'] ) && ! isset( $sources['heritage_week'] ) ) {
			$sources['heritage_week']             = $sources['national-heritage-week'];
			$sources['heritage_week']['id']       = 'heritage_week';
			$sources['heritage_week']['name']     = 'National Heritage Week';
			$sources['heritage_week']['county']   = 'Laois';
			$sources['heritage_week']['category'] = 'Heritage';
			unset( $sources['national-heritage-week'] );
			$changed = true;
		}

		// Merge in any default sources that are missing.
		foreach ( $this->get_defaults() as $default_id => $default_source ) {
			if ( ! isset( $sources[ $default_id ] ) ) {
				// Preserve other user-added sources; only add missing defaults.
				// New sources keep their declared default status (e.g. ivvcc,
				// motorsport_ireland and mondello_park ship inactive and must
				// stay inactive until explicitly activated in wp-admin).
				$sources[ $default_id ]       = $default_source;
				$sources[ $default_id ]['id'] = $default_id;
				$changed                      = true;
				continue;
			}

			/*
			 * STAGE 7.x — self-heal a DRIFTED county hint.
			 *
			 * A source's `county` is not decoration: it is the per-source
			 * taxonomy hint that Conexao_Source_ICalendar /
			 * Conexao_Source_Eventbrite copy onto every event they emit, and
			 * Conexao_Event_Importer::save_event_taxonomies() turns into a
			 * `conexao_county` term. The Events archive filters on exactly
			 * that term (?county=<slug>), so an empty hint means every event
			 * from that source is permanently invisible to the county
			 * filter.
			 *
			 * The drift is reachable through wp-admin: "Add Source" renders
			 * the County input EMPTY (only a placeholder, no value), so
			 * re-creating or re-saving a shipped source without retyping the
			 * county silently persists ''. handle_save_source() then stores
			 * that empty string, and because get_all() only ever ADDED
			 * missing sources it never restored the declared default — the
			 * shipped county was lost for good.
			 *
			 * Repair rule (deliberately narrow, and idempotent):
			 *   - only for a source id that SHIPS a default county;
			 *   - only when the STORED county is empty/absent;
			 *   - a non-empty stored county is NEVER overwritten, so a
			 *     deliberate operator override always wins.
			 *
			 * `conexao_county` is a shared geography taxonomy (AGENTS.md), so
			 * this writes no new term and no language-scoped term: it only
			 * restores the hint that decides which EXISTING shared term the
			 * importer assigns.
			 */
			$default_county = isset( $default_source['county'] ) ? trim( (string) $default_source['county'] ) : '';
			$stored_county  = isset( $sources[ $default_id ]['county'] ) ? trim( (string) $sources[ $default_id ]['county'] ) : '';

			if ( '' !== $default_county && '' === $stored_county ) {
				$sources[ $default_id ]['county'] = $default_county;
				$changed                          = true;
			}
		}

		if ( $changed ) {
			update_option( self::OPTION_KEY, $sources, false );
		}

		return $sources;
	}

	/**
	 * Get active sources.
	 *
	 * @return array
	 */
	public function get_active() {
		$active = array();
		foreach ( $this->get_all() as $source ) {
			if ( 'active' === $source['status'] ) {
				$active[ $source['id'] ] = $source;
			}
		}
		return $active;
	}

	/**
	 * Get a single source.
	 *
	 * @param string $source_id Source slug.
	 * @return array|null
	 */
	public function get( $source_id ) {
		$sources = $this->get_all();
		return isset( $sources[ $source_id ] ) ? $sources[ $source_id ] : null;
	}

	/**
	 * Save a source.
	 *
	 * @param array $source Source data.
	 * @return bool
	 */
	public function save( $source ) {
		if ( empty( $source['id'] ) ) {
			return false;
		}

		$sources = $this->get_all();
		$sources[ $source['id'] ] = wp_parse_args(
			$source,
			array(
				'name'               => '',
				'url'                => '',
				'type'               => 'website',
				'status'             => 'inactive',
				'last_import'        => '',
				'last_import_status' => '',
				'events_imported'    => 0,
				'last_error'         => '',
			)
		);

		return update_option( self::OPTION_KEY, $sources, false );
	}

	/**
	 * Toggle a source's active status.
	 *
	 * @param string $source_id Source slug.
	 * @param string $status    active|inactive.
	 * @return bool
	 */
	public function set_status( $source_id, $status ) {
		$source = $this->get( $source_id );
		if ( ! $source ) {
			return false;
		}
		$source['status'] = ( 'active' === $status ) ? 'active' : 'inactive';
		return $this->save( $source );
	}

	/**
	 * Delete a source.
	 *
	 * Removes the source configuration from the sources array.
	 * Imported events are preserved (not deleted).
	 *
	 * @param string $source_id Source slug.
	 * @return bool True on success, false on failure.
	 */
	public function delete( $source_id ) {
		if ( empty( $source_id ) ) {
			return false;
		}

		$sources = $this->get_all();
		if ( ! isset( $sources[ $source_id ] ) ) {
			return false;
		}

		unset( $sources[ $source_id ] );
		return update_option( self::OPTION_KEY, $sources, false );
	}

	/**
	 * Update import stats for a source.
	 *
	 * @param string $source_id Source slug.
	 * @param array  $stats     Stats to update.
	 * @return bool
	 */
	public function update_import_stats( $source_id, $stats ) {
		$source = $this->get( $source_id );
		if ( ! $source ) {
			return false;
		}

		if ( isset( $stats['last_import'] ) ) {
			$source['last_import'] = $stats['last_import'];
		}
		if ( isset( $stats['last_import_status'] ) ) {
			$source['last_import_status'] = $stats['last_import_status'];
		}
		if ( isset( $stats['events_imported'] ) ) {
			$source['events_imported'] = (int) $stats['events_imported'];
		}
		if ( isset( $stats['last_error'] ) ) {
			$source['last_error'] = $stats['last_error'];
		}
		return $this->save( $source );
	}

	/**
	 * Seed default sources (used on activation).
	 */
	public function seed_defaults() {
		$existing = get_option( self::OPTION_KEY, array() );
		if ( ! empty( $existing ) ) {
			return;
		}
		update_option( self::OPTION_KEY, $this->get_defaults(), false );
	}

	/**
	 * Seed the county source registrations from the authoritative county registry.
	 *
	 * Generates 26 Eventbrite + 26 Heritage Week source configs (52 total).
	 * ALL sources start as `inactive`. Idempotent: running twice does not
	 * create duplicates or overwrite unrelated source configuration.
	 *
	 * Only inserts sources whose IDs do not already exist in the option,
	 * so previously activated/edited sources are never reset.
	 *
	 * @return array{inserted:int, skipped:int, ids:array} Summary of the seeding run.
	 */
	public function seed_county_sources() {
		$existing = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$county_sources = Conexao_County_Registry::get_all_county_sources();

		$inserted = 0;
		$skipped  = 0;
		$new_ids  = array();

		foreach ( $county_sources as $id => $source ) {
			if ( isset( $existing[ $id ] ) ) {
				$skipped++;
				continue;
			}
			$existing[ $id ] = $source;
			$new_ids[] = $id;
			$inserted++;
		}

		if ( $inserted > 0 ) {
			update_option( self::OPTION_KEY, $existing, false );
		}

		return array(
			'inserted' => $inserted,
			'skipped'  => $skipped,
			'ids'      => $new_ids,
		);
	}

	/**
	 * Get all county source IDs currently registered.
	 *
	 * @return array List of county source IDs.
	 */
	public function get_county_source_ids() {
		$sources = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $sources ) ) {
			return array();
		}

		$county_ids = array();
		foreach ( Conexao_County_Registry::get_all_county_sources() as $id => $source ) {
			if ( isset( $sources[ $id ] ) ) {
				$county_ids[] = $id;
			}
		}
		return $county_ids;
	}

	/**
	 * Register the admin menu.
	 */
	public function register_admin_menu() {
		add_menu_page(
			__( 'Event Import', 'conexao-event-importer' ),
			__( 'Event Import', 'conexao-event-importer' ),
			'manage_options',
			'conexao-event-import',
			array( $this, 'render_import_dashboard' ),
			'dashicons-calendar-alt',
			26
		);

		add_submenu_page(
			'conexao-event-import',
			__( 'Event Sources', 'conexao-event-importer' ),
			__( 'Event Sources', 'conexao-event-importer' ),
			'manage_options',
			'conexao-event-sources',
			array( $this, 'render_sources_page' )
		);

		add_submenu_page(
			'conexao-event-import',
			__( 'Import History', 'conexao-event-importer' ),
			__( 'Import History', 'conexao-event-importer' ),
			'manage_options',
			'conexao-import-history',
			array( $this, 'render_history_page' )
		);
	}

	/**
	 * Render the sources admin page.
	 */
	public function render_sources_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Handle actions: toggle, save, run import, delete.
		if ( isset( $_POST['conexao_source_action'] ) && check_admin_referer( 'conexao_event_sources', 'conexao_event_sources_nonce' ) ) {
			$action = sanitize_text_field( wp_unslash( $_POST['conexao_source_action'] ) );

			if ( 'toggle' === $action && isset( $_POST['source_id'] ) ) {
				$source_id = sanitize_text_field( wp_unslash( $_POST['source_id'] ) );
				$source    = $this->get( $source_id );
				if ( $source ) {
					$new_status = ( 'active' === $source['status'] ) ? 'inactive' : 'active';
					$this->set_status( $source_id, $new_status );
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Source status updated.', 'conexao-event-importer' ) . '</p></div>';
				}
			} elseif ( 'save' === $action && isset( $_POST['source_id'] ) ) {
				$this->handle_save_source();
			} elseif ( 'run_import' === $action && isset( $_POST['source_id'] ) ) {
				$this->handle_run_import();
			} elseif ( 'delete' === $action && isset( $_POST['source_id'] ) ) {
				$this->handle_delete_source();
			}
		}

		$sources = $this->get_all();
		?>
		<div class="wrap conexao-event-sources">
			<h1><?php esc_html_e( 'Event Sources', 'conexao-event-importer' ); ?></h1>
			<p><?php esc_html_e( 'Manage the sources from which events are imported. Only active sources are processed during an import run.', 'conexao-event-importer' ); ?></p>
			<?php $this->render_sources_table( $sources ); ?>
			<script>
			(function() {
				'use strict';
				// Attach delete confirmation to all delete forms.
				document.querySelectorAll( '.conexao-source-delete-form' ).forEach( function( form ) {
					form.addEventListener( 'submit', function( e ) {
						var button = form.querySelector( '[data-source-name]' );
						var sourceName = button ? button.getAttribute( 'data-source-name' ) : '';
						var message = <?php echo wp_json_encode(
							/* translators: %s: Source name. */
							sprintf( __( 'Delete Event Source?\n\nAre you sure you want to delete "%s"?\n\nThis will remove the Event Source configuration. Imported events will remain in the database.', 'conexao-event-importer' ), '%s' )
						); ?>;
						message = message.replace( '%s', sourceName );
						if ( ! confirm( message ) ) {
							e.preventDefault();
						}
					} );
				} );
			})();
			</script>
			<?php
			$edit_id = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : '';
			if ( $edit_id ) {
				$this->render_edit_form( $edit_id );
			} else {
				$this->render_add_form();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Handle saving a source from the admin form.
	 */
	protected function handle_save_source() {
		$source_id = sanitize_title( wp_unslash( $_POST['source_id'] ) );

		// Get the URL and handle webcal:// protocol specially.
		$raw_url = isset( $_POST['source_url'] ) ? trim( wp_unslash( $_POST['source_url'] ) ) : '';

		// For webcal:// URLs, we need to handle them specially since esc_url_raw
		// strips unknown protocols. We'll normalize webcal:// to https:// for storage
		// but keep the original if the user explicitly wants webcal.
		$is_webcal = ( 0 === strpos( $raw_url, 'webcal://' ) );

		// For iCalendar sources, accept webcal:// URLs as-is.
		$source_type = isset( $_POST['source_type'] ) ? sanitize_text_field( wp_unslash( $_POST['source_type'] ) ) : 'website';

		if ( $is_webcal && 'icalendar' === $source_type ) {
			// Keep webcal:// URL for iCalendar sources.
			$url = $raw_url;
		} else {
			$url = esc_url_raw( $raw_url );
		}

		$source    = array(
			'id'     => $source_id,
			'name'   => isset( $_POST['source_name'] ) ? sanitize_text_field( wp_unslash( $_POST['source_name'] ) ) : '',
			'url'    => $url,
			'type'   => $source_type,
			'status' => isset( $_POST['source_status'] ) ? 'active' : 'inactive',
			// Taxonomy hints.
			'county'           => isset( $_POST['source_county'] ) ? sanitize_text_field( wp_unslash( $_POST['source_county'] ) ) : '',
			'category'         => isset( $_POST['source_category'] ) ? sanitize_text_field( wp_unslash( $_POST['source_category'] ) ) : '',
		);

		// Preserve existing stats if editing.
		$existing = $this->get( $source_id );
		if ( $existing ) {
			$source['last_import']        = $existing['last_import'];
			$source['last_import_status'] = $existing['last_import_status'];
			$source['events_imported']    = $existing['events_imported'];
			$source['last_error']         = $existing['last_error'];
			// Preserve existing ICS content if not uploading a new file.
			if ( ! empty( $existing['ics_content'] ) ) {
				$source['ics_content'] = $existing['ics_content'];
			}
		}

		// Handle ICS file upload for iCalendar sources.
		if ( 'icalendar' === $source_type && ! empty( $_FILES['ics_file']['tmp_name'] ) ) {
			$ics_content = $this->handle_ics_upload( $_FILES['ics_file'] );
			if ( $ics_content ) {
				$source['ics_content'] = $ics_content;
			}
		}

		$this->save( $source );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Source saved.', 'conexao-event-importer' ) . '</p></div>';
	}

	/**
	 * Handle ICS file upload.
	 *
	 * @param array $file $_FILES array for the uploaded file.
	 * @return string|false ICS content or false on failure.
	 */
	protected function handle_ics_upload( $file ) {
		// Check for upload errors.
		if ( ! isset( $file['error'] ) || UPLOAD_ERR_OK !== $file['error'] ) {
			$error_msg = $this->get_upload_error_message( $file['error'] ?? UPLOAD_ERR_NO_FILE );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sprintf( __( 'ICS file upload failed: %s', 'conexao-event-importer' ), $error_msg ) ) . '</p></div>';
			return false;
		}

		// Check file size (max 5MB).
		$max_size = 5 * 1024 * 1024;
		if ( $file['size'] > $max_size ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'ICS file is too large. Maximum size is 5MB.', 'conexao-event-importer' ) . '</p></div>';
			return false;
		}

		// Read the file content.
		$content = file_get_contents( $file['tmp_name'] );
		if ( false === $content ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to read uploaded ICS file.', 'conexao-event-importer' ) . '</p></div>';
			return false;
		}

		// Basic validation: check if it looks like iCalendar data.
		if ( false === strpos( $content, 'BEGIN:VCALENDAR' ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'The uploaded file does not appear to be a valid iCalendar (.ics) file.', 'conexao-event-importer' ) . '</p></div>';
			return false;
		}

		return $content;
	}

	/**
	 * Get upload error message.
	 *
	 * @param int $error Upload error code.
	 * @return string
	 */
	protected function get_upload_error_message( $error ) {
		$messages = array(
			UPLOAD_ERR_INI_SIZE   => __( 'The uploaded file exceeds the upload_max_filesize directive in php.ini.', 'conexao-event-importer' ),
			UPLOAD_ERR_FORM_SIZE  => __( 'The uploaded file exceeds the MAX_FILE_SIZE directive specified in the HTML form.', 'conexao-event-importer' ),
			UPLOAD_ERR_PARTIAL    => __( 'The uploaded file was only partially uploaded.', 'conexao-event-importer' ),
			UPLOAD_ERR_NO_FILE    => __( 'No file was uploaded.', 'conexao-event-importer' ),
			UPLOAD_ERR_NO_TMP_DIR => __( 'Missing a temporary folder.', 'conexao-event-importer' ),
			UPLOAD_ERR_CANT_WRITE => __( 'Failed to write file to disk.', 'conexao-event-importer' ),
			UPLOAD_ERR_EXTENSION  => __( 'A PHP extension stopped the file upload.', 'conexao-event-importer' ),
		);
		return isset( $messages[ $error ] ) ? $messages[ $error ] : __( 'Unknown upload error.', 'conexao-event-importer' );
	}

	/**
	 * Handle running an import from the admin form.
	 *
	 * Supports both single-source imports (source_id = slug) and full imports
	 * (source_id = "all" or empty).
	 */
	protected function handle_run_import() {
		$source_id = isset( $_POST['source_id'] ) ? sanitize_text_field( wp_unslash( $_POST['source_id'] ) ) : '';

		if ( '' === $source_id || 'all' === $source_id ) {
			// Full import across all active sources.
			$result = apply_filters( 'conexao_event_importer_run_all', array() );
		} else {
			// Single-source import.
			$result = apply_filters( 'conexao_event_importer_run_source', $source_id );
		}

		$this->render_import_result_notice( $result, $source_id );
	}

	/**
	 * Render a detailed admin notice after an import run.
	 *
	 * Shows created/updated/skipped/failed counts and a collapsible "View details"
	 * section listing the events that failed and why.
	 *
	 * @param array  $result    Import result array.
	 * @param string $source_id Source slug or 'all'.
	 */
	protected function render_import_result_notice( $result, $source_id ) {
		if ( ! is_array( $result ) || empty( $result ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'The import could not be completed. No result was returned.', 'conexao-event-importer' ) . '</p></div>';
			return;
		}

		$status  = isset( $result['status'] ) ? $result['status'] : 'success';
		$created = isset( $result['created'] ) ? (int) $result['created'] : ( isset( $result['new'] ) ? (int) $result['new'] : 0 );
		$updated = isset( $result['updated'] ) ? (int) $result['updated'] : 0;
		$unchanged = isset( $result['unchanged'] ) ? (int) $result['unchanged'] : 0;
		$duplicates = isset( $result['duplicates'] ) ? (int) $result['duplicates'] : 0;
		$skipped = isset( $result['skipped'] ) ? (int) $result['skipped'] : 0;
		$failed  = isset( $result['failed'] ) ? (int) $result['failed'] : ( isset( $result['errors'] ) ? (int) $result['errors'] : 0 );

		// Determine notice type.
		$notice_type = 'success';
		$title       = __( 'Import completed', 'conexao-event-importer' );
		if ( 'failed' === $status ) {
			$notice_type = 'error';
			$title       = __( 'Import could not be completed', 'conexao-event-importer' );
		} elseif ( 'partial' === $status ) {
			$notice_type = 'warning';
			$title       = __( 'Import completed with errors', 'conexao-event-importer' );
		} elseif ( 'warning' === $status ) {
			$notice_type = 'warning';
			$title       = __( 'Import completed with warnings', 'conexao-event-importer' );
		}

		$source_label = __( 'All sources', 'conexao-event-importer' );
		if ( $source_id && 'all' !== $source_id ) {
			$source = $this->get( $source_id );
			if ( $source ) {
				$source_label = $source['name'];
			}
		}

		// Build the summary line.
		$summary_parts = array();
		if ( $created > 0 ) {
			$summary_parts[] = sprintf( /* translators: %d: count */ __( '%d created', 'conexao-event-importer' ), $created );
		}
		if ( $updated > 0 ) {
			$summary_parts[] = sprintf( /* translators: %d: count */ __( '%d updated', 'conexao-event-importer' ), $updated );
		}
		if ( $unchanged > 0 ) {
			$summary_parts[] = sprintf( /* translators: %d: count */ __( '%d unchanged', 'conexao-event-importer' ), $unchanged );
		}
		if ( $duplicates > 0 ) {
			$summary_parts[] = sprintf( /* translators: %d: count */ __( '%d duplicates', 'conexao-event-importer' ), $duplicates );
		}
		if ( $skipped > 0 ) {
			$skipped_past    = isset( $result['skipped_past'] ) ? (int) $result['skipped_past'] : 0;
			$skipped_invalid = isset( $result['skipped_invalid_date'] ) ? (int) $result['skipped_invalid_date'] : 0;

			if ( $skipped_past > 0 || $skipped_invalid > 0 ) {
				$summary_parts[] = sprintf(
					/* translators: 1: total skipped, 2: skipped because already ended, 3: skipped with invalid dates */
					__( '%1$d skipped (%2$d past, %3$d invalid date)', 'conexao-event-importer' ),
					$skipped,
					$skipped_past,
					$skipped_invalid
				);
			} else {
				$summary_parts[] = sprintf( /* translators: %d: count */ __( '%d skipped', 'conexao-event-importer' ), $skipped );
			}
		}
		if ( $failed > 0 ) {
			$summary_parts[] = sprintf( /* translators: %d: count */ __( '%d failed', 'conexao-event-importer' ), $failed );
		}
		if ( empty( $summary_parts ) ) {
			$summary_parts[] = __( 'No events found', 'conexao-event-importer' );
		}

		// Collect failed events / fatal errors for the details section.
		$fatal_errors   = isset( $result['fatal_errors'] ) && is_array( $result['fatal_errors'] ) ? $result['fatal_errors'] : array();
		$failed_events  = array();
		if ( isset( $result['event_results'] ) && is_array( $result['event_results'] ) ) {
			foreach ( $result['event_results'] as $evt ) {
				if ( isset( $evt['outcome'] ) && 'failed' === $evt['outcome'] ) {
					$failed_events[] = $evt;
				}
			}
		}

		$has_details = ( ! empty( $fatal_errors ) || ! empty( $failed_events ) );

		?>
		<div class="notice notice-<?php echo esc_attr( $notice_type ); ?> is-dismissible conexao-import-notice">
			<p>
				<strong><?php echo esc_html( $title ); ?></strong> — <?php echo esc_html( $source_label ); ?>
			</p>
			<p><?php echo esc_html( implode( ', ', $summary_parts ) ); ?></p>

			<?php if ( 'failed' === $status && ! empty( $fatal_errors ) ) : ?>
				<p>
					<?php foreach ( $fatal_errors as $fe ) : ?>
						<strong><?php echo esc_html( $fe['message'] ); ?></strong>
						<?php if ( ! empty( $fe['technical'] ) ) : ?>
							<details class="conexao-error-details">
								<summary><?php esc_html_e( 'Technical details', 'conexao-event-importer' ); ?></summary>
								<code><?php echo esc_html( $fe['technical'] ); ?></code>
							</details>
						<?php endif; ?>
					<?php endforeach; ?>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $failed_events ) ) : ?>
				<details class="conexao-error-details">
					<summary>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of failed events */
								__( 'View %d failed event(s)', 'conexao-event-importer' ),
								count( $failed_events )
							)
						);
						?>
					</summary>
					<ul class="conexao-failed-events-list">
						<?php foreach ( array_slice( $failed_events, 0, 50 ) as $failed_event ) : ?>
							<li>
								<strong><?php echo esc_html( $failed_event['title'] ); ?></strong>
								— <?php echo esc_html( $failed_event['message'] ); ?>
								<?php if ( ! empty( $failed_event['technical'] ) ) : ?>
									<details>
										<summary><?php esc_html_e( 'Technical detail', 'conexao-event-importer' ); ?></summary>
										<code><?php echo esc_html( $failed_event['technical'] ); ?></code>
									</details>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
						<?php if ( count( $failed_events ) > 50 ) : ?>
							<li><em><?php esc_html_e( 'Additional failures are available in the import history.', 'conexao-event-importer' ); ?></em></li>
						<?php endif; ?>
					</ul>
				</details>
			<?php endif; ?>

			<?php if ( $has_details ) : ?>
				<p class="description">
					<?php esc_html_e( 'The remaining events continued importing normally.', 'conexao-event-importer' ); ?>
				</p>
			<?php endif; ?>

			<p style="margin-top:10px;">
				<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=conexao-import-log' ) ); ?>">
					<?php esc_html_e( 'View / Download Import Logs', 'conexao-event-importer' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle deleting a source from the admin form.
	 *
	 * Deletes the source configuration while preserving imported events.
	 * Events remain in the database but are no longer associated with an active source.
	 */
	protected function handle_delete_source() {
		$source_id = sanitize_text_field( wp_unslash( $_POST['source_id'] ) );
		$source    = $this->get( $source_id );

		if ( ! $source ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Unable to delete the Event Source. Please try again.', 'conexao-event-importer' ) . '</p></div>';
			return;
		}

		$source_name = $source['name'];
		$deleted     = $this->delete( $source_id );

		if ( $deleted ) {
			// Log the deletion for debugging purposes.
			Conexao_Import_Log::add( $source_id, 'info', sprintf( 'Event Source "%s" was deleted by %s.', $source_name, wp_get_current_user()->user_login ) );

			echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(
				/* translators: %s: Source name. */
				esc_html__( 'Event Source "%s" was deleted successfully.', 'conexao-event-importer' ),
				'<strong>' . esc_html( $source_name ) . '</strong>'
			) . '</p></div>';
		} else {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Unable to delete the Event Source. Please try again.', 'conexao-event-importer' ) . '</p></div>';
		}
	}

	/**
	 * Get the display label for a source type.
	 *
	 * @param string $type Source type.
	 * @return string
	 */
	protected function get_type_label( $type ) {
		$labels = array(
			'icalendar'    => __( 'iCalendar / Webcal', 'conexao-event-importer' ),
			'website'      => __( 'Website', 'conexao-event-importer' ),
			'facebook'     => __( 'Facebook', 'conexao-event-importer' ),
			'instagram'    => __( 'Instagram', 'conexao-event-importer' ),
			'eventbrite'   => __( 'Eventbrite', 'conexao-event-importer' ),
			'heritage_week' => __( 'National Heritage Week', 'conexao-event-importer' ),
		);
		return isset( $labels[ $type ] ) ? $labels[ $type ] : ucfirst( $type );
	}

	/**
	 * Get the display label for a source, allowing per-source overrides.
	 *
	 * @param array $source Source config.
	 * @return string
	 */
	protected function get_source_type_label( $source ) {
		if ( 'heritage_week' === $source['id'] ) {
			return __( 'Heritage Week / Website', 'conexao-event-importer' );
		}
		return $this->get_type_label( isset( $source['type'] ) ? $source['type'] : 'website' );
	}

	/**
	 * Render the sources table.
	 *
	 * @param array $sources Source list.
	 */
	protected function render_sources_table( $sources ) {
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'conexao-event-importer' ); ?></th>
					<th><?php esc_html_e( 'Feed URL', 'conexao-event-importer' ); ?></th>
					<th><?php esc_html_e( 'Type', 'conexao-event-importer' ); ?></th>
					<th><?php esc_html_e( 'Status', 'conexao-event-importer' ); ?></th>
					<th><?php esc_html_e( 'Last Import', 'conexao-event-importer' ); ?></th>
					<th><?php esc_html_e( 'Events Imported', 'conexao-event-importer' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'conexao-event-importer' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $sources as $source ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $source['name'] ); ?></strong>
							<?php if ( 'icalendar' === $source['type'] ) : ?>
								<br><span class="description"><?php esc_html_e( 'Calendar feed source', 'conexao-event-importer' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<a href="<?php echo esc_url( $this->normalize_source_url( $source['url'] ) ); ?>" target="_blank" rel="noopener noreferrer">
								<?php echo esc_html( $this->truncate_url( $source['url'] ) ); ?>
							</a>
						</td>
						<td>
							<span class="conexao-source-type conexao-source-type--<?php echo esc_attr( $source['type'] ); ?>">
								<?php echo esc_html( $this->get_source_type_label( $source ) ); ?>
							</span>
							<?php if ( 'heritage_week' === $source['id'] ) : ?>
								<br><span class="description"><?php esc_html_e( 'County: Laois · Category: Heritage', 'conexao-event-importer' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( 'active' === $source['status'] ) : ?>
								<span class="conexao-source-status conexao-source-status--active">● <?php esc_html_e( 'Active', 'conexao-event-importer' ); ?></span>
							<?php else : ?>
								<span class="conexao-source-status conexao-source-status--inactive">○ <?php esc_html_e( 'Inactive', 'conexao-event-importer' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( ! empty( $source['last_import'] ) ) : ?>
								<?php echo esc_html( $source['last_import'] ); ?>
								<?php if ( 'error' === $source['last_import_status'] && ! empty( $source['last_error'] ) ) : ?>
									<br><span class="conexao-source-error"><?php echo esc_html( $source['last_error'] ); ?></span>
								<?php endif; ?>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $source['events_imported'] ); ?></td>
						<td>
							<form method="post" style="display:inline-block;" data-conexao-import-form>
								<?php wp_nonce_field( 'conexao_event_sources', 'conexao_event_sources_nonce' ); ?>
								<input type="hidden" name="conexao_source_action" value="run_import">
								<input type="hidden" name="source_id" value="<?php echo esc_attr( $source['id'] ); ?>">
								<button type="submit" class="button button-primary"><?php esc_html_e( 'Import Now', 'conexao-event-importer' ); ?></button>
							</form>
							<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=conexao-event-sources&edit=' . $source['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'conexao-event-importer' ); ?></a>
							<form method="post" style="display:inline-block;">
								<?php wp_nonce_field( 'conexao_event_sources', 'conexao_event_sources_nonce' ); ?>
								<input type="hidden" name="conexao_source_action" value="toggle">
								<input type="hidden" name="source_id" value="<?php echo esc_attr( $source['id'] ); ?>">
								<button type="submit" class="button"><?php echo 'active' === $source['status'] ? esc_html__( 'Disable', 'conexao-event-importer' ) : esc_html__( 'Enable', 'conexao-event-importer' ); ?></button>
							</form>
							<form method="post" style="display:inline-block;" class="conexao-source-delete-form">
								<?php wp_nonce_field( 'conexao_event_sources', 'conexao_event_sources_nonce' ); ?>
								<input type="hidden" name="conexao_source_action" value="delete">
								<input type="hidden" name="source_id" value="<?php echo esc_attr( $source['id'] ); ?>">
								<button type="submit" class="button button-link-delete" data-source-name="<?php echo esc_attr( $source['name'] ); ?>"><?php esc_html_e( 'Delete', 'conexao-event-importer' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Normalize a source URL for display (webcal:// -> https://).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	protected function normalize_source_url( $url ) {
		if ( 0 === strpos( $url, 'webcal://' ) ) {
			return 'https://' . substr( $url, 9 );
		}
		return $url;
	}

	/**
	 * Truncate a URL for display.
	 *
	 * @param string $url URL.
	 * @param int    $max_length Maximum length.
	 * @return string
	 */
	protected function truncate_url( $url, $max_length = 50 ) {
		if ( strlen( $url ) <= $max_length ) {
			return $url;
		}
		return substr( $url, 0, $max_length - 3 ) . '...';
	}

	/**
	 * Render the add-source form.
	 */
	protected function render_add_form() {
		?>
		<h2><?php esc_html_e( 'Add New Source', 'conexao-event-importer' ); ?></h2>
		<form method="post" class="conexao-source-form">
			<?php wp_nonce_field( 'conexao_event_sources', 'conexao_event_sources_nonce' ); ?>
			<input type="hidden" name="conexao_source_action" value="save">
			<table class="form-table">
				<tr>
					<th><label for="source_id"><?php esc_html_e( 'Source ID', 'conexao-event-importer' ); ?></label></th>
					<td><input type="text" id="source_id" name="source_id" class="regular-text" required></td>
				</tr>
				<tr>
					<th><label for="source_name"><?php esc_html_e( 'Name', 'conexao-event-importer' ); ?></label></th>
					<td><input type="text" id="source_name" name="source_name" class="regular-text" required></td>
				</tr>
				<tr>
					<th><label for="source_url"><?php esc_html_e( 'URL', 'conexao-event-importer' ); ?></label></th>
					<td>
						<input type="text" id="source_url" name="source_url" class="regular-text" required placeholder="https://... or webcal://...">
						<p class="description"><?php esc_html_e( 'For iCalendar sources, use webcal:// or https:// URLs.', 'conexao-event-importer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="source_type"><?php esc_html_e( 'Type', 'conexao-event-importer' ); ?></label></th>
					<td>
						<select id="source_type" name="source_type">
							<option value="icalendar"><?php esc_html_e( 'iCalendar / Webcal', 'conexao-event-importer' ); ?></option>
							<option value="website"><?php esc_html_e( 'Website', 'conexao-event-importer' ); ?></option>
							<option value="facebook"><?php esc_html_e( 'Facebook', 'conexao-event-importer' ); ?></option>
							<option value="instagram"><?php esc_html_e( 'Instagram', 'conexao-event-importer' ); ?></option>
							<option value="eventbrite"><?php esc_html_e( 'Eventbrite', 'conexao-event-importer' ); ?></option>
							<option value="heritage_week"><?php esc_html_e( 'National Heritage Week', 'conexao-event-importer' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="source_county"><?php esc_html_e( 'County (optional)', 'conexao-event-importer' ); ?></label></th>
					<td><input type="text" id="source_county" name="source_county" class="regular-text" placeholder="Laois"></td>
				</tr>
				<tr>
					<th><label for="source_category"><?php esc_html_e( 'Category (optional)', 'conexao-event-importer' ); ?></label></th>
					<td><input type="text" id="source_category" name="source_category" class="regular-text" placeholder="Heritage"></td>
				</tr>
				<tr>
					<th><label for="source_status"><?php esc_html_e( 'Active', 'conexao-event-importer' ); ?></label></th>
					<td><input type="checkbox" id="source_status" name="source_status" checked></td>
				</tr>
			</table>
			<?php submit_button( __( 'Add Source', 'conexao-event-importer' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Render the edit-source form.
	 *
	 * @param string $source_id Source slug.
	 */
	protected function render_edit_form( $source_id ) {
		$source = $this->get( $source_id );
		if ( ! $source ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Edit Source', 'conexao-event-importer' ); ?></h2>
		<form method="post" class="conexao-source-form" enctype="multipart/form-data">
			<?php wp_nonce_field( 'conexao_event_sources', 'conexao_event_sources_nonce' ); ?>
			<input type="hidden" name="conexao_source_action" value="save">
			<input type="hidden" name="source_id" value="<?php echo esc_attr( $source['id'] ); ?>">
			<table class="form-table">
				<tr>
					<th><label><?php esc_html_e( 'Source ID', 'conexao-event-importer' ); ?></label></th>
					<td><strong><?php echo esc_html( $source['id'] ); ?></strong></td>
				</tr>
				<tr>
					<th><label for="source_name"><?php esc_html_e( 'Name', 'conexao-event-importer' ); ?></label></th>
					<td><input type="text" id="source_name" name="source_name" class="regular-text" value="<?php echo esc_attr( $source['name'] ); ?>" required></td>
				</tr>
				<tr>
					<th><label for="source_url"><?php esc_html_e( 'URL', 'conexao-event-importer' ); ?></label></th>
					<td>
						<input type="text" id="source_url" name="source_url" class="regular-text" value="<?php echo esc_attr( $source['url'] ); ?>" placeholder="https://... or webcal://...">
						<p class="description"><?php esc_html_e( 'For iCalendar sources, use webcal:// or https:// URLs. Leave empty if uploading an ICS file.', 'conexao-event-importer' ); ?></p>
					</td>
				</tr>
				<tr class="icalendar-upload-row" <?php echo 'icalendar' !== $source['type'] ? 'style="display:none;"' : ''; ?>>
					<th><label for="ics_file"><?php esc_html_e( 'Upload ICS File', 'conexao-event-importer' ); ?></label></th>
					<td>
						<input type="file" id="ics_file" name="ics_file" accept=".ics,.ical,text/calendar">
						<p class="description">
							<?php esc_html_e( 'Upload an .ics file to import events from. This overrides the URL above.', 'conexao-event-importer' ); ?>
							<?php if ( ! empty( $source['ics_content'] ) ) : ?>
								<br><strong><?php esc_html_e( 'A file is currently loaded.', 'conexao-event-importer' ); ?></strong>
							<?php endif; ?>
						</p>
					</td>
				</tr>
				<tr>
					<th><label for="source_type"><?php esc_html_e( 'Type', 'conexao-event-importer' ); ?></label></th>
					<td>
						<select id="source_type" name="source_type">
							<option value="icalendar" <?php selected( $source['type'], 'icalendar' ); ?>><?php esc_html_e( 'iCalendar / Webcal', 'conexao-event-importer' ); ?></option>
							<option value="website" <?php selected( $source['type'], 'website' ); ?>><?php esc_html_e( 'Website', 'conexao-event-importer' ); ?></option>
							<option value="facebook" <?php selected( $source['type'], 'facebook' ); ?>><?php esc_html_e( 'Facebook', 'conexao-event-importer' ); ?></option>
							<option value="instagram" <?php selected( $source['type'], 'instagram' ); ?>><?php esc_html_e( 'Instagram', 'conexao-event-importer' ); ?></option>
							<option value="eventbrite" <?php selected( $source['type'], 'eventbrite' ); ?>><?php esc_html_e( 'Eventbrite', 'conexao-event-importer' ); ?></option>
							<option value="heritage_week" <?php selected( $source['type'], 'heritage_week' ); ?>><?php esc_html_e( 'National Heritage Week', 'conexao-event-importer' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="source_county"><?php esc_html_e( 'County (optional)', 'conexao-event-importer' ); ?></label></th>
					<td><input type="text" id="source_county" name="source_county" class="regular-text" value="<?php echo esc_attr( isset( $source['county'] ) ? $source['county'] : '' ); ?>" placeholder="Laois"></td>
				</tr>
				<tr>
					<th><label for="source_category"><?php esc_html_e( 'Category (optional)', 'conexao-event-importer' ); ?></label></th>
					<td><input type="text" id="source_category" name="source_category" class="regular-text" value="<?php echo esc_attr( isset( $source['category'] ) ? $source['category'] : '' ); ?>" placeholder="Heritage"></td>
				</tr>
				<tr>
					<th><label for="source_status"><?php esc_html_e( 'Active', 'conexao-event-importer' ); ?></label></th>
					<td><input type="checkbox" id="source_status" name="source_status" <?php checked( 'active', $source['status'] ); ?>></td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Source', 'conexao-event-importer' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Render the import dashboard page.
	 */
	public function render_import_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Handle "Run Import Now" submitted from this page.
		if ( isset( $_POST['conexao_source_action'] ) && 'run_import' === sanitize_text_field( wp_unslash( $_POST['conexao_source_action'] ) ) && check_admin_referer( 'conexao_event_sources', 'conexao_event_sources_nonce' ) ) {
			$this->handle_run_import();
		}

		$sources = $this->get_all();
		?>
		<div class="wrap conexao-import-dashboard">
			<h1><?php esc_html_e( 'Event Import', 'conexao-event-importer' ); ?></h1>

			<div class="conexao-import-summary">
				<div class="conexao-import-stat">
					<span class="conexao-import-stat-label"><?php esc_html_e( 'Last successful import', 'conexao-event-importer' ); ?></span>
					<span class="conexao-import-stat-value">
						<?php
						$last_success = '';
						foreach ( $sources as $source ) {
							if ( ! empty( $source['last_import'] ) && 'error' !== $source['last_import_status'] ) {
								$last_success = $source['last_import'];
								break;
							}
						}
						echo $last_success ? esc_html( $last_success ) : '&mdash;';
						?>
					</span>
				</div>
			</div>

			<h2><?php esc_html_e( 'Sources', 'conexao-event-importer' ); ?></h2>
			<ul class="conexao-source-list">
				<?php foreach ( $sources as $source ) : ?>
					<li>
						<span class="conexao-source-indicator <?php echo 'active' === $source['status'] ? 'conexao-source-indicator--active' : 'conexao-source-indicator--inactive'; ?>">
							<?php echo 'active' === $source['status'] ? '&#10003;' : '&#10007;'; ?>
						</span>
						<?php echo esc_html( $source['name'] ); ?>
						<?php if ( 'error' === $source['last_import_status'] && ! empty( $source['last_error'] ) ) : ?>
							<span class="conexao-source-error">(<?php echo esc_html( $source['last_error'] ); ?>)</span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<h2><?php esc_html_e( 'Run Import', 'conexao-event-importer' ); ?></h2>
			<form method="post" data-conexao-import-form>
				<?php wp_nonce_field( 'conexao_event_sources', 'conexao_event_sources_nonce' ); ?>
				<input type="hidden" name="conexao_source_action" value="run_import">
				<input type="hidden" name="source_id" value="all">
				<button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Import Events Now', 'conexao-event-importer' ); ?></button>
			</form>

			<p style="margin-top: 10px;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=conexao-event-export' ) ); ?>" class="button button-secondary">
					<?php esc_html_e( 'Export Events', 'conexao-event-importer' ); ?>
				</a>
			</p>

			<?php $this->render_history_summary(); ?>
		</div>
		<?php
	}

	/**
	 * Render a compact history summary on the dashboard.
	 */
	protected function render_history_summary() {
		$history = Conexao_Import_History::get_all();
		if ( empty( $history ) ) {
			echo '<p>' . esc_html__( 'No imports yet.', 'conexao-event-importer' ) . '</p>';
			return;
		}
		?>
		<h2><?php esc_html_e( 'Import History', 'conexao-event-importer' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Time', 'conexao-event-importer' ); ?></th>
					<th><?php esc_html_e( 'Source', 'conexao-event-importer' ); ?></th>
					<th><?php esc_html_e( 'Found', 'conexao-event-importer' ); ?></th>
					<th><?php esc_html_e( 'New', 'conexao-event-importer' ); ?></th>
					<th><?php esc_html_e( 'Updated', 'conexao-event-importer' ); ?></th>
					<th><?php esc_html_e( 'Unchanged', 'conexao-event-importer' ); ?></th>
					<th><?php esc_html_e( 'Duplicates', 'conexao-event-importer' ); ?></th>
					<th><?php esc_html_e( 'Skipped', 'conexao-event-importer' ); ?></th>
					<th><?php esc_html_e( 'Errors', 'conexao-event-importer' ); ?></th>
					<th><?php esc_html_e( 'Status', 'conexao-event-importer' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_slice( $history, 0, 10 ) as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( $entry['time'] ); ?></td>
						<td><?php echo esc_html( $entry['source'] ); ?></td>
						<td><?php echo esc_html( $entry['found'] ); ?></td>
						<td><?php echo esc_html( $entry['new'] ); ?></td>
						<td><?php echo esc_html( $entry['updated'] ); ?></td>
						<td><?php echo esc_html( isset( $entry['unchanged'] ) ? $entry['unchanged'] : 0 ); ?></td>
						<td><?php echo esc_html( $entry['duplicates'] ); ?></td>
						<td><?php echo esc_html( isset( $entry['skipped'] ) ? $entry['skipped'] : 0 ); ?></td>
						<td><?php echo esc_html( $entry['errors'] ); ?></td>
						<td>
							<span class="conexao-status-badge conexao-status-badge--<?php echo esc_attr( $entry['status'] ); ?>">
								<?php echo esc_html( $entry['status'] ); ?>
							</span>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the history page.
	 */
	public function render_history_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$history = Conexao_Import_History::get_all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import History', 'conexao-event-importer' ); ?></h1>
			<p class="description">
				<?php
				$logs_url = admin_url( 'admin.php?page=conexao-import-log' );
				printf(
					/* translators: %s: URL to the Import Logs page */
					wp_kses(
						__( 'For detailed import logs (including failures and API errors), see the <a href="%s">Import Logs</a> page.', 'conexao-event-importer' ),
						array( 'a' => array( 'href' => array() ) )
					),
					esc_url( $logs_url )
				);
				?>
			</p>
			<?php if ( empty( $history ) ) : ?>
				<p><?php esc_html_e( 'No imports yet.', 'conexao-event-importer' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time', 'conexao-event-importer' ); ?></th>
							<th><?php esc_html_e( 'Source', 'conexao-event-importer' ); ?></th>
							<th><?php esc_html_e( 'Found', 'conexao-event-importer' ); ?></th>
							<th><?php esc_html_e( 'New', 'conexao-event-importer' ); ?></th>
							<th><?php esc_html_e( 'Updated', 'conexao-event-importer' ); ?></th>
							<th><?php esc_html_e( 'Unchanged', 'conexao-event-importer' ); ?></th>
							<th><?php esc_html_e( 'Duplicates', 'conexao-event-importer' ); ?></th>
							<th><?php esc_html_e( 'Skipped', 'conexao-event-importer' ); ?></th>
							<th><?php esc_html_e( 'Errors', 'conexao-event-importer' ); ?></th>
							<th><?php esc_html_e( 'Status', 'conexao-event-importer' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $history as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( $entry['time'] ); ?></td>
								<td><?php echo esc_html( $entry['source'] ); ?></td>
								<td><?php echo esc_html( $entry['found'] ); ?></td>
								<td><?php echo esc_html( $entry['new'] ); ?></td>
								<td><?php echo esc_html( $entry['updated'] ); ?></td>
								<td><?php echo esc_html( isset( $entry['unchanged'] ) ? $entry['unchanged'] : 0 ); ?></td>
								<td><?php echo esc_html( $entry['duplicates'] ); ?></td>
								<td><?php echo esc_html( isset( $entry['skipped'] ) ? $entry['skipped'] : 0 ); ?></td>
								<td><?php echo esc_html( $entry['errors'] ); ?></td>
								<td>
									<span class="conexao-status-badge conexao-status-badge--<?php echo esc_attr( $entry['status'] ); ?>">
										<?php echo esc_html( $entry['status'] ); ?>
									</span>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}