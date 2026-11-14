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
			// A missing or empty option is a first-run state, not a reason to
			// ship a partial registry. Seed the six legacy defaults AND the
			// complete 52-source county registry in one pass, so a brand-new
			// install can never come up with the county coverage missing.
			$sources = array_merge( $this->get_defaults(), $this->get_county_registry_sources() );
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

		/*
		 * Merge in any MISSING county registry source.
		 *
		 * This is the single self-healing point that keeps the shipped
		 * registry complete on EVERY environment, not only on a fresh
		 * activation. It applies the same rule already used for the six
		 * legacy defaults above: only insert an id that does not exist yet,
		 * so an operator's edited or activated source is never reset.
		 *
		 * Why this must live in get_all() and not only in activate():
		 * seed_county_sources() is a MANUAL operator step, documented in
		 * docs/plugins/conexao-event-importer.md as a `wp eval` one-liner.
		 * Until somebody runs it, any environment that was built or rebuilt
		 * by something other than an interactive activation -- the local
		 * Docker stack, a CI fixture build, a restored database -- reports
		 * only the six legacy defaults and silently loses the whole
		 * 26-county Eventbrite + National Heritage Week coverage. That is
		 * exactly the regression this merge repairs.
		 *
		 * Every county source SHIPS `inactive` (Conexao_County_Registry), so
		 * this can never start an import, never fetch a provider and never
		 * write an Event post. It restores the registration only.
		 */
		foreach ( $this->get_county_registry_sources() as $county_id => $county_source ) {
			if ( isset( $sources[ $county_id ] ) ) {
				continue;
			}
			$sources[ $county_id ] = $county_source;
			$changed               = true;
		}

		if ( $changed ) {
			update_option( self::OPTION_KEY, $sources, false );
		}

		return $sources;
	}

	/**
	 * The county registry source configs as a map keyed by source id.
	 *
	 * A thin, defensive wrapper so the source registry has exactly ONE place
	 * that knows the county registry exists. Returns an empty array when the
	 * registry class is unavailable, so this can never fatal the importer.
	 *
	 * @return array Map of source id => source config.
	 */
	protected function get_county_registry_sources() {
		if ( ! class_exists( 'Conexao_County_Registry' ) ) {
			return array();
		}
		$county_sources = Conexao_County_Registry::get_all_county_sources();
		return is_array( $county_sources ) ? $county_sources : array();
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
	}

	/**
	 * The URL an administrator visiting the RETIRED Import History URL should
	 * be sent to, or '' when the request must not be redirected.
	 *
	 * The Import History screen is part of the dashboard since v1.10.0, so the
	 * old `conexao-import-history` slug is no longer registered as a page — no
	 * submenu, no callback, no hidden page. A bookmarked admin URL therefore
	 * dies with WordPress's standard "you are not allowed to access this page"
	 * refusal, which this redirect turns into a friendly hop to the one screen
	 * that now owns the history.
	 *
	 * Deliberately a pure decision — capability + requested page slug → URL or
	 * `''`, no output, no exit — so the regression suite can prove both
	 * directions without having to fake an `exit`.
	 *
	 * @return string
	 */
	public function retired_history_redirect_url() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing for a GET that changes nothing.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( 'conexao-import-history' !== $page ) {
			return '';
		}

		// Only the operator who could have read the old screen is redirected;
		// anyone else keeps WordPress's own refusal.
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		return admin_url( 'admin.php?page=conexao-event-import' );
	}

	/**
	 * Redirect the retired Import History URL to the dashboard.
	 *
	 * `wp-admin/admin.php` requires `menu.php` BEFORE `admin_init`, and
	 * `menu.php` fires `admin_page_access_denied` immediately before it refuses
	 * an unregistered admin page. That makes this hook the one lifecycle point
	 * that runs for a retired `page=` slug, and `admin_init` too late to be
	 * usable here.
	 *
	 * @return void
	 */
	public function maybe_redirect_retired_history_page() {
		$target = $this->retired_history_redirect_url();

		if ( '' === $target ) {
			return;
		}

		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Render the sources admin page.
	 */
	public function render_sources_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Display bulk action result notice from redirect.
		$bulk_message = isset( $_GET['bulk_message'] ) ? sanitize_text_field( wp_unslash( $_GET['bulk_message'] ) ) : '';
		$bulk_status  = isset( $_GET['bulk_status'] ) ? sanitize_text_field( wp_unslash( $_GET['bulk_status'] ) ) : '';
		if ( '' !== $bulk_message && in_array( $bulk_status, array( 'success', 'error' ), true ) ) {
			$notice_class = 'success' === $bulk_status ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . esc_attr( $notice_class ) . ' is-dismissible"><p>' . esc_html( $bulk_message ) . '</p></div>';
		}

		// Handle actions: toggle, save, run import, delete, bulk_action.
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
			} elseif ( 'bulk_action' === $action && isset( $_POST['bulk_source_ids'] ) && isset( $_POST['bulk_action_type'] ) ) {
				$this->handle_bulk_action();
				// handle_bulk_action() issues wp_redirect() and exits.
			}
		}

		$sources = $this->get_all();
		?>
		<div class="wrap conexao-event-sources">
			<h1><?php esc_html_e( 'Event Sources', 'conexao-event-importer' ); ?></h1>
			<p><?php esc_html_e( 'Manage the sources from which events are imported. Only active sources are processed during an import run.', 'conexao-event-importer' ); ?></p>

			<?php $this->render_bulk_actions_controls( $sources ); ?>

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
	 * The result is `mixed`, not `array`: it is whatever an import filter
	 * returns (see handle_run_import()), which is unvalidated, so the
	 * is_array() guard below is load-bearing and must stay.
	 *
	 * @param mixed  $result    Import result array.
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
	 * Handle a bulk action (enable/disable/delete) on selected event sources.
	 *
	 * Verifies the capability and the existing nonce, then delegates the work to
	 * apply_bulk_action() and redirects back to the Event Sources page with ONE
	 * admin notice summarising the outcome.
	 *
	 * The redirect target and the `bulk_message` / `bulk_status` query arguments
	 * are the ones this page has always used and are rendered by
	 * render_sources_page(); no second notice mechanism is introduced.
	 */
	protected function handle_bulk_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'conexao-event-importer' ) );
		}

		check_admin_referer( 'conexao_event_sources', 'conexao_event_sources_nonce' );

		$bulk_action_type = '';
		if ( isset( $_POST['bulk_action_type'] ) && is_string( wp_unslash( $_POST['bulk_action_type'] ) ) ) {
			$bulk_action_type = sanitize_text_field( wp_unslash( $_POST['bulk_action_type'] ) );
		}

		$raw_ids = isset( $_POST['bulk_source_ids'] ) ? wp_unslash( $_POST['bulk_source_ids'] ) : array();

		$notice = $this->build_bulk_notice( $this->apply_bulk_action( $bulk_action_type, $raw_ids ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'bulk_message' => $notice['message'],
					'bulk_status'  => $notice['status'],
				),
				admin_url( 'admin.php?page=conexao-event-sources' )
			)
		);
		exit;
	}

	/**
	 * Apply a bulk action to the submitted source IDs.
	 *
	 * This is the whole registry side of the operation: no capability check, no
	 * nonce check, no redirect and no output. handle_bulk_action() runs exactly
	 * this implementation, and the regression suite exercises it directly —
	 * there is no second copy of the source-management logic.
	 *
	 * Semantics:
	 *
	 * - `enable` sets `active`, `disable` sets `inactive`, `delete` removes the
	 *   configuration. The requested status is ALWAYS the one applied: a source
	 *   is never toggled, so a bulk action can never reverse a state by accident.
	 * - A source that is already in the requested state is reported as skipped,
	 *   which makes the operation idempotent and the notice truthful.
	 * - An empty selection is an error, never a request to affect every source.
	 *
	 * @param mixed $action  Requested bulk action (enable|disable|delete).
	 * @param mixed $raw_ids Submitted `bulk_source_ids[]` value.
	 * @return array {
	 *     Result of the operation, always with the same keys.
	 *
	 *     @type string $action     Requested action, '' when it was not a string.
	 *     @type string $error      '' | invalid_action | empty_selection | no_valid_sources.
	 *     @type int    $requested  Submitted IDs before de-duplication.
	 *     @type int    $duplicates Number of duplicate IDs removed.
	 *     @type array  $invalid    Submitted IDs that are not in the registry.
	 *     @type array  $updated    IDs changed (status set, or config deleted).
	 *     @type array  $skipped    IDs already in the requested state.
	 *     @type array  $failed     IDs that could not be processed.
	 * }
	 */
	protected function apply_bulk_action( $action, $raw_ids ) {
		$allowed_actions = array( 'enable', 'disable', 'delete' );

		$result = array(
			'action'     => is_string( $action ) ? sanitize_text_field( $action ) : '',
			'error'      => '',
			'requested'  => 0,
			'duplicates' => 0,
			'invalid'    => array(),
			'updated'    => array(),
			'skipped'    => array(),
			'failed'     => array(),
		);

		if ( ! in_array( $result['action'], $allowed_actions, true ) ) {
			$result['error'] = 'invalid_action';
			return $result;
		}

		$submitted_ids = array();
		foreach ( (array) $raw_ids as $raw_id ) {
			if ( ! is_scalar( $raw_id ) ) {
				continue;
			}
			$source_id = trim( sanitize_text_field( (string) $raw_id ) );
			if ( '' === $source_id ) {
				continue;
			}
			$submitted_ids[] = $source_id;
		}

		$result['requested']  = count( $submitted_ids );
		$unique_ids           = array_values( array_unique( $submitted_ids ) );
		$result['duplicates'] = count( $submitted_ids ) - count( $unique_ids );

		if ( empty( $unique_ids ) ) {
			$result['error'] = 'empty_selection';
			return $result;
		}

		$sources   = $this->get_all();
		$valid_ids = array();
		foreach ( $unique_ids as $source_id ) {
			if ( isset( $sources[ $source_id ] ) ) {
				$valid_ids[] = $source_id;
			} else {
				$result['invalid'][] = $source_id;
			}
		}

		if ( empty( $valid_ids ) ) {
			$result['error'] = 'no_valid_sources';
			return $result;
		}

		$target_status = ( 'enable' === $result['action'] ) ? 'active' : 'inactive';

		$deleted_sources = array();
		$changed         = false;

		foreach ( $valid_ids as $source_id ) {
			$source = $sources[ $source_id ];

			if ( 'delete' === $result['action'] ) {
				// Keep enough information to generate the deletion log message
				// after the registry has been successfully persisted.
				$deleted_sources[ $source_id ] = $source;
				unset( $sources[ $source_id ] );
				$changed = true;
				continue;
			}

			// Already in the requested state: an idempotent no-op, reported
			// separately so the operator is never told something changed.
			if ( $target_status === $source['status'] ) {
				$result['skipped'][] = $source_id;
				continue;
			}

			// Explicitly set the requested status. Never toggle. Preserve all
			// other source metadata — only the status field changes.
			$sources[ $source_id ]['status'] = $target_status;
			$changed                         = true;
		}

		if ( ! $changed ) {
			// No-op batch: nothing to persist, nothing to report as updated.
			return $result;
		}

		// Persist the entire resulting registry in a single update_option()
		// call. No per-source save(), set_status() or delete() inside the loop.
		if ( ! $this->persist_sources( $sources ) ) {
			// The write could not be verified as persisted: report every
			// affected ID as failed and write no successful deletion logs.
			foreach ( $valid_ids as $source_id ) {
				$result['failed'][] = $source_id;
			}
			return $result;
		}

		// Persistence succeeded: report the changes now.
		if ( 'delete' === $result['action'] ) {
			foreach ( $deleted_sources as $source_id => $source ) {
				Conexao_Import_Log::add(
					$source_id,
					'info',
					sprintf( 'Event Source "%s" was bulk-deleted by %s.', $source['name'], wp_get_current_user()->user_login )
				);
				$result['updated'][] = $source_id;
			}
		} else {
			foreach ( $valid_ids as $source_id ) {
				if ( $target_status === $sources[ $source_id ]['status'] && ! in_array( $source_id, $result['skipped'], true ) ) {
					$result['updated'][] = $source_id;
				}
			}
		}

		return $result;
	}

	/**
	 * Persist the complete source registry in a single update_option() call.
	 *
	 * Used by apply_bulk_action() so a bulk operation writes the option at
	 * most once per batch instead of once per selected source.
	 *
	 * update_option() returns false both when the write genuinely fails and
	 * when the stored value is already identical. Since this method is only
	 * called when actual changes are planned, a false return is ambiguous: a
	 * read-back verification distinguishes a failed write from a value that
	 * happens to match what is already stored.
	 *
	 * @param array $sources Complete source registry to persist.
	 * @return bool True when the registry was persisted (or already matches),
	 *              false when the write could not be verified.
	 */
	protected function persist_sources( array $sources ) {
		$written = update_option( self::OPTION_KEY, $sources, false );

		if ( false !== $written ) {
			return true;
		}

		// update_option() returns false when the value is unchanged as well as
		// on a genuine failure. Since changes were planned, read back the
		// stored value to confirm it matches what we intended to write.
		$stored = get_option( self::OPTION_KEY, null );
		if ( is_array( $stored ) && $stored === $sources ) {
			return true;
		}

		return false;
	}

	/**
	 * Build the redirect notice for a bulk-action result.
	 *
	 * Messages are plain, unescaped text: render_sources_page() escapes them when
	 * it prints the admin notice, so escaping here would double-escape a source
	 * ID that contains an HTML special character.
	 *
	 * @param array $result Result from apply_bulk_action().
	 * @return array {
	 *     @type string $message Notice text.
	 *     @type string $status  success | error.
	 * }
	 */
	protected function build_bulk_notice( array $result ) {
		$invalid_list = implode( ', ', $result['invalid'] );

		switch ( $result['error'] ) {
			case 'invalid_action':
				return array(
					'message' => __( 'Invalid bulk action requested.', 'conexao-event-importer' ),
					'status'  => 'error',
				);
			case 'empty_selection':
				return array(
					'message' => __( 'No sources were selected.', 'conexao-event-importer' ),
					'status'  => 'error',
				);
			case 'no_valid_sources':
				return array(
					'message' => sprintf(
						/* translators: %s: comma-separated list of invalid source IDs. */
						__( 'No valid sources selected. Invalid IDs: %s', 'conexao-event-importer' ),
						$invalid_list
					),
					'status'  => 'error',
				);
		}

		$updated_count = count( $result['updated'] );
		$skipped_count = count( $result['skipped'] );
		$failed_count  = count( $result['failed'] );
		$parts         = array();

		if ( 'delete' === $result['action'] ) {
			if ( $updated_count > 0 ) {
				$parts[] = sprintf(
					/* translators: %d: number of sources deleted. */
					__( '%d source(s) deleted successfully.', 'conexao-event-importer' ),
					$updated_count
				);
			}
			if ( $failed_count > 0 ) {
				$parts[] = sprintf(
					/* translators: %d: number of sources that failed. */
					__( '%d source(s) could not be deleted.', 'conexao-event-importer' ),
					$failed_count
				);
			}
		} elseif ( 'enable' === $result['action'] ) {
			if ( $updated_count > 0 ) {
				$parts[] = sprintf(
					/* translators: %d: number of sources enabled. */
					__( '%d source(s) enabled.', 'conexao-event-importer' ),
					$updated_count
				);
			}
			if ( $skipped_count > 0 ) {
				$parts[] = sprintf(
					/* translators: %d: number of sources already active. */
					__( '%d source(s) were already active.', 'conexao-event-importer' ),
					$skipped_count
				);
			}
			if ( $failed_count > 0 ) {
				$parts[] = sprintf(
					/* translators: %d: number of sources that failed. */
					__( '%d source(s) could not be enabled.', 'conexao-event-importer' ),
					$failed_count
				);
			}
		} else {
			if ( $updated_count > 0 ) {
				$parts[] = sprintf(
					/* translators: %d: number of sources disabled. */
					__( '%d source(s) disabled.', 'conexao-event-importer' ),
					$updated_count
				);
			}
			if ( $skipped_count > 0 ) {
				$parts[] = sprintf(
					/* translators: %d: number of sources already disabled. */
					__( '%d source(s) were already disabled.', 'conexao-event-importer' ),
					$skipped_count
				);
			}
			if ( $failed_count > 0 ) {
				$parts[] = sprintf(
					/* translators: %d: number of sources that failed. */
					__( '%d source(s) could not be disabled.', 'conexao-event-importer' ),
					$failed_count
				);
			}
		}

		if ( ! empty( $result['invalid'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: comma-separated list of invalid source IDs. */
				__( 'Invalid IDs ignored: %s.', 'conexao-event-importer' ),
				$invalid_list
			);
		}

		if ( empty( $parts ) ) {
			$parts[] = __( 'No sources were changed.', 'conexao-event-importer' );
		}

		return array(
			'message' => implode( ' ', $parts ),
			'status'  => ( $failed_count > 0 || ! empty( $result['invalid'] ) ) ? 'error' : 'success',
		);
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
	 * Render the bulk action controls above the sources table.
	 *
	 * @param array $sources Source list.
	 */
	protected function render_bulk_actions_controls( $sources ) {
		$source_count = count( $sources );
		?>
		<form method="post" id="conexao-bulk-actions-form" class="conexao-bulk-actions">
			<?php wp_nonce_field( 'conexao_event_sources', 'conexao_event_sources_nonce' ); ?>
			<input type="hidden" name="conexao_source_action" value="bulk_action">
			<h2 class="screen-reader-text"><?php esc_html_e( 'Bulk actions for Event Sources', 'conexao-event-importer' ); ?></h2>
			<div class="conexao-bulk-controls">
				<button type="button" class="button button-secondary" id="conexao-bulk-select-all"><?php esc_html_e( 'Select All', 'conexao-event-importer' ); ?></button>
				<button type="button" class="button button-secondary" id="conexao-bulk-deselect-all"><?php esc_html_e( 'Deselect All', 'conexao-event-importer' ); ?></button>
				<?php
				/*
				 * The counter keeps ONE translated suffix rendered by PHP. The
				 * selected count and the total are separate numeric spans:
				 * assets/admin.js updates their textContent only, so the suffix is
				 * never duplicated and the total always reflects the live DOM
				 * (it is re-counted whenever rows are added, removed or replaced).
				 */
				?>
				<span class="conexao-bulk-count" id="conexao-bulk-count">
					<span class="conexao-bulk-selected-count" id="conexao-bulk-selected-count">0</span>
					<?php
					printf(
						/* translators: %s: total number of Event Sources, rendered as a live-updated number. */
						esc_html__( ' of %s selected', 'conexao-event-importer' ),
						'<span class="conexao-bulk-total-count" id="conexao-bulk-total-count">' . (int) $source_count . '</span>'
					);
					?>
				</span>
				<span class="conexao-bulk-status" id="conexao-bulk-status" aria-live="polite"></span>
				<select name="bulk_action_type" id="conexao-bulk-action-select" class="regular-text">
					<option value=""><?php esc_html_e( 'Bulk action', 'conexao-event-importer' ); ?></option>
					<option value="enable"><?php esc_html_e( 'Enable Selected', 'conexao-event-importer' ); ?></option>
					<option value="disable"><?php esc_html_e( 'Disable Selected', 'conexao-event-importer' ); ?></option>
					<option value="delete"><?php esc_html_e( 'Delete Selected', 'conexao-event-importer' ); ?></option>
				</select>
				<button type="submit" class="button button-primary" id="conexao-bulk-apply" disabled><?php esc_html_e( 'Apply', 'conexao-event-importer' ); ?></button>
			</div>
		</form>
		<?php
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
					<th scope="col" class="check-column"><input type="checkbox" id="conexao-bulk-header-checkbox" aria-label="<?php esc_attr_e( 'Select all Event Sources', 'conexao-event-importer' ); ?>"></th>
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
						<th scope="row" class="check-column"><input type="checkbox" name="bulk_source_ids[]" value="<?php echo esc_attr( $source['id'] ); ?>" class="conexao-bulk-row-checkbox" form="conexao-bulk-actions-form"></th>
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

			<?php $this->render_history_table( Conexao_Import_History::get_all() ); ?>
		</div>
		<?php
	}

	/**
	 * Render the complete Import History table.
	 *
	 * This is the ONE renderer for import history, and the dashboard is the
	 * one screen that shows it. The table is the 13-column one the retired
	 * `conexao-import-history` page used to render — same columns, same cells,
	 * same fallbacks for records written before the optional fields existed —
	 * over the FULL retained history, never a 10-row slice of it. The cap that
	 * bounds it lives in Conexao_Import_History::MAX_ENTRIES (100), applied
	 * when a run is recorded, not when this page is rendered.
	 *
	 * Public so the regression suite can drive the REAL renderer (including
	 * records that predate `unchanged`, `skipped`, `complete`, `checkpoint`
	 * and `cooldown`) instead of a copy of its logic — the same reason
	 * history_progress_text() is public.
	 *
	 * @param array $history History entries, newest first.
	 * @return void
	 */
	public function render_history_table( array $history ) {
		?>
		<h2><?php esc_html_e( 'Import History', 'conexao-event-importer' ); ?></h2>
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
			<div class="conexao-import-history-scroll">
				<table class="widefat striped conexao-import-history">
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
							<th><?php esc_html_e( 'Listing', 'conexao-event-importer' ); ?></th>
							<th><?php esc_html_e( 'Progress', 'conexao-event-importer' ); ?></th>
							<th><?php esc_html_e( 'Cooldown', 'conexao-event-importer' ); ?></th>
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
								<td>
									<?php
									/*
									 * G9 §29. An operator must be able to tell "this
									 * source had nothing new" apart from "this
									 * source was never fully enumerated", which is
									 * the distinction that decides whether a
									 * re-run is worth anything.
									 */
									$is_complete = array_key_exists( 'complete', $entry ) ? (bool) $entry['complete'] : true;
									echo esc_html(
										$is_complete
											? __( 'complete', 'conexao-event-importer' )
											: __( 'incomplete', 'conexao-event-importer' )
									);
									?>
								</td>
								<td>
									<?php echo esc_html( self::history_progress_text( $entry ) ); ?>
								</td>
								<td>
									<?php
									$cool = isset( $entry['cooldown'] ) && is_array( $entry['cooldown'] ) ? $entry['cooldown'] : array();
									if ( ! empty( $cool['cooling_down'] ) ) {
										$reason_label = isset( $cool['last_reason'] ) ? (string) $cool['last_reason'] : 'throttled';
										$seconds_left = isset( $cool['seconds_remaining'] ) ? (int) $cool['seconds_remaining'] : 0;
										$text         = sprintf(
											/* translators: 1: reason code, 2: seconds remaining */
											__( 'cooling down (%1$s) — retry in ~%2$ds', 'conexao-event-importer' ),
											$reason_label,
											(string) $seconds_left
										);
									} elseif ( ! empty( $cool['last_reason'] ) ) {
										$text = sprintf(
											/* translators: %s: reason code */
											__( 'cleared (last: %s)', 'conexao-event-importer' ),
											(string) $cool['last_reason']
										);
									} else {
										$text = __( '—', 'conexao-event-importer' );
									}

									echo esc_html( $text );
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * The operator-facing text of the Import History "Progress" cell.
	 *
	 * Extracted from the renderer so the promise the cell makes is directly
	 * testable: the phrase "resumable" appears ONLY when a continuation is
	 * actually scheduled. A cell that says "resumable" while nothing will run is
	 * the defect this stage exists to remove, so it is asserted, not assumed.
	 *
	 * G10 §27: this is PROGRESS state, never completeness. The "Listing" column
	 * remains the completeness signal; a checkpoint is never read as one.
	 *
	 * G18: the suffix reports the LIVE scheduler state (a future execution, a
	 * cooldown, a stall), read from `Conexao_Import_Continuation`, so the words
	 * and the behaviour cannot disagree. A row that is no longer the current one
	 * for its source keeps the historical fact and makes no lifecycle claim.
	 *
	 * @param array $entry One Import History entry.
	 * @return string Plain text; the caller escapes it.
	 */
	public static function history_progress_text( array $entry ) {
		$cp = isset( $entry['checkpoint'] ) && is_array( $entry['checkpoint'] ) ? $entry['checkpoint'] : array();

		if ( empty( $cp['has_checkpoint'] ) ) {
			return __( '—', 'conexao-event-importer' );
		}

		$cp_page  = isset( $cp['last_page'] ) ? (int) $cp['last_page'] : 0;
		$cp_total = isset( $cp['page_count'] ) ? (int) $cp['page_count'] : 0;
		$cp_fail  = isset( $cp['last_failure'] ) ? (string) $cp['last_failure'] : '';
		$source   = isset( $entry['source'] ) ? (string) $entry['source'] : '';

		if ( '' !== $cp_fail ) {
			$text = sprintf(
				/* translators: 1: last confirmed page, 2: total pages, 3: failure class */
				__( 'page %1$d of %2$d (%3$s)', 'conexao-event-importer' ),
				$cp_page,
				$cp_total,
				$cp_fail
			);
		} else {
			$text = sprintf(
				/* translators: 1: last confirmed page, 2: total pages */
				__( 'page %1$d of %2$d', 'conexao-event-importer' ),
				$cp_page,
				$cp_total
			);
		}

		// Pages still outstanding, judged on the pair of progress facts the
		// checkpoint already carries (never on its existence).
		$outstanding = empty( $cp['complete'] ) && ( 0 === $cp_total || $cp_page < $cp_total );

		if ( ! $outstanding ) {
			return $text . ' — ' . __( 'complete', 'conexao-event-importer' );
		}

		// Only the CURRENT row can be annotated with the live lifecycle: for an
		// older row the scheduler state describes a different checkpoint.
		$live = class_exists( 'Conexao_Source_Enumeration_Checkpoint' )
			? Conexao_Source_Enumeration_Checkpoint::get_state( $source )
			: array();

		$current = ! empty( $live['updated_at'] )
			&& ! empty( $cp['updated_at'] )
			&& (string) $live['updated_at'] === (string) $cp['updated_at'];

		if ( ! $current || ! class_exists( 'Conexao_Import_Continuation' ) ) {
			return $text;
		}

		$cont = Conexao_Import_Continuation::get_state( $source );

		$phase  = (string) $cont['phase'];
		$remain = (int) $cont['seconds_remaining'];

		if ( 'running' === $phase ) {
			return $text . ' — ' . __( 'resuming — a worker is running it now', 'conexao-event-importer' );
		}

		if ( 'cooling' === $phase ) {
			$minutes = max( 1, (int) ceil( $remain / 60 ) );

			return $text . ' — ' . sprintf(
				/* translators: %d: whole minutes until the next automatic attempt */
				_n( 'resumable — next run in %d minute', 'resumable — next run in %d minutes', $minutes, 'conexao-event-importer' ),
				$minutes
			);
		}

		if ( 'due' === $phase ) {
			return $text . ' — ' . __( 'resumable — resume scheduled', 'conexao-event-importer' );
		}

		if ( 'exhausted' === $phase ) {
			return $text . ' — ' . __( 'NOT scheduled — the automatic continuation stopped; press Run to resume', 'conexao-event-importer' );
		}

		if ( 'failed' === $phase ) {
			return $text . ' — ' . __( 'NOT scheduled — the continuation could not be scheduled', 'conexao-event-importer' );
		}

		return $text . ' — ' . __( 'NOT scheduled — no continuation exists', 'conexao-event-importer' );
	}
}
