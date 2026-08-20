#!/usr/bin/env python3
"""Add handle_wiki_import AJAX method to the leisure image admin class."""

filepath = '/home/andrei/IdeaProjects/wordpress-website/wp-content/plugins/conexao-admin-ux/includes/class-leisure-image-admin.php'
with open(filepath, 'r') as f:
    content = f.read()

# 1. Register the new AJAX action in the constructor.
old_constructor = "add_action( 'wp_ajax_conexao_leisure_wiki_search', array( $this, 'handle_wiki_search' ) );\n\t\tadd_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );"
new_constructor = "add_action( 'wp_ajax_conexao_leisure_wiki_search', array( $this, 'handle_wiki_search' ) );\n\t\tadd_action( 'wp_ajax_conexao_leisure_wiki_import', array( $this, 'handle_wiki_import' ) );\n\t\tadd_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );"
if old_constructor in content:
    content = content.replace(old_constructor, new_constructor, 1)
    print("Constructor updated OK")
else:
    print("Constructor marker not found")

# 2. Add i18n strings.
old_i18n = "'confirm_import' => __( 'Importar esta imagem do Wikimedia Commons para a biblioteca de mídia e associá-la a este local?', 'conexao-admin-ux' ),\n\t\t)\n\t);"
new_i18n = "'confirm_import' => __( 'Importar esta imagem do Wikimedia Commons para a biblioteca de mídia e associá-la a este local?', 'conexao-admin-ux' ),\n\t\t\t'approve'     => __( 'Aprovar e importar', 'conexao-admin-ux' ),\n\t\t\t'reject'      => __( 'Rejeitar', 'conexao-admin-ux' ),\n\t\t\t'choose'      => __( 'Escolher outra', 'conexao-admin-ux' ),\n\t\t\t'importing'   => __( 'Importando...', 'conexao-admin-ux' ),\n\t\t\t'imported'    => __( 'Imagem importada e associada com sucesso!', 'conexao-admin-ux' ),\n\t\t\t'import_error'=> __( 'Erro ao importar a imagem.', 'conexao-admin-ux' ),\n\t\t\t'pd'          => __( 'Domínio Público / CC0', 'conexao-admin-ux' ),\n\t\t\t'by'          => __( 'CC BY', 'conexao-admin-ux' ),\n\t\t\t'bysa'        => __( 'CC BY-SA', 'conexao-admin-ux' ),\n\t\t\t'no_results'  => __( 'Nenhuma imagem com licença adequada encontrada no Wikimedia Commons.', 'conexao-admin-ux' ),\n\t\t\t'searching'   => __( 'Buscando no Wikimedia Commons...', 'conexao-admin-ux' ),\n\t\t\t'author'      => __( 'Autor:', 'conexao-admin-ux' ),\n\t\t\t'license'      => __( 'Licença:', 'conexao-admin-ux' ),\n\t\t\t'source'      => __( 'Fonte:', 'conexao-admin-ux' ),\n\t\t\t'size'         => __( 'Tamanho:', 'conexao-admin-ux' ),\n\t\t\t'view_on'      => __( 'Ver no Wikimedia', 'conexao-admin-ux' ),\n\t\t\t'unknown'      => __( 'Desconhecido', 'conexao-admin-ux' ),\n\t\t\t'preview_of'   => __( 'Prévia de ', 'conexao-admin-ux' ),\n\t\t\t'error'         => __( 'Erro na busca.', 'conexao-admin-ux' ),\n\t\t)\n\t);"
if old_i18n in content:
    content = content.replace(old_i18n, new_i18n, 1)
    print("i18n strings added OK")
else:
    print("i18n marker not found")

# 3. Insert handle_wiki_import method before handle_mark_pending.
method_code = '''\t/**
\t * Handle AJAX request: import a selected Wikimedia Commons image.
\t *
\t * The admin approves a candidate from the search results; this handler
\t * downloads the image, imports it into the Media Library, sets it as the
\t * post thumbnail, and stores all attribution metadata.
\t */
\tpublic function handle_wiki_import() {
\t\tcheck_ajax_referer( 'conexao_wikimedia_search', 'nonce' );

\t\tif ( ! current_user_can( 'edit_posts' ) ) {
\t\t\twp_send_json_error( array( 'message' => 'unauthorized' ), 403 );
\t\t}

\t\t$post_id    = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
\t\t$file_title = isset( $_POST['file_title'] ) ? sanitize_text_field( wp_unslash( $_POST['file_title'] ) ) : '';

\t\tif ( ! $post_id || 'leisure' !== get_post_type( $post_id ) || empty( $file_title ) ) {
\t\t\twp_send_json_error( array( 'message' => 'invalid parameters' ), 400 );
\t\t}

\t\trequire_once CONEXAO_ADMIN_UX_DIR . 'includes/class-wikimedia-client.php';
\t\t$client = new Conexao_Wikimedia_Client();
\t\t$info   = $client->get_file_info( $file_title );

\t\tif ( ! $info || ! $info['license_accepted'] || ! $info['image_url'] ) {
\t\t\twp_send_json_error( array( 'message' => 'license not acceptable or image not found' ), 400 );
\t\t}

\t\t$title  = get_the_title( $post_id );
\t\t$county = get_post_meta( $post_id, '_leisure_county', true );
\t\t$slug   = sanitize_title( $title );
\t\t$att_id = $client->import_image( $info['image_url'], $slug, $post_id );

\t\tif ( is_wp_error( $att_id ) ) {
\t\t\twp_send_json_error( array( 'message' => $att_id->get_error_message() ), 500 );
\t\t}

\t\t$attribution = 'Foto: ' . $info['author'];
\t\tif ( $info['require_attr'] ) {
\t\t\t$attribution .= ', Licença: ' . $info['license_label'];
\t\t}
\t\t$attribution .= ', Fonte: Wikimedia Commons';

\t\t$alt_text = $title;
\t\tif ( $county ) {
\t\t\t$alt_text .= ', ' . $county . ', Irlanda';
\t\t}

\t\tupdate_post_meta( $post_id, '_leisure_image_attachment_id', (int) $att_id );
\t\tupdate_post_meta( $post_id, '_leisure_image_external_url', '' );
\t\tupdate_post_meta( $post_id, '_leisure_image_source', 'Wikimedia Commons' );
\t\tupdate_post_meta( $post_id, '_leisure_image_source_url', $info['page_url'] );
\t\tupdate_post_meta( $post_id, '_leisure_image_author', $info['author'] );
\t\tupdate_post_meta( $post_id, '_leisure_image_license', $info['license_label'] );
\t\tupdate_post_meta( $post_id, '_leisure_image_attribution', $attribution );
\t\tupdate_post_meta( $post_id, '_leisure_image_alt_text', $alt_text );
\t\tupdate_post_meta( $post_id, '_leisure_image_status', 'local' );

\t\tset_post_thumbnail( $post_id, $att_id );
\t\tupdate_post_meta( $att_id, '_wp_attachment_image_alt', $alt_text );
\t\twp_update_post( array(
\t\t\t'ID'         => $att_id,
\t\t\t'post_title' => $title,
\t\t) );

\t\twp_send_json_success( array(
\t\t\t'attachment_id' => (int) $att_id,
\t\t\t'attribution'   => $attribution,
\t\t\t'alt_text'      => $alt_text,
\t\t) );
\t}

'''

marker = '\t/**\n\t * Handle marking one or more locations'
if marker in content:
    content = content.replace(marker, method_code + marker, 1)
    print("handle_wiki_import method inserted OK")
else:
    print("handle_wiki_import marker not found")

with open(filepath, 'w') as f:
    f.write(content)

print("File written successfully.")
