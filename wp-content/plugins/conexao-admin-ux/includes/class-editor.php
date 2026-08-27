<?php
/**
 * Structured editor for the Conexão Admin UX.
 *
 * Replaces the default meta-box clutter with a clean, sectioned editor:
 *   - Logical sections rendered as cards with icons and headings
 *   - Sticky publish/save sidebar with status, preview, and action buttons
 *   - Imported-content insight panel (source, import date, last checked)
 *   - Inline validation messages before publish
 *   - Clear "where this appears" context
 *
 * @package Conexao_Admin_Ux
 */

defined( 'ABSPATH' ) || exit;

final class Conexao_Admin_Ux_Editor {

	/** @var string */
	private $post_type;

	/** @var array */
	private $config;

	/**
	 * Recursion guard: tracks post IDs currently being saved to prevent
	 * infinite loops when wp_update_post() re-fires save_post_{type}.
	 *
	 * @var array<int,bool>
	 */
	private static $saving = array();

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
	 * Register editor hooks.
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'remove_default_meta_boxes' ), 20 );
		add_action( 'add_meta_boxes', array( $this, 'add_editor_meta_box' ), 30 );
		add_action( 'add_meta_boxes', array( $this, 'add_publish_meta_box' ), 30 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'save_post_' . $this->post_type, array( $this, 'save' ), 10, 2 );
		add_filter( 'redirect_post_location', array( $this, 'redirect_with_notice' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'show_save_notice' ) );
	}

	/**
	 * Enqueue editor assets.
	 *
	 * @param string $hook Current admin hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== $this->post_type ) {
			return;
		}

		wp_enqueue_style( 'conexao-admin-ux', CONEXAO_ADMIN_UX_URL . 'assets/admin.css', array(), CONEXAO_ADMIN_UX_VERSION );
		wp_enqueue_script( 'conexao-admin-ux', CONEXAO_ADMIN_UX_URL . 'assets/admin.js', array( 'jquery' ), CONEXAO_ADMIN_UX_VERSION, true );
		wp_localize_script(
			'conexao-admin-ux',
			'ConexaoAdminUx',
			array(
				'laoisTowns'   => Conexao_Admin_Ux_Config::laois_towns(),
				'confirmDelete' => __( 'Excluir permanentemente? Esta ação não pode ser desfeita.', 'conexao-admin-ux' ),
				'confirmArchive' => __( 'Arquivar este conteúdo? Ele deixará de aparecer no site público.', 'conexao-admin-ux' ),
			)
		);

		// Ensure media library picker works.
		wp_enqueue_media();
	}

	/**
	 * Remove the default meta boxes we replace.
	 */
	public function remove_default_meta_boxes() {
		$screen = get_current_screen();
		if ( ! $screen || $screen->id !== $this->post_type ) {
			return;
		}

		// Remove the generic meta box from the data-model plugin.
		remove_meta_box( 'conexao_' . $this->post_type . '_details', $this->post_type, 'normal' );
		remove_meta_box( 'conexao_event_status_box', $this->post_type, 'side' );

		// Keep the default editor but hide it; our custom editor replaces it.
		// We remove the core editor and re-add our sectioned version.
		remove_meta_box( 'postdivrich', $this->post_type, 'normal' );
	}

	/**
	 * Add the main sectioned editor meta box.
	 */
	public function add_editor_meta_box() {
		$screen = get_current_screen();
		if ( ! $screen || $screen->id !== $this->post_type ) {
			return;
		}

		add_meta_box(
			'conexao_admin_ux_editor',
			$this->config['labels']['edit_item'],
			array( $this, 'render_editor' ),
			$this->post_type,
			'normal',
			'high'
		);
	}

	/**
	 * Add the publish/save sidebar.
	 */
	public function add_publish_meta_box() {
		$screen = get_current_screen();
		if ( ! $screen || $screen->id !== $this->post_type ) {
			return;
		}

		add_meta_box(
			'conexao_admin_ux_publish',
			__( 'Publicação', 'conexao-admin-ux' ),
			array( $this, 'render_publish_box' ),
			$this->post_type,
			'side',
			'high'
		);
	}

	/**
	 * Render the sectioned editor.
	 *
	 * @param WP_Post $post Post object.
	 */
	public function render_editor( $post ) {
		wp_nonce_field( 'conexao_admin_ux_save', 'conexao_admin_ux_nonce' );

		// Keep the core WordPress title/content fields in the form so the
		// standard save flow also picks them up (redundant safety).
		echo '<div style="display:none;">';
		echo '<input type="text" name="post_title" id="conexao_post_title_hidden" value="' . esc_attr( $post->post_title ) . '" />';
		echo '<textarea name="content" id="conexao_post_content_hidden" rows="10">' . esc_textarea( $post->post_content ) . '</textarea>';
		echo '</div>';

		echo '<div class="conexao-editor-wrapper">';

		// Import info banner for imported events.
		if ( 'event' === $this->post_type ) {
			$this->render_import_banner( $post );
		}

		$sections = $this->config['sections'];
		usort( $sections, function ( $a, $b ) {
			return ( $a['priority'] ?? 10 ) <=> ( $b['priority'] ?? 10 );
		} );

		foreach ( $sections as $key => $section ) {
			$icon    = isset( $section['icon'] ) ? $section['icon'] : 'dashicons-admin-generic';
			$section_id = 'conexao-section--' . sanitize_html_class( $key );
			echo '<div class="conexao-section" id="' . esc_attr( $section_id ) . '">';
			echo '<div class="conexao-section-header">';
			echo '<span class="conexao-section-icon dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span>';
			echo '<h2 class="conexao-section-title">' . esc_html( $section['title'] ) . '</h2>';
			echo '</div>';
			echo '<div class="conexao-section-body">';
			echo '<div class="conexao-fields-grid">';
			foreach ( $section['fields'] as $field ) {
				$value = Conexao_Admin_Ux_Fields::get_value( $post, $field );
				echo Conexao_Admin_Ux_Fields::render( $field, $value, $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput -- field renderer escapes its output.
			}
			echo '</div>';

			echo '</div></div>';
		}

		echo '</div>';
	}

	/**
	 * Render the publish/save sidebar.
	 *
	 * @param WP_Post $post Post object.
	 */
	public function render_publish_box( $post ) {
		$status   = Conexao_Admin_Ux_Actions::get_status( $post->ID, $this->post_type );
		$statuses = isset( $this->config['publishing']['statuses'] ) ? $this->config['publishing']['statuses'] : array();
		$label    = isset( $statuses[ $status ]['label'] ) ? $statuses[ $status ]['label'] : $status;
		$badge    = isset( $statuses[ $status ]['badge'] ) ? $statuses[ $status ]['badge'] : 'draft';

		$is_new = 'auto-draft' === $post->post_status;
		$is_published = 'published' === $status;

		// Where this appears.
		$public_url = ( 'publish' === $post->post_status ) ? get_permalink( $post->ID ) : '';
		$archive_url = get_post_type_archive_link( $this->post_type );

		echo '<div class="conexao-publish-box">';

		// Current status display.
		echo '<div class="conexao-current-status">';
		printf(
			'<span class="conexao-status-badge conexao-status-badge--%1$s"><span class="conexao-status-dot" aria-hidden="true">●</span> %2$s</span>',
			esc_attr( $badge ),
			esc_html( $label )
		);
		echo '</div>';

		// Where it appears.
		echo '<div class="conexao-where-appears">';
		echo '<h4>' . esc_html__( 'Onde este conteúdo aparece', 'conexao-admin-ux' ) . '</h4>';
		if ( $public_url ) {
			echo '<p><a href="' . esc_url( $public_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Ver no site ↗', 'conexao-admin-ux' ) . '</a></p>';
		} else {
			$preview = get_preview_post_link( $post );
			if ( $preview ) {
				echo '<p><a href="' . esc_url( $preview ) . '" target="_blank" rel="noopener">' . esc_html__( 'Visualizar rascunho ↗', 'conexao-admin-ux' ) . '</a></p>';
			}
		}
		if ( $archive_url ) {
			echo '<p class="conexao-muted">' . esc_html__( 'Página pública:', 'conexao-admin-ux' ) . ' <a href="' . esc_url( $archive_url ) . '" target="_blank" rel="noopener">' . esc_html( $archive_url ) . '</a></p>';
		}
		echo '</div>';

		// Status selector.
		echo '<div class="conexao-status-select">';
		echo '<label for="conexao_editor_status"><strong>' . esc_html__( 'Status', 'conexao-admin-ux' ) . '</strong></label>';
		echo '<select id="conexao_editor_status" name="conexao_editor_status">';
		foreach ( $statuses as $key => $item ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $key ),
				selected( $status, $key, false ),
				esc_html( $item['label'] )
			);
		}
		echo '</select>';
		echo '</div>';

		// Action buttons.
		echo '<div class="conexao-publish-actions">';
		if ( $is_new ) {
			echo '<button type="submit" name="conexao_publish_action" value="draft" class="button button-large">' . esc_html__( 'Salvar rascunho', 'conexao-admin-ux' ) . '</button>';
			echo '<button type="submit" name="conexao_publish_action" value="publish" class="button button-primary button-large">' . esc_html__( 'Publicar', 'conexao-admin-ux' ) . '</button>';
		} else {
			echo '<button type="submit" name="conexao_publish_action" value="publish" class="button button-primary button-large">' . esc_html__( 'Atualizar', 'conexao-admin-ux' ) . '</button>';
			echo '<button type="submit" name="conexao_publish_action" value="draft" class="button button-large">' . esc_html__( 'Salvar rascunho', 'conexao-admin-ux' ) . '</button>';
		}
		echo '</div>';

		// Hidden status field for non-event types.
		echo '<input type="hidden" name="conexao_editor_status_input" value="1" />';

		// Danger zone (archive / delete for existing posts).
		if ( ! $is_new ) {
			echo '<div class="conexao-danger-zone">';
			if ( 'archived' !== $status ) {
				$archive_url = wp_nonce_url(
					admin_url( 'edit.php?post_type=' . $this->post_type . '&conexao_action=archive&post=' . $post->ID ),
					'conexao_archive_' . $post->ID
				);
				echo '<a href="' . esc_url( $archive_url ) . '" class="conexao-archive-link" data-confirm="' . esc_attr__( 'Arquivar este conteúdo? Ele deixará de aparecer no site público.', 'conexao-admin-ux' ) . '">' . esc_html__( 'Arquivar', 'conexao-admin-ux' ) . '</a>';
			}
			$del_url = wp_nonce_url(
				admin_url( 'edit.php?post_type=' . $this->post_type . '&conexao_action=delete&post=' . $post->ID ),
				'conexao_delete_' . $post->ID
			);
			echo '<a href="' . esc_url( $del_url ) . '" class="conexao-delete-link" data-confirm="' . esc_attr__( 'Excluir permanentemente? Esta ação não pode ser desfeita.', 'conexao-admin-ux' ) . '">' . esc_html__( 'Excluir permanentemente', 'conexao-admin-ux' ) . '</a>';
			echo '</div>';
		}

		// Return to list link.
		echo '<p class="conexao-back-link"><a href="' . esc_url( admin_url( 'edit.php?post_type=' . $this->post_type ) ) . '">← ' . esc_html( sprintf( __( 'Voltar para %s', 'conexao-admin-ux' ), $this->config['labels']['plural'] ) ) . '</a></p>';

		echo '</div>';
	}

	/**
	 * Render imported-event insight banner.
	 *
	 * @param WP_Post $post Post object.
	 */
	private function render_import_banner( $post ) {
		$source     = get_post_meta( $post->ID, '_event_source', true );
		$source_url = get_post_meta( $post->ID, '_event_url', true );
		$imported   = get_post_meta( $post->ID, '_event_imported', true );
		$import_date = get_post_meta( $post->ID, '_event_import_date', true );
		$last_check = get_post_meta( $post->ID, '_event_last_checked', true );
		$status      = Conexao_Admin_Ux_Actions::get_status( $post->ID, 'event' );

		if ( ! $source && ! $imported ) {
			return;
		}

		$source_name = $source;
		if ( class_exists( 'Conexao_Event_Sources' ) ) {
			$sources = ( new Conexao_Event_Sources() )->get_all();
			if ( isset( $sources[ $source ]['name'] ) ) {
				$source_name = $sources[ $source ]['name'];
			}
		}

		echo '<div class="conexao-import-banner conexao-import-banner--' . esc_attr( $status ) . '">';
		echo '<div class="conexao-import-banner-head">';
		echo '<strong>' . esc_html__( 'Importado automaticamente', 'conexao-admin-ux' ) . '</strong>';
		echo '</div>';

		echo '<div class="conexao-import-details">';
		if ( $source_name ) {
			echo '<p><strong>' . esc_html__( 'Fonte', 'conexao-admin-ux' ) . ':</strong> ' . esc_html( $source_name ) . '</p>';
		}
		if ( $import_date ) {
			echo '<p><strong>' . esc_html__( 'Importado', 'conexao-admin-ux' ) . ':</strong> ' . esc_html( date_i18n( 'j M Y', strtotime( $import_date ) ) ) . '</p>';
		}
		if ( $last_check ) {
			echo '<p><strong>' . esc_html__( 'Última verificação', 'conexao-admin-ux' ) . ':</strong> ' . esc_html( date_i18n( 'j M Y', strtotime( $last_check ) ) ) . '</p>';
		}
		if ( $source_url ) {
			echo '<p><a href="' . esc_url( $source_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Abrir evento original ↗', 'conexao-admin-ux' ) . '</a></p>';
		}
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Save handler for the sectioned editor.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Prevent infinite recursion: wp_update_post() re-fires save_post_{type},
		// which would call this method again before Fields::save() is reached.
		if ( ! empty( self::$saving[ $post_id ] ) ) {
			return;
		}
		self::$saving[ $post_id ] = true;

		if ( ! isset( $_POST['conexao_admin_ux_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['conexao_admin_ux_nonce'] ) ), 'conexao_admin_ux_save' ) ) {
			unset( self::$saving[ $post_id ] );
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			unset( self::$saving[ $post_id ] );
			return;
		}

		$data = wp_unslash( $_POST );

		// Save title and content from virtual fields.
		$field_key  = ltrim( $this->title_field_key(), '_' );
		$content_key = ltrim( $this->content_field_key(), '_' );

		$title = isset( $data['conexao_fields'][ $field_key ] ) ? sanitize_text_field( $data['conexao_fields'][ $field_key ] ) : ( isset( $data['post_title'] ) ? sanitize_text_field( $data['post_title'] ) : $post->post_title );
		$content = isset( $data['conexao_fields'][ $content_key ] ) ? wp_kses_post( $data['conexao_fields'][ $content_key ] ) : ( isset( $data['content'] ) ? wp_kses_post( $data['content'] ) : $post->post_content );

		// Validate required fields.
		$errors = Conexao_Admin_Ux_Fields::validate( $this->config, $data );

		// Determine the desired status before saving.
		$publish_action = isset( $data['conexao_publish_action'] ) ? sanitize_key( $data['conexao_publish_action'] ) : '';
		$status         = isset( $data['conexao_editor_status'] ) ? sanitize_key( $data['conexao_editor_status'] ) : '';
		if ( 'publish' === $publish_action || ( $status && 'published' === $status ) || ( isset( $data['publish'] ) && '' !== $data['publish'] ) ) {
			if ( empty( $errors ) ) {
				$status = 'published';
			} else {
				$status = 'draft';
			}
		} elseif ( $publish_action ) {
			$status = 'draft';
		}

		if ( empty( $status ) ) {
			$status = isset( $this->config['publishing']['default_status'] ) ? $this->config['publishing']['default_status'] : 'draft';
		}

		// Build the post array.
		$post_array = array(
			'ID'           => $post_id,
			'post_title'   => $title,
			'post_content' => $content,
		);

		// For content types whose "description" is a textarea (not a rich
		// editor), also populate post_excerpt so public templates that use
		// get_the_excerpt() display the saved description correctly.
		if ( 'sponsor' === $this->post_type || 'course_provider' === $this->post_type ) {
			$post_array['post_excerpt'] = $content;
		}

		// Set WordPress publish/draft status.
		if ( 'published' === $status ) {
			$post_array['post_status'] = 'publish';
		} else {
			$post_array['post_status'] = 'draft';
		}

		wp_update_post( $post_array );

		// Capture the PRE-save state of the sponsor image relationships so the
		// thumbnail sync below can tell "administrator cleared the image"
		// apart from "record never had admin-managed images" — a sponsor whose
		// artwork lived solely in the WordPress featured image must keep it.
		$sponsor_image_state = null;
		if ( 'sponsor' === $this->post_type ) {
			$sponsor_image_state = array(
				'image'   => get_post_meta( $post_id, '_sponsor_image', true ),
				'mobile'  => get_post_meta( $post_id, '_sponsor_mobile_image', true ),
				'desktop' => get_post_meta( $post_id, '_sponsor_desktop_image', true ),
				'legacy'  => get_post_meta( $post_id, '_sponsor_logo', true ),
			);
		}

		// Save all custom fields + taxonomies.
		Conexao_Admin_Ux_Fields::save( $post_id, $this->config, $data );

		// Keep the public theme's legacy event meta in sync
		// (_event_time, _event_location, county/town taxonomies).
		Conexao_Admin_Ux_Fields::sync_legacy_event_meta( $post_id, $data );

		// Sync the sponsor carousel images to the WordPress featured image so
		// public templates using has_post_thumbnail() / the_post_thumbnail()
		// display the correct artwork.
		$this->sync_media_to_thumbnail( $post_id, $data, $sponsor_image_state );

		// Save the custom status.
		Conexao_Admin_Ux_Actions::set_status( $post_id, $this->post_type, $status );

		// Release the recursion guard.
		unset( self::$saving[ $post_id ] );

		// Set the notice for the redirect.
		if ( $errors ) {
			update_option( 'conexao_admin_ux_errors_' . $post_id, $errors, false );
		}

		// Save success message.
		if ( ! $errors ) {
			if ( 'published' === $status ) {
				$message = ( isset( $_POST['original_post_status'] ) && 'publish' === $_POST['original_post_status'] )
					? $this->config['labels']['success_saved']
					: $this->config['labels']['success_published'];
			} elseif ( isset( $_POST['conexao_publish_action'] ) && 'draft' === $_POST['conexao_publish_action'] ) {
				$message = $this->config['labels']['success_draft'];
			} else {
				$message = $this->config['labels']['success_saved'];
			}
			update_option( 'conexao_admin_ux_notice_' . $post_id, $message, false );
		}
	}

	/**
	 * Sync a media-type meta field to the WordPress post thumbnail (featured image).
	 *
	 * The public theme uses has_post_thumbnail() / the_post_thumbnail() to display
	 * sponsor logos and other artwork. This helper ensures the WordPress featured
	 * image is set when a media field value (attachment ID) is saved.
	 *
	 * @param int        $post_id       Post ID.
	 * @param array      $data          Unslashed POST data.
	 * @param array|null $sponsor_state Pre-save snapshot of the sponsor image
	 *                                    metas (desktop/mobile/legacy), or null
	 *                                    for non-sponsor types.
	 */
	private function sync_media_to_thumbnail( $post_id, $data, $sponsor_state = null ) {
		// Sponsors use the responsive two-image model (Imagem Desktop /
		// Imagem Mobile) with its own resolution + safety rules.
		if ( 'sponsor' === $this->post_type ) {
			$this->sync_sponsor_thumbnail( $post_id, $data, is_array( $sponsor_state ) ? $sponsor_state : array() );
			return;
		}

		// Map of post type → meta key (without leading underscore) that holds the attachment ID.
		$media_fields = array(
			'event'           => 'event_banner',
			'guide'           => 'guide_featured_image',
			'course_provider' => 'provider_logo',
			'leisure'         => 'leisure_image_attachment_id',
		);

		if ( ! isset( $media_fields[ $this->post_type ] ) ) {
			return;
		}

		$field_key = $media_fields[ $this->post_type ];
		$meta      = isset( $data['conexao_fields'] ) ? $data['conexao_fields'] : array();

		// Only act when the editor form actually submitted this field. Other
		// write paths (Quick Edit, bulk actions, importers, autosaves) never
		// include it and must not have their thumbnails touched here.
		if ( ! isset( $meta[ $field_key ] ) ) {
			return;
		}

		// Normalize to an attachment ID (also resolves legacy URL values back
		// to their Media Library attachment when possible).
		$value         = Conexao_Admin_Ux_Fields::normalize_media_value( $meta[ $field_key ] );
		$attachment_id = is_int( $value ) ? $value : 0;

		if ( $attachment_id && 'attachment' === get_post_type( $attachment_id ) ) {
			set_post_thumbnail( $post_id, $attachment_id );
		} elseif ( '' === $value ) {
			// Explicitly cleared — remove the featured image via the core
			// API. The Media Library attachment itself is never deleted.
			delete_post_thumbnail( $post_id );
		}
		// Legacy unresolvable URL values leave any existing thumbnail untouched.
	}

	/**
	 * Sync the Apoiador canonical image to the WordPress featured image.
	 *
	 * Resolution order for the featured image (mirrors the front-end fallback
	 * chain in the theme):
	 *
	 *   1. Imagem do Apoiador (_sponsor_image — submitted value)
	 *   2. Legacy pre-consolidation metas still holding the artwork
	 *      (mobile → desktop → _sponsor_logo), so records never re-saved
	 *      through the single-image editor keep their thumbnail.
	 *
	 * The featured image is only CLEARED when the record previously had an
	 * admin-managed image relationship and everything now resolves empty —
	 * sponsors whose artwork lived solely in the WordPress featured image are
	 * left untouched.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    Unslashed POST data.
	 * @param array $state   Pre-save snapshot: image/mobile/desktop/legacy values.
	 */
	private function sync_sponsor_thumbnail( $post_id, $data, $state ) {
		$meta = isset( $data['conexao_fields'] ) ? $data['conexao_fields'] : array();

		// Only act when the redesigned editor actually submitted the image
		// field. Other write paths (Quick Edit, bulk actions, importers,
		// autosaves) must not have their thumbnails touched here.
		if ( ! isset( $meta['sponsor_image'] ) ) {
			return;
		}

		/**
		 * Resolve a submitted media value into a real attachment ID.
		 *
		 * Empty submissions resolve to 0 (explicit clearing). Non-empty
		 * unresolvable legacy URL strings keep the previously stored
		 * relationship when it points at a real attachment.
		 *
		 * @param string $submitted Raw submitted value.
		 * @param mixed  $stored    Pre-save stored value.
		 * @return int Attachment ID or 0.
		 */
		$resolve = function ( $submitted, $stored ) {
			$value = Conexao_Admin_Ux_Fields::normalize_media_value( $submitted );

			if ( is_int( $value ) && $value && 'attachment' === get_post_type( $value ) ) {
				return (int) $value;
			}

			if ( is_string( $value ) && '' !== $value ) {
				$stored_id = absint( $stored );
				if ( $stored_id && 'attachment' === get_post_type( $stored_id ) ) {
					return $stored_id;
				}
			}

			return 0;
		};

		$effective = $resolve(
			isset( $meta['sponsor_image'] ) ? $meta['sponsor_image'] : '',
			isset( $state['image'] ) ? $state['image'] : ''
		);

		if ( ! $effective ) {
			// Unresolvable legacy URL values keep whatever the pre-consolidation
			// metas still hold; an explicitly EMPTY submission does NOT (the
			// administrator removed the image, and Fields::save() has already
			// deleted every legacy relationship).
			foreach ( array( '_sponsor_mobile_image', '_sponsor_desktop_image' ) as $legacy_key ) {
				if ( isset( $state[ 'mobile' === $legacy_key ? 'mobile' : 'desktop' ] ) ) {
					$stored = get_post_meta( $post_id, $legacy_key, true );
					$stored_id = absint( $stored );
					if ( $stored_id && 'attachment' === get_post_type( $stored_id ) && wp_attachment_is_image( $stored_id ) ) {
						$effective = $stored_id;
						break;
					}
				}
			}
		}

		if ( ! $effective ) {
			$legacy = get_post_meta( $post_id, '_sponsor_logo', true );
			$legacy_id = ( is_numeric( $legacy ) && (int) $legacy > 0 ) ? absint( $legacy ) : 0;
			if ( $legacy_id && 'attachment' === get_post_type( $legacy_id ) ) {
				$effective = $legacy_id;
			}
		}

		if ( $effective ) {
			set_post_thumbnail( $post_id, $effective );
			return;
		}

		// Everything resolved empty. Clear the featured image only when the
		// record previously had an admin-managed image relationship.
		$had_managed = ! empty( $state['image'] ) || ! empty( $state['desktop'] ) || ! empty( $state['mobile'] ) || ! empty( $state['legacy'] );
		if ( $had_managed ) {
			delete_post_thumbnail( $post_id );
		}
	}

	/**
	 * Append a query arg with the notice state to the redirect URL.
	 *
	 * @param string $location Redirect location.
	 * @param int    $post_id  Post ID.
	 * @return string
	 */
	public function redirect_with_notice( $location, $post_id ) {
		$errors = get_option( 'conexao_admin_ux_errors_' . $post_id, false );
		if ( $errors ) {
			$location = add_query_arg( 'conexao_validation', '1', $location );
			// Keep the errors option so show_save_notice() can render the
			// specific field messages on the redirected editor screen.
		}

		$notice = get_option( 'conexao_admin_ux_notice_' . $post_id, false );
		if ( $notice ) {
			$location = add_query_arg( 'conexao_saved', '1', $location );
			delete_option( 'conexao_admin_ux_notice_' . $post_id );
		}

		return $location;
	}

	/**
	 * Get the virtual title field key for this content type.
	 *
	 * @return string
	 */
	private function title_field_key() {
		$map = array(
			'event'           => '_event_title',
			'guide'           => '_guide_title',
			'job'             => '_job_title',
			'sponsor'         => '_sponsor_name',
			'course_provider' => '_provider_name',
		);
		return isset( $map[ $this->post_type ] ) ? $map[ $this->post_type ] : '_' . $this->post_type . '_title';
	}

	/**
	 * Get the virtual content field key for this content type.
	 *
	 * @return string
	 */
	private function content_field_key() {
		$map = array(
			'event'           => '_event_description',
			'guide'           => '_guide_content',
			'job'             => '_job_description',
			'sponsor'         => '_sponsor_description',
			'course_provider' => '_provider_description',
		);
		return isset( $map[ $this->post_type ] ) ? $map[ $this->post_type ] : '_' . $this->post_type . '_content';
	}

	/**
	 * Display the save/validation notice.
	 */
	public function show_save_notice() {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base || $screen->post_type !== $this->post_type ) {
			return;
		}

		if ( isset( $_GET['conexao_saved'] ) ) {
			$label = $this->config['labels']['success_saved'];
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $label ) . '</p></div>';
		}

		if ( isset( $_GET['conexao_validation'] ) ) {
			$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
			$errors  = $post_id ? get_option( 'conexao_admin_ux_errors_' . $post_id, array() ) : array();

			echo '<div class="notice notice-error is-dismissible">';
			echo '<p><strong>' . esc_html__( 'Não foi possível publicar.', 'conexao-admin-ux' ) . '</strong></p>';
			if ( is_array( $errors ) && ! empty( $errors ) ) {
				echo '<ul class="conexao-validation-list">';
				foreach ( $errors as $error ) {
					echo '<li>' . esc_html( $error ) . '</li>';
				}
				echo '</ul>';
			} else {
				echo '<p>' . esc_html__( 'Preencha os campos obrigatórios antes de publicar.', 'conexao-admin-ux' ) . '</p>';
			}
			echo '</div>';

			if ( $post_id ) {
				delete_option( 'conexao_admin_ux_errors_' . $post_id );
			}
		}
	}
}