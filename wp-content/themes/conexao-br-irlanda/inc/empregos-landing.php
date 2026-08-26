<?php
/**
 * Empregos Landing page module.
 *
 * Supports the Jobs information hub at /empregos/ (backed by a normal
 * WordPress Page rendered through page-empregos.php):
 *
 *   Empregos → portrait image → editable body content → "Mais informações" CTA
 *
 * Content (title, portrait featured image, body) is edited through the native
 * Page editor in wp-admin. This module only adds the single minimal field the
 * hub needs on top of what WordPress already provides: the optional external
 * URL used by the "Mais informações" button. If it is left empty, the button is
 * not rendered at all (no empty CTA).
 *
 * @package Conexao_BR_Irlanda
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the `_empregos_link` meta so the block editor / REST API are aware
 * of it and it is saved reliably. It is a simple string (sanitized URL).
 */
function conexao_register_empregos_meta() {
	register_post_meta(
		'page',
		'_empregos_link',
		array(
			'single'            => true,
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'show_in_rest'      => true,
			'auth_callback'     => function ( $allowed, $meta_key, $object_id ) {
				return current_user_can( 'edit_post', $object_id );
			},
		)
	);
}
add_action( 'init', 'conexao_register_empregos_meta' );

/**
 * Whether a given post is the Jobs landing page.
 *
 * True when the post is a page using the `page-empregos.php` template, or whose
 * slug is `empregos` (the two normally coincide), so the field also behaves on
 * already-published pages before a template is explicitly chosen.
 *
 * @param WP_Post|int|null $post Post object, ID or null (current post).
 * @return bool
 */
function conexao_is_empregos_landing( $post = null ) {
	$post = get_post( $post );
	if ( ! $post || 'page' !== $post->post_type ) {
		return false;
	}
	if ( 'empregos' === $post->post_name ) {
		return true;
	}
	return 'page-empregos.php' === get_page_template_slug( $post->ID );
}

/**
 * Add the minimal admin field for the "Mais informações" link.
 * Only rendered on the Jobs landing page.
 */
function conexao_empregos_add_meta_box() {
	add_meta_box(
		'conexao-empregos-link',
		'Jobs — Link "Mais informações"',
		'conexao_empregos_meta_box_cb',
		'page',
		'normal',
		'default'
	);
}
add_action( 'add_meta_boxes', 'conexao_empregos_add_meta_box' );

/**
 * Render the metabox for the CTA link.
 *
 * @param WP_Post $post Current post.
 */
function conexao_empregos_meta_box_cb( $post ) {
	// Only relevant on the Jobs landing page — hide it everywhere else.
	if ( ! conexao_is_empregos_landing( $post ) ) {
		echo '<p class="description">'
			. esc_html__( 'Este campo é exibido apenas na página de destino de Empregos (template "Empregos — Página de Difusão").', 'conexao-br-irlanda' )
			. '</p>';
		return;
	}

	wp_nonce_field( 'conexao_empregos_link_save', 'conexao_empregos_link_nonce' );
	$value = get_post_meta( $post->ID, '_empregos_link', true );
	?>
	<p class="description">
		<?php esc_html_e( 'URL ou destino do botão "Mais informações". Se deixado em branco, o botão não será exibido.', 'conexao-br-irlanda' ); ?>
	</p>
	<p>
		<input type="url" class="widefat" name="conexao_empregos_link"
			value="<?php echo esc_attr( $value ); ?>"
			placeholder="https://example.com" />
	</p>
	<?php
}

/**
 * Persist the CTA link when the landing page is saved.
 *
 * @param int $post_id Post ID.
 */
function conexao_empregos_save_meta( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! isset( $_POST['conexao_empregos_link_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['conexao_empregos_link_nonce'] ) ), 'conexao_empregos_link_save' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	if ( isset( $_POST['conexao_empregos_link'] ) ) {
		$url = esc_url_raw( trim( wp_unslash( $_POST['conexao_empregos_link'] ) ) );
		if ( $url ) {
			update_post_meta( $post_id, '_empregos_link', $url );
		} else {
			delete_post_meta( $post_id, '_empregos_link' );
		}
	}
}
add_action( 'save_post_page', 'conexao_empregos_save_meta' );

/**
 * The escaped CTA URL for a given Jobs landing page ('' when none).
 *
 * @param int $post_id Post ID (defaults to the current post).
 * @return string URL, escaped for output, or empty string.
 */
function conexao_empregos_link( $post_id = 0 ) {
	$post_id = $post_id ? $post_id : (int) get_the_ID();
	if ( ! $post_id ) {
		return '';
	}
	$url = trim( (string) get_post_meta( $post_id, '_empregos_link', true ) );
	return $url ? esc_url( $url ) : '';
}

/**
 * Canonical URL of the Jobs landing page ('' when the page is missing).
 * Used by the theme breadcrumb for job singles, which previously linked to the
 * (now disabled) job CPT archive.
 *
 * @return string
 */
function conexao_empregos_page_url() {
	$page = get_page_by_path( 'empregos', OBJECT, 'page' );
	if ( $page && 'publish' === $page->post_status ) {
		return get_permalink( $page );
	}
	return '';
}

/**
 * Remind the editor that the featured image should be an Instagram-style
 * portrait (same convention as the per-Job artwork) when editing the landing.
 */
function conexao_empregos_featured_image_hint( $content, $post_id ) {
	if ( ! $post_id || ! conexao_is_empregos_landing( $post_id ) ) {
		return $content;
	}

	$hint = '<p class="description">'
		. __( 'Imagem da página de Empregos: use uma imagem vertical, preferencialmente 1080 × 1920 px (formato Instagram Stories).', 'conexao-br-irlanda' )
		. '</p>';

	return $hint . $content;
}
add_filter( 'admin_post_thumbnail_html', 'conexao_empregos_featured_image_hint', 11, 2 );