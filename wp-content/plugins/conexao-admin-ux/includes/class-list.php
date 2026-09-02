<?php
/**
 * Reusable admin list screen for the Conexão Admin UX.
 *
 * Enhances the default WordPress edit.php list for each content type with:
 *   - human-friendly columns (date, location, source, status)
 *   - status badges with text + color (never color alone)
 *   - dashboard summary cards that link into filtered views
 *   - custom bulk actions (publish, draft, archive, category, county, delete)
 *   - duplicate row action
 *   - empty state message
 *
 * @package Conexao_Admin_Ux
 */

defined( 'ABSPATH' ) || exit;

final class Conexao_Admin_Ux_List {

	/** @var string */
	private $post_type;

	/** @var array */
	private $config;

	/**
	 * Constructor.
	 *
	 * @param string $post_type Post type.
	 */
	public function __construct( $post_type ) {
		$this->post_type = $post_type;
		$this->config    = Conexao_Admin_Ux_Config::get( $post_type );
	}

	/**
	 * Register list hooks.
	 */
	public function register() {
		add_filter( "manage_{$this->post_type}_posts_columns", array( $this, 'columns' ) );
		add_action( "manage_{$this->post_type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'render_filters' ), 10, 1 );
		add_action( 'pre_get_posts', array( $this, 'apply_filters' ) );
		add_filter( "bulk_actions-edit-{$this->post_type}", array( $this, 'bulk_actions' ) );
		add_filter( "handle_bulk_actions-edit-{$this->post_type}", array( $this, 'handle_bulk' ), 10, 3 );
		add_filter( "post_row_actions", array( $this, 'row_actions' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'bulk_notice' ) );
		add_action( 'admin_footer', array( $this, 'render_summary_cards' ) );
		add_filter( 'default_hidden_columns', array( $this, 'default_hidden_columns' ), 10, 2 );
	}

	/**
	 * Customize the columns for this post type.
	 *
	 * @param array $columns Default columns.
	 * @return array
	 */
	public function columns( $columns ) {
		$new = array();
		if ( isset( $columns['cb'] ) ) {
			$new['cb'] = $columns['cb'];
		}
		$new['title'] = isset( $columns['title'] ) ? $columns['title'] : __( 'Título', 'conexao-admin-ux' );

		if ( ! empty( $this->config['columns'] ) && is_array( $this->config['columns'] ) ) {
			foreach ( $this->config['columns'] as $key => $col ) {
				if ( ! is_array( $col ) ) {
					continue;
				}
				$label          = isset( $col['label'] ) && is_string( $col['label'] ) ? $col['label'] : $key;
				$new[ 'conexao_' . $key ] = $label;
			}
		}

		if ( isset( $columns['date'] ) ) {
			$new['date'] = $columns['date'];
		}

		return $new;
	}

	/**
	 * Render custom column content.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function render_column( $column, $post_id ) {
		if ( 0 !== strpos( $column, 'conexao_' ) ) {
			return;
		}

		$key    = substr( $column, strlen( 'conexao_' ) );
		$config = isset( $this->config['columns'][ $key ] ) ? $this->config['columns'][ $key ] : null;

		if ( ! $config || ! is_array( $config ) ) {
			return;
		}

		// Determine the render type safely. Only a non-empty string is valid.
		$render = isset( $config['render'] ) && is_string( $config['render'] ) ? $config['render'] : '';

		// Dispatch to the appropriate renderer.
		switch ( $render ) {
			case 'status':
				$this->render_status_badge( $post_id );
				return;

			case 'agency_active':
				$this->render_agency_active( $post_id );
				return;

			case 'source':
				$this->render_source( $post_id );
				return;

			case 'event_location':
				$this->render_event_location( $post_id );
				return;

			case 'category':
				$this->render_category( $post_id );
				return;

		}

		// No custom render callback — fall back to the default renderer.
		$this->render_default( $post_id, $config );
	}

	/**
	 * Render the status badge (text + icon, not color alone).
	 *
	 * @param int $post_id Post ID.
	 */
	private function render_status_badge( $post_id ) {
		$status   = Conexao_Admin_Ux_Actions::get_status( $post_id, $this->post_type );
		$statuses = isset( $this->config['publishing']['statuses'] ) && is_array( $this->config['publishing']['statuses'] )
			? $this->config['publishing']['statuses']
			: array();

		$label = isset( $statuses[ $status ]['label'] ) ? $statuses[ $status ]['label'] : $status;
		$badge = isset( $statuses[ $status ]['badge'] ) ? $statuses[ $status ]['badge'] : 'draft';

		$icon = 'published' === $badge ? '●' : ( 'review' === $badge ? '⚠' : ( 'warning' === $badge ? '⚠' : '●' ) );
		printf(
			'<span class="conexao-status-badge conexao-status-badge--%1$s" aria-label="Status: %2$s"><span class="conexao-status-dot" aria-hidden="true">%3$s</span> %2$s</span>',
			esc_attr( $badge ),
			esc_html( $label ),
			esc_html( $icon )
		);
	}

	/**
	 * Render the "Ativa" column: an agency is active when its publishing
	 * status is `published` (the same status that gates /empregos/ display).
	 * Every other status (draft, needs_review, archived) renders as Inativa —
	 * there is no separate active flag to keep out of sync.
	 *
	 * @param int $post_id Post ID.
	 */
	private function render_agency_active( $post_id ) {
		$status = Conexao_Admin_Ux_Actions::get_status( $post_id, $this->post_type );

		if ( 'published' === $status ) {
			printf(
				'<span class="conexao-status-badge conexao-status-badge--published"><span class="conexao-status-dot" aria-hidden="true">●</span> %s</span>',
				esc_html__( 'Ativa', 'conexao-admin-ux' )
			);
		} else {
			printf(
				'<span class="conexao-status-badge conexao-status-badge--draft"><span class="conexao-status-dot" aria-hidden="true">●</span> %s</span>',
				esc_html__( 'Inativa', 'conexao-admin-ux' )
			);
		}
	}

	/**
	 * Render source column.
	 *
	 * @param int $post_id Post ID.
	 */
	private function render_source( $post_id ) {
		$source = get_post_meta( $post_id, '_event_source', true );
		if ( ! is_string( $source ) || '' === $source ) {
			$source = get_post_meta( $post_id, '_' . $this->post_type . '_source', true );
		}

		if ( is_string( $source ) && '' !== $source ) {
			if ( 'event' === $this->post_type && class_exists( 'Conexao_Event_Sources' ) ) {
				$sources = ( new Conexao_Event_Sources() )->get_all();
				$name    = isset( $sources[ $source ]['name'] ) && is_string( $sources[ $source ]['name'] )
					? $sources[ $source ]['name']
					: $source;
				echo esc_html( $name );
			} else {
				echo esc_html( $source );
			}
		} else {
			echo '<span class="conexao-muted">' . esc_html__( 'Manual', 'conexao-admin-ux' ) . '</span>';
		}
	}

	/**
	 * Render event location (venue, town, county).
	 *
	 * @param int $post_id Post ID.
	 */
	private function render_event_location( $post_id ) {
		$venue  = get_post_meta( $post_id, '_event_venue', true );
		$town   = get_post_meta( $post_id, '_event_town', true );
		$county = wp_get_object_terms( $post_id, 'conexao_county', array( 'fields' => 'names' ) );

		$parts = array();
		if ( is_string( $venue ) && '' !== $venue ) {
			$parts[] = $venue;
		}
		if ( is_string( $town ) && '' !== $town ) {
			$parts[] = $town;
		}
		if ( is_array( $county ) && ! empty( $county ) && isset( $county[0] ) && is_string( $county[0] ) ) {
			$parts[] = $county[0];
		}

		if ( empty( $parts ) ) {
			$legacy = get_post_meta( $post_id, '_event_location', true );
			if ( is_string( $legacy ) && '' !== $legacy ) {
				echo esc_html( $legacy );
			} else {
				echo '<span class="conexao-muted">—</span>';
			}
			return;
		}

		echo esc_html( implode( ', ', $parts ) );
	}

	/**
	 * Render the category column.
	 *
	 * @param int $post_id Post ID.
	 */
	private function render_category( $post_id ) {
		$terms = get_the_terms( $post_id, 'conexao_category' );
		if ( $terms && ! is_wp_error( $terms ) && ! empty( $terms ) && isset( $terms[0]->name ) ) {
			echo esc_html( $terms[0]->name );
		} else {
			echo '<span class="conexao-muted">—</span>';
		}
	}

	/**
	 * Default renderer for simple meta columns.
	 *
	 * Safely handles missing keys, null, empty strings, false, arrays,
	 * objects, and unexpected data types. Valid values such as 0/'0' are
	 * preserved when meaningful.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $config  Column config.
	 */
	private function render_default( $post_id, $config ) {
		$meta_key = isset( $config['meta'] ) && is_string( $config['meta'] ) ? $config['meta'] : '';
		if ( '' === $meta_key ) {
			echo '<span class="conexao-muted">—</span>';
			return;
		}

		$value = get_post_meta( $post_id, $meta_key, true );

		// Handle unexpected data types (arrays, objects, resources).
		if ( is_array( $value ) || is_object( $value ) || is_resource( $value ) ) {
			echo '<span class="conexao-muted">—</span>';
			return;
		}

		$format = isset( $config['format'] ) && is_string( $config['format'] ) ? $config['format'] : '';

		// Featured format: checkbox value.
		if ( 'featured' === $format ) {
			$is_featured = in_array( $value, array( '1', 'on', 'yes', true, 1 ), true );
			if ( $is_featured ) {
				echo '<span class="conexao-status-badge conexao-status-badge--published">★ Destaque</span>';
			} else {
				echo '<span class="conexao-muted">—</span>';
			}
			return;
		}

		// Boolean format: yes/no checkbox value rendered as a "Sim" badge.
		if ( 'boolean' === $format ) {
			if ( in_array( $value, array( '1', 'on', 'yes', true, 1 ), true ) ) {
				echo '<span class="conexao-status-badge conexao-status-badge--published">' . esc_html__( 'Sim', 'conexao-admin-ux' ) . '</span>';
			} else {
				echo '<span class="conexao-muted">—</span>';
			}
			return;
		}

		// Missing or empty values (null, '', false). Allow 0 and '0' as meaningful.
		if ( null === $value || '' === $value || false === $value ) {
			echo '<span class="conexao-muted">—</span>';
			return;
		}

		// Date format.
		if ( 'date' === $format ) {
			$timestamp = strtotime( (string) $value );
			if ( false === $timestamp ) {
				echo '<span class="conexao-muted">—</span>';
				return;
			}
			echo esc_html( date_i18n( 'j M Y', $timestamp ) );
			return;
		}

		// Default: display the value as escaped text.
		echo esc_html( (string) $value );
	}

	/**
	 * Render extra filters on the list screen.
	 *
	 * @param string $post_type Current post type.
	 */
	public function render_filters( $post_type ) {
		if ( $post_type !== $this->post_type ) {
			return;
		}

		// Source filter (events).
		if ( 'event' === $this->post_type ) {
			$current = isset( $_GET['conexao_source'] ) ? sanitize_text_field( wp_unslash( $_GET['conexao_source'] ) ) : '';
			echo '<select name="conexao_source">';
			echo '<option value="">' . esc_html__( 'Todas as fontes', 'conexao-admin-ux' ) . '</option>';
			if ( class_exists( 'Conexao_Event_Sources' ) ) {
				$sources = ( new Conexao_Event_Sources() )->get_all();
				if ( is_array( $sources ) ) {
					foreach ( $sources as $source ) {
						if ( ! is_array( $source ) || ! isset( $source['id'], $source['name'] ) ) {
							continue;
						}
						printf(
							'<option value="%1$s" %2$s>%3$s</option>',
							esc_attr( (string) $source['id'] ),
							selected( $current, (string) $source['id'], false ),
							esc_html( (string) $source['name'] )
						);
					}
				}
			}
			echo '</select>';
		}

		// County filter (events, guides).
		if ( in_array( $this->post_type, array( 'event', 'guide' ), true ) ) {
			$current = isset( $_GET['conexao_county'] ) ? sanitize_text_field( wp_unslash( $_GET['conexao_county'] ) ) : '';
			$terms   = get_terms( array( 'taxonomy' => 'conexao_county', 'hide_empty' => false, 'orderby' => 'name' ) );
			if ( ! is_wp_error( $terms ) ) {
				echo '<select name="conexao_county">';
				echo '<option value="">' . esc_html__( 'Todos os condados', 'conexao-admin-ux' ) . '</option>';
				foreach ( $terms as $term ) {
					printf(
						'<option value="%1$s" %2$s>%3$s</option>',
						esc_attr( $term->slug ),
						selected( $current, $term->slug, false ),
						esc_html( $term->name )
					);
				}
				echo '</select>';
			}
		}
	}

	/**
	 * Apply list filters to the query.
	 *
	 * @param WP_Query $query Query object.
	 */
	public function apply_filters( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$post_type = $query->get( 'post_type' );
		if ( $post_type !== $this->post_type ) {
			return;
		}

		$meta_query = $query->get( 'meta_query' );
		if ( ! is_array( $meta_query ) ) {
			$meta_query = array();
		}

		// Source filter.
		$source = isset( $_GET['conexao_source'] ) ? sanitize_text_field( wp_unslash( $_GET['conexao_source'] ) ) : '';
		if ( $source ) {
			$meta_query[] = array(
				'key'   => '_event_source',
				'value' => $source,
			);
		}

		// County filter.
		$county = isset( $_GET['conexao_county'] ) ? sanitize_text_field( wp_unslash( $_GET['conexao_county'] ) ) : '';
		if ( $county ) {
			$tax_query = $query->get( 'tax_query' );
			if ( ! is_array( $tax_query ) ) {
				$tax_query = array();
			}
			$tax_query[] = array(
				'taxonomy' => 'conexao_county',
				'field'    => 'slug',
				'terms'    => $county,
			);
			$query->set( 'tax_query', $tax_query );
		}

		// Custom status filter (non-event types use their own status meta).
		if ( 'event' !== $this->post_type ) {
			$status = isset( $_GET['conexao_status'] ) ? sanitize_text_field( wp_unslash( $_GET['conexao_status'] ) ) : '';
			if ( $status ) {
				$meta_query[] = array(
					'key'   => Conexao_Admin_Ux_Actions::status_meta_key( $this->post_type ),
					'value' => $status,
				);
			}
		}

		if ( ! empty( $meta_query ) ) {
			$query->set( 'meta_query', $meta_query );
		}

		// Sort events by event date ascending by default.
		if ( 'event' === $this->post_type && empty( $_GET['orderby'] ) ) {
			$query->set( 'meta_key', '_event_date' );
			$query->set( 'orderby', 'meta_value' );
			$query->set( 'order', 'ASC' );
		}
	}

	/**
	 * Register custom bulk actions.
	 *
	 * @param array $actions Default bulk actions.
	 * @return array
	 */
	public function bulk_actions( $actions ) {
		unset( $actions['edit'] );
		unset( $actions['trash'] );

		if ( ! empty( $this->config['bulk_actions'] ) && is_array( $this->config['bulk_actions'] ) ) {
			foreach ( $this->config['bulk_actions'] as $key => $action ) {
				if ( ! is_array( $action ) || empty( $action['label'] ) || ! is_string( $action['label'] ) ) {
					continue;
				}
				$actions[ 'conexao_' . $key ] = $action['label'];
			}
		}

		return $actions;
	}

	/**
	 * Handle custom bulk actions.
	 *
	 * @param string $redirect URL to redirect to.
	 * @param string $doaction Action key.
	 * @param int[]  $post_ids Selected post IDs.
	 * @return string
	 */
	public function handle_bulk( $redirect, $doaction, $post_ids ) {
		if ( 0 !== strpos( $doaction, 'conexao_' ) ) {
			return $redirect;
		}

		$key    = substr( $doaction, strlen( 'conexao_' ) );
		$action = isset( $this->config['bulk_actions'][ $key ] ) ? $this->config['bulk_actions'][ $key ] : null;
		if ( ! $action || ! is_array( $action ) ) {
			return $redirect;
		}

		$post_ids = array_map( 'absint', $post_ids );
		$count    = 0;
		$type     = isset( $action['type'] ) ? $action['type'] : '';

		switch ( $type ) {
			case 'status':
				$count = Conexao_Admin_Ux_Actions::bulk_status( $post_ids, $this->post_type, $action['value'] );
				break;

			case 'taxonomy':
				$term_id = isset( $_POST[ 'conexao_bulk_' . $key ] ) ? absint( $_POST[ 'conexao_bulk_' . $key ] ) : 0;
				$taxonomy = isset( $action['taxonomy'] ) ? $action['taxonomy'] : '';
				if ( $taxonomy ) {
					$count = Conexao_Admin_Ux_Actions::bulk_taxonomy( $post_ids, $taxonomy, $term_id );
				}
				break;

			case 'delete':
				$count = Conexao_Admin_Ux_Actions::bulk_delete( $post_ids, $this->post_type );
				break;
		}

		$redirect = add_query_arg(
			array(
				'conexao_bulk_done'  => rawurlencode( $key ),
				'conexao_bulk_count' => $count,
			),
			$redirect
		);

		return $redirect;
	}

	/**
	 * Show a success notice after bulk actions.
	 */
	public function bulk_notice() {
		if ( empty( $_GET['conexao_bulk_done'] ) || empty( $_GET['post_type'] ) || $_GET['post_type'] !== $this->post_type ) {
			return;
		}

		$key     = sanitize_key( wp_unslash( $_GET['conexao_bulk_done'] ) );
		$count   = isset( $_GET['conexao_bulk_count'] ) ? absint( $_GET['conexao_bulk_count'] ) : 0;
		$actions = isset( $this->config['bulk_actions'][ $key ] ) ? $this->config['bulk_actions'][ $key ] : null;
		$label   = ( $actions && is_array( $actions ) && isset( $actions['label'] ) && is_string( $actions['label'] ) )
			? strtolower( $actions['label'] )
			: $key;

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( sprintf( __( '%d %s com sucesso.', 'conexao-admin-ux' ), $count, $label ) )
		);
	}

	/**
	 * Add Duplicate / Archive / Delete row actions.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Post object.
	 * @return array
	 */
	public function row_actions( $actions, $post ) {
		if ( $post->post_type !== $this->post_type ) {
			return $actions;
		}

		$post_status = Conexao_Admin_Ux_Actions::get_status( $post->ID, $this->post_type );

		// View / preview link.
		if ( 'published' === $post_status && 'publish' === $post->post_status ) {
			$actions['view'] = '<a href="' . esc_url( get_permalink( $post->ID ) ) . '" target="_blank">' . esc_html__( 'Ver no site ↗', 'conexao-admin-ux' ) . '</a>';
		} elseif ( 'publish' !== $post->post_status ) {
			$preview = get_preview_post_link( $post );
			if ( $preview ) {
				$actions['view'] = '<a href="' . esc_url( $preview ) . '" target="_blank">' . esc_html__( 'Visualizar rascunho ↗', 'conexao-admin-ux' ) . '</a>';
			}
		}

		// Duplicate.
		$dup_url = wp_nonce_url(
			admin_url( 'edit.php?post_type=' . $this->post_type . '&conexao_action=duplicate&post=' . $post->ID ),
			'conexao_duplicate_' . $post->ID
		);
		$actions['conexao_duplicate'] = '<a href="' . esc_url( $dup_url ) . '" aria-label="' . esc_attr__( 'Duplicar', 'conexao-admin-ux' ) . '">' . esc_html__( 'Duplicar', 'conexao-admin-ux' ) . '</a>';

		// Archive.
		if ( 'archived' !== $post_status ) {
			$archive_url = wp_nonce_url(
				admin_url( 'edit.php?post_type=' . $this->post_type . '&conexao_action=archive&post=' . $post->ID ),
				'conexao_archive_' . $post->ID
			);
			$actions['conexao_archive'] = '<a href="' . esc_url( $archive_url ) . '" aria-label="' . esc_attr__( 'Arquivar', 'conexao-admin-ux' ) . '">' . esc_html__( 'Arquivar', 'conexao-admin-ux' ) . '</a>';
		}

		// Delete (permanent, with confirm).
		$del_url = wp_nonce_url(
			admin_url( 'edit.php?post_type=' . $this->post_type . '&conexao_action=delete&post=' . $post->ID ),
			'conexao_delete_' . $post->ID
		);
		$actions['conexao_delete'] = '<a href="' . esc_url( $del_url ) . '" class="conexao-delete-link" data-confirm="' . esc_attr__( 'Excluir permanentemente? Esta ação não pode ser desfeita.', 'conexao-admin-ux' ) . '" aria-label="' . esc_attr__( 'Excluir permanentemente', 'conexao-admin-ux' ) . '">' . esc_html__( 'Excluir', 'conexao-admin-ux' ) . '</a>';

		return $actions;
	}

	/**
	 * Keep some columns hidden by default for a cleaner list.
	 *
	 * @param array  $hidden Hidden columns.
	 * @param object $screen Screen object.
	 * @return array
	 */
	public function default_hidden_columns( $hidden, $screen ) {
		if ( isset( $screen->post_type ) && $screen->post_type === $this->post_type ) {
			$hidden[] = 'date';
		}
		return $hidden;
	}

	/**
	 * Render actionable summary cards at the top of the list.
	 */
	public function render_summary_cards() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-' . $this->post_type !== $screen->id || empty( $this->config['summary'] ) || ! is_array( $this->config['summary'] ) ) {
			return;
		}

		echo '<div class="conexao-summary-cards">';

		foreach ( $this->config['summary'] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$count      = $this->summary_count( $item );
			$url        = $this->summary_url( $item );
			$item_key   = isset( $item['key'] ) && is_string( $item['key'] ) ? $item['key'] : 'summary';
			$item_label = isset( $item['label'] ) && is_string( $item['label'] ) ? $item['label'] : '';

			printf(
				'<a href="%1$s" class="conexao-summary-card conexao-summary-card--%2$s"><span class="conexao-summary-value">%3$d</span><span class="conexao-summary-label">%4$s</span></a>',
				esc_url( $url ),
				esc_attr( $item_key ),
				(int) $count,
				esc_html( $item_label )
			);
		}

		echo '</div>';
	}

	/**
	 * Count posts matching a summary definition.
	 *
	 * @param array $item Summary definition.
	 * @return int
	 */
	private function summary_count( $item ) {
		$args = array(
			'post_type'      => $this->post_type,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
		);

		// Status summary.
		if ( ! empty( $item['status'] ) ) {
			$meta_query = array(
				array(
					'key'   => Conexao_Admin_Ux_Actions::status_meta_key( $this->post_type ),
					'value' => $item['status'],
				),
			);
			// Events: also count legacy events without a status as published.
			if ( 'event' === $this->post_type && 'published' === $item['status'] ) {
				$meta_query = array(
					'relation' => 'OR',
					array(
						'key'   => '_event_status',
						'value' => 'published',
					),
					array(
						'key'     => '_event_status',
						'compare' => 'NOT EXISTS',
					),
				);
			}
			$args['meta_query'] = $meta_query;
		}

		// Weekly range.
		if ( ! empty( $item['date_range'] ) && 'week' === $item['date_range'] ) {
			$args['date_query'] = array(
				array(
					'after'     => '1 week ago',
					'inclusive' => true,
				),
			);
		}

		// Closing soon (jobs).
		if ( ! empty( $item['closing_range'] ) && 'week' === $item['closing_range'] && 'job' === $this->post_type ) {
			$args['meta_query'] = array(
				array(
					'key'     => '_job_closing_date',
					'value'   => gmdate( 'Y-m-d', strtotime( '+1 week' ) ),
					'compare' => '<=',
					'type'    => 'DATE',
				),
			);
		}

		$query = new WP_Query( $args );
		return (int) $query->found_posts;
	}

	/**
	 * Build the filtered-list URL for a summary card.
	 *
	 * @param array $item Summary definition.
	 * @return string
	 */
	private function summary_url( $item ) {
		$base = admin_url( 'edit.php?post_type=' . $this->post_type );

		if ( ! empty( $item['status'] ) ) {
			if ( 'event' === $this->post_type ) {
				return add_query_arg( 'event_status', $item['status'], $base );
			}
			return add_query_arg( 'conexao_status', $item['status'], $base );
		}

		if ( ! empty( $item['date_range'] ) ) {
			return add_query_arg( 'conexao_range', $item['date_range'], $base );
		}

		return $base;
	}
}