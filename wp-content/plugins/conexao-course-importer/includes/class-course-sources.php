<?php
/**
 * Course Sources management (admin area + storage).
 *
 * @package Conexao_Course_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Course_Sources {

	const OPTION_KEY = 'conexao_course_sources';

	/**
	 * Default sources seeded on activation.
	 *
	 * @return array
	 */
	public function get_defaults() {
		return array(
			'leo_laois' => array(
				'id'                 => 'leo_laois',
				'name'               => 'Local Enterprise Office — Laois',
				'url'                => 'https://www.localenterprise.ie/laois/training-events/online-bookings/',
				'type'               => 'leo_training',
				'status'             => 'active',
				'last_import'        => '',
				'last_import_status' => '',
				'courses_imported'   => 0,
				'last_error'         => '',
				'county'             => 'Laois',
				'category'           => 'Treinamento',
			),
		);
	}

	/**
	 * Get all sources.
	 *
	 * Lazily merges any missing default sources into the stored list so
	 * existing installs automatically pick up new defaults without
	 * overwriting user changes to existing sources. This keeps the source
	 * list complete and idempotent.
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

		// Merge in any default sources that are missing.
		foreach ( $this->get_defaults() as $default_id => $default_source ) {
			if ( ! isset( $sources[ $default_id ] ) ) {
				$sources[ $default_id ]            = $default_source;
				$sources[ $default_id ]['id']      = $default_id;
				$sources[ $default_id ]['status']  = 'active';
				$changed = true;
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
				'courses_imported'   => 0,
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
	 * Imported courses are preserved (not deleted).
	 *
	 * @param string $source_id Source slug.
	 * @return bool
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
		if ( isset( $stats['courses_imported'] ) ) {
			$source['courses_imported'] = (int) $stats['courses_imported'];
		}
		if ( isset( $stats['last_error'] ) ) {
			$source['last_error'] = $stats['last_error'];
		}
		$source['last_checked'] = current_time( 'mysql' );

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
	 * Register the admin menu.
	 */
	public function register_admin_menu() {
		add_menu_page(
			__( 'Course Import', 'conexao-course-importer' ),
			__( 'Course Import', 'conexao-course-importer' ),
			'manage_options',
			'conexao-course-import',
			array( $this, 'render_import_dashboard' ),
			'dashicons-welcome-learn-more',
			27
		);

		add_submenu_page(
			'conexao-course-import',
			__( 'Course Sources', 'conexao-course-importer' ),
			__( 'Course Sources', 'conexao-course-importer' ),
			'manage_options',
			'conexao-course-sources',
			array( $this, 'render_sources_page' )
		);

		add_submenu_page(
			'conexao-course-import',
			__( 'Import History', 'conexao-course-importer' ),
			__( 'Import History', 'conexao-course-importer' ),
			'manage_options',
			'conexao-course-import-history',
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

		// Handle actions.
		if ( isset( $_POST['conexao_course_source_action'] ) && check_admin_referer( 'conexao_course_sources', 'conexao_course_sources_nonce' ) ) {
			$action = sanitize_text_field( wp_unslash( $_POST['conexao_course_source_action'] ) );

			if ( 'toggle' === $action && isset( $_POST['source_id'] ) ) {
				$source_id = sanitize_text_field( wp_unslash( $_POST['source_id'] ) );
				$source    = $this->get( $source_id );
				if ( $source ) {
					$new_status = ( 'active' === $source['status'] ) ? 'inactive' : 'active';
					$this->set_status( $source_id, $new_status );
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Source status updated.', 'conexao-course-importer' ) . '</p></div>';
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
		<div class="wrap conexao-course-sources">
			<h1><?php esc_html_e( 'Course Sources', 'conexao-course-importer' ); ?></h1>
			<p><?php esc_html_e( 'Manage the sources from which courses are imported. Only active sources are processed during an import run.', 'conexao-course-importer' ); ?></p>
			<?php $this->render_sources_table( $sources ); ?>
			<script>
			(function() {
				'use strict';
				document.querySelectorAll( '.conexao-course-source-delete-form' ).forEach( function( form ) {
					form.addEventListener( 'submit', function( e ) {
						var button = form.querySelector( '[data-source-name]' );
						var sourceName = button ? button.getAttribute( 'data-source-name' ) : '';
						var message = <?php echo wp_json_encode(
							sprintf( __( 'Delete Course Source?\n\nAre you sure you want to delete "%s"?\n\nThis will remove the Course Source configuration. Imported courses will remain in the database.', 'conexao-course-importer' ), '%s' )
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
		$url       = isset( $_POST['source_url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['source_url'] ) ) ) : '';
		$source_type = isset( $_POST['source_type'] ) ? sanitize_text_field( wp_unslash( $_POST['source_type'] ) ) : 'website';

		$source = array(
			'id'     => $source_id,
			'name'   => isset( $_POST['source_name'] ) ? sanitize_text_field( wp_unslash( $_POST['source_name'] ) ) : '',
			'url'    => $url,
			'type'   => $source_type,
			'status' => isset( $_POST['source_status'] ) ? 'active' : 'inactive',
		);

		// Preserve existing stats + source-level defaults if editing.
		$existing = $this->get( $source_id );
		if ( $existing ) {
			$source['last_import']        = $existing['last_import'];
			$source['last_import_status'] = $existing['last_import_status'];
			$source['courses_imported']   = $existing['courses_imported'];
			$source['last_error']         = $existing['last_error'];

			// Keep location/category defaults (e.g. County Laois) when editing.
			if ( ! empty( $existing['county'] ) ) {
				$source['county'] = $existing['county'];
			}
			if ( ! empty( $existing['category'] ) ) {
				$source['category'] = $existing['category'];
			}
		}

		$this->save( $source );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Source saved.', 'conexao-course-importer' ) . '</p></div>';
	}

	/**
	 * Handle running an import from the admin form.
	 *
	 * Shows detailed per-source diagnostics (source reachable, pages scanned,
	 * links discovered, parsed, plus new/updated/unchanged/errors) so a failed
	 * import is understandable instead of just "0 courses imported".
	 */
	protected function handle_run_import() {
		$source_id = sanitize_text_field( wp_unslash( $_POST['source_id'] ) );

		if ( 'all' === $source_id ) {
			$result = apply_filters( 'conexao_course_importer_run_all', array() );
		} else {
			$result = apply_filters( 'conexao_course_importer_run_source', $source_id );
		}

		if ( ! $result || is_wp_error( $result ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Import failed.', 'conexao-course-importer' ) . '</p></div>';
			return;
		}

		$found     = isset( $result['found'] ) ? (int) $result['found'] : 0;
		$new       = isset( $result['new'] ) ? (int) $result['new'] : 0;
		$updated   = isset( $result['updated'] ) ? (int) $result['updated'] : 0;
		$unchanged = isset( $result['unchanged'] ) ? (int) $result['unchanged'] : 0;
		$errors    = isset( $result['errors'] ) ? (int) $result['errors'] : 0;

		$source_name       = isset( $result['source_name'] ) ? $result['source_name'] : '';
		$source_reachable  = isset( $result['source_reachable'] ) ? (bool) $result['source_reachable'] : null;
		$pages_scanned     = isset( $result['pages_scanned'] ) ? (int) $result['pages_scanned'] : 0;
		$links_discovered  = isset( $result['links_discovered'] ) ? (int) $result['links_discovered'] : $found;
		$courses_parsed    = isset( $result['courses_parsed'] ) ? (int) $result['courses_parsed'] : $found;
		$source_error      = isset( $result['source_error'] ) ? (string) $result['source_error'] : '';

		echo '<div class="notice notice-success is-dismissible"><p>';
		if ( $source_name ) {
			echo '<strong>' . esc_html( $source_name ) . '</strong><br>';
		} elseif ( 'all' === $source_id ) {
			echo '<strong>' . esc_html__( 'All Course Sources', 'conexao-course-importer' ) . '</strong><br>';
		}

		if ( 'all' !== $source_id && null !== $source_reachable ) {
			echo esc_html__( 'Source reachable:', 'conexao-course-importer' ) . ' ' . ( $source_reachable ? '✓' : '✗' ) . '<br>';
		}
		if ( 'all' !== $source_id ) {
			echo esc_html__( 'Pages scanned:', 'conexao-course-importer' ) . ' ' . esc_html( $pages_scanned ) . '<br>';
			echo esc_html__( 'Course links discovered:', 'conexao-course-importer' ) . ' ' . esc_html( $links_discovered ) . '<br>';
			echo esc_html__( 'Courses parsed:', 'conexao-course-importer' ) . ' ' . esc_html( $courses_parsed ) . '<br>';
		}

		echo esc_html( sprintf(
			/* translators: %1$d: new, %2$d: updated, %3$d: unchanged, %4$d: errors */
			__( 'New: %1$d | Updated: %2$d | Unchanged: %3$d | Errors: %4$d', 'conexao-course-importer' ),
			$new, $updated, $unchanged, $errors
		) );

		if ( $source_error ) {
			echo '<br><span style="color:#b32d2e;">' . esc_html( $source_error ) . '</span>';
		}

		echo '</p></div>';
	}

	/**
	 * Handle deleting a source.
	 */
	protected function handle_delete_source() {
		$source_id = sanitize_text_field( wp_unslash( $_POST['source_id'] ) );
		$source    = $this->get( $source_id );

		if ( ! $source ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Unable to delete the Course Source.', 'conexao-course-importer' ) . '</p></div>';
			return;
		}

		$source_name = $source['name'];
		$deleted     = $this->delete( $source_id );

		if ( $deleted ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(
				esc_html__( 'Course Source "%s" was deleted successfully.', 'conexao-course-importer' ),
				'<strong>' . esc_html( $source_name ) . '</strong>'
			) . '</p></div>';
		} else {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Unable to delete the Course Source.', 'conexao-course-importer' ) . '</p></div>';
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
			'website'     => __( 'Website', 'conexao-course-importer' ),
			'api'         => __( 'API', 'conexao-course-importer' ),
			'rss'         => __( 'RSS/XML', 'conexao-course-importer' ),
			'icalendar'   => __( 'iCalendar', 'conexao-course-importer' ),
			'custom'      => __( 'Custom', 'conexao-course-importer' ),
			'leo_training' => __( 'Website / Training Courses', 'conexao-course-importer' ),
		);
		return isset( $labels[ $type ] ) ? $labels[ $type ] : ucfirst( $type );
	}

	/**
	 * Render the sources table.
	 *
	 * @param array $sources Source list.
	 */
	protected function render_sources_table( $sources ) {
		if ( empty( $sources ) ) {
			echo '<p>' . esc_html__( 'No course sources configured yet. Add one below.', 'conexao-course-importer' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'conexao-course-importer' ); ?></th>
					<th><?php esc_html_e( 'Source URL', 'conexao-course-importer' ); ?></th>
					<th><?php esc_html_e( 'Type', 'conexao-course-importer' ); ?></th>
					<th><?php esc_html_e( 'Status', 'conexao-course-importer' ); ?></th>
					<th><?php esc_html_e( 'Last Import', 'conexao-course-importer' ); ?></th>
					<th><?php esc_html_e( 'Last Checked', 'conexao-course-importer' ); ?></th>
					<th><?php esc_html_e( 'Courses Imported', 'conexao-course-importer' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'conexao-course-importer' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $sources as $source ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $source['name'] ); ?></strong></td>
						<td>
							<a href="<?php echo esc_url( $source['url'] ); ?>" target="_blank" rel="noopener noreferrer">
								<?php echo esc_html( $this->truncate_url( $source['url'] ) ); ?>
							</a>
						</td>
						<td>
							<span class="conexao-source-type conexao-source-type--<?php echo esc_attr( $source['type'] ); ?>">
								<?php echo esc_html( $this->get_type_label( isset( $source['type'] ) ? $source['type'] : 'website' ) ); ?>
							</span>
						</td>
						<td>
							<?php if ( 'active' === $source['status'] ) : ?>
								<span class="conexao-source-status conexao-source-status--active">● <?php esc_html_e( 'Active', 'conexao-course-importer' ); ?></span>
							<?php else : ?>
								<span class="conexao-source-status conexao-source-status--inactive">○ <?php esc_html_e( 'Inactive', 'conexao-course-importer' ); ?></span>
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
						<td>
							<?php
							$last_checked = isset( $source['last_checked'] ) ? $source['last_checked'] : '';
							echo ! empty( $last_checked ) ? esc_html( $last_checked ) : '&mdash;';
							?>
						</td>
						<td><?php echo esc_html( isset( $source['courses_imported'] ) ? $source['courses_imported'] : 0 ); ?></td>
						<td>
							<form method="post" style="display:inline-block;">
								<?php wp_nonce_field( 'conexao_course_sources', 'conexao_course_sources_nonce' ); ?>
								<input type="hidden" name="conexao_course_source_action" value="run_import">
								<input type="hidden" name="source_id" value="<?php echo esc_attr( $source['id'] ); ?>">
								<button type="submit" class="button button-primary"><?php esc_html_e( 'Import Now', 'conexao-course-importer' ); ?></button>
							</form>
							<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=conexao-course-sources&edit=' . $source['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'conexao-course-importer' ); ?></a>
							<form method="post" style="display:inline-block;">
								<?php wp_nonce_field( 'conexao_course_sources', 'conexao_course_sources_nonce' ); ?>
								<input type="hidden" name="conexao_course_source_action" value="toggle">
								<input type="hidden" name="source_id" value="<?php echo esc_attr( $source['id'] ); ?>">
								<button type="submit" class="button"><?php echo 'active' === $source['status'] ? esc_html__( 'Disable', 'conexao-course-importer' ) : esc_html__( 'Enable', 'conexao-course-importer' ); ?></button>
							</form>
							<form method="post" style="display:inline-block;" class="conexao-course-source-delete-form">
								<?php wp_nonce_field( 'conexao_course_sources', 'conexao_course_sources_nonce' ); ?>
								<input type="hidden" name="conexao_course_source_action" value="delete">
								<input type="hidden" name="source_id" value="<?php echo esc_attr( $source['id'] ); ?>">
								<button type="submit" class="button button-link-delete" data-source-name="<?php echo esc_attr( $source['name'] ); ?>"><?php esc_html_e( 'Delete', 'conexao-course-importer' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
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
		<h2><?php esc_html_e( 'Add New Course Source', 'conexao-course-importer' ); ?></h2>
		<form method="post" class="conexao-source-form">
			<?php wp_nonce_field( 'conexao_course_sources', 'conexao_course_sources_nonce' ); ?>
			<input type="hidden" name="conexao_course_source_action" value="save">
			<table class="form-table">
				<tr>
					<th><label for="source_id"><?php esc_html_e( 'Source ID', 'conexao-course-importer' ); ?></label></th>
					<td><input type="text" id="source_id" name="source_id" class="regular-text" required placeholder="e.g. leo_laois"></td>
				</tr>
				<tr>
					<th><label for="source_name"><?php esc_html_e( 'Name', 'conexao-course-importer' ); ?></label></th>
					<td><input type="text" id="source_name" name="source_name" class="regular-text" required placeholder="e.g. Local Enterprise Office — Laois"></td>
				</tr>
				<tr>
					<th><label for="source_url"><?php esc_html_e( 'Source URL', 'conexao-course-importer' ); ?></label></th>
					<td><input type="text" id="source_url" name="source_url" class="regular-text" required placeholder="https://..."></td>
				</tr>
				<tr>
					<th><label for="source_type"><?php esc_html_e( 'Source Type', 'conexao-course-importer' ); ?></label></th>
					<td>
						<select id="source_type" name="source_type">
							<option value="website"><?php esc_html_e( 'Website', 'conexao-course-importer' ); ?></option>
							<option value="leo_training"><?php esc_html_e( 'Website / Training Courses', 'conexao-course-importer' ); ?></option>
							<option value="api"><?php esc_html_e( 'API', 'conexao-course-importer' ); ?></option>
							<option value="rss"><?php esc_html_e( 'RSS/XML', 'conexao-course-importer' ); ?></option>
							<option value="icalendar"><?php esc_html_e( 'iCalendar', 'conexao-course-importer' ); ?></option>
							<option value="custom"><?php esc_html_e( 'Custom', 'conexao-course-importer' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="source_status"><?php esc_html_e( 'Active', 'conexao-course-importer' ); ?></label></th>
					<td><input type="checkbox" id="source_status" name="source_status" checked></td>
				</tr>
			</table>
			<?php submit_button( __( 'Add Course Source', 'conexao-course-importer' ) ); ?>
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
		<h2><?php esc_html_e( 'Edit Course Source', 'conexao-course-importer' ); ?></h2>
		<form method="post" class="conexao-source-form">
			<?php wp_nonce_field( 'conexao_course_sources', 'conexao_course_sources_nonce' ); ?>
			<input type="hidden" name="conexao_course_source_action" value="save">
			<input type="hidden" name="source_id" value="<?php echo esc_attr( $source['id'] ); ?>">
			<table class="form-table">
				<tr>
					<th><label><?php esc_html_e( 'Source ID', 'conexao-course-importer' ); ?></label></th>
					<td><strong><?php echo esc_html( $source['id'] ); ?></strong></td>
				</tr>
				<tr>
					<th><label for="source_name"><?php esc_html_e( 'Name', 'conexao-course-importer' ); ?></label></th>
					<td><input type="text" id="source_name" name="source_name" class="regular-text" value="<?php echo esc_attr( $source['name'] ); ?>" required></td>
				</tr>
				<tr>
					<th><label for="source_url"><?php esc_html_e( 'Source URL', 'conexao-course-importer' ); ?></label></th>
					<td><input type="text" id="source_url" name="source_url" class="regular-text" value="<?php echo esc_attr( $source['url'] ); ?>"></td>
				</tr>
				<tr>
					<th><label for="source_type"><?php esc_html_e( 'Source Type', 'conexao-course-importer' ); ?></label></th>
					<td>
						<select id="source_type" name="source_type">
							<option value="website" <?php selected( $source['type'], 'website' ); ?>><?php esc_html_e( 'Website', 'conexao-course-importer' ); ?></option>
							<option value="leo_training" <?php selected( $source['type'], 'leo_training' ); ?>><?php esc_html_e( 'Website / Training Courses', 'conexao-course-importer' ); ?></option>
							<option value="api" <?php selected( $source['type'], 'api' ); ?>><?php esc_html_e( 'API', 'conexao-course-importer' ); ?></option>
							<option value="rss" <?php selected( $source['type'], 'rss' ); ?>><?php esc_html_e( 'RSS/XML', 'conexao-course-importer' ); ?></option>
							<option value="icalendar" <?php selected( $source['type'], 'icalendar' ); ?>><?php esc_html_e( 'iCalendar', 'conexao-course-importer' ); ?></option>
							<option value="custom" <?php selected( $source['type'], 'custom' ); ?>><?php esc_html_e( 'Custom', 'conexao-course-importer' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="source_status"><?php esc_html_e( 'Active', 'conexao-course-importer' ); ?></label></th>
					<td><input type="checkbox" id="source_status" name="source_status" <?php checked( 'active', $source['status'] ); ?>></td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Course Source', 'conexao-course-importer' ) ); ?>
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
		$sources = $this->get_all();
		$next    = wp_next_scheduled( 'conexao_course_import_cron' );
		?>
		<div class="wrap conexao-import-dashboard">
			<h1><?php esc_html_e( 'Course Import', 'conexao-course-importer' ); ?></h1>

			<div class="conexao-import-summary">
				<div class="conexao-import-stat">
					<span class="conexao-import-stat-label"><?php esc_html_e( 'Active sources', 'conexao-course-importer' ); ?></span>
					<span class="conexao-import-stat-value"><?php echo count( $this->get_active() ); ?></span>
				</div>
				<div class="conexao-import-stat">
					<span class="conexao-import-stat-label"><?php esc_html_e( 'Next scheduled import', 'conexao-course-importer' ); ?></span>
					<span class="conexao-import-stat-value">
						<?php
						if ( $next ) {
							echo esc_html( gmdate( 'Y-m-d H:i', $next ) );
						} else {
							echo esc_html__( 'Weekly — Europe/Dublin', 'conexao-course-importer' );
						}
						?>
					</span>
				</div>
			</div>

			<h2><?php esc_html_e( 'Sources', 'conexao-course-importer' ); ?></h2>
			<?php if ( empty( $sources ) ) : ?>
				<p><?php esc_html_e( 'No course sources configured yet.', 'conexao-course-importer' ); ?></p>
			<?php else : ?>
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
			<?php endif; ?>

			<h2><?php esc_html_e( 'Run Import', 'conexao-course-importer' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'conexao_course_sources', 'conexao_course_sources_nonce' ); ?>
				<input type="hidden" name="conexao_course_source_action" value="run_import">
				<input type="hidden" name="source_id" value="all">
				<button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Run Import Now', 'conexao-course-importer' ); ?></button>
			</form>

			<?php $this->render_history_summary(); ?>
		</div>
		<?php
	}

	/**
	 * Render a compact history summary on the dashboard.
	 */
	protected function render_history_summary() {
		$history = get_option( 'conexao_course_import_history', array() );
		if ( empty( $history ) ) {
			echo '<p>' . esc_html__( 'No imports yet.', 'conexao-course-importer' ) . '</p>';
			return;
		}
		?>
		<h2><?php esc_html_e( 'Import History', 'conexao-course-importer' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Time', 'conexao-course-importer' ); ?></th>
					<th><?php esc_html_e( 'Source', 'conexao-course-importer' ); ?></th>
					<th><?php esc_html_e( 'Found', 'conexao-course-importer' ); ?></th>
					<th><?php esc_html_e( 'New', 'conexao-course-importer' ); ?></th>
					<th><?php esc_html_e( 'Updated', 'conexao-course-importer' ); ?></th>
					<th><?php esc_html_e( 'Unchanged', 'conexao-course-importer' ); ?></th>
					<th><?php esc_html_e( 'Errors', 'conexao-course-importer' ); ?></th>
					<th><?php esc_html_e( 'Status', 'conexao-course-importer' ); ?></th>
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
						<td><?php echo esc_html( $entry['errors'] ); ?></td>
						<td><?php echo esc_html( $entry['status'] ); ?></td>
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
		$history = get_option( 'conexao_course_import_history', array() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Course Import History', 'conexao-course-importer' ); ?></h1>
			<?php if ( empty( $history ) ) : ?>
				<p><?php esc_html_e( 'No imports yet.', 'conexao-course-importer' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time', 'conexao-course-importer' ); ?></th>
							<th><?php esc_html_e( 'Source', 'conexao-course-importer' ); ?></th>
							<th><?php esc_html_e( 'Found', 'conexao-course-importer' ); ?></th>
							<th><?php esc_html_e( 'New', 'conexao-course-importer' ); ?></th>
							<th><?php esc_html_e( 'Updated', 'conexao-course-importer' ); ?></th>
							<th><?php esc_html_e( 'Unchanged', 'conexao-course-importer' ); ?></th>
							<th><?php esc_html_e( 'Errors', 'conexao-course-importer' ); ?></th>
							<th><?php esc_html_e( 'Status', 'conexao-course-importer' ); ?></th>
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
								<td><?php echo esc_html( $entry['errors'] ); ?></td>
								<td><?php echo esc_html( $entry['status'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}