<?php
/**
 * Empregos — "Onde procurar emprego" job-search resources module.
 *
 * Backs the job-resources section rendered by page-empregos.php below the
 * existing Instagram banner / guidance content. The section reuses the Event
 * card visual language (.event-card*) so it stays native to the design system.
 *
 * The resources are managed in wp-admin under the Empregos menu
 * ("Onde procurar emprego" — see conexao_job_resources_admin_menu()) and are
 * stored in the `conexao_job_resources` option. Each resource is an
 * associative array:
 *
 *   'title'       => (string, required)  Card title, e.g. "Jobs.ie".
 *   'description' => (string)             Short Portuguese description.
 *   'url'         => (string, required)  External URL, opened in a new tab.
 *   'image_id'    => (int, optional)     Media Library attachment ID (preferred;
 *                                        gets responsive srcset via WP image API).
 *   'alt'         => (string, optional)  Meaningful alt text; falls back to title.
 *
 * Until the admin screen is saved for the first time, the built-in defaults
 * (Jobs.ie, Indeed Ireland, IrishJobs) are used, so the section never renders
 * empty. The `conexao_job_resources` filter remains available for developers.
 *
 * @package Conexao_BR_Irlanda
 */

defined( 'ABSPATH' ) || exit;

const CONEXAO_JOB_RESOURCES_OPTION = 'conexao_job_resources';

/**
 * The job-search resources shown on /empregos/.
 *
 * @return array[] List of resource definitions (see file header for the shape).
 */
function conexao_job_resources() {
	$resources = get_option( CONEXAO_JOB_RESOURCES_OPTION );

	if ( ! is_array( $resources ) || empty( $resources ) ) {
		$resources = conexao_job_resource_defaults();
	}

	$resources = array_values( array_filter( $resources, 'is_array' ) );

	/**
	 * Filter the /empregos/ job-search resources.
	 *
	 * @param array[] $resources List of resource definitions.
	 */
	return apply_filters( 'conexao_job_resources', $resources );
}

/**
 * Built-in defaults shown until the admin screen is saved.
 *
 * @return array[] Resource definitions.
 */
function conexao_job_resource_defaults() {
	return array(
		array(
			'title'       => 'Jobs.ie',
			'description' => __( 'Encontre vagas de emprego em diversas áreas e regiões da Irlanda.', 'conexao-br-irlanda' ),
			'url'         => 'https://www.jobs.ie/',
			'image_id'    => 0,
			'alt'         => __( 'Jobs.ie — site de empregos na Irlanda', 'conexao-br-irlanda' ),
		),
		array(
			'title'       => 'Indeed Ireland',
			'description' => __( 'Busca de vagas em todo o país, com filtros por área, salário e localização.', 'conexao-br-irlanda' ),
			'url'         => 'https://ie.indeed.com/',
			'image_id'    => 0,
			'alt'         => __( 'Indeed Irlanda — site de busca de empregos', 'conexao-br-irlanda' ),
		),
		array(
			'title'       => 'IrishJobs',
			'description' => __( 'Uma das maiores plataformas de recrutamento da Irlanda, com vagas de grandes empresas.', 'conexao-br-irlanda' ),
			'url'         => 'https://www.irishjobs.ie/',
			'image_id'    => 0,
			'alt'         => __( 'IrishJobs — site de empregos na Irlanda', 'conexao-br-irlanda' ),
		),
	);
}

/**
 * Sanitize the job resources submitted from the admin screen.
 *
 * Rows missing a title or a URL are dropped; surviving rows are re-indexed in
 * the submitted order so reordering works by removing/re-adding rows.
 *
 * @param mixed $raw Raw submitted value (array of rows, or anything).
 * @return array[] Sanitized resource definitions.
 */
function conexao_job_resources_sanitize( $raw ) {
	$clean = array();

	if ( ! is_array( $raw ) ) {
		return $clean;
	}

	foreach ( $raw as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$title = isset( $row['title'] ) ? sanitize_text_field( wp_unslash( $row['title'] ) ) : '';
		$url   = isset( $row['url'] ) ? esc_url_raw( trim( (string) wp_unslash( $row['url'] ) ) ) : '';

		if ( '' === $title || '' === $url ) {
			continue;
		}

		$description = isset( $row['description'] ) ? sanitize_textarea_field( wp_unslash( $row['description'] ) ) : '';
		$image_id    = isset( $row['image_id'] ) ? absint( $row['image_id'] ) : 0;
		$alt         = isset( $row['alt'] ) ? sanitize_text_field( wp_unslash( $row['alt'] ) ) : '';

		// A non-image attachment must never be stored.
		if ( $image_id && ! wp_attachment_is_image( $image_id ) ) {
			$image_id = 0;
		}

		$clean[] = array(
			'title'       => $title,
			'description' => $description,
			'url'         => $url,
			'image_id'    => $image_id,
			'alt'         => $alt,
		);
	}

	return $clean;
}

/**
 * Register the "Onde procurar emprego" management screen.
 *
 * Lives as a submenu of the existing Empregos menu (edit.php?post_type=job) so
 * editors manage the /empregos/ job-search cards where they expect them.
 */
function conexao_job_resources_admin_menu() {
	add_submenu_page(
		'edit.php?post_type=job',
		__( 'Onde procurar emprego', 'conexao-br-irlanda' ),
		__( 'Onde procurar emprego', 'conexao-br-irlanda' ),
		'edit_others_posts',
		'conexao-job-resources',
		'conexao_job_resources_admin_page'
	);
}
add_action( 'admin_menu', 'conexao_job_resources_admin_menu' );

/**
 * Render the job resources management screen.
 */
function conexao_job_resources_admin_page() {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		wp_die( esc_html__( 'Você não tem permissão para acessar esta página.', 'conexao-br-irlanda' ) );
	}

	$saved          = get_option( CONEXAO_JOB_RESOURCES_OPTION );
	$using_defaults = ! is_array( $saved ) || empty( $saved );
	$resources      = $using_defaults ? conexao_job_resource_defaults() : array_values( array_filter( $saved, 'is_array' ) );

	$notice = '';
	if ( isset( $_GET['conexao-resources-updated'] ) && '1' === $_GET['conexao-resources-updated'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display notice.
		$notice = __( 'Sites de emprego salvos com sucesso.', 'conexao-br-irlanda' );
	}
	?>
	<div class="wrap conexao-job-resources-admin">
		<h1><?php esc_html_e( 'Onde procurar emprego', 'conexao-br-irlanda' ); ?></h1>
		<p class="description">
			<?php esc_html_e( 'Estes sites de busca de emprego aparecem como cartões na página /empregos/ (seção "Onde procurar emprego"). Escolha uma imagem da Biblioteca de Mídia para o logo de cada cartão; sem imagem, um banner com a inicial do site é exibido automaticamente.', 'conexao-br-irlanda' ); ?>
		</p>
		<?php if ( $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>
		<?php if ( $using_defaults ) : ?>
			<div class="notice notice-info"><p><?php esc_html_e( 'Mostrando os sites padrão. Salve abaixo para começar a editar — a lista salva passa a valer para o site público.', 'conexao-br-irlanda' ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="conexao_job_resources_save" />
			<?php wp_nonce_field( 'conexao_job_resources_save', 'conexao_job_resources_nonce' ); ?>

			<div id="conexao-job-resources-rows">
				<?php
				foreach ( $resources as $resource ) {
					conexao_job_resource_admin_row( $resource );
				}
				?>
			</div>

			<p>
				<button type="button" class="button button-secondary" id="conexao-job-resources-add">
					<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
					<?php esc_html_e( 'Adicionar site', 'conexao-br-irlanda' ); ?>
				</button>
			</p>

			<?php submit_button( __( 'Salvar sites de emprego', 'conexao-br-irlanda' ) ); ?>
		</form>
	</div>

	<?php conexao_job_resources_admin_template_row(); ?>
	<?php
}

/**
 * Render one resource row (used for existing rows and cloned for new ones).
 *
 * @param array $resource Resource definition.
 */
function conexao_job_resource_admin_row( $resource ) {
	$title       = isset( $resource['title'] ) ? (string) $resource['title'] : '';
	$description = isset( $resource['description'] ) ? (string) $resource['description'] : '';
	$url         = isset( $resource['url'] ) ? (string) $resource['url'] : '';
	$image_id    = isset( $resource['image_id'] ) ? absint( $resource['image_id'] ) : 0;
	$alt         = isset( $resource['alt'] ) ? (string) $resource['alt'] : '';
	?>
	<div class="conexao-job-resource-row postbox">
		<div class="postbox-header">
			<h2 class="hndle conexao-job-resource-row-title"><?php echo esc_html( $title ? $title : __( 'Novo site', 'conexao-br-irlanda' ) ); ?></h2>
			<div class="handle-actions">
				<button type="button" class="button-link conexao-job-resource-remove" aria-label="<?php esc_attr_e( 'Remover este site', 'conexao-br-irlanda' ); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>
			</div>
		</div>
		<div class="inside">
			<div class="conexao-job-resource-grid">
				<p class="conexao-job-resource-field">
					<label><?php esc_html_e( 'Nome do site', 'conexao-br-irlanda' ); ?> *</label>
					<input type="text" name="conexao_job_resources[][title]" value="<?php echo esc_attr( $title ); ?>" class="widefat conexao-job-resource-title-input" />
				</p>
				<p class="conexao-job-resource-field">
					<label><?php esc_html_e( 'URL', 'conexao-br-irlanda' ); ?> *</label>
					<input type="url" name="conexao_job_resources[][url]" value="<?php echo esc_attr( $url ); ?>" class="widefat" placeholder="https://..." />
				</p>
				<p class="conexao-job-resource-field conexao-job-resource-field--full">
					<label><?php esc_html_e( 'Descrição', 'conexao-br-irlanda' ); ?></label>
					<textarea name="conexao_job_resources[][description]" rows="2" class="widefat"><?php echo esc_textarea( $description ); ?></textarea>
				</p>
				<p class="conexao-job-resource-field">
					<label><?php esc_html_e( 'Texto alternativo (alt)', 'conexao-br-irlanda' ); ?></label>
					<input type="text" name="conexao_job_resources[][alt]" value="<?php echo esc_attr( $alt ); ?>" class="widefat" />
				</p>
				<div class="conexao-job-resource-field conexao-job-resource-image">
					<label><?php esc_html_e( 'Imagem do cartão (logo)', 'conexao-br-irlanda' ); ?></label>
					<input type="hidden" name="conexao_job_resources[][image_id]" class="conexao-job-resource-image-id" value="<?php echo esc_attr( (string) $image_id ); ?>" />
					<div class="conexao-job-resource-image-preview">
						<?php if ( $image_id && wp_attachment_is_image( $image_id ) ) : ?>
							<?php echo wp_get_attachment_image( $image_id, 'thumbnail', false, array( 'class' => 'conexao-job-resource-thumb' ) ); ?>
						<?php endif; ?>
					</div>
					<p>
						<button type="button" class="button conexao-job-resource-image-select"><?php esc_html_e( 'Escolher imagem', 'conexao-br-irlanda' ); ?></button>
						<button type="button" class="button-link-delete conexao-job-resource-image-remove" <?php echo $image_id ? '' : 'style="display:none;"'; ?>><?php esc_html_e( 'Remover imagem', 'conexao-br-irlanda' ); ?></button>
					</p>
				</div>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Output the <template> used by the admin JS to append new empty rows.
 */
function conexao_job_resources_admin_template_row() {
	ob_start();
	conexao_job_resource_admin_row(
		array(
			'title'       => '',
			'description' => '',
			'url'         => '',
			'image_id'    => 0,
			'alt'         => '',
		)
	);
	$row_html = ob_get_clean();
	?>
	<template id="conexao-job-resource-row-template"><?php echo $row_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped row markup. ?></template>
	<?php
}

/**
 * Persist the submitted job resources.
 */
function conexao_job_resources_admin_save() {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		wp_die( esc_html__( 'Você não tem permissão para acessar esta página.', 'conexao-br-irlanda' ) );
	}

	check_admin_referer( 'conexao_job_resources_save', 'conexao_job_resources_nonce' );

	$raw   = isset( $_POST['conexao_job_resources'] ) ? wp_unslash( $_POST['conexao_job_resources'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in conexao_job_resources_sanitize().
	$clean = conexao_job_resources_sanitize( $raw );

	update_option( CONEXAO_JOB_RESOURCES_OPTION, $clean, false );

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'                      => 'conexao-job-resources',
				'post_type'                 => 'job',
				'conexao-resources-updated' => '1',
			),
			admin_url( 'edit.php' )
		)
	);
	exit;
}
add_action( 'admin_post_conexao_job_resources_save', 'conexao_job_resources_admin_save' );

/**
 * Enqueue the management screen's script and the media picker.
 *
 * @param string $hook Current admin page hook.
 */
function conexao_job_resources_admin_assets( $hook ) {
	if ( 'job_page_conexao-job-resources' !== $hook ) {
		return;
	}

	wp_enqueue_media();
	wp_enqueue_style(
		'conexao-job-resources-admin',
		get_theme_file_uri( '/assets/css/job-resources-admin.css' ),
		array(),
		wp_get_theme()->get( 'Version' )
	);
	wp_enqueue_script(
		'conexao-job-resources-admin',
		get_theme_file_uri( '/assets/js/job-resources-admin.js' ),
		array(),
		wp_get_theme()->get( 'Version' ),
		true
	);
}
add_action( 'admin_enqueue_scripts', 'conexao_job_resources_admin_assets' );

/**
 * Resolve the best available image for a job resource.
 *
 * Preference order mirrors the Event card logic: Media Library attachment
 * first (responsive srcset), then a direct URL, then no image (the card
 * template renders a clean branded fallback banner).
 *
 * @param array $resource Single resource definition.
 * @return array{attachment_id: int, url: string, alt: string} Image data.
 */
function conexao_job_resource_image( $resource ) {
	$attachment_id = isset( $resource['image_id'] ) ? absint( $resource['image_id'] ) : 0;
	$url           = isset( $resource['image_url'] ) ? esc_url_raw( (string) $resource['image_url'] ) : '';
	$alt           = isset( $resource['alt'] ) && '' !== trim( (string) $resource['alt'] )
		? trim( (string) $resource['alt'] )
		: (string) ( $resource['title'] ?? '' );

	// A non-image attachment (or a deleted one) must never render broken.
	if ( $attachment_id && ! wp_attachment_is_image( $attachment_id ) ) {
		$attachment_id = 0;
	}
	if ( ! $attachment_id && $url ) {
		$attachment_id = attachment_url_to_postid( $url );
		if ( $attachment_id && ! wp_attachment_is_image( $attachment_id ) ) {
			$attachment_id = 0;
		}
	}

	return array(
		'attachment_id' => $attachment_id,
		'url'           => $attachment_id ? '' : $url,
		'alt'           => $alt,
	);
}
