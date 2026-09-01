<?php
/**
 * Leisure Image Admin.
 *
 * Adds a "Lazer → Imagens" admin page under the Leisure menu. This page lets an
 * administrator review every leisure location and, from one place, associate
 * the appropriate image/source with each location — or mark it as
 * "Image pending" when no properly licensed image is available yet.
 *
 * The image is always stored as a local WordPress Media Library attachment
 * (`_leisure_image_attachment_id`), which is set as the post thumbnail
 * automatically by the editor.
 *
 * This page also supports searching Wikimedia Commons for free-licensed images
 * and importing them directly into the Media Library with full attribution.
 * Wikimedia data is stored as source/licensing metadata only.
 *
 * @package Conexao_Admin_Ux
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Leisure_Image_Admin {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_post_conexao_leisure_save_image', array( $this, 'handle_save' ) );
		add_action( 'admin_post_conexao_leisure_mark_pending', array( $this, 'handle_mark_pending' ) );
		add_action( 'wp_ajax_conexao_leisure_wiki_search', array( $this, 'handle_wiki_search' ) );
		add_action( 'wp_ajax_conexao_leisure_wiki_import', array( $this, 'handle_wiki_import' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue admin assets for the leisure images page (Wikimedia search UI).
	 *
		* @param string $hook Current admin hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'tools_page_conexao-leisure-images' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'conexao-wikimedia-admin', CONEXAO_ADMIN_UX_URL . 'assets/wikimedia-admin.js', array( 'jquery' ), CONEXAO_ADMIN_UX_VERSION, true );
		wp_localize_script( 'conexao-wikimedia-admin', 'conexaoWikimedia', array(
			'ajax_url'  => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'conexao_wikimedia_search' ),
			'i18n'      => array(
				'searching'   => __( 'Buscando no Wikimedia Commons...', 'conexao-admin-ux' ),
				'no_results'  => __( 'Nenhuma imagem com licença adequada encontrada no Wikimedia Commons.', 'conexao-admin-ux' ),
				'author'      => __( 'Autor:', 'conexao-admin-ux' ),
				'license'     => __( 'Licença:', 'conexao-admin-ux' ),
				'source'      => __( 'Fonte:', 'conexao-admin-ux' ),
				'size'        => __( 'Tamanho:', 'conexao-admin-ux' ),
				'approve'     => __( 'Aprovar e importar', 'conexao-admin-ux' ),
				'reject'      => __( 'Rejeitar', 'conexao-admin-ux' ),
				'choose'      => __( 'Escolher outra', 'conexao-admin-ux' ),
				'confirm_import' => __( 'Importar esta imagem do Wikimedia Commons para a biblioteca de mídia e associá-la a este local?', 'conexao-admin-ux' ),
			),
		) );
	}

	/**
	 * Register the "Imagens" submenu page under the Lazer menu.
	 */
	public function register_admin_menu() {
		add_submenu_page(
			'edit.php?post_type=leisure',
			__( 'Imagens dos Locais', 'conexao-admin-ux' ),
			__( 'Imagens', 'conexao-admin-ux' ),
			'edit_posts',
			'conexao-leisure-images',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Handle saving an image/source association for a single location.
	 */
	public function handle_save() {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		// Object-level authorization: the user must be able to edit this
		// specific leisure location, not just any post.
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Você não tem permissão para editar locais.', 'conexao-admin-ux' ) );
		}

		check_admin_referer( 'conexao_leisure_image_save', 'conexao_leisure_image_nonce' );

		if ( 'leisure' !== get_post_type( $post_id ) ) {
			wp_safe_redirect( add_query_arg( 'conexao_leisure_img_notice', 'invalid', admin_url( 'admin.php?page=conexao-leisure-images' ) ) );
			exit;
		}

		// Save fields.
		$attachment  = isset( $_POST['leisure_image_attachment_id'] ) ? absint( $_POST['leisure_image_attachment_id'] ) : 0;
		$source      = isset( $_POST['leisure_image_source'] ) ? sanitize_text_field( wp_unslash( $_POST['leisure_image_source'] ) ) : '';
		$source_url  = isset( $_POST['leisure_image_source_url'] ) ? esc_url_raw( wp_unslash( $_POST['leisure_image_source_url'] ) ) : '';
		$attribution = isset( $_POST['leisure_image_attribution'] ) ? sanitize_textarea_field( wp_unslash( $_POST['leisure_image_attribution'] ) ) : '';
		$author      = isset( $_POST['leisure_image_author'] ) ? sanitize_text_field( wp_unslash( $_POST['leisure_image_author'] ) ) : '';
		$license     = isset( $_POST['leisure_image_license'] ) ? sanitize_text_field( wp_unslash( $_POST['leisure_image_license'] ) ) : '';
		$alt_text    = isset( $_POST['leisure_image_alt_text'] ) ? sanitize_text_field( wp_unslash( $_POST['leisure_image_alt_text'] ) ) : '';
		$status      = isset( $_POST['leisure_image_status'] ) ? sanitize_key( wp_unslash( $_POST['leisure_image_status'] ) ) : '';

		// Derive status from provided values when not set to a valid value.
		$valid_statuses = array( 'none', 'pending', 'local' );
		if ( ! in_array( $status, $valid_statuses, true ) ) {
			if ( $attachment && wp_attachment_is_image( $attachment ) ) {
				$status = 'local';
			} else {
				$status = 'pending';
			}
		}

		update_post_meta( $post_id, '_leisure_image_attachment_id', $attachment ? $attachment : '' );
		update_post_meta( $post_id, '_leisure_image_source', $source );
		update_post_meta( $post_id, '_leisure_image_source_url', $source_url );
		update_post_meta( $post_id, '_leisure_image_author', $author );
		update_post_meta( $post_id, '_leisure_image_license', $license );
		update_post_meta( $post_id, '_leisure_image_attribution', $attribution );
		update_post_meta( $post_id, '_leisure_image_alt_text', $alt_text );
		update_post_meta( $post_id, '_leisure_image_status', $status );

		// Keep the WordPress featured thumbnail in sync so the public card hero
		// renders the local image (mirrors the editor's sync_media_to_thumbnail).
		if ( $attachment && wp_attachment_is_image( $attachment ) ) {
			set_post_thumbnail( $post_id, $attachment );
		} else {
			delete_post_meta( $post_id, '_thumbnail_id' );
		}

		// Set the attachment's alt text if a local image is used.
		if ( $attachment && $alt_text ) {
			update_post_meta( $attachment, '_wp_attachment_image_alt', $alt_text );
		}

		wp_safe_redirect( add_query_arg( 'conexao_leisure_img_notice', 'saved', admin_url( 'admin.php?page=conexao-leisure-images' ) ) );
		exit;
	}

	/**
	 * Handle AJAX request: search Wikimedia Commons for a given query.
	 *
	 * Returns candidates with verified licenses. The admin UI lets the
	 * administrator preview and approve before importing.
	 */
	public function handle_wiki_search() {
		check_ajax_referer( 'conexao_wikimedia_search', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'unauthorized' ), 403 );
		}

		$query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
		if ( empty( $query ) ) {
			wp_send_json_error( array( 'message' => 'empty query' ), 400 );
		}

		$client = new Conexao_Wikimedia_Client();
		$titles = $client->search_files( $query, 12 );

		$candidates = array();
		foreach ( $titles as $title ) {
			$info = $client->get_file_info( $title );
			if ( ! $info || ! $info['license_accepted'] || ! $info['image_url'] ) {
				continue;
			}
			// Skip tiny thumbnails.
			if ( $info['image_width'] < 400 || $info['image_height'] < 300 ) {
				continue;
			}
			$candidates[] = array(
				'file_title'       => $info['file_title'],
				'filename'         => $info['filename'],
				'image_url'        => $info['image_url'],
				'image_width'      => $info['image_width'],
				'image_height'     => $info['image_height'],
				'author'           => $info['author'],
				'license_label'    => $info['license_label'],
				'license_short'    => $info['license_short'],
				'license_priority' => $info['license_priority'],
				'page_url'         => $info['page_url'],
				'require_attr'     => $info['require_attr'],
			);
		}

		if ( empty( $candidates ) ) {
			wp_send_json_success( array( 'candidates' => array(), 'message' => 'no_results' ) );
		}

		// Sort by license priority then image size.
		usort( $candidates, function ( $a, $b ) {
			if ( $a['license_priority'] !== $b['license_priority'] ) {
				return $a['license_priority'] <=> $b['license_priority'];
			}
			return ( $b['image_width'] * $b['image_height'] ) <=> ( $a['image_width'] * $a['image_height'] );
		} );

		wp_send_json_success( array(
			'candidates' => array_slice( $candidates, 0, 8 ),
		) );
	}

	/**
	 * Handle AJAX request: import a selected Wikimedia Commons image.
	 *
	 * The admin approves a candidate from the search results; this handler
	 * downloads the image, imports it into the Media Library, sets it as the
	 * post thumbnail, and stores all attribution metadata.
	 */
	public function handle_wiki_import() {
		check_ajax_referer( 'conexao_wikimedia_search', 'nonce' );

		$post_id    = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$file_title = isset( $_POST['file_title'] ) ? sanitize_text_field( wp_unslash( $_POST['file_title'] ) ) : '';

		// Creating a Media Library attachment requires the upload capability,
		// and the target leisure post must be editable by this user.
		if ( ! current_user_can( 'upload_files' ) || ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => 'unauthorized' ), 403 );
		}

		if ( 'leisure' !== get_post_type( $post_id ) || empty( $file_title ) ) {
			wp_send_json_error( array( 'message' => 'invalid parameters' ), 400 );
		}

		require_once CONEXAO_ADMIN_UX_DIR . 'includes/class-wikimedia-client.php';
		$client = new Conexao_Wikimedia_Client();
		$info   = $client->get_file_info( $file_title );

		if ( ! $info || ! $info['license_accepted'] || ! $info['image_url'] ) {
			wp_send_json_error( array( 'message' => 'license not acceptable or image not found' ), 400 );
		}

		$title  = get_the_title( $post_id );
		$county = get_post_meta( $post_id, '_leisure_county', true );
		$slug   = sanitize_title( $title );
		$att_id = $client->import_image( $info['image_url'], $slug, $post_id );

		if ( is_wp_error( $att_id ) ) {
			wp_send_json_error( array( 'message' => $att_id->get_error_message() ), 500 );
		}

		$attribution = 'Foto: ' . $info['author'];
		if ( $info['require_attr'] ) {
			$attribution .= ', Licença: ' . $info['license_label'];
		}
		$attribution .= ', Fonte: Wikimedia Commons';

		$alt_text = $title;
		if ( $county ) {
			$alt_text .= ', ' . $county . ', Irlanda';
		}

		update_post_meta( $post_id, '_leisure_image_attachment_id', (int) $att_id );
		update_post_meta( $post_id, '_leisure_image_source', 'Wikimedia Commons' );
		update_post_meta( $post_id, '_leisure_image_source_url', $info['page_url'] );
		update_post_meta( $post_id, '_leisure_image_author', $info['author'] );
		update_post_meta( $post_id, '_leisure_image_license', $info['license_label'] );
		update_post_meta( $post_id, '_leisure_image_attribution', $attribution );
		update_post_meta( $post_id, '_leisure_image_alt_text', $alt_text );
		update_post_meta( $post_id, '_leisure_image_status', 'local' );

		set_post_thumbnail( $post_id, $att_id );
		update_post_meta( $att_id, '_wp_attachment_image_alt', $alt_text );
		wp_update_post( array(
			'ID'         => $att_id,
			'post_title' => $title,
		) );

		wp_send_json_success( array(
			'attachment_id' => (int) $att_id,
			'attribution'   => $attribution,
			'alt_text'      => $alt_text,
		) );
	}

	/**
	 * Handle marking one or more locations as "Image pending" (no licensed image yet).
	 */
	public function handle_mark_pending() {
		$is_bulk = isset( $_POST['bulk_pending'] );
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		// Bulk pending touches every published leisure post, so the broad
		// edit_posts capability applies there. The single-post path is
		// authorized at the object level instead.
		if ( $is_bulk ) {
			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_die( esc_html__( 'Você não tem permissão para editar locais.', 'conexao-admin-ux' ) );
			}
		} elseif ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Você não tem permissão para editar locais.', 'conexao-admin-ux' ) );
		}

		check_admin_referer( 'conexao_leisure_mark_pending', 'conexao_leisure_pending_nonce' );

		$redirect = admin_url( 'admin.php?page=conexao-leisure-images' );

		// Bulk: mark all published leisure locations without an image as pending.
		if ( isset( $_POST['bulk_pending'] ) ) {
			$query = new WP_Query(
				array(
					'post_type'      => 'leisure',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array(
						array(
							'key'     => '_leisure_image_attachment_id',
							'compare' => 'NOT EXISTS',
						),
					),
				)
			);

			$count = 0;
			if ( $query->have_posts() ) {
				// Prime the post cache once so the per-post capability checks
				// below do not trigger a database query per post.
				_prime_post_caches( $query->posts, false, false );
				foreach ( $query->posts as $id ) {
					if ( ! current_user_can( 'edit_post', $id ) ) {
						continue;
					}
					// Only touch locations lacking a local image.
					if ( ! get_post_thumbnail_id( $id ) ) {
						update_post_meta( $id, '_leisure_image_status', 'pending' );
						$count++;
					}
				}
			}
			wp_safe_redirect( add_query_arg( 'conexao_leisure_img_notice', 'pending', $redirect ) . '&count=' . $count );
			exit;
		}

		// Single location mark as pending.
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( $post_id && 'leisure' === get_post_type( $post_id ) ) {
			update_post_meta( $post_id, '_leisure_image_status', 'pending' );
			delete_post_meta( $post_id, '_leisure_image_attachment_id' );
			delete_post_meta( $post_id, '_thumbnail_id' );
			wp_safe_redirect( add_query_arg( 'conexao_leisure_img_notice', 'saved', $redirect ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'conexao_leisure_img_notice', 'invalid', $redirect ) );
		exit;
	}

	/**
	 * Render the Leisure Images admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$notice = isset( $_GET['conexao_leisure_img_notice'] ) ? sanitize_key( wp_unslash( $_GET['conexao_leisure_img_notice'] ) ) : '';
		$count  = isset( $_GET['count'] ) ? absint( $_GET['count'] ) : 0;

		// Pending count: published leisure locations without a local image.
		$pending_query = new WP_Query(
			array(
				'post_type'      => 'leisure',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => '_leisure_image_attachment_id',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		$pending_count = $pending_query->post_count;

		// Statistics: imported (local), pending, external.
		$stats = array(
			'local'    => (int) get_posts( array(
				'post_type'      => 'leisure',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array( 'key' => '_leisure_image_status', 'value' => 'local' ),
				),
				'fields'  => 'ids',
				'no_found_rows' => true,
			) ),
			'pending'  => (int) get_posts( array(
				'post_type'      => 'leisure',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array( 'key' => '_leisure_image_status', 'value' => 'pending' ),
				),
				'fields'  => 'ids',
				'no_found_rows' => true,
			) ),
		);

		// List all leisure locations for the table.
		$locations = new WP_Query(
			array(
				'post_type'      => 'leisure',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		?>
		<div class="wrap conexao-leisure-images">
			<h1><?php esc_html_e( 'Imagens dos Locais de Lazer', 'conexao-admin-ux' ); ?></h1>
			<p>
				<?php esc_html_e( 'Associe a imagem e a fonte de cada local da página /lazer/. Use apenas imagens com direitos adequados (biblioteca de mídia, Wikimedia Commons, site oficial do local que permita o uso, ou foto própria). Não use imagens do Discover Ireland sem permissão — a página oficial pode ser guardada como referência.', 'conexao-admin-ux' ); ?>
			</p>

			<?php if ( 'saved' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Imagem/fonte atualizada com sucesso.', 'conexao-admin-ux' ); ?></p></div>
			<?php elseif ( 'pending' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( __( '%d local(is) marcado(s) como "Imagem pendente".', 'conexao-admin-ux' ), $count ) ); ?></p></div>
			<?php elseif ( 'invalid' === $notice ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Local inválido. Nenhuma alteração foi feita.', 'conexao-admin-ux' ); ?></p></div>
			<?php endif; ?>

			<div class="conexao-leisure-images-card">
				<h2><?php esc_html_e( 'Estatísticas de imagens', 'conexao-admin-ux' ); ?></h2>
				<p>
					<strong><?php echo esc_html( number_format_i18n( $stats['local'] ) ); ?></strong>
					<?php esc_html_e( ' locais com imagem importada da biblioteca de mídia', 'conexao-admin-ux' ); ?>
					| <strong><?php echo esc_html( number_format_i18n( $stats['pending'] ) ); ?></strong>
					<?php esc_html_e( ' com imagem pendente', 'conexao-admin-ux' ); ?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 8px;">
					<input type="hidden" name="action" value="conexao_leisure_mark_pending">
					<input type="hidden" name="bulk_pending" value="1">
					<?php wp_nonce_field( 'conexao_leisure_mark_pending', 'conexao_leisure_pending_nonce' ); ?>
					<?php submit_button( __( 'Marcar todos sem imagem como "Imagem pendente"', 'conexao-admin-ux' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>

			<?php if ( class_exists( 'Conexao_Wikimedia_Client' ) ) : ?>
				<div class="conexao-leisure-images-card" style="margin-top:12px;">
					<h2><?php esc_html_e( 'Buscar no Wikimedia Commons', 'conexao-admin-ux' ); ?></h2>
					<p><?php esc_html_e( 'Digite o nome do local para procurar imagens com licença adequada no Wikimedia Commons. Clique em "Buscar" para ver as sugestências.', 'conexao-admin-ux' ); ?></p>
					<div style="display:flex; gap:8px; align-items:center;">
						<input type="text" id="conexao-wikimedia-search" placeholder="<?php esc_attr_e( 'Ex.: Cliffs of Moher', 'conexao-admin-ux' ); ?>" style="flex:1; max-width:400px;">
						<button type="button" id="conexao-wikimedia-search-btn" class="button button-primary"><?php esc_html_e( 'Buscar', 'conexao-admin-ux' ); ?></button>
					</div>
					<div id="conexao-wikimedia-results" style="margin-top:12px;"></div>
				</div>
			<?php endif; ?>

			<h2 class="title"><?php esc_html_e( 'Locais', 'conexao-admin-ux' ); ?></h2>

			<?php if ( $locations->have_posts() ) : ?>
				<table class="widefat striped conexao-leisure-images-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Local', 'conexao-admin-ux' ); ?></th>
							<th><?php esc_html_e( 'Imagem / Fonte', 'conexao-admin-ux' ); ?></th>
							<th><?php esc_html_e( 'Autor / Licença', 'conexao-admin-ux' ); ?></th>
							<th><?php esc_html_e( 'Status', 'conexao-admin-ux' ); ?></th>
							<th><?php esc_html_e( 'Ações', 'conexao-admin-ux' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php while ( $locations->have_posts() ) : $locations->the_post(); ?>
						<?php
						$id          = get_the_ID();
						$title       = get_the_title();
						$edit_link   = get_edit_post_link( $id );
						$att_id      = get_post_meta( $id, '_leisure_image_attachment_id', true );
						$source      = get_post_meta( $id, '_leisure_image_source', true );
						$source_url  = get_post_meta( $id, '_leisure_image_source_url', true );
						$author      = get_post_meta( $id, '_leisure_image_author', true );
						$license     = get_post_meta( $id, '_leisure_image_license', true );
						$alt_text    = get_post_meta( $id, '_leisure_image_alt_text', true );
						$attribution = get_post_meta( $id, '_leisure_image_attribution', true );
						$status      = get_post_meta( $id, '_leisure_image_status', true );
						if ( ! in_array( $status, array( 'none', 'pending', 'local' ), true ) ) {
							$status = 'none';
						}
						$thumb = $att_id ? wp_get_attachment_image_url( $att_id, 'medium' ) : '';
						$wiki_query = $title;
						?>
						<tr class="conexao-leisure-row" data-post-id="<?php echo esc_attr( (string) $id ); ?>" data-wiki-query="<?php echo esc_attr( $wiki_query ); ?>">
							<td>
								<strong><a href="<?php echo esc_url( $edit_link ); ?>"><?php echo esc_html( $title ); ?></a></strong>
								<div class="conexao-leisure-meta">ID: <?php echo esc_html( (string) $id ); ?></div>
							</td>
							<td>
								<?php if ( $thumb ) : ?>
									<img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( $alt_text ? $alt_text : $title ); ?>" class="conexao-leisure-thumb" style="max-width:90px; height:auto; border-radius:6px;">
								<?php else : ?>
									<span class="conexao-muted"><?php esc_html_e( 'Sem imagem', 'conexao-admin-ux' ); ?></span>
								<?php endif; ?>
								<?php if ( $source ) : ?>
									<div class="conexao-leisure-meta"><?php esc_html_e( 'Fonte:', 'conexao-admin-ux' ); ?> <?php echo esc_html( $source ); ?></div>
								<?php endif; ?>
								<?php if ( $source_url ) : ?>
									<div class="conexao-leisure-meta"><a href="<?php echo esc_url( $source_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Ver página de origem', 'conexao-admin-ux' ); ?> ↗</a></div>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $author ) : ?>
									<div class="conexao-leisure-meta"><strong><?php esc_html_e( 'Autor:', 'conexao-admin-ux' ); ?></strong> <?php echo esc_html( $author ); ?></div>
								<?php endif; ?>
								<?php if ( $license ) : ?>
									<div class="conexao-leisure-meta"><strong><?php esc_html_e( 'Licença:', 'conexao-admin-ux' ); ?></strong> <?php echo esc_html( $license ); ?></div>
								<?php endif; ?>
								<?php if ( ! $author && ! $license ) : ?>
									<span class="conexao-muted">—</span>
								<?php endif; ?>
							</td>
							<td>
								<?php
								$status_labels = array(
									'none'     => __( 'Nenhuma', 'conexao-admin-ux' ),
									'pending'  => __( 'Imagem pendente', 'conexao-admin-ux' ),
									'local'    => __( 'Local (mídia)', 'conexao-admin-ux' ),
								);
								echo '<span class="dashicons dashicons-marker ' . ( 'local' === $status ? 'status-published' : ( 'pending' === $status ? 'status-review' : '' ) ) . '"></span> ';
								echo esc_html( isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : $status );
								?>
							</td>
							<td>
								<button type="button" class="button button-small conexao-wikimedia-lookup"
									data-post-id="<?php echo esc_attr( (string) $id ); ?>"
									data-query="<?php echo esc_attr( $wiki_query ); ?>">
									<?php esc_html_e( 'Buscar no Wikimedia', 'conexao-admin-ux' ); ?>
								</button>
								<br><br>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block; margin: 0; vertical-align: top;">
									<input type="hidden" name="action" value="conexao_leisure_save_image">
									<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $id ); ?>">
									<?php wp_nonce_field( 'conexao_leisure_image_save', 'conexao_leisure_image_nonce' ); ?>
									<p>
										<input type="text" name="leisure_image_source_url" value="<?php echo esc_attr( $source_url ); ?>" placeholder="<?php esc_attr_e( 'URL da página de origem (ex.: Discover Ireland)', 'conexao-admin-ux' ); ?>" size="28" style="width:100%;">
									</p>
									<p>
										<input type="text" name="leisure_image_source" value="<?php echo esc_attr( $source ); ?>" placeholder="<?php esc_attr_e( 'Fonte da imagem', 'conexao-admin-ux' ); ?>" size="20" style="width:100%;">
									</p>
									<p>
										<input type="text" name="leisure_image_author" value="<?php echo esc_attr( $author ); ?>" placeholder="<?php esc_attr_e( 'Fotógrafo / autor', 'conexao-admin-ux' ); ?>" size="20" style="width:100%;">
									</p>
									<p>
										<input type="text" name="leisure_image_license" value="<?php echo esc_attr( $license ); ?>" placeholder="<?php esc_attr_e( 'Licença', 'conexao-admin-ux' ); ?>" size="20" style="width:100%;">
									</p>
									<p>
										<input type="text" name="leisure_image_alt_text" value="<?php echo esc_attr( $alt_text ); ?>" placeholder="<?php esc_attr_e( 'Texto alternativo (alt)', 'conexao-admin-ux' ); ?>" size="20" style="width:100%;">
									</p>
									<p>
										<textarea name="leisure_image_attribution" rows="2" placeholder="<?php esc_attr_e( 'Atribuição completa (fotógrafo + licença + fonte)', 'conexao-admin-ux' ); ?>" style="width:100%;"><?php echo esc_html( $attribution ); ?></textarea>
									</p>
									<p>
										<select name="leisure_image_status" style="width:100%;">
											<?php foreach ( $status_labels as $val => $label ) : ?>
												<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $status, $val ); ?>><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
									</p>
									<?php submit_button( __( 'Salvar referência', 'conexao-admin-ux' ), 'small', 'submit', false ); ?>
								</form>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block; margin-left:4px;">
									<input type="hidden" name="action" value="conexao_leisure_mark_pending">
									<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $id ); ?>">
									<?php wp_nonce_field( 'conexao_leisure_mark_pending', 'conexao_leisure_pending_nonce' ); ?>
									<button type="submit" class="button button-link button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Definir como "Imagem pendente" e remover a imagem atual?', 'conexao-admin-ux' ) ); ?>');"><?php esc_html_e( 'Imagem pendente', 'conexao-admin-ux' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endwhile; ?>
					</tbody>
				</table>
				<?php wp_reset_postdata(); ?>
			<?php else : ?>
				<p><?php esc_html_e( 'Nenhum local de lazer encontrado.', 'conexao-admin-ux' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}

new Conexao_Leisure_Image_Admin();

