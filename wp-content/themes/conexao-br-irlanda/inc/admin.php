<?php
/**
 * Admin interface labels (Posts -> Blog, menu relabelling)
 *
 * Relabels the native "Posts" admin terminology to "Blog" so the wp-admin
 * interface matches the public relabelling, plus the admin menu label and the
 * job featured-image editor hint. Admin-only presentation; no public behaviour.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Relabel "Posts" to "Blog" in the WordPress admin.
 *
 * This makes the admin interface clearer for non-technical administrators
 * by using "Blog" terminology instead of WordPress's native "Posts".
 * All native WordPress post functionality remains intact.
 */
function conexao_relabel_posts_to_blog() {
	global $wp_post_types;
	
	if ( isset( $wp_post_types['post'] ) && isset( $wp_post_types['post']->labels ) ) {
		// Update the existing labels object in-place rather than replacing it.
		// WordPress core expects properties such as `menu_name` and
		// `name_admin_bar` to exist on the labels object; replacing the whole
		// object with a partial one triggers "Undefined property" warnings in
		// wp-admin/menu.php and wp-includes/admin-bar.php.
		$labels = $wp_post_types['post']->labels;

		$labels->name                  = 'Blog';
		$labels->singular_name         = 'Artigo';
		$labels->add_new               = 'Adicionar Novo';
		$labels->add_new_item          = 'Adicionar Novo Artigo';
		$labels->edit_item             = 'Editar Artigo';
		$labels->new_item              = 'Novo Artigo';
		$labels->view_item             = 'Ver Artigo';
		$labels->view_items            = 'Ver Artigos';
		$labels->search_items          = 'Buscar Artigos';
		$labels->not_found             = 'Nenhum artigo encontrado';
		$labels->not_found_in_trash    = 'Nenhum artigo encontrado na lixeira';
		$labels->parent_item_colon     = 'Artigo pai:';
		$labels->all_items             = 'Todos os Artigos';
		$labels->archives              = 'Arquivos do Blog';
		$labels->attributes            = 'Atributos do Artigo';
		$labels->insert_into_item      = 'Inserir no artigo';
		$labels->uploaded_to_this_item = 'Enviado para este artigo';
		$labels->featured_image        = 'Imagem Destacada';
		$labels->set_featured_image    = 'Definir imagem destacada';
		$labels->remove_featured_image = 'Remover imagem destacada';
		$labels->use_featured_image    = 'Usar como imagem destacada';
		$labels->filter_items_list     = 'Filtrar lista de artigos';
		$labels->items_list_navigation = 'Navegação da lista de artigos';
		$labels->items_list            = 'Lista de artigos';

		// Explicitly set the menu/admin-bar labels so the Blog terminology is
		// used consistently in the admin menu and admin bar.
		$labels->menu_name             = 'Blog';
		$labels->name_admin_bar        = 'Artigo';
	}
}
add_action( 'init', 'conexao_relabel_posts_to_blog', 10 );

/**
 * Change the admin menu label for "Posts" to "Blog".
 */
function conexao_change_admin_menu_label() {
	global $menu;
	
	foreach ( $menu as $key => $value ) {
		if ( isset( $value[0] ) && 'Posts' === $value[0] ) {
			$menu[ $key ][0] = 'Blog';
		}
	}
}
add_action( 'admin_menu', 'conexao_change_admin_menu_label', 5 );

/**
 * Get the canonical archive URL for the Guides CPT.
 *
 * @return string
 */
function conexao_job_featured_image_hint( $content, $post_id ) {
	if ( ! $post_id || 'job' !== get_post_type( $post_id ) ) {
		return $content;
	}

	$hint = '<p class="description">'
		. __( 'Imagem da vaga: use uma imagem vertical, preferencialmente 1080 × 1920 px (formato Instagram Stories).', 'conexao-br-irlanda' )
		. '</p>';

	return $hint . $content;
}
add_filter( 'admin_post_thumbnail_html', 'conexao_job_featured_image_hint', 10, 2 );


/**
 * WhatsApp floating button
 */
